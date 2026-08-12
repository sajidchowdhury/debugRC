<?php

/**
 * FINANCE-1 — G-103 (HIGH, notifications phase)
 *
 * Adds `status` + `reversed_by` + `reversed_at` + `reverse_reason` columns
 * to `asset_disposals` so disposal reversal can SOFT-DELETE (mark
 * `status='reversed'`) instead of HARD-DELETE (`DELETE FROM asset_disposals`).
 *
 * Before this migration, `AssetDisposalService::reverseDisposal` called
 * `$disposal->delete()` which fired a raw `DELETE FROM asset_disposals` —
 * the disposal record vanished, the GL reversal JE's `reference_id` pointed
 * at nothing, and the audit trail was broken. The `asset_disposals` table
 * is the sub-ledger proof that an asset was disposed (with proceeds, gain/
 * loss, linked JE, reason). Hard-deleting it is an append-only-audit
 * principle violation.
 *
 * After this migration, `reverseDisposal` sets `status='reversed'` +
 * `reversed_by` + `reversed_at` + `reverse_reason` and LEAVES the row in
 * place. The disposal history is preserved; the GL reversal JE's
 * `reference_id` still resolves; accountants + auditors can see the full
 * disposal → reversal chain.
 *
 * The `status` column mirrors `asset_depreciation_schedules.status`:
 *   - pending:  disposal record created but not yet posted to GL (future use)
 *   - posted:   disposal JE posted to GL, asset status flipped to 'disposed'
 *   - reversed: disposal reversed, GL reversal JE posted, asset restored
 *
 * Existing rows (created before this migration) default to `status='posted'`
 * — every disposal row in production has a linked `journal_entry_id` and
 * the asset is already `disposed`, so `posted` is the correct historical
 * state.
 *
 * Also adds a partial index `idx_ad_active` filtering `WHERE status != 'reversed'`
 * so the disposal worklist query (admin UI "active disposals") skips
 * reversed rows without a full table scan.
 *
 * Resolves: G-103. Depends on: 2026_08_13_000001_create_fixed_assets.php.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_disposals', function (Blueprint $table) {
            // Status machine: pending → posted → reversed (mirrors schedules).
            // Default 'posted' for existing rows (see migration docblock).
            $table->string('status', 20)->default('posted')
                  ->after('journal_entry_id')
                  ->check("status IN ('pending','posted','reversed')");

            $table->unsignedBigInteger('reversed_by')->nullable()->after('status');
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->text('reverse_reason')->nullable()->after('reversed_at');
        });

        DB::statement(
            "CREATE INDEX idx_ad_active ON asset_disposals (fixed_asset_id, disposal_date) "
            . "WHERE status != 'reversed'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_ad_active');

        Schema::table('asset_disposals', function (Blueprint $table) {
            $table->dropColumn(['status', 'reversed_by', 'reversed_at', 'reverse_reason']);
        });
    }
};
