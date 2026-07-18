<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * P0-4 — Fix customer_payments.reference_no schema/code mismatch.
 *
 * Audit Finding C.5: CustomerPaymentService::createPayment (line 70),
 * CustomerPayment model fillable (line 55), CustomerPaymentController
 * validation (line 98), and customer-payments/create.blade.php (line 167)
 * all reference `reference_no`, but 06_payment_and_misc.sql does NOT
 * define this column.
 *
 * The `reference_no` field captures:
 *   - Cheque number (for payment_mode='cheque')
 *   - Bank transaction ID (for payment_mode='bank')
 *   - Mobile banking transaction ID (for payment_mode='mobile_banking')
 *   - Any external reference number
 *
 * This was present in legacy MySQL (`customer_payments.reference_no`)
 * but was accidentally omitted from the PG schema redesign.
 *
 * Column added:
 *   reference_no varchar(100)  — nullable, for cheque/transaction reference
 *
 * Index added:
 *   idx_cp_reference_no — for searching payments by reference number
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_payments', 'reference_no')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->string('reference_no', 100)->nullable()
                      ->after('payment_mode');
            });
        }

        // Index for reference number lookups (partial — only non-null rows).
        $idxExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'customer_payments' " .
            "AND indexname = 'idx_cp_reference_no'"
        ))->count();

        if (!$idxExists) {
            DB::statement(
                'CREATE INDEX idx_cp_reference_no ON customer_payments (reference_no) ' .
                'WHERE reference_no IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_cp_reference_no');

        if (Schema::hasColumn('customer_payments', 'reference_no')) {
            Schema::table('customer_payments', function (Blueprint $table) {
                $table->dropColumn('reference_no');
            });
        }
    }
};
