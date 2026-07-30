<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTransaction;
use App\Services\Accounting\EmployeeTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employee Transaction Controller — Phase 2 (Accounts Sub-Ledger).
 *
 * Handles the employee transaction workflow: list, create, show, reverse, slip, audit.
 *
 * Transaction types:
 *   - advance:    Cash/bank paid to employee → Dr Employee Payable, Cr Bank/Cash
 *   - loan:       Loan disbursed → Dr Employee Payable, Cr Bank/Cash
 *   - salary:     Salary paid out → Dr Salary Expense, Cr Bank/Cash
 *   - repayment:  Employee repays → Dr Bank/Cash, Cr Employee Payable
 *   - deduction:  Deduction/recovery → Dr Salary Expense, Cr Employee Payable
 *   - adjustment: Manual adjustment → debit or credit depending on context
 */
class EmployeeTransactionController extends Controller
{
    public function __construct(
        private EmployeeTransactionService $service
    ) {}

    /**
     * List employee transactions with filters and stats.
     */
    public function index(Request $request)
    {
        $filters = $this->resolveIndexFilters($request);
        $listBranchId = $this->resolveListBranchId();

        $transactions = $this->service->getFilteredTransactions($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $employees = \App\Models\Employee::active()->orderBy('name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        $showReversed = $request->input('status') === 'reversed';

        return view('admin.employee-transactions.index', [
            'title'        => $showReversed ? 'Reversed Employee Transactions' : 'Employee Transactions',
            'transactions' => $transactions,
            'stats'        => $stats,
            'employees'    => $employees,
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
        $preselectEmployee = null;
        $preselectId = (int) $request->input('employee_id');
        if ($preselectId > 0) {
            $row = \App\Models\Employee::find($preselectId);
            if ($row && $row->is_active) {
                $preselectEmployee = $row;
            }
        }

        $transactionType = $request->input('transaction_type', 'advance');

        $employees = \App\Models\Employee::active()->orderBy('name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();
        $collectors = \App\Models\Employee::active()->orderBy('name')->get();

        return view('admin.employee-transactions.create', [
            'title'              => $this->getTitleForType($transactionType),
            'transactionType'    => $transactionType,
            'preselectEmployee'  => $preselectEmployee,
            'employees'          => $employees,
            'branches'           => $branches,
            'banks'              => $banks,
            'collectors'         => $collectors,
            'glPreviewLabels'    => $this->service->getGlPreviewLabels(),
            'today'              => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Store a new employee transaction.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id'       => 'required|integer|exists:employees,id',
            'branch_id'         => 'required|integer|exists:branches,id',
            'bank_id'           => 'nullable|integer|exists:banks,id',
            'payment_mode'      => 'required|in:cash,bank,mobile_banking,cheque,adjustment',
            'transaction_type'  => 'required|in:advance,loan,repayment,salary,deduction,adjustment',
            'amount'            => 'required|numeric|min:0.01',
            'description'       => 'nullable|string|max:500',
            'collected_by'      => 'nullable|integer|exists:employees,id',
        ]);

        try {
            $transaction = $this->service->createTransaction([
                'employee_id'      => $validated['employee_id'],
                'branch_id'        => $validated['branch_id'],
                'bank_id'          => $validated['bank_id'] ?? null,
                'payment_mode'     => $validated['payment_mode'],
                'transaction_type' => $validated['transaction_type'],
                'amount'           => $validated['amount'],
                'description'      => $validated['description'] ?? '',
                'collected_by'     => $validated['collected_by'] ?? null,
                'created_by'       => auth()->id(),
            ]);

            $typeMessages = [
                'advance'    => "Advance {$transaction->transaction_code} recorded. GL posted (Dr Employee Payable / Cr Bank/Cash) + employee ledger updated.",
                'loan'       => "Loan {$transaction->transaction_code} recorded. GL posted (Dr Employee Payable / Cr Bank/Cash) + employee ledger updated.",
                'salary'     => "Salary {$transaction->transaction_code} recorded. GL posted (Dr Salary Expense / Cr Bank/Cash) + employee ledger updated.",
                'repayment'  => "Repayment {$transaction->transaction_code} recorded. GL posted (Dr Bank/Cash / Cr Employee Payable) + employee ledger updated.",
                'deduction'  => "Deduction {$transaction->transaction_code} recorded. GL posted (Dr Salary Expense / Cr Employee Payable) + employee ledger updated.",
                'adjustment' => "Adjustment {$transaction->transaction_code} recorded. GL posted + employee ledger updated.",
            ];

            $successMessage = $typeMessages[$validated['transaction_type']] ?? $typeMessages['advance'];

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'           => 'success',
                    'transaction_id'   => $transaction->id,
                    'transaction_code' => $transaction->transaction_code,
                    'message'          => $successMessage,
                ]);
            }

            return redirect()->route('admin.employee-transactions.show', $transaction->id)
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
     * Show transaction details with sub-ledger and GL journal.
     */
    public function show(int $id)
    {
        $transaction = EmployeeTransaction::with([
            'employee', 'branch', 'bank', 'collectedBy',
            'journalEntry.lines.ledger',
        ])->findOrFail($id);

        $employeeLedgerEntries = DB::table('employee_ledger')
            ->where('reference_type', 'employee_transaction')
            ->where('reference_id', $id)
            ->orderBy('id')
            ->get();

        $employeeDue = $this->service->getEmployeeDue((int) $transaction->employee_id);

        return view('admin.employee-transactions.show', [
            'title'                  => 'Transaction — ' . $transaction->transaction_code,
            'transaction'            => $transaction,
            'employeeLedgerEntries'  => $employeeLedgerEntries,
            'employeeDue'            => $employeeDue,
            'canReverse'             => !$transaction->is_reversed,
        ]);
    }

    /**
     * Reverse an employee transaction.
     */
    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $transaction = $this->service->reverseTransaction(
                $id,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'           => 'success',
                    'message'          => "Transaction {$transaction->transaction_code} reversed. GL + ledger reversed.",
                    'transaction_id'   => $transaction->id,
                    'transaction_code' => $transaction->transaction_code,
                    'is_reversed'      => true,
                ]);
            }

            return redirect()->route('admin.employee-transactions.show', $transaction->id)
                ->with('success', "Transaction {$transaction->transaction_code} reversed. GL + ledger reversed.");
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
     * Print a transaction slip.
     */
    public function slip(int $id)
    {
        $transaction = EmployeeTransaction::with([
            'employee', 'branch', 'bank', 'collectedBy',
        ])->findOrFail($id);

        return view('admin.employee-transactions.slip', [
            'title'       => 'Employee Transaction Slip',
            'transaction' => $transaction,
        ]);
    }

    /**
     * Show audit logs for employee transactions.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'employee_transaction_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.employee-transactions.audit', [
            'title' => 'Employee Transaction Audit Logs',
            'logs'  => $logs,
        ]);
    }

    /**
     * AJAX: Get employee's current due balance.
     */
    public function getDue(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
        ]);

        $due = $this->service->getEmployeeDue((int) $request->input('employee_id'));

        return response()->json([
            'status'       => 'success',
            'due_balance'  => $due,
            'due_formatted' => number_format($due, 2),
        ]);
    }

    /**
     * AJAX: Search employees by name.
     */
    public function searchEmployee(Request $request)
    {
        $term = trim((string) $request->input('term', ''));

        $employees = \App\Models\Employee::active()
            ->when($term, fn($q) => $q->where('name', 'ILIKE', "%{$term}%")
                ->orWhere('employee_code', 'ILIKE', "%{$term}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'employee_code', 'name', 'mobile', 'designation']);

        return response()->json($employees);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * Resolve index page filters from request.
     */
    private function resolveIndexFilters(Request $request): array
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
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'transaction_type' => $request->input('transaction_type', 'all'),
            'status'           => $request->input('status', 'all'),
            'payment_mode'     => $request->input('payment_mode', 'all'),
            'employee_id'      => $request->input('employee_id'),
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
            'advance'    => 'New Employee Advance',
            'loan'       => 'New Employee Loan',
            'salary'     => 'New Salary Payment',
            'repayment'  => 'New Employee Repayment',
            'deduction'  => 'New Deduction',
            'adjustment' => 'New Adjustment',
        ][$type] ?? 'New Employee Transaction';
    }
}
