<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Confirm all calculated commission entries for a period
 * (admin only — month-end batch).
 *
 * Extracted from CommissionApiController::confirmPeriod() inline validate().
 *
 * POST /api/v1/sales/commission/confirm-period
 *   {"period":"2026-09"}
 *
 * Triggers GL posting (Dr Commission Expense / Cr Commission Payable) for
 * every salesman with non-zero net commission in the period. Entries below
 * config('commission.batch_minimum_amount') skip GL (just marked confirmed).
 */
class ConfirmCommissionPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'period' => ['description' => 'Commission period to confirm (YYYY-MM)', 'example' => '2026-09'],
        ];
    }
}
