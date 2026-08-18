<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Other Expense — Phase 5 (Accounts Sub-Ledger).
 *
 * Records non-operational expenses (bank charges, rent paid, utilities, etc.).
 * Lifecycle: create → reverse.
 *
 * GL posting:
 *   Dr Operating Expense head (user-selected ledger) / Cr Cash/Bank
 *
 * @property int $id
 * @property string $expense_code
 * @property string $expense_date
 * @property int $branch_id
 * @property string $payment_mode cash|bank|mobile_banking|cheque
 * @property int|null $bank_id
 * @property string|null $expense_type
 * @property string $amount
 * @property string|null $description
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 */
class OtherExpense extends Model
{
    use AuditableMasterData, BelongsToFiscalYear;

    protected $table = 'other_expenses';

    public $timestamps = true;

    /**
     * P0-8: Branch isolation global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'fiscal_year_id',
        'expense_code', 'expense_date', 'branch_id', 'payment_mode',
        'bank_id', 'expense_type', 'amount', 'description',
        'journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount'       => 'decimal:2',
        'is_reversed'  => 'boolean',
        'reversed_at'  => 'datetime',
        'branch_id'    => 'integer',
        'bank_id'      => 'integer',
        'journal_entry_id' => 'integer',
        'created_by'   => 'integer',
        'reversed_by'  => 'integer',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
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

    /**
     * Get the GL description for this expense.
     */
    public function getGlDescription(): string
    {
        return 'Dr Operating Expense · Cr Cash/Bank';
    }
}
