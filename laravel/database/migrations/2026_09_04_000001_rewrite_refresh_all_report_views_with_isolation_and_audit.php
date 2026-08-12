<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REPORTS-1 — Rewrites refresh_all_report_views() with per-MV exception
 * isolation + audit-log entries + mv_consolidated_trial_balance inclusion.
 *
 * Resolves 4 CRITICAL gaps from AI_CONTEXT/reports/materialized-views.md:
 *
 *   G3 (G-049): mv_consolidated_trial_balance had NO scheduled refresh —
 *               only the ad-hoc ConsolidationService::refreshMaterializedViews
 *               path. Now included in the function body so the 5-min
 *               scheduler + pg_cron both refresh it.
 *
 *   G6 (G-053): The previous function body was a single BEGIN…END block
 *               with 7 consecutive CONCURRENTLY statements. PostgreSQL
 *               FORBIDS `REFRESH MATERIALIZED VIEW CONCURRENTLY` inside a
 *               transaction block (a PL/pgSQL function body IS a transaction
 *               block → error 55000). Additionally, a single MV failure
 *               aborted the whole function — none of the remaining MVs
 *               were refreshed that cycle.
 *               Fix: per-MV BEGIN…EXCEPTION…END subblocks (one MV failure
 *               no longer blocks others) + plain REFRESH (no CONCURRENTLY —
 *               CONCURRENTLY cannot run inside a function). The Laravel
 *               RefreshReportViews command issues per-MV CONCURRENTLY
 *               statements from PHP (autocommit mode) for the non-blocking
 *               path; this function is the pg_cron fallback.
 *
 *   G7 (G-052 / G-054): The REFRESH operation itself wrote to
 *               financial_audit_log for NONE of the 7 MVs. A manual
 *               REFRESH after directly modifying journal_lines would be
 *               invisible in the audit chain.
 *               Fix: each per-MV subblock INSERTs a row into
 *               financial_audit_log with table_name='mv_X',
 *               operation='REFRESH', record_id=0, before_data=NULL,
 *               after_data={status, elapsed_ms}, performed_by=current
 *               setting's app.user_id, branch_id=current setting's
 *               app.branch_id. The CHECK constraint on
 *               financial_audit_log.operation is altered to allow
 *               'REFRESH' in addition to INSERT/UPDATE/DELETE.
 *
 * This migration is idempotent — DROP FUNCTION IF EXISTS + CREATE OR
 * REPLACE FUNCTION + DROP CONSTRAINT IF EXISTS + ADD CONSTRAINT.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Allow 'REFRESH' as a valid operation in financial_audit_log.
        //    The original CHECK constraint allowed only INSERT/UPDATE/DELETE
        //    (the 3 DML operations fired by fn_financial_audit_trigger).
        //    MV refreshes are not row mutations — they're bulk recompute
        //    operations — but logging them to the same audit chain keeps
        //    all financial audit data in one place.
        // ============================================================
        DB::statement(<<<'SQL'
ALTER TABLE financial_audit_log
    DROP CONSTRAINT IF EXISTS financial_audit_log_operation_check
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE financial_audit_log
    ADD CONSTRAINT financial_audit_log_operation_check
    CHECK (operation IN ('INSERT','UPDATE','DELETE','REFRESH'))
SQL);

        // ============================================================
        // 2. Drop + recreate refresh_all_report_views() with:
        //    - per-MV BEGIN…EXCEPTION…END subblocks (isolation)
        //    - plain REFRESH (no CONCURRENTLY — works inside function)
        //    - mv_consolidated_trial_balance included (was missing)
        //    - financial_audit_log INSERT for each refresh (operation='REFRESH')
        //
        //    NB: CONCURRENTLY cannot run inside a PL/pgSQL function body
        //    (PG error 55000). The Laravel RefreshReportViews command
        //    issues per-MV CONCURRENTLY statements from PHP (autocommit)
        //    for the non-blocking primary path. This function is the
        //    pg_cron fallback (DB-level, survives app crashes) and uses
        //    plain REFRESH which briefly blocks readers but is reliable.
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

    -- mv_consolidated_trial_balance — NEW (was missing — Gap G3/G-049).
    -- Previously only refreshed by ConsolidationService::refreshMaterializedViews
    -- ad-hoc after consolidation runs. Now refreshed every cycle.
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
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void
    {
        // Restore the original function (7 MVs, no isolation, no audit, no mv_consolidated_trial_balance).
        DB::statement(<<<'SQL'
DROP FUNCTION IF EXISTS refresh_all_report_views() CASCADE
SQL);
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION refresh_all_report_views()
RETURNS void AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ledger_balances;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ar_aging;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ap_aging;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_stock_valuation;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_journal_entry_summary;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_intercompany;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_movement_summary;
END;
$$ LANGUAGE plpgsql
SQL);

        // Restore the original CHECK constraint (INSERT/UPDATE/DELETE only).
        DB::statement(<<<'SQL'
ALTER TABLE financial_audit_log
    DROP CONSTRAINT IF EXISTS financial_audit_log_operation_check
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE financial_audit_log
    ADD CONSTRAINT financial_audit_log_operation_check
    CHECK (operation IN ('INSERT','UPDATE','DELETE'))
SQL);
    }
};
