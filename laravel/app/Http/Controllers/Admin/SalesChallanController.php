<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\PrepareGodownWebRequest;
use App\Models\SalesChallan;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesChallanService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sales Challan Controller — Phase 8.3.
 *
 * Two-step flow:
 * - prepareGodown: assign warehouses to invoice items (draft → confirmed)
 * - issueChallan: stock OUT + GL Dr COGS / Cr Inventory (confirmed → challan_issued)
 * - cancel: reverse stock + GL
 *
 * R3: issueChallan() now requires an idempotency_token (UUID v4) and
 * mirrors the finalize / payment-create pattern — duplicate submissions
 * within 10 minutes redirect to the originally-issued challan instead
 * of throwing "Challan already issued for this invoice." See
 * docs/REMEDIATION_LOG.md §R3.
 */
class SalesChallanController extends Controller
{
    public function __construct(
        private SalesChallanService $challanService,
        private StockService $stockService,
        private StockAvailabilityService $availabilityService
    ) {}

    public function index(Request $request)
    {
        // BUG-53: This page is the warehouse manager's workflow queue.
        // It shows THREE collections side-by-side so the WM can see
        // everything that needs their attention in one place:
        //
        //   1. pending_godown — invoices just finalized by salesmen,
        //      awaiting godown prep (warehouse assignment). Status=draft,
        //      is_godown_prepared=false, not reversed.
        //
        //   2. pending_challan — invoices with godown prep done, awaiting
        //      challan issue (physical stock OUT + COGS). is_godown_prepared=true,
        //      is_challan_issued=false, not reversed.
        //
        //   3. issued_challans — SalesChallan rows (already issued).
        //      Historical record of what has been dispatched.
        //
        // Branch filtering:
        //   - SalesInvoice has BranchScope global scope → non-admin users
        //     only see invoices where invoice.branch_id == their session
        //     branch. This is the intended behavior per BUG-53:
        //       * If a Head Office salesman creates an invoice with
        //         branch_id = Branch-B (because Branch-B should dispatch it),
        //         the invoice shows up in Branch-B's WM challan menu — NOT
        //         in Head Office's. The invoice "belongs" to the chosen
        //         dispatch branch, not the creator's branch.
        //   - SalesChallan also has BranchScope → same rule applies to
        //     issued challans.
        //   - Admins/superadmins bypass BranchScope and see all branches.
        //
        // Menu visibility for this page is permission-based (per-user
        // UserMenuPermission row, see MenuService). An admin can grant
        // any user access to this menu without changing their role.

        // --- Collection 1: Pending Godown Prep ---
        // Status=draft means "just finalized, awaiting godown prep".
        // Once godown is prepared, prepareGodown() flips status→confirmed.
        $pendingGodownQuery = SalesInvoice::with(['customer', 'branch', 'items'])
            ->where('status', 'draft')
            ->where('is_godown_prepared', false)
            ->where('is_reversed', false)
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($qc) use ($search) {
                      $qc->where('customer_name', 'ILIKE', "%{$search}%")
                         ->orWhere('customer_code', 'ILIKE', "%{$search}%");
                  });
            })
            ->orderBy('invoice_date', 'asc')   // oldest first — FIFO workflow
            ->orderBy('id', 'asc');

        // --- Collection 2: Pending Challan Issue ---
        // Godown prepared (status=confirmed) but challan not yet issued.
        $pendingChallanQuery = SalesInvoice::with(['customer', 'branch', 'items.warehouse'])
            ->where('status', 'confirmed')
            ->where('is_godown_prepared', true)
            ->where('is_challan_issued', false)
            ->where('is_reversed', false)
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($qc) use ($search) {
                      $qc->where('customer_name', 'ILIKE', "%{$search}%")
                         ->orWhere('customer_code', 'ILIKE', "%{$search}%");
                  });
            })
            ->orderBy('godown_prepared_at', 'asc')   // oldest godown first
            ->orderBy('id', 'asc');

        // --- Collection 3: Issued Challans (existing behavior, kept) ---
        $issuedChallansQuery = SalesChallan::with(['salesInvoice.customer', 'branch', 'salesInvoice.items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('challan_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('challan_date', '<=', $d))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('challan_code', 'ILIKE', "%{$search}%")
                  ->orWhereHas('salesInvoice', function ($qi) use ($search) {
                      $qi->where('invoice_code', 'ILIKE', "%{$search}%");
                  });
            })
            ->orderBy('challan_date', 'desc')
            ->orderBy('id', 'desc');

        $pendingGodown  = $pendingGodownQuery->limit(50)->get();
        $pendingChallan = $pendingChallanQuery->limit(50)->get();
        $challans       = $issuedChallansQuery->paginate(25);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Stats: workflow queue counts (scoped by BranchScope automatically).
        $stats = [
            'total'           => SalesChallan::count(),
            'active'          => SalesChallan::where('is_reversed', false)->count(),
            'reversed'        => SalesChallan::where('is_reversed', true)->count(),
            'total_cogs'      => SalesChallan::where('is_reversed', false)->sum('issue_cost'),
            // Workflow queue counts — these are the WM's "what needs doing" metrics.
            'pending_godown'  => SalesInvoice::where('status', 'draft')
                                    ->where('is_godown_prepared', false)
                                    ->where('is_reversed', false)
                                    ->count(),
            'pending_challan' => SalesInvoice::where('status', 'confirmed')
                                    ->where('is_godown_prepared', true)
                                    ->where('is_challan_issued', false)
                                    ->where('is_reversed', false)
                                    ->count(),
        ];

        return view('admin.sales-challans.index', [
            'title'           => 'Sales Challans',
            'challans'        => $challans,
            'pendingGodown'   => $pendingGodown,
            'pendingChallan'  => $pendingChallan,
            'branches'        => $branches,
            'stats'           => $stats,
            'filters'         => $request->only(['from_date', 'to_date', 'branch_id', 'search']),
        ]);
    }

    /**
     * Show the godown prep form.
     *
     * Phase 5: edit-godown mode. Previously this rejected any non-draft
     * invoice. Now it allows GET when the invoice is:
     *   - draft (first-time godown prep), OR
     *   - confirmed AND godown-prepared AND NOT challan-issued (re-edit of
     *     an already-prepared godown — the user may change warehouse
     *     assignments / dispatchers / CTN before the challan is issued).
     * It still rejects issued, reversed, or cancelled invoices.
     *
     * Phase 5: the per-warehouse availability shown in the dropdown is now
     * pipeline-aware (physical − open dispatch pipeline from OTHER
     * invoices) via StockAvailabilityService::getBranchWarehouseBreakdown,
     * passing the current invoice id as excludeInvoiceId so the invoice
     * being edited does not reserve against itself.
     */
    public function godown(Request $request, int $invoiceId)
    {
        $invoice = SalesInvoice::with(['items.product', 'dispatches', 'dispatchers', 'customer', 'branch'])
            ->findOrFail($invoiceId);

        $canEditGodown = $invoice->isDraft()
            || ($invoice->is_godown_prepared
                && !$invoice->is_challan_issued
                && !$invoice->isReversed()
                && !$invoice->isCancelled());

        if (!$canEditGodown) {
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('error', "Godown cannot be edited at this stage (status: {$invoice->status}, issued: " . ($invoice->is_challan_issued ? 'yes' : 'no') . ").");
        }

        // Get warehouses for the branch.
        $warehouses = \App\Models\Warehouse::active()
            ->where('branch_id', $invoice->branch_id)
            ->orderBy('warehouse_name')
            ->get();

        // Phase 5: pipeline-aware availability per product per warehouse.
        // The breakdown is mapped into the shape the view expects
        // (warehouse_id / warehouse_name / qty=available / avg_cost) plus
        // the extra physical_qty + pipeline_qty keys for an informative
        // tooltip. excludeInvoiceId = current invoice so its own open
        // dispatch rows do not count against the shown availability.
        $availability = [];
        foreach ($invoice->items as $item) {
            $breakdown = $this->availabilityService->getBranchWarehouseBreakdown(
                (int) $item->product_id,
                (int) $invoice->branch_id,
                (int) $invoice->id
            );
            $availability[$item->product_id] = collect($breakdown)->map(
                static fn ($r) => (object) [
                    'warehouse_id'  => $r['id'],
                    'warehouse_name'=> $r['warehouse_name'],
                    'qty'           => $r['available_qty'],
                    'physical_qty'  => $r['physical_qty'],
                    'pipeline_qty'  => $r['pipeline_qty'],
                    'avg_cost'      => $r['avg_cost'],
                ]
            );
        }

        return view('admin.sales-challans.godown', [
            'title' => 'Godown Prep — ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'warehouses' => $warehouses,
            'availability' => $availability,
        ]);
    }

    /**
     * Phase 3 — AJAX: list active dispatcher-role employees for the
     * invoice's branch. Returns Select2-compatible JSON
     * `[{id, text, name, phone}, ...]`.
     *
     * The branch is resolved from the required `?invoice_id=` query
     * param (NOT from a user-supplied branch_id) so the caller cannot
     * tamper with branch scoping. The invoice itself is branch-access
     * checked via SalesAccess::assertBranchAccessible.
     */
    public function dispatchers(Request $request)
    {
        $invoiceId = (int) $request->query('invoice_id', 0);
        if ($invoiceId <= 0) {
            return response()->json(['error' => 'invoice_id query parameter is required.'], 422);
        }

        $invoice = SalesInvoice::select('id', 'invoice_code', 'branch_id')->find($invoiceId);
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found.'], 404);
        }

        // Branch access check — defensive; route middleware already
        // enforces this for the session, but we double-check the
        // resolved invoice belongs to a branch the user can see.
        app(\App\Services\Sales\SalesAccess::class)->assertBranchAccessible($invoice->branch_id);

        $rows = \App\Models\Employee::query()
            ->where('role', 'dispatcher')
            ->where('is_active', true)
            ->where('branch_id', $invoice->branch_id)
            ->when($request->query('q'), static fn($q, $term) => $q
                ->where(static fn($qq) => $qq
                    ->where('name', 'ILIKE', "%{$term}%")
                    ->orWhere('employee_code', 'ILIKE', "%{$term}%")
                    ->orWhere('phone', 'ILIKE', "%{$term}%")))
            ->orderBy('name')
            ->select(['id', 'name', 'phone', 'employee_code'])
            ->get();

        $data = $rows->map(static fn($e) => [
            'id'            => $e->id,
            'text'          => $e->name . ($e->employee_code ? ' (' . $e->employee_code . ')' : ''),
            'name'          => $e->name,
            'phone'         => $e->phone,
            'employee_code' => $e->employee_code,
        ]);

        return response()->json(['results' => $data]);
    }

    /**
     * Save godown prep (assign warehouses + dispatchers + CTN packing).
     *
     * Phase 3: validation moved into PrepareGodownWebRequest; the
     * dispatcher_id[] array is passed through to the service which
     * syncs the BelongsToMany dispatchers() relationship.
     *
     * Phase 4: dispatched_ctn[] is passed through to the service which
     * persists it into sales_invoice_dispatches.dispatched_ctn.
     */
    public function storeGodown(PrepareGodownWebRequest $request, int $invoiceId)
    {
        $validated = $request->validated();

        try {
            $invoice = $this->challanService->prepareGodown(
                $invoiceId,
                $validated['warehouse_assignments'],
                auth()->id(),
                $validated['dispatcher_id'],
                $validated['dispatched_ctn'] ?? []
            );

            return redirect()->route('admin.sales-challans.challan-form', $invoiceId)
                ->with('success', "Godown prepared for invoice {$invoice->invoice_code}. Ready to issue challan.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the challan issue form.
     */
    public function challanForm(int $invoiceId)
    {
        $invoice = SalesInvoice::with(['items.product', 'items.warehouse', 'dispatches', 'customer', 'branch'])
            ->findOrFail($invoiceId);

        if (!$invoice->is_godown_prepared) {
            return redirect()->route('admin.sales-challans.godown', $invoiceId)
                ->with('error', 'Prepare godown first before issuing challan.');
        }

        // Get avg_cost for each item (the COGS rate).
        foreach ($invoice->items as $item) {
            $item->avg_cost = $this->stockService->getWarehouseAvgCost($item->warehouse_id, $item->product_id);
            $item->cogs_amount = (float) $item->qty * (float) $item->avg_cost;
        }

        $totalCogs = $invoice->items->sum('cogs_amount');

        return view('admin.sales-challans.issue', [
            'title' => 'Issue Challan — ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'totalCogs' => $totalCogs,
        ]);
    }

    /**
     * Issue the challan (stock OUT + GL COGS).
     *
     * R3: Idempotency — the form must send an `idempotency_token` (UUID v4).
     * If the same token was processed within 10 minutes, redirect to the
     * originally-issued challan with a warning flash instead of throwing
     * "Challan already issued for this invoice." Mirrors the finalize
     * (finalize:*) and payment-create (payment:*) cache-key conventions.
     */
    public function issueChallan(Request $request, int $invoiceId)
    {
        $validated = $request->validate([
            'transport_name' => 'nullable|string|max:100',
            'transport_phone' => 'nullable|string|max:30',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:100',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            // R3: Idempotency token (UUID v4) — mirrors the finalize pattern.
            'idempotency_token' => 'required|string|uuid',
        ]);

        // R3: Idempotency check — must run BEFORE the service call so a
        // replay is fully side-effect-free (no DB locks, no stock reads).
        $cacheKey = 'challan:' . $validated['idempotency_token'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            // Replay: redirect to the originally-issued challan with the
            // original success message + an additional warning flash.
            return redirect()
                ->route('admin.sales-challans.show', ['sales_challan' => $cached['challan_id']])
                ->with('success', $cached['success_message'])
                ->with('warning', 'Duplicate submission detected — returning the original result. No new challan was created.');
        }

        try {
            $challan = $this->challanService->issueChallan($invoiceId, [
                'transport_name' => $validated['transport_name'] ?? null,
                'transport_phone' => $validated['transport_phone'] ?? null,
                'vehicle_number' => $validated['vehicle_number'] ?? null,
                'driver_name' => $validated['driver_name'] ?? null,
                'transport_cost' => $validated['transport_cost'] ?? 0,
                'notes' => $validated['notes'] ?? '',
                'created_by' => auth()->id(),
            ]);

            $successMessage = "Challan {$challan->challan_code} issued. Stock moved + COGS posted.";

            // R3: Cache the redirect target + success message for 10 minutes
            // (idempotency window — same TTL as the finalize / payment patterns).
            Cache::put($cacheKey, [
                'challan_id'      => $challan->id,
                'challan_code'    => $challan->challan_code,
                'success_message' => $successMessage,
            ], 600);

            return redirect()->route('admin.sales-challans.show', $challan)
                ->with('success', $successMessage);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * P1-6: Print challan (delivery note).
     */
    public function printChallan(int $id)
    {
        $challan = SalesChallan::with([
            'items.product', 'items.warehouse',
            'salesInvoice.customer', 'branch',
        ])->findOrFail($id);

        return view('admin.sales-challans.print_challan', [
            'title' => 'Challan ' . $challan->challan_code,
            'challan' => $challan,
        ]);
    }

    public function show(int $id)
    {
        $challan = SalesChallan::with([
            'salesInvoice.items.product', 'salesInvoice.customer', 'branch',
            'journalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->where('st.reference_type', 'sales_challan')
            ->where('st.reference_id', $id)
            ->select('st.*', 'p.product_code', 'p.product_name', 'w.warehouse_name')
            ->orderBy('st.id')
            ->get();

        return view('admin.sales-challans.show', [
            'title' => 'Challan ' . $challan->challan_code,
            'challan' => $challan,
            'stockMovements' => $stockMovements,
        ]);
    }

    /**
     * Cancel a challan (reverse stock + GL).
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $challan = $this->challanService->cancelChallan($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.sales-challans.show', $challan)
                ->with('success', "Challan {$challan->challan_code} cancelled. Stock + GL reversed.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
