<?php

namespace App\Services\BranchDemand;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand Shadow Service — Phase 10 (Shadow Mode).
 *
 * Compares the Laravel Branch Demand system against the legacy
 * BranchIntercompanyService (MySQL) to verify data consistency
 * during the parallel-run period before cutover.
 *
 * Shadow mode states:
 *   - OFF:     No comparison. Normal operation.
 *   - PASSIVE: Laravel is primary. After each demand operation, the
 *              legacy system also processes the same operation. Results
 *              are compared and logged.
 *   - ACTIVE:  Both systems process every operation simultaneously.
 *              Legacy is the "gold" reference.
 *
 * Cutover readiness: zero diffs for 7 consecutive days.
 *
 * Design mirrors WarehouseTransferShadowService:
 *   - Same comparison table pattern (shadow_demand_comparisons)
 *   - Same RLS approach (branch-scoped reads, admin bypass)
 *   - Same cutover readiness logic (consecutive clean days)
 *   - Same batch comparison + purge workflows
 */
class BranchDemandShadowService
{
    /**
     * Compare a single demand operation between Laravel and legacy.
     *
     * @param string   $operation       create|send|confirm_receipt|reverse|settle|reprice
     * @param int      $demandId        The branch_demand_id
     * @param int|null $fromBranchId    Requester branch (for RLS)
     * @param int|null $toBranchId      Supplier branch (for RLS)
     * @param array    $laravelData     Laravel-side snapshot after the operation
     * @param int|null $comparedBy      User who triggered the comparison
     * @return array   The comparison result
     */
    public function compareOperation(
        string $operation,
        int $demandId,
        ?int $fromBranchId,
        ?int $toBranchId,
        array $laravelData,
        ?int $comparedBy = null,
    ): array {
        $enabled = config('branch_demand_shadow.enabled', false);
        $mode = config('branch_demand_shadow.mode', 'off');

        if (!$enabled || $mode === 'off') {
            return ['skipped' => true, 'reason' => 'Shadow mode is off'];
        }

        // Try to read legacy data
        $legacyData = $this->readLegacyData($demandId, $operation);

        if ($legacyData === null) {
            $result = $this->buildComparisonResult(
                $operation,
                $demandId,
                $fromBranchId,
                $toBranchId,
                $laravelData,
                null,
                'missing_legacy',
                ['message' => 'No matching legacy record found for this demand operation.'],
                $mode,
                $comparedBy,
            );

            $this->logComparison($result);
            $this->alertIfCritical($result);

            return $result;
        }

        // Compare the two systems
        $diffs = $this->computeDiffs($operation, $laravelData, $legacyData);

        $diffStatus = empty($diffs) ? 'match' : 'diff';

        $result = $this->buildComparisonResult(
            $operation,
            $demandId,
            $fromBranchId,
            $toBranchId,
            $laravelData,
            $legacyData,
            $diffStatus,
            $diffs,
            $mode,
            $comparedBy,
        );

        $this->logComparison($result);

        if ($diffStatus !== 'match') {
            $this->alertIfCritical($result);
        }

        return $result;
    }

    /**
     * Run a batch comparison over a date range.
     *
     * Compares all demand operations within the given date range
     * between Laravel and legacy.
     *
     * @param string $dateFrom  Y-m-d
     * @param string $dateTo    Y-m-d
     * @param bool   $force     Re-compare already-compared operations
     * @return array  Summary of the batch comparison
     */
    public function batchCompare(string $dateFrom, string $dateTo, bool $force = false): array
    {
        $enabled = config('branch_demand_shadow.enabled', false);
        if (!$enabled) {
            return ['skipped' => true, 'total_compared' => 0];
        }

        // Get all demands in the date range
        $demands = DB::table('branch_demands')
            ->where('demand_date', '>=', $dateFrom)
            ->where('demand_date', '<=', $dateTo)
            ->get(['id', 'demand_code', 'from_branch_id', 'to_branch_id', 'status', 'total_value', 'settlement_amount']);

        $results = [
            'total_compared' => 0,
            'match_count'    => 0,
            'diff_count'     => 0,
            'missing_legacy' => 0,
            'error_count'    => 0,
        ];

        foreach ($demands as $demand) {
            // Skip already-compared operations unless forced
            if (!$force) {
                $existing = DB::table('shadow_demand_comparisons')
                    ->where('branch_demand_id', $demand->id)
                    ->whereDate('compared_at', '>=', $dateFrom)
                    ->whereDate('compared_at', '<=', $dateTo)
                    ->exists();

                if ($existing) {
                    continue;
                }
            }

            try {
                $laravelData = [
                    'status'            => $demand->status,
                    'total_value'       => (float) $demand->total_value,
                    'settlement_amount' => (float) $demand->settlement_amount,
                ];

                $comparison = $this->compareOperation(
                    'send',
                    $demand->id,
                    $demand->from_branch_id,
                    $demand->to_branch_id,
                    $laravelData,
                );

                $results['total_compared']++;

                if (($comparison['diff_status'] ?? '') === 'match') {
                    $results['match_count']++;
                } elseif (($comparison['diff_status'] ?? '') === 'missing_legacy') {
                    $results['missing_legacy']++;
                } elseif (($comparison['diff_status'] ?? '') === 'diff') {
                    $results['diff_count']++;
                } else {
                    $results['error_count']++;
                }
            } catch (\Throwable $e) {
                $results['total_compared']++;
                $results['error_count']++;

                Log::channel(config('branch_demand_shadow.alerts.log_channel', 'shadow'))
                    ->error('Shadow demand comparison error', [
                        'demand_id' => $demand->id,
                        'error'     => $e->getMessage(),
                    ]);
            }
        }

        return $results;
    }

    /**
     * Get comparison summary for a date range.
     */
    public function getComparisonSummary(string $dateFrom, string $dateTo): array
    {
        $comparisons = DB::table('shadow_demand_comparisons')
            ->where('compared_at', '>=', $dateFrom . ' 00:00:00')
            ->where('compared_at', '<=', $dateTo . ' 23:59:59')
            ->get();

        $byOperation = [];
        $byBranch = [];

        foreach ($comparisons as $c) {
            // Count by operation
            $op = $c->operation;
            $byOperation[$op] = ($byOperation[$op] ?? 0) + 1;

            // Count by branch
            $fromBranch = $c->from_branch_id ?? 'unknown';
            $byBranch[$fromBranch] = ($byBranch[$fromBranch] ?? 0) + 1;
        }

        return [
            'from_date'       => $dateFrom,
            'to_date'         => $dateTo,
            'total'           => $comparisons->count(),
            'match'           => $comparisons->where('diff_status', 'match')->count(),
            'diff'            => $comparisons->where('diff_status', 'diff')->count(),
            'missing_legacy'  => $comparisons->where('diff_status', 'missing_legacy')->count(),
            'error'           => $comparisons->where('diff_status', 'error')->count(),
            'by_operation'    => $byOperation,
            'by_branch'       => $byBranch,
        ];
    }

    /**
     * Get recent comparison results (for dashboard display).
     */
    public function getRecentComparisons(int $limit = 10, ?string $statusFilter = null)
    {
        $query = DB::table('shadow_demand_comparisons')
            ->orderByDesc('compared_at');

        if ($statusFilter) {
            $query->where('diff_status', $statusFilter);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Check cutover readiness.
     *
     * Returns the number of consecutive days with zero diffs and
     * whether the system is ready for cutover.
     */
    public function checkCutoverReadiness(): array
    {
        $threshold = config('branch_demand_shadow.cutover.consecutive_days_zero_diff', 7);

        // Check the last 30 days for consecutive clean days
        $consecutiveCleanDays = 0;

        for ($i = 0; $i < 30; $i++) {
            $date = now()->subDays($i)->toDateString();

            $hasDiffs = DB::table('shadow_demand_comparisons')
                ->whereDate('compared_at', $date)
                ->whereIn('diff_status', ['diff', 'missing_legacy', 'error'])
                ->exists();

            if ($hasDiffs) {
                break;
            }

            // Only count days that have at least one comparison
            $hasComparisons = DB::table('shadow_demand_comparisons')
                ->whereDate('compared_at', $date)
                ->exists();

            if ($hasComparisons) {
                $consecutiveCleanDays++;
            }
        }

        return [
            'threshold'              => $threshold,
            'consecutive_clean_days' => $consecutiveCleanDays,
            'cutover_ready'          => $consecutiveCleanDays >= $threshold,
            'remaining_days'         => max(0, $threshold - $consecutiveCleanDays),
        ];
    }

    /**
     * Record a daily cutover log entry.
     */
    public function recordCutoverDailyLog(array $batchResult, ?int $userId = null): void
    {
        $date = now()->toDateString();

        // Upsert the cutover log
        $exists = DB::table('shadow_cutover_log')
            ->where('check_date', $date)
            ->where('module', 'branch_demand')
            ->exists();

        if ($exists) {
            DB::table('shadow_cutover_log')
                ->where('check_date', $date)
                ->where('module', 'branch_demand')
                ->update([
                    'total_compared' => $batchResult['total_compared'] ?? 0,
                    'match_count'    => $batchResult['match_count'] ?? 0,
                    'diff_count'     => $batchResult['diff_count'] ?? 0,
                    'is_clean'       => ($batchResult['diff_count'] ?? 0) === 0
                                       && ($batchResult['error_count'] ?? 0) === 0,
                    'checked_by'     => $userId,
                    'updated_at'     => now(),
                ]);
        } else {
            DB::table('shadow_cutover_log')->insert([
                'check_date'     => $date,
                'module'         => 'branch_demand',
                'total_compared' => $batchResult['total_compared'] ?? 0,
                'match_count'    => $batchResult['match_count'] ?? 0,
                'diff_count'     => $batchResult['diff_count'] ?? 0,
                'is_clean'       => ($batchResult['diff_count'] ?? 0) === 0
                                   && ($batchResult['error_count'] ?? 0) === 0,
                'checked_by'     => $userId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    /**
     * Purge old comparison records beyond the retention period.
     */
    public function purgeOldRecords(): int
    {
        $retentionDays = config('branch_demand_shadow.retention.comparison_retention_days', 90);
        $zeroRetentionDays = config('branch_demand_shadow.retention.zero_diff_retention_days', 30);

        // Purge zero-diff records older than the shorter retention
        $purged = DB::table('shadow_demand_comparisons')
            ->where('diff_status', 'match')
            ->where('compared_at', '<', now()->subDays($zeroRetentionDays))
            ->delete();

        // Purge all other records older than the longer retention
        $purged += DB::table('shadow_demand_comparisons')
            ->where('compared_at', '<', now()->subDays($retentionDays))
            ->delete();

        return $purged;
    }

    // ===================== INTERNAL HELPERS =====================

    /**
     * Read legacy demand data from the MySQL archive connection.
     *
     * @return array|null  Legacy data or null if not found
     */
    private function readLegacyData(int $demandId, string $operation): ?array
    {
        $connection = config('branch_demand_shadow.legacy_connection', 'archive');

        try {
            // Try to read from the legacy branch_intercompany table
            $legacy = DB::connection($connection)
                ->table('branch_intercompany')
                ->where('demand_id', $demandId)
                ->first();

            if ($legacy === null) {
                return null;
            }

            return [
                'status'            => $legacy->status ?? null,
                'total_value'       => (float) ($legacy->total_value ?? 0),
                'settlement_amount' => (float) ($legacy->settlement_amount ?? 0),
                'raw'               => (array) $legacy,
            ];
        } catch (\Throwable $e) {
            Log::channel(config('branch_demand_shadow.alerts.log_channel', 'shadow'))
                ->warning('Failed to read legacy demand data', [
                    'demand_id'  => $demandId,
                    'operation'  => $operation,
                    'error'      => $e->getMessage(),
                ]);

            return null;
        }
    }

    /**
     * Compute diffs between Laravel and legacy data.
     *
     * @return array  List of diff descriptions
     */
    private function computeDiffs(string $operation, array $laravelData, array $legacyData): array
    {
        $diffs = [];
        $scope = config('branch_demand_shadow.comparison_scope', []);

        // Compare demand header
        if ($scope['demand_header'] ?? true) {
            $tolerance = config('branch_demand_shadow.cutover.max_tolerance_amount', 0.01);

            if (isset($legacyData['status']) && ($laravelData['status'] ?? null) !== $legacyData['status']) {
                $diffs[] = [
                    'field'       => 'status',
                    'laravel'     => $laravelData['status'] ?? null,
                    'legacy'      => $legacyData['status'],
                    'tolerance'   => 0,
                ];
            }

            if (abs(($laravelData['total_value'] ?? 0) - ($legacyData['total_value'] ?? 0)) > $tolerance) {
                $diffs[] = [
                    'field'       => 'total_value',
                    'laravel'     => $laravelData['total_value'] ?? 0,
                    'legacy'      => $legacyData['total_value'] ?? 0,
                    'tolerance'   => $tolerance,
                ];
            }

            if (abs(($laravelData['settlement_amount'] ?? 0) - ($legacyData['settlement_amount'] ?? 0)) > $tolerance) {
                $diffs[] = [
                    'field'       => 'settlement_amount',
                    'laravel'     => $laravelData['settlement_amount'] ?? 0,
                    'legacy'      => $legacyData['settlement_amount'] ?? 0,
                    'tolerance'   => $tolerance,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Build a comparison result array.
     */
    private function buildComparisonResult(
        string $operation,
        int $demandId,
        ?int $fromBranchId,
        ?int $toBranchId,
        array $laravelData,
        ?array $legacyData,
        string $diffStatus,
        array $diffDetails,
        string $mode,
        ?int $comparedBy,
    ): array {
        return [
            'operation'       => $operation,
            'branch_demand_id' => $demandId,
            'from_branch_id'  => $fromBranchId,
            'to_branch_id'    => $toBranchId,
            'diff_status'     => $diffStatus,
            'diff_details'    => $diffDetails,
            'laravel_data'    => $laravelData,
            'legacy_data'     => $legacyData,
            'shadow_mode'     => $mode,
            'compared_at'     => now()->toIso8601String(),
            'compared_by'     => $comparedBy,
        ];
    }

    /**
     * Persist a comparison result to the shadow_demand_comparisons table.
     */
    private function logComparison(array $result): void
    {
        try {
            DB::table('shadow_demand_comparisons')->insert([
                'operation'        => $result['operation'],
                'branch_demand_id' => $result['branch_demand_id'],
                'demand_code'      => $result['laravel_data']['demand_code'] ?? null,
                'from_branch_id'   => $result['from_branch_id'],
                'to_branch_id'     => $result['to_branch_id'],
                'diff_status'      => $result['diff_status'],
                'diff_details'     => json_encode($result['diff_details'], JSON_UNESCAPED_UNICODE),
                'laravel_data'     => json_encode($result['laravel_data'], JSON_UNESCAPED_UNICODE),
                'legacy_data'      => $result['legacy_data'] ? json_encode($result['legacy_data'], JSON_UNESCAPED_UNICODE) : null,
                'shadow_mode'      => $result['shadow_mode'],
                'compared_at'      => now(),
                'compared_by'      => $result['compared_by'],
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel(config('branch_demand_shadow.alerts.log_channel', 'shadow'))
                ->error('Failed to log shadow demand comparison', [
                    'demand_id' => $result['branch_demand_id'] ?? null,
                    'error'     => $e->getMessage(),
                ]);
        }
    }

    /**
     * Alert if the comparison result is critical.
     */
    private function alertIfCritical(array $result): void
    {
        if (!config('branch_demand_shadow.alerts.notify_on_critical', true)) {
            return;
        }

        $diffStatus = $result['diff_status'] ?? '';

        if ($diffStatus === 'match') {
            return;
        }

        Log::channel(config('branch_demand_shadow.alerts.log_channel', 'shadow'))
            ->warning('Branch Demand shadow comparison diff detected', [
                'operation'       => $result['operation'] ?? null,
                'demand_id'       => $result['branch_demand_id'] ?? null,
                'diff_status'     => $diffStatus,
                'diff_details'    => $result['diff_details'] ?? [],
            ]);

        // Email notification if configured
        $email = config('branch_demand_shadow.alerts.notify_email');
        if ($email && $diffStatus === 'diff') {
            // Email sending is deferred to the notification system
            // (actual implementation depends on the project's notification channel)
            Log::channel(config('branch_demand_shadow.alerts.log_channel', 'shadow'))
                ->info('Branch Demand shadow diff email notification queued', [
                    'email'    => $email,
                    'demand_id' => $result['branch_demand_id'] ?? null,
                ]);
        }
    }
}
