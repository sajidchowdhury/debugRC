<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock Take Item — Phase 6.4.
 * One row per (session, warehouse, product) with system_qty, physical_qty,
 * and the GENERATED difference column.
 *
 * @property int $id
 * @property int $stock_take_session_id
 * @property int $warehouse_id
 * @property int $product_id
 * @property string $system_qty  qty at time of count setup
 * @property string $physical_qty  counted qty entered by user
 * @property string $difference GENERATED: physical_qty - system_qty
 * @property string $rate  avg_cost at time of posting (for GL valuation)
 * @property string|null $reason  per-line reason (e.g. "damaged", "lost", "found")
 * @property bool $is_applied  true after postSession applies the variance
 */
class StockTakeItem extends Model
{
    protected $table = 'stock_take_items';

    public $timestamps = false;

    protected $fillable = [
        'stock_take_session_id',
        'warehouse_id',
        'product_id',
        'system_qty',
        'physical_qty',
        'rate',
        'reason',
        'is_applied',
        'updated_at',
    ];

    protected $casts = [
        'stock_take_session_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'system_qty' => 'decimal:4',
        'physical_qty' => 'decimal:4',
        'difference' => 'decimal:4',
        'rate' => 'decimal:2',
        'is_applied' => 'boolean',
    ];

    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StockTakeSession::class, 'stock_take_session_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Is there a variance (physical ≠ system)?
     */
    public function hasVariance(): bool
    {
        return abs((float) $this->physical_qty - (float) $this->system_qty) > 0.0001;
    }

    /**
     * Value of the variance = difference × rate.
     */
    public function varianceValue(): float
    {
        return (float) $this->difference * (float) $this->rate;
    }
}
