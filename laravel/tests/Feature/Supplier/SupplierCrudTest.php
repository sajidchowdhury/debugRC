<?php

namespace Tests\Feature\Supplier;

use App\Models\Branch;
use App\Models\Supplier;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsSupplierDependencies;
use Tests\TestCase;

/**
 * Supplier CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore, toggle.
 *
 * Validates SupplierController (Phase 11: canDeactivate safety check +
 * auto-generated supplier_code + pre-validation normalization) inheriting
 * from BaseMasterDataController.
 */
class SupplierCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsSupplierDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_suppliers(): void
    {
        Supplier::factory()->count(3)->create();

        $response = $this->get(route('admin.suppliers.index'));

        $response->assertOk();
        $response->assertViewIs('admin.suppliers.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_suppliers(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $response = $this->get(route('admin.suppliers.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        Supplier::factory()->count(2)->create();

        $response = $this->get(route('admin.suppliers.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_supplier_count(): void
    {
        Supplier::factory()->count(2)->create();
        Supplier::factory()->inactive()->create();

        $response = $this->get(route('admin.suppliers.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_branch_name(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.suppliers.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $supplier->id);
        $this->assertNotNull($row, 'DataTables response should include the created supplier');
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.suppliers.create'));

        $response->assertOk();
        $response->assertViewIs('admin.suppliers.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'branches']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_supplier_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'SUP-ST-001',
            'supplier_name' => 'Test Supplier Store',
            'mobile'        => '01711000000',
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'SUP-ST-001',
            'supplier_name' => 'Test Supplier Store',
            'mobile'        => '01711000000',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'SUP-REDIR-01',
            'supplier_name' => 'Show Redirect Test',
        ]);

        $supplier = Supplier::where('supplier_code', 'SUP-REDIR-01')->first();
        $response->assertRedirect(route('admin.suppliers.show', $supplier));
        $response->assertSessionHas('success');
    }

    public function test_store_auto_generates_supplier_code_when_blank(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            // supplier_code intentionally omitted
            'supplier_name' => 'Auto Code Supplier',
        ]);

        $response->assertRedirect();
        $supplier = Supplier::where('supplier_name', 'Auto Code Supplier')->first();
        $this->assertNotNull($supplier);
        $this->assertMatchesRegularExpression('/^SUP-\d{6}$/', $supplier->supplier_code);
    }

    public function test_store_auto_generates_supplier_code_when_empty_string(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => '',
            'supplier_name' => 'Empty Code Supplier',
        ]);

        $supplier = Supplier::where('supplier_name', 'Empty Code Supplier')->first();
        $this->assertNotNull($supplier);
        $this->assertNotEmpty($supplier->supplier_code);
    }

    public function test_store_fails_on_duplicate_supplier_code(): void
    {
        Supplier::factory()->create(['supplier_code' => 'DUP-SUP-001']);

        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'DUP-SUP-001',
            'supplier_name' => 'Duplicate Test',
        ]);

        $response->assertSessionHasErrors('supplier_code');
    }

    public function test_store_fails_when_supplier_name_missing(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'MISSING-NAME-SUP-01',
        ]);

        $response->assertSessionHasErrors('supplier_name');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'MIN-SUP-01',
            'supplier_name' => 'Minimal Supplier',
            // phone, mobile, email, address, branch_id, contact_person omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('suppliers', [
            'supplier_code' => 'MIN-SUP-01',
            'supplier_name' => 'Minimal Supplier',
        ]);
    }

    public function test_store_links_to_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->post(route('admin.suppliers.store'), [
            'supplier_code'  => 'SUP-BS-01',
            'supplier_name'  => 'Branch Supplier',
            'branch_id'      => $branch->id,
            'contact_person' => 'John Doe',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'supplier_code'  => 'SUP-BS-01',
            'branch_id'      => $branch->id,
            'contact_person' => 'John Doe',
        ]);
    }

    public function test_store_stores_numeric_opening_balance(): void
    {
        $this->post(route('admin.suppliers.store'), [
            'supplier_code'   => 'SUP-RATE-01',
            'supplier_name'   => 'Rate Test',
            'opening_balance' => 1200.75,
            'balance_type'    => 'credit',
        ]);

        $supplier = Supplier::where('supplier_code', 'SUP-RATE-01')->first();
        $this->assertEquals('1200.75', (string) $supplier->opening_balance);
        $this->assertEquals('credit', $supplier->balance_type);
    }

    public function test_store_uppercases_supplier_code_before_unique_check(): void
    {
        // Phase 11: supplier_code is uppercased + trimmed BEFORE validation.
        // 'lower-01' becomes 'LOWER-01' before unique check.
        Supplier::factory()->create(['supplier_code' => 'UPPER-01']);

        // 'upper-01' should collide after normalization
        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'upper-01',
            'supplier_name' => 'Case Collision Test',
        ])->assertSessionHasErrors('supplier_code');
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_supplier_details(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->get(route('admin.suppliers.show', $supplier));

        $response->assertOk();
        $response->assertViewIs('admin.suppliers.show');
        $response->assertViewHas('item');
        $this->assertEquals($supplier->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_branch(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();

        $response = $this->get(route('admin.suppliers.show', $supplier));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('branch'));
    }

    public function test_show_works_for_soft_deleted_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.suppliers.show', $supplier));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_supplier(): void
    {
        $this->get(route('admin.suppliers.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->get(route('admin.suppliers.edit', $supplier));

        $response->assertOk();
        $response->assertViewIs('admin.suppliers.edit');
        $response->assertViewHas('item');
        $this->assertEquals($supplier->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_supplier_and_redirects_to_show(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => 'Updated Supplier Name',
            'is_active'     => true,
        ]);

        $response->assertRedirect(route('admin.suppliers.show', $supplier));
        $this->assertDatabaseHas('suppliers', [
            'id'            => $supplier->id,
            'supplier_name' => 'Updated Supplier Name',
        ]);
    }

    public function test_update_allows_changing_supplier_code_to_unique_value(): void
    {
        $supplier = Supplier::factory()->create(['supplier_code' => 'OLD-SUP-01']);

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'NEW-SUP-01',
            'supplier_name' => $supplier->supplier_name,
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('suppliers', [
            'id'            => $supplier->id,
            'supplier_code' => 'NEW-SUP-01',
        ]);
    }

    public function test_update_fails_on_duplicate_supplier_code_from_other_supplier(): void
    {
        Supplier::factory()->create(['supplier_code' => 'TAKEN-SUP-01']);
        $supplier = Supplier::factory()->create(['supplier_code' => 'OWN-SUP-01']);

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'TAKEN-SUP-01',
            'supplier_name' => $supplier->supplier_name,
            'is_active'     => true,
        ]);

        $response->assertSessionHasErrors('supplier_code');
    }

    public function test_update_allows_keeping_own_supplier_code(): void
    {
        $supplier = Supplier::factory()->create(['supplier_code' => 'KEEP-SUP-01']);

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => 'KEEP-SUP-01',
            'supplier_name' => 'New Name Same Code',
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('suppliers', [
            'id'            => $supplier->id,
            'supplier_code' => 'KEEP-SUP-01',
            'supplier_name' => 'New Name Same Code',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Supplier with outstanding AP balance → deactivation should be blocked
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 250.00, 'credit');

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->supplier_name,
            'is_active'     => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_update_deactivates_supplier_when_no_blockers(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->put(route('admin.suppliers.update', $supplier), [
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->supplier_name,
            'is_active'     => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_supplier_with_no_blockers(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->delete(route('admin.suppliers.destroy', $supplier));

        $response->assertRedirect(route('admin.suppliers.index'));
        $response->assertSessionHas('success');

        $supplier->refresh();
        $this->assertNotNull($supplier->deleted_at);
        $this->assertFalse($supplier->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $supplier = Supplier::factory()->create();

        $this->delete(route('admin.suppliers.destroy', $supplier));

        $this->assertDatabaseHas('suppliers', [
            'id'         => $supplier->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_supplier_has_outstanding_ap_balance(): void
    {
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 1000.00, 'credit');

        $response = $this->delete(route('admin.suppliers.destroy', $supplier));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('suppliers', [
            'id'         => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_supplier_has_open_purchase_order(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'draft');

        $response = $this->delete(route('admin.suppliers.destroy', $supplier));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('suppliers', [
            'id'         => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $response = $this->post(route('admin.suppliers.restore', $supplier));

        $response->assertRedirect(route('admin.suppliers.show', $supplier));
        $response->assertSessionHas('success');

        $supplier->refresh();
        $this->assertNull($supplier->deleted_at);
        $this->assertNull($supplier->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_supplier(): void
    {
        $supplier = Supplier::factory()->create(); // not deleted

        $response = $this->post(route('admin.suppliers.restore', $supplier));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_supplier(): void
    {
        $this->post(route('admin.suppliers.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_supplier_count_increments_after_store(): void
    {
        $initialCount = Supplier::count();

        $this->post(route('admin.suppliers.store'), [
            'supplier_code' => 'COUNT-SUP-01',
            'supplier_name' => 'Count Test',
        ]);

        $this->assertEquals($initialCount + 1, Supplier::count());
    }

    public function test_soft_deleted_supplier_excluded_from_default_index_query(): void
    {
        $toDelete = Supplier::factory()->create(['supplier_name' => 'Hide Me From Default']);
        $keep = Supplier::factory()->create(['supplier_name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.suppliers.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one supplier');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 11)
    // ====================================================================

    public function test_toggle_deactivates_active_supplier_with_no_blockers(): void
    {
        $supplier = Supplier::factory()->create();

        $response = $this->post(route('admin.suppliers.toggle', $supplier));

        $response->assertRedirect(route('admin.suppliers.index'));
        $response->assertSessionHas('success');

        $supplier->refresh();
        $this->assertFalse($supplier->is_active);
        $this->assertNotNull($supplier->deleted_at);
    }

    public function test_toggle_activates_inactive_supplier(): void
    {
        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $response = $this->post(route('admin.suppliers.toggle', $supplier));

        $response->assertRedirect(route('admin.suppliers.index'));
        $supplier->refresh();
        $this->assertTrue($supplier->is_active);
        $this->assertNull($supplier->deleted_at);
    }

    public function test_toggle_blocked_when_supplier_has_outstanding_balance(): void
    {
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 100.00, 'credit');

        $response = $this->post(route('admin.suppliers.toggle', $supplier));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('AP balance', session('error'));
        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_supplier(): void
    {
        $this->post(route('admin.suppliers.toggle', 999999))
            ->assertNotFound();
    }
}
