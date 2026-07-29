<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.1 — Align branch_demands table with legacy schema.
 *
 * The PostgreSQL schema (03_stock.sql) was created as a placeholder with
 * only basic columns. The legacy MySQL system has additional columns that
 * are essential for the Branch Demand business logic:
 *
 *   - total_value: locked total of qty × cost_rate at send time
 *   - settlement_amount: running total of FIFO settlements
 *   - warehouse_transfer_id: FK to the documentary warehouse_transfers row
 *   - journal_entry_id_debtor: debtor-branch fulfillment journal
 *   - received_at / received_by: warehouse manager receipt confirmation
 *   - reversed_at / reversed_by / reverse_reason: reversal metadata
 *
 * Also aligns the status CHECK constraint with the legacy values
 * ('pending','received','rejected','reversed'), removing the unused
 * 'approved','fulfilled','cancelled' values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add missing columns
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS total_value numeric(12,2) DEFAULT NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS settlement_amount numeric(12,2) DEFAULT 0
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS warehouse_transfer_id integer
                REFERENCES warehouse_transfers(id) ON DELETE SET NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS journal_entry_id_debtor integer
                REFERENCES journal_entries(id)
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS received_at timestamp(0) DEFAULT NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS received_by integer DEFAULT NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS reversed_at timestamp(0) DEFAULT NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS reversed_by integer DEFAULT NULL
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD COLUMN IF NOT EXISTS reverse_reason text DEFAULT NULL
        ");

        // 2. Align status CHECK constraint with legacy values
        // Drop the old constraint and add the new one.
        // The old constraint name is branch_demands_status_check (PG auto-named).
        DB::statement("
            ALTER TABLE branch_demands
            DROP CONSTRAINT IF EXISTS branch_demands_status_check
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD CONSTRAINT branch_demands_status_check
            CHECK (status IN ('pending','received','rejected','reversed'))
        ");

        // 3. Add indexes for common query patterns
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bd_status
            ON branch_demands(status)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bd_warehouse_transfer
            ON branch_demands(warehouse_transfer_id)
        ");
    }

    public function down(): void
    {
        // Drop indexes
        DB::statement("DROP INDEX IF EXISTS idx_bd_warehouse_transfer");
        DB::statement("DROP INDEX IF EXISTS idx_bd_status");

        // Restore original status CHECK
        DB::statement("
            ALTER TABLE branch_demands
            DROP CONSTRAINT IF EXISTS branch_demands_status_check
        ");
        DB::statement("
            ALTER TABLE branch_demands
            ADD CONSTRAINT branch_demands_status_check
            CHECK (status IN ('pending','approved','rejected','fulfilled','cancelled'))
        ");

        // Drop added columns
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS reverse_reason");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS reversed_by");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS reversed_at");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS received_by");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS received_at");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS journal_entry_id_debtor");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS warehouse_transfer_id");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS settlement_amount");
        DB::statement("ALTER TABLE branch_demands DROP COLUMN IF EXISTS total_value");
    }
};
