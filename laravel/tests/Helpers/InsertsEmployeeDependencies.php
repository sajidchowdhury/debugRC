<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Employee Phase 12 test helpers — direct table inserts for employee-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy (or that would force pulling in the entire Payroll/User
 * module).
 *
 * Used by:
 *  - tests/Unit/Employee/EmployeeDeactivationUnitTest
 *  - tests/Feature/Employee/EmployeeCrudTest
 *  - tests/Feature/Employee/EmployeeAuditTest
 *  - tests/Feature/Employee/EmployeeValidationTest
 *
 * NOTE: Tests\Helpers\BuildsRoleUsers creates Employee + User chains via
 * factories for RBAC tests. The Employee CRUD/Audit/Validation test classes
 * intentionally use ONLY this trait (not BuildsRoleUsers) for direct table
 * inserts of employee_ledger + linked user accounts — to avoid trait-method
 * collision and to keep the deactivation-safety tests focused on raw data
 * rather than factory chains.
 */
trait InsertsEmployeeDependencies
{
    use ResolvesActiveFiscalYear;
    /**
     * Insert an employee_ledger row simulating a salary advance / repayment.
     *
     * Schema: employee_id (FK NOT NULL), transaction_date (NOT NULL),
     *         transaction_type (NOT NULL CHECK in advance/loan/repayment/
     *         salary/deduction/adjustment), debit/credit (default 0).
     *
     * Pass $type='debit'  to record an advance paid to the employee
     *                      (employee owes the company → balance > 0).
     * Pass $type='credit' to record a repayment / salary credit
     *                      (employee settles the advance → balance decreases).
     *
     * @param  string  $type  'debit' or 'credit'
     * @return int  The employee_ledger.id
     */
    protected function insertEmployeeLedger(int $employeeId, float $amount, string $type = 'debit'): int
    {
        $row = [
            'employee_id'      => $employeeId,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => $type === 'credit' ? 'repayment' : 'advance',
            'reference_type'   => $type === 'credit' ? 'repayment' : 'advance',
            'reference_id'     => 0,
            'description'      => 'Phase 12 test ledger entry',
            'created_at'       => now(),
        ];

        if ($type === 'credit') {
            $row['credit'] = $amount;
        } else {
            $row['debit'] = $amount;
        }

        return DB::table('employee_ledger')->insertGetId($row);
    }

    /**
     * Insert a users row linked to an employee — used to test the
     * `hasActiveUserAccount()` deactivation guard.
     *
     * Schema requires: employee_id (FK NOT NULL UNIQUE), username (UK),
     * password_hash (NOT NULL), is_active (default true),
     * failed_login_count (default 0), credential_version (default 1).
     *
     * @param  array  $overrides  Column overrides merged on top of the defaults.
     * @return int  The users.id
     */
    protected function insertUserForEmployee(int $employeeId, array $overrides = []): int
    {
        $username = $overrides['username'] ?? 'emp_user_' . substr(uniqid(), -6);

        return DB::table('users')->insertGetId(array_merge([
            'employee_id'        => $employeeId,
            'username'           => $username,
            'password_hash'      => password_hash('password', PASSWORD_BCRYPT),
            'is_active'          => true,
            'failed_login_count' => 0,
            'credential_version' => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
    }

    /**
     * Insert an employee_transactions row referencing an employee.
     *
     * Schema requires transaction_code (UK), transaction_date, employee_id (FK),
     * branch_id (FK NOT NULL), transaction_type (CHECK in advance/loan/
     * repayment/salary/deduction/adjustment), amount NOT NULL.
     *
     * Useful for testing future deactivation safety against open employee
     * transactions (the current canDeactivate only checks ledger balance +
     * active user account, but this helper exists for completeness so future
     * tests can exercise it).
     *
     * @return int  The employee_transactions.id
     */
    protected function insertEmployeeTransaction(
        int $employeeId,
        int $branchId,
        float $amount = 100.00,
        string $transactionType = 'advance',
        bool $isReversed = false,
    ): int {
        return DB::table('employee_transactions')->insertGetId([
            'transaction_code' => 'ET-EMP-' . substr(uniqid(), -6),
            'transaction_date' => now()->toDateString(),
            'employee_id'      => $employeeId,
            'branch_id'        => $branchId,
            'transaction_type' => $transactionType,
            'amount'           => $amount,
            'is_reversed'      => $isReversed,
            'fiscal_year_id'   => $this->resolveActiveFiscalYearId(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
