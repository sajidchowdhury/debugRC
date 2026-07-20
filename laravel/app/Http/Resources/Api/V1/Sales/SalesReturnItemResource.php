<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'product_id'       => $this->product_id,
            'warehouse_id'     => $this->warehouse_id,
            'qty'              => (float) $this->qty,
            'rate'             => (float) $this->rate,
            'original_cost'    => (float) ($this->original_cost ?? 0),
            'amount'           => (float) ($this->amount ?? ($this->qty * $this->rate)),
            'damage_invoice_id' => $this->damage_invoice_id,
        ];
    }
}
