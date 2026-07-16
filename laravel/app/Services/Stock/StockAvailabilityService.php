<?php

namespace App\Services\Stock;

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
 */
class StockAvailabilityService
{
    private const QTY_TOLERANCE = 0.0001;

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
        $query = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sid.sales_invoice_id')
                     ->where('si.is_reversed', false)
                     ->whereNotIn('si.status', ['challan_completed', 'reversed', 'cancelled']);
            })
            ->where('sid.product_id', $productId)
            ->where('si.branch_id', $branchId)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty');

        if ($excludeInvoiceId) {
            $query->where('sid.sales_invoice_id', '!=', $excludeInvoiceId);
        }

        $result = $query->sum(DB::raw('sid.ordered_qty - sid.dispatched_qty'));

        return (float) $result;
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
        $query = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sid.sales_invoice_id')
                     ->where('si.is_reversed', false)
                     ->whereNotIn('si.status', ['challan_completed', 'reversed', 'cancelled']);
            })
            ->where('sid.product_id', $productId)
            ->where('sid.warehouse_id', $warehouseId)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty');

        if ($excludeInvoiceId) {
            $query->where('sid.sales_invoice_id', '!=', $excludeInvoiceId);
        }

        $result = $query->sum(DB::raw('sid.ordered_qty - sid.dispatched_qty'));

        return (float) $result;
    }

    /**
     * Per-warehouse breakdown for a branch (for the challan picker modal).
     *
     * @param int $productId
     * @param int $branchId
     * @param int|null $excludeInvoiceId
     * @return array<int, array{id: int, warehouse_name: string, physical_qty: float, pipeline_qty: float, available_qty: float}>
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
            ->select('w.id', 'w.warehouse_name', DB::raw('COALESCE(ws.qty, 0) as physical_qty'))
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
            ];
        })->values()->all();
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
}
