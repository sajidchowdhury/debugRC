<?php

namespace Tests\Feature\Employee;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Auth\CredentialVersion;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Phase 16: Employee Credential Bump tests.
 *
 * Verifies that when an employee's role or branch_id is changed via the
 * EmployeeController::update() method, the linked user's credential_version
 * is bumped — invalidating all active sessions for that user.
 *
 * This closes the security gap documented in the administration audit:
 * "Employee credential bump missing — editing role/branch doesn't invalidate
 * sessions; demoted users keep old access."
 *
 * The CheckCredentialVersion middleware runs on every authenticated request
 * and compares the session's stored credential_version against the DB value.
 * If they differ, the session is destroyed and the user is forced to re-login.
 */
class EmployeeCredentialBumpTest extends TestCase
{
    use BuildsRoleUsers;

    public function test_role_change_bumps_credential_version(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        // Create an employee with a linked user account.
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        $user = User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $originalVersion = $user->credential_version;

        // Change the employee's role from salesman to manager.
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => 'manager',
            'branch_id'     => $branch->id,
            'is_active'     => true,
        ])->assertRedirect();

        // The linked user's credential_version should have been bumped.
        $user->refresh();
        $this->assertGreaterThan($originalVersion, $user->credential_version, 'credential_version should increase on role change');
    }

    public function test_branch_change_bumps_credential_version(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch1->id)->withRole('salesman')->create();
        $user = User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $originalVersion = $user->credential_version;

        // Change the employee's branch.
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => 'salesman',
            'branch_id'     => $branch2->id,
            'is_active'     => true,
        ])->assertRedirect();

        $user->refresh();
        $this->assertGreaterThan($originalVersion, $user->credential_version, 'credential_version should increase on branch change');
    }

    public function test_no_role_or_branch_change_does_not_bump_credential_version(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        $user = User::factory()->forEmployee($employee->id)->create(['is_active' => true]);

        $originalVersion = $user->credential_version;

        // Update only the name — should NOT bump credential_version.
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Updated Name Only',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'is_active'     => true,
        ])->assertRedirect();

        $user->refresh();
        $this->assertEquals($originalVersion, $user->credential_version, 'credential_version should NOT change when role/branch are unchanged');
    }

    public function test_credential_bump_skipped_when_employee_has_no_user_account(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        // Note: NO user account linked to this employee.

        // Should not throw an error even though there's no user to bump.
        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => 'manager',
            'branch_id'     => $branch->id,
            'is_active'     => true,
        ])->assertRedirect();

        // Employee should still be updated.
        $employee->refresh();
        $this->assertEquals('manager', $employee->role);
    }

    public function test_credential_version_service_bump_works(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        $user = User::factory()->forEmployee($employee->id)->create(['credential_version' => 5]);

        CredentialVersion::bump($user->id);

        $user->refresh();
        $this->assertEquals(6, $user->credential_version);
    }

    public function test_credential_version_service_fetch_works(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        $user = User::factory()->forEmployee($employee->id)->create(['credential_version' => 42]);

        $fetched = CredentialVersion::fetch($user->id);

        $this->assertEquals('42', $fetched);
    }

    public function test_credential_version_service_is_valid(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        $user = User::factory()->forEmployee($employee->id)->create(['credential_version' => 10]);

        $this->assertTrue(CredentialVersion::isValid($user->id, '10'));
        $this->assertFalse(CredentialVersion::isValid($user->id, '9'));
        $this->assertFalse(CredentialVersion::isValid($user->id, '11'));
    }
}
