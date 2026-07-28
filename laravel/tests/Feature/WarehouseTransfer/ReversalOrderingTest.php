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
 * Phase 7 — feature tests for WarehouseTransferService reversal ordering.
 *
 * Phase 3 introduced safe reversal ordering: when cancelling a confirmed
 * transfer, dest IN (positive qty) movements are reversed FIRST, then
 * source OUT (negative qty). This prevents "insufficient stock at receiver"
 * errors during reversal.
 *
 * Covers:
 *   - When cancelling a confirmed transfer, the reversal stock_transactions
 *     for dest IN (qty>0 reversal) are created BEFORE source OUT (qty<0
 *     reversal). Verified by checking the ID sequence of reversal
 *     stock_transactions — dest IN reversal has a lower ID (created first).
 *   - After cancellation, source warehouse gets stock back and destination
 *     warehouse loses the transferred stock (stock integrity restored).
 *
 * The service uses sortMovementsForReversal() internally, which sorts
 * positive-qty (dest IN) movements before negative-qty (source OUT). The
 * StockService::reverseTransaction() processes them in that order, so the
 * resulting reversal stock_transactions follow the same sequence (lower ID =
 * created earlier in the transaction).
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown, leaving the test DB pristine.
 */
class ReversalOrderingTest extends TestCase
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
        // Dest starts with zero.
        $this->insertWarehouseStock($toWhId, $productId, 0);

        $transfer = $this->service->createTransfer([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'Reversal ordering test',
            'items'             => [
                ['product_id' => $productId, 'qty' => $qty, 'rate' => $rate],
            ],
            'created_by'        => auth()->id(),
        ]);

        $this->service->confirmTransfer($transfer->id, auth()->id());

        return [$transfer->id, $fromWhId, $toWhId, $productId, $branch->id];
    }

    // ------------------------------------------------------------------
    // Test 1: Dest IN movements reversed before source OUT
    // ------------------------------------------------------------------

    public function test_dest_in_movements_reversed_before_source_out(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createConfirmedTransfer();

        // Record the original (confirm-phase) stock_transactions.
        // Confirm creates: source OUT (qty=-10) then dest IN (qty=+10).
        $originalTxs = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transferId)
            ->where('is_reversed', false)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $originalTxs, 'Confirm should create 2 stock_transactions');

        // Identify source OUT (negative qty) and dest IN (positive qty).
        $sourceOutTx = $originalTxs->first(function ($tx) {
            return (float) $tx->qty < 0 && (int) $tx->warehouse_id === $fromWhId;
        });
        $destInTx = $originalTxs->first(function ($tx) {
            return (float) $tx->qty > 0 && (int) $tx->warehouse_id === $toWhId;
        });

        $this->assertNotNull($sourceOutTx, 'Source OUT transaction should exist');
        $this->assertNotNull($destInTx, 'Dest IN transaction should exist');

        // Cancel the confirmed transfer.
        $this->service->cancelTransfer($transferId, auth()->id(), 'Reversal ordering verification');

        // Fetch the reversal stock_transactions (reference_type='reversal').
        // Each reversal references its original transaction via reference_id.
        $reversalTxs = DB::table('stock_transactions')
            ->where('reference_type', 'reversal')
            ->whereIn('reference_id', [$sourceOutTx->id, $destInTx->id])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $reversalTxs, 'Cancel should create 2 reversal transactions');

        // The reversal of dest IN (reference_id = destInTx.id) should have a
        // LOWER ID than the reversal of source OUT (reference_id = sourceOutTx.id).
        // Lower ID = created first in the DB transaction = reversed first.
        $destInReversal = $reversalTxs->first(function ($tx) use ($destInTx) {
            return (int) $tx->reference_id === (int) $destInTx->id;
        });
        $sourceOutReversal = $reversalTxs->first(function ($tx) use ($sourceOutTx) {
            return (int) $tx->reference_id === (int) $sourceOutTx->id;
        });

        $this->assertNotNull($destInReversal, 'Dest IN reversal transaction should exist');
        $this->assertNotNull($sourceOutReversal, 'Source OUT reversal transaction should exist');

        // Verify ordering: dest IN reversal created BEFORE source OUT reversal.
        $this->assertLessThan(
            (int) $sourceOutReversal->id,
            (int) $destInReversal->id,
            'Dest IN reversal should have a lower ID (created before source OUT reversal)'
        );

        // Also verify the reversal qty signs match expectations:
        // - Dest IN reversal: qty should be negative (undoing the positive IN)
        // - Source OUT reversal: qty should be positive (undoing the negative OUT)
        $this->assertLessThan(0, (float) $destInReversal->qty,
            'Dest IN reversal qty should be negative (undoing +10)');
        $this->assertGreaterThan(0, (float) $sourceOutReversal->qty,
            'Source OUT reversal qty should be positive (undoing -10)');
    }

    // ------------------------------------------------------------------
    // Test 2: Reversal restores stock at both warehouses
    // ------------------------------------------------------------------

    public function test_reversal_restores_stock_at_both_warehouses(): void
    {
        [$transferId, $fromWhId, $toWhId, $productId, $branchId] = $this->createConfirmedTransfer();

        // After confirm: source=90, dest=10.
        $sourceQtyAfterConfirm = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(90, $sourceQtyAfterConfirm, 0.0001,
            'Source should be 90 after confirm (100 - 10)');

        $destQtyAfterConfirm = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $toWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(10, $destQtyAfterConfirm, 0.0001,
            'Dest should be 10 after confirm (0 + 10)');

        // Cancel the confirmed transfer.
        $this->service->cancelTransfer($transferId, auth()->id(), 'Stock restoration test');

        // After reversal: source gets stock back (100), dest loses transferred stock (0).
        $sourceQtyAfterCancel = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $fromWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(100, $sourceQtyAfterCancel, 0.0001,
            'Source should be restored to 100 after reversal');

        $destQtyAfterCancel = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $toWhId)
            ->where('product_id', $productId)
            ->value('qty');
        $this->assertEqualsWithDelta(0, $destQtyAfterCancel, 0.0001,
            'Dest should be back to 0 after reversal');
    }
}
