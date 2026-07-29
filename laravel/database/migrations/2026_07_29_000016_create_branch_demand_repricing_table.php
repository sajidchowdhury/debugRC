<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.7 — Create branch_demand_repricing table.
 *
 * Tracks repricing adjustments for branch demands. When branches agree
 * to reprice a demand (e.g., due to a product price change), a repricing
 * adjustment is created that:
 *   - Records the original and new total value
 *   - Calculates the adjustment amount
 *   - Posts a GL adjustment journal
 *   - Updates the branch ledger
 *
 * This is the mechanism for handling price changes between the time
 * goods are sent and the time they are settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS branch_demand_repricing (
                id                    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                branch_demand_id      integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
                original_total_value  numeric(12,2) NOT NULL,
                new_total_value       numeric(12,2) NOT NULL,
                adjustment_amount     numeric(12,2) NOT NULL,
                reason                text,
                approved_by           integer DEFAULT NULL,
                journal_entry_id      integer REFERENCES journal_entries(id),
                created_by            integer DEFAULT NULL,
                created_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_bdr_demand
            ON branch_demand_repricing(branch_demand_id)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS branch_demand_repricing CASCADE");
    }
};
