<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock Adjustment Item — Phase 6.3 + Phase 5 (UOM).
 * One row per product in a stock adjustment.
 *
 * Phase 5 (UOM) columns:
 *   - uom_id:       the unit the qty was ENTERED in (FK → units_of_measure).
 *   - qty_entered:  the quantity the user typed (in the selected UOM).
 *   - qty_base:     the quantity converted to the product's base unit
 *                   (= qty_entered × uom_factor). This is what posts to stock.
 *   - uom_factor:   the conversion factor snapshot at creation time
 *                   (1 from_uom = factor base_uom). Snapshotted for audit
 *                   immutability — if the conversion changes later, the
 *                   historical adjustment keeps the factor it was posted with.
 *
 * The legacy `qty` column is kept as the authoritative BASE quantity for
 * backward compat with existing code that reads $item->qty (it equals
 * qty_base for new rows and for backfilled old rows).
 *
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $product_id
 * @property string $qty           Base qty (legacy alias of qty_base).
 * @property int|null $uom_id      Phase 5 — UOM the qty was entered in.
 * @property string|null $qty_entered  Phase 5 — what the user typed.
 * @property string|null $qty_base     Phase 5 — converted base qty.
 * @property string|null $uom_factor   Phase 5 — factor snapshot.
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
        'uom_id',        // Phase 5
        'qty_entered',   // Phase 5
        'qty_base',      // Phase 5
        'uom_factor',    // Phase 5
        'rate',
        'reason',
    ];

    protected $casts = [
        'qty'         => 'decimal:4',
        'uom_id'      => 'integer',
        'qty_entered' => 'decimal:4',
        'qty_base'    => 'decimal:4',
        'uom_factor'  => 'decimal:6',
        'rate'        => 'decimal:2',
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
     * Phase 5 — the unit of measure the qty was entered in.
     */
    public function uom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    /**
     * Line amount = qty_base × rate (rate is per base unit).
     *
     * Falls back to `qty` when qty_base is null (pre-Phase-5 rows that
     * were not backfilled — defensive; the migration backfills all rows).
     */
    public function amount(): float
    {
        $baseQty = $this->qty_base !== null ? (float) $this->qty_base : (float) $this->qty;
        return $baseQty * (float) $this->rate;
    }

    /**
     * Phase 5 — the base quantity that posts to stock.
     *
     * Returns qty_base when set (Phase 5 rows), otherwise the legacy `qty`
     * column (pre-Phase-5 / non-UOM callers). Centralised so the service,
     * views, and reports all read the same value.
     */
    public function baseQty(): float
    {
        return $this->qty_base !== null ? (float) $this->qty_base : (float) $this->qty;
    }

    /**
     * Phase 5 — the quantity the user typed, in the selected UOM.
     *
     * Falls back to `qty` for pre-Phase-5 rows (where qty was entered in
     * base units with no UOM selection).
     */
    public function enteredQty(): float
    {
        return $this->qty_entered !== null ? (float) $this->qty_entered : (float) $this->qty;
    }

    /**
     * Phase 5 — human-readable label for the entered qty + UOM code.
     *
     * e.g. "2.0000 Carton" or "24.0000 Pcs". Used by the show/print views.
     */
    public function enteredQtyLabel(): string
    {
        $uomCode = $this->uom?->code ?? '?';
        return number_format($this->enteredQty(), 4) . ' ' . e($uomCode);
    }

    /**
     * Phase 5 — human-readable label for the base qty (what posted to stock).
     *
     * e.g. "24.0000 Pcs". Used by the show/print views next to the entered
     * qty so the conversion is transparent to the reader.
     */
    public function baseQtyLabel(): string
    {
        $baseCode = $this->product?->unit ?? '?';
        return number_format($this->baseQty(), 4) . ' ' . e($baseCode);
    }
}
