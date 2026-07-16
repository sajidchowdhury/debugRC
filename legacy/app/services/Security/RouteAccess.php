<?php
// app/services/Security/RouteAccess.php — Phase 7 route/role enforcement

require_once __DIR__ . '/../../../core/Auth.php';

class RouteAccess
{
    /** @var array<string, array<string, string[]>>|null */
    private static ?array $matrix = null;

    /**
     * @return array<string, array<string, string[]>>
     */
    private static function matrix(): array
    {
        if (self::$matrix === null) {
            $path = dirname(__DIR__, 2) . '/config/route_roles.php';
            $loaded = is_readable($path) ? require $path : [];
            self::$matrix = is_array($loaded) ? $loaded : [];
        }

        return self::$matrix;
    }

    public static function allows(string $controller, string $action): bool
    {
        if (Auth::hasAdminRouteBypass()) {
            return true;
        }

        $roles = self::matrix()[$controller][$action] ?? null;
        if ($roles === null) {
            return true;
        }

        return Auth::hasRole(...$roles);
    }

    /**
     * Abort when role is not permitted (JSON for API/AJAX, redirect for HTML).
     */
    public static function require(string $controller, string $action): void
    {
        if (self::allows($controller, $action)) {
            return;
        }

        self::deny();
    }

    private static function deny(): void
    {
        $message = 'You do not have permission to perform this action.';

        if (self::isAjaxOrJsonRequest()) {
            require_once __DIR__ . '/../../../core/ApiResponse.php';
            ApiResponse::emit(
                ApiResponse::error($message, ApiResponse::CODE_FORBIDDEN),
                403
            );
        }

        require_once __DIR__ . '/../../../core/Flash.php';
        Flash::set($message, 'error');
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    private static function isAjaxOrJsonRequest(): bool
    {
        $xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        return $xhr || str_contains($contentType, 'application/json');
    }
}