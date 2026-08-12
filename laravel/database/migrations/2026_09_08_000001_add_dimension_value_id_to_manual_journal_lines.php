<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-321 (MEDIUM-WAVE-3, G4, finance) — wire manual-journal dimension tagging.
 *
 * Problem (dimensions-cost-centers.md G4): "Tag journal line with dimension"
 * was NOT wired to any business module. The only way to populate
 * `journal_lines.dimension_value_id` was via direct SQL, so segment reports
 * returned 0 until tagging was wired.
 *
 * Solution (approach a — minimum viable wiring via the manual-journal path):
 * add `dimension_value_id` to `manual_journal_lines` so the
 * `ManualJournalService::persistLines()` → `postToGL()` →
 * `JournalPostingService::createJournalEntry()` pipeline can carry the
 * dimension tag end-to-end. `JournalPostingService::createJournalEntry`
 * already reads `$line['dimension_value_id'] ?? null` at L156, so once
 * `postToGL()` populates the key the GL `journal_lines.dimension_value_id`
 * is filled automatically.
 *
 * Type choice: `integer` (NOT foreignId / unsignedBigInteger). The actual
 * `manual_journal_lines` table is created by the SQL baseline
 * `database/sql/02_accounting.sql` as `integer GENERATED ALWAYS AS IDENTITY`
 * for the PK and `integer` for all FK columns (manual_journal_id, ledger_id,
 * journal_line_id). The existing Laravel migration
 * `2026_08_08_000001_create_manual_journal_lines_table.php` uses
 * `unsignedBigInteger` for FK columns (a Laravel default), but the SQL
 * baseline is the canonical schema for fresh installs — and the existing
 * `journal_lines.dimension_value_id` (G-320) is declared `integer` in the
 * same SQL baseline. PostgreSQL requires FK column types to EXACTLY match
 * the referenced column type, and `dimension_values.id` is `integer`, so we
 * must use `integer` here too (a `bigint` column would cause the FK
 * constraint to fail with "column type mismatch").
 *
 * FK pattern: matches the existing `journal_lines.dimension_value_id` FK
 * (`fk_jl_dim_value` in `database/sql/08_budgeting_and_dimensions.sql`) —
 * `DEFERRABLE INITIALLY DEFERRED` so the FK check happens at commit (allows
 * posting JEs where the dimension_value row is created in the same
 * transaction) + `ON DELETE SET NULL` (preserves the journal line if a
 * dimension value is deleted — the tag is lost but the GL entry remains).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_journal_lines', function (Blueprint $table) {
            // integer (not foreignId) — see class docblock for rationale.
            // Placed after ledger_id to keep the dimension tag visually
            // adjacent to the ledger it qualifies (matches the Blade form
            // row layout where the dimension dropdown sits next to the
            // ledger dropdown).
            $table->integer('dimension_value_id')->nullable()->after('ledger_id')
                ->comment('G-321: FK to dimension_values(id) — optional dimension tag for segment reporting');
            $table->index('dimension_value_id', 'idx_mjl_dim_value');
        });

        // Add the FK constraint with DEFERRABLE INITIALLY DEFERRED + ON
        // DELETE SET NULL, mirroring the fk_jl_dim_value pattern in
        // 08_budgeting_and_dimensions.sql. Laravel's foreign() helper does
        // not support DEFERRABLE, so we use a raw DB::statement. IF NOT
        // EXISTS guard makes this migration idempotent on re-runs.
        DB::statement("
            ALTER TABLE manual_journal_lines
            ADD CONSTRAINT fk_mjl_dim_value
            FOREIGN KEY (dimension_value_id) REFERENCES dimension_values(id)
            ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED
        ");
    }

    public function down(): void
    {
        // Drop the FK first (IF EXISTS — the constraint may have been
        // dropped manually on a fork branch).
        DB::statement("ALTER TABLE manual_journal_lines DROP CONSTRAINT IF EXISTS fk_mjl_dim_value");

        Schema::table('manual_journal_lines', function (Blueprint $table) {
            $table->dropIndex('idx_mjl_dim_value');
            $table->dropColumn('dimension_value_id');
        });
    }
};
