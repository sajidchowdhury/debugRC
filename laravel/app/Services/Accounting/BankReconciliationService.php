<?php

namespace App\Services\Accounting;

use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\BankReconciliationItem;
use App\Models\Accounting\JournalLine;
use App\Models\Ledger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BankReconciliationService — Phase 9.3: Bank Reconciliation
 *
 * Handles the full bank reconciliation lifecycle:
 *   1. Create reconciliation — for a bank account + period
 *   2. Import statement lines — CSV upload or manual entry
 *   3. Auto-match — match statement lines against system journal lines
 *   4. Manual match — user confirms or corrects matches
 *   5. Complete — post adjustment entries and mark journal lines as reconciled
 *   6. Reverse — reverse a completed reconciliation
 *
 * Matching Algorithm:
 *   Auto-matching uses a multi-criteria approach:
 *     - Exact amount match (primary criterion)
 *     - Date proximity (within configurable tolerance, default 5 days)
 *     - Reference match (cheque number, payment reference)
 *   Score-based: each criterion adds points; threshold determines "suggested" vs "unmatched"
 *
 * Balance Calculation:
 *   Adjusted Book Balance = System Closing Balance
 *     + Deposits in Transit (statement has, system doesn't)
 *     - Outstanding Checks (system has, statement doesn't)
 *     + Bank Charges/Interest (from adjustment entries)
 *
 *   Adjusted Bank Balance = Statement Closing Balance
 *     + Deposits in Transit
 *     - Outstanding Checks
 *
 *   Difference = Adjusted Book Balance - Adjusted Bank Balance
 *   Reconciliation is complete when Difference = 0
 */
class BankReconciliationService
{
    /**
     * Date tolerance for auto-matching (days).
     * Statement date and system date may differ by a few days.
     */
    const DATE_TOLERANCE_DAYS = 5;

    /**
     * Create a new bank reconciliation for a given bank and period.
     */
    public function createReconciliation(array $data): BankReconciliation
    {
        $bank = Bank::findOrFail($data['bank_id']);

        // Get the bank's ledger via mapping
        $ledgerMapping = $bank->ledgerMapping;
        if (!$ledgerMapping) {
            throw new \InvalidArgumentException("Bank '{$bank->bank_name}' has no ledger mapping. Please configure the bank's GL account first.");
        }

        // Calculate system opening balance (sum of all journal lines for this bank's ledger
        // before the period starts)
        $systemOpening = $this->calculateSystemBalance(
            $ledgerMapping->ledger_id,
            $data['period_from'],
            $bank->branch_id
        );

        // Calculate system closing balance
        $systemClosing = $this->calculateSystemBalance(
            $ledgerMapping->ledger_id,
            $data['period_to'],
            $bank->branch_id
        );

        // Generate reconciliation code
        $reconciliationCode = $this->generateReconciliationCode($data['period_from']);

        $reconciliation = BankReconciliation::create([
            'reconciliation_code' => $reconciliationCode,
            'bank_id' => $data['bank_id'],
            'statement_date' => $data['period_to'],
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'statement_opening_balance' => $data['statement_opening_balance'] ?? 0,
            'statement_closing_balance' => $data['statement_closing_balance'] ?? 0,
            'system_opening_balance' => $systemOpening,
            'system_closing_balance' => $systemClosing,
            'adjusted_book_balance' => 0,
            'adjusted_bank_balance' => 0,
            'difference' => 0,
            'status' => 'draft',
            'created_by' => Auth::id(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $reconciliation;
    }

    /**
     * Import bank statement lines from a CSV file.
     *
     * Expected CSV format:
     *   Date, Description, Reference, Debit, Credit, Balance
     *   or
     *   Date, Description, Reference, Amount (positive=deposit, negative=withdrawal), Balance
     *
     * @param BankReconciliation $reconciliation
     * @param array $lines Array of parsed CSV rows
     * @return int Number of lines imported
     */
    public function importStatementLines(BankReconciliation $reconciliation, array $lines): int
    {
        if (!$reconciliation->isEditable()) {
            throw new \InvalidArgumentException("Cannot import lines into a {$reconciliation->status} reconciliation.");
        }

        $imported = 0;
        $lineNumber = $reconciliation->statementLines()->max('line_number') ?? 0;

        foreach ($lines as $row) {
            $lineNumber++;

            $date = $row['date'] ?? $row['Date'] ?? null;
            $description = $row['description'] ?? $row['Description'] ?? $row['particulars'] ?? $row['Particulars'] ?? null;
            $reference = $row['reference'] ?? $row['Reference'] ?? $row['cheque_no'] ?? $row['Cheque_No'] ?? null;
            $debit = isset($row['debit']) ? (float) $row['debit'] : (isset($row['Debit']) ? (float) $row['Debit'] : 0);
            $credit = isset($row['credit']) ? (float) $row['credit'] : (isset($row['Credit']) ? (float) $row['Credit'] : 0);
            $balance = isset($row['balance']) ? (float) $row['balance'] : (isset($row['Balance']) ? (float) $row['Balance'] : 0);

            // Handle "Amount" column (single column with sign)
            if (isset($row['amount']) || isset($row['Amount'])) {
                $amount = (float) ($row['amount'] ?? $row['Amount']);
                if ($amount >= 0) {
                    $credit = abs($amount);
                    $debit = 0;
                } else {
                    $debit = abs($amount);
                    $credit = 0;
                }
            }

            if (!$date) {
                continue; // Skip rows without a date
            }

            BankStatementLine::create([
                'bank_reconciliation_id' => $reconciliation->id,
                'transaction_date' => $date,
                'description' => $description,
                'reference' => $reference,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
                'match_status' => 'unmatched',
                'line_number' => $lineNumber,
                'raw_data' => json_encode($row),
            ]);

            $imported++;
        }

        // Update reconciliation counts
        $this->updateCounts($reconciliation);

        // If we have lines, auto-match
        if ($imported > 0) {
            $reconciliation->update(['status' => 'in_progress']);
            $this->autoMatch($reconciliation);
        }

        return $imported;
    }

    /**
     * Add a single manual statement line.
     */
    public function addManualStatementLine(BankReconciliation $reconciliation, array $data): BankStatementLine
    {
        if (!$reconciliation->isEditable()) {
            throw new \InvalidArgumentException("Cannot add lines to a {$reconciliation->status} reconciliation.");
        }

        $lineNumber = ($reconciliation->statementLines()->max('line_number') ?? 0) + 1;

        $line = BankStatementLine::create([
            'bank_reconciliation_id' => $reconciliation->id,
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'] ?? null,
            'reference' => $data['reference'] ?? null,
            'debit' => $data['debit'] ?? 0,
            'credit' => $data['credit'] ?? 0,
            'balance' => $data['balance'] ?? 0,
            'match_status' => 'unmatched',
            'line_number' => $lineNumber,
        ]);

        $this->updateCounts($reconciliation);

        return $line;
    }

    /**
     * Auto-match statement lines against system journal lines.
     *
     * Strategy:
     *   1. For each unmatched statement line, find candidate journal lines
     *      that hit the bank's ledger and have the same amount.
     *   2. Score candidates by amount match, date proximity, and reference match.
     *   3. If score exceeds threshold, mark as "suggested".
     *   4. If exact match (amount + date + reference), mark as "matched" directly.
     */
    public function autoMatch(BankReconciliation $reconciliation): int
    {
        $bank = $reconciliation->bank;
        $ledgerMapping = $bank->ledgerMapping;
        if (!$ledgerMapping) {
            return 0;
        }

        $ledgerId = $ledgerMapping->ledger_id;
        $matchedCount = 0;

        // Get all unmatched statement lines
        $unmatchedLines = $reconciliation->statementLines()
            ->where('match_status', 'unmatched')
            ->orderBy('transaction_date')
            ->get();

        // Get all unreconciled journal lines for this bank's ledger in the period
        $systemEntries = $this->getUnreconciledSystemEntries(
            $ledgerId,
            $reconciliation->period_from,
            $reconciliation->period_to,
            $bank->branch_id
        );

        foreach ($unmatchedLines as $statementLine) {
            $bestMatch = null;
            $bestScore = 0;

            $stmtAmount = $statementLine->getAmount();
            $isDeposit = $statementLine->isDeposit();

            foreach ($systemEntries as $sysEntry) {
                // Skip already matched journal lines
                if ($sysEntry->is_bank_reconciled) {
                    continue;
                }

                // Amount must match: deposits = credit on journal line, withdrawals = debit
                $sysAmount = $isDeposit ? (float) $sysEntry->credit : (float) $sysEntry->debit;

                if (abs($sysAmount - $stmtAmount) > 0.01) {
                    continue; // Amount doesn't match
                }

                // Calculate match score
                $score = 50; // Base score for amount match

                // Date proximity
                $dateDiff = abs($statementLine->transaction_date->diffInDays($sysEntry->entry_date));
                if ($dateDiff === 0) {
                    $score += 30; // Same date
                } elseif ($dateDiff <= self::DATE_TOLERANCE_DAYS) {
                    $score += 15; // Within tolerance
                } else {
                    $score -= 10; // Too far
                }

                // Reference match
                if ($statementLine->reference && $sysEntry->entry_no) {
                    $ref = strtolower($statementLine->reference);
                    $entryNo = strtolower($sysEntry->entry_no);
                    if (str_contains($entryNo, $ref) || str_contains($ref, $entryNo)) {
                        $score += 20;
                    }
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $sysEntry;
                }
            }

            if ($bestMatch && $bestScore >= 60) {
                $matchStatus = $bestScore >= 80 ? 'matched' : 'suggested';

                BankReconciliationItem::create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'bank_statement_line_id' => $statementLine->id,
                    'journal_line_id' => $bestMatch->id,
                    'journal_entry_id' => $bestMatch->journal_entry_id,
                    'match_type' => 'auto',
                    'matched_amount' => $stmtAmount,
                    'matched_by' => Auth::id(),
                    'matched_at' => now(),
                ]);

                $statementLine->update(['match_status' => $matchStatus]);

                if ($matchStatus === 'matched') {
                    // Mark the journal line as reconciled
                    $bestMatch->update([
                        'is_bank_reconciled' => true,
                        'bank_reconciliation_id' => $reconciliation->id,
                    ]);
                }

                $matchedCount++;
            }
        }

        $this->updateCounts($reconciliation);

        return $matchedCount;
    }

    /**
     * Manually match a statement line with a journal line.
     */
    public function manualMatch(BankReconciliation $reconciliation, int $statementLineId, int $journalLineId): BankReconciliationItem
    {
        if (!$reconciliation->isEditable()) {
            throw new \InvalidArgumentException("Cannot modify a {$reconciliation->status} reconciliation.");
        }

        $statementLine = BankStatementLine::where('bank_reconciliation_id', $reconciliation->id)
            ->findOrFail($statementLineId);

        $journalLine = JournalLine::findOrFail($journalLineId);

        // Check if already matched
        $existingItem = BankReconciliationItem::where('bank_statement_line_id', $statementLineId)
            ->where('journal_line_id', $journalLineId)
            ->first();

        if ($existingItem) {
            throw new \InvalidArgumentException('These items are already matched.');
        }

        $item = BankReconciliationItem::create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_statement_line_id' => $statementLineId,
            'journal_line_id' => $journalLineId,
            'journal_entry_id' => $journalLine->journal_entry_id,
            'match_type' => 'manual',
            'matched_amount' => $statementLine->getAmount(),
            'matched_by' => Auth::id(),
            'matched_at' => now(),
        ]);

        $statementLine->update(['match_status' => 'matched']);

        $journalLine->update([
            'is_bank_reconciled' => true,
            'bank_reconciliation_id' => $reconciliation->id,
        ]);

        $this->updateCounts($reconciliation);

        return $item;
    }

    /**
     * Unmatch a previously matched statement line.
     */
    public function unmatch(BankReconciliation $reconciliation, int $statementLineId): void
    {
        if (!$reconciliation->isEditable()) {
            throw new \InvalidArgumentException("Cannot modify a {$reconciliation->status} reconciliation.");
        }

        $items = BankReconciliationItem::where('bank_reconciliation_id', $reconciliation->id)
            ->where('bank_statement_line_id', $statementLineId)
            ->get();

        foreach ($items as $item) {
            // Un-reconcile the journal line
            JournalLine::where('id', $item->journal_line_id)
                ->update([
                    'is_bank_reconciled' => false,
                    'bank_reconciliation_id' => null,
                ]);

            $item->delete();
        }

        // Reset statement line status
        BankStatementLine::where('id', $statementLineId)
            ->update(['match_status' => 'unmatched']);

        $this->updateCounts($reconciliation);
    }

    /**
     * Complete a reconciliation — calculate final balances and lock.
     */
    public function completeReconciliation(BankReconciliation $reconciliation, int $userId): BankReconciliation
    {
        if (!$reconciliation->isEditable()) {
            throw new \InvalidArgumentException("Cannot complete a {$reconciliation->status} reconciliation.");
        }

        $this->recalculateBalances($reconciliation);

        // Mark all "suggested" items as "matched" (auto-confirm)
        $reconciliation->statementLines()
            ->where('match_status', 'suggested')
            ->update(['match_status' => 'matched']);

        // Mark all matched journal lines as reconciled
        $matchedItems = $reconciliation->reconciliationItems()
            ->with('journalLine')
            ->get();

        foreach ($matchedItems as $item) {
            if ($item->journalLine && !$item->journalLine->is_bank_reconciled) {
                $item->journalLine->update([
                    'is_bank_reconciled' => true,
                    'bank_reconciliation_id' => $reconciliation->id,
                ]);
            }
        }

        $reconciliation->update([
            'status' => 'completed',
            'completed_by' => $userId,
            'completed_at' => now(),
        ]);

        return $reconciliation->fresh();
    }

    /**
     * Reverse a completed reconciliation.
     */
    public function reverseReconciliation(BankReconciliation $reconciliation, int $userId, string $reason): BankReconciliation
    {
        if (!$reconciliation->isCompleted()) {
            throw new \InvalidArgumentException("Only completed reconciliations can be reversed.");
        }

        DB::transaction(function () use ($reconciliation, $userId, $reason) {
            // Un-reconcile all matched journal lines
            $matchedJournalLineIds = $reconciliation->reconciliationItems()
                ->pluck('journal_line_id')
                ->toArray();

            JournalLine::whereIn('id', $matchedJournalLineIds)
                ->where('bank_reconciliation_id', $reconciliation->id)
                ->update([
                    'is_bank_reconciled' => false,
                    'bank_reconciliation_id' => null,
                ]);

            // Reverse the adjustment journal entry if one exists
            if ($reconciliation->adjustment_journal_entry_id) {
                $je = \App\Models\Accounting\JournalEntry::find($reconciliation->adjustment_journal_entry_id);
                if ($je && !$je->is_reversed) {
                    app(JournalReversalService::class)->reverse(
                        $je,
                        $userId,
                        "Reversal of bank reconciliation adjustment: {$reason}"
                    );
                }
            }

            $reconciliation->update([
                'status' => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reverse_reason' => $reason,
            ]);
        });

        return $reconciliation->fresh();
    }

    /**
     * Get unreconciled system entries for a bank's ledger.
     */
    public function getUnreconciledSystemEntries(int $ledgerId, string $periodFrom, string $periodTo, ?int $branchId = null)
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledgers', 'ledgers.id', '=', 'journal_lines.ledger_id')
            ->where('journal_lines.ledger_id', $ledgerId)
            ->where('journal_entries.entry_date', '>=', $periodFrom)
            ->where('journal_entries.entry_date', '<=', $periodTo)
            ->where('journal_lines.is_bank_reconciled', false)
            ->whereRaw('COALESCE(journal_entries.is_reversed, false) = false')
            ->whereNull('ledgers.deleted_at')
            ->select(
                'journal_lines.id',
                'journal_lines.journal_entry_id',
                'journal_lines.ledger_id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.memo',
                'journal_lines.is_bank_reconciled',
                'journal_entries.entry_no',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
                'journal_entries.source as entry_source',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
                'journal_entries.branch_id'
            )
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_no');

        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        return $query->get();
    }

    /**
     * Get all unreconciled bank entries across all banks (for the unreconciled report).
     */
    public function getAllUnreconciledEntries(?int $branchId = null)
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledgers', 'ledgers.id', '=', 'journal_lines.ledger_id')
            ->leftJoin('bank_ledger_mappings', 'bank_ledger_mappings.ledger_id', '=', 'ledgers.id')
            ->leftJoin('banks', 'banks.id', '=', 'bank_ledger_mappings.bank_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entries.branch_id')
            ->where('journal_lines.is_bank_reconciled', false)
            ->whereRaw('COALESCE(journal_entries.is_reversed, false) = false')
            ->whereNotNull('bank_ledger_mappings.bank_id')
            ->whereNull('ledgers.deleted_at')
            ->where('ledgers.is_active', true)
            ->select(
                'journal_lines.id as journal_line_id',
                'journal_lines.journal_entry_id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.memo',
                'journal_entries.entry_no',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
                'journal_entries.source as entry_source',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
                'ledgers.ledger_code',
                'ledgers.ledger_name',
                'banks.id as bank_id',
                'banks.bank_name',
                'banks.account_number',
                'journal_entries.branch_id',
                'branches.branch_name'
            )
            ->orderBy('banks.bank_name')
            ->orderBy('journal_entries.entry_date');

        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        return $query->get();
    }

    /**
     * Recalculate the reconciliation balances.
     */
    public function recalculateBalances(BankReconciliation $reconciliation): void
    {
        $this->updateCounts($reconciliation);

        $systemClosing = (float) $reconciliation->system_closing_balance;
        $statementClosing = (float) $reconciliation->statement_closing_balance;

        // Deposits in transit: statement deposits NOT matched to system entries
        $depositsInTransit = (float) $reconciliation->statementLines()
            ->where('match_status', 'unmatched')
            ->where('credit', '>', 0)
            ->sum('credit');

        // Outstanding checks: system withdrawals NOT matched to statement lines
        $bank = $reconciliation->bank;
        $ledgerMapping = $bank->ledgerMapping;
        $outstandingChecks = 0;

        if ($ledgerMapping) {
            $unreconciled = $this->getUnreconciledSystemEntries(
                $ledgerMapping->ledger_id,
                $reconciliation->period_from,
                $reconciliation->period_to,
                $bank->branch_id
            );

            // Get journal line IDs that are already matched in this reconciliation
            $matchedJlIds = $reconciliation->reconciliationItems()->pluck('journal_line_id')->toArray();

            foreach ($unreconciled as $entry) {
                if (!in_array($entry->id, $matchedJlIds)) {
                    $outstandingChecks += (float) $entry->debit;
                }
            }
        }

        // Adjusted Book Balance = System Closing + Deposits in Transit - Outstanding Checks
        $adjustedBook = $systemClosing + $depositsInTransit - $outstandingChecks;

        // Adjusted Bank Balance = Statement Closing
        $adjustedBank = $statementClosing;

        $difference = $adjustedBook - $adjustedBank;

        $reconciliation->update([
            'adjusted_book_balance' => $adjustedBook,
            'adjusted_bank_balance' => $adjustedBank,
            'difference' => $difference,
        ]);
    }

    /**
     * Calculate the system balance for a bank's ledger up to a given date.
     * For a debit-normal ledger (cash_bank): balance = sum(debit) - sum(credit)
     */
    private function calculateSystemBalance(int $ledgerId, string $asOfDate, ?int $branchId = null): float
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.ledger_id', $ledgerId)
            ->where('journal_entries.entry_date', '<=', $asOfDate)
            ->whereRaw('COALESCE(journal_entries.is_reversed, false) = false');

        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        $result = $query->selectRaw('
            COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) AS balance
        ')->first();

        return (float) ($result->balance ?? 0);
    }

    /**
     * Update reconciliation counts.
     */
    private function updateCounts(BankReconciliation $reconciliation): void
    {
        $totalLines = $reconciliation->statementLines()->count();
        $matchedLines = $reconciliation->statementLines()->where('match_status', 'matched')->count();
        $suggestedLines = $reconciliation->statementLines()->where('match_status', 'suggested')->count();
        $unmatchedLines = $reconciliation->statementLines()->where('match_status', 'unmatched')->count();
        $excludedLines = $reconciliation->statementLines()->where('match_status', 'excluded')->count();

        // Count unmatched system entries
        $bank = $reconciliation->bank;
        $ledgerMapping = $bank?->ledgerMapping;
        $unmatchedSystem = 0;

        if ($ledgerMapping) {
            $matchedJlIds = $reconciliation->reconciliationItems()->pluck('journal_line_id')->toArray();
            $unmatchedSystem = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.ledger_id', $ledgerMapping->ledger_id)
                ->where('journal_entries.entry_date', '>=', $reconciliation->period_from)
                ->where('journal_entries.entry_date', '<=', $reconciliation->period_to)
                ->where('journal_lines.is_bank_reconciled', false)
                ->whereRaw('COALESCE(journal_entries.is_reversed, false) = false')
                ->when($bank->branch_id, fn($q) => $q->where('journal_entries.branch_id', $bank->branch_id))
                ->whereNotIn('journal_lines.id', $matchedJlIds)
                ->count();
        }

        $reconciliation->update([
            'total_statement_lines' => $totalLines,
            'matched_lines' => $matchedLines + $suggestedLines,
            'unmatched_statement_lines' => $unmatchedLines,
            'unmatched_system_entries' => $unmatchedSystem,
        ]);
    }

    /**
     * Generate a unique reconciliation code.
     */
    private function generateReconciliationCode(string $periodFrom): string
    {
        $year = substr($periodFrom, 0, 4);
        $month = substr($periodFrom, 5, 2);

        $lastCode = DB::table('bank_reconciliations')
            ->where('reconciliation_code', 'LIKE', "BR-{$year}-{$month}-%")
            ->orderByDesc('reconciliation_code')
            ->value('reconciliation_code');

        $nextSeq = 1;
        if ($lastCode) {
            $parts = explode('-', $lastCode);
            $nextSeq = ((int) end($parts)) + 1;
        }

        return "BR-{$year}-{$month}-" . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
    }
}
