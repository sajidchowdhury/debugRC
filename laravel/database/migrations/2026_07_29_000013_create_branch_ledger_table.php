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
 *
 * MATERIALIALIZED VIEW DEPENDENCY:
 * The materialized view mv_branch_intercompany (created by migration
 * 2025_01_03_000001) is defined against the OLD branch_ledger schema —
 * it references bl.amount and bl.is_settled. PostgreSQL tracks this
 * dependency at the column level, so ALTER TABLE ... DROP COLUMN amount
 * fails with SQLSTATE[2BP01] "cannot drop column amount of table
 * branch_ledger because other objects depend on it: materialized view
 * mv_branch_intercompany depends on column amount".
 *
 * Fix: DROP MATERIALIZED VIEW mv_branch_intercompany CASCADE before
 * dropping the old columns, then recreate it against the NEW schema
 * (debit/credit/is_reversed). The later migration 2026_07_30_000003
 * performs the same drop+recreate with CREATE IF NOT EXISTS / DROP IF
 * EXISTS, so it is fully idempotent and will be a no-op when it runs
 * after this one.
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
            // ── Step 0: Drop dependent materialized view ──
            // mv_branch_intercompany (created by migration 2025_01_03_000001)
            // references the OLD columns (amount, is_settled) we are about to
            // drop. PostgreSQL refuses the DROP COLUMN with SQLSTATE[2BP01]
            // if we don't drop the MV first. We recreate it against the NEW
            // schema (debit/credit/is_reversed) at Step 5 below.
            //
            // CASCADE drops the MV's unique index too (it is recreated at Step 5).
            DB::statement("DROP MATERIALIZED VIEW IF EXISTS mv_branch_intercompany CASCADE");

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

        // ── Step 5: Recreate mv_branch_intercompany against the NEW schema ──
        // This view was dropped at Step 0 (if the old-schema branch ran) OR
        // may already exist (if 2025_01_03_000001 ran after a fresh migrate:fresh
        // and we took the else-branch above). CREATE IF NOT EXISTS handles
        // both cases safely.
        //
        // Definition mirrors migration 2026_07_30_000003 (which runs later and
        // also uses IF NOT EXISTS) — they are intentionally identical so the
        // order of execution does not matter.
        DB::statement(<<<'SQL'
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_branch_intercompany AS
SELECT
    bl.from_branch_id,
    bl.to_branch_id,
    fb.branch_name AS from_branch_name,
    tb.branch_name AS to_branch_name,
    SUM(bl.debit) AS total_debit,
    SUM(bl.credit) AS total_credit,
    SUM(bl.debit) - SUM(bl.credit) AS net_balance,
    SUM(CASE WHEN NOT bl.is_reversed THEN bl.debit - bl.credit ELSE 0 END) AS outstanding_amount,
    COUNT(*) AS entry_count
FROM branch_ledger bl
INNER JOIN branches fb ON fb.id = bl.from_branch_id
INNER JOIN branches tb ON tb.id = bl.to_branch_id
GROUP BY bl.from_branch_id, bl.to_branch_id, fb.branch_name, tb.branch_name
SQL);
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS mv_branch_intercompany_from_to_idx '
            . 'ON mv_branch_intercompany (from_branch_id, to_branch_id)'
        );
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_ledger CASCADE");
    }
};
