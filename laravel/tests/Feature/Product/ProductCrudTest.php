<?php

namespace Tests\Feature\Product;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroup;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Product CRUD tests — full lifecycle: index, create, store, show, edit,
 * update, destroy (soft-delete), restore.
 *
 * Validates ProductController (Phase 9: canDeactivate safety check +
 * image upload + filtered DataTables) inheriting from BaseMasterDataController.
 */
class ProductCrudTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsProductDependencies, InsertsWarehouseDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // INDEX
    // ====================================================================

    public function test_index_returns_ok_with_paginated_products(): void
    {
        Product::factory()->count(3)->create();

        $response = $this->get(route('admin.products.index'));

        $response->assertOk();
        $response->assertViewIs('admin.products.index');
        $response->assertViewHas(['title', 'items', 'showDeleted', 'stats', 'routePrefix', 'label']);
    }

    public function test_index_with_deleted_query_param_shows_inactive_products(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $response = $this->get(route('admin.products.index', ['deleted' => 1]));

        $response->assertOk();
        $response->assertViewHas('showDeleted', true);
    }

    public function test_index_data_tables_endpoint_returns_json(): void
    {
        Product::factory()->count(2)->create();

        $response = $this->get(route('admin.products.index', ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();
        $response->assertJsonStructure([
            'draw',
            'recordsTotal',
            'recordsFiltered',
            'data',
        ]);
    }

    public function test_index_stats_include_active_product_count(): void
    {
        Product::factory()->count(2)->create();
        Product::factory()->inactive()->create();

        $response = $this->get(route('admin.products.index'));

        $response->assertViewHas('stats', function ($stats): bool {
            return isset($stats['active']) && $stats['active'] >= 2;
        });
    }

    public function test_index_data_tables_endpoint_returns_category_and_group_names(): void
    {
        $category = ProductCategory::factory()->create();
        $group = ProductGroup::factory()->create();
        // Unique searchable product_name + search.value filter so the
        // DataTables response narrows to JUST this product, regardless
        // of how much seeded baseline data already exists in the test DB
        // (the table has 1000+ seeded products; without a search filter,
        // our just-created product lands past the length=25 first page).
        // Mirrors the fix applied to SupplierCrudTest in commit ee6341d.
        $searchToken = 'DATATABLE_PRODUCT_LOOKUP_' . substr(uniqid(), -6);
        $product = Product::factory()
            ->forCategory($category->id)
            ->forGroup($group->id)
            ->create(['product_name' => $searchToken]);

        $response = $this->get(route('admin.products.index', [
            'draw'   => 1,
            'start'  => 0,
            'length' => 25,
            'search' => ['value' => $searchToken],
        ]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($row, 'DataTables response should include the created product');
        $this->assertEquals($category->category_name, $row['category_name']);
        $this->assertEquals($group->group_name, $row['group_name']);
    }

    public function test_index_data_tables_endpoint_applies_filter_category(): void
    {
        $cat1 = ProductCategory::factory()->create();
        $cat2 = ProductCategory::factory()->create();
        Product::factory()->forCategory($cat1->id)->create();
        Product::factory()->forCategory($cat2->id)->create();

        $response = $this->get(route('admin.products.index', [
            'draw'            => 1,
            'start'           => 0,
            'length'          => 25,
            'filterCategory'  => $cat1->id,
        ]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($cat1->id, $data[0]['category_id']);
    }

    public function test_index_data_tables_endpoint_applies_filter_unit(): void
    {
        Product::factory()->create(['unit' => 'Pcs']);
        Product::factory()->create(['unit' => 'KG']);

        $response = $this->get(route('admin.products.index', [
            'draw'       => 1,
            'start'      => 0,
            'length'     => 25,
            'filterUnit' => 'KG',
        ]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('KG', $data[0]['unit']);
    }

    // ====================================================================
    // CREATE
    // ====================================================================

    public function test_create_returns_ok_with_form(): void
    {
        $response = $this->get(route('admin.products.create'));

        $response->assertOk();
        $response->assertViewIs('admin.products.create');
        $response->assertViewHas(['title', 'routePrefix', 'label', 'categories', 'groups', 'units']);
    }

    // ====================================================================
    // STORE
    // ====================================================================

    public function test_store_creates_product_and_redirects_to_show(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code'  => 'PRD-ST-001',
            'product_name'  => 'Test Product Store',
            'unit'          => 'Pcs',
            'purchase_rate' => 10.50,
            'sales_rate'    => 15.00,
            'is_active'     => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'product_code'  => 'PRD-ST-001',
            'product_name'  => 'Test Product Store',
            'unit'          => 'Pcs',
        ]);
    }

    public function test_store_redirects_to_show_page_with_success_message(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code' => 'PRD-REDIR-01',
            'product_name' => 'Show Redirect Test',
            'unit'         => 'Pcs',
        ]);

        $product = Product::where('product_code', 'PRD-REDIR-01')->first();
        $response->assertRedirect(route('admin.products.show', $product));
        $response->assertSessionHas('success');
    }

    public function test_store_fails_on_duplicate_product_code(): void
    {
        Product::factory()->create(['product_code' => 'DUP-PRD-001']);

        $response = $this->post(route('admin.products.store'), [
            'product_code' => 'DUP-PRD-001',
            'product_name' => 'Duplicate Test',
            'unit'         => 'Pcs',
        ]);

        $response->assertSessionHasErrors('product_code');
    }

    public function test_store_fails_when_product_name_missing(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code' => 'MISSING-NAME-01',
            'unit'         => 'Pcs',
        ]);

        $response->assertSessionHasErrors('product_name');
    }

    public function test_store_fails_when_unit_missing(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code' => 'MISSING-UNIT-01',
            'product_name' => 'Missing Unit Test',
        ]);

        $response->assertSessionHasErrors('unit');
    }

    public function test_store_accepts_optional_fields_as_null(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code' => 'MIN-PRD-01',
            'product_name' => 'Minimal Product',
            'unit'         => 'Pcs',
            // category_id, group_id, purchase_rate, sales_rate omitted
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'product_code' => 'MIN-PRD-01',
            'product_name' => 'Minimal Product',
        ]);
    }

    public function test_store_links_to_category_and_group(): void
    {
        $category = ProductCategory::factory()->create();
        $group = ProductGroup::factory()->create();

        $this->post(route('admin.products.store'), [
            'product_code' => 'PRD-CG-01',
            'product_name' => 'Cat+Group Product',
            'category_id'  => $category->id,
            'group_id'     => $group->id,
            'unit'         => 'Pcs',
        ]);

        $this->assertDatabaseHas('products', [
            'product_code' => 'PRD-CG-01',
            'category_id'  => $category->id,
            'group_id'     => $group->id,
        ]);
    }

    public function test_store_stores_numeric_rates(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'  => 'PRD-RATE-01',
            'product_name'  => 'Rate Test',
            'unit'          => 'Pcs',
            'purchase_rate' => 12.34,
            'sales_rate'    => 56.78,
        ]);

        $product = Product::where('product_code', 'PRD-RATE-01')->first();
        $this->assertEquals('12.34', (string) $product->purchase_rate);
        $this->assertEquals('56.78', (string) $product->sales_rate);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_displays_product_details(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('admin.products.show', $product));

        $response->assertOk();
        $response->assertViewIs('admin.products.show');
        $response->assertViewHas('item');
        $this->assertEquals($product->id, $response->viewData('item')->id);
    }

    public function test_show_eager_loads_category_group_and_price_history(): void
    {
        $category = ProductCategory::factory()->create();
        $group = ProductGroup::factory()->create();
        $product = Product::factory()
            ->forCategory($category->id)
            ->forGroup($group->id)
            ->create();

        $response = $this->get(route('admin.products.show', $product));

        $response->assertOk();
        $item = $response->viewData('item');
        $this->assertTrue($item->relationLoaded('category'));
        $this->assertTrue($item->relationLoaded('group'));
        $this->assertTrue($item->relationLoaded('priceHistory'));
    }

    public function test_show_works_for_soft_deleted_product(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        // show uses withTrashed() — should still find the record
        $response = $this->get(route('admin.products.show', $product));

        $response->assertOk();
    }

    public function test_show_returns_404_for_unknown_product(): void
    {
        $this->get(route('admin.products.show', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // EDIT
    // ====================================================================

    public function test_edit_displays_form_with_existing_product(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('admin.products.edit', $product));

        $response->assertOk();
        $response->assertViewIs('admin.products.edit');
        $response->assertViewHas('item');
        $this->assertEquals($product->id, $response->viewData('item')->id);
    }

    // ====================================================================
    // UPDATE
    // ====================================================================

    public function test_update_modifies_product_and_redirects_to_show(): void
    {
        $product = Product::factory()->create();

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'Updated Product Name',
            'unit'         => $product->unit,
            'is_active'    => true,
        ]);

        $response->assertRedirect(route('admin.products.show', $product));
        $this->assertDatabaseHas('products', [
            'id'           => $product->id,
            'product_name' => 'Updated Product Name',
        ]);
    }

    public function test_update_allows_changing_product_code_to_unique_value(): void
    {
        $product = Product::factory()->create(['product_code' => 'OLD-PRD-01']);

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => 'NEW-PRD-01',
            'product_name' => $product->product_name,
            'unit'         => $product->unit,
            'is_active'    => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'id'           => $product->id,
            'product_code' => 'NEW-PRD-01',
        ]);
    }

    public function test_update_fails_on_duplicate_product_code_from_other_product(): void
    {
        Product::factory()->create(['product_code' => 'TAKEN-PRD-01']);
        $product = Product::factory()->create(['product_code' => 'OWN-PRD-01']);

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => 'TAKEN-PRD-01',
            'product_name' => $product->product_name,
            'unit'         => $product->unit,
            'is_active'    => true,
        ]);

        $response->assertSessionHasErrors('product_code');
    }

    public function test_update_allows_keeping_own_product_code(): void
    {
        $product = Product::factory()->create(['product_code' => 'KEEP-PRD-01']);

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => 'KEEP-PRD-01',
            'product_name' => 'New Name Same Code',
            'unit'         => $product->unit,
            'is_active'    => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', [
            'id'           => $product->id,
            'product_code' => 'KEEP-PRD-01',
            'product_name' => 'New Name Same Code',
        ]);
    }

    public function test_update_with_is_active_false_runs_deactivation_safety_check(): void
    {
        // Product with stock-on-hand → deactivation should be blocked
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 25.0);

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'unit'         => $product->unit,
            'is_active'    => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_update_deactivates_product_when_no_blockers(): void
    {
        $product = Product::factory()->create();

        $response = $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'unit'         => $product->unit,
            'is_active'    => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
    }

    // ====================================================================
    // DESTROY (soft-delete with deactivation safety check)
    // ====================================================================

    public function test_destroy_soft_deletes_product_with_no_blockers(): void
    {
        $product = Product::factory()->create();

        $response = $this->delete(route('admin.products.destroy', $product));

        $response->assertRedirect(route('admin.products.index'));
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertNotNull($product->deleted_at);
        $this->assertFalse($product->is_active);
    }

    public function test_destroy_sets_deleted_by_to_authenticated_user(): void
    {
        $user = $this->makeRoleUser('admin');
        $this->actingAs($user);

        $product = Product::factory()->create();

        $this->delete(route('admin.products.destroy', $product));

        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_by' => $user->id,
        ]);
    }

    public function test_destroy_blocked_when_product_has_stock_on_hand(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 100.0);

        $response = $this->delete(route('admin.products.destroy', $product));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_product_has_open_invoice_items(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'confirmed');

        $response = $this->delete(route('admin.products.destroy', $product));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_blocked_when_product_has_pending_purchase_order_items(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'draft');

        $response = $this->delete(route('admin.products.destroy', $product));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', [
            'id'         => $product->id,
            'deleted_at' => null,
        ]);
    }

    // ====================================================================
    // RESTORE
    // ====================================================================

    public function test_restore_reactivates_soft_deleted_product(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $response = $this->post(route('admin.products.restore', $product));

        $response->assertRedirect(route('admin.products.show', $product));
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertNull($product->deleted_at);
        $this->assertNull($product->deleted_by);
    }

    public function test_restore_only_works_on_soft_deleted_product(): void
    {
        $product = Product::factory()->create(); // not deleted

        $response = $this->post(route('admin.products.restore', $product));

        $response->assertNotFound();
    }

    public function test_restore_returns_404_for_unknown_product(): void
    {
        $this->post(route('admin.products.restore', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // Edge cases
    // ====================================================================

    public function test_product_count_increments_after_store(): void
    {
        $initialCount = Product::count();

        $this->post(route('admin.products.store'), [
            'product_code' => 'COUNT-PRD-01',
            'product_name' => 'Count Test',
            'unit'         => 'Pcs',
        ]);

        $this->assertEquals($initialCount + 1, Product::count());
    }

    public function test_soft_deleted_product_excluded_from_default_index_query(): void
    {
        // Create one product to delete + another to remain visible in the index.
        $toDelete = Product::factory()->create(['product_name' => 'Hide Me From Default']);
        $keep = Product::factory()->create(['product_name' => 'Keep Me Visible']);
        $toDelete->delete();

        $response = $this->get(route('admin.products.index'));

        $items = $response->viewData('items');
        $this->assertGreaterThan(0, $items->count(), 'Index should return at least one product');
        $items->each(function ($item) {
            $this->assertNull($item->deleted_at);
        });
    }

    // ====================================================================
    // TOGGLE (inherited from BaseMasterDataController, Phase 9)
    // ====================================================================

    public function test_toggle_deactivates_active_product_with_no_blockers(): void
    {
        $product = Product::factory()->create();

        $response = $this->post(route('admin.products.toggle', $product));

        $response->assertRedirect(route('admin.products.index'));
        $response->assertSessionHas('success');

        $product->refresh();
        $this->assertFalse($product->is_active);
        $this->assertNotNull($product->deleted_at);
    }

    public function test_toggle_activates_inactive_product(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        $response = $this->post(route('admin.products.toggle', $product));

        $response->assertRedirect(route('admin.products.index'));
        $product->refresh();
        $this->assertTrue($product->is_active);
        $this->assertNull($product->deleted_at);
    }

    public function test_toggle_blocked_when_product_has_stock_on_hand(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 30.0);

        $response = $this->post(route('admin.products.toggle', $product));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('stock', session('error'));
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_toggle_returns_404_for_unknown_product(): void
    {
        $this->post(route('admin.products.toggle', 999999))
            ->assertNotFound();
    }

    // ====================================================================
    // PRICE HISTORY (ProductController-specific endpoints, Phase 4-A)
    // ====================================================================

    public function test_price_history_page_displays_for_product(): void
    {
        $product = Product::factory()->create();

        $response = $this->get(route('admin.products.priceHistory', $product));

        $response->assertOk();
        $response->assertViewIs('admin.products.price_history');
        $response->assertViewHas(['product', 'history', 'currentPrice', 'routePrefix', 'label']);
        $this->assertEquals($product->id, $response->viewData('product')->id);
    }

    public function test_price_history_works_for_soft_deleted_product(): void
    {
        $product = Product::factory()->create();
        $product->delete();

        // priceHistory() uses withTrashed() — should still find the record
        $this->get(route('admin.products.priceHistory', $product))->assertOk();
    }

    public function test_add_price_creates_price_history_entry(): void
    {
        $product = Product::factory()->create();

        $response = $this->post(route('admin.products.addPrice', $product), [
            'min_rate'     => 10.00,
            'max_rate'     => 20.00,
            'default_rate' => 15.00,
        ]);

        $response->assertRedirect(route('admin.products.priceHistory', $product));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('product_price_history', [
            'product_id'   => $product->id,
            'min_rate'     => 10.00,
            'max_rate'     => 20.00,
            'default_rate' => 15.00,
        ]);
    }

    public function test_add_price_closes_out_previous_current_price(): void
    {
        $product = Product::factory()->create();

        // First price entry — open-ended (effective_to = null)
        $this->post(route('admin.products.addPrice', $product), [
            'min_rate'     => 10.00,
            'max_rate'     => 20.00,
            'default_rate' => 15.00,
            'effective_from' => '2025-01-01',
        ]);

        // Second price entry — should close out the first
        $this->post(route('admin.products.addPrice', $product), [
            'min_rate'     => 12.00,
            'max_rate'     => 22.00,
            'default_rate' => 17.00,
            'effective_from' => '2025-02-01',
        ]);

        $firstEntry = DB::table('product_price_history')
            ->where('product_id', $product->id)
            ->where('min_rate', 10.00)
            ->first();
        $this->assertNotNull($firstEntry->effective_to, 'Previous current price should have effective_to set');
        $this->assertEquals('2025-02-01', $firstEntry->effective_to);
    }

    public function test_add_price_validates_min_rate_required(): void
    {
        $product = Product::factory()->create();

        $this->post(route('admin.products.addPrice', $product), [
            'max_rate'     => 20.00,
            'default_rate' => 15.00,
        ])->assertSessionHasErrors('min_rate');
    }

    public function test_add_price_validates_max_rate_gte_min_rate(): void
    {
        $product = Product::factory()->create();

        $this->post(route('admin.products.addPrice', $product), [
            'min_rate'     => 30.00,
            'max_rate'     => 20.00, // less than min_rate
            'default_rate' => 25.00,
        ])->assertSessionHasErrors('max_rate');
    }

    public function test_add_price_validates_default_rate_within_range(): void
    {
        $product = Product::factory()->create();

        $this->post(route('admin.products.addPrice', $product), [
            'min_rate'     => 10.00,
            'max_rate'     => 20.00,
            'default_rate' => 25.00, // outside [min, max]
        ])->assertSessionHasErrors('default_rate');
    }

    public function test_delete_price_removes_price_history_entry(): void
    {
        $product = Product::factory()->create();
        $priceId = DB::table('product_price_history')->insertGetId([
            'product_id'    => $product->id,
            'min_rate'      => 10.00,
            'max_rate'      => 20.00,
            'default_rate'  => 15.00,
            'effective_from' => now()->toDateString(),
            'created_at'    => now(),
        ]);

        $response = $this->delete(route('admin.products.deletePrice', [$product->id, $priceId]));

        $response->assertRedirect(route('admin.products.priceHistory', $product));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('product_price_history', ['id' => $priceId]);
    }

    public function test_delete_price_404s_for_unknown_price_id(): void
    {
        $product = Product::factory()->create();

        $this->delete(route('admin.products.deletePrice', [$product->id, 999999]))
            ->assertNotFound();
    }

    public function test_price_history_page_shows_current_price_when_set(): void
    {
        $product = Product::factory()->create();
        DB::table('product_price_history')->insert([
            'product_id'    => $product->id,
            'min_rate'      => 10.00,
            'max_rate'      => 20.00,
            'default_rate'  => 15.00,
            'effective_from' => now()->toDateString(),
            'effective_to'  => null,
            'created_at'    => now(),
        ]);

        $response = $this->get(route('admin.products.priceHistory', $product));

        $response->assertOk();
        $currentPrice = $response->viewData('currentPrice');
        $this->assertNotNull($currentPrice, 'Current price should be set when an open-ended price entry exists');
        $this->assertEquals('15.00', (string) $currentPrice->default_rate);
    }
}
