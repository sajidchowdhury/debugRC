-- ============================================================================
-- 10_materialized_views.sql — Financial Report Materialized Views (Phase 5)
-- ============================================================================
-- REPORTS-1 (G-047 / G2): resolves "MVs missing from database/sql baseline".
--
-- Previously, the 7 financial report MVs existed ONLY in migration
-- 2025_01_03_000001_create_report_materialized_views.php. A fresh
-- environment initialized from the SQL baseline (01_*..09_*.sql) would
-- lack all 7 MVs — `php artisan migrate` would create them, but the SQL
-- baseline is supposed to be the canonical schema reference. Any DBA /
-- BI tool / replication reader that reads `database/sql/` to understand
-- the schema would miss 7 MVs + the `refresh_all_report_views()` function.
--
-- This file mirrors the post-2026_09_04_000001 schema (i.e. the schema
-- you get after running migration 2026_09_04_000001 on top of the SQL
-- baseline). The migration remains as the source of truth for runtime
-- changes; this file is purely the DDL reference so external readers
-- can discover the MVs without running migrations.
--
-- NB: mv_consolidated_trial_balance is documented in 08_consolidation.sql
-- (it belongs to the consolidation subsystem). This file documents the
-- 7 financial report MVs only.
--
-- Dependency note:
--   - ledgers, journal_entries, journal_lines (02_accounting.sql) must
--     exist before mv_ledger_balances + mv_journal_entry_summary can
--     aggregate them.
--   - customer_ledger, customers, branches (01 + 02) must exist before
--     mv_ar_aging can be created.
--   - supplier_ledger, suppliers, branches must exist before mv_ap_aging.
--   - warehouse_stock, products, warehouses, branches (03_stock.sql)
--     must exist before mv_stock_valuation + mv_product_movement_summary.
--   - branch_ledger (02_accounting.sql) must exist before
--     mv_branch_intercompany.
--   - financial_audit_log (02_accounting.sql) must exist before
--     refresh_all_report_views() can log refresh events.
-- Loaded after 03_stock.sql by 2025_01_01_000001_create_rcerp_schema.php.
-- ============================================================================

-- ── 1. mv_ledger_balances — per-ledger opening/period/closing ───────────────
-- Foundation for Trial Balance, P&L, Balance Sheet.
-- One row per ledger with running debit/credit sums.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ledger_balances AS
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.is_control_account,
    l.is_active,
    l.parent_id,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net_debit,
    COUNT(jl.id) AS line_count,
    MAX(je.entry_date) AS last_entry_date
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
         l.is_control_account, l.is_active, l.parent_id;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ledger_balances_ledger_id_idx
    ON mv_ledger_balances (ledger_id);
CREATE INDEX IF NOT EXISTS mv_ledger_balances_account_type_idx
    ON mv_ledger_balances (account_type);
CREATE INDEX IF NOT EXISTS mv_ledger_balances_nature_idx
    ON mv_ledger_balances (ledger_nature);

-- ── 2. mv_ar_aging — customer receivable aging buckets ──────────────────────
-- Computed as of the latest refresh. For as-of-date queries, the report
-- service falls back to direct query.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ar_aging AS
SELECT
    c.id AS customer_id,
    c.customer_code,
    c.customer_name,
    c.mobile,
    cl.branch_id,
    b.branch_name,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) <= 30
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) BETWEEN 31 AND 60
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) BETWEEN 61 AND 90
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) > 90
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
    SUM(cl.debit - cl.credit) AS total_receivable
FROM customer_ledger cl
INNER JOIN customers c ON c.id = cl.customer_id
LEFT JOIN branches b ON b.id = cl.branch_id
WHERE COALESCE(cl.is_reversed, false) = false
GROUP BY c.id, c.customer_code, c.customer_name, c.mobile, cl.branch_id, b.branch_name
HAVING SUM(cl.debit - cl.credit) > 0.005;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ar_aging_customer_branch_idx
    ON mv_ar_aging (customer_id, branch_id);
CREATE INDEX IF NOT EXISTS mv_ar_aging_branch_idx ON mv_ar_aging (branch_id);

-- ── 3. mv_ap_aging — supplier payable aging buckets ─────────────────────────
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ap_aging AS
SELECT
    s.id AS supplier_id,
    s.supplier_code,
    s.supplier_name,
    s.mobile,
    sl.branch_id,
    b.branch_name,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) <= 30
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) BETWEEN 31 AND 60
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) BETWEEN 61 AND 90
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) > 90
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_90_plus,
    SUM(sl.credit - sl.debit) AS total_payable
FROM supplier_ledger sl
INNER JOIN suppliers s ON s.id = sl.supplier_id
LEFT JOIN branches b ON b.id = sl.branch_id
WHERE COALESCE(sl.is_reversed, false) = false
GROUP BY s.id, s.supplier_code, s.supplier_name, s.mobile, sl.branch_id, b.branch_name
HAVING SUM(sl.credit - sl.debit) > 0.005;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ap_aging_supplier_branch_idx
    ON mv_ap_aging (supplier_id, branch_id);
CREATE INDEX IF NOT EXISTS mv_ap_aging_branch_idx ON mv_ap_aging (branch_id);

-- ── 4. mv_stock_valuation — per-warehouse product stock with value ──────────
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_stock_valuation AS
SELECT
    ws.warehouse_id,
    ws.product_id,
    p.product_code,
    p.product_name,
    p.unit,
    w.warehouse_name,
    w.branch_id,
    b.branch_name,
    ws.qty AS on_hand_qty,
    ws.avg_cost,
    (ws.qty * ws.avg_cost) AS stock_value
FROM warehouse_stock ws
INNER JOIN products p ON p.id = ws.product_id
INNER JOIN warehouses w ON w.id = ws.warehouse_id
INNER JOIN branches b ON b.id = w.branch_id
WHERE ws.qty > 0;

CREATE UNIQUE INDEX IF NOT EXISTS mv_stock_valuation_wh_prod_idx
    ON mv_stock_valuation (warehouse_id, product_id);
CREATE INDEX IF NOT EXISTS mv_stock_valuation_branch_idx
    ON mv_stock_valuation (branch_id);
CREATE INDEX IF NOT EXISTS mv_stock_valuation_product_idx
    ON mv_stock_valuation (product_id);

-- ── 5. mv_journal_entry_summary — per-entry debit/credit totals ─────────────
-- For Journal Entries report + reconciliation.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_journal_entry_summary AS
SELECT
    je.id AS journal_entry_id,
    je.entry_no,
    je.entry_date,
    je.reference_type,
    je.reference_id,
    je.branch_id,
    je.description,
    je.is_reversed,
    je.created_by,
    je.created_at,
    b.branch_name,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COUNT(jl.id) AS line_count
FROM journal_entries je
LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
LEFT JOIN branches b ON b.id = je.branch_id
GROUP BY je.id, je.entry_no, je.entry_date, je.reference_type, je.reference_id,
         je.branch_id, je.description, je.is_reversed, je.created_by, je.created_at, b.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_journal_entry_summary_je_id_idx
    ON mv_journal_entry_summary (journal_entry_id);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_date_idx
    ON mv_journal_entry_summary (entry_date);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_branch_idx
    ON mv_journal_entry_summary (branch_id);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_ref_idx
    ON mv_journal_entry_summary (reference_type, reference_id);

-- ── 6. mv_branch_intercompany — Due-from/Due-to balances per branch pair ────
-- References branch_ledger (debit / credit / is_reversed) — the NEW schema
-- created by 02_accounting.sql directly. Earlier migrations referenced the
-- OLD schema (amount, is_settled) and were rewritten by 2026_07_29_000013.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_branch_intercompany AS
SELECT
    bl.from_branch_id,
    bl.to_branch_id,
    fb.branch_name AS from_branch_name,
    tb.branch_name AS to_branch_name,
    SUM(bl.debit) AS total_debit,
    SUM(bl.credit) AS total_credit,
    SUM(bl.debit) - SUM(bl.credit) AS net_balance,
    SUM(CASE WHEN NOT bl.is_reversed THEN bl.debit - bl.credit ELSE 0 END) AS outstanding_amount,
    COUNT(*) AS entry_count
FROM branch_ledger bl
INNER JOIN branches fb ON fb.id = bl.from_branch_id
INNER JOIN branches tb ON tb.id = bl.to_branch_id
GROUP BY bl.from_branch_id, bl.to_branch_id, fb.branch_name, tb.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_branch_intercompany_from_to_idx
    ON mv_branch_intercompany (from_branch_id, to_branch_id);

-- ── 7. mv_product_movement_summary — per-product in/out totals ──────────────
-- For Product Stock Analysis + Product Movement reports.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_product_movement_summary AS
SELECT
    st.product_id,
    p.product_code,
    p.product_name,
    p.unit,
    st.warehouse_id,
    w.warehouse_name,
    w.branch_id,
    b.branch_name,
    SUM(CASE WHEN st.qty > 0 THEN st.qty ELSE 0 END) AS total_in_qty,
    SUM(CASE WHEN st.qty < 0 THEN ABS(st.qty) ELSE 0 END) AS total_out_qty,
    SUM(st.qty) AS net_qty,
    SUM(CASE WHEN st.qty > 0 THEN st.total_value ELSE 0 END) AS total_in_value,
    SUM(CASE WHEN st.qty < 0 THEN st.total_value ELSE 0 END) AS total_out_value,
    MIN(st.transaction_date) AS first_movement_date,
    MAX(st.transaction_date) AS last_movement_date,
    COUNT(*) AS movement_count
FROM stock_transactions st
INNER JOIN products p ON p.id = st.product_id
INNER JOIN warehouses w ON w.id = st.warehouse_id
INNER JOIN branches b ON b.id = w.branch_id
GROUP BY st.product_id, p.product_code, p.product_name, p.unit,
         st.warehouse_id, w.warehouse_name, w.branch_id, b.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_pms_prod_wh_idx
    ON mv_product_movement_summary (product_id, warehouse_id);
CREATE INDEX IF NOT EXISTS mv_pms_branch_idx
    ON mv_product_movement_summary (branch_id);

-- ── 8. refresh_all_report_views() — rewritten per REPORTS-1 ─────────────────
-- Per-MV BEGIN…EXCEPTION…END subblocks (one MV failure no longer aborts
-- the whole function). Plain REFRESH (no CONCURRENTLY — CONCURRENTLY
-- cannot run inside a PL/pgSQL function body; the Laravel
-- RefreshReportViews command issues per-MV CONCURRENTLY from PHP for
-- the non-blocking primary path, this function is the pg_cron fallback).
-- Includes mv_consolidated_trial_balance (was missing — Gap G3/G-049).
-- Logs each refresh to financial_audit_log (operation='REFRESH' —
-- Gap G7/G-052/G-054).
--
-- NB: financial_audit_log.operation CHECK constraint must allow 'REFRESH'
-- (altered by migration 2026_09_04_000001).
CREATE OR REPLACE FUNCTION refresh_all_report_views()
RETURNS void AS $$
DECLARE
    _start_ts TIMESTAMPTZ;
    _elapsed_ms INTEGER;
    _user_id   BIGINT;
    _branch_id INTEGER;
    _op_user   TEXT;
BEGIN
    _op_user := COALESCE(current_setting('app.user_name', true), current_user);
    _user_id := NULLIF(current_setting('app.user_id', true), '')::BIGINT;
    _branch_id := NULLIF(current_setting('app.branch_id', true), '')::INTEGER;

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
$$ LANGUAGE plpgsql;

-- ── 9. mv_refresh_log + audit-log trigger (G-234 / REPORTS-AUDIT-FIX-1) ─────
-- Lightweight staleness tracker for the 8 financial MVs + mv_consolidated_trial_balance.
--
-- The original G-234 plan added a `computed_at` column to each MV via
-- `ALTER MATERIALIZED VIEW ... ADD COLUMN` — but PostgreSQL does NOT support
-- adding columns to materialized views in any version (the only way is
-- DROP + CREATE with the new column baked into the SELECT). That approach
-- was attempted in migration 2026_09_06_000003 and blocked `php artisan
-- migrate` in production. This revision uses a separate log table + a
-- trigger on financial_audit_log instead.
--
-- How it works:
--   - refresh_all_report_views() (section 8 above) already INSERTs a row
--     into financial_audit_log after every MV refresh with:
--       operation   = 'REFRESH'
--       table_name  = 'mv_X'
--       after_data  = jsonb_build_object('status','ok'|'failed','elapsed_ms',N)
--   - The AFTER INSERT trigger below mirrors those REFRESH rows into
--     mv_refresh_log (UPSERT keyed on mv_name).
--   - Reports query `SELECT refreshed_at, status FROM mv_refresh_log
--     WHERE mv_name = ?` to determine freshness.
--
-- The trigger function guards on `to_regclass('public.mv_refresh_log') IS NULL`
-- so that if the log table is ever dropped manually (without dropping the
-- trigger first), the trigger becomes a safe no-op rather than erroring on
-- every financial_audit_log insert (which would break inventory mutations
-- that audit through the same table).
CREATE TABLE IF NOT EXISTS mv_refresh_log (
    mv_name      VARCHAR(80) PRIMARY KEY,
    refreshed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    duration_ms  INTEGER,
    status       VARCHAR(10) NOT NULL DEFAULT 'backfill'
);

CREATE OR REPLACE FUNCTION trg_fn_audit_log_to_mv_refresh_log()
RETURNS trigger AS $$
BEGIN
    IF to_regclass('public.mv_refresh_log') IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.operation = 'REFRESH' AND NEW.table_name LIKE 'mv\_%' THEN
        INSERT INTO mv_refresh_log (mv_name, refreshed_at, duration_ms, status)
        VALUES (
            NEW.table_name,
            COALESCE(NEW.created_at, now()),
            (NEW.after_data ->> 'elapsed_ms')::integer,
            COALESCE(NEW.after_data ->> 'status', 'unknown')
        )
        ON CONFLICT (mv_name) DO UPDATE SET
            refreshed_at = EXCLUDED.refreshed_at,
            duration_ms  = EXCLUDED.duration_ms,
            status       = EXCLUDED.status;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_log_mv_refresh ON financial_audit_log;
CREATE TRIGGER trg_audit_log_mv_refresh
    AFTER INSERT ON financial_audit_log
    FOR EACH ROW
    EXECUTE FUNCTION trg_fn_audit_log_to_mv_refresh_log();

-- ============================================================================
-- End of 10_materialized_views.sql
-- ============================================================================
