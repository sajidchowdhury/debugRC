<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Send Branch Demand Request — Phase 2.
 *
 * Validates the send-goods flow where the supplier branch's warehouse
 * manager selects per-item FROM/TO warehouses.
 *
 * - from_warehouse_id must belong to the SUPPLIER branch (to_branch_id)
 * - to_warehouse_id must belong to the REQUESTER branch (from_branch_id)
 * - Each item must reference an existing demand item
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
            'items'                        => 'required|array|min:1',
            'items.*.id'                   => 'required|integer|exists:branch_demand_items,id',
            'items.*.from_warehouse_id'    => 'required|integer|exists:warehouses,id',
            'items.*.to_warehouse_id'      => 'required|integer|exists:warehouses,id',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item with warehouse selection is required.',
            'items.min' => 'At least one item with warehouse selection is required.',
            'items.*.id.required' => 'Demand item ID is required.',
            'items.*.id.exists' => 'Selected demand item does not exist.',
            'items.*.from_warehouse_id.required' => 'Source warehouse (from) is required for each item.',
            'items.*.from_warehouse_id.exists' => 'Selected source warehouse does not exist.',
            'items.*.to_warehouse_id.required' => 'Destination warehouse (to) is required for each item.',
            'items.*.to_warehouse_id.exists' => 'Selected destination warehouse does not exist.',
        ];
    }
}
