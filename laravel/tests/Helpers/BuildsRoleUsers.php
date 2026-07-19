<?php

namespace Tests\Helpers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Branch Phase 7 test helpers.
 *
 * Provides convenience methods for creating authenticated role-based users
 * (admin, manager, warehouse_manager, salesman, etc.) for use in RBAC tests.
 *
 * Each helper creates a Branch + Employee (with role) + User chain, mirroring
 * the legacy schema where the role is stored on Employee, not User.
 */
trait BuildsRoleUsers
{
    /**
     * Create and return a User with the given role, attached to a Branch.
     *
     * @param  string  $role  One of config/roles.php canonical roles.
     * @param  array   $employeeOverrides  Override Employee attributes.
     * @param  array   $userOverrides      Override User attributes.
     */
    protected function makeRoleUser(
        string $role,
        array $employeeOverrides = [],
        array $userOverrides = [],
        ?Branch $branch = null,
    ): User {
        $branch = $branch ?? Branch::factory()->create();

        $employee = Employee::factory()
            ->forBranch($branch->id)
            ->withRole($role)
            ->create($employeeOverrides);

        $user = User::factory()
            ->forEmployee($employee->id)
            ->create(array_merge([
                'username'      => strtolower($role) . '_' . $employee->id,
                'password_hash' => Hash::make('password'),
            ], $userOverrides));

        return $user->fresh(['employee.branch']);
    }

    /**
     * Create and authenticate as a User with the given role.
     */
    protected function actingAsRole(string $role, ?Branch $branch = null): User
    {
        $user = $this->makeRoleUser($role, [], [], $branch);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Convenience: create a superadmin user (passes any role check).
     */
    protected function superadmin(): User
    {
        return $this->makeRoleUser('superadmin');
    }

    /**
     * Convenience: create an admin user (passes admin-tier routes).
     */
    protected function adminUser(): User
    {
        return $this->makeRoleUser('admin');
    }

    /**
     * Convenience: create a manager user (read-only on branches write routes).
     */
    protected function managerUser(): User
    {
        return $this->makeRoleUser('manager');
    }

    /**
     * Convenience: create a warehouse_manager user (read access to branches).
     */
    protected function warehouseManagerUser(): User
    {
        return $this->makeRoleUser('warehouse_manager');
    }

    /**
     * Convenience: create a salesman user (no branch access).
     */
    protected function salesmanUser(): User
    {
        return $this->makeRoleUser('salesman');
    }

    /**
     * Convenience: create an accountant user (no branch access).
     */
    protected function accountantUser(): User
    {
        return $this->makeRoleUser('accountant');
    }
}
