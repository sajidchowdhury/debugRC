<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\TestCase;

/**
 * Branch CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore.
 *
 * Validates BranchController (which overrides store + update for Phase 5
 * normalization) and inherits the rest from BaseMasterDataController.
 */
class BranchCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_branches(): void
    {
        Branch::factory()->count(3)->create();

        $response = $this->get(route('admin.branches.index'));

        $response->assertOk();
        $response->assertViewIs('admin.branches.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_branches(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        $response = $this->get(route('admin.branches.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        Branch::factory()->count(2)->create();

        $response = $this->get(route('admin.branches.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_branch_count(): void
    {
        Branch::factory()->count(2)->create();
        Branch::factory()->inactive()->create();

        $response = $this->get(route('admin.branches.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.branches.create'));

        $response->assertOk();
        $response->assertViewIs('admin.branches.create');
        $response->assertViewHas(['title', 'routePrefix', 'label']);
    }

    // ====================================================================
    // STORE (Phase 5: branch_code uppercased, branch_name trimmed)
    // ====================================================================

    public function test_store_creates_branch_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'pat-001',
            'branch_name' => '  Patuatuli Branch  ',
            'address'     => '123 Main St',
            'phone'       => '  01712345678  ',
            'email'       => 'pat@example.com',
            'is_active'   => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'branch_code' => 'PAT-001', // Phase 5: uppercased
            'branch_name' => 'Patuatuli Branch', // trimmed
            'phone'       => '01712345678', // trimmed
        ]);
    }

    public function test_store_sets_created_by_from_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');

        $this->actingAs($user)
            ->post(route('admin.branches.store'), [
                'branch_code' => 'CREATED-BY-01',
                'branch_name' => 'Created-By Test Branch',
            ]);

        $this->assertDatabaseHas('branches', [
            'branch_code' => 'CREATED-BY-01',
            'created_by'  => $user->id,
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'SHOW-REDIRECT-01',
            'branch_name' => 'Show Redirect Test',
        ]);

        $branch = Branch::where('branch_code', 'SHOW-REDIRECT-01')->first();
        $response->assertRedirect(route('admin.branches.show', $branch));
        $response->assertSessionHas('success');
    }

    public function test_store_fails_on_duplicate_branch_code(): void
    {
        Branch::factory()->create(['branch_code' => 'DUP-001']);

        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'DUP-001',
            'branch_name' => 'Duplicate Test',
        ]);

        $response->assertSessionHasErrors('branch_code');
    }

    public function test_store_fails_on_invalid_branch_code_pattern(): void
    {
        // Phase 5: branch_code regex /^[A-Za-z0-9\-_.]+$/
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'INVALID CODE!',
            'branch_name' => 'Bad Code Test',
        ]);

        $response->assertSessionHasErrors('branch_code');
    }

    public function test_store_fails_when_branch_name_missing(): void
    {
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'MISSING-NAME-01',
        ]);

        $response->assertSessionHasErrors('branch_name');
    }

    public function test_store_normalizes_branch_code_to_uppercase(): void
    {
        $this->post(route('admin.branches.store'), [
            'branch_code' => 'lower-case-01',
            'branch_name' => 'Lowercase Test',
        ]);

        $this->assertDatabaseHas('branches', [
            'branch_code' => 'LOWER-CASE-01',
        ]);
        $this->assertDatabaseMissing('branches', [
            'branch_code' => 'lower-case-01',
        ]);
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.branches.store'), [
            'branch_code' => 'MIN-01',
            'branch_name' => 'Minimal Branch',
            // address, phone, email omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'branch_code' => 'MIN-01',
            'branch_name' => 'Minimal Branch',
        ]);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_branch_details(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->get(route('admin.branches.show', $branch));

        $response->assertOk();
        $response->assertViewIs('admin.branches.show');
        $response->assertViewHas('item');
        $this->assertEquals($branch->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_employees_and_warehouses(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->count(2)->create();

        $response = $this->get(route('admin.branches.show', $branch));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('employees'));
        $this->assertTrue($item->relationLoaded('warehouses'));
        $this->assertCount(2, $item->employees);
    }

    public function test_show_works_for_soft_deleted_branch(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.branches.show', $branch));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_branch(): void
    {
        $this->get(route('admin.branches.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_branch(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->get(route('admin.branches.edit', $branch));

        $response->assertOk();
        $response->assertViewIs('admin.branches.edit');
        $response->assertViewHas('item');
        $this->assertEquals($branch->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE (Phase 5: deactivation safety check on is_active=false)
    // ====================================================================

    public function test_update_modifies_branch_and_redirects_to_show(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => 'Updated Branch Name',
            'address'     => 'New Address',
            'phone'       => '01799999999',
            'email'       => 'updated@example.com',
            'is_active'   => true,
        ]);

        $response->assertRedirect(route('admin.branches.show', $branch));
        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_name' => 'Updated Branch Name',
            'address'     => 'New Address',
        ]);
    }

    public function test_update_normalizes_branch_code_to_uppercase(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'UPDATE-01']);

        $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'update-01',
            'branch_name' => 'Updated Name',
        ]);

        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_code' => 'UPDATE-01',
        ]);
    }

    public function test_update_allows_changing_branch_code_to_unique_value(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'OLD-CODE-01']);

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'new-code-01',
            'branch_name' => $branch->branch_name,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_code' => 'NEW-CODE-01', // uppercased
        ]);
    }

    public function test_update_fails_on_duplicate_branch_code_from_other_branch(): void
    {
        Branch::factory()->create(['branch_code' => 'TAKEN-01']);
        $branch = Branch::factory()->create(['branch_code' => 'OWN-01']);

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'TAKEN-01',
            'branch_name' => $branch->branch_name,
        ]);

        $response->assertSessionHasErrors('branch_code');
    }

    public function test_update_allows_keeping_own_branch_code(): void
    {
        $branch = Branch::factory()->create(['branch_code' => 'KEEP-01']);

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => 'KEEP-01',
            'branch_name' => 'New Name Same Code',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_code' => 'KEEP-01',
            'branch_name' => 'New Name Same Code',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Branch with an active warehouse → deactivation should be blocked
        $branch = Branch::factory()->create();
        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-BLOCK-01');

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => $branch->branch_name,
            'is_active'   => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_update_deactivates_branch_when_no_blockers(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->put(route('admin.branches.update', $branch), [
            'branch_code' => $branch->branch_code,
            'branch_name' => $branch->branch_name,
            'is_active'   => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($branch->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_branch_with_no_blockers(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->delete(route('admin.branches.destroy', $branch));

        $response->assertRedirect(route('admin.branches.index'));
        $response->assertSessionHas('success');

        $branch->refresh();
        $this->assertNotNull($branch->deleted_at);
        $this->assertFalse($branch->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $branch = Branch::factory()->create();

        $this->delete(route('admin.branches.destroy', $branch));

        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_branch_has_active_warehouses(): void
    {
        $branch = Branch::factory()->create();
        $this->insertWarehouse($branch->id, isActive: true, code: 'WH-BLK-DST-01');

        $response = $this->delete(route('admin.branches.destroy', $branch));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_branch_has_active_employees(): void
    {
        $branch = Branch::factory()->create();
        Employee::factory()->forBranch($branch->id)->create();

        $response = $this->delete(route('admin.branches.destroy', $branch));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_branch(): void
    {
        $branch = Branch::factory()->create();
        $branch->delete();

        $response = $this->post(route('admin.branches.restore', $branch));

        $response->assertRedirect(route('admin.branches.show', $branch));
        $response->assertSessionHas('success');

        $branch->refresh();
        $this->assertNull($branch->deleted_at);
        $this->assertNull($branch->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_branch(): void
    {
        $branch = Branch::factory()->create(); // not deleted

        $response = $this->post(route('admin.branches.restore', $branch));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_branch(): void
    {
        $this->post(route('admin.branches.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_branch_count_increments_after_store(): void
    {
        $initialCount = Branch::count();

        $this->post(route('admin.branches.store'), [
            'branch_code' => 'COUNT-01',
            'branch_name' => 'Count Test',
        ]);

        $this->assertEquals($initialCount + 1, Branch::count());
    }

    public function test_soft_deleted_branch_excluded_from_default_index_query(): void
    {
        $branch = Branch::factory()->create(['branch_name' => 'Hide Me From Default']);
        $branch->delete();

        $response = $this->get(route('admin.branches.index'));

        $items = $response->viewData('items');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }
}
