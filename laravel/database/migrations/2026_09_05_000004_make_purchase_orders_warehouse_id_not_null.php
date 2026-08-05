<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASING-API-2 (G-123/G-124): make purchase_orders.warehouse_id NOT NULL.
 *
 * Root cause: `purchase_orders.warehouse_id` was nullable but
 * `purchase_receives.warehouse_id` (and `purchase_returns.warehouse_id`)
 * was NOT NULL. A PO could be created without a warehouse, but the GRN
 * against it REQUIRES a warehouse at the header level — the FormRequest
 * masks the schema mismatch by making `warehouse_id` required on the GRN.
 * This asymmetry was a DDL-drift gap.
 *
 * Fix direction: align the schema UP to the GRN's strictness (make PO
 * warehouse_id NOT NULL). The alternative (make GRN nullable) is wrong —
 * `StockService::applyTransaction` hard-requires a positive warehouse_id
 * on every stock movement, so a GRN without a warehouse is non-functional.
 *
 * The matching FormRequest change (nullable → required) is done in
 * StorePurchaseOrderRequest + UpdatePurchaseOrderRequest. The controller
 * + service `?? null` fallbacks are removed so a missing warehouse fails
 * fast at validation, not silently coerced to null.
 *
 * Backfill guard: before the ALTER, any existing PO with warehouse_id IS
 * NULL is backfilled to the branch's first active warehouse. If the branch
 * has NO active warehouse, the migration throws (the PO must be manually
 * resolved before the NOT NULL constraint can be applied).
 *
 * SQL baseline `database/sql/05_purchase.sql` is also refreshed to match
 * (the `warehouse_id` line gains `NOT NULL`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Backfill: any existing NULL warehouse_id on purchase_orders ──
        // Resolve to the branch's first active warehouse (ordered by id for
        // determinism). If a branch has no active warehouse, the UPDATE
        // leaves the row NULL and the SET NOT NULL below will throw — which
        // is the correct behavior (the data must be manually resolved first).
        $nullCount = DB::table('purchase_orders')
            ->whereNull('warehouse_id')
            ->count();

        if ($nullCount > 0) {
            // Backfill each NULL-warehouse PO from its branch's warehouses.
            DB::statement("
                UPDATE purchase_orders po
                SET warehouse_id = (
                    SELECT w.id
                    FROM warehouses w
                    WHERE w.branch_id = po.branch_id
                      AND w.is_active = true
                      AND w.deleted_at IS NULL
                    ORDER BY w.id
                    LIMIT 1
                )
                WHERE po.warehouse_id IS NULL
            ");

            // Verify the backfill succeeded for every row.
            $remaining = DB::table('purchase_orders')->whereNull('warehouse_id')->count();
            if ($remaining > 0) {
                throw new \RuntimeException(
                    "Cannot set purchase_orders.warehouse_id NOT NULL: {$remaining} row(s) "
                    . "have a NULL warehouse_id and their branch has no active warehouse. "
                    . "Manually assign a warehouse to these POs before re-running this migration."
                );
            }
        }

        // ── 2. SET NOT NULL ───────────────────────────────────────────────
        // Acquires an ACCESS EXCLUSIVE lock briefly. On a large purchase_orders
        // table this is a cutover step — run during a maintenance window if
        // the table has millions of rows.
        DB::statement('ALTER TABLE purchase_orders ALTER COLUMN warehouse_id SET NOT NULL');
    }

    public function down(): void
    {
        // Revert to nullable. Existing data is NOT cleared (rows that were
        // backfilled retain their resolved warehouse_id).
        DB::statement('ALTER TABLE purchase_orders ALTER COLUMN warehouse_id DROP NOT NULL');
    }
};
