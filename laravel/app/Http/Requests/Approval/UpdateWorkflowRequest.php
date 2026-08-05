<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin web — Update an approval workflow's settings.
 *
 * Extracted from ApprovalController::updateWorkflow() inline validate().
 *
 * POST /admin/approvals/workflows/{id}
 *   body: is_active (bool), min_amount (numeric>=0), name (string<=100),
 *         description (string<=500, nullable)
 *
 * WORKFLOWS-AUDIT-1 (G-176).
 */
class UpdateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active'   => 'boolean',
            'min_amount'  => 'numeric|min:0',
            'name'        => 'string|max:100',
            'description' => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'is_active'   => ['description' => 'Whether the workflow is active', 'example' => true],
            'min_amount'  => ['description' => 'Minimum entity amount that triggers this workflow (BDT)', 'example' => 50000],
            'name'        => ['description' => 'Workflow display name', 'example' => 'Manual Journal Approval'],
            'description' => ['description' => 'Optional workflow description', 'example' => 'Default approval workflow for manual journal entries.'],
        ];
    }
}
