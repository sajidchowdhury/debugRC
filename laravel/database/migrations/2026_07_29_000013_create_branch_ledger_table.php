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
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_ledger CASCADE");
    }
};
