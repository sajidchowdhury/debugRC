<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 11 (Stock Take plan) — Set app.branch_id + app.is_admin GUC for API
 * requests.
 *
 * WHY THIS EXISTS:
 *   The global SetAppBranchId middleware (bootstrap/app.php line 26) sets the
 *   `app.branch_id` + `app.is_admin` PostgreSQL GUCs that RLS policies consume.
 *   But it runs in the GLOBAL stack — BEFORE route middleware like `api.auth`.
 *   For API requests (Bearer token auth), Auth::check() is false at global-
 *   middleware time, so SetAppBranchId skips and the GUCs stay at the database
 *   default (app.branch_id=0, app.is_admin=false). RLS then blocks ALL rows
 *   for non-admin API users (branch_id=0 never matches a real branch).
 *
 *   This middleware runs AFTER api.auth (it's a route middleware on the
 *   stock-take API group), so Auth::user() is available. It sets the same
 *   GUCs SetAppBranchId would have set, restoring RLS branch filtering for
 *   the stock-take API endpoints (which query RLS-protected tables:
 *   stock_take_sessions, stock_take_warehouses, stock_take_items).
 *
 *   Non-admin users: GUC = their own branch_id → RLS shows only their branch.
 *   Admin/superadmin: GUC is_admin=true → RLS bypass policy shows all branches.
 *
 *   The GUC is per-connection and resets when the connection returns to the
 *   pool, so there's no cross-request leakage.
 *
 * Register in bootstrap/app.php alias list as `set.api.branch`, then apply to
 * the stock-take API route group in routes/api.php AFTER `api.auth`:
 *
 *   Route::prefix('v1/stock-take')
 *       ->middleware(['api.auth', 'set.api.branch'])
 *       ->group(function () { ... });
 *
 * Defense-in-depth: this complements (does not replace) the explicit
 * assertBranchAccessible() checks in the controllers and the EnforceBranchIsolation
 * pattern. RLS is the last line of defense — this middleware ensures it's
 * actually active for API requests.
 */
class SetApiBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only act when a user is authenticated (api.auth has run).
        // If api.auth was bypassed for any reason, skip — the downstream
        // explicit branch checks + the database-default GUC (0) will deny.
        if (Auth::check()) {
            $user = Auth::user();
            $branchId = (int) ($user?->getBranchId() ?? 0);
            $isAdmin = $user?->isAdmin() ? 'true' : 'false';

            try {
                DB::statement("SET app.branch_id = ?", [$branchId > 0 ? $branchId : 0]);
                DB::statement("SET app.is_admin = ?", [$isAdmin]);
            } catch (\Throwable $e) {
                // GUC may not exist yet (migration not run) — silently skip.
                // RLS policies use current_setting(..., true) which returns
                // NULL when the GUC is absent, so the policies degrade to
                // deny-by-default (safe).
                \Illuminate\Support\Facades\Log::debug(
                    'SetApiBranchContext: SET app.branch_id failed (migration may not be run yet): ' . $e->getMessage()
                );
            }
        }

        return $next($request);
    }
}
