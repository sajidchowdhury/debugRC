<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Sales Return — Phase 8.5.
 *
 * Two-phase flow:
 *   1. create (status=created): NO stock movement, NO GL
 *   2. confirm (status=confirmed): stock IN at ORIGINAL avg_cost + GL
 *      (Dr Sales Return / Cr AR) + (Dr Inventory / Cr COGS)
 *   3. reverse: undoes confirm
 *
 * CRITICAL CORRECTNESS (per avg_cost_rule.md §3):
 *   Stock comes back at the ORIGINAL avg_cost at time of the challan's stock-out,
 *   NOT current avg_cost. This ensures:
 *   - The COGS reversal matches the original COGS exactly.
 *   - The avg_cost is restored to its pre-sale value.
 *
 * The original_cost is snapshotted from the stock_transaction for the challan
 * (reference_type='sales_challan', reference_id=challan_id, product_id).
 *
 * @property int $id
 * @property string $return_code
 * @property string $return_date
 * @property int $sales_invoice_id
 * @property int $customer_id
 * @property int $branch_id
 * @property string $total_amount (revenue reversal amount = Σ qty × sales_rate)
 * @property string $cogs_amount (COGS reversal amount = Σ qty × original_cost)
 * @property string $status created|confirmed|reversed
 * @property int|null $journal_entry_id (revenue reversal journal)
 * @property int|null $cogs_journal_entry_id (COGS reversal journal)
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $reason
 * @property int|null $created_by
 */
class SalesReturn extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'sales_returns';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'return_code', 'return_date', 'sales_invoice_id', 'customer_id', 'branch_id',
        'total_amount', 'cogs_amount', 'status',
        'journal_entry_id', 'cogs_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'reason', 'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'cogs_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'sales_invoice_id' => 'integer',
        'customer_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'cogs_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }

    public function salesInvoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function cogsJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'cogs_journal_entry_id');
    }

    public function isCreated(): bool { return $this->status === 'created'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isReversed(): bool { return $this->status === 'reversed' || $this->is_reversed; }
}
