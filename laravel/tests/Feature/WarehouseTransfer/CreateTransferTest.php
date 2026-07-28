<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — feature tests for WarehouseTransferService::createTransfer().
 *
 * Covers:
 *   - Draft creation with same-branch warehouses succeeds (status=draft,
 *     no stock movements, audit log written).
 *   - Cross-branch warehouse rejection (InvalidArgumentException with
 *     "same branch" message).
 *   - Same from/to warehouse rejection (InvalidArgumentException).
 *   - Empty items array rejection (InvalidArgumentException "At least one
 *     valid item").
 *   - Zero-qty items rejection (InvalidArgumentException).
 *   - Rate auto-fill from warehouse avg_cost when rate=0.
 *   - Insufficient stock at source (RuntimeException or InvalidArgumentException).
 *   - Frozen source warehouse blocks creation (RuntimeException).
 *
 * The service is resolved from the container in setUp() so constructor
 * dependencies (StockService, StockAvailabilityService, JournalPostingService,
 * WarehouseTransferAuditLogger) wire up automatically.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown, leaving the test DB pristine.
 */
class CreateTransferTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(WarehouseTransferService::class);
    }

    /**
     * Build the standard createTransfer payload for a same-branch transfer
     * with one product line. Caller can override any key.
     */
    private function basePayload(int $fromWhId, int $toWhId, array $overrides = []): array
    {
        $productId = $this->insertProduct();

        return array_merge([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Phase 7 test transfer',
            'items'             => [
                ['product_id' => $productId, 'qty' => 5, 'rate' => 10.00],
            ],
            'created_by'        => auth()->id(),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Test 1: Draft creation with valid same-branch warehouses succeeds
    // ------------------------------------------------------------------

    public function test_create_draft_with_same_branch_warehouses_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Stock at source so availability check passes.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        $transfer = $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => [['product_id' => $productId, 'qty' => 10, 'rate' => 10.00]]]
        ));

        $this->assertNotNull($transfer->id);
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'             => $transfer->id,
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'status'         => 'draft',
            'is_reversed'    => false,
        ]);

        // No stock movements should exist for a draft.
        $this->assertSame(0, DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->count());

        // Audit log written.
        $this->assertDatabaseHas('user_audit_log', [
            'action'  => 'transfer_created',
            'user_id' => auth()->id(),
        ]);

        // Verify the audit details payload contains the transfer info.
        $auditRow = DB::table('user_audit_log')
            ->where('action', 'transfer_created')
            ->where('user_id', auth()->id())
            ->first();
        $details = json_decode($auditRow->details, true);
        $this->assertSame($transfer->id, $details['transfer_id']);
        $this->assertSame('draft', $details['status']);
    }

    // ------------------------------------------------------------------
    // Test 2: Cross-branch warehouses rejected
    // ------------------------------------------------------------------

    public function test_create_draft_with_cross_branch_warehouses_fails(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branchA->id);
        $toWhId   = $this->insertWarehouse($branchB->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same branch');
        $this->service->createTransfer($this->basePayload($fromWhId, $toWhId));
    }

    // ------------------------------------------------------------------
    // Test 3: Same warehouse for both from and to rejected
    // ------------------------------------------------------------------

    public function test_create_draft_with_same_from_to_warehouse_fails(): void
    {
        $branch = Branch::factory()->create();
        $whId   = $this->insertWarehouse($branch->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be different');
        $this->service->createTransfer($this->basePayload($whId, $whId));
    }

    // ------------------------------------------------------------------
    // Test 4: Empty items array rejected
    // ------------------------------------------------------------------

    public function test_create_draft_with_no_items_fails(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one');
        $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => []]
        ));
    }

    // ------------------------------------------------------------------
    // Test 5: Zero-qty items rejected (all items have qty=0 → empty validatedItems)
    // ------------------------------------------------------------------

    public function test_create_draft_with_zero_qty_items_fails(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // All items with qty=0 — service skips them, validatedItems becomes empty.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one valid item');
        $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => [['product_id' => $productId, 'qty' => 0, 'rate' => 10.00]]]
        ));
    }

    // ------------------------------------------------------------------
    // Test 6: Rate auto-filled from warehouse avg_cost when rate=0
    // ------------------------------------------------------------------

    public function test_create_draft_rate_auto_fill_from_avg_cost(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Set up stock at source with a known avg_cost of 25.00.
        $this->insertWarehouseStock($fromWhId, $productId, 100);
        DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->update(['avg_cost' => 25.00]);

        // Create transfer with rate=0 — service should auto-fill from avg_cost.
        $transfer = $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => [['product_id' => $productId, 'qty' => 10, 'rate' => 0]]]
        ));

        // Verify the item row has rate = 25.00 (auto-filled from avg_cost).
        $itemRow = DB::table('warehouse_transfer_items')
            ->where('warehouse_transfer_id', $transfer->id)
            ->where('product_id', $productId)
            ->first();

        $this->assertNotNull($itemRow);
        $this->assertEqualsWithDelta(25.00, (float) $itemRow->rate, 0.01);
    }

    // ------------------------------------------------------------------
    // Test 7: Insufficient stock at source rejected
    // ------------------------------------------------------------------

    public function test_create_draft_with_insufficient_stock_fails(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Only 3 units at source — requesting 10 should fail.
        $this->insertWarehouseStock($fromWhId, $productId, 3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient');
        $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => [['product_id' => $productId, 'qty' => 10, 'rate' => 10.00]]]
        ));
    }

    // ------------------------------------------------------------------
    // Test 8: Frozen source warehouse blocks creation
    // ------------------------------------------------------------------

    public function test_create_draft_with_frozen_source_warehouse_fails(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Set up stock at source.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        // Freeze the source warehouse.
        DB::table('warehouses')
            ->where('id', $fromWhId)
            ->update(['is_frozen_for_count' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('frozen');
        $this->service->createTransfer($this->basePayload(
            $fromWhId, $toWhId,
            ['items' => [['product_id' => $productId, 'qty' => 10, 'rate' => 10.00]]]
        ));
    }
}
