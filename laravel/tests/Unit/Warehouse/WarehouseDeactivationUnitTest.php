<?php

namespace Tests\Unit\Warehouse;

use App\Http\Controllers\Admin\WarehouseController;
use App\Models\Branch;
use App\Models\Warehouse;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Warehouse Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on WarehouseController via reflection.
 *
 * Tests the 3 safety checks in isolation:
 *   1. No stock (qty > 0) in warehouse_stock
 *   2. No pending dispatches (ordered_qty > dispatched_qty on open invoices)
 *   3. No active stock take sessions (draft/counting)
 */
class WarehouseDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    private WarehouseController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(WarehouseController::class);
    }

    private function callCanDeactivate(Warehouse $warehouse): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $warehouse);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_warehouse_with_no_dependencies(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $result = $this->callCanDeactivate($warehouse);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    // ====================================================================
    // Blocker 1: Stock
    // ====================================================================

    public function test_cannot_deactivate_warehouse_with_stock(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 50.0);

        $result = $this->callCanDeactivate($warehouse);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stock', $result['message']);
        $this->assertStringContainsString('50.00', $result['message']);
    }

    public function test_zero_stock_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, 0.0);

        $result = $this->callCanDeactivate($warehouse);

        $this->assertTrue($result['ok']);
    }

    public function test_negative_stock_does_not_block_deactivation(): void
    {
        // Negative stock (over-issued) is allowed by the ws_qty_nonnegative
        // CHECK constraint which permits -0.0001 tolerance.
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $productId = $this->insertProduct();
        $this->insertWarehouseStock($warehouse->id, $productId, -0.0001);

        $result = $this->callCanDeactivate($warehouse);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Pending dispatches
    // ====================================================================

    public function test_cannot_deactivate_warehouse_with_pending_dispatch(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceDispatch($warehouse->id, $branch->id, orderedQty: 10, dispatchedQty: 0);

        $result = $this->callCanDeactivate($warehouse);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('dispatch', $result['message']);
    }

    public function test_fully_dispatched_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceDispatch($warehouse->id, $branch->id, orderedQty: 10, dispatchedQty: 10);

        $result = $this->callCanDeactivate($warehouse);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 3: Active stock take sessions
    // ====================================================================

    public function test_cannot_deactivate_warehouse_with_draft_stock_take(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'draft');

        $result = $this->callCanDeactivate($warehouse);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stock take', $result['message']);
    }

    public function test_cannot_deactivate_warehouse_with_counting_stock_take(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'counting');

        $result = $this->callCanDeactivate($warehouse);

        $this->assertFalse($result['ok']);
    }

    public function test_completed_stock_take_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $this->insertActiveStockTake($warehouse->id, $branch->id, status: 'posted');

        $result = $this->callCanDeactivate($warehouse);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();

        $result = $this->callCanDeactivate($warehouse);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }
}
