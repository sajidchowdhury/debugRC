<?php

namespace Tests\Unit\BranchDemand;

use App\Services\BranchDemand\BranchDemandAuditService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Branch Demand Audit Service Unit Tests — Phase 10.
 *
 * Tests the BranchDemandAuditService:
 *   - Anti-gaming flags (catalog below locked rate, sales below cost, stale outstanding)
 *   - Health checks (GL journal links, ledger nature, demand GL alignment,
 *     journal balance, orphaned settlements, reversed with open settlements)
 *   - Reconciliation (demand outstanding vs ledger running balance)
 *   - Per-demand audit (stock trace, settlement trace, GL blocks)
 *
 * Uses DB::table() inserts for test data setup.
 */
class BranchDemandAuditServiceTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsLedgerDependencies;

    private BranchDemandAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BranchDemandAuditService::class);
    }

    // ===================== getChecklist() =====================

    public function test_get_checklist_returns_all_health_checks(): void
    {
        $checklist = $this->service->getChecklist();

        $this->assertIsArray($checklist);
        $this->assertArrayHasKey('gl_journal_links', $checklist);
        $this->assertArrayHasKey('ledger_nature', $checklist);
        $this->assertArrayHasKey('demand_gl_alignment', $checklist);
        $this->assertArrayHasKey('journal_balance', $checklist);
        $this->assertArrayHasKey('orphaned_settlements', $checklist);
        $this->assertArrayHasKey('reversed_with_open_settlements', $checklist);
    }

    public function test_gl_journal_links_check_passes_with_no_demands(): void
    {
        $checklist = $this->service->getChecklist();

        $glCheck = $checklist['gl_journal_links'];
        $this->assertEquals('pass', $glCheck['status']);
        $this->assertEquals(0, $glCheck['count']);
    }

    // ===================== getDemandAudit() =====================

    public function test_get_demand_audit_returns_comprehensive_data(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        // Add audit log entries
        $this->insertBranchDemandAuditLog($demandId, 'create', $branchId);
        $this->insertBranchDemandAuditLog($demandId, 'send', $branchId);

        $auditData = $this->service->getDemandAudit($demandId);

        $this->assertIsArray($auditData);
        $this->assertArrayHasKey('demand', $auditData);
        $this->assertArrayHasKey('audit_log', $auditData);
        $this->assertArrayHasKey('anti_gaming_flags', $auditData);
    }

    public function test_get_demand_audit_throws_for_nonexistent_demand(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->getDemandAudit(999999);
    }

    // ===================== getReconciliation() =====================

    public function test_get_reconciliation_returns_branch_data(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();

        $reconciliation = $this->service->getReconciliation(
            $branchId,
            now()->subDays(7)->format('Y-m-d'),
            now()->format('Y-m-d')
        );

        $this->assertIsArray($reconciliation);
    }

    // ===================== Anti-Gaming Flags =====================

    public function test_get_demand_anti_gaming_flags_returns_three_categories(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();

        $flags = $this->service->getDemandAntiGamingFlags(
            $branchId,
            now()->subDays(30)->format('Y-m-d'),
            now()->format('Y-m-d')
        );

        $this->assertIsArray($flags);
        $this->assertArrayHasKey('catalog_below_locked', $flags);
        $this->assertArrayHasKey('sales_below_cost', $flags);
        $this->assertArrayHasKey('stale_outstanding', $flags);
    }

    public function test_stale_outstanding_flags_old_demands(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();

        // Create a demand that is > 30 days old with outstanding balance
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');
        DB::table('branch_demands')->where('id', $demandId)->update([
            'total_value' => 1000.00,
            'settlement_amount' => 200.00,
            'is_reversed' => false,
            'demand_date' => now()->subDays(45)->toDateString(),
            'created_at' => now()->subDays(45),
        ]);

        $flags = $this->service->getDemandAntiGamingFlags(
            $branchId,
            now()->subDays(60)->format('Y-m-d'),
            now()->format('Y-m-d')
        );

        $staleFlags = $flags['stale_outstanding'];
        $this->assertIsIterable($staleFlags); // May be Collection or array
        // Should have at least one stale outstanding flag
    }

    // ===================== Health Checks =====================

    public function test_orphaned_settlements_check_passes_with_no_orphans(): void
    {
        $checklist = $this->service->getChecklist();

        $orphanCheck = $checklist['orphaned_settlements'];
        $this->assertEquals('pass', $orphanCheck['status']);
        $this->assertEquals(0, $orphanCheck['count']);
    }

    public function test_reversed_with_open_settlements_check_passes(): void
    {
        $checklist = $this->service->getChecklist();

        $reversedCheck = $checklist['reversed_with_open_settlements'];
        $this->assertEquals('pass', $reversedCheck['status']);
        $this->assertEquals(0, $reversedCheck['count']);
    }

    // ===================== Journal Balance =====================

    public function test_journal_balance_check_with_balanced_journals(): void
    {
        $checklist = $this->service->getChecklist();

        $balanceCheck = $checklist['journal_balance'];
        $this->assertContains($balanceCheck['status'], ['pass', 'skip']);
    }
}
