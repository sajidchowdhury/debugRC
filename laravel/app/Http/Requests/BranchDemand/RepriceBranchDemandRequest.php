<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reprice Branch Demand Request — Phase 7.
 *
 * Validates the input for creating a repricing adjustment on a branch demand.
 *
 * Business rules:
 *   - new_total_value must be a positive number (>= 0)
 *   - new_total_value must differ from the current total_value
 *   - reason is required (min 10 characters to ensure meaningful documentation)
 *   - approved_by is optional (can be set later)
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

    public function messages(): array
    {
        return [
            'new_total_value.required' => 'The new total value is required.',
            'new_total_value.numeric'  => 'The new total value must be a number.',
            'new_total_value.min'      => 'The new total value cannot be negative.',
            'reason.required'          => 'A reason for the repricing is required.',
            'reason.min'               => 'The reason must be at least 10 characters (please provide meaningful documentation).',
            'reason.max'               => 'The reason cannot exceed 1000 characters.',
            'approved_by.exists'       => 'The approving user does not exist.',
        ];
    }
}
