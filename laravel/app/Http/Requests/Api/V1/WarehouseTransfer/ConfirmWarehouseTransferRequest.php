<?php

namespace App\Http\Requests\Api\V1\WarehouseTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Confirm a draft warehouse transfer.
 *
 * Extracted from WarehouseTransferApiController::confirm() inline validate()
 * as part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * The confirm action applies the stock movement (dest IN, source OUT) for a
 * same-branch transfer. Cross-branch transfers are rejected upstream in the
 * controller + service — this FormRequest only owns the (very small) input
 * contract: an optional confirmation note.
 *
 * Authorization is handled by the `api.auth:manager,admin` route middleware
 * (the controller's confirm action is destructive — it applies stock). The
 * FormRequest's authorize() therefore returns true.
 */
class ConfirmWarehouseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth:manager,admin route middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_reason' => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'confirm_reason' => [
                'description' => 'Optional note about the confirmation (e.g. "verified by supervisor")',
                'example'     => 'Verified physical count before dispatch.',
            ],
        ];
    }
}
