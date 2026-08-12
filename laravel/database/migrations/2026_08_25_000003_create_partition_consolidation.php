<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 7.4: Partition consolidation cron.
 *
 * Audit finding (roadmap §6.4, lines 472-491): After 3–7 years of monthly
 * partitions, even detached-and-archived parents accumulate catalog bloat
 * (pg_partman creates ~12 partitions/year/parent × ~30 parents = ~360
 * partitions/year across the system). Plan §8.3 prescribes consolidating
 * old monthly partitions into quarterly (3–7 years old) and yearly (7+ years
 * old) partitions to keep the live catalog small without losing any data.
 *
 * This migration installs two PL/pgSQL functions and a pg_cron job:
 *
 *   1. consolidate_partitions(p_parent, p_strategy, p_dry_run DEFAULT false)
 *      RETURNS TABLE(consolidated TEXT, dropped TEXT[])
 *
 *      For p_strategy = 'quarterly':
 *        - Finds monthly child partitions of `p_parent` whose end-date is
 *          older than 36 months (i.e. the partition is FULLY past the
 *          threshold — no partial quarters).
 *        - Groups them by (year, quarter).
 *        - For each group: DETACHes each monthly partition, CREATEs a new
 *          `<parent>_<YYYY>Q<n>` partition as a child of the parent with
 *          range [quarter-start, quarter-end), INSERTs the data from each
 *          detached monthly table into the new quarterly partition, then
 *          DROPs the monthly tables.
 *
 *      For p_strategy = 'yearly':
 *        - Finds partitions (monthly OR previously-consolidated quarterly)
 *          older than 84 months.
 *        - Groups by year.
 *        - Consolidates into a `<parent>_<YYYY>` partition with range
 *          [Jan-1, next-year-Jan-1).
 *
 *      p_dry_run = true: returns the (consolidated_name, dropped_names[])
 *      pairs WITHOUT performing any DDL. Safe for inspection.
 *
 *      Conservative rules:
 *        - Only partitions whose END date is strictly before the cutoff are
 *          eligible. This means a quarter ending 2023-04-01 is eligible for
 *          quarterly consolidation only after 2023-04-01 + 36 months has
 *          passed (i.e. after 2026-04-01).
 *        - Groups where the target consolidated partition already exists
 *          are SKIPPED (idempotency) — this allows safe re-runs and partial
 *          recovery from interrupted consolidations.
 *        - Errors during a single group's consolidation are logged as
 *          WARNINGs and the function continues with the next group — one
 *          bad group doesn't block the rest of the run.
 *
 *   2. run_quarterly_consolidation() RETURNS TABLE(parent, consolidated, dropped)
 *
 *      Wrapper that calls `consolidate_partitions()` for the 4 highest-volume
 *      parents: journal_entries, journal_lines, stock_transactions,
 *      sales_invoices. Uses strategy='quarterly', dry_run=false.
 *
 *      (Yearly consolidation is intentionally NOT scheduled automatically —
 *      the data volumes at 7+ years are small enough that a yearly run can
 *      be triggered manually when the first parents reach that age. The
 *      `consolidate_partitions()` function supports it; we just don't cron
 *      it to avoid surprise DDL on ancient data.)
 *
 *   3. pg_cron job 'partition-consolidation' scheduled at 04:00 on the 1st
 *      of Jan/Apr/Jul/Oct, calling `SELECT * FROM run_quarterly_consolidation()`.
 *
 *      This runs 30 minutes BEFORE the `partition:export-parquet` artisan
 *      command (scheduled at 04:30 via routes/console.php) so exports
 *      operate on already-consolidated partitions.
 *
 * Idempotency:
 *   - `CREATE OR REPLACE FUNCTION` makes the migration re-runnable.
 *   - The cron job is unscheduled before re-scheduling.
 *   - The `consolidate_partitions()` function itself is idempotent (skips
 *     groups where the target partition already exists).
 *
 * Naming convention compatibility:
 *   - Phase 1-6 migrations manually create monthly partitions named
 *     `<parent>_YYYY_MM` (e.g. `journal_entries_2026_01`).
 *   - pg_partman 5.x auto-creates future partitions named `<parent>_pYYYY_MM`
 *     (e.g. `journal_entries_p2027_01`).
 *   - Both patterns contain `_<YYYY>_<MM>` as a suffix — the regex
 *     `_(\d{4})_(\d{2})$` matches both, so this function works regardless
 *     of which naming convention produced the partition.
 *
 * DDL ordering within a single group consolidation:
 *
 *   1. DETACH each monthly partition from the parent (they become standalone
 *      tables in `public`).
 *   2. CREATE TABLE <new_name> PARTITION OF <parent> FOR VALUES FROM ... TO ...
 *      — succeeds because no child now overlaps the new range.
 *   3. INSERT INTO <new_name> SELECT * FROM each detached monthly table.
 *   4. DROP TABLE each detached monthly table.
 *
 *   If step 2 fails (e.g. because a quarterly partition with the same name
 *   already exists, or a CHECK constraint conflicts), the monthly partitions
 *   are still detached — manual recovery is required. The function logs a
 *   WARNING and continues with the next group.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. consolidate_partitions(p_parent, p_strategy, p_dry_run)
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION consolidate_partitions(
                p_parent    TEXT,
                p_strategy  TEXT,
                p_dry_run   BOOLEAN DEFAULT false
            ) RETURNS TABLE(consolidated TEXT, dropped TEXT[]) AS $$
            DECLARE
                v_threshold_months INT;
                v_cutoff           DATE;
                v_parent_oid       OID;
                v_group            RECORD;
                v_new_name         TEXT;
                v_new_start        DATE;
                v_new_end          DATE;
                v_dropped          TEXT[];
                v_part             RECORD;
                v_exists           BOOLEAN;
                v_year_int         INT;
            BEGIN
                -- Validate strategy.
                IF p_strategy NOT IN ('quarterly', 'yearly') THEN
                    RAISE EXCEPTION 'Invalid p_strategy=% (must be ''quarterly'' or ''yearly'')', p_strategy
                        USING ERRCODE = '22023';
                END IF;

                -- Threshold: 36 months for quarterly, 84 months for yearly.
                v_threshold_months := CASE WHEN p_strategy = 'quarterly' THEN 36 ELSE 84 END;
                v_cutoff := (CURRENT_DATE - make_interval(months => v_threshold_months))::DATE;

                -- Resolve parent OID.
                SELECT c.oid INTO v_parent_oid
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = p_parent;

                IF v_parent_oid IS NULL THEN
                    RAISE EXCEPTION 'Parent table public.% does not exist.', p_parent
                        USING ERRCODE = '42P01';
                END IF;

                IF p_strategy = 'quarterly' THEN
                    -- ----------------------------------------------------------
                    -- QUARTERLY strategy
                    -- ----------------------------------------------------------
                    -- Eligible: monthly partitions whose p_end < v_cutoff.
                    -- Group by (year, quarter). For each group with ≥1 partition,
                    -- create a new <parent>_YYYYQn partition covering the quarter.
                    -- ----------------------------------------------------------
                    FOR v_group IN
                        WITH children AS (
                            SELECT c.relname,
                                   regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') AS ym
                            FROM pg_inherits i
                            JOIN pg_class c ON c.oid = i.inhrelid
                            WHERE i.inhparent = v_parent_oid
                        ),
                        monthly AS (
                            SELECT relname,
                                   ym[1]::INT AS yr,
                                   ym[2]::INT AS mo,
                                   make_date(ym[1]::INT, ym[2]::INT, 1) AS p_start,
                                   (make_date(ym[1]::INT, ym[2]::INT, 1)
                                       + INTERVAL '1 month')::DATE AS p_end
                            FROM children
                            WHERE ym IS NOT NULL
                              AND ym[2]::INT BETWEEN 1 AND 12
                        ),
                        eligible AS (
                            SELECT * FROM monthly
                            WHERE p_end < v_cutoff
                        )
                        SELECT yr,
                               ((mo - 1) / 3) + 1 AS qtr,
                               make_date(yr, ((mo - 1) / 3) * 3 + 1, 1) AS g_start,
                               -- g_end = quarter start + 3 months. Using interval
                               -- addition (not make_date with month=13) so the year
                               -- rolls over correctly for Q4 (Oct-Dec → Jan 1 next yr).
                               (make_date(yr, ((mo - 1) / 3) * 3 + 1, 1)
                                   + INTERVAL '3 months')::DATE AS g_end
                        FROM eligible
                        GROUP BY yr, ((mo - 1) / 3) + 1
                        ORDER BY yr, ((mo - 1) / 3) + 1
                    LOOP
                        v_new_name  := p_parent || '_' || v_group.yr || 'Q' || v_group.qtr;
                        v_new_start := v_group.g_start;
                        v_new_end   := v_group.g_end;

                        -- Idempotency: skip if the consolidated partition already exists.
                        SELECT EXISTS (
                            SELECT 1 FROM pg_class c
                            JOIN pg_namespace n ON n.oid = c.relnamespace
                            WHERE n.nspname = 'public' AND c.relname = v_new_name
                        ) INTO v_exists;
                        IF v_exists THEN
                            RAISE NOTICE 'Skipping %: already consolidated', v_new_name;
                            CONTINUE;
                        END IF;

                        IF p_dry_run THEN
                            SELECT array_agg(relname ORDER BY relname)
                            INTO v_dropped
                            FROM pg_inherits i
                            JOIN pg_class c ON c.oid = i.inhrelid
                            WHERE i.inhparent = v_parent_oid
                              AND regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') IS NOT NULL
                              AND (regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[1]::INT = v_group.yr
                              AND ((regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[2]::INT - 1) / 3 + 1 = v_group.qtr;

                            consolidated := v_new_name;
                            dropped      := COALESCE(v_dropped, ARRAY[]::TEXT[]);
                            RETURN NEXT;
                            CONTINUE;
                        END IF;

                        BEGIN
                            v_dropped := ARRAY[]::TEXT[];

                            -- Step 1: detach all monthly partitions in this group.
                            FOR v_part IN
                                SELECT c.relname
                                FROM pg_inherits i
                                JOIN pg_class c ON c.oid = i.inhrelid
                                WHERE i.inhparent = v_parent_oid
                                  AND regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') IS NOT NULL
                                  AND (regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[1]::INT = v_group.yr
                                  AND ((regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[2]::INT - 1) / 3 + 1 = v_group.qtr
                            LOOP
                                EXECUTE format('ALTER TABLE public.%I DETACH PARTITION public.%I',
                                               p_parent, v_part.relname);
                            END LOOP;

                            -- Step 2: create the new quarterly partition as a child.
                            EXECUTE format(
                                'CREATE TABLE public.%I PARTITION OF public.%I FOR VALUES FROM (%L) TO (%L)',
                                v_new_name, p_parent, v_new_start, v_new_end
                            );

                            -- Step 3 + 4: copy data from each detached monthly table, then drop it.
                            FOR v_part IN
                                SELECT c.relname
                                FROM pg_class c
                                JOIN pg_namespace n ON n.oid = c.relnamespace
                                WHERE n.nspname = 'public'
                                  AND c.relname ~ ('^' || p_parent || '_' || v_group.yr || '_[0-9]{2}$')
                            LOOP
                                -- Sanity-check the year capture (the regex above
                                -- already enforces it; this is defense in depth).
                                v_year_int := (regexp_match(v_part.relname, '_([0-9]{4})_([0-9]{2})$'))[1]::INT;
                                IF v_year_int IS NULL OR v_year_int <> v_group.yr THEN
                                    CONTINUE;
                                END IF;

                                EXECUTE format('INSERT INTO public.%I SELECT * FROM public.%I',
                                               v_new_name, v_part.relname);
                                EXECUTE format('DROP TABLE public.%I', v_part.relname);
                                v_dropped := array_append(v_dropped, v_part.relname);
                            END LOOP;

                            consolidated := v_new_name;
                            dropped      := v_dropped;
                            RETURN NEXT;

                        EXCEPTION WHEN OTHERS THEN
                            -- Log and continue with the next group.
                            RAISE WARNING 'Quarterly consolidation failed for % (parent=%): % %',
                                v_new_name, p_parent, SQLERRM, SQLSTATE;
                        END;
                    END LOOP;

                ELSE
                    -- ----------------------------------------------------------
                    -- YEARLY strategy
                    -- ----------------------------------------------------------
                    -- Eligible: partitions (monthly OR quarterly) whose end
                    -- date is older than 84 months. Group by year. For each
                    -- year, create a <parent>_YYYY partition covering [Jan-1,
                    -- next-Jan-1).
                    -- ----------------------------------------------------------
                    FOR v_group IN
                        WITH children AS (
                            SELECT c.relname,
                                   regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') AS ym,
                                   regexp_match(c.relname, '_([0-9]{4})Q([1-4])$')   AS yq
                            FROM pg_inherits i
                            JOIN pg_class c ON c.oid = i.inhrelid
                            WHERE i.inhparent = v_parent_oid
                        ),
                        parsed AS (
                            SELECT relname,
                                   ym[1]::INT AS m_yr, ym[2]::INT AS m_mo,
                                   yq[1]::INT AS q_yr, yq[2]::INT AS q_qtr
                            FROM children
                        ),
                        monthly_end AS (
                            -- Monthly partition end-date = first of next month.
                            SELECT m_yr AS yr,
                                   (make_date(m_yr, m_mo, 1) + INTERVAL '1 month')::DATE AS p_end
                            FROM parsed
                            WHERE m_yr IS NOT NULL AND m_mo BETWEEN 1 AND 12
                        ),
                        quarterly_end AS (
                            -- Quarterly partition end-date = first day after the
                            -- quarter's last month. Computed as (last month of
                            -- quarter + 1 month) via interval addition so Q4
                            -- (month 12 + 1) rolls into Jan 1 of next year
                            -- instead of invalid make_date(yr, 13, 1).
                            SELECT q_yr AS yr,
                                   (make_date(q_yr, q_qtr * 3, 1)
                                       + INTERVAL '1 month')::DATE AS p_end
                            FROM parsed
                            WHERE q_yr IS NOT NULL AND q_qtr BETWEEN 1 AND 4
                        ),
                        eligible AS (
                            SELECT * FROM monthly_end  WHERE p_end < v_cutoff
                            UNION ALL
                            SELECT * FROM quarterly_end WHERE p_end < v_cutoff
                        )
                        SELECT yr,
                               make_date(yr, 1, 1)  AS g_start,
                               make_date(yr + 1, 1, 1) AS g_end
                        FROM eligible
                        GROUP BY yr
                        ORDER BY yr
                    LOOP
                        v_new_name  := p_parent || '_' || v_group.yr;
                        v_new_start := v_group.g_start;
                        v_new_end   := v_group.g_end;

                        -- Idempotency: skip if the yearly partition already exists.
                        SELECT EXISTS (
                            SELECT 1 FROM pg_class c
                            JOIN pg_namespace n ON n.oid = c.relnamespace
                            WHERE n.nspname = 'public' AND c.relname = v_new_name
                        ) INTO v_exists;
                        IF v_exists THEN
                            RAISE NOTICE 'Skipping %: already consolidated', v_new_name;
                            CONTINUE;
                        END IF;

                        IF p_dry_run THEN
                            SELECT array_agg(relname ORDER BY relname)
                            INTO v_dropped
                            FROM pg_inherits i
                            JOIN pg_class c ON c.oid = i.inhrelid
                            WHERE i.inhparent = v_parent_oid
                              AND (
                                  (
                                      regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') IS NOT NULL
                                      AND (regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[1]::INT = v_group.yr
                                  )
                                  OR
                                  (
                                      regexp_match(c.relname, '_([0-9]{4})Q([1-4])$') IS NOT NULL
                                      AND (regexp_match(c.relname, '_([0-9]{4})Q([1-4])$'))[1]::INT = v_group.yr
                                  )
                              );

                            consolidated := v_new_name;
                            dropped      := COALESCE(v_dropped, ARRAY[]::TEXT[]);
                            RETURN NEXT;
                            CONTINUE;
                        END IF;

                        BEGIN
                            v_dropped := ARRAY[]::TEXT[];

                            -- Step 1: detach all monthly + quarterly partitions in this year.
                            FOR v_part IN
                                SELECT c.relname
                                FROM pg_inherits i
                                JOIN pg_class c ON c.oid = i.inhrelid
                                WHERE i.inhparent = v_parent_oid
                                  AND (
                                      (regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$') IS NOT NULL
                                       AND (regexp_match(c.relname, '_([0-9]{4})_([0-9]{2})$'))[1]::INT = v_group.yr)
                                      OR
                                      (regexp_match(c.relname, '_([0-9]{4})Q([1-4])$') IS NOT NULL
                                       AND (regexp_match(c.relname, '_([0-9]{4})Q([1-4])$'))[1]::INT = v_group.yr)
                                  )
                            LOOP
                                EXECUTE format('ALTER TABLE public.%I DETACH PARTITION public.%I',
                                               p_parent, v_part.relname);
                            END LOOP;

                            -- Step 2: create the new yearly partition as a child.
                            EXECUTE format(
                                'CREATE TABLE public.%I PARTITION OF public.%I FOR VALUES FROM (%L) TO (%L)',
                                v_new_name, p_parent, v_new_start, v_new_end
                            );

                            -- Step 3 + 4: copy data from each detached monthly/quarterly
                            -- table into the new yearly partition, then drop it.
                            -- The LIKE pattern catches both _YYYY_MM and _YYYYQn variants.
                            FOR v_part IN
                                SELECT c.relname
                                FROM pg_class c
                                JOIN pg_namespace n ON n.oid = c.relnamespace
                                WHERE n.nspname = 'public'
                                  AND c.relname ~ ('^' || p_parent || '_' || v_group.yr || '(_[0-9]{2}$|Q[1-4]$)')
                            LOOP
                                EXECUTE format('INSERT INTO public.%I SELECT * FROM public.%I',
                                               v_new_name, v_part.relname);
                                EXECUTE format('DROP TABLE public.%I', v_part.relname);
                                v_dropped := array_append(v_dropped, v_part.relname);
                            END LOOP;

                            consolidated := v_new_name;
                            dropped      := v_dropped;
                            RETURN NEXT;

                        EXCEPTION WHEN OTHERS THEN
                            RAISE WARNING 'Yearly consolidation failed for % (parent=%): % %',
                                v_new_name, p_parent, SQLERRM, SQLSTATE;
                        END;
                    END LOOP;
                END IF;

                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 2. run_quarterly_consolidation() — wrapper
        // ============================================================
        // Calls consolidate_partitions(p, 'quarterly', false) for each of the
        // 4 high-volume parents. Forwards each result row with the parent
        // name prepended so callers can tell which parent was consolidated.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION run_quarterly_consolidation()
            RETURNS TABLE(parent TEXT, consolidated TEXT, dropped TEXT[]) AS $$
            DECLARE
                v_parent TEXT;
                v_row   RECORD;
            BEGIN
                FOREACH v_parent IN ARRAY ARRAY[
                    'journal_entries',
                    'journal_lines',
                    'stock_transactions',
                    'sales_invoices'
                ] LOOP
                    FOR v_row IN
                        SELECT * FROM consolidate_partitions(v_parent, 'quarterly', false)
                    LOOP
                        parent       := v_parent;
                        consolidated := v_row.consolidated;
                        dropped      := v_row.dropped;
                        RETURN NEXT;
                    END LOOP;
                END LOOP;
                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Log::info('Phase 7.4: consolidate_partitions() + run_quarterly_consolidation() created.');

        // ============================================================
        // 3. Schedule the quarterly consolidation cron job.
        // ============================================================
        $pgCronAvailable = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_cron'
            ) AS installed
        ");

        if (! ($pgCronAvailable->installed ?? false)) {
            Log::warning(
                'Phase 7.4: pg_cron extension not available — partition-consolidation '
                . 'cron not scheduled. Run run_quarterly_consolidation() manually each '
                . 'quarter or via Laravel scheduler as a fallback.'
            );
            return;
        }

        // Unschedule any existing job with the same name (idempotent).
        try {
            DB::statement("SELECT cron.unschedule('partition-consolidation')");
        } catch (\Throwable $e) {
            // Job doesn't exist yet — safe to ignore.
        }

        // Schedule: 04:00 on the 1st of Jan/Apr/Jul/Oct.
        // This runs 30 minutes BEFORE the `partition:export-parquet` artisan
        // command (scheduled at 04:30 via routes/console.php) so exports
        // operate on already-consolidated partitions.
        //
        // pg_cron's schedule format is standard crontab: min hour dom month dow.
        // `0 4 1 1,4,7,10 *` = 04:00 on day-of-month 1 in months Jan/Apr/Jul/Oct.
        DB::statement(<<<'SQL'
            SELECT cron.schedule(
                'partition-consolidation',
                '0 4 1 1,4,7,10 *',
                $$SELECT * FROM run_quarterly_consolidation()$$
            )
        SQL);

        Log::info('Phase 7.4: partition-consolidation cron scheduled (04:00 on 1st of Jan/Apr/Jul/Oct).');
    }

    public function down(): void
    {
        // 1. Unschedule the cron job.
        try {
            DB::statement("SELECT cron.unschedule('partition-consolidation')");
        } catch (\Throwable $e) {
            // Job doesn't exist — safe to ignore.
        }

        // 2. Drop both functions. Order matters: drop the wrapper first
        // because it depends on consolidate_partitions().
        DB::statement('DROP FUNCTION IF EXISTS run_quarterly_consolidation()');
        DB::statement('DROP FUNCTION IF EXISTS consolidate_partitions(TEXT, TEXT, BOOLEAN)');

        Log::info('Phase 7.4: partition-consolidation cron unscheduled + functions dropped.');
    }
};
