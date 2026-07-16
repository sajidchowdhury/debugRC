<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // Phase 3: Sanctum for future API (mobile app, AI sidecar in Phase 13).
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => null,
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

    // RC_ERP-specific auth config (mirrors legacy config.php constants).
    'max_failed_attempts' => env('AUTH_MAX_FAILED_ATTEMPTS', 5),
    'lockout_minutes' => env('AUTH_LOCKOUT_MINUTES', 15),
    'reset_token_hours' => env('AUTH_RESET_TOKEN_HOURS', 1),
    'remember_days' => env('AUTH_REMEMBER_DAYS', 30),

];
