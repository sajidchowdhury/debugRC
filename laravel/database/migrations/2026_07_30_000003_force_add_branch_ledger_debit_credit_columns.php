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
 * adds the missing columns, then drops the old ones.
 *
 * IMPORTANT: The materialized view mv_branch_intercompany depends on the old
 * columns (amount, is_settled). We must drop it first, then recreate it with
 * the new schema after the column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_ledger')) {
            return;
        }

        // ── Step 1: Drop the materialized view that depends on old columns ──
        DB::statement("DROP MATERIALIZED VIEW IF EXISTS mv_branch_intercompany CASCADE");

        // ── Step 2: Add new columns (IF NOT EXISTS — safe to run multiple times) ──
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS debit numeric(12,2) DEFAULT 0");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS credit numeric(12,2) DEFAULT 0");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS running_balance numeric(12,2) DEFAULT NULL");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS is_reversed boolean NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS created_by integer DEFAULT NULL");
        DB::statement("ALTER TABLE branch_ledger ADD COLUMN IF NOT EXISTS remarks text");

        // ── Step 3: Migrate data from old 'amount' column to new debit/credit ──
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

        // ── Step 4: Drop old columns that are replaced by the new schema ──
        DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS transaction_type");
        DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS amount");
        DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS is_settled");
        DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS settled_at");
        DB::statement("ALTER TABLE branch_ledger DROP COLUMN IF EXISTS description");

        // ── Step 5: Create indexes (safe to run multiple times) ──
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

        // ── Step 6: Recreate the materialized view with the new schema ──
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
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mv_branch_intercompany_from_to_idx ON mv_branch_intercompany (from_branch_id, to_branch_id)');

        // ── Step 7: Update the refresh_all_report_views() function ──
        // The function already references mv_branch_intercompany, but since we
        // dropped and recreated it, the function body is still valid.
        // Just refresh the view to populate it.
        try {
            DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_intercompany');
        } catch (\Throwable $e) {
            // If concurrent refresh fails (e.g. no data), try non-concurrent
            DB::statement('REFRESH MATERIALIZED VIEW mv_branch_intercompany');
        }
    }

    public function down(): void
    {
        // No down — we don't want to remove columns that other code depends on.
    }
};
