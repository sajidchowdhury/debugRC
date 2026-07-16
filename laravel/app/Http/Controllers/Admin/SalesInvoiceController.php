<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesCartService;
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
        private SalesCartService $cartService
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
