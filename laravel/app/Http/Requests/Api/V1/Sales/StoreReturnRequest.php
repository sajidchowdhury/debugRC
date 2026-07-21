<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a sales return (two-phase: created → confirmed).
 *
 * On creation, no stock movement or GL occurs. On confirmation, stock
 * comes back IN at ORIGINAL avg_cost (not current avg_cost) and COGS
 * is reversed exactly. This is the correctness-critical rule per
 * avg_cost_rule.md §3.
 */
class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_invoice_id'          => 'required|integer|exists:sales_invoices,id',
            'customer_id'               => 'required|integer|exists:customers,id',
            'return_date'               => 'required|date',
            'reason'                    => 'nullable|string|max:500',
            'items'                     => 'required|array|min:1',
            'items.*.product_id'        => 'required|integer|exists:products,id',
            'items.*.qty'               => 'required|numeric|min:0.001',
            'items.*.rate'              => 'required|numeric|min:0',
            'items.*.warehouse_id'      => 'required|integer|exists:warehouses,id',
            'items.*.damage_invoice_id' => 'nullable|integer|exists:damage_invoices,id',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'sales_invoice_id' => ['description' => 'Original invoice being returned against', 'example' => 42],
            'return_date'      => ['description' => 'Return date', 'example' => '2025-01-22'],
            'items'            => [
                'description' => 'Line items being returned — qty/rate must match original invoice items',
                'example' => [
                    ['product_id' => 10, 'qty' => 2, 'rate' => 120.50, 'warehouse_id' => 2],
                ],
            ],
        ];
    }
}
