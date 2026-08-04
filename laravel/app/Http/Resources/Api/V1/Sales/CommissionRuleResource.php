<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commission Rule API Resource — Task 37.
 *
 * Replaces CommissionApiController::formatRule() with a standard
 * JsonResource, preserving the EXACT JSON contract of the previous inline
 * formatter (field names, nested shapes, conditional collections) so the
 * migration is transparent to existing API consumers.
 *
 * Conditional collections (tiers / product_groups / targets) are only
 * emitted when the relation is loaded AND non-empty — matching the
 * previous formatRule() behaviour, which omitted the key entirely for
 * empty collections (e.g. a `flat` rule has no tiers key).
 *
 * Adds `created_at` for auditability (prepares the ground for API-4 tests);
 * this is purely additive and does not alter existing fields.
 */
class CommissionRuleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'salesman'            => $this->whenLoaded('salesman', fn() => $this->salesman ? [
                'id'            => $this->salesman->id,
                'name'          => $this->salesman->name,
                'employee_code' => $this->salesman->employee_code,
            ] : null),
            'rule_type'           => $this->rule_type,
            'rate'                => (float) $this->rate,
            'effective_from'      => $this->effective_from?->toDateString(),
            'effective_to'        => $this->effective_to?->toDateString(),
            'is_active'           => (bool) $this->is_active,
            'is_currently_active' => (bool) $this->isCurrentlyActive(),
            'branch'              => $this->whenLoaded('branch', fn() => $this->branch ? [
                'id'   => $this->branch->id,
                'name' => $this->branch->branch_name,
            ] : null),
            'notes'               => $this->notes,
            'tiers'               => $this->when(
                $this->resource->relationLoaded('tiers') && $this->tiers->isNotEmpty(),
                fn() => $this->tiers->map(fn($t) => [
                    'threshold'  => (float) $t->threshold,
                    'rate'       => (float) $t->rate,
                    'sort_order' => $t->sort_order,
                ])->values()
            ),
            'product_groups'      => $this->when(
                $this->resource->relationLoaded('productGroups') && $this->productGroups->isNotEmpty(),
                fn() => $this->productGroups->map(fn($pg) => [
                    'product_group_id' => $pg->product_group_id,
                    'group_name'       => $pg->productGroup?->group_name,
                    'rate'             => (float) $pg->rate,
                ])->values()
            ),
            'targets'             => $this->when(
                $this->resource->relationLoaded('targets') && $this->targets->isNotEmpty(),
                fn() => $this->targets->map(fn($t) => [
                    'target_amount' => (float) $t->target_amount,
                    'bonus_rate'    => (float) $t->bonus_rate,
                    'period'        => $t->period,
                ])->values()
            ),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
