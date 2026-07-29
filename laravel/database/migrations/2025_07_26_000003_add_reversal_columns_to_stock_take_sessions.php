<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (Stock Take plan) — Add reversal columns to stock_take_sessions.
 *
 * P0 blocker fix: StockTakeService::createSession() writes 'is_reversed'
 * and cancelSession() writes 'is_reversed' / 'reversed_at' / 'reversed_by'
 * / 'reverse_reason', but database/sql/03_stock.sql never defined these
 * columns. As a result every createSession() INSERT crashed with
 * SQLSTATE[42703]: Undefined column: 7 ERROR: column "is_reversed" of
 * relation "stock_take_sessions" does not exist.
 *
 * The Eloquent model (App\Models\StockTakeSession) already declares all
 * four columns in $fillable and $casts — only the DB schema was missing.
 *
 * The column set mirrors the sibling stock_adjustments table exactly
 * (see database/sql/03_stock.sql lines 99-117):
 *   is_reversed     boolean NOT NULL DEFAULT false
 *   reversed_at     timestamp(0) nullable
 *   reversed_by     integer nullable   (plain integer, no FK — matches
 *                                       stock_adjustments.reversed_by;
 *                                       avoids FK deferral complications)
 *   reverse_reason  text nullable
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §1.2, §7 Phase 0
 *   - app/Services/Stock/StockTakeService.php  lines 72, 348-353
 *   - app/Models/StockTakeSession.php  lines 51-54, 61-66
 *   - tests/Helpers/InsertsWarehouseDependencies.php  (comment "no is_reversed column")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_take_sessions', 'is_reversed')) {
                // `after()` is a no-op on PostgreSQL (column order is cosmetic);
                // kept for parity with the stock_transactions reversal migration.
                $table->boolean('is_reversed')->default(false)->after('journal_entry_id');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('is_reversed');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'reversed_by')) {
                $table->integer('reversed_by')->nullable()->after('reversed_at');
            }
            if (!Schema::hasColumn('stock_take_sessions', 'reverse_reason')) {
                $table->text('reverse_reason')->nullable()->after('reversed_by');
            }
        });

        // Partial index for fast reversal lookups (only reversed rows are indexed).
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'stock_take_sessions' AND indexname = 'idx_sts_is_reversed'"
        ))->count()) {
            DB::statement(
                'CREATE INDEX idx_sts_is_reversed ON stock_take_sessions (is_reversed) WHERE is_reversed = true'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sts_is_reversed');

        Schema::table('stock_take_sessions', function (Blueprint $table) {
            $table->dropColumn(['is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason']);
        });
    }
};
