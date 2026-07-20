<?php

namespace Tests\Feature\User;

use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsUserDependencies;
use Tests\TestCase;

/**
 * User RBAC tests — verifies that every User route enforces the correct
 * role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 14):
 *
 *   GET    /admin/users                          admin, manager
 *   GET    /admin/users/{id}                     admin, manager
 *   GET    /admin/users/create                   admin
 *   POST   /admin/users                          admin
 *   GET    /admin/users/{id}/edit                admin
 *   PUT    /admin/users/{id}                     admin
 *   DELETE /admin/users/{id}                     admin
 *   POST   /admin/users/{id}/restore             admin
 *   POST   /admin/users/{id}/toggle              admin
 *   POST   /admin/users/{id}/unlock              admin
 *   POST   /admin/users/{id}/reset-password      admin
 *   GET    /admin/users/{id}/security            admin
 *   GET    /admin/users/audit                    admin
 *
 * Users are admin-domain master data: admin has full control; manager has
 * read-only access (e.g. to look up a username for a customer payment).
 * Accountant/salesman/warehouse_manager/dispatcher/hr do not.
 */
class UserRbacTest extends TestCase
{
    use BuildsRoleUsers, InsertsUserDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one user so resource routes resolve.
        $this->makeUser();
    }

    // ====================================================================
    // READ routes — admin, manager allowed
    // ====================================================================

    public function test_admin_can_access_user_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_manager_can_access_user_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_accountant_cannot_access_user_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_user_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_user_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_user_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_user_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.users.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_and_manager(): void
    {
        $user = User::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.users.show', $user))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.users.show', $user))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $user = User::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.users.show', $user))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_hr(): void
    {
        $user = User::first();

        $this->actingAsRole('hr')
            ->get(route('admin.users.show', $user))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_user_routes(): void
    {
        $user = User::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.users.show', $user))->assertOk();
        $this->get(route('admin.users.create'))->assertOk();
        $this->get(route('admin.users.edit', $user))->assertOk();
        $this->get(route('admin.users.audit'))->assertOk();
        $this->get(route('admin.users.security', $user))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.users.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.users.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_create_form(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.users.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_user(): void
    {
        $employee = \App\Models\Employee::factory()
            ->forBranch(\App\Models\Branch::factory()->create()->id)
            ->create();

        $this->actingAsRole('manager')
            ->post(route('admin.users.store'), [
                'username'    => 'unauthorized_user',
                'employee_id' => $employee->id,
                'password'    => 'secret123',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('users', ['username' => 'unauthorized_user']);
    }

    public function test_accountant_cannot_store_user(): void
    {
        $employee = \App\Models\Employee::factory()
            ->forBranch(\App\Models\Branch::factory()->create()->id)
            ->create();

        $this->actingAsRole('accountant')
            ->post(route('admin.users.store'), [
                'username'    => 'unauthorized_user_2',
                'employee_id' => $employee->id,
                'password'    => 'secret123',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('users', ['username' => 'unauthorized_user_2']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $user = User::first();

        $this->actingAsRole('manager')
            ->get(route('admin.users.edit', $user))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_user(): void
    {
        $user = User::first();

        $this->actingAsRole('manager')
            ->put(route('admin.users.update', $user), [
                'username' => 'hacked_username',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('users', ['username' => 'hacked_username']);
    }

    public function test_manager_cannot_destroy_user(): void
    {
        $user = $this->makeUser();

        $this->actingAsRole('manager')
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', [
            'id'         => $user->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_manager_cannot_toggle_user(): void
    {
        $user = $this->makeUser();

        $this->actingAsRole('manager')
            ->post(route('admin.users.toggle', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_manager_cannot_restore_user(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.users.restore', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->deleted_at);
    }

    public function test_manager_cannot_unlock_user(): void
    {
        $user = $this->makeLockedUser();

        $this->actingAsRole('manager')
            ->post(route('admin.users.unlock', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->locked_until);
    }

    public function test_manager_cannot_reset_password(): void
    {
        $user = $this->makeUser();
        $originalHash = $user->password_hash;

        $this->actingAsRole('manager')
            ->post(route('admin.users.resetPassword', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($originalHash, $user->fresh()->password_hash);
    }

    public function test_manager_cannot_access_security_audit(): void
    {
        $user = User::first();

        $this->actingAsRole('manager')
            ->get(route('admin.users.security', $user))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.users.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.users.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.users.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $user = $this->makeUser();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.users.destroy', $user))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $employee = \App\Models\Employee::factory()
            ->forBranch(\App\Models\Branch::factory()->create()->id)
            ->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.users.store'), [
                'username'    => 'json_403_user',
                'employee_id' => $employee->id,
                'password'    => 'secret123',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $user = $this->makeUser();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.users.destroy', $user))
            ->assertUnauthorized();
    }
}
