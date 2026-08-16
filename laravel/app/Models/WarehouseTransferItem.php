<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse Transfer Item — Phase 6.5.
 * One row per product in a warehouse transfer.
 *
 * @property int $id
 * @property int $warehouse_transfer_id
 * @property int $product_id
 * @property string $qty
 * @property string $rate
 */
class WarehouseTransferItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'warehouse_transfer_items';

    public $timestamps = false;

    protected $fillable = [
        'warehouse_transfer_id',
        'product_id',
        'qty',
        'rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
    ];

    public function transfer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'warehouse_transfer_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function amount(): float
    {
        return (float) $this->qty * (float) $this->rate;
    }
}
