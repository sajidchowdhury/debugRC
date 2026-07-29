<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 — BUG-1 fix.
 *
 * The PurchaseReceive model + PurchaseReceiveService write/read the `status`
 * column ('draft'|'confirmed'|'cancelled') on every INSERT, UPDATE, and query.
 * The model exposes isDraft()/isConfirmed()/isCancelled() helpers. But the
 * column was missing from database/sql/05_purchase.sql — so every GRN create
 * was silently failing on the INSERT (PostgreSQL would reject the unknown
 * column).
 *
 * This migration is IDEMPOTENT — guarded by Schema::hasColumn() so it can be
 * re-run safely on environments where the column was manually added.
 *
 * @see docs/PURCHASE_PARITY_PLAN.md §6.1 BUG-1
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_receives', 'status')) {
            return;
        }

        Schema::table('purchase_receives', function (Blueprint $table) {
            // Position after total_amount to match purchase_orders.status ordering.
            $table->string('status', 20)
                ->default('draft')
                ->after('total_amount')
                ->comment('draft | confirmed | cancelled');
        });

        // Backfill existing rows (defensive — should be no rows in fresh install).
        DB::table('purchase_receives')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'draft']);

        // Add CHECK constraint for enum integrity (matches SQL spec pattern).
        DB::statement(
            "ALTER TABLE purchase_receives "
            . "ADD CONSTRAINT purchase_receives_status_check "
            . "CHECK (status IN ('draft','confirmed','cancelled'))"
        );

        // Index for index() controller filter `->where('status', $s)`.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_pr_status ON purchase_receives(status)"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_receives', 'status')) {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS idx_pr_status');
        DB::statement('ALTER TABLE purchase_receives DROP CONSTRAINT IF EXISTS purchase_receives_status_check');
        Schema::table('purchase_receives', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
