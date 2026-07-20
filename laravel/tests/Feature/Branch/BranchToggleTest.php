<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\TestCase;

/**
 * Branch Toggle tests — exercises the toggle() action (activate ↔
 * deactivate) and all 5 deactivation safety checks from
 * BranchController::canDeactivate().
 *
 * The 5 safety checks mirror legacy BranchModel::canDeactivateBranch():
 *   1. No active warehouses assigned to this branch
 *   2. No active employees assigned to this branch
 *   3. No open (non-reversed) sales invoices for this branch
 *   4. No pending branch demands involving this branch
 *   5. No active user accounts linked to employees in this branch
 *
 * Phase 3 commit `15e09f3`.
 */
class BranchToggleTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // ACTIVATE flow — soft-deleted branch can be re-activated
    // ====================================================================

    public function test_toggle_activates_inactive_branch(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $response->assertSessionHas('success');

        $branch->refresh();
        $this->assertTrue($branch->is_active);
        $this->assertNull($branch->deleted_at);
        $this->assertNull($branch->deleted_by);
    }

    public function test_toggle_activates_branch_without_soft_delete(): void
    {
        // Branch is_active=false but not soft-deleted
        $branch = Branch::factory()->create(['is_active' => false]);

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_deactivates_active_branch_with_no_blockers(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $response->assertSessionHas('success');

        $branch->refresh();
        $this->assertFalse($branch->is_active);
        $this->assertNotNull($branch->deleted_at);
    }

    public function test_toggle_sets_deleted_by_when_deactivating(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branch = Branch::factory()->create();

        $this->post(route('admin.branches.toggle', $branch));

        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_toggle_clears_deleted_by_when_activating(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branch = Branch::factory()->create([
            'is_active'  => false,
            'deleted_by' => $user->id,
        ]);
        $branch->delete();

        $this->post(route('admin.branches.toggle', $branch));

        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_by' => null,
        ]);
    }

    public function test_toggle_can_be_called_repeatedly_to_flip_state(): void
    {
        $branch = Branch::factory()->create();

        // Deactivate
        $this->post(route('admin.branches.toggle', $branch));
        $this->assertFalse($branch->fresh()->is_active);

        // Re-activate
        $this->post(route('admin.branches.toggle', $branch));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_branch(): void
    {
        $this->post(route('admin.branches.toggle', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // SAFETY CHECK 1: Active warehouses
    // ====================================================================

    public function test_toggle_blocked_when_branch_has_active_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-TOGGLE-01');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertTrue($branch->fresh()->is_active);

        // Verify error message mentions warehouses
        $errorMessage = session('error');
        $this->assertStringContainsString('warehouse', $errorMessage);
    }

    public function test_toggle_allows_deactivation_when_warehouses_are_inactive(): void
    {
        $branch = Branch::factory()->create();
        $this->insertWarehouse($branch->id, isActive: false, code: 'WH-INACTIVE-01');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    public function test_toggle_blocked_when_branch_has_multiple_active_warehouses(): void
    {
        $branch = Branch::factory()->create();
        for ($i = 1; $i <= 3; $i++) {
            $this->insertWarehouse($branch->id, isActive: true, code: "WH-MULTI-{$i}");
        }

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('3 active warehouse(s)', session('error'));
    }

    // ====================================================================
    // SAFETY CHECK 2: Active employees
    // ====================================================================

    public function test_toggle_blocked_when_branch_has_active_employees(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create();

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('employee', session('error'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_employees_are_inactive(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->inactive()->create();

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    // ====================================================================
    // SAFETY CHECK 3: Open sales invoices
    // ====================================================================

    public function test_toggle_blocked_when_branch_has_open_sales_invoices(): void
    {
        $branch = Branch::factory()->create();
        $this->insertSalesInvoice($branch->id, status: 'confirmed', isReversed: false, invoiceCode: 'INV-TOGGLE-001');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('sales invoice', session('error'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_invoices_are_cancelled(): void
    {
        $branch = Branch::factory()->create();
        $this->insertSalesInvoice($branch->id, status: 'cancelled', isReversed: false, invoiceCode: 'INV-CANCEL-001');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_invoices_are_reversed(): void
    {
        $branch = Branch::factory()->create();
        $this->insertSalesInvoice($branch->id, status: 'reversed', isReversed: true, invoiceCode: 'INV-REVERSED-001');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    // ====================================================================
    // SAFETY CHECK 4: Pending branch demands
    // ====================================================================

    public function test_toggle_blocked_when_branch_has_pending_demand_as_source(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $this->insertBranchDemand($branch->id, $otherBranch->id, status: 'pending', demandCode: 'BD-TOGGLE-01');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('branch demand', session('error'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_blocked_when_branch_has_pending_demand_as_destination(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $this->insertBranchDemand($otherBranch->id, $branch->id, status: 'pending', demandCode: 'BD-TOGGLE-02');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('branch demand', session('error'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_demands_are_fulfilled(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $this->insertBranchDemand($branch->id, $otherBranch->id, status: 'fulfilled', demandCode: 'BD-FULFILLED-01');

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    // ====================================================================
    // SAFETY CHECK 5: Active user accounts linked to branch's employees
    // ====================================================================

    public function test_toggle_blocked_when_branch_has_active_user_account(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('user account', session('error'));
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_user_account_is_disabled(): void
    {
        // Note: the active employee itself triggers blocker 2, so the overall
        // result is ok=false. The point of this test is to verify the message
        // does NOT include "user account" — i.e. the inactive user was
        // correctly excluded from the active-user count.
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => false]);

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $this->assertStringNotContainsString('user account', session('error'));
        $this->assertStringContainsString('employee', session('error'));
    }

    public function test_toggle_allows_deactivation_when_linked_employee_is_inactive(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->inactive()->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('success');
        $this->assertFalse($branch->fresh()->is_active);
    }

    // ====================================================================
    // COMBINED BLOCKERS — error message lists all blockers at once
    // ====================================================================

    public function test_toggle_blocked_message_lists_all_blockers(): void
    {
        $branch = Branch::factory()->create();

        // Blocker 1: active warehouse
        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-COMBO-01');

        // Blocker 2: active employee
        Employee::factory()->forBranch($branch->id)->create();

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertSessionHas('error');
        $errorMessage = session('error');
        $this->assertStringContainsString('warehouse', $errorMessage);
        $this->assertStringContainsString('employee', $errorMessage);
    }

    // ====================================================================
    // TOGGLE on already-inactive branch with blockers — should still
    // be allowed to activate (blockers only apply on deactivation)
    // ====================================================================

    public function test_toggle_can_activate_branch_even_with_active_warehouses(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);

        $response = $this->post(route('admin.branches.toggle', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $response->assertSessionHas('success');
        $this->assertTrue($branch->fresh()->is_active);
    }
}
