<?php

namespace App\Http\Requests\Api\V1\WarehouseTransfer;

use App\Rules\WarehouseBelongsToBranch;
use App\Rules\WarehouseTransferItemHasAvailableStock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a draft warehouse transfer.
 *
 * Extracted from WarehouseTransferApiController::store() inline validate().
 * Preserves the custom Rule objects (WarehouseBelongsToBranch +
 * WarehouseTransferItemHasAvailableStock) which need request context
 * (the auth user's branch + the from_warehouse_id input) — these are
 * accessed via $this->user() and $this->input() inside rules().
 *
 * The controller retains the same-branch defense-in-depth check AFTER
 * validation passes; this FormRequest only owns the input contract.
 */
class StoreWarehouseTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userBranchId = $this->user() ? (int) $this->user()->getBranchId() : null;
        $fromWarehouseId = (int) $this->input('from_warehouse_id');

        return [
            'from_warehouse_id' => [
                'required', 'integer', 'exists:warehouses,id',
                new WarehouseBelongsToBranch($userBranchId, 'branch'),
            ],
            'to_warehouse_id' => [
                'required', 'integer', 'exists:warehouses,id',
                'different:from_warehouse_id',
                new WarehouseBelongsToBranch($userBranchId, 'branch'),
            ],
            'transfer_date' => 'required|date',
            'notes'         => 'nullable|string|max:1000',
            'items'         => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => [
                'required', 'numeric', 'min:0.001',
                new WarehouseTransferItemHasAvailableStock($fromWarehouseId),
            ],
            'items.*.rate' => 'nullable|numeric|min:0',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'from_warehouse_id' => ['description' => 'Source warehouse (must belong to user\'s branch)', 'example' => 1],
            'to_warehouse_id'   => ['description' => 'Destination warehouse (same branch, different from source)', 'example' => 2],
            'transfer_date'     => ['description' => 'Transfer date (Y-m-d)', 'example' => '2025-01-21'],
            'notes'             => ['description' => 'Optional note', 'example' => 'Restocking electronics'],
            'items'             => ['description' => 'Line items to transfer (min 1)', 'example' => [['product_id' => 10, 'qty' => 5, 'rate' => 100.00]]],
        ];
    }
}
