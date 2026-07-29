<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a composite index on sales_invoice_dispatches(product_id, warehouse_id)
 * to accelerate the batched pipeline-qty query in
 * StockAvailabilityService::getBranchWarehouseBreakdownForProducts().
 *
 * Before this index, the pipeline SUM query filtered by product_id +
 * warehouse_id but only had separate indexes on sales_invoice_id and
 * warehouse_id — PostgreSQL had to scan by warehouse_id then filter
 * product_id in memory, which was slow multiplied by N×W calls on the
 * godown page. The composite index enables an index-only scan.
 *
 * The INCLUDE clause covers ordered_qty, dispatched_qty, and
 * sales_invoice_id so the SUM/exclude-invoice filter can be satisfied
 * from the index without heap fetches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sdis_product_warehouse '
            . 'ON sales_invoice_dispatches (product_id, warehouse_id) '
            . 'INCLUDE (ordered_qty, dispatched_qty, sales_invoice_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sdis_product_warehouse');
    }
};
