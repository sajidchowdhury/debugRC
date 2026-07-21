<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesChallanItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'product_id'      => $this->product_id,
            'warehouse_id'    => $this->warehouse_id,
            'qty'             => (float) $this->qty,
            'issue_rate'      => (float) $this->issue_rate,
            'cogs_amount'     => (float) ($this->cogs_amount ?? ($this->qty * $this->issue_rate)),
        ];
    }
}
