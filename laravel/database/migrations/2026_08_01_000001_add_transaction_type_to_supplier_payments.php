<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add transaction_type, reference_no, intercompany_journal_entry_id, and deleted_at
 * to the supplier_payments table.
 *
 * These columns bring supplier_payments to parity with customer_payments
 * and enable the Supplier Transaction module (Phase 1 of the Accounts Sub-Ledger plan).
 *
 * transaction_type: distinguishes payment/advance/receive (legacy used a separate column).
 * reference_no: external reference number (cheque number, etc.).
 * intercompany_journal_entry_id: for cross-branch bank-mode settlement.
 * deleted_at: soft-deletes support (parity with customer_payments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->string('transaction_type', 20)->default('payment')
                ->after('payment_mode')
                ->comment('payment, advance, receive');

            $table->string('reference_no', 100)->nullable()
                ->after('discount_amount')
                ->comment('External reference number (cheque no, etc.)');

            $table->integer('intercompany_journal_entry_id')->nullable()
                ->after('journal_entry_id')
                ->comment('FK to journal_entries — cross-branch bank-mode settlement');

            $table->timestamp('deleted_at')->nullable()
                ->after('updated_at')
                ->comment('Soft-delete timestamp');
        });

        // Add CHECK constraint for transaction_type values
        DB::statement("
            ALTER TABLE supplier_payments
            ADD CONSTRAINT supplier_payments_transaction_type_check
            CHECK (transaction_type IN ('payment', 'advance', 'receive'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE supplier_payments
            DROP CONSTRAINT IF EXISTS supplier_payments_transaction_type_check
        ");

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['transaction_type', 'reference_no', 'intercompany_journal_entry_id', 'deleted_at']);
        });
    }
};
