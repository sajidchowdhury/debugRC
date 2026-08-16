<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Session 1 — Migration B: Backfill fiscal_year_id from date columns.
 *
 * For each operational table added in migration 2026_10_16_000001, populate
 * `fiscal_year_id` by matching the row's date against fiscal_years date ranges.
 *
 * Two backfill patterns:
 *
 * 1. Direct (parent tables with own date column):
 *      UPDATE <table> t
 *      SET fiscal_year_id = sub.fy_id
 *      FROM (
 *          SELECT fy.id AS fy_id, fy.start_date, fy.end_date
 *          FROM fiscal_years fy
 *      ) sub
 *      WHERE t.<date_column> BETWEEN sub.start_date AND sub.end_date
 *        AND t.fiscal_year_id IS NULL
 *
 * 2. Join via parent (child tables with no own date column):
 *      UPDATE <child> c
 *      SET fiscal_year_id = sub.fy_id
 *      FROM (
 *          SELECT c2.id AS child_id, fy.id AS fy_id
 *          FROM <child> c2
 *          JOIN <parent> p ON p.id = c2.<parent_fk>
 *          JOIN fiscal_years fy ON p.<parent_date> BETWEEN fy.start_date AND fy.end_date
 *      ) sub
 *      WHERE c.id = sub.child_id
 *        AND c.fiscal_year_id IS NULL
 *
 * After backfill, any rows still NULL (rows whose date falls outside ALL
 * defined fiscal years — e.g., pre-onboarding data or a date in a year that
 * was never set up as a fiscal year) are set to the currently-active FY id
 * as a defensive fallback, then the column is set NOT NULL.
 *
 * The migration is idempotent: it only updates rows where fiscal_year_id
 * IS NULL, so re-running after a partial failure is safe.
 *
 * @see config/fiscal.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 1
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('fiscal.tables');

        // Resolve the active FY id once as the fallback for unmatched rows.
        $activeFyId = DB::table('fiscal_years')
            ->where('status', 'active')
            ->orWhere('is_current', true)
            ->orderByDesc('is_current')
            ->value('id');

        if (! $activeFyId) {
            // No active FY — fall back to the most recent FY by end_date.
            $activeFyId = DB::table('fiscal_years')
                ->orderByDesc('end_date')
                ->value('id');
        }

        if (! $activeFyId) {
            throw new RuntimeException(
                'Cannot backfill fiscal_year_id: no fiscal_years rows exist. '
                .'Create at least one fiscal year before running this migration.'
            );
        }

        foreach ($tables as $entry) {
            $table = $entry['table'];
            $dateColumn = $entry['date_column'] ?? null;
            $parent = $entry['parent'] ?? null;

            $this->backfillTable($table, $dateColumn, $parent, $activeFyId);

            // Set column NOT NULL now that every row has a value.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN fiscal_year_id SET NOT NULL");
        }
    }

    public function down(): void
    {
        // Reverse the NOT NULL constraint only. The data itself is left in
        // place — dropping the column is the responsibility of migration A's
        // down() method.
        $tables = config('fiscal.tables');

        foreach (array_reverse($tables) as $entry) {
            $table = $entry['table'];
            DB::statement("ALTER TABLE {$table} ALTER COLUMN fiscal_year_id DROP NOT NULL");
        }
    }

    /**
     * Backfill a single table's fiscal_year_id column.
     */
    private function backfillTable(string $table, ?string $dateColumn, ?array $parent, int $activeFyId): void
    {
        if ($dateColumn !== null) {
            // ── Direct: table has its own date column ──
            $updated = DB::statement(<<<SQL
                UPDATE {$table} AS t
                SET fiscal_year_id = fy.id
                FROM fiscal_years AS fy
                WHERE t.{$dateColumn} BETWEEN fy.start_date AND fy.end_date
                  AND t.fiscal_year_id IS NULL
            SQL);
        } elseif ($parent !== null) {
            // ── Join via parent: child table has no own date column ──
            [$parentTable, $parentFk, $parentDate] = $parent;

            $updated = DB::statement(<<<SQL
                UPDATE {$table} AS c
                SET fiscal_year_id = fy.id
                FROM fiscal_years AS fy
                JOIN {$parentTable} AS p ON p.{$parentDate} BETWEEN fy.start_date AND fy.end_date
                WHERE c.{$parentFk} = p.id
                  AND c.fiscal_year_id IS NULL
            SQL);
        } else {
            throw new RuntimeException(
                "Table {$table} has neither date_column nor parent — cannot backfill. ".
                'Fix config/fiscal.php.'
            );
        }

        // ── Defensive fallback: rows still NULL get the active FY id ──
        $remainingNull = DB::table($table)->whereNull('fiscal_year_id')->count();
        if ($remainingNull > 0) {
            Log::warning(
                "Session 1 backfill: {$table} has {$remainingNull} rows whose date ".
                'falls outside all defined fiscal years — defaulting to active FY id '."{$activeFyId}. ".
                'Investigate if this is unexpected.'
            );
            DB::table($table)
                ->whereNull('fiscal_year_id')
                ->update(['fiscal_year_id' => $activeFyId]);
        }
    }
};
