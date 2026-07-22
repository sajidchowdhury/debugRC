<?php

namespace App\Http\Requests\PurchaseReceive;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for storing a new Purchase Receive (GRN).
 *
 * Extracted from the inline $request->validate() call that lived inside
 * PurchaseReceiveController::store() since Phase 3. Validation rules
 * are unchanged.
 *
 * Notes:
 *   - purchase_order_id is nullable (Direct GRN — no PO).
 *   - supplier_id is nullable on the form (auto-derived from PO when
 *     po_id is supplied) but the service layer enforces "supplier_id
 *     required when no purchase_order_id".
 *   - Per-line warehouse_id is required (a single GRN can write into
 *     multiple warehouses — this is the legacy "Direct Purchase" mode).
 *   - purchase_order_item_id is nullable (Direct GRN lines have no PO
 *     line linkage).
 */
class StorePurchaseReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'purchase_order_id'  => 'nullable|integer|exists:purchase_orders,id',
            'supplier_id'        => 'nullable|integer|exists:suppliers,id',
            'branch_id'          => 'nullable|integer|exists:branches,id',
            'warehouse_id'       => 'required|integer|exists:warehouses,id',
            'receive_date'       => 'required|date',
            'notes'              => 'nullable|string|max:1000',
            'discount_amount'    => 'nullable|numeric|min:0',
            'tax_amount'         => 'nullable|numeric|min:0',
            'items'              => 'required|array|min:1',
            'items.*.product_id'              => 'required|integer|exists:products,id',
            'items.*.warehouse_id'            => 'required|integer|exists:warehouses,id',
            'items.*.qty'                     => 'required|numeric|min:0.001',
            'items.*.rate'                    => 'required|numeric|min:0',
            'items.*.purchase_order_item_id'  => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Please select a default warehouse.',
            'warehouse_id.exists'   => 'The selected warehouse is not active.',
            'receive_date.required' => 'Receive date is required.',
            'receive_date.date'     => 'Receive date must be a valid date.',
            'items.required'        => 'At least one line item is required.',
            'items.min'             => 'At least one line item is required.',
            'items.*.product_id.required'    => 'Each line must have a product.',
            'items.*.product_id.exists'      => 'One of the selected products is not active.',
            'items.*.warehouse_id.required'  => 'Each line must have a warehouse.',
            'items.*.warehouse_id.exists'    => 'One of the selected warehouses is not active.',
            'items.*.qty.required'           => 'Each line must have a quantity.',
            'items.*.qty.min'                => 'Quantity must be greater than zero.',
            'items.*.rate.required'          => 'Each line must have a rate.',
            'items.*.rate.min'               => 'Rate cannot be negative.',
        ];
    }
}
