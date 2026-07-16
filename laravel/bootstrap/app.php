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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Phase 3: Shared session bridge — sync Laravel auth from legacy PHP session.
        // This middleware runs FIRST in the web stack, before Laravel's auth.
        $middleware->prepend(\App\Http\Middleware\SyncLegacySession::class);

        // Phase 3: Credential-version check — invalidates session if password/role changed.
        $middleware->append(\App\Http\Middleware\CheckCredentialVersion::class);

        // Phase 3: Trust proxies (VPS behind Nginx reverse proxy).
        $middleware->trustProxies(at: '*');

        // Phase 3: Aliases for role middleware (used in routes: ->middleware('role:admin'))
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'legacy.session' => \App\Http\Middleware\SyncLegacySession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
