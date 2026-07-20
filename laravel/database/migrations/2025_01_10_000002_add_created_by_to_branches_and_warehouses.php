<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;          // ← ADD THIS LINE
use Illuminate\Support\Facades\Schema;

/**
 * Branch Module Phase 1: Database fixes.
 *
 * Adds the `created_by` column to both `branches` and `warehouses` tables.
 *
 * The legacy MySQL schema had `created_by int(11) DEFAULT NULL` on both
 * tables. The PG schema (01_auth_and_master.sql) omitted it — legacy
 * BranchModel::createBranch (line 41) and WarehouseModel::createWarehouse
 * (line 43) INSERT it, so the column must exist for PG compatibility.
 *
 * Also adds an index on `branches.is_active` and `warehouses.is_active`
 * (legacy had `idx_branches_active`; PG was missing it).
 *
 * Idempotent: guarded by Schema::hasColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- branches: add created_by + active index ---
        if (!Schema::hasColumn('branches', 'created_by')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->integer('created_by')->nullable()->after('is_active');
            });
        }

        // --- warehouses: add created_by ---
        if (!Schema::hasColumn('warehouses', 'created_by')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->integer('created_by')->nullable()->after('is_active');
            });
        }

        // Add is_active indexes if missing (legacy had idx_branches_active).
        $branchIdxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'branches' AND indexname = 'idx_branches_active'"
        ))->count();
        if (!$branchIdxExists) {
            DB::statement('CREATE INDEX idx_branches_active ON branches (is_active) WHERE is_active = true');
        }

        $whIdxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'warehouses' AND indexname = 'idx_warehouses_active'"
        ))->count();
        if (!$whIdxExists) {
            DB::statement('CREATE INDEX idx_warehouses_active ON warehouses (is_active) WHERE is_active = true');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_warehouses_active');
        DB::statement('DROP INDEX IF EXISTS idx_branches_active');

        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};
