<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BranchDemand\BranchDemandWeeklyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Branch Demand Report Controller — Phase 6.
 *
 * Handles the weekly/daily audit report that replicates the user's
 * Excel sheet ("MAIN BILL SHIT1.xlsx"). This is the single-page report
 * that eliminates the need to visit multiple reports and manually compile data.
 *
 * Endpoints:
 *   - weekly:     The main report view with date picker and branch selector
 *   - exportCsv:  CSV export of the report data
 *   - drillDown:  JSON drill-down for a specific cell (underlying transactions)
 */
class BranchDemandReportController extends Controller
{
    public function __construct(
        private BranchDemandWeeklyReportService $reportService,
    ) {}

    /**
     * Get the current user's branch ID from session.
     */
    private function currentBranchId(): int
    {
        return (int) session('branch_id', 0);
    }

    /**
     * Whether the authenticated user is an admin (sees all branches).
     */
    private function currentUserIsAdmin(): bool
    {
        $user = Auth::user();
        return $user && (method_exists($user, 'isAdmin') ? $user->isAdmin() : ($user->role ?? null) === 'admin');
    }

    /**
     * Show the weekly/daily audit report.
     *
     * GET /admin/branch-demands/weekly-report
     *
     * Query params:
     *   - branch_id: The branch to report on (admins see all branches, others see their own)
     *   - from_date: Start date (default: 7 days ago)
     *   - to_date: End date (default: today)
     */
    public function weekly(Request $request)
    {
        $branchId = $this->resolveBranchId($request);
        $dateFrom = $request->input('from_date', now()->subDays(6)->format('Y-m-d'));
        $dateTo = $request->input('to_date', now()->format('Y-m-d'));

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return back()->withErrors(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
        }

        if ($dateFrom > $dateTo) {
            return back()->withErrors(['error' => 'From date must be before or equal to to date.']);
        }

        // Limit the date range to prevent excessive queries
        $daysDiff = (int) ((strtotime($dateTo) - strtotime($dateFrom)) / 86400);
        if ($daysDiff > 90) {
            return back()->withErrors(['error' => 'Date range cannot exceed 90 days.']);
        }

        // Generate the report
        $report = $this->reportService->generateDailyReport($branchId, $dateFrom, $dateTo);

        // Get branches for the selector (admin only)
        $branches = [];
        if ($this->currentUserIsAdmin()) {
            $branches = DB::table('branches')
                ->where('is_active', true)
                ->orderBy('branch_name')
                ->get(['id', 'branch_code', 'branch_name']);
        }

        return view('admin.branch-demands.weekly-report', [
            'report'   => $report,
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
        ]);
    }

    /**
     * Export the weekly report as CSV.
     *
     * GET /admin/branch-demands/weekly-report/export
     *
     * Query params: same as weekly()
     */
    public function exportCsv(Request $request)
    {
        $branchId = $this->resolveBranchId($request);
        $dateFrom = $request->input('from_date', now()->subDays(6)->format('Y-m-d'));
        $dateTo = $request->input('to_date', now()->format('Y-m-d'));

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return back()->withErrors(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
        }

        $report = $this->reportService->generateDailyReport($branchId, $dateFrom, $dateTo);
        $csvData = $this->reportService->toCsvArray($report);

        // Generate CSV content
        $filename = "branch_demand_weekly_{$branchId}_{$dateFrom}_to_{$dateTo}.csv";
        $handle = fopen('php://temp', 'r+');

        foreach ($csvData as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response($csvContent)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * JSON: Drill-down for a specific cell.
     *
     * GET /admin/branch-demands/weekly-report/drill-down
     *
     * Query params:
     *   - column: The column key (e.g., 'cash_sale', 'demand_bill')
     *   - branch_id: The branch
     *   - date: The date
     */
    public function drillDown(Request $request)
    {
        $request->validate([
            'column'    => 'required|string',
            'branch_id' => 'required|integer|exists:branches,id',
            'date'      => 'required|date',
        ]);

        $data = $this->reportService->getDrillDown(
            $request->column,
            (int) $request->branch_id,
            $request->date
        );

        return response()->json([
            'column'    => $request->column,
            'branch_id' => (int) $request->branch_id,
            'date'      => $request->date,
            'data'      => $data,
        ]);
    }

    /**
     * Resolve the branch ID from the request.
     *
     * Admins can select any branch; non-admins are restricted to their own.
     */
    private function resolveBranchId(Request $request): int
    {
        if ($this->currentUserIsAdmin() && $request->filled('branch_id')) {
            return (int) $request->branch_id;
        }

        return $this->currentBranchId();
    }
}
