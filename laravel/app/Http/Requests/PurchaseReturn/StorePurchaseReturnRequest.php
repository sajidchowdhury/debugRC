<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for storing a new Purchase Return.
 *
 * Extracted from PurchaseReturnController::store(). Always against a
 * confirmed GRN (purchase_receive_id required). Per-line:
 *   - product_id  (required)
 *   - warehouse_id (required — even for Damage lines, kept for symmetry)
 *   - qty          (required, > 0)
 *   - rate         (nullable — server falls back to original receive rate)
 *   - purchase_receive_item_id (nullable — needed for return_qty tracking)
 *   - condition    (nullable, 'Good' or 'Damage' — Phase 5)
 *
 * The dual stock cap (return_qty ≤ GRN returnable AND ≤ warehouse
 * available) is enforced in the service layer for Good lines. Damage
 * lines skip the warehouse availability check (Phase 5 invariant —
 * Damage = no stock movement).
 */
class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'purchase_receive_id' => 'required|integer|exists:purchase_receives,id',
            'return_date'         => 'required|date',
            'reason'              => 'nullable|string|max:1000',
            'items'               => 'required|array|min:1',
            'items.*.product_id'                  => 'required|integer|exists:products,id',
            'items.*.warehouse_id'                => 'required|integer|exists:warehouses,id',
            'items.*.qty'                         => 'required|numeric|min:0.001',
            'items.*.rate'                        => 'nullable|numeric|min:0',
            'items.*.purchase_receive_item_id'    => 'nullable|integer',
            // Phase 5: Damage condition — Damage lines skip stock OUT.
            'items.*.condition'                   => 'nullable|in:Good,Damage',
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_receive_id.required' => 'A GRN reference is required.',
            'purchase_receive_id.exists'   => 'That GRN no longer exists.',
            'return_date.required'         => 'Return date is required.',
            'return_date.date'             => 'Return date must be a valid date.',
            'items.required'               => 'At least one line item is required.',
            'items.min'                    => 'At least one line item is required.',
            'items.*.product_id.required'  => 'Each line must have a product.',
            'items.*.product_id.exists'    => 'One of the selected products is not active.',
            'items.*.warehouse_id.required'=> 'Each line must have a warehouse.',
            'items.*.warehouse_id.exists'  => 'One of the selected warehouses is not active.',
            'items.*.qty.required'         => 'Each line must have a quantity.',
            'items.*.qty.min'              => 'Quantity must be greater than zero.',
            'items.*.rate.min'             => 'Rate cannot be negative.',
            'items.*.condition.in'         => 'Condition must be either "Good" or "Damage".',
        ];
    }
}
