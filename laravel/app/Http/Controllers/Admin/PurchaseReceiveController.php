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
        // Phase 1 (branch isolation): non-admin users see only their own branch.
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        // Phase 3 — server-side DataTables JSON mode (same shape as PO index).
        if ($request->boolean('datatables')) {
            return $this->grnDataTableJson($request, $branchId);
        }

        $query = PurchaseReceive::with(['supplier', 'branch', 'warehouse', 'purchaseOrder', 'items'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($request->input('from_date'), fn($q, $d) => $q->where('receive_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('receive_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('receive_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('receive_date', 'desc')
            ->orderBy('id', 'desc');

        $receives = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Phase 1: stats are also branch-scoped for non-admins.
        $statsQuery = PurchaseReceive::query();
        if ($branchId > 0) {
            $statsQuery->where('branch_id', $branchId);
        }
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'draft' => (clone $statsQuery)->where('status', 'draft')->count(),
            'confirmed' => (clone $statsQuery)->where('status', 'confirmed')->count(),
            'cancelled' => (clone $statsQuery)->where('status', 'cancelled')->count(),
            'total_value' => (clone $statsQuery)->where('status', 'confirmed')->sum('total_amount'),
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

    /**
     * Phase 3 — Server-side DataTables JSON endpoint.
     * Mirrors the legacy `PurchaseReceiveModel::getReceivesForDataTable()`
     * response shape: {draw, recordsTotal, recordsFiltered, data:[...]}.
     */
    private function grnDataTableJson(Request $request, ?int $branchId)
    {
        $draw   = (int) $request->input('draw', 1);
        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $length = $length > 0 ? $length : 25;
        $search = (string) $request->input('search.value', $request->input('search', ''));
        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        // Legacy sends ?returned=1 to flip into "show cancelled" mode.
        $showReturned = $request->boolean('returned');

        $orderColIdx = (int) ($request->input('order.0.column', 0));
        $orderDir    = strtolower((string) ($request->input('order.0.dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $orderMap = [
            0 => 'receive_date',
            1 => 'receive_code',
            2 => 'purchase_order_id',  // PO code — fall back to id sort
            3 => 'supplier_id',
            4 => 'total_amount',
            5 => 'status',
            6 => 'created_by',
        ];
        $orderCol = $orderMap[$orderColIdx] ?? 'receive_date';

        $base = PurchaseReceive::query()
            ->with([
                'supplier:id,supplier_name,supplier_code',
                'branch:id,branch_name,branch_code',
                'warehouse:id,warehouse_name,warehouse_code',
                'purchaseOrder:id,po_code',
                'items:id,purchase_receive_id',
            ])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId));

        $recordsTotal = (clone $base)->count();

        $filtered = (clone $base)
            ->when($fromDate, fn($q, $d) => $q->where('receive_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('receive_date', '<=', $d))
            ->when($status && $status !== 'all', fn($q, $s) => $q->where('status', $s))
            ->when($showReturned, fn($q) => $q->where('status', 'cancelled'))
            ->when(! $showReturned && ! $status, fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('receive_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('purchaseOrder', fn($pq) => $pq->where('po_code', 'ILIKE', "%{$search}%"));
                });
            });

        $recordsFiltered = (clone $filtered)->count();

        $rows = (clone $filtered)
            ->orderBy($orderCol, $orderDir)
            ->orderBy('id', 'desc')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $rows->map(fn($r) => [
            'id'              => $r->id,
            'receive_code'    => $r->receive_code,
            'receive_date'    => optional($r->receive_date)->format('Y-m-d'),
            'po_code'         => $r->purchaseOrder?->po_code ?? '',
            'po_show_url'     => $r->purchase_order_id ? route('admin.purchase-orders.show', $r->purchase_order_id) : '',
            'supplier_name'   => $r->supplier?->supplier_name ?? '—',
            'supplier_code'   => $r->supplier?->supplier_code ?? '',
            'branch_name'     => $r->branch?->branch_name ?? '—',
            'warehouse_name'  => $r->warehouse?->warehouse_name ?? '',
            'item_count'      => $r->items?->count() ?? 0,
            'total_amount'    => (float) $r->total_amount,
            'status'          => $r->status,
            'is_reversed'     => (bool) $r->is_reversed,
            'created_by_name' => $r->created_by ? ('User #' . $r->created_by) : 'System',
            'show_url'        => route('admin.purchase-receives.show', $r),
            'confirm_url'     => route('admin.purchase-receives.confirm', $r),
            'cancel_url'      => route('admin.purchase-receives.cancel', $r),
            'can_confirm'     => $r->status === 'draft',
            'can_cancel'      => $r->status === 'draft',
        ])->values();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * Phase 3 — CSV export of filtered GRNs (branch-scoped).
     * Mirrors legacy `PurchaseReceiveController::export()`.
     */
    public function export(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        $search   = (string) ($request->input('search') ?? '');
        $showReturned = $request->boolean('returned');

        $receives = PurchaseReceive::with(['supplier', 'branch', 'warehouse', 'purchaseOrder'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($fromDate, fn($q, $d) => $q->where('receive_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('receive_date', '<=', $d))
            ->when($status && $status !== 'all', fn($q, $s) => $q->where('status', $s))
            ->when($showReturned, fn($q) => $q->where('status', 'cancelled'))
            ->when(! $showReturned && ! $status, fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('receive_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('purchaseOrder', fn($pq) => $pq->where('po_code', 'ILIKE', "%{$search}%"));
                });
            })
            ->orderBy('receive_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $filename = 'Purchase_Receives_' . now()->format('Y-m-d_His') . '.csv';

        return response()->stream(function () use ($receives) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [
                'GRN Code', 'PO Code', 'Supplier', 'Branch', 'Warehouse',
                'Receive Date', 'Item Count', 'Total Amount',
                'Status', 'Reversed', 'Created By', 'Notes',
            ]);
            foreach ($receives as $r) {
                $statusLabel = [
                    'draft'     => 'Draft',
                    'confirmed' => 'Confirmed',
                    'cancelled' => 'Cancelled',
                ][$r->status] ?? ucfirst($r->status);

                fputcsv($out, [
                    $r->receive_code,
                    $r->purchaseOrder?->po_code ?? '',
                    $r->supplier?->supplier_name ?? '',
                    $r->branch?->branch_name ?? '',
                    $r->warehouse?->warehouse_name ?? '',
                    optional($r->receive_date)->format('Y-m-d'),
                    $r->items()->count(),
                    number_format((float) $r->total_amount, 2, '.', ''),
                    $statusLabel,
                    $r->is_reversed ? 'Yes' : 'No',
                    $r->created_by ? ('User #' . $r->created_by) : 'System',
                    $r->notes ?? '',
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

    public function create(Request $request)
    {
        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        // If po_id is passed, load the PO for pre-fill.
        // Phase 1 (branch isolation): non-admin users cannot pre-fill from
        // another branch's PO.
        $po = null;
        $poId = $request->input('po_id');
        if ($poId) {
            $po = \App\Models\PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])
                ->findOrFail($poId);
            if (!$request->user()->isAdmin()) {
                $sessionBranchId = (int) (session('branch_id') ?? $request->user()->getBranchId() ?? 0);
                if ((int) $po->branch_id !== $sessionBranchId) {
                    return redirect()->route('admin.purchase-receives.index')
                        ->with('error', 'You do not have access to that PO.');
                }
            }
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

        // Phase 1 (branch isolation): non-admin users cannot create a GRN
        // for another branch — force the session branch. If no branch_id
        // was supplied at all, fall back to session branch too.
        $clientBranchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $branchId = $this->resolveBranchIdForWrite($clientBranchId);

        try {
            $receive = $this->receiveService->createReceive([
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'branch_id' => $branchId,
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

    public function show(int $id)
    {
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

        // Phase 4 — Returns against this GRN (cross-linkage list).
        // Only confirmed GRNs can have returns against them.
        $grnReturns = collect();
        if ($receive->isConfirmed() && ! $receive->is_reversed) {
            $grnReturns = \App\Models\PurchaseReturn::with(['items', 'supplier', 'branch'])
                ->where('purchase_receive_id', $id)
                ->orderBy('return_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();
        }

        return view('admin.purchase-receives.show', [
            'title' => 'GRN ' . $receive->receive_code,
            'receive' => $receive,
            'stockMovements' => $stockMovements,
            'supplierLedgerEntries' => $supplierLedgerEntries,
            'grnReturns' => $grnReturns,
        ]);
    }

    /**
     * Phase 6: Per-module audit-log page for purchase receives (GRNs).
     * Reads user_audit_log filtered by action prefix 'purchase_receive_'.
     */
    public function audit(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $query = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ual.branch_id')
            ->where('ual.action', 'LIKE', 'purchase_receive_%')
            ->when($branchId > 0, fn($q) => $q->where('ual.branch_id', $branchId))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('ual.action', 'ILIKE', "%{$search}%")
                       ->orWhere('u.username', 'ILIKE', "%{$search}%")
                       ->orWhere('e.name', 'ILIKE', "%{$search}%");
                });
            })
            ->select(
                'ual.id', 'ual.created_at as logged_at', 'ual.user_id',
                'ual.action', 'ual.target_user_id as target_id',
                'ual.branch_id', 'ual.details', 'ual.ip_address',
                'u.username', 'e.name as employee_name',
                'b.branch_name'
            )
            ->orderBy('ual.created_at', 'desc')
            ->orderBy('ual.id', 'desc');

        $logs = $query->paginate(100)->withQueryString();

        return view('admin.purchase-receives.audit', [
            'title' => 'Purchase Receive (GRN) — Audit Log',
            'logs' => $logs,
            'filters' => $request->only(['search', 'branch_id']),
            'module' => 'purchase_receive',
            'moduleLabel' => 'Purchase Receive (GRN)',
            'indexRoute' => route('admin.purchase-receives.index'),
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
     *
     * Phase 1 (branch isolation): non-admin users can only fetch POs
     * from their own branch. This prevents a warehouse_manager from
     * pre-filling the receive form with another branch's PO data via
     * ?po_id=<guessed_id>.
     */
    public function getPoDetails(Request $request)
    {
        $request->validate([
            'po_id' => 'required|integer|exists:purchase_orders,id',
        ]);

        $po = \App\Models\PurchaseOrder::with(['items.product', 'supplier', 'branch', 'warehouse'])
            ->findOrFail($request->input('po_id'));

        // Phase 1: branch isolation — deny cross-branch access for non-admins.
        if (!$request->user()->isAdmin()) {
            $sessionBranchId = (int) (session('branch_id') ?? $request->user()->getBranchId() ?? 0);
            if ((int) $po->branch_id !== $sessionBranchId) {
                return response()->json(['message' => 'You do not have access to this PO.'], 403);
            }
        }

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
