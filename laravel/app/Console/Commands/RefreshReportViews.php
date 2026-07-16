<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh Report Materialized Views — Phase 5.
 *
 * Scheduled to run every 5 minutes (see routes/console.php).
 * Also run on-demand after journal postings.
 *
 * Usage:
 *   php artisan reports:refresh
 */
class RefreshReportViews extends Command
{
    protected $signature = 'reports:refresh';
    protected $description = 'Refresh all report materialized views (concurrently)';

    public function handle(): int
    {
        $this->info('Refreshing report materialized views...');

        try {
            $start = microtime(true);
            DB::statement('SELECT refresh_all_report_views()');
            $elapsed = round((microtime(true) - $start) * 1000);

            $this->info("All report views refreshed in {$elapsed}ms");
            Log::info('Report materialized views refreshed', ['ms' => $elapsed]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to refresh report views: ' . $e->getMessage());
            Log::error('Report MV refresh failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }
}
