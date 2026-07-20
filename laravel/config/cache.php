<?php

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'rcerp_cache_'),
];
