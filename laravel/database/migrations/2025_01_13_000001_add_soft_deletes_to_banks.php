<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Module Phase 1 — Database fix: soft-delete columns.
 *
 * Audit Finding (Phase 13): The Bank model uses the SoftDeletes trait, but
 * the PG `banks` table (01_auth_and_master.sql) was created WITHOUT a
 * `deleted_at` column. Any call to `$bank->delete()` would crash with a
 * SQLSTATE error. This migration adds the two columns SoftDeletes needs:
 *
 *   - `deleted_at` timestamp(0) NULL  — set by SoftDeletes::delete()
 *   - `deleted_by` integer NULL       — set by BaseMasterDataController::destroy()
 *
 * Note: `created_by` already exists on the banks table (legacy-compat).
 *
 * Idempotent: guarded by Schema::hasColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('banks', 'deleted_at')) {
            DB::statement('ALTER TABLE banks ADD COLUMN deleted_at timestamp(0) without time zone NULL');
        }

        if (!Schema::hasColumn('banks', 'deleted_by')) {
            DB::statement('ALTER TABLE banks ADD COLUMN deleted_by integer NULL');
        }

        // Add is_active index (legacy had idx_banks_active; PG was missing it).
        $idxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'banks' AND indexname = 'idx_banks_active'"
        ))->count();
        if (!$idxExists) {
            DB::statement('CREATE INDEX idx_banks_active ON banks (is_active) WHERE is_active = true');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_banks_active');

        if (Schema::hasColumn('banks', 'deleted_by')) {
            DB::statement('ALTER TABLE banks DROP COLUMN deleted_by');
        }

        if (Schema::hasColumn('banks', 'deleted_at')) {
            DB::statement('ALTER TABLE banks DROP COLUMN deleted_at');
        }
    }
};
