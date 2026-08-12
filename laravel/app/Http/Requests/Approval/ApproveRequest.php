<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin web — Approve an approval request.
 *
 * Extracted from ApprovalController::approve() (which had NO validation —
 * `comments` was read raw via `$request->input('comments')`).
 *
 * POST /admin/approvals/{id}/approve
 *   body: comments (optional, free-form)
 *
 * WORKFLOWS-AUDIT-1 (G-176).
 */
class ApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comments' => 'nullable|string|max:1000',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'comments' => ['description' => 'Optional approver comments', 'example' => 'Looks good — approved.'],
        ];
    }
}
