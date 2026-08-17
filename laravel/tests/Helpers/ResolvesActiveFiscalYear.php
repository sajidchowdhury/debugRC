<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Resolve an active fiscal year id for test inserts.
 *
 * Shared trait used by every Inserts*Dependencies helper so that any
 * insert into a fiscal-scoped table (see config/fiscal.php `tables`)
 * can satisfy the NOT NULL `fiscal_year_id` column added by the
 * Session 1 FY-isolation migration (2026_10_16_000002_backfill_
 * fiscal_year_id.php line 83: `ALTER TABLE … ALTER COLUMN
 * fiscal_year_id SET NOT NULL`).
 *
 * Resolution order:
 *  1. Existing FY with is_current=true AND status=active (the running FY).
 *  2. Any FY with status=active.
 *  3. Last resort: create a minimal active FY covering the current
 *     calendar year (reuses the system user id, mirrors seed migration
 *     2026_08_10_000004 pattern).
 *
 * Uses DB::table (bypasses Eloquent BranchScope) so test setup does
 * not depend on the auth state of the consuming test.
 *
 * @see config/fiscal.php
 * @see database/migrations/2026_10_16_000002_backfill_fiscal_year_id.php
 */
trait ResolvesActiveFiscalYear
{
    protected function resolveActiveFiscalYearId(): int
    {
        $existing = DB::table('fiscal_years')
            ->where('is_current', true)
            ->where('status', 'active')
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        // Fall back to ANY active FY (status=active, even if is_current=false).
        $anyActive = DB::table('fiscal_years')
            ->where('status', 'active')
            ->value('id');

        if ($anyActive) {
            return (int) $anyActive;
        }

        // Last resort: create a minimal active FY. Reuse the system user
        // id (mirrors the seed migration 2026_08_10_000004 pattern).
        $sysUserId = DB::table('users')->value('id') ?? 1;
        $year = now()->year;

        return (int) DB::table('fiscal_years')->insertGetId([
            'name'             => "Test FY {$year}-{$year}",
            'fiscal_year_code' => 'TFY-' . substr(uniqid(), -6),
            'start_date'       => "{$year}-01-01",
            'end_date'         => "{$year}-12-31",
            'branch_id'        => null,
            'period_type'      => 'monthly',
            'status'           => 'active',
            'is_current'       => true,
            'description'      => 'Auto-created by test helper resolveActiveFiscalYearId()',
            'created_by'       => $sysUserId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
