-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 4: Sales
-- ============================================================

CREATE TABLE sales_invoices (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_code varchar(30) NOT NULL,
    invoice_date date NOT NULL,
    customer_id integer NOT NULL,
    salesman_id integer,
    sales_person varchar(100),
    branch_id integer NOT NULL,
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    paid_amount numeric(14,2) DEFAULT 0,
    due_amount numeric(14,2) DEFAULT 0,
    payment_mode varchar(20) DEFAULT 'cash' CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled','reversed')),
    -- Sales workflow: draft → confirmed (godown prep) → challan issued (stock out) → paid
    is_godown_prepared boolean NOT NULL DEFAULT false,
    godown_prepared_at timestamp(0),
    is_challan_issued boolean NOT NULL DEFAULT false,
    challan_issued_at timestamp(0),
    journal_entry_id integer REFERENCES journal_entries(id),
    cogs_journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    is_soft_hold boolean NOT NULL DEFAULT false,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    -- FIX: MySQL had updated_at as DATE (not datetime). PG: timestamp.
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    CONSTRAINT sales_invoices_code_unique UNIQUE (invoice_code)
);
-- FIX: MySQL was missing these indexes — added for PG performance.
CREATE INDEX idx_si_customer ON sales_invoices(customer_id);
CREATE INDEX idx_si_invoice_date ON sales_invoices(invoice_date);
CREATE INDEX idx_si_salesman ON sales_invoices(salesman_id);
CREATE INDEX idx_si_branch ON sales_invoices(branch_id);
CREATE INDEX idx_si_journal ON sales_invoices(journal_entry_id);
CREATE INDEX idx_si_status ON sales_invoices(status);

CREATE TABLE sales_invoice_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_invoice_id integer NOT NULL REFERENCES sales_invoices(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id) ON DELETE SET NULL,
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    -- FIX: MySQL was missing `amount` column (computed in app). PG: GENERATED STORED.
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    discount_amount numeric(14,2) DEFAULT 0,
    condition_state varchar(10) DEFAULT 'Good' CHECK (condition_state IN ('Good','Damage'))
);
CREATE INDEX idx_sii_invoice ON sales_invoice_items(sales_invoice_id);
CREATE INDEX idx_sii_product ON sales_invoice_items(product_id);
CREATE INDEX idx_sii_warehouse ON sales_invoice_items(warehouse_id);

CREATE TABLE sales_invoice_dispatchers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_invoice_id integer NOT NULL REFERENCES sales_invoices(id) ON DELETE CASCADE,
    employee_id integer NOT NULL REFERENCES employees(id),
    dispatch_role varchar(30) DEFAULT 'dispatcher'
);
CREATE INDEX idx_sid_invoice ON sales_invoice_dispatchers(sales_invoice_id);

CREATE TABLE sales_invoice_dispatches (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_invoice_id integer NOT NULL REFERENCES sales_invoices(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0,
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    dispatch_date date,
    CONSTRAINT unique_invoice_product UNIQUE (sales_invoice_id, product_id)
);
CREATE INDEX idx_sdis_invoice ON sales_invoice_dispatches(sales_invoice_id);
CREATE INDEX idx_sdis_warehouse ON sales_invoice_dispatches(warehouse_id);

CREATE TABLE sales_challans (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    challan_code varchar(30) NOT NULL,
    challan_date date NOT NULL,
    sales_invoice_id integer NOT NULL REFERENCES sales_invoices(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    transport_name varchar(100),
    transport_phone varchar(30),
    vehicle_number varchar(50),
    driver_name varchar(100),
    transport_cost numeric(12,2) DEFAULT 0,
    transport_adjustment numeric(12,2) DEFAULT 0,
    adjustment_journal_entry_id integer REFERENCES journal_entries(id),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    issue_cost numeric(14,2) DEFAULT 0,
    is_dispatch_soft_hold boolean NOT NULL DEFAULT false,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sales_challans_code_unique UNIQUE (challan_code)
);
CREATE INDEX idx_sc_invoice ON sales_challans(sales_invoice_id);
CREATE INDEX idx_sc_journal ON sales_challans(journal_entry_id);
CREATE INDEX idx_sc_adj_journal ON sales_challans(adjustment_journal_entry_id);

CREATE TABLE sales_draft_carts (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    branch_id integer REFERENCES branches(id),
    customer_id integer,
    items_json jsonb,
    is_soft_hold boolean NOT NULL DEFAULT false,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sales_draft_user_customer UNIQUE (user_id, customer_id)
);
CREATE INDEX idx_sdc_branch ON sales_draft_carts(branch_id);
CREATE INDEX idx_sdc_updated ON sales_draft_carts(updated_at);

CREATE TABLE sales_returns (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    return_code varchar(30) NOT NULL,
    return_date date NOT NULL,
    sales_invoice_id integer NOT NULL REFERENCES sales_invoices(id),
    customer_id integer NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    total_amount numeric(14,2) DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'created' CHECK (status IN ('created','confirmed','reversed')),
    journal_entry_id integer REFERENCES journal_entries(id),
    cogs_journal_entry_id integer REFERENCES journal_entries(id),
    -- CRITICAL: sales return posts stock IN at ORIGINAL avg cost (not current).
    -- The original cost is snapshotted from the challan's stock-out transaction.
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sales_returns_code_unique UNIQUE (return_code)
);
CREATE INDEX idx_sr_invoice ON sales_returns(sales_invoice_id);
CREATE INDEX idx_sr_journal ON sales_returns(journal_entry_id);

CREATE TABLE sales_return_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_return_id integer NOT NULL REFERENCES sales_returns(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    -- FIX: MySQL was missing `amount`. PG: GENERATED STORED.
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    condition_state varchar(10) DEFAULT 'Good' CHECK (condition_state IN ('Good','Damage')),
    original_cost numeric(12,2) DEFAULT 0  -- snapshot of avg cost at time of original challan
);
CREATE INDEX idx_sri_return ON sales_return_items(sales_return_id);
