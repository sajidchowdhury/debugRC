<?php

/**
 * CORS configuration for RC_ERP.
 *
 * G-209 (MEDIUM): previously `config/cors.php` was absent, so browser SPA
 * consumers fell through to Laravel 11's default CORS profile — which blocks
 * credentialed cross-origin requests (cookies + Authorization header). This
 * file makes the policy explicit and env-driven.
 *
 * The primary API consumers are mobile apps + AI sidecars (Bearer-token
 * auth, no cookies, no CORS constraint). This config additionally permits a
 * future browser SPA on a separate origin to consume `/api/v1/*` with
 * credentialed requests when `CORS_ALLOWED_ORIGINS` is set.
 *
 * NOTE: the web app is served from the same origin as the API (Laravel
 * single-host), so web BLADE/AJAX requests are same-origin and unaffected.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your CORS settings. You may want to read the
    | upstream Laravel cors docs. Defaults mirror `laravel/laravel`'s
    | published stub, tuned for RC_ERP's split web/api paths.
    |
    */

    // Paths that this CORS config applies to. API paths only — the web app
    // is same-origin and does not need CORS headers.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // HTTP methods allowed for cross-origin requests. `*` is expanded to the
    // standard CRUD verbs by Laravel's HandleCors middleware.
    'allowed_methods' => ['*'],

    // Origins allowed to make credentialed cross-origin requests. Driven by
    // env so each deployment can lock this down. Default: a single
    // placeholder that MUST be overridden in production via CORS_ALLOWED_ORIGINS.
    // `*` is intentionally NOT used because credentialed requests (Authorization
    // header) require an explicit origin, never the wildcard.
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),

    // Origin patterns (regex-free here; add if you need wildcard subdomains).
    'allowed_origins_patterns' => [],

    // Headers the browser may send. Authorization covers Bearer tokens;
    // Content-Type / Accept cover JSON payloads + future Accept negotiation.
    'allowed_headers' => ['*'],

    // Headers the browser is allowed to expose to JS. Includes the custom
    // rate-limit + pagination headers the API emits.
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-Total-Count',
    ],

    // Preflight OPTIONS cache duration (seconds). One day is standard.
    'max_age' => 86400,

    // Whether cookies may be sent cross-origin. False here because the API
    // uses Bearer tokens (not session cookies). Flip to true via env if a
    // cookie-based SPA auth flow is added later.
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
