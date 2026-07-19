<?php

use App\Http\Controllers\Api\ApiDocController;
use App\Http\Controllers\Api\V1\BranchApiController;
use App\Http\Controllers\Api\V1\DashboardApiController;
use App\Http\Controllers\Api\V1\LookupApiController;
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
});
