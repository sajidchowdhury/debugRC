<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Services\BranchPnlReportService;
use App\Services\Accounting\FiscalYearService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Branch P&L Report Controller — Session 8.
 *
 * Renders the consolidated Branch P&L report ("As Branch A, what is
 * Branch B's performance?"). The user picks the supplier branch
 * (Branch A — "view as") and the selling branch (Branch B — the
 * report subject) via dropdowns.
 *
 * RBAC: admin, manager, accountant (mirrors the existing reports
 * route group middleware in routes/web.php).
 *
 * Fiscal-year scoping: the report respects the running FY. Closed
 * FYs are invisible — the BelongsToFiscalYear global scope (S2)
 * blocks reads at the Eloquent layer, and partition detach (S4)
 * blocks reads at the physical layer. Super admin cannot bypass
 * either (the Gate::before() amendment in AppServiceProvider
 * hard-denies viewHistoricalData even for super admin).
 *
 * @see \App\Services\BranchPnlReportService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 8
 */
class BranchPnlReportController extends Controller
{
    public function __construct(
        private BranchPnlReportService $reportService,
        private FiscalYearService $fiscalYearService
    ) {}

    /**
     * Branch-level P&L report.
     *
     * GET /admin/branches/{branchBId}/pnl?view_as={branchAId}&from=...&to=...
     */
    public function show(Request $request, int $branchBId): View
    {
        $this->authorizeView();

        $branchB = DB::table('branches')->where('id', $branchBId)->where('is_active', true)->first();
        if (!$branchB) {
            abort(404, 'Branch not found.');
        }

        // Default "view as" branch: the current user's branch.
        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $branchAId = (int) $request->input('view_as', $userBranchId);

        if ($branchAId === $branchBId) {
            return view('admin.branches.pnl', [
                'branchB' => $branchB,
                'branches' => $this->getActiveBranches(),
                'report' => $this->reportService->forBranch(0, 0),
                'error' => 'Please select a different supplier branch ("View as") — Branch A and Branch B cannot be the same.',
                'viewAs' => $branchAId,
                'from' => null,
                'to' => null,
            ]);
        }

        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;

        $report = $this->reportService->forBranch($branchAId, $branchBId, null, $from, $to);

        $branchA = DB::table('branches')->where('id', $branchAId)->first();

        return view('admin.branches.pnl', [
            'branchB' => $branchB,
            'branchA' => $branchA,
            'branches' => $this->getActiveBranches(),
            'report' => $report,
            'error' => null,
            'viewAs' => $branchAId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Per-demand drilldown.
     *
     * GET /admin/branch-demands/{demandId}/pnl
     */
    public function showForDemand(Request $request, int $demandId): View
    {
        $this->authorizeView();

        // FY access check (EARLY): if the demand's fiscal_year_id is not
        // the running FY AND the user is not superadmin-with-explicit-
        // override, deny. We perform this BEFORE invoking the report
        // service so that a closed-FY demand always produces a clean
        // 403 response, regardless of any bugs that might exist in the
        // service's per-FY query path (defense-in-depth).
        //
        // Super admin still cannot view closed FYs per the Gate::before
        // amendment in AppServiceProvider — the FiscalYearPolicy::
        // viewHistoricalData() method hard-denies for everyone.
        $runningFy = $this->fiscalYearService->getCurrentFiscalYear();
        $runningFyId = $runningFy ? (int) $runningFy->id : 0;

        // Lightweight header fetch — avoid running the full per-sale-line
        // query just to learn the demand's FY.
        $demandHeader = DB::table('branch_demands')
            ->where('id', $demandId)
            ->first(['id', 'fiscal_year_id']);

        if (!$demandHeader) {
            abort(404, 'Demand not found.');
        }

        $demandFyId = (int) $demandHeader->fiscal_year_id;
        if ($demandFyId !== $runningFyId && $demandFyId > 0) {
            // Load via Eloquent (App\Models\FiscalYear), NOT via
            // DB::table('fiscal_years'). The FiscalYearPolicy::
            // viewHistoricalData() method type-hints `FiscalYear $fy` —
            // passing a stdClass (which DB::table returns) breaks policy
            // resolution. We also bypass global scopes (BranchScope) here
            // because the demand's FY might belong to a different branch
            // than the authenticated user's; the policy is the authority,
            // not the scope.
            $fy = \App\Models\FiscalYear::withoutGlobalScopes()->find($demandFyId);
            if ($fy) {
                // PRIMARY CHECK (defense-in-depth): a non-active FY is
                // historical (closed/locked/draft) and must be blocked.
                // This fires BEFORE the Gate call, so even if the Gate
                // has a resolution quirk for the given user/FY combo,
                // the closed-status path produces a clean 403.
                if ($fy->status !== 'active') {
                    abort(403, 'This demand belongs to a closed fiscal year and cannot be viewed.');
                }
                // SECONDARY CHECK (defense-in-depth): even if status is
                // 'active' but the FY is not the running one, the policy's
                // viewHistoricalData() method hard-denies for everyone
                // (including super admin, via the Gate::before amendment
                // in AppServiceProvider).
                if (\Illuminate\Support\Facades\Gate::denies('viewHistoricalData', $fy)) {
                    abort(403, 'This demand belongs to a closed fiscal year and cannot be viewed.');
                }
            }
        }

        $drilldown = $this->reportService->forDemand($demandId);

        if (!$drilldown['demand']) {
            abort(404, 'Demand not found.');
        }

        return view('admin.branch-demands.pnl', [
            'demand' => $drilldown['demand'],
            'sale_lines' => $drilldown['sale_lines'],
            'summary' => $drilldown['summary'],
        ]);
    }

    /**
     * CSV export of the branch-level report.
     *
     * GET /admin/branches/{branchBId}/pnl/export?view_as={branchAId}&from=...&to=...
     */
    public function export(Request $request, int $branchBId)
    {
        $this->authorizeView();

        $branchB = DB::table('branches')->where('id', $branchBId)->where('is_active', true)->first();
        if (!$branchB) {
            abort(404, 'Branch not found.');
        }

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $branchAId = (int) $request->input('view_as', $userBranchId);
        $from = $request->input('from') ?: null;
        $to = $request->input('to') ?: null;

        $branchA = DB::table('branches')->where('id', $branchAId)->first();
        $branchAName = $branchA?->branch_name ?? 'branch_' . $branchAId;
        $branchBName = $branchB->branch_name;

        $filename = CsvExporter::filename(
            "Branch_PnL_{$branchAName}_on_{$branchBName}",
            array_filter([$from, $to])
        );

        $headerRow = [
            'Demand Code', 'Demand Date', 'Status',
            'Demanded Qty', 'Demanded Value',
            'Consumed Qty', 'Sold Qty',
            'Revenue', 'Cost', 'Net P&L',
            'Qty @ Min', 'Qty @ Default', 'Qty @ Max', 'Qty Below Min',
            'Override Count',
        ];

        $rows = $this->reportService->exportRows($branchAId, $branchBId, null, $from, $to);

        return CsvExporter::exportFromRows($filename, $headerRow, $rows);
    }

    private function authorizeView(): void
    {
        // The route group middleware already enforces role:admin,manager,
        // accountant. This is defense-in-depth.
        $user = auth()->user();
        if (!$user || !$user->hasRole('admin', 'manager', 'accountant', 'superadmin')) {
            abort(403, 'You do not have permission to view this report.');
        }
    }

    private function getActiveBranches()
    {
        return DB::table('branches')->where('is_active', true)->orderBy('branch_name')->get(['id', 'branch_name']);
    }
}
