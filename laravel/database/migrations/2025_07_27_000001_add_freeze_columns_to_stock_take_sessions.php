<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (Stock Take plan) — Stock integrity: snapshot freeze + optional
 * outbound freeze.
 *
 * Eliminates the single biggest data-integrity risk in a physical count:
 * stock moving WHILE the count is in progress. Two layers are added:
 *
 *   (a) Snapshot freeze — `count_snapshot jsonb` captures the full product
 *       list (product_id, product_code, system_qty, avg_cost) at setup time.
 *       The session detail page can reconstruct "what the counter saw" months
 *       later, even after products are renamed/deleted or stock drifts. This
 *       is always captured (cheap, one row) regardless of the outbound freeze.
 *
 *   (b) Optional outbound freeze — `freeze_outbound boolean` on the session.
 *       When true, the source warehouse is marked `is_frozen_for_count=true`
 *       and `StockService::applyTransaction` rejects any OUTBOUND movement
 *       (qty < 0) for that warehouse while the session is active — EXCEPT
 *       the stock-take's own variance application (reference_type='stock_take')
 *       and reversals (reference_type='reversal'), which must proceed.
 *
 * `frozen_at` records when the outbound freeze took effect (null when
 * freeze_outbound=false). It is the audit-friendly timestamp of "stock was
 * locked from this instant".
 *
 * The `warehouses.is_frozen_for_count` flag is a DENORMALIZED fast-lookup
 * column. It is recomputed by `StockTakeService::refreshWarehouseFreezeFlags`
 * on every create / post / cancel / delete so it always reflects the set of
 * ACTIVE sessions (status IN draft/counting) that cover the warehouse with
 * freeze_outbound=true. Multiple sessions freezing the same warehouse keep
 * the flag true until the LAST one ends.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §Phase 3
 *   - app/Services/Stock/StockTakeService.php  (freeze lifecycle)
 *   - app/Services/Stock/StockService.php       (outbound freeze check)
 *   - app/Exceptions/WarehouseFrozenForCountException.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── stock_take_sessions: snapshot + outbound freeze ──────────────
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'ADD COLUMN IF NOT EXISTS frozen_at timestamp(0), '
            . 'ADD COLUMN IF NOT EXISTS freeze_outbound boolean NOT NULL DEFAULT false, '
            . 'ADD COLUMN IF NOT EXISTS count_snapshot jsonb'
        );

        // Partial index: only frozen sessions — fast lookup for "is anything
        // currently frozen?" and for the warehouse-flag recompute query.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sts_freeze_outbound '
            . 'ON stock_take_sessions (branch_id) '
            . 'WHERE freeze_outbound = true'
        );

        // ── warehouses: denormalized freeze flag ─────────────────────────
        DB::statement(
            'ALTER TABLE warehouses '
            . 'ADD COLUMN IF NOT EXISTS is_frozen_for_count boolean NOT NULL DEFAULT false'
        );

        // Partial index: only frozen warehouses — the outbound-movement
        // check in StockService::applyTransaction hits this index (one row
        // per frozen warehouse) instead of scanning the whole table.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_wh_is_frozen '
            . 'ON warehouses (id) WHERE is_frozen_for_count = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_wh_is_frozen');
        DB::statement('ALTER TABLE warehouses DROP COLUMN IF EXISTS is_frozen_for_count');

        DB::statement('DROP INDEX IF EXISTS idx_sts_freeze_outbound');
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'DROP COLUMN IF EXISTS count_snapshot, '
            . 'DROP COLUMN IF EXISTS freeze_outbound, '
            . 'DROP COLUMN IF EXISTS frozen_at'
        );
    }
};
