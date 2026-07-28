<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — Add branch_demand_id column to warehouse_transfers.
 *
 * This column links a warehouse transfer to a Branch Demand (if the transfer
 * was created as part of a demand fulfillment). Transfers linked to a Branch
 * Demand cannot be cancelled via the WarehouseTransfer module — they must
 * be cancelled through the Branch Demand module, which handles the full
 * reversal workflow (cancel demand → reverse linked transfers).
 *
 * Also adds the branch_demand_id to the same-branch trigger exclusion:
 * if a transfer is linked to a branch_demand, the same-branch enforcement
 * trigger should NOT block it (interbranch transfers via Branch Demand are
 * allowed by design, though they are NOT created via WarehouseTransferService).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE warehouse_transfers
            ADD COLUMN IF NOT EXISTS branch_demand_id integer REFERENCES branch_demands(id) ON DELETE SET NULL
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_wt_branch_demand_id
            ON warehouse_transfers(branch_demand_id)
        ");

        // Update the same-branch trigger function to allow interbranch
        // when the transfer is linked to a branch demand (Phase 3.3).
        // Although WarehouseTransferService won't create such transfers,
        // the Branch Demand module may create them via a different path.
        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_same_branch_transfer()
            RETURNS trigger AS \$\$
            BEGIN
                -- If linked to a branch demand, same-branch enforcement is
                -- relaxed (Branch Demand handles interbranch via its own flow).
                IF NEW.branch_demand_id IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.from_branch_id IS DISTINCT FROM NEW.to_branch_id THEN
                    RAISE EXCEPTION 'Cross-branch warehouse transfers are not allowed in this module. Use the Branch Demand module instead.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");
    }

    public function down(): void
    {
        DB::statement("
            DROP INDEX IF EXISTS idx_wt_branch_demand_id
        ");

        DB::statement("
            ALTER TABLE warehouse_transfers
            DROP COLUMN IF EXISTS branch_demand_id
        ");

        // Restore the original trigger function (strict same-branch only)
        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_same_branch_transfer()
            RETURNS trigger AS \$\$
            BEGIN
                IF NEW.from_branch_id IS DISTINCT FROM NEW.to_branch_id THEN
                    RAISE EXCEPTION 'Cross-branch warehouse transfers are not allowed in this module. Use the Branch Demand module instead.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");
    }
};
