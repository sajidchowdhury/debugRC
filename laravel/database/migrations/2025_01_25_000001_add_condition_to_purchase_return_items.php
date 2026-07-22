<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — Damage condition support.
 *
 * Adds a `condition` column to `purchase_return_items` so each return line
 * can be marked either 'Good' (stock OUT + GL + supplier_ledger) or 'Damage'
 * (supplier claim only — NO stock movement, but GL + supplier_ledger still
 * posted; GRN item return_qty still incremented to track cumulative returns).
 *
 * Mirrors legacy `purchase_return_items.prt_condition` (varchar(10), default
 * 'Good', CHECK in ('Good','Damage')).
 *
 * This migration is IDEMPOTENT — guarded by Schema::hasColumn() so it can be
 * re-run safely on environments where the column was manually added.
 *
 * @see docs/PURCHASE_PARITY_PLAN.md §Phase 5 (feature F1 + F2)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_return_items', 'condition')) {
            return;
        }

        Schema::table('purchase_return_items', function (Blueprint $table) {
            // Position after `amount` (the last existing column) to keep the
            // schema diff minimal and avoid colliding with the GENERATED column.
            $table->string('condition', 10)
                ->default('Good')
                ->after('amount')
                ->comment('Good | Damage — Damage = no stock movement (supplier claim only)');
        });

        // Backfill existing rows (defensive — should be no rows in fresh install).
        DB::table('purchase_return_items')
            ->whereNull('condition')
            ->orWhere('condition', '')
            ->update(['condition' => 'Good']);

        // Add CHECK constraint for enum integrity (PG only — same pattern as
        // the status_check migration on purchase_returns).
        DB::statement(
            "ALTER TABLE purchase_return_items "
            . "ADD CONSTRAINT purchase_return_items_condition_check "
            . "CHECK (condition IN ('Good','Damage'))"
        );

        // Index for filter-by-condition dashboards (Phase 6 audit).
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_prti_condition ON purchase_return_items(condition)"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_return_items', 'condition')) {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS idx_prti_condition');
        DB::statement('ALTER TABLE purchase_return_items DROP CONSTRAINT IF EXISTS purchase_return_items_condition_check');
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $table->dropColumn('condition');
        });
    }
};
