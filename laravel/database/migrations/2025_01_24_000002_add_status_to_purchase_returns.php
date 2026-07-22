<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 — BUG-2 fix.
 *
 * The PurchaseReturn model + PurchaseReturnService write/read the `status`
 * column ('draft'|'confirmed'|'cancelled') on every INSERT, UPDATE, and query.
 * The model exposes isDraft()/isConfirmed()/isCancelled() helpers. But the
 * column was missing from database/sql/05_purchase.sql — so every Return
 * create was silently failing on the INSERT.
 *
 * This migration is IDEMPOTENT — guarded by Schema::hasColumn() so it can be
 * re-run safely on environments where the column was manually added.
 *
 * @see docs/PURCHASE_PARITY_PLAN.md §6.2 BUG-2
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_returns', 'status')) {
            return;
        }

        Schema::table('purchase_returns', function (Blueprint $table) {
            // Position after total_amount to match the sibling tables.
            $table->string('status', 20)
                ->default('draft')
                ->after('total_amount')
                ->comment('draft | confirmed | cancelled');
        });

        // Backfill existing rows (defensive — should be no rows in fresh install).
        DB::table('purchase_returns')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'draft']);

        // Add CHECK constraint for enum integrity.
        DB::statement(
            "ALTER TABLE purchase_returns "
            . "ADD CONSTRAINT purchase_returns_status_check "
            . "CHECK (status IN ('draft','confirmed','cancelled'))"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_prtn_status ON purchase_returns(status)"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_returns', 'status')) {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS idx_prtn_status');
        DB::statement('ALTER TABLE purchase_returns DROP CONSTRAINT IF EXISTS purchase_returns_status_check');
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
