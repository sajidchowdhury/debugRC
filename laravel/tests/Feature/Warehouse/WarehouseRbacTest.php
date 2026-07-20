<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Warehouse RBAC tests — verifies role middleware on every Warehouse route.
 *
 * Route → Required Role matrix (routes/web.php):
 *   GET    /admin/warehouses              admin, manager, warehouse_manager
 *   GET    /admin/warehouses/{id}         admin, manager, warehouse_manager
 *   GET    /admin/warehouses/create       admin
 *   POST   /admin/warehouses              admin
 *   GET    /admin/warehouses/{id}/edit    admin
 *   PUT    /admin/warehouses/{id}         admin
 *   DELETE /admin/warehouses/{id}         admin
 *   POST   /admin/warehouses/{id}/restore admin
 *   POST   /admin/warehouses/{id}/toggle  admin
 *   GET    /admin/warehouses/audit        admin
 */
class WarehouseRbacTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a branch + warehouse so resource routes resolve.
        $branch = Branch::factory()->create();
        Warehouse::factory()->forBranch($branch->id)->create();
    }

    public function test_admin_can_access_warehouse_index(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.warehouses.index'))
            ->assertOk();
    }

    public function test_manager_can_access_warehouse_index(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.warehouses.index'))
            ->assertOk();
    }

    public function test_warehouse_manager_can_access_warehouse_index(): void
    {
        $this->actingAsRole('warehouse_manager')
            ->get(route('admin.warehouses.index'))
            ->assertOk();
    }

    public function test_salesman_cannot_access_warehouse_index(): void
    {
        $this->actingAsRole('salesman')
            ->get(route('admin.warehouses.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accountant_cannot_access_warehouse_index(): void
    {
        $this->actingAsRole('accountant')
            ->get(route('admin.warehouses.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get(route('admin.warehouses.index'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_access_create_form(): void
    {
        $this->actingAsRole('admin')
            ->get(route('admin.warehouses.create'))
            ->assertOk();
    }

    public function test_manager_cannot_access_create_form(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.warehouses.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_manager_cannot_store_warehouse(): void
    {
        $branch = Branch::first();
        $this->actingAsRole('manager')
            ->post(route('admin.warehouses.store'), [
                'warehouse_code' => 'UNAUTH-WH-01',
                'warehouse_name' => 'Unauthorized',
                'branch_id'      => $branch->id,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('warehouses', ['warehouse_code' => 'UNAUTH-WH-01']);
    }

    public function test_salesman_cannot_destroy_warehouse(): void
    {
        $branch = Branch::first();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('salesman')
            ->delete(route('admin.warehouses.destroy', $warehouse))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('warehouses', [
            'id'         => $warehouse->id,
            'deleted_at' => null,
        ]);
    }

    public function test_manager_cannot_toggle_warehouse(): void
    {
        $branch = Branch::first();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('manager')
            ->post(route('admin.warehouses.toggle', $warehouse))
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_manager_cannot_access_audit_page(): void
    {
        $this->actingAsRole('manager')
            ->get(route('admin.warehouses.audit'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_superadmin_passes_all_warehouse_routes(): void
    {
        $branch = Branch::first();
        $warehouse = Warehouse::first();
        $this->actingAsRole('superadmin');

        $this->get(route('admin.warehouses.index'))->assertOk();
        $this->get(route('admin.warehouses.show', $warehouse))->assertOk();
        $this->get(route('admin.warehouses.create'))->assertOk();
        $this->get(route('admin.warehouses.edit', $warehouse))->assertOk();
        $this->get(route('admin.warehouses.audit'))->assertOk();
    }

    public function test_json_request_returns_403_for_unauthorized_role(): void
    {
        $branch = Branch::first();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->actingAsRole('salesman')
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('admin.warehouses.destroy', $warehouse))
            ->assertForbidden();
    }
}
