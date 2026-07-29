<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 (Stock Take plan) — pg_cron monitoring jobs.
 *
 * Schedules three pg_cron jobs that surface operational health for the
 * Stock Take feature directly from the database (so they keep running
 * even if the Laravel queue worker or app server is down):
 *
 *   1. stock-take-stale-session-reminder   — daily 06:00
 *      Lists sessions in active states (draft/counting/submitted/approved)
 *      older than 30 days. Surfaced to admins as a reminder list — NOT a
 *      state transition (admins decide whether to post, cancel, or follow
 *      up; this function does not mutate rows).
 *
 *   2. stock-take-abc-refresh              — nightly 03:30
 *      Schedules the raw SQL `REFRESH MATERIALIZED VIEW CONCURRENTLY
 *      mv_product_abc_classification` as the cron command — exactly
 *      the pattern used by the existing `refresh-rb-checks` job (migration
 *      2025_01_20_000009). CONCURRENTLY cannot run inside a plpgsql
 *      function body (the function call would be its own transaction
 *      block and PG rejects it), so we schedule the raw REFRESH command
 *      directly. The helper function stock_take_abc_classification_status()
 *      (created below) is a separate STABLE informational query that
 *      returns the MV's computed_at + row count for monitoring pages.
 *
 *   3. stock-take-reconciliation-sweep     — hourly
 *      UNIONs three reconciliation checks: posted sessions missing their
 *      GL journal_entry_id, reversed sessions whose GL entry wasn't
 *      reversed, and posted sessions with applied variance items missing
 *      their journal_line_id link.
 *
 * Idempotency:
 *   - CREATE EXTENSION IF NOT EXISTS (try/catch — falls back to Laravel
 *     scheduler if pg_cron isn't installed).
 *   - CREATE OR REPLACE FUNCTION (idempotent).
 *   - cron.schedule wrapped in DO $$ ... IF NOT EXISTS (SELECT 1 FROM
 *     cron.job WHERE jobname = ...) ... $$ — survives re-runs without
 *     creating duplicate jobs.
 *
 * One-SQL-command-per-DB::statement rule (learned from Phase 8 hotfix):
 * PostgreSQL's extended query protocol (used by PDO) allows exactly one
 * command per prepared statement. Every DB::statement below is a single
 * outer SQL statement (CREATE FUNCTION / DO / SELECT cron.unschedule /
 * DROP FUNCTION). Inner semicolons are inside the dollar-quoted function
 * body, which PG parses as a single string literal — they don't count.
 *
 * Dollar-quote tag note: the DO-block bodies use the $do$ tag (outer) and
 * the $cmd$ tag for the inner cron.schedule command string. Using the
 * default $$ for both would collide — PG would terminate the outer body
 * at the first inner $$. The $do$/$cmd$ pair is unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 0. Enable pg_cron extension (graceful fallback).
        // ============================================================
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_cron');
        } catch (\Throwable $e) {
            logger()->warning('pg_cron extension not available — Phase 12 stock-take monitoring jobs will not be scheduled at the DB level (Laravel scheduler remains the fallback).', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // ============================================================
        // 1. Function: stock_take_mark_stale_sessions(days_threshold)
        //    Returns the list of open (non-terminal) sessions older than
        //    the threshold. Stable / non-mutating — admins act on the
        //    reminder list manually (post, cancel, or follow up).
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_mark_stale_sessions(
    p_days_threshold integer DEFAULT 30
)
RETURNS TABLE(
    session_id    integer,
    session_code  text,
    session_date  date,
    branch_name   text,
    days_stale    integer
) AS $$
SELECT
    sts.id::integer                       AS session_id,
    sts.session_code::text                AS session_code,
    sts.session_date::date                AS session_date,
    COALESCE(b.branch_name, '')::text     AS branch_name,
    (CURRENT_DATE - sts.session_date)::integer AS days_stale
FROM stock_take_sessions sts
LEFT JOIN branches b ON b.id = sts.branch_id
WHERE sts.status IN ('draft', 'counting', 'submitted', 'approved')
  AND sts.session_date < (CURRENT_DATE - p_days_threshold)
  AND sts.deleted_at IS NULL
ORDER BY sts.session_date ASC, sts.id ASC
$$ LANGUAGE sql STABLE
SQL);

        // ============================================================
        // 2. Function: stock_take_reconciliation_alert_sweep()
        //    UNIONs three reconciliation checks. Each row is one alert;
        //    sessions can appear in multiple alert categories.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_reconciliation_alert_sweep()
RETURNS TABLE(
    alert_type   text,
    session_id   integer,
    session_code text,
    detail       text
) AS $$
SELECT
    'missing_journal'::text          AS alert_type,
    sts.id::integer                  AS session_id,
    sts.session_code::text           AS session_code,
    'Posted session has no GL journal entry'::text AS detail
FROM stock_take_sessions sts
WHERE sts.status = 'posted'
  AND sts.is_reversed = false
  AND sts.journal_entry_id IS NULL
  AND sts.deleted_at IS NULL

UNION ALL

SELECT
    'reversed_gl_not_reversed'::text AS alert_type,
    sts.id::integer                  AS session_id,
    sts.session_code::text           AS session_code,
    'Session reversed but GL entry not reversed'::text AS detail
FROM stock_take_sessions sts
JOIN journal_entries je ON je.id = sts.journal_entry_id
WHERE sts.status = 'reversed'
  AND sts.is_reversed = true
  AND je.is_reversed = false
  AND sts.deleted_at IS NULL

UNION ALL

SELECT
    'item_missing_gl_line'::text     AS alert_type,
    sts.id::integer                  AS session_id,
    sts.session_code::text           AS session_code,
    (COUNT(sti.id)::text || ' applied variance items missing journal_line_id')::text AS detail
FROM stock_take_sessions sts
JOIN stock_take_items sti ON sti.stock_take_session_id = sts.id
WHERE sts.status = 'posted'
  AND sts.is_reversed = false
  AND sti.is_applied = true
  AND sti.difference <> 0
  AND sti.journal_line_id IS NULL
  AND sts.deleted_at IS NULL
GROUP BY sts.id, sts.session_code
$$ LANGUAGE sql STABLE
SQL);

        // ============================================================
        // 3. Function: stock_take_abc_classification_status()
        //    STABLE informational query — returns the MV's computed_at +
        //    row count. Used by monitoring pages / dashboards to show
        //    "last computed at: <timestamp> (N products classified)".
        //
        //    NOTE: this function does NOT refresh the MV. REFRESH
        //    MATERIALIZED VIEW CONCURRENTLY cannot run inside a plpgsql
        //    function body (PG treats the function call as its own
        //    transaction block and rejects CONCURRENTLY). The nightly
        //    refresh is done by the cron.schedule command below which
        //    runs the raw REFRESH SQL at top-level (same pattern as the
        //    existing `refresh-rb-checks` job from migration
        //    2025_01_20_000009). The admin "Refresh ABC" button
        //    (AbcClassificationService::refresh() — top-level
        //    DB::statement) is the manual alternative.
        // ============================================================
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_abc_classification_status()
RETURNS TABLE(
    computed_at timestamptz,
    rows        integer
) AS $$
SELECT MAX(mv.computed_at)::timestamptz,
       COUNT(*)::integer
FROM mv_product_abc_classification mv
$$ LANGUAGE sql STABLE
SQL);

        // ============================================================
        // 4. Schedule the three pg_cron jobs.
        //    Each DO block checks cron.job for the jobname first so
        //    re-running the migration does not duplicate jobs.
        // ============================================================

        // Job 1: Stale session reminder — daily at 06:00.
        DB::statement(<<<'SQL'
DO $do$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'stock-take-stale-session-reminder') THEN
    PERFORM cron.schedule(
      'stock-take-stale-session-reminder',
      '0 6 * * *',
      $cmd$SELECT * FROM stock_take_mark_stale_sessions(30)$cmd$
    );
  END IF;
END
$do$;
SQL);

        // Job 2: ABC refresh — nightly at 03:30.
        // The cron command is the raw REFRESH MATERIALIZED VIEW CONCURRENTLY
        // statement (NOT a function call). pg_cron runs each scheduled
        // command in its own autocommit session, so CONCURRENTLY works.
        // This mirrors the existing `refresh-rb-checks` job pattern.
        DB::statement(<<<'SQL'
DO $do$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'stock-take-abc-refresh') THEN
    PERFORM cron.schedule(
      'stock-take-abc-refresh',
      '30 3 * * *',
      $cmd$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification$cmd$
    );
  END IF;
END
$do$;
SQL);

        // Job 3: Reconciliation sweep — hourly at minute 0.
        DB::statement(<<<'SQL'
DO $do$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'stock-take-reconciliation-sweep') THEN
    PERFORM cron.schedule(
      'stock-take-reconciliation-sweep',
      '0 * * * *',
      $cmd$SELECT * FROM stock_take_reconciliation_alert_sweep()$cmd$
    );
  END IF;
END
$do$;
SQL);

        // ============================================================
        // 5. v_pg_cron_jobs monitoring view.
        //    The existing migration 2025_01_20_000009 already defines it
        //    as a generic join on cron.job + cron.job_run_details (no
        //    jobname filter), so the three new jobs appear automatically.
        //    No CREATE OR REPLACE VIEW needed here — verified by reading
        //    the existing definition.
        // ============================================================
    }

    public function down(): void
    {
        // Unschedule the three jobs (safe even if they don't exist).
        try {
            DB::statement("SELECT cron.unschedule('stock-take-stale-session-reminder')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('stock-take-abc-refresh')");
        } catch (\Throwable $e) {}
        try {
            DB::statement("SELECT cron.unschedule('stock-take-reconciliation-sweep')");
        } catch (\Throwable $e) {}

        // Drop the three functions (CASCADE drops any dependent objects,
        // though none exist — they're only referenced by the unscheduled
        // cron jobs above).
        DB::statement('DROP FUNCTION IF EXISTS stock_take_mark_stale_sessions(integer) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS stock_take_reconciliation_alert_sweep() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS stock_take_abc_classification_status() CASCADE');

        // Re-create the monitoring view unchanged (spec compliance — the
        // view is owned by migration 2025_01_20_000009 and is unaffected
        // by this migration's up/down, but re-running CREATE OR REPLACE
        // VIEW with the same definition is a harmless no-op).
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_pg_cron_jobs AS
SELECT
    j.jobid,
    j.schedule,
    j.command,
    j.nodename,
    j.nodeport,
    j.database,
    j.username,
    j.active,
    j.jobname,
    r.runid AS last_run_id,
    r.job_pid AS last_pid,
    r.start_time AS last_start,
    r.end_time AS last_end,
    r.status AS last_status,
    r.return_message AS last_return_message,
    EXTRACT(EPOCH FROM (r.end_time - r.start_time))::numeric(10,3) AS last_duration_seconds
FROM cron.job j
LEFT JOIN LATERAL (
    SELECT runid, job_pid, start_time, end_time, status, return_message
    FROM cron.job_run_details
    WHERE jobid = j.jobid
    ORDER BY start_time DESC
    LIMIT 1
) r ON true
ORDER BY j.jobid
SQL);
    }
};
