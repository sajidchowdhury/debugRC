<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-3 — Restore transport snapshot columns on sales_invoices.
 *
 * Legacy migration 017 (017_challan_transport_snapshot.sql) added:
 *   - sales_invoices.pre_challan_transport (snapshot of transport_cost before
 *     challan transport adjustment)
 *   - sales_invoices.pre_challan_total (snapshot of total_amount before
 *     challan transport adjustment)
 *
 * These were removed in the PG schema redesign (04_sales.sql), breaking the
 * transport adjustment workflow: when a challan form changes the transport
 * cost (different from the invoice's original transport), the system needs
 * to snapshot the original values so they can be restored on challan reversal.
 *
 * The corresponding sales_challans columns (transport_adjustment +
 * adjustment_journal_entry_id) ARE present in 04_sales.sql — only the
 * sales_invoices snapshot columns were dropped.
 *
 * This migration is idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales_invoices', 'pre_challan_transport')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->decimal('pre_challan_transport', 12, 2)->nullable()
                      ->after('transport_cost');
            });
        }

        if (!Schema::hasColumn('sales_invoices', 'pre_challan_total')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->decimal('pre_challan_total', 14, 2)->nullable()
                      ->after('total_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $toDrop = array_filter(
                ['pre_challan_transport', 'pre_challan_total'],
                fn($col) => Schema::hasColumn('sales_invoices', $col)
            );
            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
