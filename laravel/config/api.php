<?php

/**
 * API configuration — Phase 19 (Task 19-RATELIMIT-PRINT).
 *
 * Holds rate-limit thresholds for the REST API (mobile / AI sidecar) and
 * other tunables that don't belong in the auth/database configs.
 *
 * All values are env-overridable so production can tighten limits without
 * a code deploy.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Per-(token, IP) request caps enforced by ApiRateLimit middleware.
    |
    |   default   = 60 req/min — applied to most /api/v1/* endpoints.
    |   dashboard = 120 req/min — read-only dashboard endpoints (heavier
    |               because mobile clients poll them every few seconds).
    |   lookups   = 120 req/min — read-only dropdown endpoints.
    |
    | Override per-route via the middleware parameter, e.g.
    |   ->middleware('api.rate:120')
    |
    */

    'rate_limit' => [
        'default'   => (int) env('API_RATE_LIMIT', 60),
        'dashboard' => (int) env('API_RATE_LIMIT_DASHBOARD', 120),
        'lookups'   => (int) env('API_RATE_LIMIT_LOOKUPS', 120),
    ],
];
