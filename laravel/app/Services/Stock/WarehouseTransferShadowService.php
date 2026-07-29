<?php

namespace App\Services\Stock;

use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Shadow Mode Service — Phase 7.3.
 *
 * Compares WarehouseTransfer operations between the Laravel system and the
 * legacy MySQL system to verify that both produce identical results. This
 * is the core component of the shadow mode infrastructure.
 *
 * Shadow mode operates in three states (see config/shadow_mode.php):
 *
 *   OFF      — No comparison. Normal single-system operation.
 *   PASSIVE  — Laravel primary; after each transfer operation, the
 *              legacy result is read and compared. Diffs are logged
 *              but operations are NOT blocked.
 *   ACTIVE   — Both systems process every operation. Legacy is the
 *              "gold" reference. Diffs trigger alerts.
 *
 * Cutover criterion: 7 consecutive days of zero diffs.
 *
 * Comparison scope (configurable via shadow_mode.comparison_scope):
 *   1. stock_movements  — qty, rate, warehouse_id per product
 *   2. gl_postings      — journal entries/lines (same-branch: neither should have GL)
 *   3. status           — transfer status after each operation
 *   4. avg_cost         — warehouse avg_cost at source + dest after transfer
 *   5. reversal_order   — dest IN reversed before source OUT
 *
 * Usage:
 *   // After a transfer operation (auto-called in passive/active mode):
 *   $shadowService->compareAfterOperation($transferId, 'create');
 *
 *   // Batch comparison for scheduled runs:
 *   $results = $shadowService->batchCompare($fromDate, $toDate);
 *
 *   // Cutover readiness check:
 *   $ready = $shadowService->checkCutoverReadiness();
 */
class WarehouseTransferShadowService
{
    /**
     * Whether shadow mode is enabled and in a comparison-ready state.
     */
    private bool $isEnabled;
    private string $mode;

    /**
     * Tolerance thresholds from config.
     */
    private float $toleranceQty;
    private float $toleranceRate;
    private float $toleranceAmount;

    /**
     * Comparison scope flags.
     */
    private array $scope;

    public function __construct()
    {
        $this->isEnabled      = config('shadow_mode.enabled', false);
        $this->mode           = config('shadow_mode.mode', 'off');
        $this->toleranceQty   = config('shadow_mode.cutover.max_tolerance_qty', 0.0001);
        $this->toleranceRate  = config('shadow_mode.cutover.max_tolerance_rate', 0.01);
        $this->toleranceAmount = config('shadow_mode.cutover.max_tolerance_amount', 0.01);
        $this->scope          = config('shadow_mode.comparison_scope', [
            'stock_movements'  => true,
            'gl_postings'      => true,
            'status'           => true,
            'avg_cost'         => true,
            'reversal_order'   => true,
        ]);
    }

    // ========================================================================
    // Public API — Single Transfer Comparison
    // ========================================================================

    /**
     * Compare a single Laravel transfer against its legacy equivalent
     * after a specific operation (create/confirm/cancel).
     *
     * This is the primary entry point for auto-comparison after each
     * transfer operation in passive/active mode.
     *
     * @param int    $transferId  Laravel warehouse_transfers.id
     * @param string $operation   'create', 'confirm', or 'cancel'
     * @return array Comparison result array
     */
    public function compareAfterOperation(int $transferId, string $operation): array
    {
        if (!$this->isEnabled || $this->mode === 'off') {
            return ['skipped' => true, 'reason' => 'shadow_mode_off'];
        }

        $transfer = WarehouseTransfer::with(['fromWarehouse.branch', 'toWarehouse.branch', 'items'])
            ->find($transferId);

        if (!$transfer) {
            return ['skipped' => true, 'reason' => 'transfer_not_found'];
        }

        $legacyTransfer = $this->findLegacyTransfer($transfer);
        $diffDetails = [];
        $totalChecks = 0;
        $matchCount = 0;
        $diffCount = 0;

        // 1. Status comparison
        if ($this->scope['status']) {
            $totalChecks++;
            $statusResult = $this->compareStatus($transfer, $legacyTransfer, $operation);
            $diffDetails['status'] = $statusResult;
            if ($statusResult['match']) {
                $matchCount++;
            } else {
                $diffCount++;
            }
        }

        // 2. Stock movements comparison
        if ($this->scope['stock_movements']) {
            $totalChecks++;
            $stockResult = $this->compareStockMovements($transfer, $legacyTransfer);
            $diffDetails['stock_movements'] = $stockResult;
            if ($stockResult['status'] === 'match') {
                $matchCount++;
            } else {
                $diffCount++;
            }
        }

        // 3. GL postings comparison
        if ($this->scope['gl_postings']) {
            $totalChecks++;
            $glResult = $this->compareGlPostings($transfer, $legacyTransfer);
            $diffDetails['gl_postings'] = $glResult;
            if ($glResult['status'] === 'match') {
                $matchCount++;
            } else {
                $diffCount++;
            }
        }

        // 4. Avg cost comparison (after confirm)
        if ($this->scope['avg_cost'] && $operation === 'confirm') {
            $totalChecks++;
            $avgCostResult = $this->compareAvgCost($transfer, $legacyTransfer);
            $diffDetails['avg_cost'] = $avgCostResult;
            if ($avgCostResult['status'] === 'match') {
                $matchCount++;
            } else {
                $diffCount++;
            }
        }

        // 5. Reversal order comparison (after cancel of confirmed transfer)
        if ($this->scope['reversal_order'] && $operation === 'cancel' && $transfer->is_reversed) {
            $totalChecks++;
            $reversalResult = $this->compareReversalOrder($transfer, $legacyTransfer);
            $diffDetails['reversal_order'] = $reversalResult;
            if ($reversalResult['status'] === 'match') {
                $matchCount++;
            } else {
                $diffCount++;
            }
        }

        // Determine overall diff status.
        $diffStatus = $this->determineDiffStatus($legacyTransfer, $diffCount, $totalChecks);

        // Store the comparison result.
        $comparisonId = $this->storeComparison(
            $transfer,
            $legacyTransfer,
            $operation,
            $diffStatus,
            $diffDetails,
            $totalChecks,
            $matchCount,
            $diffCount
        );

        // Log diff if found.
        if ($diffStatus !== 'match') {
            $this->logDiff($comparisonId, $transfer, $diffStatus, $diffDetails);
        }

        return [
            'comparison_id'    => $comparisonId,
            'transfer_id'      => $transferId,
            'transfer_code'    => $transfer->transfer_code,
            'operation'        => $operation,
            'diff_status'      => $diffStatus,
            'total_checks'     => $totalChecks,
            'match_count'      => $matchCount,
            'diff_count'       => $diffCount,
            'diff_details'     => $diffDetails,
            'mode'             => $this->mode,
        ];
    }

    // ========================================================================
    // Public API — Batch Comparison
    // ========================================================================

    /**
     * Batch compare transfers within a date range.
     *
     * Used for scheduled/daily comparison runs. Compares all transfers
     * in the given date range that haven't been compared yet (or
     * re-compares if --force flag is used).
     *
     * @param string|null $fromDate  Start date (YYYY-MM-DD). Default: yesterday.
     * @param string|null $toDate    End date (YYYY-MM-DD). Default: today.
     * @param bool        $force     Re-compare even if already compared.
     * @return array Summary of batch results
     */
    public function batchCompare(?string $fromDate = null, ?string $toDate = null, bool $force = false): array
    {
        if (!$this->isEnabled) {
            return ['skipped' => true, 'reason' => 'shadow_mode_off'];
        }

        $from = $fromDate ?? now()->subDay()->format('Y-m-d');
        $to   = $toDate ?? now()->format('Y-m-d');

        $query = WarehouseTransfer::whereBetween('transfer_date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderBy('id');

        // Skip already-compared transfers unless force.
        if (!$force) {
            $alreadyCompared = DB::table('shadow_transfer_comparisons')
                ->whereBetween('compared_at', [
                    now()->parse($from)->startOfDay(),
                    now()->parse($to)->endOfDay(),
                ])
                ->pluck('laravel_transfer_id')
                ->toArray();

            $query->whereNotIn('id', $alreadyCompared);
        }

        $transfers = $query->get();
        $results = [];
        $matchCount = 0;
        $diffCount = 0;
        $missingLegacy = 0;
        $errorCount = 0;

        foreach ($transfers as $transfer) {
            // Determine the operation to compare.
            $operation = $this->determineOperationForComparison($transfer);

            try {
                $result = $this->compareAfterOperation($transfer->id, $operation);
                $results[] = $result;

                if ($result['skipped']) {
                    continue;
                }

                switch ($result['diff_status']) {
                    case 'match':
                        $matchCount++;
                        break;
                    case 'missing_legacy':
                        $missingLegacy++;
                        break;
                    case 'diff':
                        $diffCount++;
                        break;
                    default:
                        $errorCount++;
                        break;
                }
            } catch (\Throwable $e) {
                $errorCount++;
                Log::error('Shadow mode comparison error', [
                    'transfer_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'from_date'        => $from,
            'to_date'          => $to,
            'total_compared'   => count($results),
            'match_count'      => $matchCount,
            'diff_count'       => $diffCount,
            'missing_legacy'   => $missingLegacy,
            'error_count'      => $errorCount,
            'results'          => $results,
        ];
    }

    // ========================================================================
    // Public API — Cutover Readiness
    // ========================================================================

    /**
     * Check cutover readiness based on consecutive zero-diff days.
     *
     * Counts the number of consecutive days (ending today) where all
     * comparisons had zero diffs. If this count meets or exceeds the
     * threshold (default: 7), cutover is ready.
     *
     * @return array Cutover readiness report
     */
    public function checkCutoverReadiness(): array
    {
        $threshold = config('shadow_mode.cutover.consecutive_days_zero_diff', 7);

        // Get the last N days of cutover log entries.
        $recentDays = DB::table('shadow_cutover_log')
            ->orderByDesc('check_date')
            ->limit($threshold + 1)
            ->get();

        // Calculate consecutive clean days from today backward.
        $consecutiveCleanDays = 0;
        $today = now()->format('Y-m-d');

        for ($i = 0; $i < $threshold; $i++) {
            $checkDate = now()->subDays($i)->format('Y-m-d');
            $dayLog = $recentDays->firstWhere('check_date', $checkDate);

            if (!$dayLog) {
                // No log for this day — not yet checked or no comparisons.
                break;
            }

            if ($dayLog->is_clean_day) {
                $consecutiveCleanDays++;
            } else {
                break;
            }
        }

        $cutoverReady = $consecutiveCleanDays >= $threshold;

        return [
            'threshold'                => $threshold,
            'consecutive_clean_days'   => $consecutiveCleanDays,
            'cutover_ready'            => $cutoverReady,
            'remaining_days'           => max(0, $threshold - $consecutiveCleanDays),
            'last_checked_date'        => $today,
            'recent_day_logs'          => $recentDays,
        ];
    }

    /**
     * Record a daily cutover log entry for today's comparison results.
     *
     * Called after a batch comparison or scheduled run to update the
     * cutover tracking.
     *
     * @param array $batchResult  Result from batchCompare()
     * @param int|null $checkedBy  User ID who triggered the check
     */
    public function recordCutoverDailyLog(array $batchResult, ?int $checkedBy = null): void
    {
        $today = now()->format('Y-m-d');
        $isCleanDay = ($batchResult['diff_count'] === 0 && $batchResult['error_count'] === 0);

        // Calculate consecutive clean days.
        $previousLog = DB::table('shadow_cutover_log')
            ->where('check_date', now()->subDay()->format('Y-m-d'))
            ->first();

        $consecutiveCleanDays = $isCleanDay
            ? ($previousLog ? ($previousLog->is_clean_day ? $previousLog->consecutive_clean_days + 1 : 1) : 1)
            : 0;

        $threshold = config('shadow_mode.cutover.consecutive_days_zero_diff', 7);

        DB::table('shadow_cutover_log')->updateOrInsert(
            ['check_date' => $today],
            [
                'comparisons_total'      => $batchResult['total_compared'] ?? 0,
                'comparisons_match'      => $batchResult['match_count'] ?? 0,
                'comparisons_diff'       => $batchResult['diff_count'] ?? 0,
                'comparisons_missing_legacy' => $batchResult['missing_legacy'] ?? 0,
                'comparisons_error'      => $batchResult['error_count'] ?? 0,
                'is_clean_day'           => $isCleanDay,
                'consecutive_clean_days' => $consecutiveCleanDays,
                'cutover_ready'          => $consecutiveCleanDays >= $threshold,
                'checked_by'             => $checkedBy,
                'checked_at'             => now(),
                'updated_at'             => now(),
            ]
        );
    }

    // ========================================================================
    // Comparison Methods — Each compares one aspect
    // ========================================================================

    /**
     * Compare transfer status between Laravel and legacy.
     */
    private function compareStatus(?WarehouseTransfer $laravel, ?object $legacy, string $operation): array
    {
        if (!$legacy) {
            return [
                'match'   => false,
                'status'  => 'missing_legacy',
                'laravel' => $laravel->status,
                'legacy'  => null,
                'detail'  => 'No matching legacy transfer found.',
            ];
        }

        $laravelStatus = $laravel->status;
        $legacyStatus  = $this->mapLegacyStatus($legacy->status ?? '');

        $match = $laravelStatus === $legacyStatus;

        return [
            'match'   => $match,
            'status'  => $match ? 'match' : 'diff',
            'laravel' => $laravelStatus,
            'legacy'  => $legacyStatus,
            'detail'  => $match ? 'Status identical.' : "Status differs: Laravel={$laravelStatus}, Legacy={$legacyStatus}",
        ];
    }

    /**
     * Compare stock movements between Laravel and legacy.
     *
     * For a confirmed transfer, Laravel should have:
     *   - Source OUT: qty=-itemQty, rate=avg_cost (reference_type=warehouse_transfer)
     *   - Dest IN:   qty=+itemQty, rate=avg_cost
     *
     * Legacy should have equivalent stock_transactions.
     * We compare per-product: qty, rate, warehouse_id.
     */
    private function compareStockMovements(?WarehouseTransfer $laravel, ?object $legacy): array
    {
        // Get Laravel stock movements.
        $laravelMovements = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $laravel->id)
            ->where('is_reversed', false)
            ->get();

        $laravelItems = [];
        foreach ($laravelMovements as $mov) {
            $laravelItems[] = [
                'product_id'    => $mov->product_id,
                'warehouse_id'  => $mov->warehouse_id,
                'qty'           => (float) $mov->qty,
                'rate'          => (float) $mov->rate,
                'direction'     => $mov->qty > 0 ? 'IN' : 'OUT',
            ];
        }

        if (!$legacy) {
            return [
                'status'   => 'missing_legacy',
                'laravel'  => $laravelItems,
                'legacy'   => null,
                'details'  => 'No legacy transfer found for stock comparison.',
                'diffs'    => [],
            ];
        }

        // Get legacy stock movements from archive connection.
        $legacyMovements = $this->getLegacyStockMovements($legacy->id ?? 0);

        $legacyItems = [];
        foreach ($legacyMovements as $mov) {
            $legacyItems[] = [
                'product_id'    => (int) ($mov->product_id ?? 0),
                'warehouse_id'  => (int) ($mov->warehouse_id ?? 0),
                'qty'           => (float) ($mov->qty ?? 0),
                'rate'          => (float) ($mov->rate ?? 0),
                'direction'     => ((float) ($mov->qty ?? 0)) > 0 ? 'IN' : 'OUT',
            ];
        }

        // Compare per product.
        $diffs = [];
        $allMatch = true;

        // Group by product_id for comparison.
        $laravelByProduct = [];
        foreach ($laravelItems as $item) {
            $key = "{$item['product_id']}:{$item['direction']}";
            $laravelByProduct[$key] = $item;
        }

        $legacyByProduct = [];
        foreach ($legacyItems as $item) {
            $key = "{$item['product_id']}:{$item['direction']}";
            $legacyByProduct[$key] = $item;
        }

        // Check all Laravel movements have legacy equivalents.
        foreach ($laravelByProduct as $key => $laravelItem) {
            if (!isset($legacyByProduct[$key])) {
                $allMatch = false;
                $diffs[] = [
                    'type'       => 'missing_in_legacy',
                    'product_id' => $laravelItem['product_id'],
                    'direction'  => $laravelItem['direction'],
                    'laravel_qty' => $laravelItem['qty'],
                    'laravel_rate' => $laravelItem['rate'],
                    'legacy_qty'  => null,
                    'legacy_rate' => null,
                ];
                continue;
            }

            $legacyItem = $legacyByProduct[$key];
            $qtyDiff = abs($laravelItem['qty'] - $legacyItem['qty']);
            $rateDiff = abs($laravelItem['rate'] - $legacyItem['rate']);

            if ($qtyDiff > $this->toleranceQty || $rateDiff > $this->toleranceRate) {
                $allMatch = false;
                $diffs[] = [
                    'type'        => 'value_diff',
                    'product_id'  => $laravelItem['product_id'],
                    'direction'   => $laravelItem['direction'],
                    'laravel_qty' => $laravelItem['qty'],
                    'legacy_qty'  => $legacyItem['qty'],
                    'qty_diff'    => $qtyDiff,
                    'laravel_rate' => $laravelItem['rate'],
                    'legacy_rate'  => $legacyItem['rate'],
                    'rate_diff'   => $rateDiff,
                ];
            }
        }

        // Check for extra legacy movements not in Laravel.
        foreach ($legacyByProduct as $key => $legacyItem) {
            if (!isset($laravelByProduct[$key])) {
                $allMatch = false;
                $diffs[] = [
                    'type'       => 'missing_in_laravel',
                    'product_id' => $legacyItem['product_id'],
                    'direction'  => $legacyItem['direction'],
                    'legacy_qty'  => $legacyItem['qty'],
                    'legacy_rate' => $legacyItem['rate'],
                    'laravel_qty' => null,
                    'laravel_rate' => null,
                ];
            }
        }

        return [
            'status'   => $allMatch ? 'match' : 'diff',
            'laravel'  => $laravelItems,
            'legacy'   => $legacyItems,
            'details'  => $allMatch ? 'All stock movements match.' : 'Stock movement diffs found.',
            'diffs'    => $diffs,
        ];
    }

    /**
     * Compare GL postings between Laravel and legacy.
     *
     * For SAME-BRANCH transfers (which this module enforces), both
     * systems should have NO GL journal entries. This is a correctness
     * check: if either system posts GL for a same-branch transfer,
     * that's a bug.
     */
    private function compareGlPostings(?WarehouseTransfer $laravel, ?object $legacy): array
    {
        // Same-branch transfers should NOT have GL.
        $laravelHasGl = !empty($laravel->journal_entry_id) || !empty($laravel->journal_entry_id_debtor);
        $laravelGlDetails = [];

        if ($laravelHasGl) {
            // Unexpected: same-branch transfer has GL. Log it.
            $laravelGlDetails = [
                'journal_entry_id'          => $laravel->journal_entry_id,
                'journal_entry_id_debtor'   => $laravel->journal_entry_id_debtor,
                'note'                      => 'UNEXPECTED: Same-branch transfer should have no GL.',
            ];
        }

        if (!$legacy) {
            return [
                'status'   => 'missing_legacy',
                'laravel'  => $laravelHasGl ? 'has_gl (unexpected)' : 'no_gl (correct)',
                'legacy'   => null,
                'details'  => 'No legacy transfer found for GL comparison.',
            ];
        }

        // Check legacy GL entries.
        $legacyHasGl = !empty($legacy->journal_entry_id) || !empty($legacy->journal_entry_id_debtor);

        // For same-branch: both should have NO GL.
        // For cross-branch (demand-linked): both should have GL.
        $isSameBranch = (int) $laravel->from_branch_id === (int) $laravel->to_branch_id;

        if ($isSameBranch) {
            $match = !$laravelHasGl && !$legacyHasGl;
            return [
                'status'   => $match ? 'match' : 'diff',
                'laravel'  => $laravelHasGl ? 'has_gl (BUG)' : 'no_gl (correct)',
                'legacy'   => $legacyHasGl ? 'has_gl' : 'no_gl',
                'details'  => $match
                    ? 'Same-branch: no GL on either system (correct).'
                    : 'Same-branch transfer has GL on one or both systems (BUG).',
                'laravel_details' => $laravelGlDetails,
            ];
        }

        // Cross-branch (demand-linked): both should have GL.
        $match = $laravelHasGl && $legacyHasGl;
        return [
            'status'   => $match ? 'match' : 'diff',
            'laravel'  => $laravelHasGl ? 'has_gl' : 'no_gl (unexpected for demand-linked)',
            'legacy'   => $legacyHasGl ? 'has_gl' : 'no_gl',
            'details'  => $match
                ? 'Demand-linked: GL present on both systems.'
                : 'Demand-linked transfer: GL mismatch.',
        ];
    }

    /**
     * Compare avg_cost at source and dest warehouses after a confirm.
     *
     * After a confirmed transfer, the destination warehouse's avg_cost
     * should be recalculated using the moving average formula:
     *   new_avg = (old_qty * old_avg + new_qty * rate) / (old_qty + new_qty)
     *
     * The source warehouse's avg_cost should be unchanged (OUT doesn't
     * affect avg_cost).
     */
    private function compareAvgCost(?WarehouseTransfer $laravel, ?object $legacy): array
    {
        if (!$laravel->isConfirmed()) {
            return [
                'status'  => 'match',
                'details' => 'Draft/cancelled transfer — no avg_cost changes to compare.',
            ];
        }

        $diffs = [];

        // Check each product's avg_cost at both warehouses.
        foreach ($laravel->items as $item) {
            $sourceAvg = DB::table('warehouse_stock')
                ->where('warehouse_id', $laravel->from_warehouse_id)
                ->where('product_id', $item->product_id)
                ->value('avg_cost');

            $destAvg = DB::table('warehouse_stock')
                ->where('warehouse_id', $laravel->to_warehouse_id)
                ->where('product_id', $item->product_id)
                ->value('avg_cost');

            if (!$legacy) {
                $diffs[] = [
                    'product_id'     => $item->product_id,
                    'source_avg'     => (float) $sourceAvg,
                    'dest_avg'       => (float) $destAvg,
                    'legacy_source_avg' => null,
                    'legacy_dest_avg'   => null,
                    'note'           => 'No legacy data for avg_cost comparison.',
                ];
                continue;
            }

            // Get legacy avg_cost from archive.
            $legacySourceAvg = $this->getLegacyAvgCost(
                $laravel->from_warehouse_id, $item->product_id
            );
            $legacyDestAvg = $this->getLegacyAvgCost(
                $laravel->to_warehouse_id, $item->product_id
            );

            $sourceDiff = abs((float) $sourceAvg - (float) $legacySourceAvg);
            $destDiff   = abs((float) $destAvg - (float) $legacyDestAvg);

            if ($sourceDiff > $this->toleranceRate || $destDiff > $this->toleranceRate) {
                $diffs[] = [
                    'product_id'        => $item->product_id,
                    'source_avg'        => (float) $sourceAvg,
                    'legacy_source_avg' => (float) $legacySourceAvg,
                    'source_diff'       => $sourceDiff,
                    'dest_avg'          => (float) $destAvg,
                    'legacy_dest_avg'   => (float) $legacyDestAvg,
                    'dest_diff'         => $destDiff,
                ];
            }
        }

        return [
            'status'  => empty($diffs) ? 'match' : ($legacy ? 'diff' : 'missing_legacy'),
            'details' => empty($diffs) ? 'All avg_costs match.' : 'Avg cost diffs found.',
            'diffs'   => $diffs,
        ];
    }

    /**
     * Compare reversal ordering between Laravel and legacy.
     *
     * Laravel Phase 3 ensures dest IN (positive qty) movements are
     * reversed FIRST, then source OUT (negative qty). The legacy
     * sortMovementsForReversal() does the same.
     *
     * We verify the order by checking the timestamps/IDs of the
     * reversal stock_transactions.
     */
    private function compareReversalOrder(?WarehouseTransfer $laravel, ?object $legacy): array
    {
        // Get Laravel reversal transactions.
        $reversalTx = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $laravel->id)
            ->where('is_reversed', true)
            ->orderBy('id')
            ->get();

        if ($reversalTx->isEmpty()) {
            return [
                'status'  => 'match',
                'details' => 'No reversal transactions found (may not have been reversed yet).',
            ];
        }

        // Verify ordering: positive qty (dest IN reversal) should have
        // lower IDs (reversed first).
        $orderCorrect = true;
        $lastPositiveId = 0;
        $firstNegativeId = PHP_INT_MAX;

        foreach ($reversalTx as $tx) {
            $qty = (float) $tx->qty;
            if ($qty > 0) {
                $lastPositiveId = max($lastPositiveId, $tx->id);
            } else {
                $firstNegativeId = min($firstNegativeId, $tx->id);
            }
        }

        // Positive (dest IN) should be reversed before negative (source OUT).
        // So positive reversal IDs should be lower than negative IDs.
        if ($lastPositiveId > 0 && $firstNegativeId < PHP_INT_MAX) {
            $orderCorrect = $lastPositiveId < $firstNegativeId;
        }

        return [
            'status'       => $orderCorrect ? 'match' : 'diff',
            'details'      => $orderCorrect
                ? 'Reversal order correct: dest IN reversed before source OUT.'
                : 'Reversal order INCORRECT: source OUT reversed before dest IN.',
            'order_correct' => $orderCorrect,
            'last_positive_id'  => $lastPositiveId,
            'first_negative_id' => $firstNegativeId,
        ];
    }

    // ========================================================================
    // Legacy Data Access (via Archive Layer)
    // ========================================================================

    /**
     * Find the legacy transfer that corresponds to a Laravel transfer.
     *
     * Uses the transfer_code as the matching key (both systems generate
     * the same format: WT-YYYYMMDD-NNNN).
     *
     * @param WarehouseTransfer $laravel
     * @return object|null  Legacy transfer row or null if not found.
     */
    private function findLegacyTransfer(WarehouseTransfer $laravel): ?object
    {
        $connection = config('shadow_mode.legacy_connection', 'archive');

        try {
            $legacy = DB::connection($connection)
                ->table('warehouse_transfers')
                ->where('transfer_code', $laravel->transfer_code)
                ->first();

            return $legacy;
        } catch (\Throwable $e) {
            Log::warning('Shadow mode: cannot connect to legacy database', [
                'transfer_code' => $laravel->transfer_code,
                'error'         => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get legacy stock movements for a transfer.
     *
     * @param int $legacyTransferId
     * @return \Illuminate\Support\Collection
     */
    private function getLegacyStockMovements(int $legacyTransferId): \Illuminate\Support\Collection
    {
        $connection = config('shadow_mode.legacy_connection', 'archive');

        try {
            return DB::connection($connection)
                ->table('stock_transactions')
                ->where('reference_type', 'warehouse_transfer')
                ->where('reference_id', $legacyTransferId)
                ->where('is_reversed', 0)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Shadow mode: cannot read legacy stock_transactions', [
                'legacy_transfer_id' => $legacyTransferId,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Get legacy warehouse avg_cost for a product at a warehouse.
     *
     * @param int $warehouseId
     * @param int $productId
     * @return float|null
     */
    private function getLegacyAvgCost(int $warehouseId, int $productId): ?float
    {
        $connection = config('shadow_mode.legacy_connection', 'archive');

        try {
            $result = DB::connection($connection)
                ->table('warehouse_stock')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            return $result ? (float) $result->avg_cost : null;
        } catch (\Throwable $e) {
            Log::warning('Shadow mode: cannot read legacy warehouse_stock', [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'error'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Map legacy transfer status to Laravel equivalent.
     *
     * Legacy uses: 'transferred', 'received', 'reversed'
     * Laravel uses: 'draft', 'confirmed', 'cancelled'
     *
     * Mapping:
     *   transferred → confirmed (stock has moved)
     *   received    → confirmed (demand-linked, received)
     *   reversed    → cancelled (cancelled with reversal)
     */
    private function mapLegacyStatus(string $legacyStatus): string
    {
        return match ($legacyStatus) {
            'transferred' => 'confirmed',
            'received'    => 'confirmed',
            'reversed'    => 'cancelled',
            default       => $legacyStatus,
        };
    }

    /**
     * Determine the operation to compare for a transfer based on its
     * current state.
     */
    private function determineOperationForComparison(WarehouseTransfer $transfer): string
    {
        if ($transfer->is_reversed) {
            return 'cancel';
        }
        if ($transfer->isConfirmed()) {
            return 'confirm';
        }
        return 'create';
    }

    /**
     * Determine overall diff status from comparison results.
     */
    private function determineDiffStatus(?object $legacy, int $diffCount, int $totalChecks): string
    {
        if (!$legacy) {
            return 'missing_legacy';
        }

        if ($diffCount === 0 && $totalChecks > 0) {
            return 'match';
        }

        if ($diffCount > 0) {
            return 'diff';
        }

        return 'error';
    }

    /**
     * Store the comparison result in the shadow_transfer_comparisons table.
     */
    private function storeComparison(
        WarehouseTransfer $transfer,
        ?object $legacy,
        string $operation,
        string $diffStatus,
        array $diffDetails,
        int $totalChecks,
        int $matchCount,
        int $diffCount
    ): int {
        return DB::table('shadow_transfer_comparisons')->insertGetId([
            'laravel_transfer_id'    => $transfer->id,
            'laravel_transfer_code'  => $transfer->transfer_code,
            'legacy_transfer_id'     => $legacy ? ($legacy->id ?? null) : null,
            'legacy_transfer_code'   => $legacy ? ($legacy->transfer_code ?? null) : null,
            'operation'              => $operation,
            'mode'                   => $this->mode,
            'diff_status'            => $diffStatus,
            'diff_details'           => json_encode($diffDetails),
            'total_checks'           => $totalChecks,
            'match_count'            => $matchCount,
            'diff_count'             => $diffCount,
            'branch_id'              => $transfer->from_branch_id,
            'compared_at'            => now(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * Log a diff to the configured log channel and optionally send email.
     */
    private function logDiff(int $comparisonId, WarehouseTransfer $transfer, string $diffStatus, array $diffDetails): void
    {
        $logChannel = config('shadow_mode.alerts.log_channel', 'shadow');

        Log::channel($logChannel)->warning('Shadow mode diff detected', [
            'comparison_id'    => $comparisonId,
            'transfer_id'      => $transfer->id,
            'transfer_code'    => $transfer->transfer_code,
            'diff_status'      => $diffStatus,
            'diff_details'     => $diffDetails,
            'mode'             => $this->mode,
        ]);

        // Check if critical diff — immediate escalation.
        if (config('shadow_mode.alerts.notify_on_critical', true)) {
            $isCritical = $this->isCriticalDiff($diffDetails);
            if ($isCritical) {
                Log::channel($logChannel)->error('CRITICAL shadow mode diff — immediate investigation required', [
                    'comparison_id' => $comparisonId,
                    'transfer_code' => $transfer->transfer_code,
                ]);

                // Email notification (if configured).
                $notifyEmail = config('shadow_mode.alerts.notify_email');
                if ($notifyEmail) {
                    // Email sending would be handled by a notification class
                    // or event listener. This is a placeholder for the
                    // notification trigger.
                    Log::channel($logChannel)->info('Critical diff notification would be sent to: ' . $notifyEmail);
                }
            }
        }
    }

    /**
     * Determine if a diff is critical (requires immediate attention).
     *
     * Critical diffs:
     *   - Stock qty mismatch > tolerance
     *   - GL posting on a same-branch transfer (BUG)
     *   - Reversal order wrong
     */
    private function isCriticalDiff(array $diffDetails): bool
    {
        // GL posting on same-branch transfer is critical.
        if (isset($diffDetails['gl_postings']) && $diffDetails['gl_postings']['status'] === 'diff') {
            return true;
        }

        // Reversal order wrong is critical.
        if (isset($diffDetails['reversal_order']) && $diffDetails['reversal_order']['status'] === 'diff') {
            return true;
        }

        // Stock movement qty/rate diff is critical.
        if (isset($diffDetails['stock_movements']) && $diffDetails['stock_movements']['status'] === 'diff') {
            return true;
        }

        return false;
    }

    // ========================================================================
    // Dashboard Data Methods
    // ========================================================================

    /**
     * Get recent comparison results for the dashboard.
     *
     * @param int $limit
     * @param string|null $statusFilter  'match', 'diff', 'missing_legacy', 'error', or null for all
     * @return \Illuminate\Support\Collection
     */
    public function getRecentComparisons(int $limit = 50, ?string $statusFilter = null): \Illuminate\Support\Collection
    {
        $query = DB::table('shadow_transfer_comparisons')
            ->orderByDesc('compared_at')
            ->limit($limit);

        if ($statusFilter) {
            $query->where('diff_status', $statusFilter);
        }

        return $query->get();
    }

    /**
     * Get comparison summary stats for a date range.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public function getComparisonSummary(?string $fromDate = null, ?string $toDate = null): array
    {
        $from = $fromDate ?? now()->subDays(7)->format('Y-m-d');
        $to   = $toDate ?? now()->format('Y-m-d');

        $comparisons = DB::table('shadow_transfer_comparisons')
            ->whereBetween('compared_at', [
                now()->parse($from)->startOfDay(),
                now()->parse($to)->endOfDay(),
            ])
            ->get();

        return [
            'from_date'        => $from,
            'to_date'          => $to,
            'total'            => $comparisons->count(),
            'match'            => $comparisons->where('diff_status', 'match')->count(),
            'diff'             => $comparisons->where('diff_status', 'diff')->count(),
            'missing_legacy'   => $comparisons->where('diff_status', 'missing_legacy')->count(),
            'error'            => $comparisons->where('diff_status', 'error')->count(),
            'by_operation'     => [
                'create'  => $comparisons->where('operation', 'create')->count(),
                'confirm' => $comparisons->where('operation', 'confirm')->count(),
                'cancel'  => $comparisons->where('operation', 'cancel')->count(),
            ],
            'by_branch'        => $comparisons->groupBy('branch_id')->map->count()->toArray(),
        ];
    }

    /**
     * Purge old comparison records based on retention settings.
     *
     * @return int Number of records purged
     */
    public function purgeOldRecords(): int
    {
        $comparisonRetention = config('shadow_mode.retention.comparison_retention_days', 90);
        $zeroDiffRetention   = config('shadow_mode.retention.zero_diff_retention_days', 30);

        // Purge zero-diff records older than zero_diff_retention_days.
        $purgedZero = DB::table('shadow_transfer_comparisons')
            ->where('diff_status', 'match')
            ->where('compared_at', '<', now()->subDays($zeroDiffRetention))
            ->delete();

        // Purge all records older than comparison_retention_days.
        $purgedAll = DB::table('shadow_transfer_comparisons')
            ->where('compared_at', '<', now()->subDays($comparisonRetention))
            ->delete();

        // Purge old cutover log records (keep last 30 days).
        DB::table('shadow_cutover_log')
            ->where('check_date', '<', now()->subDays(30)->format('Y-m-d'))
            ->delete();

        return $purgedZero + $purgedAll;
    }
}
