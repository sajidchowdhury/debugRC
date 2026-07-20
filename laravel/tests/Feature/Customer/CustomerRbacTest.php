<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Customer RBAC tests — verifies that every Customer route enforces the
 * correct role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 10):
 *
 *   GET    /admin/customers                       admin, manager, salesman
 *   GET    /admin/customers/{id}                  admin, manager, salesman
 *   GET    /admin/customers/create                admin
 *   POST   /admin/customers                       admin
 *   GET    /admin/customers/{id}/edit             admin
 *   PUT    /admin/customers/{id}                  admin
 *   DELETE /admin/customers/{id}                  admin
 *   POST   /admin/customers/{id}/restore          admin
 *   POST   /admin/customers/{id}/toggle           admin
 *   GET    /admin/customers/audit                 admin
 */
class CustomerRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one customer so resource routes resolve to a valid ID.
        Customer::factory()->create();
    }

    // ====================================================================
    // READ routes — admin, manager, salesman allowed
    // ====================================================================

    public function test_admin_can_access_customer_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.customers.index'))
            ->assertOk();
    }

    public function test_manager_can_access_customer_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.customers.index'))
            ->assertOk();
    }

    public function test_salesman_can_access_customer_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.customers.index'))
            ->assertOk();
    }

    public function test_warehouse_manager_cannot_access_customer_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.customers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_customer_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.customers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_customer_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.customers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_customer_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.customers.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.customers.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_salesman(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.customers.show', $customer))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.customers.show', $customer))->assertOk();

        $this->actingAsRole('salesman');
        $this->get(route('admin.customers.show', $customer))->assertOk();
    }

    public function test_show_route_denies_warehouse_manager(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.customers.show', $customer))
            ->assertRedirect(route('dashboard'));
    }

    public function test_show_route_denies_accountant(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('accountant')
            ->get(route('admin.customers.show', $customer))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_customer_routes(): void
    {
        $customer = Customer::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.customers.index'))->assertOk();
        $this->get(route('admin.customers.show', $customer))->assertOk();
        $this->get(route('admin.customers.create'))->assertOk();
        $this->get(route('admin.customers.edit', $customer))->assertOk();
        $this->get(route('admin.customers.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.customers.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.customers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_create_form(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.customers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.customers.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_customer(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.customers.store'), [
                'customer_name' => 'Unauthorized Customer',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('customers', ['customer_name' => 'Unauthorized Customer']);
    }

    public function test_salesman_cannot_store_customer(): void
    {
        $this->actingAsRole('salesman')
            ->post(route('admin.customers.store'), [
                'customer_name' => 'Unauthorized Customer 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('customers', ['customer_name' => 'Unauthorized Customer 2']);
    }

    public function test_accountant_cannot_store_customer(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.customers.store'), [
                'customer_name' => 'Unauthorized Customer 3',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('customers', ['customer_name' => 'Unauthorized Customer 3']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('manager')
            ->get(route('admin.customers.edit', $customer))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_customer(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('manager')
            ->put(route('admin.customers.update', $customer), [
                'customer_name' => 'Hacked Name',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('customers', ['customer_name' => 'Hacked Name']);
    }

    public function test_salesman_cannot_update_customer(): void
    {
        $customer = Customer::first();

        $this->actingAsRole('salesman')
            ->put(route('admin.customers.update', $customer), [
                'customer_name' => 'Hacked Name 2',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('customers', ['customer_name' => 'Hacked Name 2']);
    }

    public function test_manager_cannot_destroy_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.customers.destroy', $customer))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_salesman_cannot_destroy_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAsRole('salesman')
            ->delete(route('admin.customers.destroy', $customer))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.customers.toggle', $customer))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_manager_cannot_restore_customer(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.customers.restore', $customer))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($customer->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.customers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.customers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.customers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.customers.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.customers.destroy', $customer))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.customers.store'), [
                'customer_name' => 'JSON 403',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $customer = Customer::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.customers.destroy', $customer))
            ->assertUnauthorized();
    }
}
