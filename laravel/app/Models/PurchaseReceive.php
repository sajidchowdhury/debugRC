<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Purchase Receive (GRN) — Phase 7.2.
 *
 * The economic event of the purchase module. When goods are received:
 *   - Stock IN via StockService (reference_type='purchase_receive', rate=purchase rate)
 *     → avg_cost recalculated (weighted average of existing + new)
 *   - GL: Dr Inventory / Cr Accounts Payable
 *   - Supplier ledger: credit entry (we owe the supplier more)
 *   - PO received_qty updated (auto-updates PO status to partial/received)
 *
 * Two-phase flow:
 *   1. Create (draft): header + items, no stock, no GL, no supplier_ledger
 *   2. Confirm: applies all of the above
 *   3. Cancel: if confirmed, reverses everything; if draft, marks cancelled
 *
 * Can be against a PO (purchase_order_id set) or direct (purchase_order_id null).
 *
 * @property int $id
 * @property string $receive_code
 * @property string $receive_date
 * @property int|null $purchase_order_id
 * @property int $supplier_id
 * @property int $branch_id
 * @property int $warehouse_id
 * @property string $sub_total
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
class PurchaseReceive extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'purchase_receives';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'receive_code',
        'receive_date',
        'purchase_order_id',
        'supplier_id',
        'branch_id',
        'warehouse_id',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'receive_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'purchase_order_id' => 'integer',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseReceiveItem::class, 'purchase_receive_id');
    }

    public function purchaseOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    /**
     * Is this a direct receive (no PO)?
     */
    public function isDirect(): bool { return $this->purchase_order_id === null; }
}
