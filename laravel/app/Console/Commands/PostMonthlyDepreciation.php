<?php

namespace App\Console\Commands;

use App\Services\Accounting\DepreciationService;
use Illuminate\Console\Command;

/**
 * Post Monthly Depreciation — FINANCE-1 / G-100.
 *
 * Generates + posts depreciation schedules for the previous month (or a
 * caller-specified period). Closes the G-100 gap: previously the accountant
 * HAD to manually click "Generate Schedules" then "Post Depreciation" in
 * the admin UI every month — no artisan command, no cron entry, so a
 * missed month silently left depreciation unposted (asset NBV drifted
 * from the true depreciable basis, and the GL was missing the Dr dep_exp /
 * Cr acc_dep entry).
 *
 * This command does both steps in sequence:
 *   1. generateSchedulesForPeriod($periodFrom, $periodTo, $branchId)
 *   2. postMonthlyDepreciation($periodFrom, $periodTo, $branchId)
 *
 * Each individual schedule posting is wrapped in its own DB::transaction
 * (see DepreciationService::postDepreciation, G-023/G13 fix). If schedule
 * #3 of 50 fails, schedules #1-2 stay posted (partial-failure isolation).
 * The command exits with a non-zero code if ANY schedule failed, so the
 * scheduler log + monitoring can surface partial failures.
 *
 * Usage:
 *   php artisan depreciation:post-monthly                 # last month, all branches
 *   php artisan depreciation:post-monthly --branch=2      # last month, branch 2 only
 *   php artisan depreciation:post-monthly --period=2026-08 # specific YYYY-MM
 *   php artisan depreciation:post-monthly --dry-run       # generate only, no posting
 *
 * Scheduled monthly on the 1st at 01:00 in routes/console.php (offset from
 * the 02:00 stale-draft cancel + 03:00 stock-reconcile so the three heavy
 * jobs don't pile up).
 */
class PostMonthlyDepreciation extends Command
{
    protected $signature = 'depreciation:post-monthly
                            {--branch= : Scope to a single branch_id (default: all branches)}
                            {--period= : Target month as YYYY-MM (default: previous month)}
                            {--dry-run : Generate schedules only, do not post to GL}';

    protected $description = 'Generate + post monthly depreciation schedules for all active fixed assets (G-100)';

    public function handle(DepreciationService $depreciation): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $dryRun   = (bool) $this->option('dry-run');

        // Resolve the target period. Default: previous calendar month.
        // A caller passing --period=2026-08 posts depreciation for Aug 2026.
        $periodInput = $this->option('period');
        if ($periodInput) {
            if (!preg_match('/^\d{4}-\d{2}$/', $periodInput)) {
                $this->error("Invalid --period format. Expected YYYY-MM, got: {$periodInput}");
                return self::FAILURE;
            }
            $periodDate = \Carbon\Carbon::createFromFormat('Y-m', $periodInput)->startOfMonth();
        } else {
            $periodDate = now()->startOfMonth()->subMonth();
        }

        $periodFrom = $periodDate->copy()->startOfMonth()->format('Y-m-d');
        $periodTo   = $periodDate->copy()->endOfMonth()->format('Y-m-d');

        $this->info(sprintf(
            'Depreciation run for period %s to %s%s%s',
            $periodFrom,
            $periodTo,
            $branchId ? " (branch {$branchId})" : ' (all branches)',
            $dryRun ? ' [DRY RUN]' : ''
        ));

        // Step 1: generate pending schedules for any active asset that
        // doesn't yet have one for this period. Idempotent — skips assets
        // that already have a non-reversed schedule for the period.
        $generated = $depreciation->generateSchedulesForPeriod($periodFrom, $periodTo, $branchId);
        $this->info("Generated {$generated} new schedule(s).");

        if ($dryRun) {
            $this->comment('Dry run — skipping GL posting.');
            return self::SUCCESS;
        }

        // Step 2: post all pending schedules for the period to the GL.
        $result = $depreciation->postMonthlyDepreciation($periodFrom, $periodTo, $branchId);

        $this->info("Posted {$result['posted']} schedule(s) to GL.");
        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} schedule(s) failed to post:");
            foreach ($result['errors'] as $error) {
                $this->line("  - Schedule #{$error['schedule_id']} ({$error['asset_code']}): {$error['error']}");
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
