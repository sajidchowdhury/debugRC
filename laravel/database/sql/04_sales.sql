-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 4: Sales
-- ============================================================
-- NOTE: sales_invoices is PARTITION BY RANGE (invoice_date) as of Task 34.
-- See migration 2025_01_21_000004 for the partitioned CREATE TABLE + monthly partitions.
-- Declarative FKs from child tables are replaced with trigger-based enforcement
-- (fn_fk_si_check + fn_fk_si_cascade_delete) because PG 12-17 does not support
-- FK references TO partitioned tables.
--
-- DEFERRABLE FKs (Task 35, migration 2025_01_21_000005):
--   All declarative FKs in this file are configured DEFERRABLE:
--   - INITIALLY DEFERRED: FKs referencing journal_entries, customers, branches
--     (parent often created in same transaction)
--   - INITIALLY IMMEDIATE: FKs referencing products, warehouses, employees
--     (parent always pre-exists)
--   The DEFERRABLE clause is applied via ALTER CONSTRAINT in the migration,
--   not inline in CREATE TABLE, for backward compatibility.
--
-- COMMISSION TRACKING (Task 37, migration 2025_01_22_000001):
--   commission_rules, commission_rule_tiers, commission_rule_product_groups,
--   commission_rule_targets, commission_entries tables added.
--   Commission is calculated on payment allocation, not on invoice creation.
--   FK from commission_entries → sales_invoices (partitioned) is trigger-based
--   (trg_fk_ce_si), matching the pattern established in Task 34.

CREATE TABLE sales_invoices (
    id integer GENERATED ALWAYS AS IDENTITY,
    invoice_code varchar(30) NOT NULL,
    invoice_date date NOT NULL,
    customer_id integer NOT NULL,
    salesman_id integer,
    sales_person varchar(100),
    branch_id integer NOT NULL,
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    transport_cost numeric(12,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    pre_challan_transport numeric(12,2),
    pre_challan_total numeric(14,2),
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
    call_a_day boolean NOT NULL DEFAULT false,  -- G-10: Remove from daily collection list (Sales Today view)
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    -- FIX: MySQL had updated_at as DATE (not datetime). PG: timestamp.
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    deleted_by integer,
    PRIMARY KEY (id, invoice_date),
    CONSTRAINT sales_invoices_code_unique UNIQUE (invoice_code, invoice_date)
) PARTITION BY RANGE (invoice_date);

-- Monthly partitions (pg_partman auto-creates future months)
-- Example: CREATE TABLE sales_invoices_2025_01 PARTITION OF sales_invoices
--   FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');
-- Default partition catches out-of-range dates:
--   CREATE TABLE sales_invoices_default PARTITION OF sales_invoices DEFAULT;

-- Indexes (include invoice_date for partition pruning)
CREATE INDEX idx_si_customer ON sales_invoices(customer_id, invoice_date);
CREATE INDEX idx_si_invoice_date ON sales_invoices(invoice_date);
CREATE INDEX idx_si_salesman ON sales_invoices(salesman_id);
CREATE INDEX idx_si_branch ON sales_invoices(branch_id, invoice_date);
CREATE INDEX idx_si_journal ON sales_invoices(journal_entry_id);
CREATE INDEX idx_si_status ON sales_invoices(status);

-- Child tables reference sales_invoices via trigger-based FK enforcement
-- (declarative FK → partitioned table not supported in PG 12-17)

CREATE TABLE sales_invoice_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_invoice_id integer NOT NULL,  -- FK enforced by trg_fk_sii_si (trigger-based, see migration 2025_01_21_000004)
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
    sales_invoice_id integer NOT NULL,  -- FK enforced by trg_fk_sid_si (trigger-based)
    employee_id integer NOT NULL REFERENCES employees(id),
    dispatch_role varchar(30) DEFAULT 'dispatcher'
);
CREATE INDEX idx_sid_invoice ON sales_invoice_dispatchers(sales_invoice_id);

CREATE TABLE sales_invoice_dispatches (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_invoice_id integer NOT NULL,  -- FK enforced by trg_fk_sdis_si (trigger-based)
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
    sales_invoice_id integer NOT NULL,  -- FK enforced by trg_fk_sc_si (trigger-based)
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
    sales_invoice_id integer NOT NULL,  -- FK enforced by trg_fk_sr_si (trigger-based)
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

-- ============================================================
-- COMMISSION TRACKING (Task 37)
-- ============================================================
-- Commission is calculated on PAYMENT allocation, not invoice creation.
-- This ensures salesmen only earn commission on collected revenue.
--
-- Four rule types:
--   flat:          Single % rate on allocated amount
--   tiered:        Progressive rates based on cumulative sales
--   product_group: Different % per product group
--   target_bonus:  Base rate + bonus when sales target exceeded
--
-- Status workflow for commission_entries:
--   calculated → confirmed (month-end batch, GL posted) → paid
--   Any status → reversed (underlying transaction reversed)
-- ============================================================

CREATE TABLE commission_rules (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    salesman_id integer NOT NULL REFERENCES employees(id),
    rule_type varchar(20) NOT NULL CHECK (rule_type IN ('flat','tiered','product_group','target_bonus')),
    rate numeric(8,4) NOT NULL DEFAULT 0,
    -- For 'flat': single rate. For 'tiered': base rate. For 'product_group': default rate.
    -- For 'target_bonus': base rate.
    effective_from date NOT NULL DEFAULT CURRENT_DATE,
    effective_to date,
    is_active boolean NOT NULL DEFAULT true,
    branch_id integer REFERENCES branches(id),
    -- NULL = applies to all branches; specific = branch-specific rate
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT commission_rules_unique_active EXCLUDE (
        salesman_id WITH =,
        gist(
            CASE WHEN is_active AND effective_to IS NULL
                 THEN daterange(effective_from, NULL, '[)')
                 ELSE daterange(NULL, NULL, '[]')
            END WITH &&
        )
    ) WHERE (is_active AND effective_to IS NULL)
);
CREATE INDEX idx_cr_salesman ON commission_rules(salesman_id, is_active, effective_from);
CREATE INDEX idx_cr_branch ON commission_rules(branch_id);

CREATE TABLE commission_rule_tiers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
    threshold numeric(14,2) NOT NULL DEFAULT 0,
    rate numeric(8,4) NOT NULL DEFAULT 0,
    sort_order integer NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT commission_rule_tiers_threshold_unique UNIQUE (commission_rule_id, threshold)
);
CREATE INDEX idx_crt_rule ON commission_rule_tiers(commission_rule_id);

CREATE TABLE commission_rule_product_groups (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
    product_group_id integer NOT NULL REFERENCES product_groups(id) ON DELETE CASCADE,
    rate numeric(8,4) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT commission_rule_pg_unique UNIQUE (commission_rule_id, product_group_id)
);
CREATE INDEX idx_crpg_rule ON commission_rule_product_groups(commission_rule_id);
CREATE INDEX idx_crpg_group ON commission_rule_product_groups(product_group_id);

CREATE TABLE commission_rule_targets (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    commission_rule_id integer NOT NULL REFERENCES commission_rules(id) ON DELETE CASCADE,
    target_amount numeric(14,2) NOT NULL DEFAULT 0,
    bonus_rate numeric(8,4) NOT NULL DEFAULT 0,
    period varchar(10) NOT NULL DEFAULT 'monthly' CHECK (period IN ('monthly','quarterly','yearly')),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT commission_rule_targets_rule_unique UNIQUE (commission_rule_id, period)
);
CREATE INDEX idx_cxrt_rule ON commission_rule_targets(commission_rule_id);

CREATE TABLE commission_entries (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    salesman_id integer NOT NULL REFERENCES employees(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    sales_invoice_id integer,
    -- FK to sales_invoices (partitioned) — enforced by trigger trg_fk_ce_si
    commission_rule_id integer NOT NULL REFERENCES commission_rules(id),
    allocation_id integer REFERENCES invoice_payment_allocations(id) ON DELETE SET NULL,
    sales_return_id integer REFERENCES sales_returns(id) ON DELETE SET NULL,
    invoice_total numeric(14,2) DEFAULT 0,
    commission_base numeric(14,2) DEFAULT 0,
    commission_rate numeric(8,4) DEFAULT 0,
    commission_amount numeric(14,2) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'calculated'
        CHECK (status IN ('calculated','confirmed','paid','reversed')),
    entry_date date NOT NULL DEFAULT CURRENT_DATE,
    journal_entry_id integer REFERENCES journal_entries(id),
    reversed_by_entry_id integer REFERENCES commission_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    commission_period varchar(7),
    -- Format: '2025-01' — set by trigger fn_ce_set_period
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ce_salesman ON commission_entries(salesman_id, entry_date);
CREATE INDEX idx_ce_branch ON commission_entries(branch_id, entry_date);
CREATE INDEX idx_ce_invoice ON commission_entries(sales_invoice_id);
CREATE INDEX idx_ce_allocation ON commission_entries(allocation_id);
CREATE INDEX idx_ce_return ON commission_entries(sales_return_id);
CREATE INDEX idx_ce_rule ON commission_entries(commission_rule_id);
CREATE INDEX idx_ce_status ON commission_entries(status);
CREATE INDEX idx_ce_period ON commission_entries(commission_period, salesman_id);
CREATE INDEX idx_ce_journal ON commission_entries(journal_entry_id);
