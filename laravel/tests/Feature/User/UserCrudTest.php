<?php

namespace Tests\Feature\User;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsUserDependencies;
use Tests\TestCase;

/**
 * User CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle, unlock, resetPassword,
 * securityAudit.
 *
 * Validates UserController (Phase 14) inheriting from BaseMasterDataController.
 */
class UserCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsUserDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    /**
     * Convenience: create an Employee + Branch (without a linked user)
     * for use in store() tests.
     */
    private function makeEmployee(): Employee
    {
        $branch = Branch::factory()->create();

        return Employee::factory()->forBranch($branch->id)->create();
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_users(): void
    {
        $this->makeUser();
        $this->makeUser();
        $this->makeUser();

        $response = $this->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertViewIs('admin.users.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_users(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->get(route('admin.users.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        $this->makeUser();
        $this->makeUser();

        $response = $this->get(route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_user_count(): void
    {
        $this->makeUser();
        $this->makeUser();
        $this->makeUser(['is_active' => false]);

        $response = $this->get(route('admin.users.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_stats_include_locked_count(): void
    {
        $this->makeLockedUser();
        $this->makeLockedUser();

        $response = $this->get(route('admin.users.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['locked']) && $stats['locked'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_created_user(): void
    {
        $user = $this->makeUser();

        $response = $this->get(route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $user->id);
        $this->assertNotNull($row, 'DataTables response should include the created user');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.users.create'));

        $response->assertOk();
        $response->assertViewIs('admin.users.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'employees']);
    }

    public function test_create_only_lists_employees_without_existing_user(): void
    {
        $employeeWithoutUser = $this->makeEmployee();
        $employeeWithUser = $this->makeEmployee();

        // Create a user for the second employee so it should NOT appear in the list.
        User::factory()->forEmployee($employeeWithUser->id)->create([
            'username'      => 'emp_with_user_' . substr(uniqid(), -6),
            'password_hash' => Hash::make('password'),
        ]);

        $response = $this->get(route('admin.users.create'));

        $employees = $response->viewData('employees');
        $this->assertTrue($employees->contains('id', $employeeWithoutUser->id));
        $this->assertFalse($employees->contains('id', $employeeWithUser->id));
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_user_and_redirects_to_show(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->post(route('admin.users.store'), [
            'username'    => 'testuser1',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
            'is_active'   => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'username'    => 'testuser1',
            'employee_id' => $employee->id,
            'is_active'   => true,
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->post(route('admin.users.store'), [
            'username'    => 'rediruser1',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $user = User::where('username', 'rediruser1')->first();
        $response->assertRedirect(route('admin.users.show', $user));
        $response->assertSessionHas('success');
    }

    public function test_store_hashes_password_into_password_hash_column(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'hashtest1',
            'employee_id' => $employee->id,
            'password'    => 'plaintext_pw',
        ]);

        $user = User::where('username', 'hashtest1')->first();
        $this->assertNotEmpty($user->password_hash);
        $this->assertNotEquals('plaintext_pw', $user->password_hash);
        $this->assertTrue(Hash::check('plaintext_pw', $user->password_hash));
    }

    public function test_store_lowercases_username_before_validation(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'MIXED_Case_User',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'username' => 'mixed_case_user',
        ]);
    }

    public function test_store_trims_username_before_validation(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => '  padded_user  ',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'username' => 'padded_user',
        ]);
    }

    public function test_store_username_case_collision_blocked_after_normalization(): void
    {
        $employee1 = $this->makeEmployee();
        $this->post(route('admin.users.store'), [
            'username'    => 'CASEUSER',
            'employee_id' => $employee1->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $employee2 = $this->makeEmployee();
        // 'caseuser' would normalize to 'caseuser' which collides with 'CASEUSER' → 'caseuser'
        $this->post(route('admin.users.store'), [
            'username'    => 'caseuser',
            'employee_id' => $employee2->id,
            'password'    => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_store_sets_created_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'createdby_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $this->assertDatabaseHas('users', [
            'username'   => 'createdby_user',
            'created_by' => $user->id,
        ]);
    }

    public function test_store_sets_credential_version_to_one(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'credv1_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $user = User::where('username', 'credv1_user')->first();
        $this->assertEquals(1, $user->credential_version);
    }

    public function test_store_is_active_defaults_to_true_when_omitted(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'defaultactive_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        $user = User::where('username', 'defaultactive_user')->first();
        $this->assertTrue($user->is_active, 'User should default to active when is_active is omitted');
    }

    public function test_store_blocks_duplicate_user_for_same_employee(): void
    {
        $employee = $this->makeEmployee();

        // First user for this employee — should succeed
        $this->post(route('admin.users.store'), [
            'username'    => 'employee_user_1',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ])->assertRedirect();

        // Second user for the SAME employee — should be blocked
        $response = $this->post(route('admin.users.store'), [
            'username'    => 'employee_user_2',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['username' => 'employee_user_2']);
    }

    public function test_store_requires_password(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'nopass_user',
            'employee_id' => $employee->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_store_requires_password_min_length(): void
    {
        $employee = $this->makeEmployee();

        $this->post(route('admin.users.store'), [
            'username'    => 'shortpass_user',
            'employee_id' => $employee->id,
            'password'    => '12345', // 5 chars — below min:6
        ])->assertSessionHasErrors('password');
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_user_details(): void
    {
        $user = $this->makeUser();

        $response = $this->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertViewIs('admin.users.show');
        $response->assertViewHas('item');
        $this->assertEquals($user->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_employee_branch(): void
    {
        $user = $this->makeUser();

        $response = $this->get(route('admin.users.show', $user));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('employee'));
        $this->assertTrue($item->employee->relationLoaded('branch'));
    }

    public function test_show_works_for_soft_deleted_user(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->get(route('admin.users.show', $user));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_user(): void
    {
        $this->get(route('admin.users.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_user(): void
    {
        $user = $this->makeUser();

        $response = $this->get(route('admin.users.edit', $user));

        $response->assertOk();
        $response->assertViewIs('admin.users.edit');
        $response->assertViewHas('item');
        $this->assertEquals($user->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_user_and_redirects_to_show(): void
    {
        $user = $this->makeUser();

        $response = $this->put(route('admin.users.update', $user), [
            'username'   => 'updated_user',
            'is_active'  => true,
        ]);

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertDatabaseHas('users', [
            'id'       => $user->id,
            'username' => 'updated_user',
        ]);
    }

    public function test_update_keeps_existing_password_when_omitted(): void
    {
        $user = $this->makeUser();
        $originalHash = $user->password_hash;

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertSame($originalHash, $user->fresh()->password_hash);
    }

    public function test_update_hashes_new_password_when_provided(): void
    {
        $user = $this->makeUser();
        $originalHash = $user->password_hash;

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'password'  => 'brand_new_password',
            'is_active' => true,
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotEquals($originalHash, $fresh->password_hash);
        $this->assertTrue(Hash::check('brand_new_password', $fresh->password_hash));
    }

    public function test_update_bumps_credential_version_when_password_changes(): void
    {
        $user = $this->makeUser();
        $originalVersion = $user->credential_version;

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'password'  => 'new_password_123',
            'is_active' => true,
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertEquals($originalVersion + 1, $fresh->credential_version);
    }

    public function test_update_does_not_bump_credential_version_when_password_unchanged(): void
    {
        $user = $this->makeUser();
        $originalVersion = $user->credential_version;

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username . '_renamed',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertEquals($originalVersion, $user->fresh()->credential_version);
    }

    public function test_update_lowercases_username(): void
    {
        $user = $this->makeUser();

        $this->put(route('admin.users.update', $user), [
            'username'  => 'UPDATED_NAME',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id'       => $user->id,
            'username' => 'updated_name',
        ]);
    }

    public function test_update_blocked_when_user_has_active_session(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $response = $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'is_active' => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_update_deactivates_user_when_session_expired(): void
    {
        $user = $this->makeStaleLoggedInUser();

        $response = $this->put(route('admin.users.update', $user), [
            'username'  => $user->username,
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_update_allows_changing_username_to_unique_value(): void
    {
        $user = $this->makeUser(['username' => 'old_username_1']);

        $response = $this->put(route('admin.users.update', $user), [
            'username'  => 'new_unique_username_1',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'id'       => $user->id,
            'username' => 'new_unique_username_1',
        ]);
    }

    public function test_update_allows_keeping_own_username(): void
    {
        $user = $this->makeUser(['username' => 'keep_own_uname']);

        $response = $this->put(route('admin.users.update', $user), [
            'username'  => 'keep_own_uname',
            'is_active' => true,
        ]);

        $response->assertRedirect();
    }

    public function test_update_rejects_duplicate_username_from_other_user(): void
    {
        $this->makeUser(['username' => 'taken_uname']);
        $user = $this->makeUser(['username' => 'own_uname']);

        $this->put(route('admin.users.update', $user), [
            'username'  => 'taken_uname',
            'is_active' => true,
        ])->assertSessionHasErrors('username');
    }

    public function test_update_is_active_not_silently_flipped_when_omitted(): void
    {
        $user = $this->makeUser(['is_active' => true]);

        $this->put(route('admin.users.update', $user), [
            'username'  => $user->username . '_updated',
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_user_with_no_blockers(): void
    {
        $user = $this->makeUser();

        $response = $this->delete(route('admin.users.destroy', $user));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNotNull($user->deleted_at);
        $this->assertFalse($user->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $target = $this->makeUser();

        $this->delete(route('admin.users.destroy', $target));

        $this->assertDatabaseHas('users', [
            'id'         => $target->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_user_has_active_session(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $response = $this->delete(route('admin.users.destroy', $user));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', [
            'id'         => $user->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_user(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->post(route('admin.users.restore', $user));

        $response->assertRedirect(route('admin.users.show', $user));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->deleted_at);
        $this->assertNull($user->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_user(): void
    {
        $user = $this->makeUser(); // not deleted

        $response = $this->post(route('admin.users.restore', $user));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_user(): void
    {
        $this->post(route('admin.users.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController)
    // ====================================================================

    public function test_toggle_deactivates_active_user_with_no_blockers(): void
    {
        $user = $this->makeUser();

        $response = $this->post(route('admin.users.toggle', $user));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->deleted_at);
    }

    public function test_toggle_activates_inactive_user(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->post(route('admin.users.toggle', $user));

        $response->assertRedirect(route('admin.users.index'));
        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertNull($user->deleted_at);
    }

    public function test_toggle_blocked_when_user_has_active_session(): void
    {
        $user = $this->makeRecentlyLoggedInUser();

        $response = $this->post(route('admin.users.toggle', $user));

        $response->assertSessionHas('error');
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_user(): void
    {
        $this->post(route('admin.users.toggle', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // UNLOCK (custom Phase 14 action)
    // ====================================================================

    public function test_unlock_clears_locked_until_and_failed_count(): void
    {
        $user = $this->makeLockedUser();
        $this->assertNotNull($user->locked_until);
        $this->assertEquals(5, $user->failed_login_count);

        $response = $this->post(route('admin.users.unlock', $user));

        $response->assertRedirect(route('admin.users.show', $user));
        $response->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertNull($fresh->locked_until);
        $this->assertEquals(0, $fresh->failed_login_count);
    }

    public function test_unlock_works_on_already_unlocked_user(): void
    {
        // Should be idempotent — clearing a non-existent lock is fine.
        $user = $this->makeUser();

        $response = $this->post(route('admin.users.unlock', $user));

        $response->assertRedirect();
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_unlock_returns_404_for_unknown_user(): void
    {
        $this->post(route('admin.users.unlock', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // RESET PASSWORD (custom Phase 14 action)
    // ====================================================================

    public function test_reset_password_changes_password_hash(): void
    {
        $user = $this->makeUser();
        $originalHash = $user->password_hash;

        $response = $this->post(route('admin.users.resetPassword', $user));

        $response->assertRedirect(route('admin.users.show', $user));
        $response->assertSessionHas('success');
        $response->assertSessionHas('new_password');

        $fresh = $user->fresh();
        $this->assertNotEquals($originalHash, $fresh->password_hash);
    }

    public function test_reset_password_bumps_credential_version(): void
    {
        $user = $this->makeUser();
        $originalVersion = $user->credential_version;

        $this->post(route('admin.users.resetPassword', $user));

        $this->assertEquals($originalVersion + 1, $user->fresh()->credential_version);
    }

    public function test_reset_password_clears_lockout(): void
    {
        $user = $this->makeLockedUser();

        $this->post(route('admin.users.resetPassword', $user));

        $fresh = $user->fresh();
        $this->assertNull($fresh->locked_until);
        $this->assertEquals(0, $fresh->failed_login_count);
    }

    public function test_reset_password_flashes_plain_password_to_session(): void
    {
        $user = $this->makeUser();

        $response = $this->post(route('admin.users.resetPassword', $user));

        $plainPassword = session('new_password');
        $this->assertNotEmpty($plainPassword);
        $this->assertTrue(Hash::check($plainPassword, $user->fresh()->password_hash));
    }

    public function test_reset_password_returns_404_for_unknown_user(): void
    {
        $this->post(route('admin.users.resetPassword', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // SECURITY AUDIT (custom Phase 14 action)
    // ====================================================================

    public function test_security_audit_displays_user_security_events(): void
    {
        $user = $this->makeUser();

        // Seed some audit log entries
        $this->insertUserAuditLog($user->id, 'login_success', null, ['ip' => '1.2.3.4']);
        $this->insertUserAuditLog($user->id, 'login_failed', null, ['reason' => 'bad_password']);
        $this->insertUserAuditLog(null, 'account_locked', $user->id, ['reason' => 'too_many_attempts']);

        $response = $this->get(route('admin.users.security', $user));

        $response->assertOk();
        $response->assertViewIs('admin.users.security');
        $response->assertViewHas(['item', 'securityEvents', 'summary']);
    }

    public function test_security_audit_summary_counts_events(): void
    {
        $user = $this->makeUser();

        $this->insertUserAuditLog($user->id, 'login_success');
        $this->insertUserAuditLog($user->id, 'login_success');
        $this->insertUserAuditLog($user->id, 'login_failed');
        $this->insertUserAuditLog(null, 'account_locked', $user->id);
        $this->insertUserAuditLog(null, 'password_change', $user->id);

        $response = $this->get(route('admin.users.security', $user));

        $summary = $response->viewData('summary');
        $this->assertEquals(2, $summary['logins']);
        $this->assertEquals(1, $summary['failed_logins']);
        $this->assertEquals(1, $summary['lockouts']);
        $this->assertEquals(1, $summary['password_changes']);
    }

    public function test_security_audit_returns_404_for_unknown_user(): void
    {
        $this->get(route('admin.users.security', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_user_count_increments_after_store(): void
    {
        $initialCount = User::count();

        $employee = $this->makeEmployee();
        $this->post(route('admin.users.store'), [
            'username'    => 'counttest_user',
            'employee_id' => $employee->id,
            'password'    => 'secret123',
        ]);

        $this->assertEquals($initialCount + 1, User::count());
    }

    public function test_soft_deleted_user_excluded_from_default_index_query(): void
    {
        $toDelete = $this->makeUser(['username' => 'hide_me_user']);
        $keep = $this->makeUser(['username' => 'keep_visible_user']);
        $toDelete->delete();

        $response = $this->get(route('admin.users.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one user');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }
}
