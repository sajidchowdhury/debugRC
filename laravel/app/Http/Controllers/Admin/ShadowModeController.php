<?php

namespace App\Http\Controllers\Admin;

use App\Services\Stock\WarehouseTransferShadowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Shadow Mode Dashboard Controller — Phase 7.3.
 *
 * Provides a web dashboard for monitoring shadow mode comparison
 * results and cutover readiness. Accessible only to admin users.
 *
 * Routes (under admin/shadow-mode):
 *   GET  /                  Dashboard overview (summary stats + cutover readiness)
 *   GET  /comparisons       Recent comparison results (paginated, filtered)
 *   GET  /comparisons/{id}  Single comparison detail
 *   GET  /cutover           Cutover readiness report
 *   POST /run-comparison    Trigger a batch comparison run
 *   POST /purge             Purge old comparison records
 */
class ShadowModeController extends Controller
{
    private WarehouseTransferShadowService $shadowService;

    public function __construct(WarehouseTransferShadowService $shadowService)
    {
        $this->shadowService = $shadowService;
    }

    /**
     * Dashboard overview — summary stats and cutover readiness.
     */
    public function index(): View
    {
        $enabled = config('shadow_mode.enabled', false);
        $mode = config('shadow_mode.mode', 'off');

        // Get recent comparison summary (last 7 days).
        $summary = $enabled
            ? $this->shadowService->getComparisonSummary(
                now()->subDays(7)->format('Y-m-d'),
                now()->format('Y-m-d')
            )
            : [
                'from_date' => now()->subDays(7)->format('Y-m-d'),
                'to_date' => now()->format('Y-m-d'),
                'total' => 0, 'match' => 0, 'diff' => 0,
                'missing_legacy' => 0, 'error' => 0,
                'by_operation' => ['create' => 0, 'confirm' => 0, 'cancel' => 0],
                'by_branch' => [],
            ];

        // Get cutover readiness.
        $cutover = $enabled
            ? $this->shadowService->checkCutoverReadiness()
            : [
                'threshold' => config('shadow_mode.cutover.consecutive_days_zero_diff', 7),
                'consecutive_clean_days' => 0,
                'cutover_ready' => false,
                'remaining_days' => 7,
            ];

        // Get recent diff comparisons (for display).
        $recentDiffs = $enabled
            ? $this->shadowService->getRecentComparisons(10, 'diff')
            : collect();

        return view('admin.shadow-mode.index', [
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

        $query = DB::table('shadow_transfer_comparisons')
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

        // Status counts for filter badges.
        $allComparisons = DB::table('shadow_transfer_comparisons')
            ->whereBetween('compared_at', [
                now()->parse($fromDate)->startOfDay(),
                now()->parse($toDate)->endOfDay(),
            ])
            ->get();

        $statusCounts = [
            'all'   => $allComparisons->count(),
            'match' => $allComparisons->where('diff_status', 'match')->count(),
            'diff'  => $allComparisons->where('diff_status', 'diff')->count(),
            'missing_legacy' => $allComparisons->where('diff_status', 'missing_legacy')->count(),
            'error' => $allComparisons->where('diff_status', 'error')->count(),
        ];

        return view('admin.shadow-mode.comparisons', [
            'comparisons'   => $comparisons,
            'statusCounts'  => $statusCounts,
            'statusFilter'  => $statusFilter,
            'operationFilter' => $operationFilter,
            'fromDate'      => $fromDate,
            'toDate'        => $toDate,
        ]);
    }

    /**
     * Single comparison detail view.
     */
    public function comparisonDetail(int $id): View
    {
        $comparison = DB::table('shadow_transfer_comparisons')->find($id);

        if (!$comparison) {
            abort(404, 'Comparison record not found.');
        }

        $diffDetails = json_decode($comparison->diff_details, true) ?? [];

        // Get the Laravel transfer.
        $laravelTransfer = DB::table('warehouse_transfers')
            ->where('id', $comparison->laravel_transfer_id)
            ->first();

        return view('admin.shadow-mode.detail', [
            'comparison'     => $comparison,
            'diffDetails'    => $diffDetails,
            'laravelTransfer' => $laravelTransfer,
        ]);
    }

    /**
     * Cutover readiness report — detailed view.
     */
    public function cutover(): View
    {
        $readiness = $this->shadowService->checkCutoverReadiness();

        // Get last 14 days of cutover logs.
        $dailyLogs = DB::table('shadow_cutover_log')
            ->orderByDesc('check_date')
            ->limit(14)
            ->get();

        return view('admin.shadow-mode.cutover', [
            'readiness'  => $readiness,
            'dailyLogs'  => $dailyLogs,
        ]);
    }

    /**
     * Trigger a batch comparison run.
     */
    public function runComparison(Request $request): RedirectResponse
    {
        if (!config('shadow_mode.enabled', false)) {
            return redirect()->route('admin.shadow-mode.index')
                ->with('error', 'Shadow mode is disabled. Enable it first in config/shadow_mode.php.');
        }

        $fromDate = $request->get('from_date', now()->subDay()->format('Y-m-d'));
        $toDate = $request->get('to_date', now()->format('Y-m-d'));
        $force = $request->has('force');

        try {
            $result = $this->shadowService->batchCompare($fromDate, $toDate, $force);

            // Record daily cutover log.
            $this->shadowService->recordCutoverDailyLog($result, auth()->id());

            $msg = "Comparison run completed: {$result['total_compared']} transfers compared. "
                . "{$result['match_count']} match, {$result['diff_count']} diff, "
                . "{$result['missing_legacy']} missing legacy, {$result['error_count']} error.";

            if ($result['diff_count'] > 0) {
                return redirect()->route('admin.shadow-mode.comparisons', ['status' => 'diff'])
                    ->with('warning', $msg);
            }

            return redirect()->route('admin.shadow-mode.index')
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->route('admin.shadow-mode.index')
                ->with('error', 'Comparison run failed: ' . $e->getMessage());
        }
    }

    /**
     * Purge old comparison records.
     */
    public function purge(): RedirectResponse
    {
        $purged = $this->shadowService->purgeOldRecords();

        return redirect()->route('admin.shadow-mode.index')
            ->with('success', "Purged {$purged} old comparison records.");
    }

    /**
     * Toggle shadow mode (for admin testing — changes are NOT persisted
     * in .env, only in the session config for the current request).
     *
     * This is a convenience method for testing. In production, shadow
     * mode should be toggled via the SHADOW_MODE_ENABLED and
     * SHADOW_MODE_MODE environment variables.
     */
    public function toggleMode(Request $request): RedirectResponse
    {
        $newMode = $request->get('mode', 'off');
        $validModes = ['off', 'passive', 'active'];

        if (!in_array($newMode, $validModes)) {
            return redirect()->route('admin.shadow-mode.index')
                ->with('error', "Invalid mode: {$newMode}. Use: off, passive, or active.");
        }

        // Note: This only changes the runtime config for the current
        // process. In production, set SHADOW_MODE_MODE in .env.
        config(['shadow_mode.mode' => $newMode]);

        $msg = "Shadow mode set to: {$newMode}. "
            . "Note: This is a runtime change only. Set SHADOW_MODE_MODE={$newMode} "
            . "in .env for persistent changes.";

        return redirect()->route('admin.shadow-mode.index')
            ->with('warning', $msg);
    }
}
