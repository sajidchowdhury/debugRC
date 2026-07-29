<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Required-reason request for destructive / state-changing
 * stock-take actions: reverse, re-open, reject, cancel.
 *
 * All four transitions require a human-entered reason (the audit trail must
 * record WHY). This shared request enforces a non-empty reason with a sane
 * length cap. The per-action field name is set by the controller via the
 * `field` property (default: 'reason') so the same request class serves
 * reverse_reason / reopen_reason / rejection_reason / cancel_reason.
 *
 * Usage in a controller:
 *   $validated = app(ReasonRequest::class)->validated();
 *   // or injected via DI after setting the field in route binding.
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'             => 'required|string|min:3|max:1000',
            // Alternate field names accepted for API ergonomics — callers can
            // use whichever matches the action (reverse_reason, reopen_reason,
            // rejection_reason, cancel_reason). At least one must be present;
            // the controller normalizes to 'reason'.
            'reverse_reason'     => 'sometimes|string|min:3|max:1000',
            'reopen_reason'      => 'sometimes|string|min:3|max:1000',
            'rejection_reason'   => 'sometimes|string|min:3|max:2000',
            'cancel_reason'      => 'sometimes|string|min:3|max:1000',
        ];
    }

    /**
     * Resolve the reason from whichever field the caller supplied.
     * Priority: reason > reverse_reason > reopen_reason > rejection_reason > cancel_reason.
     */
    public function getReason(): string
    {
        $validated = $this->validated();
        return (string) (
            $validated['reason']
            ?? $validated['reverse_reason']
            ?? $validated['reopen_reason']
            ?? $validated['rejection_reason']
            ?? $validated['cancel_reason']
            ?? ''
        );
    }

    public function bodyParameters(): array
    {
        return [
            'reason' => [
                'description' => 'Human-entered reason for the action (audit trail). Required, 3–1000 chars.',
                'example' => 'Counter found damaged stock not reflected in system; re-count needed.',
            ],
        ];
    }
}
