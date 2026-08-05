<?php

/**
 * G-279 (G18) FINANCE-CONSOLIDATION-1: Add SoftDeletes to elimination_entries.
 *
 * `EliminationEntry` was the ONLY consolidation-namespace model without
 * SoftDeletes — `EliminationRule` and `ConsolidationRun` both use it. The
 * FK `fk_ee_consolidation_run REFERENCES consolidation_runs(id) ON DELETE
 * CASCADE` only fires on HARD delete; Laravel's SoftDeletes issues
 * `UPDATE ... SET deleted_at = NOW()`, not `DELETE`. So soft-deleting a
 * `ConsolidationRun` left orphaned `elimination_entries` (still queryable,
 * still pointing at a "deleted" run).
 *
 * This migration adds the nullable `deleted_at` timestamp column. The
 * `EliminationEntry` model now uses the `SoftDeletes` trait. The
 * `ConsolidationRun` model registers a `deleting` event listener that
 * cascades the soft-delete to its entries (defense-in-depth — the DB FK
 * cascade still handles hard deletes).
 *
 * The historical rationale ("elimination entries are permanent records")
 * is preserved by the soft-delete pattern: rows remain in the table with
 * `deleted_at` set, so the GL → elimination_entry → consolidation_run
 * chain stays auditable. Eloquent's default query scope excludes
 * soft-deleted rows, so existing reports that sum elimination amounts
 * are unaffected.
 *
 * Idempotent: `Schema::hasColumn` check guards the ADD COLUMN so re-runs
 * are safe.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('elimination_entries', 'deleted_at')) {
            Schema::table('elimination_entries', function (Blueprint $table) {
                // Place after the existing updated_at column (if present) for
                // logical column ordering. Nullable by default — existing rows
                // get NULL deleted_at (not soft-deleted).
                $table->softDeletes()->after('updated_at');
            });
            echo "  G-279: added deleted_at column to elimination_entries.\n";
        } else {
            echo "  G-279: elimination_entries.deleted_at already exists — skipped.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elimination_entries', 'deleted_at')) {
            Schema::table('elimination_entries', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
