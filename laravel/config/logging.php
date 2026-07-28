<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],
        'stderr' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        // Phase 7.3 — Shadow mode comparison log channel.
        // Logs all shadow mode diffs and alerts to a dedicated file.
        'shadow' => [
            'driver' => 'daily',
            'path' => storage_path('logs/shadow_mode.log'),
            'level' => 'debug',
            'days' => 30,
        ],
    ],
];
