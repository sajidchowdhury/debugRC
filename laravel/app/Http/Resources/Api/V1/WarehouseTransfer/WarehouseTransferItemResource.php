<?php

namespace App\Http\Resources\Api\V1\WarehouseTransfer;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Warehouse Transfer Item API Resource — Phase 8.
 *
 * One row per product in a warehouse transfer.
 * Includes: product summary, qty, rate, computed amount.
 */
class WarehouseTransferItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'product'     => $this->whenLoaded('product', fn() => [
                'id'   => $this->product?->id,
                'code' => $this->product?->product_code,
                'name' => $this->product?->product_name,
            ]),
            'product_id'  => $this->product_id,
            'qty'         => (float) $this->qty,
            'rate'        => (float) $this->rate,
            'amount'      => (float) ($this->qty * $this->rate),
        ];
    }
}
