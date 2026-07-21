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
        // Guard with Schema::hasColumn so this migration is idempotent and
        // safe on a fresh install — 04_sales.sql already declares
        // `call_a_day boolean NOT NULL DEFAULT false` on sales_invoices.
        if (!Schema::hasColumn('sales_invoices', 'call_a_day')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->boolean('call_a_day')->default(false)->after('is_soft_hold');
            });
        }

        // Partial index: only index rows where call_a_day = false
        // (the Sales Today view always filters by call_a_day = false).
        // Use IF NOT EXISTS for idempotency on re-runs and fresh installs
        // where the index may already have been created by a prior partial run.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_si_call_a_day_active ON sales_invoices (call_a_day) WHERE call_a_day = false'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_si_call_a_day_active');

        // Only drop the column if it exists (avoids error on fresh-install
        // rollback where the column was created by 04_sales.sql, not by this migration).
        if (Schema::hasColumn('sales_invoices', 'call_a_day')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->dropColumn('call_a_day');
            });
        }
    }
};
