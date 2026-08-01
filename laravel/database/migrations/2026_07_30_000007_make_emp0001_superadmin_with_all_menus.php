<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make employee EMP-0001 a superadmin and grant ALL active menu permissions
 * to the user account linked to that employee.
 *
 * WHY THIS EXISTS:
 *   Migration 2026_07_30_000006_make_e0001_superadmin_with_all_menus targeted
 *   employee_code = 'E0001', but the legacy migration that imported employees
 *   (2026_07_30_000005_migrate_legacy_admin_and_employee_data) auto-generated
 *   employee codes as 'EMP-{id padded to 4}' — e.g. legacy setup_employee.id=1
 *   becomes 'EMP-0001'. The legacy `code` column (which probably held 'E0001')
 *   was NOT carried over. So 'E0001' does not exist in the employees table,
 *   and the previous migration was a no-op.
 *
 *   This migration targets 'EMP-0001' (legacy id=1, the primary admin in
 *   virtually every legacy install) and does the actual promotion.
 *
 * What this migration does:
 *   1. UPDATE employees SET role = 'superadmin' WHERE employee_code = 'EMP-0001'
 *      — This alone is enough for app-level access because:
 *          • User::isAdmin() returns true for role in ['admin','superadmin']
 *          • MenuService::buildMenuTree() bypasses the permission filter for admins
 *          • EnsureMenuPermission middleware bypasses for admins
 *      So the user will see every active menu in the sidebar and can hit every route.
 *
 *   2. INSERT user_menu_permissions (can_view=1, can_edit=1) for every active
 *      menu, for the user whose employee_id = (EMP-0001's employee id).
 *      — Belt-and-suspenders: even if the admin-bypass is ever tightened, the
 *        explicit permission rows will still let EMP-0001 see every menu.
 *      — Idempotent via ON CONFLICT (user_id, menu_id) DO UPDATE.
 *
 * Idempotent: safe to run multiple times.
 * Reversible: down() resets role to 'admin' (NOT NULL CHECK constraint requires
 * a valid role) and removes the permission rows for that user.
 */
return new class extends Migration
{
    private const TARGET_EMPLOYEE_CODE = 'EMP-0001';
    private const FALLBACK_ROLE = 'admin';

    public function up(): void
    {
        $pdo = DB::connection()->getPdo();

        // ---------------------------------------------------------------
        // 1. Resolve the employee + user for EMP-0001.
        // ---------------------------------------------------------------
        $emp = DB::table('employees')
            ->where('employee_code', self::TARGET_EMPLOYEE_CODE)
            ->first();

        if (!$emp) {
            echo "  ! Employee '" . self::TARGET_EMPLOYEE_CODE . "' not found — skipping.\n";
            return;
        }

        $user = DB::table('users')->where('employee_id', $emp->id)->first();

        if (!$user) {
            echo "  ! No user account linked to employee '" . self::TARGET_EMPLOYEE_CODE
                . "' (employee_id={$emp->id}) — role will still be upgraded, "
                . "but menu permissions cannot be granted without a user row.\n";
        }

        // ---------------------------------------------------------------
        // 2. Promote the employee to superadmin.
        // ---------------------------------------------------------------
        DB::table('employees')
            ->where('id', $emp->id)
            ->update([
                'role'       => 'superadmin',
                'updated_at' => now(),
            ]);

        echo "  ✓ Employee '{$emp->employee_code}' (id={$emp->id}) promoted to superadmin.\n";
        echo "    • Name : {$emp->name}\n";
        echo "    • Role : {$emp->role} → superadmin\n";

        if (!$user) {
            return;
        }

        echo "    • User : id={$user->id}, username={$user->username}\n";

        // ---------------------------------------------------------------
        // 3. Grant ALL active menus (can_view=1, can_edit=1) to that user.
        //    Uses PostgreSQL ON CONFLICT to be idempotent.
        // ---------------------------------------------------------------
        $sql = <<<'SQL'
            INSERT INTO user_menu_permissions (user_id, menu_id, can_view, can_edit, created_at, updated_at)
            SELECT :uid, m.id, TRUE, TRUE, NOW(), NOW()
            FROM menus m
            WHERE m.is_active = TRUE
            ON CONFLICT (user_id, menu_id) DO UPDATE
            SET can_view   = TRUE,
                can_edit   = TRUE,
                updated_at = NOW()
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', $user->id, \PDO::PARAM_INT);
        $stmt->execute();

        $granted = DB::table('user_menu_permissions')
            ->where('user_id', $user->id)
            ->where('can_view', true)
            ->count();

        $totalActive = DB::table('menus')->where('is_active', true)->count();

        echo "  ✓ Granted {$granted}/{$totalActive} active menu permissions to user id={$user->id}.\n";
    }

    public function down(): void
    {
        $emp = DB::table('employees')
            ->where('employee_code', self::TARGET_EMPLOYEE_CODE)
            ->first();

        if (!$emp) {
            return;
        }

        // Demote back to the fallback role (column has a CHECK constraint,
        // so we cannot set NULL).
        DB::table('employees')
            ->where('id', $emp->id)
            ->update([
                'role'       => self::FALLBACK_ROLE,
                'updated_at' => now(),
            ]);

        $user = DB::table('users')->where('employee_id', $emp->id)->first();

        if ($user) {
            DB::table('user_menu_permissions')
                ->where('user_id', $user->id)
                ->delete();
        }

        echo "  ↺ Reverted: employee '{$emp->employee_code}' back to '" . self::FALLBACK_ROLE
            . "', menu permissions cleared for linked user.\n";
    }
};
