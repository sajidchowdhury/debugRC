<?php

namespace Tests\Feature\WarehouseTransfer;

use App\Models\Branch;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 7 — Branch isolation tests for WarehouseTransfer.
 *
 * Covers:
 *   1. Non-admin user can only see transfers involving their branch via the
 *      index page — WarehouseTransferBranchScope global scope + controller
 *      filtering work together to restrict visibility.
 *   2. Non-admin user from Branch B cannot confirm a Branch A transfer —
 *      the BranchScope filters findOrFail() so the transfer is invisible
 *      (404), preventing any write operation on another branch's records.
 *   3. Admin/superadmin sees all transfers regardless of branch — admin
 *      bypass in WarehouseTransferBranchScope + getUserBranchId()=null in
 *      controller remove all branch restrictions.
 *
 * These tests exercise the full HTTP stack (controller + global scope +
 * session branch_id) rather than just the model scope in isolation,
 * ensuring defense-in-depth works end-to-end.
 *
 * Every test runs inside DatabaseTransactions (TestCase trait) and rolls
 * back on tearDown, leaving the rcerp_test DB pristine.
 */
class BranchIsolationTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected WarehouseTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(WarehouseTransferService::class);
    }

    /**
     * Insert a warehouse_transfer row directly via DB::table(), bypassing
     * the model's global scope and the service's validation. Used for
     * seeding test data in specific branches without triggering stock
     * availability checks.
     *
     * Returns the transfer id.
     */
    private function insertTransferDirect(
        int $fromBranchId,
        int $toBranchId,
        int $fromWhId,
        int $toWhId,
        string $transferCode,
        string $status = 'draft',
    ): int {
        return DB::table('warehouse_transfers')->insertGetId([
            'transfer_code'    => $transferCode,
            'transfer_date'    => now()->format('Y-m-d'),
            'from_warehouse_id' => $fromWhId,
            'to_warehouse_id'   => $toWhId,
            'from_branch_id'   => $fromBranchId,
            'to_branch_id'     => $toBranchId,
            'is_interbranch'   => ($fromBranchId !== $toBranchId),
            'status'           => $status,
            'is_reversed'      => false,
            'notes'            => 'Branch isolation test',
            'fiscal_year_id'   => $this->resolveActiveFiscalYearId(),
            'created_by'       => auth()->id(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ====================================================================
    // Test 1: Non-admin user only sees transfers involving their branch
    // ====================================================================

    public function test_user_can_only_see_transfers_involving_their_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA1 = $this->insertWarehouse($branchA->id);
        $whA2 = $this->insertWarehouse($branchA->id);
        $whB1 = $this->insertWarehouse($branchB->id);
        $whB2 = $this->insertWarehouse($branchB->id);

        // Create same-branch transfers in both branches via direct DB insert
        // (avoids needing stock setup for the service).
        $transferACode = 'WT-BRANCHA-' . substr(uniqid(), -4);
        $transferBCode = 'WT-BRANCHB-' . substr(uniqid(), -4);

        $this->insertTransferDirect(
            $branchA->id, $branchA->id, $whA1, $whA2, $transferACode,
        );
        $this->insertTransferDirect(
            $branchB->id, $branchB->id, $whB1, $whB2, $transferBCode,
        );

        // Non-admin user in Branch A — should only see Branch A transfers.
        $branchAUser = $this->makeRoleUser('warehouse_manager', [], [], $branchA);

        $response = $this->actingAs($branchAUser)
            ->withSession(['branch_id' => $branchA->id])
            ->get(route('admin.warehouse-transfers.index'));

        $response->assertOk();
        $response->assertSee($transferACode);
        $response->assertDontSee($transferBCode);
    }

    // ====================================================================
    // Test 2: Non-admin user cannot confirm transfers from other branches
    // ====================================================================

    public function test_user_cannot_confirm_transfers_from_other_branches(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA1 = $this->insertWarehouse($branchA->id);
        $whA2 = $this->insertWarehouse($branchA->id);

        // Create a draft transfer in Branch A via direct DB insert.
        $transferAId = $this->insertTransferDirect(
            $branchA->id, $branchA->id, $whA1, $whA2,
            'WT-CONFIRM-' . substr(uniqid(), -4), 'draft',
        );

        // Non-admin user in Branch B — should NOT be able to confirm
        // Branch A's transfer (BranchScope makes it invisible → 404).
        $branchBUser = $this->makeRoleUser('warehouse_manager', [], [], $branchB);

        $response = $this->actingAs($branchBUser)
            ->withSession(['branch_id' => $branchB->id])
            ->post(route('admin.warehouse-transfers.confirm', $transferAId));

        // WarehouseTransferBranchScope filters findOrFail() → ModelNotFoundException → 404.
        $response->assertNotFound();
    }

    // ====================================================================
    // Test 3: Admin sees all transfers regardless of branch
    // ====================================================================

    public function test_admin_can_see_all_branch_transfers(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $whA1 = $this->insertWarehouse($branchA->id);
        $whA2 = $this->insertWarehouse($branchA->id);
        $whB1 = $this->insertWarehouse($branchB->id);
        $whB2 = $this->insertWarehouse($branchB->id);

        $transferACode = 'WT-ADMIN-A-' . substr(uniqid(), -4);
        $transferBCode = 'WT-ADMIN-B-' . substr(uniqid(), -4);

        $this->insertTransferDirect(
            $branchA->id, $branchA->id, $whA1, $whA2, $transferACode,
        );
        $this->insertTransferDirect(
            $branchB->id, $branchB->id, $whB1, $whB2, $transferBCode,
        );

        // Admin user — WarehouseTransferBranchScope bypasses for admin.
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->withSession(['branch_id' => $branchA->id])
            ->get(route('admin.warehouse-transfers.index'));

        $response->assertOk();
        $response->assertSee($transferACode);
        $response->assertSee($transferBCode);
    }
}
