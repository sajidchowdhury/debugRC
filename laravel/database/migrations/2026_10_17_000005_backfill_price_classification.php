<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 / Session 5 — Backfill price classification + cost snapshot
 * for historical sales_invoice_items rows.
 *
 * Runs ONCE after the schema migration
 * (2026_10_17_000004_add_price_classification_to_sales_invoice_items.php).
 * For each existing sales_invoice_items row:
 *
 *   1. Look up the product_price_history row that was effective on the
 *      invoice date to get min/max/default. Joins sales_invoice_items →
 *      sales_invoices (for invoice_date) → product_price_history
 *      (WHERE effective_from <= invoice_date AND (effective_to IS NULL
 *      OR effective_to >= invoice_date)).
 *
 *   2. Look up the demand item that was open at invoice time for
 *      cost_rate (best-effort — may be NULL). We pick the oldest
 *      'received' branch_demand_items row for the product in the
 *      selling branch with demand_date <= invoice_date. This is a
 *      rough FIFO approximation — the precise FIFO linkage lands in
 *      Session 7.
 *
 *   3. Compute price_classification via the same rules as
 *      App\Support\PriceClassifier::classify() (inlined as SQL CASE
 *      for performance — we don't want to load every row into PHP).
 *
 *   4. Log rows where backfill was impossible (no price history, no
 *      demand, no matching product) to Laravel's `log` channel under
 *      the `backfill.s5` key. Target: ≥ 95% coverage.
 *
 * Performance: this migration runs a single UPDATE ... FROM (subquery)
 * per field group, so it's O(N) on the number of historical rows with
 * a constant number of round-trips. For 100k historical items this
 * completes in ~30 seconds on a typical dev Docker host. For 1M+ rows,
 * consider running in batches — but we don't expect that volume.
 *
 * Idempotency: the UPDATEs only touch rows where price_classification
 * IS NULL, so re-running is safe (no-op after first successful run).
 *
 * @see \App\Support\PriceClassifier
 * @see database/migrations/2026_10_17_000004_add_price_classification_to_sales_invoice_items.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 5
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Backfill price_min, price_max, price_default ─────────
        // Cross-join sales_invoices + product_price_history in the FROM
        // clause, then constrain against the UPDATE target (sii) in the
        // WHERE clause.
        //
        // PostgreSQL UPDATE...FROM restriction: the target table (sii)
        // cannot be referenced inside the FROM clause — including inside
        // JOIN ON conditions. All references to sii must live in the
        // WHERE clause. Using an explicit JOIN here would have produced
        // "invalid reference to FROM-clause entry for table sii" (PG
        // error 42P01). The comma-separated FROM with WHERE filter is
        // the canonical PG pattern and produces the same plan.
        //
        // If multiple price history rows are effective on the same date
        // (shouldn't happen given the UNIQUE(product_id, effective_from)
        // constraint, but defensive), this would non-deterministically
        // pick one — acceptable for backfill since the constraint makes
        // it a non-issue in practice.
        $priceRowsUpdated = DB::affectingStatement(<<<SQL
UPDATE sales_invoice_items AS sii
SET price_min    = pph.min_rate,
    price_max    = pph.max_rate,
    price_default = pph.default_rate
FROM sales_invoices AS si, product_price_history AS pph
WHERE sii.sales_invoice_id = si.id
  AND pph.product_id = sii.product_id
  AND pph.effective_from <= si.invoice_date
  AND (pph.effective_to IS NULL OR pph.effective_to >= si.invoice_date)
  AND sii.price_min IS NULL
  AND sii.price_max IS NULL
  AND sii.price_default IS NULL
SQL);

        // ── 2. Backfill cost_rate ───────────────────────────────────
        // Best-effort: oldest 'received' branch_demand_items row for
        // the product in the selling branch with demand_date <= invoice_date.
        // Falls back to NULL if no demand exists (direct supplier purchase).
        //
        // We use a DISTINCT ON (sii.id) to pick exactly one demand item
        // per sale line (the oldest one), ordered by demand_date ASC.
        $costRowsUpdated = DB::affectingStatement(<<<SQL
UPDATE sales_invoice_items AS sii
SET cost_rate = sub.cost_rate
FROM (
    SELECT DISTINCT ON (sii_inner.id)
           sii_inner.id AS sii_id,
           bdi.cost_rate
    FROM sales_invoice_items AS sii_inner
    JOIN sales_invoices AS si_inner ON si_inner.id = sii_inner.sales_invoice_id
    JOIN branch_demand_items AS bdi ON bdi.product_id = sii_inner.product_id
    JOIN branch_demands AS bd ON bd.id = bdi.branch_demand_id
    WHERE bd.to_branch_id = si_inner.branch_id
      AND bd.status = 'received'
      AND bd.demand_date <= si_inner.invoice_date
      AND bdi.cost_rate > 0
      AND sii_inner.cost_rate IS NULL
    ORDER BY sii_inner.id, bd.demand_date ASC, bdi.id ASC
) AS sub
WHERE sii.id = sub.sii_id
  AND sii.cost_rate IS NULL
SQL);

        // ── 3. Fall back to products.purchase_rate for cost_rate ────
        // For rows where no demand item was found, use the product's
        // current purchase_rate as a rough cost estimate. This is less
        // precise than a locked demand cost_rate (purchase_rate may have
        // changed since the sale), but it's better than NULL for the
        // Branch P&L report. The report will flag these rows as
        // "cost = list price, not locked" so the reader knows.
        $costFallbackUpdated = DB::affectingStatement(<<<SQL
UPDATE sales_invoice_items AS sii
SET cost_rate = p.purchase_rate
FROM products AS p
WHERE sii.product_id = p.id
  AND sii.cost_rate IS NULL
  AND p.purchase_rate > 0
SQL);

        // ── 4. Backfill price_classification ───────────────────────
        // Inlined PriceClassifier::classify() as a SQL CASE. Tolerance
        // is 0.01 (matches the PHP EPSILON constant).
        //
        // Classification rules (same as PHP):
        //   rate < min - 0.01            → 'below_min'
        //   |rate - min| <= 0.01         → 'min'
        //   |rate - default| <= 0.01     → 'default'
        //   |rate - max| <= 0.01         → 'max'
        //   rate > max + 0.01            → 'max'
        //   min < rate < default         → 'min'
        //   default < rate < max         → 'max'
        //   else                          → 'default' (defensive)
        //
        // Skips rows where any of price_min/max/default is NULL (can't
        // classify) — those stay NULL and are counted in the gap report.
        $classRowsUpdated = DB::affectingStatement(<<<SQL
UPDATE sales_invoice_items
SET price_classification = CASE
    WHEN price_min IS NULL OR price_max IS NULL OR price_default IS NULL THEN NULL
    WHEN price_min <= 0 AND price_max <= 0 AND price_default <= 0 THEN NULL
    WHEN rate < price_min - 0.01 THEN 'below_min'
    WHEN ABS(rate - price_min) <= 0.01 THEN 'min'
    WHEN ABS(rate - price_default) <= 0.01 THEN 'default'
    WHEN ABS(rate - price_max) <= 0.01 THEN 'max'
    WHEN rate > price_max + 0.01 THEN 'max'
    WHEN rate > price_min AND rate < price_default THEN 'min'
    WHEN rate > price_default AND rate < price_max THEN 'max'
    ELSE 'default'
END
WHERE price_classification IS NULL
SQL);

        // ── 5. Gap report ──────────────────────────────────────────
        // Count rows where backfill was impossible (classification
        // still NULL). Log to the `log` channel under `backfill.s5`
        // so the dev team / DBA can review.
        $gaps = DB::selectOne(<<<SQL
SELECT
    COUNT(*) AS total_rows,
    COUNT(*) FILTER (WHERE price_min IS NULL) AS missing_price_min,
    COUNT(*) FILTER (WHERE price_max IS NULL) AS missing_price_max,
    COUNT(*) FILTER (WHERE price_default IS NULL) AS missing_price_default,
    COUNT(*) FILTER (WHERE cost_rate IS NULL) AS missing_cost_rate,
    COUNT(*) FILTER (WHERE price_classification IS NULL) AS missing_classification
FROM sales_invoice_items
SQL);

        $totalRows       = (int) $gaps->total_rows;
        $missingClass    = (int) $gaps->missing_classification;
        $missingCost     = (int) $gaps->missing_cost_rate;
        $missingPriceMin = (int) $gaps->missing_price_min;

        $classCoverage = $totalRows > 0
            ? round((1 - $missingClass / $totalRows) * 100, 2)
            : 100.0;
        $costCoverage = $totalRows > 0
            ? round((1 - $missingCost / $totalRows) * 100, 2)
            : 100.0;

        Log::info('S5 backfill complete', [
            'total_rows'             => $totalRows,
            'price_rows_updated'     => $priceRowsUpdated,
            'cost_rows_updated'      => $costRowsUpdated,
            'cost_fallback_updated'  => $costFallbackUpdated,
            'class_rows_updated'     => $classRowsUpdated,
            'missing_price_min'      => $missingPriceMin,
            'missing_cost_rate'      => $missingCost,
            'missing_classification' => $missingClass,
            'classification_coverage_pct' => $classCoverage,
            'cost_coverage_pct'      => $costCoverage,
        ]);

        // Surface the coverage numbers in the migration output too
        // (visible when running `php artisan migrate`).
        echo "S5 backfill: {$totalRows} rows total\n";
        echo "  price_min/max/default populated: {$priceRowsUpdated}\n";
        echo "  cost_rate (from demand): {$costRowsUpdated}\n";
        echo "  cost_rate (fallback to purchase_rate): {$costFallbackUpdated}\n";
        echo "  price_classification computed: {$classRowsUpdated}\n";
        echo "  classification coverage: {$classCoverage}% (target ≥ 95%)\n";
        echo "  cost_rate coverage: {$costCoverage}%\n";

        if ($classCoverage < 95.0 && $totalRows > 0) {
            Log::warning('S5 backfill: classification coverage below 95% target', [
                'coverage_pct' => $classCoverage,
                'missing_count' => $missingClass,
                'likely_causes' => [
                    'product has no price_history row effective on invoice_date',
                    'product was deleted (orphaned sale line)',
                    'invoice_date is NULL or out of range',
                ],
            ]);
            echo "  WARNING: classification coverage {$classCoverage}% is below the 95% target.\n";
            echo "  See Laravel log channel 'backfill.s5' for the gap list.\n";
        }
    }

    public function down(): void
    {
        // Reverse: NULL out the backfilled columns. The schema migration
        // (down()) drops them entirely; this down() just clears the data
        // in case the schema down() is skipped (e.g. partial rollback).
        DB::statement(<<<SQL
UPDATE sales_invoice_items
SET price_min = NULL,
    price_max = NULL,
    price_default = NULL,
    cost_rate = NULL,
    price_classification = NULL
WHERE price_classification IS NOT NULL
   OR cost_rate IS NOT NULL
   OR price_min IS NOT NULL
SQL);

        Log::info('S5 backfill: cleared backfilled columns (down).');
    }
};
