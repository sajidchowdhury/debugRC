<?php

use App\Http\Controllers\Api\ApiDocController;
use App\Http\Controllers\Api\V1\BranchApiController;
use App\Http\Controllers\Api\V1\DashboardApiController;
use App\Http\Controllers\Api\V1\LookupApiController;
use App\Http\Controllers\Api\V1\Sales\SalesCartApiController;
use App\Http\Controllers\Api\V1\Sales\SalesInvoiceApiController;
use App\Http\Controllers\Api\V1\Sales\SalesChallanApiController;
use App\Http\Controllers\Api\V1\Sales\SalesReturnApiController;
use App\Http\Controllers\Api\V1\Sales\CustomerPaymentApiController;
use App\Http\Controllers\Api\V1\Sales\CommissionApiController;
use App\Http\Controllers\Api\V1\StockAdjustment\StockAdjustmentApiController;
use App\Http\Controllers\Api\V1\StockTake\StockTakeItemApiController;
use App\Http\Controllers\Api\V1\StockTake\StockTakeSessionApiController;
use App\Http\Controllers\Api\V1\BranchDemand\BranchDemandApiController;
use App\Http\Controllers\Api\V1\WarehouseTransfer\WarehouseTransferApiController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 13 — API routes for mobile/AI sidecar integration.
 *
 * All routes under /api/v1 require a Bearer token (ApiAuth middleware).
 * The token is issued via User::generateApiToken() and stored SHA-256
 * hashed in users.api_token (see migration 2025_01_17_000001).
 *
 * Routes follow REST conventions:
 *   GET    /api/v1/branches           list (paginated + search)
 *   GET    /api/v1/branches/{id}       show one
 *   POST   /api/v1/branches            create (admin only)
 *   PUT    /api/v1/branches/{id}       update (admin only)
 *   DELETE /api/v1/branches/{id}       deactivate (admin only)
 *
 *   GET    /api/v1/dashboard           summary stats
 *   GET    /api/v1/dashboard/sales-trend   last 7 days sales totals
 *   GET    /api/v1/dashboard/top-products  top 10 products by sales
 *
 *   GET    /api/v1/lookups/branches    active branches (id + name only)
 *   GET    /api/v1/lookups/warehouses  warehouses (?branch_id=X filter)
 *   GET    /api/v1/lookups/products    active products (id + code + name + price)
 *   GET    /api/v1/lookups/customers   active customers (id + code + name)
 *   GET    /api/v1/lookups/suppliers   active suppliers (id + code + name)
 *   GET    /api/v1/lookups/ledgers     active ledgers (id + code + name + type)
 *
 * Phase 11 (Stock Take plan) — stock-take API (mobile count app + 3rd-party):
 *   GET    /api/v1/stock-take/sessions                        list (paginated + filtered)
 *   POST   /api/v1/stock-take/sessions                        create (draft)
 *   GET    /api/v1/stock-take/sessions/{id}                   show detail
 *   POST   /api/v1/stock-take/sessions/{id}/setup/{wh}        set up counts for a warehouse
 *   PUT    /api/v1/stock-take/sessions/{id}/counts/{wh}       save physical counts
 *   POST   /api/v1/stock-take/sessions/{id}/import/{wh}       CSV import (multipart)
 *   POST   /api/v1/stock-take/sessions/{id}/submit            submit for approval
 *   POST   /api/v1/stock-take/sessions/{id}/approve           approve (admin/manager)
 *   POST   /api/v1/stock-take/sessions/{id}/reject            reject → counting (admin/manager)
 *   POST   /api/v1/stock-take/sessions/{id}/post              post — apply variances + GL (admin/manager)
 *   POST   /api/v1/stock-take/sessions/{id}/cancel            cancel (draft/counting only; admin/manager)
 *   POST   /api/v1/stock-take/sessions/{id}/reverse           reverse posted → reversed (admin/manager)
 *   POST   /api/v1/stock-take/sessions/{id}/re-open           re-open reversed → counting (admin/manager)
 *   GET    /api/v1/stock-take/sessions/{id}/items             list items (?warehouse_id, ?variance_only)
 *   GET    /api/v1/stock-take/sessions/{id}/items/{itemId}    show one item
 *   PUT    /api/v1/stock-take/sessions/{id}/items/{itemId}    autosave one count
 *   GET    /api/v1/stock-take/sessions/{id}/variance          variance report + summary
 *
 * Phase 18 — interactive API docs page (publicly accessible, no auth):
 *   GET    /api/docs                   HTML docs + interactive tester
 *
 * Phase 19 — rate limiting:
 *   - Each route carries exactly one api.rate middleware instance.
 *     Mutating REST endpoints (branches CRUD) get the default 60 req/min
 *     cap; read-only dashboard + lookup endpoints get 120 req/min (they're
 *     polled frequently by mobile clients).
 *   - /api/docs is intentionally NOT rate-limited so the docs themselves
 *     always remain reachable.
 *
 * WH Transfer Phase 8 — warehouse-transfer API (mobile stock transfers):
 *   GET    /api/v1/warehouse-transfers                  list (paginated + filtered)
 *   POST   /api/v1/warehouse-transfers                  create draft
 *   GET    /api/v1/warehouse-transfers/{id}              show detail
 *   POST   /api/v1/warehouse-transfers/{id}/confirm      confirm (manager/admin)
 *   POST   /api/v1/warehouse-transfers/{id}/cancel        cancel/reverse (manager/admin)
 *   GET    /api/v1/warehouse-transfers/product-stock      pipeline-aware availability
 *
 * Stock Adjustment Phase 9 — stock-adjustment API (mobile / AI sidecar):
 *   GET    /api/v1/stock-adjustments                     list (paginated + filtered)
 *   POST   /api/v1/stock-adjustments                     create draft
 *   GET    /api/v1/stock-adjustments/{id}                show detail (items + movements + GL + audit)
 *   POST   /api/v1/stock-adjustments/{id}/submit         submit draft for approval
 *   POST   /api/v1/stock-adjustments/{id}/approve        approve (admin/manager)
 *   POST   /api/v1/stock-adjustments/{id}/reject         reject → draft (admin/manager)
 *   POST   /api/v1/stock-adjustments/{id}/confirm        confirm = apply stock + GL
 *   POST   /api/v1/stock-adjustments/{id}/cancel         cancel = reverse stock + GL if confirmed
 */

// Phase 18: Public API docs page (NOT behind api.auth or api.rate).
Route::get('/docs', [ApiDocController::class, 'index'])->name('api.docs');

// Phase 13/19: Authenticated API group + per-route rate limiting.
Route::prefix('v1')->middleware('api.auth')->group(function (): void {
    // ---------- Branches (REST CRUD) — 60 req/min ----------
    Route::get('branches', [BranchApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('branches/{id}', [BranchApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('branches', [BranchApiController::class, 'store'])
        ->middleware('api.auth:admin', 'api.rate:60');
    Route::put('branches/{id}', [BranchApiController::class, 'update'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:admin', 'api.rate:60');
    Route::delete('branches/{id}', [BranchApiController::class, 'destroy'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:admin', 'api.rate:60');

    // ---------- Dashboard summary (read-only, 120 req/min) ----------
    Route::get('dashboard', [DashboardApiController::class, 'index'])
        ->middleware('api.rate:120');
    Route::get('dashboard/sales-trend', [DashboardApiController::class, 'salesTrend'])
        ->middleware('api.rate:120');
    Route::get('dashboard/top-products', [DashboardApiController::class, 'topProducts'])
        ->middleware('api.rate:120');

    // ---------- Lookup data (dropdowns — read-only, 120 req/min) ----------
    Route::get('lookups/branches', [LookupApiController::class, 'branches'])
        ->middleware('api.rate:120');
    Route::get('lookups/warehouses', [LookupApiController::class, 'warehouses'])
        ->middleware('api.rate:120');
    Route::get('lookups/products', [LookupApiController::class, 'products'])
        ->middleware('api.rate:120');
    Route::get('lookups/customers', [LookupApiController::class, 'customers'])
        ->middleware('api.rate:120');
    Route::get('lookups/suppliers', [LookupApiController::class, 'suppliers'])
        ->middleware('api.rate:120');
    Route::get('lookups/ledgers', [LookupApiController::class, 'ledgers'])
        ->middleware('api.rate:120');

    // ======================================================================
    // Phase 8 — Sales Module API (mobile write endpoints)
    // ======================================================================
    // Role requirements: salesman, manager, admin, or superadmin.
    // Write endpoints: 30 req/min (transactional — stricter rate limit).
    // Read endpoints:   60 req/min (list/show — moderate rate limit).
    // ======================================================================

    // ---------- Sales Cart — 30/60 req/min ----------
    // G-086/G-087 (HIGH): the Cart write endpoints (store/update/destroy/
    // clear/soft-hold) previously had ONLY `api.rate:30` — any authenticated
    // API user (any role) could mutate the cart. Added `api.auth:salesman,
    // manager,admin` to mirror the web RBAC (routes/web.php SalesCart group
    // has `role:salesman,manager,admin`). validate + availability are read-
    // like (no state mutation) and keep auth-only via the outer `api.auth`.
    Route::get('sales/cart', [SalesCartApiController::class, 'show'])
        ->middleware('api.rate:60');
    Route::post('sales/cart', [SalesCartApiController::class, 'store'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::put('sales/cart', [SalesCartApiController::class, 'update'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::delete('sales/cart/{productId}', [SalesCartApiController::class, 'destroy'])
        ->where('productId', '[0-9]+')
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/cart/clear', [SalesCartApiController::class, 'clear'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/cart/validate', [SalesCartApiController::class, 'validateCart'])
        ->middleware('api.rate:30');
    Route::post('sales/cart/soft-hold', [SalesCartApiController::class, 'softHold'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::get('sales/cart/availability', [SalesCartApiController::class, 'availability'])
        ->middleware('api.rate:60');

    // ---------- Sales Invoices — 30/60 req/min ----------
    Route::get('sales/invoices', [SalesInvoiceApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('sales/invoices/credit-check', [SalesInvoiceApiController::class, 'creditCheck'])
        ->middleware('api.rate:60');
    Route::post('sales/invoices/call-it-a-day', [SalesInvoiceApiController::class, 'callItADay'])
        ->middleware('api.rate:30');
    Route::get('sales/invoices/{id}', [SalesInvoiceApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    // G-166 (HIGH): invoice store/update/cancel previously had only
    // `api.rate:30` — any authenticated API user (any role) could create,
    // edit, or cancel invoices. Added `api.auth:salesman,manager,admin` to
    // mirror the web RBAC at `routes/web.php:1177,1182` (cancel + update)
    // and the docblock role requirement at api.php:139. Superadmin passes
    // via ApiAuth's superadmin bypass. See `sales/sales-invoice.md` §13 G13.
    Route::post('sales/invoices', [SalesInvoiceApiController::class, 'store'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::put('sales/invoices/{id}', [SalesInvoiceApiController::class, 'update'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/invoices/{id}/cancel', [SalesInvoiceApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');

    // ---------- Sales Challans — 30/60 req/min ----------
    Route::get('sales/challans', [SalesChallanApiController::class, 'index'])
        ->middleware('api.rate:60');
    // BUG-52: godown/issue/cancel API routes were missing role enforcement —
    // any authenticated API user (incl. salesman) could issue a challan.
    // Now restricted to warehouse_manager/dispatcher/manager/admin, mirroring
    // the web route middleware in routes/web.php (lines 715-725).
    Route::post('sales/challans/godown', [SalesChallanApiController::class, 'godown'])
        ->middleware('api.auth:warehouse_manager,dispatcher,manager,admin', 'api.rate:30');
    Route::post('sales/challans/issue', [SalesChallanApiController::class, 'issue'])
        ->middleware('api.auth:warehouse_manager,dispatcher,manager,admin', 'api.rate:30');
    Route::get('sales/challans/{id}', [SalesChallanApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/challans/{id}/cancel', [SalesChallanApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:manager,admin', 'api.rate:30');

    // ---------- Sales Returns — 30/60 req/min ----------
    Route::get('sales/returns', [SalesReturnApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('sales/returns/invoice-details', [SalesReturnApiController::class, 'invoiceDetails'])
        ->middleware('api.rate:60');
    Route::get('sales/returns/{id}', [SalesReturnApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    // G-167 (HIGH): return store/confirm/reverse previously had only
    // `api.rate:30` — any authenticated API user (any role) could create,
    // confirm, or reverse returns. Added `api.auth:<roles>` to mirror the
    // web RBAC at `routes/web.php:1557,1518,1522`:
    //   - store   → salesman,manager,admin          (web L1557)
    //   - confirm → warehouse_manager,accountant,manager,admin (web L1518)
    //   - reverse → accountant,manager,admin         (web L1522)
    // Superadmin passes via ApiAuth's superadmin bypass. See
    // `sales/sales-return.md` §13 G13.
    Route::post('sales/returns', [SalesReturnApiController::class, 'store'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/returns/{id}/confirm', [SalesReturnApiController::class, 'confirm'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:warehouse_manager,accountant,manager,admin', 'api.rate:30');
    Route::post('sales/returns/{id}/reverse', [SalesReturnApiController::class, 'reverse'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:accountant,manager,admin', 'api.rate:30');

    // ---------- Customer Payments — 30/60 req/min ----------
    // G-086/G-087 (HIGH): the Customer Payments write endpoints (store/
    // confirm/cancel) previously had ONLY `api.rate:30` — any authenticated
    // API user (any role) could create/confirm/cancel a payment. Added
    // `api.auth:salesman,manager,admin` to mirror the web RBAC (web.php
    // CustomerPayment group has `role:salesman,manager,admin` for store,
    // `role:accountant,manager,admin` for confirm, `role:accountant,
    // manager,admin` for cancel). The single salesman,manager,admin matrix
    // is a defense-in-depth floor (SalesAccess::assertBranchAccessible +
    // controller-level checks retain the per-action differentiation).
    Route::get('sales/payments', [CustomerPaymentApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('sales/payments/outstanding-invoices', [CustomerPaymentApiController::class, 'outstandingInvoices'])
        ->middleware('api.rate:60');
    Route::get('sales/payments/{id}', [CustomerPaymentApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/payments', [CustomerPaymentApiController::class, 'store'])
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/payments/{id}/confirm', [CustomerPaymentApiController::class, 'confirm'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');
    Route::post('sales/payments/{id}/cancel', [CustomerPaymentApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:salesman,manager,admin', 'api.rate:30');

    // ======================================================================
    // Task 37 — Commission Tracking API
    // ======================================================================
    // Admin/manager endpoints for commission rule management and reporting.
    // Write endpoints: 30 req/min. Read endpoints: 60 req/min.
    // ======================================================================

    // ---------- Commission Rules — 30/60 req/min ----------
    Route::get('sales/commission/rules', [CommissionApiController::class, 'listRules'])
        ->middleware('api.rate:60');
    Route::get('sales/commission/rules/{id}', [CommissionApiController::class, 'showRule'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/commission/rules', [CommissionApiController::class, 'storeRule'])
        ->middleware('api.auth:admin', 'api.rate:30');
    Route::post('sales/commission/rules/{id}/deactivate', [CommissionApiController::class, 'deactivateRule'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:admin', 'api.rate:30');

    // ---------- Commission Entries — 60 req/min (read-only) ----------
    Route::get('sales/commission/entries', [CommissionApiController::class, 'listEntries'])
        ->middleware('api.rate:60');

    // ---------- Commission Summaries — 60 req/min ----------
    Route::get('sales/commission/salesman-summary', [CommissionApiController::class, 'salesmanSummary'])
        ->middleware('api.rate:60');
    Route::get('sales/commission/branch-summary', [CommissionApiController::class, 'branchSummary'])
        ->middleware('api.rate:60');

    // ---------- Commission Confirmation — 30 req/min (admin only) ----------
    Route::post('sales/commission/confirm-period', [CommissionApiController::class, 'confirmPeriod'])
        ->middleware('api.auth:admin', 'api.rate:30');

    // ======================================================================
    // Phase 11 (Stock Take plan) — Stock Take API (mobile count app + 3rd-party)
    // ======================================================================
    // All stock-take routes sit behind api.auth (bearer token) + set.api.branch
    // (sets the app.branch_id GUC so RLS on stock_take_sessions / _warehouses /
    // _items filters by the authenticated user's branch — the global
    // SetAppBranchId middleware runs before route middleware and skips API
    // requests because Auth::check() is false at that point).
    //
    // Rate limits:
    //   - Reads (list/show/items/variance): 60 req/min
    //   - Writes (create/save/post/reverse/re-open/import): 30 req/min
    //   - Setup (loads products for counting): 30 req/min (heavier query)
    //
    // Role enforcement:
    //   - Read + count entry: any authenticated user (counter can be
    //     admin/manager/warehouse_manager).
    //   - Post + reverse + re-open + cancel: admin/manager (destructive —
    //     undoes books or marks terminal). Mirrors the web routes.
    // ======================================================================
    // ======================================================================
    // WH Transfer Phase 8 — Warehouse Transfer API (mobile stock transfers)
    // ======================================================================
    // All warehouse-transfer routes sit behind api.auth + set.api.branch
    // (sets app.branch_id + app.is_admin GUC so RLS on warehouse_transfers
    // filters by the authenticated user's branch).
    //
    // Same-branch enforcement: controller, service, and DB trigger all
    // block cross-branch transfers. Warehouse dropdowns (via lookups API)
    // already filter by user's branch.
    //
    // Rate limits:
    //   - Reads (list/show/product-stock): 60 req/min
    //   - Writes (store/confirm/cancel): 30 req/min (transactional — stricter)
    //
    // Role enforcement:
    //   - Read + store draft: any authenticated user
    //   - Confirm + cancel: manager/admin (destructive — applies/reverses stock)
    // ======================================================================
    Route::prefix('warehouse-transfers')->middleware('set.api.branch')->group(function (): void {

        // ---------- Reads (60 req/min) ----------
        Route::get('/', [WarehouseTransferApiController::class, 'index'])
            ->middleware('api.rate:60');
        Route::get('/product-stock', [WarehouseTransferApiController::class, 'productStock'])
            ->middleware('api.rate:60');
        Route::get('/{id}', [WarehouseTransferApiController::class, 'show'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');

        // ---------- Writes (30 req/min) ----------
        Route::post('/', [WarehouseTransferApiController::class, 'store'])
            ->middleware('api.rate:30');
        Route::post('/{id}/confirm', [WarehouseTransferApiController::class, 'confirm'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:manager,admin', 'api.rate:30');
        Route::post('/{id}/cancel', [WarehouseTransferApiController::class, 'cancel'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:manager,admin', 'api.rate:30');
    });

    // ======================================================================
    // Stock Adjustment Phase 9 — Stock Adjustment API (mobile / AI sidecar)
    // ======================================================================
    // All stock-adjustment routes sit behind api.auth + set.api.branch
    // (sets app.branch_id + app.is_admin GUC so RLS on stock_adjustments
    // filters by the authenticated user's branch — non-admins see only
    // their own branch; admins see all).
    //
    // Reuses the SAME StockAdjustmentService + StockAdjustmentPolicyService
    // as the web controller — every Phase 1-7 protection is in force:
    //   - Phase 1: role gating (route-level + Policy::canSubmit/Approve/Confirm)
    //   - Phase 2: adjustment_category routing (opening_balance → 'opening_balance'
    //     reference_type in the ledger; everything else → 'stock_adjustment')
    //   - Phase 3: maker-checker (approver ≠ submitter; auto-approve below threshold)
    //   - Phase 4: audit-log row written for every lifecycle transition
    //   - Phase 5: UOM conversion (per-item uom_id → qty_base + uom_factor)
    //   - Phase 6.1: pipeline-aware availability on decrease (admin can force)
    //   - Phase 6.2: reversal safety (cancel reverses by exact stock_transaction_id)
    //
    // Rate limits (mirrors Warehouse Transfer):
    //   - Reads (index/show): 60 req/min
    //   - Writes (store/submit/approve/reject/confirm/cancel): 30 req/min
    //
    // Role enforcement:
    //   - Read + store + submit: admin, manager, accountant (route group gate)
    //   - Approve + reject: admin, manager (maker-checker approvers)
    //   - Confirm + cancel: admin, accountant (the poster / reverser)
    //   - Force-confirm: admin only (Policy::canForceConfirm — service re-checks)
    // ======================================================================
    Route::prefix('stock-adjustments')->middleware('set.api.branch')->group(function (): void {

        // ---------- Reads (60 req/min) ----------
        Route::get('/', [StockAdjustmentApiController::class, 'index'])
            ->middleware('api.rate:60');
        Route::get('/{id}', [StockAdjustmentApiController::class, 'show'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');

        // ---------- Writes — draft + submit (30 req/min) ----------
        // Any authenticated admin/manager/accountant can create a draft or
        // submit one for approval. Route-level role gate mirrors the web
        // routes (role:admin,accountant); the controller re-checks via
        // Policy::canSubmit for defense-in-depth.
        Route::post('/', [StockAdjustmentApiController::class, 'store'])
            ->middleware('api.auth:admin,manager,accountant', 'api.rate:30');
        Route::post('/{id}/submit', [StockAdjustmentApiController::class, 'submit'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager,accountant', 'api.rate:30');

        // ---------- Writes — approve / reject (30 req/min, admin/manager) ----------
        // Phase 3 maker-checker: the approver cannot be the submitter
        // (enforced in the controller via Policy::isSubmitter + re-checked
        // by the service).
        Route::post('/{id}/approve', [StockAdjustmentApiController::class, 'approve'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('/{id}/reject', [StockAdjustmentApiController::class, 'reject'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');

        // ---------- Writes — confirm / cancel (30 req/min) ----------
        // Confirm applies stock + posts GL; cancel reverses them. Both are
        // gated to admin/accountant at the route level (mirrors the web
        // role:admin,accountant gate); the controller re-checks via
        // Policy::canConfirm. Force-confirm (Phase 6.1, admin-only) is
        // gated inside the controller via Policy::canForceConfirm +
        // re-checked by the service.
        Route::post('/{id}/confirm', [StockAdjustmentApiController::class, 'confirm'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,accountant', 'api.rate:30');
        Route::post('/{id}/cancel', [StockAdjustmentApiController::class, 'cancel'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,accountant', 'api.rate:30');
    });

    Route::prefix('stock-take')->middleware('set.api.branch')->group(function (): void {

        // ---------- Sessions — read (60 req/min) ----------
        Route::get('sessions', [StockTakeSessionApiController::class, 'index'])
            ->middleware('api.rate:60');
        Route::get('sessions/{id}', [StockTakeSessionApiController::class, 'show'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');

        // ---------- Sessions — write (30 req/min) ----------
        Route::post('sessions', [StockTakeSessionApiController::class, 'store'])
            ->middleware('api.rate:30');

        // Count entry (setup + save + import) — any authenticated counter.
        Route::post('sessions/{id}/setup/{warehouseId}', [StockTakeSessionApiController::class, 'setup'])
            ->where('id', '[0-9]+')->where('warehouseId', '[0-9]+')
            ->middleware('api.rate:30');
        Route::put('sessions/{id}/counts/{warehouseId}', [StockTakeSessionApiController::class, 'saveCounts'])
            ->where('id', '[0-9]+')->where('warehouseId', '[0-9]+')
            ->middleware('api.rate:30');
        Route::post('sessions/{id}/import/{warehouseId}', [StockTakeSessionApiController::class, 'importCounts'])
            ->where('id', '[0-9]+')->where('warehouseId', '[0-9]+')
            ->middleware('api.rate:30');

        // Approval workflow — submit (counter), approve/reject (approver).
        Route::post('sessions/{id}/submit', [StockTakeSessionApiController::class, 'submit'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:30');
        Route::post('sessions/{id}/approve', [StockTakeSessionApiController::class, 'approve'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('sessions/{id}/reject', [StockTakeSessionApiController::class, 'reject'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');

        // Post + cancel + reverse + re-open — admin/manager (destructive/terminal).
        Route::post('sessions/{id}/post', [StockTakeSessionApiController::class, 'post'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('sessions/{id}/cancel', [StockTakeSessionApiController::class, 'cancel'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('sessions/{id}/reverse', [StockTakeSessionApiController::class, 'reverse'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('sessions/{id}/re-open', [StockTakeSessionApiController::class, 'reOpen'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');

        // ---------- Items (per-line reads + single-line autosave) ----------
        // GET list / GET one — 60 req/min. PUT single-line autosave — 30 req/min.
        Route::get('sessions/{id}/items', [StockTakeItemApiController::class, 'index'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');
        Route::get('sessions/{id}/items/{itemId}', [StockTakeItemApiController::class, 'show'])
            ->where('id', '[0-9]+')->where('itemId', '[0-9]+')
            ->middleware('api.rate:60');
        Route::put('sessions/{id}/items/{itemId}', [StockTakeItemApiController::class, 'update'])
            ->where('id', '[0-9]+')->where('itemId', '[0-9]+')
            ->middleware('api.rate:30');

        // ---------- Variance report — 60 req/min ----------
        Route::get('sessions/{id}/variance', [StockTakeItemApiController::class, 'variance'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');
    });

    // ======================================================================
    // Branch Demand Phase 10 — Branch Demand API (mobile / AI sidecar)
    // ======================================================================
    // All branch-demand routes sit behind api.auth + set.api.branch
    // (sets app.branch_id + app.is_admin GUC so RLS on branch_demands
    // filters by the authenticated user's branch — non-admins see only
    // demands involving their own branch; admins see all).
    //
    // Reuses the SAME BranchDemandService + BranchIntercompanyService +
    // BranchDemandRepricingService + BranchDemandAuditService as the web
    // controller — every Phase 1-8 protection is in force:
    //   - Phase 1: cross-branch demand creation
    //   - Phase 2: send with warehouse selection
    //   - Phase 3: GL posting (dual creditor + debtor journals)
    //   - Phase 4: FIFO settlement (bank payments + money transfers)
    //   - Phase 5: receipt confirmation before reversal
    //   - Phase 6: weekly audit report
    //   - Phase 7: price range + repricing
    //   - Phase 8: anti-gaming + audit trail
    //
    // Rate limits (mirrors Warehouse Transfer + Stock Adjustment):
    //   - Reads (list/show/audit/outstanding/ledger/stock): 60 req/min
    //   - Writes (store/send/confirm/reverse/reject/delete/reprice): 30 req/min
    //
    // Role enforcement:
    //   - Read: any authenticated user
    //   - Create + store: admin, manager, warehouse_manager
    //   - Send + confirm-receipt: admin, manager, warehouse_manager
    //   - Reverse + reject: admin, manager (destructive — reverses stock + GL)
    //   - Reprice: admin, manager (posts GL adjustment)
    //   - Delete: admin, manager
    // ======================================================================
    Route::prefix('branch-demands')->middleware('set.api.branch')->group(function (): void {

        // ---------- Reads (60 req/min) ----------
        Route::get('/', [BranchDemandApiController::class, 'index'])
            ->middleware('api.rate:60');
        Route::get('/{id}', [BranchDemandApiController::class, 'show'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');
        Route::get('/outstanding', [BranchDemandApiController::class, 'outstanding'])
            ->middleware('api.rate:60');
        Route::get('/ledger-history', [BranchDemandApiController::class, 'ledgerHistory'])
            ->middleware('api.rate:60');
        Route::get('/settlement-preview', [BranchDemandApiController::class, 'settlementPreview'])
            ->middleware('api.rate:60');
        Route::get('/{id}/audit', [BranchDemandApiController::class, 'audit'])
            ->where('id', '[0-9]+')
            ->middleware('api.rate:60');
        Route::get('/warehouses/{branchId}', [BranchDemandApiController::class, 'warehouses'])
            ->where('branchId', '[0-9]+')
            ->middleware('api.rate:60');
        Route::get('/product-stock/{productId}/{branchId}', [BranchDemandApiController::class, 'productStock'])
            ->where('productId', '[0-9]+')->where('branchId', '[0-9]+')
            ->middleware('api.rate:60');

        // ---------- Writes — create + send + confirm (30 req/min) ----------
        Route::post('/', [BranchDemandApiController::class, 'store'])
            ->middleware('api.auth:admin,manager,warehouse_manager', 'api.rate:30');
        Route::post('/{id}/send', [BranchDemandApiController::class, 'send'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager,warehouse_manager', 'api.rate:30');
        Route::post('/{id}/confirm-receipt', [BranchDemandApiController::class, 'confirmReceipt'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager,warehouse_manager', 'api.rate:30');

        // ---------- Writes — reverse + reject (30 req/min, admin/manager) ----------
        Route::post('/{id}/reverse', [BranchDemandApiController::class, 'reverse'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
        Route::post('/{id}/reject', [BranchDemandApiController::class, 'reject'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager,warehouse_manager', 'api.rate:30');

        // ---------- Writes — delete (30 req/min, admin/manager) ----------
        Route::delete('/{id}', [BranchDemandApiController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');

        // ---------- Writes — reprice (30 req/min, admin/manager) ----------
        Route::post('/{id}/reprice', [BranchDemandApiController::class, 'reprice'])
            ->where('id', '[0-9]+')
            ->middleware('api.auth:admin,manager', 'api.rate:30');
    });
});
