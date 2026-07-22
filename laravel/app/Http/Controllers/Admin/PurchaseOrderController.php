<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        // Phase 1 (branch isolation): non-admin users see only their own
        // branch. Admin can override by passing ?branch_id= explicitly.
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        // Phase 2: server-side DataTables JSON mode.
        // Triggered by ?datatables=1 (with the standard draw/page/etc. params
        // that DataTables sends automatically).
        if ($request->boolean('datatables')) {
            return $this->poDataTableJson($request, $branchId);
        }

        $query = PurchaseOrder::with(['supplier', 'branch', 'warehouse', 'items'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($request->input('from_date'), fn($q, $d) => $q->where('po_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('po_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('po_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('po_date', 'desc')
            ->orderBy('id', 'desc');

        $pos = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Phase 1: stats are also branch-scoped for non-admins.
        $statsQuery = PurchaseOrder::query();
        if ($branchId > 0) {
            $statsQuery->where('branch_id', $branchId);
        }
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'draft' => (clone $statsQuery)->where('status', 'draft')->count(),
            'sent' => (clone $statsQuery)->where('status', 'sent')->count(),
            'partial' => (clone $statsQuery)->where('status', 'partial')->count(),
            'received' => (clone $statsQuery)->where('status', 'received')->count(),
            'cancelled' => (clone $statsQuery)->where('status', 'cancelled')->count(),
            'total_value' => (clone $statsQuery)->whereNotIn('status', ['cancelled'])->sum('total_amount'),
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

    /**
     * Phase 2 — Server-side DataTables JSON endpoint.
     * Mirrors the legacy `PurchaseOrderModel::getPurchaseOrdersForDataTable()`
     * response shape: {draw, recordsTotal, recordsFiltered, data:[...]}.
     */
    private function poDataTableJson(Request $request, ?int $branchId)
    {
        $draw   = (int) $request->input('draw', 1);
        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $length = $length > 0 ? $length : 25; // -1 = "all" → clamp to 25 to avoid OOM
        $search = (string) $request->input('search.value', $request->input('search', ''));
        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        // Legacy sends `cancelled=1` to flip into "show cancelled" mode.
        $showCancelled = $request->boolean('cancelled');

        // Order — default to po_date desc.
        $orderColIdx = (int) ($request->input('order.0.column', 0));
        $orderDir    = strtolower((string) ($request->input('order.0.dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $orderMap = [
            0 => 'po_date',
            1 => 'po_code',
            2 => 'supplier_id',     // supplier name — join needed; fall back to id sort
            3 => 'branch_id',
            4 => 'total_amount',
            5 => 'status',
            6 => 'created_by',
        ];
        $orderCol = $orderMap[$orderColIdx] ?? 'po_date';

        // Base query — eager-load what the row template needs.
        $base = PurchaseOrder::query()
            ->with(['supplier:id,supplier_name,supplier_code', 'branch:id,branch_name,branch_code', 'warehouse:id,warehouse_name,warehouse_code', 'items:id,purchase_order_id'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId));

        // recordsTotal — unfiltered count for the user's branch.
        $recordsTotal = (clone $base)->count();

        // Apply filters for recordsFiltered + paged rows.
        $filtered = (clone $base)
            ->when($fromDate, fn($q, $d) => $q->where('po_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('po_date', '<=', $d))
            ->when($status && $status !== 'all', fn($q, $s) => $q->where('status', $s))
            ->when($showCancelled, fn($q) => $q->where('status', 'cancelled'))
            ->when(! $showCancelled && ! $status, fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('po_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"));
                });
            });

        $recordsFiltered = (clone $filtered)->count();

        $rows = (clone $filtered)
            ->orderBy($orderCol, $orderDir)
            ->orderBy('id', 'desc')
            ->skip($start)
            ->take($length)
            ->get();

        // DataTables expects a flat array of row objects — we serialize the
        // minimal fields needed by the index table + mobile cards.
        $data = $rows->map(fn($po) => [
            'id'              => $po->id,
            'po_code'         => $po->po_code,
            'po_date'         => optional($po->po_date)->format('Y-m-d'),
            'supplier_name'   => $po->supplier?->supplier_name ?? '—',
            'supplier_code'   => $po->supplier?->supplier_code ?? '',
            'branch_name'     => $po->branch?->branch_name ?? '—',
            'warehouse_name'  => $po->warehouse?->warehouse_name ?? '',
            'total_amount'    => (float) $po->total_amount,
            'status'          => $po->status,
            'created_by_name' => $po->created_by ? ('User #' . $po->created_by) : 'System',
            'show_url'        => route('admin.purchase-orders.show', $po),
            'edit_url'        => route('admin.purchase-orders.edit', $po),
            'cancel_url'      => route('admin.purchase-orders.cancel', $po),
            'can_cancel'      => in_array($po->status, ['draft', 'sent'], true),
            'can_edit'        => $po->status === 'draft',
        ])->values();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * Phase 2 — Typeahead product search.
     * Mirrors legacy `PurchaseOrderController::search_products()`.
     * Returns a bare JSON array of {id, product_name, product_code} —
     * no stock/price data (PO does not need it; that's a GRN concern).
     */
    public function searchProducts(Request $request)
    {
        $term = trim((string) $request->input('term', ''));
        if ($term === '' || mb_strlen($term) < 1) {
            return response()->json([]);
        }

        $rows = Product::active()
            ->where(function ($q) use ($term) {
                $q->where('product_name', 'ILIKE', "%{$term}%")
                  ->orWhere('product_code', 'ILIKE', "%{$term}%");
            })
            ->orderBy('product_name')
            ->limit(20)
            ->get(['id', 'product_name', 'product_code']);

        return response()->json($rows);
    }

    /**
     * Phase 2 — CSV export of filtered POs.
     * Mirrors legacy `PurchaseOrderController::export()`.
     */
    public function export(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        $search   = (string) ($request->input('search') ?? '');

        $pos = PurchaseOrder::with(['supplier', 'branch', 'warehouse'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($fromDate, fn($q, $d) => $q->where('po_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('po_date', '<=', $d))
            ->when($status && $status !== 'all', fn($q, $s) => $q->where('status', $s))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('po_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"));
                });
            })
            ->orderBy('po_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $filename = 'Purchase_Orders_' . now()->format('Y-m-d_His') . '.csv';

        return response()->stream(function () use ($pos) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'PO Code', 'Supplier', 'Branch', 'Warehouse',
                'PO Date', 'Expected Date', 'Total Amount',
                'Status', 'Created By', 'Notes',
            ]);
            foreach ($pos as $po) {
                $statusLabel = [
                    'draft'     => 'Draft',
                    'sent'      => 'Sent',
                    'partial'   => 'Partial',
                    'received'  => 'Received',
                    'cancelled' => 'Cancelled',
                ][$po->status] ?? ucfirst($po->status);

                fputcsv($out, [
                    $po->po_code,
                    $po->supplier?->supplier_name ?? '',
                    $po->branch?->branch_name ?? '',
                    $po->warehouse?->warehouse_name ?? '',
                    optional($po->po_date)->format('Y-m-d'),
                    $po->expected_date ? $po->expected_date->format('Y-m-d') : '',
                    number_format((float) $po->total_amount, 2, '.', ''),
                    $statusLabel,
                    $po->created_by ? ('User #' . $po->created_by) : 'System',
                    $po->notes ?? '',
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
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

        // Phase 1 (branch isolation): non-admin users cannot write to
        // another branch — force the session branch.
        $branchId = $this->resolveBranchIdForWrite((int) $validated['branch_id']);

        try {
            $po = $this->poService->createOrder([
                'supplier_id' => $validated['supplier_id'],
                'branch_id' => $branchId,
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
            'items.product', 'supplier', 'branch', 'warehouse',
            // Phase 3 — eager-load the GRNs against this PO for the
            // "Receives against this PO" list section on the show page.
            'receives' => fn($q) => $q->with(['warehouse'])->orderBy('receive_date', 'desc')->orderBy('id', 'desc'),
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

        // Phase 1 (branch isolation): non-admin users cannot move a PO
        // to another branch — force the session branch.
        $branchId = $this->resolveBranchIdForWrite((int) $validated['branch_id']);

        try {
            $po = $this->poService->updateOrder($id, [
                'supplier_id' => $validated['supplier_id'],
                'branch_id' => $branchId,
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
