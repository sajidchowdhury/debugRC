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
-- Each table gets: CREATE TRIGGER trg_<table>_updated_at BEFORE UPDATE ON <table> FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()

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
-- ALTER TABLE ledgers ADD CONSTRAINT fk_ledger_parent FOREIGN KEY (parent_id) REFERENCES ledgers(id)

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

-- idx_bl_active was REMOVED from here — it depends on branch_ledger.is_reversed
-- which doesn't exist when this SQL file runs during 2025_01_01_000001.
-- The index is now created by 02_accounting.sql (which defines the new
-- branch_ledger schema with is_reversed) and is also re-created idempotently
-- by migration 2026_07_29_000013_create_branch_ledger_table.php.

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
-- NOTE: user_audit_log and journal_posting_logs BRIN indexes are now created
-- in the base SQL files (06_payment_and_misc.sql, 02_accounting.sql) as part
-- of Phase 10.1 partitioning. The IF NOT EXISTS guards here are kept for
-- backward compatibility with databases that have not yet been partitioned.
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

-- ===================== FULL-TEXT SEARCH (TSVECTOR + GIN) =====================
-- PostgreSQL full-text search replaces LIKE '%term%' / ILIKE '%term%' with
-- index-accelerated tsvector @@ plainto_tsquery lookups. Benefits:
--   1. GIN index: sub-millisecond on millions of rows (vs. sequential scan with LIKE)
--   2. Ranking: ts_rank() returns best matches first
--   3. Weighted columns: name > code > phone > address in relevance
--   4. 'simple' dictionary: no stemming (preserves product codes, Bengali names, phone numbers)
-- GENERATED ALWAYS AS ... STORED: auto-maintained by PG on every INSERT/UPDATE.
-- Mirrors migration 2025_01_20_000005_add_fulltext_search_products_customers.php.

-- PRODUCTS: search_vector (product_name=A, product_code=B)
ALTER TABLE products ADD COLUMN IF NOT EXISTS search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(product_name, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(product_code, '')), 'B')
    ) STORED;

CREATE INDEX IF NOT EXISTS idx_products_search
    ON products USING GIN (search_vector);

-- CUSTOMERS: search_vector (customer_name=A, customer_code=B, phone/mobile=C, address=D)
ALTER TABLE customers ADD COLUMN IF NOT EXISTS search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(customer_name, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(customer_code, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(phone, '')), 'C') ||
        setweight(to_tsvector('simple', coalesce(mobile, '')), 'C') ||
        setweight(to_tsvector('simple', coalesce(address, '')), 'D')
    ) STORED;

CREATE INDEX IF NOT EXISTS idx_customers_search
    ON customers USING GIN (search_vector);

-- ===================== RUNNING BALANCE RECONCILIATION (WINDOW FUNCTIONS) =====================
-- Task 18: Window-function running balance reconciliation for sub-ledgers.
--
-- REMOVED FROM THIS FILE — these objects are created by migration
-- 2025_01_20_000006_add_running_balance_reconciliation.php which runs
-- AFTER migration 2025_01_02_000002_add_is_reversed_to_sub_ledgers.php
-- adds the `is_reversed` column to customer_ledger, supplier_ledger,
-- and employee_ledger. Defining the MVs here (during 2025_01_01_000001
-- execution) would fail because `is_reversed` does not exist yet.
-- cash_ledger NEVER gets an `is_reversed` column (no migration adds it).


-- ===================== ROW-LEVEL SECURITY (RLS) — BRANCH ISOLATION =====================
-- Task 19: Database-level branch isolation that cannot be bypassed even by raw SQL.
--
-- This is the ultimate defense-in-depth layer:
--   Layer 1 (Query):  BranchScope Eloquent global scope — filters reads
--   Layer 2 (Route):  EnforceBranchIsolation middleware — validates writes
--   Layer 3 (DB):     RLS policies — enforced by PostgreSQL, no bypass possible
--
-- How it works:
--   1. SetAppBranchId middleware runs on every authenticated request:
--      SET app.branch_id = <session_branch_id>
--      SET app.is_admin = true|false
--   2. RLS policies check: current_setting('app.is_admin', true) = 'true'
--      → admin bypass (see all branches)
--      OR branch_id = current_setting('app.branch_id')::int
--      → non-admin sees own branch only
--   3. FORCE ROW LEVEL SECURITY makes even the table owner subject to policies
--
-- Safe defaults: Database-level GUC defaults are app.branch_id=0, app.is_admin=false.
-- Direct psql sessions without SET app.branch_id will see NO branch data (deny by default).
--
-- Mirrors migration 2025_01_20_000007_add_rls_branch_isolation.php.

-- Custom GUC defaults (deny-by-default for direct SQL sessions).
-- These require database owner privilege; if they fail, RLS still works
-- because current_setting(name, true) returns NULL → not admin → no access.
-- ALTER DATABASE <dbname> SET app.branch_id = 0
-- ALTER DATABASE <dbname> SET app.is_admin = false

-- ============================================================
-- SINGLE branch_id tables (31 tables)
-- Each gets 5 policies: SELECT, INSERT, UPDATE, DELETE + admin bypass
-- ============================================================

-- Auth & Master
ALTER TABLE employees ENABLE ROW LEVEL SECURITY;
ALTER TABLE employees FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_employees_select ON employees FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employees_insert ON employees FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employees_update ON employees FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employees_delete ON employees FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employees_admin ON employees FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE customers FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_customers_select ON customers FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customers_insert ON customers FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customers_update ON customers FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customers_delete ON customers FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customers_admin ON customers FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE suppliers ENABLE ROW LEVEL SECURITY;
ALTER TABLE suppliers FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_suppliers_select ON suppliers FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_suppliers_insert ON suppliers FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_suppliers_update ON suppliers FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_suppliers_delete ON suppliers FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_suppliers_admin ON suppliers FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE warehouses ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehouses FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_warehouses_select ON warehouses FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouses_insert ON warehouses FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouses_update ON warehouses FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouses_delete ON warehouses FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouses_admin ON warehouses FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Accounting
ALTER TABLE journal_entries ENABLE ROW LEVEL SECURITY;
ALTER TABLE journal_entries FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_journal_entries_select ON journal_entries FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_journal_entries_insert ON journal_entries FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_journal_entries_update ON journal_entries FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_journal_entries_delete ON journal_entries FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_journal_entries_admin ON journal_entries FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- document_sequences: global sequences (branch_id=0) must be accessible to ALL branches
-- for atomic code generation. Advisory locks (Task 20) replace SELECT FOR UPDATE,
-- so RLS policies must allow branch_id=0 reads/writes for all authenticated users.
ALTER TABLE document_sequences ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_sequences FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_document_sequences_global_select ON document_sequences FOR SELECT USING (branch_id = 0);
CREATE POLICY rls_document_sequences_global_insert ON document_sequences FOR INSERT WITH CHECK (branch_id = 0);
CREATE POLICY rls_document_sequences_global_update ON document_sequences FOR UPDATE USING (branch_id = 0) WITH CHECK (branch_id = 0);
CREATE POLICY rls_document_sequences_admin ON document_sequences FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE customer_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_customer_ledger_select ON customer_ledger FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_ledger_insert ON customer_ledger FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_ledger_update ON customer_ledger FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_ledger_delete ON customer_ledger FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_ledger_admin ON customer_ledger FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE supplier_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE supplier_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_supplier_ledger_select ON supplier_ledger FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_ledger_insert ON supplier_ledger FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_ledger_update ON supplier_ledger FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_ledger_delete ON supplier_ledger FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_ledger_admin ON supplier_ledger FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE employee_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE employee_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_employee_ledger_select ON employee_ledger FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_ledger_insert ON employee_ledger FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_ledger_update ON employee_ledger FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_ledger_delete ON employee_ledger FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_ledger_admin ON employee_ledger FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE branch_cash ENABLE ROW LEVEL SECURITY;
ALTER TABLE branch_cash FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_branch_cash_select ON branch_cash FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_cash_insert ON branch_cash FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_cash_update ON branch_cash FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_cash_delete ON branch_cash FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_cash_admin ON branch_cash FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE branch_expenses ENABLE ROW LEVEL SECURITY;
ALTER TABLE branch_expenses FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_branch_expenses_select ON branch_expenses FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_expenses_insert ON branch_expenses FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_expenses_update ON branch_expenses FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_expenses_delete ON branch_expenses FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_expenses_admin ON branch_expenses FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE branch_product_cost ENABLE ROW LEVEL SECURITY;
ALTER TABLE branch_product_cost FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_branch_product_cost_select ON branch_product_cost FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_product_cost_insert ON branch_product_cost FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_product_cost_update ON branch_product_cost FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_product_cost_delete ON branch_product_cost FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_product_cost_admin ON branch_product_cost FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE cash_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE cash_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_cash_ledger_select ON cash_ledger FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_cash_ledger_insert ON cash_ledger FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_cash_ledger_update ON cash_ledger FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_cash_ledger_delete ON cash_ledger FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_cash_ledger_admin ON cash_ledger FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE accounting_periods ENABLE ROW LEVEL SECURITY;
ALTER TABLE accounting_periods FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_accounting_periods_select ON accounting_periods FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_accounting_periods_insert ON accounting_periods FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_accounting_periods_update ON accounting_periods FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_accounting_periods_delete ON accounting_periods FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_accounting_periods_admin ON accounting_periods FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE manual_journals ENABLE ROW LEVEL SECURITY;
ALTER TABLE manual_journals FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_manual_journals_select ON manual_journals FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_manual_journals_insert ON manual_journals FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_manual_journals_update ON manual_journals FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_manual_journals_delete ON manual_journals FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_manual_journals_admin ON manual_journals FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Stock
ALTER TABLE stock_adjustments ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_adjustments FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_stock_adjustments_select ON stock_adjustments FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_adjustments_insert ON stock_adjustments FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_adjustments_update ON stock_adjustments FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_adjustments_delete ON stock_adjustments FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_adjustments_admin ON stock_adjustments FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE stock_take_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_take_sessions FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_stock_take_sessions_select ON stock_take_sessions FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_sessions_insert ON stock_take_sessions FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_sessions_update ON stock_take_sessions FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_sessions_delete ON stock_take_sessions FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_sessions_admin ON stock_take_sessions FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Phase 8 (Stock Take plan): RLS on the two stock-take child tables that
-- were missing it after Phases 0–7. Before this, a non-admin user from
-- Branch A could read stock_take_warehouses / stock_take_items for a
-- Branch B session via direct query (the EnforceBranchIsolation middleware
-- only checks the session row, not the child rows). branch_id is
-- denormalized onto both tables at insert time (migration
-- 2025_08_01_000001) so the policies can scope by branch without a join.
ALTER TABLE stock_take_warehouses ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_take_warehouses FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_stock_take_warehouses_select ON stock_take_warehouses FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_warehouses_insert ON stock_take_warehouses FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_warehouses_update ON stock_take_warehouses FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_warehouses_delete ON stock_take_warehouses FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_warehouses_admin ON stock_take_warehouses FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE stock_take_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_take_items FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_stock_take_items_select ON stock_take_items FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_items_insert ON stock_take_items FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_items_update ON stock_take_items FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_items_delete ON stock_take_items FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_stock_take_items_admin ON stock_take_items FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Phase 8 (Stock Take plan): the EXCLUDE constraint the plan asked for —
-- prevent two ACTIVE frozen sessions from covering the same warehouse. The
-- plan noted the "active + frozen" predicate spans two tables (stw + sts),
-- which a plain partial unique index cannot express; the trigger is the
-- cleanest DB-level enforcement. App logic in StockTakeService::
-- createSession provides the friendly error; the trigger is the race-
-- condition backstop (two concurrent createSession calls would otherwise
-- both pass the app check and both insert).
CREATE OR REPLACE FUNCTION prevent_overlapping_frozen_stock_take()
RETURNS trigger AS $$
DECLARE
    conflict_count integer;
BEGIN
    IF NEW.freeze_outbound IS NOT TRUE THEN
        RETURN NEW;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM stock_take_warehouses stw
    JOIN stock_take_sessions sts ON sts.id = stw.stock_take_session_id
    WHERE stw.warehouse_id = NEW.warehouse_id
      AND stw.freeze_outbound = true
      AND stw.id IS DISTINCT FROM NEW.id
      AND stw.stock_take_session_id IS DISTINCT FROM NEW.stock_take_session_id
      AND sts.status IN ('draft','counting','submitted','approved');

    IF conflict_count > 0 THEN
        RAISE EXCEPTION
            'Warehouse % is already covered by an active frozen stock-take session. Post or cancel the existing session first, or create this session without the outbound freeze.',
            NEW.warehouse_id
            USING ERRCODE = '23000';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_stw_no_overlapping_frozen ON stock_take_warehouses;
CREATE TRIGGER trg_stw_no_overlapping_frozen
    BEFORE INSERT OR UPDATE OF warehouse_id, freeze_outbound ON stock_take_warehouses
    FOR EACH ROW EXECUTE FUNCTION prevent_overlapping_frozen_stock_take();

ALTER TABLE damage_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE damage_invoices FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_damage_invoices_select ON damage_invoices FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_insert ON damage_invoices FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_update ON damage_invoices FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_delete ON damage_invoices FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_admin ON damage_invoices FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Sales
ALTER TABLE sales_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales_invoices FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_sales_invoices_select ON sales_invoices FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_invoices_insert ON sales_invoices FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_invoices_update ON sales_invoices FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_invoices_delete ON sales_invoices FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_invoices_admin ON sales_invoices FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE sales_challans ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales_challans FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_sales_challans_select ON sales_challans FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_challans_insert ON sales_challans FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_challans_update ON sales_challans FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_challans_delete ON sales_challans FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_challans_admin ON sales_challans FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE sales_draft_carts ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales_draft_carts FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_sales_draft_carts_select ON sales_draft_carts FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_draft_carts_insert ON sales_draft_carts FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_draft_carts_update ON sales_draft_carts FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_draft_carts_delete ON sales_draft_carts FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_draft_carts_admin ON sales_draft_carts FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE sales_returns ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales_returns FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_sales_returns_select ON sales_returns FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_returns_insert ON sales_returns FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_returns_update ON sales_returns FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_returns_delete ON sales_returns FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_sales_returns_admin ON sales_returns FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Purchase
ALTER TABLE purchase_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_orders FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_purchase_orders_select ON purchase_orders FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_orders_insert ON purchase_orders FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_orders_update ON purchase_orders FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_orders_delete ON purchase_orders FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_orders_admin ON purchase_orders FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE purchase_receives ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_receives FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_purchase_receives_select ON purchase_receives FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_receives_insert ON purchase_receives FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_receives_update ON purchase_receives FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_receives_delete ON purchase_receives FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_receives_admin ON purchase_receives FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE purchase_returns ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_returns FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_purchase_returns_select ON purchase_returns FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_returns_insert ON purchase_returns FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_returns_update ON purchase_returns FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_returns_delete ON purchase_returns FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_purchase_returns_admin ON purchase_returns FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- Payment & Misc
ALTER TABLE customer_payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_payments FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_customer_payments_select ON customer_payments FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_payments_insert ON customer_payments FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_payments_update ON customer_payments FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_payments_delete ON customer_payments FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_customer_payments_admin ON customer_payments FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE supplier_payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE supplier_payments FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_supplier_payments_select ON supplier_payments FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_payments_insert ON supplier_payments FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_payments_update ON supplier_payments FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_payments_delete ON supplier_payments FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_supplier_payments_admin ON supplier_payments FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE other_incomes ENABLE ROW LEVEL SECURITY;
ALTER TABLE other_incomes FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_other_incomes_select ON other_incomes FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_incomes_insert ON other_incomes FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_incomes_update ON other_incomes FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_incomes_delete ON other_incomes FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_incomes_admin ON other_incomes FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE other_expenses ENABLE ROW LEVEL SECURITY;
ALTER TABLE other_expenses FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_other_expenses_select ON other_expenses FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_expenses_insert ON other_expenses FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_expenses_update ON other_expenses FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_expenses_delete ON other_expenses FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_other_expenses_admin ON other_expenses FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE employee_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE employee_transactions FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_employee_transactions_select ON employee_transactions FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_transactions_insert ON employee_transactions FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_transactions_update ON employee_transactions FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_transactions_delete ON employee_transactions FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_employee_transactions_admin ON employee_transactions FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- ============================================================
-- DUAL branch_id tables (4 tables — inter-branch operations)
-- Policy: user sees rows where they are the from-branch OR to-branch
-- ============================================================

ALTER TABLE branch_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE branch_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_branch_ledger_select ON branch_ledger FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_ledger_insert ON branch_ledger FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_ledger_update ON branch_ledger FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_ledger_delete ON branch_ledger FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_ledger_admin ON branch_ledger FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE warehouse_transfers ENABLE ROW LEVEL SECURITY;
ALTER TABLE warehouse_transfers FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_warehouse_transfers_select ON warehouse_transfers FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouse_transfers_insert ON warehouse_transfers FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouse_transfers_update ON warehouse_transfers FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouse_transfers_delete ON warehouse_transfers FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_warehouse_transfers_admin ON warehouse_transfers FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE money_transfers ENABLE ROW LEVEL SECURITY;
ALTER TABLE money_transfers FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_money_transfers_select ON money_transfers FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_money_transfers_insert ON money_transfers FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_money_transfers_update ON money_transfers FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_money_transfers_delete ON money_transfers FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_money_transfers_admin ON money_transfers FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

ALTER TABLE branch_demands ENABLE ROW LEVEL SECURITY;
ALTER TABLE branch_demands FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_branch_demands_select ON branch_demands FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_demands_insert ON branch_demands FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_demands_update ON branch_demands FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_demands_delete ON branch_demands FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR from_branch_id = current_setting('app.branch_id')::int OR to_branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_branch_demands_admin ON branch_demands FOR ALL USING (current_setting('app.is_admin', true) = 'true') WITH CHECK (current_setting('app.is_admin', true) = 'true');

-- ============================================================
-- ADVISORY LOCKS FOR DOCUMENT SEQUENCE GENERATION (Task 20)
-- ============================================================
--
-- PostgreSQL advisory locks replace SELECT FOR UPDATE on document_sequences.
-- The PHP service (DocumentSequenceService) uses pg_advisory_xact_lock()
-- to serialize concurrent code generation for the same doc_type/branch_id/period_key.
--
-- Key construction (PHP side):
--   $lockKey = crc32("{$docType}:{$branchId}:{$periodKey}") converted to signed int4
--
-- SQL helper function for diagnostics:
--   doc_seq_advisory_key(doc_type, branch_id, period_key) → int4
--
-- Benefits over SELECT FOR UPDATE:
--   1. No disk I/O — locks live in shared memory
--   2. Non-blocking reads — other sessions can SELECT without waiting
--   3. Transaction-scoped auto-release on COMMIT/ROLLBACK
--   4. No RLS conflict — advisory locks are independent of RLS policies
--
-- Covering index for fast lookups under advisory lock:
--   idx_doc_seq_covering ON (doc_type, branch_id, period_key) INCLUDE (last_number, id)
--
-- RLS compatibility: document_sequences uses branch_id=0 (global sequences).
-- The policies above allow all users to read/insert/update branch_id=0 rows,
-- which is required because advisory locks don't depend on row visibility.

-- Helper function: compute advisory lock key from doc_type/branch_id/period_key.
-- Mirrors DocumentSequenceService::computeLockKey() in PHP for SQL diagnostics.
CREATE OR REPLACE FUNCTION doc_seq_advisory_key(
    p_doc_type  varchar,
    p_branch_id integer DEFAULT 0,
    p_period_key varchar DEFAULT ''
) RETURNS integer
LANGUAGE sql IMMUTABLE STRICT
AS $$
    SELECT (
        ('x' || left(md5(p_doc_type || ':' || p_branch_id::text || ':' || p_period_key), 8))::bit(32)::int
    );
$$;

-- Covering index: satisfies advisory-lock read pattern entirely from index.
CREATE INDEX IF NOT EXISTS idx_doc_seq_covering
    ON document_sequences (doc_type, branch_id, period_key)
    INCLUDE (last_number, id);

-- ============================================================
-- PG_CRON — Database-Level Scheduled Jobs
-- ============================================================
-- REMOVED FROM THIS FILE — pg_cron jobs, function definitions, and the
-- v_pg_cron_jobs view are created by migration
-- 2025_01_20_000009_add_pg_cron_scheduled_jobs.php, which first creates
-- the pg_cron extension before scheduling jobs. Defining them here
-- (during 2025_01_01_000001 execution) would fail with
-- "schema 'cron' does not exist" because pg_cron is not yet installed.

-- ============================================================
-- MATERIALIZED VIEWS + CTE FUNCTIONS BASELINE MIRROR
-- ============================================================
-- SOURCED FROM MIGRATIONS:
--   * database/migrations/2025_01_03_000001_create_report_materialized_views.php
--     (7 MVs + refresh_all_report_views() + indexes)
--   * database/migrations/2025_01_21_000002_add_cte_complex_queries.php
--     (4 rcerp_*_cte PL/pgSQL functions + 2 convenience views)
--
-- This section is maintained here as a CANONICAL BASELINE MIRROR so that
-- developers reading the SQL file (rather than running `php artisan migrate`)
-- have full visibility into the MV + CTE DDL that powers financial reports.
--
-- ON A FRESH DATABASE: run `php artisan migrate` — the migrations use
-- CREATE MATERIALIZED VIEW IF NOT EXISTS / CREATE OR REPLACE FUNCTION,
-- so they are idempotent. The statements below are kept verbatim from the
-- migrations for documentation + DBA point-in-time recovery use cases.
--
-- G-128/G-129 (REPORTS-AUDIT-2): previously this file had ZERO matches for
-- mv_/rcerp_/refresh_all_report_views, so DBAs reading this file for fresh
-- provisioning would miss the 7 MVs + 4 CTE functions entirely.

-- ============================================================
-- 1. MATERIALIZED VIEWS (from 2025_01_03_000001_create_report_materialized_views.php)
-- ============================================================

-- 1.1 mv_ledger_balances — per-ledger opening/period/closing.
--     Foundation for Trial Balance, P&L, Balance Sheet.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ledger_balances AS
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.is_control_account,
    l.is_active,
    l.parent_id,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net_debit,
    COUNT(jl.id) AS line_count,
    MAX(je.entry_date) AS last_entry_date
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND COALESCE(je.is_reversed, false) = false
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
         l.is_control_account, l.is_active, l.parent_id;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ledger_balances_ledger_id_idx ON mv_ledger_balances (ledger_id);
CREATE INDEX IF NOT EXISTS mv_ledger_balances_account_type_idx ON mv_ledger_balances (account_type);
CREATE INDEX IF NOT EXISTS mv_ledger_balances_nature_idx ON mv_ledger_balances (ledger_nature);

-- 1.2 mv_ar_aging — customer receivable aging buckets.
--     Computed as of the latest refresh (CURRENT_DATE at refresh time).
--     For as-of-date queries, ReportService::receivableAging falls back to direct query.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ar_aging AS
SELECT
    c.id AS customer_id,
    c.customer_code,
    c.customer_name,
    c.mobile,
    cl.branch_id,
    b.branch_name,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) <= 30
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) BETWEEN 31 AND 60
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) BETWEEN 61 AND 90
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (CURRENT_DATE - cl.transaction_date) > 90
        THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
    SUM(cl.debit - cl.credit) AS total_receivable
FROM customer_ledger cl
INNER JOIN customers c ON c.id = cl.customer_id
LEFT JOIN branches b ON b.id = cl.branch_id
WHERE COALESCE(cl.is_reversed, false) = false
GROUP BY c.id, c.customer_code, c.customer_name, c.mobile, cl.branch_id, b.branch_name
HAVING SUM(cl.debit - cl.credit) > 0.005;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ar_aging_customer_branch_idx ON mv_ar_aging (customer_id, branch_id);
CREATE INDEX IF NOT EXISTS mv_ar_aging_branch_idx ON mv_ar_aging (branch_id);

-- 1.3 mv_ap_aging — supplier payable aging buckets.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ap_aging AS
SELECT
    s.id AS supplier_id,
    s.supplier_code,
    s.supplier_name,
    s.mobile,
    sl.branch_id,
    b.branch_name,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) <= 30
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_0_30,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) BETWEEN 31 AND 60
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_31_60,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) BETWEEN 61 AND 90
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_61_90,
    SUM(CASE WHEN (CURRENT_DATE - sl.transaction_date) > 90
        THEN (sl.credit - sl.debit) ELSE 0 END) AS bucket_90_plus,
    SUM(sl.credit - sl.debit) AS total_payable
FROM supplier_ledger sl
INNER JOIN suppliers s ON s.id = sl.supplier_id
LEFT JOIN branches b ON b.id = sl.branch_id
WHERE COALESCE(sl.is_reversed, false) = false
GROUP BY s.id, s.supplier_code, s.supplier_name, s.mobile, sl.branch_id, b.branch_name
HAVING SUM(sl.credit - sl.debit) > 0.005;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ap_aging_supplier_branch_idx ON mv_ap_aging (supplier_id, branch_id);
CREATE INDEX IF NOT EXISTS mv_ap_aging_branch_idx ON mv_ap_aging (branch_id);

-- 1.4 mv_stock_valuation — per-warehouse product stock with value.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_stock_valuation AS
SELECT
    ws.warehouse_id,
    ws.product_id,
    p.product_code,
    p.product_name,
    p.unit,
    w.warehouse_name,
    w.branch_id,
    b.branch_name,
    ws.qty AS on_hand_qty,
    ws.avg_cost,
    (ws.qty * ws.avg_cost) AS stock_value
FROM warehouse_stock ws
INNER JOIN products p ON p.id = ws.product_id
INNER JOIN warehouses w ON w.id = ws.warehouse_id
INNER JOIN branches b ON b.id = w.branch_id
WHERE ws.qty > 0;

CREATE UNIQUE INDEX IF NOT EXISTS mv_stock_valuation_wh_prod_idx ON mv_stock_valuation (warehouse_id, product_id);
CREATE INDEX IF NOT EXISTS mv_stock_valuation_branch_idx ON mv_stock_valuation (branch_id);
CREATE INDEX IF NOT EXISTS mv_stock_valuation_product_idx ON mv_stock_valuation (product_id);

-- 1.5 mv_journal_entry_summary — per-entry debit/credit totals.
--     For Journal Entries report + reconciliation.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_journal_entry_summary AS
SELECT
    je.id AS journal_entry_id,
    je.entry_no,
    je.entry_date,
    je.reference_type,
    je.reference_id,
    je.branch_id,
    je.description,
    je.is_reversed,
    je.created_by,
    je.created_at,
    b.branch_name,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COUNT(jl.id) AS line_count
FROM journal_entries je
LEFT JOIN journal_lines jl ON jl.journal_entry_id = je.id
LEFT JOIN branches b ON b.id = je.branch_id
GROUP BY je.id, je.entry_no, je.entry_date, je.reference_type, je.reference_id,
         je.branch_id, je.description, je.is_reversed, je.created_by, je.created_at, b.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_journal_entry_summary_je_id_idx ON mv_journal_entry_summary (journal_entry_id);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_date_idx ON mv_journal_entry_summary (entry_date);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_branch_idx ON mv_journal_entry_summary (branch_id);
CREATE INDEX IF NOT EXISTS mv_journal_entry_summary_ref_idx ON mv_journal_entry_summary (reference_type, reference_id);

-- 1.6 mv_branch_intercompany — Due-from/Due-to balances per branch pair.
--     NOTE: references the NEW branch_ledger schema (debit / credit / is_reversed)
--     created directly by 02_accounting.sql. Earlier versions of the migration
--     referenced the OLD schema (amount, is_settled) and were later rewritten by
--     migration 2026_07_29_000013 — that double-write is no longer needed because
--     02_accounting.sql now creates the NEW schema directly. CREATE MATERIALIZED
--     VIEW IF NOT EXISTS makes this statement a no-op if 2026_07_29_000013
--     already ran first.
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
GROUP BY bl.from_branch_id, bl.to_branch_id, fb.branch_name, tb.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_branch_intercompany_from_to_idx ON mv_branch_intercompany (from_branch_id, to_branch_id);

-- 1.7 mv_product_movement_summary — per-product in/out totals.
--     For Product Stock Analysis + Product Movement reports.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_product_movement_summary AS
SELECT
    st.product_id,
    p.product_code,
    p.product_name,
    p.unit,
    st.warehouse_id,
    w.warehouse_name,
    w.branch_id,
    b.branch_name,
    SUM(CASE WHEN st.qty > 0 THEN st.qty ELSE 0 END) AS total_in_qty,
    SUM(CASE WHEN st.qty < 0 THEN ABS(st.qty) ELSE 0 END) AS total_out_qty,
    SUM(st.qty) AS net_qty,
    SUM(CASE WHEN st.qty > 0 THEN st.total_value ELSE 0 END) AS total_in_value,
    SUM(CASE WHEN st.qty < 0 THEN st.total_value ELSE 0 END) AS total_out_value,
    MIN(st.transaction_date) AS first_movement_date,
    MAX(st.transaction_date) AS last_movement_date,
    COUNT(*) AS movement_count
FROM stock_transactions st
INNER JOIN products p ON p.id = st.product_id
INNER JOIN warehouses w ON w.id = st.warehouse_id
INNER JOIN branches b ON b.id = w.branch_id
GROUP BY st.product_id, p.product_code, p.product_name, p.unit,
         st.warehouse_id, w.warehouse_name, w.branch_id, b.branch_name;

CREATE UNIQUE INDEX IF NOT EXISTS mv_pms_prod_wh_idx ON mv_product_movement_summary (product_id, warehouse_id);
CREATE INDEX IF NOT EXISTS mv_pms_branch_idx ON mv_product_movement_summary (branch_id);

-- 1.8 refresh_all_report_views() — refreshes all 7 MVs concurrently.
--     Called by the Laravel scheduler (every 5 min via pg_cron + reports:refresh
--     artisan command) and on-demand after journal postings.
--     NOTE: migration 2026_09_04_000001_rewrite_refresh_all_report_views_with_isolation_and_audit
--     supersedes this with a version that adds transaction isolation + audit logging.
--     The simple version below is the original baseline (still valid for fresh installs
--     that have not yet applied the rewrite migration).
CREATE OR REPLACE FUNCTION refresh_all_report_views()
RETURNS void AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ledger_balances;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ar_aging;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ap_aging;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_stock_valuation;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_journal_entry_summary;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_intercompany;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_movement_summary;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- 2. CTE FUNCTIONS (from 2025_01_21_000002_add_cte_complex_queries.php)
--    All 4 functions are LANGUAGE plpgsql STABLE — STABLE volatility
--    allows query plan caching and is safe for read-only reports.
-- ============================================================

-- 2.1 rcerp_today_summary(p_branch_id, p_date) — All dashboard KPIs in a single
--     CTE query. Replaces DashboardController::getRevenueKPIs() which made 6+
--     separate SQL queries.
CREATE OR REPLACE FUNCTION rcerp_today_summary(
    p_branch_id integer DEFAULT NULL,
    p_date      date    DEFAULT CURRENT_DATE
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Active invoices (not cancelled, not reversed)
    active_invoices AS (
        SELECT *
        FROM sales_invoices
        WHERE is_reversed = false
          AND status NOT IN ('cancelled', 'reversed')
          AND deleted_at IS NULL
          AND (p_branch_id IS NULL OR branch_id = p_branch_id)
    ),

    -- CTE 2: Today's sales summary
    today_sales AS (
        SELECT
            COUNT(*)          AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(due_amount), 0)   AS total_due
        FROM active_invoices
        WHERE invoice_date = p_date
    ),

    -- CTE 3: MTD sales summary
    mtd_sales AS (
        SELECT
            COUNT(*)          AS invoice_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(due_amount), 0)   AS total_due
        FROM active_invoices
        WHERE invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
    ),

    -- CTE 4: MTD collection
    mtd_collection AS (
        SELECT COALESCE(SUM(amount), 0) AS total_collection
        FROM customer_payments
        WHERE payment_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
          AND is_reversed = false
          -- Note: customer_payments has no deleted_at column (no soft-delete);
          -- only is_reversed is used to exclude reversed payments.
          AND (p_branch_id IS NULL OR branch_id = p_branch_id)
    ),

    -- CTE 5: All-time outstanding (non-draft active invoices with due > 0)
    all_time_outstanding AS (
        SELECT COALESCE(SUM(due_amount), 0) AS total_outstanding
        FROM active_invoices
        WHERE status NOT IN ('draft')
          AND due_amount > 0
    ),

    -- CTE 6: Previous month revenue (for growth calc)
    prev_month_sales AS (
        SELECT COALESCE(SUM(total_amount), 0) AS total_sales
        FROM active_invoices
        WHERE invoice_date BETWEEN
            DATE_TRUNC('month', p_date - INTERVAL '1 month')::date AND
            (DATE_TRUNC('month', p_date) - INTERVAL '1 day')::date
    ),

    -- CTE 7: Pending operations
    pending_ops AS (
        SELECT
            (SELECT COUNT(*) FROM active_invoices WHERE is_godown_prepared = false AND status = 'confirmed') AS pending_godown,
            (SELECT COUNT(*) FROM active_invoices WHERE is_godown_prepared = true AND is_challan_issued = false AND status = 'confirmed') AS pending_challan,
            (SELECT COUNT(*) FROM active_invoices WHERE status = 'draft') AS draft_count
    ),

    -- CTE 8: Top 5 customers by MTD revenue
    top_customers AS (
        SELECT
            c.id AS customer_id,
            c.customer_name,
            COUNT(*) AS invoice_count,
            COALESCE(SUM(ai.total_amount), 0) AS total_revenue,
            COALESCE(SUM(ai.due_amount), 0) AS total_due
        FROM active_invoices ai
        INNER JOIN customers c ON c.id = ai.customer_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY c.id, c.customer_name
        ORDER BY total_revenue DESC
        LIMIT 5
    ),

    -- CTE 9: Top 5 products by MTD qty sold
    top_products AS (
        SELECT
            p.id AS product_id,
            p.product_code,
            p.product_name,
            SUM(sii.qty) AS qty_sold,
            SUM(sii.qty * sii.rate) AS revenue
        FROM sales_invoice_items sii
        INNER JOIN active_invoices ai ON ai.id = sii.sales_invoice_id
        INNER JOIN products p ON p.id = sii.product_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY p.id, p.product_code, p.product_name
        ORDER BY qty_sold DESC
        LIMIT 5
    ),

    -- CTE 10: AR aging buckets (proper sub-ledger based)
    ar_aging AS (
        SELECT
            SUM(CASE WHEN (p_date - cl.transaction_date) <= 30 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN (p_date - cl.transaction_date) BETWEEN 31 AND 60 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN (p_date - cl.transaction_date) BETWEEN 61 AND 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN (p_date - cl.transaction_date) > 90 THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus
        FROM customer_ledger cl
        WHERE cl.transaction_date <= p_date
          AND COALESCE(cl.is_reversed, false) = false
          AND (p_branch_id IS NULL OR cl.branch_id = p_branch_id)
    ),

    -- CTE 11: Branch revenue comparison (MTD)
    branch_revenue AS (
        SELECT
            b.id AS branch_id,
            b.branch_name,
            COUNT(*) AS invoice_count,
            COALESCE(SUM(ai.total_amount), 0) AS revenue
        FROM active_invoices ai
        INNER JOIN branches b ON b.id = ai.branch_id
        WHERE ai.invoice_date BETWEEN DATE_TRUNC('month', p_date)::date AND p_date
        GROUP BY b.id, b.branch_name
        ORDER BY revenue DESC
    )

    -- Final aggregation: assemble all CTEs into a single JSON result
    SELECT jsonb_build_object(
        'date', p_date,
        'branch_id', p_branch_id,
        'today', jsonb_build_object(
            'invoice_count', (SELECT invoice_count FROM today_sales),
            'total_sales', (SELECT total_sales FROM today_sales),
            'total_due', (SELECT total_due FROM today_sales)
        ),
        'mtd', jsonb_build_object(
            'invoice_count', (SELECT invoice_count FROM mtd_sales),
            'total_sales', (SELECT total_sales FROM mtd_sales),
            'total_due', (SELECT total_due FROM mtd_sales),
            'total_collection', (SELECT total_collection FROM mtd_collection),
            'collection_rate', CASE
                WHEN (SELECT total_sales FROM mtd_sales) > 0
                THEN ROUND(((SELECT total_collection FROM mtd_collection) / (SELECT total_sales FROM mtd_sales) * 100)::numeric, 1)
                ELSE 0
            END
        ),
        'outstanding', jsonb_build_object(
            'total_outstanding', (SELECT total_outstanding FROM all_time_outstanding)
        ),
        'growth', jsonb_build_object(
            'prev_month_sales', (SELECT total_sales FROM prev_month_sales),
            'revenue_growth_pct', CASE
                WHEN (SELECT total_sales FROM prev_month_sales) > 0
                THEN ROUND((((SELECT total_sales FROM mtd_sales) - (SELECT total_sales FROM prev_month_sales)) / (SELECT total_sales FROM prev_month_sales) * 100)::numeric, 1)
                ELSE 0
            END
        ),
        'pending', (SELECT jsonb_build_object(
            'pending_godown', pending_godown,
            'pending_challan', pending_challan,
            'draft_count', draft_count
        ) FROM pending_ops),
        'top_customers', COALESCE((SELECT jsonb_agg(row_to_json(tc)::jsonb) FROM top_customers tc), '[]'::jsonb),
        'top_products', COALESCE((SELECT jsonb_agg(row_to_json(tp)::jsonb) FROM top_products tp), '[]'::jsonb),
        'ar_aging', (SELECT jsonb_build_object(
            'bucket_0_30', bucket_0_30,
            'bucket_31_60', bucket_31_60,
            'bucket_61_90', bucket_61_90,
            'bucket_90_plus', bucket_90_plus
        ) FROM ar_aging),
        'branch_revenue', COALESCE((SELECT jsonb_agg(row_to_json(br)::jsonb) FROM branch_revenue br), '[]'::jsonb)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE;

-- 2.2 rcerp_ar_aging_cte(p_as_of_date, p_branch_id) — Proper sub-ledger
--     based AR aging with GL reconciliation. Single CTE query replaces 2
--     queries (aging + GL check).
CREATE OR REPLACE FUNCTION rcerp_ar_aging_cte(
    p_as_of_date date,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Customer sub-ledger balances with aging buckets
    customer_balances AS (
        SELECT
            c.id AS customer_id,
            c.customer_code,
            c.customer_name,
            c.mobile,
            cl.branch_id,
            COALESCE(b.branch_name, '—') AS branch_name,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) <= 30
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) BETWEEN 31 AND 60
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) BETWEEN 61 AND 90
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
            SUM(CASE WHEN (p_as_of_date - cl.transaction_date) > 90
                THEN (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
            SUM(cl.debit - cl.credit) AS total_receivable
        FROM customer_ledger cl
        INNER JOIN customers c ON c.id = cl.customer_id
        LEFT JOIN branches b ON b.id = cl.branch_id
        WHERE cl.transaction_date <= p_as_of_date
          AND COALESCE(cl.is_reversed, false) = false
          AND (p_branch_id IS NULL OR cl.branch_id = p_branch_id)
        GROUP BY c.id, c.customer_code, c.customer_name, c.mobile, cl.branch_id, b.branch_name
        HAVING SUM(cl.debit - cl.credit) > 0.005
    ),

    -- CTE 2: GL AR control account balance
    gl_ar_control AS (
        SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS gl_balance
        FROM ledgers l
        JOIN journal_lines jl ON jl.ledger_id = l.id
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE l.ledger_nature = 'ar'
          AND COALESCE(je.is_reversed, false) = false
          AND je.entry_date <= p_as_of_date
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
    ),

    -- CTE 3: Per-bucket invoice detail (top overdue invoices)
    overdue_invoices AS (
        SELECT
            si.id,
            si.invoice_code,
            si.invoice_date,
            (p_as_of_date - si.invoice_date) AS days_overdue,
            si.due_amount,
            c.customer_name,
            b.branch_name
        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        LEFT JOIN branches b ON b.id = si.branch_id
        WHERE si.is_reversed = false
          AND si.status NOT IN ('draft', 'cancelled', 'reversed')
          AND si.deleted_at IS NULL
          AND si.due_amount > 0
          AND si.invoice_date < p_as_of_date - INTERVAL '30 days'
          AND (p_branch_id IS NULL OR si.branch_id = p_branch_id)
        ORDER BY si.due_amount DESC
        LIMIT 20
    ),

    -- CTE 4: Aging summary totals
    aging_totals AS (
        SELECT
            SUM(bucket_0_30)   AS total_bucket_0_30,
            SUM(bucket_31_60)  AS total_bucket_31_60,
            SUM(bucket_61_90)  AS total_bucket_61_90,
            SUM(bucket_90_plus) AS total_bucket_90_plus,
            SUM(total_receivable) AS grand_total
        FROM customer_balances
    ),

    -- CTE 5: Aging by branch (for multi-branch analysis)
    aging_by_branch AS (
        SELECT
            cb.branch_id,
            cb.branch_name,
            SUM(cb.bucket_0_30)   AS bucket_0_30,
            SUM(cb.bucket_31_60)  AS bucket_31_60,
            SUM(cb.bucket_61_90)  AS bucket_61_90,
            SUM(cb.bucket_90_plus) AS bucket_90_plus,
            SUM(cb.total_receivable) AS total_receivable
        FROM customer_balances cb
        GROUP BY cb.branch_id, cb.branch_name
        ORDER BY total_receivable DESC
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'Receivable Aging (CTE)',
            'as_of_date', p_as_of_date,
            'branch_id', p_branch_id,
            'source', 'cte_query'
        ),
        'customers', COALESCE((SELECT jsonb_agg(jsonb_build_object(
            'customer_id', customer_id,
            'customer_code', customer_code,
            'customer_name', customer_name,
            'mobile', mobile,
            'branch_id', branch_id,
            'branch_name', branch_name,
            'bucket_0_30', bucket_0_30,
            'bucket_31_60', bucket_31_60,
            'bucket_61_90', bucket_61_90,
            'bucket_90_plus', bucket_90_plus,
            'total_receivable', total_receivable
        ) ORDER BY total_receivable DESC) FROM customer_balances), '[]'::jsonb),
        'totals', jsonb_build_object(
            'bucket_0_30', (SELECT total_bucket_0_30 FROM aging_totals),
            'bucket_31_60', (SELECT total_bucket_31_60 FROM aging_totals),
            'bucket_61_90', (SELECT total_bucket_61_90 FROM aging_totals),
            'bucket_90_plus', (SELECT total_bucket_90_plus FROM aging_totals),
            'total_receivable', (SELECT grand_total FROM aging_totals),
            'gl_ar_control', (SELECT gl_balance FROM gl_ar_control)
        ),
        'checks', jsonb_build_object(
            'matches_gl', (SELECT ABS(grand_total - gl_balance) < 0.01 FROM aging_totals, gl_ar_control)
        ),
        'overdue_invoices', COALESCE((SELECT jsonb_agg(row_to_json(oi)::jsonb ORDER BY due_amount DESC) FROM overdue_invoices oi), '[]'::jsonb),
        'aging_by_branch', COALESCE((SELECT jsonb_agg(row_to_json(ab)::jsonb ORDER BY total_receivable DESC) FROM aging_by_branch ab), '[]'::jsonb)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE;

-- 2.3 rcerp_general_ledger_cte(p_from_date, p_to_date, p_ledger_id, p_branch_id)
--     General ledger with SQL window-function running balance. Replaces
--     PHP-side running balance computation in ReportService::generalLedger().
CREATE OR REPLACE FUNCTION rcerp_general_ledger_cte(
    p_from_date  date,
    p_to_date    date,
    p_ledger_id  integer DEFAULT NULL,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Opening balances per ledger (before the from_date)
    opening AS (
        SELECT
            jl.ledger_id,
            COALESCE(SUM(jl.debit - jl.credit), 0) AS opening_balance
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.entry_date < p_from_date
          AND COALESCE(je.is_reversed, false) = false
          AND (p_ledger_id IS NULL OR jl.ledger_id = p_ledger_id)
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
        GROUP BY jl.ledger_id
    ),

    -- CTE 2: Period activity with running balance (window function)
    period_activity AS (
        SELECT
            je.id AS journal_entry_id,
            je.entry_no,
            je.entry_date,
            je.reference_type,
            je.reference_id,
            je.description,
            je.branch_id,
            COALESCE(b.branch_name, '—') AS branch_name,
            je.is_reversed,
            jl.id AS journal_line_id,
            jl.ledger_id,
            l.ledger_code,
            l.ledger_name,
            l.account_type,
            jl.debit,
            jl.credit,
            jl.entity_type,
            jl.entity_id,
            jl.memo,
            -- Running balance: opening + cumulative sum of (debit - credit) partitioned by ledger
            COALESCE(o.opening_balance, 0) +
                SUM(jl.debit - jl.credit) OVER (
                    PARTITION BY jl.ledger_id
                    ORDER BY je.entry_date, je.entry_no, jl.id
                    ROWS UNBOUNDED PRECEDING
                ) AS running_balance
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        JOIN ledgers l ON l.id = jl.ledger_id
        LEFT JOIN branches b ON b.id = je.branch_id
        LEFT JOIN opening o ON o.ledger_id = jl.ledger_id
        WHERE je.entry_date BETWEEN p_from_date AND p_to_date
          AND COALESCE(je.is_reversed, false) = false
          AND (p_ledger_id IS NULL OR jl.ledger_id = p_ledger_id)
          AND (p_branch_id IS NULL OR je.branch_id = p_branch_id)
        ORDER BY l.ledger_code, je.entry_date, je.entry_no, jl.id
    ),

    -- CTE 3: Closing balances per ledger
    closing AS (
        SELECT
            pa.ledger_id,
            MAX(pa.running_balance) AS closing_balance,
            -- The last row's running_balance IS the closing balance
            SUM(pa.debit) AS period_debit,
            SUM(pa.credit) AS period_credit
        FROM period_activity pa
        GROUP BY pa.ledger_id
    ),

    -- CTE 4: Ledger summary for header section
    ledger_summary AS (
        SELECT
            l.id AS ledger_id,
            l.ledger_code,
            l.ledger_name,
            l.account_type,
            COALESCE(o.opening_balance, 0) AS opening_balance,
            COALESCE(c.period_debit, 0) AS period_debit,
            COALESCE(c.period_credit, 0) AS period_credit,
            COALESCE(c.closing_balance, COALESCE(o.opening_balance, 0)) AS closing_balance
        FROM ledgers l
        LEFT JOIN opening o ON o.ledger_id = l.id
        LEFT JOIN closing c ON c.ledger_id = l.id
        WHERE l.is_active = true
          AND (p_ledger_id IS NULL OR l.id = p_ledger_id)
          AND (
              -- Only include ledgers that have activity or opening balance
              o.opening_balance IS NOT NULL
              OR c.period_debit IS NOT NULL
              OR c.period_credit IS NOT NULL
          )
        ORDER BY l.ledger_code
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'General Ledger (CTE)',
            'from_date', p_from_date,
            'to_date', p_to_date,
            'ledger_id', p_ledger_id,
            'branch_id', p_branch_id,
            'source', 'cte_window_function'
        ),
        'entries', COALESCE((SELECT jsonb_agg(jsonb_build_object(
            'journal_entry_id', journal_entry_id,
            'entry_no', entry_no,
            'entry_date', entry_date,
            'reference_type', reference_type,
            'reference_id', reference_id,
            'description', description,
            'branch_id', branch_id,
            'branch_name', branch_name,
            'ledger_id', ledger_id,
            'ledger_code', ledger_code,
            'ledger_name', ledger_name,
            'debit', debit,
            'credit', credit,
            'running_balance', running_balance,
            'memo', memo
        )) FROM period_activity), '[]'::jsonb),
        'ledger_summary', COALESCE((SELECT jsonb_agg(row_to_json(ls)::jsonb) FROM ledger_summary ls), '[]'::jsonb),
        'totals', jsonb_build_object(
            'total_debit', (SELECT COALESCE(SUM(debit), 0) FROM period_activity),
            'total_credit', (SELECT COALESCE(SUM(credit), 0) FROM period_activity),
            'total_opening', (SELECT COALESCE(SUM(opening_balance), 0) FROM ledger_summary),
            'total_closing', (SELECT COALESCE(SUM(closing_balance), 0) FROM ledger_summary)
        ),
        'checks', jsonb_build_object(
            'balanced', (SELECT ABS(SUM(debit) - SUM(credit)) < 0.01 FROM period_activity)
        )
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE;

-- 2.4 rcerp_gross_margin_cte(p_from_date, p_to_date, p_branch_id)
--     Gross margin analysis with per-item COGS via CTE. Joins invoice_items
--     → sales_challan_items → stock_transactions for accurate per-product COGS.
--     Schema fixes applied (preserved verbatim from migration):
--       * sales_challan_items has sales_challan_id (not challan_id)
--       * sales_invoice_id lives on sales_challans, not sales_challan_items
--       * stock_transactions uses qty (not qty_change) and rate (not avg_cost)
--       * sales_challans has no deleted_at column (only sales_invoices does)
CREATE OR REPLACE FUNCTION rcerp_gross_margin_cte(
    p_from_date  date,
    p_to_date    date,
    p_branch_id  integer DEFAULT NULL
)
RETURNS jsonb AS $$
DECLARE
    v_result jsonb;
BEGIN
    WITH
    -- CTE 1: Active invoices in the period
    active_invoices AS (
        SELECT
            si.id, si.invoice_code, si.invoice_date,
            si.customer_id, si.branch_id,
            si.sub_total, si.discount_amount,
            si.transport_cost, si.total_amount,
            c.customer_name,
            b.branch_name
        FROM sales_invoices si
        INNER JOIN customers c ON c.id = si.customer_id
        LEFT JOIN branches b ON b.id = si.branch_id
        WHERE si.invoice_date BETWEEN p_from_date AND p_to_date
          AND si.status NOT IN ('draft', 'cancelled')
          AND si.is_reversed = false
          AND si.deleted_at IS NULL
          AND (p_branch_id IS NULL OR si.branch_id = p_branch_id)
    ),

    -- CTE 2: Invoice items with revenue
    invoice_items AS (
        SELECT
            ai.id AS invoice_id,
            ai.invoice_code,
            ai.invoice_date,
            ai.customer_name,
            ai.branch_name,
            sii.product_id,
            p.product_code,
            p.product_name,
            sii.qty,
            sii.rate,
            sii.amount AS line_amount,
            sii.discount_amount AS line_discount
        FROM active_invoices ai
        INNER JOIN sales_invoice_items sii ON sii.sales_invoice_id = ai.id
        INNER JOIN products p ON p.id = sii.product_id
    ),

    -- CTE 3: COGS per invoice item (from stock transactions via challan)
    item_cogs AS (
        SELECT
            sc.sales_invoice_id AS invoice_id,
            sci.product_id,
            SUM(st.qty) AS cogs_qty,  -- negative (stock OUT)
            SUM(ABS(st.qty) * st.rate) AS cogs_amount
        FROM sales_challan_items sci
        INNER JOIN sales_challans sc ON sc.id = sci.sales_challan_id
        INNER JOIN stock_transactions st ON st.reference_type = 'sales_challan'
            AND st.reference_id = sc.id
            AND st.product_id = sci.product_id
        WHERE sc.is_reversed = false
        GROUP BY sc.sales_invoice_id, sci.product_id
    ),

    -- CTE 4: Per-invoice margin (aggregated from items)
    invoice_margin AS (
        SELECT
            ii.invoice_id,
            ii.invoice_code,
            ii.invoice_date,
            ii.customer_name,
            ii.branch_name,
            SUM(ii.line_amount) AS total_revenue,
            SUM(ii.line_discount) AS total_line_discount,
            COALESCE(SUM(ic.cogs_amount), 0) AS total_cogs,
            SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0) AS gross_profit,
            CASE WHEN SUM(ii.line_amount) > 0
                THEN ROUND(((SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0)) / SUM(ii.line_amount) * 100)::numeric, 2)
                ELSE 0
            END AS margin_pct
        FROM invoice_items ii
        LEFT JOIN item_cogs ic ON ic.invoice_id = ii.invoice_id AND ic.product_id = ii.product_id
        GROUP BY ii.invoice_id, ii.invoice_code, ii.invoice_date, ii.customer_name, ii.branch_name
    ),

    -- CTE 5: Per-product margin summary
    product_margin AS (
        SELECT
            ii.product_id,
            ii.product_code,
            ii.product_name,
            SUM(ii.qty) AS total_qty,
            SUM(ii.line_amount) AS total_revenue,
            COALESCE(SUM(ic.cogs_amount), 0) AS total_cogs,
            SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0) AS gross_profit,
            CASE WHEN SUM(ii.line_amount) > 0
                THEN ROUND(((SUM(ii.line_amount) - COALESCE(SUM(ic.cogs_amount), 0)) / SUM(ii.line_amount) * 100)::numeric, 2)
                ELSE 0
            END AS margin_pct
        FROM invoice_items ii
        LEFT JOIN item_cogs ic ON ic.invoice_id = ii.invoice_id AND ic.product_id = ii.product_id
        GROUP BY ii.product_id, ii.product_code, ii.product_name
        ORDER BY gross_profit DESC
    ),

    -- CTE 6: Grand totals
    grand_totals AS (
        SELECT
            SUM(total_revenue) AS total_revenue,
            SUM(total_cogs) AS total_cogs,
            SUM(gross_profit) AS total_gross_profit,
            CASE WHEN SUM(total_revenue) > 0
                THEN ROUND((SUM(gross_profit) / SUM(total_revenue) * 100)::numeric, 2)
                ELSE 0
            END AS overall_margin_pct
        FROM invoice_margin
    )

    -- Final: assemble into JSON
    SELECT jsonb_build_object(
        'meta', jsonb_build_object(
            'title', 'Gross Margin Analysis (CTE)',
            'from_date', p_from_date,
            'to_date', p_to_date,
            'branch_id', p_branch_id,
            'source', 'cte_query'
        ),
        'invoice_margin', COALESCE((SELECT jsonb_agg(row_to_json(im)::jsonb ORDER BY invoice_date DESC, invoice_code) FROM invoice_margin im), '[]'::jsonb),
        'product_margin', COALESCE((SELECT jsonb_agg(row_to_json(pm)::jsonb ORDER BY gross_profit DESC) FROM product_margin pm), '[]'::jsonb),
        'totals', (SELECT jsonb_build_object(
            'total_revenue', total_revenue,
            'total_cogs', total_cogs,
            'total_gross_profit', total_gross_profit,
            'overall_margin_pct', overall_margin_pct
        ) FROM grand_totals)
    ) INTO v_result;

    RETURN v_result;
END;
$$ LANGUAGE plpgsql STABLE;

-- 2.5 Convenience views wrapping the CTE functions for direct SQL access.
--     For psql / DBA smoke tests: SELECT * FROM v_today_summary;
CREATE OR REPLACE VIEW v_today_summary AS
SELECT rcerp_today_summary(NULL, CURRENT_DATE) AS summary_data;

CREATE OR REPLACE VIEW v_ar_aging_today AS
SELECT rcerp_ar_aging_cte(CURRENT_DATE, NULL) AS aging_data;

-- End of MV + CTE baseline mirror (G-128/G-129, REPORTS-AUDIT-2).
