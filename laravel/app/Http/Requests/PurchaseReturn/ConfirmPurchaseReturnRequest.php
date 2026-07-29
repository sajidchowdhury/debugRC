<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for confirming a draft Return.
 *
 * Confirmation triggers: stock OUT (Good only — Phase 5) + GL Dr AP /
 * Cr Inventory + supplier_ledger debit + GRN return_qty update. A
 * confirm_reason is optional for parity with the GRN confirm modal.
 */
class ConfirmPurchaseReturnRequest extends FormRequest
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
