<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P0-5 — Create the missing `sales_challan_items` table.
 *
 * Audit Finding (Critical #1): Legacy migration 040 created
 * `sales_challan_items` as the per-line issue-cost SSOT — each challan
 * line stores the avg_cost used at the moment of stock OUT, so that:
 *   (a) Challan reversal can restore inventory at the ORIGINAL per-line
 *       issue_rate (not the current avg_cost, which may have drifted).
 *   (b) GrossMarginReport can JOIN per-line to break down COGS by
 *       product / warehouse.
 *   (c) The `challan_reversal_smoke.php` test can verify issue_rate > 0.
 *
 * The Laravel PG schema (`04_sales.sql`) omitted this table, collapsing
 * per-line cost into a single aggregate `sales_challans.issue_cost`.
 * This migration restores the per-line table for fidelity with legacy
 * and to unblock the GrossMargin per-line report + smoke test.
 *
 * Schema (adapted from legacy migration 040 to PG conventions):
 *   - integer GENERATED ALWAYS AS IDENTITY (instead of INT AUTO_INCREMENT)
 *   - numeric(14,4) for qty (matches sales_invoice_items.qty precision)
 *   - numeric(12,2) for issue_rate (matches warehouse_stock.avg_cost)
 *   - numeric(14,2) for cogs_amount (matches sales_challans.issue_cost)
 *   - Proper FKs to sales_challans (CASCADE), products, warehouses
 *   - updated_at trigger via the shared update_updated_at_column() function
 *
 * Note: The Laravel StockService::reverseTransaction already reverses
 * stock at the original stock_transaction.rate (which IS the original
 * issue_rate), so per-line cost restoration on challan cancel is already
 * correct via the stock_transactions path. This table serves as:
 *   1. A denormalized per-line audit snapshot (human-readable)
 *   2. The source for GrossMargin per-line breakdown
 *   3. A legacy-compatible table for the challan_reversal_smoke test
 *   4. ETL fidelity (legacy data migrates cleanly)
 *
 * Backfill: Existing challans that already have stock_transactions with
 * reference_type='sales_challan' get their sales_challan_items rows
 * reconstructed from the stock_transactions history (same approach as
 * legacy migration 040's backfill block).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_challan_items')) {
            Schema::create('sales_challan_items', function (Blueprint $table) {
                $table->id(); // integer GENERATED ALWAYS AS IDENTITY PK

                $table->integer('sales_challan_id')->unsigned();
                $table->foreign('sales_challan_id', 'fk_sci_challan')
                      ->references('id')->on('sales_challans')
                      ->onDelete('cascade');

                $table->integer('product_id')->unsigned();
                $table->foreign('product_id', 'fk_sci_product')
                      ->references('id')->on('products')
                      ->onDelete('restrict');

                $table->integer('warehouse_id')->unsigned();
                $table->foreign('warehouse_id', 'fk_sci_warehouse')
                      ->references('id')->on('warehouses')
                      ->onDelete('restrict');

                // qty: positive (items issued OUT). numeric(14,4) matches sales_invoice_items.
                $table->decimal('qty', 14, 4);

                // issue_rate: the avg_cost at the moment of challan issue (snapshot).
                $table->decimal('issue_rate', 12, 2)->default(0);

                // cogs_amount: qty × issue_rate (denormalized for fast reporting).
                $table->decimal('cogs_amount', 14, 2)->default(0);

                $table->timestamp('created_at', 0)->useCurrent();
            });

            // Indexes (mirrors legacy migration 040).
            DB::statement('CREATE INDEX idx_sci_challan ON sales_challan_items (sales_challan_id)');
            DB::statement('CREATE INDEX idx_sci_product ON sales_challan_items (product_id)');
            DB::statement('CREATE INDEX idx_sci_wh ON sales_challan_items (warehouse_id)');

            // NOTE: We intentionally do NOT create the trg_sales_challan_items_updated_at
            // trigger here. The table has only `created_at` (no `updated_at` column),
            // because challan line items are append-only snapshots — they are never
            // updated after creation. The shared update_updated_at_column() function
            // assigns to NEW.updated_at, which would raise "column does not exist"
            // at runtime if any UPDATE fired on this table.
            //
            // The earlier draft of this migration created the trigger anyway, which
            // broke the GENERATED-column migration (2025_01_20_000000) that runs
            // `UPDATE sales_challan_items SET cogs_amount = ...` as a backfill.
            // That UPDATE would fire the trigger and abort the entire migration.
        }

        // Backfill: reconstruct sales_challan_items from stock_transactions
        // for existing challans (same approach as legacy migration 040).
        // Only runs if there are existing sales_challan rows.
        $challanCount = DB::table('sales_challans')->count();
        if ($challanCount > 0) {
            DB::statement(
                'INSERT INTO sales_challan_items ' .
                '   (sales_challan_id, product_id, warehouse_id, qty, issue_rate, cogs_amount, created_at) ' .
                'SELECT ' .
                '   st.reference_id AS sales_challan_id, ' .
                '   st.product_id, ' .
                '   st.warehouse_id, ' .
                '   ABS(st.qty) AS qty, ' .
                '   COALESCE(NULLIF(st.rate, 0), 0) AS issue_rate, ' .
                '   ROUND(ABS(st.qty) * COALESCE(NULLIF(st.rate, 0), 0), 2) AS cogs_amount, ' .
                '   st.created_at ' .
                'FROM stock_transactions st ' .
                'INNER JOIN sales_challans sc ON sc.id = st.reference_id ' .
                'WHERE st.reference_type = \'sales_challan\' ' .
                '  AND st.qty < -0.0001 ' .
                '  AND st.is_reversed = false ' .
                '  AND NOT EXISTS ( ' .
                '      SELECT 1 FROM sales_challan_items sci ' .
                '      WHERE sci.sales_challan_id = st.reference_id ' .
                '        AND sci.product_id = st.product_id ' .
                '        AND sci.warehouse_id = st.warehouse_id ' .
                '  )'
            );
        }
    }

    public function down(): void
    {
        // Defensive: drop the trigger IF it exists (it may have been created
        // by an older version of this migration before the trigger creation
        // was removed). DROP TRIGGER IF EXISTS is a no-op if the trigger
        // doesn't exist, so this is always safe.
        DB::statement('DROP TRIGGER IF EXISTS trg_sales_challan_items_updated_at ON sales_challan_items');
        DB::statement('DROP INDEX IF EXISTS idx_sci_challan');
        DB::statement('DROP INDEX IF EXISTS idx_sci_product');
        DB::statement('DROP INDEX IF EXISTS idx_sci_wh');

        Schema::dropIfExists('sales_challan_items');
    }
};
