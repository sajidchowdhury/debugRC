<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Purchase Return Item — Phase 7.3.
 *
 * @property int $id
 * @property int $purchase_return_id
 * @property int|null $purchase_receive_item_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $qty
 * @property string $rate
 * @property string $amount GENERATED: qty × rate
 */
class PurchaseReturnItem extends Model
{
    protected $table = 'purchase_return_items';

    public $timestamps = false;

    protected $fillable = [
        'purchase_return_id',
        'purchase_receive_item_id',
        'product_id',
        'warehouse_id',
        'qty',
        'rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'purchase_return_id' => 'integer',
        'purchase_receive_item_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function return(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
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
