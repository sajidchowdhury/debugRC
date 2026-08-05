<?php

namespace App\Http\Requests\Api\V1\StockAdjustment;

use App\Models\StockAdjustment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a draft stock adjustment.
 *
 * Extracted from StockAdjustmentApiController::store() inline validate().
 * References the StockAdjustment::ADJUSTMENT_CATEGORIES constant for the
 * category enum (Phase 2 — category is mandatory).
 *
 * The controller retains the Policy::canSubmit() role check AFTER validation;
 * this FormRequest only owns the input contract.
 *
 * Idempotency (G-088/G-089/G-090, PURCHASING-API-3): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of creating a duplicate draft
 * adjustment. The field is `sometimes` (not `required`) for
 * backward-compat with deployed mobile clients. See api-conventions.md
 * §11.1 for the canonical pattern.
 */
class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id'        => 'required|integer|exists:warehouses,id',
            'adjustment_type'     => 'required|in:increase,decrease',
            'adjustment_category' => 'required|in:' . implode(',', StockAdjustment::ADJUSTMENT_CATEGORIES),
            'adjustment_date'     => 'required|date',
            'reason'              => 'nullable|string|max:1000',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer|exists:products,id',
            'items.*.qty'         => 'required|numeric|min:0.001',
            'items.*.uom_id'      => 'nullable|integer|exists:units_of_measure,id',
            'items.*.rate'        => 'nullable|numeric|min:0',
            'items.*.reason'      => 'nullable|string|max:500',
            'idempotency_token'   => 'sometimes|string|uuid',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'warehouse_id'        => ['description' => 'Warehouse where stock is adjusted', 'example' => 1],
            'adjustment_type'     => ['description' => 'increase or decrease', 'example' => 'increase'],
            'adjustment_category' => ['description' => 'One of StockAdjustment::ADJUSTMENT_CATEGORIES', 'example' => 'opening_balance'],
            'adjustment_date'     => ['description' => 'Adjustment date (Y-m-d)', 'example' => '2025-01-21'],
            'reason'              => ['description' => 'Optional note', 'example' => 'Initial stock setup'],
            'items'               => ['description' => 'Line items (min 1)', 'example' => [['product_id' => 10, 'qty' => 5]]],
            'idempotency_token'   => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-3)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}
