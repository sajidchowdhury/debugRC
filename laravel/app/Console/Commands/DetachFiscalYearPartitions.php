<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FiscalYear;
use App\Services\FiscalYearPartitionService;
use Illuminate\Console\Command;

/**
 * Detach (or restore) all monthly partitions belonging to a closed
 * fiscal year.
 *
 * This command is the ONLY entry point for the partition detach/restore
 * flow outside of `AccountingPeriodService::yearEndClose()` (which calls
 * the service directly on close).
 *
 * @internal  Manual ops tool. The FiscalYearPartitionService::restoreForViewing()
 *            method must NEVER be exposed via a web UI route. The client's
 *            hard requirement is that closed-FY data is invisible to every
 *            user including super admin — the restore path is for the DBA
 *            only, and the DBA must explicitly run this command on the
 *            production host (or restore a `pg_dump -Fc` file to a
 *            separate instance) to view closed-FY data.
 *
 * Usage:
 *   # Detach+archive (typically run automatically by yearEndClose):
 *   php artisan fy:detach-archived --fiscal-year=5
 *
 *   # Restore archived partitions for inspection (manual ops only):
 *   php artisan fy:detach-archived --fiscal-year=5 --restore
 *
 *   # Check archive status (no changes made):
 *   php artisan fy:detach-archived --fiscal-year=5 --status
 *
 * @see \App\Services\FiscalYearPartitionService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 4
 */
class DetachFiscalYearPartitions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fy:detach-archived
                            {--fiscal-year= : Fiscal year ID (required)}
                            {--restore : Restore archived partitions back to public schema (manual ops only)}
                            {--status : Only report archive status, do not modify}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detach (or restore) the closed fiscal year\'s monthly partitions from/to their parent operational tables. @internal — manual ops only.';

    /**
     * Execute the console command.
     *
     * @param  FiscalYearPartitionService $service
     * @return int
     */
    public function handle(FiscalYearPartitionService $service): int
    {
        $fyId = (int) $this->option('fiscal-year');
        if ($fyId <= 0) {
            $this->error('--fiscal-year is required and must be a positive integer.');
            return self::FAILURE;
        }

        $fy = FiscalYear::find($fyId);
        if (!$fy) {
            $this->error("Fiscal year #{$fyId} not found.");
            return self::FAILURE;
        }

        $this->info("Fiscal Year: #{$fy->id} — {$fy->fiscal_year_code} ({$fy->start_date->format('Y-m-d')} → {$fy->end_date->format('Y-m-d')}, status={$fy->status})");

        // ── Status-only mode ───────────────────────────────────────
        if ($this->option('status')) {
            $archived = $service->isFiscalYearArchived($fyId);
            $this->info('Archive status: ' . ($archived ? 'FULLY ARCHIVED (all expected partitions in archive schema)' : 'NOT fully archived (some partitions still in public or missing)'));
            return self::SUCCESS;
        }

        // ── Restore mode ───────────────────────────────────────────
        if ($this->option('restore')) {
            $this->warn('RESTORE MODE: This will move archived partitions back into the public schema and re-attach them.');
            $this->warn('This is a manual ops action. Closed-FY data will become queryable from the parent tables (subject to the S2 global scope + FiscalYearPolicy read-blocks).');
            if (!$this->confirm('Proceed with restore?')) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }

            $result = $service->restoreForViewing($fyId);

            $this->info('Restore complete.');
            $this->line("  Restored: " . count($result['restored']));
            $this->line("  Skipped (already in public): " . count($result['skipped']));
            $this->line("  Missing (not in archive or public): " . count($result['missing']));

            if (!empty($result['restored'])) {
                $this->line('');
                $this->line('Restored partitions:');
                foreach ($result['restored'] as $p) {
                    $this->line("  - {$p}");
                }
            }

            return self::SUCCESS;
        }

        // ── Default: detach+archive mode ───────────────────────────
        $this->info('DETACH+ARCHIVE MODE: Moving closed-FY partitions from public to archive schema.');

        // Pre-flight: warn if FY is still active (detach while active
        // would break ongoing operations). The yearEndClose() flow
        // calls detach AFTER the closing JE but BEFORE the status flip,
        // so the FY is technically still 'active' at that moment. For
        // manual invocation, however, we expect the FY to be closed.
        if ($fy->status === 'active') {
            $this->warn("Note: FY status is still 'active'. Detaching partitions while the FY is active will make its rows invisible to all queries.");
            if (!$this->confirm('Proceed anyway?')) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $result = $service->detachAndArchive($fyId);

        $this->info('Detach+archive complete.');
        $this->line("  Detached: " . count($result['detached']));
        $this->line("  Skipped (already archived): " . count($result['skipped']));
        $this->line("  Missing (not in public or archive): " . count($result['missing']));

        if (!empty($result['detached'])) {
            $this->line('');
            $this->line('Detached partitions:');
            foreach ($result['detached'] as $p) {
                $this->line("  - {$p}");
            }
        }

        return self::SUCCESS;
    }
}
