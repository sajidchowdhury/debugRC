<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Warehouse CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy, restore.
 *
 * Validates WarehouseController (Phase 5 normalization + active-branch
 * validation + canChangeBranch) inheriting from BaseMasterDataController.
 */
class WarehouseCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_warehouses(): void
    {
        $branch = Branch::factory()->create();
        Warehouse::factory()->forBranch($branch->id)->count(3)->create();

        $response = $this->get(route('admin.warehouses.index'));

        $response->assertOk();
        $response->assertViewIs('admin.warehouses.index');
        $response->assertViewHas(['title', 'items', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        $branch = Branch::factory()->create();
        Warehouse::factory()->forBranch($branch->id)->count(2)->create();

        $response = $this->get(route('admin.warehouses.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_warehouse_and_redirects_to_show(): void
    {
        $branch = Branch::factory()->create();
        // Use a unique warehouse_code — the seeded baseline data already
        // uses 'WH-001' (see database/sql/basic_data_snapshot.sql), so a
        // hardcoded 'wh-001' collides with the seeded row on the
        // warehouse_code UNIQUE constraint and the controller rejects the
        // POST (no warehouse created → assertDatabaseHas fails to find
        // the expected branch_id).
        $whCode = 'WH-T-' . substr(uniqid(), -6);

        $response = $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => strtolower($whCode), // controller uppercases
            'warehouse_name' => '  Main Warehouse  ',
            'branch_id'      => $branch->id,
            'location'       => '  Dhaka  ',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('warehouses', [
            'warehouse_code' => $whCode, // uppercased
            'warehouse_name' => 'Main Warehouse', // trimmed
            'branch_id'      => $branch->id,
            'location'       => 'Dhaka',
        ]);
    }

    public function test_store_sets_created_by_from_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);
        $branch = Branch::factory()->create();

        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'CREATED-BY-WH-01',
            'warehouse_name' => 'Created-By Test',
            'branch_id'      => $branch->id,
        ]);

        $this->assertDatabaseHas('warehouses', [
            'warehouse_code' => 'CREATED-BY-WH-01',
            'created_by'     => $user->id,
        ]);
    }

    public function test_store_rejects_inactive_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);

        $response = $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'INACT-BR-01',
            'warehouse_name' => 'Inactive Branch Test',
            'branch_id'      => $branch->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('warehouses', ['warehouse_code' => 'INACT-BR-01']);
    }

    public function test_store_fails_on_duplicate_warehouse_code(): void
    {
        $branch = Branch::factory()->create();
        Warehouse::factory()->forBranch($branch->id)->create(['warehouse_code' => 'DUP-WH-01']);

        $response = $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'DUP-WH-01',
            'warehouse_name' => 'Duplicate',
            'branch_id'      => $branch->id,
        ]);

        $response->assertSessionHasErrors('warehouse_code');
    }

    public function test_store_fails_when_branch_id_missing(): void
    {
        $response = $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'NOBR-WH-01',
            'warehouse_name' => 'No Branch',
        ]);

        $response->assertSessionHasErrors('branch_id');
    }

    public function test_store_fails_when_branch_id_does_not_exist(): void
    {
        $response = $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'BADBR-WH-01',
            'warehouse_name' => 'Bad Branch',
            'branch_id'      => 999999,
        ]);

        $response->assertSessionHasErrors('branch_id');
    }

    public function test_store_normalizes_warehouse_code_to_uppercase(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'lower-wh-01',
            'warehouse_name' => 'Lowercase Test',
            'branch_id'      => $branch->id,
        ]);

        $this->assertDatabaseHas('warehouses', ['warehouse_code' => 'LOWER-WH-01']);
        $this->assertDatabaseMissing('warehouses', ['warehouse_code' => 'lower-wh-01']);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_warehouse_details(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.warehouses.show', $warehouse));

        $response->assertOk();
        $response->assertViewIs('admin.warehouses.show');
        $response->assertViewHas('item');
        $this->assertEquals($warehouse->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_branch(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.warehouses.show', $warehouse));

        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('branch'));
    }

    public function test_show_returns_404_for_unknown_warehouse(): void
    {
        $this->get(route('admin.warehouses.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_warehouse_and_redirects_to_show(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => $warehouse->warehouse_code,
            'warehouse_name' => 'Updated Warehouse Name',
            'branch_id'      => $branch->id,
            'is_active'      => true,
        ]);

        $response->assertRedirect(route('admin.warehouses.show', $warehouse));
        $this->assertDatabaseHas('warehouses', [
            'id'             => $warehouse->id,
            'warehouse_name' => 'Updated Warehouse Name',
        ]);
    }

    public function test_update_can_change_branch_when_no_blockers(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch1->id)->create();

        $response = $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => $warehouse->warehouse_code,
            'warehouse_name' => $warehouse->warehouse_name,
            'branch_id'      => $branch2->id,
            'is_active'      => true,
        ]);

        $response->assertRedirect();
        $this->assertEquals($branch2->id, $warehouse->fresh()->branch_id);
    }

    public function test_update_blocked_from_changing_branch_when_stock_exists(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch1->id)->create();

        // Add stock
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 50.0);

        $response = $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => $warehouse->warehouse_code,
            'warehouse_name' => $warehouse->warehouse_name,
            'branch_id'      => $branch2->id,
            'is_active'      => true,
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals($branch1->id, $warehouse->fresh()->branch_id);
    }

    public function test_update_deactivates_warehouse_when_no_blockers(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => $warehouse->warehouse_code,
            'warehouse_name' => $warehouse->warehouse_name,
            'branch_id'      => $branch->id,
            'is_active'      => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($warehouse->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY
    // ====================================================================

    public function test_destroy_soft_deletes_warehouse_with_no_blockers(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->delete(route('admin.warehouses.destroy', $warehouse));

        $response->assertRedirect(route('admin.warehouses.index'));
        $response->assertSessionHas('success');

        $warehouse->refresh();
        $this->assertNotNull($warehouse->deleted_at);
        $this->assertFalse($warehouse->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->delete(route('admin.warehouses.destroy', $warehouse));

        $this->assertDatabaseHas('warehouses', [
            'id'         => $warehouse->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_warehouse_has_stock(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 100.0);

        $response = $this->delete(route('admin.warehouses.destroy', $warehouse));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', [
            'id'         => $warehouse->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_warehouse(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $warehouse->delete();

        $response = $this->post(route('admin.warehouses.restore', $warehouse));

        $response->assertRedirect(route('admin.warehouses.show', $warehouse));
        $warehouse->refresh();
        $this->assertNull($warehouse->deleted_at);
    }

    public function test_restore_returns_404_for_non_deleted_warehouse(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->post(route('admin.warehouses.restore', $warehouse))
            ->assertNotFound();
    }
}
