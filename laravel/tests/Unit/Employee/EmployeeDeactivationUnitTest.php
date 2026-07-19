<?php

namespace Tests\Unit\Employee;

use App\Http\Controllers\Admin\EmployeeController;
use App\Models\Branch;
use App\Models\Employee;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsEmployeeDependencies;
use Tests\TestCase;

/**
 * Employee Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on EmployeeController via reflection.
 *
 * Tests the 2 safety checks in isolation (Phase 12):
 *   1. Outstanding employee ledger balance (sum debit - credit)
 *      — debit = advances/salary paid to employee; credit = repayments.
 *      A non-zero balance means there's an unsettled advance the employee owes.
 *   2. Active linked user account (legacy `hasActiveUserAccount()` guard) —
 *      deactivating an employee with an active login would orphan the user.
 *
 * Phase 12 commit.
 */
class EmployeeDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsEmployeeDependencies;

    private EmployeeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(EmployeeController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Employee $employee): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $employee);
    }

    /**
     * Convenience: create an Employee tied to a fresh Branch.
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        $branch = Branch::factory()->create();

        return Employee::factory()->forBranch($branch->id)->create($overrides);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_employee_with_no_dependencies(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_employee_with_zero_ledger_balance(): void
    {
        $employee = $this->makeEmployee();
        // Debit 100 + Credit 100 = 0 balance
        $this->insertEmployeeLedger($employee->id, 100.00, 'debit');
        $this->insertEmployeeLedger($employee->id, 100.00, 'credit');

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_employee_with_no_ledger_entries(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_employee_with_inactive_user_account(): void
    {
        $employee = $this->makeEmployee();
        // Inactive user account does NOT block deactivation.
        $this->insertUserForEmployee($employee->id, ['is_active' => false]);

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_employee_with_soft_deleted_user_account(): void
    {
        $employee = $this->makeEmployee();
        // Soft-deleted user account does NOT block deactivation.
        $this->insertUserForEmployee($employee->id, [
            'is_active'  => true,
            'deleted_at' => now(),
        ]);

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: Outstanding employee ledger balance (debit - credit != 0)
    // ====================================================================

    public function test_cannot_deactivate_employee_with_outstanding_advance(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 500.00, 'debit');

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('employee balance', $result['message']);
        $this->assertStringContainsString('500.00', $result['message']);
    }

    public function test_cannot_deactivate_employee_with_negative_balance(): void
    {
        // Credit > Debit means the company owes the employee (e.g. salary
        // credited but not yet paid). Also a non-zero balance — blocking.
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 300.00, 'credit');

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('employee balance', $result['message']);
    }

    public function test_ledger_balance_aggregates_multiple_entries(): void
    {
        $employee = $this->makeEmployee();
        // Debit 100 + Debit 200 - Credit 50 = 250 outstanding advance
        $this->insertEmployeeLedger($employee->id, 100.00, 'debit');
        $this->insertEmployeeLedger($employee->id, 200.00, 'debit');
        $this->insertEmployeeLedger($employee->id, 50.00, 'credit');

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_zero_balance_does_not_block_deactivation(): void
    {
        // 1000 debit + 1000 credit = 0 balance → no blocker
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 1000.00, 'debit');
        $this->insertEmployeeLedger($employee->id, 1000.00, 'credit');

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Active linked user account
    // ====================================================================

    public function test_cannot_deactivate_employee_with_active_user_account(): void
    {
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('user account', $result['message']);
    }

    public function test_message_includes_count_of_active_user_accounts(): void
    {
        // The schema has UNIQUE on users.employee_id, so we can only insert
        // one row per employee. The message should still mention
        // "1 active linked user account(s)".
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1 active linked user account', $result['message']);
    }

    // ====================================================================
    // Combined blockers — both reported in single message
    // ====================================================================

    public function test_both_balance_and_user_account_blockers_appear_in_message(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 250.00, 'debit');
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('user account', $result['message']);
        $this->assertStringContainsString('employee balance', $result['message']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_user_account_blocker_returned_when_balance_is_zero(): void
    {
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('user account', $result['message']);
        $this->assertStringNotContainsString('employee balance', $result['message']);
    }

    public function test_balance_blocker_returned_when_no_active_user_account(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 750.00, 'debit');

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('employee balance', $result['message']);
        $this->assertStringNotContainsString('user account', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->callCanDeactivate($employee);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 10.00, 'debit');

        $result = $this->callCanDeactivate($employee);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->callCanDeactivate($employee);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
