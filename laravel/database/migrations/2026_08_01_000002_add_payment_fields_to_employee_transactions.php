<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add payment_mode, bank_id, collected_by to employee_transactions.
 *
 * The PostgreSQL schema for employee_transactions is missing these columns
 * that the legacy system has. Without them, we can't track:
 *   - payment_mode: cash/bank/mobile_banking/cheque/adjustment
 *   - bank_id: which bank account (required when payment_mode = bank)
 *   - collected_by: which employee collected the cash
 *
 * These are needed for bank balance sync and GL posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_transactions', function (Blueprint $table) {
            $table->string('payment_mode', 20)->default('cash')->after('transaction_type')
                ->comment('cash|bank|mobile_banking|cheque|adjustment');
            $table->integer('bank_id')->nullable()->after('payment_mode');
            $table->integer('collected_by')->nullable()->after('bank_id')
                ->comment('Employee who collected the cash');
            $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
            $table->foreign('collected_by')->references('id')->on('employees')->nullOnDelete();
        });

        // Add CHECK constraint for payment_mode
        DB::statement("
            ALTER TABLE employee_transactions
            ADD CONSTRAINT employee_transactions_payment_mode_check
            CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE employee_transactions
            DROP CONSTRAINT IF EXISTS employee_transactions_payment_mode_check
        ");

        Schema::table('employee_transactions', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->dropForeign(['collected_by']);
            $table->dropColumn(['payment_mode', 'bank_id', 'collected_by']);
        });
    }
};
