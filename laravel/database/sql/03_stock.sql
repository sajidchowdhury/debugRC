-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 3: Stock / Inventory
-- ============================================================

-- The inventory ledger — single source of truth for all stock movements.
-- NOTE: stock_transactions is PARTITION BY RANGE (transaction_date) as of Task 34.
-- See migration 2025_01_21_000004 for the partitioned CREATE TABLE + monthly partitions.
-- Self-referential FK (reversal_of_transaction_id) is enforced by trigger
-- trg_st_reversal_fk because PG 12-17 does not support FK references TO
-- partitioned tables.

CREATE TABLE stock_transactions (
    id integer GENERATED ALWAYS AS IDENTITY,
    transaction_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,  -- signed: negative = OUT
    rate numeric(12,2) NOT NULL DEFAULT 0,
    -- PG supports GENERATED ALWAYS AS (...) STORED — same syntax as MySQL.
    total_value numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    reference_type varchar(30) NOT NULL CHECK (reference_type IN (
        'purchase_receive','purchase_return','sales_challan','sales_return',
        'stock_adjustment','stock_take','warehouse_transfer','damage',
        'branch_demand','opening_balance'
    )),
    reference_id integer NOT NULL,
    branch_demand_item_id integer,
    notes text,
    is_reversed boolean DEFAULT false,
    reversal_of_transaction_id integer,  -- FK enforced by trg_st_reversal_fk (trigger-based)
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, transaction_date)
) PARTITION BY RANGE (transaction_date);

-- Monthly partitions (pg_partman auto-creates future months)
-- Example: CREATE TABLE stock_transactions_2025_01 PARTITION OF stock_transactions
--   FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');
-- Default partition catches out-of-range dates:
--   CREATE TABLE stock_transactions_default PARTITION OF stock_transactions DEFAULT;

-- Indexes (include transaction_date for partition pruning)
CREATE INDEX idx_st_date_warehouse ON stock_transactions(transaction_date, warehouse_id);
CREATE INDEX idx_st_product ON stock_transactions(product_id, transaction_date);
CREATE INDEX idx_st_reference ON stock_transactions(reference_type, reference_id);
CREATE INDEX idx_st_branch_demand ON stock_transactions(branch_demand_item_id);

-- warehouse_stock: COMPOSITE PRIMARY KEY (warehouse_id, product_id) — NO `id` column.
-- This is the current on-hand stock with moving-average cost.
CREATE TABLE warehouse_stock (
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL DEFAULT 0,
    avg_cost numeric(12,2) NOT NULL DEFAULT 0,
    -- FIX: MySQL had qty+total_value+avg_cost with a GENERATED avg_cost using MySQL IF().
    -- PG: CASE WHEN instead of IF().
    total_qty numeric(14,4) NOT NULL DEFAULT 0,
    total_value numeric(14,2) NOT NULL DEFAULT 0,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (warehouse_id, product_id)
);

-- Non-negative stock guard (replaces MySQL SIGNAL SQLSTATE trigger).
-- PG CHECK constraint + a trigger with a business-friendly error message.
ALTER TABLE warehouse_stock ADD CONSTRAINT ws_qty_nonnegative CHECK (qty >= -0.0001);

CREATE OR REPLACE FUNCTION prevent_negative_stock()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.qty < -0.0001 THEN
        RAISE EXCEPTION 'Warehouse stock cannot go negative for warehouse % product % (attempted qty: %)',
            NEW.warehouse_id, NEW.product_id, NEW.qty
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_warehouse_stock_no_negative_insert
BEFORE INSERT ON warehouse_stock
FOR EACH ROW EXECUTE FUNCTION prevent_negative_stock();

CREATE TRIGGER trg_warehouse_stock_no_negative_update
BEFORE UPDATE ON warehouse_stock
FOR EACH ROW EXECUTE FUNCTION prevent_negative_stock();

CREATE TABLE stock_adjustments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    adjustment_code varchar(30) NOT NULL,
    adjustment_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    adjustment_type varchar(20) NOT NULL CHECK (adjustment_type IN ('increase','decrease')),
    reason text,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_adjustments_code_unique UNIQUE (adjustment_code)
);
CREATE INDEX idx_sa_warehouse ON stock_adjustments(warehouse_id);
CREATE INDEX idx_sa_journal ON stock_adjustments(journal_entry_id);

CREATE TABLE stock_adjustment_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0,
    reason text
);
CREATE INDEX idx_sai_adjustment ON stock_adjustment_items(stock_adjustment_id);
CREATE INDEX idx_sai_product ON stock_adjustment_items(product_id);

CREATE TABLE stock_take_sessions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_code varchar(30) NOT NULL,
    session_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','counting','posted','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_take_session_code_unique UNIQUE (session_code)
);
CREATE INDEX idx_sts_branch ON stock_take_sessions(branch_id);

CREATE TABLE stock_take_warehouses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    status varchar(20) DEFAULT 'pending' CHECK (status IN ('pending','counting','completed'))
);
CREATE INDEX idx_stw_session ON stock_take_warehouses(stock_take_session_id);

CREATE TABLE stock_take_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    system_qty numeric(14,4) NOT NULL DEFAULT 0,
    physical_qty numeric(14,4) NOT NULL DEFAULT 0,
    -- PG GENERATED with subtraction (same syntax as MySQL minus the backticks).
    difference numeric(14,4) GENERATED ALWAYS AS (physical_qty - system_qty) STORED,
    rate numeric(12,2) DEFAULT 0,
    reason text,
    is_applied boolean NOT NULL DEFAULT false,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sti_session_wh_product UNIQUE (stock_take_session_id, warehouse_id, product_id)
);
CREATE INDEX idx_sti_session ON stock_take_items(stock_take_session_id);
CREATE INDEX idx_sti_warehouse_product ON stock_take_items(warehouse_id, product_id);

CREATE TABLE warehouse_transfers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_code varchar(30) NOT NULL,
    transfer_date date NOT NULL,
    from_warehouse_id integer NOT NULL REFERENCES warehouses(id),
    to_warehouse_id integer NOT NULL REFERENCES warehouses(id),
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    journal_entry_id_debtor integer REFERENCES journal_entries(id),
    is_interbranch boolean NOT NULL DEFAULT false,
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT warehouse_transfers_code_unique UNIQUE (transfer_code)
);
CREATE INDEX idx_wt_journal_from ON warehouse_transfers(journal_entry_id);
CREATE INDEX idx_wt_journal_to ON warehouse_transfers(journal_entry_id_debtor);

CREATE TABLE warehouse_transfer_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    warehouse_transfer_id integer NOT NULL REFERENCES warehouse_transfers(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0
);
CREATE INDEX idx_wti_transfer ON warehouse_transfer_items(warehouse_transfer_id);

CREATE TABLE damage_invoices (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    damage_code varchar(30) NOT NULL,
    damage_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    reason text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT damage_invoices_code_unique UNIQUE (damage_code)
);
CREATE INDEX idx_dmg_journal ON damage_invoices(journal_entry_id);

CREATE TABLE damage_invoice_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    damage_invoice_id integer NOT NULL REFERENCES damage_invoices(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0
);
CREATE INDEX idx_dii_damage ON damage_invoice_items(damage_invoice_id);

CREATE TABLE daily_warehouse_stock_summary (
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    summary_date date NOT NULL,
    opening_qty numeric(14,4) DEFAULT 0,
    in_qty numeric(14,4) DEFAULT 0,
    out_qty numeric(14,4) DEFAULT 0,
    closing_qty numeric(14,4) DEFAULT 0,
    avg_cost numeric(12,2) DEFAULT 0,
    PRIMARY KEY (warehouse_id, product_id, summary_date)
);
CREATE INDEX idx_dwss_date ON daily_warehouse_stock_summary(summary_date);

CREATE TABLE branch_demands (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    demand_code varchar(30) NOT NULL,
    demand_date date NOT NULL,
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','fulfilled','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT branch_demands_code_unique UNIQUE (demand_code)
);
CREATE INDEX idx_bd_branches ON branch_demands(from_branch_id, to_branch_id);

CREATE TABLE branch_demand_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    fulfilled_qty numeric(14,4) DEFAULT 0,
    rate numeric(12,2) DEFAULT 0,
    notes text
);
CREATE INDEX idx_bdi_demand ON branch_demand_items(branch_demand_id);
