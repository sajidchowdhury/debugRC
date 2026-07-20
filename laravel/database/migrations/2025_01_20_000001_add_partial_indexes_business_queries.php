<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20: Add partial indexes for high-frequency business queries.
 *
 * Partial indexes (PostgreSQL WHERE-clause indexes) only index rows matching
 * the predicate, producing much smaller indexes and faster scans for the
 * common "active subset" queries the ERP runs on every page load.
 *
 * Categories:
 *   1. Open Invoices  — sales_invoices with outstanding balance
 *   2. Unpaid/Active  — payments not reversed (AR/AP dashboards)
 *   3. Pending Returns — sales_returns awaiting confirmation
 *   4. Active Ledger  — sub-ledgers with open balance, unsettled intercompany,
 *                       non-reversed journal entries, active GL ledgers
 *
 * All indexes use CREATE INDEX IF NOT EXISTS for idempotency.
 * A final ANALYZE refreshes planner statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // 1. OPEN INVOICES — confirmed sales with due balance
        // ──────────────────────────────────────────────
        // The collections dashboard and AR aging report both filter
        // for confirmed, non-reversed invoices with an outstanding balance.
        // This partial index covers the hottest query in the system.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_open_invoice
             ON sales_invoices (customer_id, due_amount, invoice_date)
             WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0"
        );

        // Branch-scoped open invoices (branch dashboard, call-it-a-day list)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_open_by_branch
             ON sales_invoices (branch_id, invoice_date)
             WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0"
        );

        // ──────────────────────────────────────────────
        // 2. UNPAID / ACTIVE PAYMENTS — non-reversed payment records
        // ──────────────────────────────────────────────
        // AR/AP dashboards list only non-reversed payments. Full-table
        // indexes on is_reversed would be wasteful; a partial index
        // indexes only the ~95% of rows that are live.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cp_active
             ON customer_payments (customer_id, payment_date)
             WHERE is_reversed = false"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sp_active
             ON supplier_payments (supplier_id, payment_date)
             WHERE is_reversed = false"
        );

        // ──────────────────────────────────────────────
        // 3. PENDING RETURNS — awaiting confirmation / processing
        // ──────────────────────────────────────────────
        // Sales returns in 'created' status need manager review.
        // Purchase returns that haven't been reversed are also
        // actively tracked in the returns dashboard.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sr_pending
             ON sales_returns (branch_id, return_date)
             WHERE status = 'created' AND is_reversed = false"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_prtn_pending
             ON purchase_returns (supplier_id, branch_id)
             WHERE is_reversed = false"
        );

        // ──────────────────────────────────────────────
        // 4. ACTIVE LEDGER — open sub-ledger rows & live GL entries
        // ──────────────────────────────────────────────
        // AR aging: customer_ledger rows with outstanding debit balance
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cl_outstanding
             ON customer_ledger (customer_id, transaction_date, balance)
             WHERE balance > 0"
        );

        // AP aging: supplier_ledger rows with outstanding credit balance
        // (in supplier terms, a positive balance means we owe them)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sl_outstanding
             ON supplier_ledger (supplier_id, transaction_date, balance)
             WHERE balance > 0"
        );

        // Unsettled intercompany: branch_ledger rows not yet settled
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_bl_unsettled
             ON branch_ledger (from_branch_id, to_branch_id, transaction_date)
             WHERE is_settled = false"
        );

        // Non-reversed journal entries (GL reports, trial balance)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_je_active
             ON journal_entries (entry_date, branch_id, reference_type)
             WHERE is_reversed = false"
        );

        // Active GL ledgers (chart of accounts filter)
        // NOTE: idx_ledgers_active already exists from migration 2025_01_14
        // but that indexes (is_active) WHERE is_active = true — this one
        // covers the account_type + is_active composite for the chart-of-
        // accounts page which filters by account_type.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_ledgers_active_by_type
             ON ledgers (account_type, ledger_code)
             WHERE is_active = true"
        );

        // Non-reversed customer payments by branch (daily collection report)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cp_active_by_branch
             ON customer_payments (branch_id, payment_date)
             WHERE is_reversed = false"
        );

        // Non-reversed supplier payments by branch (daily payment report)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sp_active_by_branch
             ON supplier_payments (branch_id, payment_date)
             WHERE is_reversed = false"
        );

        // Refresh planner statistics for the newly indexed columns
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        $indexes = [
            // Open invoices
            'idx_si_open_invoice',
            'idx_si_open_by_branch',
            // Unpaid / active payments
            'idx_cp_active',
            'idx_sp_active',
            // Pending returns
            'idx_sr_pending',
            'idx_prtn_pending',
            // Active ledger
            'idx_cl_outstanding',
            'idx_sl_outstanding',
            'idx_bl_unsettled',
            'idx_je_active',
            'idx_ledgers_active_by_type',
            'idx_cp_active_by_branch',
            'idx_sp_active_by_branch',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
