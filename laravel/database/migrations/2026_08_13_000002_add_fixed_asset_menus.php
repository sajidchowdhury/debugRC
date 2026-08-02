<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.4: Add Fixed Asset & Depreciation menus under Accounting.
 *
 * Creates a two-level menu structure:
 *
 *   Accounting
 *     ├── ... (existing items)
 *     └── Fixed Assets   (parent)
 *           ├── Asset Register   (list of all fixed assets)
 *           ├── Depreciation     (run monthly depreciation)
 *           └── Disposals        (asset disposal records)
 *
 * Also grants the superadmin user (E0001) full permissions for all new menus.
 *
 * Idempotent: uses updateOrInsert and PostgreSQL ON CONFLICT upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Find the Accounting parent menu ──────────────────────
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

        // ── 2. Insert parent menu (Fixed Assets) ────────────────────
        DB::table('menus')->updateOrInsert(
            ['menu_label' => 'Fixed Assets', 'controller' => 'fixedasset'],
            [
                'action'     => 'index',
                'icon'       => 'fas fa-building',
                'parent_id'  => $accountingParentId,
                'sort_order' => 95,
                'is_active'  => true,
                'updated_at' => now(),
            ]
        );

        $parentMenu = DB::table('menus')
            ->where('menu_label', 'Fixed Assets')
            ->where('controller', 'fixedasset')
            ->first();

        $parentMenuId = $parentMenu?->id;

        // ── 3. Insert child menus ───────────────────────────────────
        $childMenus = [
            ['menu_label' => 'Asset Register',  'controller' => 'fixedasset', 'action' => 'index',      'icon' => 'fas fa-list',           'sort_order' => 10],
            ['menu_label' => 'Depreciation',     'controller' => 'fixedasset', 'action' => 'depreciation', 'icon' => 'fas fa-chart-line',   'sort_order' => 20],
            ['menu_label' => 'Disposals',        'controller' => 'fixedasset', 'action' => 'disposals',    'icon' => 'fas fa-hand-holding-usd', 'sort_order' => 30],
        ];

        foreach ($childMenus as $child) {
            DB::table('menus')->updateOrInsert(
                [
                    'parent_id'  => $parentMenuId,
                    'controller' => $child['controller'],
                    'action'     => $child['action'],
                ],
                [
                    'menu_label' => $child['menu_label'],
                    'icon'       => $child['icon'],
                    'sort_order' => $child['sort_order'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ── 4. Grant superadmin permissions ─────────────────────────
        $this->grantSuperAdminPermissions();
    }

    /**
     * Grant the superadmin user full permissions for all new menus.
     */
    private function grantSuperAdminPermissions(): void
    {
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

        $menus = DB::table('menus')
            ->where('controller', 'fixedasset')
            ->get();

        $pdo = DB::connection()->getPdo();

        foreach ($menus as $menu) {
            $pdo->exec("
                INSERT INTO user_menu_permissions (user_id, menu_id, can_view, can_edit, created_at, updated_at)
                VALUES ({$user->id}, {$menu->id}, TRUE, TRUE, NOW(), NOW())
                ON CONFLICT (user_id, menu_id) DO UPDATE
                SET can_view   = TRUE,
                    can_edit   = TRUE,
                    updated_at = NOW()
            ");
        }
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')
            ->where('controller', 'fixedasset')
            ->pluck('id')
            ->toArray();

        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        DB::table('menus')
            ->where('controller', 'fixedasset')
            ->delete();
    }
};
