<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Sales Invoice — Phase 8.2.
 *
 * Workflow:
 *   draft → confirmed (godown prep, Phase 8.3) → challan_issued (stock OUT, Phase 8.3)
 *   → paid (payment received, Phase 8.4) → reversed/cancelled
 *
 * On finalize (cart → invoice):
 *   - sales_invoice created (status=draft)
 *   - sales_invoice_items created (from cart items, warehouse_id=NULL — assigned at godown)
 *   - sales_invoice_dispatches created (ordered_qty, dispatched_qty=0, warehouse_id=NULL)
 *   - customer_ledger: debit entry (customer owes us more)
 *   - GL: Dr Accounts Receivable / Cr Sales Revenue (+ Dr Discount / Cr Transport if applicable)
 *   - Cart cleared
 *
 * Credit limit: checked before finalize. If exceeded, requires override reason.
 *
 * @property int $id
 * @property string $invoice_code
 * @property string $invoice_date
 * @property int $customer_id
 * @property int|null $salesman_id
 * @property string|null $sales_person
 * @property int $branch_id
 * @property string $sub_total
 * @property string $discount_amount
 * @property string $transport_cost
 * @property string $total_amount
 * @property string $paid_amount
 * @property string $due_amount
 * @property string $payment_mode
 * @property string $status draft|confirmed|cancelled|reversed
 * @property bool $is_godown_prepared
 * @property string|null $godown_prepared_at
 * @property bool $is_challan_issued
 * @property string|null $challan_issued_at
 * @property int|null $journal_entry_id
 * @property int|null $cogs_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property bool $is_soft_hold
 * @property string|null $notes
 * @property int|null $created_by
 */
class SalesInvoice extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'sales_invoices';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * P0-8: Branch isolation global scope.
     * Non-admin users only see invoices from their session branch_id.
     * Admin/superadmin bypass (see all branches).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'invoice_code', 'invoice_date', 'customer_id', 'salesman_id', 'sales_person',
        'branch_id', 'sub_total', 'discount_amount', 'transport_cost', 'total_amount',
        'paid_amount', 'due_amount', 'payment_mode', 'status',
        'is_godown_prepared', 'godown_prepared_at',
        'is_challan_issued', 'challan_issued_at',
        'journal_entry_id', 'cogs_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'is_soft_hold', 'notes', 'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'is_godown_prepared' => 'boolean',
        'is_challan_issued' => 'boolean',
        'is_reversed' => 'boolean',
        'is_soft_hold' => 'boolean',
        'godown_prepared_at' => 'datetime',
        'challan_issued_at' => 'datetime',
        'reversed_at' => 'datetime',
        'customer_id' => 'integer',
        'salesman_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'cogs_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id');
    }

    public function dispatches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoiceDispatch::class, 'sales_invoice_id');
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function salesman(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isReversed(): bool { return $this->status === 'reversed' || $this->is_reversed; }
}
