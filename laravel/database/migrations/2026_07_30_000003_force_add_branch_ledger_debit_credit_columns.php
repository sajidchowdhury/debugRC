<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Force-add debit/credit/running_balance/is_reversed/remarks columns to branch_ledger.
 *
 * Migration 2026_07_29_000013 was supposed to ALTER the old branch_ledger schema
 * (amount, is_settled, transaction_type, description) to the new schema
 * (debit, credit, running_balance, is_reversed, remarks), but it used a conditional
 * check on is_settled that may have been skipped. This migration unconditionally
 * adds the missing columns using IF NOT EXISTS, so it's safe to run multiple times.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_ledger')) {
            return;
        }

        // Add new columns (IF NOT EXISTS equivalent via ADD COLUMN IF NOT EXISTS)
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS debit numeric(12,2) DEFAULT 0");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS credit numeric(12,2) DEFAULT 0");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS running_balance numeric(12,2) DEFAULT NULL");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS is_reversed boolean NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS created_by integer DEFAULT NULL");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS remarks text");

        // Migrate data from old 'amount' column to new debit/credit if amount still exists
        if (Schema::hasColumn('branch_ledger', 'amount')) {
            // Demand transfers: amount > 0 means debit for the debtor branch
            DB::statement("
                UPDATE branch_ledger
                SET debit = amount,
                    credit = 0,
                    running_balance = amount
                WHERE debit = 0 AND credit = 0 AND amount > 0
            ");
        }

        // Copy description → remarks if description still exists
        if (Schema::hasColumn('branch_ledger', 'description')) {
            DB::statement("
                UPDATE branch_ledger SET remarks = description
                WHERE remarks IS NULL AND description IS NOT NULL
            ");
        }

        // Set default for reference_type if nullable
        DB::statement("
            ALTER TABLE branch_ledger ALTER COLUMN reference_type SET DEFAULT 'adjustment'
        ");
        DB::statement("
            UPDATE branch_ledger SET reference_type = 'adjustment' WHERE reference_type IS NULL
        ");

        // Drop old columns that are replaced by the new schema
        if (Schema::hasColumn('branch_ledger', 'transaction_type')) {
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS transaction_type");
        }
        if (Schema::hasColumn('branch_ledger', 'amount')) {
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS amount");
        }
        if (Schema::hasColumn('branch_ledger', 'is_settled')) {
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS is_settled");
        }
        if (Schema::hasColumn('branch_ledger', 'settled_at')) {
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS settled_at");
        }
        if (Schema::hasColumn('branch_ledger', 'description')) {
            DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS description");
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
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bl_active
            ON branch_ledger(from_branch_id, to_branch_id, transaction_date)
            WHERE is_reversed = false
        ");
    }

    public function down(): void
    {
        // No down — we don't want to remove columns that other code depends on.
    }
};
