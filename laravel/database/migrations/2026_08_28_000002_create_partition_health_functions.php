<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 8.2: Health-check SQL functions.
 *
 * Goal (roadmap §7.2, lines 514-524): provide a small library of SQL
 * functions that the Phase 8.3 daily cron job can call to populate the
 * `partition_health_alerts` table. Each function returns a TABLE of rows
 * describing a single class of problem; the cron job UNION ALLs them into
 * the alerts table with appropriate severity.
 *
 * Six functions (all `LANGUAGE plpgsql`, `CREATE OR REPLACE`, `RETURNS TABLE`):
 *
 *   1. check_future_partitions()
 *      → (parent_table TEXT, missing_months INT)
 *      For each table in `partman.part_config`, count partitions whose
 *      bound START date is > CURRENT_DATE + 3 months. If < 3 such future
 *      partitions exist, return (parent, 3 - count) — the deficit.
 *
 *   2. check_default_partitions()
 *      → (parent_table TEXT, row_count BIGINT)
 *      For each partitioned parent in `public`, find the `_default` child
 *      (via pg_inherits, child relname LIKE '%_default'). If it exists and
 *      has > 0 rows (COUNT(*)), return (parent, row_count). Rows in the
 *      default partition indicate a row whose partition key doesn't match
 *      any existing child — usually a sign that future partitions aren't
 *      being created (links to check #1).
 *
 *   3. check_partman_stale()
 *      → (parent_table TEXT, last_maintenance TIMESTAMPTZ)
 *      Tables in `partman.part_config` where `last_maintenance` is older
 *      than 24 hours — i.e. the daily pg_cron partman job at 02:00 hasn't
 *      run successfully. The `last_maintenance` column exists in both
 *      pg_partman 4.x and 5.x; if absent (very old version), the function
 *      emits a NOTICE and returns an empty result.
 *
 *   4. check_retention_configured()
 *      → (parent_table TEXT)
 *      Tables in `partman.part_config` where `retention IS NULL OR
 *      retention = ''` — i.e. partitions will accumulate forever.
 *
 *   5. check_brin_index_usage()
 *      → (indexname TEXT, tablename TEXT)
 *      BRIN indexes (amname='brin') where pg_stat_user_indexes.idx_scan = 0
 *      — the planner is not using them. Likely cause: stats are stale, or
 *      the query patterns don't match the BRIN column.
 *
 *   6. check_trigger_fks_functional()
 *      → (child_table TEXT, fk_column TEXT, status TEXT)
 *      Structural check only: lists all trigger-based FK *check* functions
 *      (functions named `fn_trg_%_fk` per Phase 0.3/6.6 convention, or
 *      `trg_%_fk_check` per Phase 5 convention) discovered in pg_proc,
 *      joined to pg_trigger to find the child table they protect. Returns
 *      one row per (child_table, function_name) with status='EXISTS'.
 *
 *      IMPORTANT LIMITATION: this is NOT a functional test. A full
 *      functional test would attempt a known-bad INSERT (e.g. orphan FK
 *      value) and verify the trigger blocks it. Such a test requires a
 *      staging DB with sample data and is destructive — it's out of scope
 *      for a daily cron. The 8B-agent artisan command
 *      `partition:verify-fks` (out of scope here) will perform the
 *      functional test on demand against staging.
 *
 * Idempotency: all functions are `CREATE OR REPLACE`. The `down()` method
 * `DROP FUNCTION IF EXISTS`es each.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. check_future_partitions()
        // ============================================================
        // For each parent in partman.part_config, count child partitions
        // whose START bound is strictly after CURRENT_DATE + 3 months.
        // If < 3 such future partitions, return the deficit.
        //
        // Implementation:
        //   - Iterate partman.part_config rows.
        //   - For each parent_table, resolve its OID via pg_class + pg_namespace.
        //   - Find child partitions via pg_inherits.
        //   - Parse the relpartbound text (pg_get_expr(c.relpartbound, c.oid))
        //     to extract the FROM ('YYYY-MM-DD') date using a regex.
        //   - Count children whose start_date > CURRENT_DATE + 3 months.
        //   - If count < 3, RETURN NEXT (parent_table, 3 - count).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_future_partitions()
            RETURNS TABLE(parent_table TEXT, missing_months INT) AS $$
            DECLARE
                v_rec           RECORD;
                v_parent_oid    OID;
                v_future_count  INT;
            BEGIN
                FOR v_rec IN
                    SELECT pc.parent_table
                      FROM partman.part_config pc
                     ORDER BY pc.parent_table
                LOOP
                    -- Resolve parent OID in public schema.
                    SELECT c.oid INTO v_parent_oid
                      FROM pg_class c
                      JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public'
                       AND c.relname = v_rec.parent_table;

                    IF v_parent_oid IS NULL THEN
                        -- part_config row references a table that no longer exists.
                        CONTINUE;
                    END IF;

                    -- Count child partitions whose bound starts after CURRENT_DATE + 3 months.
                    SELECT count(*) INTO v_future_count
                      FROM pg_inherits i
                      JOIN pg_class c ON c.oid = i.inhrelid
                     WHERE i.inhparent = v_parent_oid
                       AND pg_get_expr(c.relpartbound, c.oid) ~ 'FROM \(''([0-9]{4}-[0-9]{2}-[0-9]{2})''\)'
                       AND (regexp_match(
                              pg_get_expr(c.relpartbound, c.oid),
                              'FROM \(''([0-9]{4}-[0-9]{2}-[0-9]{2})''\)'
                            ))[1]::DATE > (CURRENT_DATE + INTERVAL '3 months')::DATE;

                    IF v_future_count < 3 THEN
                        parent_table   := v_rec.parent_table;
                        missing_months := 3 - v_future_count;
                        RETURN NEXT;
                    END IF;
                END LOOP;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 2. check_default_partitions()
        // ============================================================
        // For each partitioned parent (pg_partitioned_table) in public,
        // find the `_default` child and count its rows. If > 0, return.
        //
        // The COUNT(*) is bounded by the default partition's size, which
        // is normally tiny (zero rows). If it's grown large, this check
        // is the early-warning signal.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_default_partitions()
            RETURNS TABLE(parent_table TEXT, row_count BIGINT) AS $$
            DECLARE
                v_parent     RECORD;
                v_default    TEXT;
                v_count      BIGINT;
            BEGIN
                FOR v_parent IN
                    SELECT c.relname AS parent_name,
                           c.oid     AS parent_oid
                      FROM pg_partitioned_table pt
                      JOIN pg_class c ON c.oid = pt.partrelid
                      JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public'
                     ORDER BY c.relname
                LOOP
                    -- Find the _default child for this parent.
                    SELECT cc.relname INTO v_default
                      FROM pg_inherits i
                      JOIN pg_class cc ON cc.oid = i.inhrelid
                     WHERE i.inhparent = v_parent.parent_oid
                       AND cc.relname LIKE '%_default'
                     LIMIT 1;

                    IF v_default IS NULL THEN
                        CONTINUE;
                    END IF;

                    -- Count rows in the default partition.
                    BEGIN
                        EXECUTE format('SELECT count(*)::BIGINT FROM public.%I', v_default)
                           INTO v_count;
                    EXCEPTION WHEN OTHERS THEN
                        -- e.g. default partition has been detached or dropped concurrently
                        v_count := 0;
                    END;

                    IF v_count > 0 THEN
                        parent_table := v_parent.parent_name;
                        row_count    := v_count;
                        RETURN NEXT;
                    END IF;

                    v_default := NULL;
                END LOOP;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 3. check_partman_stale()
        // ============================================================
        // Returns partman parents whose last_maintenance is > 24h old.
        //
        // The `last_maintenance` column exists in both pg_partman 4.x and
        // 5.x. If for some reason it doesn't exist (very old or stripped
        // pg_partman build), the function emits a NOTICE and returns
        // empty (rather than raising an exception that would break the
        // daily cron's UNION ALL).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_partman_stale()
            RETURNS TABLE(parent_table TEXT, last_maintenance TIMESTAMPTZ) AS $$
            DECLARE
                v_has_column BOOLEAN;
            BEGIN
                SELECT EXISTS (
                    SELECT 1
                      FROM information_schema.columns
                     WHERE table_schema = 'partman'
                       AND table_name   = 'part_config'
                       AND column_name  = 'last_maintenance'
                ) INTO v_has_column;

                IF NOT v_has_column THEN
                    RAISE NOTICE 'partman.part_config.last_maintenance column not found — skipping stale check.';
                    RETURN;
                END IF;

                RETURN QUERY
                SELECT pc.parent_table::TEXT,
                       pc.last_maintenance::TIMESTAMPTZ
                  FROM partman.part_config pc
                 WHERE pc.last_maintenance < (NOW() - INTERVAL '24 hours')
                 ORDER BY pc.parent_table;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 4. check_retention_configured()
        // ============================================================
        // Returns partman parents with no retention configured.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_retention_configured()
            RETURNS TABLE(parent_table TEXT) AS $$
            BEGIN
                RETURN QUERY
                SELECT pc.parent_table::TEXT
                  FROM partman.part_config pc
                 WHERE pc.retention IS NULL
                    OR pc.retention = ''
                 ORDER BY pc.parent_table;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 5. check_brin_index_usage()
        // ============================================================
        // Returns BRIN indexes that have never been scanned (idx_scan = 0).
        //
        // Joins pg_stat_user_indexes → pg_index → pg_class (index) → pg_am
        // to filter on amname='brin'. Only indexes on user tables in the
        // public schema are considered.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_brin_index_usage()
            RETURNS TABLE(indexname TEXT, tablename TEXT) AS $$
            BEGIN
                RETURN QUERY
                SELECT c.relname::TEXT  AS indexname,
                       t.relname::TEXT  AS tablename
                  FROM pg_stat_user_indexes s
                  JOIN pg_index     i ON i.indexrelid = s.indexrelid
                  JOIN pg_class     c ON c.oid        = s.indexrelid
                  JOIN pg_class     t ON t.oid        = s.relid
                  JOIN pg_namespace n ON n.oid        = c.relnamespace
                  JOIN pg_am        a ON a.oid        = c.relam
                 WHERE n.nspname = 'public'
                   AND a.amname  = 'brin'
                   AND s.idx_scan = 0
                 ORDER BY t.relname, c.relname;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 6. check_trigger_fks_functional()
        // ============================================================
        // Structural check: list all trigger-based FK *check* functions
        // (the BEFORE INSERT OR UPDATE triggers on child tables).
        //
        // Phase 0.3 / 6.6 naming: `fn_trg_<child>_<col>_je_fk`.
        // Phase 5 naming:         `trg_<child>_<parent>_fk_check`.
        //
        // The `fk_column` column of the returned row contains the
        // function NAME (not the column name) — this is intentional, as
        // reliably extracting the column from the function name is not
        // possible without a metadata table. The controller can join on
        // pg_trigger to discover the actual column if needed.
        //
        // Cascade triggers (named `*_cascade` or `trg_*_del_cascade_*`)
        // are deliberately EXCLUDED — they live on the parent and would
        // produce misleading (parent_table, parent_function) tuples.
        //
        // LIMITATION: this is NOT a functional test. A functional test
        // would attempt a known-bad INSERT and assert the trigger blocks
        // it. Such a test is destructive and requires a staging DB with
        // sample data. The 8B-agent artisan command
        // `partition:verify-fks` will perform the functional test on
        // demand.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_trigger_fks_functional()
            RETURNS TABLE(child_table TEXT, fk_column TEXT, status TEXT) AS $$
            BEGIN
                RETURN QUERY
                SELECT DISTINCT
                       child.relname::TEXT                       AS child_table,
                       p.proname::TEXT                           AS fk_column,
                       'EXISTS'::TEXT                            AS status
                  FROM pg_proc p
                  JOIN pg_namespace n   ON n.oid = p.pronamespace
                  JOIN pg_trigger  t    ON t.tgfoid = p.oid
                                        AND NOT t.tgisinternal
                  JOIN pg_class    child ON child.oid = t.tgrelid
                 WHERE n.nspname = 'public'
                   AND child.relnamespace = (
                       SELECT oid FROM pg_namespace WHERE nspname = 'public'
                   )
                   -- Match the Phase 0.3 / 6.6 BEFORE INSERT OR UPDATE
                   -- trigger function naming: `fn_trg_<child>_<col>_je_fk`
                   -- (excludes the `*_je_cascade` AFTER DELETE functions).
                   AND (
                       p.proname LIKE 'fn_trg_%_je_fk'
                       OR p.proname LIKE 'fn_trg_%_fk_check'
                       OR p.proname LIKE 'trg_%_fk_check'
                   )
                 ORDER BY child_table, fk_column;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Log::info('Phase 8.2: 6 health-check functions created (check_future_partitions, check_default_partitions, check_partman_stale, check_retention_configured, check_brin_index_usage, check_trigger_fks_functional).');
    }

    public function down(): void
    {
        // Drop in reverse order of creation (no inter-function dependencies,
        // but reverse order is a clean convention).
        DB::statement('DROP FUNCTION IF EXISTS check_trigger_fks_functional()');
        DB::statement('DROP FUNCTION IF EXISTS check_brin_index_usage()');
        DB::statement('DROP FUNCTION IF EXISTS check_retention_configured()');
        DB::statement('DROP FUNCTION IF EXISTS check_partman_stale()');
        DB::statement('DROP FUNCTION IF EXISTS check_default_partitions()');
        DB::statement('DROP FUNCTION IF EXISTS check_future_partitions()');

        Log::info('Phase 8.2: 6 health-check functions dropped.');
    }
};
