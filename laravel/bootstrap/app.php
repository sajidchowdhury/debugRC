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
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 3: Shared session bridge — sync Laravel auth from legacy PHP session.
        // This middleware runs FIRST in the web stack, before Laravel's auth.
        $middleware->prepend(\App\Http\Middleware\SyncLegacySession::class);

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
