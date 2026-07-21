<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Prepare godown (assign warehouses to invoice items).
 *
 * This is step 1 of the challan workflow. It assigns warehouse_id to
 * each invoice item + dispatch row. Invoice status transitions from
 * draft → confirmed. No stock movement yet.
 */
class PrepareGodownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments'                => 'required|array|min:1',
            'assignments.*.product_id'   => 'required|integer|exists:products,id',
            'assignments.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'assignments.*.qty'          => 'nullable|numeric|min:0.001',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'assignments' => [
                'description' => 'Array of warehouse assignments for each product in the invoice',
                'example' => [
                    ['product_id' => 10, 'warehouse_id' => 2, 'qty' => 5],
                    ['product_id' => 15, 'warehouse_id' => 2, 'qty' => 3],
                ],
            ],
        ];
    }
}
