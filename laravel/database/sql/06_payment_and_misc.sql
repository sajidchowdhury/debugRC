-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 6: Payments + Misc
-- ============================================================

-- ===================== CUSTOMER PAYMENTS =====================
CREATE TABLE customer_payments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_code varchar(30) NOT NULL,
    payment_date date NOT NULL,
    customer_id integer NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    payment_mode varchar(20) NOT NULL CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    journal_entry_id integer REFERENCES journal_entries(id),
    -- Bank-mode payments trigger intercompany settlement.
    intercompany_journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT customer_payments_code_unique UNIQUE (payment_code)
);
CREATE INDEX idx_cp_customer ON customer_payments(customer_id);
CREATE INDEX idx_cp_bank ON customer_payments(bank_id);
CREATE INDEX idx_cp_branch ON customer_payments(branch_id);
CREATE INDEX idx_cp_journal ON customer_payments(journal_entry_id);

CREATE TABLE customer_payment_settlements (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id integer NOT NULL REFERENCES customer_payments(id) ON DELETE CASCADE,
    invoice_id integer NOT NULL REFERENCES sales_invoices(id),
    settled_amount numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_cps_payment ON customer_payment_settlements(payment_id);
CREATE INDEX idx_cps_invoice ON customer_payment_settlements(invoice_id);

-- ===================== SUPPLIER PAYMENTS =====================
CREATE TABLE supplier_payments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_code varchar(30) NOT NULL,
    payment_date date NOT NULL,
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    payment_mode varchar(20) NOT NULL CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    collected_by integer REFERENCES employees(id),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT supplier_payments_code_unique UNIQUE (payment_code)
);
CREATE INDEX idx_sp_supplier ON supplier_payments(supplier_id);
CREATE INDEX idx_sp_bank ON supplier_payments(bank_id);
CREATE INDEX idx_sp_branch ON supplier_payments(branch_id);
CREATE INDEX idx_sp_collected_by ON supplier_payments(collected_by);
CREATE INDEX idx_sp_journal ON supplier_payments(journal_entry_id);

CREATE TABLE supplier_payment_settlements (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id integer NOT NULL REFERENCES supplier_payments(id) ON DELETE CASCADE,
    purchase_receive_id integer NOT NULL REFERENCES purchase_receives(id),
    settled_amount numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sps_payment ON supplier_payment_settlements(payment_id);
CREATE INDEX idx_sps_receive ON supplier_payment_settlements(purchase_receive_id);

-- ===================== MONEY TRANSFERS =====================
CREATE TABLE money_transfers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_code varchar(30) NOT NULL,
    transfer_date date NOT NULL,
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    transfer_type varchar(20) NOT NULL CHECK (transfer_type IN ('cash_to_bank','bank_to_cash','cash_to_cash','bank_to_bank')),
    from_bank_id integer REFERENCES banks(id),
    to_bank_id integer REFERENCES banks(id),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    journal_entry_id integer REFERENCES journal_entries(id),
    intercompany_journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT money_transfers_code_unique UNIQUE (transfer_code)
);
CREATE INDEX idx_mt_branches ON money_transfers(from_branch_id, to_branch_id);
CREATE INDEX idx_mt_journal ON money_transfers(journal_entry_id);

-- ===================== OTHER INCOME / EXPENSE =====================
CREATE TABLE other_incomes (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    income_code varchar(30) NOT NULL,
    income_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    income_type varchar(50),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT other_incomes_code_unique UNIQUE (income_code)
);
CREATE INDEX idx_oi_journal ON other_incomes(journal_entry_id);

CREATE TABLE other_expenses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    expense_code varchar(30) NOT NULL,
    expense_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    expense_type varchar(50),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT other_expenses_code_unique UNIQUE (expense_code)
);
CREATE INDEX idx_oe_journal ON other_expenses(journal_entry_id);

-- ===================== EMPLOYEE TRANSACTIONS =====================
CREATE TABLE employee_transactions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_code varchar(30) NOT NULL,
    transaction_date date NOT NULL,
    employee_id integer NOT NULL REFERENCES employees(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    transaction_type varchar(20) NOT NULL CHECK (transaction_type IN ('advance','loan','repayment','salary','deduction','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT employee_transactions_code_unique UNIQUE (transaction_code)
);
CREATE INDEX idx_et_employee ON employee_transactions(employee_id);
CREATE INDEX idx_et_journal ON employee_transactions(journal_entry_id);

-- ===================== NOTIFICATIONS =====================
CREATE TABLE notifications (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title varchar(200) NOT NULL,
    body text,
    type varchar(50),
    reference_type varchar(30),
    reference_id integer,
    is_read boolean NOT NULL DEFAULT false,
    read_at timestamp(0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_notif_user ON notifications(user_id);
CREATE INDEX idx_notif_is_read ON notifications(is_read);

-- ===================== INVESTIGATION MODE =====================
-- Phase 11 will simplify this to a simple admin toggle (no QR, no OTP).
-- For now, the table is included for data migration compatibility.
CREATE TABLE investigation_activators (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL,
    label varchar(100),
    is_active boolean NOT NULL DEFAULT false,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_inv_activator_user UNIQUE (user_id)
);

-- ===================== LOGIN RATE LIMITS =====================
CREATE TABLE login_rate_limits (
    bucket_key varchar(255) PRIMARY KEY,
    attempt_count integer NOT NULL DEFAULT 0,
    reset_at timestamp(0) NOT NULL,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);

-- ===================== USER AUDIT LOG =====================
CREATE TABLE user_audit_log (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer,
    action varchar(50) NOT NULL,
    target_user_id integer,
    branch_id integer,
    details jsonb,
    ip_address varchar(45),
    user_agent text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ual_user ON user_audit_log(user_id);
CREATE INDEX idx_ual_action ON user_audit_log(action);
CREATE INDEX idx_ual_created ON user_audit_log(created_at);
