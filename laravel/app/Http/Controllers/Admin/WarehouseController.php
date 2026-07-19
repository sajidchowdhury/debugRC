<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    protected function indexWith(): array
    {
        return ['branch'];
    }

    protected function detailWith(): array
    {
        return ['branch'];
    }

    /**
     * Phase 18: Columns to export for the CSV download.
     * Uses dotted relation path 'branch.branch_name' so the exporter
     * pulls the parent branch's name from the eager-loaded relation.
     */
    protected function exportColumns(): array
    {
        return [
            'warehouse_code'        => 'Code',
            'warehouse_name'        => 'Warehouse Name',
            'branch.branch_name'    => 'Branch Name',
            'location'              => 'Location',
            'is_active'             => 'Active',
        ];
    }

    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
        ];
    }

    /**
     * Validation rules for create/update.
     * Phase 5: Added code pattern validation matching legacy CODE_PATTERN.
     *
     * Phase 8: Default $id to 0 when null (on store) to avoid PostgreSQL
     * "invalid input syntax for type integer: ''" error from the unique rule.
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'warehouse_code' => 'required|string|max:30|regex:/^[A-Za-z0-9\-_.]+$/|unique:warehouses,warehouse_code,' . $id,
            'warehouse_name' => 'required|string|max:100',
            'branch_id'      => 'required|exists:branches,id',
            'location'       => 'nullable|string',
            'is_active'      => 'boolean',
        ];
    }

    /**
     * Phase 5: Override store() to uppercase warehouse_code + validate
     * that the assigned branch is active (legacy isActiveBranch check).
     * Phase 8: Normalize code BEFORE validation for case-insensitive unique.
     */
    public function store(\Illuminate\Http\Request $request)
    {
        // Pre-normalize before validation.
        if ($request->has('warehouse_code')) {
            $request->merge(['warehouse_code' => strtoupper(trim($request->input('warehouse_code')))]);
        }
        if ($request->has('warehouse_name')) {
            $request->merge(['warehouse_name' => trim($request->input('warehouse_name'))]);
        }

        $validated = $request->validate($this->validationRules());
        if (isset($validated['location'])) $validated['location'] = trim($validated['location']);

        // Phase 5: Verify the assigned branch is active (legacy isActiveBranch).
        $branch = Branch::find($validated['branch_id']);
        if (!$branch || !$branch->is_active) {
            return back()->withInput()->with('error', 'Select a valid active branch.');
        }

        // Set created_by from the authenticated user.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing(($this->modelClass)::make()->getTable());
        if (in_array('created_by', $columns)) {
            $validated['created_by'] = Auth::id();
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
     * Phase 5: Override update() — code normalization + active branch
     * validation + canChangeBranch() + deactivation safety check.
     * Phase 8: Normalize code BEFORE validation for case-insensitive unique.
     */
    public function update(\Illuminate\Http\Request $request, int $id)
    {
        $item = Warehouse::findOrFail($id);

        // Pre-normalize before validation.
        if ($request->has('warehouse_code')) {
            $request->merge(['warehouse_code' => strtoupper(trim($request->input('warehouse_code')))]);
        }
        if ($request->has('warehouse_name')) {
            $request->merge(['warehouse_name' => trim($request->input('warehouse_name'))]);
        }

        $validated = $request->validate($this->validationRules($id));
        if (isset($validated['location'])) $validated['location'] = trim($validated['location']);

        // Phase 5: Verify the assigned branch is active.
        $branch = Branch::find($validated['branch_id']);
        if (!$branch || !$branch->is_active) {
            return back()->withInput()->with('error', 'Select a valid active branch.');
        }

        // Phase 3: If branch_id is changing, run canChangeBranch safety check.
        $newBranchId = (int) ($validated['branch_id'] ?? $item->branch_id);
        if ($newBranchId !== (int) $item->branch_id) {
            $check = $this->canChangeBranch($item, $newBranchId);
            if (!$check['ok']) {
                return back()->withInput()->with('error', $check['message']);
            }
        }

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
     * Phase 3: Can this warehouse be safely deactivated?
     * Mirrors legacy WarehouseModel::canDeactivateWarehouse().
     *
     * Checks:
     *   - No stock (qty > 0) in warehouse_stock
     *   - No pending dispatches (ordered_qty > dispatched_qty on open invoices)
     *   - No active stock take sessions (draft/counting)
     */
    protected function canDeactivate($item): array
    {
        $warehouseId = $item->id;

        // Check stock.
        $hasStock = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouseId)
            ->where('qty', '>', 0.0001)
            ->exists();

        if ($hasStock) {
            $stockCount = (float) DB::table('warehouse_stock')
                ->where('warehouse_id', $warehouseId)
                ->sum('qty');
            return [
                'ok' => false,
                'message' => "Cannot deactivate this warehouse. It still has "
                    . number_format($stockCount, 2)
                    . " units of stock. Please move or adjust the stock first.",
            ];
        }

        // Check pending dispatches.
        $hasPendingDispatches = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', 'si.id', '=', 'sid.sales_invoice_id')
            ->where('sid.warehouse_id', $warehouseId)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty')
            ->where('si.is_reversed', false)
            ->whereNotIn('si.status', ['cancelled', 'reversed'])
            ->exists();

        if ($hasPendingDispatches) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this warehouse. Pending dispatch lines exist on open invoices.',
            ];
        }

        // Check active stock take sessions.
        $hasActiveStockTake = DB::table('stock_take_warehouses as stw')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
            ->where('stw.warehouse_id', $warehouseId)
            ->whereIn('sts.status', ['draft', 'counting'])
            ->exists();

        if ($hasActiveStockTake) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this warehouse. An active stock take session is in progress.',
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * Phase 3: Can the warehouse's branch_id be changed?
     * Mirrors legacy WarehouseModel::canChangeBranch().
     *
     * Checks:
     *   - No stock in the warehouse (would corrupt warehouse_stock + interbranch GL)
     *   - No pending dispatches (would orphan the dispatch lines)
     */
    private function canChangeBranch(Warehouse $warehouse, int $newBranchId): array
    {
        if ((int) $warehouse->branch_id === $newBranchId) {
            return ['ok' => true, 'message' => ''];
        }

        // Check stock.
        $hasStock = DB::table('warehouse_stock')
            ->where('warehouse_id', $warehouse->id)
            ->where('qty', '>', 0.0001)
            ->exists();

        if ($hasStock) {
            return [
                'ok' => false,
                'message' => 'Cannot change branch while stock remains in this warehouse. Transfer or adjust stock first.',
            ];
        }

        // Check pending dispatches.
        $hasPendingDispatches = DB::table('sales_invoice_dispatches as sid')
            ->join('sales_invoices as si', 'si.id', '=', 'sid.sales_invoice_id')
            ->where('sid.warehouse_id', $warehouse->id)
            ->whereRaw('sid.ordered_qty > sid.dispatched_qty')
            ->where('si.is_reversed', false)
            ->whereNotIn('si.status', ['cancelled', 'reversed'])
            ->exists();

        if ($hasPendingDispatches) {
            return [
                'ok' => false,
                'message' => 'Cannot change branch while pending dispatch lines exist on open invoices.',
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
