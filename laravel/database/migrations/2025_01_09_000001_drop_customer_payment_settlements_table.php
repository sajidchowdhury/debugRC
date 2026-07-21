<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P1-4 — Drop the redundant customer_payment_settlements table.
 *
 * Audit Finding: PG had BOTH tables tracking payment↔invoice linkage:
 *   - invoice_payment_allocations (05_purchase.sql) — written by
 *     CustomerPaymentService::allocateToInvoice, read by
 *     allocateToInvoice + SalesInvoiceController::edit +
 *     SalesInvoiceService::invoiceHasPayments
 *   - customer_payment_settlements (06_payment_and_misc.sql) — read by
 *     CustomerPaymentService::cancelPayment + CustomerPayment::settlements()
 *     relation, but NEVER written to (always empty)
 *
 * This is double-bookkeeping: two tables for the same purpose, but only
 * one is ever populated. The show view displays an EMPTY allocations
 * list because the model reads from customer_payment_settlements while
 * the service writes to invoice_payment_allocations.
 *
 * Decision: Keep invoice_payment_allocations (it's where data actually
 * lives). Drop customer_payment_settlements. Update the model relation
 * + cancelPayment to use invoice_payment_allocations.
 *
 * This migration is idempotent (checks if table exists before dropping).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_payment_settlements')) {
            // Safety: migrate any existing data from customer_payment_settlements
            // into invoice_payment_allocations (in case any rows exist).
            $existingCount = DB::table('customer_payment_settlements')->count();
            if ($existingCount > 0) {
                DB::statement(
                    'INSERT INTO invoice_payment_allocations (invoice_id, payment_id, allocated_amount, created_at) ' .
                    'SELECT invoice_id, payment_id, settled_amount, COALESCE(created_at, NOW()) ' .
                    'FROM customer_payment_settlements ' .
                    'WHERE NOT EXISTS (' .
                    '    SELECT 1 FROM invoice_payment_allocations ipa ' .
                    '    WHERE ipa.invoice_id = customer_payment_settlements.invoice_id ' .
                    '      AND ipa.payment_id = customer_payment_settlements.payment_id ' .
                    ')'
                );
            }

            // Drop indexes + table.
            DB::statement('DROP INDEX IF EXISTS idx_cps_payment');
            DB::statement('DROP INDEX IF EXISTS idx_cps_invoice');
            Schema::dropIfExists('customer_payment_settlements');
        }
    }

    public function down(): void
    {
        // Recreate the table (for rollback). Note: data is NOT restored.
        if (!Schema::hasTable('customer_payment_settlements')) {
            Schema::create('customer_payment_settlements', function (Blueprint $table) {
                $table->id();
                $table->integer('payment_id');
                $table->foreign('payment_id', 'fk_cps_payment')
                      ->references('id')->on('customer_payments')
                      ->onDelete('cascade');
                // Note: FK to sales_invoices is NOT created here because
                // sales_invoices is PARTITION BY RANGE (invoice_date), and
                // PG 12-17 does not allow declarative FK references TO a
                // partitioned table unless the referenced columns form a
                // UNIQUE constraint that includes the partition key. The
                // original table did not have this FK enforced either.
                $table->integer('invoice_id');
                $table->decimal('settled_amount', 14, 2)->default(0);
                $table->timestamp('created_at', 0)->useCurrent();
            });
            DB::statement('CREATE INDEX idx_cps_payment ON customer_payment_settlements(payment_id)');
            DB::statement('CREATE INDEX idx_cps_invoice ON customer_payment_settlements(invoice_id)');
        }
    }
};
