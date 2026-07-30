<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.1 — Materialized Views for Financial Reports.
 *
 * Creates 7 materialized views that pre-compute the heavy financial
 * aggregations. These are refreshed by a scheduled job (every 5 minutes)
 * and on-demand after any journal posting.
 *
 * Materialized views vs. plain views: MVs store the result physically,
 * so reports read pre-computed data instead of re-aggregating on every
 * request. REFRESH MATERIALIZED VIEW CONCURRENTLY allows reads during
 * refresh (requires a UNIQUE index).
 *
 * The reports fall back to direct queries (in ReportService) when the
 * MVs are stale or for real-time data (e.g., today's transactions).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. mv_ledger_balances — per-ledger opening/period/closing
        //    Foundation for Trial Balance, P&L, Balance Sheet.
        //    One row per ledger with running debit/credit sums.
        // ============================================================
        DB::statement(<<<'SQL'
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
         l.is_control_account, l.is_active, l.parent_id
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_ledger_balances_ledger_id_idx ON mv_ledger_balances (ledger_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_ledger_balances_account_type_idx ON mv_ledger_balances (account_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_ledger_balances_nature_idx ON mv_ledger_balances (ledger_nature)');

        // ============================================================
        // 2. mv_ar_aging — customer receivable aging buckets
        //    Computed as of the latest refresh. For as-of-date queries,
        //    the report service falls back to direct query.
        // ============================================================
        DB::statement(<<<'SQL'
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
HAVING SUM(cl.debit - cl.credit) > 0.005
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_ar_aging_customer_branch_idx ON mv_ar_aging (customer_id, branch_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_ar_aging_branch_idx ON mv_ar_aging (branch_id)');

        // ============================================================
        // 3. mv_ap_aging — supplier payable aging buckets
        // ============================================================
        DB::statement(<<<'SQL'
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
HAVING SUM(sl.credit - sl.debit) > 0.005
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_ap_aging_supplier_branch_idx ON mv_ap_aging (supplier_id, branch_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_ap_aging_branch_idx ON mv_ap_aging (branch_id)');

        // ============================================================
        // 4. mv_stock_valuation — per-warehouse product stock with value
        // ============================================================
        DB::statement(<<<'SQL'
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
WHERE ws.qty > 0
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_stock_valuation_wh_prod_idx ON mv_stock_valuation (warehouse_id, product_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_stock_valuation_branch_idx ON mv_stock_valuation (branch_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_stock_valuation_product_idx ON mv_stock_valuation (product_id)');

        // ============================================================
        // 5. mv_journal_entry_summary — per-entry debit/credit totals
        //    For Journal Entries report + reconciliation.
        // ============================================================
        DB::statement(<<<'SQL'
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
         je.branch_id, je.description, je.is_reversed, je.created_by, je.created_at, b.branch_name
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_journal_entry_summary_je_id_idx ON mv_journal_entry_summary (journal_entry_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_date_idx ON mv_journal_entry_summary (entry_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_branch_idx ON mv_journal_entry_summary (branch_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_ref_idx ON mv_journal_entry_summary (reference_type, reference_id)');

        // ============================================================
        // 6. mv_branch_intercompany — Due-from/Due-to balances per branch pair
        //
        // NOTE: This MV references the NEW branch_ledger schema
        // (debit / credit / is_reversed) which is created directly by
        // 02_accounting.sql. Earlier versions of this migration referenced
        // the OLD schema (amount, is_settled) and were later rewritten by
        // migration 2026_07_29_000013 — that double-write is no longer
        // needed because 02_accounting.sql now creates the NEW schema
        // directly. CREATE MATERIALIZED VIEW IF NOT EXISTS makes this
        // statement a no-op if 2026_07_29_000013 already ran first.
        // ============================================================
        DB::statement(<<<'SQL'
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
GROUP BY bl.from_branch_id, bl.to_branch_id, fb.branch_name, tb.branch_name
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_branch_intercompany_from_to_idx ON mv_branch_intercompany (from_branch_id, to_branch_id)');

        // ============================================================
        // 7. mv_product_movement_summary — per-product in/out totals
        //    For Product Stock Analysis + Product Movement reports.
        // ============================================================
        DB::statement(<<<'SQL'
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
         st.warehouse_id, w.warehouse_name, w.branch_id, b.branch_name
SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_pms_prod_wh_idx ON mv_product_movement_summary (product_id, warehouse_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mv_pms_branch_idx ON mv_product_movement_summary (branch_id)');

        // ============================================================
        // Refresh function — refreshes all MVs concurrently.
        // Called by the scheduler (every 5 min) + after journal postings.
        // ============================================================
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
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS refresh_all_report_views() CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_product_movement_summary CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_branch_intercompany CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_journal_entry_summary CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_stock_valuation CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_ap_aging CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_ar_aging CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_ledger_balances CASCADE');
    }
};
