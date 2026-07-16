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
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockTakeController;
use App\Http\Controllers\Admin\WarehouseTransferController;
use App\Http\Controllers\Admin\DamageController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\PurchaseReceiveController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\SalesCartController;
use App\Http\Controllers\Admin\SalesInvoiceController;
use App\Http\Controllers\Admin\SalesChallanController;
use App\Http\Controllers\Admin\CustomerPaymentController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\AccountingPeriodController;
use Illuminate\Support\Facades\Route;

/**
 * RC_ERP Laravel Routes — Phases 3-9.5.
 *
 * Phase 9.5: accounting period close + year-end close.
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
        Route::get('{id}/confirm', fn() => redirect()->route('admin.stock-adjustments.index'))->name('confirm');
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
    // ============================================================
    Route::prefix('admin/purchase-orders')->name('admin.purchase-orders.')->group(function () {
        Route::post('{id}/mark-sent', [PurchaseOrderController::class, 'markAsSent'])->name('markSent');
        Route::post('{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/purchase-orders', PurchaseOrderController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
        ->names('admin.purchase-orders');

    // ============================================================
    // Phase 7.2: Purchase Receive / GRN (stock IN + GL + supplier_ledger + PO update)
    // ============================================================
    Route::prefix('admin/purchase-receives')->name('admin.purchase-receives.')->group(function () {
        Route::get('po-details', [PurchaseReceiveController::class, 'getPoDetails'])->name('po-details');
        Route::post('{id}/confirm', [PurchaseReceiveController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [PurchaseReceiveController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/purchase-receives', PurchaseReceiveController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.purchase-receives');

    // ============================================================
    // Phase 7.3: Purchase Returns (stock OUT at original rate + Dr AP / Cr Inventory + supplier_ledger debit)
    // ============================================================
    Route::prefix('admin/purchase-returns')->name('admin.purchase-returns.')->group(function () {
        Route::get('receive-details', [PurchaseReturnController::class, 'getReceiveDetails'])->name('receive-details');
        Route::post('{id}/confirm', [PurchaseReturnController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [PurchaseReturnController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/purchase-returns', PurchaseReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.purchase-returns');

    // ============================================================
    // Phase 8.1: Sales Cart Service (per-user-per-customer draft cart)
    // ============================================================
    Route::prefix('admin/sales')->name('admin.sales.')->group(function () {
        Route::get('cart', [SalesCartController::class, 'index'])->name('cart');
        Route::post('cart/load', [SalesCartController::class, 'load'])->name('cart.load');
        Route::post('cart/add', [SalesCartController::class, 'add'])->name('cart.add');
        Route::post('cart/update', [SalesCartController::class, 'update'])->name('cart.update');
        Route::post('cart/remove', [SalesCartController::class, 'remove'])->name('cart.remove');
        Route::post('cart/clear', [SalesCartController::class, 'clear'])->name('cart.clear');
        Route::post('cart/validate', [SalesCartController::class, 'validateCart'])->name('cart.validate');
        Route::post('cart/soft-hold', [SalesCartController::class, 'softHold'])->name('cart.softHold');
        Route::get('cart/availability', [SalesCartController::class, 'checkAvailability'])->name('cart.availability');

        // Phase 8.2: Invoice finalize
        Route::post('finalize', [SalesInvoiceController::class, 'finalize'])->name('finalize');
        Route::get('cart-data', [SalesInvoiceController::class, 'getCartData'])->name('cart-data');
        Route::get('credit-check', [SalesInvoiceController::class, 'checkCreditLimit'])->name('credit-check');
    });

    // ============================================================
    // Phase 8.2: Sales Invoices (list + show + cancel)
    // ============================================================
    Route::prefix('admin/sales-invoices')->name('admin.sales-invoices.')->group(function () {
        Route::post('{id}/cancel', [SalesInvoiceController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/sales-invoices', SalesInvoiceController::class)
        ->only(['index', 'show'])
        ->names('admin.sales-invoices');

    // ============================================================
    // Phase 8.3: Sales Challans (godown prep + stock OUT + COGS GL)
    // ============================================================
    Route::prefix('admin/sales-challans')->name('admin.sales-challans.')->group(function () {
        Route::get('godown/{invoiceId}', [SalesChallanController::class, 'godown'])->name('godown');
        Route::post('godown/{invoiceId}', [SalesChallanController::class, 'storeGodown'])->name('storeGodown');
        Route::get('issue/{invoiceId}', [SalesChallanController::class, 'challanForm'])->name('challan-form');
        Route::post('issue/{invoiceId}', [SalesChallanController::class, 'issueChallan'])->name('issueChallan');
        Route::post('{id}/cancel', [SalesChallanController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/sales-challans', SalesChallanController::class)
        ->only(['index', 'show'])
        ->names('admin.sales-challans');

    // ============================================================
    // Phase 8.4: Customer Payments (Dr Bank/Cash / Cr AR + intercompany)
    // ============================================================
    Route::prefix('admin/customer-payments')->name('admin.customer-payments.')->group(function () {
        Route::get('outstanding-invoices', [CustomerPaymentController::class, 'getOutstandingInvoices'])->name('outstanding-invoices');
        Route::post('{id}/cancel', [CustomerPaymentController::class, 'cancel'])->name('cancel');
    });
    Route::resource('admin/customer-payments', CustomerPaymentController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.customer-payments');

    // ============================================================
    // Phase 8.5: Sales Returns (stock IN at ORIGINAL avg_cost + GL)
    // ============================================================
    Route::prefix('admin/sales-returns')->name('admin.sales-returns.')->group(function () {
        Route::get('invoice-details', [SalesReturnController::class, 'getInvoiceDetails'])->name('invoice-details');
        Route::post('{id}/confirm', [SalesReturnController::class, 'confirm'])->name('confirm');
        Route::post('{id}/reverse', [SalesReturnController::class, 'reverse'])->name('reverse');
    });
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.sales-returns');

    // ============================================================
    // Phase 9.5: Accounting Period Close + Year-End
    // ============================================================
    Route::prefix('admin/accounting')->name('admin.accounting.')->group(function () {
        Route::get('period-close', [AccountingPeriodController::class, 'index'])->name('period-close');
        Route::post('period-close', [AccountingPeriodController::class, 'close'])->name('period-close.store');
        Route::post('period-reopen', [AccountingPeriodController::class, 'reopen'])->name('period-reopen');
        Route::post('year-end-close', [AccountingPeriodController::class, 'yearEndClose'])->name('year-end-close');
    });
});

// ===================== HEALTH CHECK =====================
// The /up route is handled by Laravel's built-in health check (configured in bootstrap/app.php).
