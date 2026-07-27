<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Warehouse Phase 8 test helpers — direct table inserts for warehouse-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy.
 */
trait InsertsWarehouseDependencies
{
    /**
     * Insert a product row with minimum required columns.
     * Note: `unit` must be one of: Pcs, Carton, KG, Bag, Dobe, Set (CHECK constraint).
     */
    protected function insertProduct(string $code = null): int
    {
        $code = $code ?? 'PROD-' . substr(uniqid(), -6);

        return DB::table('products')->insertGetId([
            'product_code' => $code,
            'product_name' => 'Test Product ' . $code,
            'unit'         => 'Pcs',
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a warehouse_stock row with minimum required columns.
     * Note: warehouse_stock has no `created_at` column (only `updated_at`).
     */
    protected function insertWarehouseStock(int $warehouseId, int $productId, float $qty): void
    {
        DB::table('warehouse_stock')->insert([
            'warehouse_id' => $warehouseId,
            'product_id'   => $productId,
            'qty'          => $qty,
            'avg_cost'     => 10.00,
            'total_qty'    => $qty,
            'total_value'  => $qty * 10.00,
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a sales_invoice_dispatches row with minimum required columns.
     * Uses ordered_qty/dispatched_qty (P0-2 restored columns).
     */
    protected function insertSalesInvoiceDispatch(
        int $warehouseId,
        int $branchId,
        float $orderedQty,
        float $dispatchedQty = 0,
    ): int {
        // Need a customer + sales invoice first (FK)
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUST-DISP-' . substr(uniqid(), -6),
            'customer_name' => 'Dispatch Customer',
            'branch_id'     => $branchId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_code' => 'INV-DISP-' . substr(uniqid(), -6),
            'invoice_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'status'       => 'confirmed',
            'is_reversed'  => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $productId = $this->insertProduct();

        return DB::table('sales_invoice_dispatches')->insertGetId([
            'sales_invoice_id' => $invoiceId,
            'product_id'       => $productId,
            'warehouse_id'     => $warehouseId,
            'qty'              => $orderedQty,
            'ordered_qty'      => $orderedQty,
            'dispatched_qty'   => $dispatchedQty,
            'rate'             => 10.00,
            'dispatch_date'    => now()->toDateString(),
        ]);
    }

    /**
     * Insert a stock_take_sessions + stock_take_warehouses pair.
     * Note: `status` must be one of: draft, counting, submitted, approved,
     *       posted, cancelled (CHECK constraint — Phase 4 added submitted/approved;
     *       'reversed' is also allowed but reserved for Phase 10).
     * Note: reversal columns (is_reversed, reversed_at, reversed_by, reverse_reason)
     *       were added to stock_take_sessions in Phase 0 of the Stock Take plan
     *       (migration 2025_07_26_000003). StockTakeService::createSession writes
     *       is_reversed=false on insert, so test rows must match the service contract.
     * Note: Phase 4 added submitted_by/at, approved_by/at, approval_comments
     *       (migration 2025_07_28_000001). All nullable — test rows can omit them.
     */
    protected function insertActiveStockTake(int $warehouseId, int $branchId, string $status = 'draft'): int
    {
        $sessionId = DB::table('stock_take_sessions')->insertGetId([
            'session_code'  => 'ST-' . substr(uniqid(), -6),
            'session_date'  => now()->toDateString(),
            'branch_id'     => $branchId,
            'status'        => $status,
            'is_reversed'   => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('stock_take_warehouses')->insert([
            'stock_take_session_id' => $sessionId,
            'warehouse_id'          => $warehouseId,
        ]);

        return $sessionId;
    }
}
