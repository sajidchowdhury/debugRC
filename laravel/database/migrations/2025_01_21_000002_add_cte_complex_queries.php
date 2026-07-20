<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 32 — CTE-Based Complex Queries (Today's Summary, AR Aging).
 *
 * Implements PostgreSQL Common Table Expressions (CTEs) for complex
 * multi-table aggregation queries that previously required multiple
 * separate SQL roundtrips or PHP-side computation.
 *
 * Two main CTE functions:
 *
 * 1. rcerp_today_summary(p_branch_id, p_date)
 *    Single-query replacement for DashboardController::getRevenueKPIs()
 *    which previously made 6+ separate SQL queries. Returns:
 *      - Today's invoices, revenue, due
 *      - MTD invoices, revenue, collection, due
 *      - All-time outstanding
 *      - Collection rate, revenue growth vs previous month
 *      - Pending challans, pending godown prep
 *      - Top 5 customers (MTD), top 5 products (MTD)
 *
 * 2. rcerp_ar_aging_cte(p_as_of_date, p_branch_id)
 *    Single-query AR aging with proper sub-ledger bucketing by
 *    customer_ledger.transaction_date (not invoice_date). Replaces
 *    the DashboardController's simplified bucketing and improves
 *    the ReportService::receivableAging() with a CTE-based approach.
 *    Returns per-customer aging buckets + GL reconciliation check.
 *
 * 3. rcerp_general_ledger_cte(p_from_date, p_to_date, p_ledger_id, p_branch_id)
 *    General ledger with SQL-computed running balance (replaces PHP
 *    side running balance loop in ReportService::generalLedger()).
 *    Uses window function SUM() OVER (PARTITION BY ... ORDER BY ...).
 *
 * 4. rcerp_gross_margin_cte(p_from_date, p_to_date, p_branch_id)
 *    Gross margin analysis with per-item COGS breakdown using CTEs.
 *    Joins invoices → challan items → stock transactions for accurate
 *    per-product COGS rather than the simplified single-challan-cost.
 *
 * Why CTEs over traditional queries:
 *   1. Readability — each step named, logic flows top-to-bottom
 *   2. Performance — PostgreSQL optimizes CTEs inline (since PG 12)
 *   3. Single roundtrip — 6+ queries become 1 function call
 *   4. SQL-side computation — running balance in SQL, not PHP loops
 *   5. Reusability — functions callable from any service/controller
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. rcerp_today_summary(p_branch_id, p_date)
        //    All dashboard KPIs in a single query using CTEs.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_today_summary(
    p_branch_id integer DEFAULT NULL,
    p_date      date    DEFAULT CURRENT_DATE
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Active invoices (not cancelled, not reversed)
    active_invoices AS (
        SELECT *
        FROM sales_invoices
        WHERE is_reversed = false
          AND status NOT IN ('cancelled', 'reversed')
          AND deleted_at IS NULL
          AND (p_branch_id IS NULL OR branch_id = p_branch_id)
    ),

    -- CTE 2: Today's sales summary
    today_sales AS (
        SELECT
            COUNT(*)          AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(due_amount), 0)   AS total_due
        FROM active_invoices
        WHERE invoice_date = p_date
    ),

    -- CTE 3: MTD sales summary
    mtd_sales AS (
        SELECT
            COUNT(*)          AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(due_amount), 0)   AS total_due
        FROM active_invoices
        WHERE invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
    ),

    -- CTE 4: MTD collection
    mtd_collection AS (
        SELECT COALESCE(SUM(amount), 0) AS total_collection
        FROM customer_payments
        WHERE payment_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
          AND is_reversed = false
          AND deleted_at IS NULL
          AND (p_branch_id IS NULL OR branch_id = p_branch_id)
    ),

    -- CTE 5: All-time outstanding (non-draft active invoices with due > 0)
    all_time_outstanding AS (
        SELECT COALESCE(SUM(due_amount), 0) AS total_outstanding
        FROM active_invoices
        WHERE status NOT IN ('draft')
          AND due_amount > 0
    ),

    -- CTE 6: Previous month revenue (for growth calc)
    prev_month_sales AS (
        SELECT COALESCE(SUM(total_amount), 0) AS total_sales
        FROM active_invoices
        WHERE invoice_date BETWEEN
            DATE_TRUNC('month', p_date - INTERVAL '1 month')::date AND
            (DATE_TRUNC('month', p_date) - INTERVAL '1 day')::date
    ),

    -- CTE 7: Pending operations
    pending_ops AS (
        SELECT
            (SELECT COUNT(*) FROM active_invoices WHERE is_godown_prepared = false AND status = 'confirmed') AS pending_godown,
            (SELECT COUNT(*) FROM active_invoices WHERE is_godown_prepared = true AND is_challan_issued = false AND status = 'confirmed') AS pending_challan,
            (SELECT COUNT(*) FROM active_invoices WHERE status = 'draft') AS draft_count
    ),

    -- CTE 8: Top 5 customers by MTD revenue
    top_customers AS (
        SELECT
            c.id AS customer_id,
            c.customer_name,
            COUNT(*) AS invoice_count,
            COALESCE(SUM(ai.total_amount), 0) AS total_revenue,
            COALESCE(SUM(ai.due_amount), 0) AS total_due
        FROM active_invoices ai
        INNER JOIN customers c ON c.id = ai.customer_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY c.id, c.customer_name
        ORDER BY total_revenue DESC
        LIMIT 5
    ),

    -- CTE 9: Top 5 products by MTD qty sold
    top_products AS (
        SELECT
            p.id AS product_id,
            p.product_code,
            p.product_name,
            SUM(sii.qty) AS qty_sold,
            SUM(sii.qty * sii.rate) AS revenue
        FROM sales_invoice_items sii
        INNER JOIN active_invoices ai ON ai.id = sii.sales_invoice_id
        INNER JOIN products p ON p.id = sii.product_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY p.id, p.product_code, p.product_name
        ORDER BY qty_sold DESC
        LIMIT 5
    ),

    -- CTE 10: AR aging buckets (proper sub-ledger based)
    ar_aging AS (
        SELECT
            SUM(CASE WHEN (p_date - cl.transaction_date) <= 30 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN (p_date - cl.transaction_date) BETWEEN 31 AND 60 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN (p_date - cl.transaction_date) BETWEEN 61 AND 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN (p_date - cl.transaction_date) > 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus
        FROM customer_ledger cl
        WHERE cl.transaction_date <= p_date
          AND COALESCE(cl.is_reversed, false) = false
          AND (p_branch_id IS NULL OR cl.branch_id = p_branch_id)
    ),

    -- CTE 11: Branch revenue comparison (MTD)
    branch_revenue AS (
        SELECT
            b.id AS branch_id,
            b.branch_name,
            COUNT(*) AS invoice_count,
            COALESCE(SUM(ai.total_amount), 0) AS revenue
        FROM active_invoices ai
        INNER JOIN branches b ON b.id = ai.branch_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY b.id, b.branch_name
        ORDER BY revenue DESC
    )

    -- Final aggregation: assemble all CTEs into a single JSON result
    SELECT jsonb_build_object(
        'date', p_date,
        'branch_id', p_branch_id,
        'today', jsonb_build_object(
            'invoice_count', (SELECT invoice_count FROM today_sales),
            'total_sales', (SELECT total_sales FROM today_sales),
            'total_due', (SELECT total_due FROM today_sales)
        ),
        'mtd', jsonb_build_object(
            'invoice_count', (SELECT invoice_count FROM mtd_sales),
            'total_sales', (SELECT total_sales FROM mtd_sales),
            'total_due', (SELECT total_due FROM mtd_sales),
            'total_collection', (SELECT total_collection FROM mtd_collection),
            'collection_rate', CASE
                WHEN (SELECT total_sales FROM mtd_sales) > 0
                THEN ROUND(((SELECT total_collection FROM mtd_collection) / (SELECT total_sales FROM mtd_sales) * 100)::numeric, 1)
                ELSE 0
            END
        ),
        'outstanding', jsonb_build_object(
            'total_outstanding', (SELECT total_outstanding FROM all_time_outstanding)
        ),
        'growth', jsonb_build_object(
            'prev_month_sales', (SELECT total_sales FROM prev_month_sales),
            'revenue_growth_pct', CASE
                WHEN (SELECT total_sales FROM prev_month_sales) > 0
                THEN ROUND((((SELECT total_sales FROM mtd_sales) - (SELECT total_sales FROM prev_month_sales)) / (SELECT total_sales FROM prev_month_sales) * 100)::numeric, 1)
                ELSE 0
            END
        ),
        'pending', (SELECT jsonb_build_object(
            'pending_godown', pending_godown,
            'pending_challan', pending_challan,
            'draft_count', draft_count
        ) FROM pending_ops),
        'top_customers', COALESCE((SELECT jsonb_agg(row_to_json(tc)::jsonb) FROM top_customers tc), '[]'::jsonb),
        'top_products', COALESCE((SELECT jsonb_agg(row_to_json(tp)::jsonb) FROM top_products tp), '[]'::jsonb),
        'ar_aging', (SELECT jsonb_build_object(
            'bucket_0_30', bucket_0_30,
            'bucket_31_60', bucket_31_60,
            'bucket_61_90', bucket_61_90,
            'bucket_90_plus', bucket_90_plus
        ) FROM ar_aging),
        'branch_revenue', COALESCE((SELECT jsonb_agg(row_to_json(br)::jsonb) FROM branch_revenue br), '[]'::jsonb)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        // ============================================================
        // 2. rcerp_ar_aging_cte(p_as_of_date, p_branch_id)
        //    Proper sub-ledger based AR aging with GL reconciliation.
        //    Single CTE query replaces 2 queries (aging + GL check).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_ar_aging_cte(
    p_as_of_date date,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Customer sub-ledger balances with aging buckets
    customer_balances AS (
        SELECT
            c.id AS customer_id,
            c.customer_code,
            c.customer_name,
            c.mobile,
            cl.branch_id,
            COALESCE(b.branch_name, '—') AS branch_name,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) <= 30
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) BETWEEN 31 AND 60
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) BETWEEN 61 AND 90
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) > 90
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
            SUM(cl.debit - cl.credit) AS total_receivable
        FROM customer_ledger cl
        INNER JOIN customers c ON c.id = cl.customer_id
        LEFT JOIN branches b ON b.id = cl.branch_id
        WHERE cl.transaction_date <= p_as_of_date
          AND COALESCE(cl.is_reversed, false) = false
          AND (p_branch_id IS NULL OR cl.branch_id = p_branch_id)
        GROUP BY c.id, c.customer_code, c.customer_name, c.mobile, cl.branch_id, b.branch_name
        HAVING SUM(cl.debit - cl.credit) > 0.005
    ),

    -- CTE 2: GL AR control account balance
    gl_ar_control AS (
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS gl_balance
        FROM ledgers l
        JOIN journal_lines jl ON jl.ledger_id = l.id
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE l.ledger_nature = 'ar'
          AND COALESCE(je.is_reversed, false) = false
          AND je.entry_date <= p_as_of_date
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
    ),

    -- CTE 3: Per-bucket invoice detail (top overdue invoices)
    overdue_invoices AS (
        SELECT
            si.id,
            si.invoice_code,
            si.invoice_date,
            (p_as_of_date - si.invoice_date) AS days_overdue,
            si.due_amount,
            c.customer_name,
            b.branch_name
        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        LEFT JOIN branches b ON b.id = si.branch_id
        WHERE si.is_reversed = false
          AND si.status NOT IN ('draft', 'cancelled', 'reversed')
          AND si.deleted_at IS NULL
          AND si.due_amount > 0
          AND si.invoice_date < p_as_of_date - INTERVAL '30 days'
          AND (p_branch_id IS NULL OR si.branch_id = p_branch_id)
        ORDER BY si.due_amount DESC
        LIMIT 20
    ),

    -- CTE 4: Aging summary totals
    aging_totals AS (
        SELECT
            SUM(bucket_0_30)   AS total_bucket_0_30,
            SUM(bucket_31_60)  AS total_bucket_31_60,
            SUM(bucket_61_90)  AS total_bucket_61_90,
            SUM(bucket_90_plus) AS total_bucket_90_plus,
            SUM(total_receivable) AS grand_total
        FROM customer_balances
    ),

    -- CTE 5: Aging by branch (for multi-branch analysis)
    aging_by_branch AS (
        SELECT
            cb.branch_id,
            cb.branch_name,
            SUM(cb.bucket_0_30)   AS bucket_0_30,
            SUM(cb.bucket_31_60)  AS bucket_31_60,
            SUM(cb.bucket_61_90)  AS bucket_61_90,
            SUM(cb.bucket_90_plus) AS bucket_90_plus,
            SUM(cb.total_receivable) AS total_receivable
        FROM customer_balances cb
        GROUP BY cb.branch_id, cb.branch_name
        ORDER BY total_receivable DESC
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'Receivable Aging (CTE)',
            'as_of_date', p_as_of_date,
            'branch_id', p_branch_id,
            'source', 'cte_query'
        ),
        'customers', COALESCE((SELECT jsonb_agg(jsonb_build_object(
            'customer_id', customer_id,
            'customer_code', customer_code,
            'customer_name', customer_name,
            'mobile', mobile,
            'branch_id', branch_id,
            'branch_name', branch_name,
            'bucket_0_30', bucket_0_30,
            'bucket_31_60', bucket_31_60,
            'bucket_61_90', bucket_61_90,
            'bucket_90_plus', bucket_90_plus,
            'total_receivable', total_receivable
        ) ORDER BY total_receivable DESC) FROM customer_balances), '[]'::jsonb),
        'totals', jsonb_build_object(
            'bucket_0_30', (SELECT total_bucket_0_30 FROM aging_totals),
            'bucket_31_60', (SELECT total_bucket_31_60 FROM aging_totals),
            'bucket_61_90', (SELECT total_bucket_61_90 FROM aging_totals),
            'bucket_90_plus', (SELECT total_bucket_90_plus FROM aging_totals),
            'total_receivable', (SELECT grand_total FROM aging_totals),
            'gl_ar_control', (SELECT gl_balance FROM gl_ar_control)
        ),
        'checks', jsonb_build_object(
            'matches_gl', (SELECT ABS(grand_total - gl_balance) < 0.01 FROM aging_totals, gl_ar_control)
        ),
        'overdue_invoices', COALESCE((SELECT jsonb_agg(row_to_json(oi)::jsonb ORDER BY due_amount DESC) FROM overdue_invoices oi), '[]'::jsonb),
        'aging_by_branch', COALESCE((SELECT jsonb_agg(row_to_json(ab)::jsonb ORDER BY total_receivable DESC) FROM aging_by_branch ab), '[]'::jsonb)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        // ============================================================
        // 3. rcerp_general_ledger_cte(p_from_date, p_to_date, p_ledger_id, p_branch_id)
        //    General ledger with SQL window-function running balance.
        //    Replaces PHP-side running balance computation.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_general_ledger_cte(
    p_from_date  date,
    p_to_date    date,
    p_ledger_id  integer DEFAULT NULL,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Opening balances per ledger (before the from_date)
    opening AS (
        SELECT
            jl.ledger_id,
            COALESCE(SUM(jl.debit - jl.credit), 0) AS opening_balance
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.entry_date < p_from_date
          AND COALESCE(je.is_reversed, false) = false
          AND (p_ledger_id IS NULL OR jl.ledger_id = p_ledger_id)
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
        GROUP BY jl.ledger_id
    ),

    -- CTE 2: Period activity with running balance (window function)
    period_activity AS (
        SELECT
            je.id AS journal_entry_id,
            je.entry_no,
            je.entry_date,
            je.reference_type,
            je.reference_id,
            je.description,
            je.branch_id,
            COALESCE(b.branch_name, '—') AS branch_name,
            je.is_reversed,
            jl.id AS journal_line_id,
            jl.ledger_id,
            l.ledger_code,
            l.ledger_name,
            l.account_type,
            jl.debit,
            jl.credit,
            jl.entity_type,
            jl.entity_id,
            jl.memo,
            -- Running balance: opening + cumulative sum of (debit - credit) partitioned by ledger
            COALESCE(o.opening_balance, 0) +
                SUM(jl.debit - jl.credit) OVER (
                    PARTITION BY jl.ledger_id
                    ORDER BY je.entry_date, je.entry_no, jl.id
                    ROWS UNBOUNDED PRECEDING
                ) AS running_balance
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        JOIN ledgers l ON l.id = jl.ledger_id
        LEFT JOIN branches b ON b.id = je.branch_id
        LEFT JOIN opening o ON o.ledger_id = jl.ledger_id
        WHERE je.entry_date BETWEEN p_from_date AND p_to_date
          AND COALESCE(je.is_reversed, false) = false
          AND (p_ledger_id IS NULL OR jl.ledger_id = p_ledger_id)
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
        ORDER BY l.ledger_code, je.entry_date, je.entry_no, jl.id
    ),

    -- CTE 3: Closing balances per ledger
    closing AS (
        SELECT
            pa.ledger_id,
            MAX(pa.running_balance) AS closing_balance,
            -- The last row's running_balance IS the closing balance
            SUM(pa.debit) AS period_debit,
            SUM(pa.credit) AS period_credit
        FROM period_activity pa
        GROUP BY pa.ledger_id
    ),

    -- CTE 4: Ledger summary for header section
    ledger_summary AS (
        SELECT
            l.id AS ledger_id,
            l.ledger_code,
            l.ledger_name,
            l.account_type,
            COALESCE(o.opening_balance, 0) AS opening_balance,
            COALESCE(c.period_debit, 0) AS period_debit,
            COALESCE(c.period_credit, 0) AS period_credit,
            COALESCE(c.closing_balance, COALESCE(o.opening_balance, 0)) AS closing_balance
        FROM ledgers l
        LEFT JOIN opening o ON o.ledger_id = l.id
        LEFT JOIN closing c ON c.ledger_id = l.id
        WHERE l.is_active = true
          AND (p_ledger_id IS NULL OR l.id = p_ledger_id)
          AND (
              -- Only include ledgers that have activity or opening balance
              o.opening_balance IS NOT NULL
              OR c.period_debit IS NOT NULL
              OR c.period_credit IS NOT NULL
          )
        ORDER BY l.ledger_code
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'General Ledger (CTE)',
            'from_date', p_from_date,
            'to_date', p_to_date,
            'ledger_id', p_ledger_id,
            'branch_id', p_branch_id,
            'source', 'cte_window_function'
        ),
        'entries', COALESCE((SELECT jsonb_agg(jsonb_build_object(
            'journal_entry_id', journal_entry_id,
            'entry_no', entry_no,
            'entry_date', entry_date,
            'reference_type', reference_type,
            'reference_id', reference_id,
            'description', description,
            'branch_id', branch_id,
            'branch_name', branch_name,
            'ledger_id', ledger_id,
            'ledger_code', ledger_code,
            'ledger_name', ledger_name,
            'debit', debit,
            'credit', credit,
            'running_balance', running_balance,
            'memo', memo
        )) FROM period_activity), '[]'::jsonb),
        'ledger_summary', COALESCE((SELECT jsonb_agg(row_to_json(ls)::jsonb) FROM ledger_summary ls), '[]'::jsonb),
        'totals', jsonb_build_object(
            'total_debit', (SELECT COALESCE(SUM(debit), 0) FROM period_activity),
            'total_credit', (SELECT COALESCE(SUM(credit), 0) FROM period_activity),
            'total_opening', (SELECT COALESCE(SUM(opening_balance), 0) FROM ledger_summary),
            'total_closing', (SELECT COALESCE(SUM(closing_balance), 0) FROM ledger_summary)
        ),
        'checks', jsonb_build_object(
            'balanced', (SELECT ABS(SUM(debit) - SUM(credit)) < 0.01 FROM period_activity)
        )
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        // ============================================================
        // 4. rcerp_gross_margin_cte(p_from_date, p_to_date, p_branch_id)
        //    Gross margin analysis with per-item COGS via CTE.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_gross_margin_cte(
    p_from_date  date,
    p_to_date    date,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Active invoices in the period
    active_invoices AS (
        SELECT
            si.id, si.invoice_code, si.invoice_date,
            si.customer_id, si.branch_id,
            si.sub_total, si.discount_amount,
            si.transport_cost, si.total_amount,
            c.customer_name,
            b.branch_name
        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        LEFT JOIN branches b ON b.id = si.branch_id
        WHERE si.invoice_date BETWEEN p_from_date AND p_to_date
          AND si.status NOT IN ('draft', 'cancelled')
          AND si.is_reversed = false
          AND si.deleted_at IS NULL
          AND (p_branch_id IS NULL OR si.branch_id = p_branch_id)
    ),

    -- CTE 2: Invoice items with revenue
    invoice_items AS (
        SELECT
            ai.id AS invoice_id,
            ai.invoice_code,
            ai.invoice_date,
            ai.customer_name,
            ai.branch_name,
            sii.product_id,
            p.product_code,
            p.product_name,
            sii.qty,
            sii.rate,
            sii.amount AS line_amount,
            sii.discount_amount AS line_discount
        FROM active_invoices ai
        INNER JOIN sales_invoice_items sii ON sii.sales_invoice_id = ai.id
        INNER JOIN products p ON p.id = sii.product_id
    ),

    -- CTE 3: COGS per invoice item (from stock transactions via challan)
    item_cogs AS (
        SELECT
            sci.sales_invoice_id AS invoice_id,
            sci.product_id,
            SUM(st.qty_change) AS cogs_qty,  -- negative (stock OUT)
            SUM(ABS(st.qty_change) * st.avg_cost) AS cogs_amount
        FROM sales_challan_items sci
        INNER JOIN sales_challans sc ON sc.id = sci.challan_id
        INNER JOIN stock_transactions st ON st.reference_type = 'sales_challan'
            AND st.reference_id = sc.id
            AND st.product_id = sci.product_id
        WHERE sc.is_reversed = false
          AND sc.deleted_at IS NULL
        GROUP BY sci.sales_invoice_id, sci.product_id
    ),

    -- CTE 4: Per-invoice margin (aggregated from items)
    invoice_margin AS (
        SELECT
            ii.invoice_id,
            ii.invoice_code,
            ii.invoice_date,
            ii.customer_name,
            ii.branch_name,
            SUM(ii.line_amount) AS total_revenue,
            SUM(ii.line_discount) AS total_line_discount,
            COALESCE(SUM(ic.cogs_amount), 0) AS total_cogs,
            SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0) AS gross_profit,
            CASE WHEN SUM(ii.line_amount) > 0
                THEN ROUND(((SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0)) / SUM(ii.line_amount) * 100)::numeric, 2)
                ELSE 0
            END AS margin_pct
        FROM invoice_items ii
        LEFT JOIN item_cogs ic ON ic.invoice_id = ii.invoice_id AND ic.product_id = ii.product_id
        GROUP BY ii.invoice_id, ii.invoice_code, ii.invoice_date, ii.customer_name, ii.branch_name
    ),

    -- CTE 5: Per-product margin summary
    product_margin AS (
        SELECT
            ii.product_id,
            ii.product_code,
            ii.product_name,
            SUM(ii.qty) AS total_qty,
            SUM(ii.line_amount) AS total_revenue,
            COALESCE(SUM(ic.cogs_amount), 0) AS total_cogs,
            SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0) AS gross_profit,
            CASE WHEN SUM(ii.line_amount) > 0
                THEN ROUND(((SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0)) / SUM(ii.line_amount) * 100)::numeric, 2)
                ELSE 0
            END AS margin_pct
        FROM invoice_items ii
        LEFT JOIN item_cogs ic ON ic.invoice_id = ii.invoice_id AND ic.product_id = ii.product_id
        GROUP BY ii.product_id, ii.product_code, ii.product_name
        ORDER BY gross_profit DESC
    ),

    -- CTE 6: Grand totals
    grand_totals AS (
        SELECT
            SUM(total_revenue) AS total_revenue,
            SUM(total_cogs) AS total_cogs,
            SUM(gross_profit) AS total_gross_profit,
            CASE WHEN SUM(total_revenue) > 0
                THEN ROUND((SUM(gross_profit) / SUM(total_revenue) * 100)::numeric, 2)
                ELSE 0
            END AS overall_margin_pct
        FROM invoice_margin
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'Gross Margin Analysis (CTE)',
            'from_date', p_from_date,
            'to_date', p_to_date,
            'branch_id', p_branch_id,
            'source', 'cte_query'
        ),
        'invoice_margin', COALESCE((SELECT jsonb_agg(row_to_json(im)::jsonb ORDER BY invoice_date DESC, invoice_code) FROM invoice_margin im), '[]'::jsonb),
        'product_margin', COALESCE((SELECT jsonb_agg(row_to_json(pm)::jsonb ORDER BY gross_profit DESC) FROM product_margin pm), '[]'::jsonb),
        'totals', (SELECT jsonb_build_object(
            'total_revenue', total_revenue,
            'total_cogs', total_cogs,
            'total_gross_profit', total_gross_profit,
            'overall_margin_pct', overall_margin_pct
        ) FROM grand_totals)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        // ============================================================
        // 5. Convenience views wrapping the CTE functions for direct SQL access
        // ============================================================

        // Today's summary view (for psql / direct SQL queries)
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_today_summary AS
SELECT rcerp_today_summary(NULL, CURRENT_DATE) AS summary_data
SQL);

        // AR aging view for today
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_ar_aging_today AS
SELECT rcerp_ar_aging_cte(CURRENT_DATE, NULL) AS aging_data
SQL);
    }

    public function down(): void
    {
        // Drop convenience views
        DB::statement('DROP VIEW IF EXISTS v_today_summary');
        DB::statement('DROP VIEW IF EXISTS v_ar_aging_today');

        // Drop CTE functions (in reverse order)
        DB::statement('DROP FUNCTION IF EXISTS rcerp_gross_margin_cte(date, date, integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_general_ledger_cte(date, date, integer, integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_ar_aging_cte(date, integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_today_summary(integer, date) CASCADE');
    }
};
