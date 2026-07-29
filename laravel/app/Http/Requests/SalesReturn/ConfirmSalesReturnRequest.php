<?php

namespace App\Http\Requests\SalesReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 1.2 — Form Request for confirming a draft Sales Return.
 *
 * Confirmation triggers: stock IN (at original avg_cost) + GL revenue
 * reversal + GL COGS reversal + customer ledger credit + linked damage
 * write-offs. A confirm_reason is optional for parity with the confirm
 * modal.
 *
 * Mirrors PurchaseReturn's ConfirmPurchaseReturnRequest. Minimal — the
 * heavy lifting is in the service. Having a dedicated request class means
 * we can add withValidator hooks later (e.g. a stock-availability pre-check
 * if we add a separate confirm flow, or a re-validation that the return's
 * items still have returnable qty at confirm time).
 */
class ConfirmSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware (role + branch.isolation)
    }

    public function rules(): array
    {
        return [
            'confirm_reason' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_reason.max' => 'Confirm reason must be 500 characters or fewer.',
        ];
    }
}
