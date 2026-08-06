<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Post a stock-take session (apply variances + post the GL
 * journal entry).
 *
 * Extracted from StockTakeSessionApiController::post() inline validate() as
 * part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * The post action is destructive — it applies counted-vs-system variances to
 * physical_qty (adjustment IN or OUT per product) and posts the GL journal
 * (Dr/Cr inventory ledger vs adjustment gain/loss ledger). The optional
 * `post_reason` is recorded on the session for audit-trail purposes.
 *
 * Authorization is handled by the outer `api.auth` route middleware + the
 * StockTakeService's internal state-machine guards (the session must be in
 * "approved" state to post). The FormRequest's authorize() returns true.
 */
class PostSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth route middleware + service-level guards.
        return true;
    }

    public function rules(): array
    {
        return [
            'post_reason' => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'post_reason' => [
                'description' => 'Optional note recorded on the session when it is posted (e.g. "variance approved by branch manager")',
                'example'     => 'Variance within tolerance — approved by branch manager.',
            ],
        ];
    }
}
