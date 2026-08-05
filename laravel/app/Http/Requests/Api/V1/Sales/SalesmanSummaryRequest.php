<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Commission salesman-summary query (admin/manager only).
 *
 * Extracted from CommissionApiController::salesmanSummary() inline validate().
 *
 * GET /api/v1/sales/commission/salesman-summary
 *   ?salesman_id=42&period=2026-09
 */
class SalesmanSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salesman_id' => 'required|integer|exists:employees,id',
            'period'      => 'required|string|regex:/^\d{4}-\d{2}$/',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'salesman_id' => ['description' => 'Employee ID of the salesman', 'example' => 42],
            'period'      => ['description' => 'Commission period (YYYY-MM)', 'example' => '2026-09'],
        ];
    }
}
