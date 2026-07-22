<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — BUG-10 fix (discovered during Phase 0 verification).
 *
 * The PurchaseReturn model has `reason` in $fillable and exposes it as a
 * property. PurchaseReturnService::createReturn() writes
 *     'reason' => $data['reason'] ?? null
 * on the INSERT (line 87 of the service). The PurchaseReturn controller
 * passes `$data['reason']` from the request. But the column was missing
 * from database/sql/05_purchase.sql — only `reverse_reason` (the cancel
 * reason) and `notes` existed. So every Return create was failing on the
 * INSERT.
 *
 * The intent: `reason` is the ORIGINAL return reason (why are we returning
 * these goods to the supplier?). `reverse_reason` is the CANCELLATION reason
 * (why are we cancelling this return?). `notes` is freeform. All three are
 * distinct semantically — keep them separate.
 *
 * This migration is IDEMPOTENT — guarded by Schema::hasColumn().
 *
 * @see docs/PURCHASE_PARITY_PLAN.md §6.10 BUG-10 (added during Phase 0)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_returns', 'reason')) {
            return;
        }

        Schema::table('purchase_returns', function (Blueprint $table) {
            // Position after notes to keep it grouped with the other text fields.
            $table->text('reason')
                ->nullable()
                ->after('notes')
                ->comment('Original return reason — why goods are being returned to supplier');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_returns', 'reason')) {
            return;
        }
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
