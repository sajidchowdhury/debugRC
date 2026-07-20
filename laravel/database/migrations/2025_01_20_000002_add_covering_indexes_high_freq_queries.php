<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21: Add covering indexes (INCLUDE) for high-frequency queries.
 *
 * PostgreSQL covering indexes use the INCLUDE clause to store additional
 * columns in the leaf pages of the B-tree. This enables **index-only scans**
 * — PostgreSQL never needs to visit the heap (actual table pages), which
 * can be 5-10x faster on large tables for frequently queried columns.
 *
 * Design principles:
 *   - Key columns (WHERE/ORDER BY) go in the index key.
 *   - SELECT-only columns go in INCLUDE (stored in leaf, not used for sorting).
 *   - Partial indexes (WHERE clause) combined with INCLUDE where the predicate
 *     already filters most rows — these are the most powerful combo.
 *
 * Priority order mirrors query frequency:
 *   P0: customer_ledger balance (every invoice finalize + credit check)
 *   P0: sales_invoices outstanding (every payment creation)
 *   P1: journal_entries by reference (every reversal/cancel)
 *   P1: journal_lines for GL reports (every trial balance / ledger report)
 *   P2: listing pages (sales, payments, challans, GRN)
 *   P3: stats aggregates (SUM/COUNT by status)
 *
 * All indexes use CREATE INDEX IF NOT EXISTS for idempotency.
 * A final ANALYZE refreshes planner statistics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // P0: CUSTOMER LEDGER BALANCE — called on EVERY invoice finalize
        // ──────────────────────────────────────────────
        // Query: SELECT COALESCE(SUM(debit) - SUM(credit), 0)
        //        FROM customer_ledger WHERE customer_id = ? AND is_reversed = false
        // Without INCLUDE, PG must visit heap for debit/credit → full-page random I/O.
        // With INCLUDE (debit, credit), PG does an index-only scan → sequential leaf read.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
             ON customer_ledger (customer_id, is_reversed)
             INCLUDE (debit, credit)"
        );

        // ──────────────────────────────────────────────
        // P0: SALES INVOICES — outstanding invoices per customer (payment allocation)
        // ──────────────────────────────────────────────
        // Query: SELECT id, invoice_code, invoice_date, total_amount, paid_amount, due_amount
        //        FROM sales_invoices WHERE customer_id = ? AND is_reversed = false AND due_amount > 0.01
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_customer_due_covering
             ON sales_invoices (customer_id, is_reversed)
             INCLUDE (id, invoice_code, invoice_date, total_amount, paid_amount, due_amount)
             WHERE due_amount > 0"
        );

        // ──────────────────────────────────────────────
        // P1: JOURNAL ENTRIES — by reference (every reversal, cancel, show page)
        // ──────────────────────────────────────────────
        // Query: SELECT * FROM journal_entries
        //        WHERE reference_type = ? AND reference_id = ? AND is_reversed = false
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_je_reference_covering
             ON journal_entries (reference_type, reference_id, is_reversed)
             INCLUDE (id, entry_no, entry_date, branch_id, description, source, created_by)"
        );

        // ──────────────────────────────────────────────
        // P1: JOURNAL LINES — per-entry detail (every journal show page)
        // ──────────────────────────────────────────────
        // Query: SELECT * FROM journal_lines WHERE journal_entry_id = ? ORDER BY id
        // The existing idx_jl_journal_entry is not covering — this one INCLUDEs all
        // columns needed for the join with ledgers on the show page.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_jl_entry_covering
             ON journal_lines (journal_entry_id)
             INCLUDE (id, ledger_id, debit, credit, entity_type, entity_id, memo)"
        );

        // ──────────────────────────────────────────────
        // P1: JOURNAL LINES — per-ledger reporting (GL report, trial balance)
        // ──────────────────────────────────────────────
        // Query: JOIN journal_lines jl ON jl.ledger_id = ?
        //        WHERE je.is_reversed = false
        // Keyed on (ledger_id, journal_entry_id) with INCLUDE for the aggregates.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_jl_ledger_date_covering
             ON journal_lines (ledger_id, journal_entry_id)
             INCLUDE (debit, credit)"
        );

        // ──────────────────────────────────────────────
        // P2: SALES INVOICES — main listing page (DataTable with filters)
        // ──────────────────────────────────────────────
        // Query: SELECT ... FROM sales_invoices
        //        WHERE branch_id = ? AND status = ? AND invoice_date BETWEEN ?
        //        ORDER BY invoice_date DESC, id DESC LIMIT 25
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_listing_covering
             ON sales_invoices (branch_id, status, invoice_date DESC, id DESC)
             INCLUDE (customer_id, invoice_code, total_amount, paid_amount, due_amount,
                      is_godown_prepared, is_challan_issued, is_reversed)"
        );

        // ──────────────────────────────────────────────
        // P2: CUSTOMER PAYMENTS — listing page
        // ──────────────────────────────────────────────
        // Query: SELECT ... FROM customer_payments
        //        WHERE branch_id = ? AND payment_date BETWEEN ?
        //        ORDER BY payment_date DESC, id DESC LIMIT 25
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cp_listing_covering
             ON customer_payments (branch_id, payment_date DESC, id DESC)
             INCLUDE (customer_id, payment_code, payment_mode, amount, is_reversed)"
        );

        // ──────────────────────────────────────────────
        // P2: SUPPLIER PAYMENTS — listing page
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sp_listing_covering
             ON supplier_payments (branch_id, payment_date DESC, id DESC)
             INCLUDE (supplier_id, payment_code, payment_mode, amount, is_reversed)"
        );

        // ──────────────────────────────────────────────
        // P2: INVOICE PAYMENT ALLOCATIONS — paid-so-far per invoice
        // ──────────────────────────────────────────────
        // Query: SELECT SUM(allocated_amount) FROM invoice_payment_allocations ipa
        //        JOIN customer_payments cp ON cp.id = ipa.payment_id
        //        WHERE ipa.invoice_id = ? AND cp.is_reversed = false
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_ipa_invoice_covering
             ON invoice_payment_allocations (invoice_id)
             INCLUDE (payment_id, allocated_amount)"
        );

        // ──────────────────────────────────────────────
        // P2: WAREHOUSE STOCK — product→warehouse reverse lookup
        // ──────────────────────────────────────────────
        // Query: SELECT SUM(qty) FROM warehouse_stock ws
        //        JOIN warehouses w ON w.id = ws.warehouse_id AND w.branch_id = ?
        //        WHERE ws.product_id = ?
        // Composite PK is (warehouse_id, product_id), but branch queries need
        // the reverse path (product_id first) with qty + avg_cost for index-only scan.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_ws_product_covering
             ON warehouse_stock (product_id, warehouse_id)
             INCLUDE (qty, avg_cost)"
        );

        // ──────────────────────────────────────────────
        // P2: SALES CHALLANS — listing page
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sc_listing_covering
             ON sales_challans (branch_id, challan_date DESC, id DESC)
             INCLUDE (sales_invoice_id, challan_code, is_reversed, issue_cost, transport_cost)"
        );

        // ──────────────────────────────────────────────
        // P3: PURCHASE RECEIVES — listing page
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_pr_listing_covering
             ON purchase_receives (branch_id, receive_date DESC, id DESC)
             INCLUDE (supplier_id, receive_code, total_amount, is_reversed, purchase_order_id)"
        );

        // ──────────────────────────────────────────────
        // P3: SUPPLIER LEDGER — by reference (per purchase receive show/cancel)
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_sl_reference_covering
             ON supplier_ledger (reference_type, reference_id)
             INCLUDE (id, supplier_id, branch_id, transaction_date, transaction_type,
                      debit, credit, balance, journal_entry_id, created_by)"
        );

        // ──────────────────────────────────────────────
        // P3: STOCK TRANSACTIONS — by reference (per challan show / purchase cancel)
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_st_reference_covering
             ON stock_transactions (reference_type, reference_id)
             INCLUDE (id, warehouse_id, product_id, qty, rate, transaction_date, created_by)"
        );

        // ──────────────────────────────────────────────
        // P3: CUSTOMER LEDGER — by reference (per payment/invoice show page)
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_cl_reference_covering
             ON customer_ledger (reference_type, reference_id)
             INCLUDE (id, customer_id, branch_id, transaction_date, transaction_type,
                      debit, credit, balance, journal_entry_id, created_by)"
        );

        // ──────────────────────────────────────────────
        // P3: PURCHASE ORDERS — listing page
        // ──────────────────────────────────────────────
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_po_listing_covering
             ON purchase_orders (branch_id, po_date DESC, id DESC)
             INCLUDE (supplier_id, po_code, total_amount, status)"
        );

        // Refresh planner statistics for the newly indexed columns
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        $indexes = [
            // P0
            'idx_cl_balance_covering',
            'idx_si_customer_due_covering',
            // P1
            'idx_je_reference_covering',
            'idx_jl_entry_covering',
            'idx_jl_ledger_date_covering',
            // P2
            'idx_si_listing_covering',
            'idx_cp_listing_covering',
            'idx_sp_listing_covering',
            'idx_ipa_invoice_covering',
            'idx_ws_product_covering',
            'idx_sc_listing_covering',
            // P3
            'idx_pr_listing_covering',
            'idx_sl_reference_covering',
            'idx_st_reference_covering',
            'idx_cl_reference_covering',
            'idx_po_listing_covering',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
