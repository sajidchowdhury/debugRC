<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\PeriodCloseLog;
use App\Services\Accounting\FiscalYearService;
use App\Services\Accounting\AccountingPeriodService;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FiscalYear Controller — Phase 7: Enhanced Period & Fiscal Year Controls
 *
 * Manages fiscal years, period grid, period close/reopen, and audit log.
 */
class FiscalYearController extends Controller
{
    public function __construct(
        private FiscalYearService $fyService,
        private AccountingPeriodService $periodService
    ) {}

    /**
     * List all fiscal years.
     */
    public function index(Request $request)
    {
        $query = FiscalYear::with(['branch', 'creator', 'periods']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        $fiscalYears = $query->orderBy('start_date', 'desc')->paginate(20);
        $branches = Branch::active()->orderBy('branch_name')->get();

        return view('admin.fiscal-years.index', [
            'title'      => 'Fiscal Years',
            'fiscalYears' => $fiscalYears,
            'branches'   => $branches,
            'filters'    => $request->only(['status', 'branch_id']),
        ]);
    }

    /**
     * Show the fiscal year with its period grid.
     */
    public function show(FiscalYear $fiscalYear, Request $request)
    {
        $fiscalYear->load(['branch', 'creator', 'closer', 'periods' => function ($q) {
            $q->orderBy('period_number');
        }]);

        $branchId = $fiscalYear->branch_id ?? (int) session('branch_id', 0);

        // Get close log history
        $closeLogs = PeriodCloseLog::where('fiscal_year_id', $fiscalYear->id)
            ->with(['fiscalPeriod', 'performer', 'branch'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('admin.fiscal-years.show', [
            'title'      => $fiscalYear->name,
            'fiscalYear' => $fiscalYear,
            'closeLogs'  => $closeLogs,
            'branchId'   => $branchId,
        ]);
    }

    /**
     * Create a new fiscal year.
     */
    public function create(Request $request)
    {
        $branches = Branch::active()->orderBy('branch_name')->get();

        // Suggest default dates based on current fiscal year
        $currentFy = $this->fyService->getCurrentFiscalYear();
        $suggestedStart = null;
        $suggestedEnd = null;
        $suggestedCode = null;

        if ($currentFy) {
            $suggestedStart = $currentFy->end_date->copy()->addDay()->format('Y-m-d');
            $suggestedEnd = $currentFy->end_date->copy()->addYear()->format('Y-m-d');
            $nextYear = $currentFy->end_date->copy()->addDay()->year;
            $suggestedCode = 'FY' . $nextYear . '-' . str_pad($nextYear + 1, 2, '0', STR_PAD_LEFT);
        } else {
            $now = now();
            $year = $now->month >= 7 ? $now->year : $now->year - 1;
            $suggestedStart = "{$year}-07-01";
            $suggestedEnd = ($year + 1) . "-06-30";
            $suggestedCode = "FY{$year}-" . str_pad($year + 1, 2, '0', STR_PAD_LEFT);
        }

        return view('admin.fiscal-years.create', [
            'title'          => 'Create Fiscal Year',
            'branches'       => $branches,
            'suggestedStart' => $suggestedStart,
            'suggestedEnd'   => $suggestedEnd,
            'suggestedCode'  => $suggestedCode,
        ]);
    }

    /**
     * Store a new fiscal year.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'fiscal_year_code' => 'required|string|max:20|unique:fiscal_years,fiscal_year_code',
            'start_date'       => 'required|date',
            'end_date'         => 'required|date|after:start_date',
            'branch_id'        => 'nullable|exists:branches,id',
            'period_type'      => 'required|in:monthly,quarterly,yearly',
            'description'      => 'nullable|string|max:500',
        ]);

        try {
            $fy = $this->fyService->createFiscalYear([
                ...$validated,
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.fiscal-years.show', $fy)
                ->with('success', "Fiscal year '{$fy->name}' created with {$fy->periods()->count()} periods.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Activate a draft fiscal year.
     */
    public function activate(FiscalYear $fiscalYear)
    {
        try {
            $fy = $this->fyService->activateFiscalYear($fiscalYear);
            return redirect()->route('admin.fiscal-years.show', $fy)
                ->with('success', "Fiscal year '{$fy->name}' is now active.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close (year-end close) a fiscal year.
     */
    public function close(FiscalYear $fiscalYear)
    {
        try {
            $result = $this->fyService->closeFiscalYear($fiscalYear, auth()->id());
            return redirect()->route('admin.fiscal-years.show', $fiscalYear)
                ->with('success', $result['message'] ?? "Fiscal year closed successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Lock a fiscal year.
     */
    public function lock(FiscalYear $fiscalYear, Request $request)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        try {
            $this->fyService->lockFiscalYear($fiscalYear, auth()->id(), $validated['reason']);
            return redirect()->route('admin.fiscal-years.show', $fiscalYear)
                ->with('success', "Fiscal year '{$fiscalYear->name}' has been locked.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Close an individual period.
     */
    public function closePeriod(FiscalPeriod $period, Request $request)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $result = $this->fyService->closePeriod($period, auth()->id(), $validated['notes'] ?? '');

        if ($result['status'] === 'success') {
            return redirect()->route('admin.fiscal-years.show', $period->fiscal_year_id)
                ->with('success', $result['message']);
        }

        return back()->with('error', $result['message'])->with('preCloseChecks', $result['checks'] ?? []);
    }

    /**
     * Reopen a closed period (admin/superadmin only).
     */
    public function reopenPeriod(FiscalPeriod $period, Request $request)
    {
        if (!auth()->user()?->isAdmin()) {
            return back()->with('error', 'Only admin or superadmin can reopen periods.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $result = $this->fyService->reopenPeriod($period, auth()->id(), $validated['reason']);

        if ($result['status'] === 'success') {
            return redirect()->route('admin.fiscal-years.show', $period->fiscal_year_id)
                ->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Period close log / audit trail.
     */
    public function closeLog(Request $request)
    {
        $query = PeriodCloseLog::with(['fiscalPeriod', 'fiscalYear', 'performer', 'branch']);

        if ($request->filled('fiscal_year_id')) {
            $query->where('fiscal_year_id', $request->input('fiscal_year_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        $logs = $query->orderByDesc('created_at')->paginate(50);
        $fiscalYears = FiscalYear::orderBy('start_date', 'desc')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();

        return view('admin.fiscal-years.close-log', [
            'title'       => 'Period Close Log',
            'logs'        => $logs,
            'fiscalYears' => $fiscalYears,
            'branches'    => $branches,
            'filters'     => $request->only(['fiscal_year_id', 'action', 'branch_id']),
        ]);
    }
}
