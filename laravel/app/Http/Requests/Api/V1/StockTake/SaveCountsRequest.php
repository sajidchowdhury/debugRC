<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Save physical counts for a warehouse via the API.
 *
 * Mirrors the web controller's saveCounts() validation. The `counts` array
 * is keyed by product_id → physical_qty. Optional `reasons` is keyed by
 * product_id → free-text reason (for variance explanation).
 *
 * The service (StockTakeService::saveCounts) verifies the session + warehouse
 * exist, the session is in counting state, and each product_id is in the
 * warehouse's stock_take_items set.
 */
class SaveCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counts'          => 'required|array',
            'counts.*'        => 'numeric',
            'reasons'         => 'nullable|array',
            'reasons.*'       => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'counts' => [
                'description' => 'Map of product_id → physical_qty. Null/missing keys are left unchanged.',
                'example' => ['10' => 48, '11' => 0, '12' => 33.5],
            ],
            'reasons' => [
                'description' => 'Optional map of product_id → reason text for variance explanation.',
                'example' => ['10' => 'Damaged stock found during count'],
            ],
        ];
    }
}
