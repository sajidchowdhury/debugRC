<?php

namespace Tests\Feature\Employee;

use App\Models\Branch;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Employee Validation tests — verifies the validation rules defined in
 * EmployeeController::validationRules().
 *
 * Rules (Phase 12):
 *   - employee_code: nullable|string|max:30|unique:employees,employee_code,{id}
 *   - name:          required|string|max:100
 *   - role:          required|in:superadmin,admin,manager,accountant,salesman,
 *                          warehouse_manager,dispatcher,hr,user,other
 *   - branch_id:     required|exists:branches,id
 *   - phone:         nullable|string|max:30
 *   - email:         nullable|email|max:100
 *   - address:       nullable|string
 *   - salary:        nullable|numeric|min:0
 *   - joining_date:  nullable|date
 *   - is_active:     boolean
 *   - photo:         nullable|image|mimes:jpeg,png,webp,gif|max:2048
 *
 * Phase 12 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - employee_code is uppercased + trimmed BEFORE validation
 *     (case-insensitive unique check)
 *   - name is trimmed BEFORE validation
 */
class EmployeeValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Convenience: create a fresh Branch for FK satisfaction.
     */
    private function makeBranch(): Branch
    {
        return Branch::factory()->create();
    }

    // ====================================================================
    // employee_code — nullable (auto-generated when blank)
    // ====================================================================

    public function test_employee_code_is_optional_on_store(): void
    {
        // When employee_code is omitted, the controller auto-generates one.
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'name'      => 'Auto Code Employee',
            'role'      => 'salesman',
            'branch_id' => $branch->id,
        ])->assertRedirect();
    }

    public function test_employee_code_is_optional_on_update(): void
    {
        $branch = $this->makeBranch();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        // On update, omitting employee_code does NOT trigger required error
        // because the rule is 'nullable'.
        $this->put(route('admin.employees.update', $employee), [
            'name'      => 'Updated Name Only',
            'role'      => $employee->role,
            'branch_id' => $employee->branch_id,
            'is_active' => true,
        ])->assertRedirect();
    }

    // ====================================================================
    // employee_code — max length 30
    // ====================================================================

    public function test_employee_code_max_length_30(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => str_repeat('A', 31), // 31 chars
            'name'          => 'Too Long Code',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('employee_code');
    }

    public function test_employee_code_accepts_exactly_30_chars(): void
    {
        $branch = $this->makeBranch();
        $code = str_repeat('A', 30);

        $this->post(route('admin.employees.store'), [
            'employee_code' => $code,
            'name'          => 'Exactly 30 Chars',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', ['name' => 'Exactly 30 Chars']);
    }

    // ====================================================================
    // employee_code — unique (case-insensitive after normalization)
    // ====================================================================

    public function test_employee_code_must_be_unique_on_store(): void
    {
        $branch = $this->makeBranch();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'UNIQ-EMP-001']);

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'UNIQ-EMP-001',
            'name'          => 'Duplicate',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('employee_code');
    }

    public function test_employee_code_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 12: employee_code is uppercased + trimmed BEFORE validation.
        // 'uniq-emp-002' becomes 'UNIQ-EMP-002' before unique check, so it
        // SHOULD collide with existing 'UNIQ-EMP-002'.
        $branch = $this->makeBranch();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'UNIQ-EMP-002']);

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'uniq-emp-002',
            'name'          => 'Case Collision Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('employee_code');
    }

    public function test_employee_code_normalized_to_uppercase_on_store(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'norm-emp-01',
            'name'          => 'Normalization Test',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        // Stored value should be uppercased.
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'NORM-EMP-01',
            'name'          => 'Normalization Test',
        ]);
    }

    public function test_employee_code_normalized_to_uppercase_on_update(): void
    {
        $branch = $this->makeBranch();
        $employee = Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'UPD-OLD-01']);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'upd-new-01',
            'name'          => $employee->name,
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'id'             => $employee->id,
            'employee_code' => 'UPD-NEW-01',
        ]);
    }

    public function test_employee_code_unique_allows_keeping_own_code_on_update(): void
    {
        $branch = $this->makeBranch();
        $employee = Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'KEEP-EMP-02']);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'KEEP-EMP-02',
            'name'          => 'Same Code Update',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ])->assertRedirect();
    }

    public function test_employee_code_unique_rejects_other_employees_code_on_update(): void
    {
        $branch = $this->makeBranch();
        Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'TAKEN-EMP-02']);
        $employee = Employee::factory()->forBranch($branch->id)->create(['employee_code' => 'OWN-EMP-02']);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => 'TAKEN-EMP-02',
            'name'          => 'Steal Other Code',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ])->assertSessionHasErrors('employee_code');
    }

    // ====================================================================
    // name — required, max 100
    // ====================================================================

    public function test_name_is_required_on_store(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'NAME-REQ-EMP-01',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('name');
    }

    public function test_name_is_required_on_update(): void
    {
        $branch = $this->makeBranch();
        $employee = Employee::factory()->forBranch($branch->id)->create();

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            // name omitted
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            'is_active'     => true,
        ])->assertSessionHasErrors('name');
    }

    public function test_name_max_length_100(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'NAME-LONG-EMP-01',
            'name'          => str_repeat('X', 101),
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('name');
    }

    public function test_name_accepts_exactly_100_chars(): void
    {
        $branch = $this->makeBranch();
        $name = str_repeat('X', 100);

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'NAME-100-EMP-01',
            'name'          => $name,
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'NAME-100-EMP-01',
            'name'          => $name,
        ]);
    }

    public function test_name_is_trimmed_on_store(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'TRIM-EMP-01',
            'name'          => '  Padded Name  ',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'TRIM-EMP-01',
            'name'          => 'Padded Name',
        ]);
    }

    // ====================================================================
    // role — required, must be one of the 10 canonical roles
    // ====================================================================

    public function test_role_is_required_on_store(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-REQ-EMP-01',
            'name'          => 'No Role',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('role');
    }

    public function test_role_rejects_invalid_value(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-BAD-EMP-01',
            'name'          => 'Bad Role',
            'role'          => 'ceo',
            'branch_id'     => $branch->id,
        ])->assertSessionHasErrors('role');
    }

    public function test_role_accepts_admin(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-ADMIN-01',
            'name'          => 'Admin Role',
            'role'          => 'admin',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_role_accepts_hr(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-HR-01',
            'name'          => 'HR Role',
            'role'          => 'hr',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_role_accepts_user_value(): void
    {
        // Phase 12: 'user' role was previously rejected by the CHECK
        // constraint. The role CHECK migration now accepts it.
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-USER-01',
            'name'          => 'User Role',
            'role'          => 'user',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'ROLE-USER-01',
            'role'          => 'user',
        ]);
    }

    public function test_role_accepts_superadmin(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ROLE-SUPER-01',
            'name'          => 'Super Admin Role',
            'role'          => 'superadmin',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_role_accepts_all_operational_roles(): void
    {
        $branch = $this->makeBranch();
        $roles = ['manager', 'accountant', 'salesman', 'warehouse_manager', 'dispatcher', 'other'];

        foreach ($roles as $i => $role) {
            $this->post(route('admin.employees.store'), [
                'employee_code' => 'ROLE-OP-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name'          => 'Op Role ' . $role,
                'role'          => $role,
                'branch_id'     => $branch->id,
            ])->assertRedirect();
        }
    }

    // ====================================================================
    // branch_id — required + exists
    // ====================================================================

    public function test_branch_id_is_required(): void
    {
        $this->post(route('admin.employees.store'), [
            'employee_code' => 'BR-REQ-EMP-01',
            'name'          => 'No Branch',
            'role'          => 'salesman',
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_id_must_exist(): void
    {
        $this->post(route('admin.employees.store'), [
            'employee_code' => 'BR-BAD-EMP-01',
            'name'          => 'Bad Branch',
            'role'          => 'salesman',
            'branch_id'     => 999999,
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_id_accepts_valid_id(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'BR-OK-EMP-01',
            'name'          => 'Valid Branch',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // phone — nullable, max 30
    // ====================================================================

    public function test_phone_is_optional(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'PH-OPT-EMP-01',
            'name'          => 'No Phone',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_phone_max_length_30(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'PH-LONG-EMP-01',
            'name'          => 'Phone Too Long',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'phone'         => str_repeat('1', 31),
        ])->assertSessionHasErrors('phone');
    }

    // ====================================================================
    // email — nullable, email format, max 100
    // ====================================================================

    public function test_email_is_optional(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'EM-OPT-EMP-01',
            'name'          => 'No Email',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_email_must_be_valid_format(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'EM-BAD-EMP-01',
            'name'          => 'Bad Email',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'email'         => 'not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_email_max_length_100(): void
    {
        $branch = $this->makeBranch();
        $longEmail = str_repeat('a', 90) . '@example.com'; // > 100 chars

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'EM-LONG-EMP-01',
            'name'          => 'Email Too Long',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'email'         => $longEmail,
        ])->assertSessionHasErrors('email');
    }

    public function test_email_accepts_valid_format(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'EM-OK-EMP-01',
            'name'          => 'Valid Email',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'email'         => 'employee@example.com',
        ])->assertRedirect();
    }

    // ====================================================================
    // address — nullable string
    // ====================================================================

    public function test_address_is_optional(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AD-OPT-EMP-01',
            'name'          => 'No Address',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_address_accepts_long_text(): void
    {
        $branch = $this->makeBranch();
        $longAddress = str_repeat('Address line. ', 50);

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'AD-LONG-EMP-01',
            'name'          => 'Long Address',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'address'       => $longAddress,
        ])->assertRedirect();
    }

    // ====================================================================
    // salary — nullable + numeric + min:0
    // ====================================================================

    public function test_salary_is_optional(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'SAL-OPT-EMP-01',
            'name'          => 'No Salary',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_salary_must_be_numeric(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'SAL-BAD-EMP-01',
            'name'          => 'Bad Salary',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'salary'        => 'free',
        ])->assertSessionHasErrors('salary');
    }

    public function test_salary_rejects_negative_value(): void
    {
        // min:0 constraint — negative salary is invalid.
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'SAL-NEG-EMP-01',
            'name'          => 'Negative Salary',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'salary'        => -100.50,
        ])->assertSessionHasErrors('salary');
    }

    public function test_salary_accepts_zero(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'SAL-ZERO-EMP-01',
            'name'          => 'Zero Salary',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'salary'        => 0,
        ])->assertRedirect();
    }

    // ====================================================================
    // joining_date — nullable + date
    // ====================================================================

    public function test_joining_date_is_optional(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'JD-OPT-EMP-01',
            'name'          => 'No Joining Date',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();
    }

    public function test_joining_date_must_be_valid_date(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'JD-BAD-EMP-01',
            'name'          => 'Bad Joining Date',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'joining_date'  => 'not-a-date',
        ])->assertSessionHasErrors('joining_date');
    }

    public function test_joining_date_accepts_valid_date(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'JD-OK-EMP-01',
            'name'          => 'Valid Joining Date',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'joining_date'  => '2024-01-15',
        ])->assertRedirect();
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ACT-TRUE-EMP-01',
            'name'          => 'Active True',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'is_active'     => true,
        ])->assertRedirect();

        $employee = Employee::where('employee_code', 'ACT-TRUE-EMP-01')->first();
        $this->assertTrue($employee->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ACT-FALSE-EMP-01',
            'name'          => 'Active False',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
            'is_active'     => false,
        ])->assertRedirect();

        $employee = Employee::where('employee_code', 'ACT-FALSE-EMP-01')->first();
        $this->assertFalse($employee->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $branch = $this->makeBranch();

        $this->post(route('admin.employees.store'), [
            'employee_code' => 'ACT-DEF-EMP-01',
            'name'          => 'Default Active',
            'role'          => 'salesman',
            'branch_id'     => $branch->id,
        ])->assertRedirect();

        $employee = Employee::where('employee_code', 'ACT-DEF-EMP-01')->first();
        $this->assertTrue($employee->is_active, 'Employee should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        // Phase 12 fix: omitting is_active on update should NOT change is_active.
        $branch = $this->makeBranch();
        $employee = Employee::factory()->forBranch($branch->id)->create(['is_active' => true]);

        $this->put(route('admin.employees.update', $employee), [
            'employee_code' => $employee->employee_code,
            'name'          => 'Some Update',
            'role'          => $employee->role,
            'branch_id'     => $employee->branch_id,
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($employee->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.employees.store'), [
            'name'         => '',                 // required
            'role'         => 'ceo',               // invalid role
            'branch_id'    => 999999,              // does not exist
            'email'        => 'not-an-email',      // invalid email
        ]);

        $response->assertSessionHasErrors(['name', 'role', 'branch_id', 'email']);
    }
}
