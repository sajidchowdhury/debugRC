<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\BudgetExportRequest;
use App\Models\Budget;
use App\Services\Budgeting\BudgetService;
use App\Services\Budgeting\DimensionReportingService;
use App\Models\Branch;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Budget Controller — Phase 6: Budget Management
 *
 * Handles budget CRUD, spreadsheet-like budget entry grid,
 * budget activation/closure, and budget-vs-actual variance reporting.
 */
class BudgetController extends Controller
{
    use WritesExportAuditLog;

    public function __construct(
        private BudgetService $budgetService,
        private DimensionReportingService $reportingService
    ) {}

    /**
     * List all budgets with filters.
     */
    public function index(Request $request)
    {
        $query = Budget::with(['branch', 'creator']);

        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->input('fiscal_year'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        $budgets = $query->orderBy('fiscal_year', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $branches = Branch::active()->orderBy('branch_name')->get();
        $currentYear = now()->format('Y');

        return view('admin.budgets.index', [
            'title'   => 'Budgets',
            'budgets' => $budgets,
            'branches' => $branches,
            'filters' => $request->only(['fiscal_year', 'status', 'branch_id']),
            'currentYear' => $currentYear,
        ]);
    }

    /**
     * Show the budget entry grid (spreadsheet-like).
     */
    public function create(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', now()->format('Y'));
        $branchId = $request->input('branch_id');
        $periodType = $request->input('period_type', 'monthly');

        $gridData = $this->budgetService->getBudgetGridData($fiscalYear, $branchId, $periodType);
        $branches = Branch::active()->orderBy('branch_name')->get();

        return view('admin.budgets.create', [
            'title'       => 'Create Budget',
            'gridData'    => $gridData,
            'branches'    => $branches,
            'fiscalYear'  => $fiscalYear,
            'branchId'    => $branchId,
            'periodType'  => $periodType,
        ]);
    }

    /**
     * Store a new budget from the grid form.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:150',
            'fiscal_year' => 'required|string|max:9',
            'branch_id'   => 'nullable|exists:branches,id',
            'period_type' => 'required|in:monthly,quarterly,yearly',
            'lines'       => 'required|array',
            'lines.*.ledger_id' => 'required|exists:ledgers,id',
            'lines.*.periods'   => 'required|array',
        ]);

        try {
            $budget = $this->budgetService->saveBudgetGrid(
                $validated['fiscal_year'],
                $validated['branch_id'] ?? null,
                $validated['period_type'],
                $validated['name'],
                $validated['lines']
            );

            return redirect()->route('admin.budgets.show', $budget)
                ->with('success', "Budget '{$budget->name}' created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show a budget with its lines and variance data.
     */
    public function show(Budget $budget, Request $request)
    {
        $budget->load(['branch', 'creator', 'approver', 'lines.ledger']);

        $period = $request->input('period');
        $varianceData = null;

        if ($budget->isActive() || $budget->status === 'closed') {
            $varianceData = $this->budgetService->getBudgetVsActual($budget, $period ? (int) $period : null);
        }

        return view('admin.budgets.show', [
            'title'   => $budget->name,
            'budget'  => $budget,
            'varianceData' => $varianceData,
        ]);
    }

    /**
     * Edit a draft budget (same grid as create).
     */
    public function edit(Budget $budget)
    {
        if (!$budget->isEditable()) {
            return redirect()->route('admin.budgets.show', $budget)
                ->with('error', 'Only draft budgets can be edited.');
        }

        $gridData = $this->budgetService->getBudgetGridData(
            $budget->fiscal_year,
            $budget->branch_id,
            $budget->period_type
        );

        $branches = Branch::active()->orderBy('branch_name')->get();

        return view('admin.budgets.edit', [
            'title'      => "Edit Budget: {$budget->name}",
            'budget'     => $budget,
            'gridData'   => $gridData,
            'branches'   => $branches,
            'fiscalYear' => $budget->fiscal_year,
            'branchId'   => $budget->branch_id,
            'periodType' => $budget->period_type,
        ]);
    }

    /**
     * Update a draft budget.
     */
    public function update(Request $request, Budget $budget)
    {
        if (!$budget->isEditable()) {
            return back()->with('error', 'Only draft budgets can be edited.');
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:150',
            'lines'       => 'required|array',
            'lines.*.ledger_id' => 'required|exists:ledgers,id',
            'lines.*.periods'   => 'required|array',
        ]);

        try {
            $this->budgetService->updateBudget($budget, ['name' => $validated['name']], $validated['lines']);

            return redirect()->route('admin.budgets.show', $budget)
                ->with('success', "Budget '{$budget->name}' updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Activate a draft budget.
     */
    public function activate(Budget $budget)
    {
        try {
            $this->budgetService->activateBudget($budget);
            return redirect()->route('admin.budgets.show', $budget)
                ->with('success', "Budget '{$budget->name}' is now active.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close an active budget.
     */
    public function close(Budget $budget)
    {
        try {
            $this->budgetService->closeBudget($budget);
            return redirect()->route('admin.budgets.show', $budget)
                ->with('success', "Budget '{$budget->name}' has been closed.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel a draft/active budget.
     */
    public function cancel(Budget $budget)
    {
        try {
            $this->budgetService->cancelBudget($budget);
            return redirect()->route('admin.budgets.show', $budget)
                ->with('success', "Budget '{$budget->name}' has been cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Budget vs Actual variance report.
     */
    public function varianceReport(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', now()->format('Y'));
        $branchId = $request->input('branch_id');
        $period = $request->input('period');

        $budget = Budget::where('fiscal_year', $fiscalYear)
            ->where('status', 'active')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->first();

        $varianceData = null;
        if ($budget) {
            $varianceData = $this->budgetService->getBudgetVsActual($budget, $period ? (int) $period : null);
        }

        $branches = Branch::active()->orderBy('branch_name')->get();

        return view('admin.budgets.variance', [
            'title'   => 'Budget vs Actual',
            'budget'  => $budget,
            'varianceData' => $varianceData,
            'branches' => $branches,
            'fiscalYear' => $fiscalYear,
            'selectedBranch' => $branchId,
            'selectedPeriod' => $period,
        ]);
    }

    /**
     * Export budget vs actual as CSV.
     *
     * REPORTS-AUDIT-1 (G-153/G-132/G-150-partial / csv-export.md G24+G6+G11):
     *   - Uses BudgetExportRequest FormRequest (closes G-134) — adds
     *     `fiscal_year` string validation + `dimension_id` exists check.
     *   - Refactored to CsvExporter::exportFromRows() (closes G-150 partial +
     *     G-153) which handles BOM + Content-Type + streaming in one place.
     *     The previous implementation wrote NO BOM — Excel mojibaked Bengali
     *     ledger names. The CsvExporter writes the BOM via config('reports.csv.bom').
     *   - Writes an export_audit_log row (closes G-132) — previously no audit
     *     trail existed for exports.
     */
    public function exportCsv(BudgetExportRequest $request)
    {
        $fiscalYear = $request->input('fiscal_year', now()->format('Y'));
        $branchId = $request->input('branch_id');

        $budget = Budget::where('fiscal_year', $fiscalYear)
            ->where('status', 'active')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->first();

        if (!$budget) {
            return back()->with('error', 'No active budget found for the selected criteria.');
        }

        $varianceData = $this->budgetService->getBudgetVsActual($budget);

        $headerRow = ['Account Type', 'Ledger Code', 'Ledger Name', 'Period', 'Budget', 'Actual', 'Variance', 'Variance %'];

        // Generator yields rows one at a time so CsvExporter::exportFromRows
        // can stream them to php://output without buffering the full CSV body.
        $rows = $this->buildBudgetVarianceRows($varianceData);

        $filename = "budget_vs_actual_{$fiscalYear}";

        // Audit log: row count is computed by iterating varianceData lines.
        // The generator above does the same iteration, so we reuse the count.
        $rowCount = 0;
        foreach ($varianceData['lines'] as $lines) {
            $rowCount += is_countable($lines) ? count($lines) : 0;
        }

        $this->logExport('budget_variance', [
            'fiscal_year' => $fiscalYear,
            'branch_id' => $branchId,
            'budget_id' => $budget->id,
        ], rowCount: $rowCount, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rows);
    }

    /**
     * Build the row generator for the budget-variance CSV export.
     *
     * Extracted as a private method so the lint checker (which doesn't
     * parse the `yield` keyword inside a closure) can validate the
     * exportCsv() method body. The generator yields one row at a time
     * from the pre-built $varianceData['lines'] array.
     *
     * @param  array $varianceData Output from BudgetService::getBudgetVsActual()
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildBudgetVarianceRows(array $varianceData): \Generator
    {
        foreach ($varianceData['lines'] as $type => $lines) {
            foreach ($lines as $row) {
                yield [
                    $row->account_type,
                    $row->ledger_code,
                    $row->ledger_name,
                    $row->period,
                    number_format((float) $row->budget_amount, 2),
                    number_format((float) $row->actual_amount, 2),
                    number_format((float) $row->variance_amount, 2),
                    $row->variance_percent ?? 'N/A',
                ];
            }
        }
    }

    /**
     * Resolve the branch_id for list filtering.
     */
    private function resolveListBranchId(): ?int
    {
        $branchId = session('branch_id');
        if ($branchId && $branchId !== 'all') {
            return (int) $branchId;
        }
        return null;
    }
}
