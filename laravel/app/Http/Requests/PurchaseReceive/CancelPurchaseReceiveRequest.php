<?php

namespace App\Http\Requests\PurchaseReceive;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for cancelling a draft GRN.
 *
 * A cancel_reason is mandatory because:
 *   1. If the GRN was already confirmed (stock IN + GL posted), the
 *      cancellation must reverse all three ledgers — reviewers need a
 *      reason in the audit trail.
 *   2. The audit log entry (purchase_receive_cancelled) records the
 *      reason verbatim so future reviewers don't need to dig into the
 *      GRN itself.
 */
class CancelPurchaseReceiveRequest extends FormRequest
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
            'cancel_reason.required' => 'Please provide a reason for cancelling this GRN.',
            'cancel_reason.max'      => 'Reason must be 500 characters or fewer.',
        ];
    }
}
