<?php

/**
 * Archive Configuration — Phase 12.
 *
 * Configuration for the legacy MySQL archive (Anti-Corruption Layer).
 * The legacy database is READ-ONLY — Laravel never writes to it.
 *
 * The Archive Layer connects to the legacy MySQL, translates data into
 * Laravel DTOs, and isolates all legacy-specific logic. Laravel controllers
 * never know legacy table names or column names.
 *
 * Future: the MySQL connection can be replaced by SQL dump, data warehouse,
 * object storage, or reporting database — only this config + the repository
 * implementation change, not the ERP itself.
 */
return [

    /**
     * Legacy MySQL connection settings.
     * Used ONLY for read-only historical queries.
     */
    'connection' => [
        'driver' => 'mysql',
        'host' => env('ARCHIVE_DB_HOST', '127.0.0.1'),
        'port' => env('ARCHIVE_DB_PORT', '3306'),
        'database' => env('ARCHIVE_DB_DATABASE', 'osudlagb_remotecenter'),
        'username' => env('ARCHIVE_DB_USERNAME', 'readonly_user'),
        'password' => env('ARCHIVE_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_general_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
        // READ-ONLY enforcement at PDO level.
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ],

    /**
     * Cache TTL for archive lookups (seconds).
     * Archive data is immutable (read-only) so caching is safe.
     */
    'cache_ttl' => env('ARCHIVE_CACHE_TTL', 3600), // 1 hour

    /**
     * How many months of recent history to migrate from legacy to PostgreSQL.
     * Older data stays in the archive (legacy MySQL).
     */
    'migration_history_months' => env('ARCHIVE_MIGRATION_MONTHS', 24),

    /**
     * Whether the archive (legacy MySQL) is available.
     * Set to false after the legacy database is decommissioned.
     */
    'enabled' => env('ARCHIVE_ENABLED', true),

    /**
     * Legacy table name mappings.
     * Maps legacy MySQL table names to their Laravel equivalents.
     * Only the ArchiveRepository uses these — controllers never see them.
     */
    'tables' => [
        'invoices' => 'sales_invoices',
        'invoice_items' => 'sales_invoice_items',
        'customers' => 'customers',
        'suppliers' => 'suppliers',
        'products' => 'products',
        'journal_entries' => 'journal_entries',
        'journal_lines' => 'journal_lines',
        'customer_ledger' => 'customer_ledger',
        'supplier_ledger' => 'supplier_ledger',
    ],
];
