<?php

/**
 * G-286 (G18) LOW-E: make `purchase_receive_items.warehouse_id` NOT NULL.
 *
 * Source: AI_CONTEXT/purchasing/purchase-receive.md §11 G18 (around L496).
 *
 * `purchase_receive_items.warehouse_id` is declared
 * `integer REFERENCES warehouses(id)` — NULLABLE — in the migration
 * `2025_01_01_000001_create_rcerp_schema.php` (DROP-only) and the SQL
 * baseline `database/sql/05_purchase.sql:113`. But
 * `StorePurchaseReceiveRequest` REQUIRES `warehouse_id` on every line,
 * and `StockService::applyTransaction` hard-requires a positive
 * warehouse_id on every stock movement. A direct DB insert (or a
 * future code path that bypasses the FormRequest) could create a line
 * with NULL `warehouse_id`, which would crash `applyTransaction` when
 * the GRN is confirmed.
 *
 * This migration aligns the DDL with the FormRequest + service contract:
 *
 *   1. BACKFILL: any existing `purchase_receive_items` row with NULL
 *      `warehouse_id` is resolved from the parent
 *      `purchase_receives.warehouse_id` (which is itself NOT NULL —
 *      see 05_purchase.sql:72). In practice this should be 0 rows
 *      (the FormRequest has always required it), but the backfill is
 *      defensive so the SET NOT NULL cannot throw on legacy data.
 *
 *   2. SET NOT NULL: `ALTER TABLE purchase_receive_items ALTER COLUMN
 *      warehouse_id SET NOT NULL`. Acquires an ACCESS EXCLUSIVE lock
 *      briefly — on a large table this is a cutover step.
 *
 * The SQL baseline `05_purchase.sql:113` is also refreshed to match
 * (`warehouse_id integer` → `warehouse_id integer NOT NULL`).
 *
 * Reverse (down()): DROP NOT NULL. The backfilled values are NOT
 * reset to NULL (the original NULLs are lost — the rows now correctly
 * point at the parent receive's warehouse, which is the right value).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Backfill: any existing NULL warehouse_id on
        //      purchase_receive_items, resolved from the parent
        //      purchase_receives.warehouse_id (NOT NULL by DDL). ─────────
        $nullCount = DB::table('purchase_receive_items')
            ->whereNull('warehouse_id')
            ->count();

        if ($nullCount > 0) {
            // Resolve each NULL-warehouse line from its parent receive.
            // purchase_receives.warehouse_id is NOT NULL (05_purchase.sql:72),
            // so this UPDATE will populate every NULL row with a real warehouse.
            DB::statement("
                UPDATE purchase_receive_items pri
                SET warehouse_id = (
                    SELECT pr.warehouse_id
                    FROM purchase_receives pr
                    WHERE pr.id = pri.purchase_receive_id
                )
                WHERE pri.warehouse_id IS NULL
            ");

            // Defensive verify: if any row is STILL NULL, the parent receive
            // itself has a NULL warehouse_id (which violates the parent DDL —
            // should be impossible, but guard against it so the SET NOT NULL
            // below doesn't throw an opaque error).
            $remaining = DB::table('purchase_receive_items')
                ->whereNull('warehouse_id')
                ->count();
            if ($remaining > 0) {
                throw new \RuntimeException(
                    "Cannot set purchase_receive_items.warehouse_id NOT NULL: {$remaining} row(s) "
                    . "have a NULL warehouse_id and their parent purchase_receives row also has a "
                    . "NULL warehouse_id (which violates the parent DDL). Manually resolve these "
                    . "rows before re-running this migration."
                );
            }

            echo "  G-286: backfilled {$nullCount} purchase_receive_items row(s) with NULL warehouse_id from parent purchase_receives.warehouse_id.\n";
        } else {
            echo "  G-286: no NULL warehouse_id rows to backfill (expected — FormRequest has always required it).\n";
        }

        // ── 2. SET NOT NULL ─────────────────────────────────────────────
        // Brief ACCESS EXCLUSIVE lock; on a large purchase_receive_items
        // table this is a cutover step — run during a maintenance window.
        DB::statement('ALTER TABLE purchase_receive_items ALTER COLUMN warehouse_id SET NOT NULL');

        echo "  G-286: set purchase_receive_items.warehouse_id NOT NULL.\n";
    }

    public function down(): void
    {
        // Revert to nullable. The backfilled values are NOT cleared — they
        // now correctly point at the parent receive's warehouse, which is
        // the right value, so we keep them.
        DB::statement('ALTER TABLE purchase_receive_items ALTER COLUMN warehouse_id DROP NOT NULL');
    }
};
