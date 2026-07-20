<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix is_reversed DEFAULT on sub-ledger tables.
 *
 * The is_reversed column was added via ALTER TABLE without a DEFAULT value,
 * so inline inserts (before SubLedgerService integration) that omitted it
 * got NULL instead of false. This broke CustomerLedger::getBalance() which
 * filters WHERE is_reversed = false (NULL rows are excluded).
 *
 * This migration:
 *   1. Sets existing NULL rows to false (data fix)
 *   2. Adds DEFAULT false to the column (schema fix)
 *
 * Affects: customer_ledger, supplier_ledger, employee_ledger
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {
            // Data fix: set existing NULL is_reversed rows to false.
            DB::table($table)
                ->whereNull('is_reversed')
                ->update(['is_reversed' => false]);

            // Schema fix: add DEFAULT false to the column.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN is_reversed SET DEFAULT false");
        }
    }

    public function down(): void
    {
        foreach (['customer_ledger', 'supplier_ledger', 'employee_ledger'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN is_reversed DROP DEFAULT");
        }
    }
};
