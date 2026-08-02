<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8: Add Intercompany & Consolidation menus
 *
 * Adds menu structure under Administration:
 *   Administration
 *     ├── Consolidation (parent)
 *     │     ├── Consolidation Runs    → admin.consolidation.index
 *     │     ├── Consolidated TB       → admin.consolidation.consolidated-tb
 *     │     ├── Consolidated BS       → admin.consolidation.consolidated-bs
 *     │     ├── Consolidated P&L      → admin.consolidation.consolidated-pnl
 *     │     ├── IC Reconciliation     → admin.consolidation.reconciliation
 *     │     ├── Elimination Rules     → admin.consolidation.rules
 *     │     └── Companies             → admin.consolidation.companies
 *
 * Also grants superadmin can_view=true, can_edit=true for all new menus.
 *
 * Uses two-pass insert: parents first, then children with resolved parent_ids.
 * Idempotent using updateOrInsert and PostgreSQL ON CONFLICT upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Find the Administration parent menu ──
        $adminMenu = DB::table('menus')
            ->whereRaw("LOWER(menu_label) LIKE '%administration%'")
            ->orWhereRaw("LOWER(menu_label) LIKE '%admin%'")
            ->whereNull('parent_id')
            ->first();

        $adminParentId = $adminMenu?->id ?? 0;

        // If no admin parent, we'll create top-level menus
        if (!$adminMenu) {
            $adminParentId = 0;
        }

        // ── Pass 1: Insert parent menu (Consolidation) ──
        $consolidationParentId = DB::table('menus')->updateOrInsert(
            [
                'parent_id' => $adminParentId,
                'controller' => 'consolidation',
                'action' => 'index',
            ],
            [
                'menu_label' => 'Consolidation',
                'icon' => 'fas fa-layer-group',
                'sort_order' => 85,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Get the actual ID (updateOrInsert returns boolean in some Laravel versions)
        $consolidationParent = DB::table('menus')
            ->where('parent_id', $adminParentId)
            ->where('controller', 'consolidation')
            ->where('action', 'index')
            ->first();

        $consolidationParentId = $consolidationParent?->id;

        // ── Pass 2: Insert child menus ──
        $childMenus = [
            ['menu_label' => 'Consolidation Runs', 'controller' => 'consolidation', 'action' => 'index', 'icon' => 'fas fa-play-circle', 'sort_order' => 10],
            ['menu_label' => 'Consolidated TB', 'controller' => 'consolidation', 'action' => 'consolidated_tb', 'icon' => 'fas fa-balance-scale', 'sort_order' => 20],
            ['menu_label' => 'Consolidated BS', 'controller' => 'consolidation', 'action' => 'consolidated_bs', 'icon' => 'fas fa-building-columns', 'sort_order' => 30],
            ['menu_label' => 'Consolidated P&L', 'controller' => 'consolidation', 'action' => 'consolidated_pnl', 'icon' => 'fas fa-chart-pie', 'sort_order' => 40],
            ['menu_label' => 'IC Reconciliation', 'controller' => 'consolidation', 'action' => 'reconciliation', 'icon' => 'fas fa-exchange-alt', 'sort_order' => 50],
            ['menu_label' => 'Elimination Rules', 'controller' => 'consolidation', 'action' => 'rules', 'icon' => 'fas fa-cogs', 'sort_order' => 60],
            ['menu_label' => 'Companies', 'controller' => 'consolidation', 'action' => 'companies', 'icon' => 'fas fa-building', 'sort_order' => 70],
        ];

        foreach ($childMenus as $child) {
            DB::table('menus')->updateOrInsert(
                [
                    'parent_id' => $consolidationParentId,
                    'controller' => $child['controller'],
                    'action' => $child['action'],
                ],
                [
                    'menu_label' => $child['menu_label'],
                    'icon' => $child['icon'],
                    'sort_order' => $child['sort_order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // ── Grant superadmin permissions ──
        $this->grantSuperadminPermissions();
    }

    /**
     * Grant can_view and can_edit for all new consolidation menus to the superadmin user.
     */
    private function grantSuperadminPermissions(): void
    {
        // Find superadmin user (role='superadmin' or employee_code='E0001')
        $superadmin = DB::table('users')
            ->join('employees', 'employees.user_id', '=', 'users.id')
            ->where(function ($q) {
                $q->where('employees.role', 'superadmin')
                  ->orWhere('employees.employee_code', 'E0001');
            })
            ->select('users.id')
            ->first();

        if (!$superadmin) {
            return;
        }

        // Get all consolidation menus
        $menus = DB::table('menus')
            ->where('controller', 'consolidation')
            ->get();

        foreach ($menus as $menu) {
            DB::table('user_menu_permissions')->upsert(
                [
                    'user_id' => $superadmin->id,
                    'menu_id' => $menu->id,
                    'can_view' => true,
                    'can_edit' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['user_id', 'menu_id'],
                ['can_view' => true, 'can_edit' => true, 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Get all consolidation menu IDs
        $menuIds = DB::table('menus')
            ->where('controller', 'consolidation')
            ->pluck('id')
            ->toArray();

        // Delete permissions
        DB::table('user_menu_permissions')
            ->whereIn('menu_id', $menuIds)
            ->delete();

        // Delete menus
        DB::table('menus')
            ->where('controller', 'consolidation')
            ->delete();
    }
};
