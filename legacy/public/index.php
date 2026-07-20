<?php
// public/index.php
// Secure entry point for Remote Center ERP

// =============================================
// 1. SECURE SESSION + CSRF (using centralized class)
// =============================================
require_once '../core/Session.php';
Session::start();

// =============================================
// 2. CORE INCLUDES
// =============================================
require_once '../config/config.php';
require_once '../core/Database.php';
require_once '../core/BaseController.php';
require_once '../core/Auth.php';
require_once '../core/Flash.php';
require_once '../core/RememberMe.php';
// Phase 0: PendingLogin (2FA intermediate state) removed.
require_once '../core/InvestigationMode.php';

if (!Auth::isLoggedIn()) {
    RememberMe::attemptRestore();
}

if (Auth::isLoggedIn()) {
    InvestigationMode::syncSessionWithDatabase();
}

// ==================== ROUTING ====================
$request_uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$request_path = parse_url($request_uri, PHP_URL_PATH) ?: '/';
$request_path = '/' . trim(str_replace('\\', '/', $request_path), '/');

$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// Strip /public/index.php directory from URL (direct access: .../public/auth/login)
if ($script_dir !== '' && $script_dir !== '/' && strpos($request_path, $script_dir) === 0) {
    $path = substr($request_path, strlen($script_dir));
} else {
    // Root .htaccess rewrites to public/ but URI may omit /public (e.g. .../remote-center-erp/auth/login)
    $app_folder = basename(dirname(__DIR__));
    $stripped = false;
    foreach (['/' . $app_folder . '/public/', '/' . $app_folder . '/'] as $prefix) {
        if (strpos($request_path, $prefix) === 0) {
            $path = substr($request_path, strlen($prefix));
            $stripped = true;
            break;
        }
    }
    if (!$stripped) {
        $path = ltrim($request_path, '/');
    }
}

$path = trim($path, '/');

// Friendly shortcuts
// ==================== ROUTING ALIASES ====================
$routeAliases = [
    'login'              => 'auth/login',
    'logout'             => 'auth/logout',
    'save-fcm-token'     => 'sales/save_fcm_token',
    'save_fcm_token'     => 'sales/save_fcm_token',
    'sales-guide'        => 'sales/guide',
    'guideline'          => 'sales/guide',
    'sales-go-live'      => 'sales/go_live_checklist',
    'go-live-checklist'  => 'sales/go_live_checklist',
    'reports'            => 'Report/index',
    'report'             => 'Report/index',
    'Accounting/Reconciliation' => 'Reconciliation/index',
    'gl-reconciliation'  => 'Reconciliation/index',
    'sales/reconcile'    => 'Reconciliation/index',
    'notifications/unread' => 'notification/unread',     // Fixed
    'notification/unread'=> 'notification/unread',
];


if (isset($routeAliases[$path])) {
    $path = $routeAliases[$path];
}

// Home: dashboard when logged in, otherwise login
if ($path === '' || $path === 'index.php' || $path === 'public') {
    if (Auth::isLoggedIn()) {
        $path = 'dashboard';
    } else {
        $path = 'auth/login';
    }
}

$segments = explode('/', $path);

$controllerName = ucfirst($segments[0] ?? 'Dashboard') . 'Controller';
$method         = $segments[1] ?? 'index';
$params         = array_slice($segments, 2);

$isInvestigationScan = ($controllerName === 'InvestigationController' && $method === 'scan');

// ==================== AUTH PROTECTION ====================
$publicControllers = ['AuthController'];   // Add more public controllers here if needed

// investigation/scan sets post_login_redirect and calls requireLogin() itself
if (!in_array($controllerName, $publicControllers) && !$isInvestigationScan) {
    Auth::requireLogin();

    require_once '../app/services/Security/RouteAccess.php';
    require_once '../app/services/Security/MenuAccess.php';
    RouteAccess::require($controllerName, $method);
    MenuAccess::require($controllerName, $method);
}

// ==================== ROUTE TO CONTROLLER ====================
$controllerFile = '../app/controllers/' . $controllerName . '.php';

if (file_exists($controllerFile)) {
    require_once $controllerFile;
    
    $controller = new $controllerName();

    if (method_exists($controller, $method)) {
        $GLOBALS['__erp_route_controller'] = $controllerName;
        $GLOBALS['__erp_route_action'] = $method;
        call_user_func_array([$controller, $method], $params);
    } else {
        // Show nice custom error page
        http_response_code(404);
        $code = 404;
        $message = "Method not found: " . htmlspecialchars($method);
        include __DIR__ . '/error.php';
        exit;
    }
} else {
    // Show nice custom error page
    http_response_code(404);
    $code = 404;
    $message = "Page not found: " . htmlspecialchars($controllerName);
    include __DIR__ . '/error.php';
    exit;
}
?>