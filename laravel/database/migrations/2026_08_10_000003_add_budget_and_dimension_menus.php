<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6: Add Budgets & Cost Centers menus under Administration.
 *
 * Creates a two-level menu structure:
 *
 *   Administration
 *     ├── ... (existing items)
 *     ├── Budgets          (parent)
 *     │     ├── Budget List
 *     │     └── Budget vs Actual
 *     └── Cost Centers     (parent)
 *           ├── Dimensions
 *           ├── Segment P&L
 *           └── Segment BS
 *
 * Also grants the superadmin user (E0001) full permissions for all new menus.
 *
 * Idempotent: uses updateOrInsert on natural key (menu_label + controller).
 */
return new class extends Migration
{
    /**
     * Menu definitions for the Budgets parent + children.
     * Format: [menu_label, controller, action, icon, parent_label, sort_order]
     */
    private array $budgetMenuDefs = [
        // Parent menu
        ['Budgets',          'budget',   null,          'fas fa-wallet',          null,      10],
        // Children
        ['Budget List',      'budget',   'index',       'fas fa-list',            'Budgets',  1],
        ['Budget vs Actual', 'budget',   'variance',    'fas fa-chart-bar',       'Budgets',  2],
    ];

    private array $dimensionMenuDefs = [
        // Parent menu
        ['Cost Centers',     'dimension', null,         'fas fa-sitemap',         null,      11],
        // Children
        ['Dimensions',       'dimension', 'index',      'fas fa-tags',            'Cost Centers', 1],
        ['Segment P&L',      'dimension', 'segment_pnl','fas fa-chart-line',      'Cost Centers', 2],
        ['Segment BS',       'dimension', 'segment_bs', 'fas fa-balance-scale',   'Cost Centers', 3],
    ];

    public function up(): void
    {
        // ── 1. Resolve the Administration parent menu ─────────────────
        $adminMenu = DB::table('menus')
            ->where('menu_label', 'Administration')
            ->whereNull('controller')
            ->where('parent_id', 0)
            ->first();

        if (!$adminMenu) {
            echo "  ! Administration parent menu not found — skipping.\n";
            return;
        }

        $adminParentId = $adminMenu->id;

        // ── 2. Insert Budgets parent + children ────────────────────────
        $labelToId = [];
        $allDefs = array_merge($this->budgetMenuDefs, $this->dimensionMenuDefs);

        // Pass 1: Insert parent menus (parent_label = null)
        foreach ($allDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder] = $def;

            if ($parentLabel !== null) {
                continue; // Skip children for now
            }

            DB::table('menus')->updateOrInsert(
                ['menu_label' => $label, 'controller' => $controller],
                [
                    'action'     => $action,
                    'icon'       => $icon,
                    'parent_id'  => $adminParentId,
                    'sort_order' => $sortOrder,
                    'is_active'  => true,
                    'updated_at' => now(),
                ]
            );

            $id = DB::table('menus')
                ->where('menu_label', $label)
                ->where('controller', $controller)
                ->value('id');

            if ($id) {
                $labelToId[$label] = $id;
            }
        }

        // Pass 2: Insert child menus, resolving parent_id by label
        foreach ($allDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder] = $def;

            if ($parentLabel === null) {
                continue; // Already inserted in pass 1
            }

            $parentId = $labelToId[$parentLabel] ?? $adminParentId;

            DB::table('menus')->updateOrInsert(
                ['menu_label' => $label, 'controller' => $controller],
                [
                    'action'     => $action,
                    'icon'       => $icon,
                    'parent_id'  => $parentId,
                    'sort_order' => $sortOrder,
                    'is_active'  => true,
                    'updated_at' => now(),
                ]
            );

            // Cache this menu's id for potential grandchildren
            $id = DB::table('menus')
                ->where('menu_label', $label)
                ->where('controller', $controller)
                ->value('id');

            if ($id) {
                $labelToId[$label] = $id;
            }
        }

        // ── 3. Grant superadmin user permissions for all new menus ─────
        $this->grantSuperAdminPermissions();

        echo "  ✓ Budgets & Cost Centers menus added under Administration.\n";
    }

    /**
     * Grant the superadmin user (E0001) full permissions for all new menus.
     * Uses PostgreSQL ON CONFLICT to be idempotent.
     */
    private function grantSuperAdminPermissions(): void
    {
        // Find the superadmin user — look for employee with role='superadmin'
        $superAdminEmp = DB::table('employees')
            ->where('role', 'superadmin')
            ->first();

        if (!$superAdminEmp) {
            // Fallback: try E0001
            $superAdminEmp = DB::table('employees')
                ->where('employee_code', 'E0001')
                ->first();
        }

        if (!$superAdminEmp) {
            echo "  ! No superadmin employee found — skipping permission grant.\n";
            return;
        }

        $user = DB::table('users')
            ->where('employee_id', $superAdminEmp->id)
            ->first();

        if (!$user) {
            echo "  ! No user account linked to superadmin employee — skipping permission grant.\n";
            return;
        }

        // Get all new menu IDs
        $newMenuLabels = array_map(function ($def) {
            return $def[0];
        }, array_merge($this->budgetMenuDefs, $this->dimensionMenuDefs));

        $newMenuIds = DB::table('menus')
            ->whereIn('menu_label', $newMenuLabels)
            ->pluck('id')
            ->toArray();

        if (empty($newMenuIds)) {
            return;
        }

        // Use raw SQL with ON CONFLICT for idempotent upsert
        $pdo = DB::connection()->getPdo();

        foreach ($newMenuIds as $menuId) {
            $pdo->exec("
                INSERT INTO user_menu_permissions (user_id, menu_id, can_view, can_edit, created_at, updated_at)
                VALUES ({$user->id}, {$menuId}, TRUE, TRUE, NOW(), NOW())
                ON CONFLICT (user_id, menu_id) DO UPDATE
                SET can_view   = TRUE,
                    can_edit   = TRUE,
                    updated_at = NOW()
            ");
        }

        $granted = count($newMenuIds);
        echo "  ✓ Granted {$granted} menu permissions to superadmin user (id={$user->id}).\n";
    }

    public function down(): void
    {
        $menuLabels = array_map(function ($def) {
            return $def[0];
        }, array_merge($this->budgetMenuDefs, $this->dimensionMenuDefs));

        // Get the menu IDs to also remove permissions
        $menuIds = DB::table('menus')
            ->whereIn('menu_label', $menuLabels)
            ->pluck('id')
            ->toArray();

        // Delete permissions for these menus
        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        // Delete children first (deeper level), then parents
        // Children have parent_id pointing to our parent menus
        $parentIds = DB::table('menus')
            ->whereIn('menu_label', ['Budgets', 'Cost Centers'])
            ->pluck('id')
            ->toArray();

        if (!empty($parentIds)) {
            // Delete grandchildren (if any)
            DB::table('menus')->whereIn('parent_id', function ($q) use ($parentIds) {
                $q->select('id')->from('menus')->whereIn('parent_id', $parentIds);
            })->delete();

            // Delete children
            DB::table('menus')->whereIn('parent_id', $parentIds)->delete();
        }

        // Delete the parent menus
        DB::table('menus')->whereIn('menu_label', $menuLabels)->delete();

        echo "  ↺ Removed Budgets & Cost Centers menus and their permissions.\n";
    }
};
