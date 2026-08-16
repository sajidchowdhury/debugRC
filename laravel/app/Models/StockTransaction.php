<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock Transaction — the immutable inventory ledger (SSOT) + Task 34 (partitioned).
 *
 * Phase 6.1: Every stock movement (in or out) is recorded here as an
 * append-only row. The signed `qty` (positive = IN, negative = OUT) and
 * snapshotted `rate` make this the complete audit trail of inventory.
 *
 * The `warehouse_stock` table holds the current balance (qty + avg_cost);
 * this table holds the history. warehouse_stock is derived from the sum
 * of stock_transactions (re-playable from scratch — see StockService::replay).
 *
 * Reversals: a reversal is a NEW row with opposite-sign qty and
 * reference_type='reversal'. The original is marked is_reversed=true.
 * Originals are never mutated (append-only ledger).
 *
 * PARTITIONING (Task 34):
 *   Table is PARTITION BY RANGE (transaction_date), monthly partitions.
 *   PRIMARY KEY is (id, transaction_date) — both columns needed for partition routing.
 *   Self-referential FK (reversal_of_transaction_id) uses trigger-based enforcement
 *   (trg_st_reversal_fk → fn_st_reversal_fk_check) because PG 12-17 does not
 *   support FK references TO partitioned tables.
 *
 * Performance note: For partition pruning, include transaction_date in WHERE
 * clauses when querying by date range. Simple find($id) still works (PG routes
 * automatically via the partitioned PK).
 *
 * @property int $id
 * @property string $transaction_date
 * @property int $warehouse_id
 * @property int $product_id
 * @property string $qty Signed: positive = IN, negative = OUT
 * @property string $rate Unit cost snapshotted at transaction time
 * @property string $total_value GENERATED: qty × rate
 * @property string $reference_type One of: purchase_receive, purchase_return, sales_challan, sales_return, stock_adjustment, stock_take, warehouse_transfer, damage, branch_demand, opening_balance, reversal
 * @property int $reference_id
 * @property int|null $branch_demand_item_id
 * @property string|null $notes
 * @property bool $is_reversed
 * @property int|null $reversal_of_transaction_id
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property int|null $created_by
 * @property string $created_at
 */
class StockTransaction extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'stock_transactions';

    public $timestamps = false;

    protected $fillable = [
        'transaction_date',
        'warehouse_id',
        'product_id',
        'qty',
        'rate',
        'reference_type',
        'reference_id',
        'branch_demand_item_id',
        'notes',
        'is_reversed',
        'reversal_of_transaction_id',
        'reversed_at',
        'reversed_by',
        'reverse_reason',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'qty' => 'decimal:4',
        'rate' => 'decimal:2',
        'total_value' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'created_at' => 'datetime',
        'branch_demand_item_id' => 'integer',
        'reversal_of_transaction_id' => 'integer',
        'reversed_by' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * The 11 valid reference types (plus 'reversal' for internal use).
     */
    public const REFERENCE_TYPES = [
        'purchase_receive',
        'purchase_return',
        'sales_challan',
        'sales_return',
        'stock_adjustment',
        'stock_take',
        'warehouse_transfer',
        'damage',
        'branch_demand',
        'demand_send',
        'demand_receive',
        'demand_reversal',
        'opening_balance',
        'reversal',
    ];

    // ===================== RELATIONSHIPS =====================

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function reversalOf(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'reversal_of_transaction_id');
    }

    // ===================== SCOPES =====================

    /**
     * Scope: only non-reversed transactions (for balance calculations).
     */
    public function scopeNotReversed(Builder $query): Builder
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Scope: for a specific warehouse + product.
     */
    public function scopeForProductInWarehouse(Builder $query, int $warehouseId, int $productId): Builder
    {
        return $query->where('warehouse_id', $warehouseId)
                     ->where('product_id', $productId);
    }

    /**
     * Scope: for a specific reference (e.g., all stock moves for a challan).
     */
    public function scopeForReference(Builder $query, string $referenceType, int $referenceId): Builder
    {
        return $query->where('reference_type', $referenceType)
                     ->where('reference_id', $referenceId);
    }

    // ===================== HELPERS =====================

    /**
     * Is this an IN movement (qty > 0)?
     */
    public function isIn(): bool
    {
        return (float) $this->qty > 0;
    }

    /**
     * Is this an OUT movement (qty < 0)?
     */
    public function isOut(): bool
    {
        return (float) $this->qty < 0;
    }

    /**
     * Get the absolute quantity (always positive).
     */
    public function absQty(): float
    {
        return abs((float) $this->qty);
    }
}
