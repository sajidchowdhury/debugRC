<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtherExpense;
use App\Services\Accounting\OtherExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Other Expense Controller — Phase 5 (Accounts Sub-Ledger).
 *
 * Handles the other expense workflow: list, create, show, reverse, slip, audit.
 *
 * GL posting:
 *   Dr Operating Expense head (user-selected ledger) / Cr Cash/Bank
 */
class OtherExpenseController extends Controller
{
    public function __construct(
        private OtherExpenseService $service
    ) {}

    /**
     * List other expenses with filters and stats.
     */
    public function index(Request $request)
    {
        $filters = $this->resolveIndexFilters($request, 'expense');
        $listBranchId = $this->resolveListBranchId();

        $expenses = $this->service->getFilteredExpenses($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        $showReversed = $request->input('status') === 'reversed';

        return view('admin.other-expenses.index', [
            'title'        => $showReversed ? 'Reversed Other Expenses' : 'Other Expenses',
            'expenses'     => $expenses,
            'stats'        => $stats,
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
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        // Get expense ledgers for the dropdown (Expense account_type).
        $expenseLedgers = \App\Models\Ledger::active()
            ->where('account_type', 'Expense')
            ->orderBy('ledger_name')
            ->get(['id', 'ledger_code', 'ledger_name', 'account_type']);

        return view('admin.other-expenses.create', [
            'title'           => 'New Other Expense',
            'branches'        => $branches,
            'banks'           => $banks,
            'expenseLedgers'  => $expenseLedgers,
            'glPreviewLabels' => $this->service->getGlPreviewLabels(),
            'today'           => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Store a new other expense.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'     => 'required|integer|exists:branches,id',
            'bank_id'       => 'nullable|integer|exists:banks,id',
            'payment_mode'  => 'required|in:cash,bank,mobile_banking,cheque',
            'expense_type'  => 'nullable|string|max:50',
            'ledger_id'     => 'required|integer|exists:ledgers,id',
            'amount'        => 'required|numeric|min:0.01',
            'description'   => 'nullable|string|max:500',
            'expense_date'  => 'nullable|date',
        ]);

        try {
            $expense = $this->service->createExpense([
                'branch_id'     => $validated['branch_id'],
                'bank_id'       => $validated['bank_id'] ?? null,
                'payment_mode'  => $validated['payment_mode'],
                'expense_type'  => $validated['expense_type'] ?? null,
                'ledger_id'     => $validated['ledger_id'],
                'amount'        => $validated['amount'],
                'description'   => $validated['description'] ?? '',
                'expense_date'  => $validated['expense_date'] ?? now()->format('Y-m-d'),
                'created_by'    => auth()->id(),
            ]);

            $successMessage = "Other Expense {$expense->expense_code} recorded. GL posted (Dr Expense Head / Cr Cash/Bank).";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'         => 'success',
                    'expense_id'     => $expense->id,
                    'expense_code'   => $expense->expense_code,
                    'message'        => $successMessage,
                    'redirect_url'   => route('admin.other-expenses.show', ['id' => $expense->id]),
                ]);
            }

            return redirect()->route('admin.other-expenses.show', ['id' => $expense->id])
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
     * Show expense details with GL journal.
     */
    public function show(int $id)
    {
        $expense = OtherExpense::with([
            'branch', 'bank',
            'journalEntry.lines.ledger',
        ])->findOrFail($id);

        return view('admin.other-expenses.show', [
            'title'      => 'Other Expense — ' . $expense->expense_code,
            'expense'    => $expense,
            'canReverse' => !$expense->is_reversed,
        ]);
    }

    /**
     * Reverse an other expense.
     */
    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $expense = $this->service->reverseExpense(
                $id,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Other Expense {$expense->expense_code} reversed. GL reversed.",
                    'expense_id'   => $expense->id,
                    'expense_code' => $expense->expense_code,
                    'is_reversed'  => true,
                ]);
            }

            return redirect()->route('admin.other-expenses.show', ['id' => $expense->id])
                ->with('success', "Other Expense {$expense->expense_code} reversed. GL reversed.");
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
     * Print an expense slip.
     */
    public function slip(int $id)
    {
        $expense = OtherExpense::with([
            'branch', 'bank',
        ])->findOrFail($id);

        return view('admin.other-expenses.slip', [
            'title'   => 'Other Expense Slip',
            'expense' => $expense,
        ]);
    }

    /**
     * Show audit logs for other expenses.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'other_expense_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.other-expenses.audit', [
            'title' => 'Other Expense Audit Logs',
            'logs'  => $logs,
        ]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * Resolve index page filters from request.
     */
    private function resolveIndexFilters(Request $request, string $type = 'expense'): array
    {
        $today = now()->format('Y-m-d');

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Default: today if no dates provided.
        if (!$dateFrom && !$dateTo) {
            $dateFrom = $today;
            $dateTo = $today;
        }

        return [
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'status'        => $request->input('status', 'all'),
            'payment_mode'  => $request->input('payment_mode', 'all'),
            'expense_type'  => $request->input('expense_type'),
            'branch_id'     => $request->input('branch_id'),
            'search'        => $request->input('search'),
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
}
