<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\UserPerformanceDashboardController;
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
use App\Http\Controllers\Admin\BranchDemandController;
use App\Http\Controllers\Admin\BranchDemandShadowController;
use App\Http\Controllers\Admin\BranchDemandReportController;
use App\Http\Controllers\Admin\DamageController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\PurchaseReceiveController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\PurchaseAuditController;
use App\Http\Controllers\Admin\CommissionRuleController;
use App\Http\Controllers\Admin\SalesCartController;
use App\Http\Controllers\Admin\SalesInvoiceController;
use App\Http\Controllers\Admin\SalesChallanController;
use App\Http\Controllers\Admin\CustomerPaymentController;
use App\Http\Controllers\Admin\SupplierTransactionController;
use App\Http\Controllers\Admin\EmployeeTransactionController;
use App\Http\Controllers\Admin\MoneyTransferController;
use App\Http\Controllers\Admin\OtherIncomeController;
use App\Http\Controllers\Admin\OtherExpenseController;
use App\Http\Controllers\Admin\ManualJournalController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\SalesGuideController;
use App\Http\Controllers\Admin\GoLiveChecklistController;
use App\Http\Controllers\Admin\AccountingPeriodController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\SseController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SystemPolicyController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\GlobalAuditController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\System\PartitionHealthController;
use App\Http\Controllers\Admin\ShadowModeController;
use App\Http\Controllers\Admin\BudgetController;
use App\Http\Controllers\Admin\DimensionController;
use App\Http\Controllers\Admin\FiscalYearController;
use App\Http\Controllers\Admin\ConsolidationController;
use App\Http\Controllers\Admin\BankReconciliationController;
use App\Http\Controllers\Admin\FixedAssetController;
use App\Http\Controllers\HelpController;
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

    // ============================================================
    // Help System — Menu & Module Helper (Phase 2 scaffold).
    // Returns HTML partials for the help offcanvas + module sheet.
    // Throttled to prevent abuse; auth-only (no public help access).
    // See docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md
    // ============================================================
    Route::prefix('help')->middleware('throttle:30,1')->group(function () {
        Route::get('menu/{key}', [HelpController::class, 'menu'])->name('help.menu');
        Route::get('module/{key}', [HelpController::class, 'module'])->name('help.module');
    });

    // ============================================================
    // User Performance Dashboard (Phase 0+ — per-user attribution
    // dashboard for the /dashboard route).
    // See docs/USER_PERFORMANCE_DASHBOARD_PLAN.md for the full plan.
    // The legacy company-wide DashboardController was deleted in
    // REPORTS-AUDIT-3 G-136 (query patterns retrievable from git history).
    // ============================================================
    // G-043 (CRITICAL, defense-in-depth): dashboard routes had only `auth`
    // middleware — any authed user could hit /dashboard. The dashboard is
    // the user's own performance view (intentionally permissive across all
    // 10 roles), but we still attach `role:` as a defense-in-depth gate so
    // EnsureRole confirms the user has a recognised role. Superadmin passes
    // via the middleware's bypass; admin via the admin-tier bypass; all 8
    // operational roles via exact match. See `reports/dashboards.md` §14 G1.
    Route::get('dashboard', [UserPerformanceDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('role:admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other');
    Route::get('dashboard/sales-trend', [UserPerformanceDashboardController::class, 'salesTrendAjax'])
        ->name('dashboard.salesTrend')
        ->middleware('role:admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other');
    // Phase 6 — AJAX fragment endpoint for no-full-reload period/employee
    // switching. Returns JSON {html, period, periodLabel, range, employeeId}
    // where `html` is the rendered #perf-dashboard inner markup. The Blade
    // view detects fragmentMode=true and skips @extends('layouts.admin').
    Route::get('dashboard/fragment', [UserPerformanceDashboardController::class, 'fragmentAjax'])
        ->name('dashboard.fragment')
        ->middleware('role:admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other');

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
        // Menu permissions: admin sets per-user menu visibility
        Route::get('{user}/menu-permissions', [UserController::class, 'menuPermissions'])->name('menu-permissions')->where(['user' => '[0-9]+'])->middleware('role:admin');
        Route::post('{user}/menu-permissions', [UserController::class, 'updateMenuPermissions'])->name('menu-permissions.update')->where(['user' => '[0-9]+'])->middleware('role:admin');
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

    // Phase 5: Approval Workflow
    // G-178 (HIGH): added `branch.isolation` to the admin/approvals route
    // group. The middleware's inferTableFromUri() now maps `approvals` to
    // null (approval_requests has NO branch_id — only requested_by user_id).
    // The middleware still checks request->input('branch_id') for forged
    // values on POST bodies, and the ApprovalService validates the entity's
    // branch_id against the approver's session branch at the service layer.
    Route::prefix('admin/approvals')->name('admin.approvals.')->middleware(['role:accountant,manager,admin', 'branch.isolation'])->group(function () {
        Route::get('/', [ApprovalController::class, 'queue'])->name('queue');
        Route::post('{id}/approve', [ApprovalController::class, 'approve'])->name('approve')->middleware('role:manager,admin');
        Route::post('{id}/reject', [ApprovalController::class, 'reject'])->name('reject')->middleware('role:manager,admin');
        Route::get('workflows', [ApprovalController::class, 'workflows'])->name('workflows');
        Route::post('workflows/{id}', [ApprovalController::class, 'updateWorkflow'])->name('workflows.update')->middleware('role:admin');
    });

    // Financial reports (18 reports)
    // G-045 / G-041 / G-042 (CRITICAL): the entire `admin/reports` prefix
    // group previously had NO `role:` middleware — only the outer `auth`
    // gate from L90. Any authenticated user (incl. salesman, hr, dispatcher)
    // could hit Trial Balance, P&L, Balance Sheet, Cash Flow, all 4 CTE
    // reports, and the 3 CSV exports (stocktake variance, stocktake weekly,
    // damage) — financial data exfiltration. RLS only enforces branch
    // isolation, NOT role-based read access. Adding `role:accountant,
    // manager,admin` per the recommended fix in `reports/reports-catalog.md`
    // §13.1 + `reports/csv-export.md` §14 G1 + `reports/cte-reports.md` §14
    // G1 (all three cite this same group). NOTE: this also blocks salesmen
    // from the 4 sales-category reports (revenue_overview, gross_margin,
    // customer_performance, sales_funnel) — see reports-catalog.md §13.1
    // for the optional per-route relaxation to `role:admin,manager,
    // accountant,salesman` if cross-role read is later required.
    Route::prefix('admin/reports')->name('admin.reports.')->middleware('role:accountant,manager,admin')->group(function () {
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
        Route::get('sales-audit-checklist/run', [ReportController::class, 'salesAuditRun'])->name('salesAuditRun');
        Route::get('purchase-audit', [ReportController::class, 'purchaseAudit'])->name('purchaseAudit');
        // Phase 6 (Stock Take plan): variance + weekly control reports with
        // CSV export (Excel-friendly BOM) and per-line GL drill-down.
        // RLS on stock_take_sessions scopes reads by branch automatically.
        Route::get('stocktake-variance', [ReportController::class, 'stocktakeVariance'])->name('stocktakeVariance');
        Route::get('stocktake-variance/export', [ReportController::class, 'stocktakeVarianceExport'])->name('stocktakeVarianceExport');
        Route::get('stocktake-variance/journal/{session}', [ReportController::class, 'stocktakeVarianceJournal'])->name('stocktakeVarianceJournal');
        Route::get('stocktake-weekly', [ReportController::class, 'stocktakeWeekly'])->name('stocktakeWeekly');
        Route::get('stocktake-weekly/export', [ReportController::class, 'stocktakeWeeklyExport'])->name('stocktakeWeeklyExport');
        Route::get('branch-demand-weekly', [ReportController::class, 'branchDemandWeekly'])->name('branchDemandWeekly');

        // Phase 6 (Damage plan): Dedicated damage report with CSV export.
        // RLS on damage_invoices scopes reads by branch automatically.
        Route::get('damage', [ReportController::class, 'damageReport'])->name('damageReport');
        Route::get('damage/export', [ReportController::class, 'damageReportExport'])->name('damageReportExport');

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
    // ------------------------------------------------------------
    // Phase 1 (Stock Adjustment plan — Authorization & Role Enforcement):
    // Stock Adjustment is a BOOKKEEPING CORRECTION TOOL (opening balances,
    // data migration, UOM fixes, post-conversion fixes, legacy cleanup),
    // used infrequently by an Accountant / system administrator. It posts
    // directly to stock + GL, so it must NOT be reachable by operational
    // roles (salesman, dispatcher, hr, warehouse_manager).
    //
    // RBAC matrix (mirrors legacy route_roles.php StockAdjustmentController,
    // tightened to the accountant/admin bookkeeping identity):
    //   index / show / audit (read)        : admin, manager, accountant
    //   create / store / product-rate       : admin, accountant          (write — draft)
    //   {id}/confirm                        : admin, accountant          (posts stock + GL)
    //   {id}/cancel                         : admin, accountant          (reversal)
    //
    // Legacy allowed warehouse_manager on create/store; this plan narrows
    // the tool to accountant/admin only (the task explicitly states
    // "Accountant / system administrator, infrequently"). If warehouse_manager
    // access is later required, add the role back to the write group.
    //
    // Defense-in-depth layers:
    //   1. role: middleware (primary gate)
    //   2. StockAdjustmentPolicy via $this->authorize() in the controller
    //   3. branch.isolation middleware on POST writes (prevents cross-branch
    //      confirm/cancel by guessing URL id — resolves {id} to
    //      stock_adjustments.branch_id via EnforceBranchIsolation::inferTableFromUri)
    //   4. PostgreSQL RLS on stock_adjustments (DB-enforced; set by
    //      SetAppBranchId middleware via SET app.branch_id GUC)
    // ============================================================

    // --- Read access: admin, manager, accountant ---
    Route::middleware('role:admin,manager,accountant')->group(function () {
        Route::get('admin/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('admin.stock-adjustments.index');
        Route::get('admin/stock-adjustments/audit', [StockAdjustmentController::class, 'audit'])->name('admin.stock-adjustments.audit');
        // Phase 8 — 7-section integrity checklist (supersedes the flat audit()
        // route; audit() above redirects here for backward compat).
        Route::get('admin/stock-adjustments/checklist', [StockAdjustmentController::class, 'checklist'])->name('admin.stock-adjustments.checklist');
        // Phase 8.1 (G2) — CSV export of the filtered list. Same filters as
        // index(); cursor()-streamed + BOM-prefixed for Excel. Read-only.
        Route::get('admin/stock-adjustments/export', [StockAdjustmentController::class, 'export'])->name('admin.stock-adjustments.export');
        // Phase 7 — Reconciliation view (drift detection). GET only; the
        // drift computation runs via the POST run-reconcile AJAX below.
        Route::get('admin/stock-adjustments/reconcile', [StockAdjustmentController::class, 'reconcile'])->name('admin.stock-adjustments.reconcile');
        Route::get('admin/stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->name('admin.stock-adjustments.show')
            ->where(['id' => '[0-9]+']);
        // Phase 8.4 (G18) — Print voucher for a single adjustment. Read-only
        // (logs a 'print' audit action for the forensic trail).
        Route::get('admin/stock-adjustments/{id}/print', [StockAdjustmentController::class, 'print'])->name('admin.stock-adjustments.print')
            ->where(['id' => '[0-9]+']);
    });

    // --- Write access (create draft, fetch rate, submit, confirm, cancel): admin, accountant only ---
    // branch.isolation on POST routes resolves {id} → stock_adjustments.branch_id
    // and rejects non-admin users operating on another branch's adjustment.
    Route::middleware('role:admin,accountant')->group(function () {
        Route::get('admin/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('admin.stock-adjustments.create');
        Route::post('admin/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('admin.stock-adjustments.store');
        Route::get('admin/stock-adjustments/product-rate', [StockAdjustmentController::class, 'getProductRate'])->name('admin.stock-adjustments.product-rate');
        // Phase 5 — AJAX: available UOMs + conversion factors for a product
        // (powers the per-row UOM dropdown on the create form).
        Route::get('admin/stock-adjustments/product-uoms', [StockAdjustmentController::class, 'getProductUoms'])->name('admin.stock-adjustments.product-uoms');
        Route::get('admin/stock-adjustments/{id}/confirm', fn() => redirect()->route('admin.stock-adjustments.index'))->name('admin.stock-adjustments.confirm-form')
            ->where(['id' => '[0-9]+']);
        // Phase 3 — submit a draft for approval (draft → submitted, or
        // auto-advance to approved when below the auto-approve threshold).
        Route::post('admin/stock-adjustments/{id}/submit', [StockAdjustmentController::class, 'submit'])->name('admin.stock-adjustments.submit')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::post('admin/stock-adjustments/{id}/confirm', [StockAdjustmentController::class, 'confirm'])->name('admin.stock-adjustments.confirm')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::post('admin/stock-adjustments/{id}/cancel', [StockAdjustmentController::class, 'cancel'])->name('admin.stock-adjustments.cancel')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 7 — Reconciliation AJAX: run the drift computation. No
        // {id} in the URL so no branch.isolation needed; the service scopes
        // by the caller's branch_id (non-admin) and RLS enforces at the DB.
        Route::post('admin/stock-adjustments/reconcile/run', [StockAdjustmentController::class, 'runReconcile'])->name('admin.stock-adjustments.run-reconcile');
    });

    // --- Phase 7: Rebuild snapshot (admin only): destructive maintenance op ---
    // Rebuilds warehouse_stock from the stock_transactions ledger. Admin-only
    // at the route (role:admin) AND defense-in-depth in the controller.
    Route::middleware('role:admin')->group(function () {
        Route::post('admin/stock-adjustments/reconcile/rebuild', [StockAdjustmentController::class, 'rebuildSnapshot'])->name('admin.stock-adjustments.rebuild-snapshot');
    });

    // --- Phase 3: Approve / Reject (maker-checker): admin, manager only ---
    // Separated from the write group because approval is an approver action,
    // not a drafter action. The service enforces segregation of duties
    // (approver !== submitter) on top of this role gate.
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('admin/stock-adjustments/{id}/approve', [StockAdjustmentController::class, 'approve'])->name('admin.stock-adjustments.approve')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::post('admin/stock-adjustments/{id}/reject', [StockAdjustmentController::class, 'reject'])->name('admin.stock-adjustments.reject')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
    });

    // ============================================================
    // Phase 6.4: Stock Take (sessions + per-warehouse counts + variance posting)
    // ------------------------------------------------------------
    // Phase 0 RBAC (Stock Take plan §7 Phase 0; mirrors legacy
    // route_roles.php StockTakeController matrix):
    //   index/show              : admin, manager, warehouse_manager, accountant  (read)
    //   create/setup/count      : admin, manager, warehouse_manager             (write — form/GET)
    //   store/saveCounts/post   : admin, manager, warehouse_manager             (write — POST)
    //   cancel                  : admin, manager                                (destructive — reversal)
    // salesman/dispatcher/hr/user have NO access to any stock-take route.
    // All POST writes carry `branch.isolation` so a non-admin cannot forge a
    // branch_id in the POST body, nor post/cancel another branch's session by
    // guessing its URL id. (EnforceBranchIsolation resolves the {session} URL
    // param to stock_take_sessions.branch_id — see middleware inferTableFromUri.)
    // ============================================================
    Route::prefix('admin/stock-take')->name('admin.stock-take.')->group(function () {
        // Phase 2: read-only audit trail + health-check screens. Visible to
        // every role that can read stock-take (admin, manager,
        // warehouse_manager, accountant). No branch.isolation — RLS on
        // stock_take_audit_log scopes reads by branch automatically.
        Route::get('checklist', [StockTakeController::class, 'checklist'])
            ->name('checklist')->middleware('role:admin,manager,warehouse_manager,accountant');
        Route::get('audit', [StockTakeController::class, 'audit'])
            ->name('audit')->middleware('role:admin,manager,warehouse_manager,accountant');
        // Phase 12: AJAX health-summary endpoint surfaced on the admin
        // dashboard's "Stock Take Health" tile. Returns a tiny JSON payload
        // (summary counts + critical-failure list) so the dashboard can
        // render the tile without leaving the page. Restricted to admin /
        // manager / accountant — the same read roles as checklist/audit
        // minus warehouse_manager (the dashboard is finance-facing, not
        // count-floor-facing).
        Route::get('health-summary', [StockTakeController::class, 'healthSummary'])
            ->name('health-summary')->middleware('role:admin,manager,accountant');

        Route::get('{session}/warehouses/{warehouse}/setup', [StockTakeController::class, 'setupCounts'])
            ->name('setup')->middleware('role:admin,manager,warehouse_manager');
        Route::get('{session}/warehouses/{warehouse}/count', [StockTakeController::class, 'count'])
            ->name('count')->middleware('role:admin,manager,warehouse_manager');
        Route::post('{session}/warehouses/{warehouse}/count', [StockTakeController::class, 'saveCounts'])
            ->name('saveCounts')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        // Phase 7 (Stock Take plan): Count UX — barcode scan, bulk paste, CSV
        // import, recount, autosave. All five share the write-role middleware
        // (admin/manager/warehouse_manager) + branch.isolation so a non-admin
        // cannot forge a cross-branch write. The {session} URL param lets
        // EnforceBranchIsolation resolve stock_take_sessions.branch_id.
        Route::post('{session}/warehouses/{warehouse}/scan', [StockTakeController::class, 'scanCount'])
            ->name('scanCount')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/warehouses/{warehouse}/bulk-paste', [StockTakeController::class, 'bulkPaste'])
            ->name('bulkPaste')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/warehouses/{warehouse}/import', [StockTakeController::class, 'importCounts'])
            ->name('importCounts')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/warehouses/{warehouse}/recount', [StockTakeController::class, 'recount'])
            ->name('recount')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/warehouses/{warehouse}/autosave', [StockTakeController::class, 'autosave'])
            ->name('autosave')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/post', [StockTakeController::class, 'post'])
            ->name('post')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/cancel', [StockTakeController::class, 'cancel'])
            ->name('cancel')->middleware(['role:admin,manager', 'branch.isolation']);

        // Phase 10 (Stock Take plan): reversal vs cancellation distinction +
        // re-open after reversal.
        //   reverse : posted → reversed. Full stock + GL reversal. admin/manager
        //              only (destructive — undoes a posted session's books).
        //   re-open : reversed → counting. Re-openable up to max_reopens policy.
        //              admin/manager only (re-posting a reversed session is a
        //              materially significant action).
        // Both carry branch.isolation so a non-admin cannot reverse/re-open
        // another branch's session by guessing its URL id.
        Route::post('{session}/reverse', [StockTakeController::class, 'reverse'])
            ->name('reverse')->middleware(['role:admin,manager', 'branch.isolation']);
        Route::post('{session}/re-open', [StockTakeController::class, 'reOpen'])
            ->name('re-open')->middleware(['role:admin,manager', 'branch.isolation']);

        // Phase 4: approval workflow & segregation of duties.
        //   submit : counter (admin/manager/warehouse_manager) — counting → submitted.
        //   approve: approver (admin/manager) — submitted → approved. The
        //            service enforces approver ≠ submitter (segregation of
        //            duties); the role middleware is the first gate, the
        //            service check is the second.
        //   reject : approver (admin/manager) — submitted → counting.
        // All three carry branch.isolation so a non-admin cannot submit/
        // approve/reject another branch's session by guessing its URL id.
        Route::post('{session}/submit', [StockTakeController::class, 'submit'])
            ->name('submit')->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{session}/approve', [StockTakeController::class, 'approve'])
            ->name('approve')->middleware(['role:admin,manager', 'branch.isolation']);
        Route::post('{session}/reject', [StockTakeController::class, 'reject'])
            ->name('reject')->middleware(['role:admin,manager', 'branch.isolation']);

        // Phase 5 (Stock Take plan): cycle count & ABC classification.
        //   products/search : AJAX product picker for the ad_hoc scope
        //                     (admin/manager/warehouse_manager — write roles).
        //   scope/preview   : AJAX "how many products will this scope match?"
        //                     sanity check before creating a cycle-count session.
        //   abc-report      : ABC classification report screen (read roles).
        //   abc/refresh     : manual "refresh the ABC materialized view now"
        //                     (admin/manager only — destructive-ish, runs a
        //                     CONCURRENTLY refresh).
        // No branch.isolation on these: products/search + scope/preview take
        // an explicit warehouse_id in the query string (RLS scopes the
        // warehouse_stock join by branch); abc-report + abc/refresh read the
        // global materialized view (RLS-scoped per warehouse's branch).
        Route::get('products/search', [StockTakeController::class, 'searchProducts'])
            ->name('products.search')->middleware('role:admin,manager,warehouse_manager');
        Route::post('scope/preview', [StockTakeController::class, 'previewScope'])
            ->name('scope.preview')->middleware('role:admin,manager,warehouse_manager');
        Route::get('abc-report', [StockTakeController::class, 'abcReport'])
            ->name('abc-report')->middleware('role:admin,manager,warehouse_manager,accountant');
        Route::post('abc/refresh', [StockTakeController::class, 'refreshAbc'])
            ->name('abc.refresh')->middleware('role:admin,manager');
    });
    // Resource: read verbs only (index/create/show) get baseline read role.
    // `store` is split out below for tighter RBAC + branch.isolation.
    Route::resource('admin/stock-take', StockTakeController::class)
        ->only(['index', 'create', 'show'])
        ->names('admin.stock-take')
        ->middleware('role:admin,manager,warehouse_manager,accountant');
    // store — split out: drops accountant (read-only) + adds branch.isolation
    // (the create form posts branch_id in the body).
    Route::post('admin/stock-take', [StockTakeController::class, 'store'])
        ->name('admin.stock-take.store')
        ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);

    // ============================================================
    // Phase 6.5 + Phase 4: Warehouse Transfers (same-branch = no GL, cross-branch = intercompany GL)
    // Phase 4: Audit trail routes (checklist, run-checks, audit, reconcile)
    // ============================================================
    Route::prefix('admin/warehouse-transfers')->name('admin.warehouse-transfers.')->group(function () {
        Route::get('product-stock', [WarehouseTransferController::class, 'getProductStock'])->name('product-stock');
        // Phase 6.3 — Transfer Summary Report routes
        Route::get('summary', [WarehouseTransferController::class, 'summary'])->name('summary');
        Route::post('summary-data', [WarehouseTransferController::class, 'summaryData'])->name('summary-data');
        // REPORTS-AUDIT-6 (G-241 / csv-export.md G26): summary CSV export.
        // Same filters as summaryData (date_from/date_to/branch_id) but
        // returns a streamed multi-section CSV instead of JSON. Role
        // middleware tightened to admin/manager/accountant per the gap
        // text (salesmen should not bulk-export transfer aggregates).
        Route::get('summary/export', [WarehouseTransferController::class, 'summaryExport'])
            ->middleware('role:admin,manager,accountant')
            ->name('summary.export');
        Route::get('export', [WarehouseTransferController::class, 'export'])->name('export');
        Route::get('{id}/print', [WarehouseTransferController::class, 'print'])->name('print');
        Route::post('{id}/confirm', [WarehouseTransferController::class, 'confirm'])->name('confirm');
        Route::post('{id}/cancel', [WarehouseTransferController::class, 'cancel'])->name('cancel');
        // Phase 4 — Audit Trail & Data Integrity routes
        Route::get('checklist', [WarehouseTransferController::class, 'checklist'])->name('checklist');
        Route::post('run-checks', [WarehouseTransferController::class, 'runChecks'])->name('run-checks');
        Route::get('{id}/audit', [WarehouseTransferController::class, 'audit'])->name('audit');
        Route::get('reconcile', [WarehouseTransferController::class, 'reconcile'])->name('reconcile');
        Route::post('run-reconcile', [WarehouseTransferController::class, 'runReconcile'])->name('run-reconcile');
    });
    Route::resource('admin/warehouse-transfers', WarehouseTransferController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names('admin.warehouse-transfers');

    // ============================================================
    // Phase 2: Branch Demands (cross-branch product supply)
    // Create → Send → Confirm Receipt → Reverse/Delete/Reject
    // ============================================================
    // Phase 9: Branch Demand — role-based access
    //   admin, manager — Full access
    //   warehouse_manager — Create, send, confirm receipt, view
    //   accountant — View, audit checklist, weekly report
    // ============================================================
    Route::prefix('admin/branch-demands')->name('admin.branch-demands.')->middleware('menu.permission:branchdemand')->group(function () {
        // Read-only routes — accessible to all branch demand roles
        Route::get('pending', [BranchDemandController::class, 'pending'])->name('pending');
        Route::get('pending-receipt', [BranchDemandController::class, 'pendingReceipt'])->name('pending-receipt');
        Route::get('branches', [BranchDemandController::class, 'getBranches'])->name('branches');
        Route::get('products', [BranchDemandController::class, 'getProducts'])->name('products');
        Route::get('warehouses/{id}', [BranchDemandController::class, 'getWarehousesByBranch'])->name('warehouses');
        Route::get('stock/{pid}/{bid}', [BranchDemandController::class, 'getWarehouseStock'])->name('stock');
        Route::get('outstanding', [BranchDemandController::class, 'getOutstanding'])->name('outstanding');
        Route::get('ledger-history', [BranchDemandController::class, 'getLedgerHistory'])->name('ledger-history');
        Route::get('settlement-preview', [BranchDemandController::class, 'previewSettlement'])->name('settlement-preview');

        // Write operations — admin, manager, warehouse_manager
        Route::post('{id}/send', [BranchDemandController::class, 'send'])->name('send')
            ->middleware('role:admin,manager,warehouse_manager');
        Route::post('{id}/confirm-receipt', [BranchDemandController::class, 'confirmReceipt'])->name('confirm-receipt')
            ->middleware('role:admin,manager,warehouse_manager');
        Route::post('{id}/reverse', [BranchDemandController::class, 'reverse'])->name('reverse')
            ->middleware('role:admin,manager');
        Route::post('{id}/reject', [BranchDemandController::class, 'reject'])->name('reject')
            ->middleware('role:admin,manager,warehouse_manager');

        // Phase 7: Repricing — admin, manager only
        Route::post('{id}/reprice', [BranchDemandController::class, 'reprice'])->name('reprice')
            ->middleware('role:admin,manager');
        Route::get('price-range-comparison', [BranchDemandController::class, 'priceRangeComparison'])->name('price-range-comparison');
        Route::get('{id}/repricing-history', [BranchDemandController::class, 'getRepricingHistory'])->name('repricing-history');
        Route::post('check-sale-price', [BranchDemandController::class, 'checkSalePriceRange'])->name('check-sale-price');

        // Phase 6: Weekly Audit Report — all roles
        Route::get('weekly-report', [BranchDemandReportController::class, 'weekly'])->name('weekly-report');
        // LOW-WAVE-2 (G-302 / csv-export.md G29): tighten the export route to
        // admin/manager/accountant. The route group's menu.permission:branchdemand
        // middleware (L725 above) gates visibility per-user, but the export
        // exposes financial data (profit, COGS, customer due, cash in hand)
        // that warehouse_manager + lower roles shouldn't be able to bulk-download.
        // Matches the precedent set by warehouse-transfers/summary/export (L698).
        Route::get('weekly-report/export', [BranchDemandReportController::class, 'exportCsv'])
            ->middleware('role:admin,manager,accountant')
            ->name('weekly-report.export');
        Route::get('weekly-report/drill-down', [BranchDemandReportController::class, 'drillDown'])->name('weekly-report.drill-down');

        // Phase 8: Audit & Accountability — admin, manager, accountant
        Route::get('checklist', [BranchDemandController::class, 'checklist'])->name('checklist')
            ->middleware('role:admin,manager,accountant');
        Route::get('{id}/audit', [BranchDemandController::class, 'audit'])->name('audit');
        Route::get('reconcile', [BranchDemandController::class, 'reconcile'])->name('reconcile')
            ->middleware('role:admin,manager,accountant');
    });
    Route::resource('admin/branch-demands', BranchDemandController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy'])
        ->names('admin.branch-demands');

    // ============================================================
    // Phase 7.3: Shadow Mode Dashboard (Warehouse Transfer comparison)
    // Admin-only: compare Laravel vs legacy transfer data, track cutover readiness
    // ============================================================
    Route::prefix('admin/shadow-mode')->name('admin.shadow-mode.')->group(function () {
        Route::get('/', [ShadowModeController::class, 'index'])->name('index');
        Route::get('comparisons', [ShadowModeController::class, 'comparisons'])->name('comparisons');
        Route::get('comparisons/{id}', [ShadowModeController::class, 'comparisonDetail'])->name('detail');
        Route::get('cutover', [ShadowModeController::class, 'cutover'])->name('cutover');
        Route::post('run-comparison', [ShadowModeController::class, 'runComparison'])->name('run-comparison');
        Route::post('purge', [ShadowModeController::class, 'purge'])->name('purge');
        Route::post('toggle-mode', [ShadowModeController::class, 'toggleMode'])->name('toggle-mode');
    });

    // ============================================================
    // Phase 10: Branch Demand Shadow Mode Dashboard
    // Admin-only: compare Laravel vs legacy demand data, track cutover readiness
    // ============================================================
    Route::prefix('admin/branch-demand-shadow')->name('admin.branch-demand-shadow.')->group(function () {
        Route::get('/', [BranchDemandShadowController::class, 'index'])->name('index');
        Route::get('comparisons', [BranchDemandShadowController::class, 'comparisons'])->name('comparisons');
        Route::get('comparisons/{id}', [BranchDemandShadowController::class, 'comparisonDetail'])->name('detail');
        Route::get('cutover', [BranchDemandShadowController::class, 'cutover'])->name('cutover');
        Route::post('run-comparison', [BranchDemandShadowController::class, 'runComparison'])->name('run-comparison');
        Route::post('purge', [BranchDemandShadowController::class, 'purge'])->name('purge');
    });

    // ============================================================
    // Phase 6.6: Damages (stock OUT + GL Dr Damage Loss / Cr Inventory)
    // ------------------------------------------------------------
    // Phase 0 (Damage plan): RBAC + branch isolation — mirrors legacy
    // route_roles.php DamageController matrix:
    //   index/show/product-stock/export : admin, manager, warehouse_manager  (read)
    //   create/store                    : admin, manager, warehouse_manager  (write — draft only)
    //   confirm                         : admin, manager                     (posts stock + GL — tighter)
    //   cancel                          : admin, manager                     (reverses stock + GL — tighter)
    // salesman/dispatcher/hr/user/accountant have NO access to any damage route.
    // All POST writes carry `branch.isolation` so a non-admin operating on
    // another branch's damage (by guessing its URL id) gets a clean 403
    // instead of a confusing RLS-induced 404. EnforceBranchIsolation resolves
    // {id} → damage_invoices.branch_id via the 'damages' entry in
    // inferTableFromUri(). Defense-in-depth: RLS on damage_invoices is the
    // DB-level backstop.
    // ============================================================

    // --- Read access: admin, manager, warehouse_manager ---
    Route::middleware('role:admin,manager,warehouse_manager')->group(function () {
        Route::get('admin/damages', [DamageController::class, 'index'])->name('admin.damages.index');
        Route::get('admin/damages/product-stock', [DamageController::class, 'getProductStock'])->name('admin.damages.product-stock');

        // Phase 7 — AJAX product search (Select2, replaces the 500-cap
        // dropdown on the create form) + CSV export of the current index
        // filter selection. Placed before the {id} show route so the literal
        // paths win over the numeric wildcard.
        Route::get('admin/damages/products/search', [DamageController::class, 'searchProducts'])
            ->name('admin.damages.products.search');
        Route::get('admin/damages/export', [DamageController::class, 'export'])
            ->name('admin.damages.export');

        Route::get('admin/damages/{id}', [DamageController::class, 'show'])->name('admin.damages.show')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 7 — printable damage slip (A5). Opens in a new tab; uses the
        // layouts/print.blade.php chrome (branch-colored toolbar + auto-print).
        // Same role + branch.isolation gate as show().
        Route::get('admin/damages/{id}/print', [DamageController::class, 'print'])
            ->name('admin.damages.print')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 3 — evidence attachment view/download. Same role gate as show
        // (read access). branch.isolation resolves {id} → damage_invoices
        // .branch_id (the 'damages' URI is mapped in inferTableFromUri).
        // Files are streamed from the private disk via the controller — they
        // are NOT web-accessible via /storage/... (evidence is sensitive).
        Route::get('admin/damages/{id}/attachments/{attachmentId}/view', [DamageController::class, 'viewAttachment'])
            ->name('admin.damages.attachments.view')
            ->where(['id' => '[0-9]+', 'attachmentId' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::get('admin/damages/{id}/attachments/{attachmentId}/download', [DamageController::class, 'downloadAttachment'])
            ->name('admin.damages.attachments.download')
            ->where(['id' => '[0-9]+', 'attachmentId' => '[0-9]+'])
            ->middleware('branch.isolation');
    });

    // --- Write access (create draft, fetch product-stock): admin, manager, warehouse_manager ---
    Route::middleware('role:admin,manager,warehouse_manager')->group(function () {
        Route::get('admin/damages/create', [DamageController::class, 'create'])->name('admin.damages.create');
        Route::post('admin/damages', [DamageController::class, 'store'])->name('admin.damages.store');

        // Phase 3 — evidence upload + delete. Same role gate as create/store
        // (warehouse_manager is usually the one on the floor photographing).
        // Draft-only enforcement lives in DamagePolicy (uploadAttachment /
        // deleteAttachment return false when !isDraft). branch.isolation
        // protects cross-branch access at the request level; RLS is the
        // DB-level backstop on damage_attachments.
        Route::post('admin/damages/{id}/attachments', [DamageController::class, 'uploadAttachment'])
            ->name('admin.damages.attachments.store')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::delete('admin/damages/{id}/attachments/{attachmentId}', [DamageController::class, 'deleteAttachment'])
            ->name('admin.damages.attachments.destroy')
            ->where(['id' => '[0-9]+', 'attachmentId' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 5 — submit a draft for approval (draft → submitted/approved).
        // Same role gate as create/store (warehouse_manager is the maker).
        // The auto-approve shortcut (admin/manager + total ≤ threshold) is
        // handled in DamageService::submitForApproval — the route is the
        // same regardless. branch.isolation resolves {id} → branch_id.
        Route::post('admin/damages/{id}/submit', [DamageController::class, 'submit'])
            ->name('admin.damages.submit')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
    });

    // --- Destructive access (confirm posts stock+GL; cancel reverses): admin, manager only ---
    // Tighter than the create/store group — only admin/manager may post or
    // reverse a write-off (mirrors legacy route_roles.php reverse rule).
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('admin/damages/{id}/confirm', [DamageController::class, 'confirm'])->name('admin.damages.confirm')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::post('admin/damages/{id}/cancel', [DamageController::class, 'cancel'])->name('admin.damages.cancel')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 5 — approve / reject a submitted damage (the maker-checker
        // gate). Approve transitions submitted → approved (ready to confirm);
        // reject transitions submitted → rejected (terminal). Both enforce
        // segregation of duties (approver/rejecter ≠ submitter) in the policy
        // + the service. branch.isolation resolves {id} → branch_id.
        Route::post('admin/damages/{id}/approve', [DamageController::class, 'approve'])->name('admin.damages.approve')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
        Route::post('admin/damages/{id}/reject', [DamageController::class, 'reject'])->name('admin.damages.reject')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');

        // Phase 4 — employee recovery. Posts a GL entry (Dr employee_payable
        // / Cr loss) + an employee_ledger deduction row. As financially
        // sensitive as confirm/cancel (it creates a liability for an
        // employee), so it sits in the admin/manager destructive group.
        // DamagePolicy::recoverFromEmployee additionally requires the damage
        // to be confirmed + have an accountable employee + no prior recovery.
        Route::post('admin/damages/{id}/recover', [DamageController::class, 'recoverFromEmployee'])
            ->name('admin.damages.recover')
            ->where(['id' => '[0-9]+'])
            ->middleware('branch.isolation');
    });

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
        // PURCHASING-API-2 (G-116): maker-checker approval flow for POs.
        // submit = the PO creator requests approval (draft → submitted).
        // approve/reject = a manager (NOT the submitter) acts on the pending
        // request. The generic /admin/approvals queue also handles these —
        // these PO-specific routes are convenience shortcuts from the PO
        // show page. branch.isolation applies to all three.
        Route::post('{id}/submit', [PurchaseOrderController::class, 'submitForApproval'])
            ->name('submit')
            ->middleware(['role:admin,manager,warehouse_manager', 'branch.isolation']);
        Route::post('{id}/approve', [PurchaseOrderController::class, 'approve'])
            ->name('approve')
            ->middleware(['role:manager,admin', 'branch.isolation']);
        Route::post('{id}/reject', [PurchaseOrderController::class, 'reject'])
            ->name('reject')
            ->middleware(['role:manager,admin', 'branch.isolation']);
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
    // HIGH-WAVE-2 (G-164/G12): Commission rule management web UI.
    // Mirrors the API endpoints (CommissionApiController) but for
    // browser-based access. RBAC: admin, manager (same as API writes —
    // API reads use `api.auth:manager,admin`; API writes use `api.auth:admin`).
    // The store action reuses StoreCommissionRuleRequest (same validation
    // as the API) + CommissionService::createRule() (same business logic
    // as the API — no duplication). The create form supports all 4 rule
    // types (flat/tiered/product_group/target_bonus) with JS-driven
    // conditional sections for tiers, product groups, and targets.
    // ============================================================
    Route::prefix('admin/commission-rules')->name('admin.commission-rules.')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [CommissionRuleController::class, 'index'])->name('index');
        Route::get('create', [CommissionRuleController::class, 'create'])->name('create');
        Route::post('/', [CommissionRuleController::class, 'store'])->name('store');
        Route::get('{id}', [CommissionRuleController::class, 'show'])->name('show')->whereNumber('id');
        Route::post('{id}/deactivate', [CommissionRuleController::class, 'deactivate'])->name('deactivate')->whereNumber('id');
    });

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
        // F-12: rate-limited to 180 req/min per user (Legacy parity). The
        // DataTables client fires one AJAX per draw (page/sort/filter); 180/min
        // is well above normal use but rejects script abuse → HTTP 429.
        Route::get('datatable', [SalesInvoiceController::class, 'datatable'])
            ->name('datatable')->middleware(['role:salesman,accountant,warehouse_manager,manager,admin', 'throttle:180,1']);
        // R22: Live status-chip counts JSON endpoint.
        // F-12: rate-limited to 120 req/min per user (Legacy parity). The
        // summary is lighter than datatable but polled on filter changes;
        // 120/min rejects abuse while allowing aggressive filter UX.
        Route::get('summary', [SalesInvoiceController::class, 'summary'])
            ->name('summary')->middleware(['role:salesman,accountant,warehouse_manager,manager,admin', 'throttle:120,1']);
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
        // Phase 3: AJAX list of active dispatcher-role employees for the
        // invoice's branch. Literal segment 'dispatchers' is registered
        // BEFORE the {id} resource routes below so Laravel's route
        // matcher doesn't treat it as a challan id.
        Route::get('dispatchers', [SalesChallanController::class, 'dispatchers'])
            ->name('dispatchers')->middleware('role:warehouse_manager,dispatcher,manager,admin');
        // 3-step godown workflow — Step 1: print blank godown copy (requires
        // dispatcher selection). Sits BEFORE the godown/{invoiceId} routes so
        // the literal segment 'blank-godown' isn't swallowed by the {invoiceId}
        // wildcard. The WM must complete this step before godown prep opens.
        Route::get('blank-godown/{invoiceId}', [SalesChallanController::class, 'blankGodownForm'])
            ->name('blank-godown-form')->middleware('role:warehouse_manager,dispatcher,manager,admin');
        Route::post('blank-godown/{invoiceId}', [SalesChallanController::class, 'storeBlankGodown'])
            ->name('store-blank-godown')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);

        // Godown prep + challan issue — warehouse_manager, dispatcher, manager, admin
        Route::get('godown/{invoiceId}', [SalesChallanController::class, 'godown'])
            ->name('godown')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);
        Route::post('godown/{invoiceId}', [SalesChallanController::class, 'storeGodown'])
            ->name('storeGodown')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);
        Route::get('issue/{invoiceId}', [SalesChallanController::class, 'challanForm'])
            ->name('challan-form')->middleware(['role:warehouse_manager,dispatcher,manager,admin', 'branch.isolation']);
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
        Route::get('get-customer-due', [CustomerPaymentController::class, 'getCustomerDue'])
            ->name('get-customer-due')->middleware('role:salesman,accountant,manager,admin');
        // Phase 3B: Audit logs
        Route::get('audit', [CustomerPaymentController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        // Payment reverse — accountant, manager, admin (legacy reverse_payment)
        Route::post('{id}/cancel', [CustomerPaymentController::class, 'cancel'])
            ->name('cancel')->middleware(['role:accountant,manager,admin']);
        // P1-6: Print payment receipt
        Route::get('{id}/print-receipt', [CustomerPaymentController::class, 'printReceipt'])
            ->name('print-receipt')->middleware('role:salesman,accountant,manager,admin');
        // Phase 3B: Print payment slip (voucher)
        Route::get('{id}/slip', [CustomerPaymentController::class, 'slip'])
            ->name('slip')->middleware('role:salesman,accountant,manager,admin');
    });
    // store carries branch_id in the request body → branch.isolation
    Route::prefix('admin/customer-payments')->name('admin.customer-payments.')->group(function () {
        Route::post('/', [CustomerPaymentController::class, 'store'])
            ->name('store')->middleware(['role:salesman,accountant,manager,admin', 'branch.isolation']);
    });
    Route::resource('admin/customer-payments', CustomerPaymentController::class)
        ->only(['index', 'create'])
        ->names('admin.customer-payments')
        ->middleware('role:salesman,accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/customer-payments/{id}', [CustomerPaymentController::class, 'show'])
        ->name('admin.customer-payments.show')
        ->middleware('role:salesman,accountant,manager,admin');

    // ============================================================
    // Phase 1 (Accounts Sub-Ledger): Supplier Transactions
    // RBAC — create/store/index/show allow accountant+manager+admin;
    // reverse is accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/supplier-transactions')->name('admin.supplier-transactions.')->group(function () {
        Route::get('audit', [SupplierTransactionController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        Route::post('get-due', [SupplierTransactionController::class, 'getDue'])
            ->name('get-due')->middleware('role:accountant,manager,admin');
        Route::get('search', [SupplierTransactionController::class, 'searchSupplier'])
            ->name('search')->middleware('role:accountant,manager,admin');
        // Store — explicit POST to avoid Route::resource() split naming issues
        Route::post('/', [SupplierTransactionController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // Payment reverse — accountant, manager, admin
        Route::post('{id}/reverse', [SupplierTransactionController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // Print payment slip
        Route::get('{id}/slip', [SupplierTransactionController::class, 'slip'])
            ->name('slip')->middleware('role:accountant,manager,admin');
    });
    Route::resource('admin/supplier-transactions', SupplierTransactionController::class)
        ->only(['index', 'create'])
        ->names('admin.supplier-transactions')
        ->middleware('role:accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/supplier-transactions/{id}', [SupplierTransactionController::class, 'show'])
        ->name('admin.supplier-transactions.show')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 2 (Accounts Sub-Ledger): Employee Transactions
    // RBAC — create/store/index/show allow accountant+manager+admin;
    // reverse is accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/employee-transactions')->name('admin.employee-transactions.')->group(function () {
        Route::get('audit', [EmployeeTransactionController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        Route::post('get-due', [EmployeeTransactionController::class, 'getDue'])
            ->name('get-due')->middleware('role:accountant,manager,admin');
        Route::get('search', [EmployeeTransactionController::class, 'searchEmployee'])
            ->name('search')->middleware('role:accountant,manager,admin');
        // Store — explicit POST to avoid Route::resource() split naming issues
        Route::post('/', [EmployeeTransactionController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // Transaction reverse — accountant, manager, admin
        Route::post('{id}/reverse', [EmployeeTransactionController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // Print transaction slip
        Route::get('{id}/slip', [EmployeeTransactionController::class, 'slip'])
            ->name('slip')->middleware('role:accountant,manager,admin');
    });
    Route::resource('admin/employee-transactions', EmployeeTransactionController::class)
        ->only(['index', 'create'])
        ->names('admin.employee-transactions')
        ->middleware('role:accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/employee-transactions/{id}', [EmployeeTransactionController::class, 'show'])
        ->name('admin.employee-transactions.show')
        ->middleware('role:accountant,manager,admin');

    // ============================================================

    // ============================================================
    // Phase 4 (Accounts Sub-Ledger): Money Transfers
    // RBAC — accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/money-transfers')->name('admin.money-transfers.')->group(function () {
        Route::get('audit', [MoneyTransferController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        // Store — explicit POST to avoid Route::resource() split naming issues
        Route::post('/', [MoneyTransferController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/reverse', [MoneyTransferController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::get('{id}/slip', [MoneyTransferController::class, 'slip'])
            ->name('slip')->middleware('role:accountant,manager,admin');
    });
    Route::resource('admin/money-transfers', MoneyTransferController::class)
        ->only(['index', 'create'])
        ->names('admin.money-transfers')
        ->middleware('role:accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/money-transfers/{id}', [MoneyTransferController::class, 'show'])
        ->name('admin.money-transfers.show')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 5 (Accounts Sub-Ledger): Other Incomes
    // RBAC — accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/other-incomes')->name('admin.other-incomes.')->group(function () {
        Route::get('audit', [OtherIncomeController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        // Store — explicit POST to avoid Route::resource() split naming issues
        Route::post('/', [OtherIncomeController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/reverse', [OtherIncomeController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::get('{id}/slip', [OtherIncomeController::class, 'slip'])
            ->name('slip')->middleware('role:accountant,manager,admin');
    });
    Route::resource('admin/other-incomes', OtherIncomeController::class)
        ->only(['index', 'create'])
        ->names('admin.other-incomes')
        ->middleware('role:accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/other-incomes/{id}', [OtherIncomeController::class, 'show'])
        ->name('admin.other-incomes.show')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 5 (Accounts Sub-Ledger): Other Expenses
    // RBAC — accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/other-expenses')->name('admin.other-expenses.')->group(function () {
        Route::get('audit', [OtherExpenseController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        // Store — explicit POST to avoid Route::resource() split naming issues
        Route::post('/', [OtherExpenseController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/reverse', [OtherExpenseController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::get('{id}/slip', [OtherExpenseController::class, 'slip'])
            ->name('slip')->middleware('role:accountant,manager,admin');
    });
    Route::resource('admin/other-expenses', OtherExpenseController::class)
        ->only(['index', 'create'])
        ->names('admin.other-expenses')
        ->middleware('role:accountant,manager,admin');
    // Show route AFTER resource so create/edit routes match first; no ->where() constraint
    // so route() helper works with placeholder values in JS config
    Route::get('admin/other-expenses/{id}', [OtherExpenseController::class, 'show'])
        ->name('admin.other-expenses.show')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 6 (Accounts Sub-Ledger): Manual Journals
    // RBAC — accountant/manager/admin only.
    // ============================================================
    Route::prefix('admin/manual-journals')->name('admin.manual-journals.')->group(function () {
        Route::get('audit', [ManualJournalController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        Route::post('/', [ManualJournalController::class, 'store'])
            ->name('store')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/reverse', [ManualJournalController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/post', [ManualJournalController::class, 'post'])
            ->name('post')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        // Phase 5: Approval workflow
        // G-178 (HIGH): added `branch.isolation` to submit/approve/reject.
        // Manual journals are branch-scoped (manual_journals.branch_id), so
        // the middleware resolves {id} → branch_id via the existing
        // `manual-journals` pattern in inferTableFromUri(). A manager from
        // Branch A can no longer approve another branch's pending JE.
        Route::post('{id}/submit', [ManualJournalController::class, 'submitForApproval'])
            ->name('submit')->middleware(['role:accountant,manager,admin', 'branch.isolation']);
        Route::post('{id}/approve', [ManualJournalController::class, 'approve'])
            ->name('approve')->middleware(['role:manager,admin', 'branch.isolation']);
        Route::post('{id}/reject', [ManualJournalController::class, 'reject'])
            ->name('reject')->middleware(['role:manager,admin', 'branch.isolation']);
    });
    Route::resource('admin/manual-journals', ManualJournalController::class)
        ->only(['index', 'create'])
        ->names('admin.manual-journals')
        ->middleware('role:accountant,manager,admin');
    Route::get('admin/manual-journals/{id}', [ManualJournalController::class, 'show'])
        ->name('admin.manual-journals.show')
        ->middleware('role:accountant,manager,admin');

    // Phase 8.5 + Phase 2: Sales Returns (stock IN at ORIGINAL avg_cost + GL)
    // P0-7: RBAC — create/store is salesman/manager/admin; confirm is
    // warehouse_manager/accountant/manager/admin (two-step return);
    // reverse is accountant/manager/admin only (legacy SalesReturn::reverse).
    //
    // Phase 2 (Purchase Return parity) adds 4 AJAX endpoints:
    //   search-invoices (typeahead) : salesman, manager, admin
    //   summary       (chip counts) : salesman, accountant, warehouse_manager, manager, admin
    //   export        (CSV)         : salesman, accountant, warehouse_manager, manager, admin
    //   ?datatables=1 (server-side) : index middleware (salesman, accountant, warehouse_manager, manager, admin)
    //
    // Route layout mirrors Purchase Return's hybrid pattern:
    //   1. explicit prefix group for AJAX helpers + write actions + print-slip
    //   2. separate Route::get('/create') BEFORE the resource (so /create
    //      doesn't match {id})
    //   3. Route::resource limited to index/show with ->whereNumber('id')
    //      (kept as TWO resources because index RBAC ≠ show RBAC — index
    //      includes salesman, show does not)
    //   4. separate Route::post for store (branch.isolation on the body)
    // ============================================================
    Route::prefix('admin/sales-returns')->name('admin.sales-returns.')->group(function () {
        // Return create flow — salesman, manager, admin
        Route::get('invoice-details', [SalesReturnController::class, 'getInvoiceDetails'])
            ->name('invoice-details')->middleware('role:salesman,manager,admin');
        // Phase 2 — invoice typeahead for the create-page picker
        Route::get('search-invoices', [SalesReturnController::class, 'searchInvoices'])
            ->name('search-invoices')->middleware('role:salesman,manager,admin');
        // Phase 2 — chip counts AJAX for the index page
        Route::get('summary', [SalesReturnController::class, 'summary'])
            ->name('summary')->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
        // Phase 2 — CSV export of filtered returns
        Route::get('export', [SalesReturnController::class, 'export'])
            ->name('export')->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
        // Phase 3.4 — Per-module audit log (accountant+manager+admin only —
        // matches reverse RBAC; audit trail contains reverse reasons + GL amounts).
        Route::get('audit', [SalesReturnController::class, 'audit'])
            ->name('audit')->middleware('role:accountant,manager,admin');
        // Return confirm — warehouse_manager, accountant, manager, admin (legacy confirm_store)
        Route::post('{id}/confirm', [SalesReturnController::class, 'confirm'])
            ->name('confirm')->middleware(['role:warehouse_manager,accountant,manager,admin', 'branch.isolation'])
            ->whereNumber('id');
        // Return reverse — accountant, manager, admin (legacy SalesReturn::reverse)
        Route::post('{id}/reverse', [SalesReturnController::class, 'reverse'])
            ->name('reverse')->middleware(['role:accountant,manager,admin', 'branch.isolation'])
            ->whereNumber('id');
        // Phase 6.2 — reverse-preview AJAX (pre-check UX): shows friendly
        // "Insufficient stock in X for Y: need Z, have W" BEFORE the user
        // commits. Same RBAC + branch.isolation as the reverse POST.
        Route::get('{id}/reverse-preview', [SalesReturnController::class, 'reversePreview'])
            ->name('reverse-preview')->middleware(['role:accountant,manager,admin', 'branch.isolation'])
            ->whereNumber('id');
        // P1-6: Print return slip
        Route::get('{id}/print-slip', [SalesReturnController::class, 'printSlip'])
            ->name('print-slip')->middleware('role:salesman,accountant,warehouse_manager,manager,admin')
            ->whereNumber('id');
    });
    // create — salesman, manager, admin. Registered BEFORE the resource so
    // /create doesn't match {id}. (Mirrors Purchase Return.)
    Route::get('admin/sales-returns/create', [SalesReturnController::class, 'create'])
        ->name('admin.sales-returns.create')
        ->middleware('role:salesman,manager,admin');
    // index — broadest read (legacy: admin,manager,salesman,accountant,warehouse_manager)
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['index'])
        ->parameters(['admin/sales-returns' => 'id'])
        ->names('admin.sales-returns')
        ->middleware('role:salesman,accountant,warehouse_manager,manager,admin');
    // show — narrower read: accountant, warehouse_manager, manager, admin (legacy details)
    // whereNumber('id') prevents /create from matching {id} as a defensive second layer.
    Route::resource('admin/sales-returns', SalesReturnController::class)
        ->only(['show'])
        ->parameters(['admin/sales-returns' => 'id'])
        ->names('admin.sales-returns')
        ->middleware('role:accountant,warehouse_manager,manager,admin')
        ->whereNumber('id');
    // store — salesman, manager, admin; branch.isolation (branch_id resolved from invoice)
    Route::post('admin/sales-returns', [SalesReturnController::class, 'store'])
        ->name('admin.sales-returns.store')
        ->middleware(['role:salesman,manager,admin', 'branch.isolation']);

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
            // WORKFLOWS-AUDIT-2 (G-184): NEW — PUT/PATCH updateRule route.
            // Previously rules could only be created/toggled/deleted, never
            // edited. Admins had to delete + recreate to change name/event/
            // recipients/description, losing times_fired + created_at +
            // created_by. Both verbs map to the same updateRule method.
            Route::match(['put', 'patch'], 'rules/{id}', [NotificationController::class, 'updateRule'])->name('updateRule');
            Route::post('rules/reset-defaults', [NotificationController::class, 'resetDefaults'])->name('resetDefaults');
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
    // G-173 (HIGH): route group previously relied solely on the in-controller
    // `isSuperadmin()` check (defense-in-depth gap). Added `role:superadmin`
    // middleware so EnsureRole rejects non-superadmin users at the route
    // layer before the controller is invoked. See
    // `security/system-policy-compliance.md` §13 G3.
    // ============================================================
    Route::prefix('admin/compliance')->name('admin.compliance.')->middleware('role:superadmin')->group(function () {
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

    // Phase 10.1 — Phase 8.4: Partition health monitoring dashboard.
    // Surfaces alerts from partition_health_alerts (Phase 8.3), per-table
    // partition counts, pg_partman config, largest partitions, stale VACUUM
    // stats, default-partition row counts, missing future partitions, and
    // unused BRIN indexes. Defensive: renders even if Phase 8 SQL objects
    // don't exist yet.
    Route::get('admin/system/partition-health', [PartitionHealthController::class, 'index'])
        ->name('admin.system.partition-health')
        ->middleware('role:admin');

    // ============================================================
    // Phase 6: Budgeting & Cost Centers
    // RBAC — accountant/manager/admin for budget management
    // ============================================================

    // Budgets
    // G-353 (HIGH): per-action role differentiation. The group middleware
    // stays `role:accountant,manager,admin` (BR27 basic requirement — the
    // subsystem is accessible to accountant, manager, admin). The 3
    // status-changing routes (activate / close / cancel) get a tighter
    // `role:manager,admin` overlay: activation/closure/cancellation are
    // management approval actions (they move a budget between lifecycle
    // states that lock / unlock posting), not data entry. Accountants
    // retain create/store/show/edit/update access for budget drafting +
    // revision. Same pattern as G-114 fixed-assets (Session 6). See
    // `finance/budgeting.md` §G27.
    Route::prefix('admin/budgets')->name('admin.budgets.')->middleware('role:accountant,manager,admin')->group(function () {
        Route::get('variance', [BudgetController::class, 'varianceReport'])->name('variance');
        Route::get('export-csv', [BudgetController::class, 'exportCsv'])->name('export-csv');
        Route::get('create', [BudgetController::class, 'create'])->name('create');
        Route::post('/', [BudgetController::class, 'store'])->name('store');
        Route::get('{budget}', [BudgetController::class, 'show'])->name('show');
        Route::get('{budget}/edit', [BudgetController::class, 'edit'])->name('edit');
        Route::put('{budget}', [BudgetController::class, 'update'])->name('update');
        Route::patch('{budget}/activate', [BudgetController::class, 'activate'])->name('activate')
            ->middleware('role:manager,admin');
        Route::patch('{budget}/close', [BudgetController::class, 'close'])->name('close')
            ->middleware('role:manager,admin');
        Route::patch('{budget}/cancel', [BudgetController::class, 'cancel'])->name('cancel')
            ->middleware('role:manager,admin');
    });
    Route::get('admin/budgets', [BudgetController::class, 'index'])
        ->name('admin.budgets.index')
        ->middleware('role:accountant,manager,admin');

    // Dimensions & Cost Centers
    // G-340 (G18) FINANCE-DIM-1: split into read + write groups. Read routes
    // (index, segment reports, create form, show, edit) stay accessible to
    // accountant,manager,admin. Write routes (store, update, storeValue,
    // toggleValue, destroy) are elevated to manager,admin — accountants can
    // view + run segment reports but cannot mutate master data. Policy per
    // dimensions-cost-centers.md §13.3 #18 (the less-disruptive option;
    // managers retain write access). The stricter §4 table alternative
    // (admin-only writes) would require a product decision; this wave uses
    // the documented §13.3 #18 policy.
    Route::prefix('admin/dimensions')->name('admin.dimensions.')->middleware('role:accountant,manager,admin')->group(function () {
        Route::get('segment-pnl', [DimensionController::class, 'segmentPnl'])->name('segment-pnl');
        Route::get('segment-bs', [DimensionController::class, 'segmentBs'])->name('segment-bs');
        Route::get('create', [DimensionController::class, 'create'])->name('create');
        Route::get('{dimension}', [DimensionController::class, 'show'])->name('show');
        Route::get('{dimension}/edit', [DimensionController::class, 'edit'])->name('edit');
    });
    Route::get('admin/dimensions', [DimensionController::class, 'index'])
        ->name('admin.dimensions.index')
        ->middleware('role:accountant,manager,admin');

    // G-340 (G18) FINANCE-DIM-1: write routes — manager + admin only.
    // G-343 (G19) FINANCE-DIM-1: destroy route added (soft-delete dimension
    // + its values; pre-check refuses if any journal_lines are tagged).
    Route::prefix('admin/dimensions')->name('admin.dimensions.')->middleware('role:manager,admin')->group(function () {
        Route::post('/', [DimensionController::class, 'store'])->name('store');
        Route::put('{dimension}', [DimensionController::class, 'update'])->name('update');
        Route::delete('{dimension}', [DimensionController::class, 'destroy'])->name('destroy');
        Route::post('{dimension}/values', [DimensionController::class, 'storeValue'])->name('store-value');
        Route::patch('{dimension}/values/{value}/toggle', [DimensionController::class, 'toggleValue'])->name('toggle-value');
    });

    // ============================================================
    // Phase 7: Enhanced Period & Fiscal Year Controls
    // RBAC — accountant/manager/admin for fiscal year management
    // ============================================================

    // Fiscal Years
    Route::prefix('admin/fiscal-years')->name('admin.fiscal-years.')->middleware('role:accountant,manager,admin')->group(function () {
        Route::get('close-log', [FiscalYearController::class, 'closeLog'])->name('close-log');
        Route::get('create', [FiscalYearController::class, 'create'])->name('create');
        Route::post('/', [FiscalYearController::class, 'store'])->name('store');
        Route::get('{fiscalYear}', [FiscalYearController::class, 'show'])->name('show');
        Route::post('{fiscalYear}/activate', [FiscalYearController::class, 'activate'])->name('activate');
        Route::post('{fiscalYear}/close', [FiscalYearController::class, 'close'])->name('close');
        Route::post('{fiscalYear}/lock', [FiscalYearController::class, 'lock'])->name('lock');
        // Period operations
        Route::post('periods/{period}/close', [FiscalYearController::class, 'closePeriod'])->name('periods.close');
        Route::post('periods/{period}/reopen', [FiscalYearController::class, 'reopenPeriod'])->name('periods.reopen');
    });
    Route::get('admin/fiscal-years', [FiscalYearController::class, 'index'])
        ->name('admin.fiscal-years.index')
        ->middleware('role:accountant,manager,admin');

    // Phase 8: Intercompany & Consolidation
    // RBAC — accountant/manager/admin for consolidation management
    Route::prefix('admin/consolidation')->name('admin.consolidation.')->middleware('role:accountant,manager,admin')->group(function () {
        // Consolidation runs
        Route::get('create', [ConsolidationController::class, 'create'])->name('create');
        Route::post('/', [ConsolidationController::class, 'store'])->name('store');

        // ── Static routes MUST come before the {consolidationRun} wildcard ──
        // Otherwise Laravel matches {consolidationRun}='consolidated-tb' etc.

        // Consolidated financial statements
        Route::get('consolidated-tb', [ConsolidationController::class, 'consolidatedTrialBalance'])->name('consolidated-tb');
        Route::get('consolidated-bs', [ConsolidationController::class, 'consolidatedBalanceSheet'])->name('consolidated-bs');
        Route::get('consolidated-pnl', [ConsolidationController::class, 'consolidatedProfitAndLoss'])->name('consolidated-pnl');

        // Intercompany reconciliation
        Route::get('reconciliation', [ConsolidationController::class, 'intercompanyReconciliation'])->name('reconciliation');

        // Elimination rules
        Route::get('rules', [ConsolidationController::class, 'rulesIndex'])->name('rules');
        Route::post('rules', [ConsolidationController::class, 'rulesStore'])->name('rules.store');
        Route::patch('rules/{rule}/toggle', [ConsolidationController::class, 'rulesToggle'])->name('rules.toggle');

        // Companies
        Route::get('companies', [ConsolidationController::class, 'companiesIndex'])->name('companies');
        Route::post('companies', [ConsolidationController::class, 'companiesStore'])->name('companies.store');

        // ── Parameterized routes (wildcard) — MUST be last ──
        Route::get('{consolidationRun}', [ConsolidationController::class, 'show'])->name('show');
        Route::post('{consolidationRun}/post', [ConsolidationController::class, 'post'])->name('post');
        Route::post('{consolidationRun}/reverse', [ConsolidationController::class, 'reverse'])->name('reverse');
        Route::delete('{consolidationRun}', [ConsolidationController::class, 'destroy'])->name('destroy');
    });
    Route::get('admin/consolidation', [ConsolidationController::class, 'index'])
        ->name('admin.consolidation.index')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 9.3: Bank Reconciliation
    // ============================================================
    Route::prefix('admin/bank-reconciliation')->name('admin.bank-reconciliation.')->middleware('role:accountant,manager,admin')->group(function () {
        // Static routes first (before parameterized)
        Route::get('create', [BankReconciliationController::class, 'create'])->name('create');
        Route::post('/', [BankReconciliationController::class, 'store'])->name('store');
        Route::get('import-statement', [BankReconciliationController::class, 'importStatementPage'])->name('import-statement-page');
        Route::get('unreconciled', [BankReconciliationController::class, 'unreconciled'])->name('unreconciled');

        // Parameterized routes (wildcard) — MUST be last
        Route::get('{bankReconciliation}', [BankReconciliationController::class, 'show'])->name('show');
        Route::post('{bankReconciliation}/import-statement', [BankReconciliationController::class, 'importStatement'])->name('import-statement');
        Route::post('{bankReconciliation}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('auto-match');
        Route::post('{bankReconciliation}/manual-match', [BankReconciliationController::class, 'manualMatch'])->name('manual-match');
        Route::post('{bankReconciliation}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('unmatch');
        Route::patch('{bankReconciliation}/complete', [BankReconciliationController::class, 'complete'])->name('complete');
        Route::patch('{bankReconciliation}/reverse', [BankReconciliationController::class, 'reverse'])->name('reverse');
    });
    Route::get('admin/bank-reconciliation', [BankReconciliationController::class, 'index'])
        ->name('admin.bank-reconciliation.index')
        ->middleware('role:accountant,manager,admin');

    // ============================================================
    // Phase 9.4: Fixed Asset & Depreciation
    // G-114 (HIGH): per-action role differentiation. The group middleware
    // stays `role:accountant,manager,admin` (BR28 basic requirement — the
    // subsystem is accessible to accountant, manager, admin). Disposal +
    // depreciation POST routes get a tighter `role:manager,admin` overlay:
    //   - dispose-form/store-disposal: removal of an asset from the books
    //     is a management decision (has GL impact — gain/loss JE posted).
    //   - generate-depreciation/post-depreciation/post-single-depreciation/
    //     reverse-depreciation: posting depreciation is a period-close action.
    // Accountants retain create/store/edit/update access for asset master
    // data. See `finance/fixed-assets.md` §G27.
    // ============================================================
    Route::prefix('admin/fixed-assets')->name('admin.fixed-assets.')->middleware('role:accountant,manager,admin')->group(function () {
        // Static routes first (before parameterized)
        Route::get('create', [FixedAssetController::class, 'create'])->name('create');
        Route::post('/', [FixedAssetController::class, 'store'])->name('store');
        Route::get('depreciation', [FixedAssetController::class, 'depreciation'])->name('depreciation');
        Route::post('generate-depreciation', [FixedAssetController::class, 'generateDepreciation'])->name('generate-depreciation')
            ->middleware('role:manager,admin');
        Route::post('post-depreciation', [FixedAssetController::class, 'postDepreciation'])->name('post-depreciation')
            ->middleware('role:manager,admin');
        Route::get('disposals', [FixedAssetController::class, 'disposals'])->name('disposals');
        Route::get('disposals/{disposal}', [FixedAssetController::class, 'showDisposal'])->name('show-disposal');
        Route::patch('schedules/{schedule}/post', [FixedAssetController::class, 'postSingleDepreciation'])->name('post-single-depreciation')
            ->middleware('role:manager,admin');
        Route::patch('schedules/{schedule}/reverse', [FixedAssetController::class, 'reverseDepreciation'])->name('reverse-depreciation')
            ->middleware('role:manager,admin');

        // Parameterized routes (wildcard) — MUST be last
        Route::get('{fixedAsset}', [FixedAssetController::class, 'show'])->name('show');
        Route::get('{fixedAsset}/edit', [FixedAssetController::class, 'edit'])->name('edit');
        Route::put('{fixedAsset}', [FixedAssetController::class, 'update'])->name('update');
        Route::get('{fixedAsset}/dispose', [FixedAssetController::class, 'showDisposalForm'])->name('dispose-form')
            ->middleware('role:manager,admin');
        Route::post('{fixedAsset}/dispose', [FixedAssetController::class, 'storeDisposal'])->name('store-disposal')
            ->middleware('role:manager,admin');
    });
    Route::get('admin/fixed-assets', [FixedAssetController::class, 'index'])
        ->name('admin.fixed-assets.index')
        ->middleware('role:accountant,manager,admin');
    // Real-time event streaming from PostgreSQL → Redis → Browser
    // ============================================================
    Route::prefix('sse')->name('sse.')->group(function () {
        Route::get('events', [SseController::class, 'events'])->name('events');
        Route::get('status', [SseController::class, 'status'])->name('status');
    });
});

// ===================== HEALTH CHECK =====================
// The /up route is handled by Laravel's built-in health check (configured in bootstrap/app.php).
