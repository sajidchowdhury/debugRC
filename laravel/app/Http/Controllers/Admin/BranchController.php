<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

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

    /**
     * Phase 3: Can this branch be safely deactivated?
     * Mirrors legacy BranchModel::canDeactivateBranch() + getBranchUsage().
     *
     * Checks:
     *   - No active warehouses assigned to this branch
     *   - No active employees assigned to this branch
     *   - No open (non-reversed) sales invoices for this branch
     *   - No pending branch demands involving this branch
     *   - No active user accounts linked to employees in this branch
     */
    protected function canDeactivate($item): array
    {
        $branchId = $item->id;

        // Count active warehouses.
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->count();

        // Count active employees.
        $employees = DB::table('employees')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->count();

        // Count open sales invoices (not reversed, not cancelled).
        $openInvoices = DB::table('sales_invoices')
            ->where('branch_id', $branchId)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->count();

        // Count pending branch demands.
        $pendingDemands = DB::table('branch_demands')
            ->where('status', 'pending')
            ->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)
                  ->orWhere('to_branch_id', $branchId);
            })
            ->count();

        // Count active user accounts linked to employees in this branch.
        $activeUsers = DB::table('users as u')
            ->join('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('e.branch_id', $branchId)
            ->where('u.is_active', true)
            ->where('e.is_active', true)
            ->whereNull('u.deleted_at')
            ->count();

        // Build deactivation message if any blockers exist.
        $parts = [];
        if ($warehouses > 0)      $parts[] = "{$warehouses} active warehouse(s)";
        if ($employees > 0)       $parts[] = "{$employees} active employee(s)";
        if ($openInvoices > 0)    $parts[] = "{$openInvoices} open sales invoice(s)";
        if ($pendingDemands > 0)  $parts[] = "{$pendingDemands} pending branch demand(s)";
        if ($activeUsers > 0)     $parts[] = "{$activeUsers} active user account(s)";

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this branch. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
