<?php
// core/RememberMe.php — secure persistent login tokens (Phase 6).

require_once __DIR__ . '/Database.php';

class RememberMe
{
    public const COOKIE_NAME = 'erp_remember';

    public static function cookieDays(): int
    {
        return defined('AUTH_REMEMBER_DAYS') ? max(1, (int)AUTH_REMEMBER_DAYS) : 30;
    }

    /**
     * Issue a remember-me cookie after successful login.
     */
    public static function create(int $userId): void
    {
        try {
            self::revokeCurrent();

            $selector = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));
            $hash = hash('sha256', $validator);
            $days = self::cookieDays();

            $db = new Database();
            $db->query('
                INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, user_agent, ip_address)
                VALUES (:user_id, :selector, :token_hash, DATE_ADD(NOW(), INTERVAL :days DAY), :ua, :ip)
            ');
            $db->bind(':user_id', $userId);
            $db->bind(':selector', $selector);
            $db->bind(':token_hash', $hash);
            $db->bind(':days', $days);
            $db->bind(':ua', self::sanitizeUserAgent($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $db->bind(':ip', mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
            $db->execute();

            self::setCookie($selector . ':' . $validator, $days);
        } catch (Throwable $e) {
            error_log('RememberMe::create failed: ' . $e->getMessage());
        }
    }

    /**
     * Restore session from remember-me cookie when no active login exists.
     */
    public static function attemptRestore(): bool
    {
        if (!empty($_SESSION['user_id'])) {
            return false;
        }

        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($raw === '' || strpos($raw, ':') === false) {
            return false;
        }

        [$selector, $validator] = explode(':', $raw, 2);
        $selector = trim($selector);
        $validator = trim($validator);

        if ($selector === '' || $validator === '') {
            return false;
        }

        try {
            $db = new Database();
            $db->query('
                SELECT rt.user_id, rt.token_hash
                FROM remember_tokens rt
                JOIN users u ON u.id = rt.user_id
                WHERE rt.selector = :selector
                  AND rt.expires_at > NOW()
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
                LIMIT 1
            ');
            $db->bind(':selector', $selector);
            $row = $db->single();

            if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
                self::clearCookie();
                return false;
            }

            $user = self::fetchUserRow((int)$row['user_id']);
            if (!$user) {
                self::clearCookie();
                return false;
            }

            // Phase 0: TOTP 2FA removed — remember-me now logs in directly.
            require_once __DIR__ . '/Auth.php';
            Auth::login($user);

            // Rotate token on use
            self::create((int)$user['id']);

            return true;
        } catch (Throwable $e) {
            error_log('RememberMe::attemptRestore failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function revokeCurrent(): void
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($raw !== '' && strpos($raw, ':') !== false) {
            [$selector] = explode(':', $raw, 2);
            self::revokeSelector(trim($selector));
        }

        self::clearCookie();
    }

    public static function revokeAllForUser(int $userId): void
    {
        try {
            $db = new Database();
            $db->query('DELETE FROM remember_tokens WHERE user_id = :user_id');
            $db->bind(':user_id', $userId);
            $db->execute();
        } catch (Throwable $e) {
            error_log('RememberMe::revokeAllForUser failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetchUserRow(int $userId): ?array
    {
        try {
            $db = new Database();
            $db->query('
                SELECT
                    u.id, u.username, u.employee_id, u.password_hash,
                    u.failed_login_count, u.locked_until, u.updated_at,
                    e.role, e.branch_id, e.name AS employee_name,
                    b.branch_name, e.photo
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                JOIN branches b ON e.branch_id = b.id
                WHERE u.id = :id
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
                LIMIT 1
            ');
            $db->bind(':id', $userId);
            $row = $db->single();

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('RememberMe::fetchUserRow failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function revokeSelector(string $selector): void
    {
        if ($selector === '') {
            return;
        }

        try {
            $db = new Database();
            $db->query('DELETE FROM remember_tokens WHERE selector = :selector');
            $db->bind(':selector', $selector);
            $db->execute();
        } catch (Throwable $e) {
            error_log('RememberMe::revokeSelector failed: ' . $e->getMessage());
        }
    }

    private static function setCookie(string $value, int $days): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + ($days * 86400),
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function sanitizeUserAgent(string $ua): ?string
    {
        $ua = mb_substr(preg_replace('/[\r\n\t]/', ' ', trim($ua)), 0, 255);
        return $ua !== '' ? $ua : null;
    }
}
