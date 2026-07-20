<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-1 — Fix sales_invoices.transport_cost schema/code mismatch.
 *
 * Audit Finding C.1: SalesInvoiceService::finalizeFromCart inserts
 * `transport_cost` into sales_invoices, but 04_sales.sql did NOT define
 * this column (transport was moved to sales_challans only).
 *
 * Decision: Option B (add column back). Rationale:
 *   - The service code, model fillable, and controller validation all
 *     already reference `transport_cost` on the invoice.
 *   - The invoice captures the initial transport estimate at sale time;
 *     the challan captures the actual transport at delivery time.
 *   - Both columns coexist in legacy and the code expects both.
 *   - Adding the column is lower-risk than rewriting the service + views.
 *
 * This migration is idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales_invoices', 'transport_cost')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                // Positioned after tax_amount, before total_amount — matches
                // the logical order: subtotal, discount, tax, transport, total.
                $table->decimal('transport_cost', 12, 2)->default(0)
                      ->after('tax_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_invoices', 'transport_cost')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->dropColumn('transport_cost');
            });
        }
    }
};
