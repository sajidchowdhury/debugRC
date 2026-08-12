<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPORTS-AUDIT-7 (G-228 + G-233 + G-234 / csv-export.md G13/G14 + materialized-views.md G14).
 *
 * REPORTS-AUDIT-FIX-1 (this revision): the original G-234 implementation
 * attempted `ALTER MATERIALIZED VIEW ... ADD COLUMN computed_at` which is
 * NOT supported by PostgreSQL in any version (materialized views cannot
 * have columns added via ALTER — only via DROP + CREATE with the new
 * column baked into the SELECT). The original migration docstring's claim
 * that this works in PG 11+ was factually wrong and blocked
 * `php artisan migrate` in production. This revision replaces the
 * impossible ALTER with an equivalent staleness-detection mechanism:
 * a lightweight `mv_refresh_log` table populated by a trigger on
 * `financial_audit_log`.
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
 *   2. mv_refresh_log table + trigger (G-234) — lets reports detect MV
 *      staleness programmatically (e.g. "MV is older than 1 hour" -> show a
 *      "stale data" badge). The existing `refresh_all_report_views()`
 *      function already INSERTs a row into `financial_audit_log` with
 *      `operation='REFRESH'`, `table_name='mv_X'`, and
 *      `after_data = jsonb_build_object('status','ok','elapsed_ms',N)` after
 *      every successful refresh (and a 'failed' row on exception). An
 *      AFTER INSERT trigger on `financial_audit_log` mirrors those REFRESH
 *      rows into `mv_refresh_log` (UPSERT keyed on mv_name). Reports query
 *      `SELECT refreshed_at, status FROM mv_refresh_log WHERE mv_name = ?`
 *      to determine freshness. This achieves the same goal as a `computed_at`
 *      column on each MV WITHOUT requiring DROP+CREATE of 8 MVs (which would
 *      be risky and lose indexes).
 *
 *      MVs tracked (the 8 financial + mv_consolidated_trial_balance):
 *        - mv_ledger_balances
 *        - mv_ar_aging
 *        - mv_ap_aging
 *        - mv_stock_valuation
 *        - mv_journal_entry_summary
 *        - mv_branch_intercompany
 *        - mv_product_movement_summary
 *        - mv_product_abc_classification  (already has its own computed_at column; also tracked here for uniformity)
 *        - mv_consolidated_trial_balance
 *
 * Idempotent: Schema::hasTable / IF NOT EXISTS / IF EXISTS guards on every
 * statement. Safe to re-run.
 *
 * NOTE on the trigger guard: `trg_fn_audit_log_to_mv_refresh_log()` checks
 * `to_regclass('public.mv_refresh_log') IS NULL` before touching the log
 * table. If the table is ever dropped manually (without dropping the
 * trigger first), the trigger becomes a safe no-op rather than erroring on
 * every `financial_audit_log` insert (which would break inventory
 * mutations that audit through the same table).
 */
return new class extends Migration
{
    private const PARTITION_EXPORTS_TABLE = 'partition_exports';

    private const MV_REFRESH_LOG_TABLE = 'mv_refresh_log';

    /**
     * MVs tracked by mv_refresh_log. Backfilled on up() so the table is not
     * empty before the first refresh. The trigger populates refreshed_at +
     * status on every subsequent refresh.
     */
    private const TRACKED_MATERIALIZED_VIEWS = [
        'mv_ledger_balances',
        'mv_ar_aging',
        'mv_ap_aging',
        'mv_stock_valuation',
        'mv_journal_entry_summary',
        'mv_branch_intercompany',
        'mv_product_movement_summary',
        'mv_product_abc_classification',
        'mv_consolidated_trial_balance',
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

        // 2. mv_refresh_log table (G-234).
        //    Replaces the impossible ALTER MATERIALIZED VIEW ADD COLUMN
        //    approach. One row per MV, UPSERTed by the trigger below.
        if (!Schema::hasTable(self::MV_REFRESH_LOG_TABLE)) {
            Schema::create(self::MV_REFRESH_LOG_TABLE, function (Blueprint $table): void {
                $table->string('mv_name', 80)->primary();
                $table->timestampTz('refreshed_at')->useCurrent()->comment('When the MV was last refreshed (mirrored from financial_audit_log.created_at).');
                $table->integer('duration_ms')->nullable()->comment('Refresh duration in milliseconds (from after_data.elapsed_ms); null on failure.');
                $table->string('status', 10)->default('backfill')->comment('ok / failed / backfill / unknown.');
            });
        }

        // 2a. Backfill one row per tracked MV so the table is not empty
        //     before the first refresh. ON CONFLICT DO NOTHING keeps this
        //     idempotent (re-running the migration will not overwrite a
        //     real refreshed_at with the backfill timestamp).
        foreach (self::TRACKED_MATERIALIZED_VIEWS as $mvName) {
            DB::table(self::MV_REFRESH_LOG_TABLE)
                ->insertOrIgnore([
                    'mv_name' => $mvName,
                    'refreshed_at' => now(),
                    'duration_ms' => null,
                    'status' => 'backfill',
                ]);
        }

        // 2b. Trigger function: mirrors REFRESH rows from financial_audit_log
        //     into mv_refresh_log. The existing refresh_all_report_views()
        //     function already writes one audit-log row per refresh with
        //     operation='REFRESH', table_name='mv_X', and
        //     after_data={status, elapsed_ms}. This trigger captures those
        //     rows and UPSERTs mv_refresh_log. No function rewrite needed.
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION trg_fn_audit_log_to_mv_refresh_log()
RETURNS trigger AS $$
BEGIN
    -- Guard: if mv_refresh_log was dropped manually, no-op so the parent
    -- financial_audit_log insert (which fires this trigger) does not fail.
    -- Without this guard, dropping mv_refresh_log without dropping the
    -- trigger would break every inventory mutation that audits through
    -- financial_audit_log.
    IF to_regclass('public.mv_refresh_log') IS NULL THEN
        RETURN NEW;
    END IF;

    -- Only mirror REFRESH operations on materialized views (table_name
    -- starting with 'mv_'). The 'mv\_' pattern escapes the underscore so
    -- it matches literally (otherwise '_' is a single-char wildcard in
    -- LIKE and would also match e.g. 'mvXfoo').
    IF NEW.operation = 'REFRESH' AND NEW.table_name LIKE 'mv\_%' THEN
        INSERT INTO mv_refresh_log (mv_name, refreshed_at, duration_ms, status)
        VALUES (
            NEW.table_name,
            COALESCE(NEW.created_at, now()),
            (NEW.after_data ->> 'elapsed_ms')::integer,
            COALESCE(NEW.after_data ->> 'status', 'unknown')
        )
        ON CONFLICT (mv_name) DO UPDATE SET
            refreshed_at = EXCLUDED.refreshed_at,
            duration_ms  = EXCLUDED.duration_ms,
            status       = EXCLUDED.status;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        // 2c. Attach the trigger to financial_audit_log. The table is
        //     partitioned by RANGE(created_at); AFTER ROW triggers on the
        //     partitioned parent fire for inserts into any partition
        //     (supported in PostgreSQL 11+). DROP IF EXISTS first for
        //     idempotency.
        DB::statement(
            'DROP TRIGGER IF EXISTS trg_audit_log_mv_refresh ON financial_audit_log'
        );
        DB::statement(<<<'SQL'
CREATE TRIGGER trg_audit_log_mv_refresh
    AFTER INSERT ON financial_audit_log
    FOR EACH ROW
    EXECUTE FUNCTION trg_fn_audit_log_to_mv_refresh_log()
SQL);
    }

    public function down(): void
    {
        // 2. Drop the trigger + trigger function + mv_refresh_log table.
        //    Order matters: trigger first, then function, then table.
        DB::statement(
            'DROP TRIGGER IF EXISTS trg_audit_log_mv_refresh ON financial_audit_log'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS trg_fn_audit_log_to_mv_refresh_log()'
        );
        Schema::dropIfExists(self::MV_REFRESH_LOG_TABLE);

        // 1. Drop the partition_exports table.
        Schema::dropIfExists(self::PARTITION_EXPORTS_TABLE);
    }
};
