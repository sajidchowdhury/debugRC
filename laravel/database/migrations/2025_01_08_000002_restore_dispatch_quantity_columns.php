<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P0-2 — Fix sales_invoice_dispatches column mismatch.
 *
 * Audit Finding C.2: Code references `ordered_qty`, `dispatched_qty`,
 * `created_by` across 7+ files (model, SalesInvoiceService,
 * SalesChallanService, StockAvailabilityService, show view), but
 * 04_sales.sql collapsed these into a single `qty` column.
 *
 * The ordered-vs-dispatched distinction is CRITICAL for the stock
 * availability calculation:
 *   available = physical_qty - SUM(ordered_qty - dispatched_qty)
 *               WHERE ordered_qty > dispatched_qty
 *               AND invoice NOT reversed/cancelled/challan_completed
 *
 * Decision: Option A (restore legacy columns). The existing `qty`
 * column is retained for the GENERATED `amount` column, but the
 * service code is updated to populate `qty` alongside `ordered_qty`
 * so both representations stay in sync.
 *
 * Columns added:
 *   ordered_qty    numeric(14,4) NOT NULL DEFAULT 0  — reserved qty
 *   dispatched_qty numeric(14,4) NOT NULL DEFAULT 0  — picked/dispatched qty
 *   created_by     integer                           — user who created the reservation
 *
 * Index added (partial):
 *   idx_sdis_pipeline — speeds up the availability query
 *     WHERE dispatched_qty < ordered_qty (open pipeline rows only)
 *
 * Backfill: existing dispatch rows get ordered_qty = qty (the current
 * `qty` value represents the ordered amount for existing rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_dispatches', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_invoice_dispatches', 'ordered_qty')) {
                $table->decimal('ordered_qty', 14, 4)->default(0)->after('qty');
            }
            if (!Schema::hasColumn('sales_invoice_dispatches', 'dispatched_qty')) {
                $table->decimal('dispatched_qty', 14, 4)->default(0)->after('ordered_qty');
            }
            if (!Schema::hasColumn('sales_invoice_dispatches', 'created_by')) {
                $table->integer('created_by')->nullable()->after('dispatch_date');
            }
        });

        // Backfill: existing rows — ordered_qty = qty (the current qty
        // represents the ordered amount for pre-migration rows).
        DB::statement(
            'UPDATE sales_invoice_dispatches SET ordered_qty = qty ' .
            'WHERE ordered_qty = 0 AND qty > 0'
        );

        // Partial index for the pipeline availability query — only rows
        // where dispatched < ordered (i.e., open reservations).
        $idxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'sales_invoice_dispatches' " .
            "AND indexname = 'idx_sdis_pipeline'"
        ))->count();

        if (!$idxExists) {
            DB::statement(
                'CREATE INDEX idx_sdis_pipeline ON sales_invoice_dispatches ' .
                '(sales_invoice_id, product_id) WHERE dispatched_qty < ordered_qty'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sdis_pipeline');

        Schema::table('sales_invoice_dispatches', function (Blueprint $table) {
            $columns = array_filter(
                ['ordered_qty', 'dispatched_qty', 'created_by'],
                fn($col) => Schema::hasColumn('sales_invoice_dispatches', $col)
            );
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
