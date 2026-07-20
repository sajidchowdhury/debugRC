<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Employee Ledger — Phase 9.3.
 *
 * Sub-ledger for Employee Payable. Tracks advances, loans, repayments,
 * salary, deductions, and adjustments.
 *
 * Running balance: credit increases what we owe employee, debit decreases.
 *   balance = previous_balance + credit - debit
 *
 * Transaction types (CHECK constraint): advance, loan, repayment, salary,
 * deduction, adjustment.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $branch_id
 * @property string $transaction_date
 * @property string $transaction_type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $debit
 * @property string $credit
 * @property string $balance
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $description
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 */
class EmployeeLedger extends Model
{
    protected $table = 'employee_ledger';

    public $timestamps = false;

    protected $fillable = [
        'employee_id', 'branch_id', 'transaction_date', 'transaction_type',
        'reference_type', 'reference_id', 'debit', 'credit', 'balance',
        'is_reversed', 'reversed_at', 'reversed_by',
        'description', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'employee_id' => 'integer',
        'branch_id' => 'integer',
        'reference_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public const TRANSACTION_TYPES = ['advance', 'loan', 'repayment', 'salary', 'deduction', 'adjustment'];

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function scopeNotReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Get the current employee payable balance (what we owe = credit - debit).
     */
    public static function getBalance(int $employeeId): float
    {
        return (float) self::where('employee_id', $employeeId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS balance')
            ->value('balance');
    }
}
