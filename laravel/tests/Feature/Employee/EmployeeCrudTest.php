<?php

namespace Tests\Feature\Employee;

use App\Models\Branch;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsEmployeeDependencies;
use Tests\TestCase;

/**
 * Employee CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle, account.
 *
 * Validates EmployeeController (Phase 12: canDeactivate safety check +
 * auto-generated employee_code + pre-validation normalization + role CHECK
 * with 'user' value) inheriting from BaseMasterDataController.
 */
class EmployeeCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsEmployeeDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Convenience: create a Branch + Employee pair.
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        $branch = Branch::factory()->create();

        return Employee::factory()->forBranch($branch->id)->create($overrides);
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_employees(): void
    {
        $this->makeEmployee();
        $this->makeEmployee();
        $this->makeEmployee();

        $response = $this->get(route('admin.employees.index'));

        $response->assertOk();
        $response->assertViewIs('admin.employees.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_employees(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        $response = $this->get(route('admin.employees.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        $this->makeEmployee();
        $this->makeEmployee();

        $response = $this->get(route('admin.employees.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_employee_count(): void
    {
        $this->makeEmployee();
        $this->makeEmployee();
        $this->makeEmployee(['is_active' => false]);

        $response = $this->get(route('admin.employees.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_created_employee(): void
    {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.employees.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $employee->id);
        $this->assertNotNull($row, 'DataTables response should include the created employee');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.employees.create'));

        $response->assertOk();
        $response->assertViewIs('admin.employees.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'branches', 'roles']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_employee_and_redirects_to_show(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'EMP-ST-001',
            'name'          => 'Test Employee Store',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'phone'         => '01711000000',
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP-ST-001',
            'name'          => 'Test Employee Store',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'phone'         => '01711000000',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'EMP-REDIR-01',
            'name'          => 'Show Redirect Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $employee = Employee::where('employee_code', 'EMP-REDIR-01')->first();
        $response->assertRedirect(route('admin.employees.show', $employee));
        $response->assertSessionHas('success');
    }

    public function test_store_auto_generates_employee_code_when_blank(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            // employee_code intentionally omitted
            'name'      => 'Auto Code Employee',
            'role'      => 'salesman',
            'branch_id' => $branch->id,
        ]);

        $response->assertRedirect();
        $employee = Employee::where('name', 'Auto Code Employee')->first();
        $this->assertNotNull($employee);
        $this->assertMatchesRegularExpression('/^EMP-\d{6}$/', $employee->employee_code);
    }

    public function test_store_auto_generates_employee_code_when_empty_string(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.employees.store'), [
            'employee_code' => '',
            'name'          => 'Empty Code Employee',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $employee = Employee::where('name', 'Empty Code Employee')->first();
        $this->assertNotNull($employee);
        $this->assertNotEmpty($employee->employee_code);
    }

    public function test_store_fails_on_duplicate_employee_code(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'DUP-EMP-001']);

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'DUP-EMP-001',
            'name'          => 'Duplicate Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $response->assertSessionHasErrors('employee_code');
    }

    public function test_store_fails_when_name_missing(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'MISSING-NAME-EMP-01',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_store_fails_when_role_missing(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'MISSING-ROLE-EMP-01',
            'name'          => 'Missing Role',
            'branch_id'     => $branch->id,
        ]);

        $response->assertSessionHasErrors('role');
    }

    public function test_store_fails_when_branch_id_missing(): void
    {
        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'MISSING-BRANCH-EMP-01',
            'name'          => 'Missing Branch',
            'role'          => 'salesman',
        ]);

        $response->assertSessionHasErrors('branch_id');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'MIN-EMP-01',
            'name'          => 'Minimal Employee',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            // phone, email, address, salary, joining_date omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'MIN-EMP-01',
            'name'          => 'Minimal Employee',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);
    }

    public function test_store_stores_numeric_salary(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'EMP-SAL-01',
            'name'          => 'Salary Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'salary'        => 25000.75,
        ]);

        $employee = Employee::where('employee_code', 'EMP-SAL-01')->first();
        $this->assertEquals('25000.75', (string) $employee->salary);
    }

    public function test_store_uppercases_employee_code_before_unique_check(): void
    {
        // Phase 12: employee_code is uppercased + trimmed BEFORE validation.
        // 'lower-01' becomes 'LOWER-01' before unique check.
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'UPPER-01']);

        // 'upper-01' should collide after normalization
        $this->post(route('admin.employees.store'), [
            'employee_code' => 'upper-01',
            'name'          => 'Case Collision Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('employee_code');
    }

    public function test_store_accepts_user_role_value(): void
    {
        // Phase 12: 'user' role was previously rejected by the CHECK constraint.
        // The role CHECK migration now accepts it.
        $branch = Branch::factory()->create();

        $response = $this->post(route('admin.employees.store'), [
            'employee_code' => 'EMP-USER-ROLE-01',
            'name'          => 'Generic User Role',
            'role'          => 'user',
            'branch_id'     => $branch->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP-USER-ROLE-01',
            'role'          => 'user',
        ]);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_employee_details(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.show', $employee));

        $response->assertOk();
        $response->assertViewIs('admin.employees.show');
        $response->assertViewHas('item');
        $this->assertEquals($employee->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_branch_and_user(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.show', $employee));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('branch'));
        $this->assertTrue($item->relationLoaded('user'));
    }

    public function test_show_works_for_soft_deleted_employee(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.employees.show', $employee));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_employee(): void
    {
        $this->get(route('admin.employees.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_employee(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.edit', $employee));

        $response->assertOk();
        $response->assertViewIs('admin.employees.edit');
        $response->assertViewHas('item');
        $this->assertEquals($employee->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_employee_and_redirects_to_show(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Updated Employee Name',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));
        $this->assertDatabaseHas('employees', [
            'id'   => $employee->id,
            'name' => 'Updated Employee Name',
        ]);
    }

    public function test_update_allows_changing_employee_code_to_unique_value(): void
    {
        $employee = $this->makeEmployee(['employee_code' => 'OLD-EMP-01']);

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'NEW-EMP-01',
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', [
            'id'             => $employee->id,
            'employee_code' => 'NEW-EMP-01',
        ]);
    }

    public function test_update_fails_on_duplicate_employee_code_from_other_employee(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'TAKEN-EMP-01']);
        $employee = $this->makeEmployee(['employee_code' => 'OWN-EMP-01']);

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'TAKEN-EMP-01',
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);

        $response->assertSessionHasErrors('employee_code');
    }

    public function test_update_allows_keeping_own_employee_code(): void
    {
        $employee = $this->makeEmployee(['employee_code' => 'KEEP-EMP-01']);

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'KEEP-EMP-01',
            'name'          => 'New Name Same Code',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employees', [
            'id'             => $employee->id,
            'employee_code' => 'KEEP-EMP-01',
            'name'          => 'New Name Same Code',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Employee with outstanding advance → deactivation should be blocked
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 250.00, 'debit');

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_update_deactivates_employee_when_no_blockers(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_update_blocked_when_employee_has_active_user_account(): void
    {
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $response = $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($employee->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_employee_with_no_blockers(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->delete(route('admin.employees.destroy', $employee));

        $response->assertRedirect(route('admin.employees.index'));
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertNotNull($employee->deleted_at);
        $this->assertFalse($employee->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $employee = $this->makeEmployee();

        $this->delete(route('admin.employees.destroy', $employee));

        $this->assertDatabaseHas('employees', [
            'id'         => $employee->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_employee_has_outstanding_advance(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 1000.00, 'debit');

        $response = $this->delete(route('admin.employees.destroy', $employee));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('employees', [
            'id'         => $employee->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_employee_has_active_user_account(): void
    {
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $response = $this->delete(route('admin.employees.destroy', $employee));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('employees', [
            'id'         => $employee->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_employee(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        $response = $this->post(route('admin.employees.restore', $employee));

        $response->assertRedirect(route('admin.employees.show', $employee));
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertNull($employee->deleted_at);
        $this->assertNull($employee->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_employee(): void
    {
        $employee = $this->makeEmployee(); // not deleted

        $response = $this->post(route('admin.employees.restore', $employee));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_employee(): void
    {
        $this->post(route('admin.employees.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // ACCOUNT (read-only employee detail hub)
    // ====================================================================

    public function test_account_displays_employee_account_view(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->get(route('admin.employees.account', $employee));

        $response->assertOk();
        $response->assertViewIs('admin.employees.account');
        $response->assertViewHas(['item', 'salarySummary']);
        $this->assertEquals($employee->id, $response->viewData('item')->id);
    }

    public function test_account_works_for_soft_deleted_employee(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        $response = $this->get(route('admin.employees.account', $employee));

        $response->assertOk();
    }

    public function test_account_returns_404_for_unknown_employee(): void
    {
        $this->get(route('admin.employees.account', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_employee_count_increments_after_store(): void
    {
        $branch = Branch::factory()->create();
        $initialCount = Employee::count();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'COUNT-EMP-01',
            'name'          => 'Count Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ]);

        $this->assertEquals($initialCount + 1, Employee::count());
    }

    public function test_soft_deleted_employee_excluded_from_default_index_query(): void
    {
        $toDelete = $this->makeEmployee(['name' => 'Hide Me From Default']);
        $keep = $this->makeEmployee(['name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.employees.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one employee');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 12)
    // ====================================================================

    public function test_toggle_deactivates_active_employee_with_no_blockers(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->post(route('admin.employees.toggle', $employee));

        $response->assertRedirect(route('admin.employees.index'));
        $response->assertSessionHas('success');

        $employee->refresh();
        $this->assertFalse($employee->is_active);
        $this->assertNotNull($employee->deleted_at);
    }

    public function test_toggle_activates_inactive_employee(): void
    {
        $employee = $this->makeEmployee();
        $employee->delete();

        $response = $this->post(route('admin.employees.toggle', $employee));

        $response->assertRedirect(route('admin.employees.index'));
        $employee->refresh();
        $this->assertTrue($employee->is_active);
        $this->assertNull($employee->deleted_at);
    }

    public function test_toggle_blocked_when_employee_has_outstanding_balance(): void
    {
        $employee = $this->makeEmployee();
        $this->insertEmployeeLedger($employee->id, 100.00, 'debit');

        $response = $this->post(route('admin.employees.toggle', $employee));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('employee balance', session('error'));
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_toggle_blocked_when_employee_has_active_user_account(): void
    {
        $employee = $this->makeEmployee();
        $this->insertUserForEmployee($employee->id, ['is_active' => true]);

        $response = $this->post(route('admin.employees.toggle', $employee));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('user account', session('error'));
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_employee(): void
    {
        $this->post(route('admin.employees.toggle', 999999))
            ->assertNotFound();
    }
}
