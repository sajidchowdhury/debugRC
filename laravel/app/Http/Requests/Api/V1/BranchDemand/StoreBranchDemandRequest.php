<?php

namespace App\Http\Requests\Api\V1\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a branch demand (inter-branch stock request).
 *
 * Extracted from BranchDemandApiController::store() inline validate().
 * The controller retains the same-branch guard (to_branch_id must differ
 * from the user's branch) AFTER validation; this FormRequest owns only
 * the input contract.
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
            'to_branch_id'       => 'required|integer|exists:branches,id',
            'demand_date'        => 'required|date',
            'notes'              => 'nullable|string|max:2000',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty'        => 'required|numeric|min:0.01',
            'items.*.notes'      => 'nullable|string|max:500',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'to_branch_id' => ['description' => 'Branch to send goods FROM (the supplying branch)', 'example' => 2],
            'demand_date'  => ['description' => 'Demand date (Y-m-d)', 'example' => '2025-01-21'],
            'notes'        => ['description' => 'Optional note', 'example' => 'Urgent restock'],
            'items'        => ['description' => 'Line items (min 1)', 'example' => [['product_id' => 10, 'qty' => 20]]],
        ];
    }
}
