<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Approve a submitted stock-take session via the API.
 *
 * Approval comments are optional (the approver can just approve, or add a
 * note for the audit trail). The service enforces segregation of duties:
 * the approver CANNOT be the same user who submitted (Phase 4).
 */
class ApproveSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approval_comments' => 'nullable|string|max:2000',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'approval_comments' => [
                'description' => 'Optional note for the audit trail.',
                'example' => 'Variance within acceptable tolerance. Approved.',
            ],
        ];
    }
}
