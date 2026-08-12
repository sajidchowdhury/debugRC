<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add missing menu entries for routes that exist in web.php but have no
 * corresponding row in the menus table, plus grant superadmin permissions.
 *
 * Gap analysis (2026-08-12):
 *   HIGH   — Stock Transactions, Price Range Comparison, Reconciliation
 *   MEDIUM — Purchase Audit, Commission Rules, Product Categories, Product Groups
 *   LOW    — Archive, Global Audit, System Health, System Policy, Shadow Mode, BD Shadow
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Helper: find parent menu id ─────────────────────
        $inventoryId   = DB::table('menus')->where('menu_label', 'Inventory')->whereNull('parent_id')->orWhere('parent_id', 0)->value('id');
        $accountingId  = DB::table('menus')->where('menu_label', 'Accounting')->whereNull('parent_id')->orWhere('parent_id', 0)->value('id');
        $purchaseId    = DB::table('menus')->where('menu_label', 'Purchase')->whereNull('parent_id')->orWhere('parent_id', 0)->value('id');
        $adminId       = DB::table('menus')->where('menu_label', 'Administration')->whereNull('parent_id')->orWhere('parent_id', 0)->value('id');
        $bdId          = DB::table('menus')->where('menu_label', 'Branch Demand')->where('controller', 'branchdemand')->whereNull('parent_id')->orWhere('parent_id', 0)->value('id');

        // Fix: parent_id can be 0 or NULL depending on seeder version
        $inventoryId   = $inventoryId   ?? DB::table('menus')->where('menu_label', 'Inventory')->where('parent_id', 0)->value('id');
        $accountingId  = $accountingId  ?? DB::table('menus')->where('menu_label', 'Accounting')->where('parent_id', 0)->value('id');
        $purchaseId    = $purchaseId    ?? DB::table('menus')->where('menu_label', 'Purchase')->where('parent_id', 0)->value('id');
        $adminId       = $adminId       ?? DB::table('menus')->where('menu_label', 'Administration')->where('parent_id', 0)->value('id');
        $bdId          = $bdId          ?? DB::table('menus')->where('menu_label', 'Branch Demand')->where('parent_id', 0)->value('id');

        $newMenuIds = [];

        // ═══════════════════════════════════════════════════════
        // 🔴 HIGH PRIORITY
        // ═══════════════════════════════════════════════════════

        // 1. Stock Transactions — under Inventory
        if ($inventoryId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Stock Transactions',
                'controller' => 'stocktransaction',
                'action'     => 'index',
                'icon'       => 'fas fa-exchange-alt',
                'parent_id'  => $inventoryId,
                'sort_order' => 6,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 2. Price Range Comparison — under Branch Demand
        if ($bdId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Price Range Comparison',
                'controller' => 'branchdemand',
                'action'     => 'price_range',
                'icon'       => 'fas fa-chart-line',
                'parent_id'  => $bdId,
                'sort_order' => 7,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 3. Reconciliation — under Accounting
        if ($accountingId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Reconciliation',
                'controller' => 'reconciliation',
                'action'     => 'index',
                'icon'       => 'fas fa-balance-scale',
                'parent_id'  => $accountingId,
                'sort_order' => 6,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // ═══════════════════════════════════════════════════════
        // 🟡 MEDIUM PRIORITY
        // ═══════════════════════════════════════════════════════

        // 4. Purchase Audit — under Purchase
        if ($purchaseId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Purchase Audit',
                'controller' => 'purchaseaudit',
                'action'     => 'checklist',
                'icon'       => 'fas fa-clipboard-check',
                'parent_id'  => $purchaseId,
                'sort_order' => 4,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 5. Commission Rules — under Administration (or Sales)
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Commission Rules',
                'controller' => 'commission',
                'action'     => 'index',
                'icon'       => 'fas fa-percent',
                'parent_id'  => $adminId,
                'sort_order' => 97,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 6. Product Categories — under Administration
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Product Categories',
                'controller' => 'productcategory',
                'action'     => 'index',
                'icon'       => 'fas fa-tags',
                'parent_id'  => $adminId,
                'sort_order' => 98,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 7. Product Groups — under Administration
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Product Groups',
                'controller' => 'productgroup',
                'action'     => 'index',
                'icon'       => 'fas fa-layer-group',
                'parent_id'  => $adminId,
                'sort_order' => 99,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // ═══════════════════════════════════════════════════════
        // 🟢 LOW PRIORITY (Admin/Superadmin tools)
        // ═══════════════════════════════════════════════════════

        // 8. Archive
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Archive',
                'controller' => 'archive',
                'action'     => 'index',
                'icon'       => 'fas fa-archive',
                'parent_id'  => $adminId,
                'sort_order' => 100,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 9. Global Audit
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Global Audit',
                'controller' => 'globalaudit',
                'action'     => 'index',
                'icon'       => 'fas fa-search',
                'parent_id'  => $adminId,
                'sort_order' => 101,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 10. System Health
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'System Health',
                'controller' => 'systemhealth',
                'action'     => 'index',
                'icon'       => 'fas fa-heartbeat',
                'parent_id'  => $adminId,
                'sort_order' => 102,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 11. System Policy (Compliance)
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'System Policy',
                'controller' => 'compliance',
                'action'     => 'index',
                'icon'       => 'fas fa-shield-alt',
                'parent_id'  => $adminId,
                'sort_order' => 103,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 12. Shadow Mode (Cutover comparison)
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'Shadow Mode',
                'controller' => 'shadowmode',
                'action'     => 'index',
                'icon'       => 'fas fa-clone',
                'parent_id'  => $adminId,
                'sort_order' => 104,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // 13. Branch Demand Shadow (Cutover comparison)
        if ($adminId) {
            $id = DB::table('menus')->insertGetId([
                'menu_label' => 'BD Shadow Mode',
                'controller' => 'branchdemandshadow',
                'action'     => 'index',
                'icon'       => 'fas fa-clone',
                'parent_id'  => $adminId,
                'sort_order' => 105,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $newMenuIds[] = $id;
        }

        // ═══════════════════════════════════════════════════════
        // Grant superadmin permissions for ALL new menus
        // ═══════════════════════════════════════════════════════

        $superadminUserIds = DB::table('users')
            ->join('employees', 'users.employee_id', '=', 'employees.id')
            ->where('employees.role', 'superadmin')
            ->pluck('users.id');

        foreach ($superadminUserIds as $userId) {
            foreach ($newMenuIds as $menuId) {
                DB::table('user_menu_permissions')->updateOrInsert(
                    ['user_id' => $userId, 'menu_id' => $menuId],
                    ['can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // Also grant admin role users view access to HIGH + MEDIUM priority menus
        $adminUserIds = DB::table('users')
            ->join('employees', 'users.employee_id', '=', 'employees.id')
            ->whereIn('employees.role', ['admin', 'manager'])
            ->pluck('users.id');

        // First 7 items are HIGH + MEDIUM priority
        $highMediumIds = array_slice($newMenuIds, 0, 7);
        foreach ($adminUserIds as $userId) {
            foreach ($highMediumIds as $menuId) {
                DB::table('user_menu_permissions')->updateOrInsert(
                    ['user_id' => $userId, 'menu_id' => $menuId],
                    ['can_view' => true, 'can_edit' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // Flush menu cache
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function down(): void
    {
        // Remove the menus we added (cascade will handle permissions if FK exists)
        $controllers = [
            'stocktransaction', 'reconciliation', 'purchaseaudit',
            'commission', 'productcategory', 'productgroup',
            'archive', 'globalaudit', 'systemhealth', 'compliance',
            'shadowmode', 'branchdemandshadow',
        ];

        DB::table('menus')
            ->whereIn('controller', $controllers)
            ->delete();

        // Also remove Price Range Comparison (uses branchdemand controller)
        DB::table('menus')
            ->where('controller', 'branchdemand')
            ->where('action', 'price_range')
            ->delete();

        \Illuminate\Support\Facades\Cache::flush();
    }
};
