<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Warehouse;

/**
 * Branch controller — Phase 4 master-data CRUD for the `branches` table.
 *
 * RC_ERP operates across multiple physical branches (Head Office, Patuatuli,
 * Nowabpur, Tarabo). Branches own warehouses and employees, and gate stock
 * movements, sales, and HR scopes.
 *
 * Inherits full CRUD from BaseMasterDataController.
 */
class BranchController extends BaseMasterDataController
{
    public function __construct()
    {
        $this->modelClass  = Branch::class;
        $this->label       = 'Branch';
        $this->routePrefix = 'admin.branches';
        $this->viewDir     = 'admin.branches';
        $this->searchFields = ['branch_code', 'branch_name'];
    }

    /**
     * Hero stats: active branches, total active employees, total warehouses.
     */
    protected function indexStats(): array
    {
        return [
            'active'           => Branch::active()->count(),
            'total_employees'  => Employee::active()->count(),
            'total_warehouses' => Warehouse::active()->count(),
        ];
    }

    /**
     * Eager-load employees + warehouses for the branch detail (show) page.
     */
    protected function detailWith(): array
    {
        return ['employees', 'warehouses'];
    }

    /**
     * Validation rules for create/update.
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'branch_code' => 'required|string|max:20|unique:branches,branch_code,' . $id,
            'branch_name' => 'required|string|max:100',
            'address'     => 'nullable|string',
            'phone'       => 'nullable|string|max:30',
            'email'       => 'nullable|email|max:100',
            'is_active'   => 'boolean',
        ];
    }
}
