<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Laravel 11 bootstrap — RC_ERP application.
 *
 * Phase 3: Foundation + shared session bridge + simplified auth.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 3: Shared session bridge — sync Laravel auth from legacy PHP session.
        // This middleware runs FIRST in the web stack, before Laravel's auth.
        $middleware->prepend(\App\Http\Middleware\SyncLegacySession::class);

        // Task 19: Set app.branch_id GUC for RLS policies.
        // Must run AFTER SyncLegacySession (which populates session('branch_id')).
        $middleware->append(\App\Http\Middleware\SetAppBranchId::class);

        // Phase 3: Credential-version check — invalidates session if password/role changed.
        $middleware->append(\App\Http\Middleware\CheckCredentialVersion::class);

        // Phase 11: System Policy — loads current policy (cached) and shares with app.
        $middleware->append(\App\Http\Middleware\CheckSystemPolicy::class);

        // Phase 3: Trust proxies (VPS behind Nginx reverse proxy).
        $middleware->trustProxies(at: '*');

        // Phase 3: Aliases for role middleware (used in routes: ->middleware('role:admin'))
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'legacy.session' => \App\Http\Middleware\SyncLegacySession::class,
            // P0-8: Branch isolation — validates request branch_id matches session branch_id
            'branch.isolation' => \App\Http\Middleware\EnforceBranchIsolation::class,
            // Phase 13: API bearer-token auth (mobile/AI sidecar).
            'api.auth' => \App\Http\Middleware\ApiAuth::class,
            // Phase 19: API rate limiting — 60 req/min per token+IP by default.
            'api.rate' => \App\Http\Middleware\ApiRateLimit::class,
            // Phase 11 (Stock Take plan): set app.branch_id GUC for API requests
            // (runs after api.auth so Auth::user() is available; the global
            // SetAppBranchId runs before route middleware and skips API requests
            // because Auth::check() is false at that point).
            'set.api.branch' => \App\Http\Middleware\SetApiBranchContext::class,
            // Menu permission — blocks direct URL access to menus the user can't view.
            'menu.permission' => \App\Http\Middleware\EnsureMenuPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // PostgreSQL 25P02 — failed transaction recovery.
        // When a prior SQL error (e.g. varchar overflow) leaves the DB
        // connection in an aborted transaction state, PostgreSQL rejects ALL
        // subsequent queries on that connection until ROLLBACK. This can
        // happen in middleware, service providers, or controllers — so we
        // catch it globally, purge the poisoned connection, and retry the
        // request once with a fresh connection.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            $msg = $e->getMessage();
            if (str_contains($msg, '25P02') || str_contains($msg, 'failed sql transaction')) {
                try { \Illuminate\Support\Facades\DB::rollBack(); } catch (\Throwable $_) {}
                \Illuminate\Support\Facades\DB::purge();

                // Retry the same request once — the fresh connection will work.
                $retry = app()->handle($request);
                return $retry;
            }
        });

        // Phase 3 (Stock Take plan): render the outbound-freeze block as a
        // clear 422 — JSON for API/AJAX callers, a redirect-back-with-error
        // for web. This is registered globally so EVERY outbound service
        // (sales, transfers, adjustments, damages, purchase returns) gets a
        // consistent, actionable response naming the active session(s) that
        // froze the warehouse, without each controller needing its own catch.
        $exceptions->render(function (\App\Exceptions\WarehouseFrozenForCountException $e, \Illuminate\Http\Request $request) {
            $payload = [
                'message' => $e->getMessage(),
                'error'   => 'warehouse_frozen_for_count',
                'warehouse' => [
                    'id'   => $e->getWarehouseId(),
                    'name' => $e->getWarehouseName(),
                ],
                'sessions' => $e->getSessions(),
            ];
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($payload, 422);
            }
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        });
    })
    ->create();
