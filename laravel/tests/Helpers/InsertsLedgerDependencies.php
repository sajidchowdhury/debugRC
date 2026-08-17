<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Ledger Phase 15 test helpers — direct table inserts for ledger-specific
 * dependencies (journal entries, journal lines, child ledgers) that have
 * NOT NULL columns + FK constraints the factory can't easily satisfy.
 *
 * Used by:
 *  - tests/Unit/Ledger/LedgerDeactivationUnitTest
 *  - tests/Feature/Ledger/LedgerCrudTest
 *  - tests/Feature/Ledger/LedgerAuditTest
 *  - tests/Feature/Ledger/LedgerValidationTest
 *
 * NOTE: Tests\Helpers\BuildsRoleUsers creates Employee + User chains via
 * factories for RBAC tests. The Ledger CRUD/Audit/Validation/Deactivation
 * test classes use BOTH traits — BuildsRoleUsers for authenticated role
 * users + this trait for direct inserts of journal_entries / journal_lines /
 * child ledgers.
 *
 * Mirrors the InsertsBankDependencies pattern: tests insert raw data via
 * DB::table to avoid pulling in the entire Accounting/JournalPostingService
 * stack (which would force creation of customers/suppliers/branches/stock
 * transactions for every test case).
 */
trait InsertsLedgerDependencies
{
    use ResolvesActiveFiscalYear;
    /**
     * Insert a ledgers row directly via DB::table (bypasses Eloquent
     * timestamps + factory). Returns the ledger id.
     *
     * Schema requires: ledger_code (UK), ledger_name (NOT NULL),
     * account_type (NOT NULL CHECK in Asset/Liability/Equity/Income/Expense),
     * ledger_nature VARCHAR(50), is_active (default true),
     * is_system (default false), normal_balance (default 'debit').
     *
     * @param  array  $overrides  Column overrides merged on top of defaults.
     * @return int  The ledgers.id
     */
    protected function insertLedger(array $overrides = []): int
    {
        $code = $overrides['ledger_code'] ?? 'L-T-' . substr(uniqid(), -6);

        return DB::table('ledgers')->insertGetId(array_merge([
            'ledger_code'          => $code,
            'ledger_name'          => 'Test Ledger ' . $code,
            'parent_id'            => null,
            'account_type'         => 'Asset',
            'ledger_nature'        => null,
            'is_control_account'   => false,
            'control_account_type' => null,
            'is_active'            => true,
            'is_system'            => false,
            'normal_balance'       => 'debit',
            'opening_balance'      => 0,
            'sort_order'           => 0,
            'description'          => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));
    }

    /**
     * Insert a journal_entries row and return its id.
     *
     * Schema requires: entry_no (UK), entry_date (NOT NULL),
     * branch_id (nullable), description (nullable), source (default 'manual'),
     * is_reversed (default false).
     *
     * @param  int|null  $branchId  Branch id (nullable in schema).
     * @param  array     $overrides Column overrides merged on top of defaults.
     * @return int  The journal_entries.id
     */
    protected function insertJournalEntry(?int $branchId = null, array $overrides = []): int
    {
        $entryNo = $overrides['entry_no'] ?? 'JE-T-' . substr(uniqid(), -8);

        return DB::table('journal_entries')->insertGetId(array_merge([
            'entry_no'        => $entryNo,
            'entry_date'      => now()->toDateString(),
            'reference_type'  => 'manual',
            'reference_id'    => null,
            'branch_id'       => $branchId,
            'description'     => 'Test journal entry ' . $entryNo,
            'source'          => 'manual',
            'is_reversed'     => false,
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    /**
     * Insert a journal_lines row referencing a journal_entry + ledger.
     *
     * Schema requires: journal_entry_id (NOT NULL FK),
     * ledger_id (NOT NULL FK), debit (NOT NULL default 0),
     * credit (NOT NULL default 0), CHECK debit >= 0 AND credit >= 0,
     * CHECK debit > 0 OR credit > 0 (i.e. one must be > 0).
     *
     * The trg_journal_balanced trigger fires AFTER INSERT/UPDATE/DELETE on
     * each row and enforces that the parent journal_entry's debit total ==
     * credit total. This makes inserting a single line impossible without
     * violating the constraint (the running total won't yet be balanced).
     * We work around this by temporarily disabling the trigger within the
     * test transaction (ALTER TABLE ... DISABLE TRIGGER is transactional in
     * PostgreSQL, so the re-enable + rollback leaves the trigger intact for
     * production code).
     *
     * Tests that need to insert a balanced pair should use
     * `insertBalancedJournalPair()` instead, which inserts both lines
     * while the trigger is disabled.
     *
     * @return int  The journal_lines.id
     */
    protected function insertJournalLine(
        int $journalEntryId,
        int $ledgerId,
        float $debit = 0.00,
        float $credit = 0.00,
        array $overrides = [],
    ): int {
        // Coerce 0.0 to a tiny positive value to satisfy the
        // jl_not_both_zero_check constraint when the caller intends
        // "no amount" — but they should ideally pass a real amount.
        if ($debit <= 0 && $credit <= 0) {
            $debit = 0.01;
        }

        $this->disableJournalBalancedTrigger();
        try {
            $id = DB::table('journal_lines')->insertGetId(array_merge([
                'journal_entry_id' => $journalEntryId,
                'ledger_id'        => $ledgerId,
                'debit'            => $debit,
                'credit'           => $credit,
                'entity_type'      => null,
                'entity_id'        => null,
                'memo'             => null,
                'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
                'created_at'       => now(),
            ], $overrides));
        } finally {
            $this->enableJournalBalancedTrigger();
        }

        return $id;
    }

    /**
     * Insert a balanced pair of journal lines (debit on one ledger,
     * credit on another) so the parent journal entry is balanced.
     *
     * Both lines are inserted while the trg_journal_balanced trigger is
     * disabled (otherwise the trigger would reject the first row insert
     * before the second one can balance it).
     *
     * @return array{journal_entry_id: int, debit_line_id: int, credit_line_id: int}
     */
    protected function insertBalancedJournalPair(
        int $debitLedgerId,
        int $creditLedgerId,
        float $amount = 100.00,
        ?int $branchId = null,
    ): array {
        $jeId = $this->insertJournalEntry($branchId);

        $this->disableJournalBalancedTrigger();
        try {
            $debitLineId = DB::table('journal_lines')->insertGetId([
                'journal_entry_id' => $jeId,
                'ledger_id'        => $debitLedgerId,
                'debit'            => $amount,
                'credit'           => 0,
                'entity_type'      => null,
                'entity_id'        => null,
                'memo'             => null,
                'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
                'created_at'       => now(),
            ]);
            $creditLineId = DB::table('journal_lines')->insertGetId([
                'journal_entry_id' => $jeId,
                'ledger_id'        => $creditLedgerId,
                'debit'            => 0,
                'credit'           => $amount,
                'entity_type'      => null,
                'entity_id'        => null,
                'memo'             => null,
                'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
                'created_at'       => now(),
            ]);
        } finally {
            $this->enableJournalBalancedTrigger();
        }

        return [
            'journal_entry_id' => $jeId,
            'debit_line_id'    => $debitLineId,
            'credit_line_id'   => $creditLineId,
        ];
    }

    /**
     * Temporarily disable the trg_journal_balanced trigger on journal_lines.
     *
     * ALTER TABLE ... DISABLE TRIGGER is transactional in PostgreSQL, so
     * when the test transaction rolls back the trigger is automatically
     * re-enabled. We still re-enable explicitly inside finally blocks for
     * safety.
     */
    private function disableJournalBalancedTrigger(): void
    {
        DB::statement("ALTER TABLE journal_lines DISABLE TRIGGER trg_journal_balanced");
    }

    /**
     * Re-enable the trg_journal_balanced trigger.
     */
    private function enableJournalBalancedTrigger(): void
    {
        DB::statement("ALTER TABLE journal_lines ENABLE TRIGGER trg_journal_balanced");
    }

    /**
     * Insert a child ledgers row pointing at a parent ledger.
     * Returns the child ledger id.
     *
     * Useful for testing the canDeactivate() child-ledger blocker.
     *
     * @param  int     $parentLedgerId
     * @param  array   $overrides
     * @return int  The child ledgers.id
     */
    protected function insertChildLedger(int $parentLedgerId, array $overrides = []): int
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return DB::table('ledgers')->insertGetId(array_merge([
            'ledger_code'          => 'L-CH-' . $suffix,
            'ledger_name'          => 'Child Ledger ' . $suffix,
            'parent_id'            => $parentLedgerId,
            'account_type'         => 'Asset',
            'ledger_nature'        => null,
            'is_control_account'   => false,
            'control_account_type' => null,
            'is_active'            => true,
            'is_system'            => false,
            'normal_balance'       => 'debit',
            'opening_balance'      => 0,
            'sort_order'           => 0,
            'description'          => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));
    }
}
