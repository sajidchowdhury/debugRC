<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse Stock — the current on-hand balance with moving-average cost.
 *
 * Phase 6.1: COMPOSITE PRIMARY KEY (warehouse_id, product_id) — NO `id` column.
 * This is the inventory state table; the composite PK enforces uniqueness naturally.
 *
 * The `avg_cost` is maintained by StockService::applyTransaction() using the
 * perpetual moving-average method (see docs/migration/avg_cost_rule.md).
 *
 * DB-level invariants (from Phase 2 schema):
 *   - CHECK (qty >= -0.0001) — non-negative with FP tolerance
 *   - Trigger prevent_negative_stock() raises business-friendly error message
 *
 * @property int $warehouse_id
 * @property int $product_id
 * @property string $qty
 * @property string $avg_cost
 */
class WarehouseStock extends Model
{
    protected $table = 'warehouse_stock';

    public $timestamps = true;

    protected $primaryKey = null; // Composite PK — no single id
    public $incrementing = false;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'qty',
        'avg_cost',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'avg_cost' => 'decimal:2',
    ];

    /**
     * Composite key for route model binding.
     */
    public function getKey(): string
    {
        return "{$this->warehouse_id}-{$this->product_id}";
    }

    // ===================== RELATIONSHIPS =====================

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    // ===================== HELPERS =====================

    /**
     * Total stock value = qty × avg_cost.
     */
    public function stockValue(): float
    {
        return (float) $this->qty * (float) $this->avg_cost;
    }

    /**
     * Get or create a warehouse_stock row for the given warehouse + product.
     * Does NOT lock — caller must use StockService for locked access.
     */
    public static function getOrCreate(int $warehouseId, int $productId): self
    {
        return self::firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            ['qty' => 0, 'avg_cost' => 0]
        );
    }
}
