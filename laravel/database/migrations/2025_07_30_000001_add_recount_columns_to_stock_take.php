<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 (Stock Take plan) — Count UX: barcode, bulk paste, CSV import, recount.
 *
 * Schema additions that make the new count UX possible:
 *
 *   (a) stock_take_warehouses.status — widen the CHECK to include 'recounting'.
 *       The recount flow transitions a completed warehouse
 *       completed → recounting → counting so the audit timeline can show the
 *       "user asked for a recount" event distinctly from "user saved counts".
 *       The transition is atomic: recountWarehouse() sets 'recounting' (audit),
 *       immediately sets 'counting' (the warehouse is open for re-entry), and
 *       the counter lands on the count page. 'recounting' is therefore a
 *       transient state — but having it in the vocab means a future async
 *       recount flow (e.g. a mobile assignment) can leave it there until the
 *       counter actually opens the page.
 *
 *   (b) stock_take_items.recounted_at / recounted_by — per-line recount
 *       tracking. Set when recountWarehouse() touches the warehouse; lets the
 *       count page badge "recounted" rows and lets the audit timeline show
 *       which lines a recount affected. recounted_by is a plain integer (no
 *       FK) mirroring the created_by / reversed_by convention so a deleted
 *       user does not orphan the row.
 *
 *   (c) stock_take_audit_log.action — widen the CHECK to include 'recount'.
 *       The recount transition logs one warehouse-scoped 'recount' audit row
 *       carrying the pre-recount physical_qty snapshot (so the acceptance
 *       criterion "the previous physical_qty values are preserved in the
 *       audit log" is satisfied forensically even when the counter overwrites
 *       them). Also added to the partial critical-events index.
 *
 *   (d) stock_take_policies — seed stock_take.recount_reset_to_system
 *       (bool, default false). When false, recountWarehouse() PRESERVES the
 *       previous physical_qty on every line (the counter sees the prior count
 *       and adjusts — the ergonomic default). When true, physical_qty is reset
 *       to system_qty (counter starts fresh). Either way the audit row
 *       captures the pre-recount snapshot.
 *
 * The migration is idempotent: every ALTER is wrapped in an existence guard
 * and every DROP/CREATE uses IF EXISTS / IF NOT EXISTS, so re-running it
 * (or running it against a partially-migrated DB) is safe.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §7 Phase 7
 *   - app/Services/Stock/StockTakeService.php  (recountWarehouse)
 *   - app/Services/Stock/StockTakePolicyService.php  (recountResetToSystem)
 *   - app/Models/StockTakeAuditLog.php  (action vocab)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── (a) stock_take_warehouses: widen status CHECK ──────────────────
        // Drop any existing status CHECK by its canonical name. PostgreSQL
        // auto-names a column-level CHECK on `status` as
        // `stock_take_warehouses_status_check` — the same name the ADD below
        // uses — so a single DROP IF EXISTS handles every prior state:
        // unnamed column check, explicitly-named check from a prior run, or
        // no check at all (IF EXISTS makes it a no-op).
        //
        // We deliberately do NOT match on pg_get_constraintdef() text: PG
        // normalizes `x IN (a,b,c)` to `x = ANY (ARRAY[...])` internally, so
        // an ILIKE on 'status IN (' never matches the stored definition and
        // the drop silently skips — which is exactly the 42710 bug this fixes.
        DB::statement(
            'ALTER TABLE stock_take_warehouses '
            . 'DROP CONSTRAINT IF EXISTS stock_take_warehouses_status_check'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_warehouses
            ADD CONSTRAINT stock_take_warehouses_status_check
            CHECK (status IN ('pending','counting','completed','recounting'))
        SQL);

        // ── (b) stock_take_items: recount tracking columns ──────────────────
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'ADD COLUMN IF NOT EXISTS recounted_at timestamp(0), '
            . 'ADD COLUMN IF NOT EXISTS recounted_by integer'
        );
        // recounted_by references users(id) — added as a plain FK. ON DELETE
        // SET NULL so a deleted user does not cascade-wipe audit context.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'sti_recounted_by_fk'
                      AND conrelid = 'stock_take_items'::regclass
                ) THEN
                    ALTER TABLE stock_take_items
                    ADD CONSTRAINT sti_recounted_by_fk
                    FOREIGN KEY (recounted_by) REFERENCES users(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        // ── (c) stock_take_audit_log: widen action CHECK + critical index ──
        // Drop the existing action CHECK by its canonical name (also PG's
        // auto-name for a column-level CHECK on `action`). Idempotent — see
        // the warehouse status CHECK above for why we drop by name instead of
        // matching on pg_get_constraintdef() text.
        DB::statement(
            'ALTER TABLE stock_take_audit_log '
            . 'DROP CONSTRAINT IF EXISTS stock_take_audit_log_action_check'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_audit_log
            ADD CONSTRAINT stock_take_audit_log_action_check
            CHECK (
                action IN ('create','setup','save_count','mark_complete','submit',
                           'approve','reject','post','reverse','re_open','delete',
                           'cancel','recount','scan_count','bulk_upsert','csv_import','autosave')
            )
        SQL);

        // Refresh the critical-events partial index to also surface 'recount'
        // (a warehouse-level state change worth flagging in the summary). Drop
        // + recreate so the WHERE clause picks up the new action.
        DB::statement('DROP INDEX IF EXISTS idx_stal_critical');
        DB::statement(<<<'SQL'
            CREATE INDEX idx_stal_critical
            ON stock_take_audit_log (stock_take_session_id)
            WHERE action IN ('post','reverse','re_open','recount')
        SQL);

        // ── (d) stock_take_policies: recount_reset_to_system default ────────
        DB::table('stock_take_policies')->updateOrInsert(
            ['key' => 'stock_take.recount_reset_to_system'],
            [
                'value'       => json_encode(false),
                'description' => 'Phase 7: when true, recountWarehouse() resets physical_qty to system_qty on every line (counter starts fresh). When false (default), the previous physical_qty is preserved so the counter sees the prior count and adjusts. The pre-recount values are always captured in the recount audit row regardless.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        // Revert policy seed.
        DB::table('stock_take_policies')
            ->where('key', 'stock_take.recount_reset_to_system')
            ->delete();

        // Revert critical index to the Phase 2 set.
        DB::statement('DROP INDEX IF EXISTS idx_stal_critical');
        DB::statement(<<<'SQL'
            CREATE INDEX idx_stal_critical
            ON stock_take_audit_log (stock_take_session_id)
            WHERE action IN ('post','reverse','re_open')
        SQL);

        // Revert audit action CHECK to the Phase 2 vocab.
        // Drop the existing action CHECK by its canonical name (also PG's
        // auto-name for a column-level CHECK on `action`). Idempotent — see
        // the warehouse status CHECK above for why we drop by name instead of
        // matching on pg_get_constraintdef() text.
        DB::statement(
            'ALTER TABLE stock_take_audit_log '
            . 'DROP CONSTRAINT IF EXISTS stock_take_audit_log_action_check'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_audit_log
            ADD CONSTRAINT stock_take_audit_log_action_check
            CHECK (
                action IN ('create','setup','save_count','mark_complete','submit',
                           'approve','reject','post','reverse','re_open','delete','cancel')
            )
        SQL);

        // Revert recount tracking columns.
        DB::statement('ALTER TABLE stock_take_items DROP CONSTRAINT IF EXISTS sti_recounted_by_fk');
        DB::statement(
            'ALTER TABLE stock_take_items '
            . 'DROP COLUMN IF EXISTS recounted_by, '
            . 'DROP COLUMN IF EXISTS recounted_at'
        );

        // Revert warehouse status CHECK to the Phase 0 vocab.
        DB::statement('ALTER TABLE stock_take_warehouses DROP CONSTRAINT IF EXISTS stock_take_warehouses_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_warehouses
            ADD CONSTRAINT stock_take_warehouses_status_check
            CHECK (status IN ('pending','counting','completed'))
        SQL);
    }
};
