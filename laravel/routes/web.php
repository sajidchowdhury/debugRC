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
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\StockTransactionController;
use Illuminate\Support\Facades\Route;

/**
 * RC_ERP Laravel Routes — Phase 3 + Phase 4 + Phase 5 + Phase 6.1.
 *
 * Phase 3: auth + dashboard.
 * Phase 4: master-data CRUD modules.
 * Phase 5: reporting layer (18 reports + reconciliation).
 * Phase 6.1: stock transactions (SSOT) + warehouse stock balances.
 *
 * Nginx routes /admin/* to Laravel; /* to legacy PHP.
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

    // ============================================================
    // Phase 4: Master Data Modules
    // ============================================================

    // --- Products ---
    Route::prefix('admin/products')->name('admin.products.')->group(function () {
        Route::get('audit', [ProductController::class, 'audit'])->name('audit');
        Route::get('{product}/price-history', [ProductController::class, 'priceHistory'])->name('priceHistory');
        Route::post('{product}/price', [ProductController::class, 'addPrice'])->name('addPrice');
        Route::delete('{product}/price/{price}', [ProductController::class, 'deletePrice'])->name('deletePrice');
        Route::post('{product}/restore', [ProductController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/products', ProductController::class)->names('admin.products');

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

    // --- Customers ---
    Route::prefix('admin/customers')->name('admin.customers.')->group(function () {
        Route::get('audit', [CustomerController::class, 'audit'])->name('audit');
        Route::post('{customer}/restore', [CustomerController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/customers', CustomerController::class)->names('admin.customers');

    // --- Suppliers ---
    Route::prefix('admin/suppliers')->name('admin.suppliers.')->group(function () {
        Route::get('audit', [SupplierController::class, 'audit'])->name('audit');
        Route::post('{supplier}/restore', [SupplierController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/suppliers', SupplierController::class)->names('admin.suppliers');

    // --- Employees ---
    Route::prefix('admin/employees')->name('admin.employees.')->group(function () {
        Route::get('audit', [EmployeeController::class, 'audit'])->name('audit');
        Route::get('{employee}/account', [EmployeeController::class, 'account'])->name('account');
        Route::post('{employee}/restore', [EmployeeController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/employees', EmployeeController::class)->names('admin.employees');

    // --- Banks ---
    Route::prefix('admin/banks')->name('admin.banks.')->group(function () {
        Route::get('audit', [BankController::class, 'audit'])->name('audit');
        Route::post('{bank}/restore', [BankController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/banks', BankController::class)->names('admin.banks');

    // --- Ledgers (Chart of Accounts) ---
    Route::prefix('admin/ledgers')->name('admin.ledgers.')->group(function () {
        Route::get('audit', [LedgerController::class, 'audit'])->name('audit');
        Route::post('{ledger}/restore', [LedgerController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/ledgers', LedgerController::class)->names('admin.ledgers');

    // --- Branches ---
    Route::prefix('admin/branches')->name('admin.branches.')->group(function () {
        Route::get('audit', [BranchController::class, 'audit'])->name('audit');
        Route::post('{branch}/restore', [BranchController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/branches', BranchController::class)->names('admin.branches');

    // --- Warehouses ---
    Route::prefix('admin/warehouses')->name('admin.warehouses.')->group(function () {
        Route::get('audit', [WarehouseController::class, 'audit'])->name('audit');
        Route::post('{warehouse}/restore', [WarehouseController::class, 'restore'])->name('restore');
    });
    Route::resource('admin/warehouses', WarehouseController::class)->names('admin.warehouses');

    // ============================================================
    // Phase 5: Reports + Reconciliation
    // ============================================================

    // Reports hub
    Route::get('admin/reports', [ReportController::class, 'index'])->name('admin.reports.index');

    // Reconciliation hub
    Route::get('admin/reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation.index');
    Route::get('admin/reconciliation/refresh', [ReconciliationController::class, 'refresh'])->name('admin.reconciliation.refresh');

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
        Route::get('customer-performance', [ReportController::class, 'customerPerformance'])->name('customerPerformance');

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
    });
});

// ===================== HEALTH CHECK =====================
// The /up route is handled by Laravel's built-in health check (configured in bootstrap/app.php).
