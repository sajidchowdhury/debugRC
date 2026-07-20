<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Purchase Return — Phase 7.3.
 *
 * Returns goods to a supplier. Always against a GRN (purchase_receive_id).
 * Two-phase: draft → confirm → cancel.
 *
 * On confirm:
 *   - Stock OUT via StockService at ORIGINAL receive rate (not current avg_cost)
 *   - GL: Dr Accounts Payable / Cr Inventory (reverse of GRN)
 *   - Supplier ledger: debit entry (we owe the supplier less)
 *   - GRN item return_qty updated (tracks cumulative returns)
 *
 * Return qty cap: returnable_qty = received_qty - already_returned.
 *
 * @property int $id
 * @property string $return_code
 * @property string $return_date
 * @property int $purchase_receive_id
 * @property int $supplier_id
 * @property int $branch_id
 * @property string $total_amount
 * @property string $status draft|confirmed|cancelled
 * @property int|null $journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $reason
 * @property int|null $created_by
 */
class PurchaseReturn extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'purchase_returns';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'return_code',
        'return_date',
        'purchase_receive_id',
        'supplier_id',
        'branch_id',
        'total_amount',
        'status',
        'journal_entry_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'purchase_receive_id' => 'integer',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'purchase_return_id');
    }

    public function purchaseReceive(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseReceive::class, 'purchase_receive_id');
    }

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
