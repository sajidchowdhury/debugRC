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
 *
 * Idempotency (G-088/G-089/G-090, PURCHASING-API-3): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of creating a duplicate return +
 * reversal journal. The field is `sometimes` (not `required`) so
 * already-deployed mobile clients that don't send the token are not
 * broken; new clients are expected to send it. See api-conventions.md
 * §11.1 for the canonical pattern.
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
            'idempotency_token'         => 'sometimes|string|uuid',
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
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-3)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
