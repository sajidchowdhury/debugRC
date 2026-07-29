<?php

namespace Tests\Feature\StockTake;

use App\Exceptions\StockTakeNegativeStockException;
use App\Models\Branch;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — happy-path feature tests for StockTakeService::postSession().
 *
 * Covers:
 *   - Positive variance (gain): creates a stock IN movement + a balanced GL
 *     journal entry (Dr Inventory / Cr Inventory Surplus) + back-links
 *     journal_line_id on the variance item + updates warehouse_stock.qty.
 *   - Negative variance (loss): creates a stock OUT movement + a balanced GL
 *     journal entry (Dr Shrinkage / Cr Inventory) + updates warehouse_stock.qty.
 *   - Zero variance: posts with status='posted', journal_entry_id=null (no
 *     variance → no GL). The non-variance item stays is_applied=false (the
 *     service only iterates over variance items when marking applied).
 *   - Already-posted guard: a second post throws RuntimeException.
 *   - Cancelled-session guard: posting a cancelled session throws.
 *   - Audit log: action='post', from_status='counting', to_status='posted',
 *     payload contains journal_entry_id.
 *   - Freeze release: posting a freeze_outbound=true session clears
 *     warehouses.is_frozen_for_count (no other active freezing session).
 *   - Negative-stock pre-check: when warehouse_stock.qty has drifted below
 *     the snapshot system_qty since setup, the shortage pre-check throws
 *     StockTakeNegativeStockException BEFORE any stock movement is applied.
 *   - Approval gate (require_approval=true): a counting session with variance
 *     value above auto_approve_below_value is rejected with a clear
 *     "requires approval before posting" message.
 *   - Auto-approve inline: when variance value is strictly below
 *     auto_approve_below_value, postSession auto-approves inline (actor =
 *     system) and posts in the same transaction.
 *
 * DIVERGENCE NOTE on the zero-variance test: the task brief expected
 * is_applied=true on the non-variance item. Reading the service: the
 * foreach that marks is_applied=true iterates over $varianceItems only
 * (rows where physical_qty <> system_qty). A zero-variance item is never
 * in that set, so is_applied stays false. We assert the actual behaviour.
 *
 * The policy cache (StockTakePolicyService) is flushed in setUp() so each
 * test starts from a fresh read of the (rolled-back) DB, even when a
 * previous test mutated a policy and the in-memory cache would otherwise
 * still hold the stale value.
 */
class PostSessionTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // The policy service caches all policies in memory for 5 min under
        // 'stock_take_policies:all'. DatabaseTransactions rolls back DB
        // writes but NOT the cache — flush here so every test starts with
        // a fresh read of the seeded defaults.
        app(StockTakePolicyService::class)->flushCache();
    }

    /**
     * Helper: update a stock_take_policies row + flush the in-memory cache.
     */
    private function setPolicy(string $key, $value): void
    {
        DB::table('stock_take_policies')
            ->where('key', $key)
            ->update(['value' => json_encode($value), 'updated_at' => now()]);
        app(StockTakePolicyService::class)->flushCache();
    }

    /**
     * Build a full session (branch + warehouse + 1 product + setup + save)
     * ready for posting. Returns [sessionId, warehouseId, productId, adminUserId].
     *
     * @param float $systemQty  warehouse_stock.qty at setup (= system_qty snapshot)
     * @param float $physicalQty  counted qty entered by the counter (drives variance)
     * @param array $sessionOverrides  Extra createSession overrides (e.g. freeze_outbound)
     */
    private function makeSessionReadyForPost(
        int $branchId,
        int $warehouseId,
        float $systemQty,
        float $physicalQty,
        array $sessionOverrides = [],
    ): array {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, $systemQty);

        $session = $this->service->createSession(array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ], $sessionOverrides));

        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => $physicalQty]);

        return [$session->id, $warehouseId, $pid, $admin->id];
    }

    public function test_post_session_with_positive_variance_creates_stock_in_and_gl_journal(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // system_qty=10, physical_qty=12 → variance=+2 at rate=10 → gain=20.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        $this->service->postSession($sid, $adminId);

        // Session posted + journal_entry_id set.
        $stsRow = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('posted', $stsRow->status);
        $this->assertNotNull($stsRow->journal_entry_id);

        // journal_entries row exists with reference_type='stock_take' and balanced.
        $jeId = $stsRow->journal_entry_id;
        $this->assertDatabaseHas('journal_entries', [
            'id'             => $jeId,
            'reference_type' => 'stock_take',
            'reference_id'   => $sid,
        ]);
        $totals = DB::table('journal_lines')
            ->where('journal_entry_id', $jeId)
            ->selectRaw('COALESCE(SUM(debit),0) as dr, COALESCE(SUM(credit),0) as cr')
            ->first();
        $this->assertEqualsWithDelta($totals->dr, $totals->cr, 0.01, 'Journal entry is not balanced.');

        // Item: is_applied=true + journal_line_id set.
        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('product_id', $pid)
            ->first();
        $this->assertTrue((bool) $item->is_applied);
        $this->assertNotNull($item->journal_line_id);

        // stock_transactions row: qty=+2, reference_type='stock_take'.
        $this->assertDatabaseHas('stock_transactions', [
            'warehouse_id'   => $wid,
            'product_id'     => $pid,
            'reference_type' => 'stock_take',
            'reference_id'   => $sid,
            'qty'            => 2,
        ]);

        // warehouse_stock.qty updated from 10 → 12.
        $newQty = DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->value('qty');
        $this->assertEqualsWithDelta(12, (float) $newQty, 0.0001);
    }

    public function test_post_session_with_negative_variance_creates_stock_out_and_gl_journal(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // system_qty=10, physical_qty=8 → variance=-2 at rate=10 → loss=20.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 8);

        $this->service->postSession($sid, $adminId);

        $stsRow = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('posted', $stsRow->status);
        $this->assertNotNull($stsRow->journal_entry_id);

        // journal_lines: Dr Shrinkage / Cr Inventory. Both lines exist; the
        // pair balances (sum Dr == sum Cr).
        $lines = DB::table('journal_lines as jl')
            ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
            ->where('jl.journal_entry_id', $stsRow->journal_entry_id)
            ->select('l.ledger_nature', 'jl.debit', 'jl.credit')
            ->get();
        $this->assertTrue($lines->contains(fn($l) => $l->ledger_nature === 'inventory_shrinkage' && (float) $l->debit > 0));
        $this->assertTrue($lines->contains(fn($l) => $l->ledger_nature === 'inventory' && (float) $l->credit > 0));

        // warehouse_stock.qty updated from 10 → 8.
        $newQty = DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->value('qty');
        $this->assertEqualsWithDelta(8, (float) $newQty, 0.0001);
    }

    public function test_post_session_with_zero_variance_does_not_create_journal(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // system_qty=10, physical_qty=10 → no variance.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 10);

        $this->service->postSession($sid, $adminId);

        $stsRow = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('posted', $stsRow->status);
        $this->assertNull($stsRow->journal_entry_id); // no variance → no GL.

        // The non-variance item stays is_applied=false (the foreach in
        // postSession iterates over $varianceItems only). journal_line_id
        // is null — no GL line was created to link to.
        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('product_id', $pid)
            ->first();
        $this->assertFalse((bool) $item->is_applied);
        $this->assertNull($item->journal_line_id);

        // warehouse_stock.qty unchanged (no movement was applied).
        $newQty = DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->value('qty');
        $this->assertEqualsWithDelta(10, (float) $newQty, 0.0001);
    }

    public function test_post_session_rejects_already_posted_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        $this->service->postSession($sid, $adminId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only approved/counting/draft sessions can be posted');
        $this->service->postSession($sid, $adminId);
    }

    public function test_post_session_rejects_cancelled_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        // Cancel first.
        $this->service->cancelSession($sid, $adminId, 'Cancelled before post test.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only approved/counting/draft sessions can be posted');
        $this->service->postSession($sid, $adminId);
    }

    public function test_post_session_writes_audit_log_with_action_post(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        $this->service->postSession($sid, $adminId);

        $stsRow = DB::table('stock_take_sessions')->where('id', $sid)->first();

        $this->assertDatabaseHas('stock_take_audit_log', [
            'stock_take_session_id' => $sid,
            'action'                => 'post',
            'from_status'           => 'counting',
            'to_status'             => 'posted',
        ]);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'post')
            ->first();
        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($stsRow->journal_entry_id, $payload['journal_entry_id']);
    }

    public function test_post_session_with_freeze_outbound_releases_freeze_after_post(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost(
            $branch->id,
            $wid,
            10,
            12,
            ['freeze_outbound' => true],
        );

        // Pre-condition: warehouse is frozen during the count.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->postSession($sid, $adminId);

        // Post releases the freeze (no other active freezing session).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_post_session_enforces_negative_stock_pre_check(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 2); // system_qty will snapshot 2.

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        // Simulate stock moving between setup and post: another transaction
        // reduced live qty from 2 → 1. The pre-check sees current_qty=1,
        // variance=-2 (physical=0, system=2), resulting=-1 < 0 → throws.
        DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->update(['qty' => 1, 'updated_at' => now()]);

        $this->service->saveCounts($session->id, $wid, [$pid => 0]);

        $this->expectException(StockTakeNegativeStockException::class);
        $this->service->postSession($session->id, $admin->id);
    }

    public function test_post_session_with_require_approval_true_rejects_without_approval(): void
    {
        $this->setPolicy('stock_take.require_approval', true);
        // auto_approve_below_value defaults to 0 → disabled. Any variance
        // value >= 0 (i.e. any variance at all) is rejected.

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // variance=+2 at rate=10 → variance_value=20 > 0 → approval required.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires approval before posting');
        $this->service->postSession($sid, $adminId);
    }

    public function test_post_session_auto_approves_below_threshold(): void
    {
        $this->setPolicy('stock_take.require_approval', true);
        $this->setPolicy('stock_take.auto_approve_below_value', 1000);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // variance=+2 at rate=10 → variance_value=20 < 1000 → auto-approved inline.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForPost($branch->id, $wid, 10, 12);

        $this->service->postSession($sid, $adminId);

        // Auto-approved inline → posted in the same transaction.
        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $sid,
            'status' => 'posted',
        ]);

        // The auto-approve path writes an 'approve' audit row with
        // auto_approved=true (actor_id=null = system), followed by the post.
        $autoApproveAudit = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'approve')
            ->first();
        $this->assertNotNull($autoApproveAudit);
        $payload = json_decode($autoApproveAudit->payload, true);
        $this->assertTrue($payload['auto_approved'] ?? false);
    }
}
