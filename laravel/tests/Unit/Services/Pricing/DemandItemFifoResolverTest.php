<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\Branch;
use App\Services\DemandItemFifoResolver;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * DemandItemFifoResolver Unit Tests — Session 10.
 *
 * Verifies the S7 FIFO consume/release logic:
 *
 *   - consume() picks the OLDEST open demand item first (FIFO).
 *   - consume() SPLITS across multiple demand items when the oldest
 *     doesn't have enough remaining qty.
 *   - consume() returns [] when total open qty is insufficient (no
 *     silent over-consumption).
 *   - consume() is idempotent on qty=0 (returns [] without DB write).
 *   - release() decrements consumed_qty on the linked demand item.
 *   - release() is capped at the original sale line qty (never
 *     over-releases).
 *   - release() is a no-op when the sale line has no
 *     branch_demand_item_id link (direct supplier purchase).
 *
 * The risk register (S7 row) flags "FIFO consumed_qty race condition
 * under concurrent sales" as a Medium-likelihood / High-impact risk,
 * with mitigation: "DemandItemFifoResolver::consume() uses
 * SELECT ... FOR UPDATE inside a DB transaction. Add a concurrent-
 * finalize integration test."
 *
 * The concurrent-finalize test is harder to write in PHPUnit (requires
 * spawning parallel processes or DB::beginTransaction() tricks). This
 * file covers the SEQUENTIAL correctness tests. A follow-up
 * integration test for concurrency can be added later if needed.
 *
 * @see \App\Services\DemandItemFifoResolver
 * @see database/migrations/2026_10_18_000007_add_consumed_qty_to_branch_demand_items.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md
 *      Risk Register row "FIFO consumed_qty race condition..."
 */
class DemandItemFifoResolverTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsProductDependencies;

    private DemandItemFifoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->app->make(DemandItemFifoResolver::class);
    }

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
     * Insert a received demand A→B with one item. Returns the item id.
     *
     * Sets `receiving_branch_id` (= from_branch_id) on the item, which
     * is what the FIFO resolver's WHERE clause filters on.
     */
    private function insertReceivedDemandWithItem(
        int $fromBranchId,
        int $toBranchId,
        int $productId,
        float $qty,
        float $costRate,
        string $demandDate = '2025-01-15',
        float $consumedQty = 0.0,
    ): int {
        $demandId = $this->insertBranchDemand($fromBranchId, $toBranchId, 'received');
        DB::table('branch_demands')
            ->where('id', $demandId)
            ->update(['demand_date' => $demandDate]);

        $itemId = $this->insertBranchDemandItem($demandId, $productId, $qty, $costRate);
        DB::table('branch_demand_items')
            ->where('id', $itemId)
            ->update([
                'receiving_branch_id'    => $fromBranchId,
                'consumed_qty'           => $consumedQty,
            ]);

        return $itemId;
    }

    /**
     * Insert a sales_invoice_items row (minimal columns). Used by
     * release() tests — release() looks up the sale line by id to
     * find its branch_demand_item_id + qty.
     *
     * Builds the minimum chain: branch → customer → sales_invoice →
     * sales_invoice_item. The sales_invoices schema has NOT NULL
     * customer_id + branch_id columns; we satisfy them via a
     * factory-created branch + a customers row insert.
     */
    private function insertSalesInvoiceItem(int $productId, float $qty, ?int $branchDemandItemId = null): int
    {
        $branch = Branch::factory()->create();

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUST-FIFO-' . substr(uniqid(), -8),
            'customer_name' => 'FIFO Test Customer',
            'branch_id'     => $branch->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // sales_invoice_items requires a sales_invoice_id (trigger-enforced
        // FK). Insert a minimal sales_invoices row first.
        //
        // S1 (FY isolation) added NOT NULL `fiscal_year_id` to sales_invoices
        // (config/fiscal.php line 40). The insert MUST set it; otherwise
        // SQLSTATE[23502]: null value in column "fiscal_year_id" of relation
        // "sales_invoices_default" violates not-null constraint.
        $siId = DB::table('sales_invoices')->insertGetId([
            'invoice_code'    => 'INV-' . substr(uniqid(), -8),
            'invoice_date'    => now()->toDateString(),
            'customer_id'     => $customerId,
            'branch_id'       => $branch->id,
            'total_amount'    => $qty * 10,
            'status'          => 'confirmed',  // CHECK IN ('draft','confirmed','cancelled','reversed')
            'is_reversed'     => false,
            'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('sales_invoice_items')->insertGetId([
            'sales_invoice_id'        => $siId,
            'product_id'              => $productId,
            'qty'                     => $qty,
            'rate'                    => 12.00,
            'branch_demand_item_id'   => $branchDemandItemId,
        ]);
    }

    // ===================== consume() =====================

    public function test_consume_returns_empty_for_zero_qty(): void
    {
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $result = $this->resolver->consume($branchB->id, $productId, 0.0);

        $this->assertSame([], $result, 'qty=0 must return [] without touching the DB.');
    }

    public function test_consume_picks_oldest_open_demand_item_first(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $olderItemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 10.0,
            demandDate: '2025-01-15',
        );
        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 15.0,
            demandDate: '2025-02-15',
        );

        $result = DB::transaction(fn () => $this->resolver->consume($branchB->id, $productId, 4.0));

        $this->assertCount(1, $result, 'Single demand item should cover the qty.');
        $this->assertSame($olderItemId, $result[0]['demand_item_id'], 'Must pick the OLDEST demand item.');
        $this->assertEquals(4.0, $result[0]['qty']);

        // Verify consumed_qty was bumped.
        $consumed = DB::table('branch_demand_items')->where('id', $olderItemId)->value('consumed_qty');
        $this->assertEquals(4.0, (float) $consumed, 'consumed_qty must be incremented by the consumed amount.');
    }

    public function test_consume_splits_across_multiple_demand_items_when_oldest_insufficient(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $olderItemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 3.0, costRate: 10.0,
            demandDate: '2025-01-15',
        );
        $newerItemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 5.0, costRate: 15.0,
            demandDate: '2025-02-15',
        );

        // Consume 7 — must split: 3 from older, 4 from newer.
        $result = DB::transaction(fn () => $this->resolver->consume($branchB->id, $productId, 7.0));

        $this->assertCount(2, $result, 'Must split across 2 demand items.');
        $this->assertSame($olderItemId, $result[0]['demand_item_id']);
        $this->assertEquals(3.0, $result[0]['qty'], 'Older item must be fully drained (3.0).');
        $this->assertSame($newerItemId, $result[1]['demand_item_id']);
        $this->assertEquals(4.0, $result[1]['qty'], 'Newer item must supply the remaining 4.0.');

        // Verify consumed_qty on both items.
        $this->assertEquals(3.0, (float) DB::table('branch_demand_items')->where('id', $olderItemId)->value('consumed_qty'));
        $this->assertEquals(4.0, (float) DB::table('branch_demand_items')->where('id', $newerItemId)->value('consumed_qty'));
    }

    public function test_consume_returns_empty_when_insufficient_open_qty(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        // Total open qty = 5, but we ask for 10.
        $itemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 5.0, costRate: 10.0,
        );

        $result = DB::transaction(fn () => $this->resolver->consume($branchB->id, $productId, 10.0));

        $this->assertSame([], $result, 'Insufficient open qty must return [] (no over-consumption).');

        // Verify consumed_qty was NOT bumped (rollback inside the transaction).
        //
        // NOTE: must scope to the actual demand item id — an unscoped
        // DB::table('branch_demand_items')->value('consumed_qty') picks an
        // arbitrary row (the table may have stale rows from prior tests if
        // DatabaseTransactions trait is misconfigured or the table has
        // seed data).
        $consumed = DB::table('branch_demand_items')
            ->where('id', $itemId)
            ->value('consumed_qty');
        $this->assertEquals(0.0, (float) $consumed, 'consumed_qty must not change when allocation fails.');
    }

    public function test_consume_skips_fully_consumed_demand_items(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        // Fully-consumed older item — must be skipped.
        $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 10.0,
            demandDate: '2025-01-15',
            consumedQty: 10.0,  // fully consumed
        );
        // Open newer item — must be picked.
        $newerItemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 15.0,
            demandDate: '2025-02-15',
        );

        $result = DB::transaction(fn () => $this->resolver->consume($branchB->id, $productId, 4.0));

        $this->assertCount(1, $result);
        $this->assertSame($newerItemId, $result[0]['demand_item_id'], 'Must skip the fully-consumed item and pick the open one.');
    }

    // ===================== release() =====================

    public function test_release_decrements_consumed_qty_on_linked_demand_item(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $itemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 10.0,
        );

        // Manually mark 4 units as consumed (simulate a prior sale).
        DB::table('branch_demand_items')->where('id', $itemId)->update(['consumed_qty' => 4.0]);

        // Create a sale line that links to this demand item, qty=4.
        $saleLineId = $this->insertSalesInvoiceItem($productId, 4.0, $itemId);

        $result = DB::transaction(fn () => $this->resolver->release($saleLineId));

        $this->assertCount(1, $result);
        $this->assertSame($itemId, $result[0]['demand_item_id']);
        $this->assertEquals(4.0, $result[0]['qty'], 'Must release the full sale line qty.');

        // Verify consumed_qty was decremented back to 0.
        $consumed = DB::table('branch_demand_items')->where('id', $itemId)->value('consumed_qty');
        $this->assertEquals(0.0, (float) $consumed, 'consumed_qty must be decremented by the released amount.');
    }

    public function test_release_is_noop_when_sale_line_has_no_demand_link(): void
    {
        $productId = $this->insertProduct();
        $saleLineId = $this->insertSalesInvoiceItem($productId, 4.0, null);

        $result = DB::transaction(fn () => $this->resolver->release($saleLineId));

        $this->assertSame([], $result, 'Sale line with no demand link must return [] (no-op).');
    }

    public function test_release_capped_at_original_sale_line_qty(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $itemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 10.0,
        );

        // Sale line says qty=3 — release() must cap at 3 even if
        // consumed_qty on the demand item is higher.
        DB::table('branch_demand_items')->where('id', $itemId)->update(['consumed_qty' => 8.0]);
        $saleLineId = $this->insertSalesInvoiceItem($productId, 3.0, $itemId);

        $result = DB::transaction(fn () => $this->resolver->release($saleLineId, 100.0));

        $this->assertCount(1, $result);
        $this->assertEquals(3.0, $result[0]['qty'], 'Must cap at the sale line qty (3.0), not the requested 100.0.');

        $consumed = DB::table('branch_demand_items')->where('id', $itemId)->value('consumed_qty');
        $this->assertEquals(5.0, (float) $consumed, '8.0 - 3.0 = 5.0 remaining consumed.');
    }

    public function test_release_never_decrements_below_zero(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $productId = $this->insertProduct();

        $itemId = $this->insertReceivedDemandWithItem(
            fromBranchId: $branchB->id, toBranchId: $branchA->id,
            productId: $productId, qty: 10.0, costRate: 10.0,
        );

        // consumed_qty is 0 (no prior sale) but sale line says qty=4.
        // release() must NOT decrement below 0 — it caps at current consumed.
        $saleLineId = $this->insertSalesInvoiceItem($productId, 4.0, $itemId);

        $result = DB::transaction(fn () => $this->resolver->release($saleLineId));

        $this->assertSame([], $result, 'Nothing to release (consumed_qty already 0).');

        $consumed = DB::table('branch_demand_items')->where('id', $itemId)->value('consumed_qty');
        $this->assertEquals(0.0, (float) $consumed, 'consumed_qty must not go below 0.');
    }
}
