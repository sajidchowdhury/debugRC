-- ============================================================================
-- 08_consolidation.sql — Intercompany & Consolidation subsystem (Phase 8)
-- ============================================================================
-- FINANCE-1 (Phase 1) — resolves G-019 (consolidation-intercompany G5):
--   "DDL stale: consolidation_runs / elimination_rules / elimination_entries /
--    companies / mv_consolidated_trial_balance NOT in any database/sql/*.sql"
--
-- These tables + the materialized view previously existed ONLY in migration
-- 2026_08_11_000001_create_intercompany_and_consolidation.php. A fresh
-- `php artisan migrate:fresh` from the SQL baseline would NOT create them.
--
-- This file is the canonical DDL for the consolidation subsystem. It mirrors
-- the post-migration schema (i.e. the schema you get after running
-- 2026_08_11_000001 + 2026_08_30_000001 on top of the SQL baseline). The
-- migration remains as the source of truth for the RLS policies (which were
-- rewritten by dd31590 / G-015) and for the seed data; this file is purely
-- the table + view DDL so that external readers (BI, replication, reporting)
-- can discover the schema without running migrations.
--
-- Dependency note:
--   - branches (01_auth_and_master.sql) must exist before companies can add
--     the branches.company_id FK.
--   - ledgers (02_accounting.sql) must exist before elimination_rules can
--     reference ledger IDs, and before the MV can join against ledgers.
--   - journal_entries / journal_lines (02_accounting.sql) must exist before
--     elimination_entries can reference journal_entry_id and before the MV
--     can aggregate journal_lines.
--   - fiscal_years must exist before consolidation_runs can reference
--     fiscal_year_id.
-- Loaded after 02_accounting.sql by 2025_01_01_000001_create_rcerp_schema.php.
-- ============================================================================

-- ── 1. companies — legal entities that own one or more branches ─────────────
CREATE TABLE companies (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_code           varchar(20)  NOT NULL,
    company_name           varchar(100) NOT NULL,
    legal_name             varchar(150),
    tax_id                 varchar(50),
    registration_no        varchar(50),
    address                text,
    phone                  varchar(30),
    email                  varchar(100),
    currency               varchar(3)   NOT NULL DEFAULT 'BDT',
    is_consolidation_parent boolean     NOT NULL DEFAULT false,
    ownership_pct          numeric(5,2) NOT NULL DEFAULT 100.00,
    status                 varchar(20)  NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active','inactive','dormant')),
    description            text,
    created_by             bigint,
    created_at             timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at             timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at             timestamp(0),
    CONSTRAINT companies_company_code_unique UNIQUE (company_code)
);
CREATE INDEX idx_co_status_parent ON companies(status, is_consolidation_parent);
CREATE INDEX idx_companies_company_code_index ON companies(company_code);

-- ── 2. consolidation_runs — audit trail of each consolidation execution ─────
CREATE TABLE consolidation_runs (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_code             varchar(30) NOT NULL,
    name                 varchar(100) NOT NULL,
    period_from          date NOT NULL,
    period_to            date NOT NULL,
    status               varchar(20) NOT NULL DEFAULT 'draft'
                         CHECK (status IN ('draft','posted','reversed')),
    fiscal_year_id       bigint REFERENCES fiscal_years(id) ON DELETE SET NULL,
    company_ids          jsonb,
    elimination_summary  jsonb,
    notes                text,
    created_by           bigint NOT NULL,
    posted_by            bigint,
    posted_at            timestamp(0),
    reversed_by          bigint,
    reversed_at          timestamp(0),
    reverse_reason       text,
    created_at           timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at           timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at           timestamp(0),
    CONSTRAINT consolidation_runs_run_code_unique UNIQUE (run_code)
);
CREATE INDEX idx_cr_status_period ON consolidation_runs(status, period_from, period_to);
CREATE INDEX idx_consolidation_runs_period_from_index ON consolidation_runs(period_from);
CREATE INDEX idx_consolidation_runs_period_to_index ON consolidation_runs(period_to);
CREATE INDEX idx_consolidation_runs_fiscal_year_id_index ON consolidation_runs(fiscal_year_id);

-- ── 3. elimination_rules — configurable rules for which accounts to eliminate
CREATE TABLE elimination_rules (
    id                          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    rule_code                   varchar(30)  NOT NULL,
    rule_name                   varchar(100) NOT NULL,
    rule_type                   varchar(30)  NOT NULL DEFAULT 'balance'
                                CHECK (rule_type IN ('balance','revenue','investment','dividend','custom')),
    description                 varchar(255),
    debit_ledger_id             bigint NOT NULL REFERENCES ledgers(id) ON DELETE RESTRICT,
    credit_ledger_id            bigint NOT NULL REFERENCES ledgers(id) ON DELETE RESTRICT,
    elimination_debit_ledger_id bigint REFERENCES ledgers(id) ON DELETE SET NULL,
    elimination_credit_ledger_id bigint REFERENCES ledgers(id) ON DELETE SET NULL,
    is_active                   boolean NOT NULL DEFAULT true,
    sort_order                  integer NOT NULL DEFAULT 0,
    created_by                  bigint,
    created_at                  timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at                  timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at                  timestamp(0),
    CONSTRAINT elimination_rules_rule_code_unique UNIQUE (rule_code)
);
CREATE INDEX idx_er_type_active ON elimination_rules(rule_type, is_active);
CREATE INDEX idx_elimination_rules_is_active_index ON elimination_rules(is_active);

-- ── 4. elimination_entries — the actual elimination JEs generated per run ───
CREATE TABLE elimination_entries (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    consolidation_run_id bigint NOT NULL REFERENCES consolidation_runs(id) ON DELETE CASCADE,
    elimination_rule_id  bigint NOT NULL REFERENCES elimination_rules(id) ON DELETE RESTRICT,
    journal_entry_id     bigint REFERENCES journal_entries(id) ON DELETE SET NULL,
    from_branch_id       bigint REFERENCES branches(id) ON DELETE SET NULL,
    to_branch_id         bigint REFERENCES branches(id) ON DELETE SET NULL,
    debit_ledger_id      bigint NOT NULL REFERENCES ledgers(id) ON DELETE RESTRICT,
    credit_ledger_id     bigint NOT NULL REFERENCES ledgers(id) ON DELETE RESTRICT,
    elimination_amount   numeric(15,2) NOT NULL DEFAULT 0,
    description          text,
    created_at           timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at           timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ee_run_rule     ON elimination_entries(consolidation_run_id, elimination_rule_id);
CREATE INDEX idx_elimination_entries_journal_entry_id_index ON elimination_entries(journal_entry_id);
CREATE INDEX idx_ee_branch_pair  ON elimination_entries(from_branch_id, to_branch_id);

-- ── 5. branches.company_id — link each branch to its owning company ─────────
-- Added by 2026_08_11_000001 step 5. Idempotent (ADD COLUMN IF NOT EXISTS).
ALTER TABLE branches ADD COLUMN IF NOT EXISTS company_id bigint;
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'branches' AND constraint_name = 'fk_branches_company'
    ) THEN
        ALTER TABLE branches
            ADD CONSTRAINT fk_branches_company
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS idx_branches_company ON branches(company_id);

-- ── 6. ledgers.is_elimination — flag for elimination contra accounts ────────
-- Added by 2026_08_11_000001 step 6. Idempotent.
ALTER TABLE ledgers ADD COLUMN IF NOT EXISTS is_elimination boolean NOT NULL DEFAULT false;
CREATE INDEX IF NOT EXISTS idx_ledgers_elimination ON ledgers(is_elimination);

-- ── 7. mv_consolidated_trial_balance — consolidated TB materialized view ────
-- Definition mirrors 2026_08_11_000001::createConsolidatedTrialBalanceView().
-- CREATE IF NOT EXISTS makes this idempotent (the migration also uses IF NOT
-- EXISTS, so the order of execution does not matter).
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_consolidated_trial_balance AS
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.normal_balance,
    l.is_elimination,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit,
    COALESCE(elim.elim_debit, 0) AS elimination_debit,
    COALESCE(elim.elim_credit, 0) AS elimination_credit,
    COALESCE(SUM(jl.debit), 0) - COALESCE(elim.elim_debit, 0) AS consolidated_debit,
    COALESCE(SUM(jl.credit), 0) - COALESCE(elim.elim_credit, 0) AS consolidated_credit
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.source != 'elimination'
LEFT JOIN LATERAL (
    SELECT
        SUM(elim_jl.debit) AS elim_debit,
        SUM(elim_jl.credit) AS elim_credit
    FROM elimination_entries ee
    JOIN consolidation_runs cr ON cr.id = ee.consolidation_run_id
    JOIN journal_entries elim_je ON elim_je.id = ee.journal_entry_id
    JOIN journal_lines elim_jl ON elim_jl.journal_entry_id = elim_je.id
    WHERE elim_jl.ledger_id = l.id
      AND cr.status = 'posted'
      AND COALESCE(elim_je.is_reversed, false) = false
) elim ON TRUE
WHERE l.is_active = true
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
         l.normal_balance, l.is_elimination, elim.elim_debit, elim.elim_credit;

CREATE UNIQUE INDEX IF NOT EXISTS mv_ctb_ledger_idx
    ON mv_consolidated_trial_balance(ledger_id);

-- ============================================================================
-- NOTE: RLS policies on companies / consolidation_runs / elimination_entries /
-- elimination_rules are NOT defined here. They were originally admin-only
-- (2026_08_11_000001) and then rewritten to per-verb policies by dd31590
-- (G-015, migration 2026_08_30_000001_add_rls_missing_tables.php). The
-- migration remains the source of truth for RLS because the policy rewrite
-- is idempotent and carries the full GUC-based condition logic. Defining
-- them here too would create a maintenance hazard (two sources of truth).
-- ============================================================================
