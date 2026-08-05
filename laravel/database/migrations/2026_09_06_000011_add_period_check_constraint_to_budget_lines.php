<?php

/**
 * G-339 (G18) FINANCE-5: Add CHECK constraint to budget_lines.period.
 *
 * `budget_lines.period` was declared as `unsignedSmallInteger('period')` with
 * NO CHECK constraint (migration 2026_08_10_000002 L53). The UNIQUE constraint
 * `uq_bl_budget_ledger_period` does NOT validate range. So period values 0,
 * 13, 999 were silently accepted at the DB layer.
 *
 * This migration adds `CHECK (period BETWEEN 1 AND 12)`. The upper bound is 12
 * (max across all period_types: monthly=12, quarterly=4, yearly=1). Quarterly/
 * yearly out-of-range values (e.g. period=5 for a quarterly budget) are still
 * caught at the app layer via Budget::maxPeriod(). Defense-in-depth against
 * out-of-year values (0, 13+, negatives).
 *
 * Companion changes:
 *   - BudgetController::store + update: validation rule
 *     `lines.*.periods.* => 'required|numeric|integer|min:1|max:12'`
 *   - BudgetService::syncBudgetLines + saveBudgetGrid: range guard using
 *     Budget::maxPeriod() (per-period_type upper bound)
 *
 * Pre-migration audit: `SELECT COUNT(*) FROM budget_lines WHERE period < 1 OR
 * period > 12;` — if any rows exist, the CHECK constraint will fail loudly.
 *
 * Idempotent: checks pg_constraint for the existing constraint name.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check if the constraint already exists.
        $exists = DB::selectOne("
            SELECT 1 FROM pg_constraint
            WHERE conrelid = 'budget_lines'::regclass
              AND contype = 'c'
              AND conname = 'chk_bl_period_range'
        ");

        if (!$exists) {
            DB::statement(
                'ALTER TABLE budget_lines '
                . 'ADD CONSTRAINT chk_bl_period_range CHECK (period BETWEEN 1 AND 12)'
            );
            echo "  G-339: added CHECK constraint chk_bl_period_range (period BETWEEN 1 AND 12) to budget_lines.\n";
        } else {
            echo "  G-339: constraint chk_bl_period_range already exists — skipped.\n";
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE budget_lines DROP CONSTRAINT IF EXISTS chk_bl_period_range');
    }
};
