<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Cancel / reverse a sales challan (append-only reversal).
 *
 * Extracted from SalesChallanApiController::cancel() inline validate() as
 * part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * The cancel action is destructive — it reverses the stock OUT (dest IN,
 * source OUT — Phase 3 reversal safety) and reverses the COGS GL journal
 * entry that was posted at challan-issue time. The `reason` is REQUIRED and
 * enforces a minimum length of 10 chars because cancelling an issued challan
 * is an auditable stock/GL event — the reason must be substantive (not just
 * "oops"). The max length of 500 prevents log bloat.
 *
 * Authorization is handled by the controller via `SalesAccess::assertBranchAccessible()`
 * (the challan's branch must match the API user's branch) — this FormRequest
 * only owns the input contract. The route-level `api.auth` middleware handles
 * token + role authentication.
 */
class CancelSalesChallanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth route middleware + SalesAccess branch check.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'reason' => [
                'description' => 'Reason for cancelling the challan (required — minimum 10 chars for audit-trail substance; reverses stock OUT + COGS GL)',
                'example'     => 'Customer refused delivery — stock returned to warehouse intact.',
            ],
        ];
    }
}
