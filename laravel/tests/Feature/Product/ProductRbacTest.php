<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Product RBAC tests — verifies that every Product route enforces the
 * correct role middleware.
 *
 * Route → Required Role matrix (from routes/web.php — Phase 9):
 *
 *   GET    /admin/products                       admin, manager, warehouse_manager
 *   GET    /admin/products/{id}                  admin, manager, warehouse_manager
 *   GET    /admin/products/create                admin
 *   POST   /admin/products                       admin
 *   GET    /admin/products/{id}/edit             admin
 *   PUT    /admin/products/{id}                  admin
 *   DELETE /admin/products/{id}                  admin
 *   POST   /admin/products/{id}/restore          admin
 *   POST   /admin/products/{id}/toggle           admin
 *   GET    /admin/products/audit                 admin
 *   GET    /admin/products/{id}/price-history    admin, manager, warehouse_manager
 *   POST   /admin/products/{id}/price            admin
 *   DELETE /admin/products/{id}/price/{price}    admin
 */
class ProductRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed at least one product so resource routes resolve to a valid ID.
        Product::factory()->create();
    }

    // ====================================================================
    // READ routes — admin, manager, warehouse_manager allowed
    // ====================================================================

    public function test_admin_can_access_product_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.products.index'))
            ->assertOk();
    }

    public function test_manager_can_access_product_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.products.index'))
            ->assertOk();
    }

    public function test_warehouse_manager_can_access_product_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.products.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_product_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.products.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_product_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.products.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dispatcher_cannot_access_product_index(): void
    {
        $this->actingAsRole('dispatcher')
            ->get(route('admin.products.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_hr_cannot_access_product_index(): void
    {
        $this->actingAsRole('hr')
            ->get(route('admin.products.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('admin.products.index'))
            ->assertRedirect(route('login'));
    }

    public function test_show_route_allows_admin_manager_warehouse_manager(): void
    {
        $product = Product::first();

        $this->actingAsRole('admin');
        $this->get(route('admin.products.show', $product))->assertOk();

        $this->actingAsRole('manager');
        $this->get(route('admin.products.show', $product))->assertOk();

        $this->actingAsRole('warehouse_manager');
        $this->get(route('admin.products.show', $product))->assertOk();
    }

    public function test_show_route_denies_salesman(): void
    {
        $product = Product::first();

        $this->actingAsRole('salesman')
            ->get(route('admin.products.show', $product))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_product_routes(): void
    {
        $product = Product::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.products.index'))->assertOk();
        $this->get(route('admin.products.show', $product))->assertOk();
        $this->get(route('admin.products.create'))->assertOk();
        $this->get(route('admin.products.edit', $product))->assertOk();
        $this->get(route('admin.products.audit'))->assertOk();
    }

    // ====================================================================
    // WRITE routes — admin only
    // ====================================================================

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.products.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.products.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.products.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_product(): void
    {
        $this->actingAsRole('manager')
            ->post(route('admin.products.store'), [
                'product_code' => 'UNAUTH-PRD-01',
                'product_name' => 'Unauthorized Product',
                'unit'         => 'Pcs',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('products', ['product_code' => 'UNAUTH-PRD-01']);
    }

    public function test_salesman_cannot_store_product(): void
    {
        $this->actingAsRole('salesman')
            ->post(route('admin.products.store'), [
                'product_code' => 'UNAUTH-PRD-02',
                'product_name' => 'Unauthorized Product',
                'unit'         => 'Pcs',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('products', ['product_code' => 'UNAUTH-PRD-02']);
    }

    public function test_accountant_cannot_store_product(): void
    {
        $this->actingAsRole('accountant')
            ->post(route('admin.products.store'), [
                'product_code' => 'UNAUTH-PRD-03',
                'product_name' => 'Unauthorized Product',
                'unit'         => 'Pcs',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('products', ['product_code' => 'UNAUTH-PRD-03']);
    }

    public function test_manager_cannot_access_edit_form(): void
    {
        $product = Product::first();

        $this->actingAsRole('manager')
            ->get(route('admin.products.edit', $product))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_update_product(): void
    {
        $product = Product::first();

        $this->actingAsRole('manager')
            ->put(route('admin.products.update', $product), [
                'product_code' => $product->product_code,
                'product_name' => 'Hacked Name',
                'unit'         => $product->unit,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('products', ['product_name' => 'Hacked Name']);
    }

    public function test_salesman_cannot_update_product(): void
    {
        $product = Product::first();

        $this->actingAsRole('salesman')
            ->put(route('admin.products.update', $product), [
                'product_code' => $product->product_code,
                'product_name' => 'Hacked Name 2',
                'unit'         => $product->unit,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('products', ['product_name' => 'Hacked Name 2']);
    }

    public function test_manager_cannot_destroy_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAsRole('manager')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_at' => null,
            'is_active'  => true,
        ]);
    }

    public function test_salesman_cannot_destroy_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAsRole('salesman')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAsRole('manager')
            ->post(route('admin.products.toggle', $product))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_manager_cannot_restore_product(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $this->actingAsRole('manager')
            ->post(route('admin.products.restore', $product))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($product->fresh()->deleted_at);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.products.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_salesman_cannot_access_audit_page(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.products.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_audit_page(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.products.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_warehouse_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.products.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $product = Product::factory()->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.products.destroy', $product))
            ->assertForbidden();
    }

    public function test_json_request_returns_403_for_unauthorized_role_on_store(): void
    {
        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('admin.products.store'), [
                'product_code' => 'JSON-403',
                'product_name' => 'JSON Test',
                'unit'         => 'Pcs',
            ])
            ->assertForbidden();
    }

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.products.destroy', $product))
            ->assertUnauthorized();
    }
}
