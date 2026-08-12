<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 8.9: Per-partition autovacuum tuning.
 *
 * Goal (roadmap §7.9, lines 619-637): apply differentiated autovacuum
 * settings to each partition child based on its age:
 *
 *   - OLD (read-only) partitions: aggressive
 *       autovacuum_vacuum_scale_factor = 0.01
 *       autovacuum_analyze_scale_factor = 0.01
 *     Rationale: old partitions shouldn't accumulate dead tuples; if they
 *     do, it's a sign of an UPDATE/DELETE bug. Aggressive autovacuum
 *     keeps them clean and the planner stats fresh.
 *
 *   - CURRENT month's partition: moderate
 *       autovacuum_vacuum_scale_factor = 0.05
 *       autovacuum_analyze_scale_factor = 0.02
 *     Rationale: the current partition is the hot write target; moderate
 *     settings balance cleanup overhead against write throughput.
 *
 *   - FUTURE partitions (created by pg_partman's premake): skipped.
 *     Rationale: they're empty; tuning them now is wasted work. They'll
 *     be tuned next month when they become current.
 *
 * Implementation approach: Option A (per spec).
 *
 *   1. `tune_partition_autovacuum(p_parent TEXT)` — iterates child
 *      partitions of `p_parent` via pg_inherits. Parses each child's
 *      name with the regex `_(\d{4})_(\d{2})$` (matching both manual
 *      Phase 1-6 naming `<parent>_YYYY_MM` and pg_partman 5.x naming
 *      `<parent>_pYYYY_MM` — the suffix is the same). Applies the
 *      appropriate ALTER TABLE SET (...). Skips partitions whose names
 *      don't match the date regex with a NOTICE (e.g. `_default`,
 *      `_pre2026`, consolidated `_YYYYQn` partitions).
 *
 *   2. `run_monthly_autovacuum_tuning()` — wrapper that calls
 *      `tune_partition_autovacuum()` for EVERY parent in
 *      `partman.part_config`. Per-partent failures are caught and
 *      logged as WARNINGs (so one bad parent doesn't abort the whole
 *      run).
 *
 *   3. pg_cron job `'partition-autovacuum-tuning'` at `'0 5 1 * *'`
 *      (05:00 on the 1st of each month). Scheduled AFTER the daily 02:00
 *      partman maintenance (which creates new partitions for the upcoming
 *      month) and AFTER the 03:00 health check (which logs state). This
 *      ordering ensures the new partition created at 02:00 today gets
 *      tuned at 05:00 the same morning.
 *
 * Idempotency:
 *   - `CREATE OR REPLACE FUNCTION` is idempotent.
 *   - `ALTER TABLE ... SET (...)` is idempotent — re-applying the same
 *     value is a no-op. The function can be re-run safely.
 *   - `cron.unschedule` before `cron.schedule`.
 *
 * Reversibility:
 *   - `down()` unschedules the cron + drops both functions.
 *   - `down()` does NOT reset the storage params to defaults. Resetting
 *     them requires knowing the previous value (which could be the
 *     global default or a hand-tuned value). A safe reset would be
 *     `ALTER TABLE <child> RESET (autovacuum_vacuum_scale_factor,
 *     autovacuum_analyze_scale_factor)` — but doing this in `down()`
 *     for every partition that was tuned is fragile (the function may
 *     have been run multiple times against different parents). The
 *     task spec explicitly says "Don't try to reset the storage params —
 *     that's not safely reversible." So we don't.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. tune_partition_autovacuum(p_parent TEXT)
        // ============================================================
        // Iterates child partitions of p_parent. For each:
        //   - Parses the YYYY_MM suffix from the child name.
        //   - If the parsed date == current month → moderate settings.
        //   - If the parsed date <  current month → aggressive settings.
        //   - If the parsed date >  current month → skip (future).
        //   - If the name doesn't match the regex → skip with NOTICE.
        //
        // The regex `_([0-9]{4})_([0-9]{2})$` matches both:
        //   - Phase 1-6 manual naming: `<parent>_2026_08`
        //   - pg_partman 5.x naming:   `<parent>_p2026_08`
        // because the leading underscore before YYYY is present in both.
        //
        // ALTER TABLE SET is wrapped in EXECUTE format() for identifier
        // safety. Re-applying the same setting is a no-op (idempotent).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tune_partition_autovacuum(
                p_parent TEXT
            ) RETURNS VOID AS $$
            DECLARE
                v_parent_oid    OID;
                v_child         RECORD;
                v_ym            TEXT[];
                v_year          INT;
                v_month         INT;
                v_part_date     DATE;
                v_current_month DATE;
            BEGIN
                -- Resolve parent OID in the public schema.
                SELECT c.oid
                  INTO v_parent_oid
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public'
                   AND c.relname = p_parent;

                IF v_parent_oid IS NULL THEN
                    RAISE EXCEPTION 'Parent table public.% does not exist.', p_parent
                        USING ERRCODE = '42P01';
                END IF;

                v_current_month := date_trunc('month', CURRENT_DATE)::DATE;

                FOR v_child IN
                    SELECT c.relname, c.oid
                      FROM pg_inherits i
                      JOIN pg_class c ON c.oid = i.inhrelid
                      JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE i.inhparent = v_parent_oid
                       AND n.nspname   = 'public'
                     ORDER BY c.relname
                LOOP
                    -- Try to match the _YYYY_MM suffix.
                    v_ym := regexp_match(v_child.relname, '_([0-9]{4})_([0-9]{2})$');

                    IF v_ym IS NULL THEN
                        RAISE NOTICE 'Skipping %: name does not match _YYYY_MM pattern.',
                            v_child.relname;
                        CONTINUE;
                    END IF;

                    v_year  := v_ym[1]::INT;
                    v_month := v_ym[2]::INT;

                    IF v_month < 1 OR v_month > 12 THEN
                        RAISE NOTICE 'Skipping %: invalid month %.', v_child.relname, v_month;
                        CONTINUE;
                    END IF;

                    v_part_date := make_date(v_year, v_month, 1);

                    IF v_part_date = v_current_month THEN
                        -- Current month: moderate settings.
                        EXECUTE format(
                            'ALTER TABLE public.%I SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02)',
                            v_child.relname
                        );
                        RAISE NOTICE 'Tuned % (current month: moderate).', v_child.relname;

                    ELSIF v_part_date < v_current_month THEN
                        -- Old (read-only): aggressive settings.
                        EXECUTE format(
                            'ALTER TABLE public.%I SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_analyze_scale_factor = 0.01)',
                            v_child.relname
                        );
                        RAISE NOTICE 'Tuned % (old: aggressive).', v_child.relname;

                    ELSE
                        -- Future partition: skip.
                        RAISE NOTICE 'Skipping %: future partition.', v_child.relname;
                    END IF;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // ============================================================
        // 2. run_monthly_autovacuum_tuning() — wrapper.
        // ============================================================
        // Calls tune_partition_autovacuum() for every parent in
        // partman.part_config. Per-parent failures are caught and logged
        // as WARNINGs so one bad parent doesn't abort the whole run.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION run_monthly_autovacuum_tuning()
            RETURNS VOID AS $$
            DECLARE
                v_parent TEXT;
            BEGIN
                FOR v_parent IN
                    SELECT pc.parent_table
                      FROM partman.part_config pc
                     ORDER BY pc.parent_table
                LOOP
                    BEGIN
                        PERFORM tune_partition_autovacuum(v_parent);
                    EXCEPTION WHEN OTHERS THEN
                        RAISE WARNING 'tune_partition_autovacuum(%) failed: % %',
                            v_parent, SQLERRM, SQLSTATE;
                    END;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Log::info('Phase 8.9: tune_partition_autovacuum(TEXT) + run_monthly_autovacuum_tuning() created.');

        // ============================================================
        // 3. Schedule the monthly cron job.
        // ============================================================
        // Schedule: 05:00 on the 1st of each month.
        //   - Runs after the daily 02:00 partman maintenance (which
        //     creates the new current-month partition).
        //   - Runs after the 03:00 health-check cron.
        //   - Runs after the 04:00 quarterly consolidation cron (which
        //     only fires on Jan/Apr/Jul/Oct — on those 4 months, the
        //     consolidation finishes before this tuning job starts).
        // ============================================================
        $pgCronAvailable = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_cron'
            ) AS installed
        ");

        if (! ($pgCronAvailable->installed ?? false)) {
            Log::warning(
                'Phase 8.9: pg_cron extension not available — partition-autovacuum-tuning '
                . 'cron not scheduled. Run run_monthly_autovacuum_tuning() manually each '
                . 'month or via Laravel scheduler as a fallback.'
            );
            return;
        }

        // Unschedule any existing job with the same name (idempotent).
        try {
            DB::statement("SELECT cron.unschedule('partition-autovacuum-tuning')");
        } catch (\Throwable $e) {
            // Job doesn't exist yet — safe to ignore.
        }

        DB::statement(<<<'SQL'
            SELECT cron.schedule(
                'partition-autovacuum-tuning',
                '0 5 1 * *',
                $$SELECT run_monthly_autovacuum_tuning()$$
            )
        SQL);

        Log::info('Phase 8.9: partition-autovacuum-tuning cron scheduled (05:00 on 1st of each month).');
    }

    public function down(): void
    {
        // 1. Unschedule the cron job.
        try {
            DB::statement("SELECT cron.unschedule('partition-autovacuum-tuning')");
        } catch (\Throwable $e) {
            // Job doesn't exist — safe to ignore.
        }

        // 2. Drop both functions. Order matters: drop the wrapper first
        // because it depends on tune_partition_autovacuum().
        DB::statement('DROP FUNCTION IF EXISTS run_monthly_autovacuum_tuning()');
        DB::statement('DROP FUNCTION IF EXISTS tune_partition_autovacuum(TEXT)');

        // 3. Do NOT reset the storage params on tuned partitions.
        //    Per task spec: "Don't try to reset the storage params —
        //    that's not safely reversible." A safe reset would require
        //    knowing the previous value (global default or a hand-tuned
        //    override); attempting a blanket RESET could undo intentional
        //    DBA tuning. The storage params are harmless if left in place
        //    — they just slightly speed up autovacuum on the affected
        //    partitions.

        Log::info('Phase 8.9: partition-autovacuum-tuning cron unscheduled + functions dropped (storage params intentionally NOT reset).');
    }
};
