<?php

namespace App\Services\Accounting;

use App\Models\EmployeeTransaction;
use App\Models\EmployeeLedger;
use App\Models\Bank;
use App\Models\BankLedgerMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee Transaction Service — Phase 2 (Accounts Sub-Ledger).
 *
 * Handles the full lifecycle of employee transactions:
 *   create → confirm → reverse.
 *
 * Transaction types (transaction_type column, CHECK constraint):
 *   - advance:    Cash/bank paid to employee → Dr Employee Payable, Cr Bank/Cash
 *   - loan:       Loan disbursed → Dr Employee Payable, Cr Bank/Cash
 *   - salary:     Salary paid out → Dr Salary Expense, Cr Bank/Cash
 *   - repayment:  Employee repays → Dr Bank/Cash, Cr Employee Payable
 *   - deduction:  Deduction/recovery → Dr Salary Expense, Cr Employee Payable
 *   - adjustment: Manual adjustment → debit or credit depending on context
 *
 * On confirm (atomic operations):
 *   1. GL: Type-specific journal entry (see postTransactionGL)
 *   2. Employee ledger: debit entry for outflow types, credit for inflow types
 *   3. Bank balance sync (if bank mode)
 *
 * GL posting by transaction_type:
 *   advance/loan:     Dr Employee Payable / Cr Bank/Cash
 *   salary:           Dr Salary Expense / Cr Bank/Cash
 *   repayment:        Dr Bank/Cash / Cr Employee Payable
 *   deduction:        Dr Salary Expense / Cr Employee Payable
 *   adjustment:       Dr/Cr varies by context
 */
class EmployeeTransactionService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * Valid transaction types (matches DB CHECK constraint).
     */
    public const TRANSACTION_TYPES = ['advance', 'loan', 'repayment', 'salary', 'deduction', 'adjustment'];

    /**
     * Transaction types that are outflows (money goes out → debit employee_ledger).
     */
    public const OUTFLOW_TYPES = ['advance', 'loan', 'salary', 'adjustment'];

    /**
     * Transaction types that are inflows (money comes in → credit employee_ledger).
     */
    public const INFLOW_TYPES = ['repayment', 'deduction'];

    /**
     * Transaction types whose GL includes a Cash/Bank line.
     * Used to decide whether to sync the bank balance + post intercompany.
     * 'deduction' is EXCLUDED because its GL is Dr Salary Expense / Cr
     * Employee Payable — no cash/bank movement.
     */
    public const BANK_INVOLVED_TYPES = ['advance', 'loan', 'salary', 'repayment', 'adjustment'];

    // ============================================================
    // CREATE + CONFIRM (single-step, like legacy)
    // ============================================================

    /**
     * Create and confirm an employee transaction in one step.
     *
     * Flow (atomic):
     * 1. Validate employee is active
     * 2. Generate transaction code (ET-YYYY-NNNNN)
     * 3. Insert employee_transactions record
     * 4. Post GL journal entry (via JournalPostingService)
     * 5. Insert employee_ledger entry (via SubLedgerService)
     * 6. Link journal_entry_id back to employee_transactions
     * 7. Bank balance sync (if bank mode)
     * 8. Audit log
     *
     * @param array $data {
     *     employee_id: int,
     *     branch_id: int,
     *     transaction_date: string (Y-m-d),
     *     transaction_type: string ('advance'|'loan'|'repayment'|'salary'|'deduction'|'adjustment'),
     *     payment_mode: string ('cash'|'bank'|'mobile_banking'|'cheque'|'adjustment'),
     *     bank_id: int|null,
     *     amount: float,
     *     description: string|null,
     *     collected_by: int|null,
     *     created_by: int,
     * }
     * @return EmployeeTransaction
     */
    public function createTransaction(array $data): EmployeeTransaction
    {
        $this->validateCreateInput($data);

        $transactionCode = $this->generateTransactionCode($data['transaction_type'] ?? 'advance');
        $employeeId = (int) $data['employee_id'];
        $branchId = (int) $data['branch_id'];
        $transactionType = $data['transaction_type'] ?? 'advance';
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($data, $transactionCode, $employeeId, $branchId, $transactionType, $amount) {
            // 1. Insert employee_transactions record.
            $transactionId = DB::table('employee_transactions')->insertGetId([
                'transaction_code' => $transactionCode,
                'transaction_date' => $data['transaction_date'] ?? now()->format('Y-m-d'),
                'employee_id'      => $employeeId,
                'branch_id'        => $branchId,
                'payment_mode'     => $data['payment_mode'] ?? 'cash',
                'bank_id'          => $data['bank_id'] ?? null,
                'transaction_type' => $transactionType,
                'amount'           => $amount,
                'description'      => $data['description'] ?? null,
                'collected_by'     => $data['collected_by'] ?? null,
                'is_reversed'      => false,
                'created_by'       => $data['created_by'] ?? null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // 2. Reload as Eloquent model.
            $transaction = EmployeeTransaction::with(['employee', 'branch', 'bank'])->find($transactionId);

            // 3. Post GL journal entry.
            $journalEntryId = $this->postTransactionGL($transaction, (int) ($data['created_by'] ?? 0));

            // 4. Post employee_ledger entry.
            $this->postEmployeeLedgerForType($transaction, $journalEntryId, (int) ($data['created_by'] ?? 0));

            // 5. Intercompany settlement (if bank-mode + cross-branch + bank-involved type).
            //    'deduction' is excluded — its GL has no bank line.
            $intercompanyJournalId = null;
            if ($transaction->isBankMode() && in_array($transactionType, self::BANK_INVOLVED_TYPES)) {
                $intercompanyJournalId = $this->postIntercompanySettlement($transaction, (int) ($data['created_by'] ?? 0));
            }

            // 6. Update transaction with journal_entry_id + intercompany_journal_entry_id.
            DB::table('employee_transactions')
                ->where('id', $transactionId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'intercompany_journal_entry_id' => $intercompanyJournalId,
                    'updated_at' => now(),
                ]);

            // 7. Sync bank balance (if bank mode AND the GL has a bank/cash line).
            //    'deduction' type is EXCLUDED — its GL is Dr Salary Expense /
            //    Cr Employee Payable with NO bank/cash line, so the bank
            //    balance must NOT change.
            if ($transaction->isBankMode() && $transaction->bank_id && in_array($transactionType, self::BANK_INVOLVED_TYPES)) {
                $this->syncBankBalance($transaction->bank_id, $amount, $transactionType);
            }

            // 8. Audit log.
            $this->logAudit('employee_transaction_created', (int) ($data['created_by'] ?? 0), $transactionId, [
                'transaction_code' => $transactionCode,
                'transaction_type' => $transactionType,
                'amount'           => $amount,
                'employee_id'      => $employeeId,
                'journal_entry_id' => $journalEntryId,
                'intercompany_journal_entry_id' => $intercompanyJournalId,
            ]);

            return EmployeeTransaction::with([
                'employee', 'branch', 'bank',
                'journalEntry.lines.ledger',
                'intercompanyJournalEntry.lines.ledger',
            ])->find($transactionId);
        });
    }

    // ============================================================
    // REVERSE (CANCEL)
    // ============================================================

    /**
     * Reverse an employee transaction — reverse GL + ledger + bank balance.
     *
     * @param int $transactionId
     * @param int $reversedBy
     * @param string $reason
     * @return EmployeeTransaction
     */
    public function reverseTransaction(int $transactionId, int $reversedBy, string $reason = ''): EmployeeTransaction
    {
        return DB::transaction(function () use ($transactionId, $reversedBy, $reason) {
            $transaction = EmployeeTransaction::lockForUpdate()->find($transactionId);

            if (!$transaction) {
                throw new \RuntimeException("Transaction {$transactionId} not found.");
            }
            if ($transaction->is_reversed) {
                throw new \RuntimeException("Transaction is already reversed.");
            }
            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse GL + linked employee_ledger via JournalReversalService (cascade).
            if ($transaction->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $transaction->journal_entry_id,
                    $reversedBy,
                    "Employee transaction reversal: {$reason}"
                );
            }

            // 2. Reverse ALL intercompany GL entries for this transaction.
            //    postIntercompanySettlement() posts TWO entries (creditor at
            //    the bank's branch + debtor at the transaction's branch), both
            //    tagged reference_type='employee_transaction_intercompany'.
            //    Only the debtor id is stored in intercompany_journal_entry_id,
            //    so we reverse by reference lookup to catch both. Idempotent:
            //    skip entries already reversed.
            $intercompanyJeIds = DB::table('journal_entries')
                ->where('reference_type', 'employee_transaction_intercompany')
                ->where('reference_id', $transactionId)
                ->where('is_reversed', false)
                ->pluck('id');

            foreach ($intercompanyJeIds as $icJeId) {
                $this->journalReversal->reverseByJournalEntry(
                    (int) $icJeId,
                    $reversedBy,
                    "Employee transaction reversal: {$reason}"
                );
            }

            // Also reverse the branch_ledger obligation row.
            // NOTE: branch_ledger has NO updated_at column (only created_at),
            // so we set only is_reversed.
            DB::table('branch_ledger')
                ->where('reference_type', 'employee_transaction')
                ->where('reference_id', $transactionId)
                ->where('is_reversed', false)
                ->update([
                    'is_reversed' => true,
                ]);

            // 3. Mark transaction as reversed.
            DB::table('employee_transactions')
                ->where('id', $transactionId)
                ->update([
                    'is_reversed'    => true,
                    'reversed_at'    => now(),
                    'reversed_by'    => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at'     => now(),
                ]);

            // 4. Undo bank balance sync — only for types that had a bank sync
            //    on create (BANK_INVOLVED_TYPES). 'deduction' never touched
            //    the bank, so there's nothing to undo.
            $txnType = $transaction->transaction_type ?? 'advance';
            if ($transaction->isBankMode() && $transaction->bank_id && in_array($txnType, self::BANK_INVOLVED_TYPES)) {
                $this->syncBankBalance(
                    $transaction->bank_id,
                    (float) $transaction->amount,
                    $txnType,
                    undo: true
                );
            }

            // 5. Audit log.
            $this->logAudit('employee_transaction_reversed', $reversedBy, $transactionId, [
                'transaction_code' => $transaction->transaction_code,
                'reason'           => $reason,
            ]);

            return EmployeeTransaction::find($transactionId);
        });
    }

    // ============================================================
    // QUERY HELPERS
    // ============================================================

    /**
     * Get employee's current payable balance (what employee owes).
     */
    public function getEmployeeDue(int $employeeId): float
    {
        return EmployeeLedger::getBalance($employeeId);
    }

    /**
     * Get filtered employee transactions with pagination.
     */
    public function getFilteredTransactions(array $filters = [], ?int $branchId = null, int $perPage = 25)
    {
        $query = EmployeeTransaction::with(['employee', 'branch', 'bank', 'collectedBy'])
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn($q, $d) => $q->where('transaction_date', '<=', $d))
            ->when($filters['employee_id'] ?? null, fn($q, $eid) => $q->where('employee_id', $eid))
            ->when($filters['branch_id'] ?? null, fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($filters['payment_mode'] ?? null, fn($q, $m) => $q->where('payment_mode', $m))
            ->when($filters['transaction_type'] ?? null, fn($q, $t) => $q->where('transaction_type', $t))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('transaction_code', 'ILIKE', "%{$search}%");
            });

        // Status filter.
        if (($filters['status'] ?? 'all') === 'reversed') {
            $query->where('is_reversed', true);
        } elseif (($filters['status'] ?? 'all') === 'active') {
            $query->where('is_reversed', false);
        }

        // No default date filter — show all records when no dates provided.
        // This matches the summary cards which have no date restriction,
        // and matches the SupplierTransactionService behavior. Previously
        // defaulted to today-only which hid transactions from other days.

        return $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get summary stats for the index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = EmployeeTransaction::query();
        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        return [
            'total'        => (clone $baseQuery)->count(),
            'total_amount' => (float) (clone $baseQuery)->where('is_reversed', false)->sum('amount'),
            'cash'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'cash')->sum('amount'),
            'bank'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'bank')->sum('amount'),
            'reversed'     => (clone $baseQuery)->where('is_reversed', true)->count(),
            'advances'     => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'advance')->sum('amount'),
            'loans'        => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'loan')->sum('amount'),
            'salaries'     => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'salary')->sum('amount'),
            'repayments'   => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'repayment')->sum('amount'),
            'deductions'   => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'deduction')->sum('amount'),
            'out_today'    => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereIn('transaction_type', self::OUTFLOW_TYPES)
                ->where('transaction_date', now()->format('Y-m-d'))
                ->sum('amount'),
            'out_month'    => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereIn('transaction_type', self::OUTFLOW_TYPES)
                ->whereMonth('transaction_date', now()->month)
                ->whereYear('transaction_date', now()->year)
                ->sum('amount'),
        ];
    }

    /**
     * Get GL preview labels for the create form.
     */
    public function getGlPreviewLabels(): array
    {
        return [
            'advance'    => 'Dr Employee Payable · Cr Bank/Cash',
            'loan'       => 'Dr Employee Payable · Cr Bank/Cash',
            'salary'     => 'Dr Salary Expense · Cr Bank/Cash',
            'repayment'  => 'Dr Bank/Cash · Cr Employee Payable',
            'deduction'  => 'Dr Salary Expense · Cr Employee Payable',
            'adjustment' => 'Dr/Cr varies by context',
        ];
    }

    // ============================================================
    // GL POSTING — TYPE-SPECIFIC
    // ============================================================

    /**
     * Post GL journal entry based on transaction_type.
     *
     * advance/loan:  Dr Employee Payable / Cr Bank/Cash
     * salary:        Dr Salary Expense / Cr Bank/Cash
     * repayment:     Dr Bank/Cash / Cr Employee Payable
     * deduction:     Dr Salary Expense / Cr Employee Payable
     * adjustment:    Dr Employee Payable / Cr Bank/Cash (default)
     *
     * @return int journal_entry_id
     */
    private function postTransactionGL(EmployeeTransaction $transaction, int $createdBy): int
    {
        $amount = (float) $transaction->amount;
        if ($amount < 0.01) return 0;

        $transactionType = $transaction->transaction_type ?? 'advance';
        $lines = [];

        switch ($transactionType) {
            case 'advance':
            case 'loan':
                $lines = $this->buildOutflowGL($transaction, $amount, 'employee_payable');
                break;

            case 'salary':
                $lines = $this->buildSalaryGL($transaction, $amount);
                break;

            case 'repayment':
                $lines = $this->buildInflowGL($transaction, $amount, 'employee_payable');
                break;

            case 'deduction':
                $lines = $this->buildDeductionGL($transaction, $amount);
                break;

            case 'adjustment':
            default:
                $lines = $this->buildOutflowGL($transaction, $amount, 'employee_payable');
                break;
        }

        if (empty($lines)) return 0;

        $typeLabels = [
            'advance' => 'Employee advance',
            'loan' => 'Employee loan',
            'salary' => 'Salary payment',
            'repayment' => 'Employee repayment',
            'deduction' => 'Employee deduction',
            'adjustment' => 'Employee adjustment',
        ];

        $description = ($typeLabels[$transactionType] ?? 'Employee transaction')
            . " — {$transaction->transaction_code}"
            . ($transaction->employee ? " — {$transaction->employee->name}" : '');

        return $this->journalPosting->postJournalEntry([
            'entry_date'  => $transaction->transaction_date ?? now()->format('Y-m-d'),
            'description' => $description,
            'source'      => 'employee_transaction',
            'branch_id'   => $transaction->branch_id,
            'lines'       => $lines,
            'created_by'  => $createdBy,
        ]);
    }

    /**
     * Build GL lines for outflow types (advance/loan/adjustment).
     * Dr Employee Payable, Cr Bank/Cash.
     */
    private function buildOutflowGL(EmployeeTransaction $transaction, float $amount, string $debitNature): array
    {
        $debitLedgerId = $this->journalPosting->lookupLedgerByNature($debitNature);
        if (!$debitLedgerId) {
            throw new \RuntimeException("Ledger nature '{$debitNature}' not found. Configure in Chart of Accounts.");
        }

        $creditLedgerId = $this->resolveCreditLedger($transaction);
        if (!$creditLedgerId) {
            throw new \RuntimeException('Could not resolve credit ledger for employee transaction.');
        }

        return [
            ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Employee advance/loan'],
            ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash/Bank outflow'],
        ];
    }

    /**
     * Build GL lines for salary.
     * Dr Salary Expense, Cr Bank/Cash.
     */
    private function buildSalaryGL(EmployeeTransaction $transaction, float $amount): array
    {
        $debitLedgerId = $this->journalPosting->lookupLedgerByNature('salary_expense');
        if (!$debitLedgerId) {
            throw new \RuntimeException("Ledger nature 'salary_expense' not found. Configure in Chart of Accounts.");
        }

        $creditLedgerId = $this->resolveCreditLedger($transaction);
        if (!$creditLedgerId) {
            throw new \RuntimeException('Could not resolve credit ledger for salary transaction.');
        }

        return [
            ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Salary payment'],
            ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash/Bank outflow'],
        ];
    }

    /**
     * Build GL lines for inflow types (repayment).
     * Dr Bank/Cash, Cr Employee Payable.
     */
    private function buildInflowGL(EmployeeTransaction $transaction, float $amount, string $creditNature): array
    {
        $debitLedgerId = $this->resolveDebitLedger($transaction);
        if (!$debitLedgerId) {
            throw new \RuntimeException('Could not resolve debit ledger for employee repayment.');
        }

        $creditLedgerId = $this->journalPosting->lookupLedgerByNature($creditNature);
        if (!$creditLedgerId) {
            throw new \RuntimeException("Ledger nature '{$creditNature}' not found. Configure in Chart of Accounts.");
        }

        return [
            ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash/Bank inflow'],
            ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Employee repayment'],
        ];
    }

    /**
     * Build GL lines for deduction.
     * Dr Salary Expense, Cr Employee Payable.
     */
    private function buildDeductionGL(EmployeeTransaction $transaction, float $amount): array
    {
        $debitLedgerId = $this->journalPosting->lookupLedgerByNature('salary_expense');
        if (!$debitLedgerId) {
            throw new \RuntimeException("Ledger nature 'salary_expense' not found. Configure in Chart of Accounts.");
        }

        $creditLedgerId = $this->journalPosting->lookupLedgerByNature('employee_payable');
        if (!$creditLedgerId) {
            throw new \RuntimeException("Ledger nature 'employee_payable' not found. Configure in Chart of Accounts.");
        }

        return [
            ['ledger_id' => $debitLedgerId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Salary deduction'],
            ['ledger_id' => $creditLedgerId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Employee deduction'],
        ];
    }

    /**
     * Resolve the credit ledger (Bank/Cash) for outflow transactions.
     */
    private function resolveCreditLedger(EmployeeTransaction $transaction): ?int
    {
        if ($transaction->isBankMode() && $transaction->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $transaction->bank_id)->first();
            if ($mapping) return (int) $mapping->ledger_id;
        }

        return $this->journalPosting->lookupLedgerByNature('cash_bank');
    }

    /**
     * Resolve the debit ledger (Bank/Cash) for inflow transactions.
     */
    private function resolveDebitLedger(EmployeeTransaction $transaction): ?int
    {
        if ($transaction->isBankMode() && $transaction->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $transaction->bank_id)->first();
            if ($mapping) return (int) $mapping->ledger_id;
        }

        return $this->journalPosting->lookupLedgerByNature('cash_bank');
    }

    // ============================================================
    // EMPLOYEE LEDGER POSTING
    // ============================================================

    /**
     * Post employee_ledger entry based on transaction type.
     *
     * Outflow types (advance/loan/salary/adjustment): debit (employee owes more)
     * Inflow types (repayment/deduction): credit (employee owes less)
     */
    private function postEmployeeLedgerForType(EmployeeTransaction $transaction, int $journalEntryId, int $createdBy): void
    {
        $amount = (float) $transaction->amount;
        $transactionType = $transaction->transaction_type ?? 'advance';

        $isOutflow = in_array($transactionType, self::OUTFLOW_TYPES);

        $this->subLedger->postEmployeeLedgerEntry([
            'employee_id'      => $transaction->employee_id,
            'branch_id'        => $transaction->branch_id,
            'transaction_date' => $transaction->transaction_date ?? now()->format('Y-m-d'),
            'transaction_type' => $transactionType,
            'reference_type'   => 'employee_transaction',
            'reference_id'     => $transaction->id,
            'debit'            => $isOutflow ? $amount : 0,
            'credit'           => $isOutflow ? 0 : $amount,
            'description'      => $transaction->getTransactionTypeLabel() . ' — ' . $transaction->transaction_code,
            'journal_entry_id' => $journalEntryId,
            'created_by'       => $createdBy,
        ]);
    }

    // ============================================================
    // INTERCOMPANY SETTLEMENT
    // ============================================================

    /**
     * Post intercompany settlement for cross-branch bank-mode employee transactions.
     *
     * When an employee transaction is recorded at Branch A but the bank belongs
     * to Branch B, the interbranch obligation must be tracked. The accounting
     * direction depends on whether money flowed OUT or IN:
     *
     * OUTFLOW types (advance/loan/salary/adjustment) — bank DECREASES:
     *   Branch A used Branch B's bank to pay the employee → Branch A owes Branch B.
     *   Creditor JE (at bank's branch / Branch B):
     *     Dr interbranch_receivable (Due from Branches)   amount
     *        Cr bank-ledger (the bank that funded it)      amount
     *   Debtor JE (at transaction's branch / Branch A):
     *     Dr bank-ledger (clearing — nets the main GL bank credit)  amount
     *        Cr interbranch_payable (Due to Branches)               amount
     *
     * INFLOW type (repayment) — bank INCREASES:
     *   Branch A's employee repaid into Branch B's bank → Branch B owes Branch A.
     *   Creditor JE (at transaction's branch / Branch A):
     *     Dr interbranch_receivable (Due from Branches)   amount
     *        Cr bank-ledger (clearing — nets the main GL bank debit)   amount
     *   Debtor JE (at bank's branch / Branch B):
     *     Dr bank-ledger (the bank that received the funds)  amount
     *        Cr interbranch_payable (Due to Branches)         amount
     *
     * Net bank-ledger effect in both cases: main GL ± amount + creditor ± amount
     * + debtor ∓ amount = net ± amount (matches the bank book change from
     * syncBankBalance). interbranch_receivable (asset) ↑ at the creditor branch
     * and interbranch_payable (liability) ↑ at the debtor branch.
     *
     * Returns the debtor JE id (stored in employee_transactions.
     * intercompany_journal_entry_id for the show page). The creditor JE is
     * linked by reference_type='employee_transaction_intercompany' and is
     * reversed alongside the debtor in reverseTransaction().
     *
     * Skips (returns null) when:
     *   - amount < 0.01 or no bank_id
     *   - bank has no branch_id (NULL = shared / head-office bank)
     *   - bank's branch == transaction's branch (same branch, no intercompany)
     *   - interbranch ledgers not configured (logs a warning)
     */
    private function postIntercompanySettlement(EmployeeTransaction $transaction, int $createdBy): ?int
    {
        $amount = (float) $transaction->amount;
        if ($amount < 0.01 || !$transaction->bank_id) {
            return null;
        }

        // Load the bank with its branch_id (added by migration
        // 2026_08_06_000001_add_branch_id_to_banks). NULL = shared bank.
        $bank = Bank::find($transaction->bank_id);
        if (!$bank) {
            return null;
        }
        $bankBranchId = $bank->branch_id ? (int) $bank->branch_id : null;
        if (!$bankBranchId) {
            // Shared / head-office bank — no intercompany needed.
            return null;
        }

        $txnBranchId = (int) $transaction->branch_id;  // transaction's branch
        $bankBranchId2 = $bankBranchId;                 // bank's branch

        // Same branch — no intercompany needed.
        if ($bankBranchId2 === $txnBranchId) {
            return null;
        }

        // Resolve interbranch ledgers (seeded L-0105 / L-0303).
        $dueFromLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');
        $dueToLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        if (!$dueFromLedgerId || !$dueToLedgerId) {
            Log::warning('Intercompany ledgers not configured, skipping employee settlement', [
                'transaction_id'   => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'txn_branch_id'    => $txnBranchId,
                'bank_branch_id'   => $bankBranchId2,
            ]);
            return null;
        }

        // Resolve the bank's mapped ledger (falls back to cash_bank nature).
        $bankLedgerId = $transaction->isOutflow()
            ? $this->resolveCreditLedger($transaction)
            : $this->resolveDebitLedger($transaction);
        if (!$bankLedgerId) {
            Log::warning('Could not resolve bank ledger for employee intercompany settlement', [
                'transaction_id' => $transaction->id,
            ]);
            return null;
        }

        $entryDate = $transaction->transaction_date
            ? ($transaction->transaction_date instanceof \Carbon\Carbon
                ? $transaction->transaction_date->format('Y-m-d')
                : date('Y-m-d', strtotime((string) $transaction->transaction_date)))
            : now()->format('Y-m-d');
        $txnCode = $transaction->transaction_code;
        $isOutflow = $transaction->isOutflow();

        // For OUTFLOW: creditor = bank's branch, debtor = transaction's branch
        // For INFLOW:  creditor = transaction's branch, debtor = bank's branch
        $creditorBranchId = $isOutflow ? $bankBranchId2 : $txnBranchId;
        $debtorBranchId   = $isOutflow ? $txnBranchId   : $bankBranchId2;

        // 1. Creditor journal:
        //    Dr interbranch_receivable / Cr bank-ledger
        $creditorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'employee_transaction_intercompany',
            'reference_id'   => $transaction->id,
            'branch_id'      => $creditorBranchId,
            'description'    => "Intercompany — Employee transaction {$txnCode} (bank at branch {$bankBranchId2})",
            'source'         => 'employee_transaction_intercompany',
            'created_by'     => $createdBy,
        ], [
            [
                'ledger_id' => $dueFromLedgerId,
                'debit'     => $amount,
                'credit'    => 0,
                'memo'      => "Due from branch {$debtorBranchId} — employee transaction {$txnCode}",
            ],
            [
                'ledger_id' => $bankLedgerId,
                'debit'     => 0,
                'credit'    => $amount,
                'memo'      => ($isOutflow ? 'Bank funded' : 'Bank received') . " employee transaction {$txnCode} for branch {$debtorBranchId}",
            ],
        ]);

        // 2. Debtor journal:
        //    Dr bank-ledger / Cr interbranch_payable
        $debtorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'employee_transaction_intercompany',
            'reference_id'   => $transaction->id,
            'branch_id'      => $debtorBranchId,
            'description'    => "Intercompany — Employee transaction {$txnCode} (paid from branch {$txnBranchId})",
            'source'         => 'employee_transaction_intercompany',
            'created_by'     => $createdBy,
        ], [
            [
                'ledger_id' => $bankLedgerId,
                'debit'     => $amount,
                'credit'    => 0,
                'memo'      => "Bank clearing — employee transaction {$txnCode} via branch {$creditorBranchId} bank",
            ],
            [
                'ledger_id' => $dueToLedgerId,
                'debit'     => 0,
                'credit'    => $amount,
                'memo'      => "Due to branch {$creditorBranchId} — employee transaction {$txnCode}",
            ],
        ]);

        // 3. Record the interbranch obligation in branch_ledger.
        //    For OUTFLOW: from_branch = debtor (txn branch), to_branch = creditor (bank branch)
        //    For INFLOW:  from_branch = debtor (bank branch), to_branch = creditor (txn branch)
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => 'employee_transaction',
            'reference_id'     => $transaction->id,
            'debit'            => $amount,
            'credit'           => 0,
            'remarks'          => "Employee transaction {$txnCode} — bank at branch {$bankBranchId2}",
            'journal_entry_id' => $debtorJeId,
            'is_reversed'      => false,
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);

        Log::info('Employee transaction intercompany settlement posted', [
            'transaction_id'   => $transaction->id,
            'transaction_code' => $txnCode,
            'txn_branch_id'    => $txnBranchId,
            'bank_branch_id'   => $bankBranchId2,
            'amount'           => $amount,
            'direction'        => $isOutflow ? 'outflow' : 'inflow',
            'creditor_je_id'   => $creditorJeId,
            'debtor_je_id'     => $debtorJeId,
        ]);

        // Return the debtor JE id (primary, stored in intercompany_journal_entry_id).
        return $debtorJeId;
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank book balance (decrease for outflow, increase for inflow).
     */
    private function syncBankBalance(int $bankId, float $amount, string $transactionType, bool $undo = false): void
    {
        $isOutflow = in_array($transactionType, self::OUTFLOW_TYPES);
        $direction = $isOutflow ? -1 : 1;

        if ($undo) {
            $direction = $direction * -1; // Reverse the direction.
        }

        $delta = $amount * $direction;

        // FIX: banks table column is `balance` (numeric(18,2)), NOT
        // `current_balance`. The previous query referenced a non-existent
        // column and would throw a PostgreSQL error at runtime. The
        // SupplierTransactionService + CustomerPaymentService already use
        // the correct `balance` column via Eloquent increment/decrement.
        DB::table('banks')->where('id', $bankId)->update([
            'balance' => DB::raw("GREATEST(0, balance + {$delta})"),
            'updated_at' => now(),
        ]);

        Log::info("Employee transaction bank balance sync", [
            'bank_id' => $bankId,
            'delta'   => $delta,
            'type'    => $transactionType,
            'undo'    => $undo,
        ]);
    }

    // ============================================================
    // DOCUMENT SEQUENCE
    // ============================================================

    /**
     * Generate transaction code: ET-YYYY-NNNNN.
     */
    private function generateTransactionCode(string $type = 'advance'): string
    {
        return $this->sequenceService->next('ET');
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    /**
     * Validate input data for createTransaction.
     */
    private function validateCreateInput(array $data): void
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            throw new \RuntimeException('Employee ID is required.');
        }

        $employee = \App\Models\Employee::find($employeeId);
        if (!$employee || !$employee->is_active) {
            throw new \RuntimeException("Employee #{$employeeId} not found or inactive.");
        }

        $amount = (float) ($data['amount'] ?? 0);
        if ($amount < 0.01) {
            throw new \RuntimeException('Amount must be greater than 0.');
        }

        $type = $data['transaction_type'] ?? 'advance';
        if (!in_array($type, self::TRANSACTION_TYPES)) {
            throw new \RuntimeException("Invalid transaction type: {$type}.");
        }

        $paymentMode = $data['payment_mode'] ?? 'cash';
        if ($paymentMode === 'bank' && empty($data['bank_id'])) {
            throw new \RuntimeException('Bank ID is required when payment mode is bank.');
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
            Log::error("Failed to log employee transaction audit: {$e->getMessage()}");
        }
    }
}
