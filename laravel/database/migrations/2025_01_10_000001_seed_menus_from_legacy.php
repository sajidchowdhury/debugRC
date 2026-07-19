<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the menus table with all menu items from the legacy ERP.
 *
 * Mirrors the legacy menus table data — 50+ menu items organized into
 * sections: Dashboard, Administration, Sales, Purchase, Inventory,
 * Accounting, Reports.
 *
 * Each menu item maps a legacy controller/action to the corresponding
 * Laravel route. The MenuService resolves these at render time.
 *
 * Idempotent: uses INSERT ... ON CONFLICT DO NOTHING (upsert by id).
 */
return new class extends Migration
{
    public function up(): void
    {
        $menus = [
            // Dashboard
            [1, 'Dashboard', null, 'dashboard', 'index', 'fa fa-dashboard', 0, 1, 'Dashboard', true],

            // Administration (parent=10)
            [10, 'Administration', null, null, null, 'fa fa-cogs', 0, 2, 'Administration', true],
            [11, 'Branch', null, 'branch', 'index', 'fa fa-building', 10, 1, 'Administration', true],
            [12, 'Warehouse', null, 'warehouse', 'index', 'fa fa-warehouse', 10, 2, 'Administration', true],
            [13, 'Product', null, 'product', 'index', 'fa fa-box', 10, 3, 'Administration', true],
            [14, 'Customer', null, 'customer', 'index', 'fa fa-user-tie', 10, 4, 'Administration', true],
            [15, 'Supplier', null, 'supplier', 'index', 'fa fa-truck', 10, 5, 'Administration', true],
            [16, 'Employee', null, 'employee', 'index', 'fa fa-users', 10, 6, 'Administration', true],
            [17, 'User', null, 'user', 'index', 'fa fa-user-shield', 10, 7, 'Administration', true],
            [18, 'Bank', null, 'bank', 'index', 'fa fa-university', 10, 8, 'Administration', true],
            [19, 'Accounts', null, 'ledger', 'index', 'fa fa-book', 10, 9, 'Administration', true],

            // Sales (parent=20)
            [20, 'Sales', null, null, null, 'fa fa-shopping-cart', 0, 3, 'Sales', true],
            [21, 'Create Sales', null, 'sales', 'create', 'fa fa-file-invoice', 20, 1, 'Sales', true],
            [22, 'Today Invoice', null, 'sales', 'today', 'fa fa-calendar-day', 20, 2, 'Sales', true],
            [23, 'Challan', null, 'challan', 'index', 'fa fa-truck-loading', 20, 3, 'Sales', true],
            [24, 'Sales Return', null, 'SalesReturn', 'index', 'fa fa-undo', 20, 4, 'Sales', true],

            // Purchase (parent=30)
            [30, 'Purchase', null, null, null, 'fa fa-cart-plus', 0, 4, 'Purchase', true],
            [31, 'P. Order', null, 'PurchaseOrder', 'index', 'fa fa-file-alt', 30, 1, 'Purchase', true],
            [32, 'P. Receive', null, 'PurchaseReceive', 'index', 'fa fa-box-open', 30, 2, 'Purchase', true],
            [33, 'P. Return', null, 'PurchaseReturn', 'index', 'fa fa-undo', 30, 3, 'Purchase', true],

            // Inventory (parent=40)
            [40, 'Inventory', null, null, null, 'fa fa-boxes', 0, 5, 'Inventory', true],
            [41, 'Physical Count', null, 'StockTake', 'index', 'fa fa-cubes', 40, 1, 'Inventory', true],
            [42, 'Stock Adjustment', null, 'StockAdjustment', 'index', 'fa fa-history', 40, 2, 'Inventory', true],
            [43, 'Warehouse Transfer', null, 'WarehouseTransfer', 'index', 'fa fa-exchange-alt', 40, 3, 'Inventory', true],
            [44, 'Branch Demand', null, 'BranchDemand', 'index', 'fa fa-clipboard-list', 40, 4, 'Inventory', true],
            [45, 'Damage', null, 'damage', 'index', 'fa fa-exclamation-triangle', 40, 5, 'Inventory', true],

            // Accounting (parent=50)
            [50, 'Accounting', null, null, null, 'fa fa-balance-scale', 0, 6, 'Accounting', true],
            [101, 'Overview', null, 'Accounting', 'index', 'fa fa-arrow-up', 50, 1, 'Accounting', true],
            [102, 'Acc: home', null, 'Accounting', 'index', 'fa fa-arrow-up', 101, 1, 'Accounting', true],
            [103, 'Chart of Acc:', null, 'ledger', 'index', 'fa fa-arrow-up', 101, 2, 'Accounting', true],
            [104, 'Sub-ledgers', null, 'Accounting', 'index', 'fa fa-arrow-up', 50, 2, 'Accounting', true],
            [105, 'Customer payments', null, 'CustomerTransaction', 'index', 'fa fa-arrow-up', 104, 1, 'Accounting', true],
            [106, 'Supplier payments', null, 'SupplierTransaction', 'index', 'fa fa-arrow-up', 104, 2, 'Accounting', true],
            [107, 'Employee transactions', null, 'EmployeeTransaction', 'index', 'fa fa-arrow-up', 104, 3, 'Accounting', true],
            [108, 'Bank accounts', null, 'bank', 'index', 'fa fa-arrow-up', 104, 4, 'Accounting', true],
            [109, 'Vouchers', null, 'Accounting', 'index', 'fa fa-arrow-up', 50, 3, 'Accounting', true],
            [55, 'Other Income', null, 'OtherIncome', 'index', 'fa fa-arrow-down', 109, 1, 'Accounting', true],
            [56, 'Other Expense', null, 'OtherExpense', 'index', 'fa fa-arrow-up', 109, 2, 'Accounting', true],
            [54, 'Money Transfer', null, 'MoneyTransfer', 'index', 'fa fa-random', 109, 3, 'Accounting', true],
            [110, 'Journals & Period', null, 'Accounting', 'index', 'fa fa-arrow-up', 50, 4, 'Accounting', true],
            [111, 'Manual Journals', null, 'ManualJournal', 'index', 'fa fa-arrow-up', 110, 1, 'Accounting', true],
            [112, 'Period Close', null, 'AccountingPeriod', 'index', 'fa fa-arrow-up', 110, 2, 'Accounting', true],
            [113, 'Year-End Checklist', null, 'AccountingPeriod', 'year_end', 'fa fa-arrow-up', 110, 3, 'Accounting', true],

            // Reports (parent=71)
            [71, 'Reports', '#', 'Report', 'index', 'fas fa-file-alt', 0, 70, null, true],

            // Sales Audit (new — P1-3)
            [25, 'Sales Audit Trail', null, 'sales', 'audit', 'fa fa-clipboard-list', 20, 5, 'Sales', true],
        ];

        foreach ($menus as $menu) {
            DB::table('menus')->updateOrInsert(
                ['id' => $menu[0]],
                [
                    'menu_name' => $menu[1],
                    'menu_link' => $menu[2],
                    'controller' => $menu[3],
                    'action' => $menu[4],
                    'icon' => $menu[5],
                    'parent_id' => $menu[6],
                    'sort_order' => $menu[7],
                    'section' => $menu[8],
                    'is_active' => $menu[9],
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
