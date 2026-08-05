<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for storing a new Purchase Order.
 *
 * Extracted verbatim from the inline $request->validate() call that lived
 * inside PurchaseOrderController::store() since Phase 2. Validation rules
 * are unchanged; only the location has moved so they can be reused,
 * introspected by `php artisan route:list`, and tested in isolation.
 *
 * branch_id is intentionally still required here — the controller's
 * resolveBranchIdForWrite() will override it for non-admins to enforce
 * Phase 1 branch isolation.
 */
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware (admin, manager, warehouse_manager, accountant)
    }

    public function rules(): array
    {
        return [
            'supplier_id'      => 'required|integer|exists:suppliers,id',
            'branch_id'        => 'required|integer|exists:branches,id',
            // PURCHASING-API-2 (G-123/G-124): warehouse_id is now required.
            // Previously nullable (mismatch with purchase_receives.warehouse_id
            // which is NOT NULL). The schema is now NOT NULL too — this rule
            // aligns the FormRequest with the schema invariant.
            'warehouse_id'     => 'required|integer|exists:warehouses,id',
            'po_date'          => 'required|date',
            'expected_date'    => 'nullable|date',
            'notes'            => 'nullable|string|max:1000',
            'discount_amount'  => 'nullable|numeric|min:0',
            'tax_amount'       => 'nullable|numeric|min:0',
            'items'            => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0.001',
            'items.*.rate'       => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Please select a supplier.',
            'supplier_id.exists'   => 'The selected supplier is not active.',
            'branch_id.required'   => 'Please select a branch.',
            'branch_id.exists'     => 'The selected branch is not active.',
            'po_date.required'     => 'PO date is required.',
            'po_date.date'         => 'PO date must be a valid date.',
            'items.required'       => 'At least one line item is required.',
            'items.min'            => 'At least one line item is required.',
            'items.*.product_id.required' => 'Each line must have a product.',
            'items.*.product_id.exists'   => 'One of the selected products is not active.',
            'items.*.qty.required'        => 'Each line must have a quantity.',
            'items.*.qty.min'             => 'Quantity must be greater than zero.',
            'items.*.rate.required'       => 'Each line must have a rate.',
            'items.*.rate.min'            => 'Rate cannot be negative.',
        ];
    }
}
