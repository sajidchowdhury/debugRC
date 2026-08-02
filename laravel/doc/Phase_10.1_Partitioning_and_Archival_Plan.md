# Phase 10.1: Database Partitioning & Archival Plan

**Project**: Remote Center ERP (debugRC)  
**Database**: PostgreSQL 16+  
**Current State**: 70+ tables, 2 already partitioned, ~40 RLS-protected  
**Date**: 2026-08-02  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Partitioning State](#2-current-partitioning-state)
3. [Partitioning Principles & Constraints](#3-partitioning-principles--constraints)
4. [RLS + Partitioning Interaction](#4-rls--partitioning-interaction)
5. [Table Classification](#5-table-classification)
6. [Foreign Key Dependency Analysis](#6-foreign-key-dependency-analysis)
7. [Phase Breakdown](#7-phase-breakdown)
8. [Archival Strategy](#8-archival-strategy)
9. [Performance Validation Criteria](#9-performance-validation-criteria)
10. [Rollback Strategy](#10-rollback-strategy)
11. [Risk Register](#11-risk-register)

---

## 1. Executive Summary

This document defines a phased approach to implementing database partitioning and archival for the Remote Center ERP system. The goal is to improve query performance on large historical datasets, enable efficient data archival for compliance, and maintain the existing RLS (Row Level Security) architecture without disruption.

### Why Partitioning?

- **Query Performance**: Date-range queries (e.g., "show me this month's journal entries") currently scan entire tables. Partition pruning eliminates irrelevant partitions, reducing I/O by 90%+ for monthly queries.
- **Vacuum Efficiency**: PostgreSQL's VACUUM operates per-partition. Instead of vacuuming a 50M-row table, we vacuum 12 monthly partitions of ~4M rows each. Old partitions that are read-only need almost no vacuuming.
- **Archival & Compliance**: Old partitions can be detached and moved to cold storage (cheaper disks) or exported to Parquet/S3 for long-term retention. This is essential for statutory audit requirements (typically 7+ years of financial records).
- **Index Size Reduction**: Each partition has its own indexes. A 50M-row B-tree index is ~1.5GB; twelve 4M-row indexes are ~120MB each, which fit better in shared_buffers.

### Why Phased?

The biggest risk is partitioning `journal_entries` — it has 27+ child tables with foreign keys. Getting this wrong would break the entire accounting system. By starting with low-risk tables (audit logs, sub-ledgers) and building up institutional knowledge, we reduce the probability of catastrophic failure.

### Scope

- **In Scope**: All transaction/audit tables that grow over time (43 tables)
- **Out of Scope**: Master/reference tables (24 tables), configuration tables (17 tables) — these are small and static

---

## 2. Current Partitioning State

Two tables are already partitioned using declarative partitioning with `pg_partman` for auto-creation:

### 2.1 `stock_transactions` — RANGE by `transaction_date` (monthly)

```
Parent:  stock_transactions
PK:      (id, transaction_date)          ← composite PK includes partition key
Strategy: RANGE (transaction_date)
Naming:  stock_transactions_YYYY_MM
pg_partman: auto-creates future partitions, default partition exists
```

**Implications already in place:**
- Child tables use **trigger-based FK enforcement** instead of declarative FK (PostgreSQL cannot reference partitioned tables via declarative FK)
- `stock_adjustment_items` references `stock_transactions(id, transaction_date)` via composite trigger FK
- UNIQUE constraints include the partition key

### 2.2 `sales_invoices` — RANGE by `invoice_date` (monthly)

```
Parent:  sales_invoices
PK:      (id, invoice_date)              ← composite PK includes partition key
Strategy: RANGE (invoice_date)
Naming:  sales_invoices_YYYY_MM
pg_partman: auto-creates future partitions, default partition exists
```

**Child tables using trigger-based FKs:**
- `sales_invoice_items`
- `sales_invoice_dispatchers`
- `sales_invoice_dispatches`
- `sales_challans`
- `sales_returns`
- `invoice_payment_allocations`
- `commission_entries`

### 2.3 Lessons Learned from Existing Partitioning

| Lesson | Detail |
|--------|--------|
| **Composite PK required** | PK must include the partition key column |
| **UNIQUE must include partition key** | All unique constraints must include the partition key |
| **FK cannot reference partitioned tables** | PostgreSQL 12-17 does not support declarative FK referencing partitioned tables |
| **Trigger-based FK works** | The project already uses trigger-based FKs for `sales_invoices` and `stock_transactions` children |
| **pg_partman is reliable** | Auto-creation of future partitions works well |
| **Default partition is essential** | Catches rows with dates outside existing partition ranges |
| **RLS works with partitioning** | RLS policies at the parent level apply to all partitions automatically |

---

## 3. Partitioning Principles & Constraints

### 3.1 Hard Constraints (PostgreSQL Limitations)

1. **PK must include partition key** — All partitioned tables must have a composite primary key that includes the partition column
2. **UNIQUE must include partition key** — No unique constraint can exist without the partition key
3. **Declarative FK cannot reference partitioned tables** — All child tables referencing a partitioned parent must use trigger-based FK enforcement
4. **Partition key cannot be nullable** — The partition column must be NOT NULL
5. **No ALTER TABLE ... ATTACH PARTITION while locks held** — Attaching partitions requires ACCESS EXCLUSIVE lock on the parent (brief but blocks all access)

### 3.2 Design Principles

1. **RANGE by date first** — Monthly RANGE partitioning is the default strategy for all transaction tables. It aligns with accounting periods, fiscal months, and query patterns.
2. **Low-risk tables first** — Start with tables that have no FK children (audit logs) before tackling tables with many FK children (journal_entries).
3. **Preserve RLS** — All RLS policies must continue to work. RLS policies at the parent level automatically apply to all partitions.
4. **pg_partman for auto-creation** — Use `pg_partman` to auto-create future partitions and avoid manual maintenance.
5. **Default partition always** — Every partitioned table must have a default partition to catch rows with unexpected dates.
6. **Online migration** — All migrations must be executable without extended downtime. Use `ATTACH PARTITION` for converting existing tables.
7. **Test on staging first** — Every phase must be tested on a staging database before production.

### 3.3 Naming Conventions

```
Parent table:       <table_name>
Partitions:         <table_name>_YYYY_MM
Default partition:  <table_name>_default
pg_partman template: <table_name>_template
```

### 3.4 Trigger-Based FK Pattern

When a table is partitioned, any child table that references it must convert from declarative FK to trigger-based FK. The pattern already exists in the codebase:

```sql
-- Drop declarative FK
ALTER TABLE child_table DROP CONSTRAINT child_table_parent_id_fkey;

-- Create trigger function for INSERT/UPDATE
CREATE OR REPLACE FUNCTION trg_child_parent_fk_check()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM parent_table
        WHERE id = NEW.parent_id
          AND partition_key = NEW.parent_partition_key_value
    ) THEN
        RAISE EXCEPTION 'FK violation: parent_table(id=%, partition_key=%) not found',
            NEW.parent_id, NEW.parent_partition_key_value;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger
CREATE TRIGGER trg_child_parent_fk
    BEFORE INSERT OR UPDATE ON child_table
    FOR EACH ROW EXECUTE FUNCTION trg_child_parent_fk_check();
```

For partitioned child tables, the trigger must also handle cascading deletes and updates.

---

## 4. RLS + Partitioning Interaction

### 4.1 How RLS Works with Partitioned Tables

RLS policies are defined at the **parent table** level and automatically apply to all partitions. This means:

- No changes to RLS policies are needed when partitioning a table
- The `current_setting('app.branch_id')` and `current_setting('app.is_admin')` GUCs work identically
- Partition pruning and RLS filtering are independent — the planner prunes partitions first, then applies RLS

### 4.2 Branch-Based Partitioning Consideration

Currently, the system uses RLS for branch isolation (queries only see rows for the user's branch). An alternative is **LIST partitioning by branch_id**, which would provide physical branch isolation:

| Approach | RLS (current) | LIST by branch_id |
|----------|---------------|-------------------|
| Branch isolation | Logical (filter) | Physical (pruning) |
| Query performance | Scans all partitions, filters by branch | Only scans relevant branch's partition |
| Operational complexity | Low | Medium (new branch = new partition) |
| Archival | Date-based only | Date + branch based |
| Cross-branch queries | Simple | Requires UNION ALL |

**Decision**: We will NOT use LIST partitioning by branch_id in the near term. The branch count is small (single digits), and the RLS filter is already efficient. LIST partitioning adds operational overhead (new branch = new partition) without significant benefit at this scale. This can be revisited if branch count exceeds 20+.

### 4.3 Admin-Only RLS Tables

Some tables (fixed_assets, consolidation_runs, bank_reconciliations, etc.) have admin-only RLS policies (`current_setting('app.is_admin', true) = 'true'`). These are safe to partition because:
- RLS policies carry over to partitions automatically
- Admin-only access means no branch-scoped partition pruning benefit
- Date-based partitioning still benefits archival and vacuum

---

## 5. Table Classification

### 5.1 Tier 1 — Strongest Partitioning Candidates

High-volume, append-mostly, date-range queries, low FK risk.

| Table | Partition Key | Strategy | FK Children | Risk | Rationale |
|-------|--------------|----------|-------------|------|-----------|
| `financial_audit_log` | `created_at` | RANGE monthly | 0 | **LOW** | Append-only, immutable, fastest-growing table. No FK children. Date-range queries dominant. |
| `user_audit_log` | `created_at` | RANGE monthly | 0 | **LOW** | Append-only audit log. No FK children. |
| `stock_take_audit_log` | `created_at` | RANGE monthly | 0 | **LOW** | Append-only. No FK children. |
| `stock_adjustment_audit_log` | `created_at` | RANGE monthly | 0 | **LOW** | Append-only. No FK children. |
| `branch_demand_audit_log` | `created_at` | RANGE monthly | 0 | **LOW** | Append-only. No FK children. |
| `journal_posting_logs` | `performed_at` | RANGE monthly | 0 | **LOW** | Append-only log. No FK children. |

### 5.2 Tier 2 — Sub-Ledger Tables

High-volume, date-range queries (aging reports), moderate FK risk.

| Table | Partition Key | Strategy | FK Children | Risk | Rationale |
|-------|--------------|----------|-------------|------|-----------|
| `customer_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Denormalized sub-ledger. No FK children pointing TO it. FK references journal_entries but that's inbound. |
| `supplier_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Same pattern as customer_ledger. |
| `employee_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Lower volume but same pattern. |
| `cash_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Branch cash tracking. |
| `branch_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Intercompany tracking. |

### 5.3 Tier 3 — Transaction Header Tables

Moderate volume, date-range queries, but some have FK children.

| Table | Partition Key | Strategy | FK Children | Risk | Rationale |
|-------|--------------|----------|-------------|------|-----------|
| `customer_payments` | `payment_date` | RANGE monthly | 2 (settlements, allocations) | **MEDIUM** | Moderate volume. Two child tables. |
| `supplier_payments` | `payment_date` | RANGE monthly | 1 (settlements) | **MEDIUM** | Same pattern as customer_payments. |
| `money_transfers` | `transfer_date` | RANGE monthly | 0 | **LOW** | Dual-branch RLS. No FK children. |
| `employee_transactions` | `transaction_date` | RANGE monthly | 0 | **LOW** | No FK children. |
| `other_incomes` | `income_date` | RANGE monthly | 0 | **LOW** | No FK children. |
| `other_expenses` | `expense_date` | RANGE monthly | 0 | **LOW** | No FK children. |
| `sales_challans` | `challan_date` | RANGE monthly | 1 (challan_items) | **MEDIUM** | Child of sales_invoices (already partitioned). |
| `sales_returns` | `return_date` | RANGE monthly | 1 (return_items) | **MEDIUM** | Lower volume. |
| `purchase_receives` | `receive_date` | RANGE monthly | 1 (receive_items) | **MEDIUM** | Moderate volume. |
| `purchase_returns` | `return_date` | RANGE monthly | 1 (return_items) | **MEDIUM** | Low volume. |
| `warehouse_transfers` | `transfer_date` | RANGE monthly | 1 (transfer_items) | **MEDIUM** | Dual-branch RLS. |
| `damage_invoices` | `damage_date` | RANGE monthly | 1 (damage_items) | **MEDIUM** | Low-moderate volume. |
| `manual_journals` | `journal_date` | RANGE monthly | 1 (manual_journal_lines) | **MEDIUM** | Low volume. |
| `stock_adjustments` | `adjustment_date` | RANGE monthly | 1 (adjustment_items) | **MEDIUM** | Low-moderate volume. |
| `stock_take_sessions` | `session_date` | RANGE monthly | 2 (warehouses, items) | **MEDIUM** | Low volume. |

### 5.4 Tier 4 — Critical Accounting Core (Highest Risk)

| Table | Partition Key | Strategy | FK Children | Risk | Rationale |
|-------|--------------|----------|-------------|------|-----------|
| `journal_entries` | `entry_date` | RANGE monthly | **27+** | **CRITICAL** | Core accounting ledger. Every transaction creates entries. 27+ child tables reference it. |
| `journal_lines` | `created_at` | RANGE monthly | 0 | **MEDIUM** | Child of journal_entries. Partitioning journal_lines is simpler but depends on journal_entries being partitioned first for consistency. |

### 5.5 Tier 5 — Time-Series Summary Tables

| Table | Partition Key | Strategy | FK Children | Risk | Rationale |
|-------|--------------|----------|-------------|------|-----------|
| `daily_warehouse_stock_summary` | `summary_date` | RANGE monthly | 0 | **LOW** | One row per product × warehouse × day. Grows fast. No FK children. |

### 5.6 Tier 6 — No Partitioning Needed

All master/reference tables (24 tables), configuration tables (17 tables), and junction/child tables that are too small to benefit. These include: `branches`, `employees`, `users`, `products`, `ledgers`, `warehouses`, `menus`, `system_policies`, `sales_invoice_items`, `purchase_order_items`, etc.

---

## 6. Foreign Key Dependency Analysis

### 6.1 The `journal_entries` Dependency Graph

This is the single most critical dependency in the system. If `journal_entries` is partitioned, **27+ child tables** must convert their declarative FK to trigger-based FK:

```
journal_entries (PARTITIONED by entry_date)
│
├── journal_lines (journal_entry_id)
├── journal_posting_logs (journal_entry_id)
├── manual_journals (journal_entry_id) → manual_journal_lines
├── customer_payments (journal_entry_id) → customer_payment_settlements
├── supplier_payments (journal_entry_id) → supplier_payment_settlements
├── money_transfers (journal_entry_id)
├── other_incomes (journal_entry_id)
├── other_expenses (journal_entry_id)
├── employee_transactions (journal_entry_id)
├── sales_invoices (journal_entry_id, cogs_journal_entry_id) [ALREADY PARTITIONED]
├── sales_challans (journal_entry_id, adjustment_journal_entry_id)
├── sales_returns (journal_entry_id, cogs_journal_entry_id)
├── purchase_receives (journal_entry_id)
├── purchase_returns (journal_entry_id)
├── stock_adjustments (journal_entry_id)
├── stock_take_sessions (journal_entry_id)
├── warehouse_transfers (journal_entry_id, journal_entry_id_debtor)
├── damage_invoices (journal_entry_id)
├── branch_demands (journal_entry_id)
├── branch_ledger (journal_entry_id)
├── cash_ledger (journal_entry_id)
├── bank_reconciliations (journal_entry_id)
├── asset_depreciation_schedules (journal_entry_id)
├── asset_disposals (journal_entry_id)
├── elimination_entries (journal_entry_id)
├── branch_demand_repricing (journal_entry_id)
└── branch_demand_money_transfer_settlements (journal_entry_id)
```

### 6.2 FK Conversion Complexity Matrix

| Child Table | FK Column(s) | Partition Key Access | Complexity |
|-------------|-------------|---------------------|------------|
| `journal_lines` | `journal_entry_id` | Can join to get `entry_date` | Low — same pattern as sales_invoice_items |
| `sales_invoices` | `journal_entry_id`, `cogs_journal_entry_id` | Has `invoice_date` (approx = entry_date) | Medium — already partitioned, dual FK |
| `customer_payments` | `journal_entry_id` | Has `payment_date` | Low |
| `supplier_payments` | `journal_entry_id` | Has `payment_date` | Low |
| `money_transfers` | `journal_entry_id` | Has `transfer_date` | Low |
| `branch_ledger` | `journal_entry_id` | Has `transaction_date` | Low |
| `cash_ledger` | `journal_entry_id` | Has `transaction_date` | Low |
| Others | `journal_entry_id` | Various date columns | Low-Medium |

### 6.3 Key Insight

Most child tables already have their own date column that correlates with `journal_entries.entry_date`. The trigger-based FK can use the child's own date column to find the correct partition, avoiding the need for a composite FK like `(journal_entry_id, entry_date)`.

---

## 7. Phase Breakdown

### Phase 1: Audit Log Partitioning (Lowest Risk)

**Goal**: Partition all 6 audit/log tables that have zero FK children.  
**Duration**: 1-2 days  
**Risk**: LOW  
**Downtime**: Minimal (attach partition with brief lock)

| Step | Action | Details |
|------|--------|---------|
| 1.1 | Create partitioned parent for `financial_audit_log` | Rename existing table, create new partitioned parent, attach old data as partition |
| 1.2 | Configure `pg_partman` for `financial_audit_log` | Auto-create monthly partitions, set retention policy |
| 1.3 | Repeat for `user_audit_log` | Same pattern |
| 1.4 | Repeat for `stock_take_audit_log` | Same pattern |
| 1.5 | Repeat for `stock_adjustment_audit_log` | Same pattern |
| 1.6 | Repeat for `branch_demand_audit_log` | Same pattern |
| 1.7 | Repeat for `journal_posting_logs` | Same pattern |
| 1.8 | Add default partitions for all | Catch rows with unexpected dates |
| 1.9 | Update Laravel models | No changes needed — Eloquent sees the parent table |
| 1.10 | Verify query performance | Run `EXPLAIN ANALYZE` on date-range queries |

**Migration Strategy (per table):**

```sql
-- Step 1: Rename existing table
ALTER TABLE financial_audit_log RENAME TO financial_audit_log_old;

-- Step 2: Create new partitioned parent
CREATE TABLE financial_audit_log (
    id BIGINT NOT NULL,
    -- ... all columns ...
    created_at TIMESTAMP NOT NULL,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Step 3: Create default partition
CREATE TABLE financial_audit_log_default PARTITION OF financial_audit_log DEFAULT;

-- Step 4: Attach old data as initial partition
CREATE TABLE financial_audit_log_pre2026 PARTITION OF financial_audit_log
    FOR VALUES FROM ('2020-01-01') TO ('2026-01-01');
INSERT INTO financial_audit_log SELECT * FROM financial_audit_log_old
    WHERE created_at < '2026-01-01';

-- Step 5: Create monthly partitions for 2026
CREATE TABLE financial_audit_log_2026_01 PARTITION OF financial_audit_log
    FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
-- ... repeat for each month ...

-- Step 6: Migrate remaining data
INSERT INTO financial_audit_log SELECT * FROM financial_audit_log_old
    WHERE created_at >= '2026-01-01';

-- Step 7: Register with pg_partman
SELECT partman.create_parent(
    p_parent_table := 'public.financial_audit_log',
    p_control := 'created_at',
    p_type := 'range',
    p_interval := '1 month',
    p_premake := 6
);

-- Step 8: Drop old table
DROP TABLE financial_audit_log_old;
```

**Validation:**
- All existing queries work unchanged
- Date-range queries show partition pruning in `EXPLAIN ANALYZE`
- `pg_partman` auto-creates next month's partition
- RLS policies (if any) still work

---

### Phase 2: Sub-Ledger Partitioning

**Goal**: Partition all 5 sub-ledger tables.  
**Duration**: 2-3 days  
**Risk**: LOW  
**Prerequisite**: Phase 1 complete

| Step | Action | Details |
|------|--------|---------|
| 2.1 | Partition `customer_ledger` by `transaction_date` | Core AR sub-ledger. Benefits aging report queries. |
| 2.2 | Partition `supplier_ledger` by `transaction_date` | Core AP sub-ledger. Benefits aging report queries. |
| 2.3 | Partition `employee_ledger` by `transaction_date` | Lower volume but same pattern. |
| 2.4 | Partition `cash_ledger` by `transaction_date` | Branch cash tracking. |
| 2.5 | Partition `branch_ledger` by `transaction_date` | Intercompany tracking. |
| 2.6 | Configure `pg_partman` for all 5 tables | Monthly auto-creation |
| 2.7 | Update materialized views | `mv_ar_aging`, `mv_ap_aging` reference sub-ledgers |
| 2.8 | Verify aging report performance | Measure before/after query times |

**Key Consideration**: Sub-ledger tables have FK references TO `journal_entries` (inbound). This does NOT require trigger-based FK conversion — it's a standard FK from a non-partitioned table to a non-partitioned table (journal_entries is not yet partitioned in this phase).

**Validation:**
- AR/AP aging reports show partition pruning
- `customer_ledger` queries with `WHERE transaction_date BETWEEN ...` only scan relevant partitions
- Materialized view refresh still works

---

### Phase 3: Time-Series Summary Partitioning

**Goal**: Partition `daily_warehouse_stock_summary` by `summary_date`.  
**Duration**: 1 day  
**Risk**: LOW  
**Prerequisite**: Phase 1 complete

| Step | Action | Details |
|------|--------|---------|
| 3.1 | Partition `daily_warehouse_stock_summary` by `summary_date` | One row per product × warehouse × day. Grows fast. |
| 3.2 | Configure `pg_partman` | Monthly auto-creation |
| 3.3 | Add retention policy | Auto-detach partitions older than 24 months |
| 3.4 | Verify stock summary queries | Dashboard queries should show pruning |

**Key Consideration**: This table has a composite PK `(warehouse_id, product_id, summary_date)`. After partitioning, the PK becomes `(warehouse_id, product_id, summary_date)` — `summary_date` is already in the PK, so no change needed.

---

### Phase 4: Transaction Header Partitioning (Low-FK Children)

**Goal**: Partition transaction header tables with 0-1 FK children.  
**Duration**: 3-5 days  
**Risk**: LOW-MEDIUM  
**Prerequisite**: Phase 2 complete

| Step | Action | Details |
|------|--------|---------|
| 4.1 | Partition `money_transfers` by `transfer_date` | No FK children. Dual-branch RLS. |
| 4.2 | Partition `employee_transactions` by `transaction_date` | No FK children. |
| 4.3 | Partition `other_incomes` by `income_date` | No FK children. |
| 4.4 | Partition `other_expenses` by `expense_date` | No FK children. |
| 4.5 | Partition `sales_returns` by `return_date` | 1 child: `sales_return_items`. Convert to trigger FK. |
| 4.6 | Partition `purchase_receives` by `receive_date` | 1 child: `purchase_receive_items`. Convert to trigger FK. |
| 4.7 | Partition `purchase_returns` by `return_date` | 1 child: `purchase_return_items`. Convert to trigger FK. |
| 4.8 | Partition `damage_invoices` by `damage_date` | 1 child: `damage_invoice_items`. Convert to trigger FK. |
| 4.9 | Partition `manual_journals` by `journal_date` | 1 child: `manual_journal_lines`. Convert to trigger FK. |
| 4.10 | Configure `pg_partman` for all tables | Monthly auto-creation |
| 4.11 | Verify all transaction flows | Test each transaction type end-to-end |

**Trigger FK Pattern for Single-Child Tables:**

When a transaction header table (e.g., `sales_returns`) is partitioned, its child table (`sales_return_items`) must convert the FK. The pattern is:

```sql
-- Drop declarative FK
ALTER TABLE sales_return_items DROP CONSTRAINT sales_return_items_sales_return_id_fkey;

-- Create trigger function
CREATE OR REPLACE FUNCTION trg_sri_sales_return_fk()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.sales_return_id IS NOT NULL THEN
        IF NOT EXISTS (
            SELECT 1 FROM sales_returns
            WHERE id = NEW.sales_return_id
        ) THEN
            RAISE EXCEPTION 'FK violation: sales_returns(id=%) not found', NEW.sales_return_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_sri_sales_return_fk
    BEFORE INSERT OR UPDATE ON sales_return_items
    FOR EACH ROW EXECUTE FUNCTION trg_sri_sales_return_fk();
```

---

### Phase 5: Transaction Header Partitioning (Multi-FK Children)

**Goal**: Partition transaction header tables with 2+ FK children.  
**Duration**: 3-5 days  
**Risk**: MEDIUM  
**Prerequisite**: Phase 4 complete

| Step | Action | Details |
|------|--------|---------|
| 5.1 | Partition `customer_payments` by `payment_date` | 2 children: `customer_payment_settlements`, `invoice_payment_allocations`. Convert to trigger FK. |
| 5.2 | Partition `supplier_payments` by `payment_date` | 1 child: `supplier_payment_settlements`. Convert to trigger FK. |
| 5.3 | Partition `sales_challans` by `challan_date` | 1 child: `sales_challan_items`. Convert to trigger FK. |
| 5.4 | Partition `warehouse_transfers` by `transfer_date` | 1 child: `warehouse_transfer_items`. Convert to trigger FK. |
| 5.5 | Partition `stock_adjustments` by `adjustment_date` | 1 child: `stock_adjustment_items`. Note: `stock_adjustment_items` already has trigger FK to `stock_transactions`. |
| 5.6 | Partition `stock_take_sessions` by `session_date` | 2 children: `stock_take_warehouses`, `stock_take_items`. Convert to trigger FK. |
| 5.7 | Configure `pg_partman` for all tables | Monthly auto-creation |
| 5.8 | Verify all transaction flows | Test each transaction type end-to-end |

---

### Phase 6: Journal Entries Partitioning (Critical)

**Goal**: Partition `journal_entries` by `entry_date` — the most impactful and riskiest partitioning operation.  
**Duration**: 5-7 days  
**Risk**: CRITICAL  
**Prerequisite**: Phases 1-5 complete, extensive testing on staging

This is the most complex phase because `journal_entries` has 27+ child tables. The approach is:

1. **Do NOT convert all 27 FKs at once.** Instead, convert them in batches.
2. **Use the existing pattern** from `sales_invoices` and `stock_transactions` partitioning.
3. **Test thoroughly** on staging before production.

| Step | Action | Details |
|------|--------|---------|
| 6.1 | Create staging replica | Full copy of production database for testing |
| 6.2 | Partition `journal_entries` on staging | Rename, create partitioned parent, attach old data |
| 6.3 | Convert FKs — Batch 1 (core accounting) | `journal_lines`, `journal_posting_logs` |
| 6.4 | Convert FKs — Batch 2 (payment tables) | `customer_payments`, `supplier_payments`, `money_transfers` |
| 6.5 | Convert FKs — Batch 3 (income/expense) | `other_incomes`, `other_expenses`, `employee_transactions` |
| 6.6 | Convert FKs — Batch 4 (sales/purchase) | `sales_invoices` (already partitioned, trigger FK already exists), `sales_challans`, `sales_returns`, `purchase_receives`, `purchase_returns` |
| 6.7 | Convert FKs — Batch 5 (inventory) | `stock_adjustments`, `stock_take_sessions`, `warehouse_transfers`, `damage_invoices`, `branch_demands` |
| 6.8 | Convert FKs — Batch 6 (sub-ledgers) | `branch_ledger`, `cash_ledger` |
| 6.9 | Convert FKs — Batch 7 (advanced modules) | `manual_journals`, `bank_reconciliations`, `asset_depreciation_schedules`, `asset_disposals`, `elimination_entries`, `branch_demand_repricing` |
| 6.10 | Run full integration test suite | All transaction types, all reports, all RLS checks |
| 6.11 | Apply to production | During maintenance window |
| 6.12 | Configure `pg_partman` | Monthly auto-creation |

**Special Case: `sales_invoices` already has a trigger FK to `journal_entries`**

The `sales_invoices` table is already partitioned and already uses trigger-based FKs. Its FK to `journal_entries` needs to be updated to handle the partitioned parent. The existing trigger function `trg_si_journal_entry_fk` must be modified to include the partition key:

```sql
-- Before (current)
IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id) THEN ...

-- After (partitioned journal_entries)
IF NOT EXISTS (
    SELECT 1 FROM journal_entries
    WHERE id = NEW.journal_entry_id
      AND entry_date = NEW.invoice_date  -- approximate match
) THEN ...
```

**Note**: The exact partition key value (`entry_date`) may not exactly match the child's date column. A safe approach is to NOT include the partition key in the FK check and let PostgreSQL search all partitions. This is slightly slower but correct. The existing `sales_invoices` trigger FKs already use this pattern (no partition key in the FK check).

---

### Phase 7: Journal Lines Partitioning

**Goal**: Partition `journal_lines` by `created_at`.  
**Duration**: 2-3 days  
**Risk**: MEDIUM  
**Prerequisite**: Phase 6 complete

| Step | Action | Details |
|------|--------|---------|
| 7.1 | Partition `journal_lines` by `created_at` | Monthly RANGE. No FK children. |
| 7.2 | Update trigger FK from `journal_lines` to `journal_entries` | Already converted in Phase 6. |
| 7.3 | Configure `pg_partman` | Monthly auto-creation |
| 7.4 | Verify all accounting queries | Trial balance, balance sheet, P&L |

**Key Consideration**: `journal_lines` has a composite UNIQUE constraint `(journal_entry_id, ledger_id)` for duplicate prevention. After partitioning, this must include `created_at`:
```sql
UNIQUE (journal_entry_id, ledger_id, created_at)
```
This is acceptable because the combination is still unique — no two lines for the same journal entry and ledger can be created at the exact same timestamp.

---

### Phase 8: Archival & Retention Policies

**Goal**: Implement automated archival and retention policies for all partitioned tables.  
**Duration**: 3-5 days  
**Risk**: LOW (detaching partitions is non-destructive)  
**Prerequisite**: Phases 1-7 complete

| Step | Action | Details |
|------|--------|---------|
| 8.1 | Define retention periods per table | See Section 8.2 |
| 8.2 | Configure `pg_partman` retention policies | Auto-detach old partitions |
| 8.3 | Create archival schema (`archive`) | Separate schema for detached partitions |
| 8.4 | Create archival stored procedures | Move detached partitions to archive schema |
| 8.5 | Create export procedures | Export old partitions to CSV/Parquet |
| 8.6 | Create restore procedures | Import archived data back if needed |
| 8.7 | Test archival and restore | Verify data integrity after archival |
| 8.8 | Set up monitoring | Alert when partitions approach retention limit |

---

### Phase 9: Performance Optimization & Monitoring

**Goal**: Fine-tune partitioning, add monitoring, and optimize queries.  
**Duration**: 3-5 days  
**Risk**: LOW  
**Prerequisite**: Phase 8 complete

| Step | Action | Details |
|------|--------|---------|
| 9.1 | Add partition-aware indexes | Composite indexes matching common query patterns |
| 9.2 | Create partition statistics views | Monitor partition sizes, row counts, vacuum status |
| 9.3 | Implement partition pruning validation | Automated test that queries show pruning in EXPLAIN |
| 9.4 | Optimize materialized view refreshes | Ensure MV refreshes use partition pruning |
| 9.5 | Create partition health dashboard | Laravel page showing partition status |
| 9.6 | Set up automated VACUUM per partition | Per-partition vacuum settings |
| 9.7 | Document operational procedures | How to add/remove partitions, handle edge cases |

---

## 8. Archival Strategy

### 8.1 Archival Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    PostgreSQL (Primary)                       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ 2026-07      │  │ 2026-08      │  │ 2026-09      │ ...   │
│  │ (active)     │  │ (active)     │  │ (active)     │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2026-01-06   │  │ 2025-07-12   │  ← pg_partman retention │
│  │ (read-only)  │  │ (read-only)  │    auto-detaches older   │
│  └──────────────┘  └──────────────┘    partitions             │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  Schema: archive                                             │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2024-01-06   │  │ 2023-01-12   │  ← detached, still     │
│  │ (detached)   │  │ (detached)   │    queryable if needed   │
│  └──────────────┘  └──────────────┘                         │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  Cold Storage (S3 / Local NAS)                               │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2022.parquet │  │ 2021.parquet │  ← exported for long-   │
│  │              │  │              │    term compliance       │
│  └──────────────┘  └──────────────┘                         │
└──────────────────────────────────────────────────────────────┘
```

### 8.2 Retention Periods

| Table Category | Active (Hot) | Read-Only (Warm) | Archived (Cold) | Purge |
|---------------|-------------|-------------------|-----------------|-------|
| Financial audit logs | 12 months | 7 years | 7+ years | Never (compliance) |
| User audit logs | 3 months | 1 year | 1+ years | After 2 years |
| Sub-ledgers | 12 months | 7 years | 7+ years | Never (compliance) |
| Journal entries | 12 months | 7 years | 7+ years | Never (compliance) |
| Transaction headers | 12 months | 7 years | 7+ years | Never (compliance) |
| Stock transactions | 12 months | 3 years | 7+ years | After 7 years |
| Daily warehouse summary | 3 months | 12 months | 3 years | After 3 years |
| Stock audit logs | 3 months | 1 year | 3 years | After 3 years |

### 8.3 Archival Procedures

#### 8.3.1 Detach and Move to Archive Schema

```sql
-- Detach partition from parent (brief ACCESS EXCLUSIVE lock)
ALTER TABLE financial_audit_log DETACH PARTITION financial_audit_log_2024_01;

-- Move to archive schema
ALTER TABLE financial_audit_log_2024_01 SET SCHEMA archive;

-- Rename for clarity
ALTER TABLE archive.financial_audit_log_2024_01 
    RENAME TO financial_audit_log_2024_01;
```

#### 8.3.2 Export to Cold Storage

```sql
-- Export to CSV
COPY financial_audit_log_2024_01 TO '/archive/csv/financial_audit_log_2024_01.csv' WITH CSV HEADER;

-- Or use pg_dump for a single partition
pg_dump -t financial_audit_log_2024_01 dbname > /archive/dump/financial_audit_log_2024_01.sql
```

#### 8.3.3 Restore from Archive

```sql
-- Import from archive schema
ALTER TABLE archive.financial_audit_log_2024_01 SET SCHEMA public;

-- Re-attach to parent
ALTER TABLE financial_audit_log ATTACH PARTITION financial_audit_log_2024_01
    FOR VALUES FROM ('2024-01-01') TO ('2024-02-01');
```

### 8.4 pg_partman Retention Configuration

```sql
-- Configure retention for financial_audit_log (keep 84 months = 7 years)
UPDATE partman.part_config
SET retention = '84 months',
    retention_keep_table = false,  -- true = keep in archive schema; false = DROP
    retention_schema = 'archive'   -- Move to this schema instead of dropping
WHERE parent_table = 'public.financial_audit_log';

-- For daily_warehouse_stock_summary (keep 36 months = 3 years)
UPDATE partman.part_config
SET retention = '36 months',
    retention_keep_table = false,
    retention_schema = 'archive'
WHERE parent_table = 'public.daily_warehouse_stock_summary';
```

---

## 9. Performance Validation Criteria

### 9.1 Before/After Benchmarks

For each phase, measure the following before and after partitioning:

| Metric | Query | Expected Improvement |
|--------|-------|---------------------|
| Date-range scan | `SELECT * FROM table WHERE date_col BETWEEN '2026-07-01' AND '2026-07-31'` | 80-95% I/O reduction (partition pruning) |
| Full table scan | `SELECT COUNT(*) FROM table` | No change (same data) |
| Single-row lookup | `SELECT * FROM table WHERE id = 123` | Slight slowdown (must search partitions) |
| VACUUM time | `VACUUM ANALYZE table` | 70-90% reduction for old partitions |
| Index size | `pg_relation_size(index)` | Per-partition indexes are smaller |
| Aging report | `SELECT * FROM customer_ledger WHERE transaction_date >= '2026-01-01'` | 80%+ reduction |

### 9.2 Partition Pruning Validation

After each phase, verify that queries show partition pruning:

```sql
EXPLAIN ANALYZE 
SELECT * FROM financial_audit_log 
WHERE created_at BETWEEN '2026-07-01' AND '2026-07-31';

-- Should show: "Subplans Removed: N" (N = number of partitions pruned)
-- Should NOT show: "Seq Scan on financial_audit_log" (full table scan)
```

### 9.3 RLS Validation

After each phase, verify that RLS policies still work:

```sql
-- As a non-admin user
SET app.branch_id = '1';
SET app.is_admin = 'false';

SELECT * FROM customer_ledger WHERE transaction_date = '2026-07-15';
-- Should only return rows for branch_id = 1

-- As admin
SET app.is_admin = 'true';
SELECT * FROM customer_ledger WHERE transaction_date = '2026-07-15';
-- Should return all branches
```

---

## 10. Rollback Strategy

### 10.1 Per-Phase Rollback

Each phase has a specific rollback procedure:

| Phase | Rollback Method |
|-------|----------------|
| 1-3 (Audit logs, sub-ledgers, summary) | `ALTER TABLE ... DETACH PARTITION` all partitions, then `INSERT INTO original_table SELECT * FROM partition` for each partition. Drop partitioned parent, rename original. |
| 4-5 (Transaction headers) | Same as above, plus restore declarative FKs (drop trigger FK functions, re-add ALTER TABLE ADD CONSTRAINT). |
| 6 (Journal entries) | Same as above, but must restore ALL 27+ declarative FKs. This is the riskiest rollback. |
| 7 (Journal lines) | Same as Phase 1-3. |
| 8 (Archival) | Re-attach archived partitions from archive schema. |

### 10.2 General Rollback Pattern

```sql
-- 1. Create a non-partitioned table with the original structure
CREATE TABLE table_name_rollback (LIKE table_name INCLUDING ALL);

-- 2. Move all data from partitions to the rollback table
INSERT INTO table_name_rollback SELECT * FROM table_name_YYYY_MM;  -- for each partition
INSERT INTO table_name_rollback SELECT * FROM table_name_default;

-- 3. Drop the partitioned parent
DROP TABLE table_name;

-- 4. Rename the rollback table
ALTER TABLE table_name_rollback RENAME TO table_name;

-- 5. Restore declarative FKs (if converted to triggers)
-- Re-run the original migration's FK creation SQL
```

### 10.3 Safety Measures

1. **Always take a full backup** before each phase
2. **Test on staging first** — never apply a phase to production without staging validation
3. **Run in a transaction** where possible — `BEGIN; ... ROLLBACK;` for testing
4. **Keep the old table** until the new partitioned table is verified — don't `DROP TABLE ..._old` until the next day
5. **Monitor for 24 hours** after each phase — check error logs, slow queries, and application health

---

## 11. Risk Register

| # | Risk | Probability | Impact | Mitigation |
|---|------|-------------|--------|------------|
| R1 | FK conversion breaks data integrity | Medium | Critical | Test trigger FKs on staging with full dataset. Add constraint checks post-migration. |
| R2 | Partition pruning not working (full scans) | Low | High | Verify with `EXPLAIN ANALYZE` after each phase. Add partition-aware indexes. |
| R3 | pg_partman fails to create future partitions | Low | High | Monitor `partman.part_config` daily. Set up alerting. |
| R4 | RLS policies stop working after partitioning | Very Low | Critical | Test RLS after each phase. RLS at parent level applies to all partitions. |
| R5 | Application queries break due to composite PK | Medium | High | Laravel Eloquent doesn't use composite PKs. Ensure model `$primaryKey` is set correctly. |
| R6 | Detach partition locks block production | Low | Medium | Detach during low-traffic periods. Use `ALTER TABLE ... DETACH PARTITION ... CONCURRENTLY` (PG 14+). |
| R7 | Journal entries partitioning takes too long | Medium | High | Pre-create partitions in advance. Use `ATTACH PARTITION` instead of `CREATE TABLE ... PARTITION OF`. |
| R8 | Archival of wrong partition | Low | Critical | Double-check partition date ranges before detaching. Never auto-drop without review. |
| R9 | Laravel model changes break application | Low | High | Test all CRUD operations after partitioning. Eloquent should work unchanged since it queries the parent table. |
| R10 | Materialized view refresh fails on partitioned tables | Low | Medium | Test MV refreshes after each phase. Refresh queries should use partition pruning. |

---

## Appendix A: Complete Table Partitioning Priority Matrix

| Priority | Table | Partition Key | FK Children | Risk | Phase |
|----------|-------|--------------|-------------|------|-------|
| 1 | `financial_audit_log` | `created_at` | 0 | LOW | 1 |
| 2 | `user_audit_log` | `created_at` | 0 | LOW | 1 |
| 3 | `stock_take_audit_log` | `created_at` | 0 | LOW | 1 |
| 4 | `stock_adjustment_audit_log` | `created_at` | 0 | LOW | 1 |
| 5 | `branch_demand_audit_log` | `created_at` | 0 | LOW | 1 |
| 6 | `journal_posting_logs` | `performed_at` | 0 | LOW | 1 |
| 7 | `customer_ledger` | `transaction_date` | 0 | LOW | 2 |
| 8 | `supplier_ledger` | `transaction_date` | 0 | LOW | 2 |
| 9 | `employee_ledger` | `transaction_date` | 0 | LOW | 2 |
| 10 | `cash_ledger` | `transaction_date` | 0 | LOW | 2 |
| 11 | `branch_ledger` | `transaction_date` | 0 | LOW | 2 |
| 12 | `daily_warehouse_stock_summary` | `summary_date` | 0 | LOW | 3 |
| 13 | `money_transfers` | `transfer_date` | 0 | LOW | 4 |
| 14 | `employee_transactions` | `transaction_date` | 0 | LOW | 4 |
| 15 | `other_incomes` | `income_date` | 0 | LOW | 4 |
| 16 | `other_expenses` | `expense_date` | 0 | LOW | 4 |
| 17 | `sales_returns` | `return_date` | 1 | MEDIUM | 4 |
| 18 | `purchase_receives` | `receive_date` | 1 | MEDIUM | 4 |
| 19 | `purchase_returns` | `return_date` | 1 | MEDIUM | 4 |
| 20 | `damage_invoices` | `damage_date` | 1 | MEDIUM | 4 |
| 21 | `manual_journals` | `journal_date` | 1 | MEDIUM | 4 |
| 22 | `customer_payments` | `payment_date` | 2 | MEDIUM | 5 |
| 23 | `supplier_payments` | `payment_date` | 1 | MEDIUM | 5 |
| 24 | `sales_challans` | `challan_date` | 1 | MEDIUM | 5 |
| 25 | `warehouse_transfers` | `transfer_date` | 1 | MEDIUM | 5 |
| 26 | `stock_adjustments` | `adjustment_date` | 1 | MEDIUM | 5 |
| 27 | `stock_take_sessions` | `session_date` | 2 | MEDIUM | 5 |
| 28 | `journal_entries` | `entry_date` | 27+ | **CRITICAL** | 6 |
| 29 | `journal_lines` | `created_at` | 0 | MEDIUM | 7 |

---

## Appendix B: pg_partman Configuration Reference

### B.1 Initial Setup (if not already installed)

```sql
CREATE EXTENSION IF NOT EXISTS pg_partman;
```

### B.2 Register a Partitioned Table with pg_partman

```sql
SELECT partman.create_parent(
    p_parent_table := 'public.table_name',
    p_control := 'date_column',       -- Partition key column
    p_type := 'range',                 -- RANGE partitioning
    p_interval := '1 month',           -- Monthly partitions
    p_premake := 6,                    -- Create 6 months ahead
    p_start_partition := '2026-01-01'  -- Start from this date
);
```

### B.3 Configure Retention

```sql
UPDATE partman.part_config
SET retention = '84 months',              -- Keep 7 years
    retention_keep_table = true,           -- Keep detached as standalone table
    retention_schema = 'archive'           -- Move to archive schema
WHERE parent_table = 'public.table_name';
```

### B.4 Run pg_partman Maintenance

```sql
-- Manual run (for testing)
SELECT partman.run_maintenance();

-- Schedule via pg_cron (recommended)
SELECT cron.schedule(
    'partman-maintenance',
    '0 2 * * *',  -- Daily at 2 AM
    $$SELECT partman.run_maintenance()$$
);
```

---

## Appendix C: Materialized Views Impacted by Partitioning

| Materialized View | Tables Referenced | Impact | Action Required |
|-------------------|-------------------|--------|-----------------|
| `mv_ledger_balances` | `journal_lines`, `journal_entries` | Partition pruning on refresh | Update refresh query to include date range |
| `mv_ar_aging` | `customer_ledger`, `sales_invoices` | Partition pruning on refresh | Update refresh query to include date range |
| `mv_ap_aging` | `supplier_ledger`, `purchase_receives` | Partition pruning on refresh | Update refresh query to include date range |
| `mv_stock_valuation` | `warehouse_stock`, `stock_transactions` | Already partitioned | No change needed |
| `mv_journal_entry_summary` | `journal_entries`, `journal_lines` | Partition pruning on refresh | Update refresh query to include date range |
| `mv_branch_intercompany` | `branch_ledger`, `money_transfers` | Partition pruning on refresh | Update refresh query to include date range |
| `mv_product_movement_summary` | `stock_transactions` | Already partitioned | No change needed |
| `mv_product_abc_classification` | `stock_transactions` | Already partitioned | No change needed |
| `mv_consolidated_trial_balance` | `journal_entries`, `journal_lines` | Partition pruning on refresh | Update refresh query to include date range |

---

## Appendix D: Phase Execution Checklist

### Before Each Phase

- [ ] Full database backup taken
- [ ] Staging environment updated with latest production data
- [ ] Phase tested on staging successfully
- [ ] Rollback procedure documented and tested
- [ ] Maintenance window scheduled (if needed)
- [ ] Team notified of planned changes

### During Each Phase

- [ ] Migration executed without errors
- [ ] Data integrity verified (row counts match)
- [ ] RLS policies tested (branch-scoped and admin queries)
- [ ] Application queries tested (CRUD operations)
- [ ] `EXPLAIN ANALYZE` shows partition pruning
- [ ] pg_partman configuration verified
- [ ] Materialized view refresh tested

### After Each Phase

- [ ] Monitor error logs for 24 hours
- [ ] Check slow query log for unexpected full scans
- [ ] Verify pg_partman auto-creation (next day)
- [ ] Update documentation with any deviations
- [ ] Commit changes to Git repository

---

## Appendix E: Estimated Timeline

| Phase | Description | Duration | Cumulative |
|-------|-------------|----------|------------|
| 1 | Audit Log Partitioning | 1-2 days | 1-2 days |
| 2 | Sub-Ledger Partitioning | 2-3 days | 3-5 days |
| 3 | Time-Series Summary Partitioning | 1 day | 4-6 days |
| 4 | Transaction Headers (Low FK) | 3-5 days | 7-11 days |
| 5 | Transaction Headers (Multi FK) | 3-5 days | 10-16 days |
| 6 | Journal Entries (Critical) | 5-7 days | 15-23 days |
| 7 | Journal Lines | 2-3 days | 17-26 days |
| 8 | Archival & Retention | 3-5 days | 20-31 days |
| 9 | Performance Optimization | 3-5 days | 23-36 days |

**Total estimated duration**: 23-36 working days (spread over 2-3 months with staging/testing gaps)

---

*End of Phase 10.1 Partitioning & Archival Plan*
