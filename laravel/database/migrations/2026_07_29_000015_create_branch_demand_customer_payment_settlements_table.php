<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.6 — Create branch_demand_customer_payment_settlements table.
 *
 * Tracks which bank customer payments have settled which branch demands.
 * Used by the FIFO settlement system: when a customer payment with
 * payment_mode = 'bank' is recorded at the debtor branch, it auto-settles
 * open branch demands in FIFO order (oldest first).
 *
 * Note: Cash customer payments do NOT settle branch demands (they use
 * inter-branch money transfers instead). Only bank payments settle demands
 * because the bank is central (not branch-specific).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS branch_demand_customer_payment_settlements (
                id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                payment_id      integer NOT NULL REFERENCES customer_payments(id) ON DELETE CASCADE,
                demand_id       integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
                settled_amount  numeric(12,2) NOT NULL,
                created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdcps_demand
            ON branch_demand_customer_payment_settlements(demand_id)
        ");
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdcps_payment
            ON branch_demand_customer_payment_settlements(payment_id)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_demand_customer_payment_settlements CASCADE");
    }
};
