<?php
// core/Auth.php
// Centralized, secure authentication helper

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/RoleRegistry.php';

class Auth {

    // ===================== ROLE TIERS =====================
    // Access tiers (stored in employees.role). Operational roles
    // (salesman, manager, accountant, ...) are treated as regular users.
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN      = 'admin';
    public const ROLE_USER       = 'user';

    /**
     * Perform secure login
     * - Regenerates session ID (prevents fixation)
     * - Sets consistent session variables
     * - Updates last_login timestamp
     */
    public static function login(array $user): void
    {
        // Prevent session fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Standard session variables used across the app
        $_SESSION['user_id']        = (int)$user['id'];
        $_SESSION['username']       = $user['username'];
        $_SESSION['employee_id']    = $user['employee_id'] ?? null;
        $_SESSION['employee_name']  = $user['employee_name'] ?? $user['name'] ?? null;
        $_SESSION['role']           = $user['role'] ?? 'user';
        $_SESSION['branch_id']      = $user['branch_id'] ?? null;
        $_SESSION['branch_name']    = $user['branch_name'] ?? null;
        $_SESSION['logged_in_at']   = time();
        $_SESSION['photo']    = $user['photo'] ?? '';

        

        // Update last login in database (best effort)
        self::updateLastLogin(
            (int)$user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        // Optional: store minimal user object
        $_SESSION['user'] = [
            'id'            => (int)$user['id'],
            'username'      => $user['username'],
            'role'          => $_SESSION['role'],
            'branch_id'     => $_SESSION['branch_id'],
        ];

        require_once __DIR__ . '/InvestigationMode.php';
        InvestigationMode::applySessionForUser($user);

        // Stamp session after all login-side user row updates (last_login, lockout clear, rehash).
        $version = self::fetchCredentialVersion((int)$user['id']);
        if ($version !== null) {
            $_SESSION['credential_version'] = $version;
        } else {
            $_SESSION['credential_version'] = '';
        }
    }

    /**
     * Update last-login timestamp, IP, and user agent for the user.
     */
    private static function updateLastLogin(int $userId, ?string $ip, ?string $userAgent): void
    {
        try {
            $ip = $ip !== null ? mb_substr(trim($ip), 0, 45) : null;
            $userAgent = $userAgent !== null
                ? mb_substr(preg_replace('/[\r\n\t]/', ' ', trim($userAgent)), 0, 255)
                : null;

            $db = new Database();
            $db->query('
                UPDATE users
                SET last_login = NOW(),
                    last_login_ip = :ip,
                    last_login_user_agent = :user_agent,
                    updated_at = updated_at
                WHERE id = :id
            ');
            $db->bind(':ip', $ip);
            $db->bind(':user_agent', $userAgent);
            $db->bind(':id', $userId);
            $db->execute();
        } catch (Throwable $e) {
            error_log("Auth::updateLastLogin failed for user {$userId}: " . $e->getMessage());
        }
    }

    /**
     * Secure logout - clears everything properly
     */
    public static function logout(): void
    {
        require_once __DIR__ . '/RememberMe.php';
        RememberMe::revokeCurrent();

        // Use centralized session destroyer
        require_once __DIR__ . '/Session.php';
        Session::destroy();

        // Start a fresh session just for the flash message after logout
        session_start();
        $_SESSION['flash'] = [
            'message' => 'You have been logged out successfully.',
            'type'    => 'success'
        ];
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            Flash::set("Please login first to access this page.", "error");
            header("Location: " . BASE_URL . "auth/login");
            exit;
        }

        self::validateSessionCredential();
    }

    /**
     * End session when account credentials changed (e.g. admin password reset).
     */
    public static function validateSessionCredential(): void
    {
        if (!self::isLoggedIn()) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $stored = (string)($_SESSION['credential_version'] ?? '');
        $current = self::fetchCredentialVersion($userId);

        if ($current === null) {
            self::invalidateSessionForCredentialChange();
            return;
        }

        if ($stored === '') {
            $_SESSION['credential_version'] = $current;
            return;
        }

        if (!hash_equals($stored, $current)) {
            self::invalidateSessionForCredentialChange();
        }
    }

    private static function fetchCredentialVersion(int $userId): ?string
    {
        require_once __DIR__ . '/CredentialVersion.php';
        return CredentialVersion::fetch($userId);
    }

    public static function invalidateSessionForCredentialChange(): void
    {
        require_once __DIR__ . '/RememberMe.php';
        RememberMe::revokeCurrent();

        require_once __DIR__ . '/Session.php';
        Session::destroy();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        Flash::set('Your session ended because your account credentials were changed. Please sign in again.', 'warning');
        header('Location: ' . BASE_URL . 'auth/login');
        exit;
    }

    public static function getCurrentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function getRole(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function getEffectiveRole(): ?string
    {
        return self::getRole();
    }

    public static function getBranchId(): ?int
    {
        return $_SESSION['branch_id'] ?? null;
    }

    /**
     * True when a global investigation window is active for this admin/superadmin session.
     */
    public static function isSessionInvestigationRestricted(): bool
    {
        require_once __DIR__ . '/InvestigationMode.php';
        return InvestigationMode::isSessionRestricted();
    }

    public static function isUnrestrictedSuperadmin(): bool
    {
        return self::getRole() === self::ROLE_SUPERADMIN;
    }

    /**
     * Top access tier — full control, including company-critical actions.
     */
    public static function isSuperadmin(): bool
    {
        return self::getRole() === self::ROLE_SUPERADMIN;
    }

    /**
     * Admin tier. Superadmin is always "admin-or-above", so it returns true
     * here too. Use isUnrestrictedSuperadmin() for superadmin-only checks.
     */
    public static function isAdmin(): bool
    {
        return in_array(self::getRole(), [self::ROLE_ADMIN, self::ROLE_SUPERADMIN], true);
    }

    public static function hasAdminRouteBypass(): bool
    {
        return self::isAdmin();
    }

    /**
     * Require an admin-tier (admin or superadmin) user.
     * Redirects regular users back to the dashboard with a flash message.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            Flash::set("You do not have permission to access that area.", "error");
            header("Location: " . BASE_URL . "dashboard");
            exit;
        }
    }

    /**
     * Require a superadmin user (company-critical actions).
     */
    public static function requireSuperadmin(): void
    {
        self::requireLogin();
        if (!self::isUnrestrictedSuperadmin()) {
            Flash::set("That action requires super-admin privileges.", "error");
            header("Location: " . BASE_URL . "dashboard");
            exit;
        }
    }

    /**
     * Can the current user assign/grant a given role?
     * - superadmin can be granted only by a superadmin
     * - admin can be granted by an admin-tier user
     * - any other role requires at least admin tier
     */
    public static function canAssignRole(string $role): bool
    {
        return RoleRegistry::canActorAssign($role);
    }

    public static function hasRole(string ...$roles): bool
    {
        $current = self::getRole();
        if ($current === null || $current === '') {
            return false;
        }
        return in_array($current, $roles, true);
    }

    /**
     * Abort with JSON 403 if the user does not have one of the allowed roles.
     */
    public static function requireRoleJson(array $allowedRoles): void
    {
        if ($allowedRoles === [] || self::hasRole(...$allowedRoles)) {
            return;
        }
        require_once __DIR__ . '/ApiResponse.php';
        ApiResponse::emit(
            ApiResponse::error(
                'You do not have permission to perform this action.',
                ApiResponse::CODE_FORBIDDEN
            ),
            403
        );
    }
}
?>