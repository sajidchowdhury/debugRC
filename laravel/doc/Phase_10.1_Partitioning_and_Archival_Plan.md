# Phase 10.1: Database Partitioning & Archival Plan

**Project**: Remote Center ERP (debugRC)  
**Database**: PostgreSQL 16+  
**Current State**: 70+ tables, 2 already partitioned, ~40 RLS-protected  
**Date**: 2026-08-02  
**Revision**: 2.0 — Senior Architect Review  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Current Partitioning State](#2-current-partitioning-state)
3. [Partitioning Principles & Constraints](#3-partitioning-principles--constraints)
4. [RLS + Partitioning Interaction](#4-rls--partitioning-interaction)
5. [Table Classification](#5-table-classification)
6. [Foreign Key Dependency Analysis](#6-foreign-key-dependency-analysis)
7. [Index Strategy — BRIN + B-Tree Hybrid](#7-index-strategy--brin--b-tree-hybrid)
8. [Long-Term Partition Consolidation](#8-long-term-partition-consolidation)
9. [Partition Health & Monitoring](#9-partition-health--monitoring)
10. [Automated Validation Framework](#10-automated-validation-framework)
11. [Migration Dry-Run Mode](#11-migration-dry-run-mode)
12. [Performance Targets](#12-performance-targets)
13. [Phase Breakdown](#13-phase-breakdown)
14. [Archival Strategy](#14-archival-strategy)
15. [Rollback Strategy](#15-rollback-strategy)
16. [Risk Register](#16-risk-register)
17. [PostgreSQL Configuration Tuning](#17-postgresql-configuration-tuning)

---

## 1. Executive Summary

This document defines a phased approach to implementing database partitioning and archival for the Remote Center ERP system. The goal is to improve query performance on large historical datasets, enable efficient data archival for compliance, and maintain the existing RLS (Row Level Security) architecture without disruption.

### Why Partitioning?

- **Query Performance**: Date-range queries (e.g., "show me this month's journal entries") currently scan entire tables. Partition pruning eliminates irrelevant partitions, reducing I/O by 90%+ for monthly queries.
- **Vacuum Efficiency**: PostgreSQL's VACUUM operates per-partition. Instead of vacuuming a 50M-row table, we vacuum 12 monthly partitions of ~4M rows each. Old partitions that are read-only need almost no vacuuming.
- **Archival & Compliance**: Old partitions can be detached and moved to cold storage (cheaper disks) or exported to Parquet/S3 for long-term retention. This is essential for statutory audit requirements (typically 7+ years of financial records).
- **Index Size Reduction**: Each partition has its own indexes. A 50M-row B-tree index is ~1.5GB; twelve 4M-row indexes are ~120MB each, which fit better in shared_buffers.
- **BRIN Index Opportunity**: Append-only partitioned tables can use BRIN indexes (300x smaller than B-tree) for range queries, a major optimization not available on non-partitioned tables.

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
| **Partition-wise joins are key** | PG 14+ can push joins down to matching partitions when both tables share the same partition key and boundaries — this is critical for journal_entries + journal_lines |

---

## 3. Partitioning Principles & Constraints

### 3.1 Hard Constraints (PostgreSQL Limitations)

1. **PK must include partition key** — All partitioned tables must have a composite primary key that includes the partition column
2. **UNIQUE must include partition key** — No unique constraint can exist without the partition key
3. **Declarative FK cannot reference partitioned tables** — All child tables referencing a partitioned parent must use trigger-based FK enforcement
4. **Partition key cannot be nullable** — The partition column must be NOT NULL
5. **No ALTER TABLE ... ATTACH PARTITION while locks held** — Attaching partitions requires ACCESS EXCLUSIVE lock on the parent (brief but blocks all access)
6. **Partition-wise joins require aligned boundaries** — Both tables must be partitioned on the same key with the same interval for PG 14+ to push joins down to partitions

### 3.2 Design Principles

1. **RANGE by date first** — Monthly RANGE partitioning is the default strategy for all transaction tables. It aligns with accounting periods, fiscal months, and query patterns.
2. **Low-risk tables first** — Start with tables that have no FK children (audit logs) before tackling tables with many FK children (journal_entries).
3. **Preserve RLS** — All RLS policies must continue to work. RLS policies at the parent level automatically apply to all partitions.
4. **pg_partman for auto-creation** — Use `pg_partman` to auto-create future partitions and avoid manual maintenance.
5. **Default partition always** — Every partitioned table must have a default partition to catch rows with unexpected dates.
6. **Online migration** — All migrations must be executable without extended downtime. Use `ATTACH PARTITION` for converting existing tables.
7. **Test on staging first** — Every phase must be tested on a staging database before production.
8. **Co-locate parent-child rows** — When a parent table (e.g., `journal_entries`) and its child table (e.g., `journal_lines`) are both partitioned, they MUST use the same partition key and boundaries so that partition-wise joins work. This means `journal_lines` must be partitioned by `entry_date` (denormalized from parent), NOT by `created_at`.
9. **BRIN indexes on append-only partitions** — Use BRIN indexes instead of B-tree on the partition key column within each partition for append-only tables. BRIN is 100-300x smaller and equally effective for range queries on naturally ordered data.
10. **Partition consolidation for old data** — Monthly partitions older than 3 years should be merged into quarterly partitions; older than 7 years into yearly partitions. This prevents catalog bloat from excessive partition counts.

### 3.3 Naming Conventions

```
Parent table:       <table_name>
Partitions:         <table_name>_YYYY_MM
Default partition:  <table_name>_default
pg_partman template: <table_name>_template
Consolidated:       <table_name>_YYYY_Q1  (quarterly)
Consolidated:       <table_name>_YYYY     (yearly)
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
    ) THEN
        RAISE EXCEPTION 'FK violation: parent_table(id=%) not found',
            NEW.parent_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create trigger
CREATE TRIGGER trg_child_parent_fk
    BEFORE INSERT OR UPDATE ON child_table
    FOR EACH ROW EXECUTE FUNCTION trg_child_parent_fk_check();
```

> **Note**: For trigger-based FKs on partitioned parents, we do NOT include the partition key in the FK check. The trigger simply queries the parent table (which PostgreSQL routes to the correct partition via partition pruning). This is simpler and correct — the existing `sales_invoices` trigger FKs already use this pattern.

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
| `customer_ledger` | `transaction_date` | RANGE monthly | 0 | **LOW** | Denormalized sub-ledger. No FK children pointing TO it. |
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
| `journal_lines` | `entry_date` (denormalized) | RANGE monthly | 0 | **MEDIUM** | Child of journal_entries. MUST use `entry_date` (not `created_at`) for partition-wise joins. |

> **Critical Design Decision**: `journal_lines` must be partitioned by `entry_date` (denormalized from `journal_entries`), NOT by `created_at`. See [Section 6.2](#62-journal_lines-partition-key-entry_date-vs-created_at) for the full analysis.

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
├── journal_lines (journal_entry_id) → MUST also partition by entry_date
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

### 6.2 `journal_lines` Partition Key: `entry_date` vs `created_at`

**This is the single most important design decision in the entire plan.**

#### Option A: Partition by `created_at` (ORIGINAL PLAN — REJECTED)

| Aspect | Impact |
|--------|--------|
| **Data alignment** | A journal entry dated 2026-07-31 and its lines (created at 2026-08-01 00:00:01) would be in **DIFFERENT partitions** |
| **Partition-wise joins** | **BROKEN** — PG 14+ cannot push a join between `journal_entries` (partitioned by `entry_date`) and `journal_lines` (partitioned by `created_at`) down to matching partitions |
| **Trial Balance query** | `SELECT ... FROM journal_entries je JOIN journal_lines jl ON je.id = jl.journal_entry_id WHERE je.entry_date BETWEEN ...` — must scan ALL journal_lines partitions for every journal_entries partition |
| **Balanced entry trigger** | `enforce_balanced_journal_entry()` queries `journal_lines WHERE journal_entry_id = ?` — becomes a cross-partition scan if lines are in a different partition than the entry |
| **Vacuum efficiency** | Old journal_lines partitions cannot be vacuumed independently of journal_entries partitions |
| **Archival** | Cannot detach a month's journal_lines together with its journal_entries — they're in different partitions |

#### Option B: Partition by `entry_date` (ADOPTED)

| Aspect | Impact |
|--------|--------|
| **Data alignment** | A journal entry and ALL its lines are always in the **SAME partition** |
| **Partition-wise joins** | **WORKS** — PG 14+ pushes the join down to matching partitions (both partitioned by `entry_date` with monthly boundaries) |
| **Trial Balance query** | Only scans the relevant month's partitions in both tables — massive I/O reduction |
| **Balanced entry trigger** | Queries stay within a single partition — no cross-partition scan |
| **Vacuum efficiency** | A month's journal_entries and journal_lines can be vacuumed together |
| **Archival** | A month's journal_lines can be detached together with its journal_entries |

#### Implementation: Denormalizing `entry_date` into `journal_lines`

`journal_lines` currently has no `entry_date` column. We must add it:

```sql
-- Add entry_date column to journal_lines
ALTER TABLE journal_lines ADD COLUMN entry_date date NOT NULL DEFAULT CURRENT_DATE;

-- Backfill from parent
UPDATE journal_lines jl
SET entry_date = je.entry_date
FROM journal_entries je
WHERE jl.journal_entry_id = je.id;

-- Remove the default after backfill
ALTER TABLE journal_lines ALTER COLUMN entry_date DROP DEFAULT;

-- Add index for the partition key
CREATE INDEX idx_jl_entry_date ON journal_lines(entry_date);
```

**Cost**: 4 bytes per row (date column). For 50M rows = ~200MB additional storage. This is negligible compared to the performance benefit.

**Application impact**: The `JournalPostingService::createJournalEntry()` method must set `entry_date` on each journal line when creating them. This is a minor code change.

---

## 7. Index Strategy — BRIN + B-Tree Hybrid

### 7.1 Why BRIN Indexes on Partitioned Tables

**BRIN (Block Range Index)** is the most important index optimization for partitioned append-only tables. It is the single biggest performance-per-byte improvement available in PostgreSQL for this workload.

**How BRIN works**: Instead of indexing every row (B-tree), BRIN stores a summary for each block range (default 128 pages = 1MB). For each range, it stores the minimum and maximum value of the indexed column. If a query asks for `entry_date BETWEEN '2026-07-01' AND '2026-07-31'`, BRIN can eliminate entire block ranges that don't contain dates in that range.

**Why BRIN is perfect for partitioned append-only tables**:
- Data is naturally ordered by the partition key (append-only, chronological insertion)
- Within each partition, rows are physically ordered by insertion time, which correlates with the partition date
- Range queries on the partition key are the primary access pattern
- BRIN indexes are **100-300x smaller** than B-tree indexes

**Size comparison** (50M rows, single column):

| Index Type | Size | Lookup Speed | Range Scan Speed |
|-----------|------|-------------|-----------------|
| B-tree | ~1.5 GB | O(log n) — fast | Must scan all matching leaf pages |
| BRIN | ~5 MB | O(n/blocks) — good | Eliminates 95%+ of blocks, then scans remainder |

### 7.2 BRIN Adoption Strategy

| Table | Column | Current Index | Replace With | Reason |
|-------|--------|--------------|-------------|--------|
| `journal_entries` | `entry_date` | B-tree `idx_je_entry_date` | BRIN `idx_je_entry_date_brin` | Partition key, append-only, range queries |
| `journal_entries` | `created_at` | (none) | BRIN `idx_je_created_at_brin` | Audit queries by creation time |
| `journal_lines` | `entry_date` | (new) | BRIN `idx_jl_entry_date_brin` | Partition key, co-located with parent |
| `financial_audit_log` | `created_at` | (none currently) | BRIN `idx_fal_created_at_brin` | Append-only, range queries |
| `user_audit_log` | `created_at` | (none currently) | BRIN `idx_ual_created_at_brin` | Append-only |
| `customer_ledger` | `transaction_date` | B-tree `idx_cl_transaction_date` | BRIN `idx_cl_transaction_date_brin` | Append-only, aging queries |
| `supplier_ledger` | `transaction_date` | B-tree `idx_sl_transaction_date` | BRIN `idx_sl_transaction_date_brin` | Append-only, aging queries |
| `employee_ledger` | `transaction_date` | B-tree `idx_el_transaction_date` | BRIN `idx_el_transaction_date_brin` | Append-only |
| `cash_ledger` | `transaction_date` | B-tree `idx_cashl_transaction_date` | BRIN `idx_cashl_transaction_date_brin` | Append-only |
| `branch_ledger` | `transaction_date` | B-tree `idx_bl_transaction_date` | BRIN `idx_bl_transaction_date_brin` | Append-only |
| `daily_warehouse_stock_summary` | `summary_date` | (none currently) | BRIN `idx_dwss_summary_date_brin` | Append-only, time-series |

### 7.3 When to Keep B-Tree

Do NOT replace B-tree with BRIN when:
- **Point lookups** are the primary access pattern (e.g., `WHERE id = 123`)
- **Uniqueness** must be enforced (UNIQUE constraints require B-tree)
- **Data is NOT naturally ordered** by the indexed column (e.g., `ledger_id` in `journal_lines` — rows are not inserted in ledger_id order)
- **JOIN columns** that need precise lookups (e.g., `journal_entry_id` in `journal_lines`)

### 7.4 BRIN Configuration

```sql
-- Default pages_per_range = 128 (1MB). For append-only tables, this is good.
-- For very large tables with coarser granularity, use 32 or 64.
CREATE INDEX idx_je_entry_date_brin
    ON journal_entries USING BRIN (entry_date)
    WITH (pages_per_range = 32);  -- Finer granularity for critical tables

-- For audit logs, default is fine
CREATE INDEX idx_fal_created_at_brin
    ON financial_audit_log USING BRIN (created_at);
```

### 7.5 Partial + BRIN Combination

Partial indexes and BRIN can be combined for maximum efficiency:

```sql
-- Active (non-reversed) journal entries — only index the ~95% of rows that are live
CREATE INDEX idx_je_active_entry_date_brin
    ON journal_entries USING BRIN (entry_date)
    WHERE is_reversed = false;
```

---

## 8. Long-Term Partition Consolidation

### 8.1 The Problem

After 7-10 years of monthly partitions, each table will have 84-120 partitions. PostgreSQL's partition pruning is efficient, but excessive partition counts cause:

- **Catalog bloat**: Each partition adds ~50 entries to `pg_class`, `pg_attribute`, `pg_depend`, etc. 100 partitions × 50 entries = 5,000 catalog rows per table. With 29 partitioned tables, that's 145,000 catalog rows.
- **Planning time**: The planner must evaluate each partition's constraints during pruning. With 100+ partitions, planning time increases noticeably (10-50ms per query).
- **Lock contention**: `pg_partman` maintenance acquires locks on the parent table. With 100+ partitions, the maintenance window is longer.
- **`max_locks_per_transaction`**: Operations that touch many partitions (e.g., `ALTER TABLE ... DETACH PARTITION` in a loop) may exceed the default 64 lock limit.

### 8.2 Consolidation Strategy

```
Age          Strategy          Partition Count (per table)
0-3 years    Monthly           36
3-7 years    Quarterly         16
7+ years     Yearly            N (where N = number of years retained)
```

**Consolidation reduces partition count from 120 to ~55 per table over 10 years.**

### 8.3 Consolidation Procedure

```sql
-- Step 1: Create a new quarterly partition
CREATE TABLE journal_entries_2023_Q1 (
    LIKE journal_entries INCLUDING DEFAULTS INCLUDING CONSTRAINTS
);
ALTER TABLE journal_entries_2023_Q1
    ADD CONSTRAINT journal_entries_2023_Q1_check
    CHECK (entry_date >= '2023-01-01' AND entry_date < '2023-04-01');

-- Step 2: Move data from monthly partitions into the quarterly partition
INSERT INTO journal_entries_2023_Q1
    SELECT * FROM journal_entries_2023_01;
INSERT INTO journal_entries_2023_Q1
    SELECT * FROM journal_entries_2023_02;
INSERT INTO journal_entries_2023_Q1
    SELECT * FROM journal_entries_2023_03;

-- Step 3: Detach the old monthly partitions
ALTER TABLE journal_entries DETACH PARTITION journal_entries_2023_01;
ALTER TABLE journal_entries DETACH PARTITION journal_entries_2023_02;
ALTER TABLE journal_entries DETACH PARTITION journal_entries_2023_03;

-- Step 4: Drop the old monthly partitions (after verification)
DROP TABLE journal_entries_2023_01;
DROP TABLE journal_entries_2023_02;
DROP TABLE journal_entries_2023_03;

-- Step 5: Attach the quarterly partition
ALTER TABLE journal_entries ATTACH PARTITION journal_entries_2023_Q1
    FOR VALUES FROM ('2023-01-01') TO ('2023-04-01');
```

### 8.4 When to Consolidate

- **Trigger**: When a partition is older than 3 years AND has not been queried in the last 30 days
- **Frequency**: Run quarterly consolidation as a `pg_cron` job
- **Verification**: Count rows before and after to ensure no data loss

---

## 9. Partition Health & Monitoring

### 9.1 Operational Dashboard

A Laravel admin page (`/admin/system/partition-health`) should show the following metrics for every partitioned table:

| Metric | Source | Alert Threshold |
|--------|--------|----------------|
| Total partitions per table | `pg_inherits` + `pg_class` | > 80 (consolidation needed) |
| Partition sizes (MB/GB) | `pg_relation_size()` | Any single partition > 10GB |
| Row counts per partition | `pg_stat_user_tables` | Track growth rate |
| Index sizes per partition | `pg_indexes_size()` | Index bloat > 30% |
| Last VACUUM/ANALYZE per partition | `pg_stat_user_tables` | > 7 days for active partitions |
| Dead tuples per partition | `pg_stat_user_tables` | > 100,000 |
| Default partition size | `pg_relation_size()` | > 0 rows (data with wrong dates) |
| Missing future partitions | `pg_partman.part_config` + `pg_inherits` | < 3 months ahead |
| Oldest partition age | `pg_inherits` | > 3 years (consolidation candidate) |
| Retention status | `pg_partman.part_config` | Misconfigured retention |

### 9.2 Additional Operational Metrics

| Metric | Source | Why It Matters |
|--------|--------|----------------|
| **Partition pruning efficiency** | `EXPLAIN ANALYZE` output | If pruning isn't happening, queries scan all partitions |
| **Cross-partition query frequency** | `pg_stat_statements` | Queries that touch > 3 partitions may need index tuning |
| **Partition-wise join utilization** | `EXPLAIN ANALYZE` | Verify journal_entries + journal_lines joins are pushed down |
| **Lock wait time during ATTACH/DETACH** | `pg_locks` | Detect contention during partition maintenance |
| **Catalog table sizes** | `pg_relation_size('pg_class')` | Track catalog bloat from partition proliferation |
| **`max_locks_per_transaction` usage** | `pg_settings` | May need to increase from default 64 |
| **pg_partman maintenance duration** | `pg_partman.part_config.last_maintenance` | Should be < 30 seconds |
| **BRIN index effectiveness** | `pg_stat_user_indexes.idx_scan` | Verify BRIN indexes are being used |
| **Trigger FK overhead** | Custom timing logs | Compare trigger-based FK vs declarative FK latency |

### 9.3 Monitoring SQL Queries

```sql
-- Partition sizes per table
SELECT
    parent.relname AS table_name,
    child.relname AS partition_name,
    pg_size_pretty(pg_relation_size(child.oid)) AS size,
    pg_stat_get_tuples_returned(child.oid) AS seq_scans
FROM pg_inherits
    JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
    JOIN pg_class child ON pg_inherits.inhrelid = child.oid
ORDER BY parent.relname, child.relname;

-- Default partition check (should be empty)
SELECT relname, pg_size_pretty(pg_relation_size(oid))
FROM pg_class
WHERE relname LIKE '%_default'
  AND pg_relation_size(oid) > 0;

-- Missing future partitions
SELECT parent_table,
       premake,
       (SELECT COUNT(*) FROM pg_inherits i
        JOIN pg_class c ON i.inhrelid = c.oid
        JOIN pg_class p ON i.inhparent = p.oid
        WHERE p.relname = split_part(part_config.parent_table, '.', 2)) AS current_partitions
FROM partman.part_config;

-- Catalog bloat estimate
SELECT pg_size_pretty(pg_relation_size('pg_class')) AS pg_class_size,
       pg_size_pretty(pg_relation_size('pg_attribute')) AS pg_attribute_size,
       pg_size_pretty(pg_relation_size('pg_depend')) AS pg_depend_size;
```

---

## 10. Automated Validation Framework

### 10.1 Health Checks

The following automated checks should run daily via `pg_cron` and alert via the application's notification system:

| Check | Frequency | Alert Level | Implementation |
|-------|-----------|-------------|----------------|
| **Future partitions exist** | Daily | CRITICAL | Verify ≥ 3 months of future partitions exist for every partitioned table |
| **pg_partman is running** | Daily | CRITICAL | Check `part_config.last_maintenance` is within last 24 hours |
| **Default partition is empty** | Daily | WARNING | Any rows in default partition indicate data with unexpected dates |
| **Partition pruning is working** | Weekly | WARNING | Run `EXPLAIN ANALYZE` on a sample date-range query and verify `Subplans Removed` > 0 |
| **Retention policies are configured** | Daily | WARNING | Verify all partitioned tables have retention configured in `part_config` |
| **BRIN indexes are being used** | Weekly | INFO | Check `pg_stat_user_indexes.idx_scan > 0` for BRIN indexes |
| **Trigger FKs are functional** | Weekly | CRITICAL | Attempt to insert a row with invalid FK and verify it fails |
| **Partition-wise joins are active** | Weekly | INFO | Verify `enable_partitionwise_join = on` and that journal queries use it |

### 10.2 Validation SQL

```sql
-- Check 1: Future partitions exist (≥ 3 months ahead)
CREATE OR REPLACE FUNCTION check_future_partitions()
RETURNS TABLE(table_name text, missing_months int) AS $$
DECLARE
    r RECORD;
    three_months_ahead date := CURRENT_DATE + INTERVAL '3 months';
    partition_count int;
BEGIN
    FOR r IN SELECT DISTINCT parent_table FROM partman.part_config LOOP
        SELECT COUNT(*) INTO partition_count
        FROM pg_inherits i
        JOIN pg_class c ON i.inhrelid = c.oid
        JOIN pg_class p ON i.inhparent = p.oid
        WHERE p.relname = split_part(r.parent_table, '.', 2)
          AND c.relname ~ '\d{4}_\d{2}$';

        IF partition_count < 3 THEN
            table_name := r.parent_table;
            missing_months := 3 - partition_count;
            RETURN NEXT;
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Check 2: Default partition is empty
CREATE OR REPLACE FUNCTION check_default_partitions()
RETURNS TABLE(table_name text, row_count bigint) AS $$
DECLARE
    r RECORD;
    cnt bigint;
BEGIN
    FOR r IN
        SELECT DISTINCT c.relname, c.oid
        FROM pg_inherits i
        JOIN pg_class c ON i.inhrelid = c.oid
        WHERE c.relname LIKE '%_default'
    LOOP
        EXECUTE format('SELECT COUNT(*) FROM %I', r.relname) INTO cnt;
        IF cnt > 0 THEN
            table_name := r.relname;
            row_count := cnt;
            RETURN NEXT;
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Check 3: pg_partman maintenance is running
SELECT parent_table,
       last_maintenance,
       NOW() - last_maintenance AS since_last_run
FROM partman.part_config
WHERE last_maintenance < NOW() - INTERVAL '24 hours';
```

### 10.3 Automated Alerting

```sql
-- pg_cron job: Run health checks daily at 3 AM
SELECT cron.schedule(
    'partition-health-check',
    '0 3 * * *',
    $$
    -- Insert results into a health_check_results table
    INSERT INTO partition_health_alerts (check_name, table_name, details, severity, created_at)
    SELECT 'future_partitions', table_name, missing_months || ' months missing', 'CRITICAL', NOW()
    FROM check_future_partitions()
    UNION ALL
    SELECT 'default_partition', table_name, row_count || ' rows in default', 'WARNING', NOW()
    FROM check_default_partitions()
    UNION ALL
    SELECT 'partman_stale', parent_table, 'Last run: ' || last_maintenance, 'CRITICAL', NOW()
    FROM partman.part_config
    WHERE last_maintenance < NOW() - INTERVAL '24 hours';
    $$
);
```

---

## 11. Migration Dry-Run Mode

### 11.1 Purpose

Before executing any partitioning migration, a dry-run mode estimates the migration impact without actually modifying the database. This allows the team to plan maintenance windows, disk space, and rollback complexity.

### 11.2 Dry-Run Procedure

```sql
CREATE OR REPLACE FUNCTION partition_dry_run(
    p_table_name text,
    p_partition_key text,
    p_strategy text DEFAULT 'range',
    p_interval text DEFAULT '1 month'
)
RETURNS TABLE(
    metric text,
    value text,
    notes text
) AS $$
DECLARE
    v_row_count bigint;
    v_table_size text;
    v_index_size text;
    v_oldest_date date;
    v_newest_date date;
    v_partition_count int;
    v_estimated_duration text;
    v_lock_type text;
BEGIN
    -- Get current table stats
    EXECUTE format('SELECT COUNT(*) FROM %I', p_table_name) INTO v_row_count;
    EXECUTE format('SELECT pg_size_pretty(pg_relation_size(%L))', p_table_name) INTO v_table_size;
    EXECUTE format('SELECT pg_size_pretty(pg_indexes_size(%L))', p_table_name) INTO v_index_size;

    -- Get date range
    EXECUTE format('SELECT MIN(%I), MAX(%I) FROM %I', p_partition_key, p_partition_key, p_table_name)
        INTO v_oldest_date, v_newest_date;

    -- Estimate partition count
    v_partition_count := (v_newest_date - v_oldest_date) / 30 + 1;

    -- Estimate duration (rough: ~1 minute per million rows for ATTACH PARTITION)
    IF v_row_count < 1000000 THEN
        v_estimated_duration := '< 2 minutes';
    ELSIF v_row_count < 10000000 THEN
        v_estimated_duration := '5-20 minutes';
    ELSIF v_row_count < 100000000 THEN
        v_estimated_duration := '30-120 minutes';
    ELSE
        v_estimated_duration := '2+ hours';
    END IF;

    -- Lock type
    v_lock_type := 'ACCESS EXCLUSIVE (brief, ~1-5 seconds per ATTACH)';

    -- Return results
    metric := 'Row Count'; value := v_row_count::text; notes := 'Current total rows'; RETURN NEXT;
    metric := 'Table Size'; value := v_table_size; notes := 'Heap only (excludes indexes)'; RETURN NEXT;
    metric := 'Index Size'; value := v_index_size; notes := 'All indexes'; RETURN NEXT;
    metric := 'Date Range'; value := v_oldest_date::text || ' to ' || v_newest_date::text; notes := 'Partition key range'; RETURN NEXT;
    metric := 'Estimated Partitions'; value := v_partition_count::text; notes := 'Based on date range and monthly interval'; RETURN NEXT;
    metric := 'Disk Space Needed'; value := (v_table_size); notes := 'Approximately 2x current table size during migration'; RETURN NEXT;
    metric := 'Estimated Duration'; value := v_estimated_duration; notes := 'Data copy + ATTACH operations'; RETURN NEXT;
    metric := 'Lock Type'; value := v_lock_type; notes := 'Required for ATTACH PARTITION'; RETURN NEXT;
    metric := 'FK Children'; value := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_type = 'FOREIGN KEY' AND table_name = p_table_name)::text; notes := 'Must convert to trigger-based FK'; RETURN NEXT;
    metric := 'Rollback Complexity'; value := CASE WHEN v_partition_count < 12 THEN 'LOW' WHEN v_partition_count < 48 THEN 'MEDIUM' ELSE 'HIGH' END; notes := 'Based on partition count and FK children'; RETURN NEXT;
END;
$$ LANGUAGE plpgsql;
```

### 11.3 Usage

```sql
-- Dry-run for journal_entries
SELECT * FROM partition_dry_run('journal_entries', 'entry_date');

-- Expected output:
-- metric              | value                    | notes
-- Row Count           | 5,234,567                | Current total rows
-- Table Size          | 2345 MB                  | Heap only
-- Index Size          | 1890 MB                  | All indexes
-- Date Range          | 2025-01-01 to 2026-08-02 | Partition key range
-- Estimated Partitions| 20                       | Monthly intervals
-- Disk Space Needed   | ~4690 MB                 | 2x during migration
-- Estimated Duration  | 5-20 minutes             | Data copy + ATTACH
-- Lock Type           | ACCESS EXCLUSIVE (brief) | Per ATTACH
-- FK Children         | 27                       | Must convert to triggers
-- Rollback Complexity | HIGH                     | 27 FK conversions
```

---

## 12. Performance Targets

### 12.1 Measurable Goals

The following targets should be achievable after all phases are complete. These are measured on a production-like dataset with 50M+ journal entries and 100M+ journal lines.

| Query | Target | Max | Current (Estimated) | Notes |
|-------|--------|-----|---------------------|-------|
| **Trial Balance** (single month) | < 500ms | 1s | 2-5s | Date-range scan on journal_entries + journal_lines with partition pruning |
| **General Ledger** (single account, single month) | < 200ms | 500ms | 1-3s | Index + partition pruning on ledger_id + entry_date |
| **Customer Aging** (all branches) | < 1s | 2s | 3-8s | BRIN + partition pruning on customer_ledger |
| **Supplier Aging** (all branches) | < 1s | 2s | 3-8s | Same as customer aging |
| **Inventory Valuation** (single warehouse) | < 500ms | 1s | 1-3s | Already partitioned (stock_transactions) |
| **Journal Entry Lookup** (by reference) | < 50ms | 100ms | 20-50ms | Covering index + partition pruning |
| **Sales Invoice Listing** (single month, single branch) | < 100ms | 300ms | 200-500ms | Already partitioned (sales_invoices) |
| **Financial Audit Log** (single month) | < 300ms | 1s | 5-15s | BRIN + partition pruning on append-only table |
| **Month-end Close** (posting depreciation) | < 2s | 5s | 5-10s | Batch insert with partition-aligned writes |
| **Dashboard Stats** (active counts/sums) | < 200ms | 500ms | 500ms-2s | Partial indexes + materialized views |

### 12.2 Measurement Method

```sql
-- Before partitioning: measure baseline
EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT ... FROM journal_entries je
JOIN journal_lines jl ON je.id = jl.journal_entry_id
WHERE je.entry_date BETWEEN '2026-07-01' AND '2026-07-31'
  AND je.is_reversed = false;

-- After partitioning: measure improvement
-- Same query, should show:
--   "Subplans Removed: N" (partition pruning)
--   "Partition-wise Join" (if both tables partitioned by entry_date)
--   Execution Time < target
```

---

## 13. Phase Breakdown

### Phase 1: Audit Log Partitioning (Lowest Risk)

**Goal**: Partition all 6 audit/log tables that have zero FK children.  
**Duration**: 1-2 days  
**Risk**: LOW  
**Downtime**: Minimal (attach partition with brief lock)

| Step | Action | Details |
|------|--------|---------|
| 1.1 | Create partitioned parent for `financial_audit_log` | Rename existing table, create new partitioned parent, attach old data as partition |
| 1.2 | Add BRIN index on `created_at` | Replace B-tree with BRIN for range queries |
| 1.3 | Configure `pg_partman` for `financial_audit_log` | Auto-create monthly partitions, set retention policy |
| 1.4 | Repeat for `user_audit_log` | Same pattern |
| 1.5 | Repeat for `stock_take_audit_log` | Same pattern |
| 1.6 | Repeat for `stock_adjustment_audit_log` | Same pattern |
| 1.7 | Repeat for `branch_demand_audit_log` | Same pattern |
| 1.8 | Repeat for `journal_posting_logs` | Same pattern |
| 1.9 | Add default partitions for all | Catch rows with unexpected dates |
| 1.10 | Update Laravel models | No changes needed — Eloquent sees the parent table |
| 1.11 | Run dry-run for Phase 2 tables | Estimate migration impact for next phase |
| 1.12 | Verify query performance | Run `EXPLAIN ANALYZE` on date-range queries, verify BRIN indexes are used |

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

-- Step 6: Add BRIN index on partition key
CREATE INDEX idx_fal_created_at_brin
    ON financial_audit_log USING BRIN (created_at)
    WITH (pages_per_range = 32);

-- Step 7: Migrate remaining data
INSERT INTO financial_audit_log SELECT * FROM financial_audit_log_old
    WHERE created_at >= '2026-01-01';

-- Step 8: Register with pg_partman
SELECT partman.create_parent(
    p_parent_table := 'public.financial_audit_log',
    p_control := 'created_at',
    p_type := 'range',
    p_interval := '1 month',
    p_premake := 6
);

-- Step 9: Configure retention
UPDATE partman.part_config
SET retention = '84 months',
    retention_keep_table = true,
    retention_schema = 'archive'
WHERE parent_table = 'public.financial_audit_log';

-- Step 10: Drop old table
DROP TABLE financial_audit_log_old;
```

**Validation:**
- All existing queries work unchanged
- Date-range queries show partition pruning in `EXPLAIN ANALYZE`
- BRIN indexes are being used (check `pg_stat_user_indexes`)
- `pg_partman` auto-creates next month's partition
- RLS policies (if any) still work
- Default partition is empty

---

### Phase 2: Sub-Ledger Partitioning

**Goal**: Partition all 5 sub-ledger tables.  
**Duration**: 2-3 days  
**Risk**: LOW  
**Prerequisite**: Phase 1 complete

| Step | Action | Details |
|------|--------|---------|
| 2.1 | Partition `customer_ledger` by `transaction_date` | Core AR sub-ledger. Benefits aging report queries. |
| 2.2 | Replace B-tree on `transaction_date` with BRIN | Major index size reduction |
| 2.3 | Partition `supplier_ledger` by `transaction_date` | Core AP sub-ledger. |
| 2.4 | Replace B-tree on `transaction_date` with BRIN | Same as 2.2 |
| 2.5 | Partition `employee_ledger` by `transaction_date` | Lower volume but same pattern. |
| 2.6 | Partition `cash_ledger` by `transaction_date` | Branch cash tracking. |
| 2.7 | Partition `branch_ledger` by `transaction_date` | Intercompany tracking. |
| 2.8 | Configure `pg_partman` for all 5 tables | Monthly auto-creation |
| 2.9 | Update materialized views | `mv_ar_aging`, `mv_ap_aging` reference sub-ledgers |
| 2.10 | Verify aging report performance | Measure before/after query times |

**Key Consideration**: Sub-ledger tables have FK references TO `journal_entries` (inbound). This does NOT require trigger-based FK conversion — it's a standard FK from a non-partitioned table to a non-partitioned table (journal_entries is not yet partitioned in this phase).

**Validation:**
- AR/AP aging reports show partition pruning
- `customer_ledger` queries with `WHERE transaction_date BETWEEN ...` only scan relevant partitions
- BRIN indexes show `idx_scan > 0` in `pg_stat_user_indexes`
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
| 3.2 | Add BRIN index on `summary_date` | Append-only, time-series |
| 3.3 | Configure `pg_partman` | Monthly auto-creation |
| 3.4 | Add retention policy | Auto-detach partitions older than 24 months |
| 3.5 | Verify stock summary queries | Dashboard queries should show pruning |

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
| 4.10 | Add BRIN indexes on all partition keys | Append-only tables benefit from BRIN |
| 4.11 | Configure `pg_partman` for all tables | Monthly auto-creation |
| 4.12 | Verify all transaction flows | Test each transaction type end-to-end |

**Trigger FK Pattern for Single-Child Tables:**

When a transaction header table (e.g., `sales_returns`) is partitioned, its child table (`sales_return_items`) must convert the FK:

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
| 5.7 | Add BRIN indexes on all partition keys | Same as Phase 4 |
| 5.8 | Configure `pg_partman` for all tables | Monthly auto-creation |
| 5.9 | Verify all transaction flows | Test each transaction type end-to-end |

---

### Phase 6: Journal Entries Partitioning (Critical)

**Goal**: Partition `journal_entries` by `entry_date` — the most impactful and riskiest partitioning operation.  
**Duration**: 5-7 days  
**Risk**: CRITICAL  
**Prerequisite**: Phases 1-5 complete, extensive testing on staging

This is the most complex phase because `journal_entries` has 27+ child tables. The approach is:

1. **Do NOT convert all 27 FKs at once.** Instead, convert them in batches.
2. **Use the existing pattern** from `sales_invoices` and `stock_transactions` partitioning.
3. **Add `entry_date` to `journal_lines`** in this same phase (denormalization for partition-wise joins).
4. **Test thoroughly** on staging before production.

| Step | Action | Details |
|------|--------|---------|
| 6.1 | Create staging replica | Full copy of production database for testing |
| 6.2 | Add `entry_date` column to `journal_lines` | Denormalize from parent. Backfill. See [Section 6.2](#62-journal_lines-partition-key-entry_date-vs-created_at) |
| 6.3 | Partition `journal_entries` on staging | Rename, create partitioned parent, attach old data |
| 6.4 | Partition `journal_lines` by `entry_date` on staging | Same boundaries as journal_entries for partition-wise joins |
| 6.5 | Add BRIN indexes on `entry_date` | Both journal_entries and journal_lines |
| 6.6 | Convert FKs — Batch 1 (core accounting) | `journal_lines`, `journal_posting_logs` |
| 6.7 | Convert FKs — Batch 2 (payment tables) | `customer_payments`, `supplier_payments`, `money_transfers` |
| 6.8 | Convert FKs — Batch 3 (income/expense) | `other_incomes`, `other_expenses`, `employee_transactions` |
| 6.9 | Convert FKs — Batch 4 (sales/purchase) | `sales_invoices` (already partitioned, trigger FK already exists), `sales_challans`, `sales_returns`, `purchase_receives`, `purchase_returns` |
| 6.10 | Convert FKs — Batch 5 (inventory) | `stock_adjustments`, `stock_take_sessions`, `warehouse_transfers`, `damage_invoices`, `branch_demands` |
| 6.11 | Convert FKs — Batch 6 (sub-ledgers) | `branch_ledger`, `cash_ledger` |
| 6.12 | Convert FKs — Batch 7 (advanced modules) | `manual_journals`, `bank_reconciliations`, `asset_depreciation_schedules`, `asset_disposals`, `elimination_entries`, `branch_demand_repricing` |
| 6.13 | Verify partition-wise joins | `EXPLAIN ANALYZE` on journal_entries + journal_lines join should show "Partition-wise Join" |
| 6.14 | Run full integration test suite | All transaction types, all reports, all RLS checks |
| 6.15 | Apply to production | During maintenance window |
| 6.16 | Configure `pg_partman` | Monthly auto-creation |

**Special Case: `sales_invoices` already has a trigger FK to `journal_entries`**

The `sales_invoices` table is already partitioned and already uses trigger-based FKs. Its FK to `journal_entries` needs to be updated to handle the partitioned parent. The existing trigger function `trg_si_journal_entry_fk` already works because it queries the parent table (which PostgreSQL routes to the correct partition via partition pruning). **No change needed.**

**Key Verification: Partition-wise Joins**

After Phase 6, the most critical verification is that partition-wise joins work:

```sql
-- Enable partition-wise joins (default is 'off' in some PG versions)
SET enable_partitionwise_join = on;

-- Verify it works
EXPLAIN (COSTS OFF)
SELECT je.id, jl.ledger_id, jl.debit, jl.credit
FROM journal_entries je
JOIN journal_lines jl ON je.id = jl.journal_entry_id AND je.entry_date = jl.entry_date
WHERE je.entry_date BETWEEN '2026-07-01' AND '2026-07-31';

-- Should show "Partitioned Join" in the plan
-- Should NOT show "Nested Loop" scanning all partitions
```

**Application Code Change: `JournalPostingService::createJournalEntry()`**

When creating journal lines, the service must now set `entry_date`:

```php
// In JournalPostingService::createJournalEntry()
// After creating the journal entry, set entry_date on each line
foreach ($lines as $line) {
    $line['entry_date'] = $data['entry_date'];  // Denormalized from parent
}
```

---

### Phase 7: Archival & Retention Policies

**Goal**: Implement automated archival and retention policies for all partitioned tables.  
**Duration**: 3-5 days  
**Risk**: LOW (detaching partitions is non-destructive)  
**Prerequisite**: Phases 1-6 complete

| Step | Action | Details |
|------|--------|---------|
| 7.1 | Define retention periods per table | See Section 14.2 |
| 7.2 | Configure `pg_partman` retention policies | Auto-detach old partitions |
| 7.3 | Create archival schema (`archive`) | Separate schema for detached partitions |
| 7.4 | Create archival stored procedures | Move detached partitions to archive schema |
| 7.5 | Create export procedures | Export old partitions to Parquet/DuckDB for analytics |
| 7.6 | Create restore procedures | Import archived data back if needed |
| 7.7 | Test archival and restore | Verify data integrity after archival |
| 7.8 | Set up partition consolidation cron | Merge old monthly partitions into quarterly/yearly |
| 7.9 | Set up monitoring | Alert when partitions approach retention limit |

---

### Phase 8: Performance Optimization & Monitoring

**Goal**: Fine-tune partitioning, add monitoring, and optimize queries.  
**Duration**: 3-5 days  
**Risk**: LOW  
**Prerequisite**: Phase 7 complete

| Step | Action | Details |
|------|--------|---------|
| 8.1 | Add partition-aware indexes | Composite indexes matching common query patterns |
| 8.2 | Create partition statistics views | Monitor partition sizes, row counts, vacuum status |
| 8.3 | Build partition health dashboard | Laravel admin page at `/admin/system/partition-health` |
| 8.4 | Implement automated validation framework | Daily pg_cron health checks with alerting |
| 8.5 | Optimize materialized view refreshes | Ensure MV refreshes use partition pruning |
| 8.6 | Set up automated VACUUM per partition | Per-partition vacuum settings |
| 8.7 | Verify partition-wise joins | Automated test that journal queries use partition-wise joins |
| 8.8 | Verify BRIN index effectiveness | Check `idx_scan` for all BRIN indexes |
| 8.9 | Measure performance targets | Verify all targets in Section 12 are met |
| 8.10 | Document operational procedures | How to add/remove partitions, handle edge cases |

---

## 14. Archival Strategy

### 14.1 Archival Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    PostgreSQL (Primary)                       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ 2026-07      │  │ 2026-08      │  │ 2026-09      │ ...   │
│  │ (active)     │  │ (active)     │  │ (active)     │       │
│  │ BRIN indexes │  │ BRIN indexes │  │ BRIN indexes │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2026-01-06   │  │ 2025-07-12   │  ← pg_partman retention │
│  │ (read-only)  │  │ (read-only)  │    auto-detaches older   │
│  │ BRIN indexes │  │ BRIN indexes │    partitions             │
│  └──────────────┘  └──────────────┘                         │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  Schema: archive                                             │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2024-01-06   │  │ 2023-01-12   │  ← detached, still     │
│  │ (detached)   │  │ (detached)   │    queryable if needed   │
│  │ B-tree only  │  │ Consolidated │    (quarterly/yearly)    │
│  └──────────────┘  └──────────────┘                         │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│  Cold Storage (Parquet / DuckDB / S3)                        │
│  ┌──────────────┐  ┌──────────────┐                         │
│  │ 2022.parquet │  │ 2021.parquet │  ← exported for long-   │
│  │ DuckDB query │  │ DuckDB query │    term compliance &     │
│  │ analytics    │  │ analytics    │    analytics workloads    │
│  └──────────────┘  └──────────────┘                         │
└──────────────────────────────────────────────────────────────┘
```

### 14.2 Retention Periods

| Table Category | Active (Hot) | Read-Only (Warm) | Archived (Cold) | Purge |
|---------------|-------------|-------------------|-----------------|-------|
| Financial audit logs | 12 months | 7 years | 7+ years | Never (compliance) |
| User audit logs | 3 months | 1 year | 1+ years | After 2 years |
| Sub-ledgers | 12 months | 7 years | 7+ years | Never (compliance) |
| Journal entries | 12 months | 7 years | 7+ years | Never (compliance) |
| Journal lines | 12 months | 7 years | 7+ years | Never (compliance) |
| Transaction headers | 12 months | 7 years | 7+ years | Never (compliance) |
| Stock transactions | 12 months | 3 years | 7+ years | After 7 years |
| Daily warehouse summary | 3 months | 12 months | 3 years | After 3 years |
| Stock audit logs | 3 months | 1 year | 3 years | After 3 years |

### 14.3 Parquet/DuckDB Export for Analytics

For partitions older than 7 years, or for analytics workloads that don't need real-time data, export to Parquet and query with DuckDB:

```bash
# Export partition to CSV
psql -c "COPY financial_audit_log_2022_01 TO STDOUT WITH CSV HEADER" \
    > /archive/csv/financial_audit_log_2022_01.csv

# Convert to Parquet using DuckDB
duckdb -c "
    COPY (SELECT * FROM read_csv('/archive/csv/financial_audit_log_2022_01.csv'))
    TO '/archive/parquet/financial_audit_log_2022_01.parquet'
    (FORMAT PARQUET, COMPRESSION ZSTD);
"

# Query Parquet with DuckDB (no PostgreSQL load)
duckdb -c "
    SELECT operation, COUNT(*)
    FROM read_parquet('/archive/parquet/financial_audit_log_2022_*.parquet')
    GROUP BY operation;
"
```

**Benefits of Parquet/DuckDB:**
- **10-50x compression** vs CSV (columnar + ZSTD)
- **No PostgreSQL load** — analytics queries run on DuckDB, not on the production server
- **Schema-on-read** — can evolve schema without migration
- **Fast aggregations** — columnar storage is ideal for SUM/COUNT/AVG queries
- **S3 compatible** — can push to cloud object storage for disaster recovery

### 14.4 Archival Procedures

#### 14.4.1 Detach and Move to Archive Schema

```sql
-- Detach partition from parent (brief ACCESS EXCLUSIVE lock)
ALTER TABLE financial_audit_log DETACH PARTITION financial_audit_log_2024_01;

-- Move to archive schema
ALTER TABLE financial_audit_log_2024_01 SET SCHEMA archive;

-- Rename for clarity
ALTER TABLE archive.financial_audit_log_2024_01 
    RENAME TO financial_audit_log_2024_01;
```

#### 14.4.2 Export to Cold Storage

```bash
# Export to Parquet via DuckDB
duckdb -c "
    COPY (SELECT * FROM read_csv('/archive/csv/financial_audit_log_2024_01.csv'))
    TO '/archive/parquet/financial_audit_log_2024_01.parquet'
    (FORMAT PARQUET, COMPRESSION ZSTD);
"
```

#### 14.4.3 Restore from Archive

```sql
-- Import from archive schema
ALTER TABLE archive.financial_audit_log_2024_01 SET SCHEMA public;

-- Re-attach to parent
ALTER TABLE financial_audit_log ATTACH PARTITION financial_audit_log_2024_01
    FOR VALUES FROM ('2024-01-01') TO ('2024-02-01');
```

### 14.5 pg_partman Retention Configuration

```sql
-- Configure retention for financial_audit_log (keep 84 months = 7 years)
UPDATE partman.part_config
SET retention = '84 months',
    retention_keep_table = true,   -- Keep detached as standalone table
    retention_schema = 'archive'   -- Move to this schema instead of dropping
WHERE parent_table = 'public.financial_audit_log';

-- For daily_warehouse_stock_summary (keep 36 months = 3 years)
UPDATE partman.part_config
SET retention = '36 months',
    retention_keep_table = true,
    retention_schema = 'archive'
WHERE parent_table = 'public.daily_warehouse_stock_summary';
```

---

## 15. Rollback Strategy

### 15.1 Per-Phase Rollback

Each phase has a specific rollback procedure:

| Phase | Rollback Method |
|-------|----------------|
| 1-3 (Audit logs, sub-ledgers, summary) | `ALTER TABLE ... DETACH PARTITION` all partitions, then `INSERT INTO original_table SELECT * FROM partition` for each partition. Drop partitioned parent, rename original. |
| 4-5 (Transaction headers) | Same as above, plus restore declarative FKs (drop trigger FK functions, re-add ALTER TABLE ADD CONSTRAINT). |
| 6 (Journal entries + journal lines) | Same as above, but must restore ALL 27+ declarative FKs AND drop the `entry_date` column from `journal_lines`. This is the riskiest rollback. |
| 7 (Archival) | Re-attach archived partitions from archive schema. |

### 15.2 General Rollback Pattern

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

### 15.3 Safety Measures

1. **Always take a full backup** before each phase
2. **Test on staging first** — never apply a phase to production without staging validation
3. **Run in a transaction** where possible — `BEGIN; ... ROLLBACK;` for testing
4. **Keep the old table** until the new partitioned table is verified — don't `DROP TABLE ..._old` until the next day
5. **Monitor for 24 hours** after each phase — check error logs, slow queries, and application health
6. **Dry-run first** — use the `partition_dry_run()` function to estimate impact before executing

---

## 16. Risk Register

| # | Risk | Probability | Impact | Mitigation |
|---|------|-------------|--------|------------|
| R1 | FK conversion breaks data integrity | Medium | Critical | Test trigger FKs on staging with full dataset. Add constraint checks post-migration. |
| R2 | Partition pruning not working (full scans) | Low | High | Verify with `EXPLAIN ANALYZE` after each phase. Add BRIN indexes. Enable `enable_partitionwise_join`. |
| R3 | pg_partman fails to create future partitions | Low | High | Monitor `part_config.last_maintenance` daily. Set up automated health checks. |
| R4 | RLS policies stop working after partitioning | Very Low | Critical | Test RLS after each phase. RLS at parent level applies to all partitions. |
| R5 | Application queries break due to composite PK | Medium | High | Laravel Eloquent doesn't use composite PKs. Ensure model `$primaryKey` is set correctly. Test all CRUD operations. |
| R6 | Detach partition locks block production | Low | Medium | Detach during low-traffic periods. Use `ALTER TABLE ... DETACH PARTITION ... CONCURRENTLY` (PG 14+). |
| R7 | Journal entries partitioning takes too long | Medium | High | Pre-create partitions in advance. Use `ATTACH PARTITION` instead of `CREATE TABLE ... PARTITION OF`. |
| R8 | Archival of wrong partition | Low | Critical | Double-check partition date ranges before detaching. Never auto-drop without review. |
| R9 | Laravel model changes break application | Low | High | Test all CRUD operations after partitioning. Eloquent should work unchanged since it queries the parent table. |
| R10 | Materialized view refresh fails on partitioned tables | Low | Medium | Test MV refreshes after each phase. Refresh queries should use partition pruning. |
| R11 | **Partition-wise joins not working** (journal_entries + journal_lines) | Medium | High | Both tables must use same partition key (`entry_date`) and boundaries. Verify `enable_partitionwise_join = on`. |
| R12 | **Catalog bloat from excessive partitions** | Low | Medium | Implement partition consolidation (Section 8). Monitor catalog sizes. |
| R13 | **`max_locks_per_transaction` exceeded** | Low | High | Increase from default 64 to 256 for partition maintenance operations. |
| R14 | **Trigger FK performance overhead** | Medium | Medium | Benchmark trigger FK vs declarative FK. For hot paths, consider relaxed FK (no trigger) with periodic consistency checks. |
| R15 | **Default partition grows unexpectedly** | Low | High | Monitor daily. Alert if default partition has > 0 rows. Investigate data with wrong dates. |
| R16 | **BRIN index not effective** (data not physically ordered) | Low | Medium | Verify data is append-only and physically ordered. Use `pages_per_range = 32` for finer granularity. |
| R17 | **`entry_date` denormalization in journal_lines drifts** | Low | High | Add trigger to keep `journal_lines.entry_date` in sync with `journal_entries.entry_date`. Add periodic consistency check. |

---

## 17. PostgreSQL Configuration Tuning

### 17.1 Partitioning-Specific Settings

| Setting | Default | Recommended | Why |
|---------|---------|-------------|-----|
| `enable_partitionwise_join` | off | **on** | Enables partition-wise joins (critical for journal_entries + journal_lines) |
| `enable_partitionwise_aggregate` | off | **on** | Enables partition-wise aggregation (GROUP BY per partition) |
| `enable_partition_pruning` | on | on | Already enabled by default in PG 12+ |
| `max_locks_per_transaction` | 64 | **256** | Partition maintenance operations may need to lock many partitions |
| `constraint_exclusion` | partition | partition | Only apply constraints for partition pruning |

### 17.2 Vacuum Tuning for Partitioned Tables

```sql
-- Old partitions are read-only — reduce vacuum frequency
ALTER TABLE journal_entries_2025_01 SET (autovacuum_vacuum_scale_factor = 0.01);
ALTER TABLE journal_entries_2025_01 SET (autovacuum_analyze_scale_factor = 0.01);

-- Active partitions need more aggressive vacuuming
ALTER TABLE journal_entries_2026_08 SET (autovacuum_vacuum_scale_factor = 0.05);
ALTER TABLE journal_entries_2026_08 SET (autovacuum_analyze_scale_factor = 0.02);
```

### 17.3 Shared Buffers & Work Mem

| Setting | Default | Recommended | Why |
|---------|---------|-------------|-----|
| `shared_buffers` | 128MB | 4-8GB (25% of RAM) | Per-partition indexes fit better in memory |
| `work_mem` | 4MB | 16-64MB | Sorting within partitions; larger work_mem prevents disk sorts |
| `maintenance_work_mem` | 64MB | 512MB-1GB | ATTACH PARTITION, VACUUM, and CREATE INDEX operations |
| `effective_cache_size` | 4GB | 12-24GB (75% of RAM) | Helps planner choose index scans over seq scans |

---

## Appendix A: Complete Table Partitioning Priority Matrix

| Priority | Table | Partition Key | FK Children | Risk | Phase | BRIN? |
|----------|-------|--------------|-------------|------|-------|-------|
| 1 | `financial_audit_log` | `created_at` | 0 | LOW | 1 | YES |
| 2 | `user_audit_log` | `created_at` | 0 | LOW | 1 | YES |
| 3 | `stock_take_audit_log` | `created_at` | 0 | LOW | 1 | YES |
| 4 | `stock_adjustment_audit_log` | `created_at` | 0 | LOW | 1 | YES |
| 5 | `branch_demand_audit_log` | `created_at` | 0 | LOW | 1 | YES |
| 6 | `journal_posting_logs` | `performed_at` | 0 | LOW | 1 | YES |
| 7 | `customer_ledger` | `transaction_date` | 0 | LOW | 2 | YES |
| 8 | `supplier_ledger` | `transaction_date` | 0 | LOW | 2 | YES |
| 9 | `employee_ledger` | `transaction_date` | 0 | LOW | 2 | YES |
| 10 | `cash_ledger` | `transaction_date` | 0 | LOW | 2 | YES |
| 11 | `branch_ledger` | `transaction_date` | 0 | LOW | 2 | YES |
| 12 | `daily_warehouse_stock_summary` | `summary_date` | 0 | LOW | 3 | YES |
| 13 | `money_transfers` | `transfer_date` | 0 | LOW | 4 | YES |
| 14 | `employee_transactions` | `transaction_date` | 0 | LOW | 4 | YES |
| 15 | `other_incomes` | `income_date` | 0 | LOW | 4 | YES |
| 16 | `other_expenses` | `expense_date` | 0 | LOW | 4 | YES |
| 17 | `sales_returns` | `return_date` | 1 | MEDIUM | 4 | YES |
| 18 | `purchase_receives` | `receive_date` | 1 | MEDIUM | 4 | YES |
| 19 | `purchase_returns` | `return_date` | 1 | MEDIUM | 4 | YES |
| 20 | `damage_invoices` | `damage_date` | 1 | MEDIUM | 4 | YES |
| 21 | `manual_journals` | `journal_date` | 1 | MEDIUM | 4 | YES |
| 22 | `customer_payments` | `payment_date` | 2 | MEDIUM | 5 | YES |
| 23 | `supplier_payments` | `payment_date` | 1 | MEDIUM | 5 | YES |
| 24 | `sales_challans` | `challan_date` | 1 | MEDIUM | 5 | YES |
| 25 | `warehouse_transfers` | `transfer_date` | 1 | MEDIUM | 5 | YES |
| 26 | `stock_adjustments` | `adjustment_date` | 1 | MEDIUM | 5 | YES |
| 27 | `stock_take_sessions` | `session_date` | 2 | MEDIUM | 5 | YES |
| 28 | `journal_entries` | `entry_date` | 27+ | **CRITICAL** | 6 | YES |
| 29 | `journal_lines` | `entry_date` (denormalized) | 0 | MEDIUM | 6 | YES |

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
- [ ] Dry-run executed (`partition_dry_run()` function)
- [ ] Phase tested on staging successfully
- [ ] Rollback procedure documented and tested
- [ ] Maintenance window scheduled (if needed)
- [ ] Team notified of planned changes
- [ ] `max_locks_per_transaction` increased if needed

### During Each Phase

- [ ] Migration executed without errors
- [ ] Data integrity verified (row counts match before/after)
- [ ] RLS policies tested (branch-scoped and admin queries)
- [ ] Application queries tested (CRUD operations)
- [ ] `EXPLAIN ANALYZE` shows partition pruning
- [ ] BRIN indexes are being used (check `pg_stat_user_indexes`)
- [ ] pg_partman configuration verified
- [ ] Materialized view refresh tested
- [ ] Default partition is empty after migration
- [ ] Partition-wise joins verified (if applicable)

### After Each Phase

- [ ] Monitor error logs for 24 hours
- [ ] Check slow query log for unexpected full scans
- [ ] Verify pg_partman auto-creation (next day)
- [ ] Verify BRIN index usage (`idx_scan > 0`)
- [ ] Run automated health checks (Section 10)
- [ ] Update documentation with any deviations
- [ ] Commit changes to Git repository

---

## Appendix E: Estimated Timeline

| Phase | Description | Duration | Cumulative |
|-------|-------------|----------|------------|
| 1 | Audit Log Partitioning + BRIN | 1-2 days | 1-2 days |
| 2 | Sub-Ledger Partitioning + BRIN | 2-3 days | 3-5 days |
| 3 | Time-Series Summary Partitioning | 1 day | 4-6 days |
| 4 | Transaction Headers (Low FK) + BRIN | 3-5 days | 7-11 days |
| 5 | Transaction Headers (Multi FK) + BRIN | 3-5 days | 10-16 days |
| 6 | Journal Entries + Journal Lines (Critical) | 5-7 days | 15-23 days |
| 7 | Archival, Retention & Consolidation | 3-5 days | 18-28 days |
| 8 | Performance Optimization & Monitoring | 3-5 days | 21-33 days |

**Total estimated duration**: 21-33 working days (spread over 2-3 months with staging/testing gaps)

---

## Appendix F: Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-08-02 | Initial plan |
| 2.0 | 2026-08-02 | Senior architect review: (1) Changed journal_lines partition key from `created_at` to `entry_date` (denormalized) for partition-wise joins; (2) Added BRIN index strategy for append-only tables; (3) Added long-term partition consolidation strategy; (4) Added partition health & monitoring dashboard; (5) Added automated validation framework; (6) Added migration dry-run mode; (7) Added Parquet/DuckDB cold storage export; (8) Added performance targets; (9) Added PostgreSQL configuration tuning; (10) Added new risks R11-R17; (11) Reduced phases from 9 to 8 by merging journal_lines into Phase 6 |

---

*End of Phase 10.1 Partitioning & Archival Plan — Revision 2.0*
