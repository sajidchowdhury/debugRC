<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Traits\ApplySystemPolicyScope;
use App\Models\Scopes\BranchScope;

/**
 * Supplier Payment — Phase 1 (Accounts Sub-Ledger).
 *
 * Records money paid to a supplier (or advance given, or goods received on credit).
 * Lifecycle: create → confirm → reverse.
 *
 * Transaction types (transaction_type column, CHECK constraint):
 *   - payment:  Paying a supplier → Dr AP, Cr Bank/Cash
 *   - advance:  Advance payment to supplier → Dr AP, Cr Bank/Cash
 *   - receive:  Goods received on credit → Dr Inventory, Cr AP
 *
 * On confirm (atomic operations):
 *   1. GL: Type-specific journal entry (see SupplierTransactionService)
 *   2. Supplier ledger: debit entry for payment/advance, credit for receive
 *   3. GRN allocation (optional): supplier_payment_settlements
 *   4. Bank balance sync (if bank mode)
 *   5. Intercompany settlement (if bank-mode + cross-branch): branch_ledger entry
 *
 * Reversal is recorded via the `is_reversed` boolean flag (+ `reversed_at`,
 * `reversed_by`, `reverse_reason`). There is NO `status` enum column.
 *
 * @property int $id
 * @property string $payment_code
 * @property string $payment_date
 * @property int $supplier_id
 * @property int $branch_id
 * @property int|null $bank_id
 * @property string $payment_mode cash|bank|mobile_banking|cheque|adjustment
 * @property string $transaction_type payment|advance|receive
 * @property string $amount
 * @property string $discount_amount
 * @property string|null $reference_no
 * @property int|null $collected_by
 * @property int|null $journal_entry_id
 * @property int|null $intercompany_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
class SupplierPayment extends Model
{
    use SoftDeletes, AuditableMasterData, ApplySystemPolicyScope;

    protected $table = 'supplier_payments';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Valid transaction types (matches DB CHECK constraint).
     */
    public const TRANSACTION_TYPES = ['payment', 'advance', 'receive'];

    /**
     * Transaction types that reduce AP (we owe less → debit supplier_ledger).
     */
    public const AP_REDUCTION_TYPES = ['payment', 'advance'];

    /**
     * Transaction types that increase AP (we owe more → credit supplier_ledger).
     */
    public const AP_INCREASE_TYPES = ['receive'];

    /**
     * P0-8: Branch isolation global scope.
     * Non-admin users only see payments from their session branch_id.
     * Admin/superadmin bypass (see all branches).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * G-171 (AUDIT-TRAIL-2): the date column clamped by INVESTIGATION mode.
     */
    protected function policyDateColumn(): string
    {
        return 'payment_date';
    }

    protected $fillable = [
        'payment_code', 'payment_date', 'supplier_id', 'branch_id',
        'bank_id', 'payment_mode', 'transaction_type', 'amount', 'discount_amount',
        'reference_no', 'collected_by', 'journal_entry_id', 'intercompany_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'supplier_id' => 'integer',
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

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
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

    public function intercompanyJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'intercompany_journal_entry_id');
    }

    /**
     * GRN allocations for this payment.
     * Links to supplier_payment_settlements (purchase_receive allocations).
     */
    public function settlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierPaymentSettlement::class, 'payment_id');
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

    public function isPayment(): bool
    {
        return ($this->transaction_type ?? 'payment') === 'payment';
    }

    public function isAdvance(): bool
    {
        return $this->transaction_type === 'advance';
    }

    public function isReceive(): bool
    {
        return $this->transaction_type === 'receive';
    }

    public function isApReduction(): bool
    {
        return in_array($this->transaction_type ?? 'payment', self::AP_REDUCTION_TYPES);
    }

    /**
     * Get human-readable label for the transaction type.
     */
    public function getTransactionTypeLabel(): string
    {
        return [
            'payment' => 'Supplier Payment',
            'advance' => 'Advance Payment',
            'receive' => 'Goods Received on Credit',
        ][$this->transaction_type ?? 'payment'] ?? ucfirst($this->transaction_type ?? 'payment');
    }

    /**
     * Get the GL description for this transaction type.
     */
    public function getGlDescription(): string
    {
        return [
            'payment' => 'Dr AP · Cr Bank/Cash',
            'advance' => 'Dr AP · Cr Bank/Cash',
            'receive' => 'Dr Inventory · Cr AP',
        ][$this->transaction_type ?? 'payment'] ?? 'Dr AP · Cr Bank/Cash';
    }
}
