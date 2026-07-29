<?php

namespace App\Http\Resources\Api\V1\StockAdjustment;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock Adjustment Item API Resource — Phase 9 (API Routes & Mobile Support).
 *
 * One row per product in a stock adjustment. Serializes the Phase 5 UOM
 * fields (uom_id, qty_entered, qty_base, uom_factor) and the Phase 6.2
 * reversal-safety linkage (stock_transaction_id, stock_transaction_date)
 * so an API consumer sees the exact same fidelity as the web show page.
 *
 * The computed `amount` = qty_base × rate (the value posted to GL on
 * confirm). For draft rows qty_base may be NULL (pre-Phase-5 data); in
 * that case we fall back to the legacy `qty` column, which is the
 * authoritative base quantity for historical rows.
 */
class StockAdjustmentItemResource extends JsonResource
{
    public function toArray($request): array
    {
        // qty_base is the Phase 5 base-unit qty; fall back to legacy `qty`
        // for pre-Phase-5 rows where qty_base is NULL but qty holds the
        // base quantity. Both equal qty_base for new rows (see model docs).
        $baseQty = $this->qty_base !== null ? (float) $this->qty_base : (float) $this->qty;

        return [
            'id'             => $this->id,
            'product'        => $this->whenLoaded('product', fn() => [
                'id'   => $this->product?->id,
                'code' => $this->product?->product_code,
                'name' => $this->product?->product_name,
            ]),
            'product_id'     => $this->product_id,
            // The quantity the user typed (in the selected UOM). Null for
            // pre-Phase-5 rows that only carry the base qty.
            'qty_entered'    => $this->qty_entered !== null ? (float) $this->qty_entered : null,
            // The UOM the qty was entered in (Phase 5). Null = base unit.
            'uom'            => $this->whenLoaded('uom', fn() => $this->uom ? [
                'id'   => $this->uom->id,
                'code' => $this->uom->code,
                'name' => $this->uom->name,
                'type' => $this->uom->type,  // count|weight|volume
            ] : null),
            'uom_id'         => $this->uom_id,
            // The conversion factor snapshot at creation time
            // (1 entered_uom = factor base_uom). Immutable for audit.
            'uom_factor'     => $this->uom_factor !== null ? (float) $this->uom_factor : null,
            // The base-unit qty actually posted to stock. This is the
            // authoritative quantity for confirmed adjustments.
            'qty_base'       => $this->qty_base !== null ? (float) $this->qty_base : null,
            // Legacy base qty (alias of qty_base for new rows; the only
            // qty column on pre-Phase-5 rows). Kept for backward compat.
            'qty'            => (float) $this->qty,
            'rate'           => (float) $this->rate,
            'amount'         => round($baseQty * (float) $this->rate, 2),
            'reason'         => $this->reason,
            // Phase 6.2 — the exact stock_transactions row created for
            // this item on confirm (null until confirmed). Powers the
            // reversal-safety linkage; the API exposes both columns so a
            // consumer can correlate items back to ledger movements.
            'stock_transaction_id'    => $this->stock_transaction_id,
            'stock_transaction_date'  => $this->stock_transaction_date?->format('Y-m-d'),
        ];
    }
}
