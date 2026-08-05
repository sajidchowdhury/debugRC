<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin web — Reject an approval request.
 *
 * Extracted from ApprovalController::reject() inline validate().
 *
 * POST /admin/approvals/{id}/reject
 *   body: reason (REQUIRED, 3-500 chars)
 *
 * WORKFLOWS-AUDIT-1 (G-176).
 */
class RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:3|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'reason' => ['description' => 'Required rejection rationale (3-500 chars)', 'example' => 'Amount exceeds Q3 budget — please resubmit at lower value.'],
        ];
    }
}
