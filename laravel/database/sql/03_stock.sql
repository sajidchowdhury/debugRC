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
--   FOR VALUES FROM ('2025-01-01') TO ('2025-02-01')
-- Default partition catches out-of-range dates:
--   CREATE TABLE stock_transactions_default PARTITION OF stock_transactions DEFAULT

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
    -- Phase 2 (Stock Adjustment plan): structured reason categorization.
    -- opening_balance rows also route to reference_type='opening_balance' in
    -- stock_transactions (see StockAdjustmentService::confirmAdjustment).
    adjustment_category varchar(40) NOT NULL DEFAULT 'other'
        CONSTRAINT sa_category_check CHECK (adjustment_category IN (
            'opening_balance','data_migration','uom_correction',
            'post_conversion_fix','legacy_cleanup','reconciliation_variance','other'
        )),
    -- Denormalized cache of SUM(qty * rate) over stock_adjustment_items,
    -- computed once at create time by StockAdjustmentService::createAdjustment.
    -- numeric(14,2) matches the model's `decimal:2` cast. Added by migration
    -- 2025_08_05_000001_add_total_amount_to_stock_adjustments.php (hotfix for
    -- the missing column that broke the index page's sum() and the create
    -- flow's INSERT).
    total_amount numeric(14,2) NOT NULL DEFAULT 0,
    reason text,
    -- Phase 3 (Stock Adjustment plan): status now includes the maker-checker
    -- approval states. draft → submitted → approved → confirmed (or
    -- cancelled / rejected). See migration
    -- 2025_07_29_000001_add_approval_to_stock_adjustments.php.
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','submitted','approved','confirmed','cancelled','rejected')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    -- G15: every cancel (draft OR confirmed) stores a reason here. For a
    -- confirmed-cancel, reverse_reason is ALSO populated (it records why the
    -- stock+GL reversal happened); for a draft-cancel only cancel_reason is set.
    cancel_reason text,
    -- Phase 3: maker-checker approval trail.
    submitted_by integer,
    submitted_at timestamp(0),
    approved_by integer,
    approved_at timestamp(0),
    approval_comments text,
    -- G9: attribute the posting (confirm) action + persist confirm_reason.
    confirmed_by integer,
    confirmed_at timestamp(0),
    confirm_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_adjustments_code_unique UNIQUE (adjustment_code)
);
CREATE INDEX idx_sa_warehouse ON stock_adjustments(warehouse_id);
CREATE INDEX idx_sa_journal ON stock_adjustments(journal_entry_id);
CREATE INDEX idx_sa_category ON stock_adjustments(adjustment_category);
-- Phase 3: partial index powering the "awaiting my approval" worklist.
CREATE INDEX idx_sa_submitted ON stock_adjustments(branch_id, submitted_at) WHERE status = 'submitted';

CREATE TABLE stock_adjustment_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Phase 6.2 (Stock Adjustment plan): the exact stock_transactions row
    -- created for this item on confirm. Captured from applyTransaction's
    -- return value so cancelAdjustment can reverse the EXACT row (not a
    -- product+reference `.first()` lookup that was ambiguous when two
    -- items shared a product_id). Added by migration
    -- 2025_08_07_000001_add_stock_transaction_id_to_stock_adjustment_items.php.
    --
    -- Phase 6.2 FIX: COMPOSITE FK into stock_transactions. The ledger is
    -- PARTITION BY RANGE (transaction_date), so its PK is (id, transaction_date)
    -- and a single-column FK on (id) is IMPOSSIBLE — PostgreSQL requires every
    -- unique/PK constraint on a partitioned table to include ALL partitioning
    -- columns, so there is no UNIQUE(id) to reference. The item therefore
    -- carries the tx's date as the FK partner. Both columns nullable: the
    -- (NULL, NULL) pair is valid for pre-Phase-6.2 rows. ON DELETE SET NULL
    -- nulls BOTH columns. Column type is `integer` to match stock_transactions.id
    -- (integer GENERATED ALWAYS AS IDENTITY) — PG requires exact type match.
    stock_transaction_id integer,
    stock_transaction_date date,
    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    -- Phase 5 (Stock Adjustment plan): UOM columns. All nullable so old rows
    -- and non-UOM callers keep working. qty_entered = what the user typed (in
    -- the selected UOM); qty_base = converted to the product's base unit
    -- (= qty_entered × uom_factor, what posts to stock); uom_factor = snapshot
    -- of the factor at creation time (audit immutability). The legacy `qty`
    -- column stays as the authoritative BASE quantity (equals qty_base for
    -- new + backfilled rows). Added by migration
    -- 2025_08_06_000001_create_uom_tables.php.
    uom_id integer REFERENCES units_of_measure(id) ON DELETE SET NULL,
    qty_entered numeric(14,4),
    qty_base numeric(14,4),
    uom_factor numeric(14,6),
    rate numeric(12,2) DEFAULT 0,
    reason text,
    -- Phase 6.2 (G11 fix): one product per adjustment — the DB-level backstop
    -- for the duplicate-product-per-adjustment bug. The application-layer
    -- dedup guard (StockAdjustmentService::validateCreateInput) is the
    -- runtime gate; this is the invariant.
    CONSTRAINT sai_adj_product_unique UNIQUE (stock_adjustment_id, product_id),
    -- Phase 6.2 fix: composite FK into the partitioned ledger's real PK.
    CONSTRAINT sai_stock_tx_fk
        FOREIGN KEY (stock_transaction_id, stock_transaction_date)
        REFERENCES stock_transactions(id, transaction_date)
        ON DELETE SET NULL
);
CREATE INDEX idx_sai_adjustment ON stock_adjustment_items(stock_adjustment_id);
CREATE INDEX idx_sai_product ON stock_adjustment_items(product_id);
-- Phase 6.2 — powers the cancel-time reverse-by-item lookup AND the
-- ON DELETE SET NULL row-finder (composite, so a stock_transactions DELETE
-- never seq-scans stock_adjustment_items). Partial: only confirmed items.
CREATE INDEX idx_sai_stock_tx ON stock_adjustment_items(stock_transaction_id, stock_transaction_date) WHERE stock_transaction_id IS NOT NULL;

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
    -- the source warehouses against outbound movements during the count
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
    -- Phase 5 (Stock Take plan): cycle count & ABC classification.
    -- count_scope narrows the product set for a cycle count (vs a full
    -- warehouse count). count_scope_payload carries the scope's parameters
    -- as jsonb, e.g. {"category_ids":[3,5]}, {"abc_classes":["A"]},
    -- {"group_ids":[1,2]}, {"product_ids":[101,202]}. 'full' (default) loads
    -- every active product — the pre-Phase-5 behaviour. StockTakeService::
    -- setupWarehouseCounts branches on count_scope to build the product query.
    count_scope varchar(20) NOT NULL DEFAULT 'full'
        CHECK (count_scope IN ('full','category','abc','group','ad_hoc','negative_only','zero_only')),
    count_scope_payload jsonb,
    -- Phase 10 (Stock Take plan): reversal vs cancellation distinction +
    -- re-open after reversal. re_open_count caps how many times a reversed
    -- session can be re-opened (policy: stock_take.max_reopens, default 1).
    -- last_reopened_at/by record the most recent re-open. reversal_of_entry_id
    -- links to the journal_entries.id of the PRIOR post when this session was
    -- reversed — the audit chain back to the original post (the CURRENT post's
    -- journal_entry_id is always in the journal_entry_id column above).
    re_open_count integer NOT NULL DEFAULT 0,
    last_reopened_at timestamp(0),
    last_reopened_by integer REFERENCES users(id) ON DELETE SET NULL,
    reversal_of_entry_id integer REFERENCES journal_entries(id) ON DELETE SET NULL,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_take_session_code_unique UNIQUE (session_code)
);
CREATE INDEX idx_sts_branch ON stock_take_sessions(branch_id);
-- Partial index: only reversed sessions, for fast reversal lookups.
CREATE INDEX idx_sts_is_reversed ON stock_take_sessions(is_reversed) WHERE is_reversed = true;
-- Phase 10: partial index on status='reversed' sessions — powers the admin
-- "reversed sessions" worklist + the re-open-eligible list.
CREATE INDEX idx_sts_reversed ON stock_take_sessions(branch_id, reversed_at) WHERE status = 'reversed';
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
    -- Phase 8 (Stock Take plan): branch_id denormalized from
    -- stock_take_sessions.branch_id at insert time (set by
    -- StockTakeService::createSession, never updated afterwards). Lets RLS
    -- scope reads by branch without a join — the same pattern used on
    -- stock_take_audit_log. RLS policies live in
    -- 07_views_triggers_constraints.sql.
    branch_id integer NOT NULL REFERENCES branches(id),
    -- Phase 8: denormalized mirror of stock_take_sessions.freeze_outbound,
    -- set at insert (createSession) and never updated. The overlapping-
    -- frozen-session trigger (prevent_overlapping_frozen_stock_take, see
    -- 07_views_triggers_constraints.sql) reads this flag instead of joining
    -- to the session row, keeping the trigger cheap. The session's flag is
    -- the source of truth for runtime behavior (e.g. warehouses.is_frozen_
    -- for_count recompute); this column is only the snapshot that powers
    -- the no-overlap invariant.
    freeze_outbound boolean NOT NULL DEFAULT false,
    -- Phase 7: 'recounting' added to the status vocab. It is a transient
    -- state inserted by recountWarehouse() between 'completed' and
    -- 'counting' so the audit timeline can show the recount request
    -- distinctly. The transition is atomic (recounting is set + audited,
    -- then immediately counting), but the vocab is forward-compatible with
    -- a future async recount assignment.
    status varchar(20) DEFAULT 'pending' CHECK (status IN ('pending','counting','completed','recounting')),
    -- Phase 8: a session can cover a given warehouse at most once. The
    -- service dedupes warehouse_ids in PHP before insert, but the DB
    -- constraint is the race-condition backstop.
    CONSTRAINT uk_stw_session_wh UNIQUE (stock_take_session_id, warehouse_id)
);
CREATE INDEX idx_stw_session ON stock_take_warehouses(stock_take_session_id);
-- Phase 8: RLS branch filter scan.
CREATE INDEX idx_stw_branch ON stock_take_warehouses(branch_id);
-- Phase 8: the overlapping-frozen-session trigger's fast path — one row per
-- (frozen) warehouse per session.
CREATE INDEX idx_stw_frozen_wh ON stock_take_warehouses(warehouse_id) WHERE freeze_outbound = true;

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
    -- Phase 7 (Stock Take plan): per-line recount tracking. Set when
    -- recountWarehouse() touches this warehouse's items. recounted_by is a
    -- plain integer FK (ON DELETE SET NULL) so a deleted user does not
    -- cascade-wipe the recount context.
    recounted_at timestamp(0),
    recounted_by integer REFERENCES users(id) ON DELETE SET NULL,
    -- Phase 8 (Stock Take plan): branch_id denormalized from
    -- stock_take_sessions.branch_id at insert time (set by
    -- StockTakeService::setupWarehouseCounts, never updated). Lets RLS
    -- scope reads by branch without a join — closes the cross-branch data
    -- leak that existed when only stock_take_sessions had RLS.
    branch_id integer NOT NULL REFERENCES branches(id),
    -- Phase 9 (Stock Take plan): costing columns. system_rate is the setup-
    -- time avg cost (snapshot, never updated). post_rate is the post-time
    -- avg cost (re-fetched at postSession). The existing `rate` column above
    -- is repurposed as the post-time rate used for GL (written at post, not
    -- setup). revaluation_amount is the adjusting entry for the cost drift:
    -- (post_rate - system_rate) * physical_qty when |drift| > epsilon, else 0.
    -- revaluation_line_id mirrors journal_line_id for the revaluation entry.
    system_rate numeric(18,6),
    post_rate numeric(18,6),
    revaluation_amount numeric(18,6) NOT NULL DEFAULT 0,
    revaluation_line_id integer REFERENCES journal_lines(id) ON DELETE SET NULL,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sti_session_wh_product UNIQUE (stock_take_session_id, warehouse_id, product_id)
);
CREATE INDEX idx_sti_session ON stock_take_items(stock_take_session_id);
CREATE INDEX idx_sti_warehouse_product ON stock_take_items(warehouse_id, product_id);
-- Partial index: only posted variance items (for fast drill-down queries).
CREATE INDEX idx_sti_journal_line ON stock_take_items(journal_line_id) WHERE journal_line_id IS NOT NULL;
-- Phase 8: RLS branch filter scan.
CREATE INDEX idx_sti_branch ON stock_take_items(branch_id);

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
                   'approve','reject','post','reverse','re_open','delete',
                   'cancel','recount','scan_count','bulk_upsert','csv_import','autosave')
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
-- Partial index: only the "critical" transitions (post/reverse/re_open,
-- plus Phase 7 recount — a warehouse-level state change worth flagging).
CREATE INDEX idx_stal_critical ON stock_take_audit_log(stock_take_session_id)
    WHERE action IN ('post','reverse','re_open','recount');
-- Branch filter for the global audit screen.
CREATE INDEX idx_stal_branch ON stock_take_audit_log(branch_id, created_at);
-- "Actions by user" report.
CREATE INDEX idx_stal_actor ON stock_take_audit_log(actor_id, created_at);

-- Phase 4 (Stock Adjustment plan): dedicated audit log.
-- Replaces the dead AuditableMasterData trait (which never fires because
-- StockAdjustmentService writes header/items via DB::table(), bypassing
-- Eloquent events). Every lifecycle transition (create/submit/approve/reject/
-- confirm/cancel) writes exactly one row, inside the same DB::transaction as
-- the data change. branch_id is denormalized from stock_adjustments.branch_id
-- so RLS can scope reads by branch without a join. RLS policies are created in
-- the migration; see 2025_07_30_000001_create_stock_adjustment_audit_log.php.
CREATE TABLE stock_adjustment_audit_log (
    id bigserial PRIMARY KEY,
    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    branch_id integer NOT NULL REFERENCES branches(id),
    action varchar(40) NOT NULL CHECK (
        action IN ('create','update','submit','approve','reject','confirm',
                   'cancel','reverse','force_confirm','reopen','delete','export','print')
    ),
    actor_id bigint,
    actor_role varchar(40),
    payload jsonb,
    ip_address varchar(45),
    user_agent varchar(255),
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Timeline query: ordered list of audit rows for one adjustment (show page).
CREATE INDEX idx_saal_adjustment ON stock_adjustment_audit_log(stock_adjustment_id, created_at);
-- Partial index: only the "critical" transitions (confirm/cancel/reverse/
-- force_confirm). Powers the high-impact-events filter.
CREATE INDEX idx_saal_critical ON stock_adjustment_audit_log(stock_adjustment_id)
    WHERE action IN ('confirm','cancel','reverse','force_confirm');
-- Branch filter for the global audit screen.
CREATE INDEX idx_saal_branch ON stock_adjustment_audit_log(branch_id, created_at);
-- "Actions by user" report.
CREATE INDEX idx_saal_actor ON stock_adjustment_audit_log(actor_id, created_at);

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
-- Phase 7 seed (2025_07_30_000001_add_recount_columns_to_stock_take.php):
--   stock_take.recount_reset_to_system (bool, default false) — when true,
--     recountWarehouse() resets physical_qty to system_qty (counter starts
--     fresh); when false, the previous physical_qty is preserved so the
--     counter sees the prior count and adjusts. The pre-recount values are
--     always captured in the recount audit row regardless.
-- Phase 9 seed (2025_08_02_000001_phase9_post_time_cost_and_revaluation.php):
--   stock_take.revaluation_epsilon (numeric, default 0.01) — minimum
--     |post_rate - system_rate| delta that triggers a revaluation adjusting
--     entry at post time. When the avg cost drifts by more than this epsilon
--     between setup and post, an additional Dr/Cr Inventory/Inventory
--     Revaluation Expense line is posted for (post_rate - system_rate) *
--     physical_qty. Set to 0 to revalue on every post.
-- Phase 10 seed (2025_08_03_000001_phase10_reversal_vs_cancel_reopen.php):
--   stock_take.max_reopens (int, default 1) — maximum number of times a
--     reversed stock-take session can be re-opened for correction + re-
--     posting. Default 1 (one re-open per session). 0 forbids re-opening
--     entirely (reversed = hard terminal). Enforced by StockTakeService::reOpen.
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

-- ============================================================
-- Phase 5 (Stock Take plan): Cycle count & ABC classification.
-- ============================================================
-- Three policy-driven helper functions (STABLE — read stock_take_policies
-- at call time with a safe default). Defined BEFORE the materialized view
-- so the view's SELECT can reference them. The view is refreshed nightly
-- by a pg_cron job (refresh-abc-classification) using
-- REFRESH MATERIALIZED VIEW CONCURRENTLY, so readers are never blocked.
--
-- Policies (seeded by 2025_07_29_000001_add_cycle_count_scope_and_abc_classification.php):
--   stock_take.abc_threshold_a    (numeric, default 0.80) — A = top 80% of usage value
--   stock_take.abc_threshold_b    (numeric, default 0.95) — A+B = top 95%; B spans 80–95%, C is bottom 5%
--   stock_take.abc_lookback_days  (integer, default 365)  — consumption lookback window
CREATE OR REPLACE FUNCTION stock_take_abc_threshold_a()
RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::numeric
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_threshold_a'),
        0.80
    );
$$;

CREATE OR REPLACE FUNCTION stock_take_abc_threshold_b()
RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::numeric
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_threshold_b'),
        0.95
    );
$$;

CREATE OR REPLACE FUNCTION stock_take_abc_lookback_days()
RETURNS integer LANGUAGE sql STABLE AS $$
    SELECT COALESCE(
        (SELECT ((value::jsonb)#>>'{}')::integer
         FROM stock_take_policies
         WHERE key = 'stock_take.abc_lookback_days'),
        365
    );
$$;

-- mv_product_abc_classification — per-warehouse ABC ranking.
-- annual_usage_value = SUM(ABS(qty) * rate) for OUTBOUND (qty < 0) non-
-- reversed stock_transactions within the lookback window. Ranking is per-
-- warehouse so each warehouse has its own A/B/C distribution. The UNIQUE
-- index on (warehouse_id, product_id) is REQUIRED for CONCURRENTLY refresh.
CREATE MATERIALIZED VIEW mv_product_abc_classification AS
WITH usage AS (
    SELECT
        st.warehouse_id,
        st.product_id,
        SUM(ABS(st.qty) * st.rate) AS annual_usage_value
    FROM stock_transactions st
    JOIN products p ON p.id = st.product_id
    WHERE st.qty < 0
      AND st.is_reversed = false
      AND st.transaction_date >= (CURRENT_DATE - (stock_take_abc_lookback_days() || ' days')::interval)
      AND p.deleted_at IS NULL
    GROUP BY st.warehouse_id, st.product_id
),
wh_totals AS (
    SELECT warehouse_id, COALESCE(SUM(annual_usage_value), 0) AS wh_total
    FROM usage
    GROUP BY warehouse_id
),
ranked AS (
    SELECT
        u.warehouse_id,
        u.product_id,
        u.annual_usage_value,
        t.wh_total,
        SUM(u.annual_usage_value) OVER (
            PARTITION BY u.warehouse_id
            ORDER BY u.annual_usage_value DESC, u.product_id
        ) AS cum_value
    FROM usage u
    JOIN wh_totals t ON t.warehouse_id = u.warehouse_id
)
SELECT
    r.warehouse_id,
    r.product_id,
    r.annual_usage_value,
    CASE
        WHEN r.wh_total = 0 OR r.cum_value <= r.wh_total * stock_take_abc_threshold_a() THEN 'A'
        WHEN r.cum_value <= r.wh_total * stock_take_abc_threshold_b()                   THEN 'B'
        ELSE 'C'
    END AS abc_class,
    CURRENT_TIMESTAMP AS computed_at
FROM ranked r;
CREATE UNIQUE INDEX mv_product_abc_classification_wh_prod_uidx
    ON mv_product_abc_classification (warehouse_id, product_id);
CREATE INDEX mv_product_abc_classification_class_idx
    ON mv_product_abc_classification (abc_class);
CREATE INDEX mv_product_abc_classification_product_idx
    ON mv_product_abc_classification (product_id);
-- Nightly refresh job scheduled by the Phase 5 migration:
--   SELECT cron.schedule('refresh-abc-classification', '30 1 * * *',
--       $$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification$$)

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
