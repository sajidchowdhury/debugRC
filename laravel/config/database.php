<?php

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'rcerp'),
            'username' => env('DB_USERNAME', 'rcerp_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        // MySQL Archive — read-only connection to the legacy MySQL database.
        // Used by the migrate:legacy-employees and migrate:legacy-users Artisan
        // commands. The MySQL archive container is optional (docker compose
        // --profile archive). If the container is not running, the migration
        // commands will catch the connection error gracefully.
        'mysql_archive' => [
            'driver' => 'mysql',
            'host' => env('ARCHIVE_MYSQL_HOST', 'rcerp_mysql_archive'),
            'port' => env('ARCHIVE_MYSQL_PORT', '3306'),
            'database' => env('ARCHIVE_MYSQL_DATABASE', 'rcerp_legacy'),
            'username' => env('ARCHIVE_MYSQL_USERNAME', 'archive_reader'),
            'password' => env('ARCHIVE_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        // Phase 3: Legacy session bridge — Laravel reads legacy PHP sessions from this Redis DB.
        // Legacy php.ini: session.save_path = "tcp://127.0.0.1:6379?database=1"
        'legacy' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('LEGACY_SESSION_REDIS_DB', '1'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '2'),
        ],

    ],

];
