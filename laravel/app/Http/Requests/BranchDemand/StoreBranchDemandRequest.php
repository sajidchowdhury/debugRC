<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Branch Demand Request — Phase 2.
 *
 * Validates the creation of a new branch demand.
 * The requester (debtor) creates a demand to the supplier (creditor).
 */
class StoreBranchDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_branch_id'       => 'required|integer|exists:branches,id|different:from_branch_id',
            'demand_date'        => 'required|date',
            'notes'              => 'nullable|string|max:2000',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.notes'      => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'to_branch_id.required' => 'Supplier branch is required.',
            'to_branch_id.different' => 'Supplier branch must be different from your branch.',
            'to_branch_id.exists' => 'Selected supplier branch does not exist.',
            'demand_date.required' => 'Demand date is required.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_id.required' => 'Product is required for each item.',
            'items.*.product_id.exists' => 'Selected product does not exist.',
            'items.*.qty.required' => 'Quantity is required for each item.',
            'items.*.qty.min' => 'Quantity must be at least 0.01.',
        ];
    }
}
