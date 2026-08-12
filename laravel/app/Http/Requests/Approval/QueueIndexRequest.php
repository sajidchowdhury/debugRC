<?php

namespace App\Http\Requests\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin web — Filter the approval queue by entity_type.
 *
 * Extracted from ApprovalController::queue() (which accepted `entity_type`
 * from user input with NO validation).
 *
 * GET /admin/approvals?entity_type=manual_journal
 *
 * The entity_type is validated against the known set of approval-enabled
 * entity types so a forged query string can't cause downstream errors.
 *
 * WORKFLOWS-AUDIT-1 (G-176).
 */
class QueueIndexRequest extends FormRequest
{
    /**
     * The known set of entity types that the approval engine supports.
     * Mirrors the entity_type values seeded in approval_workflows +
     * the ApprovalService::ENTITY_TYPES constant (if any).
     */
    public const ENTITY_TYPES = [
        'manual_journal',
        'stock_adjustment',
        'stock_take_session',
        'damage_invoice',
        'purchase_order',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => 'nullable|string|in:' . implode(',', self::ENTITY_TYPES),
        ];
    }

    public function queryParameters(): array
    {
        return [
            'entity_type' => ['description' => 'Filter pending queue by entity type', 'example' => 'manual_journal'],
        ];
    }
}
