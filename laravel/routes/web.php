<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\BankController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalesFunnelController;
use App\Http\Controllers\Admin\CustomerPerformanceController;
use App\Http\Controllers\Admin\CsvExportController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\StockTransactionController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockTakeController;
use App\Http\Controllers\Admin\WarehouseTransferController;
use App\Http\Controllers\Admin\DamageController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\PurchaseReceiveController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\PurchaseAuditController;
use App\Http\Controllers\Admin\SalesCartController;
use App\Http\Controllers\Admin\SalesInvoiceController;
use App\Http\Controllers\Admin\SalesChallanController;
use App\Http\Controllers\Admin\CustomerPaymentController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\SalesGuideController;
use App\Http\Controllers\Admin\GoLiveChecklistController;
use App\Http\Controllers\Admin\AccountingPeriodController;
use App\Http\Controllers\SseController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SystemPolicyController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\GlobalAuditController;
use App\Http\Controllers\Admin\SystemHealthController;
use Illuminate\Support\Facades\Route;

/**
 * RC_ERP Laravel Routes — Phases 3-12.
 *
 * Phase 12: archive layer (anti-corruption layer for legacy MySQL, unified search).
 */

// ===================== AUTH (public) =====================
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('forgot', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('reset', [NewPasswordController::class, 'store'])
        ->name('password.update');
});

// ===================== AUTHENTICATED =====================
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    Route::get('dashboard/sales-trend', [DashboardController::class, 'salesTrendAjax'])
        ->name('dashboard.salesTrend');

    // UI Preview — Phase 4 dev/design tool (storybook-style component showcase).
    // Renders all <x-erp.*> design-system components with sample data.
    Route::get('ui-preview', [App\Http\Controllers\UiPreviewController::class, 'index'])
        ->name('ui-preview');

    // Branch switch — Phase 5 (sales layout shell). POST form from the erp
    // layout's branch <select>. Sets session('branch_id'/'branch_name'/'branch_code')
    // which SetAppBranchId middleware reads on the next request for RLS.
    Route::post('branch/switch', [App\Http\Controllers\BranchSwitchController::class, 'switch'])
        ->name('branch.switch');

    // ============================================================
    // Phase 4: Master Data Modules
    // ============================================================

    // --- Products (RBAC: admin for write, admin/manager/warehouse_manager for read) ---
    Route::prefix('admin/products')->name('admin.products.')->group(function () {
        Route::get('export', [ProductController::class, 'export'])->name('export')->middleware('role:admin,manager,warehouse_manager');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [ProductController::class, 'print'])->name('print')->middleware('role:admin,manager,warehouse_manager');
        Route::get('audit', [ProductController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::get('{product}/price-history', [ProductController::class, 'priceHistory'])->name('priceHistory')->where(['product' => '[0-9]+'])->middleware('role:admin,manager,warehouse_manager');
        Route::post('{product}/price', [ProductController::class, 'addPrice'])->name('addPrice')->where(['product' => '[0-9]+'])->middleware('role:admin');
        Route::delete('{product}/price/{price}', [ProductController::class, 'deletePrice'])->name('deletePrice')->where(['product' => '[0-9]+', 'price' => '[0-9]+'])->middleware('role:admin');
        Route::post('{product}/restore', [ProductController::class, 'restore'])->name('restore')->where(['product' => '[0-9]+'])->middleware('role:admin');
        Route::post('{product}/toggle', [ProductController::class, 'toggle'])->name('toggle')->where(['product' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, warehouse_manager
    Route::resource('admin/products', ProductController::class)
        ->only(['index', 'show'])->where(['product' => '[0-9]+'])
        ->names('admin.products')
        ->middleware('role:admin,manager,warehouse_manager');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/products', ProductController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['product' => '[0-9]+'])
        ->names('admin.products')
        ->middleware('role:admin');

    // --- Product Categories ---
    Route::prefix('admin/product-categories')->name('admin.product-categories.')->group(function () {
        Route::get('audit', [ProductCategoryController::class, 'audit'])->name('audit');
        Route::post('{category}/restore', [ProductCategoryController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/product-categories', ProductCategoryController::class)->names('admin.product-categories');

    // --- Product Groups ---
    Route::prefix('admin/product-groups')->name('admin.product-groups.')->group(function () {
        Route::get('audit', [ProductGroupController::class, 'audit'])->name('audit');
        Route::post('{group}/restore', [ProductGroupController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/product-groups', ProductGroupController::class)->names('admin.product-groups');

    // --- Customers (RBAC: admin for write, admin/manager/salesman for read) ---
    Route::prefix('admin/customers')->name('admin.customers.')->group(function () {
        Route::get('export', [CustomerController::class, 'export'])->name('export')->middleware('role:admin,manager,salesman');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [CustomerController::class, 'print'])->name('print')->middleware('role:admin,manager,salesman');
        Route::get('audit', [CustomerController::class, 'audit'])->name('audit')->middleware('role:admin');
        // Customer 360 Hub — AJAX DataTables endpoints (same RBAC as show)
        Route::get('{customer}/ledger-data', [CustomerController::class, 'ledgerData'])->name('ledger-data')->where(['customer' => '[0-9]+'])->middleware('role:admin,manager,salesman');
        Route::get('{customer}/invoices-data', [CustomerController::class, 'invoicesData'])->name('invoices-data')->where(['customer' => '[0-9]+'])->middleware('role:admin,manager,salesman');
        Route::get('{customer}/payments-data', [CustomerController::class, 'paymentsData'])->name('payments-data')->where(['customer' => '[0-9]+'])->middleware('role:admin,manager,salesman');
        Route::get('{customer}/returns-data', [CustomerController::class, 'returnsData'])->name('returns-data')->where(['customer' => '[0-9]+'])->middleware('role:admin,manager,salesman');
        Route::post('{customer}/restore', [CustomerController::class, 'restore'])->name('restore')->where(['customer' => '[0-9]+'])->middleware('role:admin');
        Route::post('{customer}/toggle', [CustomerController::class, 'toggle'])->name('toggle')->where(['customer' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, salesman
    Route::resource('admin/customers', CustomerController::class)
        ->only(['index', 'show'])->where(['customer' => '[0-9]+'])
        ->names('admin.customers')
        ->middleware('role:admin,manager,salesman');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/customers', CustomerController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['customer' => '[0-9]+'])
        ->names('admin.customers')
        ->middleware('role:admin');

    // --- Suppliers (RBAC: admin for write, admin/manager/accountant for read) ---
    Route::prefix('admin/suppliers')->name('admin.suppliers.')->group(function () {
        Route::get('export', [SupplierController::class, 'export'])->name('export')->middleware('role:admin,manager,accountant');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [SupplierController::class, 'print'])->name('print')->middleware('role:admin,manager,accountant');
        Route::get('audit', [SupplierController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::post('{supplier}/restore', [SupplierController::class, 'restore'])->name('restore')->where(['supplier' => '[0-9]+'])->middleware('role:admin');
        Route::post('{supplier}/toggle', [SupplierController::class, 'toggle'])->name('toggle')->where(['supplier' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, accountant
    Route::resource('admin/suppliers', SupplierController::class)
        ->only(['index', 'show'])->where(['supplier' => '[0-9]+'])
        ->names('admin.suppliers')
        ->middleware('role:admin,manager,accountant');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/suppliers', SupplierController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['supplier' => '[0-9]+'])
        ->names('admin.suppliers')
        ->middleware('role:admin');

    // --- Employees (RBAC: admin for write, admin/manager/hr for read) ---
    Route::prefix('admin/employees')->name('admin.employees.')->group(function () {
        Route::get('export', [EmployeeController::class, 'export'])->name('export')->middleware('role:admin,manager,hr');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [EmployeeController::class, 'print'])->name('print')->middleware('role:admin,manager,hr');
        Route::get('audit', [EmployeeController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::get('{employee}/account', [EmployeeController::class, 'account'])->name('account')->where(['employee' => '[0-9]+'])->middleware('role:admin,manager,hr');
        Route::post('{employee}/restore', [EmployeeController::class, 'restore'])->name('restore')->where(['employee' => '[0-9]+'])->middleware('role:admin');
        Route::post('{employee}/toggle', [EmployeeController::class, 'toggle'])->name('toggle')->where(['employee' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, hr
    Route::resource('admin/employees', EmployeeController::class)
        ->only(['index', 'show'])->where(['employee' => '[0-9]+'])
        ->names('admin.employees')
        ->middleware('role:admin,manager,hr');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/employees', EmployeeController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['employee' => '[0-9]+'])
        ->names('admin.employees')
        ->middleware('role:admin');

    // --- Banks (RBAC: admin for write, admin/manager/accountant for read) ---
    Route::prefix('admin/banks')->name('admin.banks.')->group(function () {
        Route::get('export', [BankController::class, 'export'])->name('export')->middleware('role:admin,manager,accountant');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [BankController::class, 'print'])->name('print')->middleware('role:admin,manager,accountant');
        Route::get('audit', [BankController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::post('{bank}/restore', [BankController::class, 'restore'])->name('restore')->where(['bank' => '[0-9]+'])->middleware('role:admin');
        Route::post('{bank}/toggle', [BankController::class, 'toggle'])->name('toggle')->where(['bank' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, accountant
    Route::resource('admin/banks', BankController::class)
        ->only(['index', 'show'])->where(['bank' => '[0-9]+'])
        ->names('admin.banks')
        ->middleware('role:admin,manager,accountant');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/banks', BankController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['bank' => '[0-9]+'])
        ->names('admin.banks')
        ->middleware('role:admin');

    // --- Ledgers (Chart of Accounts) ---
    // RBAC (Phase 15): admin for write, admin/accountant for read (accounting-domain).
    Route::prefix('admin/ledgers')->name('admin.ledgers.')->group(function () {
        Route::get('export', [LedgerController::class, 'export'])->name('export')->middleware('role:admin,accountant');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [LedgerController::class, 'print'])->name('print')->middleware('role:admin,accountant');
        Route::get('audit', [LedgerController::class, 'audit'])->name('audit')->middleware('role:admin,accountant');
        Route::post('{ledger}/restore', [LedgerController::class, 'restore'])->name('restore')->where(['ledger' => '[0-9]+'])->middleware('role:admin');
        Route::post('{ledger}/toggle', [LedgerController::class, 'toggle'])->name('toggle')->where(['ledger' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, accountant (accounting-domain master data)
    Route::resource('admin/ledgers', LedgerController::class)
        ->only(['index', 'show'])->where(['ledger' => '[0-9]+'])
        ->names('admin.ledgers')
        ->middleware('role:admin,accountant');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/ledgers', LedgerController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['ledger' => '[0-9]+'])
        ->names('admin.ledgers')
        ->middleware('role:admin');

    // --- Branches (RBAC: admin for write, admin/manager/warehouse_manager for read) ---
    Route::prefix('admin/branches')->name('admin.branches.')->group(function () {
        Route::get('export', [BranchController::class, 'export'])->name('export')->middleware('role:admin,manager,warehouse_manager');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [BranchController::class, 'print'])->name('print')->middleware('role:admin,manager,warehouse_manager');
        Route::get('audit', [BranchController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::post('{branch}/restore', [BranchController::class, 'restore'])->name('restore')->middleware('role:admin');
        Route::post('{branch}/toggle', [BranchController::class, 'toggle'])->name('toggle')->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, warehouse_manager
    Route::resource('admin/branches', BranchController::class)
        ->only(['index', 'show'])->where(['branch' => '[0-9]+'])
        ->names('admin.branches')
        ->middleware('role:admin,manager,warehouse_manager');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/branches', BranchController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['branch' => '[0-9]+'])
        ->names('admin.branches')
        ->middleware('role:admin');

    // --- Warehouses (RBAC: admin for write, admin/manager/warehouse_manager for read) ---
    Route::prefix('admin/warehouses')->name('admin.warehouses.')->group(function () {
        Route::get('export', [WarehouseController::class, 'export'])->name('export')->middleware('role:admin,manager,warehouse_manager');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [WarehouseController::class, 'print'])->name('print')->middleware('role:admin,manager,warehouse_manager');
        Route::get('audit', [WarehouseController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::post('{warehouse}/restore', [WarehouseController::class, 'restore'])->name('restore')->middleware('role:admin');
        Route::post('{warehouse}/toggle', [WarehouseController::class, 'toggle'])->name('toggle')->middleware('role:admin');
    });
    // Read access (index, show): admin, manager, warehouse_manager
    Route::resource('admin/warehouses', WarehouseController::class)
        ->only(['index', 'show'])->where(['warehouse' => '[0-9]+'])
        ->names('admin.warehouses')
        ->middleware('role:admin,manager,warehouse_manager');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/warehouses', WarehouseController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['warehouse' => '[0-9]+'])
        ->names('admin.warehouses')
        ->middleware('role:admin');

    // --- Users (RBAC: admin for write, admin/manager for read) ---
    // Phase 14: User administration — login accounts tied to Employees.
    Route::prefix('admin/users')->name('admin.users.')->group(function () {
        Route::get('export', [UserController::class, 'export'])->name('export')->middleware('role:admin,manager');
        // Phase 19: Print directory (same RBAC as export)
        Route::get('print', [UserController::class, 'print'])->name('print')->middleware('role:admin,manager');
        Route::get('audit', [UserController::class, 'audit'])->name('audit')->middleware('role:admin');
        Route::post('{user}/restore', [UserController::class, 'restore'])->name('restore')->where(['user' => '[0-9]+'])->middleware('role:admin');
        Route::post('{user}/toggle', [UserController::class, 'toggle'])->name('toggle')->where(['user' => '[0-9]+'])->middleware('role:admin');
        Route::post('{user}/unlock', [UserController::class, 'unlock'])->name('unlock')->where(['user' => '[0-9]+'])->middleware('role:admin');
        Route::post('{user}/reset-password', [UserController::class, 'resetPassword'])->name('resetPassword')->where(['user' => '[0-9]+'])->middleware('role:admin');
        Route::get('{user}/security', [UserController::class, 'securityAudit'])->name('security')->where(['user' => '[0-9]+'])->middleware('role:admin');
    });
    // Read access (index, show): admin, manager
    Route::resource('admin/users', UserController::class)
        ->only(['index', 'show'])->where(['user' => '[0-9]+'])
        ->names('admin.users')
        ->middleware('role:admin,manager');
    // Write access (create, store, edit, update, destroy): admin only
    Route::resource('admin/users', UserController::class)
        ->only(['create', 'store', 'edit', 'update', 'destroy'])->where(['user' => '[0-9]+'])
        ->names('admin.users')
        ->middleware('role:admin');

    // ============================================================
    // Phase 5: Reports + Reconciliation
    // ============================================================

    // Reports hub
    Route::get('admin/reports', [ReportController::class, 'index'])->name('admin.reports.index');

    // Reconciliation hub
    Route::get('admin/reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation.index');
    Route::get('admin/reconciliation/refresh', [ReconciliationController::class, 'refresh'])->name('admin.reconciliation.refresh');
    Route::get('admin/reconciliation/section/{sectionId}', [ReconciliationController::class, 'section'])->name('admin.reconciliation.section');

    // Financial reports (18 reports)
    Route::prefix('admin/reports')->name('admin.reports.')->group(function () {
        // Finance & Control
        Route::get('trial-balance', [ReportController::class, 'trialBalance'])->name('trialBalance');
        Route::get('profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('profitAndLoss');
        Route::get('balance-sheet', [ReportController::class, 'balanceSheet'])->name('balanceSheet');
        Route::get('cash-flow', [ReportController::class, 'cashFlow'])->name('cashFlow');
        Route::get('general-ledger', [ReportController::class, 'generalLedger'])->name('generalLedger');
        Route::get('journal-entries', [ReportController::class, 'journalEntries'])->name('journalEntries');
        Route::get('daily-cash-book', [ReportController::class, 'dailyCashBook'])->name('dailyCashBook');
        Route::get('branch-intercompany', [ReportController::class, 'branchIntercompany'])->name('branchIntercompany');
        Route::get('branch-wise-ledger', [ReportController::class, 'branchWiseLedger'])->name('branchWiseLedger');

        // Sales & Revenue
        Route::get('revenue-overview', [ReportController::class, 'revenueOverview'])->name('revenueOverview');
        Route::get('gross-margin', [ReportController::class, 'grossMargin'])->name('grossMargin');
        Route::get('customer-performance', [CustomerPerformanceController::class, 'index'])->name('customerPerformance');
        Route::get('sales-funnel', [SalesFunnelController::class, 'index'])->name('salesFunnel');

        // Purchase & Payables
        Route::get('supplier-wise-purchase', [ReportController::class, 'supplierWisePurchase'])->name('supplierWisePurchase');
        Route::get('receivable-aging', [ReportController::class, 'receivableAging'])->name('receivableAging');
        Route::get('payable-aging', [ReportController::class, 'payableAging'])->name('payableAging');

        // Inventory & Stock
        Route::get('product-stock-analysis', [ReportController::class, 'productStockAnalysis'])->name('productStockAnalysis');
        Route::get('product-movement', [ReportController::class, 'productMovement'])->name('productMovement');

        // Operations
        Route::get('sales-audit-checklist', [ReportController::class, 'salesAuditChecklist'])->name('salesAuditChecklist');
        Route::get('purchase-audit', [ReportController::class, 'purchaseAudit'])->name('purchaseAudit');
        Route::get('stocktake-variance', [ReportController::class, 'stocktakeVariance'])->name('stocktakeVariance');
        Route::get('branch-demand-weekly', [ReportController::class, 'branchDemandWeekly'])->name('branchDemandWeekly');

        // Phase 1E (Task 32): CTE-Based Reports (single-query complex aggregation)
        Route::get('today-summary-cte', [ReportController::class, 'todaySummaryCte'])->name('todaySummaryCte');
        Route::get('ar-aging-cte', [ReportController::class, 'arAgingCte'])->name('arAgingCte');
        Route::get('general-ledger-cte', [ReportController::class, 'generalLedgerCte'])->name('generalLedgerCte');
        Route::get('gross-margin-cte', [ReportController::class, 'grossMarginCte'])->name('grossMarginCte');
    });

    // ============================================================
    // Phase 6.1: Stock Transactions (SSOT) + Warehouse Stock
    // ============================================================
    Route::prefix('admin/stock')->name('admin.stock.')->group(function () {
        Route::get('transactions', [StockTransactionController::class, 'index'])->name('transactions');
        Route::get('warehouse-stock', [StockTransactionController::class, 'warehouseStock'])->name('warehouse_stock');
        Route::get('transactions/{id}', [StockTransactionController::class, 'show'])->name('show');
        Route::get('availability', [StockTransactionController::class, 'checkAvailability'])->name('availability');
        Route::get('warehouse-breakdown', [StockTransactionController::class, 'warehouseBreakdown'])->name('warehouse_breakdown');
        // Phase 6.2: drift viewer
        Route::get('drift', [StockTransactionController::class, 'drift'])->name('drift');
        Route::post('drift/{id}', [StockTransactionController::class, 'updateDrift'])->name('drift.update');
    });

    // ============================================================
    // Phase 6.3: Stock Adjustments (two-phase: draft → confirm → cancel)
    // ============================================================
    Route::prefix('admin/stock-adjustments')->name('admin.stock-adjustments.')->group(function () {
        Route::get('audit', [StockAdjustmentController::class, 'audit'])->name('audit');
        Route::get('product-rate', [StockAdjustmentController::class, 'getProductRate'])->name('product-rate');
        Route::get('{id}/confirm', fn() => redirect()->route('admin.stock-adjustments.index'))->name('confirm-form');
        Route::post('{id}/confirm', [StockAdjustmentController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/stock-adjustments', StockAdjustmentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.stock-adjustments');

    // ============================================================
    // Phase 6.4: Stock Take (sessions + per-warehouse counts + variance posting)
    // ============================================================
    Route::prefix('admin/stock-take')->name('admin.stock-take.')->group(function () {
        Route::get('{session}/warehouses/{warehouse}/setup', [StockTakeController::class, 'setupCounts'])->name('setup');
        Route::get('{session}/warehouses/{warehouse}/count', [StockTakeController::class, 'count'])->name('count');
        Route::post('{session}/warehouses/{warehouse}/count', [StockTakeController::class, 'saveCounts'])->name('saveCounts');
        Route::post('{session}/post', [StockTakeController::class, 'post'])->name('post');
        Route::post('{session}/cancel', [StockTakeController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/stock-take', StockTakeController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.stock-take');

    // ============================================================
    // Phase 6.5: Warehouse Transfers (same-branch = no GL, cross-branch = intercompany GL)
    // ============================================================
    Route::prefix('admin/warehouse-transfers')->name('admin.warehouse-transfers.')->group(function () {
        Route::get('product-stock', [WarehouseTransferController::class, 'getProductStock'])->name('product-stock');
        Route::post('{id}/confirm', [WarehouseTransferController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [WarehouseTransferController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/warehouse-transfers', WarehouseTransferController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.warehouse-transfers');

    // ============================================================
    // Phase 6.6: Damages (stock OUT + GL Dr Damage Loss / Cr Inventory)
    // ============================================================
    Route::prefix('admin/damages')->name('admin.damages.')->group(function () {
        Route::get('product-stock', [DamageController::class, 'getProductStock'])->name('product-stock');
        Route::post('{id}/confirm', [DamageController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [DamageController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/damages', DamageController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.damages');

    // ============================================================
    // Phase 7.1: Purchase Orders (draft document, no stock/GL)
    // ------------------------------------------------------------
    // Phase 1 (RBAC + branch isolation) — mirrors legacy
    // route_roles.php PurchaseOrderController matrix:
    //   index/show           : admin, manager, warehouse_manager, accountant  (read)
    //   create/store/edit    : admin, manager, warehouse_manager             (write)
    //   update/mark-sent     : admin, manager, warehouse_manager             (write)
    //   cancel               : admin, manager                                (destructive)
    // salesman/dispatcher/hr/user have NO access to any purchase route.
    // All writes also carry `branch.isolation` so a non-admin cannot
    // forge a branch_id in the POST body or cancel another branch's PO
    // by guessing its URL id.
    // ============================================================
    Route::prefix('admin/purchase-orders')->name('admin.purchase-orders.')->group(function () {
        // Phase 2 — typeahead product search (GET, throttle 60/min).
        Route::get('search-products', [PurchaseOrderController::class, 'searchProducts'])
            ->middleware(['throttle:60,1', 'role:admin,manager,warehouse_manager'])
            ->name('search-products');
        // Phase 2 — CSV export (GET, branch-scoped like index).
        Route::get('export', [PurchaseOrderController::class, 'export'])
            ->middleware(['role:admin,manager,warehouse_manager,accountant'])
            ->name('export');
        Route::post('{id}/mark-sent', [PurchaseOrderController::class, 'markAsSent'])
            ->name('markSent')
            ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{id}/cancel', [PurchaseOrderController::class, 'cancel'])
            ->name('cancel')
            ->middleware(['role:admin,manager', 'branch.isolation']);
    });
    Route::resource('admin/purchase-orders', PurchaseOrderController::class)
        ->only(['index', 'create', 'show', 'edit'])   // store + update split out below for tighter RBAC
        ->parameters(['admin/purchase-orders' => 'id'])  // override buggy inflector (would produce 'purchase_order' which is fine here, but force 'id' for consistency)
        ->names('admin.purchase-orders')
        ->middleware([
            'role:admin,manager,warehouse_manager,accountant',   // baseline read for the whole resource
        ])
        ->whereNumber('id'); // BUGFIX: prevent /create, /edit etc. from matching {id}
    // Tighten the write verbs on the resource to drop accountant and add branch.isolation.
    Route::put('admin/purchase-orders/{id}', [PurchaseOrderController::class, 'update'])
        ->name('admin.purchase-orders.update')
        ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation'])
        ->whereNumber('id');
    Route::post('admin/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->name('admin.purchase-orders.store')
        ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);

    // ============================================================
    // Phase 7.2: Purchase Receive / GRN (stock IN + GL + supplier_ledger + PO update)
    // ------------------------------------------------------------
    // Phase 1 RBAC (legacy PurchaseReceiveController matrix):
    //   index/show                : admin, manager, warehouse_manager, accountant
    //   create/store              : admin, manager, warehouse_manager
    //   get_po_details (AJAX)     : admin, manager, warehouse_manager
    //   confirm                   : admin, manager                (legacy: warehouse_manager absent)
    //   cancel                    : admin, manager
    // confirm/cancel are destructive (stock + GL reversal) so they
    // are restricted to admin/manager only — NOT warehouse_manager.
    // ============================================================
    Route::prefix('admin/purchase-receives')->name('admin.purchase-receives.')->group(function () {
        Route::get('po-details', [PurchaseReceiveController::class, 'getPoDetails'])
            ->name('po-details')
            ->middleware('role:admin,manager,warehouse_manager');
        // Phase 3 — CSV export (branch-scoped like index).
        Route::get('export', [PurchaseReceiveController::class, 'export'])
            ->name('export')
            ->middleware('role:admin,manager,warehouse_manager,accountant');
        Route::post('{id}/confirm', [PurchaseReceiveController::class, 'confirm'])
            ->name('confirm')
            ->middleware(['role:admin,manager', 'branch.isolation']);
        Route::post('{id}/cancel', [PurchaseReceiveController::class, 'cancel'])
            ->name('cancel')
            ->middleware(['role:admin,manager', 'branch.isolation']);
    });
    // IMPORTANT: register /create BEFORE the resource so Laravel matches it before {id}.
    // Also force the resource param name to 'id' via ->parameters() — Laravel's inflector
    // wrongly singularizes 'receives' -> 'receife' (treating it like 'wives' -> 'wife'),
    // which would otherwise cause /create to match the show() route with param 'purchase_receife'.
    Route::get('admin/purchase-receives/create', [PurchaseReceiveController::class, 'create'])
        ->name('admin.purchase-receives.create')
        ->middleware('role:admin,manager,warehouse_manager');
    Route::resource('admin/purchase-receives', PurchaseReceiveController::class)
        ->only(['index', 'show'])
        ->parameters(['admin/purchase-receives' => 'id'])  // override buggy inflector (was 'purchase_receife')
        ->names('admin.purchase-receives')
        ->middleware('role:admin,manager,warehouse_manager,accountant')
        ->whereNumber('id'); // BUGFIX: prevent /create from matching {id}
    Route::post('admin/purchase-receives', [PurchaseReceiveController::class, 'store'])
        ->name('admin.purchase-receives.store')
        ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);

    // ============================================================
    // Phase 7.3: Purchase Returns (stock OUT at original rate + Dr AP / Cr Inventory + supplier_ledger debit)
    // ------------------------------------------------------------
    // Phase 1 RBAC (legacy PurchaseReturnController matrix):
    //   index/show                       : admin, manager, warehouse_manager, accountant
    //   create/store                     : admin, manager, warehouse_manager
    //   receive-details (AJAX)           : admin, manager, warehouse_manager
    //   search-receives (AJAX, Phase 4)  : admin, manager, warehouse_manager
    //   summary (AJAX chip counts, Ph4)  : admin, manager, warehouse_manager, accountant
    //   export (CSV, Phase 4)            : admin, manager, warehouse_manager, accountant
    //   confirm                          : admin, manager                  (stock OUT + GL — destructive)
    //   cancel (reverse)                 : admin, manager, accountant      (legacy: accountant allowed on reverse)
    // ============================================================
    Route::prefix('admin/purchase-returns')->name('admin.purchase-returns.')->group(function () {
        Route::get('receive-details', [PurchaseReturnController::class, 'getReceiveDetails'])
            ->name('receive-details')
            ->middleware('role:admin,manager,warehouse_manager');
        Route::get('search-receives', [PurchaseReturnController::class, 'searchReceives'])
            ->name('search-receives')
            ->middleware('role:admin,manager,warehouse_manager');
        Route::get('summary', [PurchaseReturnController::class, 'summary'])
            ->name('summary')
            ->middleware('role:admin,manager,warehouse_manager,accountant');
        Route::get('export', [PurchaseReturnController::class, 'export'])
            ->name('export')
            ->middleware('role:admin,manager,warehouse_manager,accountant');
        Route::post('{id}/confirm', [PurchaseReturnController::class, 'confirm'])
            ->name('confirm')
            ->middleware(['role:admin,manager', 'branch.isolation']);
        Route::post('{id}/cancel', [PurchaseReturnController::class, 'cancel'])
            ->name('cancel')
            ->middleware(['role:admin,manager,accountant', 'branch.isolation']);
    });
    // IMPORTANT: register /create BEFORE the resource so Laravel matches it before {id}.
    // Force resource param name to 'id' via ->parameters() for consistency with receives.
    Route::get('admin/purchase-returns/create', [PurchaseReturnController::class, 'create'])
        ->name('admin.purchase-returns.create')
        ->middleware('role:admin,manager,warehouse_manager');
    Route::resource('admin/purchase-returns', PurchaseReturnController::class)
        ->only(['index', 'show'])
        ->parameters(['admin/purchase-returns' => 'id'])  // override inflector for consistency
        ->names('admin.purchase-returns')
        ->middleware('role:admin,manager,warehouse_manager,accountant')
        ->whereNumber('id'); // BUGFIX: prevent /create from matching {id}
    Route::post('admin/purchase-returns', [PurchaseReturnController::class, 'store'])
        ->name('admin.purchase-returns.store')
        ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);

    // ============================================================
    // Phase 6: Printable Return slip + per-module audit-log pages +
    // PurchaseAudit checklist dashboard.
    // ------------------------------------------------------------
    //   audit  (PO/GRN/Return) : admin, manager, accountant  (legacy route_roles.php matrix)
    //   slip   (Return)        : admin, manager, warehouse_manager, accountant  (read-only, opens in new tab)
    //   checklist + run_checks : admin, manager, accountant  (legacy PurchaseAuditController matrix)
    // ============================================================
    Route::get('admin/purchase-orders/audit', [PurchaseOrderController::class, 'audit'])
        ->name('admin.purchase-orders.audit')
        ->middleware('role:admin,manager,accountant');
    Route::get('admin/purchase-receives/audit', [PurchaseReceiveController::class, 'audit'])
        ->name('admin.purchase-receives.audit')
        ->middleware('role:admin,manager,accountant');
    Route::prefix('admin/purchase-returns')->name('admin.purchase-returns.')->group(function () {
        Route::get('audit', [PurchaseReturnController::class, 'audit'])
            ->name('audit')
            ->middleware('role:admin,manager,accountant');
        Route::get('{id}/slip', [PurchaseReturnController::class, 'slip'])
            ->name('slip')
            ->middleware('role:admin,manager,warehouse_manager,accountant')
            ->whereNumber('id'); // BUGFIX: slip must only accept numeric ids
    });
    // PurchaseAudit checklist — replaces the stub at admin/reports/purchase-audit.
    Route::get('admin/purchase-audit', [PurchaseAuditController::class, 'checklist'])
        ->name('admin.purchase-audit.checklist')
        ->middleware('role:admin,manager,accountant');
    Route::get('admin/purchase-audit/run', [PurchaseAuditController::class, 'runChecks'])
        ->name('admin.purchase-audit.run')
        ->middleware('role:admin,manager,accountant');

    // ============================================================
    // Phase 8.1: Sales Cart Service (per-user-per-customer draft cart)
    // P0-7: RBAC — all cart operations are salesman/manager/admin.
    // (Legacy: search_customer, add_to_cart, validate_cart, final_sales
    //  were all admin,manager,salesman per route_roles.php.)
    // ============================================================
    Route::prefix('admin/sales')->name('admin.sales.')
        ->middleware(['role:salesman,manager,admin', 'branch.isolation'])
        ->group(function () {
        Route::get('cart', [SalesCartController::class, 'index'])->name('cart');
        Route::post('cart/load', [SalesCartController::class, 'load'])->name('cart.load');
        Route::post('cart/add', [SalesCartController::class, 'add'])->name('cart.add');
        Route::post('cart/update', [SalesCartController::class, 'update'])->name('cart.update');
        Route::post('cart/remove', [SalesCartController::class, 'remove'])->name('cart.remove');
        Route::post('cart/clear', [SalesCartController::class, 'clear'])->name('cart.clear');
        Route::post('cart/validate', [SalesCartController::class, 'validateCart'])->name('cart.validate');
        Route::post('cart/soft-hold', [SalesCartController::class, 'softHold'])->name('cart.softHold');
        Route::get('cart/availability', [SalesCartController::class, 'checkAvailability'])->name('cart.availability');

        // R11: list all open draft carts for the #draft-tabs dock.
        // Used by the cart blade on page-load to restore the cashier's
        // multi-customer session. Throttled to 60 req/min (matches Legacy
        // guardJsonApi limit for sales/list_draft_carts).
        Route::get('cart/list-drafts', [SalesCartController::class, 'listDrafts'])
            ->middleware('throttle:60,1')
            ->name('cart.list-drafts');

        // R14: live customer credit snapshot for the cart page.
        // Returns credit_limit, current_due (SUM of debit−credit on
        // customer_ledger, is_reversed=false), and due_left. The cart
        // blade combines this with the cart subtotal to compute a
        // projected new balance — gives the cashier an early warning
        // before finalize. Throttled to 60 req/min (matches Legacy
        // guardJsonApi limit for sales/customer_details).
        Route::get('cart/customer-details', [SalesCartController::class, 'customerDetails'])
            ->middleware('throttle:60,1')
            ->name('cart.customer-details');

        // R1: Live search endpoints (ported from Legacy sales/search_customer &
        // sales/search_product). Replaces 500-row select2 dropdowns with
        // AJAX-driven typeahead. Throttled to 90 req/min via configured
        // API rate limiter (matches Legacy guardJsonApi).
        Route::get('cart/search-customer', [SalesCartController::class, 'searchCustomer'])
            ->middleware('throttle:90,1')
            ->name('cart.search-customer');
        Route::get('cart/search-product', [SalesCartController::class, 'searchProduct'])
            ->middleware('throttle:90,1')
            ->name('cart.search-product');
        Route::get('cart/product-by-code', [SalesCartController::class, 'productByCode'])
            ->middleware('throttle:120,1')
            ->name('cart.product-by-code');

        // P1-2: Manual stale-draft cancellation (manager+admin only)
        Route::post('cancel-stale-drafts', [SalesInvoiceController::class, 'cancelStaleDrafts'])
            ->name('cancel-stale-drafts')->middleware('role:manager,admin');

        // P1-3: Sales audit trail (accountant+manager+admin read-only)
        Route::get('audit', [SalesInvoiceController::class, 'auditTrail'])
            ->name('audit')->middleware('role:accountant,manager,admin');

        // G-14: Sales guideline page (Bengali/English) — all sales-module roles
        Route::get('guide', [SalesGuideController::class, 'guide'])
            ->name('guide')->middleware('role:salesman,warehouse_manager,dispatcher,accountant,manager,admin');

        // G-15: Go-live checklist (manager sign-off) — manager, admin, accountant, warehouse_manager
        Route::get('go-live-checklist', [GoLiveChecklistController::class, 'index'])
            ->name('go-live-checklist')->middleware('role:accountant,warehouse_manager,manager,admin');
    });

    // BUG-53: Phase 8.2 — Invoice finalize.
    // Pulled OUT of the admin/sales group above so we can drop the
    // branch.isolation middleware. Per user requirement, a salesman at
    // one branch MUST be able to finalize an invoice with branch_id set
    // to a DIFFERENT branch (e.g., Head Office salesman creates an
    // invoice dispatched from Branch-B). The invoice then appears in
    // Branch-B's warehouse manager's challan menu (filtered by
    // invoice.branch_id via BranchScope), not in Head Office's.
    //
    // branch.isolation would 403 this scenario for non-admin users.
    // We keep the role middleware (salesman/manager/admin only) — only
    // the branch check is dropped. EnforceBranchIsolation still applies
    // to invoice edit/update/cancel routes (which DO require the
    // request to be on the invoice's own branch).
    Route::post('admin/sales/finalize', [SalesInvoiceController::class, 'finalize'])
        ->name('admin.sales.finalize')
        ->middleware('role:salesman,manager,admin');

    // Phase 8.2: cart-data + credit-check are GET endpoints (read-only).
    // Pulled out of the admin/sales group above (same as finalize) so
    // they are NOT subject to branch.isolation. The cart may target a
    // different dispatch branch than the user's session branch, so these
    // read endpoints must work cross-branch.
    Route::get('admin/sales/cart-data', [SalesInvoiceController::class, 'getCartData'])
        ->name('admin.sales.cart-data')
        ->middleware('role:salesman,manager,admin');
    Route::get('admin/sales/credit-check', [SalesInvoiceController::class, 'checkCreditLimit'])
        ->name('admin.sales.credit-check')
        ->middleware('role:salesman,manager,admin');

    // ============================================================
    // Phase 8.2: Sales Invoices (list + show + cancel + edit)
    // P0-7: RBAC — index/show allow accountant (read); cancel is
    // salesman/manager/admin (legacy delete_invoice).
    // P1-1: edit/update — salesman/manager/admin (legacy edit/update).
    // ============================================================
    Route::prefix('admin/sales-invoices')->name('admin.sales-invoices.')->group(function () {
        // R21: Server-side DataTables JSON endpoint (smart sort + smart search).
        // BUG-52: warehouse_manager included — they need datatable JSON to
        // render the invoice list (which is now their entry point for
        // finding invoices awaiting godown prep).
        Route::get('datatable', [SalesInvoiceController::class, 'datatable'])
            ->name('datatable')->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
        // R22: Live status-chip counts JSON endpoint.
        Route::get('summary', [SalesInvoiceController::class, 'summary'])
            ->name('summary')->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
        // G-10: Call It A Day batch operation (remove invoices from daily collection list)
        Route::post('call-it-a-day', [SalesInvoiceController::class, 'callItADay'])
            ->name('call-it-a-day')->middleware(['role:salesman,accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/cancel', [SalesInvoiceController::class, 'cancel'])
            ->name('cancel')->middleware(['role:salesman,manager,admin', 'branch.isolation']);
        // P1-1: Edit draft invoice (GET form + PUT update)
        Route::get('{id}/edit', [SalesInvoiceController::class, 'edit'])
            ->name('edit')->middleware(['role:salesman,manager,admin', 'branch.isolation']);
        Route::put('{id}', [SalesInvoiceController::class, 'update'])
            ->name('update')->middleware(['role:salesman,manager,admin', 'branch.isolation']);
        // Dispatcher assignment (AJAX)
        Route::post('{id}/dispatchers', [SalesInvoiceController::class, 'assignDispatchers'])
            ->name('dispatchers.assign')->middleware(['role:salesman,manager,admin,warehouse_manager', 'branch.isolation']);
        Route::get('branch-dispatchers', [SalesInvoiceController::class, 'getBranchDispatchers'])
            ->name('branch-dispatchers')->middleware('role:salesman,manager,admin,warehouse_manager');
        // P1-6: Print views
        Route::get('{id}/print-invoice', [SalesInvoiceController::class, 'printInvoice'])
            ->name('print-invoice')->middleware('role:salesman,accountant,manager,admin');
        Route::get('{id}/print-godown', [SalesInvoiceController::class, 'printGodown'])
            ->name('print-godown')->middleware('role:warehouse_manager,manager,admin');
        Route::get('{id}/print-blank-godown', [SalesInvoiceController::class, 'printBlankGodown'])
            ->name('print-blank-godown')->middleware('role:warehouse_manager,manager,admin');
        // R19: Inline receive-payment modal body (AJAX-fetched HTML).
        // Returns the Blade partial injected into #receivePaymentModal on
        // the sales-invoices index page. Mirrors Legacy sales/receive_modal/{id}.
        Route::get('{id}/receive-modal', [SalesInvoiceController::class, 'receiveModal'])
            ->name('receive-modal')->middleware('role:salesman,accountant,manager,admin');
    });
    // index + show — accountant + warehouse_manager included (read access).
    // BUG-52: warehouse_manager was previously excluded — but they NEED
    // to see the invoice list + detail to discover invoices awaiting
    // godown prep. They have no other entry point. The WM cannot mutate
    // invoices (edit/update/cancel/finalize routes still exclude them),
    // so this is purely read access — safe to grant.
    Route::resource('admin/sales-invoices', SalesInvoiceController::class)
        ->only(['index', 'show'])->where(['warehouse' => '[0-9]+'])
        ->names('admin.sales-invoices')
        ->middleware('role:salesman,accountant,warehouse_manager,manager,admin');

    // CSV export — invoices
    Route::get('admin/sales-invoices/export-csv', [CsvExportController::class, 'exportInvoices'])
        ->name('admin.sales-invoices.export-csv')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 8.3: Sales Challans (godown prep + stock OUT + COGS GL)
    // P0-7: RBAC — godown/issue are warehouse_manager/dispatcher;
    // cancel (reverse) is manager/admin only (legacy reverse_challan).
    // ============================================================
    Route::prefix('admin/sales-challans')->name('admin.sales-challans.')->group(function () {
        // Godown prep + challan issue — warehouse_manager, dispatcher, manager, admin
        Route::get('godown/{invoiceId}', [SalesChallanController::class, 'godown'])
            ->name('godown')->middleware('role:warehouse_manager,dispatcher,manager,admin');
        Route::post('godown/{invoiceId}', [SalesChallanController::class, 'storeGodown'])
            ->name('storeGodown')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);
        Route::get('issue/{invoiceId}', [SalesChallanController::class, 'challanForm'])
            ->name('challan-form')->middleware('role:warehouse_manager,dispatcher,manager,admin');
        Route::post('issue/{invoiceId}', [SalesChallanController::class, 'issueChallan'])
            ->name('issueChallan')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);
        // Challan reverse — manager, admin only (legacy reverse_challan)
        Route::post('{id}/cancel', [SalesChallanController::class, 'cancel'])
            ->name('cancel')->middleware(['role:manager,admin', 'branch.isolation']);
        // P1-6: Print challan
        Route::get('{id}/print-challan', [SalesChallanController::class, 'printChallan'])
            ->name('print-challan')->middleware('role:warehouse_manager,dispatcher,accountant,manager,admin');
    });
    // index — warehouse_manager, dispatcher, manager, admin (legacy ChallanController::index)
    Route::resource('admin/sales-challans', SalesChallanController::class)
        ->only(['index'])
        ->names('admin.sales-challans')
        ->middleware('role:warehouse_manager,dispatcher,manager,admin');
    // show — accountant included (legacy details: admin,manager,accountant,warehouse_manager)
    Route::resource('admin/sales-challans', SalesChallanController::class)
        ->only(['show'])
        ->names('admin.sales-challans')
        ->middleware('role:accountant,warehouse_manager,manager,admin');

    // CSV export — challans
    Route::get('admin/sales-challans/export-csv', [CsvExportController::class, 'exportChallans'])
        ->name('admin.sales-challans.export-csv')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 8.4: Customer Payments (Dr Bank/Cash / Cr AR + intercompany)
    // P0-7: RBAC — create/store/index/show allow salesman+accountant;
    // cancel (reverse payment) is accountant/manager/admin only
    // (legacy reverse_payment).
    // ============================================================
    Route::prefix('admin/customer-payments')->name('admin.customer-payments.')->group(function () {
        Route::get('outstanding-invoices', [CustomerPaymentController::class, 'getOutstandingInvoices'])
            ->name('outstanding-invoices')->middleware('role:salesman,accountant,manager,admin');
        // Payment reverse — accountant, manager, admin (legacy reverse_payment)
        Route::post('{id}/cancel', [CustomerPaymentController::class, 'cancel'])
            ->name('cancel')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // P1-6: Print payment receipt
        Route::get('{id}/print-receipt', [CustomerPaymentController::class, 'printReceipt'])
            ->name('print-receipt')->middleware('role:salesman,accountant,manager,admin');
    });
    // store carries branch_id in the request body → branch.isolation
    Route::resource('admin/customer-payments', CustomerPaymentController::class)
        ->only(['index', 'create', 'show'])
        ->names('admin.customer-payments')
        ->middleware('role:salesman,accountant,manager,admin');
    Route::resource('admin/customer-payments', CustomerPaymentController::class)
        ->only(['store'])
        ->names('admin.customer-payments')
        ->middleware(['role:salesman,accountant,manager,admin', 'branch.isolation']);

    // ============================================================
    // Phase 8.5: Sales Returns (stock IN at ORIGINAL avg_cost + GL)
    // P0-7: RBAC — create/store is salesman/manager/admin; confirm is
    // warehouse_manager/accountant/manager/admin (two-step return);
    // reverse is accountant/manager/admin only (legacy SalesReturn::reverse).
    // ============================================================
    Route::prefix('admin/sales-returns')->name('admin.sales-returns.')->group(function () {
        // Return create flow — salesman, manager, admin
        Route::get('invoice-details', [SalesReturnController::class, 'getInvoiceDetails'])
            ->name('invoice-details')->middleware('role:salesman,manager,admin');
        // Return confirm — warehouse_manager, accountant, manager, admin (legacy confirm_store)
        Route::post('{id}/confirm', [SalesReturnController::class, 'confirm'])
            ->name('confirm')->middleware(['role:warehouse_manager,accountant,manager,admin', 'branch.isolation']);
        // Return reverse — accountant, manager, admin (legacy SalesReturn::reverse)
        Route::post('{id}/reverse', [SalesReturnController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // P1-6: Print return slip
        Route::get('{id}/print-slip', [SalesReturnController::class, 'printSlip'])
            ->name('print-slip')->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
    });
    // index — broadest (legacy: admin,manager,salesman,accountant,warehouse_manager)
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['index'])
        ->names('admin.sales-returns')
        ->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
    // create, store — salesman, manager, admin (legacy create/store)
    // store carries branch_id (resolved from invoice) → branch.isolation
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['create'])
        ->names('admin.sales-returns')
        ->middleware('role:salesman,manager,admin');
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['store'])
        ->names('admin.sales-returns')
        ->middleware(['role:salesman,manager,admin', 'branch.isolation']);
    // show — accountant, warehouse_manager, manager, admin (legacy details)
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['show'])
        ->names('admin.sales-returns')
        ->middleware('role:accountant,warehouse_manager,manager,admin');

    // ============================================================
    // Phase 9.5: Accounting Period Close + Year-End
    // ============================================================
    Route::prefix('admin/accounting')->name('admin.accounting.')->group(function () {
        Route::get('period-close', [AccountingPeriodController::class, 'index'])->name('period-close');
        Route::post('period-close', [AccountingPeriodController::class, 'close'])->name('period-close.store');
        Route::post('period-reopen', [AccountingPeriodController::class, 'reopen'])->name('period-reopen');
        Route::post('year-end-close', [AccountingPeriodController::class, 'yearEndClose'])->name('year-end-close');
    });

    // ============================================================
    // Phase 10: Notifications (rules + inbox + AJAX)
    // Phase 4 F-18a: split into admin-only rule CRUD (role:admin
    //   middleware — previously ANY authenticated user could create /
    //   toggle / delete notification rules, a privilege-escalation gap)
    //   vs. all-user inbox + AJAX endpoints (each user reads/marks
    //   their own notifications).
    // ============================================================
    Route::prefix('admin/notifications')->name('admin.notifications.')->group(function () {
        // Rule CRUD — admin / superadmin only.
        Route::middleware('role:admin')->group(function () {
            Route::get('rules', [NotificationController::class, 'rules'])->name('rules');
            Route::post('rules', [NotificationController::class, 'storeRule'])->name('storeRule');
            Route::post('rules/{id}/toggle', [NotificationController::class, 'toggleRule'])->name('toggleRule');
            Route::delete('rules/{id}', [NotificationController::class, 'destroyRule'])->name('destroyRule');
        });

        // Inbox + AJAX — all authenticated users (operates on the
        // authenticated user's own notifications only; see
        // NotificationController::markRead / inbox which scope by
        // auth()->user()->notifications()).
        Route::get('inbox', [NotificationController::class, 'inbox'])->name('inbox');
        Route::post('inbox/{id}/read', [NotificationController::class, 'markRead'])->name('markRead');
        Route::post('inbox/read-all', [NotificationController::class, 'markAllRead'])->name('markAllRead');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unreadCount');
        Route::get('recent', [NotificationController::class, 'recent'])->name('recent');
    });

    // ============================================================
    // Phase 11: System Policy & Compliance Framework
    // ============================================================
    Route::prefix('admin/compliance')->name('admin.compliance.')->group(function () {
        Route::get('/', [SystemPolicyController::class, 'index'])->name('index');
        Route::post('/activate', [SystemPolicyController::class, 'activate'])->name('activate');
        Route::post('/deactivate', [SystemPolicyController::class, 'deactivate'])->name('deactivate');
    });

    // ============================================================
    // Phase 12: Archive Layer (historical search across PG + legacy MySQL)
    // ============================================================
    Route::prefix('admin/archive')->name('admin.archive.')->group(function () {
        Route::get('/', [ArchiveController::class, 'index'])->name('index');
        Route::get('customer-ledger/{customerId}', [ArchiveController::class, 'customerLedger'])->name('customer-ledger');
        Route::get('supplier-ledger/{supplierId}', [ArchiveController::class, 'supplierLedger'])->name('supplier-ledger');
    });

    // ============================================================
    // Phase 20-AUDIT-HEALTH: Global Audit Log Viewer + System Health
    // ============================================================

    // Global audit log viewer — cross-module audit entries with filtering + CSV export.
    Route::prefix('admin/audit')->name('admin.audit.')->middleware('role:admin')->group(function () {
        Route::get('/', [GlobalAuditController::class, 'index'])->name('index');
        Route::get('/export', [GlobalAuditController::class, 'export'])->name('export');
        Route::get('/{id}', [GlobalAuditController::class, 'show'])->name('show')->where(['id' => '[0-9]+']);
    });

    // System health monitoring dashboard.
    Route::get('admin/system-health', [SystemHealthController::class, 'index'])
        ->name('admin.system-health.index')
        ->middleware('role:admin');

    // ============================================================
    // Phase 1E (Task 31): SSE (Server-Sent Events) for LISTEN/NOTIFY
    // Real-time event streaming from PostgreSQL → Redis → Browser
    // ============================================================
    Route::prefix('sse')->name('sse.')->group(function () {
        Route::get('events', [SseController::class, 'events'])->name('events');
        Route::get('status', [SseController::class, 'status'])->name('status');
    });
});

// ===================== HEALTH CHECK =====================
// The /up route is handled by Laravel's built-in health check (configured in bootstrap/app.php).
