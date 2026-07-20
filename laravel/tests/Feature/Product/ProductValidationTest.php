<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroup;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Product Validation tests — verifies the validation rules defined in
 * ProductController::validationRules().
 *
 * Rules (Phase 9):
 *   - product_code:    required|string|max:50|unique:products,product_code,{id}
 *   - product_name:    required|string|max:200
 *   - category_id:     nullable|exists:product_categories,id
 *   - group_id:        nullable|exists:product_groups,id
 *   - unit:            required|in:Pcs,Carton,KG,Bag,Dobe,Set
 *   - purchase_rate:   nullable|numeric|min:0
 *   - sales_rate:      nullable|numeric|min:0
 *   - min_stock:       nullable|numeric
 *   - max_stock:       nullable|numeric
 *   - reorder_level:   nullable|numeric
 *   - is_active:       boolean
 *   - condition_state: nullable|in:Good,Damage
 *   - image:           nullable|image|mimes:jpeg,png,webp,gif|max:2048
 *
 * Phase 9 also includes:
 *   - is_active defaults to true when omitted (DB default applies)
 *   - On update, is_active only changes when explicitly provided
 */
class ProductValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // product_code — required
    // ====================================================================

    public function test_product_code_is_required_on_store(): void
    {
        $this->post(route('admin.products.store'), [
            'product_name' => 'Missing Code Product',
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_code');
    }

    public function test_product_code_is_required_on_update(): void
    {
        $product = Product::factory()->create();

        $this->put(route('admin.products.update', $product), [
            'product_name' => 'Updated Name',
            'unit'         => $product->unit,
        ])->assertSessionHasErrors('product_code');
    }

    // ====================================================================
    // product_code — max length 50
    // ====================================================================

    public function test_product_code_max_length_50(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => str_repeat('A', 51), // 51 chars
            'product_name' => 'Too Long Code',
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_code');
    }

    public function test_product_code_accepts_exactly_50_chars(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => str_repeat('A', 50),
            'product_name' => 'Exactly 50 Chars',
            'unit'         => 'Pcs',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['product_name' => 'Exactly 50 Chars']);
    }

    // ====================================================================
    // product_code — unique
    // ====================================================================

    public function test_product_code_must_be_unique_on_store(): void
    {
        Product::factory()->create(['product_code' => 'UNIQ-PRD-001']);

        $this->post(route('admin.products.store'), [
            'product_code' => 'UNIQ-PRD-001',
            'product_name' => 'Duplicate',
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_code');
    }

    public function test_product_code_unique_is_case_sensitive(): void
    {
        // ProductController does NOT normalize product_code to uppercase
        // (unlike Branch/Warehouse). 'uniq-prd-002' and 'UNIQ-PRD-002' are
        // distinct codes per PostgreSQL default collation.
        Product::factory()->create(['product_code' => 'UNIQ-PRD-002']);

        // 'uniq-prd-002' (lowercase) should NOT collide with 'UNIQ-PRD-002'.
        $this->post(route('admin.products.store'), [
            'product_code' => 'uniq-prd-002',
            'product_name' => 'Case Distinct Test',
            'unit'         => 'Pcs',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['product_code' => 'uniq-prd-002']);
    }

    public function test_product_code_unique_allows_keeping_own_code_on_update(): void
    {
        $product = Product::factory()->create(['product_code' => 'KEEP-PRD-02']);

        $this->put(route('admin.products.update', $product), [
            'product_code' => 'KEEP-PRD-02',
            'product_name' => 'Same Code Update',
            'unit'         => $product->unit,
            'is_active'    => true,
        ])->assertRedirect();
    }

    public function test_product_code_unique_rejects_other_products_code_on_update(): void
    {
        Product::factory()->create(['product_code' => 'TAKEN-PRD-02']);
        $product = Product::factory()->create(['product_code' => 'OWN-PRD-02']);

        $this->put(route('admin.products.update', $product), [
            'product_code' => 'TAKEN-PRD-02',
            'product_name' => 'Steal Other Code',
            'unit'         => $product->unit,
            'is_active'    => true,
        ])->assertSessionHasErrors('product_code');
    }

    public function test_product_code_rejects_empty_string(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => '',
            'product_name' => 'Empty Code',
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_code');
    }

    // ====================================================================
    // product_name — required, max 200
    // ====================================================================

    public function test_product_name_is_required_on_store(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'NAME-REQ-PRD-01',
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_name');
    }

    public function test_product_name_max_length_200(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'NAME-LONG-PRD-01',
            'product_name' => str_repeat('X', 201),
            'unit'         => 'Pcs',
        ])->assertSessionHasErrors('product_name');
    }

    public function test_product_name_accepts_exactly_200_chars(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'NAME-200-PRD-01',
            'product_name' => str_repeat('X', 200),
            'unit'         => 'Pcs',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', [
            'product_code' => 'NAME-200-PRD-01',
            'product_name' => str_repeat('X', 200),
        ]);
    }

    // ====================================================================
    // unit — required + enum
    // ====================================================================

    public function test_unit_is_required(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'UNIT-REQ-PRD-01',
            'product_name' => 'Missing Unit',
        ])->assertSessionHasErrors('unit');
    }

    public function test_unit_accepts_all_valid_values(): void
    {
        $units = ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];

        foreach ($units as $i => $unit) {
            $this->post(route('admin.products.store'), [
                'product_code' => 'UNIT-' . $i . '-' . $unit,
                'product_name' => 'Unit Test ' . $unit,
                'unit'         => $unit,
            ])->assertRedirect();
        }
    }

    public function test_unit_rejects_invalid_value(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'UNIT-BAD-PRD-01',
            'product_name' => 'Bad Unit',
            'unit'         => 'Liter',
        ])->assertSessionHasErrors('unit');
    }

    public function test_unit_rejects_empty_string(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'UNIT-EMPTY-PRD-01',
            'product_name' => 'Empty Unit',
            'unit'         => '',
        ])->assertSessionHasErrors('unit');
    }

    public function test_unit_case_sensitive_check(): void
    {
        // Validation rule uses `in:` which is case-sensitive — 'pcs' should fail.
        $this->post(route('admin.products.store'), [
            'product_code' => 'UNIT-CASE-PRD-01',
            'product_name' => 'Lowercase Unit',
            'unit'         => 'pcs',
        ])->assertSessionHasErrors('unit');
    }

    // ====================================================================
    // category_id — nullable + exists
    // ====================================================================

    public function test_category_id_is_optional(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'CAT-OPT-PRD-01',
            'product_name' => 'No Category',
            'unit'         => 'Pcs',
        ])->assertRedirect();
    }

    public function test_category_id_must_exist(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'CAT-BAD-PRD-01',
            'product_name' => 'Bad Category',
            'unit'         => 'Pcs',
            'category_id'  => 999999,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_category_id_accepts_valid_id(): void
    {
        $category = ProductCategory::factory()->create();

        $this->post(route('admin.products.store'), [
            'product_code' => 'CAT-OK-PRD-01',
            'product_name' => 'Valid Category',
            'unit'         => 'Pcs',
            'category_id'  => $category->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // group_id — nullable + exists
    // ====================================================================

    public function test_group_id_is_optional(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'GRP-OPT-PRD-01',
            'product_name' => 'No Group',
            'unit'         => 'Pcs',
        ])->assertRedirect();
    }

    public function test_group_id_must_exist(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'GRP-BAD-PRD-01',
            'product_name' => 'Bad Group',
            'unit'         => 'Pcs',
            'group_id'     => 999999,
        ])->assertSessionHasErrors('group_id');
    }

    public function test_group_id_accepts_valid_id(): void
    {
        $group = ProductGroup::factory()->create();

        $this->post(route('admin.products.store'), [
            'product_code' => 'GRP-OK-PRD-01',
            'product_name' => 'Valid Group',
            'unit'         => 'Pcs',
            'group_id'     => $group->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // purchase_rate / sales_rate — nullable + numeric + min:0
    // ====================================================================

    public function test_purchase_rate_is_optional(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'PR-OPT-PRD-01',
            'product_name' => 'No Purchase Rate',
            'unit'         => 'Pcs',
        ])->assertRedirect();
    }

    public function test_purchase_rate_must_be_numeric(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'  => 'PR-BAD-PRD-01',
            'product_name'  => 'Bad Purchase Rate',
            'unit'          => 'Pcs',
            'purchase_rate' => 'not-a-number',
        ])->assertSessionHasErrors('purchase_rate');
    }

    public function test_purchase_rate_rejects_negative(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'  => 'PR-NEG-PRD-01',
            'product_name'  => 'Negative Purchase Rate',
            'unit'          => 'Pcs',
            'purchase_rate' => -1,
        ])->assertSessionHasErrors('purchase_rate');
    }

    public function test_sales_rate_must_be_numeric(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'SR-BAD-PRD-01',
            'product_name' => 'Bad Sales Rate',
            'unit'         => 'Pcs',
            'sales_rate'   => 'free',
        ])->assertSessionHasErrors('sales_rate');
    }

    public function test_sales_rate_rejects_negative(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'SR-NEG-PRD-01',
            'product_name' => 'Negative Sales Rate',
            'unit'         => 'Pcs',
            'sales_rate'   => -0.01,
        ])->assertSessionHasErrors('sales_rate');
    }

    // ====================================================================
    // min_stock / max_stock / reorder_level — nullable + numeric
    // ====================================================================

    public function test_min_stock_must_be_numeric(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'MS-BAD-PRD-01',
            'product_name' => 'Bad Min Stock',
            'unit'         => 'Pcs',
            'min_stock'    => 'lots',
        ])->assertSessionHasErrors('min_stock');
    }

    public function test_max_stock_must_be_numeric(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'MXS-BAD-PRD-01',
            'product_name' => 'Bad Max Stock',
            'unit'         => 'Pcs',
            'max_stock'    => 'many',
        ])->assertSessionHasErrors('max_stock');
    }

    public function test_reorder_level_must_be_numeric(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'  => 'RL-BAD-PRD-01',
            'product_name'  => 'Bad Reorder Level',
            'unit'          => 'Pcs',
            'reorder_level' => 'unknown',
        ])->assertSessionHasErrors('reorder_level');
    }

    public function test_stock_fields_accept_zero_and_negative(): void
    {
        // No min:0 constraint on stock fields (unlike rates), so zero/negative allowed.
        $this->post(route('admin.products.store'), [
            'product_code'  => 'STK-ZN-PRD-01',
            'product_name'  => 'Zero Negative Stock',
            'unit'          => 'Pcs',
            'min_stock'     => 0,
            'max_stock'     => -10,
            'reorder_level' => -5,
        ])->assertRedirect();
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'ACT-TRUE-PRD-01',
            'product_name' => 'Active True',
            'unit'         => 'Pcs',
            'is_active'    => true,
        ])->assertRedirect();

        $product = Product::where('product_code', 'ACT-TRUE-PRD-01')->first();
        $this->assertTrue($product->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'ACT-FALSE-PRD-01',
            'product_name' => 'Active False',
            'unit'         => 'Pcs',
            'is_active'    => false,
        ])->assertRedirect();

        $product = Product::where('product_code', 'ACT-FALSE-PRD-01')->first();
        $this->assertFalse($product->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'ACT-DEF-PRD-01',
            'product_name' => 'Default Active',
            'unit'         => 'Pcs',
        ])->assertRedirect();

        $product = Product::where('product_code', 'ACT-DEF-PRD-01')->first();
        $this->assertTrue($product->is_active, 'Product should default to active when is_active is omitted');
    }

    public function test_is_active_not_silently_flipped_on_update_when_omitted(): void
    {
        // Phase 9 fix: omitting is_active on update should NOT change is_active.
        $product = Product::factory()->create(['is_active' => true]);

        $this->put(route('admin.products.update', $product), [
            'product_code' => $product->product_code,
            'product_name' => 'Some Update',
            'unit'         => $product->unit,
            // is_active omitted
        ])->assertRedirect();

        $this->assertTrue($product->fresh()->is_active, 'is_active should remain true when omitted on update');
    }

    // ====================================================================
    // condition_state — nullable + enum
    // ====================================================================

    public function test_condition_state_is_optional(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'CS-OPT-PRD-01',
            'product_name' => 'No Condition State',
            'unit'         => 'Pcs',
        ])->assertRedirect();
    }

    public function test_condition_state_accepts_good(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'    => 'CS-GOOD-PRD-01',
            'product_name'    => 'Good Condition',
            'unit'            => 'Pcs',
            'condition_state' => 'Good',
        ])->assertRedirect();
    }

    public function test_condition_state_accepts_damage(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'    => 'CS-DMG-PRD-01',
            'product_name'    => 'Damaged Condition',
            'unit'            => 'Pcs',
            'condition_state' => 'Damage',
        ])->assertRedirect();
    }

    public function test_condition_state_rejects_invalid_value(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code'    => 'CS-BAD-PRD-01',
            'product_name'    => 'Bad Condition',
            'unit'            => 'Pcs',
            'condition_state' => 'Broken',
        ])->assertSessionHasErrors('condition_state');
    }

    public function test_condition_state_defaults_to_good_in_db(): void
    {
        $this->post(route('admin.products.store'), [
            'product_code' => 'CS-DEF-PRD-01',
            'product_name' => 'Default Condition',
            'unit'         => 'Pcs',
        ])->assertRedirect();

        $product = Product::where('product_code', 'CS-DEF-PRD-01')->first();
        $this->assertEquals('Good', $product->condition_state);
    }

    // ====================================================================
    // Multiple validation errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $response = $this->post(route('admin.products.store'), [
            'product_code' => '',                 // required
            'product_name' => '',                 // required
            'unit'         => 'Liter',            // invalid enum
            'category_id'  => 999999,             // does not exist
        ]);

        $response->assertSessionHasErrors(['product_code', 'product_name', 'unit', 'category_id']);
    }
}
