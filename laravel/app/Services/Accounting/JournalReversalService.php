<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Journal Reversal Service — Phase 9.4.
 *
 * Centralizes the full reversal cascade: when a business transaction is
 * cancelled, this service reverses:
 *   1. The GL journal entry (swap Dr/Cr via JournalPostingService)
 *   2. All linked sub-ledger entries (customer_ledger, supplier_ledger, employee_ledger)
 *      that reference the same journal_entry_id
 *
 * This eliminates the duplicated private reversal methods scattered across
 * business services (SalesInvoiceService::reverseCustomerLedgerDebit,
 * PurchaseReceiveService::reverseSupplierLedgerCredit, etc.) — they can all
 * call this single service.
 *
 * The reversal is append-only:
 *   - The original GL entry is marked is_reversed=true (never mutated)
 *   - A new reversal GL entry is created (swapped Dr/Cr)
 *   - The original sub-ledger entries are marked is_reversed=true
 *   - New reversal sub-ledger entries are created (opposite debit/credit)
 *
 * Verification: all reversals should net to zero on Trial Balance.
 */
class JournalReversalService
{
    public function __construct(
        private JournalPostingService $postingService,
        private SubLedgerService $subLedgerService
    ) {}

    /**
     * Reverse a journal entry and cascade to all linked sub-ledger entries.
     *
     * This is the single entry point for all business-transaction cancellations.
     * Business services call this with the journal_entry_id they want to reverse.
     *
     * @param int $journalEntryId The GL journal entry to reverse.
     * @param int $reversedBy User ID performing the reversal.
     * @param string $reason Reason for reversal.
     * @return array{
     *     reversal_journal_entry_id: int,
     *     reversed_sub_ledger_entries: array{ customer: int[], supplier: int[], employee: int[] },
     *     original_entry_no: string,
     *     reversal_entry_no: string,
     * }
     * @throws \RuntimeException If entry not found, already reversed, or cascade fails.
     */
    public function reverseByJournalEntry(int $journalEntryId, int $reversedBy, string $reason = ''): array
    {
        return DB::transaction(function () use ($journalEntryId, $reversedBy, $reason) {
            // 1. Reverse the GL journal entry (swap Dr/Cr, mark original).
            $reversalJournalId = $this->postingService->reverseJournalEntry(
                $journalEntryId, $reversedBy, $reason
            );

            // 2. Find and reverse all linked sub-ledger entries.
            $reversedSubLedger = [
                'customer' => [],
                'supplier' => [],
                'employee' => [],
            ];

            // 2a. Customer ledger entries linked to this journal entry.
            $customerEntries = DB::table('customer_ledger')
                ->where('journal_entry_id', $journalEntryId)
                ->where('is_reversed', false)
                ->get();

            foreach ($customerEntries as $entry) {
                $reversalId = $this->subLedgerService->reverseCustomerLedgerEntry(
                    $entry->id, $reversedBy, $reason
                );
                $reversedSubLedger['customer'][] = $reversalId;
            }

            // 2b. Supplier ledger entries linked to this journal entry.
            $supplierEntries = DB::table('supplier_ledger')
                ->where('journal_entry_id', $journalEntryId)
                ->where('is_reversed', false)
                ->get();

            foreach ($supplierEntries as $entry) {
                $reversalId = $this->subLedgerService->reverseSupplierLedgerEntry(
                    $entry->id, $reversedBy, $reason
                );
                $reversedSubLedger['supplier'][] = $reversalId;
            }

            // 2c. Employee ledger entries linked to this journal entry.
            $employeeEntries = DB::table('employee_ledger')
                ->where('journal_entry_id', $journalEntryId)
                ->where('is_reversed', false)
                ->get();

            foreach ($employeeEntries as $entry) {
                $reversalId = $this->subLedgerService->reverseEmployeeLedgerEntry(
                    $entry->id, $reversedBy, $reason
                );
                $reversedSubLedger['employee'][] = $reversalId;
            }

            // 3. Get entry numbers for the result.
            $originalEntry = DB::table('journal_entries')->where('id', $journalEntryId)->first();
            $reversalEntry = DB::table('journal_entries')->where('id', $reversalJournalId)->first();

            // 4. Log the cascade.
            Log::info('Journal reversal cascade completed', [
                'original_entry_id' => $journalEntryId,
                'original_entry_no' => $originalEntry?->entry_no,
                'reversal_entry_id' => $reversalJournalId,
                'reversal_entry_no' => $reversalEntry?->entry_no,
                'reversed_by' => $reversedBy,
                'reason' => $reason,
                'customer_ledger_reversals' => count($reversedSubLedger['customer']),
                'supplier_ledger_reversals' => count($reversedSubLedger['supplier']),
                'employee_ledger_reversals' => count($reversedSubLedger['employee']),
            ]);

            return [
                'reversal_journal_entry_id' => $reversalJournalId,
                'reversed_sub_ledger_entries' => $reversedSubLedger,
                'original_entry_no' => $originalEntry?->entry_no ?? "JE-#{$journalEntryId}",
                'reversal_entry_no' => $reversalEntry?->entry_no ?? "JE-#{$reversalJournalId}",
            ];
        });
    }

    /**
     * Reverse all journal entries for a given reference (e.g. cancel a sales invoice
     * that has both a revenue journal + COGS journal).
     *
     * @param string $referenceType e.g. 'sales_invoice', 'purchase_receive'
     * @param int $referenceId
     * @param int $reversedBy
     * @param string $reason
     * @return array List of reversal results (one per journal entry reversed).
     */
    public function reverseByReference(string $referenceType, int $referenceId, int $reversedBy, string $reason = ''): array
    {
        $entries = DB::table('journal_entries')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('is_reversed', false)
            ->get();

        $results = [];
        foreach ($entries as $entry) {
            $results[] = $this->reverseByJournalEntry($entry->id, $reversedBy, $reason);
        }

        return $results;
    }

    /**
     * Get a summary of all reversals in the system.
     * For verification + audit reporting.
     *
     * @return array{
     *     total_reversed: int,
     *     by_reference_type: array,
     *     total_reversal_amount: float,
     *     unbalanced_reversals: array,
     * }
     */
    public function getReversalSummary(): array
    {
        // Total reversed entries.
        $totalReversed = (int) DB::table('journal_entries')
            ->where('is_reversed', true)
            ->count();

        // By reference type.
        $byType = DB::table('journal_entries')
            ->where('is_reversed', true)
            ->select('reference_type', DB::raw('COUNT(*) as count'))
            ->groupBy('reference_type')
            ->pluck('count', 'reference_type')
            ->toArray();

        // Total reversal amount (sum of reversal entry amounts).
        $totalReversalAmount = (float) DB::selectOne(<<<SQL
SELECT COALESCE(SUM(jl.debit), 0) AS amount
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.source = 'reversal' AND je.is_reversed = false
SQL)->amount;

        // Check for unbalanced reversals (original + reversal should net to zero).
        $unbalanced = DB::select(<<<SQL
SELECT
    orig.id AS original_id,
    orig.entry_no AS original_no,
    rev.id AS reversal_id,
    rev.entry_no AS reversal_no,
    COALESCE(SUM(CASE WHEN jl.journal_entry_id = orig.id THEN jl.debit ELSE 0 END), 0) AS orig_debit,
    COALESCE(SUM(CASE WHEN jl.journal_entry_id = orig.id THEN jl.credit ELSE 0 END), 0) AS orig_credit,
    COALESCE(SUM(CASE WHEN jl.journal_entry_id = rev.id THEN jl.debit ELSE 0 END), 0) AS rev_debit,
    COALESCE(SUM(CASE WHEN jl.journal_entry_id = rev.id THEN jl.credit ELSE 0 END), 0) AS rev_credit
FROM journal_entries orig
JOIN journal_entries rev ON rev.reversal_of_entry_id = orig.id
JOIN journal_lines jl ON jl.journal_entry_id IN (orig.id, rev.id)
GROUP BY orig.id, orig.entry_no, rev.id, rev.entry_no
HAVING
    ABS(COALESCE(SUM(CASE WHEN jl.journal_entry_id = orig.id THEN jl.debit ELSE 0 END), 0)
      - COALESCE(SUM(CASE WHEN jl.journal_entry_id = rev.id THEN jl.credit ELSE 0 END), 0)) > 0.01
    OR ABS(COALESCE(SUM(CASE WHEN jl.journal_entry_id = orig.id THEN jl.credit ELSE 0 END), 0)
      - COALESCE(SUM(CASE WHEN jl.journal_entry_id = rev.id THEN jl.debit ELSE 0 END), 0)) > 0.01
ORDER BY orig.id
SQL);

        return [
            'total_reversed' => $totalReversed,
            'by_reference_type' => $byType,
            'total_reversal_amount' => $totalReversalAmount,
            'unbalanced_reversals' => array_map(fn($r) => [
                'original_id' => $r->original_id,
                'original_no' => $r->original_no,
                'reversal_id' => $r->reversal_id,
                'reversal_no' => $r->reversal_no,
                'orig_debit' => $r->orig_debit,
                'orig_credit' => $r->orig_credit,
                'rev_debit' => $r->rev_debit,
                'rev_credit' => $r->rev_credit,
            ], $unbalanced),
        ];
    }

    /**
     * Verify that a specific reversal nets to zero with its original.
     *
     * @param int $originalEntryId
     * @return array{ nets_to_zero: bool, original_dr: float, original_cr: float, reversal_dr: float, reversal_cr: float }
     */
    public function verifyReversalNetsToZero(int $originalEntryId): array
    {
        $original = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(debit), 0) AS dr, COALESCE(SUM(credit), 0) AS cr
FROM journal_lines WHERE journal_entry_id = ?
SQL, [$originalEntryId]);

        $reversalEntry = DB::table('journal_entries')
            ->where('reversal_of_entry_id', $originalEntryId)
            ->orderBy('id', 'desc')
            ->first();

        $reversalDr = 0;
        $reversalCr = 0;

        if ($reversalEntry) {
            $reversal = DB::selectOne(<<<SQL
SELECT COALESCE(SUM(debit), 0) AS dr, COALESCE(SUM(credit), 0) AS cr
FROM journal_lines WHERE journal_entry_id = ?
SQL, [$reversalEntry->id]);
            $reversalDr = (float) $reversal->dr;
            $reversalCr = (float) $reversal->cr;
        }

        $origDr = (float) $original->dr;
        $origCr = (float) $original->cr;

        // Net to zero: original Dr should equal reversal Cr, and original Cr should equal reversal Dr.
        $netsToZero = abs($origDr - $reversalCr) < 0.01 && abs($origCr - $reversalDr) < 0.01;

        return [
            'nets_to_zero' => $netsToZero,
            'original_dr' => $origDr,
            'original_cr' => $origCr,
            'reversal_dr' => $reversalDr,
            'reversal_cr' => $reversalCr,
        ];
    }
}
