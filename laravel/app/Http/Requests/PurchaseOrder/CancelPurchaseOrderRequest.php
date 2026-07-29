<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for cancelling a Purchase Order.
 *
 * Only draft or sent POs can be cancelled (status check happens in the
 * service layer — PurchaseOrderService::cancelOrder throws if not). A
 * cancel_reason is mandatory — the audit log entry records it so future
 * reviewers know why the PO was abandoned.
 */
class CancelPurchaseOrderRequest extends FormRequest
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
            'cancel_reason.required' => 'Please provide a reason for cancelling this PO.',
            'cancel_reason.max'      => 'Reason must be 500 characters or fewer.',
        ];
    }
}
