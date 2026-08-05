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
    -- PURCHASING-API-2 (G-123/G-124): warehouse_id is now NOT NULL.
    -- Previously nullable (mismatch with purchase_receives.warehouse_id
    -- which is NOT NULL). Migration 2026_09_05_000004 backfills any
    -- existing NULL rows from the branch's first active warehouse before
    -- applying the NOT NULL constraint.
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    sub_total numeric(14,2) DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    tax_amount numeric(14,2) DEFAULT 0,
    total_amount numeric(14,2) DEFAULT 0,
    -- PURCHASING-API-2 (G-116): status CHECK expanded to include the 3
    -- approval states (submitted/approved/rejected). Migration
    -- 2026_09_05_000003 applies the same expansion to live schemas.
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted','approved','rejected','sent','partial','received','cancelled')),
    expected_date date,
    notes text,
    created_by integer,
    -- PURCHASING-API-2 (G-116): approval audit columns (mirror manual_journals).
    -- All nullable — null for rows that have never been submitted.
    submitted_by bigint,
    submitted_at timestamp(0),
    approved_by bigint,
    approved_at timestamp(0),
    approval_comments text,
    rejected_by bigint,
    rejected_at timestamp(0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT purchase_orders_code_unique UNIQUE (po_code)
);
CREATE INDEX idx_po_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_po_branch ON purchase_orders(branch_id);
CREATE INDEX idx_po_status ON purchase_orders(status);
CREATE INDEX idx_po_submitted ON purchase_orders(status, submitted_at);

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
    -- paid_amount: accumulated supplier-payment allocations (G-024/G-025).
    -- Mirrors sales_invoices.paid_amount layout. Maintained by
    -- SupplierTransactionService::allocateToGRN (+) / reversePayment (-).
    paid_amount numeric(14,2) DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    -- PURCHASING-3 G-039: confirmer identity persisted on the row for fast
    -- O(1) PK lookup (avoids slow month-partitioned user_audit_log join).
    -- Null for draft rows; populated by confirmReceive().
    confirmed_by integer,
    confirmed_at timestamp(0),
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
-- Partial index — only GRNs with at least one supplier-payment allocation.
-- Powers the audit checklist's "partially-paid GRNs" view cheaply.
CREATE INDEX IF NOT EXISTS idx_pr_paid ON purchase_receives(paid_amount) WHERE paid_amount > 0;

CREATE TABLE purchase_receive_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_receive_id integer NOT NULL REFERENCES purchase_receives(id) ON DELETE CASCADE,
    purchase_order_item_id integer REFERENCES purchase_order_items(id),
    product_id integer NOT NULL REFERENCES products(id),
    -- G-286 (LOW-E) — 2026_09_07_000002: NOT NULL. Previously nullable
    -- (mismatch with the FormRequest requirement + StockService::applyTransaction
    -- which hard-requires a positive warehouse_id). Migration backfills any
    -- legacy NULL rows from the parent purchase_receives.warehouse_id (defensive
    -- — should be 0 rows in practice) before applying the constraint.
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
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
    -- PURCHASING-3 G-039: confirmer identity persisted on the row for fast
    -- O(1) PK lookup (avoids slow month-partitioned user_audit_log join).
    -- Null for draft rows; populated by confirmReturn().
    confirmed_by integer,
    confirmed_at timestamp(0),
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

-- ============================================================
-- Hash-chained audit triggers (PURCHASING-1, G-030/G-031/G-032)
-- ============================================================
-- Attached to all 6 purchase tables by migration
-- 2026_09_03_000002_attach_financial_audit_trigger_to_purchase_tables.php.
--
-- The fn_financial_audit_trigger() function (defined in 02_accounting.sql:381-443)
-- writes an immutable, hash-chained row to financial_audit_log on every
-- INSERT / UPDATE / DELETE. This closes the forensic gap where direct
-- DB::table('purchase_*') mutations bypassed the hash chain.
--
-- Trigger names follow the convention trg_audit_<table>. Each is
-- AFTER INSERT OR UPDATE OR DELETE FOR EACH ROW.
--
-- NOTE: supplier_payments already has the trigger (set up by 06_payment_and_misc.sql).
-- It is intentionally NOT re-attached here.
--
-- The attachments below are idempotent (DROP IF EXISTS + CREATE), so re-applying
-- 05_purchase.sql on a database that already has them is a safe no-op.
DO $$
DECLARE
    t text;
    trg text;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'purchase_orders',
        'purchase_order_items',
        'purchase_receives',
        'purchase_receive_items',
        'purchase_returns',
        'purchase_return_items'
    ] LOOP
        trg := 'trg_audit_' || t;
        EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', trg, t);
        EXECUTE format(
            'CREATE TRIGGER %I AFTER INSERT OR UPDATE OR DELETE ON %I '
            'FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger()',
            trg, t
        );
    END LOOP;
END $$;
