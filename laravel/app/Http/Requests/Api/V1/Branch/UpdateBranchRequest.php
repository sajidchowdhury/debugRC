<?php

namespace App\Http\Requests\Api\V1\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Update an existing branch (admin only).
 *
 * Extracted from BranchApiController::update() inline validate(). Uses
 * `sometimes` on all rules so partial updates are supported (PATCH-like
 * semantics on PUT). The unique rule excludes the current branch id.
 */
class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Route param is {id} (see routes/api.php:107). Exclude the current
        // branch from the unique check so it can keep its own code.
        $branchId = (int) $this->route('id');

        $uniqueRule = $branchId > 0
            ? "unique:branches,branch_code,{$branchId}"
            : 'unique:branches,branch_code';

        return [
            'branch_code' => "sometimes|required|string|max:20|regex:/^[A-Za-z0-9\\-_.]+$/|{$uniqueRule}",
            'branch_name' => 'sometimes|required|string|max:100',
            'address'     => 'nullable|string',
            'phone'       => 'nullable|string|max:20',
            'email'       => 'nullable|email|max:100',
            'is_active'   => 'boolean',
        ];
    }
}
