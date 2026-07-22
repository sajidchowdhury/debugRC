<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — BUG-3 fix.
 *
 * The PurchaseOrder model casts `expected_date` to date, includes it in
 * $fillable, and the controller validates `expected_date => 'nullable|date'`.
 * The service writes 'expected_date' => $data['expected_date'] ?? null on
 * both createOrder() and updateOrder(). The blade views have a date input
 * for it. But the column was missing from database/sql/05_purchase.sql — so
 * every PO create was silently failing on the INSERT.
 *
 * This migration is IDEMPOTENT — guarded by Schema::hasColumn().
 *
 * @see docs/PURCHASE_PARITY_PLAN.md §6.3 BUG-3
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_orders', 'expected_date')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            // Position after status to match the model's $fillable ordering.
            $table->date('expected_date')
                ->nullable()
                ->after('status')
                ->comment('Optional — when the supplier is expected to deliver');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_orders', 'expected_date')) {
            return;
        }
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('expected_date');
        });
    }
};
