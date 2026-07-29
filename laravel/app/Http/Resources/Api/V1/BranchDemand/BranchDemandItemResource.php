<?php

namespace App\Http\Resources\Api\V1\BranchDemand;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch Demand Item API Resource — Phase 10 (mobile-optimized JSON shape).
 *
 * Includes: product, warehouse, qty, cost_rate, price range, line total.
 * Excludes: internal audit fields to keep payload small.
 */
class BranchDemandItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'product'          => $this->whenLoaded('product', fn() => [
                'id'   => $this->product?->id,
                'code' => $this->product?->product_code,
                'name' => $this->product?->product_name,
                'unit' => $this->product?->unit,
            ]),
            'qty'              => (float) $this->qty,
            'cost_rate'        => (float) ($this->cost_rate ?? 0),
            'line_total'       => (float) $this->qty * (float) ($this->cost_rate ?? 0),
            'from_warehouse'   => $this->whenLoaded('fromWarehouse', fn() => [
                'id'   => $this->fromWarehouse?->id,
                'name' => $this->fromWarehouse?->warehouse_name,
            ]),
            'to_warehouse'     => $this->whenLoaded('toWarehouse', fn() => [
                'id'   => $this->toWarehouse?->id,
                'name' => $this->toWarehouse?->warehouse_name,
            ]),
            'price_range'      => [
                'min'      => (float) ($this->price_min ?? 0),
                'max'      => (float) ($this->price_max ?? 0),
                'default'  => (float) ($this->price_default ?? 0),
            ],
            'is_sent'          => $this->from_warehouse_id !== null,
            'notes'            => $this->notes,
        ];
    }
}
