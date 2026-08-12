-- ============================================================================
-- 09_branch_demand.sql — Branch Demand subsystem (Phase 8 + shadow mode)
-- ============================================================================
-- FINANCE-1 (Phase 1) — resolves G-018 (branch-demand G5):
--   "DDL stale — branch_demand* tables + shadow tables missing from
--    database/sql/*.sql"
--
-- This file defines the 6 branch-demand + shadow tables that previously
-- existed ONLY in migrations:
--   1. branch_demand_repricing                       (2026_07_29_000016)
--   2. branch_demand_audit_log                       (2026_07_29_000017)
--   3. branch_demand_customer_payment_settlements    (2026_07_29_000015)
--   4. branch_demand_money_transfer_settlements      (2026_07_29_000014)
--   5. shadow_demand_comparisons                     (2026_07_29_000019)
--   6. shadow_cutover_log                            (2025_07_28_000012)
--
-- NOTE on branch_demands + branch_demand_items:
--   These two tables ARE in 03_stock.sql, but with the LEGACY pre-migration
--   schema (status CHECK with 'pending','approved','rejected','fulfilled',
--   'cancelled'; warehouse_id; fulfilled_qty; rate). They are refreshed
--   IN-PLACE in 03_stock.sql (see FINANCE-1 edit to that file) to match the
--   post-migration schema (status CHECK with 'pending','received','rejected',
--   'reversed'; from_warehouse_id + to_warehouse_id; cost_rate; price_min/
--   max/default; total_value; settlement_amount; warehouse_transfer_id;
--   journal_entry_id_debtor; received_at/by; reversed_at/by/reverse_reason).
--   They are NOT redefined here to avoid a double-definition conflict.
--
-- NOTE on branch_ledger:
--   branch_ledger is defined in 02_accounting.sql:203 with the NEW schema
--   (debit/credit/running_balance/is_reversed/remarks). It is NOT stale and
--   is NOT redefined here.
--
-- NOTE on RLS:
--   RLS policies for these 6 tables are defined in their respective migrations
--   (and rewritten for 5 of them by dd31590 / G-022 via migration
--   2026_08_30_000001_add_rls_missing_tables.php). The migrations remain the
--   source of truth for RLS. Defining policies here would create a
--   maintenance hazard (two sources of truth).
--
-- Dependency: loaded after 03_stock.sql (which defines branch_demands,
-- branch_demand_items, warehouse_transfers, money_transfers, customer_payments)
-- and after 02_accounting.sql (which defines journal_entries, branch_ledger).
-- ============================================================================
-- Loaded by 2025_01_01_000001_create_rcerp_schema.php after 08_consolidation.sql.
-- ============================================================================

-- ── 1. branch_demand_repricing — price-change adjustments on demands ────────
-- Mirrors migration 2026_07_29_000016 + 2026_09_05_000002 (G-329).
-- Append-only (no updated_at).
CREATE TABLE branch_demand_repricing (
    id                    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id      integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    original_total_value  numeric(12,2) NOT NULL,
    new_total_value       numeric(12,2) NOT NULL,
    adjustment_amount     numeric(12,2) NOT NULL,
    reason                text,
    approved_by           integer,
    journal_entry_id      integer REFERENCES journal_entries(id),
    journal_entry_id_debtor integer REFERENCES journal_entries(id),  -- G-329: debtor (requester) side
    created_by            integer,
    created_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bdr_demand ON branch_demand_repricing(branch_demand_id);
CREATE INDEX idx_bdr_journal_debtor ON branch_demand_repricing(journal_entry_id_debtor);

-- ── 2. branch_demand_audit_log — immutable forensic trail per action ────────
-- Mirrors migration 2026_07_29_000017. Append-only (RLS blocks UPDATE/DELETE).
CREATE TABLE branch_demand_audit_log (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id bigint NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    branch_id        bigint,
    action           varchar(40) NOT NULL,
    actor_id         bigint,
    actor_role       varchar(50),
    payload          jsonb,
    ip_address       varchar(45),
    user_agent       varchar(255),
    created_at       timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_bdal_action CHECK (action IN (
        'create', 'send', 'confirm_receipt', 'reverse', 'delete',
        'reject', 'reprice', 'settle', 'settlement_reverse',
        'export', 'print'
    ))
);
CREATE INDEX idx_bdal_demand   ON branch_demand_audit_log(branch_demand_id);
CREATE INDEX idx_bdal_branch   ON branch_demand_audit_log(branch_id);
CREATE INDEX idx_bdal_actor    ON branch_demand_audit_log(actor_id);
CREATE INDEX idx_bdal_critical ON branch_demand_audit_log(branch_demand_id, action)
    WHERE action IN ('reverse', 'delete', 'reprice', 'settlement_reverse');

-- ── 3. branch_demand_customer_payment_settlements — FIFO bank-payment links ─
-- Mirrors migration 2026_07_29_000015. Append-only.
CREATE TABLE branch_demand_customer_payment_settlements (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id      integer NOT NULL REFERENCES customer_payments(id) ON DELETE CASCADE,
    demand_id       integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    settled_amount  numeric(12,2) NOT NULL,
    created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bdcps_demand  ON branch_demand_customer_payment_settlements(demand_id);
CREATE INDEX idx_bdcps_payment ON branch_demand_customer_payment_settlements(payment_id);

-- ── 4. branch_demand_money_transfer_settlements — FIFO transfer links ──────
-- Mirrors migration 2026_07_29_000014. Append-only.
CREATE TABLE branch_demand_money_transfer_settlements (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_id     integer NOT NULL REFERENCES money_transfers(id) ON DELETE CASCADE,
    demand_id       integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    settled_amount  numeric(12,2) NOT NULL,
    created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bdmts_demand   ON branch_demand_money_transfer_settlements(demand_id);
CREATE INDEX idx_bdmts_transfer ON branch_demand_money_transfer_settlements(transfer_id);

-- ── 5. shadow_demand_comparisons — Laravel-vs-legacy demand comparison ─────
-- Mirrors migration 2026_07_29_000019.
CREATE TABLE shadow_demand_comparisons (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    operation         varchar(30),
    branch_demand_id  integer,
    demand_code       varchar(100),
    from_branch_id    integer,
    to_branch_id      integer,
    diff_status       varchar(30),
    diff_details      jsonb,
    laravel_data      jsonb,
    legacy_data       jsonb,
    shadow_mode       varchar(10) NOT NULL DEFAULT 'passive',
    compared_at       timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    compared_by       integer,
    created_at        timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at        timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_shadow_demand_comparisons_branch_demand_id_index ON shadow_demand_comparisons(branch_demand_id);
CREATE INDEX idx_shadow_demand_comparisons_diff_status_index      ON shadow_demand_comparisons(diff_status);
CREATE INDEX idx_shadow_demand_comparisons_operation_index         ON shadow_demand_comparisons(operation);
CREATE INDEX idx_shadow_demand_comparisons_from_branch_id_index    ON shadow_demand_comparisons(from_branch_id);
CREATE INDEX idx_shadow_demand_comparisons_to_branch_id_index      ON shadow_demand_comparisons(to_branch_id);
CREATE INDEX idx_shadow_demand_comparisons_compared_at_index       ON shadow_demand_comparisons(compared_at);
CREATE INDEX idx_shadow_demand_comparisons_diff_status_compared_at_index ON shadow_demand_comparisons(diff_status, compared_at);

-- ── 6. shadow_cutover_log — daily cutover-readiness rollup ─────────────────
-- Mirrors migration 2025_07_28_000012 (table 2 of 2). Diagnostic table.
CREATE TABLE shadow_cutover_log (
    id                       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    check_date               date NOT NULL,
    comparisons_total        integer NOT NULL DEFAULT 0,
    comparisons_match        integer NOT NULL DEFAULT 0,
    comparisons_diff         integer NOT NULL DEFAULT 0,
    comparisons_missing_legacy integer NOT NULL DEFAULT 0,
    comparisons_error        integer NOT NULL DEFAULT 0,
    is_clean_day             boolean NOT NULL DEFAULT false,
    consecutive_clean_days   integer NOT NULL DEFAULT 0,
    cutover_ready            boolean NOT NULL DEFAULT false,
    checked_by               bigint,
    checked_at               timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    created_at               timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at               timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT shadow_cutover_log_check_date_unique UNIQUE (check_date)
);
