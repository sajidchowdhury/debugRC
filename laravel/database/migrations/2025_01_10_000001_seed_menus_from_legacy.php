<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the menus table with all menu items from the legacy ERP.
 *
 * IMPORTANT: This migration uses ONLY the columns that exist in the
 * actual PostgreSQL menus table schema (01_auth_and_master.sql):
 *   id, parent_id, menu_label, controller, action, icon, sort_order,
 *   is_active, created_at, updated_at
 *
 * The legacy MySQL table had extra columns (menu_name, menu_link, section)
 * that were NOT carried over to the PG schema. This migration maps:
 *   legacy menu_name  → PG menu_label
 *   legacy menu_link  → (dropped — not in PG schema)
 *   legacy section    → (dropped — not in PG schema)
 *
 * The MenuService resolves routes from controller + action, so menu_link
 * is not needed. The section field was informational only (for grouping
 * in the admin UI) and can be inferred from the parent_id hierarchy.
 *
 * Idempotent: uses updateOrInsert by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each row: [id, menu_label, controller, action, icon, parent_id, sort_order, is_active]
        $menus = [
            // Dashboard
            [1, 'Dashboard', 'dashboard', 'index', 'fa fa-dashboard', 0, 1, true],

            // Administration (parent=10)
            [10, 'Administration', null, null, 'fa fa-cogs', 0, 2, true],
            [11, 'Branch', 'branch', 'index', 'fa fa-building', 10, 1, true],
            [12, 'Warehouse', 'warehouse', 'index', 'fa fa-warehouse', 10, 2, true],
            [13, 'Product', 'product', 'index', 'fa fa-box', 10, 3, true],
            [14, 'Customer', 'customer', 'index', 'fa fa-user-tie', 10, 4, true],
            [15, 'Supplier', 'supplier', 'index', 'fa fa-truck', 10, 5, true],
            [16, 'Employee', 'employee', 'index', 'fa fa-users', 10, 6, true],
            [17, 'User', 'user', 'index', 'fa fa-user-shield', 10, 7, true],
            [18, 'Bank', 'bank', 'index', 'fa fa-university', 10, 8, true],
            [19, 'Accounts', 'ledger', 'index', 'fa fa-book', 10, 9, true],

            // Sales (parent=20)
            [20, 'Sales', null, null, 'fa fa-shopping-cart', 0, 3, true],
            [21, 'Create Sales', 'sales', 'create', 'fa fa-file-invoice', 20, 1, true],
            [22, 'Today Invoice', 'sales', 'today', 'fa fa-calendar-day', 20, 2, true],
            [23, 'Challan', 'challan', 'index', 'fa fa-truck-loading', 20, 3, true],
            [24, 'Sales Return', 'SalesReturn', 'index', 'fa fa-undo', 20, 4, true],

            // Sales Audit (new — P1-3)
            [25, 'Sales Audit Trail', 'sales', 'audit', 'fa fa-clipboard-list', 20, 5, true],

            // Purchase (parent=30)
            [30, 'Purchase', null, null, 'fa fa-cart-plus', 0, 4, true],
            [31, 'P. Order', 'PurchaseOrder', 'index', 'fa fa-file-alt', 30, 1, true],
            [32, 'P. Receive', 'PurchaseReceive', 'index', 'fa fa-box-open', 30, 2, true],
            [33, 'P. Return', 'PurchaseReturn', 'index', 'fa fa-undo', 30, 3, true],

            // Inventory (parent=40)
            [40, 'Inventory', null, null, 'fa fa-boxes', 0, 5, true],
            [41, 'Physical Count', 'StockTake', 'index', 'fa fa-cubes', 40, 1, true],
            [42, 'Stock Adjustment', 'StockAdjustment', 'index', 'fa fa-history', 40, 2, true],
            [43, 'Warehouse Transfer', 'WarehouseTransfer', 'index', 'fa fa-exchange-alt', 40, 3, true],
            [44, 'Branch Demand', 'BranchDemand', 'index', 'fa fa-clipboard-list', 40, 4, true],
            [45, 'Damage', 'damage', 'index', 'fa fa-exclamation-triangle', 40, 5, true],

            // Accounting (parent=50)
            [50, 'Accounting', null, null, 'fa fa-balance-scale', 0, 6, true],
            [101, 'Overview', 'Accounting', 'index', 'fa fa-arrow-up', 50, 1, true],
            [102, 'Acc: home', 'Accounting', 'index', 'fa fa-arrow-up', 101, 1, true],
            [103, 'Chart of Acc:', 'ledger', 'index', 'fa fa-arrow-up', 101, 2, true],
            [104, 'Sub-ledgers', 'Accounting', 'index', 'fa fa-arrow-up', 50, 2, true],
            [105, 'Customer payments', 'CustomerTransaction', 'index', 'fa fa-arrow-up', 104, 1, true],
            [106, 'Supplier payments', 'SupplierTransaction', 'index', 'fa fa-arrow-up', 104, 2, true],
            [107, 'Employee transactions', 'EmployeeTransaction', 'index', 'fa fa-arrow-up', 104, 3, true],
            [108, 'Bank accounts', 'bank', 'index', 'fa fa-arrow-up', 104, 4, true],
            [109, 'Vouchers', 'Accounting', 'index', 'fa fa-arrow-up', 50, 3, true],
            [55, 'Other Income', 'OtherIncome', 'index', 'fa fa-arrow-down', 109, 1, true],
            [56, 'Other Expense', 'OtherExpense', 'index', 'fa fa-arrow-up', 109, 2, true],
            [54, 'Money Transfer', 'MoneyTransfer', 'index', 'fa fa-random', 109, 3, true],
            [110, 'Journals & Period', 'Accounting', 'index', 'fa fa-arrow-up', 50, 4, true],
            [111, 'Manual Journals', 'ManualJournal', 'index', 'fa fa-arrow-up', 110, 1, true],
            [112, 'Period Close', 'AccountingPeriod', 'index', 'fa fa-arrow-up', 110, 2, true],
            [113, 'Year-End Checklist', 'AccountingPeriod', 'year_end', 'fa fa-arrow-up', 110, 3, true],

            // Reports (parent=71)
            [71, 'Reports', 'Report', 'index', 'fas fa-file-alt', 0, 70, true],
        ];

        foreach ($menus as $menu) {
            DB::table('menus')->updateOrInsert(
                ['id' => $menu[0]],
                [
                    'menu_label' => $menu[1],
                    'controller' => $menu[2],
                    'action' => $menu[3],
                    'icon' => $menu[4],
                    'parent_id' => $menu[5],
                    'sort_order' => $menu[6],
                    'is_active' => $menu[7],
                ]
            );
        }
    }

    public function down(): void
    {
        // Don't delete menus on rollback — they may have been customized.
        // Only remove the new Sales Audit menu (id=25).
        DB::table('menus')->where('id', 25)->delete();
    }
};
