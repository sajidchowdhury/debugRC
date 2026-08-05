<?php

/**
 * G-332 (G13) FINANCE-DIM-1: Convert dimensions.code from a plain UNIQUE
 * constraint to a partial UNIQUE index that only covers non-soft-deleted rows.
 *
 * The plain UNIQUE constraint (created by migration 2026_08_10_000002 L68:
 * `$table->string('code', 20)->unique()`) blocks code reuse after soft-delete.
 * A soft-deleted dimension's code is permanently consumed — the accountant
 * cannot re-create a dimension with the same code even though the original
 * is no longer active. This is inconsistent with the sibling `dimension_values`
 * table which already uses a partial UNIQUE index `uq_dv_dim_code_active`
 * (migration L94-100) that allows code reuse after soft-delete.
 *
 * The fix mirrors the dimension_values pattern: drop the plain UNIQUE
 * constraint, create a partial UNIQUE INDEX ... WHERE deleted_at IS NULL.
 * This allows a soft-deleted dimension's code to be reused by a new dimension.
 *
 * The companion DimensionController::store() validation rule was updated to
 * `unique:dimensions,code,NULL,id,deleted_at,NULL` so the validation layer
 * matches the new DB constraint (only considers non-deleted rows).
 *
 * Idempotent: uses DROP IF EXISTS + CREATE INDEX IF NOT EXISTS.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the plain UNIQUE constraint created by $table->string('code', 20)->unique().
        // Laravel names it {table}_{column}_unique by default.
        DB::statement('ALTER TABLE dimensions DROP CONSTRAINT IF EXISTS dimensions_code_unique');

        // Create the partial UNIQUE index mirroring uq_dv_dim_code_active.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_dim_code_active '
            . 'ON dimensions (code) WHERE deleted_at IS NULL'
        );

        echo "  G-332: converted dimensions.code from plain UNIQUE to partial UNIQUE INDEX (uq_dim_code_active WHERE deleted_at IS NULL).\n";
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_dim_code_active');
        DB::statement('ALTER TABLE dimensions ADD CONSTRAINT dimensions_code_unique UNIQUE (code)');
    }
};
