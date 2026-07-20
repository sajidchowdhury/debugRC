<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22: Add BRIN indexes for time-series / append-mostly tables.
 *
 * BRIN (Block Range Index) indexes are ideal for tables where rows are
 * inserted roughly in chronological order. Instead of indexing every row
 * (like B-tree), BRIN stores a compact summary per block range — min/max
 * values for each range of pages. This makes them:
 *
 *   - Tiny: ~0.1% of table size vs ~10% for B-tree
 *   - Fast to maintain: O(1) per INSERT (just update the block summary)
 *   - Effective for range queries: PostgreSQL skips entire block ranges
 *     whose min/max don't overlap the query predicate
 *
 * Design principles:
 *   1. Only apply to columns with natural correlation (created_at, *_date)
 *      where new rows are appended in increasing order.
 *   2. Use pages_per_range tuned to the table's expected growth:
 *      - 32 for medium tables (default, ~256 KB per range)
 *      - 64 for very large append-only tables (audit logs, stock transactions)
 *   3. BRIN complements B-tree indexes — B-tree handles equality/point
 *      lookups; BRIN handles date-range scans efficiently at near-zero cost.
 *   4. Do NOT add BRIN where a B-tree already covers the same column
 *      pattern AND equality lookups dominate (BRIN is poor for point queries).
 *
 * Categories:
 *   1. Core transaction tables  — sales, payments, returns, purchases
 *   2. Sub-ledgers              — customer, supplier, employee, branch, cash
 *   3. Inventory ledger         — stock_transactions (pure append)
 *   4. Audit & log tables       — user_audit_log, notifications (pure append)
 *   5. Daily summaries          — daily_warehouse_stock_summary
 *
 * All indexes use CREATE INDEX IF NOT EXISTS for idempotency.
 * A final ANALYZE refreshes planner statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // 1. CORE TRANSACTION TABLES — date-range reports & dashboards
        // ──────────────────────────────────────────────
        // Every listing page filters by date range (today, this week, this month).
        // B-tree indexes on these columns exist for point lookups (e.g. idx_si_invoice_date),
        // but BRIN is vastly cheaper for range scans and complements them perfectly.

        // sales_invoices: AR aging, collections dashboard, monthly revenue
        // Rows inserted chronologically (created_at correlates with invoice_date).
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_created_at_brin
             ON sales_invoices USING BRIN (created_at)
             WITH (pages_per_range = 32)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_invoice_date_brin
             ON sales_invoices USING BRIN (invoice_date)
             WITH (pages_per_range = 32)"
        );

        // customer_payments: daily collection report, payment history
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cp_payment_date_brin
             ON customer_payments USING BRIN (payment_date)
             WITH (pages_per_range = 32)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cp_created_at_brin
             ON customer_payments USING BRIN (created_at)
             WITH (pages_per_range = 32)"
        );

        // supplier_payments: AP aging, payment history
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sp_payment_date_brin
             ON supplier_payments USING BRIN (payment_date)
             WITH (pages_per_range = 32)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sp_created_at_brin
             ON supplier_payments USING BRIN (created_at)
             WITH (pages_per_range = 32)"
        );

        // sales_returns: returns report by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sr_return_date_brin
             ON sales_returns USING BRIN (return_date)
             WITH (pages_per_range = 32)"
        );

        // purchase_receives: GRN listing by date, monthly purchase summary
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_pr_receive_date_brin
             ON purchase_receives USING BRIN (receive_date)
             WITH (pages_per_range = 32)"
        );

        // purchase_returns: return date range queries
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_prtn_return_date_brin
             ON purchase_returns USING BRIN (return_date)
             WITH (pages_per_range = 32)"
        );

        // purchase_orders: PO listing by date
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_po_po_date_brin
             ON purchase_orders USING BRIN (po_date)
             WITH (pages_per_range = 32)"
        );

        // ──────────────────────────────────────────────
        // 2. SUB-LEDGERS — AR/AP aging, running balance queries
        // ──────────────────────────────────────────────
        // Sub-ledger rows are appended chronologically per entity.
        // transaction_date is the business date; created_at is the system timestamp.
        // BRIN on both allows the planner to skip old block ranges for:
        //   - AR aging (customer_ledger WHERE transaction_date >= ?)
        //   - AP aging (supplier_ledger WHERE transaction_date >= ?)
        //   - Employee statement (employee_ledger WHERE transaction_date BETWEEN ? AND ?)

        // customer_ledger: AR aging, customer 360 ledger tab
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cl_transaction_date_brin
             ON customer_ledger USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cl_created_at_brin
             ON customer_ledger USING BRIN (created_at)
             WITH (pages_per_range = 32)"
        );

        // supplier_ledger: AP aging, supplier payment history
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sl_transaction_date_brin
             ON supplier_ledger USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sl_created_at_brin
             ON supplier_ledger USING BRIN (created_at)
             WITH (pages_per_range = 32)"
        );

        // employee_ledger: employee advance/loan/salary statement
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_el_transaction_date_brin
             ON employee_ledger USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        // branch_ledger: intercompany settlement by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_bl_transaction_date_brin
             ON branch_ledger USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        // cash_ledger: daily cash position, branch cash history
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cashl_transaction_date_brin
             ON cash_ledger USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        // branch_expenses: expense report by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_be_expense_date_brin
             ON branch_expenses USING BRIN (expense_date)
             WITH (pages_per_range = 32)"
        );

        // ──────────────────────────────────────────────
        // 3. INVENTORY LEDGER — stock_transactions (pure append)
        // ──────────────────────────────────────────────
        // stock_transactions is the largest append-only table in the ERP.
        // Every stock movement (sales, purchases, adjustments, transfers)
        // appends a row. B-tree idx_st_date_warehouse handles equality
        // lookups; BRIN handles "last 30 days of stock movements" queries
        // in the product movement report at near-zero index overhead.
        // Using pages_per_range = 64 because this table grows fastest.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_st_transaction_date_brin
             ON stock_transactions USING BRIN (transaction_date)
             WITH (pages_per_range = 64)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_st_created_at_brin
             ON stock_transactions USING BRIN (created_at)
             WITH (pages_per_range = 64)"
        );

        // ──────────────────────────────────────────────
        // 4. AUDIT & LOG TABLES — pure append-only, never updated
        // ──────────────────────────────────────────────
        // Audit logs and notifications are write-once tables with perfect
        // chronological correlation — the ideal BRIN use case.
        // pages_per_range = 64 because these tables accumulate indefinitely.

        // user_audit_log: security audit trail queries by date range
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_ual_created_at_brin
             ON user_audit_log USING BRIN (created_at)
             WITH (pages_per_range = 64)"
        );

        // notifications: "show recent notifications" (last 7 days)
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_notif_created_at_brin
             ON notifications USING BRIN (created_at)
             WITH (pages_per_range = 64)"
        );

        // journal_posting_logs: audit trail for GL posting actions
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_jpl_performed_at_brin
             ON journal_posting_logs USING BRIN (performed_at)
             WITH (pages_per_range = 64)"
        );

        // ──────────────────────────────────────────────
        // 5. DAILY SUMMARIES — snapshot tables with date dimension
        // ──────────────────────────────────────────────
        // daily_warehouse_stock_summary: one row per warehouse×product×day.
        // Queries always filter by summary_date range (e.g. "last 30 days").
        // BRIN is perfect because rows are strictly ordered by summary_date.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_dwss_summary_date_brin
             ON daily_warehouse_stock_summary USING BRIN (summary_date)
             WITH (pages_per_range = 32)"
        );

        // ──────────────────────────────────────────────
        // 6. OTHER TRANSACTION TABLES — income/expense/employee/transfers
        // ──────────────────────────────────────────────
        // These have their own date columns and are appended chronologically.

        // other_incomes: income report by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_oi_income_date_brin
             ON other_incomes USING BRIN (income_date)
             WITH (pages_per_range = 32)"
        );

        // other_expenses: expense report by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_oe_expense_date_brin
             ON other_expenses USING BRIN (expense_date)
             WITH (pages_per_range = 32)"
        );

        // employee_transactions: employee statement by period
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_et_transaction_date_brin
             ON employee_transactions USING BRIN (transaction_date)
             WITH (pages_per_range = 32)"
        );

        // money_transfers: transfer history by date
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_mt_transfer_date_brin
             ON money_transfers USING BRIN (transfer_date)
             WITH (pages_per_range = 32)"
        );

        // sales_challans: challan listing by date
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sc_challan_date_brin
             ON sales_challans USING BRIN (challan_date)
             WITH (pages_per_range = 32)"
        );

        // manual_journals: manual journal listing by date
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_mj_journal_date_brin
             ON manual_journals USING BRIN (journal_date)
             WITH (pages_per_range = 32)"
        );

        // Refresh planner statistics for the newly indexed columns
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        $indexes = [
            // 1. Core transaction tables
            'idx_si_created_at_brin',
            'idx_si_invoice_date_brin',
            'idx_cp_payment_date_brin',
            'idx_cp_created_at_brin',
            'idx_sp_payment_date_brin',
            'idx_sp_created_at_brin',
            'idx_sr_return_date_brin',
            'idx_pr_receive_date_brin',
            'idx_prtn_return_date_brin',
            'idx_po_po_date_brin',
            // 2. Sub-ledgers
            'idx_cl_transaction_date_brin',
            'idx_cl_created_at_brin',
            'idx_sl_transaction_date_brin',
            'idx_sl_created_at_brin',
            'idx_el_transaction_date_brin',
            'idx_bl_transaction_date_brin',
            'idx_cashl_transaction_date_brin',
            'idx_be_expense_date_brin',
            // 3. Inventory ledger
            'idx_st_transaction_date_brin',
            'idx_st_created_at_brin',
            // 4. Audit & log tables
            'idx_ual_created_at_brin',
            'idx_notif_created_at_brin',
            'idx_jpl_performed_at_brin',
            // 5. Daily summaries
            'idx_dwss_summary_date_brin',
            // 6. Other transaction tables
            'idx_oi_income_date_brin',
            'idx_oe_expense_date_brin',
            'idx_et_transaction_date_brin',
            'idx_mt_transfer_date_brin',
            'idx_sc_challan_date_brin',
            'idx_mj_journal_date_brin',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
