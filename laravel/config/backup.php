<?php

/**
 * Backup Configuration — Session 3 (Q1, Gap 3).
 *
 * Single source of truth for the year-end database backup pipeline.
 * Read via `config('backup.<key>')` — NEVER via env() in service code
 * (env() in non-config code breaks `php artisan config:cache`).
 *
 * The year-end backup is a HARD GATE on `yearEndClose()`: if no
 * fresh, verified backup file exists for the fiscal year being
 * closed, the close ABORTS with a YearEndCloseException. This
 * implements the client's "auto-backup DB file to PC on FY close"
 * requirement — the backup MUST exist before close can proceed.
 *
 * Environment overrides (.env):
 *   BACKUP_PG_DUMP_BINARY  — path to pg_dump binary (default /usr/bin/pg_dump)
 *   BACKUP_PATH            — directory where .dump files are written
 *                            (default storage_path('app/backups') for dev;
 *                            on production this is overridden to a path on
 *                            the client's PC — typically C:\rcerp\backups\
 *                            on Windows or /var/rcerp/backups/ on Linux)
 *   BACKUP_FRESHNESS_HOURS — how many hours old a backup can be and still
 *                            count as "fresh" for the year-end close gate
 *                            (default 24)
 *   BACKUP_RETENTION_COUNT — how many recent backups to keep per fiscal
 *                            year; older ones are marked 'superseded'
 *                            (default 5)
 *
 * @see \App\Services\DatabaseBackupService
 * @see \App\Console\Commands\BackupDatabaseYearEnd
 * @see \App\Services\Accounting\AccountingPeriodService::yearEndClose()
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */

return [

    // ── pg_dump binary ───────────────────────────────────────────────
    // Path to the pg_dump binary. Must match the PostgreSQL server major
    // version (pg_dump 16 works with PG 16 servers; pg_dump 15 may fail
    // on PG 16 features). Verified at command start via `pg_dump --version`.
    'pg_dump_binary' => env('BACKUP_PG_DUMP_BINARY', '/usr/bin/pg_dump'),

    // ── Backup output directory ──────────────────────────────────────
    // Where .dump files are written. The directory is created if it does
    // not exist (with 0700 permissions — backups contain sensitive data).
    //
    // DEV:  storage_path('app/backups')  (inside the Laravel storage dir)
    // PROD: a path on the client's PC — set BACKUP_PATH in .env to e.g.
    //       C:\rcerp\backups\ (Windows) or /var/rcerp/backups/ (Linux).
    //
    // The path is used as-is (no trailing slash normalization needed —
    // the service uses PHP's DIRECTORY_SEPARATOR-aware path join).
    'backup_path' => env('BACKUP_PATH', storage_path('app/backups')),

    // ── Database connection ──────────────────────────────────────────
    // Which DB connection to back up. Defaults to the default connection
    // (set in config/database.php). Override per-environment if backups
    // should target a different connection (e.g., a read replica).
    'connection' => env('BACKUP_CONNECTION', config('database.default')),

    // ── Freshness threshold (year-end close gate) ────────────────────
    // How many hours old a backup can be and still count as "fresh" for
    // the yearEndClose() gate. Default 24 hours — the accountant is
    // expected to run `php artisan db:backup-year-end` on the day of
    // year-end close.
    'freshness_hours' => (int) env('BACKUP_FRESHNESS_HOURS', 24),

    // ── Retention ────────────────────────────────────────────────────
    // How many recent 'verified' backups to keep per fiscal year. When a
    // new backup is created, older 'verified' backups for the same FY
    // are marked 'superseded' (the files are NOT deleted — they remain
    // on disk for manual recovery). Set to 0 to disable retention
    // (keep all backups forever — disk usage will grow).
    'retention_count' => (int) env('BACKUP_RETENTION_COUNT', 5),

    // ── pg_dump options ──────────────────────────────────────────────
    // Format: -Fc = custom compressed format (compatible with pg_restore).
    // These options are appended to the pg_dump command line. The
    // connection params (--host, --port, --username, --dbname) are
    // derived from config/database.php and appended automatically by
    // DatabaseBackupService — do NOT specify them here.
    'pg_dump_options' => [
        '--format=custom',    // -Fc
        '--no-password',      // never prompt (use PGPASSWORD env)
        '--verbose',          // progress to stderr
    ],

    // ── Process timeout ──────────────────────────────────────────────
    // Symfony Process timeout in seconds. pg_dump on a large DB can take
    // several minutes; 600s (10 min) is a safe default. Override per-
    // environment if the DB is very large.
    'process_timeout' => (int) env('BACKUP_PROCESS_TIMEOUT', 600),
];
