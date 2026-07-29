<?php

namespace App\Http\Requests\PurchaseReceive;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for confirming a draft GRN.
 *
 * Confirmation is the irreversible step: stock IN + GL Dr Inv/Cr AP +
 * supplier_ledger credit + PO received_qty update. A confirm_reason is
 * optional (legacy UI has a free-text "Notes" box on the confirm modal
 * that maps here) — kept for parity.
 */
class ConfirmPurchaseReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'confirm_reason' => 'nullable|string|max:500',
        ];
    }
}
