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
-- (G8: branch_id is varchar in the migration — a known typo; kept as-is
-- here for schema parity. Postgres implicit-casts int → text at query
-- time, so runtime is unaffected.)
CREATE TABLE approval_workflows (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(100) NOT NULL,
    entity_type varchar(50) NOT NULL,
    min_amount numeric(15,2) NOT NULL DEFAULT 0,
    is_active boolean NOT NULL DEFAULT true,
    requires_approval_levels integer NOT NULL DEFAULT 1,
    branch_id varchar(255),
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
-- entity_id: unsignedBigInteger (NOT a FK — G6: polymorphic, no constraint).
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
