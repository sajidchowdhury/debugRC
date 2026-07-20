<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceDispatchResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'product_id'     => $this->product_id,
            'warehouse_id'   => $this->warehouse_id,
            'ordered_qty'    => (float) $this->ordered_qty,
            'dispatched_qty' => (float) $this->dispatched_qty,
        ];
    }
}
