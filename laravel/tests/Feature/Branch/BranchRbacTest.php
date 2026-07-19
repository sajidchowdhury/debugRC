<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Branch RBAC tests — verifies that every Branch route enforces the
 * correct role middleware.
 *
 * Route → Required Role matrix (from routes/web.php):
 *
 *   GET    /admin/branches              admin, manager, warehouse_manager
 *   GET    /admin/branches/{id}         admin, manager, warehouse_manager
 *   GET    /admin/branches/create       admin
 *   POST   /admin/branches              admin
 *   GET    /admin/branches/{id}/edit    admin
 *   PUT    /admin/branches/{id}         admin
 *   DELETE /admin/branches/{id}         admin
 *   POST   /admin/branches/{id}/restore admin
 *   POST   /admin/branches/{id}/toggle  admin
 *   GET    /admin/branches/audit        admin
 *
 * Phase 2 RBAC commit `17544db`.
 */
class BranchRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one branch so resource routes resolve to a valid ID.
        Branch::factory()->create();
    }

    // ====================================================================
    // READ routes — admin, manager, warehouse_manager allowed
    // ====================================================================

    public function test_admin_can_access_branch_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.branches.index'))
            ->assertOk();
    }

    public function test_manager_can_access_branch_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.branches.index'))
            ->assertOk();
    }

    public function test_warehouse_manager_can_access_branch_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.branches.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_branch_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.branches.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_branch_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.branches.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_branch_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.branches.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_branch_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.branches.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.branches.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_warehouse_manager(): void
    {
        $branch = Branch::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.branches.show', $branch))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.branches.show', $branch))->assertOk();

        $this->actingAsRole('warehouse_manager');
        $this->get(route('admin.branches.show', $branch))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $branch = Branch::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.branches.show', $branch))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_branch_routes(): void
    {
        $branch = Branch::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.branches.index'))->assertOk();
        $this->get(route('admin.branches.show', $branch))->assertOk();
        $this->get(route('admin.branches.create'))->assertOk();
        $this->get(route('admin.branches.edit', $branch))->assertOk();
        $this->get(route('admin.branches.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.branches.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.branches.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.branches.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_branch(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.branches.store'), [
                'branch_code' => 'UNAUTH-01',
                'branch_name' => 'Unauthorized Branch',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('branches', ['branch_code' => 'UNAUTH-01']);
    }

    public function test_salesman_cannot_store_branch(): void
    {
        $this->actingAsRole('salesman')
            ->post(route('admin.branches.store'), [
                'branch_code' => 'UNAUTH-02',
                'branch_name' => 'Unauthorized Branch',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('branches', ['branch_code' => 'UNAUTH-02']);
    }

    public function test_accountant_cannot_store_branch(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.branches.store'), [
                'branch_code' => 'UNAUTH-03',
                'branch_name' => 'Unauthorized Branch',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('branches', ['branch_code' => 'UNAUTH-03']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $branch = Branch::first();

        $this->actingAsRole('manager')
            ->get(route('admin.branches.edit', $branch))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_branch(): void
    {
        $branch = Branch::first();

        $this->actingAsRole('manager')
            ->put(route('admin.branches.update', $branch), [
                'branch_code' => $branch->branch_code,
                'branch_name' => 'Hacked Name',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('branches', ['branch_name' => 'Hacked Name']);
    }

    public function test_salesman_cannot_update_branch(): void
    {
        $branch = Branch::first();

        $this->actingAsRole('salesman')
            ->put(route('admin.branches.update', $branch), [
                'branch_code' => $branch->branch_code,
                'branch_name' => 'Hacked Name 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('branches', ['branch_name' => 'Hacked Name 2']);
    }

    public function test_manager_cannot_destroy_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.branches.destroy', $branch))
            ->assertRedirect(route('dashboard'));

        // Branch should still be active
        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_salesman_cannot_destroy_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('salesman')
            ->delete(route('admin.branches.destroy', $branch))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.branches.toggle', $branch))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_manager_cannot_restore_branch(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.branches.restore', $branch))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($branch->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.branches.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.branches.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.branches.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.branches.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.branches.destroy', $branch))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.branches.store'), [
                'branch_code' => 'JSON-403',
                'branch_name' => 'JSON Test',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $branch = Branch::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.branches.destroy', $branch))
            ->assertUnauthorized();
    }
}
