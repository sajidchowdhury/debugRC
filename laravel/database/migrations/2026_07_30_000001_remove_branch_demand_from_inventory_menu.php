<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove "Branch Demand" from the Inventory parent menu.
 *
 * Branch Demand now has its own top-level sidebar menu (added by
 * migration 2026_07_29_000018_add_branch_demand_sidebar_menu.php)
 * with sub-items: My Demands, Pending for Me, Receipt Confirmations,
 * Weekly Report, Audit Checklist, and Reconciliation.
 *
 * This migration deactivates the legacy "Branch Demand" child item
 * that sits under the Inventory parent, so it no longer appears
 * duplicated in the sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find the Inventory parent menu
        $inventoryParent = DB::table('menus')
            ->where('menu_label', 'Inventory')
            ->whereNull('parent_id')
            ->first();

        if ($inventoryParent) {
            // Deactivate the "Branch Demand" child under Inventory
            DB::table('menus')
                ->where('menu_label', 'Branch Demand')
                ->where('controller', 'BranchDemand')
                ->where('parent_id', $inventoryParent->id)
                ->update(['is_active' => false]);

            // Also remove any user_menu_permissions for this menu item
            $menuIds = DB::table('menus')
                ->where('menu_label', 'Branch Demand')
                ->where('controller', 'BranchDemand')
                ->where('parent_id', $inventoryParent->id)
                ->pluck('id');

            if ($menuIds->isNotEmpty()) {
                DB::table('user_menu_permissions')
                    ->whereIn('menu_id', $menuIds)
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Re-activate the "Branch Demand" child under Inventory
        $inventoryParent = DB::table('menus')
            ->where('menu_label', 'Inventory')
            ->whereNull('parent_id')
            ->first();

        if ($inventoryParent) {
            DB::table('menus')
                ->where('menu_label', 'Branch Demand')
                ->where('controller', 'BranchDemand')
                ->where('parent_id', $inventoryParent->id)
                ->update(['is_active' => true]);
        }
    }
};
