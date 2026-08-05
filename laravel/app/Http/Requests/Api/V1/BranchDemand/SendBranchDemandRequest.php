<?php

namespace App\Http\Requests\Api\V1\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Send goods for a branch demand (with warehouse routing).
 *
 * Extracted from BranchDemandApiController::send() inline validate().
 * Each line item specifies which source warehouse to pick from and which
 * destination warehouse to deliver to.
 *
 * Idempotency (PURCHASING-API-4, G7 Medium-risk): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of re-sending the demand (which
 * would otherwise move stock + post GL a second time, or hit a 409).
 * The field is `sometimes` (not `required`) for backward-compat with
 * deployed mobile clients. See api-conventions.md §11.1.
 */
class SendBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                     => 'required|array|min:1',
            'items.*.id'                => 'required|integer|exists:branch_demand_items,id',
            'items.*.from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.to_warehouse_id'   => 'required|integer|exists:warehouses,id',
            'idempotency_token'         => 'sometimes|string|uuid',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'items' => ['description' => 'Line items with warehouse routing (min 1)', 'example' => [['id' => 1, 'from_warehouse_id' => 3, 'to_warehouse_id' => 5]]],
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-4)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
