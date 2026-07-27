<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesReturn\ConfirmSalesReturnRequest;
use App\Http\Requests\SalesReturn\GetInvoiceDetailsRequest;
use App\Http\Requests\SalesReturn\ReverseSalesReturnRequest;
use App\Http\Requests\SalesReturn\StoreSalesReturnRequest;
use App\Models\SalesReturn;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesReturnableQty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Return Controller — Phase 8.5 + Phase 2 (Purchase Return parity).
 *
 * Two-phase: create (no GL) → confirm (stock IN at ORIGINAL cost + GL) → reverse.
 *
 * Phase 2 additions (Purchase Return parity):
 *   - index():        server-side DataTables JSON mode (?datatables=1)
 *   - summary():      chip counts AJAX (mirrors legacy return_filter_summary)
 *   - searchInvoices(): invoice typeahead AJAX (mirrors legacy search_invoice)
 *   - export():       CSV export of filtered returns
 *
 * Branch isolation for reads is enforced BOTH by the SalesReturn / SalesInvoice
 * BranchScope global scope AND by the explicit resolveBranchIdForRead() call
 * (admin "all-branches" override via ?branch_id= query param).
 */
class SalesReturnController extends Controller
{
    public function __construct(
        private SalesReturnService $returnService,
        private SalesReturnableQty $returnableQty,
    ) {}

    public function index(Request $request)
    {
        // Phase 2 — server-side DataTables JSON mode (mirrors Purchase Return).
        if ($request->boolean('datatables')) {
            $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);
            return $this->returnDataTableJson($request, $branchId);
        }

        $query = SalesReturn::with(['salesInvoice', 'customer', 'branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('return_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $returns = $query->paginate(25);

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => SalesReturn::count(),
            'created' => SalesReturn::where('status', 'created')->count(),
            'confirmed' => SalesReturn::where('status', 'confirmed')->count(),
            'reversed' => SalesReturn::where('status', 'reversed')->count(),
            'total_value' => SalesReturn::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.sales-returns.index', [
            'title' => 'Sales Returns',
            'returns' => $returns,
            'customers' => $customers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        // Phase 4 — workspace create page (typeahead find-invoice → return form).
        // Pre-fill the search box from ?invoice_id= (resolve to invoice_code) or
        // ?q= (raw search term). The workspace JS calls search-invoices +
        // invoice-details via AJAX, so we no longer eager-load the invoice's
        // items here (the old select2 dropdown list is dropped).
        $prefill = trim((string) (request()->input('q') ?? ''));
        if (!$prefill && request()->has('invoice_id')) {
            $inv = \App\Models\SalesInvoice::select('id', 'invoice_code')
                ->where('is_challan_issued', true)
                ->where('is_reversed', false)
                ->find((int) request()->input('invoice_id'));
            if ($inv) {
                $prefill = $inv->invoice_code;
            }
        }

        return view('admin.sales-returns.create', [
            'title'    => 'New Sales Return',
            'prefill'  => $prefill,
        ]);
    }

    public function store(StoreSalesReturnRequest $request)
    {
        $validated = $request->validated();

        try {
            $return = $this->returnService->createReturn([
                'sales_invoice_id' => $validated['sales_invoice_id'],
                'return_date' => $validated['return_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} created. Confirm to apply stock + GL.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * P1-6: Print return slip.
     */
    public function printSlip(int $id)
    {
        $return = SalesReturn::with([
            'items.product', 'items.warehouse',
            'salesInvoice.customer', 'branch',
        ])->findOrFail($id);

        return view('admin.sales-returns.print_slip', [
            'title' => 'Return Slip ' . $return->return_code,
            'salesReturn' => $return,
        ]);
    }

    public function show(int $id)
    {
        $return = SalesReturn::with([
            'items.product', 'items.warehouse', 'salesInvoice.customer', 'customer', 'branch',
            'journalEntry.lines.ledger', 'cogsJournalEntry.lines.ledger',
        ])->findOrFail($id);

        $stockMovements = [];
        if ($return->isConfirmed() || $return->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'sales_return')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        $customerLedgerEntries = [];
        if ($return->isConfirmed()) {
            $customerLedgerEntries = DB::table('customer_ledger')
                ->where('reference_type', 'sales_return')
                ->where('reference_id', $id)
                ->orderBy('id')
                ->get();
        }

        return view('admin.sales-returns.show', [
            'title' => 'Return ' . $return->return_code,
            'return' => $return,
            'stockMovements' => $stockMovements,
            'customerLedgerEntries' => $customerLedgerEntries,
        ]);
    }

    public function confirm(ConfirmSalesReturnRequest $request, int $id)
    {
        try {
            $return = $this->returnService->confirmReturn($id, auth()->id());
            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} confirmed. Stock restored at original cost + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reverse(ReverseSalesReturnRequest $request, int $id)
    {
        try {
            $return = $this->returnService->reverseReturn($id, auth()->id(), $request->input('reverse_reason'));

            // Phase 3.2 — AJAX-aware response. The index page's reverse button
            // posts via $.ajax and expects JSON; the show page's reverse button
            // submits the form synchronously and expects a redirect. Detect
            // via $request->expectsJson() (checks Accept: application/json header
            // set automatically by $.ajax with dataType:'json').
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => "Return {$return->return_code} reversed.",
                    'redirect' => route('admin.sales-returns.show', $return),
                ]);
            }

            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} reversed.");
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 422);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: Get invoice details for return form pre-fill.
     */
    public function getInvoiceDetails(GetInvoiceDetailsRequest $request)
    {
        $invoice = \App\Models\SalesInvoice::with(['items.product', 'items.warehouse', 'customer', 'branch'])
            ->where('is_challan_issued', true)
            ->where('is_reversed', false)
            ->findOrFail($request->input('invoice_id'));

        // Phase 4.3: look up ORIGINAL avg_cost per (product_id, warehouse_id)
        // from the active challan's stock_transactions, so the create-page
        // workspace can show the yellow-tinted "Original Cost" column that
        // documents the COGS-reversal + stock-IN cost (Laravel's BETTER-than-
        // legacy original_cost snapshot). Mirrors the prefill PHP in the old
        // create.blade.php. The service re-looks this up on store as the
        // source of truth (defense-in-depth); the UI value is display-only.
        $challan = \Illuminate\Support\Facades\DB::table('sales_challans')
            ->where('sales_invoice_id', $invoice->id)
            ->where('is_reversed', false)
            ->first();

        $origCostMap = [];
        if ($challan) {
            $costRows = \Illuminate\Support\Facades\DB::table('stock_transactions')
                ->where('reference_type', 'sales_challan')
                ->where('reference_id', $challan->id)
                ->where('is_reversed', false)
                ->select('product_id', 'warehouse_id', 'rate')
                ->get();
            foreach ($costRows as $row) {
                $origCostMap[(int) $row->product_id . ':' . (int) $row->warehouse_id] = (float) $row->rate;
            }
        }

        // Calculate returnable_qty for each item.
        $items = $invoice->items->map(function ($item) use ($origCostMap) {
            $alreadyReturned = (float) DB::table('sales_return_items as sri')
                ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
                ->where('sri.sales_invoice_item_id', $item->id)
                ->whereIn('sr.status', ['created', 'confirmed'])
                ->where('sr.is_reversed', false)
                ->sum('sri.qty');

            $origCost = $origCostMap[(int) $item->product_id . ':' . (int) $item->warehouse_id] ?? 0;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product?->product_code,
                'product_name' => $item->product?->product_name,
                'qty' => (float) $item->qty,
                'already_returned' => $alreadyReturned,
                'returnable_qty' => max(0, (float) $item->qty - $alreadyReturned),
                'rate' => (float) $item->rate,
                'original_cost' => $origCost,
                'warehouse_id' => $item->warehouse_id,
                'warehouse_name' => $item->warehouse?->warehouse_name,
            ];
        })->filter(fn($i) => $i['returnable_qty'] > 0.0001)->values();

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_code' => $invoice->invoice_code,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->customer_name,
                'branch_id' => $invoice->branch_id,
            ],
            'items' => $items,
        ]);
    }

    /**
     * Phase 2 — Invoice typeahead AJAX.
     * Mirrors legacy `SalesReturn::search_invoice` + Purchase Return `searchReceives`.
     *
     * Returns a list of challan-issued, non-reversed invoices with at least one
     * returnable item, matching $q against invoice_code / customer_name /
     * customer mobile / customer phone. Branch-scoped for non-admins.
     *
     * Response: {status: 'success', data: [{id, invoice_code, customer_id,
     *   customer_name, branch_id, branch_name, invoice_date, total_amount,
     *   returnable_total}]}
     */
    public function searchInvoices(Request $request)
    {
        $request->validate([
            'q'    => 'nullable|string|max:100',
            'term' => 'nullable|string|max:100', // alias (Purchase Return compat)
        ]);

        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $term = trim((string) ($request->input('q', $request->input('term', ''))));

        // Candidate invoices: challan-issued + non-reversed + non-cancelled +
        // branch-scoped + term match. We post-filter for returnable_qty > 0
        // in PHP because the returnable calc needs a correlated subquery on
        // sales_return_items. Limit 25 for typeahead UX.
        $query = \App\Models\SalesInvoice::with([
                'customer:id,customer_name,mobile,phone',
                'branch:id,branch_name',
                'items:id,sales_invoice_id,product_id,qty,rate',
            ])
            ->where('is_challan_issued', true)
            ->where('is_reversed', false)
            ->where('status', '!=', 'cancelled')
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($qq) use ($term) {
                    $qq->where('invoice_code', 'ILIKE', "%{$term}%")
                       ->orWhereHas('customer', function ($cq) use ($term) {
                           $cq->where('customer_name', 'ILIKE', "%{$term}%")
                              ->orWhere('mobile', 'ILIKE', "%{$term}%")
                              ->orWhere('phone', 'ILIKE', "%{$term}%");
                       });
                });
            })
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(25);

        $invoices = $query->get();

        // Batch-compute returnable qty for every invoice-item in ONE grouped
        // query (avoids N+1 across the 25 invoices × their line items).
        $allItemIds = $invoices->flatMap(fn($inv) => $inv->items->pluck('id'))->all();
        $returnableMap = $this->returnableQty->getReturnableQtyMap($allItemIds);

        $rows = [];
        foreach ($invoices as $inv) {
            $returnableTotal = 0.0;
            $hasReturnable = false;
            foreach ($inv->items as $item) {
                $returnable = $returnableMap[$item->id] ?? 0.0;
                if ($returnable > 0.0001) {
                    $hasReturnable = true;
                    $returnableTotal += $returnable * (float) $item->rate;
                }
            }
            // Exclude fully-returned invoices (no line has any returnable qty left).
            if (!$hasReturnable) {
                continue;
            }

            $rows[] = [
                'id'               => $inv->id,
                'invoice_code'     => $inv->invoice_code,
                'customer_id'      => $inv->customer_id,
                'customer_name'    => $inv->customer?->customer_name ?? '—',
                'branch_id'        => $inv->branch_id,
                'branch_name'      => $inv->branch?->branch_name ?? '',
                'invoice_date'     => optional($inv->invoice_date)->format('Y-m-d'),
                'total_amount'     => (float) $inv->total_amount,
                'returnable_total' => round($returnableTotal, 4),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data'   => $rows,
        ]);
    }

    /**
     * Phase 2 — Live chip counts AJAX.
     * Mirrors legacy `return_filter_summary` + Purchase Return `summary`.
     *
     * Returns JSON: {all, pending, confirmed, reversed} for the index page chip
     * badges. `pending` = status='created' AND is_reversed=false;
     * `confirmed` = status='confirmed' AND is_reversed=false;
     * `reversed` = is_reversed=true. Branch-scoped for non-admins.
     *
     * `total` (=all) and `active` (=pending+confirmed) aliases are included for
     * Purchase Return frontend parity.
     */
    public function summary(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $search   = (string) ($request->input('q', $request->input('search', '')));

        $base = SalesReturn::query()
            ->when($branchId > 0, fn($q) => $q->where('branch_id', $branchId))
            ->when($fromDate, fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('return_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('salesInvoice', fn($iq) => $iq->where('invoice_code', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('customer', fn($cq) => $cq->where('customer_name', 'ILIKE', "%{$search}%")
                           ->orWhere('mobile', 'ILIKE', "%{$search}%"));
                });
            });

        $all       = (clone $base)->count();
        $pending   = (clone $base)->where('is_reversed', false)->where('status', 'created')->count();
        $confirmed = (clone $base)->where('is_reversed', false)->where('status', 'confirmed')->count();
        $reversed  = (clone $base)->where('is_reversed', true)->count();

        return response()->json([
            'all'       => $all,
            'total'     => $all,       // alias (Purchase Return compat)
            'pending'   => $pending,
            'confirmed' => $confirmed,
            'active'    => $pending + $confirmed, // alias (Purchase Return compat)
            'reversed'  => $reversed,
        ]);
    }

    /**
     * Phase 2 — Server-side DataTables JSON endpoint.
     * Mirrors legacy `datatable_returns` + Purchase Return `returnDataTableJson`.
     *
     * Branch isolation is enforced via $branchId (non-admins only see their own
     * branch's returns) PLUS the SalesReturn BranchScope global scope.
     *
     * Columns (sort map):
     *   0 = return_code, 1 = invoice_code (via salesInvoice), 2 = customer_name,
     *   3 = return_date,  4 = total_amount, 5 = is_reversed,    6 = id (actions)
     *
     * Smart-sort: when enabled (default), active returns sort before reversed
     * ones regardless of the column being sorted on (matches Purchase Return).
     */
    private function returnDataTableJson(Request $request, ?int $branchId)
    {
        $draw   = (int) $request->input('draw', 1);
        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $length = $length > 0 ? $length : 25;
        $search = (string) $request->input('search.value', $request->input('q', $request->input('search', '')));
        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        $invoiceCode = $request->input('invoice_code');
        // Legacy sends ?reversed=1 to flip into "show reversed only" mode.
        $showReversed = $request->boolean('reversed');
        $smartSort    = $request->input('smart_sort', '1') !== '0';

        $orderColIdx = (int) ($request->input('order.0.column', 0));
        $orderDir    = strtolower((string) ($request->input('order.0.dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $orderMap = [
            0 => 'return_code',
            1 => 'sales_invoice_id',  // invoice_code — fall back to id sort (joined in select)
            2 => 'customer_id',
            3 => 'return_date',
            4 => 'total_amount',
            5 => 'is_reversed',
            6 => 'id',
        ];
        $orderCol = $orderMap[$orderColIdx] ?? 'return_date';

        $base = SalesReturn::query()
            ->with([
                'salesInvoice:id,invoice_code',
                'customer:id,customer_name',
                'branch:id,branch_name',
                'items:id,sales_return_id',
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
            ->when($invoiceCode, fn($q, $code) => $q->whereHas('salesInvoice', fn($iq) => $iq->where('invoice_code', 'ILIKE', "%{$code}%")))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('return_code', 'ILIKE', "%{$search}%")
                       ->orWhereHas('salesInvoice', fn($iq) => $iq->where('invoice_code', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('customer', fn($cq) => $cq->where('customer_name', 'ILIKE', "%{$search}%")
                           ->orWhere('mobile', 'ILIKE', "%{$search}%"));
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

        $data = $rows->map(function ($r) {
            // Status pill: reversed (red) takes precedence; else pending/confirmed (green).
            if ($r->is_reversed) {
                $statusLabel = 'Reversed';
            } elseif ($r->status === 'confirmed') {
                $statusLabel = 'Confirmed';
            } else {
                $statusLabel = 'Pending';
            }

            return [
                'id'              => $r->id,
                'return_code'     => $r->return_code,
                'invoice_code'    => $r->salesInvoice?->invoice_code ?? '',
                'invoice_id'      => $r->sales_invoice_id,
                'customer_name'   => $r->customer?->customer_name ?? '—',
                'branch_name'     => $r->branch?->branch_name ?? '',
                'return_date'     => optional($r->return_date)->format('Y-m-d'),
                'total_amount'    => (float) $r->total_amount,
                'is_reversed'     => (bool) $r->is_reversed,
                'status'          => $r->status,
                'status_label'    => $statusLabel,
                'created_by_name' => $r->created_by ? ('User #' . $r->created_by) : 'System',
                'show_url'        => route('admin.sales-returns.show', $r),
                'reverse_url'     => route('admin.sales-returns.reverse', $r),
                'can_reverse'     => ! $r->is_reversed && $r->status === 'confirmed',
            ];
        })->values();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * Phase 2 — CSV export of filtered returns (branch-scoped).
     * Mirrors legacy `SalesReturn::export` + Purchase Return `export`.
     *
     * Columns: Return Code / Invoice Code / Customer / Branch / Return Date /
     * Total Amount / Status / Reversed / Created By / Reason.
     * UTF-8 BOM prefix so Excel opens Bengali characters correctly.
     */
    public function export(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $fromDate = $request->input('date_from') ?: $request->input('from_date');
        $toDate   = $request->input('date_to')   ?: $request->input('to_date');
        $status   = $request->input('filterStatus') ?: $request->input('status');
        $search   = (string) ($request->input('q', $request->input('search', '')));
        $showReversed = $request->boolean('reversed');

        $returns = SalesReturn::with(['salesInvoice', 'customer', 'branch'])
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
                       ->orWhereHas('salesInvoice', fn($iq) => $iq->where('invoice_code', 'ILIKE', "%{$search}%"))
                       ->orWhereHas('customer', fn($cq) => $cq->where('customer_name', 'ILIKE', "%{$search}%")
                           ->orWhere('mobile', 'ILIKE', "%{$search}%"));
                });
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $filename = 'Sales_Returns_' . now()->format('Y-m-d_His') . '.csv';

        return response()->stream(function () use ($returns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [
                'Return Code', 'Invoice Code', 'Customer', 'Branch',
                'Return Date', 'Total Amount', 'Status', 'Reversed',
                'Created By', 'Reason',
            ]);
            foreach ($returns as $r) {
                if ($r->is_reversed) {
                    $statusLabel = 'Reversed';
                } elseif ($r->status === 'confirmed') {
                    $statusLabel = 'Confirmed';
                } else {
                    $statusLabel = 'Pending';
                }

                fputcsv($out, [
                    $r->return_code,
                    $r->salesInvoice?->invoice_code ?? '',
                    $r->customer?->customer_name ?? '',
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

    /**
     * Phase 3.4 — Per-module audit-log page for sales returns.
     *
     * Reads user_audit_log filtered by action prefix 'return_' (per
     * SalesAuditLogger: return_created / return_confirmed / return_reversed).
     * Mirrors PurchaseReturnController::audit() structure and reuses the
     * shared admin.purchase.partials.audit-log-table partial (the partial is
     * module-agnostic — accepts $module, $moduleLabel, $indexRoute, $filters).
     *
     * RBAC: accountant, manager, admin (matches the reverse action RBAC —
     * anyone who can reverse a return can view its audit trail; salesman
     * and warehouse_manager are excluded because the audit trail may
     * contain reverse reasons + GL amounts that are accountant-scope).
     */
    public function audit(Request $request)
    {
        $branchId = $this->resolveBranchIdForRead($request->input('branch_id') ? (int) $request->input('branch_id') : null);

        $query = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->leftJoin('branches as b', 'b.id', '=', 'ual.branch_id')
            ->where('ual.action', 'LIKE', 'return_%')
            ->when($branchId > 0, fn($q) => $q->where('ual.branch_id', $branchId))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('ual.action', 'ILIKE', "%{$search}%")
                       ->orWhere('u.username', 'ILIKE', "%{$search}%")
                       ->orWhere('e.name', 'ILIKE', "%{$search}%");
                });
            })
            ->select(
                'ual.id',
                'ual.created_at as logged_at',
                'ual.user_id',
                'ual.action',
                'ual.target_user_id as target_id',
                'ual.branch_id',
                'ual.details',
                'ual.ip_address',
                'u.username',
                'e.name as employee_name',
                'b.branch_name'
            )
            ->orderBy('ual.created_at', 'desc')
            ->orderBy('ual.id', 'desc');

        $logs = $query->paginate(100)->withQueryString();

        return view('admin.sales-returns.audit', [
            'title'       => 'Sales Return — Audit Log',
            'logs'        => $logs,
            'filters'     => $request->only(['search', 'branch_id']),
            'module'      => 'sales_return',
            'moduleLabel' => 'Sales Return',
            'indexRoute'  => route('admin.sales-returns.index'),
        ]);
    }
}
