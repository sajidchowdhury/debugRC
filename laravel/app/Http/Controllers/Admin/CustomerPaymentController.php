<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerPayment;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Payment Controller — Phase 8.4.
 *
 * Two-phase: create draft → confirm (GL + ledger + allocation + intercompany) → cancel.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(
        private CustomerPaymentService $paymentService
    ) {}

    public function index(Request $request)
    {
        $query = CustomerPayment::with(['customer', 'branch', 'bank', 'allocations.invoice'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('payment_mode'), fn($q, $m) => $q->where('payment_mode', $m))
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
        ];

        return view('admin.customer-payments.index', [
            'title' => 'Customer Payments',
            'payments' => $payments,
            'customers' => $customers,
            'branches' => $branches,
            'banks' => $banks,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'payment_mode', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

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

        return view('admin.customer-payments.create', [
            'title' => 'Receive Payment',
            'customers' => $customers,
            'branches' => $branches,
            'banks' => $banks,
            'selectedCustomerId' => $customerId ? (int) $customerId : null,
            'outstandingInvoices' => $outstandingInvoices,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'branch_id' => 'required|integer|exists:branches,id',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'payment_mode' => 'required|in:cash,bank,mobile_banking,cheque,adjustment',
            'amount' => 'required|numeric|min:0.01',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_date' => 'required|date',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $payment = $this->paymentService->createPayment([
                'customer_id' => $validated['customer_id'],
                'branch_id' => $validated['branch_id'],
                'bank_id' => $validated['bank_id'] ?? null,
                'payment_mode' => $validated['payment_mode'],
                'amount' => $validated['amount'],
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'payment_date' => $validated['payment_date'],
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? '',
                'created_by' => auth()->id(),
            ]);

            // Auto-confirm (payments are typically immediate).
            $invoiceId = $request->input('invoice_id') ? (int) $request->input('invoice_id') : null;
            $payment = $this->paymentService->confirmPayment($payment->id, auth()->id(), $invoiceId);

            return redirect()->route('admin.customer-payments.show', $payment)
                ->with('success', "Payment {$payment->payment_code} recorded. GL posted + customer ledger updated.");
        } catch (\Throwable $e) {
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
            'customer', 'branch', 'bank',
            'journalEntry.lines.ledger',
            'intercompanyJournalEntry.lines.ledger',
            'allocations.invoice',
        ])->findOrFail($id);

        $customerLedgerEntries = DB::table('customer_ledger')
            ->where('reference_type', 'customer_payment')
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get();

        return view('admin.customer-payments.show', [
            'title' => 'Payment ' . $payment->payment_code,
            'payment' => $payment,
            'customerLedgerEntries' => $customerLedgerEntries,
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $payment = $this->paymentService->cancelPayment($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.customer-payments.show', $payment)
                ->with('success', "Payment {$payment->payment_code} cancelled.");
        } catch (\Throwable $e) {
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
}
