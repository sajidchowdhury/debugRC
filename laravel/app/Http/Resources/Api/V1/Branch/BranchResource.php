<?php

namespace App\Http\Resources\Api\V1\Branch;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Branch API Resource — mobile-optimized JSON shape.
 *
 * Replaces the raw Eloquent serialization previously returned by
 * BranchApiController (which leaked internal audit columns such as
 * created_by / deleted_by / updated_at). This resource standardizes the
 * payload to the same convention used by the Sales / Stock resources:
 *
 *   - Business fields only (code, name, contact, status).
 *   - Relations exposed via whenLoaded() to prevent N+1.
 *   - Money/bool/date casts applied uniformly.
 *   - deleted_at retained because BranchApiController::show() uses
 *     withTrashed() for admin visibility of deactivated branches.
 *
 * Excludes: created_by, deleted_by, updated_at (internal audit fields).
 */
class BranchResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'branch_code' => $this->branch_code,
            'branch_name' => $this->branch_name,
            'company_id'  => $this->company_id,
            'company'     => $this->whenLoaded('company', fn() => $this->company ? [
                'id'   => $this->company->id,
                'name' => $this->company?->company_name,
                'code' => $this->company?->company_code,
            ] : null),
            'address'     => $this->address,
            'phone'       => $this->phone,
            'email'       => $this->email,
            'is_active'   => (bool) $this->is_active,
            'deleted_at'  => $this->deleted_at?->toIso8601String(),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
