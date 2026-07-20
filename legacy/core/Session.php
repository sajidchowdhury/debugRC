<?php
// core/Session.php
// Centralized, secure session management for Remote Center ERP
// Handles secure session startup + early CSRF token generation

class Session
{
    /**
     * Start a secure session with proper configuration.
     * Must be called very early (before any output).
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Already started
        }

        // =====================================================
        // SECURE SESSION CONFIGURATION
        // =====================================================
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $https ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', '28800'); // 8 hours

        session_set_cookie_params([
            'lifetime' => 0,           // Until browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        // Generate CSRF token early (available even on login page)
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Optional: Regenerate session ID periodically for long-lived sessions
        self::regenerateIfNeeded();
    }

    /**
     * Regenerate session ID if it has been a while (defense in depth)
     */
    private static function regenerateIfNeeded(): void
    {
        $regenerateInterval = 1800; // 30 minutes

        if (empty($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
            return;
        }

        if (time() - $_SESSION['last_regeneration'] > $regenerateInterval) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }

    /**
     * Destroy the current session completely and securely.
     * Used by Auth::logout()
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Clear all session data
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();
    }

    /**
     * Get the current CSRF token (creates one if missing)
     */
    public static function getCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Check if a session is currently active
     */
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }
}
?>