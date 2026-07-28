<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 (Stock Take plan) — Concurrency, RLS, and locking hardening.
 *
 * Closes the cross-branch data leak on `stock_take_warehouses` (stw) and
 * `stock_take_items` (sti) — the two stock-take tables that were missing RLS
 * after Phases 0–7 — and adds warehouse-level mutual exclusion during post.
 *
 * What this migration does:
 *
 *   (1) Add `branch_id` to `stock_take_warehouses` and `stock_take_items`.
 *       Denormalized from `stock_take_sessions.branch_id` at insert time and
 *       NEVER updated afterwards (the session's branch is immutable for the
 *       life of the session). This lets RLS scope reads by branch without a
 *       join — the same pattern already used on `stock_take_audit_log` and
 *       every other branch-scoped table. Backfill runs BEFORE the NOT NULL
 *       constraint is added so existing rows pick up their session's branch.
 *
 *   (2) Add the UNIQUE constraint `uk_stw_session_wh` on
 *       `stock_take_warehouses(stock_take_session_id, warehouse_id)`. The
 *       service already dedupes warehouse_ids in PHP before insert, but the
 *       DB constraint is the race-condition backstop (two concurrent
 *       createSession calls for the same session+warehouse — impossible today
 *       but defensively impossible tomorrow).
 *
 *   (3) Add RLS policies to both tables, mirroring `stock_take_sessions`
 *       and `stock_take_audit_log`: branch-scoped select/insert/update/delete
 *       + admin bypass. This is the headline security fix — before this
 *       migration a non-admin user from Branch A could read
 *       `stock_take_items` for a Branch B session via direct query (the
 *       EnforceBranchIsolation middleware only checks the session row, not
 *       the child rows; RLS closes that hole at the DB level).
 *
 *   (4) Add a denormalized `freeze_outbound` boolean to `stock_take_warehouses`
 *       (mirror of the session's flag at insert; never updated) and a
 *       BEFORE INSERT/UPDATE trigger `prevent_overlapping_frozen_stock_take`
 *       that rejects a warehouse row when another ACTIVE (status in
 *       draft/counting/submitted/approved) frozen session already covers
 *       the same warehouse. This is the EXCLUDE constraint the plan asked
 *       for — implemented as a trigger because the "active + frozen"
 *       predicate spans two tables (stw + sts), which a plain partial
 *       unique index cannot express. The app logic in
 *       StockTakeService::createSession provides the friendly error message;
 *       the trigger is the race-condition backstop (two concurrent
 *       createSession calls would otherwise both pass the app check and
 *       both insert).
 *
 * What this migration does NOT do:
 *   - It does not add the `pg_advisory_xact_lock` call to postSession — that
 *     is a pure code change in StockTakeService (no schema change needed).
 *   - It does not touch `stock_take_sessions` RLS (already present in
 *     07_views_triggers_constraints.sql since Phase 0).
 *
 * Idempotency: every ALTER is wrapped in an existence guard and every
 * CREATE uses IF NOT EXISTS, so re-running (or running against a partially-
 * migrated DB) is safe.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §7 Phase 8
 *   - app/Services/Stock/StockTakeService.php  (createSession, setupWarehouseCounts, postSession)
 *   - database/migrations/2025_07_26_000005_create_stock_take_audit_log_table.php  (RLS pattern)
 *   - database/sql/07_views_triggers_constraints.sql  (stock_take_sessions RLS)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ───────────────────────────────────────────────────────────────
        // (1a) stock_take_warehouses: add branch_id (nullable first, backfill, then NOT NULL)
        // ───────────────────────────────────────────────────────────────
        DB::statement('ALTER TABLE stock_take_warehouses ADD COLUMN IF NOT EXISTS branch_id integer');

        // Backfill from the parent session's branch_id. Every existing stw
        // row has a parent session (ON DELETE CASCADE guarantees it), so this
        // touches every row.
        $missingStw = DB::table('stock_take_warehouses as stw')
            ->leftJoin('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
            ->whereNull('stw.branch_id')
            ->count();
        if ($missingStw > 0) {
            DB::statement(<<<'SQL'
                UPDATE stock_take_warehouses AS stw
                SET branch_id = sts.branch_id
                FROM stock_take_sessions AS sts
                WHERE stw.stock_take_session_id = sts.id
                  AND stw.branch_id IS NULL
            SQL);
        }

        // Now make it NOT NULL + add the FK. Wrap in existence guards so a
        // re-run is a no-op.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                -- NOT NULL
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'stock_take_warehouses'
                      AND column_name = 'branch_id'
                      AND is_nullable = 'NO'
                ) THEN
                    ALTER TABLE stock_take_warehouses ALTER COLUMN branch_id SET NOT NULL;
                END IF;

                -- FK to branches(id)
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'stw_branch_id_fk'
                      AND conrelid = 'stock_take_warehouses'::regclass
                ) THEN
                    ALTER TABLE stock_take_warehouses
                    ADD CONSTRAINT stw_branch_id_fk
                    FOREIGN KEY (branch_id) REFERENCES branches(id);
                END IF;
            END $$;
        SQL);

        // Index for RLS branch filter scans on stw.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_stw_branch ON stock_take_warehouses(branch_id)');

        // ───────────────────────────────────────────────────────────────
        // (1b) stock_take_items: add branch_id (same nullable → backfill → NOT NULL dance)
        // ───────────────────────────────────────────────────────────────
        DB::statement('ALTER TABLE stock_take_items ADD COLUMN IF NOT EXISTS branch_id integer');

        $missingSti = DB::table('stock_take_items as sti')
            ->leftJoin('stock_take_sessions as sts', 'sts.id', '=', 'sti.stock_take_session_id')
            ->whereNull('sti.branch_id')
            ->count();
        if ($missingSti > 0) {
            DB::statement(<<<'SQL'
                UPDATE stock_take_items AS sti
                SET branch_id = sts.branch_id
                FROM stock_take_sessions AS sts
                WHERE sti.stock_take_session_id = sts.id
                  AND sti.branch_id IS NULL
            SQL);
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'stock_take_items'
                      AND column_name = 'branch_id'
                      AND is_nullable = 'NO'
                ) THEN
                    ALTER TABLE stock_take_items ALTER COLUMN branch_id SET NOT NULL;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'sti_branch_id_fk'
                      AND conrelid = 'stock_take_items'::regclass
                ) THEN
                    ALTER TABLE stock_take_items
                    ADD CONSTRAINT sti_branch_id_fk
                    FOREIGN KEY (branch_id) REFERENCES branches(id);
                END IF;
            END $$;
        SQL);

        // Index for RLS branch filter scans on sti.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sti_branch ON stock_take_items(branch_id)');

        // ───────────────────────────────────────────────────────────────
        // (2) UNIQUE(session_id, warehouse_id) on stock_take_warehouses
        // ───────────────────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'uk_stw_session_wh'
                      AND conrelid = 'stock_take_warehouses'::regclass
                ) THEN
                    ALTER TABLE stock_take_warehouses
                    ADD CONSTRAINT uk_stw_session_wh
                    UNIQUE (stock_take_session_id, warehouse_id);
                END IF;
            END $$;
        SQL);

        // ───────────────────────────────────────────────────────────────
        // (4a) Denormalized freeze_outbound on stock_take_warehouses
        // ───────────────────────────────────────────────────────────────
        // Mirror of the session's freeze_outbound flag, set at insert time
        // (StockTakeService::createSession) and NEVER updated. The overlapping-
        // frozen-session trigger reads this flag instead of joining to the
        // session row, which keeps the trigger cheap (one index lookups vs a
        // join). Backfill from the session's flag for existing rows.
        DB::statement('ALTER TABLE stock_take_warehouses ADD COLUMN IF NOT EXISTS freeze_outbound boolean NOT NULL DEFAULT false');

        DB::statement(<<<'SQL'
            UPDATE stock_take_warehouses AS stw
            SET freeze_outbound = sts.freeze_outbound
            FROM stock_take_sessions AS sts
            WHERE stw.stock_take_session_id = sts.id
              AND stw.freeze_outbound = false
              AND sts.freeze_outbound = true
        SQL);

        // Partial index: only the frozen warehouse rows — the trigger's fast
        // path. One row per (frozen) warehouse per session.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_stw_frozen_wh
            ON stock_take_warehouses(warehouse_id)
            WHERE freeze_outbound = true
        SQL);

        // ───────────────────────────────────────────────────────────────
        // (4b) Trigger: prevent overlapping frozen sessions per warehouse
        // ───────────────────────────────────────────────────────────────
        // The plan asked for an EXCLUDE constraint, but the "active + frozen"
        // predicate spans two tables (stw + sts), which a plain partial
        // unique index cannot express. A trigger is the cleanest DB-level
        // enforcement. The app logic in StockTakeService::createSession
        // provides the friendly error message; the trigger is the race-
        // condition backstop (two concurrent createSession calls would
        // otherwise both pass the app check and both insert).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_overlapping_frozen_stock_take()
            RETURNS trigger AS $$
            DECLARE
                conflict_count integer;
            BEGIN
                -- Only enforce when the warehouse row is marked frozen.
                IF NEW.freeze_outbound IS NOT TRUE THEN
                    RETURN NEW;
                END IF;

                -- Is there another ACTIVE (non-terminal) frozen session
                -- covering the same warehouse? Terminal statuses
                -- (posted/cancelled/reversed) do NOT count — their freeze
                -- has ended (refreshWarehouseFreezeFlags has already
                -- cleared warehouses.is_frozen_for_count for them when no
                -- other active frozen session covers the same wh).
                SELECT count(*) INTO conflict_count
                FROM stock_take_warehouses stw
                JOIN stock_take_sessions sts ON sts.id = stw.stock_take_session_id
                WHERE stw.warehouse_id = NEW.warehouse_id
                  AND stw.freeze_outbound = true
                  AND stw.id IS DISTINCT FROM NEW.id
                  AND stw.stock_take_session_id IS DISTINCT FROM NEW.stock_take_session_id
                  AND sts.status IN ('draft','counting','submitted','approved');

                IF conflict_count > 0 THEN
                    RAISE EXCEPTION
                        'Warehouse % is already covered by an active frozen stock-take session. Post or cancel the existing session first, or create this session without the outbound freeze.',
                        NEW.warehouse_id
                        USING ERRCODE = '23000';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // PostgreSQL's prepared-statement protocol (which Laravel/PDO uses)
        // allows exactly ONE SQL command per DB::statement() — a multi-
        // command string separated by ';' is rejected with SQLSTATE[42601]
        // "cannot insert multiple commands into a prepared statement". So
        // the DROP TRIGGER and CREATE TRIGGER must be two separate calls.
        DB::statement(
            'DROP TRIGGER IF EXISTS trg_stw_no_overlapping_frozen ON stock_take_warehouses'
        );

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_stw_no_overlapping_frozen
            BEFORE INSERT OR UPDATE OF warehouse_id, freeze_outbound ON stock_take_warehouses
            FOR EACH ROW EXECUTE FUNCTION prevent_overlapping_frozen_stock_take()
        SQL);

        // ───────────────────────────────────────────────────────────────
        // (3) RLS on stock_take_warehouses + stock_take_items
        // ───────────────────────────────────────────────────────────────
        // Mirrors the stock_take_sessions + stock_take_audit_log policy set:
        // branch-scoped select/insert/update/delete + an admin-bypass FOR ALL
        // policy. The GUC names app.is_admin + app.branch_id are the canonical
        // ones (set by SetAppBranchId middleware on every request).
        //
        // Idempotency: the base SQL (07_views_triggers_constraints.sql) already
        // creates these exact policies on a fresh install. DROP POLICY IF EXISTS
        // first so re-runs (and fresh installs where the base SQL ran first)
        // don't fail with SQLSTATE[42710] Duplicate object.
        DB::statement('ALTER TABLE stock_take_warehouses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_warehouses FORCE ROW LEVEL SECURITY');
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_warehouses_{$verb} ON stock_take_warehouses");
        }

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_warehouses_select ON stock_take_warehouses
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_warehouses_insert ON stock_take_warehouses
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_warehouses_update ON stock_take_warehouses
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
            CREATE POLICY rls_stock_take_warehouses_delete ON stock_take_warehouses
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_warehouses_admin ON stock_take_warehouses
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
        SQL);

        // Same five-policy set on stock_take_items.
        // Idempotency: DROP POLICY IF EXISTS first (base SQL creates these too).
        DB::statement('ALTER TABLE stock_take_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_items FORCE ROW LEVEL SECURITY');
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_items_{$verb} ON stock_take_items");
        }

        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_items_select ON stock_take_items
                FOR SELECT USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_items_insert ON stock_take_items
                FOR INSERT WITH CHECK (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_items_update ON stock_take_items
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
            CREATE POLICY rls_stock_take_items_delete ON stock_take_items
                FOR DELETE USING (
                    current_setting('app.is_admin', true) = 'true'
                    OR branch_id = current_setting('app.branch_id', true)::integer
                )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_stock_take_items_admin ON stock_take_items
                FOR ALL
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
        SQL);
    }

    public function down(): void
    {
        // Drop RLS policies + disable RLS on both tables.
        foreach (['select', 'insert', 'update', 'delete', 'admin'] as $verb) {
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_items_{$verb} ON stock_take_items");
            DB::statement("DROP POLICY IF EXISTS rls_stock_take_warehouses_{$verb} ON stock_take_warehouses");
        }
        DB::statement('ALTER TABLE stock_take_items NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_items DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_warehouses NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_take_warehouses DISABLE ROW LEVEL SECURITY');

        // Drop the overlapping-frozen-session trigger + function.
        DB::statement('DROP TRIGGER IF EXISTS trg_stw_no_overlapping_frozen ON stock_take_warehouses');
        DB::statement('DROP FUNCTION IF EXISTS prevent_overlapping_frozen_stock_take()');

        // Drop the partial frozen-warehouse index.
        DB::statement('DROP INDEX IF EXISTS idx_stw_frozen_wh');

        // Drop the denormalized freeze_outbound column from stw.
        DB::statement('ALTER TABLE stock_take_warehouses DROP COLUMN IF EXISTS freeze_outbound');

        // Drop the UNIQUE(session_id, warehouse_id) constraint.
        DB::statement('ALTER TABLE stock_take_warehouses DROP CONSTRAINT IF EXISTS uk_stw_session_wh');

        // Drop branch_id indexes.
        DB::statement('DROP INDEX IF EXISTS idx_sti_branch');
        DB::statement('DROP INDEX IF EXISTS idx_stw_branch');

        // Drop branch_id columns + FKs from both tables.
        DB::statement('ALTER TABLE stock_take_items DROP CONSTRAINT IF EXISTS sti_branch_id_fk');
        DB::statement('ALTER TABLE stock_take_items DROP COLUMN IF EXISTS branch_id');
        DB::statement('ALTER TABLE stock_take_warehouses DROP CONSTRAINT IF EXISTS stw_branch_id_fk');
        DB::statement('ALTER TABLE stock_take_warehouses DROP COLUMN IF EXISTS branch_id');
    }
};
