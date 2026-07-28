<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 — Same-branch enforcement trigger for warehouse_transfers.
 *
 * Creates a PostgreSQL trigger that prevents cross-branch transfers at the
 * database level. This is the last line of defense-in-depth:
 *   1. Client-side JS guard (create.blade.php)
 *   2. WarehouseBelongsToBranch validation rule
 *   3. Controller-level branch guard
 *   4. Service-level same-branch check (throws InvalidArgumentException)
 *   5. This DB trigger (raises check_violation exception)
 *
 * Cross-branch transfers MUST go through the Branch Demand module, which
 * has its own workflow (create → send → receive), approval chain, and
 * intercompany settlement tracking.
 *
 * The trigger uses Option B (trigger with friendly error message) instead
 * of a CHECK constraint, because existing cross-branch transfers may exist
 * in the database from the pre-Phase-1 Laravel implementation. A trigger
 * will prevent new ones while allowing existing ones to remain. A data
 * cleanup script can be run separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create the trigger function
        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_same_branch_transfer()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NEW.from_branch_id != NEW.to_branch_id THEN
                    RAISE EXCEPTION 'Cross-branch warehouse transfers are not allowed (from_branch=%, to_branch=%). Use Branch Demand module instead.',
                        NEW.from_branch_id, NEW.to_branch_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Create the trigger (fires BEFORE INSERT or UPDATE)
        DB::statement("
            CREATE TRIGGER trg_enforce_same_branch_transfer
            BEFORE INSERT OR UPDATE OF from_branch_id, to_branch_id ON warehouse_transfers
            FOR EACH ROW EXECUTE FUNCTION enforce_same_branch_transfer();
        ");

        // Add a comment documenting the trigger
        DB::statement("
            COMMENT ON FUNCTION enforce_same_branch_transfer() IS
            'Phase 1: Enforces same-branch constraint on warehouse_transfers. Cross-branch transfers must go through Branch Demand module.';
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS trg_enforce_same_branch_transfer ON warehouse_transfers");
        DB::statement("DROP FUNCTION IF EXISTS enforce_same_branch_transfer()");
    }
};
