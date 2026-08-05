<?php

namespace App\Http\Requests\Api\V1\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a branch demand (inter-branch stock request).
 *
 * Extracted from BranchDemandApiController::store() inline validate().
 * The controller retains the same-branch guard (to_branch_id must differ
 * from the user's branch) AFTER validation; this FormRequest owns only
 * the input contract.
 *
 * Idempotency (G-088/G-089/G-090, PURCHASING-API-3): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of creating a duplicate demand +
 * intercompany journals. The field is `sometimes` (not `required`) for
 * backward-compat with deployed mobile clients. See api-conventions.md
 * §11.1 for the canonical pattern.
 */
class StoreBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_branch_id'       => 'required|integer|exists:branches,id',
            'demand_date'        => 'required|date',
            'notes'              => 'nullable|string|max:2000',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.notes'      => 'nullable|string|max:500',
            'idempotency_token'  => 'sometimes|string|uuid',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'to_branch_id' => ['description' => 'Branch to send goods FROM (the supplying branch)', 'example' => 2],
            'demand_date'  => ['description' => 'Demand date (Y-m-d)', 'example' => '2025-01-21'],
            'notes'        => ['description' => 'Optional note', 'example' => 'Urgent restock'],
            'items'        => ['description' => 'Line items (min 1)', 'example' => [['product_id' => 10, 'qty' => 20]]],
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-3)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
