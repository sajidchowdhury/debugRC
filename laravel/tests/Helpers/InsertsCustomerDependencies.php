<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Customer Phase 10 test helpers — direct table inserts for customer-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy (or that would force pulling in the entire Sales module).
 *
 * Used by:
 *  - tests/Unit/Customer/CustomerDeactivationUnitTest
 *  - tests/Feature/Customer/CustomerCrudTest
 *  - tests/Feature/Customer/CustomerAuditTest
 *  - tests/Feature/Customer/CustomerValidationTest
 *
 * NOTE: Tests\Helpers\InsertsBranchDependencies already has an insertCustomer()
 * method with the signature `insertCustomer(int $branchId, string $code = null)`.
 * Customer test classes intentionally use ONLY this trait (not
 * InsertsBranchDependencies) to avoid trait-method collision. Branches are
 * obtained via `Branch::factory()->create()` instead.
 */
trait InsertsCustomerDependencies
{
    /**
     * Insert a customer row with the minimum required columns.
     * Returns the customer id.
     *
     * @param  int|null  $branchId  FK to branches.id (nullable in schema but tests usually pass one)
     * @param  array     $overrides Column overrides merged on top of the defaults.
     */
    protected function insertCustomer(?int $branchId = null, array $overrides = []): int
    {
        $code = $overrides['customer_code'] ?? 'CUST-DEP-' . substr(uniqid(), -6);

        return DB::table('customers')->insertGetId(array_merge([
            'customer_code' => $code,
            'customer_name' => 'Dep Customer ' . $code,
            'branch_id'     => $branchId,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));
    }

    /**
     * Insert a customer_ledger row simulating an AR transaction.
     *
     * Schema: customer_id (FK NOT NULL), transaction_date (NOT NULL),
     *         transaction_type (NOT NULL), debit/credit (default 0).
     *
     * Pass $type='debit'  to record an AR increase (e.g. invoice issued).
     * Pass $type='credit' to record an AR decrease (e.g. payment received).
     *
     * @param  string  $type  'debit' or 'credit'
     * @return int  The customer_ledger.id
     */
    protected function insertCustomerLedger(int $customerId, float $amount, string $type = 'debit'): int
    {
        $row = [
            'customer_id'      => $customerId,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => $type === 'credit' ? 'payment' : 'invoice',
            'reference_type'   => $type === 'credit' ? 'payment' : 'invoice',
            'reference_id'     => 0,
            'description'      => 'Phase 10 test ledger entry',
            'created_at'       => now(),
        ];

        if ($type === 'credit') {
            $row['credit'] = $amount;
        } else {
            $row['debit'] = $amount;
        }

        return DB::table('customer_ledger')->insertGetId($row);
    }

    /**
     * Insert a sales_invoice row referencing a customer.
     *
     * Builds the minimum chain: customer → invoice.
     * Schema requires customer_id (FK), branch_id (FK), invoice_code (UK),
     * invoice_date, status CHECK in (draft/confirmed/cancelled/reversed),
     * is_reversed bool.
     *
     * @param  string  $status     One of draft/confirmed/cancelled/reversed.
     * @param  bool    $isReversed Whether the invoice is reversed.
     * @return int  The sales_invoice.id
     */
    protected function insertSalesInvoiceForCustomer(
        int $customerId,
        int $branchId,
        string $status = 'confirmed',
        bool $isReversed = false,
    ): int {
        return DB::table('sales_invoices')->insertGetId([
            'invoice_code' => 'INV-CUST-' . substr(uniqid(), -6),
            'invoice_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'status'       => $status,
            'is_reversed'  => $isReversed,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a customer_payment row referencing a customer.
     *
     * Schema requires payment_code (UK), payment_date, customer_id (FK),
     * branch_id (FK), payment_mode CHECK, amount NOT NULL.
     *
     * Useful for testing deactivation safety against outstanding payments
     * (although payments are credits to AR, not blockers — the helper exists
     * for completeness so future tests can exercise it).
     */
    protected function insertCustomerPaymentForCustomer(
        int $customerId,
        int $branchId,
        float $amount = 100.00,
        string $paymentMode = 'cash',
    ): int {
        return DB::table('customer_payments')->insertGetId([
            'payment_code' => 'PAY-CUST-' . substr(uniqid(), -6),
            'payment_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'payment_mode' => $paymentMode,
            'amount'       => $amount,
            'is_reversed'  => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
