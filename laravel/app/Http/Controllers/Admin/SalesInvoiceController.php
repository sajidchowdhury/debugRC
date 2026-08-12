<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Models\Employee;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesCartService;
use App\Services\Sales\SalesAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Invoice Controller — Phase 8.2.
 *
 * Manages draft sales invoices created from the cart.
 * - finalize: cart → draft invoice (GL Dr AR / Cr Revenue + credit limit check)
 * - index: list invoices
 * - show: detail with items + dispatches + GL journal + customer ledger
 * - cancel: reverse draft invoice (GL + customer_ledger)
 */
class SalesInvoiceController extends Controller
{
    public function __construct(
        private SalesInvoiceService $invoiceService,
        private SalesCartService $cartService,
        private SalesAuditLogger $auditLogger
    ) {}

    public function index(Request $request)
    {
        // BUG-52: support new workflow chips — 'today', 'pending_godown',
        // 'pending_challan'. These pre-set the standard filter combos so
        // sales/warehouse users have one-click queues instead of having
        // to manually set from_date/to_date each time.
        $today = now()->format('Y-m-d');
        $scope = $request->input('scope');

        // Resolve scope → effective date range + status filters.
        $effectiveFrom = $request->input('from_date');
        $effectiveTo   = $request->input('to_date');
        $forcePendingGodown  = false;
        $forcePendingChallan = false;

        if ($scope === 'today') {
            $effectiveFrom = $effectiveFrom ?? $today;
            $effectiveTo   = $effectiveTo   ?? $today;
        } elseif ($scope === 'pending_godown') {
            // Invoices awaiting godown prep: status confirmed (i.e. not draft),
            // is_godown_prepared=false, not reversed. Across all dates so the
            // warehouse manager sees the full backlog, not just today's.
            $forcePendingGodown = true;
        } elseif ($scope === 'pending_challan') {
            // Godown prepared but challan not yet issued.
            $forcePendingChallan = true;
        }

        // F-2: Hide invoices flagged call_a_day from the default view across
        // ALL scopes/chips (not just scope=today). Admin/manager can bypass
        // with ?include_called=1 for auditing called-it-a-day invoices.
        $includeCalled = $this->shouldIncludeCalledInvoices($request);

        $query = SalesInvoice::with(['customer', 'branch', 'items'])
            ->when(! $includeCalled, fn($q) => $q->where('call_a_day', false))
            ->when($effectiveFrom, fn($q, $d) => $q->where('invoice_date', '>=', $d))
            ->when($effectiveTo,   fn($q, $d) => $q->where('invoice_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($forcePendingGodown, function ($q) {
                $q->where('status', 'confirmed')
                  ->where('is_godown_prepared', false)
                  ->where('is_reversed', false);
            })
            ->when($forcePendingChallan, function ($q) {
                $q->where('is_godown_prepared', true)
                  ->where('is_challan_issued', false)
                  ->where('is_reversed', false);
            })
            ->when(!$forcePendingGodown && !$forcePendingChallan && $request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        $invoices = $query->paginate(25);

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // BUG-52: extended stats with workflow-relevant counts so the
        // index page can render Today / Pending Godown / Pending Challan
        // chips with live counts.
        // F-2: chip counts honor the same call_a_day filter as the list
        // (and the summary() AJAX endpoint) so the initial render matches
        // the AJAX-refreshed counts. Admin/manager with ?include_called=1
        // see the unfiltered counts.
        $statsBase = SalesInvoice::query()
            ->when(! $includeCalled, fn($q) => $q->where('call_a_day', false));
        $stats = [
            'total'           => (clone $statsBase)->count(),
            'today'           => (clone $statsBase)->where('invoice_date', $today)->count(),
            'draft'           => (clone $statsBase)->where('status', 'draft')->count(),
            'confirmed'       => (clone $statsBase)->where('status', 'confirmed')->count(),
            'cancelled'       => (clone $statsBase)->where('status', 'cancelled')->count(),
            'pending_godown'  => (clone $statsBase)->where('status', 'confirmed')
                                    ->where('is_godown_prepared', false)
                                    ->where('is_reversed', false)
                                    ->count(),
            'pending_challan' => (clone $statsBase)->where('is_godown_prepared', true)
                                    ->where('is_challan_issued', false)
                                    ->where('is_reversed', false)
                                    ->count(),
            'total_value'     => (float) (clone $statsBase)->whereNotIn('status', ['cancelled'])->sum('total_amount'),
        ];

        // F-20: Stale-draft count for the dismissible warning banner.
        // A draft is "stale" when it is older than config('sales.stale_draft_days')
        // (default 14), not reversed, and (consistent with the page's call_a_day
        // filter) not flagged call-it-a-day unless the user opted into those.
        // The count is independent of the active date/scope chips so the banner
        // surfaces stale drafts even when the user is viewing "today" only.
        $staleDays = (int) config('sales.stale_draft_days', 14);
        $staleCount = (clone $statsBase)
            ->where('status', 'draft')
            ->where('is_reversed', false)
            ->where('invoice_date', '<', now()->subDays($staleDays)->format('Y-m-d'))
            ->count();

        // F-20: "Cancel stale drafts" link is role-gated to match the
        // admin.sales.cancel-stale-drafts route middleware (role:manager,admin).
        // Salesmen / accountants see a disabled tooltip instead.
        $canCancelStaleDrafts = auth()->user()?->hasRole('manager', 'admin') ?? false;

        return view('admin.sales-invoices.index', [
            'title'    => 'Sales Invoices',
            'invoices' => $invoices,
            'customers'=> $customers,
            'branches' => $branches,
            'stats'    => $stats,
            'filters'  => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search', 'scope']),
            'scope'    => $scope,
            'staleCount'           => $staleCount,
            'staleDays'            => $staleDays,
            'canCancelStaleDrafts' => $canCancelStaleDrafts,
        ]);
    }

    /**
     * Finalize a cart into a draft invoice (AJAX endpoint).
     * P2-6: Idempotency token prevents duplicate invoice creation on
     * double-click or refresh-after-submit.
     */
    public function finalize(Request $request)
    {
        // Phase 6: defense-in-depth policy check (mirrors route role middleware).
        $this->authorize('create', SalesInvoice::class);

        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'invoice_date' => 'required|date',
            'salesman_id' => 'nullable|integer',
            'sales_person' => 'nullable|string|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'is_soft_hold' => 'nullable|boolean',
            'credit_limit_override' => 'nullable|boolean',
            // R26 (2026-07-22): min:10 parity with Legacy SalesInvoiceOperationsTrait.
            'override_reason' => 'nullable|string|min:10|max:500',
            'idempotency_token' => 'required|string|uuid',
            'dispatcher_ids'   => 'nullable|array',
            'dispatcher_ids.*' => 'integer|exists:employees,id',
        ]);

        // P2-6: Idempotency check — if this token was already processed,
        // return the cached response (prevents duplicate invoice on double-submit).
        $cacheKey = 'finalize:' . $validated['idempotency_token'];
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($cached !== null) {
            // Return the original response — this is a duplicate submission.
            return response()->json(array_merge($cached, [
                'idempotent_replay' => true,
                'message' => 'Duplicate submission detected — returning the original result.',
            ]));
        }

        try {
            $invoice = $this->invoiceService->finalizeFromCart([
                'customer_id' => $validated['customer_id'],
                'branch_id' => $validated['branch_id'],
                'invoice_date' => $validated['invoice_date'],
                'salesman_id' => $validated['salesman_id'] ?? null,
                'sales_person' => $validated['sales_person'] ?? null,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'transport_cost' => $validated['transport_cost'] ?? 0,
                'notes' => $validated['notes'] ?? '',
                'is_soft_hold' => $validated['is_soft_hold'] ?? false,
                'credit_limit_override' => $validated['credit_limit_override'] ?? false,
                'override_reason' => $validated['override_reason'] ?? '',
                'created_by' => auth()->id(),
                'dispatcher_ids' => $validated['dispatcher_ids'] ?? [],
            ]);

            $response = [
                'status' => 'success',
                'message' => "Invoice {$invoice->invoice_code} created (draft). GL posted.",
                'invoice_id' => $invoice->id,
                'invoice_code' => $invoice->invoice_code,
                'redirect' => route('admin.sales-invoices.show', $invoice),
            ];

            // P2-6: Cache the response for 10 minutes (idempotency window).
            \Illuminate\Support\Facades\Cache::put($cacheKey, $response, 600);

            return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Show the invoice edit form (P1-1).
     * Only draft invoices (no godown, no payments) can be edited.
     */
    public function edit(int $id)
    {
        $invoice = SalesInvoice::with(['items.product', 'customer', 'branch', 'dispatchers'])
            ->findOrFail($id);

        // Guard: only draft invoices can be edited.
        if (!$invoice->isDraft() || $invoice->is_godown_prepared || $invoice->is_reversed) {
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('error', 'Only draft invoices (before godown) can be edited.');
        }

        // Check for payments — if any exist, block editing.
        $hasPayments = DB::table('invoice_payment_allocations as ipa')
            ->join('customer_payments as cp', 'cp.id', '=', 'ipa.payment_id')
            ->where('ipa.invoice_id', $id)
            ->where('cp.is_reversed', false)
            ->exists();

        if ($hasPayments) {
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('error', 'Cannot edit: payments have been received against this invoice. Reverse the payments first.');
        }

        // Load products for the add-item dropdown (active products).
        $products = \App\Models\Product::active()->with(['category'])->orderBy('product_name')->limit(500)->get();

        // Load dispatchers for the multi-select (active dispatcher-role employees for this branch).
        $dispatchers = Employee::dispatchers()
            ->where('branch_id', (int) $invoice->branch_id)
            ->orderBy('name')
            ->get();

        // Currently assigned dispatcher IDs (for pre-selecting in the dropdown).
        $assignedDispatcherIds = $invoice->dispatchers->pluck('id')->toArray();

        return view('admin.sales-invoices.edit', [
            'title' => 'Edit Invoice ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'products' => $products,
            'dispatchers' => $dispatchers,
            'assignedDispatcherIds' => $assignedDispatcherIds,
        ]);
    }

    /**
     * Update a draft invoice (P1-1).
     * Reverses old GL + customer_ledger, re-posts with new items/totals.
     */
    public function update(Request $request, int $id)
    {
        $invoice = SalesInvoice::findOrFail($id);
        // Phase 6: defense-in-depth policy check (mirrors route role middleware).
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.condition_state' => 'nullable|string|in:Good,Damage',
            'invoice_date' => 'required|date',
            'sales_person' => 'nullable|string|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'is_soft_hold' => 'nullable|boolean',
            'credit_limit_override' => 'nullable|boolean',
            // R26 (2026-07-22): min:10 parity with Legacy SalesInvoiceOperationsTrait.
            'override_reason' => 'nullable|string|min:10|max:500',
            'dispatcher_ids'   => 'nullable|array',
            'dispatcher_ids.*' => 'integer|exists:employees,id',
        ]);

        try {
            $invoice = $this->invoiceService->updateInvoice($id, [
                'items' => $validated['items'],
                'invoice_date' => $validated['invoice_date'],
                'sales_person' => $validated['sales_person'] ?? null,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'transport_cost' => $validated['transport_cost'] ?? 0,
                'notes' => $validated['notes'] ?? '',
                'is_soft_hold' => $validated['is_soft_hold'] ?? false,
                'credit_limit_override' => $validated['credit_limit_override'] ?? false,
                'override_reason' => $validated['override_reason'] ?? '',
                'updated_by' => auth()->id(),
                'dispatcher_ids' => $validated['dispatcher_ids'] ?? [],
            ]);

            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->invoice_code} updated successfully. GL + customer ledger re-posted.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Manual admin endpoint: cancel stale draft invoices (P1-2).
     * Calls the Artisan command's underlying logic via the service.
     * Accessible via POST /admin/sales/cancel-stale-drafts (manager+admin only).
     */
    public function cancelStaleDrafts(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'dry_run' => 'nullable|boolean',
        ]);

        $days = (int) ($request->input('days') ?: config('sales.stale_draft_days', 14));
        $dryRun = (bool) $request->input('dry_run', false);
        $systemUserId = (int) (config('sales.stale_draft_cancelled_by') ?: 1);

        // Query stale drafts (limit per config).
        $maxPerRun = (int) config('sales.stale_draft_max_per_run', 200);
        $staleDrafts = SalesInvoice::where('status', 'draft')
            ->where('is_reversed', false)
            ->where('is_godown_prepared', false)
            ->where('is_challan_issued', false)
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id', 'asc')
            ->limit($maxPerRun)
            ->get(['id', 'invoice_code', 'created_at', 'total_amount', 'branch_id']);

        $count = $staleDrafts->count();

        if ($dryRun) {
            return response()->json([
                'status' => 'dry_run',
                'days' => $days,
                'found' => $count,
                'drafts' => $staleDrafts->map(fn($i) => [
                    'id' => $i->id,
                    'invoice_code' => $i->invoice_code,
                    'created_at' => $i->created_at?->toIso8601String(),
                    'total_amount' => (float) $i->total_amount,
                    'branch_id' => $i->branch_id,
                ]),
            ]);
        }

        $cancelled = 0;
        $errors = [];
        $reason = "Stale draft manual cancel (>{$days} days)";

        foreach ($staleDrafts as $draft) {
            try {
                $this->invoiceService->cancelInvoice($draft->id, $systemUserId, $reason);
                $cancelled++;
            } catch (\Throwable $e) {
                $errors[] = "{$draft->invoice_code}: {$e->getMessage()}";
            }
        }

        // Audit log.
        DB::table('user_audit_log')->insert([
            'user_id' => auth()->id() ?? $systemUserId,
            'action' => 'stale_drafts_cancelled',
            'target_user_id' => null,
            'branch_id' => null,
            'details' => json_encode([
                'cancelled_count' => $cancelled,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, 20),
                'days_threshold' => $days,
                'reason' => $reason,
                'trigger' => 'manual_endpoint',
                'triggered_by' => auth()->id(),
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'days' => $days,
            'found' => $count,
            'cancelled' => $cancelled,
            'errors' => $errors,
        ]);
    }

    /**
     * Sales audit trail (P1-3).
     * Displays recent sales business events from user_audit_log.
     */
    public function auditTrail(Request $request)
    {
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $actionFilter = $request->input('action');
        $limit = min(500, max(50, (int) $request->input('limit', 300)));

        $query = DB::table('user_audit_log')
            ->whereIn('action', [
                // G-161 (SALES-AUDIT-2): action list now mirrors
                // SalesAuditLogger::recentSalesEvents() exactly, so the
                // audit-trail web view no longer silently hides cart
                // tampering events (R4) or payment allocation sub-types.
                'sale_created', 'sale_updated', 'sale_cancelled', 'sale_call_a_day',
                'credit_limit_override',
                'payment_received', 'payment_reversed',
                'payment_discount', 'payment_write_off', 'payment_refund',
                'return_created', 'return_confirmed', 'return_reversed',
                'godown_prepared', 'challan_issued', 'challan_reversed',
                'stale_drafts_cancelled',
                // R4: cart mutation events (were OMITTED — gap G9/G-161)
                'cart_item_added', 'cart_item_updated',
                'cart_item_removed', 'cart_cleared',
                // Commission events (fire since SALES-2 wired the pipeline)
                'commission_rule_created', 'commission_calculated',
                'commission_reversed_on_return',
                'commission_reversed_on_payment_reversal',
                'commission_period_confirmed',
            ]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($actionFilter) {
            $query->where('action', $actionFilter);
        }

        $events = $query->orderByDesc('id')->limit($limit)->get();

        // Load user names for display.
        $userIds = $events->pluck('user_id')->unique()->filter()->toArray();
        $users = DB::table('users')
            ->join('employees', 'employees.id', '=', 'users.employee_id')
            ->whereIn('users.id', $userIds)
            ->pluck('employees.name', 'users.id');

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Action labels for display.
        // G-161 (SALES-AUDIT-2): added labels for the 3 payment allocation
        // sub-types + 4 R4 cart events + 5 commission events that were
        // previously omitted from the inline action list.
        $actionLabels = [
            'sale_created' => ['label' => 'Invoice Created', 'icon' => 'fa-file-circle-plus', 'color' => 'success'],
            'sale_updated' => ['label' => 'Invoice Updated', 'icon' => 'fa-pen-to-square', 'color' => 'primary'],
            'sale_cancelled' => ['label' => 'Invoice Cancelled', 'icon' => 'fa-ban', 'color' => 'danger'],
            'sale_call_a_day' => ['label' => 'Call It A Day', 'icon' => 'fa-check-circle', 'color' => 'secondary'],
            'credit_limit_override' => ['label' => 'Credit Override', 'icon' => 'fa-shield-halved', 'color' => 'warning'],
            'payment_received' => ['label' => 'Payment Received', 'icon' => 'fa-money-bill-wave', 'color' => 'success'],
            'payment_reversed' => ['label' => 'Payment Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'payment_discount' => ['label' => 'Payment Discount', 'icon' => 'fa-tag', 'color' => 'info'],
            'payment_write_off' => ['label' => 'Payment Write-off', 'icon' => 'fa-file-circle-xmark', 'color' => 'warning'],
            'payment_refund' => ['label' => 'Payment Refund', 'icon' => 'fa-arrow-rotate-left', 'color' => 'danger'],
            'return_created' => ['label' => 'Return Created', 'icon' => 'fa-arrow-rotate-left', 'color' => 'info'],
            'return_confirmed' => ['label' => 'Return Confirmed', 'icon' => 'fa-check', 'color' => 'primary'],
            'return_reversed' => ['label' => 'Return Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'godown_prepared' => ['label' => 'Godown Prepared', 'icon' => 'fa-warehouse', 'color' => 'primary'],
            'challan_issued' => ['label' => 'Challan Issued', 'icon' => 'fa-truck', 'color' => 'success'],
            'challan_reversed' => ['label' => 'Challan Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'stale_drafts_cancelled' => ['label' => 'Stale Drafts Cleaned', 'icon' => 'fa-broom', 'color' => 'secondary'],
            // R4: cart mutation events (were HIDDEN — gap G9/G-161)
            'cart_item_added' => ['label' => 'Cart Item Added', 'icon' => 'fa-cart-plus', 'color' => 'info'],
            'cart_item_updated' => ['label' => 'Cart Item Updated', 'icon' => 'fa-pen-to-square', 'color' => 'primary'],
            'cart_item_removed' => ['label' => 'Cart Item Removed', 'icon' => 'fa-cart-arrow-down', 'color' => 'warning'],
            'cart_cleared' => ['label' => 'Cart Cleared', 'icon' => 'fa-trash-can', 'color' => 'danger'],
            // Commission events (fire since SALES-2)
            'commission_rule_created' => ['label' => 'Commission Rule Created', 'icon' => 'fa-gavel', 'color' => 'primary'],
            'commission_calculated' => ['label' => 'Commission Calculated', 'icon' => 'fa-calculator', 'color' => 'success'],
            'commission_reversed_on_return' => ['label' => 'Commission Reversed (Return)', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'commission_reversed_on_payment_reversal' => ['label' => 'Commission Reversed (Payment)', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'commission_period_confirmed' => ['label' => 'Commission Period Confirmed', 'icon' => 'fa-check-circle', 'color' => 'success'],
        ];

        return view('admin.sales-audit.index', [
            'title' => 'Sales Audit Trail',
            'events' => $events,
            'users' => $users,
            'branches' => $branches,
            'actionLabels' => $actionLabels,
            'filters' => $request->only(['branch_id', 'action', 'limit']),
        ]);
    }

    /**
     * P1-6: Print invoice (paginated, A4-friendly).
     *
     * Supports two output modes:
     *   - ?mode=pdf  → DomPDF server-side PDF (exact layout, multi-page)
     *   - default    → HTML view (browser preview, then window.print())
     *
     * Branch-specific header/footer images are loaded from the branch model
     * and injected into the view for rendering.
     */
    public function printInvoice(int $id)
    {
        $invoice = SalesInvoice::with(['items.product', 'customer', 'branch.company', 'salesman', 'dispatchers'])
            ->findOrFail($id);

        $data = [
            'title'   => 'Invoice ' . $invoice->invoice_code,
            'invoice' => $invoice,
        ];

        // If ?mode=pdf, render via DomPDF for exact multi-page output
        if (request()->input('mode') === 'pdf') {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
                'admin.sales-invoices.print_invoice', $data
            );
            $pdf->setPaper('A4', 'portrait');
            return $pdf->stream('invoice-' . $invoice->invoice_code . '.pdf');
        }

        // Default: HTML view (browser print dialog)
        return view('admin.sales-invoices.print_invoice', $data);
    }

    /**
     * P1-6: Print godown copy (picking list for warehouse staff).
     */
    public function printGodown(int $id)
    {
        $invoice = SalesInvoice::with(['items.product', 'items.warehouse', 'customer', 'branch'])
            ->findOrFail($id);

        return view('admin.sales-invoices.print_godown', [
            'title' => 'Godown Copy — ' . $invoice->invoice_code,
            'invoice' => $invoice,
        ]);
    }

    /**
     * Print blank godown copy — handwriting template for manual picking.
     * Bengali/English bilingual, blank write-in cells, 17 items per page.
     * Ported from legacy: challan/print_blank_godown_copy.php
     */
    public function printBlankGodown(int $id)
    {
        $invoice = SalesInvoice::with([
            'items.product', 'items.warehouse',
            'customer', 'branch', 'salesman', 'dispatchers',
        ])->findOrFail($id);

        return view('admin.sales-invoices.print_blank_godown', [
            'title' => 'খালি গোডাউন / Blank Godown — ' . $invoice->invoice_code,
            'invoice' => $invoice,
        ]);
    }

    /**
     * AJAX: Get dispatchers for a branch (for finalize/edit dropdowns).
     */
    public function getBranchDispatchers(Request $request)
    {
        $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $branchId = (int) $request->input('branch_id');

        $dispatchers = Employee::dispatchers()
            ->where('branch_id', $branchId)
            ->orderBy('name')
            ->get(['id', 'employee_code', 'name', 'phone']);

        return response()->json($dispatchers);
    }

    /**
     * Assign dispatchers to an invoice (AJAX endpoint).
     * Accepts dispatcher_ids array — replaces existing assignments.
     */
    public function assignDispatchers(Request $request, int $id)
    {
        $validated = $request->validate([
            'dispatcher_ids' => 'nullable|array',
            'dispatcher_ids.*' => 'integer|exists:employees,id',
        ]);

        try {
            $this->invoiceService->assignDispatchers($id, $validated['dispatcher_ids'] ?? []);

            $invoice = SalesInvoice::with('dispatchers')->find($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Dispatchers updated.',
                'dispatchers' => $invoice->dispatchers->map(fn($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'dispatch_role' => $d->pivot->dispatch_role,
                ]),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function show(int $id)
    {
        $invoice = SalesInvoice::with([
            'items.product', 'dispatches.product', 'customer', 'branch', 'salesman', 'dispatchers',
            'journalEntry.lines.ledger'
        ])->findOrFail($id);

        // Customer ledger entries for this invoice.
        $customerLedgerEntries = DB::table('customer_ledger')
            ->where('reference_type', 'sales_invoice')
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get();

        return view('admin.sales-invoices.show', [
            'title' => 'Invoice ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'customerLedgerEntries' => $customerLedgerEntries,
        ]);
    }

    /**
     * Cancel a draft invoice.
     */
    public function cancel(Request $request, int $id)
    {
        // Phase 6: defense-in-depth policy check (mirrors route role middleware).
        $this->authorize('delete', SalesInvoice::findOrFail($id));

        $request->validate([
            // Phase 3 (business): min:5 parity with Legacy
            // SalesPaymentOperationsTrait::reverseCustomerPayment() runtime
            // check `if (strlen($reason) < 5) { return error; }` — and with
            // CustomerPaymentController::cancel() (line 301) which already
            // enforces min:5. Closes the curl-bypass gap where the client-side
            // SweetAlert2 inputValidator (index.blade.php) could be skipped by
            // a direct POST, allowing a 1-char reason to be audit-logged.
            'cancel_reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $invoice = $this->invoiceService->cancelInvoice($id, auth()->id(), $request->input('cancel_reason'));
            // Phase 1 (UI/UX): AJAX branch — the inline cancel action
            // (overflow dropdown) posts via AJAX and expects JSON.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'invoice_id'   => $invoice->id,
                    'invoice_code' => $invoice->invoice_code,
                    'message'      => "Invoice {$invoice->invoice_code} cancelled.",
                ]);
            }
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->invoice_code} cancelled.");
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: Get cart data for the finalize form.
     */
    public function getCartData(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);

        return response()->json(
            $this->cartService->getCart(auth()->id(), $customerId, $branchId)
        );
    }

    /**
     * Call It A Day — batch flag invoices as removed from daily collection list (Gap G-10).
     * AJAX endpoint. Sets call_a_day = true on selected invoices for the user's branch.
     * No GL, ledger, or stock impact — purely a UI/operational convenience.
     */
    public function callItADay(Request $request)
    {
        // Phase 6: defense-in-depth policy check (mirrors route role middleware).
        // callItADay operates on a batch of invoice IDs — authorize against a
        // stub model (the policy checks role only, not model attributes).
        $this->authorize('callItADay', new SalesInvoice());

        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'integer',
        ]);

        $invoiceIds = $validated['invoice_ids'];
        $branchId = (int) (session('branch_id') ?? auth()->user()?->getBranchId() ?? 0);
        $userId = (int) auth()->id();

        try {
            $result = $this->invoiceService->callItADay($invoiceIds, $branchId, $userId);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'updated_count' => 0,
            ], 400);
        }
    }

    /**
     * AJAX: Check credit limit before finalizing.
     */
    public function checkCreditLimit(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount' => 'required|numeric|min:0',
        ]);

        $customerId = (int) $request->input('customer_id');
        $amount = (float) $request->input('amount');

        $customer = DB::table('customers')->where('id', $customerId)->first();
        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
            ->value('balance');

        $newBalance = $currentBalance + $amount;
        $exceeds = $creditLimit > 0 && $newBalance > $creditLimit + 0.01;

        return response()->json([
            'exceeds' => $exceeds,
            'current_balance' => round($currentBalance, 2),
            'credit_limit' => round($creditLimit, 2),
            'new_balance' => round($newBalance, 2),
            'invoice_amount' => round($amount, 2),
        ]);
    }

    /**
     * R19: Inline receive-payment modal — returns the HTML body for
     * the #receivePaymentModal on the Today's Sales / sales-invoices
     * index page. Loaded via AJAX when the user clicks the "Receive"
     * button on a row with due_amount > 0.
     *
     * Mirrors Legacy sales/receive_modal/{id} endpoint which is
     * fetched by sales-today-index.js and injected into #receiveModalContent.
     *
     * Returns a Blade partial with the invoice summary, payment form
     * (mode/amount/bank/reference/notes), and a list of payments
     * already recorded against this invoice. The form posts to the
     * existing admin.customer-payments.store route — no new write
     * endpoint is created (R2 idempotency-token flow is reused).
     */
    public function receiveModal(int $id)
    {
        $invoice = SalesInvoice::with([
            'customer', 'branch',
            'allocations' => function ($q) {
                $q->with('payment.branch', 'payment.bank')
                    ->orderByDesc('id');
            },
        ])->findOrFail($id);

        // Phase 6: defense-in-depth policy check (mirrors route role middleware).
        $this->authorize('receivePayment', $invoice);

        // Outstanding payments already allocated to this invoice
        // (uses the invoice_payment_allocations table joined via
        // SalesInvoice::allocations() → InvoicePaymentAllocation::payment()).
        // received_by_name is resolved from the users table via the
        // CustomerPayment::created_by FK (no formal model relationship
        // — we look it up here so the modal can display who collected it).
        $userIds = $invoice->allocations
            ->map(fn ($a) => $a->payment?->created_by)
            ->filter()
            ->unique()
            ->all();
        $userNames = $userIds
            ? \App\Models\User::whereIn('id', $userIds)->pluck('username', 'id')
            : collect();

        $payments = $invoice->allocations->map(function ($alloc) use ($userNames) {
            $p = $alloc->payment;
            return [
                'payment_id'        => $p?->id ?? 0,
                'payment_code'      => $p?->payment_code ?? '—',
                'payment_date'      => $p?->payment_date ?? null,
                'allocated_amount'  => (float) $alloc->allocated_amount,
                'payment_mode'      => $p?->payment_mode ?? 'cash',
                'bank_name'         => $p?->bank?->bank_name ?? '',
                'received_by_name'  => $userNames[$p?->created_by ?? 0] ?? '—',
            ];
        });

        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Default branch = invoice's branch (or session branch as fallback)
        $defaultBranchId = (int) ($invoice->branch_id ?? session('branch_id', 0));

        $grandTotal = (float) $invoice->total_amount;
        $amountPaid = (float) $invoice->paid_amount;
        $balance   = max(0.0, round($grandTotal - $amountPaid, 2));

        return view('admin.sales-invoices._receive_modal_body', [
            'invoice'        => $invoice,
            'payments'       => $payments,
            'banks'          => $banks,
            'branches'       => $branches,
            'defaultBranchId' => $defaultBranchId,
            'grandTotal'     => $grandTotal,
            'amountPaid'     => $amountPaid,
            'balance'        => $balance,
        ]);
    }

    /**
     * R21: Server-side DataTables endpoint for the sales-invoices
     * index page. Returns DataTables SSP JSON (draw / recordsTotal /
     * recordsFiltered / data) so the index table can paginate + sort
     * + search on the server instead of loading 25 rows at a time
     * through Laravel's paginator + a client-side DataTable on top.
     *
     * Mirrors Legacy `sales/datatable_invoices` (called from
     * sales-today-index.js::initDataTable). Supports:
     *
     *   - Standard DataTables paging params: draw / start / length
     *   - Standard DataTables ordering params: order[i][column] /
     *     order[i][dir]; columns[j][data] is used to map the column
     *     index to a real DB column.
     *   - Standard DataTables search[value] (when "smart" search is on)
     *   - R21 filter params (from the index page's filter form):
     *       from_date, to_date, customer_id, branch_id, status,
     *       search, smart_sort
     *   - R22 status-chip params (override the `status` filter):
     *       status_chip=awaiting_payment | draft | confirmed |
     *       cancelled | reversed | all
     *
     * Smart sort (R21): when smart_sort=1 AND the user has not
     * explicitly clicked a column header to sort, the rows are
     * ordered "unpaid first, then oldest invoice date" — mirroring
     * Legacy `sales-today-index.js` `#filterSmartSort` checkbox.
     * The "unpaid first" rule is implemented as a CASE expression
     * that puts invoices with `due_amount > 0.01 AND status NOT IN
     * ('cancelled','reversed')` before everything else.
     */
    public function datatable(Request $request)
    {
        $filters = $this->buildInvoiceFilterQuery($request);

        // recordsTotal = unfiltered count of all invoices (DataTables
        // SSP contract). Computed from a fresh query — the filter
        // query is reused below for recordsFiltered + the actual page.
        $recordsTotal = SalesInvoice::count();

        // recordsFiltered = filtered count (before pagination).
        $recordsFiltered = (clone $filters)->count();

        // Apply ordering.
        $order = $request->input('order', []);
        $columns = $request->input('columns', []);
        $smartSort = $request->boolean('smart_sort', true);

        if (is_array($order) && count($order) > 0) {
            // DataTables column ordering — user clicked a header.
            $colMap = [
                'invoice_code' => 'invoice_code',
                'invoice_date' => 'invoice_date',
                'customer_name' => 'customer_id',  // proxy via FK; real name join would need a subquery
                'branch_name'   => 'branch_id',
                'items_count'   => 'id',           // not directly orderable; fall back to id
                'total_amount'  => 'total_amount',
                'paid_amount'   => 'paid_amount',
                'due_amount'    => 'due_amount',
                'status'        => 'status',
            ];
            foreach ($order as $o) {
                $colIdx = (int) ($o['column'] ?? 0);
                $colData = $columns[$colIdx]['data'] ?? null;
                $dir = strtolower($o['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                $dbCol = $colMap[$colData] ?? null;
                if ($dbCol) {
                    $filters->orderBy($dbCol, $dir);
                }
            }
            // Always add a stable tiebreaker.
            $filters->orderBy('id', 'desc');
        } elseif ($smartSort) {
            // R21 smart sort: unpaid first (asc=0), then oldest date.
            $filters->orderByRaw(
                "(CASE WHEN (total_amount - paid_amount) > 0.01 AND status NOT IN ('cancelled','reversed') THEN 0 ELSE 1 END) ASC"
            )->orderBy('invoice_date', 'asc')->orderBy('id', 'asc');
        } else {
            // Default: newest first.
            $filters->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');
        }

        $start  = max(0, (int) $request->input('start', 0));
        $length = max(1, min(500, (int) $request->input('length', 25)));

        $rows = $filters->with(['customer', 'branch', 'items'])
            ->skip($start)->take($length)->get();

        $data = $rows->map(function ($inv) {
            // Compute due on-the-fly from total - paid instead of relying
            // on the due_amount generated column, which may be 0 for
            // invoices created before the GENERATED-column migration was
            // applied (or if the INSERT didn't trigger the generation).
            // This guarantees show_receive is correct for any invoice with
            // an outstanding balance.
            $due = round((float) $inv->total_amount - (float) $inv->paid_amount, 2);
            $isCancelled = $inv->status === 'cancelled';
            $isReversed = (bool) $inv->is_reversed;
            $isDraft = $inv->status === 'draft';
            $calledItADay = (bool) $inv->call_a_day;
            return [
                'id'             => $inv->id,
                'invoice_code'   => $inv->invoice_code,
                'invoice_date'   => optional($inv->invoice_date)->format('Y-m-d'),
                'customer_name'  => $inv->customer?->customer_name ?? '',
                'customer_code'  => $inv->customer?->customer_code ?? '',
                'branch_name'    => $inv->branch?->branch_name ?? '',
                'branch_code'    => $inv->branch?->branch_code ?? '',
                'items_count'    => $inv->items->count(),
                'total_amount'   => (float) $inv->total_amount,
                'paid_amount'    => (float) $inv->paid_amount,
                'due_amount'     => $due,
                'status'         => $inv->status,
                'is_soft_hold'   => (bool) $inv->is_soft_hold,
                'is_reversed'    => $isReversed,
                'call_a_day'     => $calledItADay,
                // Phase 1 (UI/UX): action-visibility flags + per-row URLs
                // so the DataTables actions column can render the full
                // Legacy action set (View / Edit / Cancel / Receive /
                // Call-it-a-day / Print) without hardcoding routes in JS.
                'show_receive'   => $due > 0.01 && !$isCancelled && !$isReversed,
                'show_edit'      => $isDraft && !$isReversed,
                'show_cancel'    => $isDraft && !$isReversed,
                'show_call_a_day'=> $due <= 0.01 && !$isCancelled && !$isReversed && !$calledItADay,
                'show_print'     => !$isDraft && !$isReversed,
                'show_url'       => route('admin.sales-invoices.show', $inv),
                'edit_url'       => $isDraft && !$isReversed ? route('admin.sales-invoices.edit', $inv) : null,
                'print_invoice_url' => !$isDraft && !$isReversed ? route('admin.sales-invoices.print-invoice', $inv) : null,
            ];
        });

        return response()->json([
            'draw'            => (int) $request->input('draw', 0),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    /**
     * R22: Live counts for the status-chip row on the sales-invoices
     * index page. Returns JSON with one count per chip, computed
     * against the current filter set (date range / customer / branch /
     * search) — but NOT against the active chip itself, so the user
     * can see how many invoices are in each bucket without losing
     * their filter context. Mirrors Legacy
     * `sales/today_filter_summary` (called from
     * sales-today-index.js::refreshSummary).
     *
     * Chip buckets:
     *   - all               = total in current filter scope
     *   - awaiting_payment  = due_amount > 0.01 AND status NOT IN cancelled,reversed
     *   - draft             = status = draft
     *   - confirmed         = status = confirmed
     *   - cancelled         = status = cancelled
     *   - reversed          = is_reversed = true
     *   - total_value       = SUM(total_amount) EXCLUDING cancelled (display only)
     */
    public function summary(Request $request)
    {
        $base = $this->buildInvoiceFilterQuery($request, excludeStatusChip: true);

        // Clone for each bucket so we don't mutate the base query.
        $countAll = (clone $base)->count();
        $countAwaiting = (clone $base)
            ->whereRaw('(total_amount - paid_amount) > 0.01')
            ->whereNotIn('status', ['cancelled'])
            ->where('is_reversed', false)
            ->count();
        $countDraft = (clone $base)->where('status', 'draft')->count();
        $countConfirmed = (clone $base)->where('status', 'confirmed')->count();
        $countCancelled = (clone $base)->where('status', 'cancelled')->count();
        $countReversed = (clone $base)->where('is_reversed', true)->count();
        $totalValue = (float) (clone $base)
            ->whereNotIn('status', ['cancelled'])
            ->sum('total_amount');

        // BUG-52: workflow buckets for the new chips.
        $today = now()->format('Y-m-d');
        // F-2: $base already filters call_a_day=false (unless ?include_called=1
        // for admin/manager), so $countToday inherits that filter — no need
        // to repeat it here. This keeps the Today chip count consistent with
        // the filtered DataTable rows across all audit modes.
        $countToday = (clone $base)->where('invoice_date', $today)->count();
        $countPendingGodown = (clone $base)
            ->where('status', 'confirmed')
            ->where('is_godown_prepared', false)
            ->where('is_reversed', false)
            ->count();
        $countPendingChallan = (clone $base)
            ->where('is_godown_prepared', true)
            ->where('is_challan_issued', false)
            ->where('is_reversed', false)
            ->count();

        return response()->json([
            'status'            => 'success',
            'total'             => $countAll,
            'today'             => $countToday,
            'awaiting_payment'  => $countAwaiting,
            'draft'             => $countDraft,
            'confirmed'         => $countConfirmed,
            'cancelled'         => $countCancelled,
            'reversed'          => $countReversed,
            'pending_godown'    => $countPendingGodown,
            'pending_challan'   => $countPendingChallan,
            'total_value'       => $totalValue,
        ]);
    }

    /**
     * Shared filter-query builder used by both datatable() and
     * summary(). Mirrors the filter logic in index() so chip counts
     * and table rows stay in sync.
     *
     * When $excludeStatusChip is true (summary mode), the status
     * chip / status filter is NOT applied — the summary must return
     * counts for ALL buckets regardless of which chip is currently
     * active, otherwise clicking "Draft" would zero-out every other
     * chip's count.
     */
    private function buildInvoiceFilterQuery(Request $request, bool $excludeStatusChip = false)
    {
        $q = SalesInvoice::query();

        // F-2: Hide invoices flagged call_a_day from the default view across
        // ALL scopes/chips (not just scope=today). Admin/manager can bypass
        // with ?include_called=1 for auditing called-it-a-day invoices.
        // Backed by partial index idx_si_call_a_day_active WHERE call_a_day = false.
        if (! $this->shouldIncludeCalledInvoices($request)) {
            $q->where('call_a_day', false);
        }

        if ($d = $request->input('from_date')) {
            $q->where('invoice_date', '>=', $d);
        }
        if ($d = $request->input('to_date')) {
            $q->where('invoice_date', '<=', $d);
        }
        if ($cid = $request->input('customer_id')) {
            $q->where('customer_id', $cid);
        }
        if ($bid = $request->input('branch_id')) {
            $q->where('branch_id', $bid);
        }

        // Smart search: invoice_code, customer name/code/mobile,
        // branch name, salesman name, creator username, product name/code.
        // Mirrors Legacy sales-today-index.js search hint
        // "invoice, customer, mobile, branch, salesman, product".
        //
        // F-6: extended to also match employees.name (salesman via
        // whereHas('salesman')), users.username (creator via whereHas('creator')),
        // and products.product_name/product_code (via whereHas('items.product')).
        // Each whereHas emits a EXISTS subquery; ILIKE '%term%' is used for
        // consistency with the existing customer/branch matching (the GIN
        // tsvector indexes from migration 2025_01_20_000005 accelerate the
        // standalone product/customer search endpoints, not this join-based
        // invoice filter — adding them here would require a raw tsquery
        // clause per relationship, which is a larger refactor and not what
        // the plan asks for).
        if ($s = $request->input('search')) {
            if (is_array($s)) { $s = $s['value'] ?? ''; }
            $s = trim((string) $s);
            if ($s !== '') {
                $q->where(function ($qq) use ($s) {
                    $qq->where('invoice_code', 'ILIKE', "%{$s}%")
                       ->orWhereHas('customer', function ($qc) use ($s) {
                           $qc->where('customer_name', 'ILIKE', "%{$s}%")
                              ->orWhere('customer_code', 'ILIKE', "%{$s}%")
                              ->orWhere('mobile', 'ILIKE', "%{$s}%");
                       })
                       ->orWhereHas('branch', function ($qb) use ($s) {
                           $qb->where('branch_name', 'ILIKE', "%{$s}%")
                              ->orWhere('branch_code', 'ILIKE', "%{$s}%");
                       })
                       // F-6: salesman name (employees.name via salesman_id).
                       // Backed by idx_si_salesman on sales_invoices.salesman_id.
                       ->orWhereHas('salesman', function ($qsm) use ($s) {
                           $qsm->where('name', 'ILIKE', "%{$s}%");
                       })
                       // F-6: creator username (users.username via created_by).
                       ->orWhereHas('creator', function ($qc) use ($s) {
                           $qc->where('username', 'ILIKE', "%{$s}%");
                       })
                       // F-6: product name/code on any line item
                       // (sales_invoice_items → products). This is the
                       // broadest clause — it matches the invoice if ANY
                       // item's product matches. EXISTS subquery keeps it
                       // efficient (no row duplication).
                       ->orWhereHas('items.product', function ($qp) use ($s) {
                           $qp->where('product_name', 'ILIKE', "%{$s}%")
                              ->orWhere('product_code', 'ILIKE', "%{$s}%");
                       });
                });
            }
        }

        if (! $excludeStatusChip) {
            // R22 status chip overrides the simple `status` param when present.
            $chip = $request->input('status_chip');
            $plainStatus = $request->input('status');
            // BUG-52: scope chip (today / pending_godown / pending_challan)
            // is a sibling of the status chip — handled here so the
            // DataTables AJAX endpoint honors it just like index() does.
            $scope = $request->input('scope');
            if ($scope === 'today') {
                $today = now()->format('Y-m-d');
                if (! $request->input('from_date')) { $q->where('invoice_date', '>=', $today); }
                if (! $request->input('to_date'))   { $q->where('invoice_date', '<=', $today); }
                // F-2: call_a_day filter is applied at the base of
                // buildInvoiceFilterQuery() — no need to repeat it here.
            } elseif ($scope === 'pending_godown') {
                $q->where('status', 'confirmed')
                  ->where('is_godown_prepared', false)
                  ->where('is_reversed', false);
            } elseif ($scope === 'pending_challan') {
                $q->where('is_godown_prepared', true)
                  ->where('is_challan_issued', false)
                  ->where('is_reversed', false);
            } elseif ($chip && $chip !== 'all') {
                switch ($chip) {
                    case 'awaiting_payment':
                        // Use (total_amount - paid_amount) instead of the
                        // due_amount generated column — same reason as
                        // datatable(): the generated column may be 0 for
                        // some rows, which would hide outstanding invoices.
                        $q->whereRaw('(total_amount - paid_amount) > 0.01')
                          ->whereNotIn('status', ['cancelled'])
                          ->where('is_reversed', false);
                        break;
                    case 'reversed':
                        $q->where('is_reversed', true);
                        break;
                    case 'draft':
                    case 'confirmed':
                    case 'cancelled':
                        $q->where('status', $chip);
                        $q->where('is_reversed', false);
                        break;
                }
            } elseif ($plainStatus) {
                $q->where('status', $plainStatus);
            }
        }

        return $q;
    }

    /**
     * F-2: Determine whether the current request should include
     * call_a_day=true invoices in the result set.
     *
     * Default behavior (no flag / non-admin / non-manager): invoices flagged
     * call_a_day are hidden from the list, datatable, summary, and chip counts.
     *
     * Audit escape hatch: admin + manager can pass ?include_called=1 to see
     * called-it-a-day invoices for auditing. This is a Laravel improvement
     * over Legacy (Legacy has no way to recover called invoices once removed).
     *
     * @param Request $request
     * @return bool True if called invoices should be included in the result set.
     */
    private function shouldIncludeCalledInvoices(Request $request): bool
    {
        if (! $request->boolean('include_called')) {
            return false;
        }
        $user = $request->user();
        return $user !== null && $user->hasRole('admin', 'manager');
    }
}
