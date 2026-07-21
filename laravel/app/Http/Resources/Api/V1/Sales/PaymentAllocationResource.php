<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAllocationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'invoice_id'       => $this->invoice_id,
            'payment_id'       => $this->payment_id,
            'allocated_amount' => (float) $this->allocated_amount,
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
