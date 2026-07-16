<?php
// core/AccountLockout.php — persisted per-account login lockout (Phase 5).

require_once __DIR__ . '/Database.php';

class AccountLockout
{
    public static function maxAttempts(): int
    {
        return defined('AUTH_MAX_FAILED_ATTEMPTS') ? max(1, (int)AUTH_MAX_FAILED_ATTEMPTS) : 5;
    }

    public static function lockoutMinutes(): int
    {
        return defined('AUTH_LOCKOUT_MINUTES') ? max(1, (int)AUTH_LOCKOUT_MINUTES) : 15;
    }

    /**
     * @param array{locked_until?: string|null, failed_login_count?: int|string|null} $user
     */
    public static function isLocked(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        return strtotime((string)$lockedUntil) > time();
    }

    /**
     * Human-readable lockout message, or null when not locked.
     *
     * @param array{locked_until?: string|null} $user
     */
    public static function lockMessage(array $user): ?string
    {
        if (!self::isLocked($user)) {
            return null;
        }

        $until = strtotime((string)($user['locked_until'] ?? ''));
        if ($until <= time()) {
            return null;
        }

        $minutes = max(1, (int)ceil(($until - time()) / 60));

        return "This account is temporarily locked due to too many failed login attempts. Try again in about {$minutes} minute(s), or contact an administrator.";
    }

    public static function recordFailure(int $userId): void
    {
        try {
            $db = new Database();
            $max = self::maxAttempts();
            $minutes = self::lockoutMinutes();

            $db->query('
                UPDATE users
                SET failed_login_count = failed_login_count + 1,
                    locked_until = CASE
                        WHEN failed_login_count + 1 >= :max_attempts THEN DATE_ADD(NOW(), INTERVAL :lock_minutes MINUTE)
                        ELSE locked_until
                    END
                WHERE id = :id
            ');
            $db->bind(':max_attempts', $max);
            $db->bind(':lock_minutes', $minutes);
            $db->bind(':id', $userId);
            $db->execute();
        } catch (Throwable $e) {
            error_log('AccountLockout::recordFailure failed: ' . $e->getMessage());
        }
    }

    public static function clear(int $userId): void
    {
        try {
            $db = new Database();
            $db->query('
                UPDATE users
                SET failed_login_count = 0,
                    locked_until = NULL,
                    updated_at = updated_at
                WHERE id = :id
            ');
            $db->bind(':id', $userId);
            $db->execute();
        } catch (Throwable $e) {
            error_log('AccountLockout::clear failed: ' . $e->getMessage());
        }
    }

    public static function unlock(int $userId): bool
    {
        try {
            $db = new Database();
            $db->query('
                UPDATE users
                SET failed_login_count = 0,
                    locked_until = NULL
                WHERE id = :id
            ');
            $db->bind(':id', $userId);
            return $db->execute();
        } catch (Throwable $e) {
            error_log('AccountLockout::unlock failed: ' . $e->getMessage());
            return false;
        }
    }
}
