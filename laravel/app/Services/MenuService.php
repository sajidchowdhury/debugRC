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
            'menu_name' => $menu->menu_label, // PG column is menu_label, not menu_name
            'url' => $url,
            'icon' => $menu->icon ?? 'fas fa-circle',
            'controller' => $menu->controller,
            'action' => $menu->action,
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
        // The PG menus table does NOT have a menu_link column.
        // Routes are resolved from controller + action via the route map below.

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
            'branchdemand' => $this->resolveBranchDemandRoute($action),
            'damage' => 'admin.damages.index',
            'moneytransfer' => 'admin.money-transfers.index',
            'otherincome' => 'admin.other-incomes.index',
            'otherexpense' => 'admin.other-expenses.index',
            'manualjournal' => 'admin.manual-journals.index',
            'accountingperiod' => $action === 'year_end' ? 'admin.accounting.year-end' : 'admin.accounting.period-close',
            'customertransaction' => 'admin.customer-payments.index',
            'suppliertransaction' => 'admin.supplier-transactions.index',
            'employeetransaction' => 'admin.employee-transactions.index',
            'moneytransfer' => 'admin.money-transfers.index',
            'reconciliation' => 'admin.reconciliation.index',
            'report' => 'admin.reports.index',
            'budget' => $this->resolveBudgetRoute($action),
            'dimension' => $this->resolveDimensionRoute($action),
            'fiscalyear' => $this->resolveFiscalYearRoute($action),
            'consolidation' => $this->resolveConsolidationRoute($action),
            'bankreconciliation' => $this->resolveBankReconciliationRoute($action),
            'fixedasset' => $this->resolveFixedAssetRoute($action),
            // WORKFLOWS-AUDIT-1 (G-186): Approval Queue + Workflows menus.
            'approval' => $action === 'workflows' ? 'admin.approvals.workflows' : 'admin.approvals.queue',
            // WORKFLOWS-AUDIT-2 (G-185): Notification Rules sidebar menu.
            'notification' => 'admin.notifications.rules',
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
     * Resolve the Branch Demand action to a Laravel named route.
     * Phase 9 — supports all sub-menu actions.
     */
    private function resolveBranchDemandRoute(string $action): string
    {
        $actionMap = [
            'index'            => 'admin.branch-demands.index',
            'pending'          => 'admin.branch-demands.pending',
            'pending_receipt'  => 'admin.branch-demands.pending-receipt',
            'weekly'           => 'admin.branch-demands.weekly-report',
            'checklist'        => 'admin.branch-demands.checklist',
            'reconcile'        => 'admin.branch-demands.reconcile',
            'price_range'      => 'admin.branch-demands.price-range-comparison',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.branch-demands.index';
    }

    /**
     * Resolve the Budget action to a Laravel named route.
     * Phase 6 — supports budget list and variance report.
     */
    private function resolveBudgetRoute(string $action): string
    {
        $actionMap = [
            'index'    => 'admin.budgets.index',
            'variance' => 'admin.budgets.variance',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.budgets.index';
    }

    /**
     * Resolve the Dimension action to a Laravel named route.
     * Phase 6 — supports dimensions list, segment P&L, and segment BS.
     */
    private function resolveDimensionRoute(string $action): string
    {
        $actionMap = [
            'index'       => 'admin.dimensions.index',
            'segment_pnl' => 'admin.dimensions.segment-pnl',
            'segment_bs'  => 'admin.dimensions.segment-bs',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.dimensions.index';
    }

    /**
     * Resolve the Fiscal Year action to a Laravel named route.
     * Phase 7 — supports fiscal year list, close log.
     */
    private function resolveFiscalYearRoute(string $action): string
    {
        $actionMap = [
            'index'     => 'admin.fiscal-years.index',
            'close_log' => 'admin.fiscal-years.close-log',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.fiscal-years.index';
    }

    /**
     * Resolve the Consolidation action to a Laravel named route.
     * Phase 8 — supports consolidation runs, reports, rules, companies.
     */
    private function resolveConsolidationRoute(string $action): string
    {
        $actionMap = [
            'index'            => 'admin.consolidation.index',
            'consolidated_tb'  => 'admin.consolidation.consolidated-tb',
            'consolidated_bs'  => 'admin.consolidation.consolidated-bs',
            'consolidated_pnl' => 'admin.consolidation.consolidated-pnl',
            'reconciliation'   => 'admin.consolidation.reconciliation',
            'rules'            => 'admin.consolidation.rules',
            'companies'        => 'admin.consolidation.companies',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.consolidation.index';
    }

    /**
     * Resolve the Bank Reconciliation action to a Laravel named route.
     * Phase 9.3 — supports reconciliation runs, import, and unreconciled entries.
     */
    private function resolveBankReconciliationRoute(string $action): string
    {
        $actionMap = [
            'index'            => 'admin.bank-reconciliation.index',
            'import_statement' => 'admin.bank-reconciliation.import-statement-page',
            'unreconciled'     => 'admin.bank-reconciliation.unreconciled',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.bank-reconciliation.index';
    }

    /**
     * Resolve the Fixed Asset action to a Laravel named route.
     * Phase 9.4 — supports asset register, depreciation, disposals.
     */
    private function resolveFixedAssetRoute(string $action): string
    {
        $actionMap = [
            'index'        => 'admin.fixed-assets.index',
            'depreciation' => 'admin.fixed-assets.depreciation',
            'disposals'    => 'admin.fixed-assets.disposals',
        ];

        return $actionMap[strtolower($action)] ?? 'admin.fixed-assets.index';
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
