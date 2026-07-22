-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 5: Purchase
-- ============================================================
--
-- DEFERRABLE FKs (Task 35, migration 2025_01_21_000005):
--   All declarative FKs in this file are configured DEFERRABLE:
--   - INITIALLY DEFERRED: FKs referencing journal_entries, branches, warehouses,
--     suppliers, purchase_orders, purchase_receives
--   - INITIALLY IMMEDIATE: FKs referencing products

CREATE TABLE purchase_orders (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_code varchar(30) NOT NULL,
    po_date date NOT NULL,
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    warehouse_id integer REFERENCES warehouses(id),
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sent','partial','received','cancelled')),
    expected_date date,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT purchase_orders_code_unique UNIQUE (po_code)
);
CREATE INDEX idx_po_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_po_branch ON purchase_orders(branch_id);

CREATE TABLE purchase_order_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id integer NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    received_qty numeric(14,4) DEFAULT 0,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    -- PG GENERATED STORED (same syntax as MySQL).
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED
);
CREATE INDEX idx_poi_po ON purchase_order_items(purchase_order_id);
CREATE INDEX idx_poi_product ON purchase_order_items(product_id);

CREATE TABLE purchase_receives (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    receive_code varchar(30) NOT NULL,
    receive_date date NOT NULL,
    purchase_order_id integer REFERENCES purchase_orders(id),
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT purchase_receives_code_unique UNIQUE (receive_code)
);
CREATE INDEX idx_pr_po ON purchase_receives(purchase_order_id);
CREATE INDEX idx_pr_supplier ON purchase_receives(supplier_id);
CREATE INDEX idx_pr_branch ON purchase_receives(branch_id);
CREATE INDEX idx_pr_journal ON purchase_receives(journal_entry_id);
CREATE INDEX idx_pr_reversed ON purchase_receives(is_reversed, reversed_at);
CREATE INDEX idx_pr_status ON purchase_receives(status);

CREATE TABLE purchase_receive_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_receive_id integer NOT NULL REFERENCES purchase_receives(id) ON DELETE CASCADE,
    purchase_order_item_id integer REFERENCES purchase_order_items(id),
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    return_qty numeric(14,4) DEFAULT 0,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED
);
CREATE INDEX idx_pri_receive ON purchase_receive_items(purchase_receive_id);
CREATE INDEX idx_pri_poitem ON purchase_receive_items(purchase_order_item_id);
CREATE INDEX idx_pri_product ON purchase_receive_items(product_id);
CREATE INDEX idx_pri_warehouse ON purchase_receive_items(warehouse_id);

CREATE TABLE purchase_returns (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    return_code varchar(30) NOT NULL,
    return_date date NOT NULL,
    purchase_receive_id integer NOT NULL REFERENCES purchase_receives(id),
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT purchase_returns_code_unique UNIQUE (return_code)
);
CREATE INDEX idx_prtn_receive ON purchase_returns(purchase_receive_id);
CREATE INDEX idx_prtn_supplier ON purchase_returns(supplier_id);
CREATE INDEX idx_prtn_branch ON purchase_returns(branch_id);
CREATE INDEX idx_prtn_journal ON purchase_returns(journal_entry_id);
CREATE INDEX idx_prtn_reversed ON purchase_returns(is_reversed, reversed_at);
CREATE INDEX idx_prtn_status ON purchase_returns(status);

CREATE TABLE purchase_return_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_return_id integer NOT NULL REFERENCES purchase_returns(id) ON DELETE CASCADE,
    purchase_receive_item_id integer REFERENCES purchase_receive_items(id),
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    -- Phase 5: Damage condition. Good = stock OUT + GL + supplier_ledger.
    -- Damage = supplier claim only (no stock movement, GL + ledger still posted).
    condition varchar(10) NOT NULL DEFAULT 'Good'
        CHECK (condition IN ('Good','Damage'))
);
CREATE INDEX idx_prti_return ON purchase_return_items(purchase_return_id);
CREATE INDEX idx_prti_poitem ON purchase_return_items(purchase_receive_item_id);
CREATE INDEX idx_prti_product ON purchase_return_items(product_id);
CREATE INDEX idx_prti_warehouse ON purchase_return_items(warehouse_id);
CREATE INDEX idx_prti_condition ON purchase_return_items(condition);

CREATE TABLE invoice_payment_allocations (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- FK to sales_invoices (partitioned) is enforced by trigger trg_fk_ipa_si
    -- (created in migration 2025_01_21_000004). PG 12-17 does not support
    -- declarative FK references TO a partitioned table.
    invoice_id integer NOT NULL,
    -- FK to customer_payments(id) is added by migration 2025_01_21_000003
    -- (ipa_payment_id_foreign, ON DELETE CASCADE). It cannot be inline here
    -- because customer_payments is created in 06_payment_and_misc.sql (runs AFTER).
    payment_id integer NOT NULL,
    allocated_amount numeric(14,2) NOT NULL DEFAULT 0 CHECK (allocated_amount > 0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
    -- EXCLUDE constraint (ipa_unique_invoice_payment) is added by migration
    -- 2025_01_21_000003, which first enables the btree_gist extension required
    -- for = operator on integers in GiST indexes.
);
CREATE INDEX idx_ipa_invoice ON invoice_payment_allocations(invoice_id);
CREATE INDEX idx_ipa_payment ON invoice_payment_allocations(payment_id);

-- Over-allocation trigger: prevents SUM(allocated_amount) > invoice total_amount.
-- See migration 2025_01_21_000003 for the full trigger + function definition.
-- Requires: CREATE EXTENSION IF NOT EXISTS btree_gist; (handled by 2025_01_21_000003)
