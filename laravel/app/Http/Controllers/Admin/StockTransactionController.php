<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockTransaction;
use App\Services\Stock\StockService;
use App\Services\Stock\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Transaction Controller — Phase 6.1.
 *
 * Read-only listing of the immutable inventory ledger (stock_transactions)
 * + the current warehouse_stock balances. Stock movements are created by
 * the business modules (purchase, sales, adjustments, etc.) via StockService,
 * NOT directly through this controller.
 *
 * This controller provides:
 *   - Index: searchable/filterable list of all stock transactions
 *   - Warehouse stock: current balances (qty + avg_cost) per warehouse/product
 *   - Show: single transaction detail
 *   - Availability check: AJAX endpoint for sales module
 */
class StockTransactionController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private StockAvailabilityService $availabilityService
    ) {}

    /**
     * Stock transactions ledger — searchable/filterable list.
     */
    public function index(Request $request)
    {
        $query = StockTransaction::with(['product', 'warehouse', 'warehouse.branch'])
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('product_id'), fn($q, $pid) => $q->where('product_id', $pid))
            ->when($request->input('reference_type'), fn($q, $ref) => $q->where('reference_type', $ref))
            ->when($request->input('from_date'), fn($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('transaction_date', '<=', $d))
            ->when($request->boolean('show_reversed') === false, fn($q) => $q->where('is_reversed', false))
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc');

        $transactions = $query->paginate(50);

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.stock.transactions', [
            'title' => 'Stock Transactions — Inventory Ledger',
            'transactions' => $transactions,
            'warehouses' => $warehouses,
            'products' => $products,
            'referenceTypes' => $this->referenceTypeLabels(),
            'filters' => $request->only(['warehouse_id', 'product_id', 'reference_type', 'from_date', 'to_date', 'show_reversed']),
        ]);
    }

    /**
     * Current warehouse stock balances (qty + avg_cost).
     */
    public function warehouseStock(Request $request)
    {
        $query = DB::table('warehouse_stock as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->join('branches as b', 'b.id', '=', 'w.branch_id')
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('w.branch_id', $bid))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('ws.warehouse_id', $wid))
            ->when($request->input('product_id'), fn($q, $pid) => $q->where('ws.product_id', $pid))
            ->when($request->boolean('zero_stock') === false, fn($q) => $q->where('ws.qty', '>', 0))
            ->select(
                'ws.warehouse_id', 'ws.product_id',
                'ws.qty', 'ws.avg_cost', 'ws.stock_value',
                'p.product_code', 'p.product_name', 'p.unit',
                'w.warehouse_name', 'b.branch_name'
            )
            ->orderBy('b.branch_name')
            ->orderBy('w.warehouse_name')
            ->orderBy('p.product_name');

        $stock = $query->paginate(50);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        $totals = [
            'total_qty' => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('qty'),
            'total_value' => DB::table('warehouse_stock')->where('qty', '>', 0)->sum('stock_value'),
        ];

        return view('admin.stock.warehouse_stock', [
            'title' => 'Warehouse Stock — Current Balances',
            'stock' => $stock,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'totals' => $totals,
            'filters' => $request->only(['branch_id', 'warehouse_id', 'product_id', 'zero_stock']),
        ]);
    }

    /**
     * Show a single stock transaction (with its reversal info if reversed).
     */
    public function show(int $id)
    {
        $transaction = StockTransaction::with(['product', 'warehouse.branch', 'reversalOf'])
            ->findOrFail($id);

        // Find the reversal row (if any).
        $reversal = StockTransaction::where('reference_type', 'reversal')
            ->where('reference_id', $id)
            ->first();

        return view('admin.stock.show', [
            'title' => "Stock Transaction #{$id}",
            'transaction' => $transaction,
            'reversal' => $reversal,
            'referenceTypeLabels' => $this->referenceTypeLabels(),
        ]);
    }

    /**
     * AJAX: check available qty for a product in a warehouse.
     * Used by the sales module before finalizing an invoice.
     */
    public function checkAvailability(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'exclude_invoice_id' => 'nullable|integer',
        ]);

        $productId = (int) $request->input('product_id');
        $warehouseId = $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $excludeInvoiceId = $request->input('exclude_invoice_id') ? (int) $request->input('exclude_invoice_id') : null;

        if ($warehouseId) {
            $physical = $this->availabilityService->getWarehousePhysicalQty($productId, $warehouseId);
            $pipeline = $this->availabilityService->getWarehousePipelineQty($productId, $warehouseId, $excludeInvoiceId);
            $available = $this->availabilityService->getWarehouseAvailableQty($productId, $warehouseId, $excludeInvoiceId);
        } elseif ($branchId) {
            $physical = $this->availabilityService->getBranchPhysicalQty($productId, $branchId);
            $pipeline = $this->availabilityService->getBranchPipelineQty($productId, $branchId, $excludeInvoiceId);
            $available = $this->availabilityService->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
        } else {
            return response()->json(['error' => 'Either warehouse_id or branch_id is required.'], 400);
        }

        $avgCost = $warehouseId
            ? $this->stockService->getWarehouseAvgCost($warehouseId, $productId)
            : 0;

        return response()->json([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'branch_id' => $branchId,
            'physical_qty' => round($physical, 4),
            'pipeline_qty' => round($pipeline, 4),
            'available_qty' => round($available, 4),
            'avg_cost' => round($avgCost, 2),
        ]);
    }

    /**
     * AJAX: per-warehouse breakdown for a product in a branch.
     * Used by the challan picker modal.
     */
    public function warehouseBreakdown(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $breakdown = $this->availabilityService->getBranchWarehouseBreakdown(
            (int) $request->input('product_id'),
            (int) $request->input('branch_id'),
            $request->input('exclude_invoice_id') ? (int) $request->input('exclude_invoice_id') : null
        );

        return response()->json($breakdown);
    }

    /**
     * Reference type labels for display.
     */
    private function referenceTypeLabels(): array
    {
        return [
            'purchase_receive' => 'Purchase Receive',
            'purchase_return' => 'Purchase Return',
            'sales_challan' => 'Sales Challan',
            'sales_return' => 'Sales Return',
            'stock_adjustment' => 'Stock Adjustment',
            'stock_take' => 'Stock Take',
            'warehouse_transfer' => 'Warehouse Transfer',
            'damage' => 'Damage',
            'branch_demand' => 'Branch Demand',
            'opening_balance' => 'Opening Balance',
            'reversal' => 'Reversal',
        ];
    }

    /**
     * Phase 6.2: avg_cost_drift viewer — browse drift rows from the replay test.
     */
    public function drift(Request $request)
    {
        $query = DB::table('avg_cost_drift as d')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', 'w.branch_id')
            ->when($request->input('status'), fn($q, $s) => $q->where('d.status', $s))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('d.warehouse_id', $wid))
            ->when($request->input('product_id'), fn($q, $pid) => $q->where('d.product_id', $pid))
            ->select(
                'd.*',
                'p.product_code', 'p.product_name',
                'w.warehouse_name', 'b.branch_name'
            )
            ->orderByDesc('d.qty_drift')
            ->orderByDesc('d.cost_drift');

        $drifts = $query->paginate(50);

        $stats = [
            'total' => DB::table('avg_cost_drift')->count(),
            'open' => DB::table('avg_cost_drift')->where('status', 'open')->count(),
            'investigated' => DB::table('avg_cost_drift')->where('status', 'investigated')->count(),
            'resolved' => DB::table('avg_cost_drift')->where('status', 'resolved')->count(),
        ];

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        return view('admin.stock.drift', [
            'title' => 'Avg-Cost Drift — Replay Verification',
            'drifts' => $drifts,
            'stats' => $stats,
            'warehouses' => $warehouses,
            'referenceTypeLabels' => $this->referenceTypeLabels(),
            'filters' => $request->only(['status', 'warehouse_id', 'product_id']),
        ]);
    }

    /**
     * Phase 6.2: Update a drift row's investigation status + notes.
     */
    public function updateDrift(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:open,investigated,resolved',
            'investigation_notes' => 'nullable|string|max:2000',
        ]);

        $update = [
            'status' => $request->input('status'),
            'investigation_notes' => $request->input('investigation_notes'),
        ];

        if ($request->input('status') === 'resolved') {
            $update['resolved_at'] = now();
        }

        DB::table('avg_cost_drift')->where('id', $id)->update($update);

        return redirect()->route('admin.stock.drift')
            ->with('success', "Drift #{$id} updated.");
    }
}
