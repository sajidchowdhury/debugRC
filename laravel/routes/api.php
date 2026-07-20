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
    Route::get('sales/cart', [SalesCartApiController::class, 'show'])
        ->middleware('api.rate:60');
    Route::post('sales/cart', [SalesCartApiController::class, 'store'])
        ->middleware('api.rate:30');
    Route::put('sales/cart', [SalesCartApiController::class, 'update'])
        ->middleware('api.rate:30');
    Route::delete('sales/cart/{productId}', [SalesCartApiController::class, 'destroy'])
        ->where('productId', '[0-9]+')
        ->middleware('api.rate:30');
    Route::post('sales/cart/clear', [SalesCartApiController::class, 'clear'])
        ->middleware('api.rate:30');
    Route::post('sales/cart/validate', [SalesCartApiController::class, 'validateCart'])
        ->middleware('api.rate:30');
    Route::post('sales/cart/soft-hold', [SalesCartApiController::class, 'softHold'])
        ->middleware('api.rate:30');
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
    Route::post('sales/invoices', [SalesInvoiceApiController::class, 'store'])
        ->middleware('api.rate:30');
    Route::put('sales/invoices/{id}', [SalesInvoiceApiController::class, 'update'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');
    Route::post('sales/invoices/{id}/cancel', [SalesInvoiceApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');

    // ---------- Sales Challans — 30/60 req/min ----------
    Route::get('sales/challans', [SalesChallanApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::post('sales/challans/godown', [SalesChallanApiController::class, 'godown'])
        ->middleware('api.rate:30');
    Route::post('sales/challans/issue', [SalesChallanApiController::class, 'issue'])
        ->middleware('api.rate:30');
    Route::get('sales/challans/{id}', [SalesChallanApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/challans/{id}/cancel', [SalesChallanApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');

    // ---------- Sales Returns — 30/60 req/min ----------
    Route::get('sales/returns', [SalesReturnApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('sales/returns/invoice-details', [SalesReturnApiController::class, 'invoiceDetails'])
        ->middleware('api.rate:60');
    Route::get('sales/returns/{id}', [SalesReturnApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/returns', [SalesReturnApiController::class, 'store'])
        ->middleware('api.rate:30');
    Route::post('sales/returns/{id}/confirm', [SalesReturnApiController::class, 'confirm'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');
    Route::post('sales/returns/{id}/reverse', [SalesReturnApiController::class, 'reverse'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');

    // ---------- Customer Payments — 30/60 req/min ----------
    Route::get('sales/payments', [CustomerPaymentApiController::class, 'index'])
        ->middleware('api.rate:60');
    Route::get('sales/payments/outstanding-invoices', [CustomerPaymentApiController::class, 'outstandingInvoices'])
        ->middleware('api.rate:60');
    Route::get('sales/payments/{id}', [CustomerPaymentApiController::class, 'show'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:60');
    Route::post('sales/payments', [CustomerPaymentApiController::class, 'store'])
        ->middleware('api.rate:30');
    Route::post('sales/payments/{id}/confirm', [CustomerPaymentApiController::class, 'confirm'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');
    Route::post('sales/payments/{id}/cancel', [CustomerPaymentApiController::class, 'cancel'])
        ->where('id', '[0-9]+')
        ->middleware('api.rate:30');
});
