<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OtherIncome;
use App\Services\Accounting\OtherIncomeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Other Income Controller — Phase 5 (Accounts Sub-Ledger).
 *
 * Handles the other income workflow: list, create, show, reverse, slip, audit.
 *
 * GL posting:
 *   Dr Cash/Bank / Cr Other Income head (user-selected ledger)
 */
class OtherIncomeController extends Controller
{
    public function __construct(
        private OtherIncomeService $service
    ) {}

    /**
     * List other incomes with filters and stats.
     */
    public function index(Request $request)
    {
        $filters = $this->resolveIndexFilters($request, 'income');
        $listBranchId = $this->resolveListBranchId();

        $incomes = $this->service->getFilteredIncomes($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $banks = \App\Models\Bank::active()->orderBy('bank_name')->get();

        $showReversed = $request->input('status') === 'reversed';

        return view('admin.other-incomes.index', [
            'title'        => $showReversed ? 'Reversed Other Incomes' : 'Other Incomes',
            'incomes'      => $incomes,
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

        // Get income ledgers for the dropdown (Income account_type or other_income nature).
        $incomeLedgers = \App\Models\Ledger::active()
            ->where('account_type', 'Income')
            ->orderBy('ledger_name')
            ->get(['id', 'ledger_code', 'ledger_name', 'account_type']);

        return view('admin.other-incomes.create', [
            'title'           => 'New Other Income',
            'branches'        => $branches,
            'banks'           => $banks,
            'incomeLedgers'   => $incomeLedgers,
            'glPreviewLabels' => $this->service->getGlPreviewLabels(),
            'today'           => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Store a new other income.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'     => 'required|integer|exists:branches,id',
            'bank_id'       => 'nullable|integer|exists:banks,id',
            'payment_mode'  => 'required|in:cash,bank,mobile_banking,cheque',
            'income_type'   => 'nullable|string|max:50',
            'ledger_id'     => 'required|integer|exists:ledgers,id',
            'amount'        => 'required|numeric|min:0.01',
            'description'   => 'nullable|string|max:500',
            'income_date'   => 'nullable|date',
        ]);

        try {
            $income = $this->service->createIncome([
                'branch_id'     => $validated['branch_id'],
                'bank_id'       => $validated['bank_id'] ?? null,
                'payment_mode'  => $validated['payment_mode'],
                'income_type'   => $validated['income_type'] ?? null,
                'ledger_id'     => $validated['ledger_id'],
                'amount'        => $validated['amount'],
                'description'   => $validated['description'] ?? '',
                'income_date'   => $validated['income_date'] ?? now()->format('Y-m-d'),
                'created_by'    => auth()->id(),
            ]);

            $successMessage = "Other Income {$income->income_code} recorded. GL posted (Dr Cash/Bank / Cr Income Head).";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'        => 'success',
                    'income_id'     => $income->id,
                    'income_code'   => $income->income_code,
                    'message'       => $successMessage,
                    'redirect_url'  => route('admin.other-incomes.show', ['id' => $income->id]),
                ]);
            }

            return redirect()->route('admin.other-incomes.show', ['id' => $income->id])
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
     * Show income details with GL journal.
     */
    public function show(int $id)
    {
        $income = OtherIncome::with([
            'branch', 'bank',
            'journalEntry.lines.ledger',
        ])->findOrFail($id);

        return view('admin.other-incomes.show', [
            'title'     => 'Other Income — ' . $income->income_code,
            'income'    => $income,
            'canReverse' => !$income->is_reversed,
        ]);
    }

    /**
     * Reverse an other income.
     */
    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $income = $this->service->reverseIncome(
                $id,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Other Income {$income->income_code} reversed. GL reversed.",
                    'income_id'    => $income->id,
                    'income_code'  => $income->income_code,
                    'is_reversed'  => true,
                ]);
            }

            return redirect()->route('admin.other-incomes.show', ['id' => $income->id])
                ->with('success', "Other Income {$income->income_code} reversed. GL reversed.");
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
     * Print an income slip.
     */
    public function slip(int $id)
    {
        $income = OtherIncome::with([
            'branch', 'bank',
        ])->findOrFail($id);

        return view('admin.other-incomes.slip', [
            'title'  => 'Other Income Slip',
            'income' => $income,
        ]);
    }

    /**
     * Show audit logs for other incomes.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'other_income_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.other-incomes.audit', [
            'title' => 'Other Income Audit Logs',
            'logs'  => $logs,
        ]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    /**
     * Resolve index page filters from request.
     */
    private function resolveIndexFilters(Request $request, string $type = 'income'): array
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
            'income_type'   => $request->input('income_type'),
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
