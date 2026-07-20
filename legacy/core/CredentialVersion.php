<?php
// core/CredentialVersion.php — session invalidation stamp (separate from updated_at).

require_once __DIR__ . '/Database.php';

class CredentialVersion
{
    /**
     * Read stamp for an active, non-deleted user. Null when account unavailable.
     */
    public static function fetch(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $db = new Database();
            $db->query('
                SELECT credential_version
                FROM users
                WHERE id = :id
                  AND is_active = 1
                  AND deleted_at IS NULL
                LIMIT 1
            ');
            $db->bind(':id', $userId);
            $row = $db->single();

            if (!$row) {
                return null;
            }

            return (string)(int)($row['credential_version'] ?? 1);
        } catch (Throwable $e) {
            error_log('CredentialVersion::fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate other sessions for this user (monotonic increment).
     */
    public static function bump(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $db = new Database();
            $db->query('
                UPDATE users
                SET credential_version = credential_version + 1
                WHERE id = :id
                  AND deleted_at IS NULL
            ');
            $db->bind(':id', $userId);

            return $db->execute() && $db->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('CredentialVersion::bump failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bump linked login when employee role/branch changes.
     */
    public static function bumpForEmployee(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }

        try {
            $db = new Database();
            $db->query('
                UPDATE users
                SET credential_version = credential_version + 1
                WHERE employee_id = :employee_id
                  AND deleted_at IS NULL
            ');
            $db->bind(':employee_id', $employeeId);
            $db->execute();
        } catch (Throwable $e) {
            error_log('CredentialVersion::bumpForEmployee failed: ' . $e->getMessage());
        }
    }

    /**
     * Refresh session stamp after a self-service security change.
     */
    public static function syncSession(int $userId): void
    {
        if ($userId !== (int)($_SESSION['user_id'] ?? 0)) {
            return;
        }

        $version = self::fetch($userId);
        if ($version !== null) {
            $_SESSION['credential_version'] = $version;
        }
    }
}
