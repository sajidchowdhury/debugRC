<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Warehouse;

/**
 * Warehouse controller — Phase 4 master-data CRUD for the `warehouses` table.
 *
 * Warehouses are stock SSOT locations tied to a branch. They gate godown
 * balances, challans, inter-warehouse transfers, and stock adjustments.
 *
 * Inherits full CRUD from BaseMasterDataController.
 */
class WarehouseController extends BaseMasterDataController
{
    public function __construct()
    {
        $this->modelClass  = Warehouse::class;
        $this->label       = 'Warehouse';
        $this->routePrefix = 'admin.warehouses';
        $this->viewDir     = 'admin.warehouses';
        $this->searchFields = ['warehouse_code', 'warehouse_name'];
    }

    /**
     * Hero stats: active warehouses, by-branch breakdown.
     *
     * `by_branch` is keyed by branch_id; the view resolves names via the
     * eager-loaded `branch` relation.
     */
    protected function indexStats(): array
    {
        return [
            'active'   => Warehouse::active()->count(),
            'by_branch' => Warehouse::active()
                ->with('branch')
                ->selectRaw('branch_id, count(*) as count')
                ->groupBy('branch_id')
                ->pluck('count', 'branch_id'),
        ];
    }

    /**
     * Eager-load the parent branch on the index listing.
     */
    protected function indexWith(): array
    {
        return ['branch'];
    }

    /**
     * Eager-load the branch on the detail / edit screens.
     */
    protected function detailWith(): array
    {
        return ['branch'];
    }

    /**
     * Form dropdown data: active branches for the warehouse->branch link.
     */
    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
        ];
    }

    /**
     * Validation rules for create/update.
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'warehouse_code' => 'required|string|max:30|unique:warehouses,warehouse_code,' . $id,
            'warehouse_name' => 'required|string|max:100',
            'branch_id'      => 'required|exists:branches,id',
            'location'       => 'nullable|string',
            'is_active'      => 'boolean',
        ];
    }
}
