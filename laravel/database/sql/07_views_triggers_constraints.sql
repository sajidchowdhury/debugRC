-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 7: Views + Updated-at Triggers
-- ============================================================
-- Run AFTER all table-creation SQL files (01-06).

-- ===================== VIEWS =====================

-- v_journal_entries_with_lines: JOIN of journal_entries ⨝ journal_lines ⨝ ledgers.
-- Used by General Ledger report and journal entries listing.
CREATE OR REPLACE VIEW v_journal_entries_with_lines AS
SELECT
    je.id AS journal_entry_id,
    je.entry_no,
    je.entry_date,
    je.reference_type,
    je.reference_id,
    je.branch_id,
    je.description,
    je.is_reversed,
    je.created_at,
    jl.id AS journal_line_id,
    jl.ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    jl.debit,
    jl.credit,
    jl.entity_type,
    jl.entity_id,
    jl.memo
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
LEFT JOIN ledgers l ON l.id = jl.ledger_id;

-- ===================== UPDATED_AT TRIGGERS =====================
-- MySQL used ON UPDATE CURRENT_TIMESTAMP on many tables.
-- PG doesn't have that clause, so we use a trigger function.

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply the trigger to every table with an updated_at column.
-- Each table gets: CREATE TRIGGER trg_<table>_updated_at BEFORE UPDATE ON <table> FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DO $$
DECLARE
    t text;
    tables_with_updated_at text[] := ARRAY[
        'branches','employees','users','menus','user_menu_permissions',
        'product_categories','product_groups','products','product_price_history',
        'customers','suppliers','banks','bank_ledger_mappings','warehouses',
        'ledgers','journal_entries','document_sequences','accounting_periods',
        'manual_journals','branch_ledger','branch_cash','branch_expenses',
        'branch_product_cost','cash_ledger',
        'stock_adjustments','stock_take_sessions','warehouse_transfers',
        'damage_invoices','branch_demands',
        'sales_invoices','sales_challans','sales_draft_carts','sales_returns',
        'purchase_orders','purchase_receives','purchase_returns',
        'customer_payments','supplier_payments','money_transfers',
        'other_incomes','other_expenses','employee_transactions',
        'investigation_activators'
    ];
BEGIN
    FOREACH t IN ARRAY tables_with_updated_at LOOP
        BEGIN
            EXECUTE format(
                'CREATE TRIGGER trg_%s_updated_at BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()',
                t, t
            );
        EXCEPTION WHEN duplicate_object THEN
            NULL; -- trigger already exists, skip
        END;
    END LOOP;
END;
$$;

-- ===================== ADD MISSING FOREIGN KEYS =====================
-- The MySQL dump was missing FKs on legacy sales/customer/supplier tables.
-- Phase 2 is the moment to enforce them. These are ADDED here (after data migration)
-- so the ETL doesn't fail on existing orphan rows.
-- NOTE: Run these AFTER pgloader ETL + data cleanup. If orphans exist, clean them first.

-- sales_invoices FKs (were missing in MySQL)
ALTER TABLE sales_invoices
    ADD CONSTRAINT fk_si_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    ADD CONSTRAINT fk_si_branch FOREIGN KEY (branch_id) REFERENCES branches(id);

-- customer_ledger FKs (were missing in MySQL)
ALTER TABLE customer_ledger
    ADD CONSTRAINT fk_cl_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE;

-- supplier_ledger FKs (were missing in MySQL)
ALTER TABLE supplier_ledger
    ADD CONSTRAINT fk_sl_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE;

-- employee_ledger FKs (were missing in MySQL)
ALTER TABLE employee_ledger
    ADD CONSTRAINT fk_el_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE;

-- money_transfers FKs (were missing in MySQL)
ALTER TABLE money_transfers
    ADD CONSTRAINT fk_mt_from_bank FOREIGN KEY (from_bank_id) REFERENCES banks(id),
    ADD CONSTRAINT fk_mt_to_bank FOREIGN KEY (to_bank_id) REFERENCES banks(id);

-- ledgers self-referential FK (was using parent_id=0 sentinel, no FK)
-- NOTE: only add if no parent_id=0 rows exist (0 is not a valid id).
-- This is commented out because the legacy data uses 0 as "no parent".
-- In Phase 3 (Laravel), we will migrate parent_id=0 → parent_id=NULL and add the FK.
-- ALTER TABLE ledgers ADD CONSTRAINT fk_ledger_parent FOREIGN KEY (parent_id) REFERENCES ledgers(id);

-- ===================== SEED: DOCUMENT SEQUENCE TYPES =====================
-- Ensure document_sequences has rows for all needed types per branch.
-- This is a data seed, not schema. Run after ETL.

-- ===================== PERFORMANCE INDEXES FOR REPORTS =====================
-- Additional indexes for common report queries (not in MySQL original).
CREATE INDEX IF NOT EXISTS idx_cl_customer_branch ON customer_ledger(customer_id, branch_id);
CREATE INDEX IF NOT EXISTS idx_sl_supplier_branch ON supplier_ledger(supplier_id, branch_id);
CREATE INDEX IF NOT EXISTS idx_je_date_branch ON journal_entries(entry_date, branch_id);
CREATE INDEX IF NOT EXISTS idx_jl_ledger_entry ON journal_lines(ledger_id, journal_entry_id);

-- ===================== PARTIAL INDEXES FOR BUSINESS QUERIES =====================
-- PostgreSQL partial indexes (WHERE clause) index only the rows matching the
-- predicate, producing much smaller indexes and faster scans for the common
-- "active subset" queries the ERP runs on every page load.
-- Mirrors migration 2025_01_20_000001_add_partial_indexes_business_queries.php.

-- 1. OPEN INVOICES — confirmed sales with outstanding balance (AR aging, collections)
CREATE INDEX IF NOT EXISTS idx_si_open_invoice
    ON sales_invoices (customer_id, due_amount, invoice_date)
    WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0;

CREATE INDEX IF NOT EXISTS idx_si_open_by_branch
    ON sales_invoices (branch_id, invoice_date)
    WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0;

-- 2. UNPAID / ACTIVE PAYMENTS — non-reversed payments (AR/AP dashboards)
CREATE INDEX IF NOT EXISTS idx_cp_active
    ON customer_payments (customer_id, payment_date)
    WHERE is_reversed = false;

CREATE INDEX IF NOT EXISTS idx_sp_active
    ON supplier_payments (supplier_id, payment_date)
    WHERE is_reversed = false;

CREATE INDEX IF NOT EXISTS idx_cp_active_by_branch
    ON customer_payments (branch_id, payment_date)
    WHERE is_reversed = false;

CREATE INDEX IF NOT EXISTS idx_sp_active_by_branch
    ON supplier_payments (branch_id, payment_date)
    WHERE is_reversed = false;

-- 3. PENDING RETURNS — awaiting confirmation / processing
CREATE INDEX IF NOT EXISTS idx_sr_pending
    ON sales_returns (branch_id, return_date)
    WHERE status = 'created' AND is_reversed = false;

CREATE INDEX IF NOT EXISTS idx_prtn_pending
    ON purchase_returns (supplier_id, branch_id)
    WHERE is_reversed = false;

-- 4. ACTIVE LEDGER — open sub-ledger rows & live GL entries
CREATE INDEX IF NOT EXISTS idx_cl_outstanding
    ON customer_ledger (customer_id, transaction_date, balance)
    WHERE balance > 0;

CREATE INDEX IF NOT EXISTS idx_sl_outstanding
    ON supplier_ledger (supplier_id, transaction_date, balance)
    WHERE balance > 0;

CREATE INDEX IF NOT EXISTS idx_bl_unsettled
    ON branch_ledger (from_branch_id, to_branch_id, transaction_date)
    WHERE is_settled = false;

CREATE INDEX IF NOT EXISTS idx_je_active
    ON journal_entries (entry_date, branch_id, reference_type)
    WHERE is_reversed = false;

CREATE INDEX IF NOT EXISTS idx_ledgers_active_by_type
    ON ledgers (account_type, ledger_code)
    WHERE is_active = true;

-- ===================== COVERING INDEXES (INCLUDE) FOR HIGH-FREQ QUERIES =====================
-- PostgreSQL covering indexes store INCLUDE columns in leaf pages only,
-- enabling index-only scans — PG never visits the heap for these queries.
-- Mirrors migration 2025_01_20_000002_add_covering_indexes_high_freq_queries.php.

-- P0: Customer ledger balance (every invoice finalize + credit check)
-- Query: SELECT SUM(debit) - SUM(credit) FROM customer_ledger WHERE customer_id = ? AND is_reversed = false
CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
    ON customer_ledger (customer_id, is_reversed)
    INCLUDE (debit, credit);

-- P0: Outstanding invoices per customer (payment allocation AJAX)
-- Query: SELECT id, invoice_code, invoice_date, total_amount, paid_amount, due_amount
--        FROM sales_invoices WHERE customer_id = ? AND is_reversed = false AND due_amount > 0.01
CREATE INDEX IF NOT EXISTS idx_si_customer_due_covering
    ON sales_invoices (customer_id, is_reversed)
    INCLUDE (id, invoice_code, invoice_date, total_amount, paid_amount, due_amount)
    WHERE due_amount > 0;

-- P1: Journal entries by reference (every reversal, cancel, show page)
CREATE INDEX IF NOT EXISTS idx_je_reference_covering
    ON journal_entries (reference_type, reference_id, is_reversed)
    INCLUDE (id, entry_no, entry_date, branch_id, description, source, created_by);

-- P1: Journal lines per-entry detail (every journal show page)
CREATE INDEX IF NOT EXISTS idx_jl_entry_covering
    ON journal_lines (journal_entry_id)
    INCLUDE (id, ledger_id, debit, credit, entity_type, entity_id, memo);

-- P1: Journal lines per-ledger reporting (GL report, trial balance)
CREATE INDEX IF NOT EXISTS idx_jl_ledger_date_covering
    ON journal_lines (ledger_id, journal_entry_id)
    INCLUDE (debit, credit);

-- P2: Sales invoices listing (DataTable with branch + status + date filters)
CREATE INDEX IF NOT EXISTS idx_si_listing_covering
    ON sales_invoices (branch_id, status, invoice_date DESC, id DESC)
    INCLUDE (customer_id, invoice_code, total_amount, paid_amount, due_amount,
             is_godown_prepared, is_challan_issued, is_reversed);

-- P2: Customer payments listing
CREATE INDEX IF NOT EXISTS idx_cp_listing_covering
    ON customer_payments (branch_id, payment_date DESC, id DESC)
    INCLUDE (customer_id, payment_code, payment_mode, amount, is_reversed);

-- P2: Supplier payments listing
CREATE INDEX IF NOT EXISTS idx_sp_listing_covering
    ON supplier_payments (branch_id, payment_date DESC, id DESC)
    INCLUDE (supplier_id, payment_code, payment_mode, amount, is_reversed);

-- P2: Invoice payment allocations (paid-so-far per invoice)
CREATE INDEX IF NOT EXISTS idx_ipa_invoice_covering
    ON invoice_payment_allocations (invoice_id)
    INCLUDE (payment_id, allocated_amount);

-- P2: Warehouse stock reverse lookup (product → warehouses)
CREATE INDEX IF NOT EXISTS idx_ws_product_covering
    ON warehouse_stock (product_id, warehouse_id)
    INCLUDE (qty, avg_cost);

-- P2: Sales challans listing
CREATE INDEX IF NOT EXISTS idx_sc_listing_covering
    ON sales_challans (branch_id, challan_date DESC, id DESC)
    INCLUDE (sales_invoice_id, challan_code, is_reversed, issue_cost, transport_cost);

-- P3: Purchase receives listing
CREATE INDEX IF NOT EXISTS idx_pr_listing_covering
    ON purchase_receives (branch_id, receive_date DESC, id DESC)
    INCLUDE (supplier_id, receive_code, total_amount, is_reversed, purchase_order_id);

-- P3: Supplier ledger by reference
CREATE INDEX IF NOT EXISTS idx_sl_reference_covering
    ON supplier_ledger (reference_type, reference_id)
    INCLUDE (id, supplier_id, branch_id, transaction_date, transaction_type,
             debit, credit, balance, journal_entry_id, created_by);

-- P3: Stock transactions by reference
CREATE INDEX IF NOT EXISTS idx_st_reference_covering
    ON stock_transactions (reference_type, reference_id)
    INCLUDE (id, warehouse_id, product_id, qty, rate, transaction_date, created_by);

-- P3: Customer ledger by reference
CREATE INDEX IF NOT EXISTS idx_cl_reference_covering
    ON customer_ledger (reference_type, reference_id)
    INCLUDE (id, customer_id, branch_id, transaction_date, transaction_type,
             debit, credit, balance, journal_entry_id, created_by);

-- P3: Purchase orders listing
CREATE INDEX IF NOT EXISTS idx_po_listing_covering
    ON purchase_orders (branch_id, po_date DESC, id DESC)
    INCLUDE (supplier_id, po_code, total_amount, status);

-- ===================== BRIN INDEXES FOR TIME-SERIES / APPEND-MOSTLY TABLES =====================
-- PostgreSQL BRIN (Block Range Index) indexes store only min/max summaries per block range,
-- making them tiny (~0.1% of table size vs ~10% for B-tree) and ideal for chronologically-
-- ordered columns. They complement B-tree indexes — B-tree handles equality/point lookups,
-- BRIN handles date-range scans efficiently at near-zero cost.
-- Mirrors migration 2025_01_20_000003_add_brin_indexes_time_series_tables.php.

-- 1. CORE TRANSACTION TABLES — date-range reports & dashboards
CREATE INDEX IF NOT EXISTS idx_si_created_at_brin
    ON sales_invoices USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_si_invoice_date_brin
    ON sales_invoices USING BRIN (invoice_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_cp_payment_date_brin
    ON customer_payments USING BRIN (payment_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_cp_created_at_brin
    ON customer_payments USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sp_payment_date_brin
    ON supplier_payments USING BRIN (payment_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sp_created_at_brin
    ON supplier_payments USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sr_return_date_brin
    ON sales_returns USING BRIN (return_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_pr_receive_date_brin
    ON purchase_receives USING BRIN (receive_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_prtn_return_date_brin
    ON purchase_returns USING BRIN (return_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_po_po_date_brin
    ON purchase_orders USING BRIN (po_date)
    WITH (pages_per_range = 32);

-- 2. SUB-LEDGERS — AR/AP aging, running balance queries
CREATE INDEX IF NOT EXISTS idx_cl_transaction_date_brin
    ON customer_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_cl_created_at_brin
    ON customer_ledger USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sl_transaction_date_brin
    ON supplier_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sl_created_at_brin
    ON supplier_ledger USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_el_transaction_date_brin
    ON employee_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_bl_transaction_date_brin
    ON branch_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_cashl_transaction_date_brin
    ON cash_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_be_expense_date_brin
    ON branch_expenses USING BRIN (expense_date)
    WITH (pages_per_range = 32);

-- 3. INVENTORY LEDGER — stock_transactions (pure append, largest table)
CREATE INDEX IF NOT EXISTS idx_st_transaction_date_brin
    ON stock_transactions USING BRIN (transaction_date)
    WITH (pages_per_range = 64);

CREATE INDEX IF NOT EXISTS idx_st_created_at_brin
    ON stock_transactions USING BRIN (created_at)
    WITH (pages_per_range = 64);

-- 4. AUDIT & LOG TABLES — pure append-only, never updated
CREATE INDEX IF NOT EXISTS idx_ual_created_at_brin
    ON user_audit_log USING BRIN (created_at)
    WITH (pages_per_range = 64);

CREATE INDEX IF NOT EXISTS idx_notif_created_at_brin
    ON notifications USING BRIN (created_at)
    WITH (pages_per_range = 64);

CREATE INDEX IF NOT EXISTS idx_jpl_performed_at_brin
    ON journal_posting_logs USING BRIN (performed_at)
    WITH (pages_per_range = 64);

-- 5. DAILY SUMMARIES — snapshot tables with date dimension
CREATE INDEX IF NOT EXISTS idx_dwss_summary_date_brin
    ON daily_warehouse_stock_summary USING BRIN (summary_date)
    WITH (pages_per_range = 32);

-- 6. OTHER TRANSACTION TABLES — income/expense/employee/transfers
CREATE INDEX IF NOT EXISTS idx_oi_income_date_brin
    ON other_incomes USING BRIN (income_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_oe_expense_date_brin
    ON other_expenses USING BRIN (expense_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_et_transaction_date_brin
    ON employee_transactions USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_mt_transfer_date_brin
    ON money_transfers USING BRIN (transfer_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_sc_challan_date_brin
    ON sales_challans USING BRIN (challan_date)
    WITH (pages_per_range = 32);

CREATE INDEX IF NOT EXISTS idx_mj_journal_date_brin
    ON manual_journals USING BRIN (journal_date)
    WITH (pages_per_range = 32);

-- ===================== GIN INDEX FOR JSONB CART ITEMS =====================
-- GIN (Generalized Inverted Index) on sales_draft_carts.items_json enables
-- @> containment queries for cart item lookups (e.g., "which carts contain
-- product X?"). jsonb_path_ops produces a smaller, faster index than default GIN
-- at the cost of only supporting @> (not ? existence operators).
-- Mirrors migration 2025_01_20_000004_add_gin_index_draft_carts_items_json.php.

CREATE INDEX IF NOT EXISTS idx_sdc_items_gin
    ON sales_draft_carts USING GIN (items_json jsonb_path_ops);
