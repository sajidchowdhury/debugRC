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
--
-- SALES-4 (2026-09-01): DDL refreshed to match the live schema.
-- Previously this file was stale — 8 columns and the entire sales_challan_items
-- table existed ONLY in migrations (gap G5 CRITICAL). The DDL now includes:
--   - sales_invoices: is_blank_godown_printed + blank_godown_printed_at/by
--   - sales_invoice_dispatches: ordered_qty, dispatched_qty, dispatched_ctn, created_by
--   - sales_challan_items: full CREATE TABLE (was missing entirely)
--   - sales_returns: cogs_amount, reason
--   - sales_return_items: sales_invoice_item_id, damage_invoice_id
-- Plus the associated indexes (idx_sci_*, idx_sdis_pipeline, idx_sdis_product_warehouse,
-- idx_sri_invoice_item, idx_sri_damage_invoice, idx_si_call_a_day_active).
-- All migrations that add these columns are idempotent (Schema::hasColumn guards),
-- so they remain no-ops on a fresh install where the DDL already has the columns.

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
    -- Phase 4.2: blank godown print tracking (3-step godown workflow)
    is_blank_godown_printed boolean NOT NULL DEFAULT false,
    blank_godown_printed_at timestamp(0),
    blank_godown_printed_by integer,
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
--   FOR VALUES FROM ('2025-01-01') TO ('2025-02-01')
-- Default partition catches out-of-range dates:
--   CREATE TABLE sales_invoices_default PARTITION OF sales_invoices DEFAULT

-- Indexes (include invoice_date for partition pruning)
CREATE INDEX idx_si_customer ON sales_invoices(customer_id, invoice_date);
CREATE INDEX idx_si_invoice_date ON sales_invoices(invoice_date);
CREATE INDEX idx_si_salesman ON sales_invoices(salesman_id);
CREATE INDEX idx_si_branch ON sales_invoices(branch_id, invoice_date);
CREATE INDEX idx_si_journal ON sales_invoices(journal_entry_id);
CREATE INDEX idx_si_status ON sales_invoices(status);
-- Partial index: only rows where call_a_day = false (the default Today Invoice
-- view filters by call_a_day = false across ALL scopes/chips).
CREATE INDEX idx_si_call_a_day_active ON sales_invoices(call_a_day) WHERE call_a_day = false;

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
    -- ordered/dispatched distinction is CRITICAL for stock availability:
    --   available = physical_qty - SUM(ordered_qty - dispatched_qty)
    --               WHERE ordered_qty > dispatched_qty
    ordered_qty numeric(14,4) NOT NULL DEFAULT 0,
    dispatched_qty numeric(14,4) NOT NULL DEFAULT 0,
    dispatched_ctn numeric(14,4) NOT NULL DEFAULT 0,  -- Phase 4: carton-packing count (annotation, not a quantity)
    rate numeric(12,2) DEFAULT 0,
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    dispatch_date date,
    created_by integer,
    CONSTRAINT unique_invoice_product UNIQUE (sales_invoice_id, product_id)
);
CREATE INDEX idx_sdis_invoice ON sales_invoice_dispatches(sales_invoice_id);
CREATE INDEX idx_sdis_warehouse ON sales_invoice_dispatches(warehouse_id);
-- Partial index: only open pipeline rows (dispatched < ordered).
CREATE INDEX idx_sdis_pipeline ON sales_invoice_dispatches(sales_invoice_id, product_id) WHERE dispatched_qty < ordered_qty;
-- Composite covering index for the batched pipeline-qty query in
-- StockAvailabilityService::getBranchWarehouseBreakdownForProducts().
CREATE INDEX idx_sdis_product_warehouse ON sales_invoice_dispatches(product_id, warehouse_id) INCLUDE (ordered_qty, dispatched_qty, sales_invoice_id);

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

-- ============================================================
-- sales_challan_items (P0-5, migration 2025_01_08_000005)
-- ============================================================
-- Per-line issue-cost SSOT. Each challan line stores the avg_cost used at
-- the moment of stock OUT, so that:
--   (a) Challan reversal can restore inventory at the ORIGINAL per-line
--       issue_rate (not the current avg_cost, which may have drifted).
--   (b) GrossMarginReport can JOIN per-line to break down COGS by
--       product / warehouse.
-- Append-only snapshots — NO updated_at column (lines are never updated).
CREATE TABLE sales_challan_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_challan_id integer NOT NULL REFERENCES sales_challans(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    warehouse_id integer NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT,
    qty numeric(14,4) NOT NULL,              -- positive (items issued OUT)
    issue_rate numeric(12,2) DEFAULT 0,      -- avg_cost snapshot at challan issue
    cogs_amount numeric(14,2) DEFAULT 0,    -- qty × issue_rate (denormalized for reporting)
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sci_challan ON sales_challan_items(sales_challan_id);
CREATE INDEX idx_sci_product ON sales_challan_items(product_id);
CREATE INDEX idx_sci_wh ON sales_challan_items(warehouse_id);

CREATE TABLE sales_draft_carts (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- branch_id is NOT NULL DEFAULT 0 (Legacy semantics): 0 = "no specific branch".
    -- R6: branch_id is part of the unique key (uq_sales_draft_user_customer_branch)
    -- so a salesman switching branches with the same customer gets a fresh cart per
    -- branch — prevents cross-branch cart contamination (audit risks V11, C7).
    branch_id integer NOT NULL DEFAULT 0,
    customer_id integer,
    items_json jsonb,
    is_soft_hold boolean NOT NULL DEFAULT false,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_sales_draft_user_customer_branch UNIQUE (user_id, customer_id, branch_id)
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
    cogs_amount numeric(14,2) DEFAULT 0,  -- total COGS to reverse (snapshot)
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
    reason text,  -- user-supplied rationale for the return (distinct from notes)
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
    sales_invoice_item_id integer REFERENCES sales_invoice_items(id) ON DELETE SET NULL,  -- returnable-qty cap + original_cost lookup
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    -- FIX: MySQL was missing `amount`. PG: GENERATED STORED.
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    condition_state varchar(10) DEFAULT 'Good' CHECK (condition_state IN ('Good','Damage')),
    original_cost numeric(12,2) DEFAULT 0,  -- snapshot of avg cost at time of original challan
    damage_invoice_id integer REFERENCES damage_invoices(id) ON DELETE SET NULL  -- P1-5 linked damage write-off
);
CREATE INDEX idx_sri_return ON sales_return_items(sales_return_id);
CREATE INDEX idx_sri_invoice_item ON sales_return_items(sales_invoice_item_id) WHERE sales_invoice_item_id IS NOT NULL;
CREATE INDEX idx_sri_damage_invoice ON sales_return_items(damage_invoice_id) WHERE damage_invoice_id IS NOT NULL;

-- ============================================================
-- COMMISSION TRACKING (Task 37)
-- ============================================================
-- Commission tracking tables (commission_rules, commission_rule_tiers,
-- commission_rule_product_groups, commission_rule_targets, commission_entries)
-- are created by migration 2025_01_22_000001_create_commission_tracking.php.
--
-- They are NOT defined here because:
--   1. commission_entries.allocation_id REFERENCES invoice_payment_allocations(id),
--      which is created in 05_purchase.sql (runs AFTER 04_sales.sql). Defining
--      the FK inline here would cause a "relation does not exist" error.
--   2. The dedicated migration runs AFTER 05_purchase.sql, so the FK can be
--      created inline there without issue.
--   3. The dedicated migration also installs the trigger-based FK
--      (trg_fk_ce_si) for commission_entries → sales_invoices (partitioned),
--      following the same pattern as other child tables of sales_invoices.
