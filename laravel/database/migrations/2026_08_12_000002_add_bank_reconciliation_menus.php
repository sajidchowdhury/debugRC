<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.3: Add Bank Reconciliation menus under Administration.
 *
 * Creates a two-level menu structure:
 *
 *   Administration
 *     ├── ... (existing items)
 *     └── Bank Reconciliation   (parent)
 *           ├── Reconciliations  (list of all reconciliation runs)
 *           ├── Import Statement  (upload bank statement CSV)
 *           └── Unreconciled     (unreconciled bank entries)
 *
 * Also grants the superadmin user (E0001) full permissions for all new menus.
 *
 * Idempotent: uses updateOrInsert and PostgreSQL ON CONFLICT upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Find the Administration parent menu ─────────────────
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

        // ── 2. Insert parent menu (Bank Reconciliation) ─────────────
        DB::table('menus')->updateOrInsert(
            ['menu_label' => 'Bank Reconciliation', 'controller' => 'bankreconciliation'],
            [
                'action'     => 'index',
                'icon'       => 'fas fa-university',
                'parent_id'  => $adminParentId,
                'sort_order' => 90,
                'is_active'  => true,
                'updated_at' => now(),
            ]
        );

        $parentMenu = DB::table('menus')
            ->where('menu_label', 'Bank Reconciliation')
            ->where('controller', 'bankreconciliation')
            ->first();

        $parentMenuId = $parentMenu?->id;

        // ── 3. Insert child menus ───────────────────────────────────
        $childMenus = [
            ['menu_label' => 'Reconciliations',  'controller' => 'bankreconciliation', 'action' => 'index',             'icon' => 'fas fa-list',            'sort_order' => 10],
            ['menu_label' => 'Import Statement',  'controller' => 'bankreconciliation', 'action' => 'import_statement',  'icon' => 'fas fa-file-upload',     'sort_order' => 20],
            ['menu_label' => 'Unreconciled',      'controller' => 'bankreconciliation', 'action' => 'unreconciled',      'icon' => 'fas fa-exclamation-triangle', 'sort_order' => 30],
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
            ->where('controller', 'bankreconciliation')
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
            ->where('controller', 'bankreconciliation')
            ->pluck('id')
            ->toArray();

        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        DB::table('menus')
            ->where('controller', 'bankreconciliation')
            ->delete();
    }
};
