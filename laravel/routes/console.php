<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 5: Refresh report materialized views every 5 minutes.
Schedule::command('reports:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('reports-refresh')
    ->description('Refresh financial report materialized views');

// P1-2: Cancel stale sales draft invoices nightly at 02:00.
// Drafts older than 14 days (configurable) with no godown/challan/reversal
// are auto-cancelled (GL + customer_ledger reversed). Gated by
// config('sales.stale_draft_auto_cancel').
Schedule::command('sales:cancel-stale-drafts')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('sales-cancel-stale-drafts')
    ->description('Cancel stale draft sales invoices (>14 days, no godown)');

// Phase 7 (Stock Adjustment): Nightly stock drift reconciliation at 03:00.
// Computes warehouse_stock.qty vs SUM(stock_transactions.qty) for every
// (warehouse, product). When drift is found, fires an ERPNotification to
// every user whose employee role is in stock_adjustment.reconcile_alert_roles
// (default admin). Offset from the 02:00 stale-draft job so the two heavy
// queries don't overlap. withoutOverlapping prevents a slow run from
// stacking; runInBackground frees the schedule worker.
Schedule::command('stock:reconcile-drift')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('stock-reconcile-drift')
    ->description('Detect warehouse_stock ↔ stock_transactions drift and alert admins');

// Phase 10.1 — Phase 7.3: Export archived partitions to Parquet quarterly.
// Runs at 04:30 on the first day of Jan/Apr/Jul/Oct (offset 30 min after the
// 04:00 pg_cron consolidation job so exports operate on already-consolidated
// partitions). NB: Laravel's ->quarterly() runs at 00:00 — we use an explicit
// cron expression to control the 04:30 timing.
//
// G-046 (CRITICAL, REPORTS-2): pass `--require-parquet` so the scheduled run
// ABORTs (return FAILURE) instead of silently falling back to CSV when DuckDB
// is missing. The CSV fallback path DROPs the typed archive table after a
// type-less CSV export — irretrievable data loss. The Dockerfile installs
// DuckDB v1.1.0, so this flag is defense-in-depth against a misconfigured
// image. A failed quarterly run is visible in the scheduler log; a silent
// CSV degradation is not.
Schedule::command('partition:export-parquet --require-parquet')
    ->cron('30 4 1 1,4,7,10 *')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-export-parquet')
    ->description('Export archived partitions to Parquet cold storage (quarterly, requires DuckDB)');

// Phase 10.1 — Phase 8.7: Verify partition-wise joins weekly (Mondays 05:00).
// Runs EXPLAIN ANALYZE on the JE↔JL join, asserts a partition-wise join node
// appears in the plan. Alerts if partition-wise joins silently stop working.
Schedule::command('partition:verify-join')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-verify-join')
    ->description('Verify partition-wise joins are working (weekly)');

// Phase 10.1 — Phase 8.8: Measure partition query performance weekly (Mondays 05:30).
// Runs the 10 plan §12.1 queries, persists results, alerts on target breaches.
// Offset 30 min after the verify-join job so they don't overlap.
Schedule::command('partition:measure-perf')
    ->weeklyOn(1, '05:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('partition-measure-perf')
    ->description('Measure partition query performance vs targets (weekly)');

// FINANCE-1 / G-100: Post monthly depreciation on the 1st at 01:00.
// Generates pending schedules for the previous month + posts them to GL
// (Dr dep_expense / Cr accumulated_depreciation). Previously the accountant
// had to manually click both buttons every month — a missed month silently
// left depreciation unposted (asset NBV drifted, GL missing the entry).
// Offset from the 02:00 stale-draft cancel + 03:00 stock-reconcile so the
// three heavy jobs don't pile up. withoutOverlapping prevents a slow run
// (many assets) from stacking; runInBackground frees the schedule worker.
// Exits non-zero on partial failure so the scheduler log surfaces it.
Schedule::command('depreciation:post-monthly')
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('depreciation-post-monthly')
    ->description('Generate + post monthly depreciation schedules for all active fixed assets');

// LOW-G / G-325: Generate the budget variance report on the 1st at 04:00.
// Writes a CSV (default) to storage/app/budget-variance-{YYYY-MM-DD}.csv.
// Previously the accountant HAD to manually click "Variance" in the admin
// UI every month — no artisan command, no scheduled job. A missed month
// silently left the report un-generated. Offset past the 01:00 depreciation
// post + 02:00 stale-draft cancel + 03:00 stock-reconcile so the four
// month-start jobs don't pile up. withoutOverlapping prevents a slow run
// (large budget grid) from stacking; runInBackground frees the schedule
// worker. The report is read-only (single query against the budget_vs_actual
// VIEW) so concurrent execution with the 04:30 quarterly partition export
// (Jan/Apr/Jul/Oct only) is safe. Returns SUCCESS even when no active
// budget exists for the fiscal year (early-month gap) — see the command
// docblock for rationale.
Schedule::command('budget:variance-report --format=csv')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->name('budget-variance-report')
    ->description('Generate monthly budget variance report (G-325)');

