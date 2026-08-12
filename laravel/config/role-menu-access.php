<?php

/**
 * Role-Menu Compatibility Map
 * ===========================
 * Defines which menus are accessible by which employee roles.
 * Used by:
 *   - Menu permission UI (to show warnings when granting incompatible menus)
 *   - UserController (to validate/reject incompatible permission grants)
 *   - MenuService (to filter menus by role compatibility)
 *
 * Format: 'controller' => [allowed roles]
 * 
 * If a controller is NOT listed here, it is accessible by ALL roles.
 * admin and superadmin always have access to everything (bypass).
 * 
 * Roles: superadmin, admin, manager, accountant, salesman,
 *        warehouse_manager, dispatcher, hr, user, other
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Administration
    |--------------------------------------------------------------------------
    */
    'branch'            => ['admin', 'manager', 'warehouse_manager'],
    'warehouse'         => ['admin', 'manager', 'warehouse_manager'],
    'product'           => ['admin', 'manager', 'warehouse_manager'],
    'productcategory'   => ['admin', 'manager', 'warehouse_manager'],
    'productgroup'      => ['admin', 'manager', 'warehouse_manager'],
    'customer'          => ['admin', 'manager', 'salesman'],
    'supplier'          => ['admin', 'manager', 'accountant'],
    'employee'          => ['admin', 'manager', 'hr'],
    'user'              => ['admin', 'manager'],
    'bank'              => ['admin', 'manager', 'accountant'],
    'ledger'            => ['admin', 'accountant', 'manager'],
    'commission'        => ['admin', 'manager'],

    /*
    |--------------------------------------------------------------------------
    | Sales
    |--------------------------------------------------------------------------
    */
    'sales'             => ['admin', 'manager', 'salesman', 'accountant'],
    'salesreturn'       => ['admin', 'manager', 'salesman', 'accountant', 'warehouse_manager'],
    'challan'           => ['admin', 'manager', 'warehouse_manager', 'dispatcher'],

    /*
    |--------------------------------------------------------------------------
    | Purchase
    |--------------------------------------------------------------------------
    */
    'purchaseorder'     => ['admin', 'manager', 'accountant'],
    'purchasereceive'   => ['admin', 'manager', 'accountant'],
    'purchasereturn'   => ['admin', 'manager', 'accountant'],
    'purchaseaudit'    => ['admin', 'manager', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */
    'stocktake'         => ['admin', 'manager', 'warehouse_manager'],
    'stockadjustment'   => ['admin', 'manager', 'warehouse_manager'],
    'warehousetransfer' => ['admin', 'manager', 'warehouse_manager'],
    'stocktransaction'  => ['admin', 'manager', 'warehouse_manager'],
    'damage'            => ['admin', 'manager', 'warehouse_manager'],
    'branchdemand'      => ['admin', 'manager', 'warehouse_manager', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | Accounting / Payments
    |--------------------------------------------------------------------------
    */
    'customertransaction'  => ['admin', 'manager', 'accountant', 'salesman'],
    'suppliertransaction'  => ['admin', 'manager', 'accountant'],
    'employeetransaction'  => ['admin', 'manager', 'accountant'],
    'moneytransfer'        => ['admin', 'manager', 'accountant'],
    'otherincome'          => ['admin', 'manager', 'accountant'],
    'otherexpense'         => ['admin', 'manager', 'accountant'],
    'manualjournal'        => ['admin', 'manager', 'accountant'],
    'accountingperiod'     => ['admin', 'manager', 'accountant'],
    'reconciliation'       => ['admin', 'manager', 'accountant'],
    'budget'               => ['admin', 'manager', 'accountant'],
    'dimension'            => ['admin', 'manager', 'accountant'],
    'fiscalyear'           => ['admin', 'manager', 'accountant'],
    'bankreconciliation'   => ['admin', 'manager', 'accountant'],
    'fixedasset'           => ['admin', 'manager', 'accountant'],
    'consolidation'        => ['admin', 'manager', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | Reports & Approvals
    |--------------------------------------------------------------------------
    */
    'report'            => ['admin', 'manager', 'accountant'],
    'approval'          => ['admin', 'manager', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | System (Admin-only)
    |--------------------------------------------------------------------------
    */
    'notification'      => ['admin'],
    'compliance'        => ['superadmin'],
    'globalaudit'       => ['admin'],
    'systemhealth'      => ['admin'],
    'archive'           => ['admin'],
    'shadowmode'        => ['superadmin', 'admin'],
    'branchdemandshadow' => ['superadmin', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard — accessible by ALL roles (not listed = open to all)
    |--------------------------------------------------------------------------
    */
    // 'dashboard' => all roles — no restriction needed
];
