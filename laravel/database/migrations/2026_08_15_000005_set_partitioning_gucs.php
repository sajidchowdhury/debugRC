<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 0.7: Set PostgreSQL partitioning GUCs.
 *
 * Audit finding B8: enable_partitionwise_join is not set anywhere (default
 * is 'off'). Phase 6 partition-wise joins (journal_entries + journal_lines)
 * will silently not work without this. Also, max_locks_per_transaction is
 * at the default 64 — partition maintenance operations that touch many
 * partitions may exceed this limit.
 *
 * This migration sets the following GUCs via ALTER SYSTEM:
 *
 *   enable_partitionwise_join    = on   (critical for Phase 6)
 *   enable_partitionwise_aggregate = on (speeds up GROUP BY per partition)
 *   max_locks_per_transaction    = 256  (plan §17.1 — partition maintenance)
 *
 * ALTER SYSTEM requires superuser privileges. If the migration role lacks
 * superuser, the ALTER SYSTEM will fail — the migration catches this and
 * logs a warning instead of failing. The DBA must then set these manually
 * in postgresql.conf and reload.
 *
 * Note: ALTER SYSTEM does not take effect until pg_reload_conf() is called
 * (for reloadable parameters) or until PostgreSQL is restarted (for
 * max_locks_per_transaction, which is a postmaster-level parameter).
 *
 *   - enable_partitionwise_join:     reloadable (SUSET)
 *   - enable_partitionwise_aggregate: reloadable (SUSET)
 *   - max_locks_per_transaction:     postmaster-level — requires RESTART
 *
 * Idempotent — re-running is safe (ALTER SYSTEM overwrites the previous value).
 */
return new class extends Migration
{
    /**
     * Disable Laravel's default per-migration transaction wrapping.
     *
     * ALTER SYSTEM is one of the PostgreSQL statements that CANNOT run
     * inside a transaction block (alongside VACUUM, CREATE INDEX
     * CONCURRENTLY, REINDEX, CLUSTER, CREATE/DROP DATABASE). When Laravel
     * wraps up()/down() in BEGIN...COMMIT, the first ALTER SYSTEM fails
     * with "ALTER SYSTEM cannot run inside a transaction block", which
     * ABORTS the transaction. Every subsequent statement — including the
     * verification SELECT at the end of up() — then fails with the
     * cascading SQLSTATE 25P02 "current transaction is aborted, commands
     * ignored until end of transaction block". The try/catch blocks below
     * are powerless because catching the exception does not un-abort the
     * transaction; only COMMIT/ROLLBACK can, and Laravel only does that
     * at the end of up().
     *
     * Setting $withinTransaction = false makes the migrator run up()/down()
     * without a wrapping transaction, so each DB::statement executes as an
     * autocommit statement. The existing try/catch blocks then correctly
     * isolate per-statement failures (e.g. permission denied for non-
     * superuser roles) without cascading.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $settings = [
            // Critical for Phase 6: enables partition-wise joins between
            // journal_entries and journal_lines (both partitioned by entry_date).
            'enable_partitionwise_join' => 'on',

            // Speeds up GROUP BY queries that can be pushed down to partitions.
            'enable_partitionwise_aggregate' => 'on',

            // Partition maintenance (ATTACH/DETACH many partitions in one txn)
            // can exceed the default 64 lock limit. 256 is the plan's recommendation.
            // NOTE: This is a postmaster-level setting — requires PostgreSQL restart.
            'max_locks_per_transaction' => '256',
        ];

        foreach ($settings as $name => $value) {
            try {
                DB::statement("ALTER SYSTEM SET {$name} = '{$value}'");
                Log::info("Set PostgreSQL GUC: {$name} = {$value}");
            } catch (\Throwable $e) {
                Log::warning("Failed to set PostgreSQL GUC {$name} = {$value}. The DBA must set this manually in postgresql.conf. Error: {$e->getMessage()}");
            }
        }

        // Reload configuration so reloadable parameters take effect immediately.
        // max_locks_per_transaction will NOT take effect until PostgreSQL restarts.
        try {
            DB::statement('SELECT pg_reload_conf()');
            Log::info('PostgreSQL configuration reloaded. Note: max_locks_per_transaction requires a PostgreSQL restart to take effect.');
        } catch (\Throwable $e) {
            Log::warning("Failed to reload PostgreSQL configuration: {$e->getMessage()}. The DBA must run SELECT pg_reload_conf(); manually.");
        }

        // ============================================================
        // Document the current state for verification.
        // ============================================================
        $current = DB::selectOne("
            SELECT
                current_setting('enable_partitionwise_join') AS pwj,
                current_setting('enable_partitionwise_aggregate') AS pwa,
                current_setting('max_locks_per_transaction') AS mlpt
        ");

        Log::info("Current GUC values: enable_partitionwise_join={$current->pwj}, enable_partitionwise_aggregate={$current->pwa}, max_locks_per_transaction={$current->mlpt}");

        // Warn if max_locks_per_transaction hasn't taken effect yet (expected — needs restart).
        if ($current->mlpt !== '256') {
            Log::warning("max_locks_per_transaction is currently {$current->mlpt} (not 256). A PostgreSQL restart is required for this change to take effect.");
        }
    }

    public function down(): void
    {
        // Restore defaults.
        $defaults = [
            'enable_partitionwise_join' => 'off',
            'enable_partitionwise_aggregate' => 'off',
            'max_locks_per_transaction' => '64',
        ];

        foreach ($defaults as $name => $value) {
            try {
                DB::statement("ALTER SYSTEM SET {$name} = '{$value}'");
            } catch (\Throwable $e) {
                Log::warning("Failed to reset PostgreSQL GUC {$name}: {$e->getMessage()}");
            }
        }

        try {
            DB::statement('SELECT pg_reload_conf()');
        } catch (\Throwable $e) {
            // Ignore — DBA can reload manually.
        }
    }
};
