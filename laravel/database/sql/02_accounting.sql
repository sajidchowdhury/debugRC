-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 2: Accounting Core
-- ============================================================

CREATE TABLE ledgers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ledger_code varchar(20) NOT NULL,
    ledger_name varchar(100) NOT NULL,
    parent_id integer DEFAULT 0,
    account_type varchar(20) NOT NULL CHECK (account_type IN ('Asset','Liability','Equity','Income','Expense')),
    ledger_nature varchar(50),
    is_control_account boolean NOT NULL DEFAULT false,
    control_account_type varchar(30),
    is_active boolean NOT NULL DEFAULT true,
    opening_balance numeric(15,2) DEFAULT 0,
    sort_order integer DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT ledgers_ledger_code_unique UNIQUE (ledger_code)
);
CREATE INDEX idx_ledgers_parent ON ledgers(parent_id);
CREATE INDEX idx_ledgers_account_type ON ledgers(account_type);
CREATE INDEX idx_ledgers_nature ON ledgers(ledger_nature);

CREATE TABLE journal_entries (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entry_no varchar(30) NOT NULL,
    entry_date date NOT NULL,
    reference_type varchar(30),
    reference_id integer,
    branch_id integer,
    description text,
    source varchar(30) DEFAULT 'manual',
    is_reversed boolean NOT NULL DEFAULT false,
    reversal_of_entry_id integer,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT journal_entries_entry_no_unique UNIQUE (entry_no)
);
CREATE INDEX idx_je_reference ON journal_entries(reference_type, reference_id);
CREATE INDEX idx_je_entry_date ON journal_entries(entry_date);
CREATE INDEX idx_je_branch ON journal_entries(branch_id);
CREATE INDEX idx_je_reversed ON journal_entries(is_reversed);

CREATE TABLE journal_lines (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_entry_id integer NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    ledger_id integer NOT NULL,
    debit numeric(15,2) NOT NULL DEFAULT 0,
    credit numeric(15,2) NOT NULL DEFAULT 0,
    entity_type varchar(30),
    entity_id integer,
    memo text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT jl_balanced_check CHECK (debit >= 0 AND credit >= 0),
    CONSTRAINT jl_not_both_zero_check CHECK (debit > 0 OR credit > 0)
);
CREATE INDEX idx_jl_journal_entry ON journal_lines(journal_entry_id);
CREATE INDEX idx_jl_ledger ON journal_lines(ledger_id);
CREATE INDEX idx_jl_entity ON journal_lines(entity_type, entity_id);

-- DB-level enforcement: every journal entry MUST have balanced debits = credits.
-- This is the crown-jewel invariant of double-entry bookkeeping.
CREATE OR REPLACE FUNCTION enforce_balanced_journal_entry()
RETURNS TRIGGER AS $$
DECLARE
    total_debit numeric(15,2);
    total_credit numeric(15,2);
BEGIN
    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
    INTO total_debit, total_credit
    FROM journal_lines
    WHERE journal_entry_id = COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

    IF total_debit <> total_credit THEN
        RAISE EXCEPTION 'Journal entry % is not balanced: debits (%) do not equal credits (%)',
            COALESCE(NEW.journal_entry_id, OLD.journal_entry_id), total_debit, total_credit
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_journal_balanced
AFTER INSERT OR UPDATE OR DELETE ON journal_lines
FOR EACH ROW EXECUTE FUNCTION enforce_balanced_journal_entry();

CREATE TABLE journal_posting_logs (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_entry_id integer NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    action varchar(20) NOT NULL CHECK (action IN ('posted','reversed','edited')),
    performed_by integer,
    performed_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    remarks text
);
CREATE INDEX idx_jpl_entry ON journal_posting_logs(journal_entry_id);

CREATE TABLE document_sequences (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    doc_type varchar(30) NOT NULL,
    branch_id integer NOT NULL DEFAULT 0,
    period_key varchar(10) NOT NULL DEFAULT '',
    last_number integer NOT NULL DEFAULT 0,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_doc_sequence UNIQUE (doc_type, branch_id, period_key)
);

-- Sub-ledgers (denormalized running balances for AR / AP / employee)
CREATE TABLE customer_ledger (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id integer NOT NULL,
    branch_id integer,
    transaction_date date NOT NULL,
    transaction_type varchar(30) NOT NULL,
    reference_type varchar(30),
    reference_id integer,
    debit numeric(14,2) DEFAULT 0,
    credit numeric(14,2) DEFAULT 0,
    balance numeric(14,2) DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_cl_customer_date ON customer_ledger(customer_id, transaction_date);
CREATE INDEX idx_cl_reference ON customer_ledger(reference_type, reference_id);
CREATE INDEX idx_cl_branch ON customer_ledger(branch_id);
CREATE INDEX idx_cl_journal ON customer_ledger(journal_entry_id);

CREATE TABLE supplier_ledger (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id integer NOT NULL,
    branch_id integer,
    transaction_date date NOT NULL,
    transaction_type varchar(30) NOT NULL,
    reference_type varchar(30),
    reference_id integer,
    debit numeric(14,2) DEFAULT 0,
    credit numeric(14,2) DEFAULT 0,
    balance numeric(14,2) DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sl_supplier_date ON supplier_ledger(supplier_id, transaction_date);
CREATE INDEX idx_sl_reference ON supplier_ledger(reference_type, reference_id);
CREATE INDEX idx_sl_journal ON supplier_ledger(journal_entry_id);

CREATE TABLE employee_ledger (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id integer NOT NULL,
    branch_id integer,
    transaction_date date NOT NULL,
    transaction_type varchar(30) NOT NULL CHECK (transaction_type IN ('advance','loan','repayment','salary','deduction','adjustment')),
    reference_type varchar(30),
    reference_id integer,
    debit numeric(14,2) DEFAULT 0,
    credit numeric(14,2) DEFAULT 0,
    balance numeric(14,2) DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_el_employee_date ON employee_ledger(employee_id, transaction_date);

CREATE TABLE branch_ledger (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    transaction_date date NOT NULL,
    transaction_type varchar(30) NOT NULL,
    reference_type varchar(30),
    reference_id integer,
    amount numeric(15,2) DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_settled boolean NOT NULL DEFAULT false,
    settled_at timestamp(0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bl_from_branch ON branch_ledger(from_branch_id);
CREATE INDEX idx_bl_to_branch ON branch_ledger(to_branch_id);

CREATE TABLE branch_cash (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_id integer NOT NULL REFERENCES branches(id) ON DELETE CASCADE,
    cash_point varchar(50) NOT NULL,
    balance numeric(15,2) DEFAULT 0,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_branch_cash UNIQUE (branch_id, cash_point)
);

CREATE TABLE branch_expenses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_id integer NOT NULL REFERENCES branches(id),
    expense_date date NOT NULL,
    amount numeric(14,2) NOT NULL,
    description text,
    expense_type varchar(50),
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_be_branch_date ON branch_expenses(branch_id, expense_date);

CREATE TABLE branch_product_cost (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_id integer NOT NULL REFERENCES branches(id),
    product_id integer NOT NULL REFERENCES products(id),
    avg_cost numeric(12,2) DEFAULT 0,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bpc_branch_product ON branch_product_cost(branch_id, product_id);

CREATE TABLE cash_ledger (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_id integer NOT NULL REFERENCES branches(id),
    transaction_date date NOT NULL,
    transaction_type varchar(30) NOT NULL,
    reference_type varchar(30),
    reference_id integer,
    amount numeric(15,2) DEFAULT 0,
    balance numeric(15,2) DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer REFERENCES users(id),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_cashl_branch_date ON cash_ledger(branch_id, transaction_date);

CREATE TABLE accounting_periods (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_id integer NOT NULL,
    closed_through_date date NOT NULL,
    closed_by integer,
    closed_at timestamp(0),
    notes text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_accounting_period_branch UNIQUE (branch_id)
);

CREATE TABLE manual_journals (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    journal_code varchar(30) NOT NULL,
    journal_date date NOT NULL,
    branch_id integer NOT NULL,
    description text,
    total_debit numeric(15,2) NOT NULL DEFAULT 0,
    total_credit numeric(15,2) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted','reversed')),
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT manual_journals_code_unique UNIQUE (journal_code)
);
CREATE INDEX idx_mj_branch_date ON manual_journals(branch_id, journal_date);
CREATE INDEX idx_mj_journal ON manual_journals(journal_entry_id);

CREATE TABLE schema_migrations (
    filename varchar(255) NOT NULL PRIMARY KEY,
    applied_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
