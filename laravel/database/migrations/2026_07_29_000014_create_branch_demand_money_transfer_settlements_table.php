<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.5 — Create branch_demand_money_transfer_settlements table.
 *
 * Tracks which inter-branch money transfers have settled which branch demands.
 * Used by the FIFO settlement system: when a money transfer (cash_to_cash or
 * cash_to_bank) is made between branches, it auto-settles open branch demands
 * in FIFO order (oldest first).
 *
 * Note: A separate table from the existing customer_payment_settlements
 * (which tracks invoice-level allocations). This table specifically tracks
 * branch demand settlement from inter-branch money transfers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS branch_demand_money_transfer_settlements (
                id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                transfer_id     integer NOT NULL REFERENCES money_transfers(id) ON DELETE CASCADE,
                demand_id       integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
                settled_amount  numeric(12,2) NOT NULL,
                created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdmts_demand
            ON branch_demand_money_transfer_settlements(demand_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdmts_transfer
            ON branch_demand_money_transfer_settlements(transfer_id)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_demand_money_transfer_settlements CASCADE");
    }
};
