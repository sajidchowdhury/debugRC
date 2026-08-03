<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 8.5: Partition statistics views.
 *
 * Goal (roadmap §7.5, lines 585-594): expose the most useful partition
 * monitoring queries as SQL views so the Laravel dashboard (Phase 8.4,
 * built by the parallel 8B agent) can `SELECT * FROM v_partition_sizes`
 * without having to inline complex catalog joins.
 *
 * Five views (all `CREATE OR REPLACE VIEW`, idempotent):
 *
 *   1. v_partition_sizes
 *      One row per partition child. Columns: parent, child,
 *      size_pretty (TEXT), size_bytes (BIGINT), seq_scans (BIGINT),
 *      seq_tuples_read (BIGINT). Joins pg_inherits + pg_class (parent +
 *      child) + pg_stat_user_tables. Column names align with the
 *      PartitionHealthController (Phase 8.4) which SELECTs these directly.
 *
 *   2. v_partition_vacuum_stats
 *      One row per partition child. Columns: parent, child,
 *      last_vacuum, last_autovacuum, last_analyze, last_autoanalyze,
 *      n_dead_tup, n_live_tup, stale_days (INT — days since the most
 *      recent of last_vacuum/last_autovacuum, NULL if never vacuumed).
 *      Source: pg_stat_user_tables.
 *
 *   3. v_default_partition_check
 *      One row per partitioned parent that has a `_default` child.
 *      Columns: parent, default_partition, row_count, size_pretty,
 *      size_bytes. Filter: child relname LIKE '%_default'.
 *
 *   4. v_missing_future_partitions
 *      One row per partman.part_config entry with < 3 future partitions.
 *      Columns: parent, last_partition_date, months_ahead (INT — count of
 *      future partitions found; NULL if partman not configured),
 *      missing_count (INT — 3 - months_ahead, the deficit).
 *      Reuses the relpartbound-parsing logic from check_future_partitions()
 *      but as a pure-SQL view (CTE + regexp_match).
 *
 *   5. v_catalog_bloat
 *      Sizes of pg_class, pg_attribute, pg_depend, pg_constraint,
 *      pg_index — the catalog tables that grow as partition count grows.
 *      Columns: catalog_table, size_pretty, size_bytes, estimated_row_count.
 *
 * Idempotency: `CREATE OR REPLACE VIEW` for each. `down()` drops all 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. v_partition_sizes
        // ============================================================
        // One row per partition child in the public schema. Joins the
        // partition tree (pg_inherits) with pg_class (for the parent +
        // child names) and pg_stat_user_tables (for seq_scan, the count
        // of sequential scans since last reset).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_partition_sizes AS
            SELECT p.relname                           AS parent,
                   c.relname                           AS child,
                   pg_size_pretty(pg_relation_size(c.oid)) AS size_pretty,
                   pg_relation_size(c.oid)             AS size_bytes,
                   s.seq_scan                          AS seq_scans,
                   s.seq_tup_read                      AS seq_tuples_read
              FROM pg_inherits i
              JOIN pg_class p            ON p.oid = i.inhparent
              JOIN pg_class c            ON c.oid = i.inhrelid
              JOIN pg_namespace n_child  ON n_child.oid = c.relnamespace
              JOIN pg_namespace n_parent ON n_parent.oid = p.relnamespace
              LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
             WHERE n_parent.nspname = 'public'
               AND n_child.nspname  = 'public'
             ORDER BY p.relname, c.relname;
        SQL);

        // ============================================================
        // 2. v_partition_vacuum_stats
        // ============================================================
        // One row per partition child with VACUUM/ANALYZE timing + dead
        // tuple count. Lets the dashboard flag partitions that haven't
        // been vacuumed in > 7 days (roadmap §8.4 threshold table).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_partition_vacuum_stats AS
            SELECT p.relname AS parent,
                   c.relname AS child,
                   s.last_vacuum,
                   s.last_autovacuum,
                   s.last_analyze,
                   s.last_autoanalyze,
                   s.n_dead_tup,
                   s.n_live_tup,
                   -- stale_days = days since the most recent of
                   -- last_vacuum / last_autovacuum (NULL if neither has
                   -- ever run). The dashboard flags partitions with
                   -- stale_days > 7 (roadmap §8.4 threshold table).
                   CASE
                     WHEN COALESCE(s.last_vacuum, s.last_autovacuum) IS NULL
                       THEN NULL
                     ELSE EXTRACT(DAY FROM (NOW() - COALESCE(s.last_vacuum, s.last_autovacuum)))::INT
                   END AS stale_days
              FROM pg_inherits i
              JOIN pg_class p            ON p.oid = i.inhparent
              JOIN pg_class c            ON c.oid = i.inhrelid
              JOIN pg_namespace n_parent ON n_parent.oid = p.relnamespace
              JOIN pg_namespace n_child  ON n_child.oid = c.relnamespace
              LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
             WHERE n_parent.nspname = 'public'
               AND n_child.nspname  = 'public';
        SQL);

        // ============================================================
        // 3. v_default_partition_check
        // ============================================================
        // One row per partitioned parent that has a `_default` child.
        // `n_live_tup` is the planner's row estimate (fast, may be stale
        // if ANALYZE hasn't run); `size_bytes` is exact.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_default_partition_check AS
            SELECT p.relname                               AS parent,
                   c.relname                               AS default_partition,
                   s.n_live_tup                            AS row_count,
                   pg_size_pretty(pg_relation_size(c.oid)) AS size_pretty,
                   pg_relation_size(c.oid)                 AS size_bytes
              FROM pg_inherits i
              JOIN pg_class p            ON p.oid = i.inhparent
              JOIN pg_class c            ON c.oid = i.inhrelid
              JOIN pg_namespace n_parent ON n_parent.oid = p.relnamespace
              JOIN pg_namespace n_child  ON n_child.oid = c.relnamespace
              LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
             WHERE n_parent.nspname = 'public'
               AND n_child.nspname  = 'public'
               AND c.relname LIKE '%_default'
             ORDER BY p.relname;
        SQL);

        // ============================================================
        // 4. v_missing_future_partitions
        // ============================================================
        // Mirrors check_future_partitions() (migration 8.2) but as a pure
        // SQL view (CTE + regexp_match on relpartbound). Returns one row
        // per partman parent with fewer than 3 future partitions (bound
        // start > CURRENT_DATE + 3 months).
        //
        // `last_partition_date` is the max bound_start across all the
        // parent's children — useful to show "last created: <date>" in the
        // dashboard even when the deficit is 0.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_missing_future_partitions AS
            WITH partman_parents AS (
                SELECT pc.parent_table,
                       c.oid AS parent_oid
                  FROM partman.part_config pc
                  JOIN pg_class c        ON c.relname = pc.parent_table
                  JOIN pg_namespace n    ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public'
            ),
            children AS (
                SELECT pp.parent_table,
                       pp.parent_oid,
                       c.relname AS child_name,
                       (regexp_match(
                           pg_get_expr(c.relpartbound, c.oid),
                           'FROM \(''([0-9]{4}-[0-9]{2}-[0-9]{2})''\)'
                       ))[1]::DATE AS bound_start
                  FROM partman_parents pp
                  JOIN pg_inherits i ON i.inhparent = pp.parent_oid
                  JOIN pg_class c    ON c.oid = i.inhrelid
            ),
            agg AS (
                SELECT parent_table,
                       count(*) FILTER (
                           WHERE bound_start > (CURRENT_DATE + INTERVAL '3 months')::DATE
                       ) AS future_count,
                       max(bound_start) AS last_partition_date
                  FROM children
                 GROUP BY parent_table
            )
            SELECT parent_table AS parent,
                   last_partition_date,
                   future_count AS months_ahead,
                   (3 - future_count) AS missing_count
              FROM agg
             WHERE future_count < 3
             ORDER BY parent_table;
        SQL);

        // ============================================================
        // 5. v_catalog_bloat
        // ============================================================
        // Sizes of the 5 pg_catalog tables that grow with partition count.
        // A healthy system shows roughly linear growth; a sudden spike
        // (e.g. after a runaway pg_partman run that creates 100s of
        // partitions) will be visible here.
        //
        // Uses pg_stat_all_tables (not pg_stat_user_tables) because these
        // are system catalogs in pg_catalog, not user tables.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_catalog_bloat AS
            SELECT c.relname                               AS catalog_table,
                   pg_size_pretty(pg_relation_size(c.oid)) AS size_pretty,
                   pg_relation_size(c.oid)                 AS size_bytes,
                   s.n_live_tup                            AS estimated_row_count
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
              LEFT JOIN pg_stat_all_tables s ON s.relid = c.oid
             WHERE n.nspname = 'pg_catalog'
               AND c.relkind = 'r'
               AND c.relname IN (
                   'pg_class',
                   'pg_attribute',
                   'pg_depend',
                   'pg_constraint',
                   'pg_index'
               )
             ORDER BY pg_relation_size(c.oid) DESC;
        SQL);

        Log::info('Phase 8.5: 5 partition-statistics views created (v_partition_sizes, v_partition_vacuum_stats, v_default_partition_check, v_missing_future_partitions, v_catalog_bloat).');
    }

    public function down(): void
    {
        // Drop in reverse order of creation (no inter-view dependencies,
        // but reverse order is a clean convention).
        DB::statement('DROP VIEW IF EXISTS v_catalog_bloat');
        DB::statement('DROP VIEW IF EXISTS v_missing_future_partitions');
        DB::statement('DROP VIEW IF EXISTS v_default_partition_check');
        DB::statement('DROP VIEW IF EXISTS v_partition_vacuum_stats');
        DB::statement('DROP VIEW IF EXISTS v_partition_sizes');

        Log::info('Phase 8.5: 5 partition-statistics views dropped.');
    }
};
