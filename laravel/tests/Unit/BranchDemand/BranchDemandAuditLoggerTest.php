<?php

namespace Tests\Unit\BranchDemand;

use App\Services\BranchDemand\BranchDemandAuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\TestCase;

/**
 * Branch Demand Audit Logger Unit Tests — Phase 10.
 *
 * Tests the BranchDemandAuditLogger service that writes audit rows
 * inside the caller's DB::transaction. Mirrors the
 * StockAdjustmentAuditLogger pattern.
 *
 * Coverage:
 *   - log() writes one row with correct fields
 *   - log() with zero demand_id is a no-op
 *   - log() resolves actor_id / actor_role from auth context
 *   - log() resolves IP / user_agent from request context
 *   - getTrailForDemand() returns chronological entries
 *   - getTrailForBranch() returns branch-scoped entries
 *   - getCriticalActions() filters to high-severity actions
 *   - log() inside a rolled-back transaction is also rolled back
 */
class BranchDemandAuditLoggerTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;

    private BranchDemandAuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->app->make(BranchDemandAuditLogger::class);
    }

    // ===================== log() =====================

    public function test_log_writes_one_row_with_correct_fields(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        $this->logger->log($demandId, 'create', $branchId, [
            'demand_code' => 'BD-TEST-001',
            'items_count' => 3,
        ]);

        $row = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals($demandId, $row->branch_demand_id);
        $this->assertEquals('create', $row->action);
        $this->assertEquals($branchId, $row->branch_id);
        $this->assertEquals($user->id, $row->actor_id);
        $this->assertEquals('admin', $row->actor_role);
        $this->assertNotNull($row->payload);

        $payload = json_decode($row->payload, true);
        $this->assertEquals('BD-TEST-001', $payload['demand_code']);
        $this->assertEquals(3, $payload['items_count']);
    }

    public function test_log_with_zero_demand_id_is_noop(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $countBefore = DB::table('branch_demand_audit_log')->count();

        $this->logger->log(0, 'create', 1, []);

        $countAfter = DB::table('branch_demand_audit_log')->count();
        $this->assertEquals($countBefore, $countAfter);
    }

    public function test_log_resolves_actor_from_auth_context(): void
    {
        $user = $this->makeRoleUser('manager');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        $this->logger->log($demandId, 'send', $branchId, []);

        $row = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->first();

        $this->assertEquals($user->id, $row->actor_id);
        $this->assertEquals('manager', $row->actor_role);
    }

    public function test_log_resolves_ip_and_user_agent_from_request(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        $this->logger->log($demandId, 'reverse', $branchId, ['reason' => 'test']);

        $row = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->first();

        $this->assertNotNull($row->ip_address);
        $this->assertNotNull($row->user_agent);
    }

    public function test_log_with_explicit_actor_id_overrides_auth(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        $overrideActorId = 99999;
        $this->logger->log($demandId, 'delete', $branchId, [], $overrideActorId);

        $row = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->first();

        $this->assertEquals($overrideActorId, $row->actor_id);
    }

    // ===================== Rollback =====================

    public function test_log_inside_rolled_back_transaction_is_also_rolled_back(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        try {
            DB::transaction(function () use ($demandId, $branchId) {
                $this->logger->log($demandId, 'create', $branchId, ['test' => 'rollback']);
                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $row = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->first();

        $this->assertNull($row);
    }

    // ===================== getTrailForDemand() =====================

    public function test_get_trail_for_demand_returns_chronological_entries(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        // Insert audit log entries directly
        $this->insertBranchDemandAuditLog($demandId, 'create', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'send', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'confirm_receipt', $branchId);

        $trail = $this->logger->getTrailForDemand($demandId);

        $this->assertCount(3, $trail);
        $this->assertEquals('create', $trail[0]->action);
        $this->assertEquals('send', $trail[1]->action);
        $this->assertEquals('confirm_receipt', $trail[2]->action);
    }

    // ===================== getTrailForBranch() =====================

    public function test_get_trail_for_branch_returns_branch_scoped_entries(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        $this->insertBranchDemandAuditLog($demandId, 'create', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'send', $branchId);

        // Insert an entry for a different branch (should not appear)
        $otherDemandId = $this->insertBranchDemand($branchId + 1, $branchId + 2);
        $this->insertBranchDemandAuditLog($otherDemandId, 'create', $branchId + 1);

        $trail = $this->logger->getTrailForBranch(
            $branchId,
            now()->subDay()->format('Y-m-d'),
            now()->addDay()->format('Y-m-d')
        );

        $this->assertCount(2, $trail);
        foreach ($trail as $entry) {
            $this->assertEquals($branchId, $entry->branch_id);
        }
    }

    // ===================== getCriticalActions() =====================

    public function test_get_critical_actions_filters_to_high_severity(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1);

        // Critical actions
        $this->insertBranchDemandAuditLog($demandId, 'reverse', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'delete', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'reprice', $branchId);

        // Non-critical actions
        $this->insertBranchDemandAuditLog($demandId, 'create', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'send', $branchId);

        $critical = $this->logger->getCriticalActions(
            $branchId,
            now()->subDay()->format('Y-m-d'),
            now()->addDay()->format('Y-m-d')
        );

        $this->assertCount(3, $critical);
        $actions = $critical->pluck('action')->toArray();
        $this->assertContains('reverse', $actions);
        $this->assertContains('delete', $actions);
        $this->assertContains('reprice', $actions);
        $this->assertNotContains('create', $actions);
        $this->assertNotContains('send', $actions);
    }
}
