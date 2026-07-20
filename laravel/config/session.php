<?php

use Illuminate\Support\Str;

return [

    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => env('SESSION_LIFETIME', 480),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => env('SESSION_ENCRYPT', false),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION', 'legacy'),

    'table' => 'sessions',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env('SESSION_COOKIE', 'PHPSESSID'),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE', false),

    'http_only' => true,

    'same_site' => env('SESSION_SAMESITE', 'lax'),

    // RC_ERP Phase 3: Legacy PHP session bridge config.
    'legacy' => [
        'redis_db' => env('LEGACY_SESSION_REDIS_DB', 1),
        'cookie' => env('LEGACY_SESSION_COOKIE', 'PHPSESSID'),
        // The key prefix phpredis uses when storing sessions in Redis.
        'redis_prefix' => 'PHPREDIS_SESSION:',
    ],

];
