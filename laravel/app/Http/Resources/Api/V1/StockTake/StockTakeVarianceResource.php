<?php

namespace App\Http\Resources\Api\V1\StockTake;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock Take Variance API Resource — a single variance line (an item with a
 * non-zero difference between physical and system qty).
 *
 * This is a focused subset of StockTakeItemResource for the variance report
 * + the "show me what changed" mobile view. It omits fields that are only
 * relevant during counting (e.g. is_applied, reason) and emphasizes the
 * variance + costing + GL-trace fields that matter after posting.
 *
 * Computed fields:
 *   - difference: physical_qty - system_qty
 *   - value_diff: difference * post_rate (the money impact at post-time cost)
 *   - variance_type: 'gain' | 'loss' (sign of difference)
 */
class StockTakeVarianceResource extends JsonResource
{
    public function toArray($request): array
    {
        $systemQty = (float) ($this->system_qty ?? 0);
        $physicalQty = (float) ($this->physical_qty ?? 0);
        $difference = $physicalQty - $systemQty;
        $postRate = (float) ($this->post_rate ?? $this->rate ?? 0);
        $valueDiff = round($difference * $postRate, 4);

        return [
            'id'                    => $this->id,
            'product_id'            => $this->product_id,
            'product'               => $this->whenLoaded('product', fn () => [
                'id'   => $this->product?->id,
                'name' => $this->product?->product_name,
                'code' => $this->product?->product_code,
            ]),
            'warehouse_id'          => $this->warehouse_id,
            'system_qty'            => $systemQty,
            'physical_qty'          => $physicalQty,
            'difference'            => round($difference, 6),
            'variance_type'         => $difference > 0 ? 'gain' : ($difference < 0 ? 'loss' : 'none'),
            'value_diff'            => $valueDiff,

            // Costing (Phase 9).
            'system_rate'           => $this->system_rate !== null ? (float) $this->system_rate : null,
            'post_rate'             => $this->post_rate !== null ? (float) $this->post_rate : null,
            'revaluation_amount'    => (float) ($this->revaluation_amount ?? 0),

            // GL trace (Phase 1 + Phase 9).
            'journal_line_id'       => $this->journal_line_id,
            'revaluation_line_id'   => $this->revaluation_line_id,
        ];
    }
}
