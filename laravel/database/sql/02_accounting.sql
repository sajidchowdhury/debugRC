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
    is_system boolean NOT NULL DEFAULT false,
    normal_balance varchar(10) DEFAULT 'debit' CHECK (normal_balance IN ('debit', 'credit')),
    description text,
    opening_balance numeric(15,2) DEFAULT 0,
    sort_order integer DEFAULT 0,
    created_by integer,
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
    -- G-320 (G3) FINANCE-DIM-1: dimension tagging column. FK to dimension_values(id)
    -- is defined in 08_budgeting_and_dimensions.sql (DEFERRABLE INITIALLY DEFERRED
    -- so the FK check happens at commit, not at INSERT — allows posting JEs where
    -- the dimension_value row is created in the same transaction). Nullable because
    -- most journal lines are NOT dimension-tagged (only manual-journal lines tagged
    -- by the accountant + future business-module wiring carry a non-null value).
    -- The migration 2026_08_10_000002 L105-112 adds this column post-hoc; this
    -- canonical DDL now includes it so fresh `psql -f database/sql/*.sql` installs
    -- have the column without needing the migration.
    dimension_value_id integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT jl_balanced_check CHECK (debit >= 0 AND credit >= 0),
    CONSTRAINT jl_not_both_zero_check CHECK (debit > 0 OR credit > 0)
);
CREATE INDEX idx_jl_journal_entry ON journal_lines(journal_entry_id);
CREATE INDEX idx_jl_ledger ON journal_lines(ledger_id);
CREATE INDEX idx_jl_entity ON journal_lines(entity_type, entity_id);
CREATE INDEX idx_jl_dim_value ON journal_lines(dimension_value_id);

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

-- Phase 10.1: journal_posting_logs is now partitioned by RANGE (performed_at)
-- See migration 2026_08_02_000001_partition_audit_log_tables.php for the
-- conversion logic (rename → recreate → copy → drop old).
CREATE TABLE journal_posting_logs (
    id integer GENERATED BY DEFAULT AS IDENTITY,
    journal_entry_id integer NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    action varchar(20) NOT NULL CHECK (action IN ('posted','reversed','edited')),
    performed_by integer,
    performed_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    remarks text,
    PRIMARY KEY (id, performed_at)
) PARTITION BY RANGE (performed_at);

-- Pre-2026 catch-all partition for historical data
CREATE TABLE journal_posting_logs_pre2026 PARTITION OF journal_posting_logs
    FOR VALUES FROM ('2020-01-01') TO ('2026-01-01');
-- Monthly partitions for 2026
CREATE TABLE journal_posting_logs_2026_01 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
CREATE TABLE journal_posting_logs_2026_02 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
CREATE TABLE journal_posting_logs_2026_03 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-03-01') TO ('2026-04-01');
CREATE TABLE journal_posting_logs_2026_04 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-04-01') TO ('2026-05-01');
CREATE TABLE journal_posting_logs_2026_05 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-05-01') TO ('2026-06-01');
CREATE TABLE journal_posting_logs_2026_06 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');
CREATE TABLE journal_posting_logs_2026_07 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE journal_posting_logs_2026_08 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE journal_posting_logs_2026_09 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE journal_posting_logs_2026_10 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE journal_posting_logs_2026_11 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');
CREATE TABLE journal_posting_logs_2026_12 PARTITION OF journal_posting_logs FOR VALUES FROM ('2026-12-01') TO ('2027-01-01');
-- Default partition for out-of-range dates
CREATE TABLE journal_posting_logs_default PARTITION OF journal_posting_logs DEFAULT;

CREATE INDEX idx_jpl_entry ON journal_posting_logs(journal_entry_id);
CREATE INDEX idx_jpl_performed_at_brin ON journal_posting_logs USING BRIN (performed_at) WITH (pages_per_range = 64);

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
    transaction_date date NOT NULL,
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    reference_type varchar(50) NOT NULL DEFAULT 'adjustment',
    reference_id integer DEFAULT NULL,
    journal_entry_id integer REFERENCES journal_entries(id),
    debit numeric(12,2) DEFAULT 0,
    credit numeric(12,2) DEFAULT 0,
    running_balance numeric(12,2) DEFAULT NULL,
    remarks text,
    is_reversed boolean NOT NULL DEFAULT false,
    created_by integer DEFAULT NULL,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bl_branches ON branch_ledger(from_branch_id, to_branch_id);
CREATE INDEX idx_bl_reference ON branch_ledger(reference_type, reference_id);
CREATE INDEX idx_bl_date ON branch_ledger(transaction_date);
CREATE INDEX idx_bl_active ON branch_ledger(from_branch_id, to_branch_id, transaction_date) WHERE is_reversed = false;

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
    -- G-081 (WORKFLOWS-APPROVAL): expanded from ('draft','posted','reversed')
    -- to 6 states by migration 2026_08_10_000001 (approval workflow engine).
    -- draft → submitted → approved → posted (or rejected → draft resubmit).
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved','posted','reversed','rejected')),
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    reversed_by integer,
    reversed_at timestamp(0),
    reverse_reason varchar(500),
    -- G-081: 7 approval-workflow columns added by migration
    -- 2026_08_10_000001. submitted_by/at + approved_by/at + approval_comments
    -- + rejected_by/at track the maker-checker gate.
    submitted_by integer,
    submitted_at timestamp(0),
    approved_by integer,
    approved_at timestamp(0),
    approval_comments text,
    rejected_by integer,
    rejected_at timestamp(0),
    deleted_at timestamp(0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT manual_journals_code_unique UNIQUE (journal_code)
);
CREATE INDEX idx_mj_branch_date ON manual_journals(branch_id, journal_date);
CREATE INDEX idx_mj_journal ON manual_journals(journal_entry_id);
CREATE INDEX idx_mj_status ON manual_journals(status);
CREATE INDEX idx_mj_submitted ON manual_journals(branch_id, submitted_at) WHERE status = 'submitted';

-- Phase 1.1: manual_journal_lines table (draft line persistence)
CREATE TABLE manual_journal_lines (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    manual_journal_id integer NOT NULL REFERENCES manual_journals(id) ON DELETE CASCADE,
    ledger_id integer NOT NULL,
    debit numeric(15,2) NOT NULL DEFAULT 0,
    credit numeric(15,2) NOT NULL DEFAULT 0,
    description varchar(500),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted')),
    journal_line_id integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT mjl_debit_non_negative CHECK (debit >= 0),
    CONSTRAINT mjl_credit_non_negative CHECK (credit >= 0),
    CONSTRAINT mjl_not_both_zero CHECK (debit > 0 OR credit > 0)
);
CREATE INDEX idx_mjl_journal ON manual_journal_lines(manual_journal_id);
CREATE INDEX idx_mjl_journal_status ON manual_journal_lines(manual_journal_id, status);
CREATE INDEX idx_mjl_ledger ON manual_journal_lines(ledger_id);

-- Phase 1.3: Enable pgcrypto for digest() (SHA-256 hashing in audit trigger)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Phase 1.3: Immutable financial audit log
-- Phase 10.1: financial_audit_log is now partitioned by RANGE (created_at)
-- See migration 2026_08_02_000001_partition_audit_log_tables.php for the
-- conversion logic (rename → recreate → copy → drop old).
CREATE TABLE financial_audit_log (
    id              BIGINT GENERATED BY DEFAULT AS IDENTITY,
    table_name      VARCHAR(64) NOT NULL,
    operation       VARCHAR(6)  NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE')),
    record_id       BIGINT NOT NULL,
    before_data     JSONB,
    after_data      JSONB,
    changed_columns TEXT[],
    performed_by    VARCHAR(100),
    db_session_user VARCHAR(100),
    branch_id       INTEGER,
    transaction_id  XID,
    request_path    VARCHAR(500),
    request_ip      VARCHAR(45),
    request_id      VARCHAR(100),
    prev_hash       VARCHAR(64),
    row_hash        VARCHAR(64),
    created_at      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Pre-2026 catch-all partition for historical data
CREATE TABLE financial_audit_log_pre2026 PARTITION OF financial_audit_log
    FOR VALUES FROM ('2020-01-01') TO ('2026-01-01');
-- Monthly partitions for 2026
CREATE TABLE financial_audit_log_2026_01 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
CREATE TABLE financial_audit_log_2026_02 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
CREATE TABLE financial_audit_log_2026_03 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-03-01') TO ('2026-04-01');
CREATE TABLE financial_audit_log_2026_04 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-04-01') TO ('2026-05-01');
CREATE TABLE financial_audit_log_2026_05 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-05-01') TO ('2026-06-01');
CREATE TABLE financial_audit_log_2026_06 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');
CREATE TABLE financial_audit_log_2026_07 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE financial_audit_log_2026_08 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE financial_audit_log_2026_09 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE financial_audit_log_2026_10 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE financial_audit_log_2026_11 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');
CREATE TABLE financial_audit_log_2026_12 PARTITION OF financial_audit_log FOR VALUES FROM ('2026-12-01') TO ('2027-01-01');
-- Default partition for out-of-range dates
CREATE TABLE financial_audit_log_default PARTITION OF financial_audit_log DEFAULT;

CREATE INDEX idx_fal_table_record ON financial_audit_log(table_name, record_id);
CREATE INDEX idx_fal_operation ON financial_audit_log(operation);
CREATE INDEX idx_fal_performed_by ON financial_audit_log(performed_by);
CREATE INDEX idx_fal_branch ON financial_audit_log(branch_id);
CREATE INDEX idx_fal_table_op ON financial_audit_log(table_name, operation);
-- BRIN replaces B-tree on created_at for append-only partitioned table
CREATE INDEX idx_fal_created_at_brin ON financial_audit_log USING BRIN (created_at) WITH (pages_per_range = 32);

-- Phase 1.3: Audit trigger function
CREATE OR REPLACE FUNCTION fn_financial_audit_trigger()
RETURNS TRIGGER AS $$
DECLARE
    _prev_hash VARCHAR(64);
    _row_hash  VARCHAR(64);
    _before    JSONB;
    _after     JSONB;
    _changed   TEXT[];
    _col       TEXT;
    _op        VARCHAR(6);
    _record_id BIGINT;
    _branch_id INTEGER;
    _performed_by VARCHAR(100);
    _session_user VARCHAR(100);
    _request_path VARCHAR(500);
    _request_ip   VARCHAR(45);
    _request_id   VARCHAR(100);
    _xmin      XID;
BEGIN
    _op := TG_OP;
    IF _op = 'DELETE' THEN
        _record_id := OLD.id;
        _before := to_jsonb(OLD);
        _after := NULL;
        _changed := ARRAY[]::TEXT[];
        _xmin := OLD.xmin;
    ELSIF _op = 'INSERT' THEN
        _record_id := NEW.id;
        _before := NULL;
        _after := to_jsonb(NEW);
        _changed := ARRAY[]::TEXT[];
        _xmin := NEW.xmin;
    ELSE
        _record_id := NEW.id;
        _before := to_jsonb(OLD);
        _after := to_jsonb(NEW);
        _changed := ARRAY[]::TEXT[];
        FOR _col IN
            SELECT key FROM jsonb_object_keys(_before) AS key
            WHERE (_before->>key) IS DISTINCT FROM (_after->>key)
        LOOP
            _changed := array_append(_changed, _col);
        END LOOP;
        _xmin := NEW.xmin;
    END IF;
    -- Get branch_id from the JSONB representation (works for tables without branch_id column)
    _branch_id := COALESCE(
        (_after ->> 'branch_id')::INTEGER,
        (_before ->> 'branch_id')::INTEGER
    );
    _session_user := session_user;
    _performed_by := current_user;
    BEGIN _request_path := current_setting('app.request_path', true); EXCEPTION WHEN OTHERS THEN _request_path := NULL; END;
    BEGIN _request_ip := current_setting('app.request_ip', true); EXCEPTION WHEN OTHERS THEN _request_ip := NULL; END;
    BEGIN _request_id := current_setting('app.request_id', true); EXCEPTION WHEN OTHERS THEN _request_id := NULL; END;
    SELECT row_hash INTO _prev_hash FROM financial_audit_log ORDER BY id DESC LIMIT 1;
    IF _prev_hash IS NULL THEN _prev_hash := '0000000000000000000000000000000000000000000000000000000000000000'; END IF;
    _row_hash := encode(digest(_prev_hash || TG_TABLE_NAME || _op || _record_id::TEXT || COALESCE(_after::TEXT, _before::TEXT), 'sha256'), 'hex');
    INSERT INTO financial_audit_log (table_name, operation, record_id, before_data, after_data, changed_columns, performed_by, db_session_user, branch_id, transaction_id, request_path, request_ip, request_id, prev_hash, row_hash)
    VALUES (TG_TABLE_NAME, _op, _record_id, _before, _after, _changed, _performed_by, _session_user, _branch_id, _xmin, _request_path, _request_ip, _request_id, _prev_hash, _row_hash);
    IF _op = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Phase 1.3: Attach audit triggers to financial tables
CREATE TRIGGER trg_audit_journal_entries AFTER INSERT OR UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_journal_lines AFTER INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_manual_journals AFTER INSERT OR UPDATE OR DELETE ON manual_journals FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_manual_journal_lines AFTER INSERT OR UPDATE OR DELETE ON manual_journal_lines FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_customer_payments AFTER INSERT OR UPDATE OR DELETE ON customer_payments FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_supplier_payments AFTER INSERT OR UPDATE OR DELETE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_money_transfers AFTER INSERT OR UPDATE OR DELETE ON money_transfers FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_other_incomes AFTER INSERT OR UPDATE OR DELETE ON other_incomes FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_other_expenses AFTER INSERT OR UPDATE OR DELETE ON other_expenses FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_employee_transactions AFTER INSERT OR UPDATE OR DELETE ON employee_transactions FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();

-- Phase 1.3: Make financial_audit_log immutable (no UPDATE/DELETE)
-- Role-safe: only revoke from roles that exist
DO $$ BEGIN
    EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC';
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'postgres') THEN
        EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM postgres';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'remote_center') THEN
        EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM remote_center';
    END IF;
END $$;

-- Phase 1.3: Chain verification view
CREATE OR REPLACE VIEW v_financial_audit_chain_verification AS
SELECT id, table_name, operation, record_id, prev_hash, row_hash,
    CASE WHEN id = 1 THEN prev_hash = '0000000000000000000000000000000000000000000000000000000000000000'
         ELSE prev_hash = LAG(row_hash) OVER (ORDER BY id) END AS chain_valid,
    created_at
FROM financial_audit_log ORDER BY id;

CREATE TABLE schema_migrations (
    filename varchar(255) NOT NULL PRIMARY KEY,
    applied_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
