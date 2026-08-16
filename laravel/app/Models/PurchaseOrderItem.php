<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Purchase Order Item — Phase 7.1.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property string $qty  ordered quantity
 * @property string $received_qty  quantity received via GRN (Phase 7.2)
 * @property string $rate  unit purchase rate
 * @property string $amount GENERATED: qty × rate
 */
class PurchaseOrderItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'purchase_order_items';

    public $timestamps = false;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'qty',
        'received_qty',
        'rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function purchaseOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Remaining qty to receive = qty - received_qty.
     */
    public function remainingQty(): float
    {
        return max(0, (float) $this->qty - (float) $this->received_qty);
    }

    /**
     * Is this line fully received?
     */
    public function isFullyReceived(): bool
    {
        return (float) $this->received_qty >= (float) $this->qty - 0.0001;
    }
}
