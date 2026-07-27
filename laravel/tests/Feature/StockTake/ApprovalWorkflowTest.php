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
 * Phase 12 — feature tests for the StockTakeService approval workflow:
 * submit() / approve() / reject() and the postSession() approval gate.
 *
 * Covers:
 *   - counting → submitted (submit): persists submitted_by/at, clears prior
 *     approval artifacts.
 *   - Guards: submit on draft / already-submitted sessions → RuntimeException.
 *   - submitted → approved (approve): persists approved_by/at + comments.
 *   - Segregation of duties: the submitter cannot approve their own session.
 *   - Guards: approve on a non-submitted session → RuntimeException.
 *   - submitted → counting (reject): persists the rejection reason in
 *     approval_comments; resets warehouse statuses; CLEARS approved_* but
 *     PRESERVES submitted_by/at (so the audit timeline keeps the full
 *     submit→reject→resubmit chain — a fresh submit() overwrites the prior
 *     submitted_by/at with the new values, so they need not be null first).
 *   - End-to-end: submit → approve → post (no resubmission needed — status=
 *     'approved' bypasses the postSession approval gate).
 *   - postSession auto-approve inline (variance value < auto_approve_below_value
 *     threshold + require_approval=true → posted directly from counting).
 *   - postSession force-approval threshold (require_approval=false +
 *     variance value >= variance_threshold_block → RuntimeException).
 *   - Audit logs for submit / approve / reject with the exact payload keys
 *     used by the service.
 *
 * DIVERGENCE NOTES (vs. the brief):
 *   - The brief expected the approve audit payload to contain `approver_id`.
 *     The service actually writes `approved_by` (same key as the session
 *     column). Asserted the actual key.
 *   - The brief expected the reject audit payload to contain `reason`. The
 *     service actually writes `comments` (the reject() parameter is named
 *     $comments — it doubles as the rejection reason). Asserted the actual
 *     key.
 *   - The brief expected reject to clear submitted_by/at ("so a fresh submit
 *     can happen"). The service PRESERVES submitted_by/at as audit history;
 *     a fresh submit works because submit() OVERWRITES submitted_by/at with
 *     the new values (it does not require them to be null first). Asserted
 *     the actual behaviour (submitted_by/at preserved, approved_* cleared).
 *
 * The policy cache (StockTakePolicyService) is flushed in setUp() and after
 * every setPolicy() mutation. tearDown() also flushes + defensively writes
 * the default policy values (the writes are rolled back with the test
 * transaction by DatabaseTransactions, but the flush is the meaningful
 * action — it clears any in-memory cache entry that survived past the test).
 */
class ApprovalWorkflowTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);

        // Defensive: ensure each test starts from a fresh policy read.
        // DatabaseTransactions rolls back DB writes but NOT the in-memory
        // array cache — flush here so policy-mutating tests don't leak.
        app(StockTakePolicyService::class)->flushCache();
    }

    protected function tearDown(): void
    {
        // Restore policy defaults. The DB writes are rolled back with the
        // test transaction (DatabaseTransactions), but the cache flush is
        // the meaningful action — clears any in-memory cache entry holding
        // a mutated value. Each restoration is wrapped in try/catch so a
        // failure on one does not mask the original test error.
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
     * Build a full session (branch + warehouse + 1 product + setup + save)
     * sitting at status='counting' with all warehouses marked 'completed'
     * — ready for submit(). Returns [sessionId, warehouseId, productId, adminUserId].
     *
     * @param float $systemQty   warehouse_stock.qty at setup (= system_qty snapshot)
     * @param float $physicalQty counted qty entered by the counter (drives variance)
     */
    private function makeSessionReadyForSubmit(
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

        return [$session->id, $warehouseId, $pid, $admin->id];
    }

    public function test_submit_transitions_counting_to_submitted(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'           => $sid,
            'status'       => 'submitted',
            'submitted_by' => $submitterId,
        ]);

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertNotNull($row->submitted_at);

        // submit() also clears any prior approval artifacts so a fresh
        // submission starts clean (the brief's "fresh submit can happen"
        // intent — achieved here by clearing approved_*, not submitted_*).
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);
        $this->assertNull($row->approval_comments);
    }

    public function test_submit_rejects_draft_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        // createSession leaves status='draft' (no setup → no counting state).
        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only counting sessions can be submitted');
        $this->service->submit($session->id, $admin->id);
    }

    public function test_submit_rejects_already_submitted_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only counting sessions can be submitted');
        $this->service->submit($sid, $submitterId);
    }

    public function test_approve_transitions_submitted_to_approved(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        // Different user approves (segregation of duties: approver ≠ submitter).
        $approver = $this->makeRoleUser('admin');
        $this->service->approve($sid, $approver->id, 'Looks good.');

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'          => $sid,
            'status'      => 'approved',
            'approved_by' => $approver->id,
        ]);

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertNotNull($row->approved_at);
        $this->assertSame('Looks good.', $row->approval_comments);
    }

    public function test_approve_enforces_segregation_of_duties(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        // Same user tries to approve their own submission → rejected.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Segregation of duties');
        $this->service->approve($sid, $submitterId, 'Self-approve attempt.');
    }

    public function test_approve_rejects_non_submitted_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // Session sits at 'counting' (setup + save done, no submit yet).
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $approver = $this->makeRoleUser('admin');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only submitted sessions can be approved');
        $this->service->approve($sid, $approver->id);
    }

    public function test_reject_transitions_submitted_back_to_counting(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $approver = $this->makeRoleUser('admin');
        $reason = 'Counts look off — recheck warehouse ' . uniqid();
        $this->service->reject($sid, $approver->id, $reason);

        $row = DB::table('stock_take_sessions')->where('id', $sid)->first();
        $this->assertSame('counting', $row->status);
        $this->assertSame($reason, $row->approval_comments);

        // DIVERGENCE: submitted_by/at are PRESERVED (not cleared) so the
        // audit timeline retains the full submit→reject→resubmit chain. A
        // fresh submit() works because submit() overwrites submitted_by/at
        // with the new values — it does not require them to be null first.
        $this->assertSame($submitterId, (int) $row->submitted_by);
        $this->assertNotNull($row->submitted_at);

        // approved_* are cleared (no approval happened on this cycle).
        $this->assertNull($row->approved_by);
        $this->assertNull($row->approved_at);

        // Warehouse status reset from 'completed' back to 'counting' so the
        // counter sees the session as "needs re-count".
        $this->assertSame(
            'counting',
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sid)
                ->where('warehouse_id', $wid)
                ->value('status'),
        );
    }

    public function test_approve_then_post_succeeds_without_requiring_resubmission(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $approver = $this->makeRoleUser('admin');
        $this->service->approve($sid, $approver->id);

        // A session in status='approved' bypasses the postSession approval
        // gate (the gate only fires for counting/draft). No resubmission
        // needed — the prior approval carries the post through.
        $this->service->postSession($sid, $approver->id);

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $sid,
            'status' => 'posted',
        ]);
    }

    public function test_post_auto_approves_below_threshold(): void
    {
        $this->setPolicy('stock_take.require_approval', true);
        $this->setPolicy('stock_take.auto_approve_below_value', 1000);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // variance=+2 at rate=10 → variance_value=20 < 1000 → auto-approved inline.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForSubmit($branch->id, $wid, 10, 12);

        $this->service->postSession($sid, $adminId);

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $sid,
            'status' => 'posted',
        ]);

        // The auto-approve path writes an 'approve' audit row with
        // auto_approved=true (actor_id=null = system).
        $autoApproveAudit = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'approve')
            ->first();
        $this->assertNotNull($autoApproveAudit);
        $payload = json_decode($autoApproveAudit->payload, true);
        $this->assertTrue($payload['auto_approved'] ?? false);
    }

    public function test_post_force_approval_when_variance_exceeds_threshold(): void
    {
        $this->setPolicy('stock_take.require_approval', false);
        $this->setPolicy('stock_take.variance_threshold_block', 100);

        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        // variance=+12 at rate=10 → variance_value=120 >= 100 → force-approval.
        [$sid, $wid, $pid, $adminId] = $this->makeSessionReadyForSubmit($branch->id, $wid, 10, 22);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('force-approval threshold');
        $this->service->postSession($sid, $adminId);
    }

    public function test_submit_writes_audit_log_with_action_submit(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $this->assertDatabaseHas('stock_take_audit_log', [
            'stock_take_session_id' => $sid,
            'action'                => 'submit',
            'from_status'           => 'counting',
            'to_status'             => 'submitted',
            'actor_id'              => $submitterId,
        ]);
    }

    public function test_approve_writes_audit_log_with_action_approve(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $approver = $this->makeRoleUser('admin');
        $this->service->approve($sid, $approver->id, 'Approved by second admin.');

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'approve')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('submitted', $auditRow->from_status);
        $this->assertSame('approved', $auditRow->to_status);
        $this->assertSame($approver->id, (int) $auditRow->actor_id);

        // DIVERGENCE: payload key is `approved_by` (not `approver_id` as the
        // brief suggested). Asserted the actual key written by the service.
        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($approver->id, (int) $payload['approved_by']);
    }

    public function test_reject_writes_audit_log_with_action_reject(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pid, $submitterId] = $this->makeSessionReadyForSubmit($branch->id, $wid);

        $this->service->submit($sid, $submitterId);

        $approver = $this->makeRoleUser('admin');
        $reason = 'Rejected — re-count needed ' . uniqid();
        $this->service->reject($sid, $approver->id, $reason);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'reject')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame('submitted', $auditRow->from_status);
        $this->assertSame('counting', $auditRow->to_status);
        $this->assertSame($approver->id, (int) $auditRow->actor_id);

        // DIVERGENCE: payload key is `comments` (not `reason` as the brief
        // suggested). The reject() $comments parameter doubles as the
        // rejection reason; the same key is used in approve() for the
        // approver's comments. Asserted the actual key.
        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($reason, $payload['comments']);
    }
}
