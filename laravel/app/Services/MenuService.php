<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\User;
use App\Models\UserMenuPermission;
use Illuminate\Support\Facades\Cache;

/**
 * Menu Service — DB-driven navigation menu with per-user permissions.
 *
 * Mirrors legacy MenuModel::getUserMenus() + MenuAccess.
 *
 * Builds a hierarchical menu tree filtered by:
 *   - Menu is active
 *   - User has can_view=1 permission for the menu
 *   - Admin/superadmin bypass: see all active menus (no permission check)
 *
 * Cache: per-user menu tree is cached for 5 minutes (invalidated on
 * permission change or menu update).
 */
class MenuService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'menu_tree:';

    /**
     * Get the hierarchical menu tree for a user.
     *
     * @param User $user
     * @return array Hierarchical menu tree (top-level → children → grandchildren)
     */
    public function getUserMenuTree(User $user): array
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->buildMenuTree($user);
        });
    }

    /**
     * Build the menu tree from DB, filtered by user permissions.
     */
    private function buildMenuTree(User $user): array
    {
        $isAdmin = $user->isAdmin();

        // Get all active menus, ordered by sort_order.
        $query = Menu::active()->orderBy('sort_order')->orderBy('id');

        // Admin/superadmin: see all active menus (no permission filter).
        if (!$isAdmin) {
            // Non-admin: only menus where user has can_view=1.
            $permittedMenuIds = UserMenuPermission::where('user_id', $user->id)
                ->where('can_view', true)
                ->pluck('menu_id')
                ->toArray();

            if (empty($permittedMenuIds)) {
                return []; // No permissions at all — empty menu.
            }

            $query->whereIn('id', $permittedMenuIds);
        }

        $allMenus = $query->get();

        // Build 3-level hierarchical tree.
        return $this->buildTree($allMenus);
    }

    /**
     * Build a hierarchical tree from a flat collection of menus.
     * Supports 3 levels: top-level → children → grandchildren.
     */
    private function buildTree($menus): array
    {
        $tree = [];
        $byParent = [];

        // Group by parent_id.
        foreach ($menus as $menu) {
            $parentId = $menu->parent_id ?? 0;
            $byParent[$parentId][] = $menu;
        }

        // Build top-level → children → grandchildren.
        foreach (($byParent[0] ?? []) as $topMenu) {
            $topArray = $this->menuToArray($topMenu);
            $topArray['children'] = [];

            foreach (($byParent[$topMenu->id] ?? []) as $child) {
                $childArray = $this->menuToArray($child);
                $childArray['children'] = [];

                foreach (($byParent[$child->id] ?? []) as $grandchild) {
                    $childArray['children'][] = $this->menuToArray($grandchild);
                }

                $topArray['children'][] = $childArray;
            }

            $tree[] = $topArray;
        }

        return $tree;
    }

    /**
     * Convert a Menu model to an array with route resolution.
     */
    private function menuToArray(Menu $menu): array
    {
        // Resolve the route for this menu item.
        $url = $this->resolveMenuUrl($menu);

        return [
            'id' => $menu->id,
            'menu_name' => $menu->menu_name,
            'url' => $url,
            'icon' => $menu->icon ?? 'fas fa-circle',
            'controller' => $menu->controller,
            'action' => $menu->action,
            'section' => $menu->section,
            'sort_order' => $menu->sort_order,
            'children' => [],
        ];
    }

    /**
     * Resolve a menu's controller/action to a Laravel route URL.
     * Maps legacy controller/action pairs to Laravel named routes.
     */
    private function resolveMenuUrl(Menu $menu): string
    {
        // If menu_link is set and starts with #, it's a dropdown parent.
        if ($menu->menu_link && str_starts_with($menu->menu_link, '#')) {
            return '#';
        }

        // If menu_link is set as a URL path, use it directly.
        if ($menu->menu_link && !empty($menu->menu_link)) {
            return '/' . ltrim($menu->menu_link, '/');
        }

        // Map controller/action to Laravel named routes.
        $controller = strtolower($menu->controller ?? '');
        $action = strtolower($menu->action ?? '');

        // Route mapping table (legacy controller → Laravel route name prefix).
        $routeMap = [
            'dashboard' => 'dashboard',
            'branch' => 'admin.branches.index',
            'warehouse' => 'admin.warehouses.index',
            'product' => 'admin.products.index',
            'customer' => 'admin.customers.index',
            'supplier' => 'admin.suppliers.index',
            'employee' => 'admin.employees.index',
            'user' => 'admin.users.index',
            'bank' => 'admin.banks.index',
            'ledger' => 'admin.ledgers.index',
            'sales' => $action === 'create' ? 'admin.sales.cart' : 'admin.sales-invoices.index',
            'challan' => 'admin.sales-challans.index',
            'salesreturn' => 'admin.sales-returns.index',
            'purchaseorder' => 'admin.purchase-orders.index',
            'purchasereceive' => 'admin.purchase-receives.index',
            'purchasereturn' => 'admin.purchase-returns.index',
            'stocktake' => 'admin.stock-take.index',
            'stockadjustment' => 'admin.stock-adjustments.index',
            'warehousetransfer' => 'admin.warehouse-transfers.index',
            'branchdemand' => $action === 'weekly' ? 'admin.branch-demands.weekly' : 'admin.branch-demands.index',
            'damage' => 'admin.damages.index',
            'moneytransfer' => 'admin.money-transfers.index',
            'otherincome' => 'admin.other-incomes.index',
            'otherexpense' => 'admin.other-expenses.index',
            'manualjournal' => 'admin.manual-journals.index',
            'accountingperiod' => $action === 'year_end' ? 'admin.accounting.year-end' : 'admin.accounting.period-close',
            'customertransaction' => 'admin.customer-payments.index',
            'suppliertransaction' => 'admin.supplier-payments.index',
            'employeetransaction' => 'admin.employee-transactions.index',
            'reconciliation' => 'admin.reconciliation.index',
            'report' => 'admin.reports.index',
        ];

        $routeName = $routeMap[$controller] ?? null;

        if ($routeName) {
            try {
                return route($routeName);
            } catch (\Throwable $e) {
                return '#';
            }
        }

        return '#';
    }

    /**
     * Check if a user can view a specific menu (by controller + action).
     * Mirrors legacy MenuAccess::allows().
     *
     * @param User $user
     * @param string $controller
     * @param string $action
     * @return bool
     */
    public function canView(User $user, string $controller, string $action = ''): bool
    {
        // Admin/superadmin bypass.
        if ($user->isAdmin()) {
            return true;
        }

        $controller = strtolower($controller);
        $action = strtolower($action);

        return UserMenuPermission::where('user_id', $user->id)
            ->where('can_view', true)
            ->whereHas('menu', function ($q) use ($controller, $action) {
                $q->where('is_active', true);
                if ($controller) {
                    $q->whereRaw('LOWER(controller) = ?', [$controller]);
                }
                if ($action) {
                    $q->whereRaw('LOWER(action) = ?', [$action]);
                }
            })
            ->exists();
    }

    /**
     * Invalidate the menu cache for a user (call when permissions change).
     *
     * @param int $userId
     */
    public function invalidateUserMenuCache(int $userId): void
    {
        Cache::forget(self::CACHE_PREFIX . $userId);
    }

    /**
     * Invalidate all menu caches (call when menu items change).
     */
    public function invalidateAllMenuCaches(): void
    {
        // For Redis, scan + delete by prefix.
        // For file/array, this is a no-op (caches expire in 5 min).
        Cache::flush(); // Simple but effective — menu changes are rare.
    }

    /**
     * Get all active menus (for the admin menu management page).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllMenus()
    {
        return Menu::orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Get a user's menu permissions (for the permission management page).
     *
     * @param int $userId
     * @return \Illuminate\Support\Collection
     */
    public function getUserPermissions(int $userId)
    {
        return UserMenuPermission::where('user_id', $userId)->get()->keyBy('menu_id');
    }

    /**
     * Set a user's menu permission (create or update).
     *
     * @param int $userId
     * @param int $menuId
     * @param bool $canView
     * @param bool $canEdit
     */
    public function setPermission(int $userId, int $menuId, bool $canView, bool $canEdit): void
    {
        UserMenuPermission::updateOrCreate(
            ['user_id' => $userId, 'menu_id' => $menuId],
            ['can_view' => $canView, 'can_edit' => $canEdit]
        );

        $this->invalidateUserMenuCache($userId);
    }
}
