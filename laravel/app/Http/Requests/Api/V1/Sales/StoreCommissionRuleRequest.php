<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a commission rule (admin only).
 *
 * Extracted from CommissionApiController::storeRule() inline validate().
 * Covers all 4 rule types (flat / tiered / product_group / target_bonus)
 * with conditional nested validation for tiers, product_groups, targets.
 */
class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'salesman_id' => 'required|integer|exists:employees,id',
            'rule_type'   => 'required|in:flat,tiered,product_group,target_bonus',
            'rate'        => 'required|numeric|min:0|max:100',
            'effective_from' => 'nullable|date',
            'effective_to'   => 'nullable|date|after:effective_from',
            'branch_id'   => 'nullable|integer|exists:branches,id',
            'notes'       => 'nullable|string|max:500',
            'tiers'       => 'required_if:rule_type,tiered|array',
            'tiers.*.threshold' => 'required_with:tiers|numeric|min:0',
            'tiers.*.rate'      => 'required_with:tiers|numeric|min:0|max:100',
            'product_groups' => 'required_if:rule_type,product_group|array',
            'product_groups.*.product_group_id' => 'required_with:product_groups|integer|exists:product_groups,id',
            'product_groups.*.rate'             => 'required_with:product_groups|numeric|min:0|max:100',
            'targets'      => 'required_if:rule_type,target_bonus|array',
            'targets.*.target_amount' => 'required_with:targets|numeric|min:0',
            'targets.*.bonus_rate'    => 'required_with:targets|numeric|min:0|max:100',
            'targets.*.period'        => 'nullable|in:monthly,quarterly,yearly',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'salesman_id'   => ['description' => 'Employee ID of the salesman', 'example' => 42],
            'rule_type'     => ['description' => 'Commission structure type', 'example' => 'tiered'],
            'rate'          => ['description' => 'Base/default commission rate (percentage)', 'example' => 1.5],
            'effective_from' => ['description' => 'Start date (Y-m-d). Defaults to today if omitted.', 'example' => '2025-01-01'],
            'effective_to'  => ['description' => 'End date (Y-m-d). NULL = open-ended.', 'example' => null],
            'branch_id'     => ['description' => 'Branch scope. NULL = applies to all branches.', 'example' => null],
            'notes'         => ['description' => 'Free-form note', 'example' => 'Q1 2025 rate increase'],
            'tiers'         => ['description' => 'Required when rule_type=tiered', 'example' => [['threshold' => 50000, 'rate' => 1.0]]],
            'product_groups' => ['description' => 'Required when rule_type=product_group', 'example' => [['product_group_id' => 2, 'rate' => 2.0]]],
            'targets'       => ['description' => 'Required when rule_type=target_bonus', 'example' => [['target_amount' => 100000, 'bonus_rate' => 2.0, 'period' => 'monthly']]],
        ];
    }
}
