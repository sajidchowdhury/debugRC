<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix cross-branch warehouse transfer trigger to allow interbranch transfers.
 *
 * The original trigger (enforce_same_branch_transfer) rejected ALL cross-branch
 * transfers. The Branch Demand module legitimately creates documentary
 * cross-branch transfers with is_interbranch = true. This migration updates
 * the trigger to allow those transfers through while still blocking
 * cross-branch transfers that are NOT flagged as interbranch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_enforce_same_branch_transfer ON warehouse_transfers;
            DROP FUNCTION IF EXISTS enforce_same_branch_transfer();

            CREATE OR REPLACE FUNCTION enforce_same_branch_transfer()
            RETURNS TRIGGER AS \$\$
            BEGIN
                -- Allow interbranch transfers created by Branch Demand module
                IF NEW.from_branch_id != NEW.to_branch_id AND NOT NEW.is_interbranch THEN
                    RAISE EXCEPTION 'Cross-branch warehouse transfers are not allowed in this module. Use the Branch Demand module instead.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_enforce_same_branch_transfer
                BEFORE INSERT OR UPDATE ON warehouse_transfers
                FOR EACH ROW EXECUTE FUNCTION enforce_same_branch_transfer();
        ");
    }

    public function down(): void
    {
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_enforce_same_branch_transfer ON warehouse_transfers;
            DROP FUNCTION IF EXISTS enforce_same_branch_transfer();

            CREATE OR REPLACE FUNCTION enforce_same_branch_transfer()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NEW.from_branch_id != NEW.to_branch_id THEN
                    RAISE EXCEPTION 'Cross-branch warehouse transfers are not allowed in this module. Use the Branch Demand module instead.';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_enforce_same_branch_transfer
                BEFORE INSERT OR UPDATE ON warehouse_transfers
                FOR EACH ROW EXECUTE FUNCTION enforce_same_branch_transfer();
        ");
    }
};
