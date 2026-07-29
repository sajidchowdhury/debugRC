<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reverse Branch Demand Request — Phase 2.
 *
 * Validates the reversal of a sent/received demand.
 * A reason is mandatory for audit trail purposes.
 */
class ReverseBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:5|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reversal reason is required for audit trail.',
            'reason.min' => 'The reversal reason must be at least 5 characters.',
            'reason.max' => 'The reversal reason must not exceed 2000 characters.',
        ];
    }
}
