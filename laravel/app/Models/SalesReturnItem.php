<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Return Item — Phase 8.5.
 *
 * @property int $id
 * @property int $sales_return_id
 * @property int $sales_invoice_item_id
 * @property int $product_id
 * @property int|null $warehouse_id
 * @property string $qty
 * @property string $rate (sales rate — for revenue reversal)
 * @property string $amount GENERATED: qty × rate
 * @property string $original_cost (ORIGINAL avg_cost at time of challan — for COGS reversal + stock IN)
 */
class SalesReturnItem extends Model
{
    protected $table = 'sales_return_items';

    public $timestamps = false;

    protected $fillable = [
        'sales_return_id', 'sales_invoice_item_id', 'product_id', 'warehouse_id',
        'qty', 'rate', 'original_cost', 'damage_invoice_id',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'original_cost' => 'decimal:2',
        'sales_return_id' => 'integer',
        'sales_invoice_item_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function salesReturn(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
