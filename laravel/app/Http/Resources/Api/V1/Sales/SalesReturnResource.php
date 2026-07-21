<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sales Return API Resource.
 */
class SalesReturnResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'return_code'          => $this->return_code,
            'return_date'          => $this->return_date?->format('Y-m-d'),
            'sales_invoice_id'     => $this->sales_invoice_id,
            'customer'             => $this->whenLoaded('customer', fn() => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->customer_name,
            ]),
            'branch_id'            => $this->branch_id,
            'total_amount'         => (float) $this->total_amount,
            'cogs_amount'          => (float) $this->cogs_amount,
            'status'               => $this->status,
            'is_reversed'          => (bool) $this->is_reversed,
            'reason'               => $this->reason,
            'items'                => SalesReturnItemResource::collection($this->whenLoaded('items')),
            'journal_entry_id'     => $this->journal_entry_id,
            'cogs_journal_entry_id' => $this->cogs_journal_entry_id,
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
