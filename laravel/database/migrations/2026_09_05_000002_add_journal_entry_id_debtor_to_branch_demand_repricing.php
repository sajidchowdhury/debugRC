<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FINANCE-3 (G-329) — Add `journal_entry_id_debtor` column to
 * `branch_demand_repricing` + trigger-based FK to journal_entries.
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
 * HOTFIX (post-8cfe7ca): The original version of this migration added the
 * column with a declarative `REFERENCES journal_entries(id)` clause. That
 * fails at runtime on the upgrade path because `journal_entries` was
 * partitioned by `entry_date` in migration `2026_08_22_000002` (PK is now
 * `(id, entry_date)`, not `id` alone). PostgreSQL REJECTS declarative FK
 * references to a partitioned parent unless the partition key is included
 * in the referenced unique constraint. The codebase's established pattern
 * (migrations `2026_08_15_000003` + `2026_08_22_000004`) is to enforce
 * these FKs via triggers instead. This migration mirrors that pattern:
 *
 *   1. ADD COLUMN ... integer            (NO declarative FK clause)
 *   2. fn_trg_{table}_{column}_je_fk()   (checks parent existence)
 *   3. trg_{table}_{column}_je_fk        (BEFORE INSERT OR UPDATE)
 *   4. trg_je_del_cascade_{table}_{col}  (CONSTRAINT TRIGGER, NO_ACTION —
 *      prevents parent delete if child rows exist)
 *
 * The `on_delete = NO_ACTION` matches the behaviour configured for the
 * existing `branch_demand_repricing.journal_entry_id` FK in
 * `2026_08_22_000004`'s FK_MAP — journal entries are reversed, not deleted,
 * so NO_ACTION is the correct semantics.
 *
 * The SQL baseline `database/sql/09_branch_demand.sql` retains the
 * declarative `REFERENCES journal_entries(id)` clause for documentation
 * consistency with every other `journal_entry_id` column in the SQL
 * baselines; on fresh installs the declarative FK is dropped by the
 * partition migration (`2026_08_22_000002`) and this migration re-creates
 * the enforcement as a trigger-based FK idempotently.
 *
 * Down: drops the column + all trigger-based FK artefacts. Existing debtor
 * JE references would be lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Add the column as a plain integer — NO declarative FK.
        //    journal_entries is partitioned (PK = (id, entry_date)),
        //    so PostgreSQL rejects `REFERENCES journal_entries(id)`.
        //    FK enforcement is set up via triggers below.
        // ============================================================
        DB::statement("
            ALTER TABLE branch_demand_repricing
            ADD COLUMN IF NOT EXISTS journal_entry_id_debtor integer
        ");

        // ============================================================
        // 2. Trigger function — checks parent existence on INSERT/UPDATE.
        //    Pattern: fn_trg_{table}_{column}_je_fk (same as 2026_08_22_000004).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_fk()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.journal_entry_id_debtor IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM journal_entries
                        WHERE id = NEW.journal_entry_id_debtor
                    ) THEN
                        RAISE EXCEPTION 'FK violation: journal_entries(id=%) not found for branch_demand_repricing.journal_entry_id_debtor',
                            NEW.journal_entry_id_debtor;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // ============================================================
        // 3. BEFORE INSERT OR UPDATE trigger on the child table.
        // ============================================================
        DB::statement("DROP TRIGGER IF EXISTS trg_branch_demand_repricing_journal_entry_id_debtor_je_fk ON branch_demand_repricing");
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_branch_demand_repricing_journal_entry_id_debtor_je_fk
                BEFORE INSERT OR UPDATE OF journal_entry_id_debtor ON branch_demand_repricing
                FOR EACH ROW EXECUTE FUNCTION fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_fk()
        SQL);

        // ============================================================
        // 4. NO_ACTION constraint trigger on journal_entries — prevents
        //    parent delete if child rows exist. Matches the on_delete
        //    behaviour configured for branch_demand_repricing.journal_entry_id
        //    in 2026_08_22_000004's FK_MAP. Uses a CONSTRAINT TRIGGER so it
        //    can be DEFERRABLE if needed.
        // ============================================================
        DB::statement("DROP TRIGGER IF EXISTS trg_je_del_cascade_branch_demand_repricing_journal_entry_id_debtor ON journal_entries");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_cascade()
            RETURNS TRIGGER AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM branch_demand_repricing WHERE journal_entry_id_debtor = OLD.id
                ) THEN
                    RAISE EXCEPTION 'Cannot delete journal_entries(id=%): referenced by branch_demand_repricing.journal_entry_id_debtor',
                        OLD.id;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_je_del_cascade_branch_demand_repricing_journal_entry_id_debtor
                AFTER DELETE ON journal_entries
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_cascade()
        SQL);

        // ============================================================
        // 5. B-tree index for lookup-by-debtor-JE queries (same as the
        //    idx_bdr_journal_debtor declared in the SQL baseline).
        // ============================================================
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdr_journal_debtor
            ON branch_demand_repricing (journal_entry_id_debtor)
        ");

        Log::info('G-329: Added branch_demand_repricing.journal_entry_id_debtor column + trigger-based FK (NO_ACTION) to journal_entries.');
    }

    public function down(): void
    {
        // Drop index.
        DB::statement("DROP INDEX IF EXISTS idx_bdr_journal_debtor");

        // Drop parent-side constraint trigger + function.
        DB::statement("DROP TRIGGER IF EXISTS trg_je_del_cascade_branch_demand_repricing_journal_entry_id_debtor ON journal_entries");
        DB::statement("DROP FUNCTION IF EXISTS fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_cascade() CASCADE");

        // Drop child-side trigger + function.
        DB::statement("DROP TRIGGER IF EXISTS trg_branch_demand_repricing_journal_entry_id_debtor_je_fk ON branch_demand_repricing");
        DB::statement("DROP FUNCTION IF EXISTS fn_trg_branch_demand_repricing_journal_entry_id_debtor_je_fk() CASCADE");

        // Drop the column (cascades the index if not already dropped).
        DB::statement("ALTER TABLE branch_demand_repricing DROP COLUMN IF EXISTS journal_entry_id_debtor");
    }
};
