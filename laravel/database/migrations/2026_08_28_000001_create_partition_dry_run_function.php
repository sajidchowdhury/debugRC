<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 8.1: `partition_dry_run()` SQL function.
 *
 * Goal (roadmap §7.1, lines 508-512): give the operations team a single
 * callable that returns the planning metrics they need to safely schedule a
 * partitioning cutover for an arbitrary table — row count, on-disk size,
 * index size, date range, estimated partitions, disk-space requirement,
 * estimated duration, required lock type, FK-dependency count, and a
 * rollback-complexity rating.
 *
 * The function is read-only: it queries catalog tables (pg_class,
 * pg_namespace, pg_stat_user_tables, pg_constraint) and runs ONE bounded
 * `SELECT min(p_control), max(p_control) FROM <table>` against the user
 * table to determine the date range of the partition key. It does NOT
 * modify any data or DDL.
 *
 * Reference: Phase 10.1 Partitioning & Archival Plan §11.2.
 *
 * Idempotency: `CREATE OR REPLACE FUNCTION` is used so re-running the
 * migration is a no-op. The `down()` method `DROP FUNCTION IF EXISTS`es it.
 *
 * Output columns (exactly per plan §11.2):
 *   - table_name           TEXT  (echo of p_table)
 *   - row_count            BIGINT (from pg_stat_user_tables.n_live_tup —
 *                                  fast estimate, NOT a COUNT(*))
 *   - table_size           TEXT   (pg_size_pretty of pg_table_size)
 *   - index_size           TEXT   (pg_size_pretty of pg_indexes_size)
 *   - date_range_min       TEXT   (min of p_control column, or NULL if not a
 *                                  date/numeric column)
 *   - date_range_max       TEXT   (max of p_control column)
 *   - estimated_partitions INT    (date span in months, or 0 if undeterminable)
 *   - disk_space_needed    TEXT   (roughly = table_size, since the data must
 *                                  be copied during partitioning cutover)
 *   - estimated_duration   TEXT   (heuristic "~N minutes" based on row count)
 *   - lock_type            TEXT   ('ACCESS EXCLUSIVE' — required for ALTER
 *                                  TABLE partitioning)
 *   - fk_children_count    INT    (count of declarative FKs referencing this
 *                                  table, from pg_constraint contype='f')
 *   - rollback_complexity  TEXT   ('HIGH' if fk_children_count > 0,
 *                                  else 'LOW')
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // partition_dry_run(p_table TEXT, p_control TEXT)
        // ============================================================
        // Returns a single-row table of planning metrics for a future
        // partitioning operation on `public.p_table`, partitioned by the
        // `p_control` column.
        //
        // Implementation notes:
        //   - Table OID is resolved via pg_class + pg_namespace. If the table
        //     doesn't exist in `public`, an exception is raised.
        //   - row_count comes from pg_stat_user_tables.n_live_tup — this is
        //     the planner's statistics estimate, refreshed by ANALYZE, and
        //     is fast (no table scan). It can be slightly stale if ANALYZE
        //     hasn't run recently.
        //   - table_size / index_size use pg_table_size() / pg_indexes_size()
        //     wrapped in pg_size_pretty() for human-readable output.
        //   - date_range_min/max use EXECUTE format(... '%I' ...) to safely
        //     interpolate the column name. Wrapped in BEGIN/EXCEPTION so a
        //     non-date column doesn't abort the whole function — they just
        //     come back NULL.
        //   - estimated_partitions = months between date_range_min and max.
        //     0 if either is NULL.
        //   - estimated_duration heuristic: 1M rows ≈ 2 minutes (linear).
        //     This is a planning aid, not a SLA.
        //   - lock_type is always 'ACCESS EXCLUSIVE' — ALTER TABLE ... PARTITION
        //     OF / ATTACH PARTITION requires it.
        //   - fk_children_count = pg_constraint rows WHERE contype='f' AND
        //     confrelid = table_oid.
        //   - rollback_complexity: HIGH if there are any declarative FK
        //     children (must drop + re-create them as trigger-based BEFORE
        //     partitioning), else LOW.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION partition_dry_run(
                p_table   TEXT,
                p_control TEXT
            ) RETURNS TABLE(
                table_name           TEXT,
                row_count            BIGINT,
                table_size           TEXT,
                index_size           TEXT,
                date_range_min       TEXT,
                date_range_max       TEXT,
                estimated_partitions INT,
                disk_space_needed    TEXT,
                estimated_duration   TEXT,
                lock_type            TEXT,
                fk_children_count    INT,
                rollback_complexity  TEXT
            ) AS $$
            DECLARE
                v_oid          OID;
                v_row_count    BIGINT;
                v_table_bytes  BIGINT;
                v_index_bytes  BIGINT;
                v_min          TEXT;
                v_max          TEXT;
                v_months       INT;
                v_fk_count     INT;
                v_duration_min INT;
            BEGIN
                -- Resolve the table OID in the public schema.
                SELECT c.oid
                  INTO v_oid
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public'
                   AND c.relname = p_table;

                IF v_oid IS NULL THEN
                    RAISE EXCEPTION 'Table public.% does not exist.', p_table
                        USING ERRCODE = '42P01';
                END IF;

                -- Row count: fast estimate from pg_stat_user_tables.
                SELECT s.n_live_tup
                  INTO v_row_count
                  FROM pg_stat_user_tables s
                 WHERE s.relid = v_oid;

                v_row_count := COALESCE(v_row_count, 0);

                -- Table + index sizes (raw bytes for arithmetic; pretty for output).
                v_table_bytes := pg_table_size(v_oid);
                v_index_bytes := pg_indexes_size(v_oid);

                -- Date range of the control column.
                -- Wrapped in BEGIN/EXCEPTION so a non-date column (or a
                -- column that doesn't exist) doesn't abort the whole function.
                BEGIN
                    EXECUTE format('SELECT min(%I)::TEXT, max(%I)::TEXT FROM public.%I',
                                   p_control, p_control, p_table)
                       INTO v_min, v_max;
                EXCEPTION WHEN OTHERS THEN
                    v_min := NULL;
                    v_max := NULL;
                END;

                -- Estimated number of monthly partitions spanning the range.
                IF v_min IS NULL OR v_max IS NULL THEN
                    v_months := 0;
                ELSE
                    -- Cast back to date for the months-between arithmetic.
                    -- If the column isn't date-castable, fall back to 0.
                    BEGIN
                        v_months := GREATEST(
                            EXTRACT(YEAR FROM age(v_max::DATE, v_min::DATE))::INT * 12
                          + EXTRACT(MONTH FROM age(v_max::DATE, v_min::DATE))::INT,
                            0
                        );
                    EXCEPTION WHEN OTHERS THEN
                        v_months := 0;
                    END;
                END IF;

                -- Declarative FK children count.
                SELECT count(*)
                  INTO v_fk_count
                  FROM pg_constraint
                 WHERE contype = 'f'
                   AND confrelid = v_oid;

                -- Estimated duration: heuristic 1M rows ≈ 2 minutes (linear).
                v_duration_min := GREATEST((v_row_count / 500000)::INT, 1);

                RETURN QUERY SELECT
                    p_table                                   AS table_name,
                    v_row_count                               AS row_count,
                    pg_size_pretty(v_table_bytes)             AS table_size,
                    pg_size_pretty(v_index_bytes)             AS index_size,
                    v_min                                     AS date_range_min,
                    v_max                                     AS date_range_max,
                    v_months                                  AS estimated_partitions,
                    pg_size_pretty(v_table_bytes)             AS disk_space_needed,
                    '~' || v_duration_min || ' minutes'      AS estimated_duration,
                    'ACCESS EXCLUSIVE'                        AS lock_type,
                    v_fk_count                                AS fk_children_count,
                    CASE WHEN v_fk_count > 0
                         THEN 'HIGH'
                         ELSE 'LOW'
                    END                                       AS rollback_complexity;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Log::info('Phase 8.1: partition_dry_run(TEXT, TEXT) function created.');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS partition_dry_run(TEXT, TEXT)');

        Log::info('Phase 8.1: partition_dry_run(TEXT, TEXT) function dropped.');
    }
};
