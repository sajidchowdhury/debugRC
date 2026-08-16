<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Challan Item — Phase 8.3 (P0-5).
 *
 * Per-line issue-cost SSOT for each sales challan. Created at challan
 * issue time (issueChallan) to snapshot the avg_cost used for each line's
 * stock OUT, so that:
 *   - Challan reversal restores inventory at the ORIGINAL per-line rate
 *     (not current avg_cost, which may have drifted).
 *   - GrossMarginReport can break down COGS per product / warehouse.
 *   - The challan_reversal_smoke test can verify issue_rate > 0.
 *
 * This is the Laravel equivalent of legacy migration 040's
 * `sales_challan_items` table.
 *
 * @property int $id
 * @property int $sales_challan_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property string $qty (positive — items issued OUT)
 * @property string $issue_rate (avg_cost at challan issue time — snapshot)
 * @property string $cogs_amount (qty × issue_rate — denormalized)
 * @property \Illuminate\Support\Carbon $created_at
 */
class SalesChallanItem extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'sales_challan_items';

    public $timestamps = false; // only created_at, no updated_at column (legacy pattern)

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'sales_challan_id', 'product_id', 'warehouse_id',
        'qty', 'issue_rate',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'issue_rate' => 'decimal:2',
        'cogs_amount' => 'decimal:2', // GENERATED: qty * issue_rate
        'sales_challan_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function challan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalesChallan::class, 'sales_challan_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Scope: only non-reversed challans' items (via join on sales_challans).
     * Usage: SalesChallanItem::forActiveChallans()->where('product_id', $pid)->sum('cogs_amount')
     */
    public function scopeForActiveChallans($query)
    {
        return $query->whereHas('challan', fn($q) => $q->where('is_reversed', false));
    }
}
