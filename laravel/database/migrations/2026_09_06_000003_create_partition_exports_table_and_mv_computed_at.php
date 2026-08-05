<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPORTS-AUDIT-7 (G-228 + G-233 + G-234 / csv-export.md G13/G14 + materialized-views.md G14).
 *
 * Three DDL gaps closed in one migration:
 *
 *   1. partition_exports table (G-228 + G-233) — append-only manifest of every
 *      Parquet/CSV archive produced by `partition:export-parquet`. Columns:
 *      parent_table, partition_name, parquet_path, byte_size, row_count,
 *      sha256, exported_at, duckdb_version. The ExportArchivedPartitionsToParquet
 *      command writes a row here after each successful export (replacing the
 *      prior TODO that only wrote to Log::info). The sha256 column lets
 *      downstream integrity checks verify cold-storage files have not been
 *      silently corrupted.
 *
 *   2. computed_at column on the 7 financial MVs (G-234) — lets reports detect
 *      staleness programmatically (e.g. "MV is older than 1 hour" → show a
 *      "stale data" badge). The column is populated by refresh_all_report_views()
 *      AFTER each REFRESH — but that function update is a separate concern
 *      (the function reads now() at the end of its body and sets computed_at
 *      via UPDATE). Here we only add the column + a default of now() so
 *      existing rows get a non-null value on backfill.
 *
 * MVs touched (the 7 financial + mv_product_abc_classification for completeness):
 *   - mv_ledger_balances
 *   - mv_ar_aging
 *   - mv_ap_aging
 *   - mv_stock_valuation
 *   - mv_journal_entry_summary
 *   - mv_branch_intercompany
 *   - mv_product_movement_summary
 *   - mv_product_abc_classification
 *
 * Idempotent: Schema::hasColumn guards every ALTER TABLE; Schema::hasTable
 * guards the CREATE TABLE. Safe to re-run.
 *
 * NOTE: PostgreSQL does NOT support adding a column with a default of now()
 * via ALTER MATERIALIZED VIEW ... ADD COLUMN in all versions — but
 * `ALTER MATERIALIZED VIEW ... ADD COLUMN computed_at timestamptz DEFAULT now()`
 * IS supported in PG 11+. We use raw DB::statement for the MV ALTERs because
 * Schema::table does not work on materialized views (Blueprint expects a
 * regular table).
 */
return new class extends Migration
{
    private const PARTITION_EXPORTS_TABLE = 'partition_exports';

    private const MATERIALIZED_VIEWS_WITH_COMPUTED_AT = [
        'mv_ledger_balances',
        'mv_ar_aging',
        'mv_ap_aging',
        'mv_stock_valuation',
        'mv_journal_entry_summary',
        'mv_branch_intercompany',
        'mv_product_movement_summary',
        'mv_product_abc_classification',
    ];

    public function up(): void
    {
        // 1. partition_exports table (G-228 + G-233).
        if (!Schema::hasTable(self::PARTITION_EXPORTS_TABLE)) {
            Schema::create(self::PARTITION_EXPORTS_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('parent_table', 100)->comment('The original partitioned table name (e.g. sales_invoices).');
                $table->string('partition_name', 150)->comment('The archived partition table name (e.g. sales_invoices_2024_01).');
                $table->string('parquet_path', 500)->nullable()->comment('Relative path on the archive disk; null for CSV-fallback exports.');
                $table->bigInteger('byte_size')->default(0)->comment('File size in bytes.');
                $table->bigInteger('row_count')->default(0)->comment('Row count exported (COUNT(*) pre-export).');
                $table->string('sha256', 64)->nullable()->comment('Hex SHA-256 of the file contents; null if not computed.');
                $table->string('duckdb_version', 30)->nullable()->comment('DuckDB CLI version string; null for CSV-fallback exports.');
                $table->string('format', 10)->default('parquet')->comment('parquet or csv (fallback when DuckDB missing).');
                $table->timestampTz('exported_at')->useCurrent()->comment('When the export completed.');
                $table->index(['parent_table', 'exported_at'], 'idx_partition_exports_parent_time');
                $table->index('exported_at', 'idx_partition_exports_time');
            });
        }

        // 2. computed_at column on the financial MVs (G-234).
        //    Schema::table does not work on materialized views, so use raw
        //    ALTER MATERIALIZED VIEW with information_schema guards.
        foreach (self::MATERIALIZED_VIEWS_WITH_COMPUTED_AT as $mvName) {
            $mvExists = DB::selectOne("
                SELECT 1
                FROM pg_matviews
                WHERE matviewname = ?
            ", [$mvName]);

            if ($mvExists === null) {
                continue;
            }

            $hasColumn = DB::selectOne("
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = ?
                  AND column_name = 'computed_at'
            ", [$mvName]);

            if ($hasColumn === null) {
                // Add the column with a default of now() so existing rows get
                // a non-null value on backfill. REFRESH MATERIALIZED VIEW
                // repopulates all rows, so after the next refresh every row
                // will have computed_at set by the refresh function (or by
                // the DEFAULT if the function does not set it explicitly).
                DB::statement(
                    'ALTER MATERIALIZED VIEW "' . $mvName . '" ' .
                    'ADD COLUMN computed_at timestamptz NOT NULL DEFAULT now()'
                );
            }
        }
    }

    public function down(): void
    {
        // Drop the computed_at column from each MV (if it exists).
        foreach (self::MATERIALIZED_VIEWS_WITH_COMPUTED_AT as $mvName) {
            $mvExists = DB::selectOne("
                SELECT 1
                FROM pg_matviews
                WHERE matviewname = ?
            ", [$mvName]);

            if ($mvExists === null) {
                continue;
            }

            $hasColumn = DB::selectOne("
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = ?
                  AND column_name = 'computed_at'
            ", [$mvName]);

            if ($hasColumn !== null) {
                DB::statement(
                    'ALTER MATERIALIZED VIEW "' . $mvName . '" ' .
                    'DROP COLUMN computed_at'
                );
            }
        }

        // Drop the partition_exports table.
        Schema::dropIfExists(self::PARTITION_EXPORTS_TABLE);
    }
};
