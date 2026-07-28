<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Services\Stock\WarehouseTransferService;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — Defense-in-depth same-branch guard tests for WarehouseTransfer.
 *
 * Covers:
 *   1. Service-level enforcement: WarehouseTransferService::createTransfer()
 *      rejects cross-branch warehouses with InvalidArgumentException
 *      "Both warehouses must belong to the same branch".
 *   2. Controller-level enforcement: HTTP POST to the store endpoint with
 *      cross-branch warehouses returns a redirect with errors about "same
 *      branch" (the controller's own branch guard catches it even for admin
 *      users where the WarehouseBelongsToBranch validation rule skips).
 *   3. No privilege escalation: even admin-role users are blocked from
 *      creating cross-branch transfers — the same-branch check is a business
 *      rule, not a permission check.
 *   4. Positive case: same-branch warehouses succeed (transfer created,
 *      status=draft, audit log written).
 *
 * The service is resolved from the container in setUp() so constructor
 * dependencies (StockService, StockAvailabilityService, JournalPostingService,
 * WarehouseTransferAuditLogger) wire up automatically.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls back
 * on tearDown, leaving the rcerp_test DB pristine.
 */
class SameBranchGuardTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(WarehouseTransferService::class);
    }

    // ====================================================================
    // Test 1: Service-level same-branch enforcement
    // ====================================================================

    public function test_cross_branch_transfer_rejected_at_service_level(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = $this->insertWarehouse($branchA->id);
        $whB = $this->insertWarehouse($branchB->id);
        $productId = $this->insertProduct();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both warehouses must belong to the same branch');

        $this->service->createTransfer([
            'from_warehouse_id' => $whA,
            'to_warehouse_id'   => $whB,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'cross-branch attempt',
            'items'             => [
                ['product_id' => $productId, 'qty' => 5, 'rate' => 10.00],
            ],
            'created_by'        => auth()->id(),
        ]);
    }

    // ====================================================================
    // Test 2: Controller-level same-branch enforcement (HTTP POST)
    // ====================================================================

    public function test_cross_branch_transfer_rejected_at_controller_level(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = $this->insertWarehouse($branchA->id);
        $whB = $this->insertWarehouse($branchB->id);
        $productId = $this->insertProduct();

        // Stock at source so WarehouseTransferItemHasAvailableStock passes
        // and the request reaches the controller's cross-branch guard.
        $this->insertWarehouseStock($whA, $productId, 100);

        // Admin user: WarehouseBelongsToBranch validation rule skips
        // (getUserBranchId() returns null for admin), but the
        // controller's own branch check at line 224 catches it.
        $response = $this->post(route('admin.warehouse-transfers.store'), [
            'from_warehouse_id' => $whA,
            'to_warehouse_id'   => $whB,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'cross-branch attempt',
            'items'             => [
                ['product_id' => $productId, 'qty' => 5, 'rate' => 10.00],
            ],
        ]);

        // Controller returns back()->withErrors() — 302 redirect with
        // session error about "same branch".
        $response->assertRedirect();
        $response->assertSessionHasErrors('to_warehouse_id');
    }

    // ====================================================================
    // Test 3: No privilege escalation — admin also blocked
    // ====================================================================

    public function test_admin_cannot_create_cross_branch_transfer(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA = $this->insertWarehouse($branchA->id);
        $whB = $this->insertWarehouse($branchB->id);
        $productId = $this->insertProduct();

        // Explicitly use admin user — no privilege bypass for the
        // same-branch business rule.
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Both warehouses must belong to the same branch');

        $this->service->createTransfer([
            'from_warehouse_id' => $whA,
            'to_warehouse_id'   => $whB,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'admin cross-branch attempt',
            'items'             => [
                ['product_id' => $productId, 'qty' => 5, 'rate' => 10.00],
            ],
            'created_by'        => $admin->id,
        ]);
    }

    // ====================================================================
    // Test 4: Same-branch transfer succeeds
    // ====================================================================

    public function test_same_branch_transfer_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $fromWhId = $this->insertWarehouse($branch->id);
        $toWhId   = $this->insertWarehouse($branch->id);
        $productId = $this->insertProduct();

        // Stock at source so availability check passes.
        $this->insertWarehouseStock($fromWhId, $productId, 100);

        $transfer = $this->service->createTransfer([
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'transfer_date'     => now()->format('Y-m-d'),
            'notes'             => 'same-branch transfer',
            'items'             => [
                ['product_id' => $productId, 'qty' => 10, 'rate' => 10.00],
            ],
            'created_by'        => auth()->id(),
        ]);

        // Transfer was created successfully.
        $this->assertNotNull($transfer->id);
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'               => $transfer->id,
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'from_branch_id'   => $branch->id,
            'to_branch_id'     => $branch->id,
            'is_interbranch'   => false,
            'status'           => 'draft',
            'is_reversed'      => false,
        ]);

        // Audit log written.
        $this->assertDatabaseHas('user_audit_log', [
            'action'  => 'transfer_created',
            'user_id' => auth()->id(),
        ]);
    }
}
