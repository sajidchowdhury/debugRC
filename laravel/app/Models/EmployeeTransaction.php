<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Employee Transaction — Phase 2 (Accounts Sub-Ledger).
 *
 * Records money given to or received from an employee (advance, loan, salary,
 * repayment, deduction, adjustment). Lifecycle: create → confirm → reverse.
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
 *   1. GL: Type-specific journal entry (see EmployeeTransactionService)
 *   2. Employee ledger: debit entry for advance/loan/salary, credit for repayment/deduction
 *   3. Bank balance sync (if bank mode)
 *
 * Reversal is recorded via the `is_reversed` boolean flag (+ `reversed_at`,
 * `reversed_by`, `reverse_reason`). There is NO `status` enum column.
 *
 * @property int $id
 * @property string $transaction_code
 * @property string $transaction_date
 * @property int $employee_id
 * @property int $branch_id
 * @property string $payment_mode cash|bank|mobile_banking|cheque|adjustment
 * @property int|null $bank_id
 * @property string $transaction_type advance|loan|repayment|salary|deduction|adjustment
 * @property string $amount
 * @property string|null $description
 * @property int|null $collected_by
 * @property int|null $journal_entry_id
 * @property int|null $intercompany_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class EmployeeTransaction extends Model
{
    use AuditableMasterData;

    protected $table = 'employee_transactions';

    public $timestamps = true;

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
     * P0-8: Branch isolation global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'transaction_code', 'transaction_date', 'employee_id', 'branch_id',
        'payment_mode', 'bank_id', 'transaction_type', 'amount', 'description',
        'collected_by', 'journal_entry_id', 'intercompany_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'employee_id' => 'integer',
        'branch_id' => 'integer',
        'bank_id' => 'integer',
        'collected_by' => 'integer',
        'journal_entry_id' => 'integer',
        'intercompany_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function collectedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'collected_by');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Intercompany journal entry (cross-branch bank-mode settlement).
     * Populated by EmployeeTransactionService::postIntercompanySettlement()
     * when an employee transaction uses a bank that belongs to a different
     * branch. NULL for cash-mode, same-branch, shared-bank, or deduction
     * (non-cash) transactions.
     */
    public function intercompanyJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'intercompany_journal_entry_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeNotReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_reversed', false);
    }

    public function scopeByBranch(\Illuminate\Database\Eloquent\Builder $query, int $branchId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('branch_id', $branchId);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public function isBankMode(): bool
    {
        return $this->payment_mode === 'bank' && $this->bank_id !== null;
    }

    public function isOutflow(): bool
    {
        return in_array($this->transaction_type ?? 'advance', self::OUTFLOW_TYPES);
    }

    public function isInflow(): bool
    {
        return in_array($this->transaction_type, self::INFLOW_TYPES);
    }

    /**
     * Get human-readable label for the transaction type.
     */
    public function getTransactionTypeLabel(): string
    {
        return [
            'advance'    => 'Employee Advance',
            'loan'       => 'Employee Loan',
            'salary'     => 'Salary Payment',
            'repayment'  => 'Employee Repayment',
            'deduction'  => 'Deduction',
            'adjustment' => 'Adjustment',
        ][$this->transaction_type ?? 'advance'] ?? ucfirst($this->transaction_type ?? 'advance');
    }

    /**
     * Get the GL description for this transaction type.
     */
    public function getGlDescription(): string
    {
        return [
            'advance'    => 'Dr Employee Payable · Cr Bank/Cash',
            'loan'       => 'Dr Employee Payable · Cr Bank/Cash',
            'salary'     => 'Dr Salary Expense · Cr Bank/Cash',
            'repayment'  => 'Dr Bank/Cash · Cr Employee Payable',
            'deduction'  => 'Dr Salary Expense · Cr Employee Payable',
            'adjustment' => 'Dr/Cr varies by context',
        ][$this->transaction_type ?? 'advance'] ?? 'Dr Employee Payable · Cr Bank/Cash';
    }
}
