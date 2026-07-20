<?php

namespace Tests\Feature\Bank;

use App\Models\Bank;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Bank RBAC tests — verifies that every Bank route enforces the correct
 * role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 13):
 *
 *   GET    /admin/banks                       admin, manager, accountant
 *   GET    /admin/banks/{id}                  admin, manager, accountant
 *   GET    /admin/banks/create                admin
 *   POST   /admin/banks                       admin
 *   GET    /admin/banks/{id}/edit             admin
 *   PUT    /admin/banks/{id}                  admin
 *   DELETE /admin/banks/{id}                  admin
 *   POST   /admin/banks/{id}/restore          admin
 *   POST   /admin/banks/{id}/toggle           admin
 *   GET    /admin/banks/audit                 admin
 *
 * Banks are accounting-domain master data: accountant has read access
 * alongside admin and manager; salesman/warehouse_manager/dispatcher/hr
 * do not.
 */
class BankRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one bank so resource routes resolve.
        Bank::factory()->create();
    }

    // ====================================================================
    // READ routes — admin, manager, accountant allowed
    // ====================================================================

    public function test_admin_can_access_bank_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.banks.index'))
            ->assertOk();
    }

    public function test_manager_can_access_bank_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.banks.index'))
            ->assertOk();
    }

    public function test_accountant_can_access_bank_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.banks.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_bank_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.banks.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_bank_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.banks.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_bank_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.banks.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_bank_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.banks.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.banks.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_accountant(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.banks.show', $bank))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.banks.show', $bank))->assertOk();

        $this->actingAsRole('accountant');
        $this->get(route('admin.banks.show', $bank))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.banks.show', $bank))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_warehouse_manager(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.banks.show', $bank))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_hr(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('hr')
            ->get(route('admin.banks.show', $bank))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_bank_routes(): void
    {
        $bank = Bank::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.banks.index'))->assertOk();
        $this->get(route('admin.banks.show', $bank))->assertOk();
        $this->get(route('admin.banks.create'))->assertOk();
        $this->get(route('admin.banks.edit', $bank))->assertOk();
        $this->get(route('admin.banks.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.banks.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.banks.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_create_form(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.banks.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.banks.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_create_form(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.banks.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_bank(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.banks.store'), [
                'bank_name' => 'Unauthorized Bank',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('banks', ['bank_name' => 'Unauthorized Bank']);
    }

    public function test_accountant_cannot_store_bank(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.banks.store'), [
                'bank_name' => 'Unauthorized Bank 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('banks', ['bank_name' => 'Unauthorized Bank 2']);
    }

    public function test_salesman_cannot_store_bank(): void
    {
        $this->actingAsRole('salesman')
            ->post(route('admin.banks.store'), [
                'bank_name' => 'Unauthorized Bank 3',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('banks', ['bank_name' => 'Unauthorized Bank 3']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('manager')
            ->get(route('admin.banks.edit', $bank))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_edit_form(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('accountant')
            ->get(route('admin.banks.edit', $bank))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_bank(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('manager')
            ->put(route('admin.banks.update', $bank), [
                'bank_name' => 'Hacked Bank Name',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('banks', ['bank_name' => 'Hacked Bank Name']);
    }

    public function test_accountant_cannot_update_bank(): void
    {
        $bank = Bank::first();

        $this->actingAsRole('accountant')
            ->put(route('admin.banks.update', $bank), [
                'bank_name' => 'Hacked Bank Name 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('banks', ['bank_name' => 'Hacked Bank Name 2']);
    }

    public function test_manager_cannot_destroy_bank(): void
    {
        $bank = Bank::factory()->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.banks.destroy', $bank))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('banks', [
            'id'         => $bank->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_accountant_cannot_destroy_bank(): void
    {
        $bank = Bank::factory()->create();

        $this->actingAsRole('accountant')
            ->delete(route('admin.banks.destroy', $bank))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('banks', [
            'id'         => $bank->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_bank(): void
    {
        $bank = Bank::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.banks.toggle', $bank))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_accountant_cannot_toggle_bank(): void
    {
        $bank = Bank::factory()->create();

        $this->actingAsRole('accountant')
            ->post(route('admin.banks.toggle', $bank))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_manager_cannot_restore_bank(): void
    {
        $bank = Bank::factory()->create();
        $bank->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.banks.restore', $bank))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($bank->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.banks.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.banks.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.banks.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.banks.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $bank = Bank::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.banks.destroy', $bank))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.banks.store'), [
                'bank_name' => 'JSON 403 Bank',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $bank = Bank::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.banks.destroy', $bank))
            ->assertUnauthorized();
    }
}
