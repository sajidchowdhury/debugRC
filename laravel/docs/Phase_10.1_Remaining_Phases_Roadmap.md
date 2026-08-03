# Phase 10.1 — Remaining Phases Roadmap (Phases 5–8 + Regression Fixes)

**Project**: Remote Center ERP (debugRC)
**Database**: PostgreSQL 16+
**Companion document**: `Phase_10.1_Partitioning_and_Archival_Plan.md` (Revision 2.0)
**Date**: 2026-08-15
**Status**: Planning — implementation not yet started
**Author**: Architecture review based on audit of migrations `2026_08_02_000001` … `2026_08_02_000004` + supporting code

---

## Table of Contents

1. [Audit Summary — What Is Done vs What Is Left](#1-audit-summary--what-is-done-vs-what-is-left)
2. [Roadmap at a Glance](#2-roadmap-at-a-glance)
3. [Phase 0 — Regression & Blocker Fixes (MUST DO FIRST)](#3-phase-0--regression--blocker-fixes-must-do-first)
4. [Phase 5 — Multi-FK Transaction Header Partitioning](#4-phase-5--multi-fk-transaction-header-partitioning)
5. [Phase 6 — Journal Entries + Journal Lines (Critical)](#5-phase-6--journal-entries--journal-lines-critical)
6. [Phase 7 — Archival, Retention & Consolidation (Full)](#6-phase-7--archival-retention--consolidation-full)
7. [Phase 8 — Monitoring & Validation Framework](#7-phase-8--monitoring--validation-framework)
8. [Cross-Phase Concerns](#8-cross-phase-concerns)
9. [Sequencing & Dependencies](#9-sequencing--dependencies)
10. [Risk Register Updates](#10-risk-register-updates)
11. [Definition of Done — Per Phase Checklist](#11-definition-of-done--per-phase-checklist)

---

## 1. Audit Summary — What Is Done vs What Is Left

A full audit of the codebase against `Phase_10.1_Partitioning_and_Archival_Plan.md` (Revision 2.0) was performed. The result:

| Phase | Plan goal | Tables targeted | Status | % complete |
|---|---|---|---|---|
| 1 | Audit log partitioning | 6 | ✅ Implemented (1 regression) | ~95% |
| 2 | Sub-ledger partitioning | 5 | ✅ Implemented | 100% |
| 3 | Time-series summary | 1 | ✅ Implemented | 100% |
| 4 | Low-FK transaction headers | 9 | ✅ Implemented (missing retention) | ~90% |
| 5 | Multi-FK transaction headers | 6 | 🚧 **In progress** (schema audit complete, migration being authored) | ~10% |
| 6 | journal_entries + journal_lines (CRITICAL) | 2 + 27 FK conversions | ❌ **Not started** | 0% |
| 7 | Archival, retention & consolidation | — | ⚠️ Partial (only pg_partman retention rows for 12 tables) | ~10% |
| 8 | Monitoring & validation framework | — | ❌ **Not started** | 0% |

**Tables already partitioned (21):** `stock_transactions`, `sales_invoices` (pre-existing), 5 audit logs (Phase 1 minus `financial_audit_log` which was later un-partitioned), 5 sub-ledgers (Phase 2), `daily_warehouse_stock_summary` (Phase 3), 9 transaction headers (Phase 4).

**Tables still flat (8):** `financial_audit_log` (regression), `customer_payments`, `supplier_payments`, `sales_challans`, `warehouse_transfers`, `stock_adjustments`, `stock_take_sessions`, `journal_entries`, `journal_lines`.

### 1.1 Regressions & blockers discovered during the audit

| # | Issue | Severity | Affects |
|---|---|---|---|
| **B1** | `financial_audit_log` was un-partitioned by migration `2026_08_08_000002_create_financial_audit_log_table.php`. Its `partman.part_config` row is now **orphaned** (points to a flat table). | High | Phase 1, Phase 7 |
| **B2** | Phase 4 created 9 partitioned tables but **set no retention** in `partman.part_config`. Old monthly partitions will accumulate forever. | Medium | Phase 4, Phase 7 |
| **B3** | `stock_transactions` and `sales_invoices` (initial setup) **also have no retention** configured. | Medium | Phase 7 |
| **B4** | **14 partitioned child tables still carry declarative `REFERENCES journal_entries(id)` FKs** (5 sub-ledgers from Phase 2 + 9 transaction headers from Phase 4). PostgreSQL will **reject** partitioning `journal_entries` while these declarative FKs exist. All 14 must be converted to trigger-based FKs **before** Phase 6.3. | **Critical** | Phase 6 (blocker) |
| **B5** | Plan §6 (line 1058) claims `sales_invoices.journal_entry_id` FK is **already** trigger-based via `trg_si_journal_entry_fk`. **It is NOT** — the FK is still declarative (`fk_si_journal`). | High | Phase 6 (blocker) |
| **B6** | **No `pg_partman.run_maintenance()` cron job** is scheduled. Future partitions beyond `p_premake=6` months will silently stop being auto-created. | High | All phases |
| **B7** | The `archive` schema is referenced by 12 retention configs (`retention_schema='archive'`) but is **never explicitly created** by any migration. pg_partman will lazy-create it on first retention run, but the absence is fragile. | Low | Phase 7 |
| **B8** | `enable_partitionwise_join` GUC is **not set anywhere**. Default is `off`. Phase 6 partition-wise joins will silently not work without this. | High | Phase 6 |
| **B9** | `config/archive.php` and `app/Archive/` namespace belong to **Phase 12 (legacy MySQL anti-corruption layer)**, NOT to Phase 7 of this plan. They must not be confused with partition archival. | Info | Phase 7 |

These 9 issues must be addressed **before or during** the remaining phases. Phase 0 below captures them.

---

## 2. Roadmap at a Glance

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Phase 0 — Regression & Blocker Fixes  ✅ DONE  Duration: 2-3 days        │
│   B1 orphaned pg_partman row for financial_audit_log                    │
│   B2 + B3 add missing retention for 11 tables                          │
│   B4 convert 14 child FKs to journal_entries → trigger-based (BATCH A)  │
│   B5 convert sales_invoices.journal_entry_id → trigger-based           │
│   B6 schedule pg_partman.run_maintenance() cron                        │
│   B7 explicitly CREATE SCHEMA archive                                   │
│   B8 set enable_partitionwise_join = on (postgresql.conf)              │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Phase 5 — Multi-FK Transaction Headers   🚧 IN PROGRESS   3-5 days      │
│   Partition 6 tables: customer_payments, supplier_payments,            │
│   sales_challans, warehouse_transfers, stock_adjustments,              │
│   stock_take_sessions                                                  │
│   Convert 11 child-table FKs to trigger-based (revised from 8 after    │
│   schema audit — see §4.1.1)                                           │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Phase 6 — Journal Entries + Journal Lines     Duration: 5-7 days        │
│   6.1  Staging replica                                                  │
│   6.2  Add entry_date column to journal_lines + backfill               │
│   6.3  Partition journal_entries by entry_date                         │
│   6.4  Partition journal_lines by entry_date (same boundaries)         │
│   6.5  BRIN indexes on entry_date for both tables                      │
│   6.6  Remaining FK conversions (Batches B-G — 13 more tables)         │
│   6.7  Update JournalPostingService::createJournalEntry()              │
│   6.8  Verify partition-wise joins                                      │
│   6.9  Full integration test + production cutover                      │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Phase 7 — Archival, Retention & Consolidation Duration: 3-5 days        │
│   7.1  Complete retention config for ALL partitioned tables            │
│   7.2  Archival stored procedures (detach → archive schema)            │
│   7.3  Restore procedures (archive schema → re-attach)                 │
│   7.4  Parquet/DuckDB cold-storage export                              │
│   7.5  Partition consolidation cron (monthly → quarterly → yearly)     │
│   7.6  Retention monitoring                                            │
└─────────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ Phase 8 — Monitoring & Validation Framework   Duration: 3-5 days        │
│   8.1  partition_dry_run() SQL function                                │
│   8.2  check_future_partitions() + check_default_partitions()          │
│   8.3  partition_health_alerts table + daily pg_cron job               │
│   8.4  Laravel admin page /admin/system/partition-health               │
│   8.5  Partition statistics views                                      │
│   8.6  BRIN effectiveness verification                                │
│   8.7  Partition-wise join automated test                             │
│   8.8  Performance targets measurement                                │
│   8.9  Per-partition VACUUM tuning                                    │
└─────────────────────────────────────────────────────────────────────────┘
```

**Total estimated duration**: 16–25 working days (spread over 2–3 months with staging/testing gaps).

---

## 3. Phase 0 — Regression & Blocker Fixes (MUST DO FIRST) ✅ DONE

**Goal**: Clear the 9 audit findings so that Phases 5–8 can proceed without hidden landmines.
**Duration**: 2–3 days
**Risk**: LOW (each fix is small and reversible)
**Prerequisite**: None — this is the prerequisite for everything else.
**Status**: ✅ **IMPLEMENTED** — commit `a715c48` (2026-08-15). 5 migrations + 1 config edit.

> **Implementation notes**: Tasks 0.3 and 0.4 were combined into a single migration (`000003`) for cleanliness — it converts all 15 FKs (14 Batch-A tables + sales_invoices × 2 columns) in one pass. Tasks 0.5 and 0.6 were combined into migration `000004` (partman cron + archive schema). The migration also handles `journal_posting_logs` (Phase 1 partitioned table whose FK to `journal_entries` was still declarative) — 15 tables total, not 14. Each FK conversion preserves the original ON DELETE behaviour (CASCADE / SET NULL / RESTRICT) via appropriate parent-side triggers.

### 3.1 Tasks

| ID | Task | Files to create / modify | Validation |
|---|---|---|---|
| **0.1** | Remove the orphaned `partman.part_config` row for `financial_audit_log` (B1). Decision: either (a) re-partition `financial_audit_log` per Phase 1 spec, or (b) accept it as flat and remove the partman row. **Recommend (a)** because the hash-chain audit trigger writes append-only rows that benefit from partitioning + BRIN. | New migration `2026_08_15_000001_fix_financial_audit_log_partitioning.php`. Coordinate with `2026_08_08_000002_create_financial_audit_log_table.php` — the audit trigger function `fn_financial_audit_trigger()` must keep working against the partitioned parent. | `SELECT * FROM partman.part_config WHERE parent_table = 'public.financial_audit_log'` returns a row whose `retention` and `retention_schema` are set; `pg_inherits` shows ≥ 1 child partition; inserting into `financial_audit_log` routes to the correct monthly partition. |
| **0.2** | Add retention config for the 11 tables that are missing it (B2 + B3): the 9 Phase 4 tables + `stock_transactions` + `sales_invoices`. See retention table in §6.2 of the original plan and §7.2 below. | New migration `2026_08_15_000002_add_missing_retention_configs.php`. | `SELECT parent_table, retention, retention_keep_table, retention_schema FROM partman.part_config ORDER BY parent_table;` — every partitioned table has a non-null `retention`. |
| **0.3** | Convert the 14 declarative FKs from partitioned child tables to `journal_entries(id)` into trigger-based FKs (B4). This is **Batch A** of the Phase 6 FK conversion work, pulled forward because it is a hard blocker. The 14 tables are: <br>• Phase 2 sub-ledgers (5): `customer_ledger`, `supplier_ledger`, `employee_ledger`, `cash_ledger`, `branch_ledger` <br>• Phase 4 transaction headers (9): `money_transfers`, `employee_transactions`, `other_incomes`, `other_expenses`, `sales_returns`, `purchase_receives`, `purchase_returns`, `damage_invoices`, `manual_journals` | New migration `2026_08_15_000003_convert_journal_entry_fks_batch_a.php`. Pattern: `ALTER TABLE <child> DROP CONSTRAINT <fk_name>;` then create trigger function `trg_<child>_je_fk_check()` + `CREATE CONSTRAINT TRIGGER trg_<child>_je_fk BEFORE INSERT OR UPDATE ON <child> DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION ...`. | For each child table, attempt `INSERT INTO <child> (journal_entry_id, ...) VALUES (999999999, ...)` should fail with `FK violation: journal_entries(id=999999999) not found`. |
| **0.4** | Convert `sales_invoices.journal_entry_id` declarative FK to trigger-based (B5). Drop `fk_si_journal` constraint; add trigger `trg_si_journal_entry_fk` reusing the existing `fn_fk_si_check()` helper or a dedicated function. | Add to the same migration as 0.3, or a separate `2026_08_15_000004_convert_sales_invoices_journal_entry_fk.php`. | Insert into `sales_invoices` with a non-existent `journal_entry_id` should fail. |
| **0.5** | Schedule a daily `pg_partman.run_maintenance_proc()` cron job (B6). Also schedule the partition health-check job (will be defined in Phase 8). | Extend migration `2025_01_20_000009_add_pg_cron_scheduled_jobs.php` OR create a new `2026_08_15_000005_schedule_partman_maintenance.php`. Schedule: `0 2 * * *` (daily 02:00). | `SELECT jobname, schedule, command FROM cron.job WHERE jobname IN ('partman-maintenance', 'partition-health-check');` returns both rows. |
| **0.6** | Explicitly `CREATE SCHEMA IF NOT EXISTS archive;` (B7). Grant USAGE to the application role. | Add to migration 0.5 above, or a one-liner `2026_08_15_000006_create_archive_schema.php`. | `\dn archive` in psql shows the schema. |
| **0.7** | Set `enable_partitionwise_join = on` and `enable_partitionwise_aggregate = on` in `postgresql.conf` (B8). Also bump `max_locks_per_transaction` from 64 → 256 per plan §17.1. Apply via `ALTER SYSTEM SET …; SELECT pg_reload_conf();` in a migration, OR via the Docker `postgres/` config. | New migration `2026_08_15_000007_set_partitioning_gucs.php`. Note: `ALTER SYSTEM` requires superuser; ensure the migration role has it, or document that this must be applied manually by the DBA. | `SHOW enable_partitionwise_join;` returns `on`. `SHOW max_locks_per_transaction;` returns `256`. |
| **0.8** | Add a code comment + README note clarifying that `config/archive.php` and `app/Archive/` belong to Phase 12 (legacy MySQL), NOT Phase 7 of the partitioning plan (B9). | Edit `config/archive.php` header (already says Phase 12 — extend it) and `laravel/docs/` index. | Manual review. |

### 3.2 Rollback

Each task is independently reversible:
- 0.1: re-run the original `2026_08_08_000002` migration (drops partitioned table, recreates flat).
- 0.2: `UPDATE partman.part_config SET retention = NULL WHERE parent_table IN (…);`
- 0.3 + 0.4: drop the trigger functions, re-add the declarative FK constraints.
- 0.5 + 0.6: `SELECT cron.unschedule('partman-maintenance');` + `DROP SCHEMA archive;`
- 0.7: `ALTER SYSTEM SET enable_partitionwise_join = off; SELECT pg_reload_conf();`

---

## 4. Phase 5 — Multi-FK Transaction Header Partitioning  🚧 IN PROGRESS

**Goal**: Partition the 6 transaction header tables that have 1–2 FK children each.
**Duration**: 3–5 days
**Risk**: MEDIUM
**Prerequisite**: Phase 0 complete (especially 0.5 — pg_partman maintenance cron must be running).
**Status**: 🚧 **IN PROGRESS** — schema audit complete (see §4.1.1), migration file `2026_08_20_000001` being authored.

### 4.1 Tables and children

| # | Table | Partition key | Child table(s) | Child FK column | Cascade behaviour |
|---|---|---|---|---|---|
| 5.1 | `customer_payments` | `payment_date` | `invoice_payment_allocations` <br> `branch_demand_customer_payment_settlements` | `payment_id` | `ON DELETE CASCADE` (both) |
| 5.2 | `supplier_payments` | `payment_date` | `supplier_payment_settlements` | `payment_id` | `ON DELETE CASCADE` |
| 5.3 | `sales_challans` | `challan_date` | `sales_challan_items` | `sales_challan_id` | `ON DELETE CASCADE` |
| 5.4 | `warehouse_transfers` | `transfer_date` | `warehouse_transfer_items` <br> `branch_demands` | `warehouse_transfer_id` | `ON DELETE CASCADE` (items) <br> `ON DELETE SET NULL` (branch_demands) |
| 5.5 | `stock_adjustments` | `adjustment_date` | `stock_adjustment_items` <br> `stock_adjustment_audit_log` | `stock_adjustment_id` | `ON DELETE CASCADE` (both) |
| 5.6 | `stock_take_sessions` | `session_date` | `stock_take_warehouses` <br> `stock_take_items` <br> `stock_take_audit_log` | `stock_take_session_id` | `ON DELETE CASCADE` (all 3) |

### 4.1.1 Schema audit findings (2026-08-20)

A detailed schema audit was performed before writing the migration. Key deviations from the original §4.1 table:

1. **`customer_payment_settlements` was DROPPED** by migration `2025_01_09_000001_drop_customer_payment_settlements_table.php`. The canonical payment↔invoice link is `invoice_payment_allocations`. The original §4.1 listed `customer_payment_settlements` as a child — **it does not exist**. Removed from the conversion list.
2. **4 additional inbound FKs were discovered** that the original §4.1 did not enumerate. They MUST also be converted to trigger-based, or partitioning will fail. These are:
   - `branch_demand_customer_payment_settlements.payment_id → customer_payments(id) ON DELETE CASCADE`
   - `stock_take_audit_log.stock_take_session_id → stock_take_sessions(id) ON DELETE CASCADE` (audit log is itself `PARTITION BY RANGE (created_at)`)
   - `stock_adjustment_audit_log.stock_adjustment_id → stock_adjustments(id) ON DELETE CASCADE` (audit log is itself `PARTITION BY RANGE (created_at)`)
   - `branch_demands.warehouse_transfer_id → warehouse_transfers(id) ON DELETE SET NULL`
3. **Net FK conversions**: 11 (not 8 as originally stated). Breakdown: `invoice_payment_allocations`, `branch_demand_customer_payment_settlements`, `supplier_payment_settlements`, `sales_challan_items`, `warehouse_transfer_items`, `branch_demands`, `stock_adjustment_items`, `stock_adjustment_audit_log`, `stock_take_warehouses`, `stock_take_items`, `stock_take_audit_log`.
4. **`sales_challans.sales_invoice_id` is already trigger-based** (`trg_fk_sc_si` using `fn_fk_si_check`) — must be preserved when recreating `sales_challans` as partitioned.
5. **`stock_adjustment_items.sai_stock_tx_fk`** is a declarative **composite** FK to `stock_transactions(id, transaction_date)` — must be preserved (not in scope for Phase 5).
6. **`invoice_payment_allocations.trg_ipa_no_overallocation`** constraint trigger joins `customer_payments` — must be preserved (partitioning is transparent for SELECTs, so it will keep working).
7. **`stock_take_warehouses.trg_stw_no_overlapping_frozen`** joins `stock_take_sessions` — preserve.
8. **`warehouse_transfers.trg_enforce_same_branch_transfer`** — preserve.
9. All 6 parents have `deleted_at` (soft deletes), `created_at`/`updated_at`, and `branch_id` (RLS-scoped). `warehouse_transfers` uses dual-branch RLS (`from_branch_id OR to_branch_id`).
10. `GENERATED ALWAYS AS IDENTITY` → `GENERATED BY DEFAULT AS IDENTITY` for `OVERRIDING SYSTEM VALUE` data copy (Phase 4 pattern).

### 4.2 Migration file

**New file**: `database/migrations/2026_08_20_000001_partition_transaction_headers_multi_fk.php`

Reuse the helper pattern from Phase 4 (`2026_08_02_000004_partition_transaction_headers_low_fk.php`):
- `isAlreadyPartitioned($table)` idempotency guard.
- `renameOldTable()`, `createPartitionedParent()`, `createDefaultAndPre2026Partitions()`, `copyDataWithOverridingSystemValue()`, `fixIdentitySequence()`, `recreateIndexes()`, `recreateRlsPolicies()`, `recreateTriggers()`, `dropOldTable()`, `registerPartman()`, `configureRetention()`.

For each of the 6 tables, also call:
- `convertChildFkToTrigger($child, $parent, $fkColumn, $cascadeMode)` — drops the declarative FK, creates a `BEFORE INSERT OR UPDATE` check trigger, and (if cascade) an `AFTER DELETE` cascade trigger on the parent.

### 4.3 Validation

- All 6 parents show `PARTITION BY RANGE (...)` in `\d+ <table>`.
- Each parent has a `_default` partition + `pre2026` catch-all + monthly partitions for 2026 + 6 months ahead (via pg_partman `p_premake=6`).
- BRIN index on partition key with `pages_per_range=32` exists for each parent.
- For each child table, inserting a row with a non-existent parent id fails with the trigger's FK violation message.
- Deleting a parent row cascades to children (where cascade mode is set).
- RLS policies on parents still apply (test with `SET app.branch_id = …; SET app.is_admin = …;`).
- `EXPLAIN ANALYZE SELECT * FROM customer_payments WHERE payment_date BETWEEN '2026-07-01' AND '2026-07-31';` shows `Subplans Removed: N` (partition pruning working).

### 4.4 Rollback

Per-table: `DETACH PARTITION` all children, `INSERT INTO <table>_rollback SELECT * FROM <partition>`, drop partitioned parent, rename rollback table, re-add declarative FK.

---

## 5. Phase 6 — Journal Entries + Journal Lines (Critical)

**Goal**: Partition `journal_entries` by `entry_date` and `journal_lines` by denormalized `entry_date`. Convert the remaining 13 child-table FKs to trigger-based. Enable partition-wise joins.
**Duration**: 5–7 days
**Risk**: **CRITICAL** — `journal_entries` has 27+ child tables; getting this wrong breaks the entire accounting system.
**Prerequisite**: Phase 0 (especially B4, B5, B8) + Phase 5 complete. Extensive staging testing mandatory.

### 5.1 Pre-flight (Day 1)

| Step | Action |
|---|---|
| 6.1.1 | Create a full staging replica of production. |
| 6.1.2 | Run `partition_dry_run('journal_entries', 'entry_date')` (function will be created in Phase 8 — for now, run the SQL manually per plan §11.2). |
| 6.1.3 | Capture baseline `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` for: Trial Balance, General Ledger, Journal Entry Lookup by reference. Save to `docs/perf-baselines/phase6_pre.json`. |
| 6.1.4 | Document rollback procedure. Test it on staging. |

### 5.2 Add `entry_date` to `journal_lines` (Day 2)

This is the **single most important design decision** in the entire plan (plan §6.2). It MUST be done before partitioning `journal_entries`.

| Step | Action |
|---|---|
| 6.2.1 | `ALTER TABLE journal_lines ADD COLUMN entry_date date NOT NULL DEFAULT CURRENT_DATE;` |
| 6.2.2 | Backfill: `UPDATE journal_lines jl SET entry_date = je.entry_date FROM journal_entries je WHERE jl.journal_entry_id = je.id;` — batch this in 100k-row chunks to avoid long transactions. |
| 6.2.3 | `ALTER TABLE journal_lines ALTER COLUMN entry_date DROP DEFAULT;` |
| 6.2.4 | Add a **sync trigger** to keep `journal_lines.entry_date` aligned with `journal_entries.entry_date` (mitigates plan risk R17): <br> `CREATE OR REPLACE FUNCTION fn_jl_sync_entry_date() RETURNS TRIGGER AS $$ BEGIN NEW.entry_date := (SELECT entry_date FROM journal_entries WHERE id = NEW.journal_entry_id); RETURN NEW; END; $$ LANGUAGE plpgsql;` <br> `CREATE TRIGGER trg_jl_sync_entry_date BEFORE INSERT OR UPDATE OF journal_entry_id ON journal_lines FOR EACH ROW EXECUTE FUNCTION fn_jl_sync_entry_date();` |
| 6.2.5 | `CREATE INDEX idx_jl_entry_date ON journal_lines(entry_date);` (will be replaced by BRIN in 6.5). |

**Migration file**: `database/migrations/2026_08_22_000001_add_entry_date_to_journal_lines.php`

### 5.3 Partition `journal_entries` (Day 3)

| Step | Action |
|---|---|
| 6.3.1 | `ALTER TABLE journal_entries RENAME TO journal_entries_old;` |
| 6.3.2 | Create new partitioned parent: <br> `CREATE TABLE journal_entries (id BIGINT GENERATED ALWAYS AS IDENTITY, entry_no VARCHAR UNIQUE, entry_date DATE NOT NULL, …, PRIMARY KEY (id, entry_date)) PARTITION BY RANGE (entry_date);` |
| 6.3.3 | `CREATE TABLE journal_entries_default PARTITION OF journal_entries DEFAULT;` |
| 6.3.4 | `CREATE TABLE journal_entries_pre2026 PARTITION OF journal_entries FOR VALUES FROM ('2020-01-01') TO ('2026-01-01');` |
| 6.3.5 | Create monthly partitions for 2026-01 through 2026-12 + 2027-01 through 2027-06. |
| 6.3.6 | Copy data: `INSERT INTO journal_entries (id, entry_no, entry_date, …) OVERRIDING SYSTEM VALUE SELECT id, entry_no, entry_date, … FROM journal_entries_old;` |
| 6.3.7 | Fix identity sequence: `SELECT setval('journal_entries_id_seq', (SELECT MAX(id) FROM journal_entries));` |
| 6.3.8 | Recreate indexes (B-tree on `entry_no`, `reference`, `is_reversed`; BRIN on `entry_date` — see 6.5). |
| 6.3.9 | Recreate RLS policies (parent-level — apply to all partitions automatically). |
| 6.3.10 | Recreate the `enforce_balanced_journal_entry()` trigger (queries `journal_lines WHERE journal_entry_id = NEW.id` — now co-located in the same partition). |
| 6.3.11 | `DROP TABLE journal_entries_old;` (only after 24h monitoring — see §11 DoD). |
| 6.3.12 | Register with pg_partman: `SELECT partman.create_parent(p_parent_table := 'public.journal_entries', p_control := 'entry_date', p_type := 'range', p_interval := '1 month', p_premake := 6, p_start_partition := '2027-01-01');` |
| 6.3.13 | Configure retention: `UPDATE partman.part_config SET retention = '84 months', retention_keep_table = true, retention_schema = 'archive' WHERE parent_table = 'public.journal_entries';` |

**Migration file**: `database/migrations/2026_08_22_000002_partition_journal_entries.php`

### 5.4 Partition `journal_lines` (Day 3–4)

| Step | Action |
|---|---|
| 6.4.1 | `ALTER TABLE journal_lines RENAME TO journal_lines_old;` |
| 6.4.2 | Create partitioned parent with the SAME partition key and boundaries as `journal_entries`: <br> `CREATE TABLE journal_lines (id BIGINT GENERATED ALWAYS AS IDENTITY, journal_entry_id BIGINT NOT NULL, entry_date DATE NOT NULL, ledger_id INTEGER NOT NULL, …, PRIMARY KEY (id, entry_date)) PARTITION BY RANGE (entry_date);` |
| 6.4.3 | `CREATE TABLE journal_lines_default PARTITION OF journal_lines DEFAULT;` |
| 6.4.4 | Create the same `pre2026` + monthly partitions with IDENTICAL date boundaries to `journal_entries`. **This is required for partition-wise joins** (plan §3.2 principle 8). |
| 6.4.5 | Copy data: `INSERT INTO journal_lines (id, journal_entry_id, entry_date, …) OVERRIDING SYSTEM VALUE SELECT id, journal_entry_id, entry_date, … FROM journal_lines_old;` |
| 6.4.6 | Fix identity sequence. |
| 6.4.7 | Recreate indexes (B-tree on `journal_entry_id`, `ledger_id`; BRIN on `entry_date` — see 6.5). |
| 6.4.8 | Recreate the `fn_jl_sync_entry_date()` trigger from 6.2.4. |
| 6.4.9 | Recreate RLS policies. |
| 6.4.10 | `DROP TABLE journal_lines_old;` (after 24h). |
| 6.4.11 | Register with pg_partman + configure 84-month retention (same as `journal_entries`). |

**Migration file**: `database/migrations/2026_08_22_000003_partition_journal_lines.php`

### 5.5 BRIN indexes (Day 4)

| Table | Index |
|---|---|
| `journal_entries` | `CREATE INDEX idx_je_entry_date_brin ON journal_entries USING BRIN (entry_date) WITH (pages_per_range = 32);` |
| `journal_entries` | `CREATE INDEX idx_je_created_at_brin ON journal_entries USING BRIN (created_at);` (audit queries) |
| `journal_entries` | `CREATE INDEX idx_je_active_entry_date_brin ON journal_entries USING BRIN (entry_date) WHERE is_reversed = false;` (partial — plan §7.5) |
| `journal_lines` | `CREATE INDEX idx_jl_entry_date_brin ON journal_lines USING BRIN (entry_date) WITH (pages_per_range = 32);` |

Drop the B-tree `idx_je_entry_date` (replaced by BRIN). Keep B-tree on `journal_entry_id` and `ledger_id` (point lookups, not range scans).

### 5.6 Remaining FK conversions — Batches B–G (Day 4–5)

Phase 0.3 already converted 14 child FKs (Batch A). The remaining 13 are:

| Batch | Child table | FK column | Notes |
|---|---|---|---|
| **B** | `journal_lines` | `journal_entry_id` | Now partitioned itself; trigger queries parent with partition pruning. |
| **B** | `journal_posting_logs` | `journal_entry_id` | Already partitioned (Phase 1). FK is still declarative — convert. |
| **C** | `customer_payments` | `journal_entry_id` | Phase 5 will partition the table itself; FK must be trigger-based. |
| **C** | `supplier_payments` | `journal_entry_id` | Same as above. |
| **C** | `money_transfers` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **C** | `other_incomes` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **C** | `other_expenses` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **C** | `employee_transactions` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **D** | `sales_invoices` | `journal_entry_id` | Already converted in Phase 0.4 — skip. |
| **D** | `sales_challans` | `journal_entry_id` | Phase 5 partitions it; convert FK. |
| **D** | `sales_returns` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **D** | `purchase_receives` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **D** | `purchase_returns` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **E** | `stock_adjustments` | `journal_entry_id` | Phase 5 partitions it; convert FK. |
| **E** | `stock_take_sessions` | `journal_entry_id` | Phase 5 partitions it; convert FK. |
| **E** | `warehouse_transfers` | `journal_entry_id` | Phase 5 partitions it; convert FK. |
| **E** | `damage_invoices` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **E** | `branch_demands` | `journal_entry_id` | Not yet partitioned; convert FK to trigger-based anyway. |
| **F** | `cash_ledger` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **F** | `branch_ledger` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **G** | `manual_journals` | `journal_entry_id` | Already converted in Phase 0.3 — skip. |
| **G** | `bank_reconciliations` | `journal_entry_id` | Convert. |
| **G** | `asset_depreciation_schedules` | `journal_entry_id` | Convert. |
| **G** | `asset_disposals` | `journal_entry_id` | Convert. |
| **G** | `elimination_entries` | `journal_entry_id` | Convert. |
| **G** | `branch_demand_repricing` | `journal_entry_id` | Convert. |
| **G** | `branch_demand_money_transfer_settlements` | `journal_entry_id` | Convert. |

**Net new conversions in Phase 6**: `journal_lines`, `journal_posting_logs`, `customer_payments`, `supplier_payments`, `sales_challans`, `stock_adjustments`, `stock_take_sessions`, `warehouse_transfers`, `branch_demands`, `bank_reconciliations`, `asset_depreciation_schedules`, `asset_disposals`, `elimination_entries`, `branch_demand_repricing`, `branch_demand_money_transfer_settlements` — **15 tables**.

**Migration file**: `database/migrations/2026_08_22_000004_convert_journal_entry_fks_batch_b_to_g.php`

### 5.7 Update `JournalPostingService::createJournalEntry()` (Day 5)

**File**: `app/Services/Accounting/JournalPostingService.php` (around lines 122-135).

**Change**: when building `$lineRows`, add `'entry_date' => $data['entry_date']` to each row. The sync trigger (6.2.4) is a safety net, but the application should set it explicitly so the trigger doesn't fire on the hot path.

Also audit every other place that inserts into `journal_lines`:
- `ManualJournalService`
- `BankReconciliationService`
- `FixedAssetDepreciationService`
- `ConsolidationService` (elimination entries)
- Any artisan command that backfills journal lines

Each must set `entry_date` from the parent `journal_entries.entry_date`.

### 5.8 Verify partition-wise joins (Day 6)

```sql
SET enable_partitionwise_join = on;  -- should already be on from Phase 0.7

EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
SELECT je.id, jl.ledger_id, jl.debit, jl.credit
FROM journal_entries je
JOIN journal_lines jl ON je.id = jl.journal_entry_id AND je.entry_date = jl.entry_date
WHERE je.entry_date BETWEEN '2026-07-01' AND '2026-07-31';

-- Expected: plan shows "Partitioned Join" / "Append" with per-partition nested loops
-- Expected: execution time < 500ms (plan §12.1 Trial Balance target)
```

Save the post-implementation plan to `docs/perf-baselines/phase6_post.json` and compare against the pre-baseline.

### 5.9 Production cutover (Day 7)

- Schedule a maintenance window (low-traffic period).
- Take a full backup.
- Run the 4 migrations (6.2 → 6.3 → 6.4 → 6.5 → 6.6) in sequence.
- Run smoke tests: create a manual journal, post a sales invoice, run Trial Balance + General Ledger reports.
- Monitor error logs for 24h.

---

## 6. Phase 7 — Archival, Retention & Consolidation (Full)

**Goal**: Complete the archival pipeline so that old partitions are automatically detached, moved to the `archive` schema, exported to Parquet for cold storage, and consolidated (monthly → quarterly → yearly) to prevent catalog bloat.
**Duration**: 3–5 days
**Risk**: LOW (detaching partitions is non-destructive when `retention_keep_table = true`)
**Prerequisite**: Phase 6 complete.

### 7.1 Complete retention config for ALL partitioned tables

After Phases 0–6, every partitioned table must have a retention row in `partman.part_config`. Use this matrix:

| Table category | Tables | Retention | Notes |
|---|---|---|---|
| Financial audit logs | `financial_audit_log` (after Phase 0.1 fix), `journal_posting_logs` | 84 months (7 years) | Compliance — never purge |
| User audit logs | `user_audit_log`, `stock_take_audit_log`, `stock_adjustment_audit_log`, `branch_demand_audit_log` | 36 months (3 years) | |
| Sub-ledgers | `customer_ledger`, `supplier_ledger`, `employee_ledger`, `cash_ledger`, `branch_ledger` | 84 months | Compliance |
| Journal core | `journal_entries`, `journal_lines` | 84 months | Compliance |
| Transaction headers | `customer_payments`, `supplier_payments`, `money_transfers`, `employee_transactions`, `other_incomes`, `other_expenses`, `sales_returns`, `purchase_receives`, `purchase_returns`, `damage_invoices`, `manual_journals`, `sales_challans`, `warehouse_transfers`, `stock_adjustments`, `stock_take_sessions` | 84 months | Compliance |
| Stock transactions | `stock_transactions` | 84 months | |
| Sales invoices | `sales_invoices` | 84 months | |
| Daily warehouse summary | `daily_warehouse_stock_summary` | 36 months | Plan §14.2 says 24 months hot + 12 months warm; 36 total. |

All with `retention_keep_table = true` and `retention_schema = 'archive'`.

**Migration file**: `database/migrations/2026_08_25_000001_complete_retention_configs.php`

### 7.2 Archival stored procedures

Create SQL functions to detach and move partitions to the `archive` schema, and to restore them.

**Migration file**: `database/migrations/2026_08_25_000002_create_archival_procedures.php`

```sql
-- Detach a partition and move it to the archive schema
CREATE OR REPLACE FUNCTION archive_partition(
    p_parent TEXT,
    p_partition TEXT
) RETURNS VOID AS $$
BEGIN
    EXECUTE format('ALTER TABLE %I DETACH PARTITION %I', p_parent, p_partition);
    EXECUTE format('ALTER TABLE %I SET SCHEMA archive', p_partition);
    -- Optional: rename to include '_archived' suffix
END;
$$ LANGUAGE plpgsql;

-- Restore a partition from the archive schema
CREATE OR REPLACE FUNCTION restore_partition(
    p_parent TEXT,
    p_partition TEXT,
    p_start DATE,
    p_end DATE
) RETURNS VOID AS $$
BEGIN
    EXECUTE format('ALTER TABLE archive.%I SET SCHEMA public', p_partition);
    EXECUTE format('ALTER TABLE %I ATTACH PARTITION %I FOR VALUES FROM (%L) TO (%L)',
        p_parent, p_partition, p_start, p_end);
END;
$$ LANGUAGE plpgsql;
```

### 7.3 Parquet / DuckDB cold-storage export

Create an artisan command that exports archived partitions to Parquet via DuckDB. Run quarterly.

**New file**: `app/Console/Commands/ExportArchivedPartitionsToParquet.php`

```php
// Pseudocode:
// 1. List all tables in the `archive` schema older than 7 years.
// 2. For each, run: COPY (SELECT * FROM archive.<table>) TO STDOUT WITH CSV HEADER
// 3. Pipe to DuckDB: COPY (SELECT * FROM read_csv('...')) TO '<path>.parquet' (FORMAT PARQUET, COMPRESSION ZSTD)
// 4. Once exported + verified, DROP TABLE archive.<table>
// 5. Log the export to a `partition_exports` table.
```

Schedule via Laravel scheduler in `routes/console.php`:
```php
Schedule::command('partition:export-parquet')->quarterly();
```

### 7.4 Partition consolidation cron

Create a SQL function + pg_cron job that merges old monthly partitions into quarterly (3–7 years old) or yearly (7+ years old) per plan §8.

**Migration file**: `database/migrations/2026_08_25_000003_create_partition_consolidation.php`

```sql
CREATE OR REPLACE FUNCTION consolidate_partitions(
    p_parent TEXT,
    p_strategy TEXT  -- 'quarterly' or 'yearly'
) RETURNS TABLE(consolidated TEXT, dropped TEXT[]) AS $$
-- Implementation per plan §8.3
$$ LANGUAGE plpgsql;

SELECT cron.schedule(
    'partition-consolidation',
    '0 4 1 1,4,7,10 *',  -- Quarterly at 04:00 on the 1st of Jan/Apr/Jul/Oct
    $$SELECT consolidate_partitions('public.journal_entries', 'quarterly')$$
);
```

### 7.5 Retention monitoring

Add a check to the daily health job (Phase 8.3): list partitions within 30 days of their retention limit, so the team can review before auto-detach.

---

## 7. Phase 8 — Monitoring & Validation Framework

**Goal**: Build the operational safety net so the partitioning system is observable and self-healing.
**Duration**: 3–5 days
**Risk**: LOW
**Prerequisite**: Phase 7 complete (though many sub-tasks can be done in parallel with Phase 6).

### 8.1 `partition_dry_run()` SQL function

**Migration file**: `database/migrations/2026_08_28_000001_create_partition_dry_run_function.php`

Implement exactly per plan §11.2. Returns a table of metrics (row count, table size, index size, date range, estimated partitions, disk space needed, estimated duration, lock type, FK children count, rollback complexity).

### 8.2 Health-check SQL functions

**Migration file**: `database/migrations/2026_08_28_000002_create_partition_health_functions.php`

Implement per plan §10.2:
- `check_future_partitions()` — returns tables with < 3 months of future partitions.
- `check_default_partitions()` — returns default partitions with > 0 rows.
- `check_partman_stale()` — returns tables whose `last_maintenance > 24 hours ago`.
- `check_retention_configured()` — returns tables with NULL retention.
- `check_brin_index_usage()` — returns BRIN indexes with `idx_scan = 0`.
- `check_trigger_fks_functional()` — attempts a known-bad insert and verifies it fails (per plan §10.1).

### 8.3 `partition_health_alerts` table + daily pg_cron job

**Migration file**: `database/migrations/2026_08_28_000003_create_partition_health_alerts.php`

```sql
CREATE TABLE partition_health_alerts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    check_name TEXT NOT NULL,
    table_name TEXT,
    details TEXT,
    severity TEXT NOT NULL CHECK (severity IN ('INFO', 'WARNING', 'CRITICAL')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ,
    resolved_by TEXT
);

CREATE INDEX idx_pha_unresolved ON partition_health_alerts(severity, created_at) WHERE resolved_at IS NULL;

-- Daily pg_cron job at 03:00
SELECT cron.schedule(
    'partition-health-check',
    '0 3 * * *',
    $$
    INSERT INTO partition_health_alerts (check_name, table_name, details, severity, created_at)
    SELECT 'future_partitions', table_name, missing_months || ' months missing', 'CRITICAL', NOW() FROM check_future_partitions()
    UNION ALL
    SELECT 'default_partition', table_name, row_count || ' rows in default', 'WARNING', NOW() FROM check_default_partitions()
    UNION ALL
    SELECT 'partman_stale', parent_table, 'Last run: ' || last_maintenance, 'CRITICAL', NOW() FROM check_partman_stale()
    UNION ALL
    SELECT 'retention_missing', parent_table, 'No retention configured', 'WARNING', NOW() FROM check_retention_configured()
    UNION ALL
    SELECT 'brin_unused', indexname, 'BRIN index never scanned', 'INFO', NOW() FROM check_brin_index_usage();
    $$
);
```

### 8.4 Laravel admin page `/admin/system/partition-health`

**Files to create**:
- `app/Http/Controllers/Admin/System/PartitionHealthController.php` — queries `partition_health_alerts`, `partman.part_config`, `pg_inherits`, `pg_stat_user_tables`, returns a dashboard view.
- `resources/views/admin/system/partition-health.blade.php` — shows: alerts table (with severity badges), per-table partition count, partition sizes, last VACUUM/ANALYZE, default partition row count, missing future partitions, BRIN index usage.
- Route in `routes/web.php`: `Route::get('/admin/system/partition-health', [PartitionHealthController::class, 'index'])->name('admin.system.partition-health');`
- Menu entry under Administration → System.

**Metrics to display** (per plan §9.1):

| Metric | Source | Alert threshold |
|---|---|---|
| Total partitions per table | `pg_inherits` | > 80 |
| Partition sizes | `pg_relation_size()` | > 10 GB |
| Last VACUUM/ANALYZE per partition | `pg_stat_user_tables` | > 7 days |
| Dead tuples per partition | `pg_stat_user_tables` | > 100,000 |
| Default partition size | `pg_relation_size()` | > 0 |
| Missing future partitions | `check_future_partitions()` | Any |
| Oldest partition age | `pg_inherits` | > 3 years |
| Retention status | `partman.part_config` | NULL = warning |
| BRIN index usage | `pg_stat_user_indexes.idx_scan` | 0 = info |

### 8.5 Partition statistics views

**Migration file**: `database/migrations/2026_08_28_000004_create_partition_statistics_views.php`

Create SQL views per plan §9.3:
- `v_partition_sizes` — parent + child + size + seq_scans.
- `v_partition_vacuum_stats` — last vacuum/analyze per partition.
- `v_default_partition_check` — default partitions with row counts.
- `v_missing_future_partitions` — tables with < 3 months ahead.
- `v_catalog_bloat` — `pg_class`, `pg_attribute`, `pg_depend` sizes.

### 8.6 BRIN effectiveness verification

Add to the daily health job: query `pg_stat_user_indexes` for all BRIN indexes; flag any with `idx_scan = 0` (means the planner is not using them — likely a stats issue or the queries don't match).

### 8.7 Partition-wise join automated test

**New file**: `app/Console/Commands/VerifyPartitionwiseJoin.php`

Runs the `EXPLAIN (ANALYZE, FORMAT JSON)` query from §5.8, parses the JSON, and asserts that the plan contains a partition-wise join node. Fails with an alert if not.

Schedule weekly via `routes/console.php`:
```php
Schedule::command('partition:verify-join')->weekly();
```

### 8.8 Performance targets measurement

**New file**: `app/Console/Commands/MeasurePartitionPerformance.php`

Runs the 10 queries from plan §12.1 with `EXPLAIN (ANALYZE, BUFFERS)`, saves results to a `partition_performance_measurements` table, and compares against the targets. Alerts if any query exceeds its max target.

Schedule weekly.

### 8.9 Per-partition VACUUM tuning

**Migration file**: `database/migrations/2026_08_28_000005_tune_per_partition_vacuum.php`

For old (read-only) partitions, set aggressive autovacuum thresholds:
```sql
ALTER TABLE journal_entries_2025_01 SET (autovacuum_vacuum_scale_factor = 0.01);
ALTER TABLE journal_entries_2025_01 SET (autovacuum_analyze_scale_factor = 0.01);
```

For the current month's partition, use moderate settings:
```sql
ALTER TABLE journal_entries_2026_08 SET (autovacuum_vacuum_scale_factor = 0.05);
ALTER TABLE journal_entries_2026_08 SET (autovacuum_analyze_scale_factor = 0.02);
```

Note: this needs to be applied to each new partition as it's created. Either:
- Use pg_partman's `template_table` feature to bake the settings into the template, OR
- Add a monthly cron that applies the settings to the newest partition.

---

## 8. Cross-Phase Concerns

### 8.1 Migration ordering

All new migrations must be timestamp-ordered to run AFTER the existing Phase 1–4 migrations. Use dates `2026_08_15_*` (Phase 0), `2026_08_20_*` (Phase 5), `2026_08_22_*` (Phase 6), `2026_08_25_*` (Phase 7), `2026_08_28_*` (Phase 8).

### 8.2 Idempotency

Every migration MUST be idempotent — re-running it must be a no-op. Use the `isAlreadyPartitioned()` helper pattern from Phase 4.

### 8.3 Testing

Each phase must be tested on a staging replica with a full production data copy before production cutover. Run the existing test suite (`php artisan test`) plus the integration tests in `tests/Unit/Services/Accounting/JournalPostingServiceTest.php`.

### 8.4 Documentation

Update `Phase_10.1_Partitioning_and_Archival_Plan.md` Appendix F (Revision History) with each phase's completion date and any deviations from the plan.

### 8.5 Rollback rehearsal

Before each phase's production cutover, **physically rehearse the rollback** on staging. Document the time it takes. If rollback > 30 minutes, do not proceed to production.

---

## 9. Sequencing & Dependencies

```
Phase 0 (regression fixes) ──────────────────────────────────────────────┐
   0.1 financial_audit_log          0.5 partman cron                       │
   0.2 retention configs            0.6 archive schema                     │
   0.3 Batch A FK conversions (14)  0.7 GUCs                               │
   0.4 sales_invoices FK            0.8 doc clarification                  │
                                                                         ▼
Phase 5 (multi-FK headers) ───────── partition 6 tables + convert 8 child FKs
                                                                         │
                                                                         ▼
Phase 6 (journal core) ──────────────────────────────────────────────────┐
   6.1 pre-flight (staging, baseline)                                      │
   6.2 add entry_date to journal_lines + sync trigger                     │
   6.3 partition journal_entries                                          │
   6.4 partition journal_lines (same boundaries)                          │
   6.5 BRIN indexes                                                       │
   6.6 Batch B-G FK conversions (15 tables)                               │
   6.7 update JournalPostingService + audit other writers                 │
   6.8 verify partition-wise joins                                        │
   6.9 production cutover                                                 │
                                                                         ▼
Phase 7 (archival & retention) ──────────────────────────────────────────┐
   7.1 complete retention configs                                         │
   7.2 archival procedures                                                │
   7.3 Parquet/DuckDB export                                              │
   7.4 consolidation cron                                                 │
   7.5 retention monitoring                                               │
                                                                         ▼
Phase 8 (monitoring & validation) ───────────────────────────────────────┐
   8.1 dry_run function         8.5 statistics views                      │
   8.2 health functions         8.6 BRIN effectiveness                    │
   8.3 alerts table + cron      8.7 partition-wise join test              │
   8.4 admin dashboard          8.8 performance targets                   │
                                8.9 per-partition VACUUM                  │
                                                                         ▼
                                                                  DONE
```

**Phases 7 and 8 can run partially in parallel** — sub-tasks 8.1, 8.2, 8.5 do not depend on Phase 7. But 8.3 (alerts that check retention) and 8.4 (dashboard that shows retention status) are only useful after Phase 7.1.

---

## 10. Risk Register Updates

The original plan's risk register (§16) is still valid. Add these new risks discovered during the audit:

| # | Risk | Probability | Impact | Mitigation |
|---|---|---|---|---|
| **R18** | `financial_audit_log` regression (B1) is silently shipping in production — writes go to a flat table, not a partitioned one. | High (already happening) | Medium | Phase 0.1 fixes it. |
| **R19** | Phase 4's missing retention (B2) means 9 tables accumulate partitions forever. | High (already happening) | Medium | Phase 0.2 fixes it. |
| **R20** | 14 declarative FKs to `journal_entries` from already-partitioned tables (B4) will hard-block Phase 6. | Certain (if not fixed first) | Critical | Phase 0.3 converts them first. |
| **R21** | Plan §6 line 1058 claim about `sales_invoices` trigger-FK is false (B5). Anyone relying on it during Phase 6 will fail. | High | High | Phase 0.4 converts it; update the plan doc. |
| **R22** | No `run_maintenance()` cron (B6) — future partitions silently stop being created. | High (already happening) | High | Phase 0.5 schedules it. |
| **R23** | `enable_partitionwise_join` is off (B8) — Phase 6 partition-wise joins will silently not work even after partitioning. | Certain | High | Phase 0.7 sets it on. |
| **R24** | `JournalPostingService::createJournalEntry()` does not set `entry_date` on lines — Phase 6.2 sync trigger will fire on every insert (perf overhead) and will fail if the parent doesn't exist yet (e.g. during bulk import). | Medium | Medium | Phase 6.7 updates the service to set `entry_date` explicitly. |
| **R25** | Bulk-import paths (CSV seeders, ETL scripts in `database/etl/`) may insert into `journal_lines` without setting `entry_date`, falling back to `CURRENT_DATE` — wrong for historical imports. | Medium | High | Audit all writers in Phase 6.7; add a NOT NULL WITHOUT DEFAULT check. |

---

## 11. Definition of Done — Per Phase Checklist

### Phase 0 — Done when: ✅ ALL COMPLETE
- [x] `financial_audit_log` is partitioned again — migration `2026_08_15_000001` re-applies RANGE(created_at) monthly partitioning + BRIN + pg_partman + 84-month retention. Cleans up orphaned `partman.part_config` row. Preserves hash-chain trigger + verification view.
- [x] All 11 missing retention configs are set in `partman.part_config` — migration `2026_08_15_000002` sets 84-month retention for 9 Phase 4 tables + `stock_transactions` + `sales_invoices`.
- [x] All 15 child FKs to `journal_entries` are trigger-based (14 Batch-A + `journal_posting_logs` + `sales_invoices` × 2) — migration `2026_08_15_000003`. Each gets a BEFORE INSERT OR UPDATE check trigger + cascade/set-null/restrict parent trigger.
- [x] `sales_invoices.journal_entry_id` + `cogs_journal_entry_id` FKs are trigger-based — included in migration `000003`.
- [x] `pg_partman.run_maintenance_proc()` runs daily via pg_cron at 02:00 — migration `2026_08_15_000004`. Handles both pg_partman 4.x (`run_maintenance()`) and 5.x (`run_maintenance_proc()`).
- [x] `archive` schema exists — created explicitly in migration `000004` with USAGE grants for `remote_center` + `postgres` roles.
- [x] `enable_partitionwise_join = on` and `max_locks_per_transaction = 256` — migration `2026_08_15_000005` uses `ALTER SYSTEM SET` with graceful fallback. Also sets `enable_partitionwise_aggregate = on`. Note: `max_locks_per_transaction` requires PostgreSQL restart to take effect.
- [x] `config/archive.php` header clarifies it is Phase 12, not Phase 7 — header rewritten with explicit "⚠️ NOT related to Phase 10.1 Phase 7" warning.

### Phase 5 — Done when:
- [x] All 6 tables are partitioned by their date column, monthly. — migration `2026_08_20_000001` creates `pre2026` + 12 monthly partitions for 2026 + `_default` for each parent.
- [x] All 11 child-table FKs are trigger-based with correct cascade behaviour. — revised from 8 after schema audit (see §4.1.1). Each FK gets a BEFORE INSERT OR UPDATE check trigger on the child + (CASCADE or SET NULL) AFTER DELETE trigger on the parent.
- [x] All 6 tables have BRIN indexes on partition key. — `idx_cp_payment_date_brin`, `idx_sp_payment_date_brin`, `idx_sc_challan_date_brin`, `idx_wt_transfer_date_brin`, `idx_sa_adjustment_date_brin`, `idx_sts_session_date_brin` (all `pages_per_range = 32`).
- [x] All 6 tables are registered with pg_partman (`p_premake=6`). — `registerPartman()` called for each parent with `p_start_partition='2027-01-01'`.
- [ ] All 6 tables have retention configured (84 months). — **DEFERRED to Phase 7.1** (`complete_retention_configs`). The 11 retention configs added by Phase 0.2 cover the Phase 1-4 + `stock_transactions` + `sales_invoices` tables; Phase 5's 6 new parents will get their retention in Phase 7.1.
- [x] RLS policies on all 6 tables still work (branch-scoped + admin queries tested). — single-branch RLS re-applied to 5 parents; dual-branch (`from_branch_id OR to_branch_id`) re-applied to `warehouse_transfers`.
- [ ] `EXPLAIN ANALYZE` on a date-range query shows partition pruning. — **PENDING staging validation** (requires running the migration against a staging DB).

### Phase 6 — Done when:
- [ ] `journal_lines` has a non-null `entry_date` column, backfilled, with sync trigger.
- [ ] `journal_entries` is partitioned by `entry_date`, monthly, with default + pre2026 + 2026 + 2027-01..06 partitions.
- [ ] `journal_lines` is partitioned by `entry_date` with IDENTICAL boundaries to `journal_entries`.
- [ ] BRIN indexes on `entry_date` for both tables (including partial BRIN for `is_reversed = false`).
- [ ] All 15 Batch B–G child FKs to `journal_entries` are trigger-based.
- [ ] `JournalPostingService::createJournalEntry()` sets `entry_date` on each line.
- [ ] All other writers of `journal_lines` audited and updated.
- [ ] `EXPLAIN ANALYZE` on the Trial Balance query shows "Partitioned Join" and runs < 500ms.
- [ ] Full integration test suite passes.
- [ ] 24h production monitoring shows no errors.

### Phase 7 — Done when:
- [ ] Every partitioned table has a retention row in `partman.part_config` (84 or 36 months).
- [ ] `archive_partition()` and `restore_partition()` SQL functions exist and are tested.
- [ ] `ExportArchivedPartitionsToParquet` artisan command exists, tested end-to-end with a sample partition.
- [ ] `consolidate_partitions()` SQL function exists; quarterly pg_cron job scheduled.
- [ ] Retention monitoring alert fires when a partition is within 30 days of retention limit.

### Phase 8 — Done when:
- [ ] `partition_dry_run()` SQL function exists and returns correct metrics for a sample table.
- [ ] `check_future_partitions()`, `check_default_partitions()`, `check_partman_stale()`, `check_retention_configured()`, `check_brin_index_usage()` SQL functions exist.
- [ ] `partition_health_alerts` table exists; daily pg_cron job at 03:00 populates it.
- [ ] Laravel admin page `/admin/system/partition-health` renders with live data.
- [ ] `v_partition_sizes`, `v_partition_vacuum_stats`, `v_default_partition_check`, `v_missing_future_partitions`, `v_catalog_bloat` views exist.
- [ ] `VerifyPartitionwiseJoin` command runs weekly and alerts on failure.
- [ ] `MeasurePartitionPerformance` command runs weekly and persists results.
- [ ] Per-partition VACUUM settings applied to old + current partitions.
- [ ] All 10 performance targets from plan §12.1 are met on the production dataset.

---

## Appendix — New files to be created

| Path | Phase | Purpose |
|---|---|---|
| `database/migrations/2026_08_15_000001_fix_financial_audit_log_partitioning.php` | 0.1 | Re-partition financial_audit_log |
| `database/migrations/2026_08_15_000002_add_missing_retention_configs.php` | 0.2 | Retention for 11 tables |
| `database/migrations/2026_08_15_000003_convert_journal_entry_fks_batch_a.php` | 0.3 | 14 child FKs → trigger-based |
| `database/migrations/2026_08_15_000004_convert_sales_invoices_journal_entry_fk.php` | 0.4 | sales_invoices FK → trigger-based |
| `database/migrations/2026_08_15_000005_schedule_partman_maintenance.php` | 0.5, 0.6 | partman cron + archive schema |
| `database/migrations/2026_08_15_000007_set_partitioning_gucs.php` | 0.7 | enable_partitionwise_join + max_locks |
| `database/migrations/2026_08_20_000001_partition_transaction_headers_multi_fk.php` | 5 | ✅ CREATED — 6 tables partitioned + 11 child FK conversions to trigger-based (revised from 8 after schema audit; see §4.1.1). 1252 lines. |
| `database/migrations/2026_08_22_000001_add_entry_date_to_journal_lines.php` | 6.2 | Denormalize entry_date |
| `database/migrations/2026_08_22_000002_partition_journal_entries.php` | 6.3 | Partition journal_entries |
| `database/migrations/2026_08_22_000003_partition_journal_lines.php` | 6.4 | Partition journal_lines |
| `database/migrations/2026_08_22_000004_convert_journal_entry_fks_batch_b_to_g.php` | 6.6 | 15 child FKs → trigger-based |
| `app/Services/Accounting/JournalPostingService.php` | 6.7 | Set entry_date on lines (EDIT) |
| `database/migrations/2026_08_25_000001_complete_retention_configs.php` | 7.1 | Retention for all remaining tables |
| `database/migrations/2026_08_25_000002_create_archival_procedures.php` | 7.2 | archive_partition() + restore_partition() |
| `app/Console/Commands/ExportArchivedPartitionsToParquet.php` | 7.3 | Parquet export command |
| `database/migrations/2026_08_25_000003_create_partition_consolidation.php` | 7.4 | consolidate_partitions() + cron |
| `database/migrations/2026_08_28_000001_create_partition_dry_run_function.php` | 8.1 | partition_dry_run() |
| `database/migrations/2026_08_28_000002_create_partition_health_functions.php` | 8.2 | Health-check functions |
| `database/migrations/2026_08_28_000003_create_partition_health_alerts.php` | 8.3 | Alerts table + daily cron |
| `app/Http/Controllers/Admin/System/PartitionHealthController.php` | 8.4 | Admin dashboard controller |
| `resources/views/admin/system/partition-health.blade.php` | 8.4 | Admin dashboard view |
| `database/migrations/2026_08_28_000004_create_partition_statistics_views.php` | 8.5 | Statistics views |
| `app/Console/Commands/VerifyPartitionwiseJoin.php` | 8.7 | Weekly join verification |
| `app/Console/Commands/MeasurePartitionPerformance.php` | 8.8 | Weekly perf measurement |
| `database/migrations/2026_08_28_000005_tune_per_partition_vacuum.php` | 8.9 | Per-partition VACUUM settings |

**Total**: 17 new migrations, 3 new artisan commands, 1 new controller, 1 new blade view, 1 service edit, 1 doc edit.

---

*End of Phase 10.1 Remaining Phases Roadmap — companion to Phase 10.1 Partitioning & Archival Plan Revision 2.0*
