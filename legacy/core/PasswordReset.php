<?php
// core/PasswordReset.php — token-based password reset (Phase 5).

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PasswordPolicy.php';

class PasswordReset
{
    public static function tokenHours(): int
    {
        return defined('AUTH_RESET_TOKEN_HOURS') ? max(1, (int)AUTH_RESET_TOKEN_HOURS) : 1;
    }

    /**
     * Create a one-time reset token. Returns [token, expires_at] or null on failure.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function createToken(int $userId): ?array
    {
        try {
            self::invalidateExistingTokens($userId);

            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $hours = self::tokenHours();

            $db = new Database();
            $db->query('
                INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL :hours HOUR))
            ');
            $db->bind(':user_id', $userId);
            $db->bind(':token_hash', $hash);
            $db->bind(':hours', $hours);

            if (!$db->execute()) {
                return null;
            }

            $db->query('SELECT expires_at FROM password_reset_tokens WHERE token_hash = :hash LIMIT 1');
            $db->bind(':hash', $hash);
            $row = $db->single();

            return [$token, (string)($row['expires_at'] ?? date('Y-m-d H:i:s', time() + ($hours * 3600)))];
        } catch (Throwable $e) {
            error_log('PasswordReset::createToken failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array{id: int, user_id: int, username: string}|null
     */
    public static function validateToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        try {
            $hash = hash('sha256', $token);
            $db = new Database();
            $db->query('
                SELECT prt.id, prt.user_id, u.username
                FROM password_reset_tokens prt
                JOIN users u ON u.id = prt.user_id
                WHERE prt.token_hash = :hash
                  AND prt.used_at IS NULL
                  AND prt.expires_at > NOW()
                  AND u.deleted_at IS NULL
                  AND u.is_active = 1
                LIMIT 1
            ');
            $db->bind(':hash', $hash);
            $row = $db->single();

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('PasswordReset::validateToken failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function resetPassword(string $token, string $newPassword, string $confirmPassword): array
    {
        if ($newPassword !== $confirmPassword) {
            return ['status' => 'error', 'message' => 'Password and confirmation do not match.'];
        }

        $validation = PasswordPolicy::validate($newPassword);
        if ($validation !== true) {
            return ['status' => 'error', 'message' => $validation];
        }

        $record = self::validateToken($token);
        if (!$record) {
            return ['status' => 'error', 'message' => 'This reset link is invalid or has expired.'];
        }

        try {
            $db = new Database();
            $db->beginTransaction();

            $db->query('UPDATE users SET password_hash = :hash WHERE id = :id');
            $db->bind(':hash', password_hash($newPassword, PASSWORD_DEFAULT));
            $db->bind(':id', (int)$record['user_id']);
            if (!$db->execute()) {
                $db->rollback();
                return ['status' => 'error', 'message' => 'Failed to update password.'];
            }

            require_once __DIR__ . '/CredentialVersion.php';
            CredentialVersion::bump((int)$record['user_id']);

            $db->query('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
            $db->bind(':id', (int)$record['id']);
            $db->execute();

            require_once __DIR__ . '/AccountLockout.php';
            AccountLockout::clear((int)$record['user_id']);

            require_once __DIR__ . '/RememberMe.php';
            RememberMe::revokeAllForUser((int)$record['user_id']);

            $db->commit();

            return ['status' => 'success', 'message' => 'Your password has been reset. You can now sign in.'];
        } catch (Throwable $e) {
            if (isset($db)) {
                $db->rollback();
            }
            error_log('PasswordReset::resetPassword failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to reset password. Please try again.'];
        }
    }

    /**
     * Best-effort email to the employee address linked to the user account.
     */
    public static function sendResetEmail(int $userId, string $token): bool
    {
        try {
            $db = new Database();
            $db->query('
                SELECT u.username, e.email, e.name
                FROM users u
                JOIN employees e ON e.id = u.employee_id
                WHERE u.id = :id
                LIMIT 1
            ');
            $db->bind(':id', $userId);
            $row = $db->single();

            $email = trim((string)($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            $resetUrl = BASE_URL . 'auth/reset/' . urlencode($token);
            $name = trim((string)($row['name'] ?? $row['username'] ?? 'User'));
            $hours = self::tokenHours();

            $subject = APP_NAME . ' — Password reset';
            $body = "Hello {$name},\n\n"
                . "A password reset was requested for your account ({$row['username']}).\n\n"
                . "Reset your password using this link (valid for {$hours} hour(s)):\n"
                . "{$resetUrl}\n\n"
                . "If you did not request this, you can ignore this email.\n";

            return @mail($email, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
        } catch (Throwable $e) {
            error_log('PasswordReset::sendResetEmail failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function invalidateExistingTokens(int $userId): void
    {
        $db = new Database();
        $db->query('DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL');
        $db->bind(':user_id', $userId);
        $db->execute();
    }
}
