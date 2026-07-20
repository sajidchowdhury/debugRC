<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock Adjustment Item — Phase 6.3.
 * One row per product in a stock adjustment.
 *
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $product_id
 * @property string $qty
 * @property string $rate
 * @property string|null $reason
 */
class StockAdjustmentItem extends Model
{
    protected $table = 'stock_adjustment_items';

    public $timestamps = false;

    protected $fillable = [
        'stock_adjustment_id',
        'product_id',
        'qty',
        'rate',
        'reason',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
    ];

    public function adjustment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Line amount = qty × rate.
     */
    public function amount(): float
    {
        return (float) $this->qty * (float) $this->rate;
    }
}
