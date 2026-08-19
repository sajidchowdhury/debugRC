<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: Make accounting_periods.closed_through_date nullable.
 *
 * AccountingPeriodService::reopenPeriod() sets closed_through_date=null
 * to re-open the period, but the column is defined as NOT NULL.
 * Make it nullable so the reopen operation works.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE accounting_periods
            ALTER COLUMN closed_through_date DROP NOT NULL
        ");
    }

    public function down(): void
    {
        // Revert: set any NULLs to a sentinel date first, then re-add NOT NULL.
        DB::statement("
            UPDATE accounting_periods
            SET closed_through_date = '2000-01-01'
            WHERE closed_through_date IS NULL
        ");
        DB::statement("
            ALTER TABLE accounting_periods
            ALTER COLUMN closed_through_date SET NOT NULL
        ");
    }
};
