<?php

namespace App\Http\Resources\Api\V1\WarehouseTransfer;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Warehouse Transfer API Resource — Phase 8 (mobile-optimized JSON shape).
 *
 * Includes: header, from/to warehouse summaries, branch, items, status flags.
 * Excludes: internal audit fields (reversed_by, etc.) to keep payload small.
 *
 * Same-branch ONLY — cross-branch transfers are blocked at every layer
 * (controller, service, DB trigger). The API mirrors this enforcement.
 */
class WarehouseTransferResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'transfer_code'     => $this->transfer_code,
            'transfer_date'     => $this->transfer_date?->format('Y-m-d'),
            'from_warehouse'    => $this->whenLoaded('fromWarehouse', fn() => [
                'id'   => $this->fromWarehouse?->id,
                'name' => $this->fromWarehouse?->warehouse_name,
            ]),
            'to_warehouse'      => $this->whenLoaded('toWarehouse', fn() => [
                'id'   => $this->toWarehouse?->id,
                'name' => $this->toWarehouse?->warehouse_name,
            ]),
            'from_branch'       => $this->whenLoaded('fromBranch', fn() => [
                'id'   => $this->fromBranch?->id,
                'name' => $this->fromBranch?->branch_name,
            ]),
            'to_branch'         => $this->whenLoaded('toBranch', fn() => [
                'id'   => $this->toBranch?->id,
                'name' => $this->toBranch?->branch_name,
            ]),
            'is_interbranch'    => (bool) $this->is_interbranch,
            'branch_demand_id'  => $this->branch_demand_id,
            'total_amount'      => (float) ($this->total_amount ?? 0),
            'status'            => $this->status,
            'is_reversed'       => (bool) $this->is_reversed,
            'reversed_at'       => $this->reversed_at?->toIso8601String(),
            'reverse_reason'    => $this->reverse_reason,
            'notes'             => $this->notes,
            'created_by'        => $this->whenLoaded('createdBy', fn() => [
                'id'   => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]),
            'journal_entry_id'           => $this->journal_entry_id,
            'journal_entry_id_debtor'    => $this->journal_entry_id_debtor,
            'items'             => WarehouseTransferItemResource::collection($this->whenLoaded('items')),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
