<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — Add dispatched_ctn column to sales_invoice_dispatches.
 *
 * Parity with legacy Project A: the godown copy screen lets the
 * warehouse dispatcher record a carton-packing count per line
 * (how many cartons each product was packed into for this delivery).
 * This is distinct from the ordered/dispatched QUANTITY (which stays
 * fixed at the invoice demand) — it is a packing annotation used by
 * the delivery team and printed on the godown copy / challan.
 *
 * Confirmed MISSING in Phase 0 baseline verification:
 *   grep -r dispatched_ctn laravel/ → 0 matches
 *
 * Column spec (mirrors legacy sales_invoice_dispatches.dispatched_ctn):
 *   dispatched_ctn numeric(14,4) NOT NULL DEFAULT 0
 *   Positioned after dispatched_qty (restored by migration
 *   2025_01_08_000002_restore_dispatch_quantity_columns).
 *
 * Idempotent via Schema::hasColumn() — safe to re-run on environments
 * where the column was manually added.
 *
 * @see challan_godown_copy.md Phase 4 task 6
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_invoice_dispatches', 'dispatched_ctn')) {
            return;
        }

        Schema::table('sales_invoice_dispatches', function (Blueprint $table) {
            // Position after dispatched_qty (the last quantity column added
            // by 2025_01_08_000002). Falls back to 'after qty' if that
            // column doesn't exist on a non-standard schema.
            $afterColumn = Schema::hasColumn('sales_invoice_dispatches', 'dispatched_qty')
                ? 'dispatched_qty'
                : 'qty';

            $table->decimal('dispatched_ctn', 14, 4)
                ->default(0)
                ->after($afterColumn)
                ->comment('Phase 4: carton-packing count per dispatch line (packing annotation, not a quantity)');
        });

        // Backfill existing rows to 0 (defensive — the DEFAULT handles
        // new rows, but explicit UPDATE ensures existing rows are clean).
        DB::table('sales_invoice_dispatches')
            ->whereNull('dispatched_ctn')
            ->update(['dispatched_ctn' => 0]);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sales_invoice_dispatches', 'dispatched_ctn')) {
            return;
        }

        Schema::table('sales_invoice_dispatches', function (Blueprint $table) {
            $table->dropColumn('dispatched_ctn');
        });
    }
};
