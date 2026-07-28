<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Adjustment Phase 2 — Reason Categorization & Opening-Balance Reference.
 *
 * Adds a structured `adjustment_category` column to `stock_adjustments` so the
 * *reason* for an adjustment is no longer a free-text guess but a fixed enum:
 *
 *   opening_balance       — initial stock loaded when onboarding a warehouse
 *   data_migration        — bulk correction during a data-import cutover
 *   uom_correction        — fixing a unit-of-measure mistake (Phase 5 will add
 *                           proper UOM conversion; until then this category
 *                           documents the manual fix)
 *   post_conversion_fix   — cleanup after a legacy → Laravel conversion run
 *   legacy_cleanup        — generic tidy-up of legacy-era junk rows
 *   reconciliation_variance — booked when settling a warehouse_stock ↔ ledger
 *                           drift (Phase 7 will automate the detection; this
 *                           category tags the human-booked correction today)
 *   other                 — fallback for one-off corrections that don't fit
 *
 * Why a column (not just a free-text tag in `reason`):
 *   - The category drives ledger behaviour (Phase 2.3): an `opening_balance`
 *     adjustment writes `stock_transactions.reference_type = 'opening_balance'`
 *     instead of `'stock_adjustment'`, so opening-balance rows are trivially
 *     distinguishable in the immutable ledger and in opening-balance reports.
 *   - The category powers filtering / reporting (Phase 2.5) and the audit
 *     checklist (Phase 4 will add a "stale opening-balance drafts" check).
 *   - A CHECK constraint at the DB level guarantees the value is one of the
 *     seven known categories — no typos, no drift.
 *
 * Backfill: existing rows get `adjustment_category = 'other'` (the column
 * DEFAULT). An explicit UPDATE is run anyway so the default is materialised
 * even on rows that already existed at migration time (defensive — makes
 * `WHERE adjustment_category = 'other'` queries immediately correct).
 *
 * Idempotent: guarded by Schema::hasColumn / pg_constraint checks so re-running
 * is safe. The CHECK constraint is created with a custom name (`sa_category_check`)
 * so it can be dropped cleanly in `down()`.
 *
 * References:
 *   - STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §Phase 2 (G6, G17)
 *   - app/Services/Stock/StockAdjustmentService.php  (createAdjustment, confirmAdjustment)
 *   - app/Models/StockAdjustment.php  (ADJUSTMENT_CATEGORIES constant)
 */
return new class extends Migration
{
    /**
     * The seven valid adjustment categories.
     * Kept in sync with StockAdjustment::ADJUSTMENT_CATEGORIES.
     */
    private const CATEGORIES = [
        'opening_balance',
        'data_migration',
        'uom_correction',
        'post_conversion_fix',
        'legacy_cleanup',
        'reconciliation_variance',
        'other',
    ];

    private const CHECK_CONSTRAINT = 'sa_category_check';

    public function up(): void
    {
        // ── 1. Add the column ─────────────────────────────────────────────
        // NOT NULL with DEFAULT 'other' so existing rows are backfilled by the
        // ALTER itself. varchar(40) is plenty (longest value is 23 chars).
        if (!Schema::hasColumn('stock_adjustments', 'adjustment_category')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->string('adjustment_category', 40)
                      ->after('adjustment_type')
                      ->default('other');
            });
        }

        // ── 2. Backfill: materialise the default on any pre-existing rows ──
        // (Defensive — the DEFAULT already covers this, but an explicit UPDATE
        // guarantees the value is written even if a prior row somehow has a
        // NULL or unexpected value from a partial backfill.)
        DB::table('stock_adjustments')
            ->whereNull('adjustment_category')
            ->orWhereNotIn('adjustment_category', self::CATEGORIES)
            ->update(['adjustment_category' => 'other']);

        // ── 3. CHECK constraint (DB-enforced enum) ─────────────────────────
        // Drop any prior constraint of the same name first (idempotent), then
        // add fresh. Using a custom name (sa_category_check) so down() can drop
        // it reliably without relying on PostgreSQL's auto-naming.
        $this->dropCheckConstraint();
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            self::CATEGORIES,
        ));
        DB::statement(<<<SQL
            ALTER TABLE stock_adjustments
            ADD CONSTRAINT sa_category_check
            CHECK (adjustment_category IN ({$values}))
        SQL);

        // ── 4. Index for the index-page filter ────────────────────────────
        // The list view filters by category; a small btree index keeps that
        // cheap. Partial index would be premature (categories are not skewed
        // toward one value the way statuses often are).
        $indexExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = 'stock_adjustments' AND indexname = 'idx_sa_category'"
        ))->count();
        if (!$indexExists) {
            DB::statement(
                'CREATE INDEX idx_sa_category ON stock_adjustments(adjustment_category)'
            );
        }
    }

    public function down(): void
    {
        // Drop the index, the CHECK, then the column.
        DB::statement('DROP INDEX IF EXISTS idx_sa_category');
        $this->dropCheckConstraint();

        if (Schema::hasColumn('stock_adjustments', 'adjustment_category')) {
            Schema::table('stock_adjustments', function (Blueprint $table) {
                $table->dropColumn('adjustment_category');
            });
        }
    }

    /**
     * Drop the category CHECK constraint if it exists (idempotent).
     */
    private function dropCheckConstraint(): void
    {
        $exists = DB::table('pg_constraint')
            ->where('conname', self::CHECK_CONSTRAINT)
            ->where('contype', 'c')
            ->exists();
        if ($exists) {
            DB::statement(
                'ALTER TABLE stock_adjustments DROP CONSTRAINT ' . self::CHECK_CONSTRAINT
            );
        }
    }
};
