<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Customer Payment — Phase 8.4.
 *
 * Records money received from a customer. Two-phase: draft → confirm → cancel.
 *
 * On confirm:
 *   1. GL: Dr Bank/Cash / Cr Accounts Receivable
 *      - Bank mode: Dr Bank Ledger (via bank_ledger_mappings) / Cr AR
 *      - Cash mode: Dr Cash Ledger / Cr AR
 *   2. Customer ledger: credit entry (customer owes less)
 *   3. Invoice allocation (if against a specific invoice): invoice_payment_allocations
 *   4. Invoice paid_amount updated
 *   5. Intercompany settlement (if bank-mode + cross-branch): branch_ledger entry
 *
 * @property int $id
 * @property string $payment_code
 * @property string $payment_date
 * @property int $customer_id
 * @property int $branch_id
 * @property int|null $bank_id
 * @property string $payment_mode cash|bank|mobile_banking|cheque|adjustment
 * @property string $amount
 * @property string $discount_amount
 * @property string|null $reference_no
 * @property int|null $journal_entry_id
 * @property int|null $intercompany_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
class CustomerPayment extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'customer_payments';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * P0-8: Branch isolation global scope.
     * Non-admin users only see payments from their session branch_id.
     * Admin/superadmin bypass (see all branches).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'payment_code', 'payment_date', 'customer_id', 'branch_id',
        'bank_id', 'payment_mode', 'amount', 'discount_amount',
        'reference_no', 'transaction_type', 'journal_entry_id', 'intercompany_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'customer_id' => 'integer',
        'branch_id' => 'integer',
        'bank_id' => 'integer',
        'journal_entry_id' => 'integer',
        'intercompany_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

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

    public function intercompanyJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'intercompany_journal_entry_id');
    }

    /**
     * Invoice allocations for this payment (P1-4: consolidated to invoice_payment_allocations).
     * Previously used customer_payment_settlements (dropped — it was never populated).
     */
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoicePaymentAllocation::class, 'payment_id');
    }

    /**
     * Backward-compatible alias for allocations().
     * Views/controllers that used ->settlements will still work.
     */
    public function settlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->allocations();
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isBankMode(): bool { return $this->payment_mode === 'bank' && $this->bank_id !== null; }

    /**
     * Transaction type helpers (P2-5).
     */
    public function isReceive(): bool { return ($this->transaction_type ?? 'receive') === 'receive'; }
    public function isDiscount(): bool { return $this->transaction_type === 'discount'; }
    public function isWriteOff(): bool { return $this->transaction_type === 'write_off'; }
    public function isRefund(): bool { return $this->transaction_type === 'payment'; }
    public function isArReduction(): bool { return in_array($this->transaction_type ?? 'receive', ['receive', 'discount', 'write_off']); }

    /**
     * Get human-readable label for the transaction type.
     */
    public function getTransactionTypeLabel(): string
    {
        return [
            'receive' => 'Payment Received',
            'discount' => 'Discount Allowed',
            'write_off' => 'Bad Debt Write-off',
            'payment' => 'Refund',
        ][$this->transaction_type ?? 'receive'] ?? ucfirst($this->transaction_type ?? 'receive');
    }

    /**
     * Get the GL description for this transaction type.
     */
    public function getGlDescription(): string
    {
        return [
            'receive' => 'Dr Bank/Cash · Cr AR',
            'discount' => 'Dr Sales Discount · Cr AR',
            'write_off' => 'Dr Bad Debt Expense · Cr AR',
            'payment' => 'Dr AR · Cr Bank/Cash',
        ][$this->transaction_type ?? 'receive'] ?? 'Dr Bank/Cash · Cr AR';
    }
}
