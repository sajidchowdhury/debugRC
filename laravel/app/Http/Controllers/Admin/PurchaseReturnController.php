<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Services\Purchase\PurchaseReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Return Controller — Phase 7.3 + Phase 4 UI parity.
 *
 * Two-phase: create draft → confirm (stock OUT + GL + supplier_ledger + GRN update) → cancel.
 * Always against a confirmed GRN.
 *
 * Phase 4 additions:
 *   - index(): server-side DataTables JSON mode (?datatables=1)
 *   - summary(): chip counts AJAX (mirrors legacy return_filter_summary)
 *   - searchReceives(): GRN typeahead AJAX (mirrors legacy search_receive)
 *   - export(): CSV export of filtered returns
 *   - getReceiveDetails(): now returns per-item per-warehouse availability
 *     (warehouse_stock physical + available) for the client-side dual cap.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(
        private PurchaseReturnService $returnService
    ) {}

    public function index(Request $request)
    {
        // Phase 1 (branch isolation): non-admin users see only their own branch.
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        // Phase 4 — server-side DataTables JSON mode (same shape as PO/GRN index).
        if ($request->boolean('datatables')) {
            return $this->returnDataTableJson($request, $branchId);
        }

        $query = PurchaseReturn::with(['supplier', 'branch', 'purchaseReceive', 'items'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($request->input('from_date'), fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($request->input('supplier_id'), fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('return_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $returns = $query->paginate(25);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Phase 1: stats are also branch-scoped for non-admins.
        $statsQuery = PurchaseReturn::query();
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
            // Phase 1 (branch isolation): non-admin users cannot start a
            // return from another branch's GRN.
            if (!$request->user()->isAdmin()) {
                $sessionBranchId = (int) (session('branch_id') ?? $request->user()->getBranchId() ?? 0);
                if ((int) $receive->branch_id !== $sessionBranchId) {
                    return redirect()->route('admin.purchase-returns.index')
                        ->with('error', 'You do not have access to that GRN.');
                }
            }
        }

        // Get confirmed GRNs with returnable items for the selector.
        // Phase 1: branch-scope this list for non-admin users.
        $receivesQuery = \App\Models\PurchaseReceive::with(['supplier', 'branch'])
            ->where('status', 'confirmed')
            ->where('is_reversed', false)
            ->orderBy('receive_date', 'desc')
            ->limit(100);
        if (!$request->user()->isAdmin()) {
            $sessionBranchId = (int) (session('branch_id') ?? $request->user()->getBranchId() ?? 0);
            $receivesQuery->where('branch_id', $sessionBranchId);
        }
        $receives = $receivesQuery->get();

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

    public function show(int $id)
    {
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
     *
     * Phase 1 (branch isolation): non-admin users can only fetch GRNs
     * from their own branch.
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

        // Phase 1: deny cross-branch access for non-admins.
        if (!$request->user()->isAdmin()) {
            $sessionBranchId = (int) (session('branch_id') ?? $request->user()->getBranchId() ?? 0);
            if ((int) $receive->branch_id !== $sessionBranchId) {
                return response()->json(['message' => 'You do not have access to this GRN.'], 403);
            }
        }

        // Calculate returnable_qty for each item + per-warehouse availability.
        // Phase 4: include warehouse_breakdown for the client-side dual cap
        // (return qty ≤ GRN returnable AND ≤ warehouse_stock available).
        $items = $receive->items->map(function ($item) {
            $alreadyReturned = DB::table('purchase_return_items')
                ->where('purchase_receive_item_id', $item->id)
                ->whereIn('purchase_return_id', function ($q) {
                    $q->select('id')->from('purchase_returns')
                      ->where('status', 'confirmed')
                      ->where('is_reversed', false);
                })
                ->sum('qty');

            $returnable = (float) $item->qty - (float) $alreadyReturned;

            // Per-warehouse availability from warehouse_stock.
            // Mirrors legacy PurchaseReturnModel::getReceiveForReturn() which
            // joins warehouse_stock and returns each warehouse's physical_qty
            // and available_qty (physical - committed-out).
            $warehouses = DB::table('warehouse_stock as ws')
                ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                ->where('ws.product_id', $item->product_id)
                ->where('w.is_active', true)
                ->select([
                    'w.id',
                    'w.warehouse_name',
                    DB::raw('COALESCE(ws.physical_qty, 0) AS physical_qty'),
                    DB::raw('COALESCE(ws.available_qty, ws.physical_qty, 0) AS available_qty'),
                ])
                ->get()
                ->map(fn($w) => [
                    'id'             => $w->id,
                    'warehouse_name' => $w->warehouse_name,
                    'physical_qty'   => (float) $w->physical_qty,
                    'available_qty'  => (float) $w->available_qty,
                ])
                ->values()
                ->all();

            return [
                'id'                => $item->id,
                'purchase_receive_item_id' => $item->id,
                'product_id'        => $item->product_id,
                'product_code'      => $item->product?->product_code,
                'product_name'      => $item->product?->product_name,
                'received_qty'      => (float) $item->qty,
                'already_returned'  => (float) $alreadyReturned,
                'returnable_qty'    => $returnable,
                'rate'              => (float) $item->rate,
                'warehouse_id'      => $item->warehouse_id,
                'warehouses'        => $warehouses,
            ];
        })->filter(fn($i) => $i['returnable_qty'] > 0.0001)->values();

        return response()->json([
            'status' => 'success',
            'receive' => [
                'id'             => $receive->id,
                'receive_code'   => $receive->receive_code,
                'supplier_id'    => $receive->supplier_id,
                'supplier_name'  => $receive->supplier?->supplier_name,
                'branch_id'      => $receive->branch_id,
                'total_amount'   => (float) $receive->total_amount,
            ],
            'items' => $items,
        ]);
    }

    /**
     * Phase 4 — Server-side DataTables JSON endpoint.
     * Mirrors the legacy `PurchaseReturnModel::getPurchaseReturnsForDataTable()`
     * response shape: {draw, recordsTotal, recordsFiltered, data:[...]}.
     *
     * Branch isolation is enforced via $branchId (non-admins only see their
     * own branch's returns).
     */
    private function returnDataTableJson(Request $request, ?int $branchId)
    {
        $draw   = (int) $request->input('draw', 1);
        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $length = $length > 0 ? $length : 25;
        $search = (string) $request->input('search.value', $request->input('search', ''));
        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        // Legacy sends ?reversed=1 to flip into "show reversed only" mode.
        $showReversed = $request->boolean('reversed');
        $smartSort    = $request->input('smart_sort', '1') !== '0';

        $orderColIdx = (int) ($request->input('order.0.column', 0));
        $orderDir    = strtolower((string) ($request->input('order.0.dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $orderMap = [
            0 => 'return_code',
            1 => 'purchase_receive_id',  // GRN code — fall back to id sort
            2 => 'supplier_id',
            3 => 'return_date',
            4 => 'total_amount',
            5 => 'is_reversed',
            6 => 'id',
        ];
        $orderCol = $orderMap[$orderColIdx] ?? 'return_date';

        $base = PurchaseReturn::query()
            ->with([
                'supplier:id,supplier_name,supplier_code',
                'branch:id,branch_name,branch_code',
                'purchaseReceive:id,receive_code',
                'items:id,purchase_return_id',
            ])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId));

        $recordsTotal = (clone $base)->count();

        $filtered = (clone $base)
            ->when($fromDate, fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($status && $status !== 'all', function ($q) use ($status, $showReversed) {
                if ($status === 'reversed') {
                    $q->where('is_reversed', true);
                } elseif ($status === 'active') {
                    $q->where('is_reversed', false);
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($showReversed, fn($q) => $q->where('is_reversed', true))
            ->when(! $showReversed && ! $status, fn($q) => $q->where('is_reversed', false))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('return_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('purchaseReceive', fn($pq) => $pq->where('receive_code', 'ILIKE', "%{$search}%"));
                });
            });

        $recordsFiltered = (clone $filtered)->count();

        // Smart-sort: active first, then reversed. Within each group, respect
        // the user's column/dir selection.
        $rowsQuery = (clone $filtered);
        if ($smartSort) {
            $rowsQuery->orderBy('is_reversed', 'asc'); // false (0) first, true (1) last
        }
        $rows = $rowsQuery
            ->orderBy($orderCol, $orderDir)
            ->orderBy('id', 'desc')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $rows->map(fn($r) => [
            'id'              => $r->id,
            'return_code'     => $r->return_code,
            'receive_code'    => $r->purchaseReceive?->receive_code ?? '',
            'receive_id'      => $r->purchase_receive_id,
            'supplier_name'   => $r->supplier?->supplier_name ?? '—',
            'supplier_code'   => $r->supplier?->supplier_code ?? '',
            'branch_name'     => $r->branch?->branch_name ?? '—',
            'return_date'     => optional($r->return_date)->format('Y-m-d'),
            'total_amount'    => (float) $r->total_amount,
            'is_reversed'     => (bool) $r->is_reversed,
            'status'          => $r->status,
            'created_by_name' => $r->created_by ? ('User #' . $r->created_by) : 'System',
            'show_url'        => route('admin.purchase-returns.show', $r),
            'cancel_url'      => route('admin.purchase-returns.cancel', $r),
            'can_cancel'      => ! $r->is_reversed && $r->status !== 'cancelled',
        ])->values();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * Phase 4 — Live chip counts AJAX.
     * Mirrors legacy `PurchaseReturnController::return_filter_summary()`.
     *
     * Returns JSON: {all, active, reversed} for the index page chip badges.
     * Branch-scoped for non-admins.
     */
    public function summary(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $search   = (string) ($request->input('search') ?? '');

        $base = PurchaseReturn::query()
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($fromDate, fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('return_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('purchaseReceive', fn($pq) => $pq->where('receive_code', 'ILIKE', "%{$search}%"));
                });
            });

        $all      = (clone $base)->count();
        $active   = (clone $base)->where('is_reversed', false)->count();
        $reversed = (clone $base)->where('is_reversed', true)->count();

        return response()->json([
            'all'      => $all,
            'total'    => $all,
            'active'   => $active,
            'reversed' => $reversed,
        ]);
    }

    /**
     * Phase 4 — GRN typeahead AJAX.
     * Mirrors legacy `PurchaseReturnController::search_receive()`.
     *
     * Returns a list of confirmed non-reversed GRNs with at least one
     * returnable item, matching $term against receive_code or supplier_name.
     * Branch-scoped for non-admins.
     */
    public function searchReceives(Request $request)
    {
        $request->validate([
            'term' => 'nullable|string|max:100',
        ]);

        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $term = trim((string) $request->input('term', ''));

        // Get confirmed non-reversed GRNs that still have at least one returnable item.
        // We can't fully compute returnable in a single query without a correlated
        // subquery on purchase_return_items — so we filter to the candidate GRNs
        // (term + status + branch) and then post-filter for returnable_qty > 0
        // in PHP. Limit to 25 for typeahead UX.
        $query = \App\Models\PurchaseReceive::with(['supplier:id,supplier_name', 'branch:id,branch_name', 'items:id,purchase_receive_id,product_id,qty'])
            ->where('status', 'confirmed')
            ->where('is_reversed', false)
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($qq) use ($term) {
                    $qq->where('receive_code', 'ILIKE', "%{$term}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$term}%"));
                });
            })
            ->orderBy('receive_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(25);

        $receives = $query->get();

        // Post-filter: only include GRNs with at least one returnable item.
        $rows = [];
        foreach ($receives as $rcv) {
            $returnableItemCount = 0;
            foreach ($rcv->items as $item) {
                $alreadyReturned = DB::table('purchase_return_items')
                    ->where('purchase_receive_item_id', $item->id)
                    ->whereIn('purchase_return_id', function ($q) {
                        $q->select('id')->from('purchase_returns')
                          ->where('status', 'confirmed')
                          ->where('is_reversed', false);
                    })
                    ->sum('qty');
                if ((float) $item->qty - (float) $alreadyReturned > 0.0001) {
                    $returnableItemCount++;
                    break; // one is enough
                }
            }
            if ($returnableItemCount === 0) {
                continue;
            }
            $rows[] = [
                'id'             => $rcv->id,
                'receive_code'   => $rcv->receive_code,
                'supplier_id'    => $rcv->supplier_id,
                'supplier_name'  => $rcv->supplier?->supplier_name ?? '—',
                'branch_id'      => $rcv->branch_id,
                'branch_name'    => $rcv->branch?->branch_name ?? '',
                'receive_date'   => optional($rcv->receive_date)->format('Y-m-d'),
                'total_amount'   => (float) $rcv->total_amount,
            ];
        }

        // Legacy wraps the response in {status, data: [...]}.
        return response()->json([
            'status' => 'success',
            'data'   => $rows,
        ]);
    }

    /**
     * Phase 4 — CSV export of filtered returns (branch-scoped).
     * Mirrors legacy `PurchaseReturnController::export()`.
     */
    public function export(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        $search   = (string) ($request->input('search') ?? '');
        $showReversed = $request->boolean('reversed');

        $returns = PurchaseReturn::with(['supplier', 'branch', 'purchaseReceive'])
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($fromDate, fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($status && $status !== 'all', function ($q) use ($status, $showReversed) {
                if ($status === 'reversed') {
                    $q->where('is_reversed', true);
                } elseif ($status === 'active') {
                    $q->where('is_reversed', false);
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($showReversed, fn($q) => $q->where('is_reversed', true))
            ->when(! $showReversed && ! $status, fn($q) => $q->where('is_reversed', false))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('return_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('supplier', fn($sq) => $sq->where('supplier_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('branch', fn($bq) => $bq->where('branch_name', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('purchaseReceive', fn($pq) => $pq->where('receive_code', 'ILIKE', "%{$search}%"));
                });
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $filename = 'Purchase_Returns_' . now()->format('Y-m-d_His') . '.csv';

        return response()->stream(function () use ($returns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [
                'Return Code', 'GRN Code', 'Supplier', 'Branch',
                'Return Date', 'Total Amount', 'Status', 'Reversed',
                'Created By', 'Reason',
            ]);
            foreach ($returns as $r) {
                $statusLabel = [
                    'draft'     => 'Draft',
                    'confirmed' => 'Confirmed',
                    'cancelled' => 'Cancelled',
                ][$r->status] ?? ucfirst($r->status);

                fputcsv($out, [
                    $r->return_code,
                    $r->purchaseReceive?->receive_code ?? '',
                    $r->supplier?->supplier_name ?? '',
                    $r->branch?->branch_name ?? '',
                    optional($r->return_date)->format('Y-m-d'),
                    number_format((float) $r->total_amount, 2, '.', ''),
                    $statusLabel,
                    $r->is_reversed ? 'Yes' : 'No',
                    $r->created_by ? ('User #' . $r->created_by) : 'System',
                    $r->reason ?? '',
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
}
