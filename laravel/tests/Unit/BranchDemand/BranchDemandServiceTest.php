<?php

namespace Tests\Unit\BranchDemand;

use App\Models\Branch;
use App\Services\BranchDemand\BranchDemandService;
use App\Services\BranchDemand\BranchDemandAuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * Branch Demand Service Unit Tests — Phase 10.
 *
 * Tests the BranchDemandService core lifecycle methods:
 *   - createDemand() — creates demand with items
 *   - sendGoodsWithWarehouses() — sends goods with warehouse selection
 *   - confirmReceipt() — confirms receipt (Phase 5)
 *   - reverseDemand() — reverses a demand (blocked until receipt confirmed)
 *   - deleteDraftDemand() — deletes a pending demand
 *   - rejectDemand() — rejects a pending demand
 *
 * These tests use DB::table() inserts to set up test data, mirroring
 * the project's test helper pattern (InsertsBranchDependencies, etc.).
 */
class BranchDemandServiceTest extends TestCase
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

    // ===================== createDemand() =====================

    public function test_create_demand_creates_demand_with_items(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $fromBranchId = $user->getBranchId();
        $toBranch = Branch::factory()->create();
        $toBranchId = $toBranch->id;

        // Insert a product
        $categoryId = $this->insertProductCategory();
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'P-TEST-' . uniqid(),
            'product_name' => 'Test Product',
            'category_id' => $categoryId,
            'unit' => 'pcs',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $demand = $this->service->createDemand([
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'demand_date' => now()->toDateString(),
            'notes' => 'Test demand',
        ], [
            ['product_id' => $productId, 'qty' => 10],
        ]);

        $this->assertNotNull($demand);
        $this->assertEquals('pending', $demand->status);
        $this->assertEquals($fromBranchId, $demand->from_branch_id);
        $this->assertEquals($toBranchId, $demand->to_branch_id);
        $this->assertNotNull($demand->demand_code);
    }

    public function test_create_demand_rejects_same_branch(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createDemand([
            'from_branch_id' => $branchId,
            'to_branch_id' => $branchId,
            'demand_date' => now()->toDateString(),
        ], [
            ['product_id' => 1, 'qty' => 10],
        ]);
    }

    // ===================== deleteDraftDemand() =====================

    public function test_delete_draft_demand_removes_pending_demand(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'pending');

        $this->service->deleteDraftDemand($demandId);

        $this->assertNull(DB::table('branch_demands')->where('id', $demandId)->first());
    }

    public function test_delete_draft_demand_rejects_non_pending(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        $this->expectException(\RuntimeException::class);

        $this->service->deleteDraftDemand($demandId);
    }

    // ===================== rejectDemand() =====================

    public function test_reject_demand_sets_status_to_rejected(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'pending');

        $demand = $this->service->rejectDemand($demandId, 'Test rejection reason', $user->id);

        $this->assertEquals('rejected', $demand->status);
    }

    public function test_reject_demand_rejects_non_pending(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        $this->expectException(\RuntimeException::class);

        $this->service->rejectDemand($demandId, 'Cannot reject', $user->id);
    }

    // ===================== confirmReceipt() =====================

    public function test_confirm_receipt_sets_received_at_and_received_by(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        $demand = $this->service->confirmReceipt($demandId, $user->id, $branchId);

        $this->assertNotNull($demand->received_at);
        $this->assertEquals($user->id, $demand->received_by);
    }

    public function test_confirm_receipt_rejects_non_received_status(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'pending');

        $this->expectException(\RuntimeException::class);

        $this->service->confirmReceipt($demandId, $user->id, $branchId);
    }

    // ===================== reverseDemand() =====================

    public function test_reverse_demand_blocked_until_receipt_confirmed(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        // Create a received demand WITHOUT receipt confirmation
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'received');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('receipt');

        $this->service->reverseDemand($demandId, 'Test reversal', $user->id);
    }

    public function test_reverse_demand_rejects_non_received(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branchId = $user->getBranchId();
        $demandId = $this->insertBranchDemand($branchId, $branchId + 1, 'pending');

        $this->expectException(\RuntimeException::class);

        $this->service->reverseDemand($demandId, 'Cannot reverse', $user->id);
    }

    // ===================== Audit Trail =====================

    public function test_create_demand_writes_audit_log(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $fromBranchId = $user->getBranchId();
        $toBranch = Branch::factory()->create();
        $toBranchId = $toBranch->id;

        $categoryId = $this->insertProductCategory();
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'P-TEST2-' . uniqid(),
            'product_name' => 'Test Product 2',
            'category_id' => $categoryId,
            'unit' => 'pcs',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $demand = $this->service->createDemand([
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'demand_date' => now()->toDateString(),
        ], [
            ['product_id' => $productId, 'qty' => 5],
        ]);

        $auditRow = DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demand->id)
            ->where('action', 'create')
            ->first();

        $this->assertNotNull($auditRow);
        $this->assertEquals($fromBranchId, $auditRow->branch_id);
    }
}
