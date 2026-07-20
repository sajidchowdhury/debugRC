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
