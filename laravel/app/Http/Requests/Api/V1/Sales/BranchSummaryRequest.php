<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Commission branch-summary query (admin/manager only).
 *
 * Extracted from CommissionApiController::branchSummary() inline validate().
 *
 * GET /api/v1/sales/commission/branch-summary
 *   ?period=2026-09&branch_id=3   (branch_id optional — null = all branches)
 */
class BranchSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period'    => 'required|string|regex:/^\d{4}-\d{2}$/',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'period'    => ['description' => 'Commission period (YYYY-MM)', 'example' => '2026-09'],
            'branch_id' => ['description' => 'Branch scope. NULL = all branches (admin only).', 'example' => null],
        ];
    }
}
