# Implementation Plan — Audit Confirmation (Session 0)

| Field | Value |
|---|---|
| **Session** | 0 — Pre-Flight: Audit & Branch Setup |
| **Date** | 2026-08-16 |
| **Baseline commit** | `39e8b25133cef321dbbf8480d304ef1ac8337725` (on `main`) |
| **Baseline message** | `docs: add implementation plan for FY isolation + inter-branch P&L` |
| **Feature branch** | `feature/fy-isolation-and-branch-pnl` (created, checked out) |
| **Executed by** | Super Z (analysis sandbox) |
| **Plan reference** | `docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md` |

---

## 1. Gap Re-verification

All 8 gaps from the original audit (worklog Task IDs 3-a, 4-a) were re-verified against the current `main` HEAD. **All 8 remain open.** No independent work has closed any of them since the audit.

### Gap 1 — Operational tables have no `fiscal_year_id` column
**Status: CONFIRMED OPEN**

`fiscal_year_id` exists ONLY on fiscal-control and planning tables:
- `fiscal_periods.fiscal_year_id` — migration `2026_08_10_000004_create_enhanced_period_and_fiscal_year_controls.php:61`
- `period_close_log.fiscal_year_id` — same migration, line 83
- `budgets.fiscal_year_id` — same migration, lines 102–108 (nullable, added alongside legacy `fiscal_year` VARCHAR column)
- `consolidation_runs.fiscal_year_id` — migration `2026_08_11_000001_create_intercompany_and_consolidation.php:82`

Verified ABSENT from operational tables. Grepped `sales_invoices`, `sales_invoice_items`, `purchases`, `journal_entries`, `branch_demands`, `branch_demand_items`, `stock_movements`, `customer_ledger`, `supplier_ledger`, `branch_ledger` — none have a `fiscal_year_id` column. The 36 migrations touching `sales_invoices`/`sales_invoice_items` were reviewed; none adds `fiscal_year_id`.

**Impact**: Queries on operational data are scoped only by date range, not by FY. Even after a fiscal year is "closed", any user with read access can filter by last year's dates and see everything. Session 1 closes this gap.

---

### Gap 2 — No hard read-block on closed/locked FY data
**Status: CONFIRMED OPEN**

Grepped for `FiscalYearPolicy`, `viewHistoricalData`, `BelongsToFiscalYear` across the entire `laravel/` tree — **zero matches**.

- No `FiscalYearPolicy` exists in `app/Policies/`. The 21 existing policies (per audit) do not include one for `FiscalYear`.
- No `BelongsToFiscalYear` trait exists in `app/Models/Concerns/`.
- No global scope on any operational model filters by `fiscal_year_id` (which is consistent with Gap 1 — the column doesn't exist).
- `Gate::before()` in `AppServiceProvider` still applies the super-admin bypass unconditionally to every ability — no exclusion for a `viewHistoricalData` ability (because that ability doesn't exist yet).

**Impact**: There is currently no application-layer mechanism to prevent any user — including super admin — from viewing closed-FY data via the UI or via URL parameters. Session 2 closes this gap.

---

### Gap 3 — No in-app `db:backup` command; no year-end backup gate
**Status: CONFIRMED OPEN**

Grepped for `backup-year-end`, `db:backup`, `BackupDatabaseYearEnd`, `DatabaseBackupService`, `spatie.*backup` — **zero matches**.

- No `app/Console/Commands/BackupDatabaseYearEnd.php`.
- No `app/Services/DatabaseBackupService.php`.
- No `database_backups` table.
- No `spatie/laravel-backup` in `composer.json` (verified via grep).
- `yearEndClose()` in `AccountingPeriodService` has 5 pre-flight checks (per audit) — none of them is a backup-freshness check.

**Impact**: Year-end close can proceed without any backup file existing on disk. The client's requirement — "when a fiscal year is done the system will auto backup the database file from the system and store in pc" — is not satisfied. Session 3 closes this gap.

---

### Gap 4 — No partition DETACH on close; `opening_balance` columns not refreshed by `yearEndClose()`
**Status: CONFIRMED OPEN (infrastructure exists, but is not wired into close)**

**Infrastructure present** (confirmed):
- `archive_partition(p_parent, p_partition)` PL/pgSQL function — migration `2026_08_25_000002_create_archival_procedures.php:81`
- `restore_partition(p_parent, p_partition, p_start, p_end)` — same migration, line 154
- `archive` schema exists
- ~30 partitioned operational tables (per audit Task 3-a §6)
- `pg_partman` and `pg_cron` are referenced by migrations `2026_08_02_000003`, `2026_08_28_000004`, `2026_08_28_000005` — these migrations degrade gracefully (log a warning) if the extensions are absent, so the dev team must confirm they are actually installed on the dev DB (see §3 below).

**Gap (not wired)**:
- No `FiscalYearPartitionService` class.
- No `detachAndArchive()` call from `yearEndClose()`.
- `ledgers.opening_balance`, `customers.opening_balance`, `suppliers.opening_balance` are static columns (per audit) — confirmed not refreshed by `yearEndClose()` (no code path touches them).

**Impact**: Even after Gap 1 and Gap 2 are closed, the data is still physically in the parent table — a determined super admin using `withoutGlobalScope('current_fy')` could still read it. Partition DETACH makes it physically invisible. Session 4 closes this gap.

---

### Gap 5 — `sales_invoice_items` does not persist price classification per line
**Status: CONFIRMED OPEN**

`price_min`, `price_max`, `price_default` exist on `branch_demand_items` — migration `2026_07_29_000011_align_branch_demand_items_table.php` lines 47, 51, 55. These are the **locked inter-branch cost and price range** captured at demand-send time. ✅ (This is the existing infrastructure Q2 builds on.)

However, on `sales_invoice_items`:
- No `price_min`, `price_max`, `price_default` columns.
- No `cost_rate` column (the inter-branch cost snapshot at sale time).
- No `price_classification` column.
- No `below_min_override_id` column.

The cart stores min/max/default in `sales_draft_carts.items_json` (per audit), but this context is lost on finalize — `sales_invoice_items` only has `qty`, `rate`, `amount` (GENERATED), `discount_amount`.

**Impact**: It is impossible today to answer "of B's 10 sold units, how many were at min vs max vs below-min" without re-deriving it from `product_price_history` (which may have changed since the sale). Session 5 closes this gap.

---

### Gap 6 — No admin-override workflow for below-min sales
**Status: CONFIRMED OPEN**

Grepped `app/` for `below_min`, `price_override`, `discount_approval`, `BelowMinApproval` — **only one match**: `app/Services/BranchDemand/BranchDemandRepricingService.php:790`, where `'below_min'` appears as a **string literal** in an audit-log `type` field for *demand repricing* (admin/manager re-prices an inter-branch demand). This is NOT a customer-sale below-min approval workflow.

- No `BelowMinApprovalService`.
- No `SalesBelowMinApprovalController`.
- No `below_min_override_id` on `sales_invoice_items` (consistent with Gap 5).
- `SalesCartService::addItem()` still hard-throws `RuntimeException` on rate outside `[min, max]` (per audit) — no override path.

Existing overrides in the codebase are for different concerns: `credit_limit_override` (customer credit) and `period_close_admin_override` (accounting period close). Both log to `user_audit_log`.

**Impact**: The client's requirement — "sometime need to less more then less price only by permission from the admin" — is not satisfied. The cart simply blocks the sale. Session 6 closes this gap.

---

### Gap 7 — No FK from `sales_invoice_items` to `branch_demand_items` (FIFO attribution)
**Status: CONFIRMED OPEN**

Grepped for `branch_demand_item_id`, `consumed_qty`, `DemandItemFifoResolver`. The `branch_demand_item_id` field appears in `app/Services/Stock/StockService.php` lines 49 and 103 — but as a **field inside a JSON metadata payload** on stock movements (a `reference_id` / `branch_demand_item_id` pair used for stock-movement lineage), NOT as a column on `sales_invoice_items`.

- No `branch_demand_item_id` column on `sales_invoice_items`.
- No `consumed_qty` column on `branch_demand_items`.
- No `DemandItemFifoResolver` class.
- The anti-gaming detection in `BranchDemandAuditService::getSalesBelowLockedCost()` (per audit) joins `branch_demand_items` to `sales_invoice_items` by `product_id` — a heuristic, not deterministic attribution.

**Impact**: When B has stock of the same product from two different demands (at different cost rates), the system cannot tell which demand a specific sale drew from. Per-demand profit/loss attribution is impossible. Session 7 closes this gap.

---

### Gap 8 — No Branch P&L report
**Status: CONFIRMED OPEN**

Grepped for `BranchPnlReport`, `BranchPnl`, `branch_pnl`, `BranchPerformanceReport` — **zero matches**.

- No `BranchPnlReportService`.
- No `BranchPnlReportController`.
- No `pnl` route under `/admin/branches/` or `/admin/branch-demands/`.
- No `branches/pnl.blade.php` or `branch-demands/pnl.blade.php` view.
- The existing reconciliation view at `/admin/branch-demands/reconcile` (per audit) compares demand outstanding vs `branch_ledger.running_balance` — a data-integrity check, NOT a P&L statement.
- The per-USER performance dashboard exists (planned, `docs/USER_PERFORMANCE_DASHBOARD_PLAN.md`) but is a different concern.

**Impact**: The client's requirement — "A will have a clear view of B due and performance and sales with demand" — is not satisfied as a single report. Session 8 closes this gap.

---

## 2. Feature Branch

- **Created**: `feature/fy-isolation-and-branch-pnl` off `main` at `39e8b25`.
- **Checked out**: yes.
- **All Sessions 1–8 commits** go to this branch.
- **Merge to `main`**: only after Session 8 signoff.

---

## 3. DB-Dependent Tasks — Runbook for Dev Team

> **Note**: The analysis sandbox where Session 0 was executed does not have Docker, local PostgreSQL, or `pg_isready`/`pg_dump`/`psql` binaries available, and there is no `laravel/.env` file checked in. The four DB-dependent tasks below MUST be executed on the dev machine where the RC ERP Docker stack runs. The dev team should run them and record the results in `docs/worklog.md` under Task ID `0-db`.

### 3.1 Snapshot the dev database (acceptance test for S0.4)

```bash
# Run on the dev machine, from the repo root
mkdir -p ~/rcerp_snapshots
docker exec rcerp_postgres pg_dump -Fc -U rcerp_app -d rcerp \
  > ~/rcerp_snapshots/pre_implementation_$(date +%Y%m%d_%H%M%S).dump

# Verify the dump is non-empty and valid
ls -lh ~/rcerp_snapshots/pre_implementation_*.dump
docker exec rcerp_postgres pg_restore -l ~/rcerp_snapshots/pre_implementation_*.dump | head -20
```

**Pass criteria**: file exists, size > 50% of expected DB size, `pg_restore -l` lists expected tables.

### 3.2 Confirm `pg_partman` and `pg_cron` extensions (acceptance test for S0.5)

```bash
docker exec rcerp_postgres psql -U rcerp_app -d rcerp -c "\dx"
# Expect to see: pg_partman, pg_cron, pg_trgm (at minimum)
```

If `pg_partman` or `pg_cron` is missing, several migrations will have logged warnings but degraded gracefully. **Before Session 4**, install them:
```bash
docker exec rcerp_postgres psql -U rcerp_app -d rcerp -c "CREATE EXTENSION IF NOT EXISTS pg_partman; CREATE EXTENSION IF NOT EXISTS pg_cron;"
```
Note: `pg_cron` requires `shared_preload_libraries = 'pg_cron'` in `postgresql.conf` — a Postgres restart is needed if it's not already loaded.

### 3.3 Identify test fiscal years (acceptance test for S0.6)

```bash
docker exec rcerp_postgres psql -U rcerp_app -d rcerp -c \
  "SELECT id, name, start_date, end_date, status FROM fiscal_years ORDER BY start_date;"
```

**Pass criteria**: at least one row with `status='active'` and (ideally) one with `status='closed'`. If no closed FY exists, create a synthetic one for testing:
```sql
INSERT INTO fiscal_years (name, start_date, end_date, status, created_at, updated_at)
VALUES ('TEST CLOSED FY 2024', '2024-01-01', '2024-12-31', 'closed', NOW(), NOW());
```

Record the two IDs in `docs/worklog.md` under Task ID `0-db`.

### 3.4 Identify test branches A and B with existing branch demands (acceptance test for S0.7)

```bash
docker exec rcerp_postgres psql -U rcerp_app -d rcerp -c \
  "SELECT bd.id AS demand_id, bd.demanding_branch_id AS branch_b, bd.supplying_branch_id AS branch_a,
          bd.status, bd.created_at
   FROM branch_demands bd
   ORDER BY bd.created_at DESC
   LIMIT 5;"
```

**Pass criteria**: at least one `branch_demands` row exists between two distinct branches. Record the two branch IDs (A = supplying, B = demanding) in `docs/worklog.md` under Task ID `0-db`. These IDs are referenced by Sessions 5–8.

If no branch demands exist yet, create test branches and a test demand before Session 5.

---

## 4. PM Checkpoint — Client Signoff Required

The following requirement is non-negotiable and must be confirmed by the client **in writing** (email or chat) before Session 2 begins:

> **"After a fiscal year is closed, NO user — including the super admin — may view that year's data through the application UI. The only way to view closed-year data is to restore the database backup file separately."**

Specifically, the client must confirm they understand and accept:

1. **Super admin is also blocked.** This is enforced by amending `Gate::before()` in `AppServiceProvider` so the super-admin bypass does NOT apply to the `viewHistoricalData` ability. If the client later wants a "super admin can see historical reports" exception, that is a scope change and must be raised now.

2. **No historical reports in the UI.** All existing reports (Sales, Purchases, Inventory, Branch Demands, P&L, etc.) will only show running-FY data. There will be NO "select fiscal year" dropdown on any report.

3. **The restore path is manual.** To view closed-FY data, an admin must run `php artisan fy:detach-archived` reverse flow (i.e., `FiscalYearPartitionService::restoreForViewing($fyId)`) on the command line. This is intentionally NOT exposed in the UI. The client accepts this is the only viewing path.

4. **Backup file is on the PC, not in the cloud.** The `BACKUP_PATH` env var on production points to a local path on the PC (e.g., `C:\rcerp\backups\`). The client is responsible for ensuring this path is backed up to off-PC storage (external drive, cloud sync) per their own disaster-recovery policy. The application does NOT auto-upload backups to the cloud.

**PM action**: Email the client with the above 4 points. Do not start Session 2 until written confirmation is received. Paste the confirmation into `docs/worklog.md` under Task ID `0-pm`.

---

## 5. Acceptance Test Summary

| Test | Status | Notes |
|---|---|---|
| Feature branch `feature/fy-isolation-and-branch-pnl` exists and is checked out | ✅ PASS | Created off `main` at `39e8b25` |
| This doc (`IMPLEMENTATION_PLAN_AUDIT_CONFIRMATION.md`) exists with all 8 gaps listed | ✅ PASS | You are reading it |
| Gap 1 confirmed open (no `fiscal_year_id` on operational tables) | ✅ PASS | See §1.1 |
| Gap 2 confirmed open (no `FiscalYearPolicy`, no global scope, no `viewHistoricalData` gate) | ✅ PASS | See §1.2 |
| Gap 3 confirmed open (no `db:backup` command, no `DatabaseBackupService`) | ✅ PASS | See §1.3 |
| Gap 4 confirmed open (no `detachAndArchive` on close; `opening_balance` not refreshed) | ✅ PASS | See §1.4 — infrastructure exists, not wired |
| Gap 5 confirmed open (no price classification on `sales_invoice_items`) | ✅ PASS | See §1.5 |
| Gap 6 confirmed open (no below-min override workflow for customer sales) | ✅ PASS | See §1.6 |
| Gap 7 confirmed open (no `branch_demand_item_id` on `sales_invoice_items`; no `consumed_qty`) | ✅ PASS | See §1.7 |
| Gap 8 confirmed open (no Branch P&L report) | ✅ PASS | See §1.8 |
| Dev DB snapshot file exists at documented path | ⏳ PENDING | Dev team runbook §3.1 |
| `pg_partman` + `pg_cron` extensions active on dev DB | ⏳ PENDING | Dev team runbook §3.2 |
| Two test fiscal-year IDs recorded in worklog | ⏳ PENDING | Dev team runbook §3.3 |
| Two test branch IDs (A, B) recorded in worklog | ⏳ PENDING | Dev team runbook §3.4 |
| Client confirmed hard read-block requirement includes super admin | ⏳ PENDING | PM checkpoint §4 |

---

## 6. Ready for Session 1

Once the 5 ⏳ PENDING items above are complete (dev team runbook + client signoff), Session 1 may begin. Session 1's goal is to add the `fiscal_year_id` column to all operational tables — a pure schema migration with no application behaviour change, so it carries low risk and can proceed immediately after the pending items close.
