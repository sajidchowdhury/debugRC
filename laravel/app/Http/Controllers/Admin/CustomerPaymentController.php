<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Payment Controller — Phase 8.4 + Transaction Types (P2-5).
 *
 * Two-phase: create draft → confirm (GL + ledger + allocation + intercompany) → cancel.
 *
 * Transaction types supported:
 *   - receive:   Customer paying us → Dr Bank/Cash, Cr AR
 *   - discount:  Discount allowed → Dr Sales Discount, Cr AR
 *   - write_off: Bad debt write-off → Dr Bad Debt Expense, Cr AR
 *   - payment:   Refund to customer → Dr AR, Cr Bank/Cash
 *
 * R2: store() now requires an idempotency_token (UUID v4) and mirrors
 * the finalize pattern — duplicate submissions within 10 minutes return
 * the cached redirect instead of creating a second payment. See
 * docs/REMEDIATION_LOG.md §R2.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(
        private CustomerPaymentService $paymentService
    ) {}

    public function index(Request $request)
    {
        $query = CustomerPayment::with(['customer', 'branch', 'bank', 'collectedBy', 'allocations.invoice'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('payment_mode') && $request->input('payment_mode') !== 'all', fn($q, $m) => $q->where('payment_mode', $m))
            ->when($request->input('transaction_type') && $request->input('transaction_type') !== 'all', fn($q, $t) => $q->where('transaction_type', $t))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('payment_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');

        $payments = $query->paginate(25);

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        $stats = [
            'total' => CustomerPayment::count(),
            'total_amount' => CustomerPayment::where('is_reversed', false)->sum('amount'),
            'cash' => CustomerPayment::where('is_reversed', false)->where('payment_mode', 'cash')->sum('amount'),
            'bank' => CustomerPayment::where('is_reversed', false)->where('payment_mode', 'bank')->sum('amount'),
            'reversed' => CustomerPayment::where('is_reversed', true)->count(),
            'discounts' => CustomerPayment::where('is_reversed', false)->where('transaction_type', 'discount')->sum('amount'),
            'write_offs' => CustomerPayment::where('is_reversed', false)->where('transaction_type', 'write_off')->sum('amount'),
            'refunds' => CustomerPayment::where('is_reversed', false)->where('transaction_type', 'payment')->sum('amount'),
        ];

        return view('admin.customer-payments.index', [
            'title' => 'Customer Payments',
            'payments' => $payments,
            'customers' => $customers,
            'branches' => $branches,
            'banks' => $banks,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'payment_mode', 'transaction_type', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();
        $employees = \App\Models\Employee::active()->orderBy('name')->get();

        // Branch restriction: non-admin users can only create payments for their own branch.
        // This prevents a salesman from branch A inserting data for branch B.
        $user = auth()->user();
        $userBranchId = (int) (session('branch_id') ?? ($user ? $user->getBranchId() : 0));
        $isAdmin = $user && $user->isAdmin();

        if ($isAdmin) {
            $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        } else {
            // Non-admin: only their own branch
            $branches = \App\Models\Branch::active()
                ->where('id', $userBranchId)
                ->orderBy('branch_name')
                ->get();
        }

        // Pre-compute receivable balance for each customer (for select dropdown)
        $customerReceivables = [];
        foreach ($customers as $c) {
            $customerReceivables[$c->id] = $this->getCustomerReceivable($c->id);
        }

        // If customer_id is passed, load outstanding invoices.
        $outstandingInvoices = collect();
        $customerId = $request->input('customer_id');
        if ($customerId) {
            $outstandingInvoices = \App\Models\SalesInvoice::where('customer_id', $customerId)
                ->where('is_reversed', false)
                ->whereRaw('due_amount > 0.01')
                ->orderBy('invoice_date')
                ->get();
        }

        // Transaction type from query string (default: receive).
        $transactionType = $request->input('transaction_type', 'receive');

        return view('admin.customer-payments.create', [
            'title' => $this->getTitleForType($transactionType),
            'customers' => $customers,
            'branches' => $branches,
            'banks' => $banks,
            'employees' => $employees,
            'selectedCustomerId' => $customerId ? (int) $customerId : null,
            'outstandingInvoices' => $outstandingInvoices,
            'transactionType' => $transactionType,
            'isAdmin' => $isAdmin,
            'userBranchId' => $userBranchId,
            'customerReceivables' => $customerReceivables,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'collected_by' => 'nullable|integer|exists:employees,id',
            'payment_mode' => 'required|in:cash,bank,mobile_banking,cheque,adjustment',
            'transaction_type' => 'required|in:receive,discount,write_off,payment',
            'amount' => 'required|numeric|min:0.01',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_date' => 'required|date',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            // Multi-invoice allocation arrays
            'alloc_invoice_id' => 'nullable|array',
            'alloc_invoice_id.*' => 'integer|exists:sales_invoices,id',
            'alloc_amount' => 'nullable|array',
            'alloc_amount.*' => 'numeric|min:0',
            // R2: Idempotency token (UUID v4) — mirrors the finalize pattern.
            'idempotency_token' => 'required|string|uuid',
        ]);

        // R2: Idempotency check — if this token was already processed,
        // redirect to the originally-created payment with the original
        // success message and an idempotent_replay warning. Prevents
        // duplicate payments on double-submit / refresh-after-submit.
        $cacheKey = 'payment:' . $validated['idempotency_token'];
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($cached !== null) {
            // R2 Replay: redirect to the same payment show page with the
            // original success message + an additional warning flash.
            // Phase 1 (UI/UX): AJAX branch — the inline receive modal
            // posts via fetch() and expects JSON, not a redirect.
            if ($request->expectsJson() || $request->ajax()) {
                $replayInvoiceId = (int) ($validated['alloc_invoice_id'][0] ?? 0);
                return response()->json([
                    'status'             => 'success',
                    'idempotent_replay'  => true,
                    'payment_id'         => $cached['payment_id'],
                    'payment_code'       => $cached['payment_code'] ?? '',
                    'invoice_id'         => $replayInvoiceId,
                    'message'            => $cached['success_message'] ?? 'Payment already recorded (duplicate submission).',
                    'print_receipt_url'  => route('admin.customer-payments.print-receipt', ['id' => $cached['payment_id']]),
                ]);
            }
            return redirect()
                ->route('admin.customer-payments.show', ['id' => $cached['payment_id']])
                ->with('success', $cached['success_message'])
                ->with('warning', 'Duplicate submission detected — returning the original result. No new payment was created.');
        }

        try {
            $transactionType = $validated['transaction_type'];

            $payment = $this->paymentService->createPayment([
                'customer_id' => $validated['customer_id'],
                'branch_id' => $validated['branch_id'],
                'bank_id' => $validated['bank_id'] ?? null,
                'collected_by' => $validated['collected_by'] ?? null,
                'payment_mode' => $validated['payment_mode'],
                'transaction_type' => $transactionType,
                'amount' => $validated['amount'],
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'payment_date' => $validated['payment_date'],
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? '',
                'created_by' => auth()->id(),
            ]);

            // Build allocations array from the parallel invoice_id + amount arrays.
            $allocations = [];
            $invoiceIds = $validated['alloc_invoice_id'] ?? [];
            $amounts = $validated['alloc_amount'] ?? [];
            foreach ($invoiceIds as $idx => $invoiceId) {
                $allocatedAmount = (float) ($amounts[$idx] ?? 0);
                if ($invoiceId > 0 && $allocatedAmount > 0.001) {
                    $allocations[] = [
                        'invoice_id' => (int) $invoiceId,
                        'allocated_amount' => $allocatedAmount,
                    ];
                }
            }

            // Auto-confirm (payments are typically immediate).
            $payment = $this->paymentService->confirmPayment($payment->id, auth()->id(), $allocations);

            $allocCount = count($allocations);
            $allocMsg = $allocCount > 0
                ? " Allocated across {$allocCount} invoice(s)."
                : '';

            $typeMessages = [
                'receive' => "Payment {$payment->payment_code} recorded. GL posted (Dr Bank/Cash / Cr AR) + customer ledger updated.",
                'discount' => "Discount {$payment->payment_code} recorded. GL posted (Dr Sales Discount / Cr AR) + customer ledger updated.",
                'write_off' => "Write-off {$payment->payment_code} recorded. GL posted (Dr Bad Debt Expense / Cr AR) + customer ledger updated.",
                'payment' => "Refund {$payment->payment_code} recorded. GL posted (Dr AR / Cr Bank/Cash) + customer ledger updated.",
            ];

            $successMessage = ($typeMessages[$transactionType] ?? $typeMessages['receive']) . $allocMsg;

            // R2: Cache the redirect target + success message for 10 minutes
            // (idempotency window — same TTL as the finalize pattern).
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'payment_id'      => $payment->id,
                'payment_code'    => $payment->payment_code,
                'success_message' => $successMessage,
            ], 600);

            // Phase 1 (UI/UX): AJAX branch — the inline receive modal
            // posts via fetch() and expects JSON so the user stays on
            // the Today Invoice list (no redirect). We return enough
            // context for the client to offer "Print receipt" + the
            // "Call it a day?" follow-up when the invoice is fully paid.
            if ($request->expectsJson() || $request->ajax()) {
                $invoiceId = (int) ($validated['alloc_invoice_id'][0] ?? 0);
                $isFullyPaid = false;
                $balanceAfter = null;
                if ($invoiceId > 0) {
                    $invoiceFresh = \App\Models\SalesInvoice::find($invoiceId);
                    if ($invoiceFresh) {
                        $balanceAfter = (float) $invoiceFresh->due_amount;
                        $isFullyPaid = $balanceAfter <= 0.01
                            && $invoiceFresh->status !== 'cancelled'
                            && !$invoiceFresh->is_reversed;
                    }
                }
                return response()->json([
                    'status'           => 'success',
                    'payment_id'       => $payment->id,
                    'payment_code'     => $payment->payment_code,
                    'invoice_id'       => $invoiceId,
                    'is_fully_paid'    => $isFullyPaid,
                    'balance_after'    => $balanceAfter,
                    'message'          => $successMessage,
                    'print_receipt_url' => route('admin.customer-payments.print-receipt', ['id' => $payment->id]),
                ]);
            }

            return redirect()->route('admin.customer-payments.show', ['id' => $payment->id])
                ->with('success', $successMessage);
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * P1-6: Print payment receipt.
     */
    public function printReceipt(int $id)
    {
        $payment = CustomerPayment::with([
            'customer', 'branch', 'bank', 'allocations.invoice',
        ])->findOrFail($id);

        return view('admin.customer-payments.print_receipt', [
            'title' => 'Receipt ' . $payment->payment_code,
            'payment' => $payment,
        ]);
    }

    public function show(int $id)
    {
        $payment = CustomerPayment::with([
            'customer', 'branch', 'bank', 'collectedBy',
            'journalEntry.lines.ledger',
            'intercompanyJournalEntry.lines.ledger',
            'allocations.invoice',
        ])->findOrFail($id);

        $customerLedgerEntries = DB::table('customer_ledger')
            ->where('reference_type', 'customer_payment')
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get();

        // Compute customer's current AR balance (what they owe us)
        $customerDue = 0;
        if ($payment->customer) {
            $customerDue = (float) \App\Models\CustomerLedger::getBalance($payment->customer_id);
        }

        return view('admin.customer-payments.show', [
            'title' => 'Payment ' . $payment->payment_code,
            'payment' => $payment,
            'customerLedgerEntries' => $customerLedgerEntries,
            'customerDue' => $customerDue,
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        // Route middleware already restricts to accountant/manager/admin.
        // The $this->authorize('delete') was causing 403 for users who
        // should have access — removed since route middleware is sufficient.
        $payment = CustomerPayment::findOrFail($id);

        // Prevent double-reversal.
        if ($payment->is_reversed) {
            return back()->with('error', 'This payment is already reversed.');
        }

        $request->validate([
            // R27 (2026-07-22): min:5 parity with Legacy
            // SalesPaymentOperationsTrait::reverseCustomerPayment() —
            // runtime check `if (strlen($reason) < 5) { return error; }`.
            'cancel_reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $payment = $this->paymentService->cancelPayment($id, auth()->id(), $request->input('cancel_reason'));

            // Phase 3 (UI/UX): inline reverse from the receive modal —
            // return JSON so the AJAX flow can re-fetch the modal body +
            // announce the reversal without a page redirect. Mirrors the
            // AJAX branch in CustomerPaymentController::store.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Payment {$payment->payment_code} cancelled. GL + ledger reversed.",
                    'payment_id'   => $payment->id,
                    'payment_code' => $payment->payment_code,
                    'is_reversed'  => true,
                ]);
            }

            return redirect()->route('admin.customer-payments.show', ['id' => $payment->id])
                ->with('success', "Payment {$payment->payment_code} cancelled. GL + ledger reversed.");
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
     * AJAX: Get outstanding invoices for a customer.
     */
    public function getOutstandingInvoices(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $invoices = \App\Models\SalesInvoice::where('customer_id', (int) $request->input('customer_id'))
            ->where('is_reversed', false)
            ->whereRaw('due_amount > 0.01')
            ->orderBy('invoice_date')
            ->select('id', 'invoice_code', 'invoice_date', 'total_amount', 'paid_amount', 'due_amount')
            ->get();

        return response()->json($invoices);
    }

    /**
     * Phase 3B: Print a payment slip (voucher).
     */
    public function slip(int $id)
    {
        $payment = CustomerPayment::with([
            'customer', 'branch', 'bank', 'collectedBy',
            'allocations.invoice',
        ])->findOrFail($id);

        return view('admin.customer-payments.slip', [
            'title'   => 'Customer Payment Slip',
            'payment' => $payment,
        ]);
    }

    /**
     * Phase 3B: Show audit logs for customer payments.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'customer_payment_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.customer-payments.audit', [
            'title' => 'Customer Payment Audit Logs',
            'logs'  => $logs,
        ]);
    }

    /**
     * Helper: page title by transaction type.
     */
    private function getTitleForType(string $type): string
    {
        return [
            'receive' => 'Receive Payment',
            'discount' => 'Allow Discount',
            'write_off' => 'Write Off Bad Debt',
            'payment' => 'Issue Refund',
        ][$type] ?? 'Record Payment';
    }

    /**
     * AJAX: Get customer's current receivable balance.
     */
    public function getCustomerDue(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $due = $this->getCustomerReceivable((int) $request->input('customer_id'));

        return response()->json([
            'status'        => 'success',
            'due_balance'   => $due,
            'due_formatted' => number_format($due, 2),
        ]);
    }

    /**
     * Get customer's outstanding receivable balance (what they owe us).
     *
     * Uses the customer_ledger SUM(debit-credit) for non-reversed entries,
     * which is the same computation as CustomerLedger::getBalance().
     * Falls back to outstanding invoice due_amount sum if no ledger rows exist.
     */
    private function getCustomerReceivable(int $customerId): float
    {
        // Primary: customer_ledger balance (debit - credit for non-reversed)
        $ledgerBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');

        // If ledger has entries, return that balance
        if ($ledgerBalance > 0.001 || $ledgerBalance < -0.001) {
            return abs($ledgerBalance);
        }

        // Check if any ledger rows exist at all (zero balance is valid)
        $hasLedgerRows = DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->exists();

        if ($hasLedgerRows) {
            return 0.0; // Truly zero balance
        }

        // Fallback: sum of outstanding invoice due amounts
        return (float) \App\Models\SalesInvoice::where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->sum('due_amount');
    }
}
