<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: Add updated_at column to stock_take_warehouses.
 *
 * The StockTakeService sets updated_at on stock_take_warehouses in many
 * places (reOpen, cancelSession, saveCounts, etc.) but the column doesn't
 * exist on the table. This migration adds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE stock_take_warehouses
            ADD COLUMN IF NOT EXISTS updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE stock_take_warehouses
            DROP COLUMN IF EXISTS updated_at
        ");
    }
};
