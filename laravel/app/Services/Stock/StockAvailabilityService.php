<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stock Availability Service — Phase 6.1.
 *
 * Re-derived from first principles (see avg_cost_rule.md §5):
 *
 *   available_qty = physical_qty - sales_pipeline_qty
 *
 * where sales_pipeline = open sales invoice dispatches not yet challan-completed.
 * This prevents overselling when invoices are drafted but not yet dispatched.
 *
 * Two scopes:
 *   - Branch-level: SUM(warehouse_stock for branch) - SUM(open dispatches for branch)
 *   - Warehouse-level: warehouse_stock.qty - SUM(open dispatches for this warehouse)
 *
 * The branch-level check is used when a salesman creates an invoice (before
 * godown assigns a specific warehouse). The warehouse-level check is used
 * when godown prepares the challan (specific warehouse assigned).
 *
 * P2-7: Pipeline qty is cached in Redis (5-min TTL) to avoid repeated
 * JOIN queries on sales_invoice_dispatches + sales_invoices during cart
 * validation. Cache is invalidated by SalesInvoiceService + SalesChallanService
 * whenever the pipeline changes (finalize, edit, cancel, challan issue/cancel).
 *
 * G3 fix (2026-09-01): the sales-pipeline filter previously excluded
 * `si.status = 'challan_completed'`, but `sales_invoices.status` has no such
 * value (CHECK is draft/confirmed/cancelled/reversed). The nonexistent value
 * was a harmless no-op (whereNotIn never matched it) — the real "fully
 * dispatched" exclusion is the `sid.ordered_qty > sid.dispatched_qty` filter.
 * The status filter now excludes only `['reversed', 'cancelled']`.
 */
class StockAvailabilityService
{
    private const QTY_TOLERANCE = 0.0001;

    /**
     * Cache TTL for pipeline qty (seconds).
     * 5 minutes — short enough to be fresh, long enough to serve
     * multiple cart-add validations in a single session.
     */
    private const PIPELINE_CACHE_TTL = 300;

    /**
     * Cache key prefix for branch-level pipeline qty.
     * Key format: pipeline:branch:{branchId}:{productId}
     */
    private const BRANCH_PIPELINE_KEY = 'pipeline:branch:';

    /**
     * Cache key prefix for warehouse-level pipeline qty.
     * Key format: pipeline:wh:{warehouseId}:{productId}
     */
    private const WAREHOUSE_PIPELINE_KEY = 'pipeline:wh:';

    /**
     * Branch-level available qty = physical - open dispatch pipeline.
     *
     * @param int $productId
     * @param int $branchId
     * @param int|null $excludeInvoiceId Exclude this invoice from the pipeline (for editing drafts).
     * @return float
     */
    public function getBranchAvailableQty(int $productId, int $branchId, ?int $excludeInvoiceId = null): float
    {
        $physical = $this->getBranchPhysicalQty($productId, $branchId);
        $pipeline = $this->getBranchPipelineQty($productId, $branchId, $excludeInvoiceId);

        return max(0.0, $physical - $pipeline);
    }

    /**
     * Warehouse-level available qty = physical - open dispatches for this warehouse.
     *
     * @param int $productId
     * @param int $warehouseId
     * @param int|null $excludeInvoiceId
     * @return float
     */
    public function getWarehouseAvailableQty(int $productId, int $warehouseId, ?int $excludeInvoiceId = null): float
    {
        $physical = $this->getWarehousePhysicalQty($productId, $warehouseId);
        $pipeline = $this->getWarehousePipelineQty($productId, $warehouseId, $excludeInvoiceId);

        return max(0.0, $physical - $pipeline);
    }

    /**
     * Physical on-hand qty for a product across all warehouses in a branch.
     */
    public function getBranchPhysicalQty(int $productId, int $branchId): float
    {
        $result = DB::table('warehouse_stock as ws')
            ->join('warehouses as w', function ($join) use ($branchId) {
                $join->on('w.id', '=', 'ws.warehouse_id')
                     ->where('w.branch_id', '=', $branchId);
            })
            ->where('ws.product_id', $productId)
            ->sum('ws.qty');

        return (float) $result;
    }

    /**
     * Physical on-hand qty for a product in a specific warehouse.
     */
    public function getWarehousePhysicalQty(int $productId, int $warehouseId): float
    {
        $result = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('qty');

        return $result !== null ? (float) $result : 0.0;
    }

    /**
     * Open sales pipeline qty for a product in a branch.
     * = SUM(ordered_qty - dispatched_qty) for invoices NOT yet challan-completed.
     *
     * @param int $productId
     * @param int $branchId
     * @param int|null $excludeInvoiceId
     * @return float
     */
    public function getBranchPipelineQty(int $productId, int $branchId, ?int $excludeInvoiceId = null): float
    {
        // P2-7: Check cache first (only when no exclude — cached values are
        // for the full pipeline; excludes need a fresh query).
        $cacheKey = self::BRANCH_PIPELINE_KEY . $branchId . ':' . $productId;

        if (!$excludeInvoiceId) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return (float) $cached;
            }
        }

        $query = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sid.sales_invoice_id')
                     ->where('si.is_reversed', false)
                     ->whereNotIn('si.status', ['reversed', 'cancelled']);
            })
            ->where('sid.product_id', $productId)
            ->where('si.branch_id', $branchId)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty');

        if ($excludeInvoiceId) {
            $query->where('sid.sales_invoice_id', '!=', $excludeInvoiceId);
        }

        $result = $query->sum(DB::raw('sid.ordered_qty - sid.dispatched_qty'));
        $pipeline = (float) $result;

        // Cache the full-pipeline value (no exclude) for 5 minutes.
        if (!$excludeInvoiceId) {
            Cache::put($cacheKey, $pipeline, self::PIPELINE_CACHE_TTL);
        }

        return $pipeline;
    }

    /**
     * Open sales pipeline qty for a product in a specific warehouse.
     *
     * @param int $productId
     * @param int $warehouseId
     * @param int|null $excludeInvoiceId
     * @return float
     */
    public function getWarehousePipelineQty(int $productId, int $warehouseId, ?int $excludeInvoiceId = null): float
    {
        // P2-7: Check cache first (only when no exclude).
        $cacheKey = self::WAREHOUSE_PIPELINE_KEY . $warehouseId . ':' . $productId;

        if (!$excludeInvoiceId) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return (float) $cached;
            }
        }

        $query = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sid.sales_invoice_id')
                     ->where('si.is_reversed', false)
                     ->whereNotIn('si.status', ['reversed', 'cancelled']);
            })
            ->where('sid.product_id', $productId)
            ->where('sid.warehouse_id', $warehouseId)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty');

        if ($excludeInvoiceId) {
            $query->where('sid.sales_invoice_id', '!=', $excludeInvoiceId);
        }

        $result = $query->sum(DB::raw('sid.ordered_qty - sid.dispatched_qty'));
        $pipeline = (float) $result;

        // Cache the full-pipeline value (no exclude) for 5 minutes.
        if (!$excludeInvoiceId) {
            Cache::put($cacheKey, $pipeline, self::PIPELINE_CACHE_TTL);
        }

        return $pipeline;
    }

    /**
     * Live product search with branch-level available stock — ported from
     * Legacy `StockAvailabilityService::searchProductsWithStock`.
     *
     * Returns up to 30 active products whose product_name OR product_code
     * matches the term (ILIKE on PgSQL), each row enriched with:
     *   - default_rate / min_rate / max_rate (latest product_price_history row)
     *   - available_qty = max(0, branch physical − branch pipeline)
     *
     * @param  string $term    Search term (>= 1 char). Empty term returns [].
     * @param  int    $branchId Branch scope for stock calculation.
     * @return array<int, array{
     *     id: int,
     *     product_code: string,
     *     product_name: string,
     *     default_rate: float,
     *     min_rate: float,
     *     max_rate: float,
     *     price: float,
     *     available_qty: float
     * }>
     */
    public function searchProductsWithStock(string $term, int $branchId): array
    {
        $term = trim($term);
        if ($term === '' || $branchId <= 0) {
            return [];
        }

        // Latest price row per product — correlated subqueries mirror the
        // Legacy SQL exactly so behaviour matches the production Legacy app.
        $latestPriceSub = function (string $column): string {
            return "COALESCE((
                SELECT lp.{$column}
                FROM product_price_history lp
                WHERE lp.product_id = p.id
                ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                LIMIT 1
            ), 0)";
        };

        $rows = DB::table('products as p')
            ->leftJoinSub(
                DB::table('warehouse_stock as ws')
                    ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                    ->where('w.branch_id', $branchId)
                    ->select('ws.product_id', DB::raw('SUM(ws.qty) AS physical_qty'))
                    ->groupBy('ws.product_id'),
                'phys',
                'phys.product_id',
                '=',
                'p.id'
            )
            ->leftJoinSub(
                DB::table('sales_invoice_dispatches as sid')
                    ->join('sales_invoices as si', 'si.id', '=', 'sid.sales_invoice_id')
                    ->whereRaw('sid.ordered_qty > sid.dispatched_qty')
                    ->where('si.is_reversed', false)
                    ->whereNotIn('si.status', ['reversed', 'cancelled'])
                    ->where('si.branch_id', $branchId)
                    ->whereNotNull('sid.product_id')
                    ->select('sid.product_id', DB::raw('SUM(sid.ordered_qty - sid.dispatched_qty) AS pending_qty'))
                    ->groupBy('sid.product_id'),
                'pend',
                'pend.product_id',
                '=',
                'p.id'
            )
            ->where(function ($q) use ($term) {
                $q->where('p.product_name', 'ILIKE', "%{$term}%")
                  ->orWhere('p.product_code', 'ILIKE', "%{$term}%");
            })
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                DB::raw($latestPriceSub('default_rate') . ' AS default_rate'),
                DB::raw($latestPriceSub('min_rate') . ' AS min_rate'),
                DB::raw($latestPriceSub('max_rate') . ' AS max_rate'),
                DB::raw($latestPriceSub('default_rate') . ' AS price'),
                DB::raw('GREATEST(0, COALESCE(phys.physical_qty, 0) - COALESCE(pend.pending_qty, 0)) AS available_qty')
            )
            ->orderBy('p.product_name')
            ->limit(30)
            ->get();

        return $rows->map(fn ($r) => [
            'id'            => (int) $r->id,
            'product_code'  => (string) $r->product_code,
            'product_name'  => (string) $r->product_name,
            'default_rate'  => (float) $r->default_rate,
            'min_rate'      => (float) $r->min_rate,
            'max_rate'      => (float) $r->max_rate,
            'price'         => (float) $r->price,
            'available_qty' => (float) $r->available_qty,
        ])->values()->all();
    }

    /**
     * Barcode / scanner — exact product_code lookup with branch stock.
     * Ported from Legacy `StockAvailabilityService::findProductByExactCode`.
     *
     * Case-insensitive, trimmed. Returns null when no active product matches.
     *
     * @param  string $code
     * @param  int    $branchId
     * @return array<string, mixed>|null
     */
    public function findProductByExactCode(string $code, int $branchId): ?array
    {
        $code = trim($code);
        if ($code === '' || $branchId <= 0) {
            return null;
        }

        $latestPriceSub = function (string $column): string {
            return "COALESCE((
                SELECT lp.{$column}
                FROM product_price_history lp
                WHERE lp.product_id = p.id
                ORDER BY lp.effective_from DESC, lp.created_at DESC, lp.id DESC
                LIMIT 1
            ), 0)";
        };

        $row = DB::table('products as p')
            ->leftJoinSub(
                DB::table('warehouse_stock as ws')
                    ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                    ->where('w.branch_id', $branchId)
                    ->select('ws.product_id', DB::raw('SUM(ws.qty) AS physical_qty'))
                    ->groupBy('ws.product_id'),
                'phys',
                'phys.product_id',
                '=',
                'p.id'
            )
            ->leftJoinSub(
                DB::table('sales_invoice_dispatches as sid')
                    ->join('sales_invoices as si', 'si.id', '=', 'sid.sales_invoice_id')
                    ->whereRaw('sid.ordered_qty > sid.dispatched_qty')
                    ->where('si.is_reversed', false)
                    ->whereNotIn('si.status', ['reversed', 'cancelled'])
                    ->where('si.branch_id', $branchId)
                    ->whereNotNull('sid.product_id')
                    ->select('sid.product_id', DB::raw('SUM(sid.ordered_qty - sid.dispatched_qty) AS pending_qty'))
                    ->groupBy('sid.product_id'),
                'pend',
                'pend.product_id',
                '=',
                'p.id'
            )
            ->whereRaw('UPPER(TRIM(p.product_code)) = UPPER(TRIM(?))', [$code])
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                DB::raw($latestPriceSub('default_rate') . ' AS default_rate'),
                DB::raw($latestPriceSub('min_rate') . ' AS min_rate'),
                DB::raw($latestPriceSub('max_rate') . ' AS max_rate'),
                DB::raw($latestPriceSub('default_rate') . ' AS price'),
                DB::raw('GREATEST(0, COALESCE(phys.physical_qty, 0) - COALESCE(pend.pending_qty, 0)) AS available_qty')
            )
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Per-warehouse breakdown for a branch (for the challan picker modal).
     *
     * @param int $productId
     * @param int $branchId
     * @param int|null $excludeInvoiceId Exclude this invoice from the pipeline
     *        (used by the godown screen so an invoice being edited does not
     *        count its own open dispatch against the displayed availability).
     * @return array<int, array{id: int, warehouse_name: string, physical_qty: float, pipeline_qty: float, available_qty: float, avg_cost: float}>
     */
    public function getBranchWarehouseBreakdown(int $productId, int $branchId, ?int $excludeInvoiceId = null): array
    {
        $warehouses = DB::table('warehouses as w')
            ->leftJoin('warehouse_stock as ws', function ($join) use ($productId) {
                $join->on('ws.warehouse_id', '=', 'w.id')
                     ->where('ws.product_id', '=', $productId);
            })
            ->where('w.branch_id', $branchId)
            ->where('w.is_active', true)
            ->select(
                'w.id',
                'w.warehouse_name',
                DB::raw('COALESCE(ws.qty, 0) as physical_qty'),
                DB::raw('COALESCE(ws.avg_cost, 0) as avg_cost')
            )
            ->orderBy('w.warehouse_name')
            ->get();

        return $warehouses->map(function ($wh) use ($productId, $excludeInvoiceId) {
            $pipeline = $this->getWarehousePipelineQty($productId, $wh->id, $excludeInvoiceId);
            return [
                'id' => (int) $wh->id,
                'warehouse_name' => $wh->warehouse_name,
                'physical_qty' => (float) $wh->physical_qty,
                'pipeline_qty' => $pipeline,
                'available_qty' => max(0.0, (float) $wh->physical_qty - $pipeline),
                'avg_cost' => (float) $wh->avg_cost,
            ];
        })->values()->all();
    }

    /**
     * Batched version of getBranchWarehouseBreakdown — fetches the
     * per-warehouse breakdown for MANY products in just 3 queries
     * (warehouses + warehouse_stock + pipeline), instead of N(1+W)
     * queries when called per-product in a loop.
     *
     * Used by the godown page (SalesChallanController::godown) which
     * previously called getBranchWarehouseBreakdown() once per invoice
     * item, causing an O(N×W) N+1 query storm that made the page slow.
     *
     * @param array<int> $productIds
     * @param int $branchId
     * @param int|null $excludeInvoiceId
     * @return array<int, array{id: int, warehouse_name: string, physical_qty: float, pipeline_qty: float, available_qty: float, avg_cost: float}>  keyed by product_id
     */
    public function getBranchWarehouseBreakdownForProducts(array $productIds, int $branchId, ?int $excludeInvoiceId = null): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0));
        if (empty($productIds) || $branchId <= 0) {
            return [];
        }

        // Query 1 — warehouses for the branch (fetched once, reused for all products).
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('warehouse_name')
            ->pluck('warehouse_name', 'id');

        if ($warehouses->isEmpty()) {
            return [];
        }

        $warehouseIds = $warehouses->keys()->all();

        // Query 2 — physical stock for ALL products × ALL warehouses in one go.
        // Left join on warehouses so every product×warehouse combo appears
        // (COALESCE to 0 where no stock row exists).
        $stockRows = DB::table('warehouses as w')
            ->leftJoin('warehouse_stock as ws', function ($join) use ($productIds) {
                $join->on('ws.warehouse_id', '=', 'w.id')
                     ->whereIn('ws.product_id', $productIds);
            })
            ->where('w.branch_id', $branchId)
            ->where('w.is_active', true)
            ->whereIn('ws.product_id', $productIds)
            ->select(
                'ws.product_id',
                'ws.warehouse_id',
                DB::raw('COALESCE(ws.qty, 0) as physical_qty'),
                DB::raw('COALESCE(ws.avg_cost, 0) as avg_cost')
            )
            ->get();

        // Index stock by [productId => [warehouseId => {physical, avg_cost}]]
        $stockByProduct = [];
        foreach ($stockRows as $row) {
            $pid = (int) $row->product_id;
            $wid = (int) $row->warehouse_id;
            $stockByProduct[$pid][$wid] = [
                'physical_qty' => (float) $row->physical_qty,
                'avg_cost' => (float) $row->avg_cost,
            ];
        }

        // Query 3 — pipeline qty for ALL products × ALL warehouses in ONE query.
        // Replaces N×W individual getWarehousePipelineQty() calls.
        $pipelineQuery = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sid.sales_invoice_id')
                     ->where('si.is_reversed', false)
                     ->whereNotIn('si.status', ['reversed', 'cancelled']);
            })
            ->whereIn('sid.product_id', $productIds)
            ->whereIn('sid.warehouse_id', $warehouseIds)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty');

        if ($excludeInvoiceId) {
            $pipelineQuery->where('sid.sales_invoice_id', '!=', $excludeInvoiceId);
        }

        $pipelineRows = $pipelineQuery
            ->select(
                'sid.product_id',
                'sid.warehouse_id',
                DB::raw('SUM(sid.ordered_qty - sid.dispatched_qty) as pipeline_qty')
            )
            ->groupBy('sid.product_id', 'sid.warehouse_id')
            ->get();

        // Index pipeline by [productId => [warehouseId => pipelineQty]]
        $pipelineByProduct = [];
        foreach ($pipelineRows as $row) {
            $pid = (int) $row->product_id;
            $wid = (int) $row->warehouse_id;
            $pipelineByProduct[$pid][$wid] = (float) $row->pipeline_qty;
        }

        // Assemble the breakdown per product (same shape as
        // getBranchWarehouseBreakdown, keyed by product_id).
        $result = [];
        foreach ($productIds as $pid) {
            $breakdown = [];
            foreach ($warehouses as $wid => $wname) {
                $physical = $stockByProduct[$pid][$wid]['physical_qty'] ?? 0.0;
                $avgCost = $stockByProduct[$pid][$wid]['avg_cost'] ?? 0.0;
                $pipeline = $pipelineByProduct[$pid][$wid] ?? 0.0;
                $breakdown[] = [
                    'id' => (int) $wid,
                    'warehouse_name' => $wname,
                    'physical_qty' => $physical,
                    'pipeline_qty' => $pipeline,
                    'available_qty' => max(0.0, $physical - $pipeline),
                    'avg_cost' => $avgCost,
                ];
            }
            $result[$pid] = $breakdown;
        }

        return $result;
    }

    /**
     * Assert that the requested quantities are available in the branch.
     * Throws if any product is insufficient.
     *
     * @param int $branchId
     * @param array<int, float> $qtyByProduct product_id => requested qty
     * @param int|null $excludeInvoiceId
     * @throws \RuntimeException If any product is insufficient.
     */
    public function assertBranchProductsAvailable(int $branchId, array $qtyByProduct, ?int $excludeInvoiceId = null): void
    {
        foreach ($qtyByProduct as $productId => $requestedQty) {
            $productId = (int) $productId;
            if ($productId <= 0 || (float) $requestedQty <= 0) {
                continue;
            }
            $available = $this->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
            if ((float) $requestedQty > $available + self::QTY_TOLERANCE) {
                throw new \RuntimeException(
                    "Insufficient available stock for product {$productId}: "
                    . "requested " . number_format((float) $requestedQty, 2)
                    . ", available " . number_format($available, 2)
                );
            }
        }
    }

    // ============================================================
    // ============== P2-7: CACHE INVALIDATION ====================
    // ============================================================

    /**
     * P2-7: Invalidate the branch-level pipeline cache for a specific
     * product + branch. Call after any operation that changes the
     * sales pipeline (finalize, edit, cancel, challan issue/cancel).
     *
     * @param int $branchId
     * @param int|null $productId If null, flushes ALL products for this branch.
     */
    public function invalidateBranchPipeline(int $branchId, ?int $productId = null): void
    {
        if ($productId !== null) {
            Cache::forget(self::BRANCH_PIPELINE_KEY . $branchId . ':' . $productId);
        } else {
            // Flush all pipeline keys for this branch using a tag (if supported)
            // or a prefix scan (Redis). For simplicity, we use a flush by pattern.
            $this->flushByPrefix(self::BRANCH_PIPELINE_KEY . $branchId . ':');
        }
    }

    /**
     * P2-7: Invalidate the warehouse-level pipeline cache for a specific
     * product + warehouse.
     *
     * @param int $warehouseId
     * @param int|null $productId
     */
    public function invalidateWarehousePipeline(int $warehouseId, ?int $productId = null): void
    {
        if ($productId !== null) {
            Cache::forget(self::WAREHOUSE_PIPELINE_KEY . $warehouseId . ':' . $productId);
        } else {
            $this->flushByPrefix(self::WAREHOUSE_PIPELINE_KEY . $warehouseId . ':');
        }
    }

    /**
     * P2-7: Invalidate pipeline cache for all products affected by an
     * invoice operation. Queries the dispatches for the given invoice
     * and flushes each product's cache.
     *
     * @param int $invoiceId
     */
    public function invalidatePipelineForInvoice(int $invoiceId): void
    {
        $dispatches = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', 'si.id', '=', 'sid.sales_invoice_id')
            ->where('sid.sales_invoice_id', $invoiceId)
            ->select('sid.product_id', 'si.branch_id', 'sid.warehouse_id')
            ->get();

        foreach ($dispatches as $d) {
            $this->invalidateBranchPipeline((int) $d->branch_id, (int) $d->product_id);
            if ($d->warehouse_id) {
                $this->invalidateWarehousePipeline((int) $d->warehouse_id, (int) $d->product_id);
            }
        }
    }

    /**
     * Flush cache keys by prefix (Redis SCAN + DEL, or no-op for array/file cache).
     */
    private function flushByPrefix(string $prefix): void
    {
        // For Redis cache stores, we can use the Redis connection directly.
        $store = Cache::getStore();

        if (method_exists($store, 'getRedis')) {
            // Redis store — use SCAN to find + delete keys.
            $redis = $store->getRedis();
            $connection = $redis->connection();
            $prefixKey = $store->getPrefix() . $prefix . '*';

            $iterator = null;
            do {
                $keys = $connection->scan($iterator, $prefixKey, 100);
                if (!empty($keys)) {
                    $connection->del($keys);
                }
            } while ($iterator > 0);
        }
        // For file/array stores, individual Cache::forget calls are the only way.
        // In practice, the per-product invalidation (invalidateBranchPipeline with
        // a specific productId) is the primary path — the flush-all-for-branch
        // path is rarely needed and gracefully degrades to a no-op for non-Redis stores.
    }
}
