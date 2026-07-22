<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Services\Purchase\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Return Controller — Phase 7.3.
 *
 * Two-phase: create draft → confirm (stock OUT + GL + supplier_ledger + GRN update) → cancel.
 * Always against a confirmed GRN.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(
        private PurchaseReturnService $returnService
    ) {}

    public function index(Request $request)
    {
        $query = PurchaseReturn::with(['supplier', 'branch', 'purchaseReceive', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('return_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $returns = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => PurchaseReturn::count(),
            'draft' => PurchaseReturn::where('status', 'draft')->count(),
            'confirmed' => PurchaseReturn::where('status', 'confirmed')->count(),
            'cancelled' => PurchaseReturn::where('status', 'cancelled')->count(),
            'total_value' => PurchaseReturn::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.purchase-returns.index', [
            'title' => 'Purchase Returns',
            'returns' => $returns,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'supplier_id', 'branch_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $receive = null;
        $receiveId = $request->input('receive_id');
        if ($receiveId) {
            $receive = \App\Models\PurchaseReceive::with(['items.product', 'items.warehouse', 'supplier', 'branch'])
                ->where('status', 'confirmed')
                ->where('is_reversed', false)
                ->findOrFail($receiveId);
        }

        // Get confirmed GRNs with returnable items for the selector.
        $receives = \App\Models\PurchaseReceive::with(['supplier', 'branch'])
            ->where('status', 'confirmed')
            ->where('is_reversed', false)
            ->orderBy('receive_date', 'desc')
            ->limit(100)
            ->get();

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        return view('admin.purchase-returns.create', [
            'title' => 'New Purchase Return',
            'receives' => $receives,
            'receive' => $receive,
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_receive_id' => 'required|integer|exists:purchase_receives,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.purchase_receive_item_id' => 'nullable|integer',
        ]);

        try {
            $return = $this->returnService->createReturn([
                'purchase_receive_id' => $validated['purchase_receive_id'],
                'return_date' => $validated['return_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.purchase-returns.show', $return)
                ->with('success', "Draft return {$return->return_code} created. Confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show($id)
    {
        // Removed int type-hint: PHP 8.4 strict types broke /create URL which
        // passes "create" as the route param. findOrFail() handles non-numeric
        // input with a 404, which is the correct behavior.
        $return = PurchaseReturn::with([
            'items.product', 'items.warehouse', 'supplier', 'branch',
            'purchaseReceive', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = [];
        if ($return->isConfirmed() || $return->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'purchase_return')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        $supplierLedgerEntries = [];
        if ($return->isConfirmed()) {
            $supplierLedgerEntries = DB::table('supplier_ledger')
                ->where('reference_type', 'purchase_return')
                ->where('reference_id', $id)
                ->orderBy('id')
                ->get();
        }

        return view('admin.purchase-returns.show', [
            'title' => 'Return ' . $return->return_code,
            'return' => $return,
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
            $return = $this->returnService->confirmReturn($id, auth()->id());
            return redirect()->route('admin.purchase-returns.show', $return)
                ->with('success', "Return {$return->return_code} confirmed. Stock returned + GL posted + supplier ledger updated.");
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
            $return = $this->returnService->cancelReturn($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.purchase-returns.show', $return)
                ->with('success', "Return {$return->return_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get GRN details for return form pre-fill.
     */
    public function getReceiveDetails(Request $request)
    {
        $request->validate([
            'receive_id' => 'required|integer|exists:purchase_receives,id',
        ]);

        $receive = \App\Models\PurchaseReceive::with(['items.product', 'supplier', 'branch'])
            ->where('status', 'confirmed')
            ->where('is_reversed', false)
            ->findOrFail($request->input('receive_id'));

        // Calculate returnable_qty for each item.
        $items = $receive->items->map(function ($item) {
            $alreadyReturned = DB::table('purchase_return_items')
                ->where('purchase_receive_item_id', $item->id)
                ->whereIn('purchase_return_id', function ($q) {
                    $q->select('id')->from('purchase_returns')
                      ->where('status', 'confirmed')
                      ->where('is_reversed', false);
                })
                ->sum('qty');

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product?->product_code,
                'product_name' => $item->product?->product_name,
                'received_qty' => (float) $item->qty,
                'already_returned' => (float) $alreadyReturned,
                'returnable_qty' => (float) $item->qty - (float) $alreadyReturned,
                'rate' => (float) $item->rate,
                'warehouse_id' => $item->warehouse_id,
            ];
        })->filter(fn($i) => $i['returnable_qty'] > 0.0001)->values();

        return response()->json([
            'receive' => [
                'id' => $receive->id,
                'receive_code' => $receive->receive_code,
                'supplier_id' => $receive->supplier_id,
                'supplier_name' => $receive->supplier?->supplier_name,
                'branch_id' => $receive->branch_id,
            ],
            'items' => $items,
        ]);
    }
}
