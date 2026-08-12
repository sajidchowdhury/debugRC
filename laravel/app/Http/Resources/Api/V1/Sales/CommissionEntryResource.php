<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commission Entry API Resource — Task 37.
 *
 * Replaces CommissionApiController::formatEntry() with a standard
 * JsonResource, preserving the EXACT JSON contract of the previous inline
 * formatter (field names, nested salesman shape, computed is_reversal flag,
 * ISO-8601 created_at) so the migration is transparent to existing API
 * consumers.
 *
 * The `invoice_code` is sourced from the loaded salesInvoice relation;
 * controllers always eager-load it, so the key is present in practice
 * (null when the entry has no associated invoice, e.g. a manual adjustment).
 */
class CommissionEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'salesman'           => $this->whenLoaded('salesman', fn() => $this->salesman ? [
                'id'   => $this->salesman->id,
                'name' => $this->salesman->name,
            ] : null),
            'invoice_code'       => $this->whenLoaded('salesInvoice', fn() => $this->salesInvoice?->invoice_code),
            'commission_base'    => (float) $this->commission_base,
            'commission_rate'    => (float) $this->commission_rate,
            'commission_amount'  => (float) $this->commission_amount,
            'status'             => $this->status,
            'entry_date'         => $this->entry_date?->toDateString(),
            'commission_period'  => $this->commission_period,
            'is_reversal'        => (bool) $this->isReturnReversal(),
            'notes'              => $this->notes,
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
