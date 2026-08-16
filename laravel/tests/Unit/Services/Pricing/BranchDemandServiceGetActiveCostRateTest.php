<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\Branch;
use App\Services\BranchDemand\BranchDemandService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * BranchDemandService::getActiveCostRate() Unit Tests — Session 10.
 *
 * Verifies the S9 fix to the S5 cost-snapshot bug.
 *
 * Bug history: S5 filtered on `bd.to_branch_id = $branchId` — but per
 * the codebase convention (BranchDemandService class docblock L42-43 +
 * confirmReceipt() L631), `to_branch_id` is the SUPPLIER, not the
 * receiver. So the method was returning the cost OTHER branches paid
 * when THIS branch supplied them — the wrong cost for the selling
 * branch's own sales.
 *
 * S9 corrected the filter to `bdi.receiving_branch_id = $branchId`
 * (the S7-denormalized column == `from_branch_id` == the receiver)
 * and added `bdi.consumed_qty < bdi.qty` open-qty filter (proper FIFO
 * semantics, now possible thanks to S7's `consumed_qty` column).
 *
 * These tests confirm:
 *   - The method returns the demand item's cost_rate when the branch
 *     is the RECEIVER (from_branch_id matches $branchId).
 *   - The method returns NULL when the branch is the SUPPLIER
 *     (to_branch_id matches $branchId) — the bug would have returned
 *     a cost here.
 *   - The method returns NULL when the demand item is fully consumed
 *     (consumed_qty = qty) — the S9 open-qty filter.
 *   - The method returns the OLDEST open demand item's cost (FIFO).
 *
 * @see \App\Services\BranchDemand\BranchDemandService::getActiveCostRate()
 *      (S9 fix)
 * @see database/migrations/2026_10_18_000007_add_consumed_qty_to_branch_demand_items.php
 *      (S7 added receiving_branch_id + consumed_qty)
 * @see database/migrations/2026_10_18_000009_audit_and_correct_historical_cost_snapshots.php
 *      (S9 historical correction)
 * @see docs/IMPLEMENTATION_PLAN_SESSION9_CONFIRMATION.md
 */
class BranchDemandServiceGetActiveCostRateTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsProductDependencies;

    private BranchDemandService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BranchDemandService::class);
    }

    /**
     * Insert a product with the minimum required columns.
     * The product helpers in InsertsProductDependencies don't include
     * a product-row insert (only categories/groups/stock). We add one
     * here for the test's local needs.
     */
    private function insertProduct(float $purchaseRate = 5.0): int
    {
        $categoryId = $this->insertProductCategory();
        $groupId    = $this->insertProductGroup();

        return DB::table('products')->insertGetId([
            'product_code'  => 'P-' . substr(uniqid(), -6),
            'product_name'  => 'Test Product ' . uniqid(),
            'category_id'   => $categoryId,
            'group_id'      => $groupId,
            'unit'          => 'Pcs',  // CHECK (unit IN ('Pcs','Carton','KG','Bag','Dobe','Set'))
            'purchase_rate' => $purchaseRate,
            'sales_rate'    => $purchaseRate * 1.2,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Helper: insert a received demand A→B (A=from/requester/receiver,
     * B=to/supplier) with one item at the given cost_rate + qty.
     *
     * Returns the demand item id (with receiving_branch_id backfilled
     * via the migration's pattern — we set it directly here because
     * the migration only backfills once at migrate time, not on test
     * inserts).
     */
    private function insertReceivedDemandWithItem(
        int $fromBranchId,
        int $toBranchId,
        int $productId,
        float $qty,
        float $costRate,
        string $demandDate = null,
    ): int {
        $demandId = $this->insertBranchDemand($fromBranchId, $toBranchId, 'received');
        if ($demandDate) {
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->update(['demand_date' => $demandDate]);
        }

        $itemId = $this->insertBranchDemandItem($demandId, $productId, $qty, $costRate);

        // Set receiving_branch_id (denormalized column added in S7 —
        // equals from_branch_id). Test helpers don't set this because
        // they pre-date S7.
        DB::table('branch_demand_items')
            ->where('id', $itemId)
            ->update(['receiving_branch_id' => $fromBranchId]);

        return $itemId;
    }

    // ===================== RECEIVER returns the cost =====================

    /**
     * Happy path: branch B received goods from branch A. Branch B
     * asks "what's my active cost for this product?" — must get the
     * demand's cost_rate.
     */
    public function test_returns_demand_cost_rate_for_receiving_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();
        $demandCost = 12.50;

        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,    // B is the receiver
            toBranchId:   $branchA->id,    // A is the supplier
            productId:    $productId,
            qty:           10.0,
            costRate:      $demandCost,
        );

        $result = $this->service->getActiveCostRate($branchB->id, $productId);

        $this->assertNotNull($result, 'Receiver must get a cost from the open demand item.');
        $this->assertEquals($demandCost, $result, 'Cost must match the demand item cost_rate.');
    }

    // ===================== SUPPLIER returns NULL (the bug) =====================

    /**
     * THE BUG-CATCHER: if S5's `to_branch_id` filter were still in
     * place, this test would FAIL — branch A would get branch B's
     * cost returned, instead of NULL.
     *
     * After S9, branch A (the supplier) gets NULL because A has no
     * DEMAND where A is the RECEIVER (from_branch_id).
     */
    public function test_returns_null_for_supplying_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();
        $demandCost = 12.50;

        // Demand B → A: B is receiver, A is supplier.
        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,
            toBranchId:   $branchA->id,
            productId:    $productId,
            qty:           10.0,
            costRate:      $demandCost,
        );

        // Branch A asks "what's my active cost?" — A is the SUPPLIER,
        // not the receiver. A never received these goods. A has no
        // demand where A is from_branch_id. Must return NULL.
        $result = $this->service->getActiveCostRate($branchA->id, $productId);

        $this->assertNull(
            $result,
            'Supplier branch must NOT receive a cost from a demand where it was the supplier. ' .
            'If this fails, the S9 fix has been reverted — check that the filter is on ' .
            'bdi.receiving_branch_id (= from_branch_id), NOT bd.to_branch_id.'
        );
    }

    // ===================== Fully consumed → NULL =====================

    /**
     * S9 added the `consumed_qty < qty` open-qty filter. If a demand
     * item is fully consumed (consumed_qty = qty), it should NOT be
     * returned — the method must fall back to NULL or the next open
     * demand item.
     */
    public function test_returns_null_when_demand_item_fully_consumed(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $itemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,
            toBranchId:   $branchA->id,
            productId:    $productId,
            qty:           10.0,
            costRate:      12.50,
        );

        // Mark the item as fully consumed.
        DB::table('branch_demand_items')
            ->where('id', $itemId)
            ->update([
                'consumed_qty'            => 10.0,
                'consumed_qty_updated_at' => now(),
            ]);

        $result = $this->service->getActiveCostRate($branchB->id, $productId);

        $this->assertNull(
            $result,
            'Fully consumed demand item must NOT be returned. ' .
            'If this fails, the S9 consumed_qty < qty open-qty filter is missing.'
        );
    }

    // ===================== FIFO oldest wins =====================

    /**
     * FIFO: among multiple open demand items for the same branch+product,
     * the OLDEST (lowest demand_date, then lowest id) must be returned.
     */
    public function test_returns_oldest_open_demand_item_cost_fifo(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        // OLDER demand (cost 10) — should be picked.
        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,
            toBranchId:   $branchA->id,
            productId:    $productId,
            qty:           10.0,
            costRate:      10.00,
            demandDate:    '2025-01-15',
        );

        // NEWER demand (cost 15) — should NOT be picked while the
        // older one still has open qty.
        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,
            toBranchId:   $branchA->id,
            productId:    $productId,
            qty:           10.0,
            costRate:      15.00,
            demandDate:    '2025-02-15',
        );

        $result = $this->service->getActiveCostRate($branchB->id, $productId);

        $this->assertNotNull($result);
        $this->assertEquals(10.00, $result, 'FIFO must return the OLDEST open demand item cost.');
    }

    // ===================== Reversed demand excluded =====================

    /**
     * S9 added `bd.is_reversed = false` — reversed demands should not
     * contribute cost even if their items still show open qty.
     */
    public function test_excludes_reversed_demand(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $demandId = $this->insertBranchDemand($branchB->id, $branchA->id, 'received');
        DB::table('branch_demands')
            ->where('id', $demandId)
            ->update(['is_reversed' => true, 'status' => 'reversed']);

        $itemId = $this->insertBranchDemandItem($demandId, $productId, 10.0, 12.50);
        DB::table('branch_demand_items')
            ->where('id', $itemId)
            ->update(['receiving_branch_id' => $branchB->id]);

        $result = $this->service->getActiveCostRate($branchB->id, $productId);

        $this->assertNull(
            $result,
            'Reversed demand must NOT contribute cost. ' .
            'If this fails, the S9 is_reversed = false filter is missing.'
        );
    }

    // ===================== No demand → NULL (no regression) =====================

    /**
     * Sanity: branch with no demands at all gets NULL. Caller falls
     * back to products.purchase_rate.
     */
    public function test_returns_null_when_no_demand_exists(): void
    {
        $branch = Branch::factory()->create();
        $productId = $this->insertProduct();

        $result = $this->service->getActiveCostRate($branch->id, $productId);

        $this->assertNull($result, 'No demand → NULL (caller falls back to products.purchase_rate).');
    }

    // ===================== Zero-cost demand excluded =====================

    /**
     * `cost_rate > 0` filter — zero-cost snapshots (rare, happens when
     * supplier's avg_cost was zero at send time) are skipped.
     */
    public function test_excludes_zero_cost_demand_item(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id,
            toBranchId:   $branchA->id,
            productId:    $productId,
            qty:           10.0,
            costRate:      0.00,  // zero cost — should be skipped
        );

        $result = $this->service->getActiveCostRate($branchB->id, $productId);

        $this->assertNull($result, 'Zero-cost demand item must be skipped (cost_rate > 0 filter).');
    }
}
