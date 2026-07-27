<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — feature tests for StockTakeService::reverseSession().
 *
 * Phase 10 split "cancel a draft/counting session" from "reverse a posted
 * session" — these tests cover the reversal side of that distinction.
 * Reverse is the full-undo path for a session that has already been POSTED
 * (stock movements applied + GL journal posted): it creates new reversal
 * rows in stock_transactions + journal_entries (append-only — the originals
 * are preserved as history and marked is_reversed=true), and it transitions
 * the session status posted → reversed.
 *
 * Covers:
 *   - Reverse a posted session → status='reversed' + the four reversal
 *     columns (is_reversed, reversed_at, reversed_by, reverse_reason) set.
 *   - Reversal JE: the original journal_entry_id is preserved on the
 *     session row AND mirrored into reversal_of_entry_id; the original JE
 *     is marked is_reversed=true; a NEW reversal JE (reference_type=
 *     'reversal', reference_id=original JE id) is created.
 *   - Stock reversal: warehouse_stock.qty returns to the pre-post value
 *     (the original stock_transaction is marked is_reversed=true; a new
 *     reversal transaction with negated qty is created with reference_type
 *     = 'reversal').
 *   - Freeze release: a freeze_outbound=true session's warehouse flag is
 *     cleared (releaseSessionFreeze recomputes from remaining active
 *     freezing sessions — here there are none).
 *   - Guards: reverse on a counting/draft session → RuntimeException
 *     ("Only posted sessions can be reversed"); reverse on an already-
 *     reversed session → "Session is already reversed."; reverse on a
 *     cancelled session → "Session is cancelled (never posted) — nothing
 *     to reverse.".
 *   - Reason required: an empty reason throws "A reversal reason is
 *     required.".
 *   - Audit log: action='reverse', from_status='posted', to_status='reversed',
 *     payload contains `reason` + `reversal_of_entry_id`.
 *
 * DIVERGENCE NOTE (vs. the brief): the brief's wording on the reversal JE
 * pattern was ambiguous ("a new reversal JE exists in journal_entries with
 * is_reversed=true (or the same JE is marked reversed — read the service
 * to determine the pattern)"). Reading the service: BOTH happen — the
 * ORIGINAL JE is marked is_reversed=true (and its reversal_of_entry_id is
 * set to the new reversal JE's id), AND a NEW reversal JE is created
 * (reference_type='reversal', reference_id=original JE id, is_reversed=
 * false — it is itself not reversed). Asserted the actual pattern.
 *
 * Similarly, the brief's wording on stock_transactions ("a new reversal
 * row with is_reversed=true (or reference_type='stock_take' reversal)")
 * was ambiguous. Reading the service: the ORIGINAL stock_transaction is
 * marked is_reversed=true; a NEW reversal transaction is created with
 * reference_type='reversal' (not 'stock_take'), reference_id=original
 * transaction id, is_reversed=false. Asserted the actual pattern.
 */
class ReverseSessionTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
    }

    /**
     * Build a full chain ending in a POSTED session — used by every reverse
     * test as the precondition. Returns [sessionId, warehouseId, productId,
     * adminUserId, originalJournalEntryId].
     *
     * @param float $systemQty   warehouse_stock.qty at setup (= system_qty snapshot)
     * @param float $physicalQty counted qty entered by the counter (drives variance)
     */
    private function makePostedSession(
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

        $jeId = DB::table('stock_take_sessions')->where('id', $session->id)->value('journal_entry_id');

        return [$session->id, $warehouseId, $pid, $admin->id, $jeId];
    }

    public function test_reverse_posted_session_sets_status_reversed_and_reversal_columns(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makePostedSession($branch->id, $wid);

        $reverser = $this->makeRoleUser('admin');
        $reason = 'Counter error — re-count needed ' . uniqid();
        $this->service->reverseSession($sid, $reverser->id, $reason);

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('reversed', $row->status);
        $this->assertTrue((bool) $row->is_reversed);
        $this->assertNotNull($row->reversed_at);
        $this->assertSame($reverser->id, (int) $row->reversed_by);
        $this->assertSame($reason, $row->reverse_reason);
    }

    public function test_reverse_creates_reversal_journal_entry_linked_via_reversal_of_entry_id(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $originalJeId] = $this->makePostedSession($branch->id, $wid);

        $this->assertNotNull($originalJeId, 'Pre-condition: post created a journal entry.');

        $this->service->reverseSession($sid, $adminId, 'Reverse for JE-link test.');

        $stsRow = DB::table('stock_take_sessions')->where('id', $sid)->first();

        // Original journal_entry_id PRESERVED on the session row (the show
        // page renders the original post's JE).
        $this->assertSame((int) $originalJeId, (int) $stsRow->journal_entry_id);

        // reversal_of_entry_id mirrors the original JE id (the audit-chain
        // link — survives a future re-post that overwrites journal_entry_id).
        $this->assertSame((int) $originalJeId, (int) $stsRow->reversal_of_entry_id);

        // Original JE marked is_reversed=true + its reversal_of_entry_id
        // set to the NEW reversal JE's id.
        $originalJe = DB::table('journal_entries')->where('id', $originalJeId)->first();
        $this->assertTrue((bool) $originalJe->is_reversed);
        $this->assertNotNull($originalJe->reversal_of_entry_id);

        // A NEW reversal JE exists with reference_type='reversal',
        // reference_id=original JE id. It is NOT itself reversed.
        $this->assertDatabaseHas('journal_entries', [
            'id'             => $originalJe->reversal_of_entry_id,
            'reference_type' => 'reversal',
            'reference_id'   => $originalJeId,
            'is_reversed'    => false,
        ]);
    }

    public function test_reverse_undoes_stock_movements(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // system=10, physical=12 → posted qty 10→12; reversed back to 10.
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makePostedSession($branch->id, $wid, 10, 12);

        // Pre-condition: post moved qty 10 → 12.
        $postedQty = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->value('qty');
        $this->assertEqualsWithDelta(12, $postedQty, 0.0001);

        $this->service->reverseSession($sid, $adminId, 'Reverse stock test.');

        // warehouse_stock.qty returned to the pre-post value (10).
        $reversedQty = (float) DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->value('qty');
        $this->assertEqualsWithDelta(10, $reversedQty, 0.0001);

        // The ORIGINAL stock_transaction (reference_type='stock_take',
        // reference_id=$sid) is marked is_reversed=true.
        $originalTx = DB::table('stock_transactions')
            ->where('reference_type', 'stock_take')
            ->where('reference_id', $sid)
            ->first();
        $this->assertNotNull($originalTx);
        $this->assertTrue((bool) $originalTx->is_reversed);

        // A NEW reversal stock_transaction exists with reference_type=
        // 'reversal', reference_id=original transaction id, qty=-(original).
        $reversalTx = DB::table('stock_transactions')
            ->where('reference_type', 'reversal')
            ->where('reference_id', $originalTx->id)
            ->first();
        $this->assertNotNull($reversalTx);
        $this->assertEqualsWithDelta(-2, (float) $reversalTx->qty, 0.0001);
    }

    public function test_reverse_releases_freeze_on_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makePostedSession(
            $branch->id,
            $wid,
            10,
            12,
            ['freeze_outbound' => true],
        );

        // Post already released the freeze; assert it for clarity.
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        // Re-freeze manually to simulate the "still frozen" pre-condition
        // (in a real flow, post would have released it — we re-freeze here
        // to prove the reverse path also releases). Use a direct DB::table
        // write bypassing refreshWarehouseFreezeFlags so we can verify the
        // recompute path.
        DB::table('warehouses')->where('id', $wid)->update(['is_frozen_for_count' => true]);
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->reverseSession($sid, $adminId, 'Reverse releases freeze.');

        // Reverse recomputes the flag from remaining active freezing
        // sessions — here there are none, so the flag is cleared.
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_reverse_rejects_non_posted_session(): void
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
        // setup + save → status='counting' (NOT posted).
        $this->service->setupWarehouseCounts($session->id, $wid);
        $this->service->saveCounts($session->id, $wid, [$pid => 12]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only posted sessions can be reversed');
        $this->service->reverseSession($session->id, $admin->id, 'Should fail on counting.');
    }

    public function test_reverse_rejects_already_reversed_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makePostedSession($branch->id, $wid);

        $this->service->reverseSession($sid, $adminId, 'First reverse.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session is already reversed');
        $this->service->reverseSession($sid, $adminId, 'Second reverse.');
    }

    public function test_reverse_rejects_cancelled_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);

        // Cancel the draft → status='cancelled'.
        $this->service->cancelSession($session->id, $admin->id, 'Cancelling draft.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nothing to reverse');
        $this->service->reverseSession($session->id, $admin->id, 'Should fail on cancelled.');
    }

    public function test_reverse_writes_audit_log_with_action_reverse(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $originalJeId] = $this->makePostedSession($branch->id, $wid);

        $reason = 'Audit-log reverse test ' . uniqid();
        $this->service->reverseSession($sid, $adminId, $reason);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'reverse')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('posted', $auditRow->from_status);
        $this->assertSame('reversed', $auditRow->to_status);
        $this->assertSame($adminId, (int) $auditRow->actor_id);

        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($reason, $payload['reason']);
        $this->assertSame((int) $originalJeId, (int) $payload['reversal_of_entry_id']);
    }

    public function test_reverse_requires_reason(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $adminId, $jeId] = $this->makePostedSession($branch->id, $wid);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A reversal reason is required');
        $this->service->reverseSession($sid, $adminId, '');
    }
}
