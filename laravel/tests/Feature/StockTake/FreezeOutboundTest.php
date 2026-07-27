<?php

namespace Tests\Feature\StockTake;

use App\Exceptions\WarehouseFrozenForCountException;
use App\Models\Branch;
use App\Services\Stock\StockService;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — special-features feature tests for the outbound-freeze side
 * of the Stock Take feature (StockTakeService + StockService integration).
 *
 * Scope (Task 2-c):
 *   - Default freeze_outbound=false (no frozen_at, no warehouse flag).
 *   - freeze_outbound=true path flips warehouses.is_frozen_for_count=true.
 *   - StockService::applyTransaction guards outbound movements (qty<0) on a
 *     frozen warehouse via WarehouseFrozenForCountException. Inbound
 *     movements (qty>0) are explicitly allowed (the freeze only blocks stock
 *     LEAVING the warehouse — inbound receipts cannot corrupt the count).
 *   - Freeze release lifecycle: post / cancel / reverse each release the
 *     warehouse flag via refreshWarehouseFreezeFlags (recomputes from the
 *     remaining active freezing sessions).
 *   - reOpen re-asserts the freeze — a re-opened counting session is
 *     "actively counting" again, so the freeze must resume.
 *   - Overlap invariant: two freeze=true sessions on the SAME warehouse are
 *     rejected by the service pre-check + the DB trigger
 *     (prevent_overlapping_frozen_stock_take). A non-freezing session B can
 *     coexist with a freezing session A (B doesn't acquire the flag, so the
 *     overlap rule has nothing to reject).
 *   - Multi-session release: cancelling session A releases A's warehouses'
 *     flags while B's stay frozen (refreshWarehouseFreezeFlags recomputes
 *     per warehouse from the remaining active freezing sessions). A new
 *     freezing session C can then grab A's old warehouse (A no longer
 *     conflicts).
 *
 * DIVERGENCE NOTE on the outbound reference_type:
 *   The task brief hypothesised `reference_type='sales_invoice'` for the
 *   outbound-blocked test. Reading StockService::applyTransaction +
 *   StockTransaction::REFERENCE_TYPES: 'sales_invoice' is NOT in the valid
 *   set (the valid set is purchase_receive, purchase_return, sales_challan,
 *   sales_return, stock_adjustment, stock_take, warehouse_transfer, damage,
 *   branch_demand, opening_balance, reversal). The sales-side outbound
 *   movement reference is `sales_challan`. We use `sales_challan` for the
 *   outbound-blocked test and `purchase_receive` for the inbound-allowed
 *   test (both explicitly NOT in the ['stock_take', 'reversal'] exemption
 *   list, so the freeze guard fires).
 *
 * The service is resolved from the container in setUp(). Every test runs
 * inside DatabaseTransactions and rolls back on tearDown.
 */
class FreezeOutboundTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;
    protected StockService $stockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
        $this->stockService = app(StockService::class);

        // The policy service caches all policies in memory. DatabaseTransactions
        // rolls back DB writes but NOT the cache — flush here so every test
        // starts with a fresh read of the (rolled-back) seeded defaults.
        // (Same defensive pattern as PostSessionTest::setUp.)
        app(StockTakePolicyService::class)->flushCache();
    }

    /**
     * Build the standard createSession payload for a single-branch,
     * N-warehouse session. Caller can override any key.
     */
    private function basePayload(int $branchId, array $warehouseIds, array $overrides = []): array
    {
        return array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => $warehouseIds,
            'notes'         => 'Phase 12 freeze test',
            'created_by'    => auth()->id(),
        ], $overrides);
    }

    /**
     * Build a full chain ending in a POSTED session with a +2 variance.
     * Returns [sessionId, warehouseId, productId, adminUserId].
     */
    private function makePostedSession(int $branchId, int $warehouseId, array $sessionOverrides = []): array
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, 10);

        $session = $this->service->createSession(array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ], $sessionOverrides));
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => 12]); // +2 variance
        $this->service->postSession($session->id, $admin->id);

        return [$session->id, $warehouseId, $pid, $admin->id];
    }

    public function test_freeze_outbound_default_is_false(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $session = $this->service->createSession($this->basePayload($branch->id, [$wid]));

        $stsRow = DB::table('stock_take_sessions')->where('id', $session->id)->first();
        $this->assertFalse((bool) $stsRow->freeze_outbound);
        $this->assertNull($stsRow->frozen_at);

        // The warehouse flag is also false (no freezing session covers it).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_freeze_outbound_true_marks_warehouses_is_frozen_for_count(): void
    {
        $branch = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch->id);
        $wid2 = $this->insertWarehouse($branch->id);

        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid1, $wid2],
            ['freeze_outbound' => true],
        ));

        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid1)->value('is_frozen_for_count'));
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid2)->value('is_frozen_for_count'));
    }

    /**
     * DIVERGENCE NOTE: the brief said reference_type='sales_invoice'. That
     * value is NOT in StockTransaction::REFERENCE_TYPES, so applyTransaction
     * would throw InvalidArgumentException('Invalid reference_type') BEFORE
     * reaching the freeze guard — masking the freeze test entirely. We use
     * 'sales_challan' (the actual sales-side outbound movement reference),
     * which is in the valid set AND not in the ['stock_take','reversal']
     * exemption list, so the freeze guard fires.
     */
    public function test_freeze_outbound_blocks_outbound_stock_transaction(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));

        // Sanity: warehouse IS frozen before we try the outbound.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        // Outbound movement (qty < 0) with reference_type='sales_challan' must
        // be blocked by the freeze guard. WarehouseFrozenForCountException
        // extends RuntimeException and its message contains "frozen".
        try {
            $this->stockService->applyTransaction([
                'warehouse_id'   => $wid,
                'product_id'     => $pid,
                'qty'            => -3,
                'rate'           => 10.00,
                'reference_type' => 'sales_challan',
                'reference_id'   => 9999,
                'transaction_date' => now()->format('Y-m-d'),
                'created_by'     => auth()->id(),
            ]);
            $this->fail('Expected WarehouseFrozenForCountException was not thrown.');
        } catch (WarehouseFrozenForCountException $e) {
            $this->assertStringContainsStringIgnoringCase('frozen', $e->getMessage());
            $this->assertSame($wid, $e->getWarehouseId());
        }

        // warehouse_stock.qty unchanged — the guard fires before any insert.
        $this->assertEqualsWithDelta(10, (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)->where('product_id', $pid)->value('qty'), 0.0001);
    }

    public function test_freeze_outbound_allows_inbound_stock_transaction(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));

        // Inbound movement (qty > 0) with reference_type='purchase_receive'
        // is explicitly ALLOWED during a count — only stock LEAVING the
        // warehouse would corrupt the count. The freeze guard only fires
        // for qty < 0 (and not for the exempt 'stock_take'/'reversal' refs).
        $tx = $this->stockService->applyTransaction([
            'warehouse_id'   => $wid,
            'product_id'     => $pid,
            'qty'            => 5,
            'rate'           => 12.00,
            'reference_type' => 'purchase_receive',
            'reference_id'   => 9999,
            'transaction_date' => now()->format('Y-m-d'),
            'created_by'     => auth()->id(),
        ]);

        $this->assertNotNull($tx->id);
        $this->assertDatabaseHas('stock_transactions', [
            'id'             => $tx->id,
            'warehouse_id'   => $wid,
            'product_id'     => $pid,
            'qty'            => 5,
            'reference_type' => 'purchase_receive',
        ]);

        // warehouse_stock.qty updated 10 → 15.
        $this->assertEqualsWithDelta(15, (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)->where('product_id', $pid)->value('qty'), 0.0001);
    }

    public function test_freeze_released_after_post(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Create the session with freeze=true + assert the warehouse IS frozen
        // BEFORE the post (the freeze is in effect during the count).
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);
        $session = $this->service->createSession([
            'branch_id'      => $branch->id,
            'session_date'   => now()->format('Y-m-d'),
            'warehouse_ids'  => [$wid],
            'freeze_outbound'=> true,
            'created_by'     => $admin->id,
        ]);
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->setupWarehouseCounts($session->id, $wid);
        $this->service->saveCounts($session->id, $wid, [$pid => 12]); // +2 variance
        $this->service->postSession($session->id, $admin->id);

        // Post releases the freeze (no other active freezing session).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
        $this->assertSame('posted', DB::table('stock_take_sessions')->where('id', $session->id)->value('status'));
    }

    public function test_freeze_released_after_cancel(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $session = $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true, 'created_by' => $admin->id],
        ));

        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->cancelSession($session->id, $admin->id, 'Releasing freeze via cancel.');

        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
        $this->assertSame('cancelled', DB::table('stock_take_sessions')->where('id', $session->id)->value('status'));
    }

    public function test_freeze_released_after_reverse(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makePostedSession(
            $branch->id,
            $wid,
            ['freeze_outbound' => true],
        );

        // After post, the freeze was released (no other freezing session).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        // Re-freeze scenario is not what we test here — the test asserts that
        // reverseSession on a posted freeze_outbound=true session leaves the
        // warehouse flag false (the post already released it; reverse must
        // not re-freeze — the session is now in the terminal 'reversed'
        // status, not an active counting status).
        $this->service->reverseSession($sid, $adminId, 'Reversed for freeze-release test.');

        $this->assertSame('reversed', DB::table('stock_take_sessions')->where('id', $sid)->value('status'));
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_freeze_re_asserted_on_re_open(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makePostedSession(
            $branch->id,
            $wid,
            ['freeze_outbound' => true],
        );

        // After post: warehouse flag is false.
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->reverseSession($sid, $adminId, 'Reversed prior to re-open.');
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        // reOpen transitions reversed → counting. A re-opened counting
        // session is "actively counting" again, so the freeze must resume.
        $this->service->reOpen($sid, $adminId, 'Re-opening to re-assert the freeze.');

        $this->assertSame('counting', DB::table('stock_take_sessions')->where('id', $sid)->value('status'));
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_overlapping_frozen_sessions_are_rejected(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Session A: freeze=true, covers $wid.
        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));

        // Session B: freeze=true, covers the SAME warehouse — must be
        // rejected by the service pre-check (the DB trigger is the race-
        // condition backstop).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another active stock-take session already froze them');
        $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));
    }

    public function test_non_freezing_session_can_coexist_with_freezing_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Session A: freeze=true, covers $wid.
        $sessionA = $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => true],
        ));

        // Session B: freeze=FALSE, covers the SAME warehouse — must succeed
        // (B doesn't acquire the freeze flag, so the overlap rule has
        // nothing to reject). The DB trigger only fires when NEW.freeze_outbound
        // IS TRUE; a non-freezing row sails through.
        $sessionB = $this->service->createSession($this->basePayload(
            $branch->id,
            [$wid],
            ['freeze_outbound' => false],
        ));

        $this->assertNotNull($sessionA->id);
        $this->assertNotNull($sessionB->id);
        $this->assertNotSame($sessionA->id, $sessionB->id);

        // Warehouse stays frozen (session A still holds it).
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_freeze_release_honors_overlapping_sessions(): void
    {
        $branch = Branch::factory()->create();
        $widA = $this->insertWarehouse($branch->id);
        $widB = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        // Session A: freeze=true, covers $widA.
        $sessionA = $this->service->createSession($this->basePayload(
            $branch->id,
            [$widA],
            ['freeze_outbound' => true, 'created_by' => $admin->id],
        ));
        // Session B: freeze=true, covers $widB (a different warehouse).
        $sessionB = $this->service->createSession($this->basePayload(
            $branch->id,
            [$widB],
            ['freeze_outbound' => true, 'created_by' => $admin->id],
        ));

        // Pre-condition: both warehouses frozen.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $widA)->value('is_frozen_for_count'));
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $widB)->value('is_frozen_for_count'));

        // Cancel A — A's warehouse flag goes false, B's stays true.
        $this->service->cancelSession($sessionA->id, $admin->id, 'Cancelling A.');
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $widA)->value('is_frozen_for_count'));
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $widB)->value('is_frozen_for_count'));

        // Session C: freeze=true, covers $widA (A's old warehouse). A is
        // cancelled (terminal status) so its freeze has been released —
        // C must succeed (no overlapping active frozen session on $widA).
        $sessionC = $this->service->createSession($this->basePayload(
            $branch->id,
            [$widA],
            ['freeze_outbound' => true, 'created_by' => $admin->id],
        ));

        $this->assertNotNull($sessionC->id);
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $widA)->value('is_frozen_for_count'));
        // B's warehouse stays frozen (B is still active).
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $widB)->value('is_frozen_for_count'));
    }
}
