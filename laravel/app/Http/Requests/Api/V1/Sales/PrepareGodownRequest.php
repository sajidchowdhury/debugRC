<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Prepare godown (assign warehouses to invoice items).
 *
 * This is step 1 of the challan workflow. It assigns warehouse_id to
 * each invoice item + dispatch row. Invoice status transitions from
 * draft → confirmed. No stock movement yet.
 *
 * Idempotency (PURCHASING-API-4, G7 Medium-risk): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of re-running the godown prepare
 * (which would otherwise hit the "invoice not draft" 409 path on the
 * second call). The field is `sometimes` (not `required`) for
 * backward-compat with deployed mobile clients. See api-conventions.md
 * §11.1 for the canonical pattern.
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
            'idempotency_token'          => 'sometimes|string|uuid',
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
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-4)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
