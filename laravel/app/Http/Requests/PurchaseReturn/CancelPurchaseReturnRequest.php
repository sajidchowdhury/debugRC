<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for cancelling (reversing) a confirmed Return.
 *
 * A cancel_reason is mandatory because reversal restores stock (Good
 * lines only — Phase 5), reverses the GL journal, reverses the
 * supplier_ledger entry, and decrements the GRN return_qty. Reviewers
 * need to know why the reversal happened.
 *
 * The audit log entry (purchase_return_reversed) records the reason
 * verbatim.
 */
class CancelPurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => 'required|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'Please provide a reason for reversing this return.',
            'cancel_reason.max'      => 'Reason must be 500 characters or fewer.',
        ];
    }
}
