<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REPORTS-AUDIT-7 (G-221 + G-229 / cte-reports.md G11 + G13).
 *
 * Recreates the `rcerp_today_summary` PL/pgSQL function with 2 fixes:
 *
 *   1. G-221 — mtd_collection CTE now filters `deleted_at IS NULL` on
 *      customer_payments. The original function (migration 2025_01_21_000002)
 *      had a stale comment saying "customer_payments has no deleted_at column"
 *      because it was written BEFORE migration 2025_01_23_000002 added
 *      soft-deletes to customer_payments. Now that the column exists, a
 *      soft-deleted customer_payment would be counted in MTD collection
 *      (inflating the KPI). The new filter excludes them.
 *
 *   2. G-229 — pending_godown + pending_challan counts now exclude confirmed
 *      invoices older than 7 days. The original function counted ALL confirmed
 *      invoices with is_godown_prepared=false (or is_challan_issued=false)
 *      regardless of age. A confirmed invoice from 3 months ago that still
 *      has no godown is not really "pending" — it is abandoned. The 7-day
 *      window focuses the pending KPI on actionable items. The stale-evidence
 *      claim in G-229 ("includes draft-status rows") was wrong — the original
 *      already filtered status='confirmed'. The 7-day window is the real fix.
 *
 * NOTE on STABLE + NOW(): the function is declared STABLE and accepts p_date
 * as a parameter. Using NOW() inside would make the result depend on
 * transaction time rather than p_date. We use `p_date - INTERVAL '7 days'`
 * instead so the function remains deterministic for a given (p_branch_id, p_date).
 *
 * The full function body is recreated via CREATE OR REPLACE (idempotent).
 * The rest of the function (CTEs 1-3, 5-11, final aggregation) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Recreate the function with the 2 fixes. CREATE OR REPLACE is
        // safe — it keeps the same signature (integer, date) so existing
        // callers are unaffected. The only behavioral changes are:
        //   - mtd_collection excludes soft-deleted customer_payments
        //   - pending_godown/pending_challan exclude confirmed invoices > 7 days old
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
    -- CTE 1: Active invoices (not cancelled, not reversed, not soft-deleted)
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
    -- REPORTS-AUDIT-7 (G-221): added deleted_at IS NULL filter.
    -- customer_payments.deleted_at was added by migration 2025_01_23_000002
    -- (soft-deletes support). The original function predates that migration
    -- and had a stale comment claiming the column did not exist.
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
    -- REPORTS-AUDIT-7 (G-229): pending_godown + pending_challan now exclude
    -- confirmed invoices older than 7 days. A confirmed invoice from months
    -- ago with no godown is abandoned, not pending. The 7-day window focuses
    -- the KPI on actionable items. draft_count is unchanged (drafts are
    -- expected to be short-lived; a separate stale_drafts KPI could be added
    -- in a future enhancement if needed).
    pending_ops AS (
        SELECT
            (SELECT COUNT(*) FROM active_invoices
             WHERE is_godown_prepared = false
               AND status = 'confirmed'
               AND created_at > p_date - INTERVAL '7 days') AS pending_godown,
            (SELECT COUNT(*) FROM active_invoices
             WHERE is_godown_prepared = true
               AND is_challan_issued = false
               AND status = 'confirmed'
               AND created_at > p_date - INTERVAL '7 days') AS pending_challan,
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
    }

    public function down(): void
    {
        // No-op: the original function body (migration 2025_01_21_000002)
        // is 200+ lines. Recreating it verbatim here would bloat this
        // migration without adding value — the up() change is a pure
        // improvement (more restrictive filters: deleted_at IS NULL +
        // 7-day pending window). Reverting would re-introduce the bugs
        // (soft-deleted payments counted in MTD collection, stale
        // confirmed invoices counted as pending).
        //
        // For a fresh install, migration 2025_01_21_000002 creates the
        // original function, then THIS migration runs immediately after
        // and applies the 2 fixes. The net state on a fresh install is
        // the fixed function. On a rollback, the fixed function remains
        // (which is the desired state — the fixes are correct).
        //
        // If a true revert is ever needed, restore migration
        // 2025_01_21_000002 from git history and re-run it.
    }
};
