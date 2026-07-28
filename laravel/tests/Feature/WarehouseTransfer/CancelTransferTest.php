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
 * Phase 7 — feature tests for WarehouseTransferService::cancelTransfer().
 *
 * Covers:
 *   - Cancel a draft → status=cancelled, NO stock movements created,
 *     NO stock changes (draft has no stock impact).
 *   - Cancel a confirmed transfer → stock reversed (dest IN then source OUT),
 *     is_reversed=true, status=cancelled.
 *   - Cancel already cancelled → RuntimeException "Transfer is already cancelled."
 *   - Empty reason for confirmed transfer → RuntimeException
 *     "A cancellation reason is required".
 *   - Transfer with branch_demand_id cannot be cancelled → RuntimeException
 *     (demand-linked reversal protection).
 *   - Reversal columns written on confirmed cancel: is_reversed=true,
 *     reversed_at not null, reversed_by set, reverse_reason stored.
 *
 * The service is resolved from the container in setUp() so constructor
 * dependencies (StockService, StockAvailabilityService, JournalPostingService,
 * WarehouseTransferAuditLogger) wire up automatically.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown, leaving the test DB pristine.
 */
class CancelTransferTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(WarehouseTransferService::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a draft warehouse transfer (same-branch, 1 item, qty=10).
     * Returns [$transferId, $fromWhId, $toWhId, $productId, $branchId].
     */
    private function createDraftTransfer(float $qty = 10, float $rate = 10.00): array
    {
        $branch    = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Stock at source so availability check passes.
        $this->insertWarehouseStock($fromWhId, $productId, $qty + 50);

        $transfer = $this->service->createTransfer([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Cancel test draft',
            'items'             => [
                ['product_id' => $productId, 'qty' => $qty, 'rate' => $rate],
            ],
            'created_by'        => auth()->id(),
        ]);

        return [$transfer->id, $fromWhId, $toWhId, $productId, $branch->id];
    }

    /**
     * Create a confirmed warehouse transfer (same-branch, 1 item, qty=10).
     * Returns [$transferId, $fromWhId, $toWhId, $productId, $branchId].
     *
     * Source starts with 100, dest starts with 0.
     * After confirm: source=90, dest=10.
     */
    private function createConfirmedTransfer(float $qty = 10, float $rate = 10.00): array
    {
        $branch    = Branch::factory()->create();
        $fromWhId  = $this->insertWarehouse($branch->id);
        $toWhId    = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Source has enough stock for the transfer.
        $this->insertWarehouseStock($fromWhId, $productId, 100);
        // Dest starts with zero — confirms transfer moves qty in.
        $this->insertWarehouseStock($toWhId, $productId, 0);

        $transfer = $this->service->createTransfer([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Cancel test confirmed',
            'items'             => [
                ['product_id' => $productId, 'qty' => $qty, 'rate' => $rate],
            ],
            'created_by'        => auth()->id(),
        ]);

        $this->service->confirmTransfer($transfer->id, auth()->id());

        return [$transfer->id, $fromWhId, $toWhId, $productId, $branch->id];
    }

    // ------------------------------------------------------------------
    // Test 1: Cancel draft succeeds — no stock movements, no stock changes
    // ------------------------------------------------------------------

    public function test_cancel_draft_succeeds(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createDraftTransfer();

        // Pre-condition: source stock is unchanged (draft doesn't move stock).
        $sourceQtyBefore = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');

        $this->service->cancelTransfer($transferId, auth()->id(), 'Draft cancelled — no longer needed');

        // Status is cancelled.
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'     => $transferId,
            'status' => 'cancelled',
        ]);

        // No stock movements created for a draft cancellation.
        $movementCount = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transferId)
            ->count();
        $this->assertSame(0, $movementCount);

        // Source stock unchanged.
        $sourceQtyAfter = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta($sourceQtyBefore, $sourceQtyAfter, 0.0001);
    }

    // ------------------------------------------------------------------
    // Test 2: Cancel confirmed transfer succeeds — stock reversed,
    //         is_reversed=true, status=cancelled
    // ------------------------------------------------------------------

    public function test_cancel_confirmed_transfer_succeeds(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createConfirmedTransfer();

        // Pre-condition: after confirm, source=90, dest=10.
        $sourceQtyAfterConfirm = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(90, $sourceQtyAfterConfirm, 0.0001);

        $destQtyAfterConfirm = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $toWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(10, $destQtyAfterConfirm, 0.0001);

        // Cancel the confirmed transfer with a reason.
        $this->service->cancelTransfer($transferId, auth()->id(), 'Wrong product shipped');

        // Status is cancelled.
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'     => $transferId,
            'status' => 'cancelled',
        ]);

        // is_reversed=true on the transfer row.
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'          => $transferId,
            'is_reversed' => true,
        ]);

        // Source stock restored to 100 (original).
        $sourceQtyAfterCancel = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(100, $sourceQtyAfterCancel, 0.0001);

        // Dest stock back to 0 (original).
        $destQtyAfterCancel = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $toWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(0, $destQtyAfterCancel, 0.0001);
    }

    // ------------------------------------------------------------------
    // Test 3: Cancel already cancelled transfer fails
    // ------------------------------------------------------------------

    public function test_cancel_already_cancelled_fails(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createDraftTransfer();

        // Cancel once — succeeds.
        $this->service->cancelTransfer($transferId, auth()->id(), 'First cancel');

        // Second cancel — should throw.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transfer is already cancelled');
        $this->service->cancelTransfer($transferId, auth()->id(), 'Second cancel attempt');
    }

    // ------------------------------------------------------------------
    // Test 4: Empty reason for confirmed transfer fails
    // ------------------------------------------------------------------

    public function test_cancel_requires_reason_for_confirmed_transfer(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createConfirmedTransfer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A cancellation reason is required');
        $this->service->cancelTransfer($transferId, auth()->id(), '');
    }

    // ------------------------------------------------------------------
    // Test 5: Demand-linked transfer cannot be cancelled
    // ------------------------------------------------------------------

    public function test_cancel_demand_linked_transfer_fails(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createDraftTransfer();

        // Simulate a demand link: create a branch_demand and set
        // branch_demand_id on the transfer.
        $demandId = $this->insertBranchDemand($branchId, $branchId);

        DB::table('warehouse_transfers')
            ->where('id', $transferId)
            ->update(['branch_demand_id' => $demandId]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('linked to a branch demand');
        $this->service->cancelTransfer($transferId, auth()->id(), 'Should fail — demand linked');
    }

    // ------------------------------------------------------------------
    // Test 6: Cancel confirmed writes reversal columns
    // ------------------------------------------------------------------

    public function test_cancel_confirmed_writes_reversal_columns(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createConfirmedTransfer();

        $canceller = $this->makeRoleUser('admin');
        $reason    = 'Reversal columns test ' . uniqid();
        $this->service->cancelTransfer($transferId, $canceller->id, $reason);

        $row = DB::table('warehouse_transfers')->where('id', $transferId)->first();

        $this->assertTrue((bool) $row->is_reversed, 'is_reversed should be true');
        $this->assertNotNull($row->reversed_at, 'reversed_at should not be null');
        $this->assertSame($canceller->id, (int) $row->reversed_by, 'reversed_by should match canceller');
        $this->assertSame($reason, $row->reverse_reason, 'reverse_reason should match the provided reason');
    }
}
