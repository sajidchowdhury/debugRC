<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10 — Web Form Request for cancelling (reversing) a challan.
 *
 * Promotes the inline $request->validate() that used to live in
 * SalesChallanController::cancel into a typed Form Request.
 *
 * Rules:
 *   cancel_reason — required|string|min:5|max:500
 *                    (min 5 matches the Phase 8 Swal2 preConfirm guard
 *                    on the issue screen's Reverse button — single
 *                    source of truth for the minimum reason length.)
 *
 * The sales_returns guard (A19, Phase 9) lives in the SERVICE layer
 * (SalesChallanService::cancelChallan), NOT in this request validator,
 * because it depends on the challan's resolved sales_invoice_id which
 * is fetched inside the service's DB::transaction. The service throws
 * a RuntimeException if non-reversed confirmed returns exist; the
 * controller's try/catch surfaces it as a red error flash.
 *
 * RBAC (manager/admin only) is enforced by the route middleware.
 */
class CancelChallanWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is handled by the route middleware (role:manager, admin).
        return true;
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cancel_reason' => 'cancellation reason',
        ];
    }

    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'A cancellation reason is required.',
            'cancel_reason.min'      => 'The cancellation reason must be at least 5 characters.',
            'cancel_reason.max'      => 'The cancellation reason may not be greater than 500 characters.',
        ];
    }
}
