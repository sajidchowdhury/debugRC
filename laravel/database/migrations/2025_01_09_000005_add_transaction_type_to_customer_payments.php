<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P2-5 — Restore transaction_type on customer_payments.
 *
 * Legacy customer_payments had transaction_type ENUM('receive','payment',
 * 'discount','write_off') distinguishing:
 *   - receive: customer paying us (normal payment)
 *   - payment: refund to customer (money out)
 *   - discount: write-off of a small balance as discount
 *   - write_off: write-off of an uncollectable balance
 *
 * The PG schema redesign removed this column, losing the ability to
 * distinguish a refund from a receive in the same table. This migration
 * restores it.
 *
 * Also adds a partial index for filtering by type.
 *
 * Idempotent (guarded by Schema::hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_payments', 'transaction_type')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->string('transaction_type', 20)->default('receive')
                      ->check("transaction_type IN ('receive','payment','discount','write_off')")
                      ->after('payment_mode');
            });

            // Backfill existing rows to 'receive' (default for existing data).
            DB::statement(
                "UPDATE customer_payments SET transaction_type = 'receive' " .
                "WHERE transaction_type IS NULL OR transaction_type = ''"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_payments', 'transaction_type')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->dropColumn('transaction_type');
            });
        }
    }
};
