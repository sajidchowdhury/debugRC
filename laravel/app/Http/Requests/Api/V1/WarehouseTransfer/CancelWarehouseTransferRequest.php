<?php

namespace App\Http\Requests\Api\V1\WarehouseTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Cancel / reverse a warehouse transfer.
 *
 * Extracted from WarehouseTransferApiController::cancel() inline validate()
 * as part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * If the transfer is still a draft, the cancel marks it cancelled (no stock
 * reversal). If the transfer is already confirmed, the cancel REVERSES the
 * stock movement (dest OUT first, then source IN — Phase 3 reversal safety)
 * + reverses any GL journals. Demand-linked transfers cannot be cancelled
 * here (they must go through the Branch Demand module).
 *
 * The `cancel_reason` is REQUIRED (not optional like confirm_reason) because
 * cancelling an applied transfer is destructive + auditable — the reason must
 * be on record for the branch_ledger audit trail.
 *
 * Authorization is handled by the `api.auth:manager,admin` route middleware
 * (cancel may reverse stock/GL). The FormRequest's authorize() returns true.
 */
class CancelWarehouseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth:manager,admin route middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => 'required|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'cancel_reason' => [
                'description' => 'Reason for cancelling the transfer (required — auditable for stock/GL reversals)',
                'example'     => 'Wrong destination warehouse — stock not yet dispatched.',
            ],
        ];
    }
}
