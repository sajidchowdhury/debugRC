<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — feature tests for StockTakeService::reOpen().
 *
 * Phase 10 added reOpen() as the "undo a reversal and re-enter counting"
 * path. After a posted session has been reversed (status='reversed'), an
 * admin can re-open it for correction: the session transitions reversed →
 * counting, items are reset (is_applied=false, journal_line_id=null,
 * revaluation_line_id=null, post_rate=null, revaluation_amount=0 — but
 * physical_qty is PRESERVED so the counter sees the prior count as a
 * starting point), warehouse statuses are reset to 'counting', and the
 * approval workflow is cleared (submitted_by, approved_by,
 * approval_comments — all null) so the re-counted session goes through
 * approval again.
 *
 * The re_open_count is incremented and capped by the stock_take.max_reopens
 * policy (default 1; 0 forbids re-opening entirely). A re-opened session's
 * freeze is re-asserted if the session was originally freezing.
 *
 * Covers:
 *   - reversed → counting transition: re_open_count=1, last_reopened_at/by
 *     set.
 *   - Item reset: is_applied=false, journal_line_id=null,
 *     revaluation_line_id=null, post_rate=null, revaluation_amount=0;
 *     physical_qty PRESERVED.
 *   - Warehouse status reset: 'completed'/'recounting' → 'counting'.
 *   - Approval workflow reset: submitted_by/at=null, approved_by/at=null,
 *     approval_comments=null.
 *   - max_reopens cap: with max_reopens=1, a second re-open after post→
 *     reverse→re-open→post→reverse throws "already been re-opened".
 *   - max_reopens=0: reversed = hard terminal; re-open throws
 *     "stock_take.max_reopens=0".
 *   - Freeze re-assertion: freeze_outbound=true → warehouses.is_frozen_
 *     for_count=true after re-open.
 *   - Non-reversed guard: re-open on a 'posted' session throws.
 *   - Reason required: empty reason throws "A re-open reason is required.".
 *   - Audit log: action='re_open', from_status='reversed', to_status=
 *     'counting', payload contains reason + re_open_count + reopens_remaining.
 *   - Re-post after re-open: a fresh postSession creates a NEW journal
 *     entry (≠ original); reversal_of_entry_id still points at the FIRST
 *     reversed JE — the audit chain survives the re-post.
 *
 * The policy cache (StockTakePolicyService) is flushed in setUp() and after
 * every setPolicy() mutation. tearDown() also flushes + defensively writes
 * the default policy values (the writes are rolled back with the test
 * transaction by DatabaseTransactions, but the flush is the meaningful
 * action — it clears any in-memory cache entry that survived past the test).
 */
class ReOpenSessionTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // Defensive: ensure each test starts from a fresh policy read.
        app(StockTakePolicyService::class)->flushCache();
    }

    protected function tearDown(): void
    {
        // Restore policy defaults. The DB writes are rolled back with the
        // test transaction, but the cache flush is the meaningful action —
        // clears any in-memory cache entry holding a mutated value. Each
        // restoration is wrapped in try/catch so a failure on one does not
        // mask the original test error.
        try {
            DB::table('stock_take_policies')->where('key', 'stock_take.require_approval')
                ->update(['value' => json_encode(false), 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
        try {
            DB::table('stock_take_policies')->where('key', 'stock_take.auto_approve_below_value')
                ->update(['value' => json_encode(0), 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
        try {
            DB::table('stock_take_policies')->where('key', 'stock_take.variance_threshold_block')
                ->update(['value' => json_encode(0), 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
        try {
            DB::table('stock_take_policies')->where('key', 'stock_take.max_reopens')
                ->update(['value' => json_encode(1), 'updated_at' => now()]);
        } catch (\Throwable $e) {
        }
        try {
            app(StockTakePolicyService::class)->flushCache();
        } catch (\Throwable $e) {
        }

        parent::tearDown();
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
     * Build a full chain ending in a REVERSED session — the precondition
     * for reOpen(). Returns [sessionId, warehouseId, productId, adminUserId,
     * originalJournalEntryId].
     *
     * @param float $systemQty   warehouse_stock.qty at setup (= system_qty snapshot)
     * @param float $physicalQty counted qty entered by the counter (drives variance)
     */
    private function makeReversedSession(
        int $branchId,
        int $warehouseId,
        float $systemQty = 10,
        float $physicalQty = 12,
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
        $this->service->postSession($session->id, $admin->id);

        $originalJeId = DB::table('stock_take_sessions')
            ->where('id', $session->id)
            ->value('journal_entry_id');

        $this->service->reverseSession($session->id, $admin->id, 'Reversed for re-open test.');

        return [$session->id, $warehouseId, $pid, $admin->id, $originalJeId];
    }

    public function test_re_open_transitions_reversed_to_counting(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        $reopener = $this->makeRoleUser('admin');
        $this->service->reOpen($sid, $reopener->id, 'Re-open for correction.');

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('counting', $row->status);
        $this->assertSame(1, (int) $row->re_open_count);
        $this->assertNotNull($row->last_reopened_at);
        $this->assertSame($reopener->id, (int) $row->last_reopened_by);
    }

    public function test_re_open_preserves_physical_qty_but_resets_applied_flags(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // system=10, physical=12 → +2 variance posted + reversed.
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid, 10, 12);

        $this->service->reOpen($sid, $adminId, 'Re-open for re-count.');

        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('product_id', $pid)
            ->first();

        // Reset flags (so postSession will re-apply them on the next post).
        $this->assertFalse((bool) $item->is_applied);
        $this->assertNull($item->journal_line_id);
        $this->assertNull($item->revaluation_line_id);
        $this->assertNull($item->post_rate);
        $this->assertEqualsWithDelta(0, (float) $item->revaluation_amount, 0.000001);

        // physical_qty PRESERVED (the counter sees the prior count and
        // adjusts — same UX as a recount, not a blank slate).
        $this->assertEqualsWithDelta(12, (float) $item->physical_qty, 0.0001);
    }

    public function test_re_open_resets_warehouse_statuses_to_counting(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        // Pre-condition: warehouse was 'completed' before the post.
        $preStatus = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->value('status');
        $this->assertSame('completed', $preStatus);

        $this->service->reOpen($sid, $adminId, 'Re-open warehouse reset test.');

        $postStatus = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->value('status');
        $this->assertSame('counting', $postStatus);
    }

    public function test_re_open_resets_approval_workflow(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        $this->service->reOpen($sid, $adminId, 'Re-open approval reset test.');

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertNull($row->submitted_by);
        $this->assertNull($row->submitted_at);
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approval_comments);
    }

    public function test_re_open_enforces_max_reopens_cap(): void
    {
        // Default policy max_reopens=1; assert it explicitly so a future
        // seed change doesn't silently break this test.
        $this->setPolicy('stock_take.max_reopens', 1);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        // First re-open (count goes 0 → 1) — succeeds.
        $this->service->reOpen($sid, $adminId, 'First re-open.');

        // Save counts again to mark the warehouse completed; post again
        // (creates a NEW journal entry); reverse again — the session is
        // back to 'reversed' with re_open_count=1.
        $this->service->saveCounts($sid, $wid, [$pid => 12]);
        $this->service->postSession($sid, $adminId);
        $this->service->reverseSession($sid, $adminId, 'Reverse after first re-post.');

        // Second re-open — fails: currentCount=1 >= max=1.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been re-opened');
        $this->service->reOpen($sid, $adminId, 'Second re-open — should fail.');
    }

    public function test_re_open_rejects_when_max_reopens_is_zero(): void
    {
        $this->setPolicy('stock_take.max_reopens', 0);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stock_take.max_reopens=0');
        $this->service->reOpen($sid, $adminId, 'Should fail — reversed is terminal.');
    }

    public function test_re_open_re_asserts_freeze_if_session_was_freezing(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession(
            $branch->id,
            $wid,
            10,
            12,
            ['freeze_outbound' => true],
        );

        // Pre-condition: after post + reverse, the freeze has been released
        // (both post and reverse call releaseSessionFreeze).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->reOpen($sid, $adminId, 'Re-open re-asserts freeze.');

        // reOpen re-asserts the freeze for sessions whose freeze_outbound=true.
        // The session is now 'counting' + freeze_outbound=true, so the
        // warehouse flag is recomputed to true.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_re_open_rejects_non_reversed_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10);

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);
        $this->service->saveCounts($session->id, $wid, [$pid => 12]);
        $this->service->postSession($session->id, $admin->id);
        // Session is now 'posted' — NOT reversed. reOpen should reject.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only reversed sessions can be re-opened');
        $this->service->reOpen($session->id, $admin->id, 'Should fail on posted.');
    }

    public function test_re_open_requires_reason(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makeReversedSession($branch->id, $wid);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A re-open reason is required');
        $this->service->reOpen($sid, $adminId, '');
    }

    public function test_re_open_writes_audit_log_with_action_re_open(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $originalJeId] = $this->makeReversedSession($branch->id, $wid);

        $reason = 'Audit-log re-open test ' . uniqid();
        $this->service->reOpen($sid, $adminId, $reason);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 're_open')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('reversed', $auditRow->from_status);
        $this->assertSame('counting', $auditRow->to_status);
        $this->assertSame($adminId, (int) $auditRow->actor_id);

        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($reason, $payload['reason']);
        $this->assertSame(1, (int) $payload['re_open_count']);
        $this->assertArrayHasKey('reopens_remaining', $payload);
        // Default max_reopens=1, this is the first re-open → remaining = 0.
        $this->assertSame(0, (int) $payload['reopens_remaining']);
    }

    public function test_re_open_then_re_post_creates_new_journal_entry(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $originalJeId] = $this->makeReversedSession($branch->id, $wid, 10, 12);

        $this->assertNotNull($originalJeId, 'Pre-condition: first post created a journal entry.');

        $this->service->reOpen($sid, $adminId, 'Re-open for re-post.');

        // Save counts again to mark the warehouse completed (required by
        // postSession). physical_qty is preserved at 12 from the prior post,
        // so passing the same value is idempotent — the counter confirms
        // the prior count.
        $this->service->saveCounts($sid, $wid, [$pid => 12]);

        // Second post — creates a NEW journal entry (≠ original).
        $this->service->postSession($sid, $adminId);

        $rePostedRow = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('posted', $rePostedRow->status);

        $newJeId = (int) $rePostedRow->journal_entry_id;
        $this->assertNotSame((int) $originalJeId, $newJeId);

        // The audit-chain link to the FIRST reversed JE survives the re-post
        // (postSession does NOT touch reversal_of_entry_id — it only updates
        // status + journal_entry_id + updated_at).
        $this->assertSame((int) $originalJeId, (int) $rePostedRow->reversal_of_entry_id);
    }
}
