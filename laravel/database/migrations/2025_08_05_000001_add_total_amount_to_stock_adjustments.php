<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hotfix — add the missing `total_amount` column to stock_adjustments.
 *
 * The column has been referenced since the original Phase 6.3 implementation
 * (model $fillable/$casts, StockAdjustmentService::createAdjustment INSERT,
 * StockAdjustmentController::index `sum('total_amount')`, and the index/show
 * blade views via `$adj->total_amount`) but was never declared in the
 * `stock_adjustments` table — the canonical schema in database/sql/03_stock.sql
 * omitted it. The omission was latent until someone hit a code path that
 * touches the column:
 *
 *   - Loading /admin/stock-adjustments triggers
 *       SELECT sum(total_amount) FROM stock_adjustments WHERE status='confirmed'
 *     which throws `SQLSTATE[42703]: Undefined column: 7 ERROR: column
 *     "total_amount" does not exist` (500 Internal Server Error on the index).
 *   - Creating a new adjustment via StockAdjustmentService::createAdjustment
 *     would ALSO throw on the INSERT (same missing column) — a latent
 *     create-flow bug that has not been exercised yet because no adjustment
 *     has been created end-to-end since the column was first referenced.
 *
 * This migration:
 *   1. ADD COLUMN total_amount numeric(14,2) NOT NULL DEFAULT 0
 *      (placed after adjustment_category, the logical grouping for the
 *      category/amount cluster). numeric(14,2) matches the model's
 *      `decimal:2` cast and the service's `round($totalAmount, 2)` insert.
 *   2. Backfill every existing row from its items so historical data is
 *      correct: total_amount = SUM(qty * rate) over stock_adjustment_items.
 *      Safe regardless of row count (no-op on an empty table).
 *
 * The canonical schema file database/sql/03_stock.sql is updated in the same
 * commit so fresh installs are consistent with migrated ones.
 *
 * References:
 *   - app/Models/StockAdjustment.php  ($fillable, $casts)
 *   - app/Services/Stock/StockAdjustmentService.php  (createAdjustment INSERT,
 *     postAdjustmentGL read, confirm/cancel audit payloads)
 *   - app/Http/Controllers/Admin/StockAdjustmentController.php  (index stats)
 *   - resources/views/admin/stock-adjustments/{index,show}.blade.php
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add the column (idempotent — safe to re-run) ───────────────
        // Placed after adjustment_category to group the category/amount cluster.
        // numeric(14,2) mirrors the model's `decimal:2` cast and the service's
        // `round($totalAmount, 2)` insert.
        if (!Schema::hasColumn('stock_adjustments', 'total_amount')) {
            DB::statement(
                "ALTER TABLE stock_adjustments "
                . "ADD COLUMN total_amount numeric(14,2) NOT NULL DEFAULT 0"
            );
        }

        // ── 2. Backfill existing rows from their items ────────────────────
        // total_amount is a denormalized cache of SUM(qty * rate) over
        // stock_adjustment_items. Recompute for every row so historical data
        // (created via seeders / raw SQL before this column existed) is
        // accurate. COALESCE handles rows with no items (defensive — should
        // not happen because the service requires >= 1 item).
        DB::statement(<<<SQL
UPDATE stock_adjustments AS sa
SET total_amount = COALESCE((
        SELECT SUM(sai.qty * sai.rate)
        FROM stock_adjustment_items AS sai
        WHERE sai.stock_adjustment_id = sa.id
    ), 0)
SQL);
    }

    public function down(): void
    {
        // Drop the column. The model/service/controller/views will revert to
        // their pre-hotfix (broken) state — this down() exists for migration
        // hygiene, not for production rollback.
        if (Schema::hasColumn('stock_adjustments', 'total_amount')) {
            DB::statement('ALTER TABLE stock_adjustments DROP COLUMN total_amount');
        }
    }
};
