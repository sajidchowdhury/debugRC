<?php

namespace App\Http\Resources\Api\V1\StockTake;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock Take Session API Resource — mobile-optimized JSON shape.
 *
 * Includes: header, branch, warehouses (with progress), counts summary,
 * status lifecycle (draft → counting → submitted → approved → posted →
 * cancelled/reversed), approval workflow context, reversal + re-open
 * context (Phase 10), and GL journal reference.
 *
 * Computed fields:
 *   - progress_pct: completed warehouses / total warehouses * 100
 *
 * Excludes: internal audit columns that aren't useful to mobile clients
 * (e.g. raw timestamp columns — use the ISO-8601 formatted ones instead).
 */
class StockTakeSessionResource extends JsonResource
{
    public function toArray($request): array
    {
        // Access the loaded warehouses relation directly (whenLoaded without
        // a value arg returns a Potential, not the collection — so we check
        // relationLoaded + read the property).
        $warehousesLoaded = $this->relationLoaded('warehouses');
        $warehouses = $warehousesLoaded ? $this->warehouses : collect();
        $totalWh = $warehouses->count();
        $completedWh = $warehouses->where('status', 'completed')->count();

        return [
            'id'                    => $this->id,
            'session_code'          => $this->session_code,
            'session_date'          => $this->session_date?->format('Y-m-d'),
            'branch'                => $this->whenLoaded('branch', fn () => [
                'id'   => $this->branch?->id,
                'name' => $this->branch?->branch_name,
                'code' => $this->branch?->branch_code,
            ]),
            'status'                => $this->status,
            'count_scope'           => $this->count_scope,
            'count_scope_payload'   => $this->count_scope_payload,
            'freeze_outbound'       => (bool) $this->freeze_outbound,

            // Warehouse progress (computed from the loaded warehouses relation).
            'warehouses'            => $this->whenLoaded('warehouses', fn () => $warehouses->map(fn ($w) => [
                'id'     => $w->warehouse_id,
                'name'   => $w->warehouse?->warehouse_name,
                'status' => $w->status,
                // item_count / counted_count are computed on demand (not stored
                // columns) — mobile clients can GET /sessions/{id}/items?warehouse_id=X
                // if they need per-warehouse line counts.
            ])),
            'progress'              => [
                'total_wh'     => $totalWh,
                'completed_wh' => $completedWh,
                'pct'          => $totalWh > 0 ? (int) round($completedWh / $totalWh * 100) : 0,
            ],

            // Approval workflow context (Phase 4).
            'submitted_by'          => $this->submitted_by,
            'submitted_at'          => $this->submitted_at?->toIso8601String(),
            'approved_by'           => $this->approved_by,
            'approved_at'           => $this->approved_at?->toIso8601String(),
            'approval_comments'     => $this->approval_comments,

            // Reversal + re-open context (Phase 10).
            'is_reversed'           => (bool) $this->is_reversed,
            'reversed_at'           => $this->reversed_at?->toIso8601String(),
            'reversed_by'           => $this->reversed_by,
            'reverse_reason'        => $this->reverse_reason,
            're_open_count'         => (int) $this->re_open_count,
            'last_reopened_at'      => $this->last_reopened_at?->toIso8601String(),
            'last_reopened_by'      => $this->last_reopened_by,
            'reversal_of_entry_id'  => $this->reversal_of_entry_id,

            // GL journal reference (the CURRENT post's JE; null until posted).
            'journal_entry_id'      => $this->journal_entry_id,

            'notes'                 => $this->notes,
            'created_by'            => $this->created_by,
            'created_at'            => $this->created_at?->toIso8601String(),
            'updated_at'            => $this->updated_at?->toIso8601String(),
        ];
    }
}
