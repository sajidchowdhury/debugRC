<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Purchase Order — Phase 7.1.
 *
 * A PO is a draft document — NO stock movement, NO GL journal.
 * The economic event is the GRN (Phase 7.2) which receives stock + posts GL.
 *
 * Status flow:
 *   draft → sent → partial → received → cancelled
 *   - draft: created but not sent to supplier
 *   - sent: sent to supplier, awaiting delivery
 *   - partial: some items received via GRN
 *   - received: all items fully received
 *   - cancelled: cancelled (only draft/sent can be cancelled)
 *
 * The `received_qty` on items tracks how much has been received via GRN.
 * When received_qty >= qty for all items → status auto-updates to 'received'.
 * When some but not all → 'partial'.
 *
 * @property int $id
 * @property string $po_code
 * @property string $po_date
 * @property int $supplier_id
 * @property int $branch_id
 * @property int|null $warehouse_id
 * @property string $sub_total
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string $status draft|sent|partial|received|cancelled
 * @property string|null $expected_date
 * @property string|null $notes
 * @property int|null $created_by
 */
class PurchaseOrder extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'purchase_orders';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'po_code',
        'po_date',
        'supplier_id',
        'branch_id',
        'warehouse_id',
        'sub_total',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'expected_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'expected_date' => 'date',
        'sub_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
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

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSent(): bool { return $this->status === 'sent'; }
    public function isPartial(): bool { return $this->status === 'partial'; }
    public function isReceived(): bool { return $this->status === 'received'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    /**
     * Can this PO be edited? (only draft)
     */
    public function canEdit(): bool { return $this->isDraft(); }

    /**
     * Can this PO be cancelled? (only draft or sent)
     */
    public function canCancel(): bool { return $this->isDraft() || $this->isSent(); }

    /**
     * Can this PO receive goods (GRN)? (sent or partial, not cancelled/received)
     */
    public function canReceive(): bool { return $this->isSent() || $this->isPartial(); }
}
