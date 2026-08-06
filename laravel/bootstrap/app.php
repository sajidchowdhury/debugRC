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

        // AUDIT-TRAIL-3 (G-172): block all non-GET requests during INVESTIGATION
        // mode. Runs AFTER CheckSystemPolicy so app('system_policy_mode') is
        // available. Allowlist: auth flows + compliance admin (so superadmin
        // can deactivate) + public docs + health check. See
        // BlockWritesDuringInvestigation. Service-layer defense-in-depth hook
        // lives in JournalPostingService::createJournalEntry (G-175).
        $middleware->append(\App\Http\Middleware\BlockWritesDuringInvestigation::class);

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

        // MEDIUM-WAVE-2-C (G-197 / api-conventions.md G8): global ETag +
        // conditional-GET middleware for the API stack. Runs as a "post"
        // middleware (calls $next first, then attaches ETag + honors
        // If-None-Match). Applies to every /api/* route via Laravel 11's
        // $middleware->api([...]) helper. Mobile clients polling read endpoints
        // (dashboard, lookups) can now send If-None-Match to receive a 304 Not
        // Modified when the body is unchanged — saves bandwidth + battery.
        // See api-conventions.md §11.5 for the canonical pattern + client usage.
        $middleware->api([
            \App\Http\Middleware\ETag::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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

        // AUDIT-TRAIL-3 (G-172 + G-175): render the INVESTIGATION-mode write
        // block as a clear 422 — JSON for API/AJAX callers, a
        // redirect-back-with-error for web. Mirrors the
        // WarehouseFrozenForCountException render. Thrown by (1) the
        // BlockWritesDuringInvestigation HTTP middleware (G-172) and (2)
        // SystemPolicyService::assertWriteAllowed() called from
        // JournalPostingService::createJournalEntry() (G-175). The latter
        // can fire in console/queue contexts where no request exists — the
        // exception then propagates as a plain RuntimeException (logged +
        // failing the job/command), which is the correct forensic posture.
        $exceptions->render(function (\App\Exceptions\SystemPolicyWriteBlockedException $e, \Illuminate\Http\Request $request) {
            $payload = [
                'message'   => $e->getMessage(),
                'error'     => 'system_policy_write_blocked',
                'mode'      => $e->getMode(),
                'operation' => $e->getOperation(),
                'context'   => $e->getContext(),
            ];
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($payload, 422);
            }
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        });

        // G-205 (MEDIUM): global catch-all for API routes. Several API
        // controllers wrap their body in `try { ... } catch (\Throwable $e)
        // { return response()->json(['error' => $e->getMessage()], 422); }`,
        // which leaks raw exception messages to clients. Uncaught
        // exceptions on api/* would otherwise fall through to Laravel's
        // default handler — which, under APP_DEBUG=true, renders the full
        // stack trace (a security leak in production). This renderer is the
        // safety net: for any \Throwable reaching the framework on an api/*
        // request (or any JSON-expecting request), return a sanitized JSON
        // 500. In production (APP_DEBUG=false) the raw message is NEVER
        // sent to the client — only a generic "Server Error" + the exception
        // short class name for triage. In debug mode the message is included
        // to aid development. The original exception is still logged by the
        // framework's default logger before this renderer runs. NOTE: the
        // per-exception renderers above (WarehouseFrozenForCountException,
        // SystemPolicyWriteBlockedException) still take precedence for their
        // specific types because Laravel matches renderers in registration
        // order and more specific closures run first.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null; // defer to Laravel's default web handler
            }

            // Validation exceptions already produce a structured 422 via
            // Laravel's own renderer — don't shadow them.
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            $debug    = (bool) config('app.debug');
            $status   = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            $payload  = [
                'message' => $debug ? $e->getMessage() : 'Server Error.',
                'error'   => (new \ReflectionClass($e))->getShortName(),
            ];

            return response()->json($payload, $status >= 400 && $status < 600 ? $status : 500);
        });
    })
    ->create();
