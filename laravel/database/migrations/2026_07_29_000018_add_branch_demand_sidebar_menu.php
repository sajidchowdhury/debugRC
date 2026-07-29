<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branch Demand Sidebar Menu — Phase 9 (UI, Views & Frontend).
 *
 * Adds the "Branch Demand" parent menu and its sub-items to the `menus` table
 * so the DB-driven sidebar (MenuService) renders them automatically.
 *
 * Menu structure:
 *   Branch Demand (parent)
 *     ├── My Demands         → admin.branch-demands.index
 *     ├── Pending for Me     → admin.branch-demands.pending
 *     ├── Receipt Confirmations → admin.branch-demands.pending-receipt
 *     ├── Weekly Report      → admin.branch-demands.weekly-report
 *     └── Audit Checklist    → admin.branch-demands.checklist
 *
 * The sort_order is set to place the menu after existing inventory items.
 * The controller column uses 'branchdemand' to match MenuService's route map.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find the max sort_order to place the new menu after existing items
        $maxSort = DB::table('menus')->max('sort_order') ?? 0;

        // Parent menu: Branch Demand
        $parentId = DB::table('menus')->insertGetId([
            'menu_label'  => 'Branch Demand',
            'controller'  => 'branchdemand',
            'action'      => 'index',
            'icon'        => 'fas fa-right-left',
            'sort_order'  => $maxSort + 10,
            'parent_id'   => null,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Sub-items
        $subItems = [
            [
                'menu_label'  => 'My Demands',
                'controller'  => 'branchdemand',
                'action'      => 'index',
                'icon'        => 'fas fa-list',
                'sort_order'  => 1,
            ],
            [
                'menu_label'  => 'Pending for Me',
                'controller'  => 'branchdemand',
                'action'      => 'pending',
                'icon'        => 'fas fa-inbox',
                'sort_order'  => 2,
            ],
            [
                'menu_label'  => 'Receipt Confirmations',
                'controller'  => 'branchdemand',
                'action'      => 'pending_receipt',
                'icon'        => 'fas fa-clipboard-check',
                'sort_order'  => 3,
            ],
            [
                'menu_label'  => 'Weekly Report',
                'controller'  => 'branchdemand',
                'action'      => 'weekly',
                'icon'        => 'fas fa-chart-bar',
                'sort_order'  => 4,
            ],
            [
                'menu_label'  => 'Audit Checklist',
                'controller'  => 'branchdemand',
                'action'      => 'checklist',
                'icon'        => 'fas fa-clipboard-check',
                'sort_order'  => 5,
            ],
            [
                'menu_label'  => 'Reconciliation',
                'controller'  => 'branchdemand',
                'action'      => 'reconcile',
                'icon'        => 'fas fa-balance-scale',
                'sort_order'  => 6,
            ],
        ];

        foreach ($subItems as $item) {
            DB::table('menus')->insert([
                'menu_label'  => $item['menu_label'],
                'controller'  => $item['controller'],
                'action'      => $item['action'],
                'icon'        => $item['icon'],
                'sort_order'  => $item['sort_order'],
                'parent_id'   => $parentId,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Update the MenuService route map to handle the new sub-item actions
        // The route map already handles 'branchdemand' → we need to add more action mappings
        // This is handled in the MenuService code update (separate change).
    }

    public function down(): void
    {
        // Find the parent menu
        $parent = DB::table('menus')
            ->where('controller', 'branchdemand')
            ->whereNull('parent_id')
            ->first();

        if ($parent) {
            // Delete children first
            DB::table('menus')->where('parent_id', $parent->id)->delete();
            // Delete parent
            DB::table('menus')->where('id', $parent->id)->delete();
        }
    }
};
