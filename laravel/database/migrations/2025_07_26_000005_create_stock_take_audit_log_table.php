<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Stock Take plan) — Real audit trail.
 *
 * Replaces the dead `AuditableMasterData` trait (which never fires because
 * `StockTakeService` writes via `DB::table()`, bypassing Eloquent events)
 * with a real, explicit audit log. Every state transition in the stock-take
 * lifecycle (create → setup → save_count → post → cancel/reverse) now writes
 * exactly one `stock_take_audit_log` row, inside the same DB::transaction as
 * the data change — so a rolled-back post also rolls back its audit row.
 *
 * Schema (mirrors the Phase 2 plan §7):
 *   - stock_take_session_id  FK ON DELETE CASCADE (audit dies with the session)
 *   - stock_take_warehouse_id  nullable FK (warehouse-scoped actions: setup/save_count)
 *   - stock_take_item_id  nullable FK (item-scoped actions — reserved; not yet used)
 *   - action  varchar(40) CHECK (create|setup|save_count|mark_complete|submit|
 *      approve|reject|post|reverse|re_open|delete|cancel)  — the full lifecycle
 *      vocab so future phases (4 approval, 10 re-open) can log without a schema change
 *   - actor_id  integer (the users.id who performed the action; plain int, no FK —
 *      mirrors the sibling `reversed_by`/`created_by` convention so a deleted user
 *      does not orphan the audit row)
 *   - from_status / to_status  varchar(20) (the transition; null from_status on `create`)
 *   - payload  jsonb (action-specific snapshot: counts saved, variance summary, etc.)
 *   - branch_id  integer (denormalized from stock_take_sessions.branch_id at insert
 *      time — REQUIRED so RLS can scope audit reads by branch without a join)
 *   - created_at  timestamp(0)  (append-only; no updated_at — audit rows are immutable)
 *
 * Indexes:
 *   - idx_stal_session  (stock_take_session_id, created_at) — the timeline query
 *   - idx_stal_critical  PARTIAL on (stock_take_session_id) WHERE action IN
 *     ('post','reverse','re_open') — the "critical transitions" filter
 *
 * RLS:
 *   - branch_id-scoped select/insert/update/delete + admin bypass, mirroring
 *     the commission_entries pattern (migration 2025_01_22_000001). The audit
 *     log is append-only in practice, but the full policy set is created for
 *     consistency with every other branch-scoped table.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §7 Phase 2
 *   - app/Services/Stock/StockTakeAuditLogger.php  (the logger that writes here)
 *   - app/Models/StockTakeAuditLog.php  (Eloquent model)
 *   - database/migrations/2025_01_22_000001_create_commission_tracking.php  (RLS pattern)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotency guard: the base schema (03_stock.sql) creates this table
        // + its 4 indexes during a fresh install. Only create if missing
        // (e.g. when this migration runs against a pre-Phase-2 database that
        // was upgraded in place). Without this guard, a fresh migrate:fresh
        // fails with SQLSTATE[42P07] Duplicate table because the base SQL
        // already created the table.
        if (!Schema::hasTable('stock_take_audit_log')) {
            DB::statement(<<<'SQL'
                CREATE TABLE stock_take_audit_log (
                    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
                    stock_take_warehouse_id integer REFERENCES warehouses(id) ON DELETE SET NULL,
                    stock_take_item_id integer REFERENCES stock_take_items(id) ON DELETE SET NULL,
                    action varchar(40) NOT NULL CHECK (
                        action IN ('create','setup','save_count','mark_complete','submit',
                                   'approve','reject','post','reverse','re_open','delete','cancel')
                    ),
                    actor_id integer,
                    from_status varchar(20),
                    to_status varchar(20),
                    payload jsonb,
                    branch_id integer NOT NULL REFERENCES branches(id),
                    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
SQL);

            // Timeline query index: ordered list of audit rows for one session.
            DB::statement(
                'CREATE INDEX idx_stal_session ON stock_take_audit_log (stock_take_session_id, created_at)'
            );

            // Partial index: only the "critical" transitions (post/reverse/re_open).
            // Powers the "critical events" summary on the session detail page and the
            // global audit screen's high-impact filter.
            DB::statement(
                "CREATE INDEX idx_stal_critical ON stock_take_audit_log (stock_take_session_id) " .
                "WHERE action IN ('post','reverse','re_open')"
            );

            // Branch index for the global audit screen's branch filter.
            DB::statement(
                'CREATE INDEX idx_stal_branch ON stock_take_audit_log (branch_id, created_at)'
            );

            // Actor index for the "actions by user" report.
            DB::statement(
                'CREATE INDEX idx_stal_actor ON stock_take_audit_log (actor_id, created_at)'
            );
        }

        // ───────────────────────────────────────────────────────────────
        // RLS — branch-scoped, admin bypass. Mirrors the commission_entries
        // pattern (migration 2025_01_22_000001) and the canonical GUC names
        // from migration 2025_01_20_000007 (app.branch_id, app.is_admin).
        //
        // NOTE: RLS policies are NOT in the base SQL (07_views_triggers_
        // constraints.sql creates RLS for stock_take_sessions/warehouses/items
        // but NOT for stock_take_audit_log). So we always run the RLS creation
        // — guarded by DROP POLICY IF EXISTS for idempotency on re-runs.
        // ───────────────────────────────────────────────────────────────
        // Drop existing policies first (idempotent — safe if they don't exist).
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_audit_log_{$verb} ON stock_take_audit_log");
        }

        DB::statement('ALTER TABLE stock_take_audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_audit_log FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_audit_log_select ON stock_take_audit_log
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_audit_log_insert ON stock_take_audit_log
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_audit_log_update ON stock_take_audit_log
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
            CREATE POLICY rls_stock_take_audit_log_delete ON stock_take_audit_log
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
SQL);

        // Admin bypass policy — admin sees/modifies all branches' audit rows.
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_audit_log_admin ON stock_take_audit_log
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
SQL);
    }

    public function down(): void
    {
        // Drop RLS policies + disable RLS.
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_audit_log_{$verb} ON stock_take_audit_log");
        }
        DB::statement('ALTER TABLE stock_take_audit_log NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_audit_log DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP INDEX IF EXISTS idx_stal_actor');
        DB::statement('DROP INDEX IF EXISTS idx_stal_branch');
        DB::statement('DROP INDEX IF EXISTS idx_stal_critical');
        DB::statement('DROP INDEX IF EXISTS idx_stal_session');
        DB::statement('DROP TABLE IF EXISTS stock_take_audit_log');
    }
};
