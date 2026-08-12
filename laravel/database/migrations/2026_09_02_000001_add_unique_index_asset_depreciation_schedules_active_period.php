<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-3 (G-330 / BR10) — Add a partial UNIQUE INDEX on
 * `asset_depreciation_schedules` to back the application-level schedule
 * generation guard with a DB-level constraint.
 *
 * BR10 (per `AI_CONTEXT/finance/fixed-assets.md` §6.2):
 *   "A schedule MUST NOT be generated if a non-reversed schedule already
 *    exists for the same `(asset, period_from, period_to)`."
 *
 * The application guard (`DepreciationService::generateSchedule` L180-188)
 * already filters `->where('status', '!=', 'reversed')->first()` and skips
 * creation if a match exists. But under concurrent `generateSchedulesForPeriod`
 * invocations (e.g. two accountants running the monthly depreciation job at
 * the same instant, or a retry race), two schedules can be created for the
 * same `(asset, period_from, period_to)` because the SELECT-then-INSERT is
 * not atomic. The duplicates pollute the depreciation register and lead to
 * double-counted depreciation expense.
 *
 * This migration adds a partial UNIQUE INDEX that allows reversed schedules
 * to coexist (so a reversed schedule can be re-depreciated after correction)
 * but blocks concurrent duplicates among `pending` / `posted` schedules:
 *
 *   CREATE UNIQUE INDEX uq_ads_active_period
 *     ON asset_depreciation_schedules (fixed_asset_id, period_from, period_to)
 *     WHERE status != 'reversed';
 *
 * The existing non-unique index `idx_ads_asset_period` (per
 * `2026_08_13_000001_create_fixed_assets.php:148`) is left in place — it
 * serves the broader query "all schedules for asset X in period Y (including
 * reversed)" which the partial UNIQUE index does NOT cover.
 *
 * The migration is idempotent (DROP INDEX IF EXISTS + CREATE UNIQUE INDEX
 * IF NOT EXISTS) so it is safe to re-run.
 *
 * NOTE: If duplicate non-reversed schedules already exist in production
 * (pre-migration), CREATE UNIQUE INDEX will fail with
 * `SQLSTATE[23505]: could not create unique index "uq_ads_active_period" —
 * key (fixed_asset_id, period_from, period_to)=(N, ..., ...) is duplicated`.
 * The remediation is to manually reverse the duplicate(s) before re-running.
 * This is intentional — the migration surfaces existing data corruption
 * rather than silently leaving it in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::selectOne("SELECT to_regclass('public.asset_depreciation_schedules')")) {
            return; // Table doesn't exist — fresh install via 2026_08_13_000001 hasn't run yet.
        }

        // Drop any prior version of the index (defensive — should not exist on a fresh DB).
        DB::statement('DROP INDEX IF EXISTS uq_ads_active_period');

        // Create the partial UNIQUE INDEX. IF NOT EXISTS guards re-runs.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_ads_active_period '
            . 'ON asset_depreciation_schedules (fixed_asset_id, period_from, period_to) '
            . "WHERE status != 'reversed'"
        );
    }

    public function down(): void
    {
        if (!DB::selectOne("SELECT to_regclass('public.asset_depreciation_schedules')")) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS uq_ads_active_period');
    }
};
