<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierPayment;
use App\Services\Accounting\SupplierTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Transaction Controller — Phase 1 (Accounts Sub-Ledger).
 *
 * Handles the supplier payment workflow: list, create, show, reverse, slip, audit.
 *
 * Transaction types:
 *   - payment:  Paying a supplier → Dr AP, Cr Bank/Cash
 *   - advance:  Advance payment to supplier → Dr AP, Cr Bank/Cash
 *   - receive:  Goods received on credit → Dr Inventory, Cr AP
 */
class SupplierTransactionController extends Controller
{
    public function __construct(
        private SupplierTransactionService $service
    ) {}

    /**
     * List supplier payments with filters and stats.
     */
    public function index(Request $request)
    {
        $filters = $this->resolveIndexFilters($request);
        $listBranchId = $this->resolveListBranchId();

        $payments = $this->service->getFilteredPayments($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        $showReversed = $request->input('status') === 'reversed';

        return view('admin.supplier-transactions.index', [
            'title'        => $showReversed ? 'Reversed Supplier Payments' : 'Supplier Payments',
            'payments'     => $payments,
            'stats'        => $stats,
            'suppliers'    => $suppliers,
            'branches'     => $branches,
            'banks'        => $banks,
            'filters'      => $filters,
            'showReversed' => $showReversed,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(Request $request)
    {
        $preselectSupplier = null;
        $preselectId = (int) $request->input('supplier_id');
        if ($preselectId > 0) {
            $row = \App\Models\Supplier::find($preselectId);
            if ($row && $row->is_active) {
                $preselectSupplier = $row;
            }
        }

        $transactionType = $request->input('transaction_type', 'payment');

        $suppliers = \App\Models\Supplier::active()->orderBy('supplier_name')->limit(500)->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();
        $employees = \App\Models\Employee::active()->orderBy('name')->get();

        // Pre-compute payable balance for each supplier (for select dropdown)
        $supplierPayables = [];
        foreach ($suppliers as $s) {
            $supplierPayables[$s->id] = $this->service->getSupplierDue($s->id);
        }

        // Branch restriction: non-admin users get their own branch auto-selected.
        $user = auth()->user();
        $userBranchId = (int) (session('branch_id') ?? ($user ? $user->getBranchId() : 0));
        $isAdmin = $user && $user->isAdmin();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.supplier-transactions.create', [
            'title'              => $this->getTitleForType($transactionType),
            'transactionType'    => $transactionType,
            'preselectSupplier'  => $preselectSupplier,
            'suppliers'          => $suppliers,
            'supplierPayables'   => $supplierPayables,
            'branches'           => $branches,
            'banks'              => $banks,
            'employees'          => $employees,
            'glPreviewLabels'    => $this->service->getGlPreviewLabels(),
            'today'              => now()->format('Y-m-d'),
            'isAdmin'            => $isAdmin,
            'userBranchId'       => $userBranchId,
        ]);
    }

    /**
     * Store a new supplier payment.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'       => 'required|integer|exists:suppliers,id',
            'branch_id'         => 'required|integer|exists:branches,id',
            'bank_id'           => 'nullable|integer|exists:banks,id',
            'payment_mode'      => 'required|in:cash,bank,mobile_banking,cheque,adjustment',
            'transaction_type'  => 'required|in:payment,advance,receive',
            'amount'            => 'required|numeric|min:0.01',
            'discount_amount'   => 'nullable|numeric|min:0',
            'payment_date'      => 'required|date',
            'reference_no'      => 'nullable|string|max:100',
            'collected_by'      => 'nullable|integer|exists:employees,id',
            'notes'             => 'nullable|string|max:500',
        ]);

        try {
            $payment = $this->service->createPayment([
                'supplier_id'      => $validated['supplier_id'],
                'branch_id'        => $validated['branch_id'],
                'bank_id'          => $validated['bank_id'] ?? null,
                'payment_mode'     => $validated['payment_mode'],
                'transaction_type' => $validated['transaction_type'],
                'amount'           => $validated['amount'],
                'discount_amount'  => $validated['discount_amount'] ?? 0,
                'payment_date'     => $validated['payment_date'],
                'reference_no'     => $validated['reference_no'] ?? null,
                'collected_by'     => $validated['collected_by'] ?? null,
                'notes'            => $validated['notes'] ?? '',
                'created_by'       => auth()->id(),
            ]);

            $typeMessages = [
                'payment' => "Payment {$payment->payment_code} recorded. GL posted (Dr AP / Cr Bank/Cash) + supplier ledger updated.",
                'advance' => "Advance {$payment->payment_code} recorded. GL posted (Dr AP / Cr Bank/Cash) + supplier ledger updated.",
                'receive' => "Credit receive {$payment->payment_code} recorded. GL posted (Dr Inventory / Cr AP) + supplier ledger updated.",
            ];

            $successMessage = $typeMessages[$validated['transaction_type']] ?? $typeMessages['payment'];

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'payment_id'   => $payment->id,
                    'payment_code' => $payment->payment_code,
                    'message'      => $successMessage,
                ]);
            }

            return redirect()->route('admin.supplier-transactions.show', ['id' => $payment->id])
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
     * Show payment details with sub-ledger and GL journal.
     */
    public function show(int $supplier_transaction)
    {
        $payment = SupplierPayment::with([
            'supplier', 'branch', 'bank', 'collectedBy',
            'journalEntry.lines.ledger',
            'intercompanyJournalEntry.lines.ledger',
        ])->findOrFail($supplier_transaction);

        $supplierLedgerEntries = DB::table('supplier_ledger')
            ->where('reference_type', 'supplier_payment')
            ->where('reference_id', $supplier_transaction)
            ->orderBy('id')
            ->get();

        $supplierDue = $this->service->getSupplierDue((int) $payment->supplier_id);

        return view('admin.supplier-transactions.show', [
            'title'                 => 'Payment — ' . $payment->payment_code,
            'payment'               => $payment,
            'supplierLedgerEntries' => $supplierLedgerEntries,
            'supplierDue'           => $supplierDue,
            'canReverse'            => !$payment->is_reversed,
        ]);
    }

    /**
     * Reverse a supplier payment.
     */
    public function reverse(Request $request, int $supplier_transaction)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $payment = $this->service->reversePayment(
                $supplier_transaction,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Payment {$payment->payment_code} reversed. GL + ledger reversed.",
                    'payment_id'   => $payment->id,
                    'payment_code' => $payment->payment_code,
                    'is_reversed'  => true,
                ]);
            }

            return redirect()->route('admin.supplier-transactions.show', ['id' => $payment->id])
                ->with('success', "Payment {$payment->payment_code} reversed. GL + ledger reversed.");
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
     * Print a payment slip.
     */
    public function slip(int $supplier_transaction)
    {
        $payment = SupplierPayment::with([
            'supplier', 'branch', 'bank', 'collectedBy',
        ])->findOrFail($supplier_transaction);

        return view('admin.supplier-transactions.slip', [
            'title'    => 'Supplier Payment Slip',
            'payment'  => $payment,
        ]);
    }

    /**
     * Show audit logs for supplier payments.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'supplier_payment_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.supplier-transactions.audit', [
            'title' => 'Supplier Payment Audit Logs',
            'logs'  => $logs,
        ]);
    }

    /**
     * AJAX: Get supplier's current due balance.
     */
    public function getDue(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
        ]);

        $due = $this->service->getSupplierDue((int) $request->input('supplier_id'));

        return response()->json([
            'status'        => 'success',
            'due_balance'   => $due,
            'due_formatted' => number_format($due, 2),
        ]);
    }

    /**
     * AJAX: Search suppliers by name.
     */
    public function searchSupplier(Request $request)
    {
        $term = trim((string) $request->input('term', ''));

        $suppliers = \App\Models\Supplier::active()
            ->when($term, fn($q) => $q->where('supplier_name', 'ILIKE', "%{$term}%")
                ->orWhere('supplier_code', 'ILIKE', "%{$term}%"))
            ->orderBy('supplier_name')
            ->limit(20)
            ->get(['id', 'supplier_code', 'supplier_name', 'mobile', 'address']);

        return response()->json($suppliers);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * Resolve index page filters from request.
     */
    private function resolveIndexFilters(Request $request): array
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // No default date filter — show all records unless user explicitly filters.
        // Previously defaulted to today-only which caused table to appear empty
        // while summary cards (which have no date filter) showed all-time totals.

        return [
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'transaction_type' => $request->input('transaction_type', 'all'),
            'status'           => $request->input('status', 'all'),
            'payment_mode'     => $request->input('payment_mode', 'all'),
            'supplier_id'      => $request->input('supplier_id'),
            'branch_id'        => $request->input('branch_id'),
            'search'           => $request->input('search'),
        ];
    }

    /**
     * Resolve the branch ID for listing (respecting RBAC).
     */
    private function resolveListBranchId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isAdmin()) {
            return null; // Admin sees all branches.
        }

        return (int) (session('branch_id') ?? ($user ? $user->getBranchId() : 0)) ?: null;
    }

    /**
     * Helper: page title by transaction type.
     */
    private function getTitleForType(string $type): string
    {
        return [
            'payment' => 'New Supplier Payment',
            'advance' => 'New Advance Payment',
            'receive' => 'New Credit Receive',
        ][$type] ?? 'New Supplier Payment';
    }
}
