<?php

namespace App\Http\Resources\Api\V1\BranchDemand;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch Demand API Resource — Phase 10 (mobile-optimized JSON shape).
 *
 * Includes: header, from/to branch summaries, items, status flags,
 * settlement progress, GL journal references, repricing history.
 * Excludes: internal audit fields (reversed_by, etc.) to keep payload small.
 */
class BranchDemandResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            'demand_code'           => $this->demand_code,
            'demand_date'           => $this->demand_date?->format('Y-m-d'),
            'from_branch'           => $this->whenLoaded('fromBranch', fn() => [
                'id'   => $this->fromBranch?->id,
                'name' => $this->fromBranch?->branch_name,
                'code' => $this->fromBranch?->branch_code,
            ]),
            'to_branch'             => $this->whenLoaded('toBranch', fn() => [
                'id'   => $this->toBranch?->id,
                'name' => $this->toBranch?->branch_name,
                'code' => $this->toBranch?->branch_code,
            ]),
            'status'                => $this->status,
            'total_value'           => (float) ($this->total_value ?? 0),
            'settlement_amount'     => (float) ($this->settlement_amount ?? 0),
            'outstanding'           => (float) ($this->total_value ?? 0) - (float) ($this->settlement_amount ?? 0),
            'settlement_progress'   => $this->settlementProgress(),
            'is_reversed'           => (bool) $this->is_reversed,
            'reversed_at'           => $this->reversed_at?->toIso8601String(),
            'reverse_reason'        => $this->reverse_reason,
            'received_at'           => $this->received_at?->toIso8601String(),
            'is_receipt_confirmed'  => $this->received_at !== null,
            'notes'                 => $this->notes,
            'created_by'            => $this->whenLoaded('createdBy', fn() => [
                'id'   => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]),
            'warehouse_transfer_id' => $this->warehouse_transfer_id,
            'journal_entry_id'      => $this->journal_entry_id,
            'journal_entry_id_debtor' => $this->journal_entry_id_debtor,
            'items'                 => BranchDemandItemResource::collection($this->whenLoaded('items')),
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
