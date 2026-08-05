<?php

/**
 * G-354 (G28) FINANCE-BD-1: Add rejection columns to branch_demands.
 *
 * `BranchDemandService::rejectDemand` previously appended "[Rejected: {reason}]"
 * to the `notes` field via text concatenation. This made it impossible to query
 * rejected demands by reason (e.g., `WHERE rejection_reason ILIKE '%out of stock%'`)
 * and polluted the notes field with structured metadata.
 *
 * This migration adds 3 nullable columns mirroring the existing `reverse_*`
 * pattern (L755-757): `rejection_reason` (text), `rejected_at` (timestamp),
 * `rejected_by` (integer FK to users). The companion service change replaces
 * the notes-concat with structured column updates. Existing rejected demands
 * have NULL rejection_reason (acceptable — historical rejections stay in the
 * audit log + notes; the new columns are populated going forward).
 *
 * The `BranchDemand` model adds the 3 columns to `$fillable` + `$casts` +
 * a `rejectedBy()` belongsTo relationship. The `BranchDemandResource` exposes
 * them behind the `?with=audit` opt-in flag (G-345).
 *
 * Idempotent: `Schema::hasColumn` guards each ADD COLUMN.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('branch_demands', 'rejection_reason')) {
            DB::statement('ALTER TABLE branch_demands ADD COLUMN rejection_reason text');
        }
        if (!Schema::hasColumn('branch_demands', 'rejected_at')) {
            DB::statement('ALTER TABLE branch_demands ADD COLUMN rejected_at timestamp(0)');
        }
        if (!Schema::hasColumn('branch_demands', 'rejected_by')) {
            DB::statement('ALTER TABLE branch_demands ADD COLUMN rejected_by integer');
        }

        echo "  G-354: added rejection_reason + rejected_at + rejected_by columns to branch_demands.\n";
    }

    public function down(): void
    {
        foreach (['rejected_by', 'rejected_at', 'rejection_reason'] as $col) {
            if (Schema::hasColumn('branch_demands', $col)) {
                DB::statement("ALTER TABLE branch_demands DROP COLUMN {$col}");
            }
        }
    }
};
