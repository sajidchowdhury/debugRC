<?php

namespace Tests\Unit\Customer;

use App\Http\Controllers\Admin\CustomerController;
use App\Models\Branch;
use App\Models\Customer;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsCustomerDependencies;
use Tests\TestCase;

/**
 * Customer Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on CustomerController via reflection.
 *
 * Tests the 2 safety checks in isolation (Phase 10):
 *   1. No outstanding AR balance in customer_ledger (sum debit - credit)
 *   2. No open (non-cancelled, non-reversed) sales invoices for this customer
 *
 * Phase 10 commit.
 */
class CustomerDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsCustomerDependencies;

    private CustomerController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(CustomerController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Customer $customer): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $customer);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_customer_with_no_dependencies(): void
    {
        $customer = Customer::factory()->create();

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_customer_with_zero_ar_balance(): void
    {
        $customer = Customer::factory()->create();
        // Debit 100 + Credit 100 = 0 balance
        $this->insertCustomerLedger($customer->id, 100.00, 'debit');
        $this->insertCustomerLedger($customer->id, 100.00, 'credit');

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_customer_with_no_ledger_entries(): void
    {
        $customer = Customer::factory()->create();

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: Outstanding AR balance in customer_ledger
    // ====================================================================

    public function test_cannot_deactivate_customer_with_outstanding_debit_balance(): void
    {
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 500.00, 'debit');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AR balance', $result['message']);
        $this->assertStringContainsString('500.00', $result['message']);
    }

    public function test_cannot_deactivate_customer_with_credit_balance_outstanding(): void
    {
        // A negative AR balance (credit > debit) is also non-zero — the customer
        // has a credit/prepayment that needs to be resolved before deactivation.
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 300.00, 'credit');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AR balance', $result['message']);
    }

    public function test_ar_balance_aggregates_multiple_ledger_entries(): void
    {
        $customer = Customer::factory()->create();
        // Debit 100 + Debit 200 + Credit 50 = 250 outstanding
        $this->insertCustomerLedger($customer->id, 100.00, 'debit');
        $this->insertCustomerLedger($customer->id, 200.00, 'debit');
        $this->insertCustomerLedger($customer->id, 50.00, 'credit');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_zero_balance_does_not_block_deactivation(): void
    {
        // 1000 debit + 1000 credit = 0 balance → no blocker
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 1000.00, 'debit');
        $this->insertCustomerLedger($customer->id, 1000.00, 'credit');

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Open sales invoices
    // ====================================================================

    public function test_cannot_deactivate_customer_with_open_confirmed_invoice(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    public function test_cannot_deactivate_customer_with_open_draft_invoice(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'draft');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
    }

    public function test_cancelled_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'cancelled');

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    public function test_reversed_status_invoice_does_not_block_deactivation(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'reversed');

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    public function test_reversed_invoice_with_is_reversed_true_does_not_block(): void
    {
        // Even if status is 'confirmed' (not 'reversed'), is_reversed=true means
        // the invoice is reversed — should not block.
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed', isReversed: true);

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
    }

    public function test_multiple_open_invoices_counted_in_message(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        for ($i = 0; $i < 3; $i++) {
            $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed');
        }

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 open sales invoice', $result['message']);
    }

    // ====================================================================
    // Combined blockers — both reported in single message
    // ====================================================================

    public function test_both_balance_and_invoice_blockers_appear_in_message(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertCustomerLedger($customer->id, 250.00, 'debit');
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AR balance', $result['message']);
        $this->assertStringContainsString('sales invoice', $result['message']);
        $this->assertStringContainsString('250.00', $result['message']);
    }

    public function test_invoice_blocker_returned_when_balance_is_zero(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        $this->insertSalesInvoiceForCustomer($customer->id, $branch->id, 'confirmed');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sales invoice', $result['message']);
    }

    public function test_balance_blocker_returned_when_no_open_invoices(): void
    {
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 750.00, 'debit');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AR balance', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $customer = Customer::factory()->create();

        $result = $this->callCanDeactivate($customer);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $customer = Customer::factory()->create();
        $this->insertCustomerLedger($customer->id, 10.00, 'debit');

        $result = $this->callCanDeactivate($customer);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $customer = Customer::factory()->create();

        $result = $this->callCanDeactivate($customer);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
