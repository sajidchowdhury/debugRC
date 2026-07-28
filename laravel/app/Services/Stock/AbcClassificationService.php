<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * ABC Classification Service — Phase 5 (Stock Take plan).
 *
 * Thin service layer over the `mv_product_abc_classification` materialized
 * view. The view itself (created by the Phase 5 migration + defined in
 * `database/sql/03_stock.sql`) does the heavy lifting: it computes annual
 * usage value per (product_id, warehouse_id) from stock_transactions, ranks
 * within each warehouse, and classifies A/B/C using policy-driven thresholds.
 *
 * This service provides:
 *   - refresh(): REFRESH MATERIALIZED VIEW CONCURRENTLY — the Laravel-
 *     scheduler / artisan fallback for the nightly pg_cron job. CONCURRENTLY
 *     never blocks readers, so cycle-count scope queries (which JOIN the
 *     view) keep working during the refresh.
 *   - getSummary($warehouseId): counts + total usage value per ABC class,
 *     for the create-form "ABC cycle count" helper card and the ABC report.
 *   - getClassForProducts($warehouseId, array $productIds): quick lookup for
 *     showing ABC badges next to ad-hoc-selected products.
 *   - getLastComputedAt(): the view's computed_at (max) — shown in the UI so
 *     users know how fresh the classification is.
 *
 * The classification thresholds and lookback window are read at REFRESH time
 * by the SQL helper functions (stock_take_abc_threshold_a/_b/_lookback_days),
 * which pull from `stock_take_policies`. So changing a policy row + calling
 * refresh() recomputes the classification with the new thresholds — no schema
 * change, no code change.
 *
 * @see \App\Services\Stock\StockTakeService::setupWarehouseCounts  (abc scope joins the view)
 * @see database/migrations/2025_07_29_000001_add_cycle_count_scope_and_abc_classification.php
 */
class AbcClassificationService
{
    /**
     * Does the mv_product_abc_classification materialized view exist in the
     * database?
     *
     * Guards every read path so that a missing view (e.g. the Phase 5
     * migration hasn't been applied yet, or the view was dropped during a
     * migration replay) degrades gracefully — the create-form ABC helper
     * card shows "not computed yet" instead of crashing the whole page with
     * a SQLSTATE[42P01] Undefined table error.
     *
     * Checked against pg_matviews (PostgreSQL's materialized-view catalog)
     * rather than a DuckDB-style information_schema lookup, because MVs are
     * not exposed in information_schema.views in PG.
     */
    public function viewExists(): bool
    {
        return (bool) DB::table('pg_matviews')
            ->where('matviewname', 'mv_product_abc_classification')
            ->exists();
    }

    /**
     * Empty-summary shape returned when the view doesn't exist yet. Kept as
     * a private helper so every guard returns the exact same structure the
     * real query would return for an empty view — callers (Blade, JSON API)
     * never need to branch on "view missing vs view empty".
     */
    private function emptySummary(): array
    {
        return [
            'classes' => [
                'A' => ['count' => 0, 'total_usage_value' => 0.0, 'share' => 0.0],
                'B' => ['count' => 0, 'total_usage_value' => 0.0, 'share' => 0.0],
                'C' => ['count' => 0, 'total_usage_value' => 0.0, 'share' => 0.0],
            ],
            'total_products'    => 0,
            'total_usage_value' => 0.0,
            'computed_at'       => null,
        ];
    }

    /**
     * Refresh the materialized view. Uses CONCURRENTLY so readers (cycle-
     * count scope queries) are never blocked. Requires the UNIQUE index on
     * (warehouse_id, product_id), which the migration creates.
     *
     * Wrap in a transaction-less statement: REFRESH MATERIALIZED VIEW cannot
     * run inside a transaction block in some PG configurations, and CONCURRENTLY
     * explicitly cannot run in a transaction. DB::statement runs it in its own
     * implicit statement, which is fine.
     *
     * @return array{refreshed: bool, computed_at: string|null, rows: int, error: string|null}
     */
    public function refresh(): array
    {
        // Pre-check: if the MV doesn't exist, REFRESH CONCURRENTLY would
        // throw SQLSTATE[42P01]. Return a clear, actionable error instead
        // so the ABC report's "Refresh now" button shows a helpful message
        // ("run php artisan migrate") rather than a raw PG error string.
        if (! $this->viewExists()) {
            return [
                'refreshed'   => false,
                'computed_at' => null,
                'rows'        => 0,
                'error'       => 'The materialized view mv_product_abc_classification does not exist. '
                    . 'Run `php artisan migrate` to apply the Phase 5 migration '
                    . '(2025_07_29_000001_add_cycle_count_scope_and_abc_classification) first.',
            ];
        }

        try {
            DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification');
        } catch (\Throwable $e) {
            return [
                'refreshed'   => false,
                'computed_at' => $this->getLastComputedAt(),
                'rows'        => $this->rowCount(),
                'error'       => $e->getMessage(),
            ];
        }

        return [
            'refreshed'   => true,
            'computed_at' => $this->getLastComputedAt(),
            'rows'        => $this->rowCount(),
            'error'       => null,
        ];
    }

    /**
     * Per-class summary for one warehouse (or all warehouses when null).
     * Returns counts + total annual usage value per class, plus the grand
     * total. Used by the create-form ABC helper card and the ABC report.
     *
     * @param int|null $warehouseId  Null = aggregate across all warehouses.
     * @return array{
     *     classes: array<string, array{count: int, total_usage_value: float, share: float}>,
     *     total_products: int,
     *     total_usage_value: float,
     *     computed_at: string|null
     * }
     */
    public function getSummary(?int $warehouseId = null): array
    {
        // Guard: if the Phase 5 migration hasn't been applied (or the view
        // was dropped), return an empty summary instead of throwing
        // SQLSTATE[42P01]. The create-form ABC card renders "not computed
        // yet"; the ABC report shows an empty table with a banner.
        if (! $this->viewExists()) {
            return $this->emptySummary();
        }

        $query = DB::table('mv_product_abc_classification')
            ->selectRaw('
                abc_class,
                COUNT(*) AS product_count,
                COALESCE(SUM(annual_usage_value), 0) AS total_usage_value
            ')
            ->groupBy('abc_class');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        $rows = $query->get()->keyBy('abc_class');
        $grandTotal = (float) $rows->sum(fn($r) => (float) $r->total_usage_value);
        $totalCount = (int) $rows->sum(fn($r) => (int) $r->product_count);

        $classes = [];
        foreach (['A', 'B', 'C'] as $class) {
            $r = $rows->get($class);
            $value = $r ? (float) $r->total_usage_value : 0.0;
            $count = $r ? (int) $r->product_count : 0;
            $classes[$class] = [
                'count'             => $count,
                'total_usage_value' => $value,
                'share'             => $grandTotal > 0 ? round($value / $grandTotal, 4) : 0.0,
            ];
        }

        return [
            'classes'            => $classes,
            'total_products'     => $totalCount,
            'total_usage_value'  => $grandTotal,
            'computed_at'        => $this->getLastComputedAt(),
        ];
    }

    /**
     * ABC class for a set of products in a given warehouse. Returns a map
     * product_id => abc_class (null for products with no classification row,
     * e.g. never sold / outside lookback). Used to show ABC badges next to
     * ad-hoc-selected products on the create form.
     *
     * @param int $warehouseId
     * @param array<int> $productIds
     * @return array<int, string|null>
     */
    public function getClassForProducts(int $warehouseId, array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        $out = array_fill_keys($productIds, null);
        if (empty($productIds)) {
            return $out;
        }
        if (! $this->viewExists()) {
            return $out;
        }

        $rows = DB::table('mv_product_abc_classification')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->select('product_id', 'abc_class')
            ->get();

        foreach ($rows as $r) {
            $out[(int) $r->product_id] = $r->abc_class;
        }
        return $out;
    }

    /**
     * The view's freshness marker — the max computed_at across all rows
     * (set by CURRENT_TIMESTAMP at refresh time). Null when the view is empty.
     */
    public function getLastComputedAt(): ?string
    {
        if (! $this->viewExists()) {
            return null;
        }
        $value = DB::table('mv_product_abc_classification')->max('computed_at');
        return $value ?: null;
    }

    /**
     * Total row count in the view (for the "X products classified" badge).
     */
    public function rowCount(): int
    {
        if (! $this->viewExists()) {
            return 0;
        }
        return (int) DB::table('mv_product_abc_classification')->count();
    }
}
