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
     * Phase 5: Added code pattern validation matching legacy CODE_PATTERN.
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'branch_code' => 'required|string|max:20|regex:/^[A-Za-z0-9\-_.]+$/|unique:branches,branch_code,' . $id,
            'branch_name' => 'required|string|max:100',
            'address'     => 'nullable|string',
            'phone'       => 'nullable|string|max:20',
            'email'       => 'nullable|email|max:100',
            'is_active'   => 'boolean',
        ];
    }

    /**
     * Phase 5: Override store() to uppercase branch_code (legacy normalization).
     */
    public function store(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $validated['branch_code'] = strtoupper(trim($validated['branch_code']));
        $validated['branch_name'] = trim($validated['branch_name']);
        if (isset($validated['phone'])) $validated['phone'] = trim($validated['phone']);
        if (isset($validated['email'])) $validated['email'] = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Set created_by from the authenticated user.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing(($this->modelClass)::make()->getTable());
        if (in_array('created_by', $columns)) {
            $validated['created_by'] = \Illuminate\Support\Facades\Auth::id();
        }

        try {
            $model = ($this->modelClass)::create($validated);
            return redirect()->route("{$this->routePrefix}.show", $model)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 5: Override update() to uppercase branch_code + run deactivation
     * safety check if is_active is being set to false during the update.
     */
    public function update(\Illuminate\Http\Request $request, int $id)
    {
        $item = ($this->modelClass)::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

        // Normalize fields (legacy behavior).
        $validated['branch_code'] = strtoupper(trim($validated['branch_code']));
        $validated['branch_name'] = trim($validated['branch_name']);
        if (isset($validated['phone'])) $validated['phone'] = trim($validated['phone']);
        if (isset($validated['email'])) $validated['email'] = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Phase 5: If is_active is being set to false, run deactivation safety check.
        if (isset($validated['is_active']) && !$validated['is_active'] && $item->is_active) {
            $deactivationCheck = $this->canDeactivate($item);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $item->update($validated);
            return redirect()->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
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
