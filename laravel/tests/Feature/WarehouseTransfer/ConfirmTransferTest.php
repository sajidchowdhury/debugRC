<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Models\WarehouseTransfer;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — feature tests for WarehouseTransferService::confirmTransfer().
 *
 * Covers:
 *   - Confirm draft → status=confirmed, stock movements created (source OUT
 *     + dest IN).
 *   - Confirm non-draft (cancelled/confirmed) → throws RuntimeException.
 *   - Double confirm → throws RuntimeException.
 *   - Insufficient stock at confirm time (stock changed between draft and
 *     confirm) → throws RuntimeException.
 *   - Same-branch confirmed transfer: journal_entry_id is NULL (no GL).
 *   - Stock movements have correct qty and rate.
 *   - Destination warehouse_stock.avg_cost recalculated correctly after
 *     receiving stock at source avg_cost.
 *
 * The service is resolved from the container in setUp() so constructor
 * dependencies wire up automatically.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown.
 */
class ConfirmTransferTest extends TestCase
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
     * Helper: create a draft transfer with sufficient stock at source and
     * optionally confirm it. Returns the WarehouseTransfer model.
     *
     * @param bool $confirm  Whether to also confirm the transfer.
     * @param float $sourceQty  How much stock to set up at the source warehouse.
     * @param float $transferQty  How many units to request in the transfer.
     * @param float $sourceAvgCost  avg_cost at source warehouse.
     * @param float $destInitialQty  How much stock already exists at dest.
     * @param float $destInitialAvgCost  avg_cost at dest before transfer.
     * @return WarehouseTransfer
     */
    private function createDraftTransfer(
        bool $confirm = false,
        float $sourceQty = 100,
        float $transferQty = 10,
        float $sourceAvgCost = 25.00,
        float $destInitialQty = 0,
        float $destInitialAvgCost = 0
    ): WarehouseTransfer {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Set up source stock with known avg_cost.
        $this->insertWarehouseStock($fromWhId, $productId, $sourceQty);
        DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->update(['avg_cost' => $sourceAvgCost, 'total_value' => $sourceQty * $sourceAvgCost]);

        // Set up dest stock (if any pre-existing stock).
        if ($destInitialQty > 0) {
            $this->insertWarehouseStock($toWhId, $productId, $destInitialQty);
            DB::table('warehouse_stock')
                ->where('warehouse_id', $toWhId)
                ->where('product_id', $productId)
                ->update(['avg_cost' => $destInitialAvgCost, 'total_value' => $destInitialQty * $destInitialAvgCost]);
        }

        $transfer = $this->service->createTransfer([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Phase 7 confirm test',
            'items'             => [
                ['product_id' => $productId, 'qty' => $transferQty, 'rate' => $sourceAvgCost],
            ],
            'created_by'        => auth()->id(),
        ]);

        if ($confirm) {
            $transfer = $this->service->confirmTransfer($transfer->id, auth()->id());
        }

        return $transfer;
    }

    /**
     * Convenience: create a draft AND confirm it, returning the model.
     */
    private function createConfirmedTransfer(
        float $sourceQty = 100,
        float $transferQty = 10,
        float $sourceAvgCost = 25.00,
        float $destInitialQty = 0,
        float $destInitialAvgCost = 0
    ): WarehouseTransfer {
        return $this->createDraftTransfer(
            confirm: true,
            sourceQty: $sourceQty,
            transferQty: $transferQty,
            sourceAvgCost: $sourceAvgCost,
            destInitialQty: $destInitialQty,
            destInitialAvgCost: $destInitialAvgCost,
        );
    }

    // ------------------------------------------------------------------
    // Test 1: Confirm draft succeeds
    // ------------------------------------------------------------------

    public function test_confirm_draft_succeeds(): void
    {
        $transfer = $this->createConfirmedTransfer();

        $this->assertDatabaseHas('warehouse_transfers', [
            'id'     => $transfer->id,
            'status' => 'confirmed',
        ]);

        // Source OUT stock movement exists.
        $sourceOut = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->first();
        $this->assertNotNull($sourceOut);
        $this->assertSame(-10.0, (float) $sourceOut->qty);

        // Dest IN stock movement exists.
        $destIn = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->first();
        $this->assertNotNull($destIn);
        $this->assertSame(10.0, (float) $destIn->qty);

        // Audit log for confirmation.
        $this->assertDatabaseHas('user_audit_log', [
            'action'  => 'transfer_confirmed',
            'user_id' => auth()->id(),
        ]);
    }

    // ------------------------------------------------------------------
    // Test 2: Confirm a cancelled transfer fails
    // ------------------------------------------------------------------

    public function test_confirm_non_draft_fails(): void
    {
        $transfer = $this->createDraftTransfer(confirm: false);

        // Manually cancel the transfer (bypass service to avoid stock reversal complexity).
        DB::table('warehouse_transfers')
            ->where('id', $transfer->id)
            ->update(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only draft');
        $this->service->confirmTransfer($transfer->id, auth()->id());
    }

    // ------------------------------------------------------------------
    // Test 3: Double confirm fails
    // ------------------------------------------------------------------

    public function test_confirm_already_confirmed_fails(): void
    {
        $transfer = $this->createConfirmedTransfer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only draft');
        $this->service->confirmTransfer($transfer->id, auth()->id());
    }

    // ------------------------------------------------------------------
    // Test 4: Insufficient stock at confirm time fails
    // ------------------------------------------------------------------

    public function test_confirm_with_insufficient_stock_fails(): void
    {
        // Create draft with 100 units available at source, requesting 10.
        $transfer = $this->createDraftTransfer(
            confirm: false,
            sourceQty: 100,
            transferQty: 10,
        );

        // Drain source stock between draft creation and confirm.
        DB::table('warehouse_stock')
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $transfer->items->first()->product_id)
            ->update(['qty' => 2]);  // Only 2 left — can't confirm 10.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient');
        $this->service->confirmTransfer($transfer->id, auth()->id());
    }

    // ------------------------------------------------------------------
    // Test 5: Same-branch confirmed transfer has no GL journals
    // ------------------------------------------------------------------

    public function test_confirm_same_branch_has_no_gl_journals(): void
    {
        $transfer = $this->createConfirmedTransfer();

        $row = DB::table('warehouse_transfers')->where('id', $transfer->id)->first();
        $this->assertNull($row->journal_entry_id);
        $this->assertNull($row->journal_entry_id_debtor);
    }

    // ------------------------------------------------------------------
    // Test 6: Stock movements have correct qty and rate
    // ------------------------------------------------------------------

    public function test_confirm_creates_stock_movements_with_correct_qty_and_rate(): void
    {
        $transfer = $this->createConfirmedTransfer(
            sourceQty: 100,
            transferQty: 15,
            sourceAvgCost: 30.00,
        );

        $productId = $transfer->items->first()->product_id;

        // Source OUT: qty=-15, rate=30.00 (at source avg_cost).
        $sourceOut = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $productId)
            ->first();
        $this->assertNotNull($sourceOut);
        $this->assertEqualsWithDelta(-15.0, (float) $sourceOut->qty, 0.01);
        $this->assertEqualsWithDelta(30.00, (float) $sourceOut->rate, 0.01);

        // Dest IN: qty=15, rate=30.00 (same avg_cost from source).
        $destIn = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->where('product_id', $productId)
            ->first();
        $this->assertNotNull($destIn);
        $this->assertEqualsWithDelta(15.0, (float) $destIn->qty, 0.01);
        $this->assertEqualsWithDelta(30.00, (float) $destIn->rate, 0.01);
    }

    // ------------------------------------------------------------------
    // Test 7: Destination avg_cost recalculated correctly
    // ------------------------------------------------------------------

    public function test_confirm_updates_destination_avg_cost(): void
    {
        // Source: 100 units @ avg_cost=20.00 → transfer 10 units
        // Dest starts with 50 units @ avg_cost=15.00
        // After confirm, dest should have:
        //   qty  = 50 + 10 = 60
        //   avg  = (50*15 + 10*20) / 60 = (750 + 200) / 60 = 950/60 ≈ 15.8333
        $transfer = $this->createConfirmedTransfer(
            sourceQty: 100,
            transferQty: 10,
            sourceAvgCost: 20.00,
            destInitialQty: 50,
            destInitialAvgCost: 15.00,
        );

        $productId = $transfer->items->first()->product_id;

        $destStock = DB::table('warehouse_stock')
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->where('product_id', $productId)
            ->first();

        $this->assertEqualsWithDelta(60.0, (float) $destStock->qty, 0.01);
        $this->assertEqualsWithDelta(15.8333, (float) $destStock->avg_cost, 0.01);

        // Source should have lost 10 units, avg_cost unchanged (OUT doesn't change avg).
        $sourceStock = DB::table('warehouse_stock')
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $productId)
            ->first();

        $this->assertEqualsWithDelta(90.0, (float) $sourceStock->qty, 0.01);
        $this->assertEqualsWithDelta(20.00, (float) $sourceStock->avg_cost, 0.01);
    }
}
