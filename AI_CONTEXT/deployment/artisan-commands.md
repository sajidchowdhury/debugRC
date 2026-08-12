# Artisan Commands Catalogue

> **Module:** Deployment (Custom artisan commands + verification + ops)
> **Audience:** Engineers, DevOps, DBAs, accountants, AI assistants
> **Status:** Canonical
> **Last reviewed:** Phase 19 (initial)
> **Source of truth:** this file, grounded in `laravel/app/Console/Commands/*.php` (27
> custom commands), `laravel/routes/console.php` (schedule definitions),
> `../database/partitioning.md` (partition command design),
> `../database/migrations-conventions.md`, and `../security/audit-trails.md`.

---

## 1. What is it?

RC_ERP_v2 ships **27 custom artisan commands** in `laravel/app/Console/Commands/`. They
fall into 5 categories:

1. **Verification commands** (10) — read-only checks that assert the books balance, stock
   is correct, partitions are healthy, RBAC is enforced. Run after every deploy + before
   every period close.
2. **Partitioning ops commands** (3) — measure partition query performance, verify
   partition-wise joins, export archived partitions to Parquet cold storage. Scheduled
   weekly/quarterly via `routes/console.php`.
3. **Migration commands** (3) — one-time commands that move data from the legacy MySQL
   archive into PostgreSQL (employees, master data, API tokens).
4. **Setup commands** (1) — `rcerp:setup`, the bootstrap command that loads schema + runs
   migrations + creates the admin user.
5. **Operational commands** (10) — cancel stale drafts, refresh report views, reconcile
   stock drift, generate API tokens, run the listen-notify worker, snapshot/restore DB,
   diagnose customer queries, penetration-test RBAC, shadow-compare warehouse transfers.

This file is the **canonical catalogue** of every custom command: its signature, options,
what it does, when to run it, what it writes to disk/DB, and the cross-reference to the
deeper module doc. It also covers the **standard Laravel commands** the ERP uses
(`migrate`, `config:cache`, `key:generate`, etc.) with deployment-specific notes.

### 1.1 Why a catalogue?

Because `php artisan list` shows the signatures but not the **operational context** — when
to run it, what failure looks like, what it writes, who owns it. A release manager
cutting a deploy needs to know "run `journal:replay-verify` after every migration that
touches the GL" without reading the command's source. An accountant closing the month
needs to know "run `subledger:reconcile` + `reversal:verify` before signing off". An AI
assistant suggesting a debugging step needs to know "run `customers:diagnose` to repro
the CustomerController code path". This file is that context.

---

## 2. Why does it exist?

- **Operational runbook.** Every command has a "when to run" + "what it checks" + "what
  failure means" entry. Release managers and on-call engineers consult this file as the
  runbook.
- **Safety verification.** 10 of the 27 commands are **read-only verification** commands.
  They are the safety net for accounting integrity, stock accuracy, partition health, and
  RBAC enforcement. Running them after every deploy is mandatory per `go-live-checklist.md`.
- **Partitioning ops.** 3 commands operate the partitioning/archival system (measure perf,
  verify joins, export to Parquet). These are the operational surface of the partitioning
  design documented in `../database/partitioning.md`.
- **Legacy migration.** 3 commands perform the one-time data migration from the legacy
  MySQL archive. They are idempotent + dry-run-by-default — safe to re-run.
- **Schedule transparency.** 6 commands are scheduled via `routes/console.php`. This file
  documents what each scheduled command does + when it runs, cross-referenced from
  `cron-scheduled-jobs.md`.
- **Onboarding.** A new engineer (or AI) can scan §7 to see every operational command in
  one place, rather than `ls laravel/app/Console/Commands/` + reading 27 PHP files.

---

## 3. When is it used?

- **After every deploy** — run the §9.1 verification suite (5 commands).
- **Before every period close** — run the §9.2 accounting verification suite (4 commands).
- **Quarterly** — partition export to Parquet (scheduled automatically).
- **Weekly** — partition perf measurement + join verification (scheduled automatically).
- **On provisioning a fresh VPS** — `rcerp:setup` + `migrate:master-data`.
- **On user complaints about customer ledger** — `customers:diagnose`.
- **On security incidents** — `sales:pen-test` + `api:token` (re-issue tokens).
- **During DBA maintenance** — `db:snapshot-basic` + `db:restore-basic` + `db:make-empty`.
- **Continuously (long-running)** — `listen-notify:worker` (via supervisor).

---

## 4. Who uses it?

- **DevOps engineer** — runs deploy-time + schedule-time commands.
- **Release manager** — runs the §9 verification suites + signs off.
- **DBA** — runs partitioning ops + DB snapshot/restore.
- **Accountant** — runs period-close verification (§9.2) + reads the output.
- **Backend engineers** — consult when adding new commands or modifying existing ones.
- **AI assistants** — MUST consult this file before suggesting any artisan command. Never
  suggest a command not in this catalogue without flagging it as "not yet documented".

---

## 5. Related modules

- `environment.md` — env vars consumed by commands (e.g. `api:token` reads `APP_KEY`).
- `cron-scheduled-jobs.md` — the schedule definitions for the 6 scheduled commands.
- `go-live-checklist.md` §9 — the verification suites that use these commands.
- `../database/partitioning.md` — the partitioning design that the 3 partition commands
  operate.
- `../database/etl-legacy-migration.md` — the ETL pipeline that the 3 migration commands
  complete.
- `../accounting/journal-posting-rules.md` — what `journal:replay-verify` asserts.
- `../accounting/subledger-reconciliation.md` — what `subledger:reconcile` asserts.
- `../inventory/stock-ledger.md` — what `stock:replay-verify` asserts.
- `../security/rbac-roles-permissions.md` — what `sales:pen-test` asserts.
- `../api/api-overview.md` §7.5 — what `api:token` does.

---

## 6. Business rules

- **R-1 — Verification commands are READ-ONLY.** The 10 commands in §7.1 NEVER write to
  the database. They may insert into a separate `*_drift` or `*_audit` table for tracking,
  but never mutate business data. Safe to run any time.
- **R-2 — Migration commands are DRY-RUN by default.** `migrate:master-data`,
  `migrate:legacy-employees` require `--execute` (or absence of `--dry-run`) to actually
  write. This prevents accidental data writes during exploration.
- **R-3 — Destructive commands require `--force`.** `db:make-empty`, `db:restore-basic`,
  `db:reseed-basic` prompt for confirmation unless `--force` is passed. In a non-interactive
  context (cron, Docker entrypoint), they MUST be passed `--force` or they fail.
- **R-4 — `rcerp:setup` is idempotent.** Re-running it on an existing DB skips schema
  load + migrations + admin user creation (each step checks for existence first). Safe to
  run multiple times.
- **R-5 — Scheduled commands use `withoutOverlapping()`.** The 6 scheduled commands in
  `routes/console.php` all chain `->withoutOverlapping()` to prevent concurrent runs. A
  slow `reports:refresh` run won't stack with the next 5-minute tick.
- **R-6 — Scheduled commands use `runInBackground()`.** They don't block the scheduler
  worker. The next scheduled command can fire even if the previous is still running
  (subject to R-5's overlap guard).
- **R-7 — `api:token` re-activates disabled users.** Issuing a token sets
  `users.is_active = true` + `credential_version++`. This is intentional (a token issuance
  implies the user should be active) but surprising — see `../api/api-overview.md` §12
  E-5.
- **R-8 — `listen-notify:worker` is a long-running process.** It does NOT exit after
  processing events. It blocks on `pg_listen()` until killed. Must be run under
  supervisor (or as a Docker container) — never via cron.
- **R-9 — `partition:export-parquet` exports + DROPS.** By default, after a successful
  Parquet export, the archived partition table is DROPPED. Use `--keep` to retain the
  table. The export is the cold-storage copy; the table is no longer needed in PG.
- **R-10 — `partition:measure-perf` persists results.** By default, results are saved to
  the `partition_performance_measurements` table. Use `--no-save` to print only. The
  persisted results drive the weekly perf report + alerting.
- **R-11 — Commands respect the `APP_TIMEZONE`.** All `Carbon::now()` calls in commands
  use `APP_TIMEZONE` (Asia/Dhaka). pg_cron jobs run in UTC — see `cron-scheduled-jobs.md`
  §6 for the timezone reconciliation.
- **R-12 — Commands respect RLS.** Most commands set `app.is_admin = true` (via the
  `--admin` flag or by default) to bypass RLS. Commands that don't (e.g.
  `customers:diagnose` without `--admin`) are scoped to the default branch, which may
  return empty results.

---

## 7. Custom command catalogue

> Sorted by category, then alphabetically within category. Each entry: signature,
> description, options, what it does, when to run, what it writes, cross-ref.

### 7.1 Verification commands (10 — read-only)

#### 7.1.1 `chart:validate`

| Field | Value |
|---|---|
| **Signature** | `chart:validate` |
| **File** | `ValidateChartOfAccounts.php` |
| **Description** | Validate the chart of accounts: critical natures, account types, extended natures |
| **Options** | (none) |
| **What it does** | Loads every account from `chart_of_accounts`, checks that each has a valid `account_type` + `nature` + `extended_nature`, and that critical accounts (Cash, AR, AP, Inventory, Sales, COGS, etc.) have the expected natures. Prints a report. |
| **When to run** | After every CoA migration. Before period close. After any `chart:seed`. |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../accounting/chart-of-accounts.md` |

#### 7.1.2 `customers:diagnose`

| Field | Value |
|---|---|
| **Signature** | `customers:diagnose {--branch= : Override session branch_id for the diagnostic} {--admin : Bypass RLS (set app.is_admin = true)} {--limit=25 : Number of rows to fetch} {--controller : Reproduce the EXACT CustomerController::dataTablesResponse() code path}` |
| **File** | `CustomersDiagnose.php` |
| **Description** | Diagnose customer ledger queries (debug slow/empty results) |
| **What it does** | Runs the customer ledger query with various scopes, prints the SQL + row counts + sample rows. The `--controller` flag reproduces the exact code path the CustomerController uses, for debugging "why does this customer show zero balance". |
| **When to run** | When a user reports "customer X has wrong balance" or "customer list is slow". |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../accounting/customer-payments.md`, `../accounting/subledger-reconciliation.md` |

#### 7.1.3 `journal:manual-verify`

| Field | Value |
|---|---|
| **Signature** | `journal:manual-verify {--type= : Filter by reference_type (e.g. sales_invoice, purchase_receive)} {--count=10 : Number of sample entries to show}` |
| **File** | `JournalManualVerify.php` |
| **Description** | Show 10 sample journal entries with full lines for accountant review |
| **What it does** | Picks 10 random journal entries (optionally filtered by `reference_type`), prints the entry header + all lines (debit, credit, account, branch, dimensions). For accountant visual review. |
| **When to run** | During accountant onboarding. When investigating a specific posting. Before period close. |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../accounting/journal-posting-rules.md`, `../accounting/manual-journals.md` |

#### 7.1.4 `journal:replay-verify`

| Field | Value |
|---|---|
| **Signature** | `journal:replay-verify {--fix-orphans : Attempt to fix orphan lines by deleting them}` |
| **File** | `JournalReplayVerify.php` |
| **Description** | Verify GL integrity: balanced entries, Dr=Cr, sub-ledger reconciliation, CoA validation |
| **What it does** | The **master verification** command. Checks: (1) every journal entry has Dr=Cr, (2) no orphan journal lines (lines without a parent entry), (3) sub-ledger totals match GL control accounts, (4) CoA is valid. With `--fix-orphans`, deletes orphan lines (DESTRUCTIVE — use only after investigation). |
| **When to run** | After every deploy. After every migration that touches the GL. Before period close. **MANDATORY per `go-live-checklist.md` §9.1.** |
| **What it writes** | Without `--fix-orphans`: nothing (read-only). With `--fix-orphans`: deletes orphan `journal_lines` rows + writes to `user_audit_log`. |
| **Cross-ref** | `../accounting/journal-posting-rules.md`, `../accounting/subledger-reconciliation.md` |

#### 7.1.5 `partition:verify-join`

| Field | Value |
|---|---|
| **Signature** | `partition:verify-join {--month= : Test month in YYYY-MM format (default: last complete month)}` |
| **File** | `VerifyPartitionwiseJoin.php` |
| **Description** | Verify partition-wise joins are working on JE↔JL (Phase 8.7) |
| **What it does** | Runs `EXPLAIN ANALYZE` on the `journal_entries ↔ journal_lines` join, asserts that a "Partition-wise join" node appears in the plan. Alerts if partition-wise joins silently stop working (which would cause a 10× perf regression). |
| **When to run** | Weekly (scheduled Mondays 05:00). Manually after PG config changes. |
| **What it writes** | (nothing — read-only; results printed to stdout) |
| **Cross-ref** | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |

#### 7.1.6 `partition:measure-perf`

| Field | Value |
|---|---|
| **Signature** | `partition:measure-perf {--month= : Measurement month in YYYY-MM format (default: last complete month)} {--no-save : Do not persist results to the partition_performance_measurements table}` |
| **File** | `MeasurePartitionPerformance.php` |
| **Description** | Measure 10 partition query runtimes vs targets and persist results (Phase 8.8) |
| **What it does** | Runs the 10 benchmark queries from the Phase 10.1 plan §12.1, measures each runtime, compares against the documented target (e.g. <50ms for "this month's invoices"), persists to `partition_performance_measurements`, and alerts on target breaches. |
| **When to run** | Weekly (scheduled Mondays 05:30). Manually after PG config changes or partition consolidation. |
| **What it writes** | Inserts into `partition_performance_measurements` (unless `--no-save`). |
| **Cross-ref** | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |

#### 7.1.7 `reconcile:running-balance`

| Field | Value |
|---|---|
| **Signature** | `reconcile:running-balance {--fix : Fix drift by updating stored balance to computed balance} {--ledger= : Check a single ledger (customer|supplier|employee|cash)} {--as-of= : Check entries up to this date (Y-m-d)} {--top=10 : Number of top-drift entities to show}` |
| **File** | `RunningBalanceReconcile.php` |
| **Description** | Verify stored running balances match computed balances |
| **What it does** | For each ledger entry (customer/supplier/employee/cash), recomputes the running balance from the line items, compares to the stored `running_balance` column, reports drift. With `--fix`, overwrites the stored balance with the computed value (DESTRUCTIVE — use only after investigation). |
| **When to run** | Before period close. After any ledger-affecting migration. When a user reports "balance is wrong". |
| **What it writes** | Without `--fix`: nothing. With `--fix`: updates `running_balance` columns on the affected ledger tables. |
| **Cross-ref** | `../accounting/running-balance.md`, `../accounting/subledger-reconciliation.md` |

#### 7.1.8 `reversal:verify`

| Field | Value |
|---|---|
| **Signature** | `reversal:verify` |
| **File** | `ReversalVerify.php` |
| **Description** | Verify all journal reversals net to zero on Trial Balance |
| **What it does** | For every reversal pair (original entry + reversal entry), asserts they net to zero on the Trial Balance. Catches the bug where a reversal is posted to the wrong account or with the wrong amount. |
| **When to run** | Before period close. After any reversal-affecting migration. |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../accounting/reversal-vs-cancellation.md` |

#### 7.1.9 `stock:manual-verify`

| Field | Value |
|---|---|
| **Signature** | `stock:manual-verify {--product=0 : Verify a specific product_id (default: pick 10 samples)} {--count=10 : Number of sample products to verify (default 10)} {--warehouse=0 : Filter to a specific warehouse_id}` |
| **File** | `StockManualVerify.php` |
| **Description** | Show step-by-step avg-cost calculation for 10 sample products (for accountant sign-off) |
| **What it does** | For each sample product, prints every stock transaction (receive, transfer, sale, return, adjustment) with the running qty + avg-cost after each. For accountant visual review of the avg-cost engine. |
| **When to run** | During accountant onboarding. When investigating a specific product's avg-cost. Before period close. |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../inventory/stock-costing.md`, `../inventory/stock-ledger.md` |

#### 7.1.10 `stock:replay-verify`

| Field | Value |
|---|---|
| **Signature** | `stock:replay-verify {--limit=0 : Limit number of transactions (0 = all)} {--from-id=0 : Start from transaction id} {--product=0 : Filter to a single product_id} {--keep-drift : Do not clear previous drift rows before running}` |
| **File** | `StockReplayVerify.php` |
| **Description** | Verify stock ledger integrity: replay every transaction, assert final qty + avg-cost match |
| **What it does** | The **master stock verification** command. Replays every `stock_transactions` row from the beginning, recomputes `warehouse_stock.qty` + `warehouse_stock.avg_cost`, compares to stored values, reports drift. With `--limit`, processes only N transactions (for fast iteration). |
| **When to run** | After every deploy. After every migration that touches stock. Before period close. **MANDATORY per `go-live-checklist.md` §9.1.** |
| **What it writes** | Inserts into `stock_reconciliation_drift` (cleared first unless `--keep-drift`). Does NOT modify `warehouse_stock` — use `stock:reconcile-drift --fix` (operational, not verification) to fix. |
| **Cross-ref** | `../inventory/stock-ledger.md`, `../inventory/stock-verification.md` |

#### 7.1.11 `subledger:reconcile`

| Field | Value |
|---|---|
| **Signature** | `subledger:reconcile` |
| **File** | `SubLedgerReconcile.php` |
| **Description** | Reconcile sub-ledgers (AR/AP/Employee) against GL control accounts |
| **What it does** | For each sub-ledger (customer, supplier, employee), sums the outstanding balances, compares to the GL control account balance (e.g. `Accounts Receivable` account), reports drift. Catches the bug where a sub-ledger entry was posted without a corresponding GL entry (or vice versa). |
| **When to run** | Before period close. After any sub-ledger-affecting migration. **MANDATORY per `go-live-checklist.md` §9.2.** |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../accounting/subledger-reconciliation.md` |

#### 7.1.12 `branch-demand:verify-schema`

| Field | Value |
|---|---|
| **Signature** | `branch-demand:verify-schema` |
| **File** | `VerifyBranchDemandSchema.php` |
| **Description** | Verify that all Branch Demand database tables, columns, and constraints exist |
| **What it does** | Checks information_schema for the expected `branch_demand_*` tables + columns + FKs. Used after the Branch Demand migration to confirm the schema landed correctly. |
| **When to run** | After the Branch Demand migration. On any VPS where Branch Demand is enabled. |
| **What it writes** | (nothing — read-only) |
| **Cross-ref** | `../finance/branch-demand.md` |

### 7.2 Partitioning ops commands (3)

#### 7.2.1 `partition:export-parquet`

| Field | Value |
|---|---|
| **Signature** | `partition:export-parquet {--dry-run : List what would be exported without doing it} {--keep : Do not drop the archive table after a successful export} {--force : Overwrite an existing Parquet file}` |
| **File** | `ExportArchivedPartitionsToParquet.php` |
| **Description** | Export archived partitions to Parquet cold storage (quarterly) |
| **What it does** | For each partition in the `archive` schema (i.e. partitions detached by pg_partman), exports the data to a Parquet file at `storage/app/partition-exports/<parent>_<YYYY_MM>.parquet`, then DROPS the table (unless `--keep`). The Parquet file is the cold-storage copy. |
| **When to run** | Quarterly (scheduled `30 4 1 1,4,7,10 *`). Manually when the archive schema accumulates too many tables. |
| **What it writes** | Writes Parquet files to `storage/app/partition-exports/`. Drops `archive.<parent>_<YYYY_MM>` tables (unless `--keep`). |
| **Cross-ref** | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |

> See also §7.2 below — `partition:verify-join` and `partition:measure-perf` are listed
> under verification (§7.1) because they're read-only, but they're operationally part of
> the partitioning ops category.

### 7.3 Migration commands (3 — one-time, dry-run default)

#### 7.3.1 `migrate:master-data`

| Field | Value |
|---|---|
| **Signature** | `migrate:master-data {--dry-run : Show what would be migrated without writing} {--skip= : Comma-separated tables to skip}` |
| **File** | `MigrateMasterData.php` |
| **Description** | One-time migration of master data from legacy MySQL to PostgreSQL |
| **What it does** | Reads master data (products, customers, suppliers, branches, warehouses, employees, users, chart of accounts) from the legacy MySQL archive, upserts into PostgreSQL. Idempotent via `ON CONFLICT DO NOTHING`. |
| **When to run** | Once, during initial VPS provisioning. Re-runnable (idempotent). |
| **What it writes** | Inserts/updates master data tables in PostgreSQL. Reads from legacy MySQL (read-only). |
| **Cross-ref** | `../database/etl-legacy-migration.md`, `../archive/legacy-read-only.md` §9 |

#### 7.3.2 `migrate:legacy-employees`

| Field | Value |
|---|---|
| **Signature** | `migrate:legacy-employees {--execute : Actually insert/update data (default: dry-run)} {--force : Skip confirmation prompt}` |
| **File** | `MigrateLegacyEmployees.php` |
| **Description** | Migrate employees + users from the MySQL archive into PostgreSQL (upsert) |
| **What it does** | Reads employees + users from the legacy MySQL archive, upserts into PostgreSQL. Handles the password hash conversion (legacy MD5 → bcrypt) by setting a temp password + flagging for reset. |
| **When to run** | Once, during initial VPS provisioning. Re-runnable (idempotent). |
| **What it writes** | Inserts/updates `employees` + `users` tables in PostgreSQL. |
| **Cross-ref** | `../database/etl-legacy-migration.md`, `../security/password-policy.md` |

#### 7.3.3 `api:token`

| Field | Value |
|---|---|
| **Signature** | `api:token {username : The username to issue a token for} {--role= : Optional role to assign (e.g. admin, manager, salesman)}` |
| **File** | `GenerateApiToken.php` |
| **Description** | Issue a new API token for a user |
| **What it does** | Generates a 60-char random token, SHA-256 hashes it, stores the hash in `users.api_token`, sets `is_active = true` + `credential_version++`. Prints the plain-text token ONCE (never stored). |
| **When to run** | On demand, when a user needs API access. After a token compromise (re-issues a new token, invalidating the old). |
| **What it writes** | Updates `users.api_token` + `users.is_active` + `users.credential_version`. |
| **Cross-ref** | `../api/api-overview.md` §7.5 |

### 7.4 Setup commands (1)

#### 7.4.1 `rcerp:setup`

| Field | Value |
|---|---|
| **Signature** | `rcerp:setup {--force : Skip confirmation prompts} {--skip-schema : Skip loading SQL schema files} {--skip-migrate : Skip running migrations} {--skip-admin : Skip creating admin user}` |
| **File** | `SetupRcerp.php` |
| **Description** | Bootstrap command: load schema + run migrations + create admin user |
| **What it does** | The one-command bootstrap. (1) Loads `database/sql/*.sql` schema files via psql. (2) Runs `php artisan migrate --force`. (3) Creates the HO branch + EMP-0001 employee + admin user (password: `password123`). Each step is guarded by `--skip-*` flags. |
| **When to run** | On a fresh VPS. On a fresh Docker container (the entrypoint calls this internally). Re-runnable (idempotent). |
| **What it writes** | Creates the entire schema + seeds the admin user. |
| **Cross-ref** | `docker-setup.md` §7.5 (entrypoint calls this), `vps-bdix-deployment.md` §8.7 |

### 7.5 Operational commands (10)

#### 7.5.1 `sales:cancel-stale-drafts`

| Field | Value |
|---|---|
| **Signature** | `sales:cancel-stale-drafts {--days= : Override the stale threshold (default: from config, 14)} {--branch= : Only cancel drafts for this branch_id} {--dry-run : Report only, do not cancel}` |
| **File** | `CancelStaleSalesDrafts.php` |
| **Description** | Cancel stale draft sales invoices (older than N days, no godown, no challan) |
| **What it does** | Finds draft sales invoices older than N days (default 14) with no godown/challan activity, cancels them (status → cancelled, GL + customer_ledger reversed). Gated by `config('sales.stale_draft_auto_cancel.enabled')`. |
| **When to run** | Nightly (scheduled 02:00). Manually with `--dry-run` to preview. |
| **What it writes** | Updates `sales_invoices.status`, inserts reversal `journal_entries` + `journal_lines`, updates `customer_ledger`, inserts into `user_audit_log`. |
| **Cross-ref** | `../sales/sales-invoice.md` §11, `cron-scheduled-jobs.md` §7.1 |

#### 7.5.2 `reports:refresh`

| Field | Value |
|---|---|
| **Signature** | `reports:refresh` |
| **File** | `RefreshReportViews.php` |
| **Description** | Refresh all report materialized views (concurrently) |
| **What it does** | Runs `REFRESH MATERIALIZED VIEW CONCURRENTLY` on all 7 financial report MVs. `CONCURRENTLY` doesn't block readers. |
| **When to run** | Every 5 minutes (scheduled). Manually after a heavy data load. |
| **What it writes** | Updates the 7 MVs (`mv_trial_balance`, `mv_balance_sheet`, `mv_income_statement`, etc.). |
| **Cross-ref** | `../reports/materialized-views.md`, `cron-scheduled-jobs.md` §7.2 |

#### 7.5.3 `stock:reconcile-drift`

| Field | Value |
|---|---|
| **Signature** | `stock:reconcile-drift {--dry-run : Report drift only; do not fire notifications} {--branch= : Scope to a single branch_id (default: all branches)}` |
| **File** | `ReconcileStockDrift.php` |
| **Description** | Detect warehouse_stock ↔ stock_transactions drift and alert admins (Phase 7) |
| **What it does** | Computes `warehouse_stock.qty` vs `SUM(stock_transactions.qty)` for every (warehouse, product). When drift is found, fires an `ERPNotification` to every user whose role is in `stock_adjustment.reconcile_alert_roles` (default: admin). Does NOT fix the drift — that requires a manual `stock:adjustment`. |
| **When to run** | Nightly (scheduled 03:00). Manually with `--dry-run` to preview. |
| **What it writes** | Inserts into `stock_reconciliation_drift`. Inserts into `notifications`. |
| **Cross-ref** | `../inventory/stock-adjustment.md`, `cron-scheduled-jobs.md` §7.3 |

#### 7.5.4 `listen-notify:worker`

| Field | Value |
|---|---|
| **Signature** | `listen-notify:worker {--no-dispatch : Skip forwarding to NotificationService} {--channels= : Comma-separated PG channels to listen on (default: all)} {--timeout=0 : Max seconds to run (0 = infinite)}` |
| **File** | `ListenNotifyWorker.php` |
| **Description** | Long-running worker: PG LISTEN → Redis pub/sub → SSE |
| **What it does** | Opens a dedicated PG connection, `LISTEN`s on `rcerp_*` channels, blocks on `pg_listen()`. When a notification arrives, publishes to Redis pub/sub (which the SSE controller subscribes to). With `--no-dispatch`, skips the NotificationService forwarding (for debugging). |
| **When to run** | Continuously (long-running). Run under supervisor (VPS) or as a Docker container (`rcerp_listen_notify`). Never via cron. |
| **What it writes** | Publishes to Redis pub/sub. Optionally calls `NotificationService::dispatch()` which writes to `notifications`. |
| **Cross-ref** | `../architecture/realtime-events.md`, `cron-scheduled-jobs.md` §5 |

#### 7.5.5 `db:snapshot-basic`

| Field | Value |
|---|---|
| **Signature** | `db:snapshot-basic {--dry-run : Print to stdout instead of writing file} {--table= : Snapshot only this table (for testing)}` |
| **File** | `DbSnapshotBasic.php` |
| **Description** | Snapshot basic-data tables (master + config) to database/sql/basic_data_snapshot.sql |
| **What it does** | Exports the basic-data tables (branches, warehouses, products, customers, suppliers, chart of accounts, system_policies, roles) as INSERT statements to `database/sql/basic_data_snapshot.sql`. Used to seed a fresh VPS with master data. |
| **When to run** | After master data changes (e.g. adding a new branch). Before a VPS reprovision. |
| **What it writes** | Writes to `database/sql/basic_data_snapshot.sql`. |
| **Cross-ref** | `vps-bdix-deployment.md` §8.7 |

#### 7.5.6 `db:restore-basic`

| Field | Value |
|---|---|
| **Signature** | `db:restore-basic {--force : Skip confirmation prompt} {--stop-on-error : Abort on first SQL error (default: continue and report)} {--show-statements : Print each statement as it executes}` |
| **File** | `DbRestoreBasic.php` |
| **Description** | Restore basic-data tables from database/sql/basic_data_snapshot.sql |
| **What it does** | Loads `database/sql/basic_data_snapshot.sql` via psql. The inverse of `db:snapshot-basic`. Used during VPS provisioning to restore master data. |
| **When to run** | During VPS provisioning (after `migrate`). Manually to reset master data. |
| **What it writes** | Inserts into basic-data tables. |
| **Cross-ref** | `vps-bdix-deployment.md` §8.7 |

#### 7.5.7 `db:make-empty`

| Field | Value |
|---|---|
| **Signature** | `db:make-empty {--force : Skip confirmation prompt}` |
| **File** | `DbMakeEmpty.php` |
| **Description** | EMPTY ALL DATA from every table (keeps schema). Excludes the migrations table. |
| **What it does** | Runs `TRUNCATE` on every business-data table (excludes `migrations`, `system_policies`). DESTRUCTIVE. |
| **When to run** | In dev only, to reset the DB to an empty state without dropping the schema. Never in production. |
| **What it writes** | Truncates every business-data table. |
| **Cross-ref** | (none — self-explanatory) |

#### 7.5.8 `db:reseed-basic`

| Field | Value |
|---|---|
| **Signature** | `db:reseed-basic {--force : Skip the interactive confirmation prompt}` |
| **File** | `DbReseedBasic.php` |
| **Description** | Re-run all data-seeding migrations to restore basic data (employees, users, customers, products, etc.) from the legacy SQL dumps. |
| **What it does** | Re-runs the data-seeding migrations (the ones that load `database/sql/basic_data_snapshot.sql`). The inverse of `db:make-empty`. |
| **When to run** | After `db:make-empty`, to restore basic data. In dev only. |
| **What it writes** | Inserts into basic-data tables. |
| **Cross-ref** | (none — self-explanatory) |

#### 7.5.9 `sales:pen-test`

| Field | Value |
|---|---|
| **Signature** | `sales:pen-test {--role= : Test a specific role} {--verbose : Show detailed output}` |
| **File** | `SalesPenTest.php` |
| **Description** | Penetration test: verify RBAC + branch isolation on sales routes |
| **What it does** | For each role (admin, manager, salesman, accountant), attempts every sales route (create invoice, finalize, reverse, view other branches' invoices, etc.) and asserts the expected RBAC + branch-isolation behavior. Catches privilege escalation + cross-branch leaks. |
| **When to run** | After RBAC changes. After route changes. Periodically as a security audit. |
| **What it writes** | (nothing — read-only; results printed to stdout) |
| **Cross-ref** | `../security/rbac-roles-permissions.md`, `../security/api-security.md` |

#### 7.5.10 `shadow:compare-transfers`

| Field | Value |
|---|---|
| **Signature** | `shadow:compare-transfers {--from= : Start date (YYYY-MM-DD)} {--to= : End date (YYYY-MM-DD)} {--transfer= : Single transfer ID to compare} {--operation= : Operation for single comparison (create/confirm/cancel)} {--force : Re-compare already compared transfers}` |
| **File** | `ShadowCompareTransfers.php` |
| **Description** | Shadow-compare the new warehouse-transfer service against the legacy implementation |
| **What it does** | For each warehouse transfer in the date range, re-runs the transfer through both the new Laravel service and the legacy PHP logic (via the ACL), compares the resulting stock + GL postings, reports drift. Used during the transition window to validate the new service matches the old behavior. |
| **When to run** | During the transition window, before decommissioning the legacy app. |
| **What it writes** | Inserts into `shadow_comparison_results`. |
| **Cross-ref** | `../inventory/warehouse-transfer.md`, `../archive/anti-corruption-layer.md` |

---

## 8. Standard Laravel commands (deployment-relevant)

> The ERP uses these built-in commands. Only the deployment-specific notes are documented
> here; the full docs are in the Laravel manual.

| Command | Deployment use |
|---|---|
| `php artisan migrate --force` | Run pending migrations (no prompt). Used in §8.6 VPS deploy + Docker entrypoint. |
| `php artisan migrate:fresh --force` | Drop all tables + re-run migrations. Used in Docker entrypoint on fresh DB ONLY. NEVER in production. |
| `php artisan migrate:status` | Verify all migrations are ran. Used in `go-live-checklist.md` §3. |
| `php artisan migrate:rollback` | Roll back the last batch. Use with caution — sees `../database/migrations-conventions.md` §11. |
| `php artisan key:generate` | Generate `APP_KEY`. Run once on VPS provisioning. |
| `php artisan config:cache` | Cache config (reads `.env` once, faster). Run after every `.env` change + every deploy. |
| `php artisan config:clear` | Clear config cache. Run BEFORE `config:cache` (so the new `.env` is read). |
| `php artisan route:cache` | Cache routes. Run after every deploy. |
| `php artisan route:clear` | Clear route cache. Run before `route:cache`. |
| `php artisan view:cache` | Compile Blade views. Run after every deploy. |
| `php artisan view:clear` | Clear compiled views. Run before `view:cache`. |
| `php artisan cache:clear` | Clear the application cache (Redis). Run after env changes that affect cached values. |
| `php artisan schedule:run` | Run the scheduled commands due now. Invoked every minute by cron. |
| `php artisan schedule:work` | Run the scheduler in the foreground (dev only). |
| `php artisan schedule:list` | List all scheduled commands + their next run time. |
| `php artisan queue:work` | Start a queue worker (long-running). Run under supervisor (VPS) or as a Docker container. |
| `php artisan queue:restart` | Restart all queue workers after the current job. Used after deploys. |
| `php artisan queue:failed` | List failed jobs. |
| `php artisan queue:retry all` | Retry all failed jobs. |
| `php artisan optimize` | Runs `config:cache` + `route:cache` + `view:cache` + `event:cache` together. |
| `php artisan optimize:clear` | Clears all caches. |
| `php artisan tinker` | REPL for the Laravel app. Use `--execute="..."` for one-liners. |
| `php artisan db:seed` | Run seeders. The ERP has no general seeders (master data is loaded via `migrate:master-data`). |
| `php artisan storage:link` | Create the `public/storage` symlink. Run once on VPS provisioning. |
| `php artisan down` | Put the app in maintenance mode (503 response). |
| `php artisan up` | Take the app out of maintenance mode. |

---

## 9. Verification suites

### 9.1 Post-deploy verification suite (mandatory)

> Run after every deploy. All must pass (exit code 0) before the deploy is declared
> successful.

```bash
# 1. GL integrity (Dr=Cr, no orphans, sub-ledger reconciliation, CoA valid)
php artisan journal:replay-verify

# 2. Stock ledger integrity (replay every transaction, assert final qty + avg-cost match)
php artisan stock:replay-verify

# 3. Chart of accounts valid
php artisan chart:validate

# 4. RBAC + branch isolation enforced
php artisan sales:pen-test

# 5. Migration status (all migrations ran)
php artisan migrate:status | grep -c Pending
# Expected: 0
```

### 9.2 Period-close verification suite (mandatory)

> Run before every period close (monthly / quarterly / annually). Accountant sign-off
> required.

```bash
# 1. GL integrity (same as post-deploy)
php artisan journal:replay-verify

# 2. Reversals net to zero on Trial Balance
php artisan reversal:verify

# 3. Sub-ledger reconciliation (AR/AP/Employee vs GL control accounts)
php artisan subledger:reconcile

# 4. Running balance reconciliation (stored vs computed)
php artisan reconcile:running-balance --top=20

# 5. Stock ledger integrity (same as post-deploy)
php artisan stock:replay-verify

# 6. Manual sample review (10 random journal entries for accountant visual check)
php artisan journal:manual-verify --count=10

# 7. Manual stock sample review (10 random products for avg-cost visual check)
php artisan stock:manual-verify --count=10
```

### 9.3 Partitioning ops suite (weekly)

> Scheduled automatically via `routes/console.php`. Can be run manually after PG config
> changes.

```bash
# 1. Verify partition-wise joins are working (Mondays 05:00)
php artisan partition:verify-join

# 2. Measure partition query performance vs targets (Mondays 05:30)
php artisan partition:measure-perf
```

### 9.4 Partitioning archival suite (quarterly)

> Scheduled automatically. Can be run manually with `--dry-run` to preview.

```bash
# 1. Export archived partitions to Parquet (quarterly, 04:30 on Jan/Apr/Jul/Oct 1st)
php artisan partition:export-parquet --dry-run   # preview first
php artisan partition:export-parquet             # actual run
```

---

## 10. Known edge cases

- **E-1 — `journal:replay-verify --fix-orphans` deletes rows.** The `--fix-orphans` flag
  silently deletes orphan `journal_lines` rows. This is DESTRUCTIVE and should only be
  used after investigating why orphans exist (usually a partial rollback bug). Without the
  flag, the command is read-only.
- **E-2 — `stock:replay-verify` is slow on large DBs.** It replays EVERY stock transaction
  from the beginning. On a 5-year DB with 1M transactions, this can take 30+ minutes. Use
  `--limit=10000` + `--from-id=N` to chunk the verification.
- **E-3 — `reconcile:running-balance --fix` overwrites stored balances.** The `--fix` flag
  overwrites the stored `running_balance` column with the computed value. This masks the
  underlying bug (why did they drift?). Use only after root-causing the drift.
- **E-4 — `api:token` re-activates disabled users.** Issuing a token for a user with
  `is_active = false` sets `is_active = true`. This is intentional (token issuance implies
  the user should be active) but surprising if you're issuing tokens for a bulk export of
  disabled users. See `../api/api-overview.md` §12 E-5.
- **E-5 — `partition:export-parquet` DROPS the source table.** By default, after a
  successful export, the archived partition table is DROPPED. Use `--keep` to retain it.
  If the Parquet file is corrupted, the data is lost (mitigation: keep the Parquet file in
  two locations).
- **E-6 — `migrate:master-data` reads from the legacy MySQL.** If the MySQL archive
  container isn't running (no `--profile archive`), the command fails with "connection
  refused". This is expected — the migration is one-time and requires the archive.
- **E-7 — `listen-notify:worker` caches the DB connection.** If the PostgreSQL restarts,
  the worker doesn't reconnect automatically. Supervisor's `autorestart=true` handles this
  (the worker exits, supervisor restarts it).
- **E-8 — `sales:cancel-stale-drafts` is gated by config.** If
  `config('sales.stale_draft_auto_cancel.enabled')` is false, the command runs but does
  nothing. Use `--dry-run` to verify it would cancel the expected drafts.
- **E-9 — `db:make-empty` excludes `migrations` + `system_policies`.** This is intentional
  (so the DB can be re-seeded without re-running migrations). But it means `migrate:status`
  still shows all migrations as ran after `db:make-empty` — confusing if you expect a
  clean slate.
- **E-10 — `rcerp:setup` creates the admin user with `password123`.** The default password
  is insecure. On VPS provisioning, change it immediately via `php artisan tinker` (see
  `vps-bdix-deployment.md` §8.9).

---

## 11. Future improvements

- **F-1 — Add a `php artisan verify:all` umbrella command.** Would run the §9.1 + §9.2
  suites in sequence + print a summary. Currently each command is run separately.
- **F-2 — Add `--json` output to verification commands.** Currently they print human-
  readable text. JSON output would enable machine-readable CI integration.
- **F-3 — Add exit codes to verification commands.** Currently some return 0 even on
  drift (they print warnings). Strict exit codes (0 = clean, 1 = drift) would enable CI
  gating.
- **F-4 — Add a `php artisan partition:consolidate` command.** Currently the quarterly
  partition consolidation is a pg_cron job calling a PL/pgSQL function. A Laravel command
  wrapper would enable `--dry-run` + logging to `user_audit_log`.
- **F-5 — Add a `php artisan archive:verify` health-check command.** Would check the
  legacy MySQL archive is reachable + the `archive_reader` user has only SELECT grants.
  Currently this is a manual procedure (see `../archive/legacy-read-only.md` §11.4).
- **F-6 — Add a `php artisan archive:refresh-cache` command.** Would clear the
  `ArchiveService` Redis cache. Currently cache TTL is 3600s (1 hour); there's no manual
  flush.
- **F-7 — Add a `php artisan env:audit` command.** Would run the §10 hygiene audit from
  `environment.md`. Currently it's a bash script.
- **F-8 — Add a `php artisan deploy` command.** Would wrap the §9 routine deploy sequence
  from `vps-bdix-deployment.md` into a single command with rollback-on-failure.
- **F-9 — Add tests for the commands themselves.** Currently ZERO commands have tests.
  The verification commands especially should have tests that assert they catch known
  drift patterns.
- **F-10 — Document the command-output format.** Currently each command prints whatever
  the developer wrote. A consistent format (header, sections, exit summary) would make
  the output parseable + consistent.

---

## 12. Verification commands

> Meta-verification: commands to verify the commands themselves work.

```bash
# 1. List all custom commands
php artisan list | grep -v "^laravel"

# 2. Confirm a command exists
php artisan list | grep 'journal:replay-verify'

# 3. Get help for a command
php artisan help journal:replay-verify

# 4. Run a command in dry-run mode (if supported)
php artisan partition:export-parquet --dry-run
php artisan migrate:master-data --dry-run

# 5. Time a command (for perf monitoring)
time php artisan stock:replay-verify --limit=1000

# 6. Run a command + capture output
php artisan journal:replay-verify | tee /tmp/journal-verify-$(date +%Y%m%d).log

# 7. Run a command as a different user (for RBAC testing)
sudo -u rcerp php artisan sales:pen-test

# 8. Run a command in the Docker container
docker compose exec rcerp_app php artisan journal:replay-verify

# 9. Confirm the schedule is registered
php artisan schedule:list

# 10. Run the schedule manually (for testing)
php artisan schedule:test
```

---

## 13. Cross-reference summary

| Topic | Where in this file | Cross-ref to other AI_CONTEXT files |
|---|---|---|
| Verification commands | §7.1 | `../accounting/journal-posting-rules.md`, `../inventory/stock-ledger.md` |
| Partitioning ops commands | §7.2 | `../database/partitioning.md`, `../architecture/partitioning-archival.md` |
| Migration commands | §7.3 | `../database/etl-legacy-migration.md`, `../archive/legacy-read-only.md` §9 |
| Setup command | §7.4 | `docker-setup.md` §7.5, `vps-bdix-deployment.md` §8.7 |
| Operational commands | §7.5 | `../architecture/realtime-events.md` (listen-notify), `../sales/sales-invoice.md` (cancel-stale) |
| Scheduled commands | §9.3, §9.4 | `cron-scheduled-jobs.md` §7 |
| Post-deploy verification suite | §9.1 | `go-live-checklist.md` §9.1 |
| Period-close verification suite | §9.2 | `go-live-checklist.md` §9.2 |
| Standard Laravel commands | §8 | (Laravel manual) |
| `api:token` re-activates users | §10 E-4 | `../api/api-overview.md` §12 E-5 |
| `partition:export-parquet` drops tables | §10 E-5 | `../database/partitioning.md` |

---

*End of `artisan-commands.md`. For the schedule that runs these commands automatically,
see `cron-scheduled-jobs.md`. For the verification suites that gate deploys + period
close, see `go-live-checklist.md` §9.*
