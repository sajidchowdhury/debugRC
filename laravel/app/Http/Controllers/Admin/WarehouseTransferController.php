<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WarehouseTransfer;
use App\Services\Stock\WarehouseTransferService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse Transfer Controller — Phase 6.5.
 *
 * Two-phase flow:
 *   - create / store: create a draft transfer (no stock, no GL)
 *   - show: detail with items + stock movements + GL journals
 *   - confirm: apply stock (source OUT + dest IN) + post GL (if cross-branch)
 *   - cancel: reverse if confirmed, or mark draft as cancelled
 */
class WarehouseTransferController extends Controller
{
    public function __construct(
        private WarehouseTransferService $transferService,
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $query = WarehouseTransfer::with(['fromWarehouse.branch', 'toWarehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('transfer_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('transfer_date', '<=', $d))
            ->when($request->input('from_warehouse_id'), fn($q, $wid) => $q->where('from_warehouse_id', $wid))
            ->when($request->input('to_warehouse_id'), fn($q, $wid) => $q->where('to_warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('interbranch') === 'yes', fn($q) => $q->where('is_interbranch', true))
            ->when($request->input('interbranch') === 'no', fn($q) => $q->where('is_interbranch', false))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('transfer_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('transfer_date', 'desc')
            ->orderBy('id', 'desc');

        $transfers = $query->paginate(25);

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        $stats = [
            'total' => WarehouseTransfer::count(),
            'draft' => WarehouseTransfer::where('status', 'draft')->count(),
            'confirmed' => WarehouseTransfer::where('status', 'confirmed')->count(),
            'cancelled' => WarehouseTransfer::where('status', 'cancelled')->count(),
            'interbranch' => WarehouseTransfer::where('is_interbranch', true)->count(),
            'total_value' => WarehouseTransfer::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.warehouse-transfers.index', [
            'title' => 'Warehouse Transfers',
            'transfers' => $transfers,
            'warehouses' => $warehouses,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'from_warehouse_id', 'to_warehouse_id', 'status', 'interbranch', 'search']),
        ]);
    }

    public function create()
    {
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.warehouse-transfers.create', [
            'title' => 'New Warehouse Transfer',
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id|different:from_warehouse_id',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
        ]);

        try {
            $transfer = $this->transferService->createTransfer([
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', "Draft transfer {$transfer->transfer_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $transfer = WarehouseTransfer::with([
            'items.product', 'fromWarehouse.branch', 'toWarehouse.branch',
            'fromBranch', 'toBranch',
            'journalEntry.lines.ledger', 'debtorJournalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = [];
        if ($transfer->isConfirmed() || $transfer->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
                ->where('st.reference_type', 'warehouse_transfer')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name', 'w.warehouse_name')
                ->orderBy('st.id')
                ->get();
        }

        return view('admin.warehouse-transfers.show', [
            'title' => 'Transfer ' . $transfer->transfer_code,
            'transfer' => $transfer,
            'stockMovements' => $stockMovements,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $transfer = $this->transferService->confirmTransfer($id, auth()->id());
            $msg = "Transfer {$transfer->transfer_code} confirmed. Stock moved";
            $msg .= $transfer->is_interbranch ? ' + intercompany GL posted.' : '.';
            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $transfer = $this->transferService->cancelTransfer($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', "Transfer {$transfer->transfer_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product stock + rate for a warehouse (for the create form).
     */
    public function getProductStock(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $rate = $this->stockService->getWarehouseAvgCost(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );
        $qty = $this->stockService->getWarehouseQty(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }
}
