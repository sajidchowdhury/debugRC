<?php

namespace App\Services\Accounting;

use App\Models\OtherIncome;
use App\Models\Bank;
use App\Models\BankLedgerMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Other Income Service — Phase 5 (Accounts Sub-Ledger).
 *
 * Handles the full lifecycle of other income:
 *   create → reverse.
 *
 * GL posting:
 *   Dr Cash/Bank / Cr Other Income head (user-selected ledger)
 *
 * Key difference from other modules:
 *   - No entity sub-ledger (no AR/AP/Employee ledger)
 *   - User selects a specific ledger from Chart of Accounts (income_type + ledger_id)
 *   - Only GL posting + bank balance sync
 */
class OtherIncomeService
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
     * Create an other income record with GL posting and bank balance sync.
     *
     * @param array $data {
     *     branch_id: int,
     *     payment_mode: string ('cash'|'bank'|'mobile_banking'|'cheque'),
     *     bank_id: int|null,
     *     income_type: string|null,
     *     ledger_id: int (user-selected income ledger from chart of accounts),
     *     amount: float,
     *     description: string|null,
     *     income_date: string (Y-m-d),
     *     created_by: int,
     * }
     * @return OtherIncome
     */
    public function createIncome(array $data): OtherIncome
    {
        $this->validateCreateInput($data);

        $incomeCode = $this->generateIncomeCode();
        $branchId = (int) $data['branch_id'];
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($data, $incomeCode, $branchId, $amount) {
            // 1. Insert other_incomes record.
            $incomeId = DB::table('other_incomes')->insertGetId([
                'income_code'  => $incomeCode,
                'income_date'  => $data['income_date'] ?? now()->format('Y-m-d'),
                'branch_id'    => $branchId,
                'payment_mode' => $data['payment_mode'] ?? 'cash',
                'bank_id'      => $data['bank_id'] ?? null,
                'income_type'  => $data['income_type'] ?? null,
                'amount'       => $amount,
                'description'  => $data['description'] ?? null,
                'is_reversed'  => false,
                'created_by'   => $data['created_by'] ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // 2. Reload as Eloquent model.
            $income = OtherIncome::with(['branch', 'bank'])->find($incomeId);

            // 3. Post GL journal entry.
            $journalEntryId = $this->postIncomeGL($income, (int) ($data['created_by'] ?? 0), (int) ($data['ledger_id'] ?? 0));

            // 4. Update income with journal_entry_id.
            DB::table('other_incomes')
                ->where('id', $incomeId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // 5. Sync bank balance (if bank mode).
            if ($income->isBankMode() && $income->bank_id) {
                $this->syncBankBalance($income->bank_id, $amount, 'income');
            }

            // 6. Audit log.
            $this->logAudit('other_income_created', (int) ($data['created_by'] ?? 0), $incomeId, [
                'income_code'     => $incomeCode,
                'income_type'     => $data['income_type'] ?? null,
                'amount'          => $amount,
                'branch_id'       => $branchId,
                'journal_entry_id' => $journalEntryId,
            ]);

            return OtherIncome::with(['branch', 'bank', 'journalEntry.lines.ledger'])->find($incomeId);
        });
    }

    // ============================================================
    // REVERSE
    // ============================================================

    /**
     * Reverse an other income record.
     */
    public function reverseIncome(int $incomeId, int $reversedBy, string $reason = ''): OtherIncome
    {
        return DB::transaction(function () use ($incomeId, $reversedBy, $reason) {
            $income = OtherIncome::lockForUpdate()->find($incomeId);

            if (!$income) {
                throw new \RuntimeException("Other income #{$incomeId} not found.");
            }
            if ($income->is_reversed) {
                throw new \RuntimeException('This income is already reversed.');
            }
            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse GL journal entry.
            if ($income->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $income->journal_entry_id,
                    $reversedBy,
                    "Other income reversal: {$reason}"
                );
            }

            // 2. Mark as reversed.
            DB::table('other_incomes')
                ->where('id', $incomeId)
                ->update([
                    'is_reversed'    => true,
                    'reversed_at'    => now(),
                    'reversed_by'    => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at'     => now(),
                ]);

            // 3. Undo bank balance sync.
            if ($income->isBankMode() && $income->bank_id) {
                $this->syncBankBalance(
                    $income->bank_id,
                    (float) $income->amount,
                    'income',
                    undo: true
                );
            }

            // 4. Audit log.
            $this->logAudit('other_income_reversed', $reversedBy, $incomeId, [
                'income_code' => $income->income_code,
                'reason'      => $reason,
            ]);

            return OtherIncome::find($incomeId);
        });
    }

    // ============================================================
    // QUERY HELPERS
    // ============================================================

    /**
     * Get filtered other incomes for the index page.
     */
    public function getFilteredIncomes(array $filters = [], ?int $branchId = null, int $perPage = 25)
    {
        $query = OtherIncome::with(['branch', 'bank'])
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->where('income_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn($q, $d) => $q->where('income_date', '<=', $d))
            ->when(($filters['payment_mode'] ?? null) && $filters['payment_mode'] !== 'all', fn($q, $m) => $q->where('payment_mode', $m))
            ->when($filters['income_type'] ?? null, fn($q, $t) => $q->where('income_type', $t))
            ->when($filters['branch_id'] ?? null, fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('income_code', 'ILIKE', "%{$search}%");
            });

        // Status filter.
        if (($filters['status'] ?? 'all') === 'reversed') {
            $query->where('is_reversed', true);
        } elseif (($filters['status'] ?? 'all') === 'active') {
            $query->where('is_reversed', false);
        }

        // Default date filter: today if no dates provided.
        if (empty($filters['date_from']) && empty($filters['date_to']) && empty($filters['status'])) {
            $query->where('income_date', now()->format('Y-m-d'));
        }

        return $query->orderBy('income_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get summary stats for the index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = OtherIncome::query();
        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        return [
            'total'        => (clone $baseQuery)->count(),
            'total_amount' => (float) (clone $baseQuery)->where('is_reversed', false)->sum('amount'),
            'cash'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'cash')->sum('amount'),
            'bank'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'bank')->sum('amount'),
            'reversed'     => (clone $baseQuery)->where('is_reversed', true)->count(),
            'today'        => (float) (clone $baseQuery)->where('is_reversed', false)
                ->where('income_date', now()->format('Y-m-d'))
                ->sum('amount'),
            'this_month'   => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereMonth('income_date', now()->month)
                ->whereYear('income_date', now()->year)
                ->sum('amount'),
        ];
    }

    /**
     * Get GL preview labels for the create form.
     */
    public function getGlPreviewLabels(): array
    {
        return [
            'income' => 'Dr Cash/Bank · Cr Other Income',
            'dr' => 'Cash/Bank',
            'cr' => 'Other Income',
        ];
    }

    // ============================================================
    // GL POSTING
    // ============================================================

    /**
     * Post GL journal entry for other income.
     *
     * Dr Cash/Bank / Cr Other Income head (user-selected ledger).
     *
     * @return int journal_entry_id
     */
    private function postIncomeGL(OtherIncome $income, int $createdBy, int $ledgerId): int
    {
        $amount = (float) $income->amount;
        if ($amount < 0.01) return 0;

        // Resolve the credit ledger (Income head).
        // If user selected a specific ledger, use it; otherwise fall back to nature lookup.
        $creditLedgerId = $ledgerId > 0
            ? $ledgerId
            : $this->journalPosting->lookupLedgerByNature('other_income');

        if (!$creditLedgerId) {
            throw new \RuntimeException('Income ledger not found. Select an income head from Chart of Accounts or configure the "other_income" nature ledger.');
        }

        // Resolve the debit ledger (Cash/Bank).
        $debitLedgerId = $this->resolveDebitLedger($income);
        if (!$debitLedgerId) {
            throw new \RuntimeException('Could not resolve debit ledger (Cash/Bank) for other income.');
        }

        $incomeType = $income->income_type ?? 'Other Income';
        $description = "Other Income — {$income->income_code}" . ($income->income_type ? " ({$income->income_type})" : '');

        return $this->journalPosting->postJournalEntry([
            'entry_date'     => $income->income_date ?? now()->format('Y-m-d'),
            'description'    => $description,
            'source'         => 'other_income',
            'reference_type' => 'other_income',
            'reference_id'   => $income->id,
            'branch_id'      => $income->branch_id,
            'lines'          => [
                ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash/Bank received'],
                ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => $incomeType],
            ],
            'created_by'     => $createdBy,
        ]);
    }

    /**
     * Resolve the debit ledger (Cash/Bank) for other income.
     */
    private function resolveDebitLedger(OtherIncome $income): ?int
    {
        if ($income->isBankMode() && $income->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $income->bank_id)->first();
            if ($mapping) return (int) $mapping->ledger_id;
        }

        return $this->journalPosting->lookupLedgerByNature('cash_bank');
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank book balance (increase for income, decrease for reversal).
     */
    private function syncBankBalance(int $bankId, float $amount, string $type, bool $undo = false): void
    {
        // Other income increases bank balance (money comes in).
        $direction = 1;

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

        Log::info("Other income bank balance sync", [
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
     * Generate income code: OI-YYYY-NNNNN.
     */
    private function generateIncomeCode(): string
    {
        return $this->sequenceService->next('OI');
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    /**
     * Validate input data for createIncome.
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
            $fallback = $this->journalPosting->lookupLedgerByNature('other_income');
            if (!$fallback) {
                throw new \RuntimeException('Please select an income ledger from Chart of Accounts.');
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
            Log::error("Failed to log other income audit: {$e->getMessage()}");
        }
    }
}
