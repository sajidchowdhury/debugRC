<?php

namespace App\Console\Commands;

use App\Services\Stock\WarehouseTransferShadowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shadow Mode: Transfer Comparison Command — Phase 7.3.
 *
 * Runs comparison between Laravel and legacy WarehouseTransfer data.
 * Can be invoked manually or via scheduled job.
 *
 * Modes of operation:
 *
 *   1. Batch comparison — compare all transfers in a date range:
 *      php artisan shadow:compare-transfers
 *      php artisan shadow:compare-transfers --from=2025-07-20 --to=2025-07-28
 *      php artisan shadow:compare-transfers --force  (re-compare already compared)
 *
 *   2. Single transfer comparison:
 *      php artisan shadow:compare-transfers --transfer=42
 *      php artisan shadow:compare-transfers --transfer=42 --operation=confirm
 *
 *   3. Cutover readiness check:
 *      php artisan shadow:compare-transfers --cutover
 *
 *   4. Purge old records:
 *      php artisan shadow:compare-transfers --purge
 *
 * Exit codes:
 *   0 = all comparisons match (or shadow mode off)
 *   1 = diffs detected or errors
 */
class ShadowCompareTransfers extends Command
{
    protected $signature = 'shadow:compare-transfers
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD)}
                            {--transfer= : Single transfer ID to compare}
                            {--operation= : Operation for single comparison (create/confirm/cancel)}
                            {--force : Re-compare already compared transfers}
                            {--cutover : Check cutover readiness only}
                            {--purge : Purge old comparison records}
                            {--batch-size=100 : Batch size for batch comparison}';

    protected $description = 'Shadow mode: compare Laravel vs legacy WarehouseTransfer data';

    public function handle(WarehouseTransferShadowService $shadowService): int
    {
        $this->info('=== Shadow Mode: Warehouse Transfer Comparison (Phase 7.3) ===');
        $this->newLine();

        // Check if shadow mode is enabled.
        if (!config('shadow_mode.enabled', false)) {
            $this->warn('Shadow mode is DISABLED. Set SHADOW_MODE_ENABLED=true in .env to enable.');
            $this->info('Configuration: config/shadow_mode.php');
            $this->info('Current mode: ' . config('shadow_mode.mode', 'off'));
            $this->newLine();

            if (!$this->option('cutover') && !$this->option('purge')) {
                $this->info('To run comparisons with shadow mode off (for testing), use --force flag.');
                if (!$this->option('force')) {
                    return self::SUCCESS;
                }
            }
        }

        $mode = config('shadow_mode.mode', 'off');
        $this->info("Shadow mode state: {$mode}");

        // ============================================================
        // Purge mode
        // ============================================================
        if ($this->option('purge')) {
            $this->info('Purging old comparison records...');
            $purged = $shadowService->purgeOldRecords();
            $this->info("Purged {$purged} old records.");
            return self::SUCCESS;
        }

        // ============================================================
        // Cutover readiness check
        // ============================================================
        if ($this->option('cutover')) {
            $this->info('Checking cutover readiness...');
            $this->newLine();

            $readiness = $shadowService->checkCutoverReadiness();

            $this->info("Threshold: {$readiness['threshold']} consecutive zero-diff days required.");
            $this->info("Consecutive clean days: {$readiness['consecutive_clean_days']}");
            $this->info("Remaining days: {$readiness['remaining_days']}");

            if ($readiness['cutover_ready']) {
                $this->newLine();
                $this->info('✓ CUTOVER IS READY — sufficient consecutive zero-diff days.');
            } else {
                $this->newLine();
                $this->warn("✗ CUTOVER NOT YET READY — need {$readiness['remaining_days']} more clean days.");
            }

            // Show recent day logs.
            if (!empty($readiness['recent_day_logs'])) {
                $this->newLine();
                $this->info('Recent daily logs:');
                $this->table(
                    ['Date', 'Total', 'Match', 'Diff', 'Missing', 'Error', 'Clean?', 'Consecutive'],
                    $readiness['recent_day_logs']->map(function ($log) {
                        return [
                            $log->check_date,
                            $log->comparisons_total,
                            $log->comparisons_match,
                            $log->comparisons_diff,
                            $log->comparisons_missing_legacy,
                            $log->comparisons_error,
                            $log->is_clean_day ? '✓' : '✗',
                            $log->consecutive_clean_days,
                        ];
                    })
                );
            }

            return $readiness['cutover_ready'] ? self::SUCCESS : self::FAILURE;
        }

        // ============================================================
        // Single transfer comparison
        // ============================================================
        if ($this->option('transfer')) {
            $transferId = (int) $this->option('transfer');
            $operation  = $this->option('operation') ?? 'create';

            $this->info("Comparing single transfer: #{$transferId} (operation: {$operation})");

            $result = $shadowService->compareAfterOperation($transferId, $operation);

            if ($result['skipped']) {
                $this->warn("Comparison skipped: {$result['reason']}");
                return self::SUCCESS;
            }

            $this->displayComparisonResult($result);

            return $result['diff_status'] === 'match' ? self::SUCCESS : self::FAILURE;
        }

        // ============================================================
        // Batch comparison
        // ============================================================
        $from = $this->option('from') ?? now()->subDay()->format('Y-m-d');
        $to   = $this->option('to') ?? now()->format('Y-m-d');
        $force = $this->option('force');

        $this->info("Running batch comparison: {$from} to {$to}");
        if ($force) {
            $this->warn('Force mode: re-comparing already compared transfers.');
        }
        $this->newLine();

        $batchResult = $shadowService->batchCompare($from, $to, $force);

        if ($batchResult['skipped']) {
            $this->warn("Batch comparison skipped: {$batchResult['reason']}");
            return self::SUCCESS;
        }

        $this->info("=== Batch Comparison Summary ===");
        $this->info("Date range: {$from} → {$to}");
        $this->info("Total compared: {$batchResult['total_compared']}");
        $this->info("Match: {$batchResult['match_count']}");
        $this->warn("Diff: {$batchResult['diff_count']}");
        $this->warn("Missing legacy: {$batchResult['missing_legacy']}");
        $this->error("Error: {$batchResult['error_count']}");

        // Record daily cutover log.
        $shadowService->recordCutoverDailyLog($batchResult);

        $this->newLine();

        // Show individual diff results.
        $diffResults = array_filter($batchResult['results'], function ($r) {
            return !$r['skipped'] && $r['diff_status'] !== 'match';
        });

        if (!empty($diffResults)) {
            $this->warn('Transfers with diffs:');
            foreach ($diffResults as $result) {
                $this->displayComparisonResult($result);
            }
        } else {
            $this->info('✓ All comparisons match — zero diffs detected.');
        }

        // Check cutover readiness after batch.
        $this->newLine();
        $readiness = $shadowService->checkCutoverReadiness();
        $this->info("Cutover progress: {$readiness['consecutive_clean_days']}/{$readiness['threshold']} clean days.");

        return ($batchResult['diff_count'] > 0 || $batchResult['error_count'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Display a single comparison result in the console.
     */
    private function displayComparisonResult(array $result): void
    {
        $statusIcon = match ($result['diff_status']) {
            'match'          => '✓',
            'diff'           => '✗',
            'missing_legacy' => '?',
            'error'          => '!',
            default          => '-',
        };

        $this->line("  {$statusIcon} Transfer #{$result['transfer_id']} {$result['transfer_code']}");
        $this->line("    Operation: {$result['operation']}, Mode: {$result['mode']}");
        $this->line("    Status: {$result['diff_status']}");
        $this->line("    Checks: {$result['total_checks']} total, {$result['match_count']} match, {$result['diff_count']} diff");

        if ($result['diff_status'] !== 'match' && isset($result['diff_details'])) {
            foreach ($result['diff_details'] as $scope => $detail) {
                $scopeIcon = $detail['match'] === true || ($detail['status'] ?? '') === 'match' ? '✓' : '✗';
                $this->line("    {$scopeIcon} {$scope}: " . ($detail['detail'] ?? ($detail['details'] ?? 'N/A')));
            }
        }

        $this->newLine();
    }
}
