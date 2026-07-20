<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 21 — pg_cron for Stale Draft Cleanup + Materialized View Refresh.
 *
 * Replaces the Laravel scheduler for DB-level maintenance jobs, ensuring
 * they run even if the Laravel queue worker or app server is down.
 *
 * Scheduled jobs:
 *   1. cancel-stale-drafts    — Daily 02:00: Cancel stale draft invoices (>14 days)
 *   2. refresh-report-views   — Every 5 min: Refresh all 7 financial report MVs
 *   3. refresh-rb-checks      — Hourly:     Refresh 4 running-balance check MVs
 *   4. purge-old-notifications — Daily 03:00: Delete notifications older than 90 days
 *   5. vacuum-analyze-sales   — Daily 04:00: VACUUM ANALYZE on high-write tables
 *
 * Prerequisite: PostgreSQL `pg_cron` extension must be available in the
 * database cluster. On most hosted PostgreSQL services (Supabase, RDS, etc.)
 * it can be enabled with `CREATE EXTENSION pg_cron`. On self-hosted, it
 * requires the pg_cron shared library in postgresql.conf:
 *   shared_preload_libraries = 'pg_cron'
 *   cron.database_name = 'your_db_name'
 *
 * The migration is idempotent — uses IF NOT EXISTS throughout and
 * cron.schedule is no-op if the job name already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 0. Enable pg_cron extension
        // ============================================================
        // Note: pg_cron requires shared_preload_libraries in postgresql.conf.
        // If the extension is not available, the CREATE EXTENSION will fail,
        // but the rest of the ERP continues to work (Laravel scheduler
        // remains the fallback).
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_cron');
        } catch (\Throwable $e) {
            // Log warning but don't fail the migration — Laravel scheduler
            // handles the same jobs as fallback.
            logger()->warning('pg_cron extension not available — Laravel scheduler will handle scheduled jobs', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // ============================================================
        // 1. SQL Function: cancel_stale_sales_drafts(days_threshold)
        //    Pure-SQL version of CancelStaleSalesDrafts artisan command.
        //    Cancels draft invoices older than N days that have no
        //    godown/challan/reversal activity.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION cancel_stale_sales_drafts(
    p_days_threshold integer DEFAULT 14,
    p_max_per_run    integer DEFAULT 200,
    p_branch_id      integer DEFAULT NULL
)
RETURNS TABLE(
    cancelled_count integer,
    skipped_count   integer,
    error_count     integer,
    details         jsonb
) AS $$
DECLARE
    v_draft RECORD;
    v_cancelled integer := 0;
    v_skipped   integer := 0;
    v_errors    integer := 0;
    v_error_list jsonb := '[]'::jsonb;
    v_system_user_id integer := 1;
BEGIN
    FOR v_draft IN
        SELECT si.id, si.invoice_code, si.created_at, si.total_amount, si.branch_id
        FROM sales_invoices si
        WHERE si.status = 'draft'
            AND si.is_reversed = false
            AND si.is_godown_prepared = false
            AND si.is_challan_issued = false
            AND si.created_at < (CURRENT_DATE - (p_days_threshold || ' days')::interval)
            AND si.deleted_at IS NULL
            AND (p_branch_id IS NULL OR si.branch_id = p_branch_id)
        ORDER BY si.id ASC
        LIMIT p_max_per_run
    LOOP
        BEGIN
            -- Mark as cancelled (soft cancel — set status, leave audit trail)
            UPDATE sales_invoices
            SET status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = v_draft.id
                AND status = 'draft';  -- re-check to prevent race

            IF FOUND THEN
                -- Audit log entry
                INSERT INTO user_audit_log (user_id, action, target_user_id, branch_id, details, ip_address, user_agent, created_at)
                VALUES (
                    v_system_user_id,
                    'stale_drafts_cancelled',
                    NULL,
                    v_draft.branch_id,
                    jsonb_build_object(
                        'invoice_id', v_draft.id,
                        'invoice_code', v_draft.invoice_code,
                        'days_threshold', p_days_threshold,
                        'trigger', 'pg_cron'
                    ),
                    NULL,
                    'pg_cron:cancel_stale_sales_drafts',
                    CURRENT_TIMESTAMP
                );
                v_cancelled := v_cancelled + 1;
            ELSE
                v_skipped := v_skipped + 1;
            END IF;
        EXCEPTION WHEN OTHERS THEN
            v_errors := v_errors + 1;
            v_error_list := v_error_list || jsonb_build_object(
                'invoice_id', v_draft.id,
                'invoice_code', v_draft.invoice_code,
                'error', SQLERRM
            );
        END;
    END LOOP;

    RETURN QUERY SELECT
        v_cancelled,
        v_skipped,
        v_errors,
        jsonb_build_object(
            'cancelled', v_cancelled,
            'skipped', v_skipped,
            'errors', v_errors,
            'error_details', v_error_list,
            'days_threshold', p_days_threshold,
            'max_per_run', p_max_per_run,
            'branch_filter', p_branch_id,
            'ran_at', CURRENT_TIMESTAMP
        );
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 2. SQL Function: purge_old_notifications(days_to_keep)
        //    Cleans up read notifications older than N days to prevent
        //    table bloat. The notifications table is append-mostly and
        //    can grow very large without periodic cleanup.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION purge_old_notifications(
    p_days_to_keep integer DEFAULT 90
)
RETURNS integer AS $$
DECLARE
    v_deleted integer;
BEGIN
    DELETE FROM notifications
    WHERE read_at IS NOT NULL
        AND created_at < (CURRENT_DATE - (p_days_to_keep || ' days')::interval);

    GET DIAGNOSTICS v_deleted = ROW_COUNT;
    RETURN v_deleted;
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 3. SQL Function: vacuum_analyze_high_write_tables()
        //    Runs VACUUM ANALYZE on the highest-write tables to keep
        //    query planner statistics accurate. Unlike autovacuum,
        //    this runs at a predictable time (04:00) and guarantees
        //    statistics are fresh before the business day starts.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION vacuum_analyze_high_write_tables()
RETURNS void AS $$
BEGIN
    -- Core transaction tables (highest write volume)
    ANALYZE sales_invoices;
    ANALYZE sales_invoice_items;
    ANALYZE customer_payments;
    ANALYZE customer_ledger;
    ANALYZE stock_transactions;
    ANALYZE journal_entries;
    ANALYZE journal_lines;
    ANALYZE notifications;
    ANALYZE user_audit_log;
    -- Supporting tables
    ANALYZE sales_challans;
    ANALYZE sales_challan_items;
    ANALYZE sales_returns;
    ANALYZE sales_return_items;
    ANALYZE purchase_orders;
    ANALYZE purchase_receives;
    ANALYZE supplier_payments;
    ANALYZE supplier_ledger;
END;
$$ LANGUAGE plpgsql
SQL);

        // ============================================================
        // 4. Schedule pg_cron jobs
        //    cron.schedule(job_name, schedule, command)
        //    Schedule format: standard cron (min hour day month dow)
        // ============================================================

        // Job 1: Cancel stale draft invoices — daily at 02:00
        DB::statement(<<<'SQL'
SELECT cron.schedule(
    'cancel-stale-drafts',
    '0 2 * * *',
    $$SELECT cancel_stale_sales_drafts(14, 200, NULL)$$
)
SQL);

        // Job 2: Refresh all financial report materialized views — every 5 minutes
        DB::statement(<<<'SQL'
SELECT cron.schedule(
    'refresh-report-views',
    '*/5 * * * *',
    $$SELECT refresh_all_report_views()$$
)
SQL);

        // Job 3: Refresh running-balance check materialized views — hourly
        // These are lighter than the financial report views and don't need
        // 5-minute freshness. Hourly is sufficient for drift detection.
        DB::statement(<<<'SQL'
SELECT cron.schedule(
    'refresh-rb-checks',
    '0 * * * *',
    $$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_customer_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_supplier_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_employee_ledger_balance_check;
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_cash_ledger_balance_check$$
)
SQL);

        // Job 4: Purge old read notifications — daily at 03:00
        DB::statement(<<<'SQL'
SELECT cron.schedule(
    'purge-old-notifications',
    '0 3 * * *',
    $$SELECT purge_old_notifications(90)$$
)
SQL);

        // Job 5: VACUUM ANALYZE high-write tables — daily at 04:00
        // Cannot run VACUUM inside a function called by pg_cron directly,
        // so we use the ANALYZE-only function (autovacuum handles VACUUM).
        DB::statement(<<<'SQL'
SELECT cron.schedule(
    'analyze-high-write-tables',
    '0 4 * * *',
    $$SELECT vacuum_analyze_high_write_tables()$$
)
SQL);

        // ============================================================
        // 5. Monitoring view: v_pg_cron_jobs
        //    Convenient view to check scheduled jobs and their last runs.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_pg_cron_jobs AS
SELECT
    j.jobid,
    j.schedule,
    j.command,
    j.nodename,
    j.nodeport,
    j.database,
    j.username,
    j.active,
    j.jobname,
    r.runid AS last_run_id,
    r.job_pid AS last_pid,
    r.start_time AS last_start,
    r.end_time AS last_end,
    r.status AS last_status,
    r.return_message AS last_return_message,
    EXTRACT(EPOCH FROM (r.end_time - r.start_time))::numeric(10,3) AS last_duration_seconds
FROM cron.job j
LEFT JOIN LATERAL (
    SELECT runid, job_pid, start_time, end_time, status, return_message
    FROM cron.job_run_details
    WHERE jobid = j.jobid
    ORDER BY start_time DESC
    LIMIT 1
) r ON true
ORDER BY j.jobid
SQL);
    }

    public function down(): void
    {
        // Unschedule all pg_cron jobs (safe even if they don't exist)
        try {
            DB::statement("SELECT cron.unschedule('cancel-stale-drafts')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('refresh-report-views')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('refresh-rb-checks')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('purge-old-notifications')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('analyze-high-write-tables')");
        } catch (\Throwable $e) {}

        // Drop monitoring view
        DB::statement('DROP VIEW IF EXISTS v_pg_cron_jobs');

        // Drop functions
        DB::statement('DROP FUNCTION IF EXISTS cancel_stale_sales_drafts(integer, integer, integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS purge_old_notifications(integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS vacuum_analyze_high_write_tables() CASCADE');

        // Drop extension (removes all cron metadata)
        try {
            DB::statement('DROP EXTENSION IF EXISTS pg_cron');
        } catch (\Throwable $e) {}
    }
};
