-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 3: Stock / Inventory
-- ============================================================
--
-- DEFERRABLE FKs (Task 35, migration 2025_01_21_000005):
--   All declarative FKs in this file are configured DEFERRABLE:
--   - INITIALLY DEFERRED: FKs referencing journal_entries, branches, warehouses,
--     suppliers (parent often created in same transaction)
--   - INITIALLY IMMEDIATE: FKs referencing products (parent always pre-exists)
--   The DEFERRABLE clause is applied via ALTER CONSTRAINT in the migration.

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
    -- 'reversal' is written by StockService::reverseTransaction() and queried
    -- by StockTransactionController; it MUST be in the allowed set (Phase 0.1).
    reference_type varchar(30) NOT NULL CHECK (reference_type IN (
        'purchase_receive','purchase_return','sales_challan','sales_return',
        'stock_adjustment','stock_take','warehouse_transfer','damage',
        'branch_demand','opening_balance','reversal'
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
    -- Phase 4 (Stock Take plan): status CHECK expanded to allow 'submitted'
    -- and 'approved' (approval-workflow states) plus 'reversed' (reserved
    -- for Phase 10's reversal-vs-cancel distinction; harmless to allow now).
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','counting','submitted','approved','posted','cancelled','reversed')),
    journal_entry_id integer REFERENCES journal_entries(id),
    -- Reversal columns (mirror stock_adjustments; added by Phase 0 of the
    -- Stock Take implementation plan). Required by StockTakeService::createSession
    -- (writes is_reversed=false) and cancelSession (writes all four on reversal).
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    -- Phase 3 (Stock Take plan): stock integrity. freeze_outbound=true locks
    -- the source warehouses against outbound movements during the count;
    -- frozen_at records when the lock took effect; count_snapshot jsonb
    -- captures the product list at setup time (see StockTakeService::
    -- setupWarehouseCounts) so the count can be reconstructed later.
    frozen_at timestamp(0),
    freeze_outbound boolean NOT NULL DEFAULT false,
    count_snapshot jsonb,
    -- Phase 4 (Stock Take plan): approval workflow & segregation of duties.
    -- submitted_by/at: who submitted the counting session for approval.
    -- approved_by/at:  who approved it (MUST differ from submitted_by —
    --                  enforced by StockTakeService::approve). null when
    --                  auto-approved by the system at post time.
    -- approval_comments: approver's comments on approval OR rejection reason.
    submitted_by integer,
    submitted_at timestamp(0),
    approved_by integer,
    approved_at timestamp(0),
    approval_comments text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_take_session_code_unique UNIQUE (session_code)
);
CREATE INDEX idx_sts_branch ON stock_take_sessions(branch_id);
-- Partial index: only reversed sessions, for fast reversal lookups.
CREATE INDEX idx_sts_is_reversed ON stock_take_sessions(is_reversed) WHERE is_reversed = true;
-- Partial index: only sessions that freeze outbound stock — powers the
-- warehouse-flag recompute in StockTakeService::refreshWarehouseFreezeFlags.
CREATE INDEX idx_sts_freeze_outbound ON stock_take_sessions(branch_id) WHERE freeze_outbound = true;
-- Phase 4: partial index on submitted sessions — powers the "awaiting my
-- approval" worklist query for approvers.
CREATE INDEX idx_sts_submitted ON stock_take_sessions(branch_id, submitted_at) WHERE status = 'submitted';

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
    -- Phase 1 (Stock Take plan): per-line GL traceability. Populated during
    -- postSession — links each variance item to the exact journal_lines row
    -- that recorded its GL impact. Nullable (null = no variance or not yet posted).
    journal_line_id integer REFERENCES journal_lines(id) ON DELETE SET NULL,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sti_session_wh_product UNIQUE (stock_take_session_id, warehouse_id, product_id)
);
CREATE INDEX idx_sti_session ON stock_take_items(stock_take_session_id);
CREATE INDEX idx_sti_warehouse_product ON stock_take_items(warehouse_id, product_id);
-- Partial index: only posted variance items (for fast drill-down queries).
CREATE INDEX idx_sti_journal_line ON stock_take_items(journal_line_id) WHERE journal_line_id IS NOT NULL;

-- Phase 2 (Stock Take plan): real audit trail. Append-only log of every
-- state transition in the stock-take lifecycle. Written explicitly by
-- StockTakeAuditLogger (inside the same DB::transaction as the data change),
-- replacing the dead AuditableMasterData trait (which never fired because
-- StockTakeService writes via DB::table(), bypassing Eloquent events).
-- branch_id is denormalized from stock_take_sessions so RLS can scope reads
-- without a join. RLS policies are created in the migration; see
-- 2025_07_26_000005_create_stock_take_audit_log_table.php.
CREATE TABLE stock_take_audit_log (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    stock_take_warehouse_id integer REFERENCES warehouses(id) ON DELETE SET NULL,
    stock_take_item_id integer REFERENCES stock_take_items(id) ON DELETE SET NULL,
    action varchar(40) NOT NULL CHECK (
        action IN ('create','setup','save_count','mark_complete','submit',
                   'approve','reject','post','reverse','re_open','delete','cancel')
    ),
    actor_id integer,
    from_status varchar(20),
    to_status varchar(20),
    payload jsonb,
    branch_id integer NOT NULL REFERENCES branches(id),
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Timeline query: ordered list of audit rows for one session.
CREATE INDEX idx_stal_session ON stock_take_audit_log(stock_take_session_id, created_at);
-- Partial index: only the "critical" transitions (post/reverse/re_open).
CREATE INDEX idx_stal_critical ON stock_take_audit_log(stock_take_session_id)
    WHERE action IN ('post','reverse','re_open');
-- Branch filter for the global audit screen.
CREATE INDEX idx_stal_branch ON stock_take_audit_log(branch_id, created_at);
-- "Actions by user" report.
CREATE INDEX idx_stal_actor ON stock_take_audit_log(actor_id, created_at);

-- Phase 4 (Stock Take plan): approval-workflow configuration knobs.
-- Lightweight key/value table: one row per policy key. The value is jsonb
-- so a single column can carry bool / numeric / string / array (approver_roles
-- is a jsonb array of role strings). The StockTakePolicyService caches all
-- rows in memory for 5 min under the 'stock_take_policies:all' cache key.
-- Seeded by 2025_07_28_000001_add_approval_workflow_to_stock_take_sessions.php
-- with the four Phase 4 defaults:
--   stock_take.require_approval          (bool)    — gate on/off
--   stock_take.auto_approve_below_value  (numeric) — skip gate below this value
--   stock_take.approver_roles            (array)   — roles that can approve
--   stock_take.variance_threshold_block  (numeric) — force approval ≥ this value
CREATE TABLE stock_take_policies (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key varchar(80) NOT NULL,
    value jsonb NOT NULL,
    description text,
    updated_by integer,
    updated_at timestamp(0),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_take_policies_key_unique UNIQUE (key)
);

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
