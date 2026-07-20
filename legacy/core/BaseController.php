<?php
// core/BaseController.php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/Logger.php';

class BaseController {

    /** @var array<string, mixed>|null */
    private ?array $jsonInputCache = null;

    protected function view($view, $data = []) {
        extract($data);
        
        // If view is in subfolder like 'auth/login'
        $viewPath = "../app/views/" . $view . ".php";
        
        if (file_exists($viewPath)) {
            require_once $viewPath;
        } else {
            Logger::error('View not found', ['view' => $view]);
            Flash::set('The requested page could not be loaded.', 'error');
            $this->redirect(Auth::isLoggedIn() ? 'dashboard' : 'auth/login');
        }
    }

    protected function redirect($url) {
        header("Location: " . BASE_URL . $url);
        exit();
    }

    /**
     * @deprecated Use Auth::requireLogin() instead for consistency and flash messages
     */
    protected function requireLogin() {
        Auth::requireLogin();
    }

    protected function isAdmin() {
        return Auth::isAdmin();
    }

    protected function isSuperadmin() {
        return Auth::isSuperadmin();
    }

    /**
     * Gate a controller/action to admin-tier (admin or superadmin) users.
     */
    protected function requireAdmin() {
        Auth::requireAdmin();
    }

    /**
     * Gate a controller/action to superadmin only.
     */
    protected function requireSuperadmin() {
        Auth::requireSuperadmin();
    }

    /**
     * Parsed JSON body (cached; php://input is single-read).
     *
     * @return array<string, mixed>
     */
    protected function getJsonInput(): array
    {
        if ($this->jsonInputCache === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '{}', true);
            $this->jsonInputCache = is_array($decoded) ? $decoded : [];
        }
        return $this->jsonInputCache;
    }

    protected function getRequestCsrfToken(): string
    {
        $token = trim((string)($_POST['csrf_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $header = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($header !== '') {
            return $header;
        }

        if ($this->isJsonRequest()) {
            return trim((string)($this->getJsonInput()['csrf_token'] ?? ''));
        }

        return '';
    }

    protected function validateCSRF(): void
    {
        $token = $this->getRequestCsrfToken();
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($token === '' || $sessionToken === '' || !hash_equals((string)$sessionToken, $token)) {
            if ($this->isAjaxRequest() || $this->isJsonRequest()) {
                ApiResponse::emit(
                    ApiResponse::error(
                        'Security token expired or invalid. Please refresh the page and try again.',
                        ApiResponse::CODE_CSRF_INVALID
                    ),
                    403
                );
            }

            // For normal form submissions
            Flash::set('Security token expired or invalid. Please refresh the page and try again.', 'error');

            if (Auth::isLoggedIn()) {
                $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                if ($referer !== '' && $host !== '' && str_contains($referer, $host)) {
                    header('Location: ' . $referer);
                    exit;
                }
                $this->redirect('dashboard');
            }

            $this->redirect('auth/login');
            exit;
        }
    }

    protected function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    protected function isJsonRequest(): bool
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        return str_contains($contentType, 'application/json');
    }

    /**
     * Session-authenticated JSON API guard (login + optional rate limit).
     */
    protected function guardJsonApi(string $rateKey, int $maxAttempts = 120, int $windowSeconds = 60, array $allowedRoles = []): void
    {
        $this->assertApiVersion();

        if (!Auth::isLoggedIn()) {
            $this->sendJson(
                ApiResponse::error('Authentication required.', ApiResponse::CODE_UNAUTHORIZED),
                401
            );
        }

        if ($allowedRoles !== []) {
            Auth::requireRoleJson($allowedRoles);
        }

        $userId = (int)(Auth::getUserId() ?? 0);
        $bucket = $rateKey . ':' . $userId;
        $check = RateLimiter::attempt($bucket, $maxAttempts, $windowSeconds);
        if (!$check['allowed']) {
            $this->sendJson(
                ApiResponse::error(
                    'Too many requests. Please wait and try again.',
                    ApiResponse::CODE_RATE_LIMITED,
                    ['retry_after' => $check['retry_after']]
                ),
                429
            );
        }
    }

    /**
     * Phase 7 — optional mobile/API version gate (header X-API-Version).
     */
    protected function assertApiVersion(): void
    {
        if (!defined('API_SUPPORTED_VERSIONS') || API_SUPPORTED_VERSIONS === '') {
            return;
        }

        $requested = trim((string)($_SERVER['HTTP_X_API_VERSION'] ?? ''));
        if ($requested === '') {
            return;
        }

        $supported = array_map('trim', explode(',', API_SUPPORTED_VERSIONS));
        if (in_array($requested, $supported, true)) {
            return;
        }

        $this->sendJson(
            ApiResponse::error(
                'Unsupported API version. Supported: ' . implode(', ', $supported),
                'unsupported_api_version'
            ),
            400
        );
    }

    /**
     * Phase 7 — enforce route_roles.php for controller action.
     */
    protected function requireRouteAccess(?string $action = null): void
    {
        $action = $action ?? ($GLOBALS['__erp_route_action'] ?? 'index');
        $controller = $GLOBALS['__erp_route_controller'] ?? static::class;
        if (!str_ends_with($controller, 'Controller')) {
            $controller = static::class;
        }
        require_once dirname(__DIR__) . '/app/services/Security/RouteAccess.php';
        RouteAccess::require($controller, $action);
    }

    protected function safeClientMessage(Throwable $e, string $fallback = 'An unexpected error occurred.'): string
    {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            return $e->getMessage();
        }
        Logger::error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return $fallback;
    }

    /**
     * User-facing HTML error instead of raw die().
     */
    protected function abortPage(string $message, string $redirectUrl = 'sales/today'): void
    {
        Flash::set($message, 'error');
        $this->redirect($redirectUrl);
    }

    protected function sendJson($data, int $httpCode = 200): void
    {
        ApiResponse::emit(is_array($data) ? $data : [], $httpCode);
    }


    /**
     * Robust Active Menu Detection for 3-level nested menus.
     * Works reliably regardless of subfolder (e.g. /remote-center-erp/)
     */
    protected function isActiveMenu($menu) {
        if (empty($menu['controller'])) {
            return false;
        }

        $currentUri = $_SERVER['REQUEST_URI'];
        $currentPath = parse_url($currentUri, PHP_URL_PATH);
        $currentPath = strtolower(trim($currentPath, '/'));

        $menuController = strtolower(trim($menu['controller'] ?? '', '/'));
        $menuAction     = strtolower(trim($menu['action'] ?? '', '/'));

        // Remove the application base path (e.g. "remote-center-erp/public")
        $scriptName = strtolower(trim(dirname($_SERVER['SCRIPT_NAME']), '/'));
        if ($scriptName && strpos($currentPath, $scriptName) === 0) {
            $currentPath = substr($currentPath, strlen($scriptName));
            $currentPath = trim($currentPath, '/');
        }

        // Clean current path further (remove "public" if present)
        $currentPath = preg_replace('#^public/?#', '', $currentPath);
        $currentPath = trim($currentPath, '/');

        // Build the menu's expected path
        $menuPath = $menuController;
        if ($menuAction) {
            $menuPath .= '/' . $menuAction;
        }

        // 1. Exact match (most accurate)
        if ($currentPath === $menuPath) {
            return true;
        }

        // 2. For parent items (no specific action), check if we're inside this section
        if (empty($menu['action'])) {
            // Current path starts with this controller (e.g. current = sales/create, menu = sales)
            if (strpos($currentPath, $menuController . '/') === 0 || $currentPath === $menuController) {
                return true;
            }
        }

        return false;
    }

}
?>