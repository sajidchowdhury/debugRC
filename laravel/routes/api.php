<?php

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
 */

Route::prefix('v1')->middleware('api.auth')->group(function (): void {
    // ---------- Branches (REST CRUD) ----------
    Route::get('branches', [BranchApiController::class, 'index']);
    Route::get('branches/{id}', [BranchApiController::class, 'show'])
        ->where('id', '[0-9]+');
    Route::post('branches', [BranchApiController::class, 'store'])
        ->middleware('api.auth:admin');
    Route::put('branches/{id}', [BranchApiController::class, 'update'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:admin');
    Route::delete('branches/{id}', [BranchApiController::class, 'destroy'])
        ->where('id', '[0-9]+')
        ->middleware('api.auth:admin');

    // ---------- Dashboard summary ----------
    Route::get('dashboard', [DashboardApiController::class, 'index']);
    Route::get('dashboard/sales-trend', [DashboardApiController::class, 'salesTrend']);
    Route::get('dashboard/top-products', [DashboardApiController::class, 'topProducts']);

    // ---------- Lookup data (dropdowns) ----------
    Route::get('lookups/branches', [LookupApiController::class, 'branches']);
    Route::get('lookups/warehouses', [LookupApiController::class, 'warehouses']);
    Route::get('lookups/products', [LookupApiController::class, 'products']);
    Route::get('lookups/customers', [LookupApiController::class, 'customers']);
    Route::get('lookups/suppliers', [LookupApiController::class, 'suppliers']);
    Route::get('lookups/ledgers', [LookupApiController::class, 'ledgers']);
});
