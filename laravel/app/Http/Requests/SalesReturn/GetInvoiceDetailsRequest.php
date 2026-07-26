<?php

namespace App\Http\Requests\SalesReturn;

use App\Models\SalesInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Phase 1.4 — Form Request for the AJAX getInvoiceDetails endpoint.
 *
 * Used by the Return create workspace to load a challan-issued invoice +
 * its items + per-item returnable_qty. Only requires invoice_id — the
 * withValidator hook enforces branch isolation (admin bypass) and the
 * challan-issued state gate before the controller builds the payload.
 *
 * Mirrors PurchaseReturn's GetReceiveDetailsRequest + the branch check
 * pattern from PurchaseReturnController::create().
 */
class GetInvoiceDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'invoice_id' => 'required|integer|exists:sales_invoices,id',
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_id.required' => 'Invoice ID is required.',
            'invoice_id.exists'   => 'That invoice no longer exists.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $invoiceId = (int) $this->input('invoice_id');
            if ($invoiceId <= 0) {
                return; // exists rule already caught it
            }

            $invoice = SalesInvoice::select(
                'id', 'branch_id', 'status', 'is_challan_issued', 'is_reversed'
            )->find($invoiceId);

            if (!$invoice) {
                return; // exists rule already flagged it
            }

            // State gate: must be challan-issued + not reversed (matches the
            // controller's existing where clauses on create + getInvoiceDetails).
            if ($invoice->is_reversed) {
                $validator->errors()->add(
                    'invoice_id',
                    'That invoice has been reversed and cannot be returned against.'
                );
                return;
            }
            if (!$invoice->is_challan_issued) {
                $validator->errors()->add(
                    'invoice_id',
                    'Returns require a completed challan. This invoice has not been challan-issued yet.'
                );
                return;
            }

            // Branch isolation (admin bypass).
            $user = $this->user();
            $sessionBranchId = (int) (session('branch_id') ?? $user?->getBranchId() ?? 0);
            $isAdmin = (bool) $user?->isAdmin();
            if (!$isAdmin && $sessionBranchId > 0
                && (int) $invoice->branch_id !== $sessionBranchId) {
                $validator->errors()->add(
                    'invoice_id',
                    'You do not have access to that invoice (different branch).'
                );
            }
        });
    }
}
