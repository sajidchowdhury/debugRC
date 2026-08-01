<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add payment_mode column to other_incomes and other_expenses tables.
 *
 * The existing schema has bank_id but no payment_mode. The JS client-side code
 * already shows a cash/bank radio selector. This migration adds the column so
 * the backend can persist the user's choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_incomes', function (Blueprint $table) {
            $table->string('payment_mode', 20)->default('cash')
                ->after('bank_id')
                ->comment('cash, bank, mobile_banking, cheque');
        });

        Schema::table('other_expenses', function (Blueprint $table) {
            $table->string('payment_mode', 20)->default('cash')
                ->after('bank_id')
                ->comment('cash, bank, mobile_banking, cheque');
        });

        // Backfill: if bank_id is set, set payment_mode to 'bank'
        DB::table('other_incomes')->whereNotNull('bank_id')->update(['payment_mode' => 'bank']);
        DB::table('other_expenses')->whereNotNull('bank_id')->update(['payment_mode' => 'bank']);
    }

    public function down(): void
    {
        Schema::table('other_incomes', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });

        Schema::table('other_expenses', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
