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
        'reference_no', 'journal_entry_id', 'intercompany_journal_entry_id',
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

    public function settlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerPaymentSettlement::class, 'payment_id');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isBankMode(): bool { return $this->payment_mode === 'bank' && $this->bank_id !== null; }
}
