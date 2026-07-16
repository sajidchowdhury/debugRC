-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 5: Purchase
-- ============================================================

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
    total_amount numeric(14,2) DEFAULT 0,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
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

CREATE TABLE purchase_return_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_return_id integer NOT NULL REFERENCES purchase_returns(id) ON DELETE CASCADE,
    purchase_receive_item_id integer REFERENCES purchase_receive_items(id),
    product_id integer NOT NULL REFERENCES products(id),
    warehouse_id integer REFERENCES warehouses(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) NOT NULL DEFAULT 0,
    amount numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED
);
CREATE INDEX idx_prti_return ON purchase_return_items(purchase_return_id);
CREATE INDEX idx_prti_poitem ON purchase_return_items(purchase_receive_item_id);
CREATE INDEX idx_prti_product ON purchase_return_items(product_id);
CREATE INDEX idx_prti_warehouse ON purchase_return_items(warehouse_id);

CREATE TABLE invoice_payment_allocations (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id integer NOT NULL REFERENCES sales_invoices(id),
    payment_id integer NOT NULL,
    allocated_amount numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ipa_invoice ON invoice_payment_allocations(invoice_id);
CREATE INDEX idx_ipa_payment ON invoice_payment_allocations(payment_id);
