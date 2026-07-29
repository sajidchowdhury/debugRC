<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for updating an existing (draft) Purchase Order.
 *
 * Rules are identical to StorePurchaseOrderRequest — kept as a separate
 * class so that future divergence (e.g. stricter rules on status
 * transitions, or relaxed rules for partial updates) is trivial.
 */
class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware; canEdit() enforced in controller
    }

    public function rules(): array
    {
        return [
            'supplier_id'      => 'required|integer|exists:suppliers,id',
            'branch_id'        => 'required|integer|exists:branches,id',
            'warehouse_id'     => 'nullable|integer|exists:warehouses,id',
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
        return (new StorePurchaseOrderRequest())->messages();
    }
}
