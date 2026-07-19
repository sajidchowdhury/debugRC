<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Warehouse;
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

    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
        ];
    }

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

    /**
     * Phase 3: Override update() to add canChangeBranch() safety check.
     * Mirrors legacy WarehouseModel::canChangeBranch().
     */
    public function update(\Illuminate\Http\Request $request, int $id)
    {
        $item = Warehouse::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

        // Phase 3: If branch_id is changing, run safety check.
        $newBranchId = (int) ($validated['branch_id'] ?? $item->branch_id);
        if ($newBranchId !== (int) $item->branch_id) {
            $check = $this->canChangeBranch($item, $newBranchId);
            if (!$check['ok']) {
                return back()->withInput()->with('error', $check['message']);
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
