<?php

return [

    'name' => env('APP_NAME', 'Remote Center ERP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'key' => env('APP_KEY'),
    'cipher' => env('APP_CIPHER', 'AES-256-CBC'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // Phase 5: GL reconciliation tolerance (amount below which a section is "green").
    'gl_reconciliation_tolerance' => (float) env('GL_RECONCILIATION_TOLERANCE', 0.02),

    'legacy_url' => env('LEGACY_APP_URL', '/'),

];
