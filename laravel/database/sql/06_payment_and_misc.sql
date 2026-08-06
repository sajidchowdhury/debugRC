-- ============================================================
-- RC_ERP PostgreSQL Schema — Part 6: Payments + Misc
-- ============================================================

-- ===================== CUSTOMER PAYMENTS =====================
CREATE TABLE customer_payments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_code varchar(30) NOT NULL,
    payment_date date NOT NULL,
    customer_id integer NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    payment_mode varchar(20) NOT NULL CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    journal_entry_id integer REFERENCES journal_entries(id),
    -- Bank-mode payments trigger intercompany settlement.
    intercompany_journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT customer_payments_code_unique UNIQUE (payment_code)
);
CREATE INDEX idx_cp_customer ON customer_payments(customer_id);
CREATE INDEX idx_cp_bank ON customer_payments(bank_id);
CREATE INDEX idx_cp_branch ON customer_payments(branch_id);
CREATE INDEX idx_cp_journal ON customer_payments(journal_entry_id);

CREATE TABLE customer_payment_settlements (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id integer NOT NULL REFERENCES customer_payments(id) ON DELETE CASCADE,
    -- FK to sales_invoices (partitioned) cannot be declarative in PG 12-17
    -- because sales_invoices is partitioned by invoice_date and `id` alone
    -- is not unique. Enforcement is handled at the application layer.
    -- This table is dropped by migration 2025_01_09_000001 in favor of
    -- invoice_payment_allocations (which uses a trigger-based FK).
    invoice_id integer NOT NULL,
    settled_amount numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_cps_payment ON customer_payment_settlements(payment_id);
CREATE INDEX idx_cps_invoice ON customer_payment_settlements(invoice_id);

-- ===================== SUPPLIER PAYMENTS =====================
CREATE TABLE supplier_payments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_code varchar(30) NOT NULL,
    payment_date date NOT NULL,
    supplier_id integer NOT NULL REFERENCES suppliers(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    payment_mode varchar(20) NOT NULL CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    discount_amount numeric(14,2) DEFAULT 0,
    collected_by integer REFERENCES employees(id),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT supplier_payments_code_unique UNIQUE (payment_code)
);
CREATE INDEX idx_sp_supplier ON supplier_payments(supplier_id);
CREATE INDEX idx_sp_bank ON supplier_payments(bank_id);
CREATE INDEX idx_sp_branch ON supplier_payments(branch_id);
CREATE INDEX idx_sp_collected_by ON supplier_payments(collected_by);
CREATE INDEX idx_sp_journal ON supplier_payments(journal_entry_id);

CREATE TABLE supplier_payment_settlements (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id integer NOT NULL REFERENCES supplier_payments(id) ON DELETE CASCADE,
    purchase_receive_id integer NOT NULL REFERENCES purchase_receives(id),
    settled_amount numeric(14,2) NOT NULL DEFAULT 0,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sps_payment ON supplier_payment_settlements(payment_id);
CREATE INDEX idx_sps_receive ON supplier_payment_settlements(purchase_receive_id);

-- ===================== MONEY TRANSFERS =====================
CREATE TABLE money_transfers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_code varchar(30) NOT NULL,
    transfer_date date NOT NULL,
    from_branch_id integer NOT NULL REFERENCES branches(id),
    to_branch_id integer NOT NULL REFERENCES branches(id),
    transfer_type varchar(20) NOT NULL CHECK (transfer_type IN ('cash_to_bank','bank_to_cash','cash_to_cash','bank_to_bank')),
    from_bank_id integer REFERENCES banks(id),
    to_bank_id integer REFERENCES banks(id),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    journal_entry_id integer REFERENCES journal_entries(id),
    intercompany_journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT money_transfers_code_unique UNIQUE (transfer_code)
);
CREATE INDEX idx_mt_branches ON money_transfers(from_branch_id, to_branch_id);
CREATE INDEX idx_mt_journal ON money_transfers(journal_entry_id);

-- ===================== OTHER INCOME / EXPENSE =====================
CREATE TABLE other_incomes (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    income_code varchar(30) NOT NULL,
    income_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    income_type varchar(50),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT other_incomes_code_unique UNIQUE (income_code)
);
CREATE INDEX idx_oi_journal ON other_incomes(journal_entry_id);

CREATE TABLE other_expenses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    expense_code varchar(30) NOT NULL,
    expense_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    bank_id integer REFERENCES banks(id),
    expense_type varchar(50),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT other_expenses_code_unique UNIQUE (expense_code)
);
CREATE INDEX idx_oe_journal ON other_expenses(journal_entry_id);

-- ===================== EMPLOYEE TRANSACTIONS =====================
CREATE TABLE employee_transactions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_code varchar(30) NOT NULL,
    transaction_date date NOT NULL,
    employee_id integer NOT NULL REFERENCES employees(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    transaction_type varchar(20) NOT NULL CHECK (transaction_type IN ('advance','loan','repayment','salary','deduction','adjustment')),
    amount numeric(14,2) NOT NULL DEFAULT 0,
    description text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT employee_transactions_code_unique UNIQUE (transaction_code)
);
CREATE INDEX idx_et_employee ON employee_transactions(employee_id);
CREATE INDEX idx_et_journal ON employee_transactions(journal_entry_id);

-- ===================== NOTIFICATIONS (Laravel-standard) =====================
-- HIGH-WAVE-1 (G-091 / G-182): DDL baseline sync. Mirrors the FINAL post-
-- migration state of migration `2025_01_06_000001_create_notification_tables.php`
-- (drops the legacy Phase-2 `notifications` table + recreates with Laravel's
-- standard polymorphic schema: uuid PK + notifiable_id + notifiable_type +
-- type + jsonb data + read_at + timestamps). The legacy schema
-- (`user_id`, `title`, `body`, `is_read`) is GONE — Laravel's Notification
-- facade sends DatabaseNotification instances that require THIS shape.
-- `php artisan migrate` remains the canonical install path; this block is
-- the SQL baseline mirror for DBA point-in-time recovery / documentation
-- parity with the migration-defined schema.
CREATE TABLE notifications (
    id uuid PRIMARY KEY,
    notifiable_id bigint NOT NULL,
    notifiable_type varchar(255) NOT NULL,
    type varchar(255) NOT NULL,
    data jsonb NOT NULL,
    read_at timestamp(0) WITHOUT TIME ZONE,
    created_at timestamp(0) WITHOUT TIME ZONE,
    updated_at timestamp(0) WITHOUT TIME ZONE
);
CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications(notifiable_type, notifiable_id);
-- Partial index for unread dropdown queries (WHERE read_at IS NULL) —
-- explicitly created by migration `2025_01_06_000001` L49-53 to keep the
-- inbox dropdown fast on tables with many read notifications.
CREATE INDEX idx_notif_is_read ON notifications(read_at) WHERE read_at IS NULL;

-- ===================== INVESTIGATION MODE =====================
-- Phase 11 will simplify this to a simple admin toggle (no QR, no OTP).
-- For now, the table is included for data migration compatibility.
CREATE TABLE investigation_activators (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id integer NOT NULL,
    label varchar(100),
    is_active boolean NOT NULL DEFAULT false,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_inv_activator_user UNIQUE (user_id)
);

-- ===================== LOGIN RATE LIMITS =====================
CREATE TABLE login_rate_limits (
    bucket_key varchar(255) PRIMARY KEY,
    attempt_count integer NOT NULL DEFAULT 0,
    reset_at timestamp(0) NOT NULL,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);

-- ===================== USER AUDIT LOG =====================
-- Phase 10.1: user_audit_log is now partitioned by RANGE (created_at)
-- See migration 2026_08_02_000001_partition_audit_log_tables.php for the
-- conversion logic (rename → recreate → copy → drop old).
CREATE TABLE user_audit_log (
    id integer GENERATED BY DEFAULT AS IDENTITY,
    user_id integer,
    action varchar(50) NOT NULL,
    target_user_id integer,
    branch_id integer,
    record_id integer,
    details jsonb,
    ip_address varchar(45),
    user_agent text,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Pre-2026 catch-all partition for historical data
CREATE TABLE user_audit_log_pre2026 PARTITION OF user_audit_log
    FOR VALUES FROM ('2020-01-01') TO ('2026-01-01');
-- Monthly partitions for 2026
CREATE TABLE user_audit_log_2026_01 PARTITION OF user_audit_log FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
CREATE TABLE user_audit_log_2026_02 PARTITION OF user_audit_log FOR VALUES FROM ('2026-02-01') TO ('2026-03-01');
CREATE TABLE user_audit_log_2026_03 PARTITION OF user_audit_log FOR VALUES FROM ('2026-03-01') TO ('2026-04-01');
CREATE TABLE user_audit_log_2026_04 PARTITION OF user_audit_log FOR VALUES FROM ('2026-04-01') TO ('2026-05-01');
CREATE TABLE user_audit_log_2026_05 PARTITION OF user_audit_log FOR VALUES FROM ('2026-05-01') TO ('2026-06-01');
CREATE TABLE user_audit_log_2026_06 PARTITION OF user_audit_log FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');
CREATE TABLE user_audit_log_2026_07 PARTITION OF user_audit_log FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
CREATE TABLE user_audit_log_2026_08 PARTITION OF user_audit_log FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE user_audit_log_2026_09 PARTITION OF user_audit_log FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE user_audit_log_2026_10 PARTITION OF user_audit_log FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE user_audit_log_2026_11 PARTITION OF user_audit_log FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');
CREATE TABLE user_audit_log_2026_12 PARTITION OF user_audit_log FOR VALUES FROM ('2026-12-01') TO ('2027-01-01');
-- Default partition for out-of-range dates
CREATE TABLE user_audit_log_default PARTITION OF user_audit_log DEFAULT;

CREATE INDEX idx_ual_user ON user_audit_log(user_id);
CREATE INDEX idx_ual_action ON user_audit_log(action);
-- BRIN replaces B-tree on created_at for append-only partitioned table
CREATE INDEX idx_ual_created_at_brin ON user_audit_log USING BRIN (created_at) WITH (pages_per_range = 64);

-- ===================== NOTIFICATION RULES + PIVOT (F-18b multi-recipients) =====================
-- HIGH-WAVE-1 (G-091 / G-182): DDL baseline sync. Mirrors the FINAL post-
-- migration state of 2 migrations:
--   1. `2025_01_06_000001_create_notification_tables.php` — initial
--      notification_rules schema (with single `recipient_type` +
--      `recipient_user_id` columns).
--   2. `2025_01_26_000001_notification_rules_multi_recipients.php` — creates
--      the `notification_rule_recipients` pivot + backfills existing rules
--      into the pivot + DROPS `recipient_type` + `recipient_user_id` columns
--      from `notification_rules` (multi-recipient selection now lives in the
--      pivot table — one rule → many recipient types).
-- The FINAL schema below reflects the state AFTER migration #2 (the dropped
-- columns are NOT here). `deleted_at` is added by the `NotificationRule` model's
-- `use SoftDeletes;` declaration (verified at app/Models/NotificationRule.php:31).
-- `created_by` is `foreignId` (bigint) but the migration does NOT add a FK
-- constraint to `users(id)` — mirroring that decision here (no FK in the
-- baseline either). `php artisan migrate` remains the canonical install path.
CREATE TABLE notification_rules (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(255) NOT NULL,
    event varchar(255) NOT NULL,
    channel varchar(255) NOT NULL DEFAULT 'database',
    is_active boolean NOT NULL DEFAULT true,
    times_fired integer NOT NULL DEFAULT 0,
    description text,
    created_by bigint,
    created_at timestamp(0) WITHOUT TIME ZONE,
    updated_at timestamp(0) WITHOUT TIME ZONE,
    deleted_at timestamp(0) WITHOUT TIME ZONE
);
CREATE INDEX notification_rules_event_index ON notification_rules(event);
CREATE INDEX notification_rules_is_active_index ON notification_rules(is_active);

-- Pivot table for multi-select recipient types per rule (F-18b).
-- One rule → many recipient_type selections (admin, warehouse_manager_of_branch,
-- salesman_of_invoice, invoice_creator, specific_user, etc.).
-- `recipient_user_id` is only set for `specific_user` (NULL for all other types).
CREATE TABLE notification_rule_recipients (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    notification_rule_id bigint NOT NULL REFERENCES notification_rules(id) ON DELETE CASCADE,
    recipient_type varchar(255) NOT NULL,
    recipient_user_id integer,
    created_at timestamp(0) WITHOUT TIME ZONE,
    updated_at timestamp(0) WITHOUT TIME ZONE
);
CREATE INDEX notification_rule_recipients_notification_rule_id_recipient_type_index ON notification_rule_recipients(notification_rule_id, recipient_type);
CREATE INDEX notification_rule_recipients_recipient_type_index ON notification_rule_recipients(recipient_type);

-- ── Audit triggers: notification config tables (WORKFLOWS-AUDIT-1 G-181) ────
-- Attach fn_financial_audit_trigger to the 2 admin-managed notification config
-- tables so rule changes (who gets notified for what) are tamper-evident.
-- A malicious DB admin can no longer silently UPDATE notification_rules SET
-- is_active = false to suppress security-relevant notifications without
-- leaving a hash-chained audit trail in financial_audit_log.
-- The trigger function reads branch_id from the row's JSONB (works for tables
-- without a branch_id column — notification_rules + notification_rule_recipients
-- have no branch_id).
CREATE TRIGGER trg_audit_notification_rules AFTER INSERT OR UPDATE OR DELETE ON notification_rules FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();
CREATE TRIGGER trg_audit_notification_rule_recipients AFTER INSERT OR UPDATE OR DELETE ON notification_rule_recipients FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();

-- ── Table: system_policies (DDL baseline mirror — MEDIUM-WAVE-2-A / G-243) ──
-- CREATE TABLE IF NOT EXISTS so a fresh psql load of 01-11_*.sql creates the
-- table BEFORE the trg_audit_system_policies trigger block below attaches to
-- it. The trigger attachment would otherwise fail with
-- `relation "system_policies" does not exist` on a DBA point-in-time recovery
-- rebuild from the SQL baseline alone.
--
-- DDL baseline mirror: `php artisan migrate` REMAINS THE CANONICAL INSTALL
-- PATH. This block exists so the SQL baseline can stand alone for DBA
-- recovery / documentation parity with the migration-defined schema. On a
-- production install the table + indexes are created by migration
-- 2025_01_07_000001_create_system_policies_table.php; this DDL block is a
-- best-effort mirror that is idempotent (`CREATE TABLE IF NOT EXISTS`) and
-- will not override an existing migration-created table.
--
-- Schema mirror of 2025_01_07_000001_create_system_policies_table.php.
-- Modes: NORMAL, INVESTIGATION, READ_ONLY (future), MAINTENANCE (future),
-- EMERGENCY (future). System-scoped (no branch_id) — managed exclusively
-- by SystemPolicyService::activate()/deactivate() under superadmin Gate.
CREATE TABLE IF NOT EXISTS system_policies (
    id BIGSERIAL PRIMARY KEY,
    mode VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    activated_by BIGINT,
    activated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    deactivated_by BIGINT,
    deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE,
    reason TEXT,
    expires_at TIMESTAMP(0) WITHOUT TIME ZONE,
    metadata JSONB,
    activation_source VARCHAR(30) NOT NULL DEFAULT 'admin_panel',
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS system_policies_mode_index ON system_policies(mode);
CREATE INDEX IF NOT EXISTS system_policies_is_active_index ON system_policies(is_active);
-- Partial unique index: only one active policy at a time. Enforced by the
-- service layer (SystemPolicyService::activate() deactivates the prior
-- policy in the same transaction). The DB-level constraint is the
-- defense-in-depth guard against direct DB writes (G9 RLS + G10 audit
-- trigger further constrain such writes).
CREATE UNIQUE INDEX IF NOT EXISTS system_policies_one_active
    ON system_policies (is_active) WHERE is_active = true;

-- ── Audit trigger: system_policies (AUDIT-TRAIL-1 G-094) ───────────────────
-- Attach fn_financial_audit_trigger to the Compliance & Security Policy
-- Framework header table (mode: NORMAL/INVESTIGATION/READ_ONLY/MAINTENANCE/
-- EMERGENCY, is_active, activated_by/deactivated_by, reason, expires_at).
-- A malicious DB admin could flip `is_active` or change `mode` to silently
-- relax investigation/lockdown posture without leaving a hash-chained audit
-- trail. The trigger makes such changes tamper-evident in financial_audit_log.
--
-- Mirror of migration 2026_09_06_000005_attach_financial_audit_trigger_to_remaining_tables.php
-- (DDL baseline mirror — on a fresh DB, `php artisan migrate` is the
-- canonical install path; this appendix is documentation + DBA point-in-time
-- recovery use only).
--
-- The trigger function reads branch_id from the row's JSONB (works for
-- tables without a branch_id column — system_policies is system-scoped,
-- has no branch_id; _branch_id resolves to NULL which is the correct
-- posture for system-scoped tables).
--
-- NOTE: the `notifications` table (Laravel-standard UUID PK) is
-- INTENTIONALLY EXCLUDED from audit-trigger coverage — see migration
-- 2026_09_06_000005 docstring (UUID PK is incompatible with
-- financial_audit_log.record_id BIGINT NOT NULL).
--
-- DROP IF EXISTS before CREATE makes the attachment idempotent.
DROP TRIGGER IF EXISTS trg_audit_system_policies ON system_policies;
CREATE TRIGGER trg_audit_system_policies AFTER INSERT OR UPDATE OR DELETE ON system_policies FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger();

-- ── Export Audit Log (REPORTS-AUDIT-1 G-132 / csv-export.md G6) ─────────────
-- Append-only audit trail for every CSV/JSON/HTML export performed by an
-- authenticated user. Required for SOX/audit-trail compliance on financial-
-- data exports (invoices, trial balance, GL, budget, branch-demand weekly, etc.)
-- — fn_financial_audit_trigger only fires on INSERT/UPDATE/DELETE, NOT on
-- SELECT/COPY, so exports were previously invisible to the audit trail.
--
-- Written by the WritesExportAuditLog trait from any controller that performs
-- a CSV/JSON/HTML export. The trait is non-blocking: a failure to write the
-- audit row is logged via Log::warning and the export proceeds.
--
-- No fn_financial_audit_trigger attachment — this table IS itself an audit log.
-- A separate audit-of-the-auditor trail would be redundant. Reads of this
-- table are restricted to admins via a future /admin/export-audit-log page
-- (not yet built — out of scope for this wave).
CREATE TABLE export_audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    route VARCHAR(255) NOT NULL,
    module VARCHAR(100) NOT NULL,
    filters_json JSONB,
    row_count INTEGER,
    byte_size BIGINT,
    ip_address INET,
    user_agent TEXT,
    exported_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_export_audit_log_user ON export_audit_log(user_id, exported_at DESC);
CREATE INDEX idx_export_audit_log_module ON export_audit_log(module, exported_at DESC);
