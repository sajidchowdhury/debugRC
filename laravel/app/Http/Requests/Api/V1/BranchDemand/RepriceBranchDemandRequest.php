<?php

namespace App\Http\Requests\Api\V1\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Reprice a branch demand (adjust total value post-send).
 *
 * Extracted from BranchDemandApiController::reprice() inline validate().
 * Requires a new total value and a reason (min 10 chars). Optional
 * approved_by for audit trail. This endpoint is gap G12 (no happy-path
 * test) — this FormRequest prepares the contract for API-4 tests.
 *
 * Idempotency (PURCHASING-API-4, G7 Medium-risk): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of posting a second GL adjustment
 * journal. The field is `sometimes` (not `required`) for backward-compat
 * with deployed mobile clients. See api-conventions.md §11.1.
 */
class RepriceBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_total_value' => 'required|numeric|min:0',
            'reason'          => 'required|string|min:10|max:1000',
            'approved_by'     => 'nullable|integer|exists:users,id',
            'idempotency_token' => 'sometimes|string|uuid',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'new_total_value' => ['description' => 'The revised total value of the demand', 'example' => 15000.00],
            'reason'          => ['description' => 'Reason for repricing (min 10 chars)', 'example' => 'Negotiated discount with partner branch'],
            'approved_by'     => ['description' => 'Optional approving user ID', 'example' => 1],
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-4)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
