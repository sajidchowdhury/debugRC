<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 8.3: `partition_health_alerts` table + daily cron.
 *
 * Goal (roadmap §7.3, lines 526-561): a single table that records every
 * problem the 6 health-check functions discover, plus a daily pg_cron job
 * at 03:00 that runs all 5 alert-generating functions and inserts their
 * UNION ALL output.
 *
 * Schema (roadmap lines 530-542):
 *   partition_health_alerts (
 *     id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 *     check_name   TEXT NOT NULL,           -- 'future_partitions', 'default_partition', etc.
 *     table_name   TEXT,                     -- the table the alert concerns
 *     details      TEXT,                     -- human-readable details
 *     severity     TEXT NOT NULL,            -- 'INFO' | 'WARNING' | 'CRITICAL'
 *     created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 *     resolved_at  TIMESTAMPTZ,              -- NULL until manually resolved
 *     resolved_by  TEXT                      -- the user/system that resolved it
 *   )
 *
 * Plus a partial index on (severity, created_at) WHERE resolved_at IS NULL
 * for the dashboard's "open alerts" view.
 *
 * Daily cron job (03:00):
 *   - Runs the 5 alert-generating health functions from migration 8.2.
 *   - `check_trigger_fks_functional()` (#6) is NOT included — it returns
 *     structural info, not alerts. The dashboard will call it directly.
 *   - Each function's output is mapped to (check_name, table_name,
 *     details, severity).
 *
 * Severity assignments (roadmap lines 548-558):
 *   - future_partitions:    CRITICAL (will cause inserts to fail soon)
 *   - default_partition:    WARNING  (rows landing in default — should be 0)
 *   - partman_stale:        CRITICAL (maintenance isn't running)
 *   - retention_missing:    WARNING  (partitions will accumulate forever)
 *   - brin_unused:          INFO     (BRIN indexes are cheap; not an error)
 *
 * Dependencies:
 *   - migration 8.2 (functions) MUST run before 8.3 (cron job calls them).
 *     Filename ordering (000002 → 000003) guarantees this.
 *   - pg_cron extension (installed by migration 2025_01_20_000009).
 *
 * Idempotency:
 *   - `CREATE TABLE IF NOT EXISTS` + `CREATE INDEX IF NOT EXISTS`.
 *   - `cron.unschedule` before `cron.schedule`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Create the partition_health_alerts table.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS partition_health_alerts (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                check_name  TEXT NOT NULL,
                table_name  TEXT,
                details     TEXT,
                severity    TEXT NOT NULL CHECK (severity IN ('INFO', 'WARNING', 'CRITICAL')),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                resolved_at TIMESTAMPTZ,
                resolved_by TEXT
            );
        SQL);

        // Partial index for the dashboard's "open alerts" query.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_pha_unresolved
                ON partition_health_alerts (severity, created_at)
                WHERE resolved_at IS NULL;
        SQL);

        Log::info('Phase 8.3: partition_health_alerts table created (or already existed).');

        // ============================================================
        // 2. Schedule the daily health-check cron job at 03:00.
        // ============================================================
        // The job runs a single INSERT ... SELECT ... UNION ALL that calls
        // all 5 alert-generating functions. (check_trigger_fks_functional
        // is excluded — it's structural, not alert-generating.)
        //
        // Function signature reference (from migration 8.2):
        //   check_future_partitions()  → (parent_table TEXT, missing_months INT)
        //   check_default_partitions() → (parent_table TEXT, row_count BIGINT)
        //   check_partman_stale()      → (parent_table TEXT, last_maintenance TIMESTAMPTZ)
        //   check_retention_configured() → (parent_table TEXT)
        //   check_brin_index_usage()   → (indexname TEXT, tablename TEXT)
        //
        // The INSERT mapping:
        //   - future_partitions:   table_name=parent_table, details=missing_months||' months missing', severity=CRITICAL
        //   - default_partition:   table_name=parent_table, details=row_count||' rows in default', severity=WARNING
        //   - partman_stale:       table_name=parent_table, details='Last run: '||last_maintenance, severity=CRITICAL
        //   - retention_missing:   table_name=parent_table, details='No retention configured', severity=WARNING
        //   - brin_unused:         table_name=tablename,    details='BRIN index '||indexname||' never scanned', severity=INFO
        // ============================================================
        $pgCronAvailable = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_cron'
            ) AS installed
        ");

        if (! ($pgCronAvailable->installed ?? false)) {
            Log::warning(
                'Phase 8.3: pg_cron extension not available — partition-health-check '
                . 'cron not scheduled. Run the health functions manually each day '
                . 'or via Laravel scheduler as a fallback.'
            );
            return;
        }

        // Unschedule any existing job with the same name (idempotent).
        try {
            DB::statement("SELECT cron.unschedule('partition-health-check')");
        } catch (\Throwable $e) {
            // Job doesn't exist yet — safe to ignore.
        }

        // Schedule: daily at 03:00.
        // Runs AFTER the 02:00 partman maintenance job (so the health
        // checks see fresh state) and BEFORE the 04:00 consolidation job.
        //
        // Note on quoting: the entire INSERT is wrapped in $$...$$ for
        // cron.schedule. The INSERT body itself contains no $$ markers,
        // so there's no quoting conflict.
        DB::statement(<<<'SQL'
            SELECT cron.schedule(
                'partition-health-check',
                '0 3 * * *',
                $cron$
                INSERT INTO partition_health_alerts (check_name, table_name, details, severity, created_at)
                SELECT 'future_partitions',
                       parent_table,
                       missing_months::TEXT || ' months missing',
                       'CRITICAL',
                       NOW()
                  FROM check_future_partitions()
                UNION ALL
                SELECT 'default_partition',
                       parent_table,
                       row_count::TEXT || ' rows in default',
                       'WARNING',
                       NOW()
                  FROM check_default_partitions()
                UNION ALL
                SELECT 'partman_stale',
                       parent_table,
                       'Last run: ' || last_maintenance::TEXT,
                       'CRITICAL',
                       NOW()
                  FROM check_partman_stale()
                UNION ALL
                SELECT 'retention_missing',
                       parent_table,
                       'No retention configured',
                       'WARNING',
                       NOW()
                  FROM check_retention_configured()
                UNION ALL
                SELECT 'brin_unused',
                       tablename,
                       'BRIN index ' || indexname || ' never scanned',
                       'INFO',
                       NOW()
                  FROM check_brin_index_usage()
                $cron$
            )
        SQL);

        Log::info('Phase 8.3: partition-health-check cron scheduled (daily at 03:00).');
    }

    public function down(): void
    {
        // 1. Unschedule the cron job.
        try {
            DB::statement("SELECT cron.unschedule('partition-health-check')");
        } catch (\Throwable $e) {
            // Job doesn't exist — safe to ignore.
        }

        // 2. Drop the table. (Alerts are operational data, not source-of-
        // truth — safe to drop on rollback. The dashboard will simply show
        // no alerts until the cron runs again.)
        DB::statement('DROP TABLE IF EXISTS partition_health_alerts');

        Log::info('Phase 8.3: partition-health-check cron unscheduled + table dropped.');
    }
};
