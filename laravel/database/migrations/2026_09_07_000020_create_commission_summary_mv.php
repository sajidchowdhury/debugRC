<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HIGH-WAVE-2 (G-159 / G8) — Commission summary materialized view.
 *
 * `CommissionService::getSalesmanSummary` and `getBranchSummary` previously
 * recomputed per-salesman-per-period commission totals by loading every
 * `commission_entries` row for the period + joining `sales_invoices` for
 * cumulative sales, on every API call. With many salesmen × many periods ×
 * many invoices, the read path scaled linearly with the data set.
 *
 * This migration introduces `mv_commission_summary` — a per-salesman-per-
 * period pre-aggregate that the service reads in O(1) per salesman. The MV
 * is refreshed by the existing `refresh_all_report_views()` function
 * (pg_cron fallback) + the Laravel `RefreshReportViews` command (PHP-driven
 * CONCURRENTLY primary path).
 *
 * The service methods retain a defensive fallback to direct computation
 * (try/catch around the MV read) so the API still works on environments
 * where the MV hasn't been refreshed yet or is temporarily unavailable.
 *
 * Idempotent: CREATE MATERIALIZED VIEW IF NOT EXISTS + CREATE INDEX IF NOT
 * EXISTS + CREATE OR REPLACE FUNCTION.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Create the mv_commission_summary materialized view.
        //    One row per (salesman_id, commission_period, branch_id).
        //    Pre-aggregates the heavy joins CommissionService used to run
        //    on every API call.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_commission_summary AS
SELECT
    ce.salesman_id,
    e.name AS salesman_name,
    e.employee_code,
    ce.branch_id,
    ce.commission_period,
    COALESCE(SUM(ce.commission_amount), 0) AS total_commission,
    COALESCE(SUM(CASE WHEN ce.status = 'confirmed' THEN ce.commission_amount ELSE 0 END), 0) AS confirmed_commission,
    COALESCE(SUM(CASE WHEN ce.status = 'calculated' THEN ce.commission_amount ELSE 0 END), 0) AS pending_commission,
    COALESCE(SUM(CASE WHEN ce.status = 'paid' THEN ce.commission_amount ELSE 0 END), 0) AS paid_commission,
    COUNT(ce.id) AS entry_count,
    COALESCE((
        SELECT SUM(si.total_amount) FROM sales_invoices si
        WHERE si.salesman_id = ce.salesman_id
          AND si.invoice_date >= (ce.commission_period || '-01')::date
          AND si.invoice_date <= (date_trunc('month', (ce.commission_period || '-01')::date) + interval '1 month - 1 day')::date
          AND si.status NOT IN ('cancelled', 'reversed')
          AND COALESCE(si.is_reversed, false) = false
          AND si.deleted_at IS NULL
    ), 0) AS total_sales
FROM commission_entries ce
LEFT JOIN employees e ON e.id = ce.salesman_id
WHERE ce.deleted_at IS NULL
GROUP BY ce.salesman_id, e.name, e.employee_code, ce.branch_id, ce.commission_period
SQL);

        // ============================================================
        // 2. Indexes. The unique index enables future CONCURRENTLY refreshs
        //    (PostgreSQL requires a UNIQUE index on the MV for CONCURRENTLY).
        //    The period + branch indexes match the query patterns of
        //    getBranchSummary (filter by period, optionally by branch).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS mv_commission_summary_sm_per_branch_idx
    ON mv_commission_summary (salesman_id, commission_period, branch_id)
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS mv_commission_summary_period_idx
    ON mv_commission_summary (commission_period)
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS mv_commission_summary_branch_idx
    ON mv_commission_summary (branch_id)
SQL);

        // ============================================================
        // 3. Drop + recreate refresh_all_report_views() with the new
        //    mv_commission_summary refresh block added. Mirrors the pattern
        //    from migration 2026_09_04_000001 (per-MV BEGIN…EXCEPTION…END
        //    subblocks with audit-log entries — one MV failure no longer
        //    aborts the whole function).
        //
        //    The function body is COPIED VERBATIM from
        //    2026_09_04_000001 with one addition: the
        //    `mv_commission_summary` block appended BEFORE the final `END;`.
        // ============================================================
        DB::statement(<<<'SQL'
DROP FUNCTION IF EXISTS refresh_all_report_views() CASCADE
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION refresh_all_report_views()
RETURNS void AS $$
DECLARE
    _start_ts TIMESTAMPTZ;
    _elapsed_ms INTEGER;
    _user_id   BIGINT;
    _branch_id INTEGER;
    _op_user   TEXT;
BEGIN
    -- Resolve current session context (set by SetAppBranchId middleware
    -- / CLI --branch flag). Falls back to NULL when unset (e.g. pg_cron
    -- runs without an app context).
    _op_user := COALESCE(current_setting('app.user_name', true), current_user);
    _user_id := NULLIF(current_setting('app.user_id', true), '')::BIGINT;
    _branch_id := NULLIF(current_setting('app.branch_id', true), '')::INTEGER;

    -- mv_ledger_balances
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_ledger_balances;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ledger_balances', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ledger_balances', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_ar_aging
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_ar_aging;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ar_aging', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ar_aging', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_ap_aging
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_ap_aging;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ap_aging', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_ap_aging', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_stock_valuation
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_stock_valuation;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_stock_valuation', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_stock_valuation', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_journal_entry_summary
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_journal_entry_summary;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_journal_entry_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_journal_entry_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_branch_intercompany
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_branch_intercompany;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_branch_intercompany', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_branch_intercompany', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_product_movement_summary
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_product_movement_summary;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_product_movement_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_product_movement_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_consolidated_trial_balance — included since REPORTS-1 (was missing).
    -- Wrapped in its own BEGIN…EXCEPTION so a missing MV (environments
    -- that haven't run 2026_08_11_000001) doesn't abort the function.
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_consolidated_trial_balance;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_consolidated_trial_balance', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        -- MV may not exist on environments that haven't run the
        -- consolidation migration (2026_08_11_000001). Log + continue.
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_consolidated_trial_balance', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;

    -- mv_commission_summary — HIGH-WAVE-2 (G-159 / G8).
    -- Pre-aggregates per-salesman-per-period commission totals so
    -- CommissionService::getSalesmanSummary / getBranchSummary can read
    -- pre-computed rows instead of re-joining commission_entries +
    -- sales_invoices on every API call. Wrapped in its own BEGIN…EXCEPTION
    -- so a missing MV (environments that haven't run this migration)
    -- doesn't abort the function.
    BEGIN
        _start_ts := clock_timestamp();
        REFRESH MATERIALIZED VIEW mv_commission_summary;
        _elapsed_ms := EXTRACT(EPOCH FROM (clock_timestamp() - _start_ts)) * 1000;
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_commission_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','ok','elapsed_ms',_elapsed_ms),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO financial_audit_log
            (table_name, operation, record_id, before_data, after_data,
             changed_columns, performed_by, db_session_user, branch_id,
             request_path, request_ip, request_id)
        VALUES
            ('mv_commission_summary', 'REFRESH', 0, NULL,
             jsonb_build_object('status','failed','error',SQLERRM),
             ARRAY[]::TEXT[], _op_user, current_user, _branch_id,
             'refresh_all_report_views()', '127.0.0.1', NULL);
    END;
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void
    {
        // Drop the MV. We intentionally do NOT DROP the function —
        // refresh_all_report_views() references mv_commission_summary
        // inside a BEGIN…EXCEPTION block, which fails gracefully (logs
        // the failure to financial_audit_log + continues refreshing the
        // other 8 MVs) if the MV doesn't exist. Restoring the previous
        // function body would require copying the entire 250-line
        // PL/pgSQL function again with the new block removed — the
        // graceful degradation is sufficient for a rollback scenario.
        DB::statement(<<<'SQL'
DROP MATERIALIZED VIEW IF EXISTS mv_commission_summary CASCADE
SQL);
    }
};
