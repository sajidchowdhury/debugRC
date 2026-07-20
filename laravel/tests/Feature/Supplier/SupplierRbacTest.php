<?php

namespace Tests\Feature\Supplier;

use App\Models\Supplier;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Supplier RBAC tests — verifies that every Supplier route enforces the
 * correct role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 11):
 *
 *   GET    /admin/suppliers                       admin, manager, accountant
 *   GET    /admin/suppliers/{id}                  admin, manager, accountant
 *   GET    /admin/suppliers/create                admin
 *   POST   /admin/suppliers                       admin
 *   GET    /admin/suppliers/{id}/edit             admin
 *   PUT    /admin/suppliers/{id}                  admin
 *   DELETE /admin/suppliers/{id}                  admin
 *   POST   /admin/suppliers/{id}/restore          admin
 *   POST   /admin/suppliers/{id}/toggle           admin
 *   GET    /admin/suppliers/audit                 admin
 */
class SupplierRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one supplier so resource routes resolve to a valid ID.
        Supplier::factory()->create();
    }

    // ====================================================================
    // READ routes — admin, manager, accountant allowed
    // ====================================================================

    public function test_admin_can_access_supplier_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.suppliers.index'))
            ->assertOk();
    }

    public function test_manager_can_access_supplier_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.suppliers.index'))
            ->assertOk();
    }

    public function test_accountant_can_access_supplier_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.suppliers.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_supplier_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.suppliers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_supplier_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.suppliers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_supplier_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.suppliers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_supplier_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.suppliers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.suppliers.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_accountant(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.suppliers.show', $supplier))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.suppliers.show', $supplier))->assertOk();

        $this->actingAsRole('accountant');
        $this->get(route('admin.suppliers.show', $supplier))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.suppliers.show', $supplier))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_warehouse_manager(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.suppliers.show', $supplier))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_supplier_routes(): void
    {
        $supplier = Supplier::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.suppliers.index'))->assertOk();
        $this->get(route('admin.suppliers.show', $supplier))->assertOk();
        $this->get(route('admin.suppliers.create'))->assertOk();
        $this->get(route('admin.suppliers.edit', $supplier))->assertOk();
        $this->get(route('admin.suppliers.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.suppliers.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.suppliers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_create_form(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.suppliers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.suppliers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_supplier(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.suppliers.store'), [
                'supplier_name' => 'Unauthorized Supplier',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('suppliers', ['supplier_name' => 'Unauthorized Supplier']);
    }

    public function test_accountant_cannot_store_supplier(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.suppliers.store'), [
                'supplier_name' => 'Unauthorized Supplier 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('suppliers', ['supplier_name' => 'Unauthorized Supplier 2']);
    }

    public function test_salesman_cannot_store_supplier(): void
    {
        $this->actingAsRole('salesman')
            ->post(route('admin.suppliers.store'), [
                'supplier_name' => 'Unauthorized Supplier 3',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('suppliers', ['supplier_name' => 'Unauthorized Supplier 3']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('manager')
            ->get(route('admin.suppliers.edit', $supplier))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_supplier(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('manager')
            ->put(route('admin.suppliers.update', $supplier), [
                'supplier_name' => 'Hacked Name',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('suppliers', ['supplier_name' => 'Hacked Name']);
    }

    public function test_accountant_cannot_update_supplier(): void
    {
        $supplier = Supplier::first();

        $this->actingAsRole('accountant')
            ->put(route('admin.suppliers.update', $supplier), [
                'supplier_name' => 'Hacked Name 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('suppliers', ['supplier_name' => 'Hacked Name 2']);
    }

    public function test_manager_cannot_destroy_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.suppliers.destroy', $supplier))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('suppliers', [
            'id'         => $supplier->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_accountant_cannot_destroy_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsRole('accountant')
            ->delete(route('admin.suppliers.destroy', $supplier))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('suppliers', [
            'id'         => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.suppliers.toggle', $supplier))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_accountant_cannot_toggle_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsRole('accountant')
            ->post(route('admin.suppliers.toggle', $supplier))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_manager_cannot_restore_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.suppliers.restore', $supplier))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($supplier->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.suppliers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.suppliers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.suppliers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.suppliers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.suppliers.destroy', $supplier))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.suppliers.store'), [
                'supplier_name' => 'JSON 403',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $supplier = Supplier::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.suppliers.destroy', $supplier))
            ->assertUnauthorized();
    }
}
