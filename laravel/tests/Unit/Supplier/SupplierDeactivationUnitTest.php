<?php

namespace Tests\Unit\Supplier;

use App\Http\Controllers\Admin\SupplierController;
use App\Models\Branch;
use App\Models\Supplier;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsSupplierDependencies;
use Tests\TestCase;

/**
 * Supplier Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on SupplierController via reflection.
 *
 * Tests the 2 safety checks in isolation (Phase 11):
 *   1. No outstanding AP balance in supplier_ledger (sum credit - debit)
 *   2. No open (non-cancelled, non-received) purchase orders for this supplier
 *
 * Phase 11 commit.
 */
class SupplierDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsSupplierDependencies;

    private SupplierController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(SupplierController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Supplier $supplier): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $supplier);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_supplier_with_no_dependencies(): void
    {
        $supplier = Supplier::factory()->create();

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_supplier_with_zero_ap_balance(): void
    {
        $supplier = Supplier::factory()->create();
        // Credit 100 + Debit 100 = 0 balance
        $this->insertSupplierLedger($supplier->id, 100.00, 'credit');
        $this->insertSupplierLedger($supplier->id, 100.00, 'debit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_supplier_with_no_ledger_entries(): void
    {
        $supplier = Supplier::factory()->create();

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: Outstanding AP balance in supplier_ledger
    // ====================================================================

    public function test_cannot_deactivate_supplier_with_outstanding_credit_balance(): void
    {
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 500.00, 'credit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AP balance', $result['message']);
        $this->assertStringContainsString('500.00', $result['message']);
    }

    public function test_cannot_deactivate_supplier_with_debit_balance_outstanding(): void
    {
        // A negative AP balance (debit > credit) is also non-zero — the
        // supplier has an advance/prepayment that needs to be resolved before
        // deactivation.
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 300.00, 'debit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AP balance', $result['message']);
    }

    public function test_ap_balance_aggregates_multiple_ledger_entries(): void
    {
        $supplier = Supplier::factory()->create();
        // Credit 100 + Credit 200 - Debit 50 = 250 outstanding AP
        $this->insertSupplierLedger($supplier->id, 100.00, 'credit');
        $this->insertSupplierLedger($supplier->id, 200.00, 'credit');
        $this->insertSupplierLedger($supplier->id, 50.00, 'debit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_zero_balance_does_not_block_deactivation(): void
    {
        // 1000 credit + 1000 debit = 0 balance → no blocker
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 1000.00, 'credit');
        $this->insertSupplierLedger($supplier->id, 1000.00, 'debit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Open purchase orders
    // ====================================================================

    public function test_cannot_deactivate_supplier_with_open_draft_purchase_order(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'draft');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('purchase order', $result['message']);
    }

    public function test_cannot_deactivate_supplier_with_open_sent_purchase_order(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'sent');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
    }

    public function test_cannot_deactivate_supplier_with_open_partial_purchase_order(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'partial');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
    }

    public function test_cancelled_purchase_order_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'cancelled');

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
    }

    public function test_received_purchase_order_does_not_block_deactivation(): void
    {
        // 'received' = fully received — PO is closed, no longer blocking.
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'received');

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
    }

    public function test_multiple_open_purchase_orders_counted_in_message(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        for ($i = 0; $i < 3; $i++) {
            $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'draft');
        }

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 open purchase order', $result['message']);
    }

    // ====================================================================
    // Combined blockers — both reported in single message
    // ====================================================================

    public function test_both_balance_and_purchase_order_blockers_appear_in_message(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertSupplierLedger($supplier->id, 250.00, 'credit');
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'draft');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AP balance', $result['message']);
        $this->assertStringContainsString('purchase order', $result['message']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_purchase_order_blocker_returned_when_balance_is_zero(): void
    {
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->forBranch($branch->id)->create();
        $this->insertPurchaseOrderForSupplier($supplier->id, $branch->id, 'draft');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('purchase order', $result['message']);
    }

    public function test_balance_blocker_returned_when_no_open_purchase_orders(): void
    {
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 750.00, 'credit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AP balance', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $supplier = Supplier::factory()->create();

        $result = $this->callCanDeactivate($supplier);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $supplier = Supplier::factory()->create();
        $this->insertSupplierLedger($supplier->id, 10.00, 'credit');

        $result = $this->callCanDeactivate($supplier);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $supplier = Supplier::factory()->create();

        $result = $this->callCanDeactivate($supplier);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
