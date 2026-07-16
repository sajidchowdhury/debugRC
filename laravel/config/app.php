<?php

return [

    'key' => env('APP_KEY'),
    'cipher' => env('APP_CIPHER', 'AES-256-CBC'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'legacy_url' => env('LEGACY_APP_URL', '/'),

    // Phase 5: GL reconciliation tolerance (amount below which a section is "green").
    'gl_reconciliation_tolerance' => (float) env('GL_RECONCILIATION_TOLERANCE', 0.02),

    'providers' => [
        \Illuminate\Encryption\EncryptionServiceProvider::class,
    ],

];
