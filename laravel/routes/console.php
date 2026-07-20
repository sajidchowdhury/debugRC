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
