<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BranchDemand\BranchDemandShadowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Branch Demand Shadow Mode Dashboard Controller — Phase 10.
 *
 * Provides a web dashboard for monitoring the Branch Demand shadow mode
 * comparison results and cutover readiness. Accessible only to admin users.
 *
 * Routes (under admin/branch-demand-shadow):
 *   GET  /                  Dashboard overview (summary stats + cutover readiness)
 *   GET  /comparisons       Recent comparison results (paginated, filtered)
 *   GET  /comparisons/{id}  Single comparison detail
 *   GET  /cutover           Cutover readiness report
 *   POST /run-comparison    Trigger a batch comparison run
 *   POST /purge             Purge old comparison records
 *
 * Mirrors the ShadowModeController pattern for Warehouse Transfer.
 */
class BranchDemandShadowController extends Controller
{
    private BranchDemandShadowService $shadowService;

    public function __construct(BranchDemandShadowService $shadowService)
    {
        $this->shadowService = $shadowService;
    }

    /**
     * Dashboard overview — summary stats and cutover readiness.
     */
    public function index(): View
    {
        $enabled = config('branch_demand_shadow.enabled', false);
        $mode = config('branch_demand_shadow.mode', 'off');

        // Get recent comparison summary (last 7 days)
        $summary = $enabled
            ? $this->shadowService->getComparisonSummary(
                now()->subDays(7)->format('Y-m-d'),
                now()->format('Y-m-d')
            )
            : [
                'from_date'      => now()->subDays(7)->format('Y-m-d'),
                'to_date'        => now()->format('Y-m-d'),
                'total'          => 0, 'match' => 0, 'diff' => 0,
                'missing_legacy' => 0, 'error' => 0,
                'by_operation'   => [],
                'by_branch'      => [],
            ];

        // Get cutover readiness
        $cutover = $enabled
            ? $this->shadowService->checkCutoverReadiness()
            : [
                'threshold'              => config('branch_demand_shadow.cutover.consecutive_days_zero_diff', 7),
                'consecutive_clean_days' => 0,
                'cutover_ready'          => false,
                'remaining_days'         => 7,
            ];

        // Get recent diff comparisons
        $recentDiffs = $enabled
            ? $this->shadowService->getRecentComparisons(10, 'diff')
            : collect();

        return view('admin.branch-demand-shadow.index', [
            'enabled'      => $enabled,
            'mode'         => $mode,
            'summary'      => $summary,
            'cutover'      => $cutover,
            'recentDiffs'  => $recentDiffs,
        ]);
    }

    /**
     * Recent comparison results — paginated, filterable.
     */
    public function comparisons(Request $request): View
    {
        $statusFilter = $request->get('status', null);
        $operationFilter = $request->get('operation', null);
        $fromDate = $request->get('from_date', now()->subDays(7)->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));

        $query = DB::table('shadow_demand_comparisons')
            ->whereBetween('compared_at', [
                now()->parse($fromDate)->startOfDay(),
                now()->parse($toDate)->endOfDay(),
            ])
            ->orderByDesc('compared_at');

        if ($statusFilter) {
            $query->where('diff_status', $statusFilter);
        }
        if ($operationFilter) {
            $query->where('operation', $operationFilter);
        }

        $comparisons = $query->paginate(50)->withQueryString();

        // Status counts for filter badges
        $allComparisons = DB::table('shadow_demand_comparisons')
            ->whereBetween('compared_at', [
                now()->parse($fromDate)->startOfDay(),
                now()->parse($toDate)->endOfDay(),
            ])
            ->get();

        $statusCounts = [
            'all'            => $allComparisons->count(),
            'match'          => $allComparisons->where('diff_status', 'match')->count(),
            'diff'           => $allComparisons->where('diff_status', 'diff')->count(),
            'missing_legacy' => $allComparisons->where('diff_status', 'missing_legacy')->count(),
            'error'          => $allComparisons->where('diff_status', 'error')->count(),
        ];

        return view('admin.branch-demand-shadow.comparisons', [
            'comparisons'    => $comparisons,
            'statusCounts'   => $statusCounts,
            'statusFilter'   => $statusFilter,
            'operationFilter' => $operationFilter,
            'fromDate'       => $fromDate,
            'toDate'         => $toDate,
        ]);
    }

    /**
     * Single comparison detail view.
     */
    public function comparisonDetail(int $id): View
    {
        $comparison = DB::table('shadow_demand_comparisons')->find($id);

        if (!$comparison) {
            abort(404, 'Comparison record not found.');
        }

        $diffDetails = json_decode($comparison->diff_details, true) ?? [];
        $laravelData = json_decode($comparison->laravel_data, true) ?? [];
        $legacyData = json_decode($comparison->legacy_data, true) ?? [];

        // Get the Laravel demand
        $laravelDemand = DB::table('branch_demands')
            ->where('id', $comparison->branch_demand_id)
            ->first();

        return view('admin.branch-demand-shadow.detail', [
            'comparison'   => $comparison,
            'diffDetails'  => $diffDetails,
            'laravelData'  => $laravelData,
            'legacyData'   => $legacyData,
            'laravelDemand' => $laravelDemand,
        ]);
    }

    /**
     * Cutover readiness report — detailed view.
     */
    public function cutover(): View
    {
        $readiness = $this->shadowService->checkCutoverReadiness();

        // Get last 14 days of cutover logs
        $dailyLogs = DB::table('shadow_cutover_log')
            ->where('module', 'branch_demand')
            ->orderByDesc('check_date')
            ->limit(14)
            ->get();

        return view('admin.branch-demand-shadow.cutover', [
            'readiness' => $readiness,
            'dailyLogs' => $dailyLogs,
        ]);
    }

    /**
     * Trigger a batch comparison run.
     */
    public function runComparison(Request $request): RedirectResponse
    {
        if (!config('branch_demand_shadow.enabled', false)) {
            return redirect()->route('admin.branch-demand-shadow.index')
                ->with('error', 'Shadow mode is disabled. Enable it first in config/branch_demand_shadow.php.');
        }

        $fromDate = $request->get('from_date', now()->subDay()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));
        $force = $request->has('force');

        try {
            $result = $this->shadowService->batchCompare($fromDate, $toDate, $force);

            // Record daily cutover log
            $this->shadowService->recordCutoverDailyLog($result, auth()->id());

            $msg = "Comparison run completed: {$result['total_compared']} demands compared. "
                . "{$result['match_count']} match, {$result['diff_count']} diff, "
                . "{$result['missing_legacy']} missing legacy, {$result['error_count']} error.";

            if ($result['diff_count'] > 0) {
                return redirect()->route('admin.branch-demand-shadow.comparisons', ['status' => 'diff'])
                    ->with('warning', $msg);
            }

            return redirect()->route('admin.branch-demand-shadow.index')
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('admin.branch-demand-shadow.index')
                ->with('error', 'Comparison run failed: ' . $e->getMessage());
        }
    }

    /**
     * Purge old comparison records.
     */
    public function purge(): RedirectResponse
    {
        $purged = $this->shadowService->purgeOldRecords();

        return redirect()->route('admin.branch-demand-shadow.index')
            ->with('success', "Purged {$purged} old comparison records.");
    }
}
