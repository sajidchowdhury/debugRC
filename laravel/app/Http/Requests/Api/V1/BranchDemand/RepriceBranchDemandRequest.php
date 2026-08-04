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
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'new_total_value' => ['description' => 'The revised total value of the demand', 'example' => 15000.00],
            'reason'          => ['description' => 'Reason for repricing (min 10 chars)', 'example' => 'Negotiated discount with partner branch'],
            'approved_by'     => ['description' => 'Optional approving user ID', 'example' => 1],
        ];
    }
}
