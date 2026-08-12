<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Update (autosave) a single stock-take item's physical_qty.
 *
 * Extracted from StockTakeItemApiController::update() inline validate() as
 * part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * This is the per-item PUT endpoint used by the mobile autosave flow: the
 * counter taps a quantity into a line + the client PUTs it immediately
 * (without waiting for a "Save all" action). The service's saveCounts guard
 * fires downstream (session must be in "counting" state, product must be in
 * the warehouse's item set, etc.).
 *
 * `physical_qty` accepts any numeric value (including 0 + decimals for
 * fractionally-counted products like produce by weight). Negative values are
 * rejected by the numeric rule (the regex `^-?[0-9]+(\.[0-9]+)?$` would
 * allow them — the Laravel `numeric` rule does too, but the service layer
 * guards against negative physical_qty at the domain level).
 *
 * `reason` is an optional variance note recorded against the line (the
 * controller writes it to stock_take_items.reason after saveCounts succeeds).
 *
 * Authorization is handled by the outer `api.auth` route middleware +
 * StockTakeService's session-state guards. The FormRequest's authorize()
 * returns true.
 */
class UpdateStockTakeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth route middleware + service-level guards.
        return true;
    }

    public function rules(): array
    {
        return [
            'physical_qty' => 'required|numeric',
            'reason'       => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'physical_qty' => [
                'description' => 'Counted physical quantity (autosaved on each PUT — service guards against session-state + warehouse membership)',
                'example'     => 48,
            ],
            'reason' => [
                'description' => 'Optional variance reason recorded against the line',
                'example'     => 'Damaged carton — 2 units short.',
            ],
        ];
    }
}
