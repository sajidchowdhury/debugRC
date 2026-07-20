<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the menus table with all menu items from the legacy ERP.
 *
 * PostgreSQL-compatible: does NOT insert into the `id` column
 * (GENERATED ALWAYS AS IDENTITY). Uses `menu_label` + `controller`
 * as the natural unique key for updateOrInsert.
 *
 * Two-pass approach:
 *   Pass 1: insert all top-level menus (parent_id = 0)
 *   Pass 2: insert all child menus, resolving parent_id by looking
 *           up the parent's actual generated ID via menu_label
 *
 * Idempotent: updateOrInsert matches on natural key, so running
 * multiple times updates existing rows without creating duplicates.
 */
return new class extends Migration
{
    /**
     * Menu definitions.
     * Each entry: [menu_label, controller, action, icon, parent_label, sort_order, is_active]
     * parent_label = null means top-level (parent_id = 0).
     */
    private array $menuDefs = [
        // --- Top-level menus (parent = null) ---
        ['Dashboard',                'dashboard',           'index',     'fa fa-dashboard',           null,              1, true],
        ['Administration',           null,                  null,        'fa fa-cogs',                null,              2, true],
        ['Sales',                    null,                  null,        'fa fa-shopping-cart',       null,              3, true],
        ['Purchase',                 null,                  null,        'fa fa-cart-plus',           null,              4, true],
        ['Inventory',                null,                  null,        'fa fa-boxes',               null,              5, true],
        ['Accounting',               null,                  null,        'fa fa-balance-scale',       null,              6, true],
        ['Reports',                  'Report',              'index',     'fas fa-file-alt',            null,             70, true],

        // --- Administration children (parent = 'Administration') ---
        ['Branch',                   'branch',              'index',     'fa fa-building',             'Administration',  1, true],
        ['Warehouse',                'warehouse',           'index',     'fa fa-warehouse',            'Administration',  2, true],
        ['Product',                  'product',             'index',     'fa fa-box',                  'Administration',  3, true],
        ['Customer',                 'customer',            'index',     'fa fa-user-tie',             'Administration',  4, true],
        ['Supplier',                 'supplier',            'index',     'fa fa-truck',                'Administration',  5, true],
        ['Employee',                 'employee',            'index',     'fa fa-users',                'Administration',  6, true],
        ['User',                     'user',                'index',     'fa fa-user-shield',          'Administration',  7, true],
        ['Bank',                     'bank',                'index',     'fa fa-university',           'Administration',  8, true],
        ['Accounts',                 'ledger',              'index',     'fa fa-book',                 'Administration',  9, true],

        // --- Sales children (parent = 'Sales') ---
        ['Create Sales',             'sales',               'create',    'fa fa-file-invoice',         'Sales',           1, true],
        ['Today Invoice',            'sales',               'today',     'fa fa-calendar-day',         'Sales',           2, true],
        ['Challan',                  'challan',             'index',     'fa fa-truck-loading',        'Sales',           3, true],
        ['Sales Return',             'SalesReturn',         'index',     'fa fa-undo',                 'Sales',           4, true],
        ['Sales Audit Trail',        'sales',               'audit',     'fa fa-clipboard-list',       'Sales',           5, true],

        // --- Purchase children (parent = 'Purchase') ---
        ['P. Order',                 'PurchaseOrder',       'index',     'fa fa-file-alt',             'Purchase',        1, true],
        ['P. Receive',               'PurchaseReceive',     'index',     'fa fa-box-open',             'Purchase',        2, true],
        ['P. Return',                'PurchaseReturn',      'index',     'fa fa-undo',                 'Purchase',        3, true],

        // --- Inventory children (parent = 'Inventory') ---
        ['Physical Count',           'StockTake',           'index',     'fa fa-cubes',                'Inventory',       1, true],
        ['Stock Adjustment',         'StockAdjustment',     'index',     'fa fa-history',              'Inventory',       2, true],
        ['Warehouse Transfer',       'WarehouseTransfer',   'index',     'fa fa-exchange-alt',         'Inventory',       3, true],
        ['Branch Demand',            'BranchDemand',        'index',     'fa fa-clipboard-list',       'Inventory',       4, true],
        ['Damage',                   'damage',              'index',     'fa fa-exclamation-triangle', 'Inventory',       5, true],

        // --- Accounting children (parent = 'Accounting') ---
        ['Overview',                 'Accounting',          'index',     'fa fa-arrow-up',             'Accounting',      1, true],
        ['Sub-ledgers',              'Accounting',          'index',     'fa fa-arrow-up',             'Accounting',      2, true],
        ['Vouchers',                 'Accounting',          'index',     'fa fa-arrow-up',             'Accounting',      3, true],
        ['Journals & Period',        'Accounting',          'index',     'fa fa-arrow-up',             'Accounting',      4, true],

        // --- Overview children (parent = 'Overview') ---
        ['Acc: home',                'Accounting',          'index',     'fa fa-arrow-up',             'Overview',        1, true],
        ['Chart of Acc:',            'ledger',              'index',     'fa fa-arrow-up',             'Overview',        2, true],

        // --- Sub-ledgers children (parent = 'Sub-ledgers') ---
        ['Customer payments',        'CustomerTransaction', 'index',     'fa fa-arrow-up',             'Sub-ledgers',     1, true],
        ['Supplier payments',        'SupplierTransaction', 'index',     'fa fa-arrow-up',             'Sub-ledgers',     2, true],
        ['Employee transactions',    'EmployeeTransaction', 'index',     'fa fa-arrow-up',             'Sub-ledgers',     3, true],
        ['Bank accounts',            'bank',                'index',     'fa fa-arrow-up',             'Sub-ledgers',     4, true],

        // --- Vouchers children (parent = 'Vouchers') ---
        ['Other Income',             'OtherIncome',         'index',     'fa fa-arrow-down',           'Vouchers',        1, true],
        ['Other Expense',            'OtherExpense',        'index',     'fa fa-arrow-up',             'Vouchers',        2, true],
        ['Money Transfer',           'MoneyTransfer',       'index',     'fa fa-random',               'Vouchers',        3, true],

        // --- Journals & Period children (parent = 'Journals & Period') ---
        ['Manual Journals',          'ManualJournal',       'index',     'fa fa-arrow-up',             'Journals & Period', 1, true],
        ['Period Close',             'AccountingPeriod',    'index',     'fa fa-arrow-up',             'Journals & Period', 2, true],
        ['Year-End Checklist',       'AccountingPeriod',    'year_end',  'fa fa-arrow-up',             'Journals & Period', 3, true],
    ];

    public function up(): void
    {
        // Build a label → id map for parent resolution.
        $labelToId = [];

        // Pass 1: insert all top-level menus (parent_label = null).
        foreach ($this->menuDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder, $isActive] = $def;

            if ($parentLabel !== null) {
                continue; // Skip children for now.
            }

            // Natural key: menu_label + controller (both null controller = top-level parent).
            DB::table('menus')->updateOrInsert(
                ['menu_label' => $label, 'controller' => $controller],
                [
                    'action' => $action,
                    'icon' => $icon,
                    'parent_id' => 0,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]
            );

            // Cache the generated id for child resolution.
            $id = DB::table('menus')
                ->where('menu_label', $label)
                ->whereNull('controller')
                ->value('id');

            if ($id) {
                $labelToId[$label] = $id;
            }
        }

        // Pass 2: insert all child menus, resolving parent_id by label.
        foreach ($this->menuDefs as $def) {
            [$label, $controller, $action, $icon, $parentLabel, $sortOrder, $isActive] = $def;

            if ($parentLabel === null) {
                continue; // Already inserted in pass 1.
            }

            // Resolve parent_id from the label map.
            $parentId = $labelToId[$parentLabel] ?? 0;

            // Natural key: menu_label + controller.
            DB::table('menus')->updateOrInsert(
                ['menu_label' => $label, 'controller' => $controller],
                [
                    'action' => $action,
                    'icon' => $icon,
                    'parent_id' => $parentId,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]
            );

            // Cache this menu's id for potential grandchildren.
            $id = DB::table('menus')
                ->where('menu_label', $label)
                ->where('controller', $controller)
                ->value('id');

            if ($id) {
                $labelToId[$label] = $id;
            }
        }
    }

    public function down(): void
    {
        // Delete only the menus we seeded (by matching menu_label).
        $labels = array_map(fn($def) => $def[0], $this->menuDefs);
        DB::table('menus')->whereIn('menu_label', $labels)->delete();
    }
};
