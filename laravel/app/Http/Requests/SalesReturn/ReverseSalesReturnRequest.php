<?php

namespace App\Http\Requests\SalesReturn;

use App\Services\Sales\SalesReturnReversalGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Phase 1.3 — Form Request for reversing a confirmed Sales Return.
 *
 * A reverse_reason is mandatory (min 5 chars — matches legacy's
 * 5-char minimum, enforced both client-side via SweetAlert2 inputValidator
 * and server-side here). Reversal restores stock, reverses the GL journals,
 * reverses the customer_ledger entry, and reverses linked damage write-offs.
 *
 * THE BIG ONE — withValidator stock-reversal pre-check (ported from legacy's
 * getStockReversalBlockReason + buildStockReversalPreview):
 *
 *   Before the service opens its DB::transaction, this hook checks that each
 *   warehouse has enough on-hand stock to absorb the reversal (reversal =
 *   stock OUT of the qty that was restored at confirm time). If any
 *   warehouse is short, the user sees a friendly 422 listing ALL blocking
 *   reasons — instead of a mid-transaction RuntimeException with a
 *   less-helpful message and a risk of partial-transaction.
 *
 * Phase 6.1: the guard now exposes getBlockMessages() (formatted strings,
 * status + stock shortages) for this Form Request, getBlockReasons()
 * (structured tuples) for the reverse-preview endpoint, and getPreview()
 * (full snapshot). The service also calls the guard inside the transaction
 * as defense-in-depth (SalesReturnService::reverseReturn).
 */
class ReverseSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware (role + branch.isolation)
    }

    public function rules(): array
    {
        return [
            'reverse_reason' => 'required|string|min:5|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reverse_reason.required' => 'Please provide a reason for reversing this return.',
            'reverse_reason.min'      => 'Reason must be at least 5 characters.',
            'reverse_reason.max'      => 'Reason must be 500 characters or fewer.',
        ];
    }

    public function attributes(): array
    {
        return [
            'reverse_reason' => 'reason',
        ];
    }

    /**
     * Stock-reversal pre-check. Runs after the reverse_reason rule passes.
     * Adds a validation error (-> 422) for EACH blocking reason, so the
     * user sees every shortage in one round-trip.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $returnId = (int) $this->route('id');
            if ($returnId <= 0) {
                return; // route model binding / 404 handles a missing id
            }

            $guard = app(SalesReturnReversalGuard::class);
            // Phase 6.1 — getBlockMessages() returns formatted strings (status +
            // stock shortages), ready to attach as validation errors.
            $messages = $guard->getBlockMessages($returnId);

            foreach ($messages as $message) {
                // Attach to reverse_reason so the error renders next to the
                // reason field in the modal (not as a generic form error).
                $validator->errors()->add('reverse_reason', $message);
            }
        });
    }
}
