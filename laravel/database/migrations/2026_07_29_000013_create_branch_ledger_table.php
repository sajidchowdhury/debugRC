<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.4 — Create branch_ledger table.
 *
 * The branch_ledger tracks the running balance between branches for
 * inter-branch demand transfers and settlements. Each transaction
 * (demand transfer or settlement) creates a pair of rows:
 *   - Debtor row: records the debit (owes more) or credit (paid)
 *   - Creditor row: records the credit (owed more) or debit (received)
 *
 * The running_balance column tracks the net owed between each branch pair
 * after each transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The base SQL (02_accounting.sql) creates branch_ledger with the OLD schema
        // (amount, is_settled, transaction_type, description). The Branch Demand feature
        // needs the NEW schema (debit, credit, running_balance, is_reversed, remarks, created_by).
        // Since CREATE TABLE IF NOT EXISTS silently skips when the table already exists,
        // we must ALTER the existing table to add the new columns and drop the old ones.

        $hasOldSchema = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM information_schema.columns
            WHERE table_name = 'branch_ledger'
              AND column_name = 'is_settled'
        ");

        if ((int) $hasOldSchema->cnt > 0) {
            // Old schema exists — ALTER to add new columns
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS debit numeric(12,2) DEFAULT 0");
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS credit numeric(12,2) DEFAULT 0");
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS running_balance numeric(12,2) DEFAULT NULL");
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS is_reversed boolean NOT NULL DEFAULT false");
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS created_by integer DEFAULT NULL");

            // Migrate data: amount → debit/credit (existing rows are demand_send = debit)
            DB::statement("
                UPDATE branch_ledger
                SET debit = amount,
                    credit = 0,
                    running_balance = amount
                WHERE debit = 0 AND credit = 0 AND amount > 0
            ");

            // Add remarks column if not exists (replaces description)
            DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS remarks text");

            // Copy description → remarks where remarks is null
            DB::statement("
                UPDATE branch_ledger SET remarks = description
                WHERE remarks IS NULL AND description IS NOT NULL
            ");

            // Change reference_type to NOT NULL DEFAULT 'adjustment' if it's nullable
            DB::statement("
                ALTER TABLE branch_ledger ALTER COLUMN reference_type SET DEFAULT 'adjustment'
            ");
            DB::statement("
                UPDATE branch_ledger SET reference_type = 'adjustment' WHERE reference_type IS NULL
            ");

            // Drop old columns that are no longer needed
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS transaction_type");
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS amount");
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS is_settled");
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS settled_at");
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS description");

            // Drop old single-column indexes (replaced by composite)
            DB::statement("DROP INDEX IF EXISTS idx_bl_from_branch");
            DB::statement("DROP INDEX IF EXISTS idx_bl_to_branch");
            DB::statement("DROP INDEX IF EXISTS idx_bl_unsettled");
        } else {
            // Table doesn't exist yet — create with new schema
            DB::statement("
                CREATE TABLE IF NOT EXISTS branch_ledger (
                    id                integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    transaction_date  date NOT NULL,
                    from_branch_id    integer NOT NULL REFERENCES branches(id),
                    to_branch_id      integer NOT NULL REFERENCES branches(id),
                    reference_type    varchar(50) NOT NULL DEFAULT 'adjustment',
                    reference_id      integer DEFAULT NULL,
                    journal_entry_id  integer REFERENCES journal_entries(id),
                    debit             numeric(12,2) DEFAULT 0,
                    credit            numeric(12,2) DEFAULT 0,
                    running_balance   numeric(12,2) DEFAULT NULL,
                    remarks           text,
                    is_reversed       boolean NOT NULL DEFAULT false,
                    created_by        integer DEFAULT NULL,
                    created_at        timestamp(0) DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }

        // Create indexes (safe to run multiple times)
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bl_branches
            ON branch_ledger(from_branch_id, to_branch_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bl_reference
            ON branch_ledger(reference_type, reference_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bl_date
            ON branch_ledger(transaction_date)
        ");
        // Replaces the old idx_bl_unsettled (which used is_settled) with
        // an active-ledger index on is_reversed.
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bl_active
            ON branch_ledger(from_branch_id, to_branch_id, transaction_date)
            WHERE is_reversed = false
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_ledger CASCADE");
    }
};
