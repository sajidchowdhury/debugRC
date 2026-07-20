<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Purchase Receive Item — Phase 7.2.
 *
 * @property int $id
 * @property int $purchase_receive_id
 * @property int|null $purchase_order_item_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $qty
 * @property string $return_qty
 * @property string $rate
 * @property string $amount GENERATED: qty × rate
 */
class PurchaseReceiveItem extends Model
{
    protected $table = 'purchase_receive_items';

    public $timestamps = false;

    protected $fillable = [
        'purchase_receive_id',
        'purchase_order_item_id',
        'product_id',
        'warehouse_id',
        'qty',
        'return_qty',
        'rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'return_qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'purchase_receive_id' => 'integer',
        'purchase_order_item_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function receive(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseReceive::class, 'purchase_receive_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function amount(): float
    {
        return (float) $this->qty * (float) $this->rate;
    }
}
