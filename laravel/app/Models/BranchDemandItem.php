<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Branch Demand Item — one row per product in a branch demand.
 *
 * At creation time: only product_id + qty are set.
 * At send time: from_warehouse_id, to_warehouse_id, cost_rate, and price range are set.
 *
 * @property int $id
 * @property int $branch_demand_id
 * @property int $product_id
 * @property string $qty
 * @property string $cost_rate Locked avg_cost at send time (numeric 12,4)
 * @property int|null $from_warehouse_id Sender warehouse (set at send time)
 * @property int|null $to_warehouse_id Receiver warehouse (set at send time)
 * @property string $price_min Minimum price at send time
 * @property string $price_max Maximum price at send time
 * @property string $price_default Default price at send time
 * @property string $consumed_qty S7: how much of this demand item has been sold by the receiving branch
 * @property string|null $consumed_qty_updated_at S7: last time consumed_qty was bumped
 * @property int|null $receiving_branch_id S7: denormalized from branch_demands.from_branch_id for FIFO hot path
 * @property string|null $notes
 */
class BranchDemandItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'branch_demand_items';

    // G-352 (G27) FINANCE-BD-1: timestamps now enabled. Migration
    // 2026_09_06_000009 added created_at + updated_at columns. Existing rows
    // were backfilled with created_at = NOW(). Note: service-layer code that
    // uses DB::table('branch_demand_items')->update() bypasses Eloquent
    // timestamps — those call sites need to manually set 'updated_at' => now()
    // if per-item modification tracking is needed for forensics.
    protected $fillable = [
        'branch_demand_id',
        'product_id',
        'qty',
        'cost_rate',
        'from_warehouse_id',
        'to_warehouse_id',
        'price_min',
        'price_max',
        'price_default',
        // S7: FIFO consumed_qty + receiving_branch_id denormalization.
        'consumed_qty',
        'consumed_qty_updated_at',
        'receiving_branch_id',
        'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'cost_rate' => 'decimal:4',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'price_default' => 'decimal:2',
        'consumed_qty' => 'decimal:3',
        'branch_demand_id' => 'integer',
        'product_id' => 'integer',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'receiving_branch_id' => 'integer',
    ];

    // ===================== RELATIONSHIPS =====================

    public function demand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'branch_demand_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function fromWarehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    // ===================== HELPERS =====================

    /**
     * Line total = qty × cost_rate.
     */
    public function lineTotal(): float
    {
        return (float) $this->qty * (float) $this->cost_rate;
    }

    /**
     * Has this item been sent? (from_warehouse_id is set)
     */
    public function isSent(): bool
    {
        return $this->from_warehouse_id !== null;
    }

    /**
     * Is the sale price within the recorded price range?
     */
    public function isPriceInRange(float $salePrice): bool
    {
        $min = (float) $this->price_min;
        $max = (float) $this->price_max;

        if ($min <= 0 && $max <= 0) {
            return true; // No range recorded
        }

        return $salePrice >= $min && $salePrice <= $max;
    }
}
