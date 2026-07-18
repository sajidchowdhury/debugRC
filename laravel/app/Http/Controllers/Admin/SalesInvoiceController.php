<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
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
        $query = SalesInvoice::with(['customer', 'branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('invoice_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('invoice_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        $invoices = $query->paginate(25);

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => SalesInvoice::count(),
            'draft' => SalesInvoice::where('status', 'draft')->count(),
            'confirmed' => SalesInvoice::where('status', 'confirmed')->count(),
            'cancelled' => SalesInvoice::where('status', 'cancelled')->count(),
            'total_value' => SalesInvoice::whereNotIn('status', ['cancelled'])->sum('total_amount'),
        ];

        return view('admin.sales-invoices.index', [
            'title' => 'Sales Invoices',
            'invoices' => $invoices,
            'customers' => $customers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search']),
        ]);
    }

    /**
     * Finalize a cart into a draft invoice (AJAX endpoint).
     */
    public function finalize(Request $request)
    {
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
            'override_reason' => 'nullable|string|max:500',
        ]);

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
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Invoice {$invoice->invoice_code} created (draft). GL posted.",
                'invoice_id' => $invoice->id,
                'invoice_code' => $invoice->invoice_code,
                'redirect' => route('admin.sales-invoices.show', $invoice),
            ]);
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
        $invoice = SalesInvoice::with(['items.product', 'customer', 'branch'])
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

        return view('admin.sales-invoices.edit', [
            'title' => 'Edit Invoice ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'products' => $products,
        ]);
    }

    /**
     * Update a draft invoice (P1-1).
     * Reverses old GL + customer_ledger, re-posts with new items/totals.
     */
    public function update(Request $request, int $id)
    {
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
            'override_reason' => 'nullable|string|max:500',
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
                'sale_created', 'sale_updated', 'sale_cancelled',
                'credit_limit_override',
                'payment_received', 'payment_reversed',
                'return_created', 'return_confirmed', 'return_reversed',
                'godown_prepared', 'challan_issued', 'challan_reversed',
                'stale_drafts_cancelled',
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
        $actionLabels = [
            'sale_created' => ['label' => 'Invoice Created', 'icon' => 'fa-file-circle-plus', 'color' => 'success'],
            'sale_updated' => ['label' => 'Invoice Updated', 'icon' => 'fa-pen-to-square', 'color' => 'primary'],
            'sale_cancelled' => ['label' => 'Invoice Cancelled', 'icon' => 'fa-ban', 'color' => 'danger'],
            'credit_limit_override' => ['label' => 'Credit Override', 'icon' => 'fa-shield-halved', 'color' => 'warning'],
            'payment_received' => ['label' => 'Payment Received', 'icon' => 'fa-money-bill-wave', 'color' => 'success'],
            'payment_reversed' => ['label' => 'Payment Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'return_created' => ['label' => 'Return Created', 'icon' => 'fa-arrow-rotate-left', 'color' => 'info'],
            'return_confirmed' => ['label' => 'Return Confirmed', 'icon' => 'fa-check', 'color' => 'primary'],
            'return_reversed' => ['label' => 'Return Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'godown_prepared' => ['label' => 'Godown Prepared', 'icon' => 'fa-warehouse', 'color' => 'primary'],
            'challan_issued' => ['label' => 'Challan Issued', 'icon' => 'fa-truck', 'color' => 'success'],
            'challan_reversed' => ['label' => 'Challan Reversed', 'icon' => 'fa-rotate-left', 'color' => 'danger'],
            'stale_drafts_cancelled' => ['label' => 'Stale Drafts Cleaned', 'icon' => 'fa-broom', 'color' => 'secondary'],
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

    public function show(int $id)
    {
        $invoice = SalesInvoice::with([
            'items.product', 'dispatches.product', 'customer', 'branch', 'salesman',
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
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $invoice = $this->invoiceService->cancelInvoice($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('success', "Invoice {$invoice->invoice_code} cancelled.");
        } catch (\Throwable $e) {
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
}
