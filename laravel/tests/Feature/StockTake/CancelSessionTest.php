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
 * Phase 12 — happy-path feature tests for StockTakeService::cancelSession().
 *
 * Phase 10 split "cancel a draft/counting session" from "reverse a posted
 * session" — these tests cover the cancel side of that distinction.
 *
 * Covers:
 *   - Cancelling a draft session → status='cancelled'.
 *   - Cancelling a counting session (after setup + save) → status='cancelled'.
 *   - Cancelling a freeze_outbound=true session releases the warehouse freeze
 *     flag (refreshWarehouseFreezeFlags recomputes from remaining active
 *     freezing sessions — here there are none).
 *   - Audit-log row (action='cancel', to_status='cancelled', payload carries
 *     the caller's reason).
 *   - Posted-session guard: cancel on a posted session throws RuntimeException
 *     pointing the caller at reverseSession().
 *   - Double-cancel guard: cancelling an already-cancelled session throws
 *     "already cancelled".
 *   - Reversed-session guard: cancelling a reversed session throws
 *     "already reversed".
 *
 * The posted-session + reversed-session tests require a full setup → save →
 * post (+ optional reverse) chain to reach the target state. We reuse the
 * same createSession/setupWarehouseCounts/saveCounts/postSession path as
 * PostSessionTest to reach the posted state, then exercise the cancel guard.
 */
class CancelSessionTest extends TestCase
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
     * Build a full chain ending in a POSTED session — used by the
     * posted/reversed cancel guard tests. Returns [sessionId, warehouseId, adminUserId].
     */
    private function makePostedSession(int $branchId, int $warehouseId): array
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, 10);

        $session = $this->service->createSession([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => 12]); // +2 variance
        $this->service->postSession($session->id, $admin->id);

        return [$session->id, $warehouseId, $admin->id];
    }

    public function test_cancel_draft_session_sets_status_cancelled(): void
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

        $this->service->cancelSession($session->id, $admin->id, 'Draft no longer needed.');

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $session->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_counting_session_sets_status_cancelled(): void
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
        $this->service->saveCounts($session->id, $wid, [$pid => 8]); // counting state, with counts saved.

        $this->service->cancelSession($session->id, $admin->id, 'Counter abandoned.');

        $this->assertDatabaseHas('stock_take_sessions', [
            'id'     => $session->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_releases_freeze_on_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'freeze_outbound'=> true,
            'created_by'    => $admin->id,
        ]);

        // Pre-condition: the warehouse IS frozen.
        $this->assertTrue((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));

        $this->service->cancelSession($session->id, $admin->id, 'Releasing freeze via cancel.');

        // Post-cancel: freeze released (no other active freezing session).
        $this->assertFalse((bool) DB::table('warehouses')->where('id', $wid)->value('is_frozen_for_count'));
    }

    public function test_cancel_writes_audit_log_with_action_cancel(): void
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

        $reason = 'Audit-log reason test ' . uniqid();
        $this->service->cancelSession($session->id, $admin->id, $reason);

        $this->assertDatabaseHas('stock_take_audit_log', [
            'stock_take_session_id' => $session->id,
            'action'                => 'cancel',
            'to_status'             => 'cancelled',
            'actor_id'              => $admin->id,
        ]);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $session->id)
            ->where('action', 'cancel')
            ->first();
        $payload = json_decode($auditRow->payload, true);
        $this->assertSame($reason, $payload['reason']);
    }

    public function test_cancel_posted_session_throws_and_points_to_reverse(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $adminId] = $this->makePostedSession($branch->id, $wid);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be reversed');
        $this->service->cancelSession($sid, $adminId, 'Should have used reverse.');
    }

    public function test_cancel_already_cancelled_session_throws(): void
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

        $this->service->cancelSession($session->id, $admin->id, 'First cancel.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already cancelled');
        $this->service->cancelSession($session->id, $admin->id, 'Second cancel.');
    }

    public function test_cancel_reversed_session_throws(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $adminId] = $this->makePostedSession($branch->id, $wid);

        // Reverse the posted session — moves it to status='reversed'.
        $this->service->reverseSession($sid, $adminId, 'Reversed for cancel-guard test.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already reversed');
        $this->service->cancelSession($sid, $adminId, 'Should fail on reversed.');
    }
}
