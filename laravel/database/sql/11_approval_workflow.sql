-- =============================================================================
-- 11_approval_workflow.sql — Phase 5: Generic Approval Workflow Engine
-- =============================================================================
-- G-081 (CRITICAL, WORKFLOWS-APPROVAL): the 4 generic approval tables
-- previously existed ONLY in migration 2026_08_10_000001 — NOT in the SQL
-- baseline. A fresh install from the SQL snapshot (without running
-- `php artisan migrate`) would lose the entire approval engine. This file
-- mirrors the migration so the SQL baseline is self-sufficient.
--
-- The manual_journals + damage_invoices approval columns are refreshed
-- in-place in 02_accounting.sql and 03_stock.sql respectively (this file
-- only defines the 4 generic engine tables + the seed workflow).
-- =============================================================================

-- ── 1. approval_workflows ────────────────────────────────────────────────────
-- Defines which entity types require approval and at what thresholds.
-- entity_type: 'manual_journal', 'stock_adjustment', 'damage_invoice'.
-- branch_id: null = all branches; specific integer = branch-scoped.
-- WORKFLOWS-AUDIT-1 (G-183): branch_id is now integer with FK to branches(id)
-- (was varchar(255) in the original migration — a known typo). Postgres
-- implicit-casts int→text worked at query time but no FK enforcement +
-- index efficiency degraded. Now: integer + FK ON DELETE CASCADE.
CREATE TABLE approval_workflows (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(100) NOT NULL,
    entity_type varchar(50) NOT NULL,
    min_amount numeric(15,2) NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    requires_approval_levels integer NOT NULL DEFAULT 1,
    branch_id integer REFERENCES branches(id) ON DELETE CASCADE,  -- WORKFLOWS-AUDIT-1 (G-183): integer + FK
    description text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0),
    CONSTRAINT uq_workflow_entity_branch UNIQUE (entity_type, branch_id, deleted_at)
);
CREATE INDEX idx_aw_entity_active ON approval_workflows(entity_type, is_active);

-- ── 2. approval_steps ────────────────────────────────────────────────────────
-- Defines the approval levels (sequential or parallel) within each workflow.
-- level: 1 = first approval level, 2 = second, etc.
-- role: 'manager', 'admin', etc. — the role required to act at this level.
-- is_parallel: if true, ALL users with this role must approve (G9: currently
-- dead config — approve() never reads it, but stored for future use).
CREATE TABLE approval_steps (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    approval_workflow_id integer NOT NULL REFERENCES approval_workflows(id) ON DELETE CASCADE,
    level integer NOT NULL,
    role varchar(50) NOT NULL,
    is_parallel boolean NOT NULL DEFAULT false,
    description text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_step_workflow_level UNIQUE (approval_workflow_id, level)
);

-- ── 3. approval_requests ─────────────────────────────────────────────────────
-- Tracks the current state of an approval request for an entity.
-- status: pending / approved / rejected / cancelled.
-- entity_id: integer (NOT a hard FK — G6/G-180: polymorphic by design).
-- WORKFLOWS-AUDIT-1 (G-180): the polymorphic design is intentional —
-- entity_id references manual_journals.id, stock_adjustments.id,
-- damage_invoices.id, purchase_orders.id, or stock_take_sessions.id
-- depending on entity_type. PostgreSQL doesn't support a single FK column
-- pointing to multiple parent tables natively. Mitigations:
--   (a) Partial indexes per entity_type (see below) speed up the queue.
--   (b) A cleanup_orphan_approval_requests() SQL function (defined at the
--       end of this file) marks orphaned pending rows as 'cancelled'.
CREATE TABLE approval_requests (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entity_type varchar(50) NOT NULL,
    entity_id integer NOT NULL,
    approval_workflow_id integer NOT NULL REFERENCES approval_workflows(id) ON DELETE CASCADE,
    current_level integer NOT NULL DEFAULT 1,
    status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
    requested_by integer NOT NULL,
    requested_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by integer,
    approved_at timestamp(0),
    rejected_by integer,
    rejected_at timestamp(0),
    rejection_reason text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ar_entity ON approval_requests(entity_type, entity_id);
CREATE INDEX idx_ar_status_level ON approval_requests(status, current_level);
CREATE INDEX idx_ar_requested_by ON approval_requests(requested_by);
-- WORKFLOWS-AUDIT-1 (G-180): partial indexes per entity_type — speeds up
-- the pending-queue lookup per entity type (the hot path in
-- ApprovalService::getPendingQueueForUser).
CREATE INDEX idx_ar_manual_journal_pending    ON approval_requests(entity_id, current_level) WHERE entity_type = 'manual_journal'    AND status = 'pending';
CREATE INDEX idx_ar_stock_adjustment_pending  ON approval_requests(entity_id, current_level) WHERE entity_type = 'stock_adjustment'  AND status = 'pending';
CREATE INDEX idx_ar_damage_invoice_pending    ON approval_requests(entity_id, current_level) WHERE entity_type = 'damage_invoice'    AND status = 'pending';
CREATE INDEX idx_ar_purchase_order_pending    ON approval_requests(entity_id, current_level) WHERE entity_type = 'purchase_order'    AND status = 'pending';
CREATE INDEX idx_ar_stock_take_session_pending ON approval_requests(entity_id, current_level) WHERE entity_type = 'stock_take_session' AND status = 'pending';

-- ── 4. approval_actions ─────────────────────────────────────────────────────
-- Audit log of every approve/reject action taken.
-- action: 'approved', 'rejected', 'commented'.
-- role_at_time: the actor's role captured at action time (immutable audit).
CREATE TABLE approval_actions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    approval_request_id integer NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    level integer NOT NULL,
    action varchar(20) NOT NULL,
    acted_by integer NOT NULL,
    acted_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    comments text,
    role_at_time varchar(50)
);
CREATE INDEX idx_aa_request_level ON approval_actions(approval_request_id, level);
CREATE INDEX idx_aa_acted_by ON approval_actions(acted_by);

-- ── 5. Seed: default Manual Journal approval workflow ───────────────────────
-- Mirrors the seed in migration 2026_08_10_000001. Two levels:
--   Level 1: manager (any manager can approve)
--   Level 2: admin (optional, for high-value journals)
-- Idempotent: uses ON CONFLICT DO NOTHING so re-running is safe.
INSERT INTO approval_workflows (name, entity_type, min_amount, is_active, requires_approval_levels, branch_id, description, created_at, updated_at)
SELECT 'Manual Journal Approval', 'manual_journal', 0, true, 1, NULL,
       'Default approval workflow for manual journal entries. All journals above min_amount require manager approval before posting.',
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows WHERE name = 'Manual Journal Approval' AND entity_type = 'manual_journal');

INSERT INTO approval_steps (approval_workflow_id, level, role, is_parallel, description, created_at, updated_at)
SELECT aw.id, 1, 'manager', false,
       'First-level approval by manager. Any manager can approve.',
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM approval_workflows aw
WHERE aw.name = 'Manual Journal Approval' AND aw.entity_type = 'manual_journal'
  AND NOT EXISTS (
      SELECT 1 FROM approval_steps s
      WHERE s.approval_workflow_id = aw.id AND s.level = 1
  );

INSERT INTO approval_steps (approval_workflow_id, level, role, is_parallel, description, created_at, updated_at)
SELECT aw.id, 2, 'admin', false,
       'Second-level approval by admin. Only required for high-value journals.',
       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM approval_workflows aw
WHERE aw.name = 'Manual Journal Approval' AND aw.entity_type = 'manual_journal'
  AND NOT EXISTS (
      SELECT 1 FROM approval_steps s
      WHERE s.approval_workflow_id = aw.id AND s.level = 2
  );

-- ── 6. Orphan-cleanup helper (WORKFLOWS-AUDIT-1 G-180) ──────────────────────
-- Marks orphaned pending approval_requests rows as 'cancelled'.
-- An orphan = a pending approval_requests row whose entity_id no longer
-- exists in the parent table (manual_journals / stock_adjustments /
-- damage_invoices / purchase_orders / stock_take_sessions).
--
-- Callable via: SELECT cleanup_orphan_approval_requests();
-- Returns: integer (count of rows marked cancelled).
-- Idempotent — re-running is safe (only marks currently-orphaned rows).
--
-- The polymorphic entity_id design (no single hard FK) is intentional —
-- see the comment block above the approval_requests CREATE TABLE.
CREATE OR REPLACE FUNCTION cleanup_orphan_approval_requests()
RETURNS integer
LANGUAGE plpgsql
AS $$
DECLARE
    v_count integer := 0;
    v_entity_type text;
    v_entity_table text;
    v_entity_id bigint;
    v_orphan_ids bigint[];
    v_affected integer;
BEGIN
    FOR v_entity_type, v_entity_table IN
        VALUES
            ('manual_journal',     'manual_journals'),
            ('stock_adjustment',   'stock_adjustments'),
            ('damage_invoice',      'damage_invoices'),
            ('purchase_order',      'purchase_orders'),
            ('stock_take_session',  'stock_take_sessions')
    LOOP
        EXECUTE format(
            'SELECT ARRAY_AGG(ar.id) FROM approval_requests ar
             LEFT JOIN %I parent ON parent.id = ar.entity_id
             WHERE ar.entity_type = %L
               AND ar.status = ''pending''
               AND parent.id IS NULL',
            v_entity_table, v_entity_type
        ) INTO v_orphan_ids;

        IF v_orphan_ids IS NOT NULL THEN
            UPDATE approval_requests
            SET status = 'cancelled',
                rejection_reason = 'Auto-cancelled: parent ' ||
                    v_entity_type || ' #' || entity_id ||
                    ' was hard-deleted (WORKFLOWS-AUDIT-1 G-180 cleanup)',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ANY(v_orphan_ids);

            GET DIAGNOSTICS v_affected = ROW_COUNT;
            v_count := v_count + v_affected;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$$;

-- ── 7. Audit triggers: approval engine tables (WORKFLOWS-AUDIT-1 G-187) ─────
-- Attach fn_financial_audit_trigger to the 4 approval engine tables so policy
-- changes (approval_workflows.min_amount, is_active; approval_steps.role,
-- level) are tamper-evident. An admin can no longer silently change approval
-- thresholds and erase the evidence — every direct DB mutation is captured in
-- financial_audit_log with hash-chained before/after snapshots.
-- The trigger function reads branch_id from the row's JSONB (works for tables
-- without a branch_id column — none of the 4 approval tables have branch_id
-- directly; approval_workflows.branch_id is the scoped-branch FK column but
-- the trigger's JSONB access pattern handles it correctly).
CREATE TRIGGER trg_audit_approval_workflows AFTER INSERT OR UPDATE OR DELETE ON approval_workflows FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_approval_steps AFTER INSERT OR UPDATE OR DELETE ON approval_steps FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_approval_requests AFTER INSERT OR UPDATE OR DELETE ON approval_requests FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_approval_actions AFTER INSERT OR UPDATE OR DELETE ON approval_actions FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
