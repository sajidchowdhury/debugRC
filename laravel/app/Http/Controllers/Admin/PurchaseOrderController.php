<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Http\Request;

/**
 * Purchase Order Controller — Phase 7.1.
 *
 * POs are draft documents — NO stock movement, NO GL journal.
 * Full CRUD + mark-as-sent + cancel.
 *
 * Status flow: draft → sent → partial → received → cancelled
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderService $poService
    ) {}

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'branch', 'warehouse', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('po_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('po_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('po_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('po_date', 'desc')
            ->orderBy('id', 'desc');

        $pos = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => PurchaseOrder::count(),
            'draft' => PurchaseOrder::where('status', 'draft')->count(),
            'sent' => PurchaseOrder::where('status', 'sent')->count(),
            'partial' => PurchaseOrder::where('status', 'partial')->count(),
            'received' => PurchaseOrder::where('status', 'received')->count(),
            'cancelled' => PurchaseOrder::where('status', 'cancelled')->count(),
            'total_value' => PurchaseOrder::whereNotIn('status', ['cancelled'])->sum('total_amount'),
        ];

        return view('admin.purchase-orders.index', [
            'title' => 'Purchase Orders',
            'pos' => $pos,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'supplier_id', 'branch_id', 'status', 'search']),
        ]);
    }

    public function create()
    {
        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.purchase-orders.create', [
            'title' => 'New Purchase Order',
            'suppliers' => $suppliers,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'po_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0',
        ]);

        try {
            $po = $this->poService->createOrder([
                'supplier_id' => $validated['supplier_id'],
                'branch_id' => $validated['branch_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'po_date' => $validated['po_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? '',
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.purchase-orders.show', $po)
                ->with('success', "Purchase order {$po->po_code} created (draft).");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $po = PurchaseOrder::with([
            'items.product', 'supplier', 'branch', 'warehouse'
        ])->findOrFail($id);

        return view('admin.purchase-orders.show', [
            'title' => 'PO ' . $po->po_code,
            'po' => $po,
        ]);
    }

    public function edit(int $id)
    {
        $po = PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])->findOrFail($id);

        if (!$po->canEdit()) {
            return redirect()->route('admin.purchase-orders.show', $po)
                ->with('error', "Only draft POs can be edited (current: {$po->status}).");
        }

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.purchase-orders.edit', [
            'title' => 'Edit PO ' . $po->po_code,
            'po' => $po,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'po_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0',
        ]);

        try {
            $po = $this->poService->updateOrder($id, [
                'supplier_id' => $validated['supplier_id'],
                'branch_id' => $validated['branch_id'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'po_date' => $validated['po_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? '',
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'items' => $validated['items'],
            ]);

            return redirect()->route('admin.purchase-orders.show', $po)
                ->with('success', "Purchase order {$po->po_code} updated.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark a draft PO as sent to supplier.
     */
    public function markAsSent(int $id)
    {
        try {
            $po = $this->poService->markAsSent($id);
            return redirect()->route('admin.purchase-orders.show', $po)
                ->with('success', "PO {$po->po_code} marked as sent to supplier.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel a draft or sent PO.
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $po = $this->poService->cancelOrder($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.purchase-orders.show', $po)
                ->with('success', "PO {$po->po_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
