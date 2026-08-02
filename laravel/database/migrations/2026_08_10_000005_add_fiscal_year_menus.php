<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: Add Fiscal Years menu under Accounting.
 *
 * Menu structure:
 *   Accounting
 *     ├── ... (existing items)
 *     └── Fiscal Years (parent)
 *           ├── Year List
 *           └── Close Log
 *
 * Also grants superadmin user full permissions for all new menus.
 */
return new class extends Migration
{
    private array $menuDefs = [
        // Parent menu
        ['Fiscal Years',  'fiscalyear', null,        'fas fa-calendar-alt',  null,         5],
        // Children
        ['Year List',     'fiscalyear', 'index',     'fas fa-list',          'Fiscal Years', 1],
        ['Close Log',     'fiscalyear', 'close_log', 'fas fa-history',       'Fiscal Years', 2],
    ];

    public function up(): void
    {
        // ── 1. Resolve the Accounting parent menu ────────────────────
        $accountingMenu = DB::table('menus')
            ->where('menu_label', 'Accounting')
            ->whereNull('controller')
            ->where('parent_id', 0)
            ->first();

        if (!$accountingMenu) {
            echo "  ! Accounting parent menu not found — skipping.\n";
            return;
        }

        $accountingParentId = $accountingMenu->id;
        $labelToId = [];

        // Pass 1: Insert parent menus
        foreach ($this->menuDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder] = $def;

            if ($parentLabel !== null) {
                continue;
            }

            DB::table('menus')->updateOrInsert(
                ['menu_label' => $label, 'controller' => $controller],
                [
                    'action'     => $action,
                    'icon'       => $icon,
                    'parent_id'  => $accountingParentId,
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

        // Pass 2: Insert child menus
        foreach ($this->menuDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder] = $def;

            if ($parentLabel === null) {
                continue;
            }

            $parentId = $labelToId[$parentLabel] ?? $accountingParentId;

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

            $id = DB::table('menus')
                ->where('menu_label', $label)
                ->where('controller', $controller)
                ->value('id');

            if ($id) {
                $labelToId[$label] = $id;
            }
        }

        // ── 2. Grant superadmin permissions ──────────────────────────
        $superAdminEmp = DB::table('employees')
            ->where('role', 'superadmin')
            ->first();

        if (!$superAdminEmp) {
            $superAdminEmp = DB::table('employees')
                ->where('employee_code', 'E0001')
                ->first();
        }

        if (!$superAdminEmp) {
            return;
        }

        $user = DB::table('users')
            ->where('employee_id', $superAdminEmp->id)
            ->first();

        if (!$user) {
            return;
        }

        $menuLabels = array_map(fn($def) => $def[0], $this->menuDefs);
        $newMenuIds = DB::table('menus')
            ->whereIn('menu_label', $menuLabels)
            ->pluck('id')
            ->toArray();

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

        echo "  ✓ Fiscal Years menu added under Accounting with superadmin permissions.\n";
    }

    public function down(): void
    {
        $menuLabels = array_map(fn($def) => $def[0], $this->menuDefs);

        $menuIds = DB::table('menus')
            ->whereIn('menu_label', $menuLabels)
            ->pluck('id')
            ->toArray();

        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        // Delete children first
        $parentIds = DB::table('menus')
            ->whereIn('menu_label', ['Fiscal Years'])
            ->pluck('id')
            ->toArray();

        if (!empty($parentIds)) {
            DB::table('menus')->whereIn('parent_id', $parentIds)->delete();
        }

        DB::table('menus')->whereIn('menu_label', $menuLabels)->delete();
    }
};
