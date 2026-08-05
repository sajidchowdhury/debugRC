<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-3 (G-329) — Add `journal_entry_id_debtor` column to
 * `branch_demand_repricing`.
 *
 * The table previously stored only ONE `journal_entry_id` (the creditor /
 * supplier-side JE). `BranchDemandRepricingService::postRepricingAdjustmentJournals`
 * posts TWO journal entries (creditor + debtor) but only persisted the
 * creditor id. The audit trail therefore lost the debtor-side journal
 * reference — reversing a repricing could not trace back to the debtor JE
 * without a `reference_type='branch_demand_repricing'` scan.
 *
 * This migration adds the `journal_entry_id_debtor` column, mirroring the
 * pattern already used on `branch_demands` (migration
 * `2026_07_29_000010_align_branch_demands_table`). The existing
 * `journal_entry_id` column is now semantically the CREDITOR JE id (the
 * supplier-side adjustment); the new `journal_entry_id_debtor` is the
 * DEBTOR JE id (the requester-side adjustment). Both are nullable because
 * a repricing may have only one side posted (rare; legacy data may have
 * only the creditor id).
 *
 * The SQL baseline `database/sql/09_branch_demand.sql` is updated in the
 * same commit to mirror this migration.
 *
 * Down: drops the column. Existing debtor JE references would be lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE branch_demand_repricing
            ADD COLUMN IF NOT EXISTS journal_entry_id_debtor integer
                REFERENCES journal_entries(id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdr_journal_debtor
            ON branch_demand_repricing (journal_entry_id_debtor)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_bdr_journal_debtor");
        DB::statement("ALTER TABLE branch_demand_repricing DROP COLUMN IF EXISTS journal_entry_id_debtor");
    }
};
