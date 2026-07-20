<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Warehouse Validation tests — Phase 5 validation rules from
 * WarehouseController::validationRules().
 *
 * Rules:
 *   - warehouse_code: required|string|max:30|regex:/^[A-Za-z0-9\-_.]+$/|unique
 *   - warehouse_name: required|string|max:100
 *   - branch_id:      required|exists:branches,id
 *   - location:       nullable|string
 *   - is_active:      boolean
 *
 * Plus Phase 5 normalization: warehouse_code uppercased + warehouse_name trimmed.
 */
class WarehouseValidationTest extends TestCase
{
    use BuildsRoleUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function makeBranch(): Branch
    {
        return Branch::factory()->create();
    }

    // ====================================================================
    // warehouse_code — required + regex + unique + max
    // ====================================================================

    public function test_warehouse_code_is_required(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_name' => 'Missing Code',
            'branch_id'      => $this->makeBranch()->id,
        ])->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_code_rejects_spaces(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'WH 001',
            'warehouse_name' => 'Space Test',
            'branch_id'      => $this->makeBranch()->id,
        ])->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_code_rejects_special_chars(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'WH@001',
            'warehouse_name' => 'Special Test',
            'branch_id'      => $this->makeBranch()->id,
        ])->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_code_accepts_valid_pattern(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'WH-001_main.1',
            'warehouse_name' => 'Valid Code',
            'branch_id'      => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['warehouse_code' => 'WH-001_MAIN.1']);
    }

    public function test_warehouse_code_must_be_unique(): void
    {
        $branch = $this->makeBranch();
        Warehouse::factory()->forBranch($branch->id)->create(['warehouse_code' => 'UNIQ-WH-01']);

        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'UNIQ-WH-01',
            'warehouse_name' => 'Duplicate',
            'branch_id'      => $branch->id,
        ])->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_code_unique_check_is_case_insensitive(): void
    {
        $branch = $this->makeBranch();
        Warehouse::factory()->forBranch($branch->id)->create(['warehouse_code' => 'CASE-WH-01']);

        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'case-wh-01', // uppercased before unique check
            'warehouse_name' => 'Case Collision',
            'branch_id'      => $branch->id,
        ])->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_code_unique_allows_own_on_update(): void
    {
        $branch = $this->makeBranch();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create(['warehouse_code' => 'KEEP-WH-01']);

        $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => 'KEEP-WH-01',
            'warehouse_name' => 'Same Code Update',
            'branch_id'      => $branch->id,
        ])->assertRedirect();
    }

    // ====================================================================
    // warehouse_name — required + max
    // ====================================================================

    public function test_warehouse_name_is_required(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'NAME-REQ-01',
            'branch_id'      => $this->makeBranch()->id,
        ])->assertSessionHasErrors('warehouse_name');
    }

    public function test_warehouse_name_max_100(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'NAME-LONG-01',
            'warehouse_name' => str_repeat('X', 101),
            'branch_id'      => $this->makeBranch()->id,
        ])->assertSessionHasErrors('warehouse_name');
    }

    // ====================================================================
    // branch_id — required + exists
    // ====================================================================

    public function test_branch_id_is_required(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'NOBR-VAL-01',
            'warehouse_name' => 'No Branch',
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_id_must_exist(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'BADBR-VAL-01',
            'warehouse_name' => 'Bad Branch',
            'branch_id'      => 999999,
        ])->assertSessionHasErrors('branch_id');
    }

    // ====================================================================
    // Normalization
    // ====================================================================

    public function test_warehouse_code_uppercased_on_store(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'mixed-wh-01',
            'warehouse_name' => 'Mixed Case',
            'branch_id'      => $branch->id,
        ]);

        $this->assertDatabaseHas('warehouses', ['warehouse_code' => 'MIXED-WH-01']);
        $this->assertDatabaseMissing('warehouses', ['warehouse_code' => 'mixed-wh-01']);
    }

    public function test_warehouse_name_trimmed_on_store(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'TRIM-WH-01',
            'warehouse_name' => '  Trimmed Name  ',
            'branch_id'      => $branch->id,
        ]);

        $this->assertDatabaseHas('warehouses', ['warehouse_name' => 'Trimmed Name']);
    }

    public function test_location_trimmed_on_store(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'TRIM-LOC-01',
            'warehouse_name' => 'Trim Location',
            'branch_id'      => $branch->id,
            'location'       => '  123 Trimmed St  ',
        ]);

        $this->assertDatabaseHas('warehouses', ['location' => '123 Trimmed St']);
    }

    public function test_normalization_applies_on_update_too(): void
    {
        $branch = $this->makeBranch();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create(['warehouse_code' => 'NORM-UPD-01']);

        $this->put(route('admin.warehouses.update', $warehouse), [
            'warehouse_code' => 'norm-upd-01',
            'warehouse_name' => '  Updated Trimmed  ',
            'branch_id'      => $branch->id,
        ]);

        $this->assertDatabaseHas('warehouses', [
            'id'             => $warehouse->id,
            'warehouse_code' => 'NORM-UPD-01',
            'warehouse_name' => 'Updated Trimmed',
        ]);
    }

    // ====================================================================
    // is_active — boolean + default
    // ====================================================================

    public function test_is_active_accepts_true(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'ACT-TRUE-01',
            'warehouse_name' => 'Active True',
            'branch_id'      => $branch->id,
            'is_active'      => true,
        ])->assertRedirect();

        $warehouse = Warehouse::where('warehouse_code', 'ACT-TRUE-01')->first();
        $this->assertTrue($warehouse->is_active);
    }

    public function test_is_active_accepts_false(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'ACT-FALSE-01',
            'warehouse_name' => 'Active False',
            'branch_id'      => $branch->id,
            'is_active'      => false,
        ])->assertRedirect();

        $warehouse = Warehouse::where('warehouse_code', 'ACT-FALSE-01')->first();
        $this->assertFalse($warehouse->is_active);
    }

    public function test_is_active_defaults_to_true_when_omitted(): void
    {
        $branch = $this->makeBranch();
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'ACT-DEF-01',
            'warehouse_name' => 'Default Active',
            'branch_id'      => $branch->id,
        ])->assertRedirect();

        $warehouse = Warehouse::where('warehouse_code', 'ACT-DEF-01')->first();
        $this->assertTrue($warehouse->is_active);
    }

    // ====================================================================
    // Multiple errors at once
    // ====================================================================

    public function test_multiple_validation_errors_are_all_reported(): void
    {
        $this->post(route('admin.warehouses.store'), [
            'warehouse_code' => 'BAD CODE!',
            'warehouse_name' => '',
            'branch_id'      => 999999,
        ])->assertSessionHasErrors(['warehouse_code', 'warehouse_name', 'branch_id']);
    }
}
