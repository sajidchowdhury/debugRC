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

    'providers' => [
        \Illuminate\Encryption\EncryptionServiceProvider::class,
    ],

];
