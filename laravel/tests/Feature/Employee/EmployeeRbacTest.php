<?php

namespace Tests\Feature\Employee;

use App\Models\Branch;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Employee RBAC tests — verifies that every Employee route enforces the
 * correct role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 12):
 *
 *   GET    /admin/employees                       admin, manager, hr
 *   GET    /admin/employees/{id}                  admin, manager, hr
 *   GET    /admin/employees/{id}/account          admin, manager, hr
 *   GET    /admin/employees/create                admin
 *   POST   /admin/employees                       admin
 *   GET    /admin/employees/{id}/edit             admin
 *   PUT    /admin/employees/{id}                  admin
 *   DELETE /admin/employees/{id}                  admin
 *   POST   /admin/employees/{id}/restore          admin
 *   POST   /admin/employees/{id}/toggle           admin
 *   GET    /admin/employees/audit                 admin
 *
 * Employees are HR-domain master data: hr role has read access alongside
 * admin and manager; accountant/salesman/warehouse_manager/dispatcher do not.
 */
class EmployeeRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one employee + branch so resource routes resolve.
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create();
    }

    // ====================================================================
    // READ routes — admin, manager, hr allowed
    // ====================================================================

    public function test_admin_can_access_employee_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.employees.index'))
            ->assertOk();
    }

    public function test_manager_can_access_employee_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.employees.index'))
            ->assertOk();
    }

    public function test_hr_can_access_employee_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.employees.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_employee_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.employees.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_employee_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.employees.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_employee_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.employees.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_employee_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.employees.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.employees.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_hr(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.employees.show', $employee))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.employees.show', $employee))->assertOk();

        $this->actingAsRole('hr');
        $this->get(route('admin.employees.show', $employee))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.employees.show', $employee))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_warehouse_manager(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.employees.show', $employee))
            ->assertRedirect(route('dashboard'));
    }

    public function test_account_route_allows_admin_manager_hr(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.employees.account', $employee))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.employees.account', $employee))->assertOk();

        $this->actingAsRole('hr');
        $this->get(route('admin.employees.account', $employee))->assertOk();
    }

    public function test_account_route_denies_salesman(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.employees.account', $employee))
            ->assertRedirect(route('dashboard'));
    }

    public function test_account_route_denies_accountant(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('accountant')
            ->get(route('admin.employees.account', $employee))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_employee_routes(): void
    {
        $employee = Employee::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.employees.index'))->assertOk();
        $this->get(route('admin.employees.show', $employee))->assertOk();
        $this->get(route('admin.employees.account', $employee))->assertOk();
        $this->get(route('admin.employees.create'))->assertOk();
        $this->get(route('admin.employees.edit', $employee))->assertOk();
        $this->get(route('admin.employees.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.employees.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.employees.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_create_form(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.employees.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_create_form(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.employees.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.employees.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_employee(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.employees.store'), [
                'name'      => 'Unauthorized Employee',
                'role'      => 'salesman',
                'branch_id' => $branch->id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('employees', ['name' => 'Unauthorized Employee']);
    }

    public function test_hr_cannot_store_employee(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('hr')
            ->post(route('admin.employees.store'), [
                'name'      => 'Unauthorized Employee 2',
                'role'      => 'salesman',
                'branch_id' => $branch->id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('employees', ['name' => 'Unauthorized Employee 2']);
    }

    public function test_salesman_cannot_store_employee(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('salesman')
            ->post(route('admin.employees.store'), [
                'name'      => 'Unauthorized Employee 3',
                'role'      => 'salesman',
                'branch_id' => $branch->id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('employees', ['name' => 'Unauthorized Employee 3']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('manager')
            ->get(route('admin.employees.edit', $employee))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_employee(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('manager')
            ->put(route('admin.employees.update', $employee), [
                'name'      => 'Hacked Name',
                'role'      => $employee->role,
                'branch_id' => $employee->branch_id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('employees', ['name' => 'Hacked Name']);
    }

    public function test_hr_cannot_update_employee(): void
    {
        $employee = Employee::first();

        $this->actingAsRole('hr')
            ->put(route('admin.employees.update', $employee), [
                'name'      => 'Hacked Name 2',
                'role'      => $employee->role,
                'branch_id' => $employee->branch_id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('employees', ['name' => 'Hacked Name 2']);
    }

    public function test_manager_cannot_destroy_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.employees.destroy', $employee))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id'         => $employee->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_hr_cannot_destroy_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('hr')
            ->delete(route('admin.employees.destroy', $employee))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('employees', [
            'id'         => $employee->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('manager')
            ->post(route('admin.employees.toggle', $employee))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_hr_cannot_toggle_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('hr')
            ->post(route('admin.employees.toggle', $employee))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_manager_cannot_restore_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();
        $employee->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.employees.restore', $employee))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($employee->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.employees.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_audit_page(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.employees.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.employees.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.employees.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.employees.destroy', $employee))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.employees.store'), [
                'name'      => 'JSON 403',
                'role'      => 'salesman',
                'branch_id' => $branch->id,
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.employees.destroy', $employee))
            ->assertUnauthorized();
    }
}
