<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add call_a_day column to sales_invoices (Gap G-10).
     *
     * Legacy MySQL had this column; it was omitted in the PG schema redesign.
     * The column is a simple boolean flag — when true, the invoice is removed
     * from the "Sales Today" daily collection list view.
     *
     * No GL, ledger, or stock impact — purely a UI/operational convenience.
     */
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('call_a_day')->default(false)->after('is_soft_hold');
        });

        // Partial index: only index rows where call_a_day = false
        // (the Sales Today view always filters by call_a_day = false).
        DB::statement(
            'CREATE INDEX idx_si_call_a_day_active ON sales_invoices (call_a_day) WHERE call_a_day = false'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_si_call_a_day_active');

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('call_a_day');
        });
    }
};
