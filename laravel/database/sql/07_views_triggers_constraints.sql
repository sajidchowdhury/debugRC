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
-- The denormalized `balance` column in each sub-ledger (customer_ledger,
-- supplier_ledger, employee_ledger, cash_ledger) is maintained by
-- SubLedgerService using prev + debit/credit. This is efficient for reads
-- but fragile — if any row is inserted out of order or modified, all
-- subsequent balances are wrong.
--
-- These materialized views use SUM() OVER (PARTITION BY entity ORDER BY id)
-- to compute the mathematically correct running balance and compare it
-- against the stored `balance` column. The Artisan command
-- `reconcile:running-balance` refreshes these views and reports any drift.
--
-- Running balance formulas (same as SubLedgerService):
--   customer_ledger: balance = prev + debit - credit
--   supplier_ledger: balance = prev + credit - debit
--   employee_ledger: balance = prev + credit - debit
--   cash_ledger:     balance = prev + amount
--
-- Mirrors migration 2025_01_20_000006_add_running_balance_reconciliation.php.

-- 1. reconciliation_snapshots — Structured audit trail for reconciliation runs.
CREATE TABLE IF NOT EXISTS reconciliation_snapshots (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_type        varchar(30) NOT NULL DEFAULT 'running_balance',
    ledger_type     varchar(30) NOT NULL,
    entity_id       integer,
    total_rows      integer NOT NULL DEFAULT 0,
    matched_rows    integer NOT NULL DEFAULT 0,
    drift_rows      integer NOT NULL DEFAULT 0,
    max_drift       numeric(15,2) DEFAULT 0,
    max_drift_entity_id integer,
    status          varchar(10) NOT NULL DEFAULT 'green' CHECK (status IN ('green','red','error')),
    tolerance       numeric(15,4) NOT NULL DEFAULT 0.02,
    as_of_date      date,
    details         jsonb,
    ran_at          timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ran_by          integer
);

CREATE INDEX IF NOT EXISTS idx_rs_run_type_ran_at
    ON reconciliation_snapshots (run_type, ran_at DESC);

CREATE INDEX IF NOT EXISTS idx_rs_ledger_type_status
    ON reconciliation_snapshots (ledger_type, status, ran_at DESC);

-- 2. Materialized View: mv_customer_ledger_balance_check
--    Compares stored balance vs window-function computed balance per customer.
--    Only includes non-reversed rows.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_customer_ledger_balance_check AS
SELECT
    id,
    customer_id,
    transaction_date,
    transaction_type,
    debit,
    credit,
    balance AS stored_balance,
    SUM(debit - credit) OVER (
        PARTITION BY customer_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(debit - credit) OVER (
        PARTITION BY customer_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM customer_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_clbc_id
    ON mv_customer_ledger_balance_check (id);

-- 3. Materialized View: mv_supplier_ledger_balance_check
--    Formula: SUM(credit - debit) OVER (PARTITION BY supplier_id ORDER BY id)
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_supplier_ledger_balance_check AS
SELECT
    id,
    supplier_id,
    transaction_date,
    transaction_type,
    debit,
    credit,
    balance AS stored_balance,
    SUM(credit - debit) OVER (
        PARTITION BY supplier_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(credit - debit) OVER (
        PARTITION BY supplier_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM supplier_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_slbc_id
    ON mv_supplier_ledger_balance_check (id);

-- 4. Materialized View: mv_employee_ledger_balance_check
--    Formula: SUM(credit - debit) OVER (PARTITION BY employee_id ORDER BY id)
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_employee_ledger_balance_check AS
SELECT
    id,
    employee_id,
    transaction_date,
    transaction_type,
    debit,
    credit,
    balance AS stored_balance,
    SUM(credit - debit) OVER (
        PARTITION BY employee_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(credit - debit) OVER (
        PARTITION BY employee_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM employee_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_elbc_id
    ON mv_employee_ledger_balance_check (id);

-- 5. Materialized View: mv_cash_ledger_balance_check
--    Formula: SUM(amount) OVER (PARTITION BY branch_id ORDER BY id)
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_cash_ledger_balance_check AS
SELECT
    id,
    branch_id,
    transaction_date,
    transaction_type,
    amount,
    balance AS stored_balance,
    SUM(amount) OVER (
        PARTITION BY branch_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(amount) OVER (
        PARTITION BY branch_id
        ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM cash_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mv_cashlbc_id
    ON mv_cash_ledger_balance_check (id);

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
-- ALTER DATABASE <dbname> SET app.branch_id = 0;
-- ALTER DATABASE <dbname> SET app.is_admin = false;

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

ALTER TABLE document_sequences ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_sequences FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_document_sequences_select ON document_sequences FOR SELECT USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_document_sequences_insert ON document_sequences FOR INSERT WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_document_sequences_update ON document_sequences FOR UPDATE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int) WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_document_sequences_delete ON document_sequences FOR DELETE USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
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
