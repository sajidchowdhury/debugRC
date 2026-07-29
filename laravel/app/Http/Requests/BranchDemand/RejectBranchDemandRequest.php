<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject Branch Demand Request — Phase 2.
 *
 * Validates the rejection of a pending demand.
 * A reason is mandatory for audit trail purposes.
 */
class RejectBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:3|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A rejection reason is required for audit trail.',
            'reason.min' => 'The rejection reason must be at least 3 characters.',
            'reason.max' => 'The rejection reason must not exceed 2000 characters.',
        ];
    }
}
