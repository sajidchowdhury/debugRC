<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReceive;
use App\Services\Purchase\PurchaseReceiveService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Receive (GRN) Controller — Phase 7.2.
 *
 * Two-phase: create draft → confirm (stock + GL + supplier_ledger + PO update) → cancel.
 * Can be against a PO or direct (no PO).
 */
class PurchaseReceiveController extends Controller
{
    public function __construct(
        private PurchaseReceiveService $receiveService,
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $query = PurchaseReceive::with(['supplier', 'branch', 'warehouse', 'purchaseOrder', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('receive_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('receive_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('receive_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('receive_date', 'desc')
            ->orderBy('id', 'desc');

        $receives = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => PurchaseReceive::count(),
            'draft' => PurchaseReceive::where('status', 'draft')->count(),
            'confirmed' => PurchaseReceive::where('status', 'confirmed')->count(),
            'cancelled' => PurchaseReceive::where('status', 'cancelled')->count(),
            'total_value' => PurchaseReceive::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.purchase-receives.index', [
            'title' => 'Purchase Receives (GRN)',
            'receives' => $receives,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'supplier_id', 'branch_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        // If po_id is passed, load the PO for pre-fill.
        $po = null;
        $poId = $request->input('po_id');
        if ($poId) {
            $po = \App\Models\PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])
                ->findOrFail($poId);
            if (!$po->canReceive()) {
                return redirect()->route('admin.purchase-orders.show', $po)
                    ->with('error', "This PO cannot receive goods (status: {$po->status}).");
            }
        }

        return view('admin.purchase-receives.create', [
            'title' => 'New Purchase Receive (GRN)',
            'suppliers' => $suppliers,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
            'po' => $po,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'receive_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.purchase_order_item_id' => 'nullable|integer',
        ]);

        try {
            $receive = $this->receiveService->createReceive([
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'warehouse_id' => $validated['warehouse_id'],
                'receive_date' => $validated['receive_date'],
                'notes' => $validated['notes'] ?? '',
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.purchase-receives.show', $receive)
                ->with('success', "Draft GRN {$receive->receive_code} created. Confirm to apply stock + GL.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        // Removed int type-hint: PHP 8.4 strict types broke /create URL which
        // passes "create" as the route param. findOrFail() handles non-numeric
        // input with a 404, which is the correct behavior.
        $receive = PurchaseReceive::with([
            'items.product', 'items.warehouse', 'supplier', 'branch', 'warehouse',
            'purchaseOrder', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = [];
        if ($receive->isConfirmed() || $receive->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'purchase_receive')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        // Supplier ledger entries for this GRN.
        $supplierLedgerEntries = [];
        if ($receive->isConfirmed()) {
            $supplierLedgerEntries = DB::table('supplier_ledger')
                ->where('reference_type', 'purchase_receive')
                ->where('reference_id', $id)
                ->orderBy('id')
                ->get();
        }

        return view('admin.purchase-receives.show', [
            'title' => 'GRN ' . $receive->receive_code,
            'receive' => $receive,
            'stockMovements' => $stockMovements,
            'supplierLedgerEntries' => $supplierLedgerEntries,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $receive = $this->receiveService->confirmReceive($id, auth()->id());
            return redirect()->route('admin.purchase-receives.show', $receive)
                ->with('success', "GRN {$receive->receive_code} confirmed. Stock received + GL posted + supplier ledger updated.");
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
            $receive = $this->receiveService->cancelReceive($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.purchase-receives.show', $receive)
                ->with('success', "GRN {$receive->receive_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get PO details for receive form pre-fill.
     */
    public function getPoDetails(Request $request)
    {
        $request->validate([
            'po_id' => 'required|integer|exists:purchase_orders,id',
        ]);

        $po = \App\Models\PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])
            ->findOrFail($request->input('po_id'));

        if (!$po->canReceive()) {
            return response()->json(['error' => "PO cannot receive (status: {$po->status})"], 400);
        }

        // Return only items with remaining qty.
        $items = $po->items->filter(fn($i) => $i->remainingQty() > 0.0001)->values();

        return response()->json([
            'po' => [
                'id' => $po->id,
                'po_code' => $po->po_code,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier?->supplier_name,
                'branch_id' => $po->branch_id,
                'warehouse_id' => $po->warehouse_id,
            ],
            'items' => $items->map(fn($i) => [
                'purchase_order_item_id' => $i->id,
                'product_id' => $i->product_id,
                'product_code' => $i->product?->product_code,
                'product_name' => $i->product?->product_name,
                'qty' => (float) $i->qty,
                'received_qty' => (float) $i->received_qty,
                'remaining_qty' => $i->remainingQty(),
                'rate' => (float) $i->rate,
            ]),
        ]);
    }
}
