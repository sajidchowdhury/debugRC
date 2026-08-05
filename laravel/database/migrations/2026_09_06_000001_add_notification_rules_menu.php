<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOWS-AUDIT-2 (G-185) — Add Notification Rules sidebar menu.
 *
 * Resolves:
 *   - G-185 (notification-workflow G9 MAJOR): No sidebar menu entry for
 *     /admin/notifications/rules. The ONLY UI entry point was the gear icon
 *     in the bell dropdown (top-nav.blade.php:135-141 @can('view-
 *     notification-rules')). Admins had no sidebar shortcut — inconsistent
 *     with every other admin module. Same gap pattern as Phase 14 G11
 *     (Pattern A approval menu missing), resolved in WORKFLOWS-AUDIT-1
 *     via 2026_09_05_000009_add_approval_queue_menus.php.
 *
 * Adds a single "Notification Rules" menu under the existing
 * "Administration" parent (menu id=2 in basic_data_snapshot.sql):
 *
 *   Administration
 *     ├── ... (existing items: branches, warehouses, products, …,
 *     │         Approval Queue, …)
 *     └── Notification Rules   (controller='notification', action='rules')
 *                               → /admin/notifications/rules
 *
 * Route resolution: MenuService::resolveMenuUrl() routeMap is extended (in
 * a separate code edit) to map controller='notification' → the
 * admin.notifications.rules named route. Same pattern as the
 * WORKFLOWS-AUDIT-1 approval-queue menu (controller='approval').
 *
 * Permissions: superadmin (E0001) gets full can_view + can_edit. Other
 * admins gain access via the `role:admin` route middleware + the
 * `view-notification-rules` Gate (AppServiceProvider L71-73, which returns
 * true for admin + superadmin via User::isAdmin()). The menu permission
 * row is the menu-visibility gate, separate from the route middleware.
 *
 * Idempotent: uses updateOrInsert on (controller, action) so re-running
 * is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Find the Administration parent menu (same parent the
        //    approval-queue menu uses — menu id=2 in basic_data_snapshot.sql).
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

        // 2. Insert the "Notification Rules" menu (controller='notification',
        //    action='rules'). sort_order=96 — after Approval Queue (95) and
        //    before the Settings tail (100+).
        DB::table('menus')->updateOrInsert(
            ['controller' => 'notification', 'action' => 'rules'],
            [
                'menu_label' => 'Notification Rules',
                'parent_id'  => $adminParentId,
                'icon'       => 'fas fa-bell',
                'sort_order' => 96,
                'is_active'  => true,
                'updated_at' => now(),
            ]
        );

        // 3. Grant superadmin (E0001) full can_view + can_edit — mirrors
        //    the approval-queue migration's grantSuperAdminPermissions().
        $this->grantSuperAdminPermissions();
    }

    /**
     * Grant the superadmin user full permissions for the new menu.
     * Mirrors 2026_09_05_000009_add_approval_queue_menus.php.
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

        $menu = DB::table('menus')
            ->where('controller', 'notification')
            ->where('action', 'rules')
            ->first();

        if (!$menu) {
            return;
        }

        $pdo = DB::connection()->getPdo();

        $pdo->exec("
            INSERT INTO user_menu_permissions (user_id, menu_id, can_view, can_edit, created_at, updated_at)
            VALUES ({$user->id}, {$menu->id}, TRUE, TRUE, NOW(), NOW())
            ON CONFLICT (user_id, menu_id) DO UPDATE
            SET can_view   = TRUE,
                can_edit   = TRUE,
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')
            ->where('controller', 'notification')
            ->pluck('id')
            ->toArray();

        if (!empty($menuIds)) {
            DB::table('user_menu_permissions')
                ->whereIn('menu_id', $menuIds)
                ->delete();
        }

        DB::table('menus')
            ->where('controller', 'notification')
            ->delete();
    }
};
