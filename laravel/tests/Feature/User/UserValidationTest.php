<?php

namespace Tests\Feature\User;

use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsUserDependencies;
use Tests\TestCase;

/**
 * User Validation tests — verifies the validation rules defined in
 * UserController::validationRules().
 *
 * Rules (Phase 14):
 *   - username:         required|string|max:50|unique:users,username,{id}
 *   - employee_id:      required|exists:employees,id
 *   - is_active:        boolean
 *
 * NOTE (2026-07-22): The telegram_user_id rules + tests were removed when
 * R24/R25 (Telegram + FCM notifications) were dropped per user request.
 * Migration 2025_01_20_000010_drop_fcm_and_telegram_fields drops the column.
 *
 * Phase 14 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 *   - username is lowercased + trimmed BEFORE validation
 *     (case-insensitive unique check)
 *   - password is required on store (min 6), optional on update
 */
class UserValidationTest extends TestCase
{
    use BuildsRoleUsers, InsertsUserDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Helper: create an Employee + Branch for use in store tests.
     */
    private function makeEmployee(): \App\Models\Employee
    {
        $branch = \App\Models\Branch::factory()->create();

        return \App\Models\Employee::factory()->forBranch($branch->id)->create();
    }

    // ====================================================================
    // username — required, max 50, unique (case-insensitive)
    // ====================================================================

    public function test_username_is_required_on_store(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_username_is_required_on_update(): void
    {
        $user = $this->makeUser();

        $this->put(route('admin.users.update', $user), [
            // username omitted
            'is_active' => true,
        ])->assertSessionHasErrors('username');
    }

    public function test_username_max_length_50(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => str_repeat('x', 51),
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_username_accepts_exactly_50_chars(): void
    {
        $username = str_repeat('u', 50);
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => $username,
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['username' => $username]);
    }

    public function test_username_must_be_unique_on_store(): void
    {
        $this->makeUser(['username' => 'uniq_user_1']);
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'uniq_user_1',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_username_unique_is_case_insensitive_after_normalization(): void
    {
        // Phase 14: username is lowercased + trimmed BEFORE validation.
        // 'UNIQ_USER_2' becomes 'uniq_user_2' before unique check, so it
        // SHOULD collide with existing 'uniq_user_2'.
        $this->makeUser(['username' => 'uniq_user_2']);
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'UNIQ_USER_2',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_username_normalized_to_lowercase_on_store(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'MIXED_CASE_User',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'username' => 'mixed_case_user',
        ]);
    }

    public function test_username_normalized_to_lowercase_on_update(): void
    {
        $user = $this->makeUser(['username' => 'norm_old_user']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'NORM_NEW_USER',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id'       => $user->id,
            'username' => 'norm_new_user',
        ]);
    }

    public function test_username_trimmed_on_store(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => '  padded_username  ',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'username' => 'padded_username',
        ]);
    }

    public function test_username_trimmed_on_update(): void
    {
        $user = $this->makeUser(['username' => 'trim_old_user']);

        $this->put(route('admin.users.update', $user), [
            'username'  => '  trim_new_user  ',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id'       => $user->id,
            'username' => 'trim_new_user',
        ]);
    }

    public function test_username_unique_allows_keeping_own_on_update(): void
    {
        $user = $this->makeUser(['username' => 'keep_own_user']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'keep_own_user',
            'is_active' => true,
        ])->assertRedirect();
    }

    public function test_username_unique_rejects_other_users_on_update(): void
    {
        $this->makeUser(['username' => 'taken_user_2']);
        $user = $this->makeUser(['username' => 'own_user_2']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'taken_user_2',
            'is_active' => true,
        ])->assertSessionHasErrors('username');
    }

    // ====================================================================
    // employee_id — required, must exist
    // ====================================================================

    public function test_employee_id_is_required_on_store(): void
    {
        $this->post(route('admin.users.store'), [
            'username' => 'no_employee_user',
            'password' => 'secret123',
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_employee_id_must_exist(): void
    {
        $this->post(route('admin.users.store'), [
            'username'    => 'bad_employee_user',
            'employee_id' => 999999,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_employee_id_accepts_valid_id(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'valid_employee_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();
    }

    // ====================================================================
    // password — required on store, optional on update, min 6
    // ====================================================================

    public function test_password_is_required_on_store(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'no_pass_user',
            'employee_id' => $employee->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_password_min_length_6_on_store(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'short_pass_user',
            'employee_id' => $employee->id,
            'password'    => '12345',
        ])->assertSessionHasErrors('password');
    }

    public function test_password_optional_on_update(): void
    {
        $user = $this->makeUser();

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            // password omitted
            'is_active' => true,
        ])->assertRedirect();
    }

    public function test_password_min_length_6_on_update(): void
    {
        $user = $this->makeUser();

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'password'  => '12345', // too short
            'is_active' => true,
        ])->assertSessionHasErrors('password');
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'active_true_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
            'is_active'   => true,
        ])->assertRedirect();

        $user = User::where('username', 'active_true_user')->first();
        $this->assertTrue($user->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'active_false_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
            'is_active'   => false,
        ])->assertRedirect();

        $user = User::where('username', 'active_false_user')->first();
        $this->assertFalse($user->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'default_active_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $user = User::where('username', 'default_active_user')->first();
        $this->assertTrue($user->is_active, 'User should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        $user = $this->makeUser(['is_active' => true]);

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username . '_updated',
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.users.store'), [
            'username'         => '',                  // required
            'employee_id'      => 999999,              // exists
            'password'         => '12345',             // min:6
        ]);

        $response->assertSessionHasErrors(['username', 'employee_id', 'password']);
    }

    // ====================================================================
    // Defense-in-depth: employee uniqueness
    // ====================================================================

    public function test_store_rejects_duplicate_employee_with_friendly_error(): void
    {
        $employee = $this->makeEmployee();

        // First user — success
        $this->post(route('admin.users.store'), [
            'username'    => 'employee_dup_1',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        // Second user for SAME employee — friendly error
        $response = $this->post(route('admin.users.store'), [
            'username'    => 'employee_dup_2',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('already has a login account', session('error'));
    }
}
