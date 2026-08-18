<?php

namespace App\Services\Accounting;

use App\Models\OtherExpense;
use App\Models\Bank;
use App\Models\BankLedgerMapping;
use App\Support\FiscalYearResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Other Expense Service — Phase 5 (Accounts Sub-Ledger).
 *
 * Handles the full lifecycle of other expenses:
 *   create → reverse.
 *
 * GL posting:
 *   Dr Operating Expense head (user-selected ledger) / Cr Cash/Bank
 *
 * Key difference from other modules:
 *   - No entity sub-ledger (no AR/AP/Employee ledger)
 *   - User selects a specific ledger from Chart of Accounts (expense_type + ledger_id)
 *   - Only GL posting + bank balance sync
 */
class OtherExpenseService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private DocumentSequenceService $sequenceService,
    ) {}

    // ============================================================
    // CREATE
    // ============================================================

    /**
     * Create an other expense record with GL posting and bank balance sync.
     *
     * @param array $data {
     *     branch_id: int,
     *     payment_mode: string ('cash'|'bank'|'mobile_banking'|'cheque'),
     *     bank_id: int|null,
     *     expense_type: string|null,
     *     ledger_id: int (user-selected expense ledger from chart of accounts),
     *     amount: float,
     *     description: string|null,
     *     expense_date: string (Y-m-d),
     *     created_by: int,
     * }
     * @return OtherExpense
     */
    public function createExpense(array $data): OtherExpense
    {
        $this->validateCreateInput($data);

        $expenseCode = $this->generateExpenseCode();
        $branchId = (int) $data['branch_id'];
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($data, $expenseCode, $branchId, $amount) {
            // 1. Insert other_expenses record.
            $expenseId = DB::table('other_expenses')->insertGetId([
                'expense_code' => $expenseCode,
                'expense_date' => $data['expense_date'] ?? now()->format('Y-m-d'),
                'branch_id'    => $branchId,
                'payment_mode' => $data['payment_mode'] ?? 'cash',
                'bank_id'      => $data['bank_id'] ?? null,
                'expense_type' => $data['expense_type'] ?? null,
                'amount'       => $amount,
                'description'  => $data['description'] ?? null,
                'is_reversed'  => false,
                'created_by'   => $data['created_by'] ?? null,
                'fiscal_year_id' => FiscalYearResolver::activeId(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // 2. Reload as Eloquent model.
            $expense = OtherExpense::with(['branch', 'bank'])->find($expenseId);

            // 3. Post GL journal entry.
            $journalEntryId = $this->postExpenseGL($expense, (int) ($data['created_by'] ?? 0), (int) ($data['ledger_id'] ?? 0));

            // 4. Update expense with journal_entry_id.
            DB::table('other_expenses')
                ->where('id', $expenseId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // 5. Sync bank balance (if bank mode).
            if ($expense->isBankMode() && $expense->bank_id) {
                $this->syncBankBalance($expense->bank_id, $amount, 'expense');
            }

            // 6. Audit log.
            $this->logAudit('other_expense_created', (int) ($data['created_by'] ?? 0), $expenseId, [
                'expense_code'    => $expenseCode,
                'expense_type'    => $data['expense_type'] ?? null,
                'amount'          => $amount,
                'branch_id'       => $branchId,
                'journal_entry_id' => $journalEntryId,
            ]);

            return OtherExpense::with(['branch', 'bank', 'journalEntry.lines.ledger'])->find($expenseId);
        });
    }

    // ============================================================
    // REVERSE
    // ============================================================

    /**
     * Reverse an other expense record.
     */
    public function reverseExpense(int $expenseId, int $reversedBy, string $reason = ''): OtherExpense
    {
        return DB::transaction(function () use ($expenseId, $reversedBy, $reason) {
            $expense = OtherExpense::lockForUpdate()->find($expenseId);

            if (!$expense) {
                throw new \RuntimeException("Other expense #{$expenseId} not found.");
            }
            if ($expense->is_reversed) {
                throw new \RuntimeException('This expense is already reversed.');
            }
            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse GL journal entry.
            if ($expense->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $expense->journal_entry_id,
                    $reversedBy,
                    "Other expense reversal: {$reason}"
                );
            }

            // 2. Mark as reversed.
            DB::table('other_expenses')
                ->where('id', $expenseId)
                ->update([
                    'is_reversed'    => true,
                    'reversed_at'    => now(),
                    'reversed_by'    => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at'     => now(),
                ]);

            // 3. Undo bank balance sync.
            if ($expense->isBankMode() && $expense->bank_id) {
                $this->syncBankBalance(
                    $expense->bank_id,
                    (float) $expense->amount,
                    'expense',
                    undo: true
                );
            }

            // 4. Audit log.
            $this->logAudit('other_expense_reversed', $reversedBy, $expenseId, [
                'expense_code' => $expense->expense_code,
                'reason'       => $reason,
            ]);

            return OtherExpense::find($expenseId);
        });
    }

    // ============================================================
    // QUERY HELPERS
    // ============================================================

    /**
     * Get filtered other expenses for the index page.
     */
    public function getFilteredExpenses(array $filters = [], ?int $branchId = null, int $perPage = 25)
    {
        $query = OtherExpense::with(['branch', 'bank'])
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->where('expense_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn($q, $d) => $q->where('expense_date', '<=', $d))
            ->when(($filters['payment_mode'] ?? null) && $filters['payment_mode'] !== 'all', fn($q, $m) => $q->where('payment_mode', $m))
            ->when($filters['expense_type'] ?? null, fn($q, $t) => $q->where('expense_type', $t))
            ->when($filters['branch_id'] ?? null, fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('expense_code', 'ILIKE', "%{$search}%");
            });

        // Status filter.
        if (($filters['status'] ?? 'all') === 'reversed') {
            $query->where('is_reversed', true);
        } elseif (($filters['status'] ?? 'all') === 'active') {
            $query->where('is_reversed', false);
        }

        // Default date filter: today if no dates provided.
        if (empty($filters['date_from']) && empty($filters['date_to']) && empty($filters['status'])) {
            $query->where('expense_date', now()->format('Y-m-d'));
        }

        return $query->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get summary stats for the index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = OtherExpense::query();
        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        return [
            'total'        => (clone $baseQuery)->count(),
            'total_amount' => (float) (clone $baseQuery)->where('is_reversed', false)->sum('amount'),
            'cash_total'   => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'cash')->sum('amount'),
            'bank_total'   => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'bank')->sum('amount'),
            'reversed'     => (clone $baseQuery)->where('is_reversed', true)->count(),
            'today'        => (float) (clone $baseQuery)->where('is_reversed', false)
                ->where('expense_date', now()->format('Y-m-d'))
                ->sum('amount'),
            'this_month'   => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereMonth('expense_date', now()->month)
                ->whereYear('expense_date', now()->year)
                ->sum('amount'),
        ];
    }

    /**
     * Get GL preview labels for the create form.
     */
    public function getGlPreviewLabels(): array
    {
        return [
            'expense' => 'Dr Operating Expense · Cr Cash/Bank',
            'dr' => 'Operating Expense',
            'cr' => 'Cash/Bank',
        ];
    }

    // ============================================================
    // GL POSTING
    // ============================================================

    /**
     * Post GL journal entry for other expense.
     *
     * Dr Operating Expense head (user-selected ledger) / Cr Cash/Bank.
     *
     * @return int journal_entry_id
     */
    private function postExpenseGL(OtherExpense $expense, int $createdBy, int $ledgerId): int
    {
        $amount = (float) $expense->amount;
        if ($amount < 0.01) return 0;

        // Resolve the debit ledger (Expense head).
        // If user selected a specific ledger, use it; otherwise fall back to nature lookup.
        $debitLedgerId = $ledgerId > 0
            ? $ledgerId
            : $this->journalPosting->lookupLedgerByNature('operating_expense');

        if (!$debitLedgerId) {
            throw new \RuntimeException('Expense ledger not found. Select an expense head from Chart of Accounts or configure the "operating_expense" nature ledger.');
        }

        // Resolve the credit ledger (Cash/Bank).
        $creditLedgerId = $this->resolveCreditLedger($expense);
        if (!$creditLedgerId) {
            throw new \RuntimeException('Could not resolve credit ledger (Cash/Bank) for other expense.');
        }

        $expenseType = $expense->expense_type ?? 'Other Expense';
        $description = "Other Expense — {$expense->expense_code}" . ($expense->expense_type ? " ({$expense->expense_type})" : '');

        return $this->journalPosting->postJournalEntry([
            'entry_date'     => $expense->expense_date ?? now()->format('Y-m-d'),
            'description'    => $description,
            'source'         => 'other_expense',
            'reference_type' => 'other_expense',
            'reference_id'   => $expense->id,
            'branch_id'      => $expense->branch_id,
            'lines'          => [
                ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => $expenseType],
                ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash/Bank paid out'],
            ],
            'created_by'     => $createdBy,
        ]);
    }

    /**
     * Resolve the credit ledger (Cash/Bank) for other expense.
     */
    private function resolveCreditLedger(OtherExpense $expense): ?int
    {
        if ($expense->isBankMode() && $expense->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $expense->bank_id)->first();
            if ($mapping) return (int) $mapping->ledger_id;
        }

        return $this->journalPosting->lookupLedgerByNature('cash_bank');
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank book balance (decrease for expense, increase for reversal).
     */
    private function syncBankBalance(int $bankId, float $amount, string $type, bool $undo = false): void
    {
        // Other expense decreases bank balance (money goes out).
        $direction = -1;

        if ($undo) {
            $direction = $direction * -1; // Reverse the direction.
        }

        $delta = $amount * $direction;

        // FIX: banks table column is `balance` (numeric(18,2)), NOT
        // `current_balance`. The previous query referenced a non-existent
        // column and would throw a PostgreSQL error at runtime.
        DB::table('banks')->where('id', $bankId)->update([
            'balance' => DB::raw("GREATEST(0, balance + {$delta})"),
            'updated_at' => now(),
        ]);

        Log::info("Other expense bank balance sync", [
            'bank_id' => $bankId,
            'delta'   => $delta,
            'type'    => $type,
            'undo'    => $undo,
        ]);
    }

    // ============================================================
    // DOCUMENT SEQUENCE
    // ============================================================

    /**
     * Generate expense code: OE-YYYY-NNNNN.
     */
    private function generateExpenseCode(): string
    {
        return $this->sequenceService->next('OE');
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    /**
     * Validate input data for createExpense.
     */
    private function validateCreateInput(array $data): void
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount < 0.01) {
            throw new \RuntimeException('Amount must be greater than 0.');
        }

        $paymentMode = $data['payment_mode'] ?? 'cash';
        if ($paymentMode === 'bank' && empty($data['bank_id'])) {
            throw new \RuntimeException('Bank ID is required when payment mode is bank.');
        }

        $ledgerId = (int) ($data['ledger_id'] ?? 0);
        if ($ledgerId <= 0) {
            // Try to fall back to nature lookup — but warn.
            $fallback = $this->journalPosting->lookupLedgerByNature('operating_expense');
            if (!$fallback) {
                throw new \RuntimeException('Please select an expense ledger from Chart of Accounts.');
            }
        }
    }

    // ============================================================
    // AUDIT LOG
    // ============================================================

    /**
     * Log an audit entry to user_audit_log.
     */
    private function logAudit(string $action, int $userId, int $recordId, array $details = []): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'user_id'    => $userId,
                'action'     => $action,
                'details'    => json_encode(array_merge($details, ['record_id' => $recordId])),
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to log other expense audit: {$e->getMessage()}");
        }
    }
}
