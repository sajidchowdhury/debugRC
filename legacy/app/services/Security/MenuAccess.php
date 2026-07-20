<?php
// app/services/Security/MenuAccess.php — enforce per-user menu permissions on URLs.

require_once __DIR__ . '/../../../core/Auth.php';
require_once __DIR__ . '/../../../core/Database.php';

class MenuAccess
{
    /** @var array<string, array<int, array<string, mixed>>>|null */
    private static ?array $menusByController = null;

    /** @var array<int, array<string, int>>|null */
    private static ?array $userPermissionCache = null;

    /**
     * Controller/actions always available to any logged-in user (self-service, infra).
     *
     * @var array<string, string[]|string>
     */
    private static array $exempt = [
        'DashboardController'     => '*',
        'NotificationController'  => '*',
        'InvestigationController' => ['scan'],
        'UserController'          => [
            'change_password',
            'update_password',
            // Phase 0: two_factor_* actions removed (2FA disabled).
            'update_telegram',
        ],
    ];

    /** @var string[] */
    private static array $editActions = [
        'store',
        'update',
        'delete',
        'toggle',
        'restore',
        'save_permissions',
        'unlock',
        'generate_reset_link',
        // Phase 0: 'admin_disable_two_factor' removed (2FA disabled).
        'bulkaction',
        'confirm_store',
        'add_price',
        'delete_price',
        'create',
        'edit',
        'permission',
        'permission_json',
        'categorystore',
        'categoryupdate',
        'groupstore',
        'groupupdate',
        'categorycreate',
        'categoryedit',
        'groupcreate',
        'groupedit',
    ];

    public static function allows(string $controller, string $action): bool
    {
        if (Auth::hasAdminRouteBypass()) {
            return true;
        }

        if (self::isExempt($controller, $action)) {
            return true;
        }

        $userId = Auth::getUserId();
        if (!$userId) {
            return false;
        }

        $slug = self::controllerSlug($controller);
        $menus = self::menusForController($slug);

        // No menu rows for this module — rely on route_roles / controller gates only.
        if ($menus === []) {
            return true;
        }

        $permissions = self::permissionsForUser($userId);
        $needsEdit = self::requiresEdit($controller, $action);

        if ($needsEdit) {
            return self::hasControllerPermission($menus, $permissions, 'edit');
        }

        $routeAction = self::normalizeAction($action);

        foreach ($menus as $menu) {
            $menuAction = self::normalizeAction((string)($menu['action'] ?? 'index'));
            if ($menuAction !== $routeAction) {
                continue;
            }

            $perm = $permissions[(int)$menu['id']] ?? null;
            if ($perm && !empty($perm['view'])) {
                return true;
            }
        }

        return self::hasControllerPermission($menus, $permissions, 'view');
    }

    public static function require(string $controller, string $action): void
    {
        if (self::allows($controller, $action)) {
            return;
        }

        self::deny();
    }

    private static function deny(): void
    {
        $message = 'You do not have permission to access this page.';

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

    private static function isExempt(string $controller, string $action): bool
    {
        if (!isset(self::$exempt[$controller])) {
            return false;
        }

        $rules = self::$exempt[$controller];
        if ($rules === '*') {
            return true;
        }

        return in_array(strtolower($action), array_map('strtolower', (array)$rules), true);
    }

    private static function controllerSlug(string $controllerName): string
    {
        $name = preg_replace('/Controller$/', '', $controllerName) ?? $controllerName;
        return strtolower($name);
    }

    private static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        return $action === '' ? 'index' : $action;
    }

    private static function requiresEdit(string $controller, string $action): bool
    {
        if ($action === 'index' && (isset($_GET['draw']) || isset($_POST['draw']))) {
            return false;
        }

        $normalized = self::normalizeAction($action);

        if (in_array($normalized, self::$editActions, true)) {
            return true;
        }

        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param array<int, array<string, int>> $permissions
     */
    private static function hasControllerPermission(array $menus, array $permissions, string $type): bool
    {
        foreach ($menus as $menu) {
            $perm = $permissions[(int)$menu['id']] ?? null;
            if (!$perm) {
                continue;
            }

            if ($type === 'edit' && !empty($perm['edit'])) {
                return true;
            }

            if ($type === 'view' && !empty($perm['view'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function menusForController(string $slug): array
    {
        if (self::$menusByController === null) {
            self::loadMenus();
        }

        return self::$menusByController[$slug] ?? [];
    }

    private static function loadMenus(): void
    {
        self::$menusByController = [];

        try {
            $db = new Database();
            $db->query('
                SELECT id, controller, action
                FROM menus
                WHERE is_active = 1
                  AND controller IS NOT NULL
                  AND controller != ""
            ');

            foreach ($db->resultSet() as $row) {
                $slug = strtolower(trim((string)$row['controller']));
                if ($slug === '') {
                    continue;
                }

                self::$menusByController[$slug][] = $row;
            }
        } catch (Throwable $e) {
            error_log('MenuAccess::loadMenus failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, int>>
     */
    private static function permissionsForUser(int $userId): array
    {
        if (self::$userPermissionCache !== null && isset(self::$userPermissionCache[$userId])) {
            return self::$userPermissionCache[$userId];
        }

        $permissions = [];

        try {
            $db = new Database();
            $db->query('
                SELECT menu_id, can_view, can_edit
                FROM user_menu_permissions
                WHERE user_id = :user_id
            ');
            $db->bind(':user_id', $userId);

            foreach ($db->resultSet() as $row) {
                $permissions[(int)$row['menu_id']] = [
                    'view' => (int)$row['can_view'],
                    'edit' => (int)$row['can_edit'],
                ];
            }
        } catch (Throwable $e) {
            error_log('MenuAccess::permissionsForUser failed: ' . $e->getMessage());
        }

        if (self::$userPermissionCache === null) {
            self::$userPermissionCache = [];
        }

        self::$userPermissionCache[$userId] = $permissions;

        return $permissions;
    }

    private static function isAjaxOrJsonRequest(): bool
    {
        $xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        return $xhr || str_contains($contentType, 'application/json');
    }
}
