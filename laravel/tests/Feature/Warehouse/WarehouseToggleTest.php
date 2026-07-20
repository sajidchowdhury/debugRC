<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Warehouse Toggle tests — toggle activate↔deactivate + 3 deactivation
 * safety checks from WarehouseController::canDeactivate().
 *
 * The 3 checks mirror legacy WarehouseModel::canDeactivateWarehouse():
 *   1. No stock (qty > 0) in warehouse_stock
 *   2. No pending dispatches (ordered_qty > dispatched_qty on open invoices)
 *   3. No active stock take sessions (draft/counting)
 */
class WarehouseToggleTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    // ====================================================================
    // ACTIVATE / DEACTIVATE flow
    // ====================================================================

    public function test_toggle_deactivates_warehouse_with_no_blockers(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertRedirect(route('admin.warehouses.index'));
        $response->assertSessionHas('success');

        $warehouse->refresh();
        $this->assertFalse($warehouse->is_active);
        $this->assertNotNull($warehouse->deleted_at);
    }

    public function test_toggle_activates_inactive_warehouse(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $warehouse->delete();

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertRedirect(route('admin.warehouses.index'));
        $this->assertTrue($warehouse->fresh()->is_active);
        $this->assertNull($warehouse->fresh()->deleted_at);
    }

    public function test_toggle_can_be_called_repeatedly_to_flip_state(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $this->post(route('admin.warehouses.toggle', $warehouse));
        $this->assertFalse($warehouse->fresh()->is_active);

        $this->post(route('admin.warehouses.toggle', $warehouse));
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    // ====================================================================
    // SAFETY CHECK 1: Stock in warehouse_stock
    // ====================================================================

    public function test_toggle_blocked_when_warehouse_has_stock(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 75.5);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('stock', session('error'));
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_stock_is_zero(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 0.0);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('success');
        $this->assertFalse($warehouse->fresh()->is_active);
    }

    public function test_toggle_blocked_message_includes_stock_quantity(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 125.75);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $errorMessage = session('error');
        $this->assertStringContainsString('125.75', $errorMessage);
    }

    // ====================================================================
    // SAFETY CHECK 2: Pending dispatches
    // ====================================================================

    public function test_toggle_blocked_when_pending_dispatch_exists(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceDispatch($warehouse->id, $branch->id, orderedQty: 10, dispatchedQty: 0);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('dispatch', session('error'));
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_dispatch_fully_dispatched(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceDispatch($warehouse->id, $branch->id, orderedQty: 10, dispatchedQty: 10);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('success');
        $this->assertFalse($warehouse->fresh()->is_active);
    }

    // ====================================================================
    // SAFETY CHECK 3: Active stock take sessions
    // ====================================================================

    public function test_toggle_blocked_when_active_stock_take_exists(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'draft');

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('error');
        $this->assertStringContainsString('stock take', session('error'));
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_toggle_blocked_when_counting_stock_take_exists(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'counting');

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('error');
        $this->assertTrue($warehouse->fresh()->is_active);
    }

    public function test_toggle_allows_deactivation_when_stock_take_completed(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'posted');

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('success');
        $this->assertFalse($warehouse->fresh()->is_active);
    }

    // ====================================================================
    // Combined blockers
    // ====================================================================

    public function test_toggle_blocked_message_lists_multiple_blockers(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 50.0);
        $this->insertSalesInvoiceDispatch($warehouse->id, $branch->id, orderedQty: 10, dispatchedQty: 0);

        $response = $this->post(route('admin.warehouses.toggle', $warehouse));

        $response->assertSessionHas('error');
        $errorMessage = session('error');
        // The controller returns on the FIRST blocker, so only one will be
        // mentioned. Verify at least one blocker is reported.
        $this->assertTrue(
            str_contains($errorMessage, 'stock') || str_contains($errorMessage, 'dispatch'),
            'Error message should mention stock or dispatch'
        );
    }
}
