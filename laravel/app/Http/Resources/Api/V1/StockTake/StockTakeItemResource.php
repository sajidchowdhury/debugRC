<?php

namespace App\Http\Resources\Api\V1\StockTake;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock Take Item API Resource — one count line.
 *
 * Includes: product info, system_qty (snapshot at setup), physical_qty
 * (the count), difference (computed: physical - system), rate (post-time
 * avg cost), system_rate (setup-time snapshot), post_rate, revaluation
 * context (Phase 9), and the GL journal_line_id trace (Phase 1).
 *
 * Computed fields (the plan requires these in the API response):
 *   - difference: physical_qty - system_qty (the DB has this as a GENERATED
 *     STORED column, but we expose it explicitly for API clients that
 *     don't know the schema).
 *   - value_diff: difference * rate (the money impact of this line's
 *     variance, at post-time cost).
 *   - has_variance: |difference| > 0 (convenience flag for mobile list views).
 */
class StockTakeItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $systemQty = (float) ($this->system_qty ?? 0);
        $physicalQty = $this->physical_qty !== null ? (float) $this->physical_qty : null;
        $difference = $physicalQty !== null ? ($physicalQty - $systemQty) : null;
        $rate = (float) ($this->rate ?? 0);
        $valueDiff = $difference !== null ? round($difference * $rate, 4) : null;

        return [
            'id'                       => $this->id,
            'stock_take_session_id'    => $this->stock_take_session_id,
            'warehouse_id'             => $this->warehouse_id,
            'product'                  => $this->whenLoaded('product', fn () => [
                'id'    => $this->product?->id,
                'name'  => $this->product?->product_name,
                'code'  => $this->product?->product_code,
                'unit'  => $this->product?->unit,  // string column, not a relation
            ], [
                // Fallback when product relation isn't loaded — still expose
                // the ids so mobile clients can re-fetch product details.
                'id'    => $this->product_id,
            ]),
            'product_id'               => $this->product_id,

            // The count itself.
            'system_qty'               => $systemQty,
            'physical_qty'             => $physicalQty,
            'difference'               => $difference,         // computed: physical - system
            'has_variance'             => $difference !== null && abs($difference) > 0.000001,
            'value_diff'               => $valueDiff,          // computed: difference * rate

            // Costing (Phase 9): system_rate = setup snapshot, rate = post-time
            // cost (overwritten at post), post_rate = same as rate (explicit).
            'rate'                     => $rate,
            'system_rate'              => $this->system_rate !== null ? (float) $this->system_rate : null,
            'post_rate'                => $this->post_rate !== null ? (float) $this->post_rate : null,
            'revaluation_amount'       => (float) ($this->revaluation_amount ?? 0),

            // Phase 1: GL trace.
            'journal_line_id'          => $this->journal_line_id,
            'revaluation_line_id'      => $this->revaluation_line_id,

            // Lifecycle.
            'is_applied'               => (bool) $this->is_applied,
            'reason'                   => $this->reason,
            'updated_at'               => $this->updated_at?->toIso8601String(),
        ];
    }
}
