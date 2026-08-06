-- ============================================================
-- 08_budgeting_and_dimensions.sql
-- Canonical DDL baseline for the Budgeting + Cost Center / Dimensions subsystem.
--
-- G-320 (G3) FINANCE-DIM-1: this file was missing — the canonical DDL for
-- dimensions, dimension_values, budgets, budget_lines, and the budget_vs_actual
-- view lived ONLY in migration 2026_08_10_000002_create_budgeting_and_cost_centers.php.
-- A fresh `psql -f database/sql/*.sql` install (the canonical-baseline path used
-- for greenfield deployments) would NOT have these tables, so segment-reporting
-- queries would fail with "relation does not exist".
--
-- `php artisan migrate` remains the canonical install path (migrations handle
-- the seed data + the partitioning overlay + RLS policies). This SQL baseline
-- mirrors the migration's schema so fresh installs + DB snapshots work.
--
-- Cross-ref:
--   - journal_lines.dimension_value_id column: defined in 02_accounting.sql
--     (G-320 fix). The FK from that column to dimension_values(id) is declared
--     below (DEFERRABLE INITIALLY DEFERRED so the FK check happens at commit).
--   - RLS policies: declared below (budgets_branch_policy + dimension_values
--     branch isolation). Mirrors migration 2026_08_30_000001_add_rls_missing_tables.
-- ============================================================

-- ── 1. dimensions ──────────────────────────────────────────────
-- Dimension types: cost_center, profit_center, department, project, location.
-- G-332 (G13) FINANCE-DIM-1: code uses a PARTIAL UNIQUE index (only non-soft-
-- deleted rows) so codes can be reused after soft-delete. Mirrors the sibling
-- dimension_values.uq_dv_dim_code_active pattern.
CREATE TABLE IF NOT EXISTS dimensions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(100) NOT NULL,
    type varchar(30) NOT NULL CHECK (type IN ('cost_center','profit_center','department','project','location')),
    code varchar(20) NOT NULL,
    description text,
    is_active boolean NOT NULL DEFAULT true,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_dim_code_active ON dimensions (code) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_dim_type ON dimensions(type);
CREATE INDEX IF NOT EXISTS idx_dim_active ON dimensions(is_active) WHERE deleted_at IS NULL;

-- ── 2. dimension_values ────────────────────────────────────────
-- Individual values within a dimension (e.g., "Sales Dept" under the Department
-- dimension). branch_id IS NULL means "all branches" (company-wide value); a
-- non-null branch_id means "specific to this branch".
CREATE TABLE IF NOT EXISTS dimension_values (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dimension_id integer NOT NULL REFERENCES dimensions(id) ON DELETE CASCADE,
    code varchar(30) NOT NULL,
    name varchar(150) NOT NULL,
    branch_id integer,  -- NULL = all branches; non-null = branch-specific
    is_active boolean NOT NULL DEFAULT true,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0)
);
-- Partial unique: prevents duplicate active codes within a dimension.
-- Standard UNIQUE(dimension_id, code, deleted_at) fails on PostgreSQL because
-- NULL != NULL, so two rows with the same (dimension_id, code) and deleted_at=NULL
-- would both be allowed. The partial index avoids this.
CREATE UNIQUE INDEX IF NOT EXISTS uq_dv_dim_code_active
    ON dimension_values (dimension_id, code)
    WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_dv_dimension ON dimension_values(dimension_id);
CREATE INDEX IF NOT EXISTS idx_dv_branch ON dimension_values(branch_id) WHERE branch_id IS NOT NULL;

-- G-320 (G3) FINANCE-DIM-1: FK from journal_lines.dimension_value_id to
-- dimension_values(id). DEFERRABLE INITIALLY DEFERRED so the FK check happens
-- at commit, not at INSERT — allows posting JEs where the dimension_value row
-- is created in the same transaction. ON DELETE SET NULL preserves the journal
-- line if a dimension value is deleted (the dimension tag is lost but the GL
-- entry remains — the auditor can see that a tag existed via the audit log).
ALTER TABLE journal_lines
    ADD CONSTRAINT fk_jl_dim_value
    FOREIGN KEY (dimension_value_id) REFERENCES dimension_values(id)
    ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED;

-- G-321 (G4, MEDIUM-WAVE-3) FINANCE-DIM-1: same FK pattern on
-- manual_journal_lines.dimension_value_id. Mirrors fk_jl_dim_value above
-- (DEFERRABLE INITIALLY DEFERRED + ON DELETE SET NULL). The column is declared
-- in 02_accounting.sql; this FK is declared here alongside fk_jl_dim_value so
-- both dimension-tag FKs live in the dimensions baseline file. The migration
-- 2026_09_08_000001 adds this FK post-hoc; this canonical DDL now includes it
-- so fresh `psql -f database/sql/*.sql` installs have the FK without needing
-- the migration.
ALTER TABLE manual_journal_lines
    ADD CONSTRAINT fk_mjl_dim_value
    FOREIGN KEY (dimension_value_id) REFERENCES dimension_values(id)
    ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED;

-- ── 3. budgets ─────────────────────────────────────────────────
-- Budget header: one per (fiscal_year, branch, period_type). Status lifecycle:
-- draft → active → cancelled. period_type: monthly (12 periods), quarterly (4),
-- yearly (1).
CREATE TABLE IF NOT EXISTS budgets (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(150) NOT NULL,
    fiscal_year varchar(9) NOT NULL,  -- e.g., "2026" or "2026-2027"
    branch_id integer,  -- NULL = company-wide budget
    period_type varchar(10) NOT NULL DEFAULT 'monthly' CHECK (period_type IN ('monthly','quarterly','yearly')),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','cancelled')),
    total_amount numeric(15,2) NOT NULL DEFAULT 0,
    approved_by integer,
    approved_at timestamp(0),
    cancelled_by integer,
    cancelled_at timestamp(0),
    cancel_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp(0)
);
CREATE INDEX IF NOT EXISTS idx_budget_fiscal_year ON budgets(fiscal_year);
CREATE INDEX IF NOT EXISTS idx_budget_branch ON budgets(branch_id) WHERE branch_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_budget_status ON budgets(status) WHERE deleted_at IS NULL;

-- ── 4. budget_lines ────────────────────────────────────────────
-- One row per (budget, ledger, period). period: 1-12 for monthly, 1-4 for
-- quarterly, 1 for yearly. G-339 (G18) in finance/budgeting.md adds a CHECK
-- constraint period BETWEEN 1 AND 12 (defense-in-depth; the per-period_type
-- upper bound is enforced at the app layer via Budget::maxPeriod()).
CREATE TABLE IF NOT EXISTS budget_lines (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    budget_id integer NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    ledger_id integer NOT NULL,
    period smallint NOT NULL CHECK (period BETWEEN 1 AND 12),
    amount numeric(15,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_bl_budget_ledger_period UNIQUE (budget_id, ledger_id, period)
);
CREATE INDEX IF NOT EXISTS idx_bl_budget ON budget_lines(budget_id);
CREATE INDEX IF NOT EXISTS idx_bl_ledger ON budget_lines(ledger_id);

-- ── 5. budget_vs_actual view ───────────────────────────────────
-- The variance reporting view. Joins budget_lines to actual GL activity
-- (journal_lines) by (ledger_id, period). Actuals are computed via
-- EXTRACT(MONTH FROM journal_entries.entry_date) for monthly budgets.
CREATE OR REPLACE VIEW budget_vs_actual AS
SELECT
    b.id AS budget_id,
    b.name AS budget_name,
    b.fiscal_year,
    b.branch_id,
    b.period_type,
    bl.ledger_id,
    bl.period,
    bl.amount AS budget_amount,
    COALESCE(
        SUM(CASE
            WHEN je.is_reversed = false
            THEN jl.debit - jl.credit
            ELSE 0
        END), 0
    ) AS actual_amount,
    bl.amount - COALESCE(
        SUM(CASE
            WHEN je.is_reversed = false
            THEN jl.debit - jl.credit
            ELSE 0
        END), 0
    ) AS variance_amount
FROM budgets b
JOIN budget_lines bl ON bl.budget_id = b.id
LEFT JOIN journal_lines jl ON jl.ledger_id = bl.ledger_id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND EXTRACT(MONTH FROM je.entry_date) = bl.period
    AND EXTRACT(YEAR FROM je.entry_date)::text = split_part(b.fiscal_year, '-', 1)::text
    AND (b.branch_id IS NULL OR je.branch_id = b.branch_id)
WHERE b.deleted_at IS NULL
    AND b.status = 'active'
GROUP BY b.id, b.name, b.fiscal_year, b.branch_id, b.period_type, bl.ledger_id, bl.period, bl.amount;

-- ── 6. RLS policies ────────────────────────────────────────────
-- Budgets + dimension_values carry branch_id (nullable = all-branches / company-
-- wide). RLS allows users to see their own branch's rows + NULL-branch rows.
-- Admins see all. Mirrors the canonical add_rls_branch_isolation pattern.

-- Enable RLS on budgets + dimension_values.
ALTER TABLE budgets ENABLE ROW LEVEL SECURITY;
ALTER TABLE budgets FORCE ROW LEVEL SECURITY;
ALTER TABLE dimension_values ENABLE ROW LEVEL SECURITY;
ALTER TABLE dimension_values FORCE ROW LEVEL SECURITY;

-- budgets: branch-scoped (NULL branch_id = visible to all).
CREATE POLICY budgets_branch_policy ON budgets
    USING (
        current_setting('app.is_admin', true)::boolean
        OR branch_id IS NULL
        OR branch_id = current_setting('app.branch_id', true)::int
    )
    WITH CHECK (
        current_setting('app.is_admin', true)::boolean
        OR branch_id IS NULL
        OR branch_id = current_setting('app.branch_id', true)::int
    );

-- dimension_values: branch-scoped (NULL branch_id = visible to all).
CREATE POLICY dimension_values_branch_policy ON dimension_values
    USING (
        current_setting('app.is_admin', true)::boolean
        OR branch_id IS NULL
        OR branch_id = current_setting('app.branch_id', true)::int
    )
    WITH CHECK (
        current_setting('app.is_admin', true)::boolean
        OR branch_id IS NULL
        OR branch_id = current_setting('app.branch_id', true)::int
    );

-- dimensions: no branch_id column → admin-only OR read-all (dimensions are
-- master data, not transactional). No RLS policy needed — the route middleware
-- (role:accountant,manager,admin for read; role:manager,admin for write per
-- G-340) handles access control at the application layer.
