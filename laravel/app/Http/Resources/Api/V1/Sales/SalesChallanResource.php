<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sales Challan API Resource — delivery note with items.
 */
class SalesChallanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            'challan_code'          => $this->challan_code,
            'challan_date'          => $this->challan_date?->format('Y-m-d'),
            'sales_invoice_id'      => $this->sales_invoice_id,
            'branch_id'             => $this->branch_id,
            'transport_name'        => $this->transport_name,
            'transport_phone'       => $this->transport_phone,
            'vehicle_number'        => $this->vehicle_number,
            'driver_name'           => $this->driver_name,
            'transport_cost'        => (float) $this->transport_cost,
            'issue_cost'            => (float) ($this->issue_cost ?? 0),
            'is_reversed'           => (bool) $this->is_reversed,
            'is_dispatch_soft_hold' => (bool) ($this->is_dispatch_soft_hold ?? false),
            'items'                 => SalesChallanItemResource::collection($this->whenLoaded('items')),
            'journal_entry_id'      => $this->journal_entry_id,
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
