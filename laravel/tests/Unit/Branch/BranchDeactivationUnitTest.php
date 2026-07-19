<?php

namespace Tests\Unit\Branch;

use App\Http\Controllers\Admin\BranchController;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\TestCase;

/**
 * Branch Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on BranchController via reflection.
 *
 * This bypasses the HTTP layer for fast, focused testing of the 5
 * safety checks defined in BranchController::canDeactivate().
 *
 * The 5 checks mirror legacy BranchModel::canDeactivateBranch():
 *   1. No active warehouses assigned to this branch
 *   2. No active employees assigned to this branch
 *   3. No open (non-reversed, non-cancelled) sales invoices
 *   4. No pending branch demands involving this branch
 *   5. No active user accounts linked to employees in this branch
 *
 * Tested in isolation so failures pinpoint exactly which check
 * misbehaved, without the noise of HTTP redirects / sessions.
 */
class BranchDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies;

    private BranchController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(BranchController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Branch $branch): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $branch);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_branch_with_no_dependencies(): void
    {
        $branch = Branch::factory()->create();

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_branch_with_only_inactive_dependencies(): void
    {
        $branch = Branch::factory()->create();

        $this->insertWarehouse($branch->id, isActive: false, code: 'WH-UNIT-01');

        Employee::factory()->forBranch($branch->id)->inactive()->create();

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok'], 'Inactive dependencies should not block deactivation');
    }

    // ====================================================================
    // Blocker 1: Active warehouses
    // ====================================================================

    public function test_cannot_deactivate_branch_with_active_warehouse(): void
    {
        $branch = Branch::factory()->create();

        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-UNIT-02');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1 active warehouse', $result['message']);
    }

    public function test_message_lists_warehouse_count_when_multiple(): void
    {
        $branch = Branch::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->insertWarehouse($branch->id, isActive: true, code: "WH-COUNT-{$i}");
        }

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('5 active warehouse(s)', $result['message']);
    }

    // ====================================================================
    // Blocker 2: Active employees
    // ====================================================================

    public function test_cannot_deactivate_branch_with_active_employee(): void
    {
        $branch = Branch::factory()->create();

        Employee::factory()->forBranch($branch->id)->create();

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('employee', $result['message']);
    }

    public function test_inactive_employee_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();

        Employee::factory()->forBranch($branch->id)->inactive()->create();

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 3: Open sales invoices
    // ====================================================================

    public function test_cannot_deactivate_branch_with_open_invoice(): void
    {
        $branch = Branch::factory()->create();

        $this->insertSalesInvoice($branch->id, status: 'confirmed', isReversed: false, invoiceCode: 'INV-UNIT-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    public function test_cancelled_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();

        $this->insertSalesInvoice($branch->id, status: 'cancelled', isReversed: false, invoiceCode: 'INV-CAN-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    public function test_reversed_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();

        $this->insertSalesInvoice($branch->id, status: 'reversed', isReversed: true, invoiceCode: 'INV-REV-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    public function test_is_reversed_true_blocks_deactivation_even_if_status_not_cancelled(): void
    {
        // Test the WHERE clause: whereNotIn('status', ['cancelled', 'reversed'])
        // Even if is_reversed=false, if status is 'draft' the invoice counts.
        $branch = Branch::factory()->create();

        $this->insertSalesInvoice($branch->id, status: 'draft', isReversed: false, invoiceCode: 'INV-DRF-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    // ====================================================================
    // Blocker 4: Pending branch demands
    // ====================================================================

    public function test_pending_demand_as_source_branch_blocks_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $other  = Branch::factory()->create();

        $this->insertBranchDemand($branch->id, $other->id, status: 'pending', demandCode: 'BD-UNIT-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('branch demand', $result['message']);
    }

    public function test_pending_demand_as_destination_branch_blocks_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $other  = Branch::factory()->create();

        $this->insertBranchDemand($other->id, $branch->id, status: 'pending', demandCode: 'BD-UNIT-002');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
    }

    public function test_fulfilled_demand_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $other  = Branch::factory()->create();

        $this->insertBranchDemand($branch->id, $other->id, status: 'fulfilled', demandCode: 'BD-FUL-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    public function test_cancelled_demand_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $other  = Branch::factory()->create();

        $this->insertBranchDemand($branch->id, $other->id, status: 'cancelled', demandCode: 'BD-CAN-001');

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 5: Active user accounts linked to branch's employees
    // ====================================================================

    public function test_active_user_account_blocks_deactivation(): void
    {
        $branch = Branch::factory()->create();

        $employee = Employee::factory()->forBranch($branch->id)->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('user account', $result['message']);
    }

    public function test_inactive_user_account_does_not_block_deactivation(): void
    {
        // Note: the active employee itself triggers blocker 2, so the overall
        // result will be ok=false. The point of this test is to verify the
        // message does NOT include "user account" — i.e. the inactive user
        // was correctly excluded from the active-user count.
        $branch = Branch::factory()->create();

        $employee = Employee::factory()->forBranch($branch->id)->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => false]);

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('user account', $result['message']);
        $this->assertStringContainsString('employee', $result['message']);
    }

    public function test_user_account_with_inactive_employee_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();

        $employee = Employee::factory()->forBranch($branch->id)->inactive()->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $result = $this->callCanDeactivate($branch);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Combined blockers — message enumerates all blockers
    // ====================================================================

    public function test_message_lists_all_blocker_types_at_once(): void
    {
        $branch = Branch::factory()->create();
        $other  = Branch::factory()->create();

        // All 5 blockers
        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-ALL-01');

        Employee::factory()->forBranch($branch->id)->create();

        $this->insertSalesInvoice($branch->id, status: 'confirmed', isReversed: false, invoiceCode: 'INV-ALL-01');

        $this->insertBranchDemand($branch->id, $other->id, status: 'pending', demandCode: 'BD-ALL-01');

        $employee = Employee::factory()->forBranch($branch->id)->create();
        User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('warehouse', $result['message']);
        $this->assertStringContainsString('employee', $result['message']);
        $this->assertStringContainsString('sales invoice', $result['message']);
        $this->assertStringContainsString('branch demand', $result['message']);
        $this->assertStringContainsString('user account', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_ok_true_with_empty_message_when_no_blockers(): void
    {
        $branch = Branch::factory()->create();

        $result = $this->callCanDeactivate($branch);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $branch = Branch::factory()->create();

        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-CONTRACT-01');

        $result = $this->callCanDeactivate($branch);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }
}
