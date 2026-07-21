<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sales Invoice API Resource — mobile-optimized JSON shape.
 *
 * Includes: header, items, dispatches, customer summary, GL journal reference.
 * Excludes: internal audit fields (reversed_by, etc.) to keep payload small.
 */
class SalesInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'invoice_code'       => $this->invoice_code,
            'invoice_date'       => $this->invoice_date?->format('Y-m-d'),
            'customer'           => $this->whenLoaded('customer', fn() => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->customer_name,
                'code' => $this->customer?->customer_code,
            ]),
            'branch_id'          => $this->branch_id,
            'sub_total'          => (float) $this->sub_total,
            'discount_amount'    => (float) $this->discount_amount,
            'transport_cost'     => (float) $this->transport_cost,
            'total_amount'       => (float) $this->total_amount,
            'paid_amount'        => (float) $this->paid_amount,
            'due_amount'         => (float) $this->due_amount,
            'status'             => $this->status,
            'is_godown_prepared' => (bool) $this->is_godown_prepared,
            'is_challan_issued'  => (bool) $this->is_challan_issued,
            'is_reversed'        => (bool) $this->is_reversed,
            'is_soft_hold'       => (bool) $this->is_soft_hold,
            'call_a_day'         => (bool) $this->call_a_day,
            'payment_mode'       => $this->payment_mode,
            'notes'              => $this->notes,
            'sales_person'       => $this->sales_person,
            'items'              => SalesInvoiceItemResource::collection($this->whenLoaded('items')),
            'dispatches'         => SalesInvoiceDispatchResource::collection($this->whenLoaded('dispatches')),
            'journal_entry_id'   => $this->journal_entry_id,
            'cogs_journal_entry_id' => $this->cogs_journal_entry_id,
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
