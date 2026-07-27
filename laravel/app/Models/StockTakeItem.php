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
 * @property string $rate  Phase 9: post-time avg_cost used for GL valuation (written at post, not setup)
 * @property string|null $reason  per-line reason (e.g. "damaged", "lost", "found")
 * @property bool $is_applied  true after postSession applies the variance
 * @property int|null $journal_line_id  Phase 1: per-line GL traceability (links to journal_lines.id)
 * @property int $branch_id  Phase 8: denormalized from stock_take_sessions.branch_id for RLS
 * @property string|null $system_rate  Phase 9: setup-time avg cost (snapshot, never updated)
 * @property string|null $post_rate  Phase 9: post-time avg cost (re-fetched at post)
 * @property string $revaluation_amount  Phase 9: (post_rate - system_rate) * physical_qty when drift > epsilon, else 0
 * @property int|null $revaluation_line_id  Phase 9: per-line GL traceability for the revaluation entry
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
        'journal_line_id',
        // Phase 8: denormalized branch_id for RLS (set at insert, never updated).
        'branch_id',
        // Phase 9: costing columns — system_rate (setup snapshot, immutable),
        // post_rate (post-time avg cost), revaluation_amount + revaluation_line_id
        // (the cost-drift adjusting entry).
        'system_rate',
        'post_rate',
        'revaluation_amount',
        'revaluation_line_id',
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
        'journal_line_id' => 'integer',
        'branch_id' => 'integer',
        // Phase 9: costing columns — 6-decimal precision for avg-cost math.
        'system_rate' => 'decimal:6',
        'post_rate' => 'decimal:6',
        'revaluation_amount' => 'decimal:6',
        'revaluation_line_id' => 'integer',
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
