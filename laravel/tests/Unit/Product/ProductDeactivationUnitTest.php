<?php

namespace Tests\Unit\Product;

use App\Http\Controllers\Admin\ProductController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Warehouse;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Product Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on ProductController via reflection.
 *
 * Tests the 3 safety checks in isolation:
 *   1. No stock-on-hand (qty > 0) in warehouse_stock for this product
 *   2. No sales_invoice_items for this product on non-reversed, non-cancelled invoices
 *   3. No purchase_order_items for this product on pending purchase orders
 *
 * Phase 9 commit.
 */
class ProductDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsProductDependencies, InsertsWarehouseDependencies;

    private ProductController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(ProductController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Product $product): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $product);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_product_with_no_dependencies(): void
    {
        $product = Product::factory()->create();

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_product_with_zero_stock_everywhere(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 0.0);

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: Stock-on-hand in warehouse_stock
    // ====================================================================

    public function test_cannot_deactivate_product_with_stock_on_hand(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 50.0);

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stock', $result['message']);
        $this->assertStringContainsString('50.00', $result['message']);
    }

    public function test_stock_in_multiple_warehouses_aggregates(): void
    {
        $branch = Branch::factory()->create();
        $warehouse1 = Warehouse::factory()->forBranch($branch->id)->create();
        $warehouse2 = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse1->id, $product->id, 30.0);
        $this->insertProductStock($warehouse2->id, $product->id, 20.0);

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('50.00', $result['message']);
    }

    public function test_negative_stock_does_not_block_deactivation(): void
    {
        // Negative stock (over-issued) is allowed by the ws_qty_nonnegative
        // CHECK constraint which permits -0.0001 tolerance.
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, -0.0001);

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Open sales invoice items
    // ====================================================================

    public function test_cannot_deactivate_product_with_open_sales_invoice_item(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'confirmed');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    public function test_cannot_deactivate_product_with_draft_invoice_item(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'draft');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
    }

    public function test_cancelled_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'cancelled');

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    public function test_reversed_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'reversed');

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    public function test_multiple_open_invoice_items_counted_in_message(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'confirmed');
        }

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 open sales invoice item', $result['message']);
    }

    // ====================================================================
    // Blocker 3: Pending purchase order items
    // ====================================================================

    public function test_cannot_deactivate_product_with_pending_purchase_order_item(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'draft');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('purchase order', $result['message']);
    }

    public function test_cannot_deactivate_product_with_sent_purchase_order_item(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'sent');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
    }

    public function test_cannot_deactivate_product_with_partial_purchase_order_item(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'partial');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
    }

    public function test_received_purchase_order_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'received');

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    public function test_cancelled_purchase_order_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'cancelled');

        $result = $this->callCanDeactivate($product);

        $this->assertTrue($result['ok']);
    }

    public function test_multiple_pending_po_items_counted_in_message(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            $this->insertPurchaseOrderItem($product->id, $branch->id, poStatus: 'draft');
        }

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2 pending purchase order item', $result['message']);
    }

    // ====================================================================
    // Combined blockers — first blocker encountered is returned
    // (canDeactivate returns at the first failure)
    // ====================================================================

    public function test_stock_blocker_returned_before_invoice_blocker(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();

        // Both blockers present — stock is checked first.
        $this->insertProductStock($warehouse->id, $product->id, 10.0);
        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'confirmed');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stock', $result['message']);
    }

    public function test_invoice_blocker_returned_when_no_stock_but_open_invoices(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create();

        $this->insertSalesInvoiceItem($product->id, $branch->id, invoiceStatus: 'confirmed');

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $product = Product::factory()->create();

        $result = $this->callCanDeactivate($product);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->forBranch($branch->id)->create();
        $product = Product::factory()->create();
        $this->insertProductStock($warehouse->id, $product->id, 10.0);

        $result = $this->callCanDeactivate($product);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }
}
