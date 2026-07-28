<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Adjustment Phase 4 — Dedicated audit log.
 *
 * Replaces the dead `AuditableMasterData` trait on the StockAdjustment model
 * (which never fires because `StockAdjustmentService` writes header/items via
 * `DB::table()`, bypassing Eloquent model events the trait hooks into) with a
 * real, explicit, append-only `stock_adjustment_audit_log` table.
 *
 * Every state transition in the stock-adjustment lifecycle
 * (create → submit → approve → reject → confirm → cancel) now writes exactly
 * one audit row, inside the SAME DB::transaction as the data change — so a
 * rolled-back confirm also rolls back its audit row (the acceptance criterion
 * copied from the sibling Stock Take Phase 2 audit log).
 *
 * Schema (mirrors the plan §4.1 + the canonical DDL in
 * STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md "Reference DDL" block):
 *   - stock_adjustment_id  integer NOT NULL REFERENCES stock_adjustments(id)
 *     ON DELETE CASCADE (audit dies with the adjustment)
 *   - branch_id  integer NOT NULL REFERENCES branches(id)  — denormalized
 *     from stock_adjustments.branch_id at insert time so RLS can scope audit
 *     reads by branch without a join. (The plan suggested nullable; we make
 *     it NOT NULL to match the proven stock_take_audit_log pattern — the
 *     logger always populates it from $adj->branch_id, which is always set
 *     because it is resolved from the warehouse at create time.)
 *   - action  varchar(40) CHECK (create|update|submit|approve|reject|confirm|
 *     cancel|reverse|force_confirm|reopen|delete|export|print) — the full
 *     lifecycle vocab so future phases (6 force_confirm, 8 export/print, 10
 *     reopen) can log without a schema change.
 *   - actor_id  bigint  (users.id of the actor; plain bigint, no FK — mirrors
 *     the sibling `confirmed_by`/`reversed_by` convention so a deleted user
 *     does not orphan the audit row)
 *   - actor_role  varchar(40)  (snapshot of the actor's role at action time —
 *     roles can change later, the audit row must not)
 *   - payload  jsonb  (action-specific snapshot: total_amount, items_count,
 *     reason, journal_entry_id, auto_approved flag, etc.)
 *   - ip_address  varchar(45)  (IPv4 or IPv6; captured from request()->ip())
 *   - user_agent  varchar(255)  (captured from request()->userAgent())
 *   - created_at  timestamp(0)  (append-only; no updated_at — audit rows are
 *     immutable; matches stock_take_audit_log)
 *
 * Indexes:
 *   - idx_saal_adjustment  (stock_adjustment_id, created_at) — the timeline
 *     query on the show page (ordered list of audit rows for one adjustment)
 *   - idx_saal_critical  PARTIAL on (stock_adjustment_id) WHERE action IN
 *     ('confirm','cancel','reverse','force_confirm') — the high-impact
 *     transitions filter (mirrors stock_take's idx_stal_critical)
 *   - idx_saal_branch  (branch_id, created_at) — the global audit screen's
 *     branch filter
 *   - idx_saal_actor  (actor_id, created_at) — the "actions by user" report
 *
 * RLS:
 *   - branch_id-scoped select/insert/update/delete + admin bypass, mirroring
 *     the stock_take_audit_log + commission_entries pattern. The audit log is
 *     append-only in practice, but the full policy set is created for
 *     consistency with every other branch-scoped table.
 *
 * References:
 *   - docs/STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  §9 Phase 4
 *   - app/Services/Stock/StockAdjustmentAuditLogger.php  (the logger)
 *   - app/Models/StockAdjustmentAuditLog.php  (Eloquent model)
 *   - database/migrations/2025_07_26_000005_create_stock_take_audit_log_table.php
 *     (the sibling pattern this mirrors)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotency guard: the base schema (03_stock.sql) creates this table
        // + its 4 indexes during a fresh install. Only create if missing
        // (e.g. when this migration runs against a pre-Phase-4 database that
        // was upgraded in place). Mirrors the stock_take_audit_log guard.
        if (!Schema::hasTable('stock_adjustment_audit_log')) {
            DB::statement(<<<'SQL'
                CREATE TABLE stock_adjustment_audit_log (
                    id bigserial PRIMARY KEY,
                    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
                    branch_id integer NOT NULL REFERENCES branches(id),
                    action varchar(40) NOT NULL CHECK (
                        action IN ('create','update','submit','approve','reject','confirm',
                                   'cancel','reverse','force_confirm','reopen','delete','export','print')
                    ),
                    actor_id bigint,
                    actor_role varchar(40),
                    payload jsonb,
                    ip_address varchar(45),
                    user_agent varchar(255),
                    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
SQL);

            // Timeline query index: ordered list of audit rows for one adjustment
            // (the show-page audit timeline).
            DB::statement(
                'CREATE INDEX idx_saal_adjustment ON stock_adjustment_audit_log (stock_adjustment_id, created_at)'
            );

            // Partial index: only the "critical" transitions
            // (confirm/cancel/reverse/force_confirm). Powers a future
            // "critical events" summary filter, mirroring idx_stal_critical.
            DB::statement(
                "CREATE INDEX idx_saal_critical ON stock_adjustment_audit_log (stock_adjustment_id) " .
                "WHERE action IN ('confirm','cancel','reverse','force_confirm')"
            );

            // Branch index for the global audit screen's branch filter.
            DB::statement(
                'CREATE INDEX idx_saal_branch ON stock_adjustment_audit_log (branch_id, created_at)'
            );

            // Actor index for the "actions by user" report.
            DB::statement(
                'CREATE INDEX idx_saal_actor ON stock_adjustment_audit_log (actor_id, created_at)'
            );
        }

        // ───────────────────────────────────────────────────────────────
        // RLS — branch-scoped, admin bypass. Mirrors the stock_take_audit_log
        // pattern (migration 2025_07_26_000005) and the canonical GUC names
        // from migration 2025_01_20_000007 (app.branch_id, app.is_admin).
        //
        // RLS is the DB-enforced backstop for branch isolation: a non-admin
        // can never see another branch's audit rows even via a raw DB::table
        // query, because PostgreSQL filters rows by the session GUC.
        // ───────────────────────────────────────────────────────────────
        // Drop existing policies first (idempotent — safe if they don't exist).
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_adjustment_audit_log_{$verb} ON stock_adjustment_audit_log");
        }

        DB::statement('ALTER TABLE stock_adjustment_audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_adjustment_audit_log FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_adjustment_audit_log_select ON stock_adjustment_audit_log
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_adjustment_audit_log_insert ON stock_adjustment_audit_log
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_adjustment_audit_log_update ON stock_adjustment_audit_log
                FOR UPDATE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
                WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_adjustment_audit_log_delete ON stock_adjustment_audit_log
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        // Admin bypass policy — admin sees/modifies all branches' audit rows.
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_adjustment_audit_log_admin ON stock_adjustment_audit_log
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
SQL);
    }

    public function down(): void
    {
        // Drop RLS policies + disable RLS.
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_adjustment_audit_log_{$verb} ON stock_adjustment_audit_log");
        }
        DB::statement('ALTER TABLE stock_adjustment_audit_log NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_adjustment_audit_log DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP INDEX IF EXISTS idx_saal_actor');
        DB::statement('DROP INDEX IF EXISTS idx_saal_branch');
        DB::statement('DROP INDEX IF EXISTS idx_saal_critical');
        DB::statement('DROP INDEX IF EXISTS idx_saal_adjustment');
        DB::statement('DROP TABLE IF EXISTS stock_adjustment_audit_log');
    }
};
