<?php

namespace App\Http\Requests\Api\V1\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Send goods for a branch demand (with warehouse routing).
 *
 * Extracted from BranchDemandApiController::send() inline validate().
 * Each line item specifies which source warehouse to pick from and which
 * destination warehouse to deliver to.
 */
class SendBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                     => 'required|array|min:1',
            'items.*.id'                => 'required|integer|exists:branch_demand_items,id',
            'items.*.from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.to_warehouse_id'   => 'required|integer|exists:warehouses,id',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'items' => ['description' => 'Line items with warehouse routing (min 1)', 'example' => [['id' => 1, 'from_warehouse_id' => 3, 'to_warehouse_id' => 5]]],
        ];
    }
}
