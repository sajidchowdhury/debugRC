<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9: Drop broken trg_product_price_history_updated_at trigger.
 *
 * The trigger function `update_updated_at_column()` references NEW.updated_at,
 * but the `product_price_history` table has NO `updated_at` column (the model
 * sets `$timestamps = false`). Any UPDATE on this table fires the BEFORE
 * UPDATE trigger, which fails with "column updated_at does not exist",
 * poisoning the surrounding DB transaction.
 *
 * This breaks the ProductController::addPrice() flow when it tries to close
 * out the previous current price entry (`$previousCurrent->save()`).
 *
 * The fix: drop the broken trigger. The table legitimately has no updated_at
 * column (price history entries are append-only by design), so an updated_at
 * trigger was a schema-creation bug.
 *
 * Idempotent: guarded by existence check.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_trigger
             WHERE tgname = 'trg_product_price_history_updated_at'
             AND tgrelid = 'product_price_history'::regclass"
        );

        if ($exists) {
            DB::statement('DROP TRIGGER trg_product_price_history_updated_at ON product_price_history');
        }
    }

    public function down(): void
    {
        DB::statement(
            'CREATE TRIGGER trg_product_price_history_updated_at '
            . 'BEFORE UPDATE ON product_price_history '
            . 'FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()'
        );
    }
};
