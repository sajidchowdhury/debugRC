<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.1 — Add reversal columns to stock_transactions.
 *
 * stock_transactions is the immutable inventory ledger (SSOT). Reversals
 * are append-only (a new row with opposite-sign qty), but we need to mark
 * the original as reversed to prevent double-reversal.
 *
 * Columns added:
 *   is_reversed (boolean, default false)
 *   reversal_of_transaction_id (nullable FK to stock_transactions.id)
 *   reversed_at (nullable timestamp)
 *   reversed_by (nullable int → users.id)
 *   reverse_reason (nullable text)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_transactions', 'is_reversed')) {
                $table->boolean('is_reversed')->default(false)->after('notes');
            }
            if (!Schema::hasColumn('stock_transactions', 'reversal_of_transaction_id')) {
                // Note: FK is enforced by trigger trg_st_reversal_fk, created by
                // migration 2025_01_21_000004_set_up_table_partitioning.php.
                // stock_transactions is PARTITION BY RANGE (transaction_date),
                // so a declarative FK on `id` alone is not allowed (PG 12-17
                // requires the referenced columns to include the partition key
                // and form a UNIQUE constraint).
                $table->integer('reversal_of_transaction_id')->nullable()->after('is_reversed');
            }
            if (!Schema::hasColumn('stock_transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('reversal_of_transaction_id');
            }
            if (!Schema::hasColumn('stock_transactions', 'reversed_by')) {
                $table->integer('reversed_by')->nullable()->after('reversed_at');
            }
            if (!Schema::hasColumn('stock_transactions', 'reverse_reason')) {
                $table->text('reverse_reason')->nullable()->after('reversed_by');
            }
        });

        // Index for quick reversal lookups
        if (!collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'stock_transactions' AND indexname = 'idx_st_reversal_of'"))->count()) {
            DB::statement('CREATE INDEX idx_st_reversal_of ON stock_transactions (reversal_of_transaction_id) WHERE reversal_of_transaction_id IS NOT NULL');
        }
        if (!collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'stock_transactions' AND indexname = 'idx_st_is_reversed'"))->count()) {
            DB::statement('CREATE INDEX idx_st_is_reversed ON stock_transactions (is_reversed) WHERE is_reversed = true');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_st_is_reversed');
        DB::statement('DROP INDEX IF EXISTS idx_st_reversal_of');

        Schema::table('stock_transactions', function (Blueprint $table) {
            // No FK to drop — FK is trigger-based (trg_st_reversal_fk).
            $table->dropColumn(['is_reversed', 'reversal_of_transaction_id', 'reversed_at', 'reversed_by', 'reverse_reason']);
        });
    }
};
