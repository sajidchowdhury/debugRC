<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 0.2: Add missing pg_partman retention configs.
 *
 * Audit finding B2 + B3: Phase 4 partitioned 9 transaction header tables
 * but did NOT configure retention in partman.part_config. The initial-setup
 * tables (stock_transactions, sales_invoices) also lack retention.
 * Without retention, old monthly partitions accumulate forever.
 *
 * This migration adds retention config for the 11 missing tables:
 *
 *   Phase 4 tables (9) — 84 months (7 years, financial compliance):
 *     - money_transfers
 *     - employee_transactions
 *     - other_incomes
 *     - other_expenses
 *     - sales_returns
 *     - purchase_receives
 *     - purchase_returns
 *     - damage_invoices
 *     - manual_journals
 *
 *   Initial-setup tables (2) — 84 months (7 years):
 *     - stock_transactions
 *     - sales_invoices
 *
 * All use retention_keep_table = true (don't DROP, just DETACH) and
 * retention_schema = 'archive' (move detached partitions to archive schema).
 *
 * Idempotent — uses UPDATE ... WHERE retention IS NULL so re-runs are safe.
 */
return new class extends Migration
{
    /**
     * Retention config for each table.
     * [table => months]
     */
    private const RETENTION_CONFIGS = [
        // Phase 4 transaction headers — 84 months (7 years, compliance)
        'money_transfers'         => 84,
        'employee_transactions'   => 84,
        'other_incomes'           => 84,
        'other_expenses'          => 84,
        'sales_returns'           => 84,
        'purchase_receives'       => 84,
        'purchase_returns'        => 84,
        'damage_invoices'         => 84,
        'manual_journals'         => 84,
        // Initial-setup tables — 84 months (7 years)
        'stock_transactions'      => 84,
        'sales_invoices'          => 84,
    ];

    public function up(): void
    {
        foreach (self::RETENTION_CONFIGS as $table => $months) {
            // Only update if the row exists AND retention is not already set.
            // This prevents overwriting any future manual config changes.
            $updated = DB::affectingStatement(<<<SQL
                UPDATE partman.part_config
                SET retention = '{$months} months',
                    retention_keep_table = true,
                    retention_schema = 'archive'
                WHERE parent_table = 'public.{$table}'
                  AND (retention IS NULL OR retention = '')
            SQL);

            if ($updated === 0) {
                // Check if the row exists at all — if not, the table may not
                // be registered with pg_partman yet (shouldn't happen for
                // Phase 1-4 tables, but log just in case).
                $exists = DB::selectOne(
                    "SELECT 1 FROM partman.part_config WHERE parent_table = ?",
                    ["public.{$table}"]
                );

                if (!$exists) {
                    Log::warning("Retention config: partman.part_config row not found for public.{$table} — skipping");
                }
            }
        }
    }

    public function down(): void
    {
        // Reverse: clear the retention config we added.
        // We only clear tables that have the exact values we set, to avoid
        // wiping any future custom configs.
        foreach (self::RETENTION_CONFIGS as $table => $months) {
            DB::statement(<<<SQL
                UPDATE partman.part_config
                SET retention = NULL,
                    retention_keep_table = false,
                    retention_schema = NULL
                WHERE parent_table = 'public.{$table}'
                  AND retention = '{$months} months'
                  AND retention_keep_table = true
                  AND retention_schema = 'archive'
            SQL);
        }
    }
};
