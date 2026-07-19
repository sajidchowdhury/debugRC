<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Bank Phase 13 test helpers — direct table inserts for bank-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy (or that would force pulling in the entire Payment /
 * Accounting modules).
 *
 * Used by:
 *  - tests/Unit/Bank/BankDeactivationUnitTest
 *  - tests/Feature/Bank/BankCrudTest
 *  - tests/Feature/Bank/BankAuditTest
 *  - tests/Feature/Bank/BankValidationTest
 *
 * NOTE: Tests\Helpers\BuildsRoleUsers creates Employee + User chains via
 * factories for RBAC tests. The Bank CRUD/Audit/Validation test classes
 * intentionally use ONLY this trait (not BuildsRoleUsers) for direct table
 * inserts of ledgers + bank_ledger_mappings — to avoid trait-method
 * collision and to keep the deactivation-safety tests focused on raw data
 * rather than factory chains.
 */
trait InsertsBankDependencies
{
    /**
     * Insert a ledgers row of nature `cash_bank` and return its id.
     *
     * Schema requires: ledger_code (UK), ledger_name (NOT NULL),
     * account_type (NOT NULL CHECK in Asset/Liability/Equity/Income/Expense),
     * ledger_nature VARCHAR(50), is_active (default true).
     *
     * @param  array  $overrides  Column overrides merged on top of defaults.
     * @return int  The ledgers.id
     */
    protected function insertLedger(array $overrides = []): int
    {
        $code = $overrides['ledger_code'] ?? 'L-CB-' . substr(uniqid(), -6);

        return DB::table('ledgers')->insertGetId(array_merge([
            'ledger_code'      => $code,
            'ledger_name'      => 'Cash/Bank Ledger ' . $code,
            'account_type'     => 'Asset',
            'ledger_nature'    => 'cash_bank',
            'is_control_account' => false,
            'is_active'        => true,
            'opening_balance'  => 0,
            'sort_order'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $overrides));
    }

    /**
     * Insert a bank_ledger_mappings row linking a bank to a GL ledger.
     *
     * Schema: bank_id (NOT NULL, UNIQUE — one mapping per bank),
     *         ledger_id (NOT NULL).
     *
     * Used by the canDeactivate() safety check tests (Blocker 2: active GL
     * ledger mapping).
     *
     * @return int  The bank_ledger_mappings.id
     */
    protected function insertBankLedgerMapping(int $bankId, int $ledgerId): int
    {
        return DB::table('bank_ledger_mappings')->insertGetId([
            'bank_id'    => $bankId,
            'ledger_id'  => $ledgerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert a customer_payments row referencing a bank.
     *
     * Schema requires payment_code (UK), payment_date, customer_id (NOT NULL),
     * branch_id (NOT NULL), bank_id, payment_mode (NOT NULL), amount (NOT NULL).
     *
     * Useful for testing future deactivation safety against historical
     * payment references (the current canDeactivate only checks balance +
     * ledger mapping, but this helper exists for completeness so future
     * tests can exercise it).
     *
     * @return int  The customer_payments.id
     */
    protected function insertCustomerPaymentForBank(
        int $bankId,
        int $customerId,
        int $branchId,
        float $amount = 100.00,
        bool $isReversed = false,
    ): int {
        return DB::table('customer_payments')->insertGetId([
            'payment_code' => 'CP-BANK-' . substr(uniqid(), -6),
            'payment_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'bank_id'      => $bankId,
            'payment_mode' => 'bank',
            'amount'       => $amount,
            'is_reversed'  => $isReversed,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a supplier_payments row referencing a bank.
     *
     * Schema requires payment_code (UK), payment_date, supplier_id (NOT NULL),
     * branch_id (NOT NULL), bank_id, payment_mode (NOT NULL), amount (NOT NULL).
     *
     * Useful for testing future deactivation safety against historical
     * payment references.
     *
     * @return int  The supplier_payments.id
     */
    protected function insertSupplierPaymentForBank(
        int $bankId,
        int $supplierId,
        int $branchId,
        float $amount = 100.00,
        bool $isReversed = false,
    ): int {
        return DB::table('supplier_payments')->insertGetId([
            'payment_code' => 'SP-BANK-' . substr(uniqid(), -6),
            'payment_date' => now()->toDateString(),
            'supplier_id'  => $supplierId,
            'branch_id'    => $branchId,
            'bank_id'      => $bankId,
            'payment_mode' => 'bank',
            'amount'       => $amount,
            'is_reversed'  => $isReversed,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a banks row directly via DB::table (bypasses Eloquent timestamps).
     * Returns the bank id. Used when a test needs a bank with specific column
     * values that the factory can't easily provide (e.g. raw balance without
     * decimal casting).
     *
     * @param  array  $overrides  Column overrides merged on top of defaults.
     * @return int  The banks.id
     */
    protected function insertBank(array $overrides = []): int
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return DB::table('banks')->insertGetId(array_merge([
            'bank_name'      => 'Dep Bank ' . $suffix,
            'account_number' => 'DEP-' . $suffix,
            'balance'        => 0,
            'is_active'      => true,
            'created_at'     => now(),
        ], $overrides));
    }
}
