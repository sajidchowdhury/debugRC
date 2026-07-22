<?php

namespace App\Http\Requests\PurchaseReceive;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for the AJAX getPoDetails endpoint.
 *
 * Used by the GRN create form to pre-fill from a selected PO. Only
 * requires po_id — the controller enforces branch isolation separately
 * (Phase 1) before returning the PO + items payload.
 */
class GetPoDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'po_id' => 'required|integer|exists:purchase_orders,id',
        ];
    }

    public function messages(): array
    {
        return [
            'po_id.required' => 'PO ID is required.',
            'po_id.exists'   => 'That PO no longer exists.',
        ];
    }
}
