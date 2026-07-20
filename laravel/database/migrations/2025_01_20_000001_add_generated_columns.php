<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C-12: Add PostgreSQL GENERATED columns for computed fields.
 *
 * Converts 3 manually-maintained computed columns into GENERATED ALWAYS AS ... STORED
 * columns, so PostgreSQL auto-computes them from same-row base columns:
 *
 *   1. sales_invoices.due_amount        = total_amount - paid_amount
 *   2. sales_challan_items.cogs_amount  = qty * issue_rate
 *   3. warehouse_stock.stock_value      = qty * avg_cost  (NEW column)
 *
 * NOTE: sales_challans.issue_cost CANNOT be a GENERATED column because PostgreSQL
 * does not allow subqueries in GENERATED expressions (it requires SUM of child table
 * rows). That column remains a manually maintained aggregate.
 *
 * Prerequisite: All existing rows must satisfy the GENERATED expression — i.e.,
 * the current stored values must equal what the formula would produce. This
 * migration first backfills any drifted values, then drops the old plain column
 * and re-adds it as GENERATED.
 *
 * For warehouse_stock.stock_value, the column does not exist yet — we simply
 * add it as a new GENERATED column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // 1. sales_invoices.due_amount → GENERATED ALWAYS AS (total_amount - paid_amount) STORED
        // ──────────────────────────────────────────────────────────

        // Step 1a: Backfill any drifted due_amount values.
        // This ensures the GENERATED constraint will be satisfied for all existing rows.
        DB::statement("
            UPDATE sales_invoices
            SET due_amount = total_amount - paid_amount
            WHERE ABS(due_amount - (total_amount - paid_amount)) > 0.005
        ");

        // Step 1b: Drop the existing plain column and re-add as GENERATED.
        // PostgreSQL does not support ALTER COLUMN ... SET GENERATED on an existing column,
        // so we must drop and recreate.
        DB::statement("
            ALTER TABLE sales_invoices
            DROP COLUMN due_amount
        ");
        DB::statement("
            ALTER TABLE sales_invoices
            ADD COLUMN due_amount numeric(14,2) GENERATED ALWAYS AS (total_amount - paid_amount) STORED
        ");

        // ──────────────────────────────────────────────────────────
        // 2. sales_challan_items.cogs_amount → GENERATED ALWAYS AS (qty * issue_rate) STORED
        // ──────────────────────────────────────────────────────────

        // Step 2a: Backfill any drifted cogs_amount values.
        DB::statement("
            UPDATE sales_challan_items
            SET cogs_amount = ROUND(qty * issue_rate, 2)
            WHERE ABS(cogs_amount - ROUND(qty * issue_rate, 2)) > 0.005
        ");

        // Step 2b: Drop and re-add as GENERATED.
        DB::statement("
            ALTER TABLE sales_challan_items
            DROP COLUMN cogs_amount
        ");
        DB::statement("
            ALTER TABLE sales_challan_items
            ADD COLUMN cogs_amount numeric(14,2) GENERATED ALWAYS AS (ROUND(qty * issue_rate, 2)) STORED
        ");

        // ──────────────────────────────────────────────────────────
        // 3. warehouse_stock.stock_value → NEW GENERATED column (qty * avg_cost)
        // ──────────────────────────────────────────────────────────

        // This column does not exist yet — simply add it.
        // Note: warehouse_stock has a composite PK (warehouse_id, product_id).
        DB::statement("
            ALTER TABLE warehouse_stock
            ADD COLUMN stock_value numeric(14,2) GENERATED ALWAYS AS (ROUND(qty * avg_cost, 2)) STORED
        ");

        // Add index for stock_value (useful for inventory valuation reports sorted by value)
        DB::statement("
            CREATE INDEX idx_warehouse_stock_stock_value ON warehouse_stock (stock_value DESC)
            WHERE stock_value > 0
        ");
    }

    public function down(): void
    {
        // Reverse: remove GENERATED columns, restore as plain columns.

        // 1. sales_invoices.due_amount — back to plain stored column
        DB::statement("ALTER TABLE sales_invoices DROP COLUMN due_amount");
        DB::statement("ALTER TABLE sales_invoices ADD COLUMN due_amount numeric(14,2) DEFAULT 0");
        // Repopulate from formula
        DB::statement("UPDATE sales_invoices SET due_amount = total_amount - paid_amount");

        // 2. sales_challan_items.cogs_amount — back to plain stored column
        DB::statement("ALTER TABLE sales_challan_items DROP COLUMN cogs_amount");
        DB::statement("ALTER TABLE sales_challan_items ADD COLUMN cogs_amount numeric(14,2) DEFAULT 0");
        DB::statement("UPDATE sales_challan_items SET cogs_amount = ROUND(qty * issue_rate, 2)");

        // 3. warehouse_stock.stock_value — drop the new column
        DB::statement("DROP INDEX IF EXISTS idx_warehouse_stock_stock_value");
        DB::statement("ALTER TABLE warehouse_stock DROP COLUMN stock_value");
    }
};
