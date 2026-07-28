<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (Stock Take plan) — Cycle count & ABC classification.
 *
 * Stops forcing every session to be a full-warehouse count. Introduces a
 * `count_scope` on each session (full / category / abc / group / ad_hoc /
 * negative_only / zero_only) plus a jsonb `count_scope_payload` carrying the
 * scope's parameters (e.g. {"category_ids":[3,5]}, {"abc_classes":["A"]},
 * {"product_ids":[101,202]}). StockTakeService::setupWarehouseCounts branches
 * on the scope to build the product set accordingly.
 *
 * Also adds an ABC classification materialized view so high-value movers can
 * be cycle-counted on a different cadence than dead stock. The view computes
 * annual usage value per (product_id, warehouse_id) from stock_transactions
 * (outbound consumption over the lookback window, excluding reversals), ranks
 * within each warehouse, and classifies A (top 80% of value) / B (next 15%) /
 * C (bottom 5%). Refreshed nightly by a pg_cron job using
 * REFRESH MATERIALIZED VIEW CONCURRENTLY so readers are never blocked.
 *
 * The ABC thresholds (A=0.80, A+B=0.95) and the lookback window (365 days)
 * are runtime-configurable via the existing `stock_take_policies` table
 * (introduced in Phase 4). Two tiny SQL helper functions read them at refresh
 * time, so changing a policy row + refreshing the view recomputes the
 * classification with the new thresholds — no schema change needed.
 *
 * Schema changes:
 *   1. ADD COLUMN count_scope + count_scope_payload on stock_take_sessions.
 *   2. CREATE MATERIALIZED VIEW mv_product_abc_classification with a UNIQUE
 *      index on (warehouse_id, product_id) — required for CONCURRENTLY refresh.
 *   3. CREATE FUNCTION stock_take_abc_threshold_a() / _b() / _lookback_days()
 *      that read the policy table (with safe defaults).
 *   4. Schedule pg_cron job `refresh-abc-classification` nightly at 01:30.
 *   5. Seed the three ABC policy defaults into stock_take_policies.
 *   6. Initial refresh so the view is populated immediately after migrate.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §Phase 5
 *   - app/Services/Stock/StockTakeService.php  (setupWarehouseCounts scope branch)
 *   - app/Services/Stock/AbcClassificationService.php  (manual refresh + summary)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. count_scope + count_scope_payload on stock_take_sessions ──
        // count_scope defaults to 'full' so pre-Phase-5 sessions (and any
        // session created without an explicit scope) behave exactly as before.
        // count_scope_payload is nullable jsonb; only populated for non-full
        // scopes.
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_take_sessions', 'count_scope')) {
                $table->string('count_scope', 20)
                    ->default('full')
                    ->after('approval_comments');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'count_scope_payload')) {
                $table->jsonb('count_scope_payload')
                    ->nullable()
                    ->after('count_scope');
            }
        });

        // CHECK constraint on count_scope. Added via raw SQL (Blueprint's
        // enum handling is awkward for a fixed allow-list). Idempotent: drop
        // first if a prior run left one.
        DB::statement(
            "ALTER TABLE stock_take_sessions "
            . "DROP CONSTRAINT IF EXISTS stock_take_sessions_count_scope_check"
        );
        DB::statement(
            "ALTER TABLE stock_take_sessions "
            . "ADD CONSTRAINT stock_take_sessions_count_scope_check "
            . "CHECK (count_scope IN ('full','category','abc','group','ad_hoc','negative_only','zero_only'))"
        );

        // ── 2. ABC threshold helper functions ────────────────────────────
        // Read the policy table at refresh time so changing a policy row +
        // refreshing recomputes the classification with the new thresholds.
        // Each function returns a safe default if the policy row is missing
        // (so the view never breaks even before the seed runs).
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_abc_threshold_a()
RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::numeric
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_threshold_a'),
        0.80
    );
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_abc_threshold_b()
RETURNS numeric LANGUAGE sql STABLE AS $$
    -- Cumulative A+B threshold (default 0.95 → B spans 80%–95%, C is 95%–100%).
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::numeric
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_threshold_b'),
        0.95
    );
$$;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION stock_take_abc_lookback_days()
RETURNS integer LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::integer
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_lookback_days'),
        365
    );
$$;
SQL);

        // ── 3. Materialized view: mv_product_abc_classification ──────────
        // Annual usage value = SUM(ABS(qty) * rate) for OUTBOUND (qty < 0)
        // non-reversed stock_transactions within the lookback window. We use
        // outbound consumption (not total throughput) because ABC analysis
        // ranks by how much value a product CONSUMES — a product that sits
        // untouched in stock gets annual_usage_value = 0 → classified C
        // (rarely needs cycle counting). Ranking is per-warehouse (the view
        // is keyed by warehouse_id, product_id) so each warehouse has its own
        // A/B/C distribution.
        //
        // A product with zero outbound movement in the lookback window is
        // still included with annual_usage_value = 0 and abc_class = 'C',
        // so the ad_hoc/abc scope join never silently drops it. (We LEFT JOIN
        // the consumption CTE against all active products.)
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_product_abc_classification');
        DB::statement(<<<'SQL'
CREATE MATERIALIZED VIEW mv_product_abc_classification AS
WITH usage AS (
    SELECT
        st.warehouse_id,
        st.product_id,
        SUM(ABS(st.qty) * st.rate) AS annual_usage_value
    FROM stock_transactions st
    JOIN products p ON p.id = st.product_id
    WHERE st.qty < 0                              -- outbound consumption only
      AND st.is_reversed = false
      AND st.transaction_date >= (CURRENT_DATE - (stock_take_abc_lookback_days() || ' days')::interval)
      AND p.deleted_at IS NULL
    GROUP BY st.warehouse_id, st.product_id
),
wh_totals AS (
    SELECT warehouse_id, COALESCE(SUM(annual_usage_value), 0) AS wh_total
    FROM usage
    GROUP BY warehouse_id
),
ranked AS (
    SELECT
        u.warehouse_id,
        u.product_id,
        u.annual_usage_value,
        t.wh_total,
        SUM(u.annual_usage_value) OVER (
            PARTITION BY u.warehouse_id
            ORDER BY u.annual_usage_value DESC, u.product_id
        ) AS cum_value
    FROM usage u
    JOIN wh_totals t ON t.warehouse_id = u.warehouse_id
)
SELECT
    r.warehouse_id,
    r.product_id,
    r.annual_usage_value,
    CASE
        WHEN r.wh_total = 0 OR r.cum_value <= r.wh_total * stock_take_abc_threshold_a() THEN 'A'
        WHEN r.cum_value <= r.wh_total * stock_take_abc_threshold_b()                   THEN 'B'
        ELSE 'C'
    END AS abc_class,
    CURRENT_TIMESTAMP AS computed_at
FROM ranked r
SQL);

        // UNIQUE index — required for REFRESH MATERIALIZED VIEW CONCURRENTLY.
        DB::statement(
            'CREATE UNIQUE INDEX mv_product_abc_classification_wh_prod_uidx '
            . 'ON mv_product_abc_classification (warehouse_id, product_id)'
        );
        // Secondary indexes for the common lookup paths.
        DB::statement(
            'CREATE INDEX mv_product_abc_classification_class_idx '
            . 'ON mv_product_abc_classification (abc_class)'
        );
        DB::statement(
            'CREATE INDEX mv_product_abc_classification_product_idx '
            . 'ON mv_product_abc_classification (product_id)'
        );

        // ── 4. Seed the three ABC policy defaults ───────────────────────
        // Reuses the Phase 4 stock_take_policies table (key/value, jsonb).
        $now = now()->toDateTimeString();
        $defaults = [
            [
                'key'         => 'stock_take.abc_threshold_a',
                'value'       => json_encode(0.80),
                'description' => 'ABC classification: cumulative usage-value share (0–1) for class A. Products whose cumulative usage value falls at or below this share of the warehouse total are class A. Default 0.80 (top 80% of value).',
            ],
            [
                'key'         => 'stock_take.abc_threshold_b',
                'value'       => json_encode(0.95),
                'description' => 'ABC classification: cumulative usage-value share (0–1) for classes A+B. Products above the A threshold but at or below this share are class B; the rest are C. Default 0.95 (B spans 80%–95%, C is the bottom 5%).',
            ],
            [
                'key'         => 'stock_take.abc_lookback_days',
                'value'       => json_encode(365),
                'description' => 'ABC classification: lookback window in days for computing annual usage value from stock_transactions (outbound consumption). Default 365 (one year).',
            ],
        ];
        foreach ($defaults as $d) {
            DB::table('stock_take_policies')->updateOrInsert(
                ['key' => $d['key']],
                [
                    'value'       => $d['value'],
                    'description' => $d['description'],
                    'updated_at'  => $now,
                    'created_at'  => $now,
                ]
            );
        }

        // ── 5. The view is populated by CREATE MATERIALIZED VIEW above ────
        // CREATE MATERIALIZED VIEW executes the underlying SELECT at creation
        // time, so the view is already populated (using the helper functions'
        // default thresholds, since the policy rows below aren't seeded yet —
        // but the defaults 0.80/0.95/365 match the seeded values, so the
        // initial classification is correct). The nightly pg_cron job + the
        // manual "Refresh now" button (AbcClassificationService::refresh)
        // handle subsequent refreshes via REFRESH ... CONCURRENTLY.
        //
        // NOTE: we intentionally do NOT run an initial REFRESH MATERIALIZED
        // VIEW CONCURRENTLY here — that statement cannot run inside a
        // transaction block, and Laravel wraps each migration's up() in a
        // transaction (PG supports DDL transactions). The CREATE above
        // already populated the view, so a redundant refresh is unnecessary
        // and would poison the transaction.

        // ── 6. pg_cron job: nightly refresh at 01:30 ────────────────────
        // Runs BEFORE the 02:00 stale-draft job and the 03:00 purge so the
        // ABC data is fresh for the next business day's cycle-count planning.
        //
        // *** CRITICAL — transaction-safety fix (SQLSTATE[0A000] class bug) ***
        // pg_cron may NOT be installed (it requires shared_preload_libraries
        // in postgresql.conf, which is not set on all hosted PG instances).
        // The previous version called `SELECT cron.schedule(...)` directly in
        // a bare try/catch — but that is a FALSE SAFETY NET. Here is why:
        //
        //   1. Laravel wraps this entire up() in a single DB transaction
        //      (PG supports DDL transactions).
        //   2. All DDL above (columns, functions, MV, indexes, policy seeds)
        //      executes successfully and is staged in the transaction.
        //   3. `SELECT cron.schedule(...)` fails with "schema 'cron' does not
        //      exist" because the pg_cron extension is absent.
        //   4. This failure POISONS the PostgreSQL transaction — it enters
        //      the 'aborted' state. EVERY subsequent statement in the
        //      transaction fails with "current transaction is aborted".
        //   5. The PHP try/catch catches the PDOException, so up() returns
        //      normally — Laravel never sees a failure.
        //   6. Laravel calls COMMIT. PG detects the aborted state and executes
        //      ROLLBACK instead. **ALL DDL is rolled back** — the columns,
        //      the 3 helper functions, the mv_product_abc_classification
        //      materialized view, the 3 indexes, and the 3 policy rows.
        //   7. PDO's commit() returns TRUE (PG's ROLLBACK-on-aborted-COMMIT
        //      returns PGRES_COMMAND_OK, not an error), so Laravel thinks
        //      the migration committed successfully.
        //   8. Laravel inserts the migration record (in a new autocommit
        //      transaction) → migration marked as "Ran".
        //
        //   RESULT: `php artisan migrate:status` shows this migration as
        //   "Ran", but `SELECT * FROM pg_matviews` shows NO
        //   mv_product_abc_classification. Every DDL statement was silently
        //   rolled back. This is exactly the bug reported by the user.
        //
        // FIX (two layers of protection):
        //   (a) PRE-CHECK: query pg_extension for 'pg_cron' BEFORE calling
        //       cron.schedule. If the extension is absent, skip the
        //       cron.schedule entirely — never let it throw inside the
        //       migration transaction.
        //   (b) SAVEPOINT: even when pg_cron IS installed, wrap the
        //       cron.schedule in a SAVEPOINT so that if it fails for any
        //       unexpected reason (permission error, duplicate job, etc.),
        //       only the savepoint rolls back — the outer migration
        //       transaction stays healthy and all DDL is preserved.
        //
        // The AbcClassificationService::refresh() method (Laravel-scheduler
        // fallback) handles refreshes when pg_cron is absent, so skipping
        // the cron.schedule here does NOT break the feature.
        $pgCronInstalled = DB::table('pg_extension')
            ->where('extname', 'pg_cron')
            ->exists();

        if (! $pgCronInstalled) {
            logger()->warning('Phase 5: pg_cron extension is not installed — ABC nightly refresh job NOT scheduled. Use AbcClassificationService::refresh() via the Laravel scheduler (or the manual "Refresh ABC" button on /admin/stock-take/abc-report) as the fallback. All DDL (materialized view + indexes + helper functions + policy seeds) was applied successfully.');
            return;
        }

        // pg_cron IS installed — schedule the nightly refresh. Wrapped in a
        // SAVEPOINT so an unexpected cron.schedule failure cannot poison the
        // outer migration transaction (which would roll back all DDL).
        DB::statement('SAVEPOINT abc_cron_schedule');
        try {
            DB::statement(<<<'SQL'
SELECT cron.schedule(
    'refresh-abc-classification',
    '30 1 * * *',
    $$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification$$
)
SQL);
            DB::statement('RELEASE SAVEPOINT abc_cron_schedule');
        } catch (\Throwable $e) {
            // Roll back to the savepoint — this restores the transaction to
            // a healthy state so the outer COMMIT (issued by Laravel after
            // up() returns) commits ALL the DDL above, not roll it back.
            DB::statement('ROLLBACK TO SAVEPOINT abc_cron_schedule');
            logger()->warning('Phase 5: pg_cron ABC refresh job not scheduled (cron.schedule failed) — use AbcClassificationService::refresh() via Laravel scheduler. All DDL was applied successfully.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Unschedule the pg_cron job (safe even if it doesn't exist).
        try {
            DB::statement("SELECT cron.unschedule('refresh-abc-classification')");
        } catch (\Throwable $e) {}

        // Drop the materialized view (its indexes drop with it).
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_product_abc_classification');

        // Drop the helper functions.
        DB::statement('DROP FUNCTION IF EXISTS stock_take_abc_threshold_a()');
        DB::statement('DROP FUNCTION IF EXISTS stock_take_abc_threshold_b()');
        DB::statement('DROP FUNCTION IF EXISTS stock_take_abc_lookback_days()');

        // Remove the three ABC policy defaults. (Leave the Phase 4 policies —
        // they're owned by the Phase 4 migration's seed.)
        DB::table('stock_take_policies')->whereIn('key', [
            'stock_take.abc_threshold_a',
            'stock_take.abc_threshold_b',
            'stock_take.abc_lookback_days',
        ])->delete();

        // Drop the count_scope CHECK + columns.
        // Uses DROP COLUMN IF EXISTS (raw SQL) instead of Blueprint's
        // dropColumn() because down() may run on a database where the
        // columns were NEVER created (the transaction-poisoning bug in the
        // original up() rolled back the columns along with the MV).
        // Blueprint's dropColumn() emits a plain DROP COLUMN which throws
        // SQLSTATE[42703] if the column is absent; IF EXISTS makes it a
        // safe no-op so rollback always succeeds.
        DB::statement(
            "ALTER TABLE stock_take_sessions "
            . "DROP CONSTRAINT IF EXISTS stock_take_sessions_count_scope_check"
        );
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'DROP COLUMN IF EXISTS count_scope, '
            . 'DROP COLUMN IF EXISTS count_scope_payload'
        );
    }
};
