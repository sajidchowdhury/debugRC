<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOWS-AUDIT-1 (G-186) — Add Approval Queue + Workflows sidebar menus.
 *
 * Resolves:
 *   - G-186 (approval-workflow G11 MAJOR): No menu/nav entry for
 *     admin/approvals. Users can only reach the approval queue by typing
 *     the URL directly. The generic approval engine is effectively
 *     invisible to end users.
 *
 * Adds a two-level menu structure under the existing "Administration"
 * parent (menu id=2 in basic_data_snapshot.sql):
 *
 *   Administration
 *     ├── ... (existing items)
 *     └── Approval Queue   (parent, controller='approval')
 *           ├── Pending Queue    (action='index')    → /admin/approvals
 *           └── Workflows        (action='workflows') → /admin/approvals/workflows
 *
 * Route resolution: MenuService::resolveMenuUrl() routeMap is extended
 * (in a separate code edit) to map controller='approval' → the
 * admin.approvals.queue + admin.approvals.workflows named routes.
 *
 * Permissions: superadmin (E0001) gets full can_view + can_edit on both
 * menus. Other roles (accountant, manager, admin) get can_view via the
 * route middleware (role:accountant,manager,admin on the route group) —
 * the menu permission check is separate from route middleware, so we
 * also grant can_view to all non-salesman/non-hr users via a follow-up
 * permission seed (best-effort — skipped if the user table is empty).
 *
 * Idempotent: uses updateOrInsert on (controller, action) so re-running
 * is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Find the Administration parent menu.
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

        // 2. Insert the parent "Approval Queue" menu (controller='approval').
        DB::table('menus')->updateOrInsert(
            ['menu_label' => 'Approval Queue', 'controller' => 'approval'],
            [
                'action'     => 'index',
                'icon'       => 'fas fa-check-circle',
                'parent_id'  => $adminParentId,
                'sort_order' => 95,  // after Bank Reconciliation (90), before Settings
                'is_active'  => true,
                'updated_at' => now(),
            ]
        );

        $parentMenu = DB::table('menus')
            ->where('menu_label', 'Approval Queue')
            ->where('controller', 'approval')
            ->first();

        $parentMenuId = $parentMenu?->id;

        // 3. Insert child menus.
        $childMenus = [
            ['menu_label' => 'Pending Queue',  'controller' => 'approval', 'action' => 'index',     'icon' => 'fas fa-list',           'sort_order' => 10],
            ['menu_label' => 'Workflows',      'controller' => 'approval', 'action' => 'workflows', 'icon' => 'fas fa-project-diagram', 'sort_order' => 20],
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

        // 4. Grant superadmin permissions (full can_view + can_edit).
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
            ->where('controller', 'approval')
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
            ->where('controller', 'approval')
            ->pluck('id')
            ->toArray();

        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        DB::table('menus')
            ->where('controller', 'approval')
            ->delete();
    }
};
