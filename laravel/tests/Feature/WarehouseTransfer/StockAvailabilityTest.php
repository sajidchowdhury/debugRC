<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — Stock Availability feature tests for WarehouseTransfer.
 *
 * Covers pipeline-aware availability enforcement at both create and
 * confirm time:
 *
 *   1. Transfer requesting more qty than available (physical - pipeline)
 *      is rejected at draft creation.
 *   2. Transfer within available qty succeeds at draft creation.
 *   3. Confirm-time availability check catches stock changes between
 *      draft creation and confirmation (stock may decrease if other
 *      operations consume it).
 *
 * Pipeline setup uses sales_invoice_dispatches rows (open invoice
 * dispatches not yet challan-completed) to consume stock availability,
 * mirroring the real-world scenario where pending sales compete with
 * transfers for the same physical stock.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and
 * rolls back on tearDown, leaving the rcerp_test DB pristine.
 */
class StockAvailabilityTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $transferService;
    protected StockAvailabilityService $availabilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->transferService = app(WarehouseTransferService::class);
        $this->availabilityService = app(StockAvailabilityService::class);
    }

    /**
     * Build the standard createTransfer payload. Caller can override any key.
     */
    private function basePayload(
        int $fromWarehouseId,
        int $toWarehouseId,
        array $items,
        array $overrides = [],
    ): array {
        return array_merge([
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Phase 7 availability test',
            'created_by'        => auth()->id(),
            'items'             => $items,
        ], $overrides);
    }

    /**
     * Insert a sales invoice dispatch that creates pipeline demand
     * for a specific product at a specific warehouse.
     *
     * This simulates an open sales order that reserves stock, reducing
     * the available qty for transfers.
     *
     * @return int The dispatch row id.
     */
    private function insertPipelineDispatch(
        int $branchId,
        int $warehouseId,
        int $productId,
        float $orderedQty,
        float $dispatchedQty = 0,
    ): int {
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUST-PIPE-' . substr(uniqid(), -6),
            'customer_name' => 'Pipeline Customer',
            'branch_id'     => $branchId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_code' => 'INV-PIPE-' . substr(uniqid(), -6),
            'invoice_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'status'       => 'confirmed',
            'is_reversed'  => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

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

    // ====================================================================
    // Test scenarios
    // ====================================================================

    /**
     * SCENARIO 1: Transfer requesting more qty than available is rejected.
     *
     * Setup: physical=100, pipeline=30 (open dispatch), available=70.
     * Request qty=80 → exceeds available → RuntimeException thrown.
     */
    public function test_transfer_respects_pipeline_aware_availability(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Physical stock = 100 at source warehouse.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        // Pipeline = 30 (open dispatch for 30 units, none yet dispatched).
        $this->insertPipelineDispatch($branch->id, $fromWhId, $productId, 30, 0);

        // Verify our setup: available should be 100 - 30 = 70.
        $available = $this->availabilityService->getWarehouseAvailableQty($productId, $fromWhId);
        $this->assertEqualsWithDelta(70, $available, 0.01, 'Available qty should be 70 (physical 100 - pipeline 30)');

        // Request qty=80 → exceeds available=70 → should fail.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient available stock');

        $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 80, 'rate' => 10]],
        ));
    }

    /**
     * SCENARIO 2: Transfer within available qty succeeds at draft creation.
     *
     * Setup: physical=100, pipeline=30, available=70.
     * Request qty=60 → within available → draft created successfully.
     */
    public function test_transfer_with_qty_within_available_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Physical stock = 100 at source warehouse.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        // Pipeline = 30.
        $this->insertPipelineDispatch($branch->id, $fromWhId, $productId, 30, 0);

        // Request qty=60 → within available=70 → succeeds.
        $transfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 60, 'rate' => 10]],
        ));

        $this->assertNotNull($transfer->id);
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'             => $transfer->id,
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'status'         => 'draft',
        ]);

        // Verify the transfer item was persisted with the requested qty.
        $this->assertDatabaseHas('warehouse_transfer_items', [
            'warehouse_transfer_id' => $transfer->id,
            'product_id'            => $productId,
            'qty'                   => 60,
        ]);
    }

    /**
     * SCENARIO 3: Confirm-time availability check catches stock changes.
     *
     * Setup: Create draft when physical=100, pipeline=0, available=100.
     * Transfer qty=60 → draft succeeds.
     * Then reduce physical stock to 40 before confirm.
     * Confirm → fails because available=40 < requested=60.
     */
    public function test_confirm_time_availability_check_catches_stock_changes(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Physical stock = 100 at source warehouse, no pipeline.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        // Draft succeeds because available=100 >= requested=60.
        $transfer = $this->transferService->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            [['product_id' => $productId, 'qty' => 60, 'rate' => 10]],
        ));

        $this->assertNotNull($transfer->id);
        $this->assertSame('draft', $transfer->status);

        // Simulate stock depletion: reduce physical stock to 40.
        // (Another transfer or sales consumed 60 units between draft and confirm.)
        DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->update(['qty' => 40, 'total_qty' => 40, 'total_value' => 400, 'updated_at' => now()]);

        // Confirm should fail because available=40 < requested=60.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient available stock');

        $this->transferService->confirmTransfer($transfer->id, auth()->id());
    }
}
