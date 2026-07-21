<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'product_id'      => $this->product_id,
            'warehouse_id'    => $this->warehouse_id,
            'qty'             => (float) $this->qty,
            'rate'            => (float) $this->rate,
            'amount'          => (float) ($this->amount ?? ($this->qty * $this->rate)),
            'condition_state' => $this->condition_state,
        ];
    }
}
