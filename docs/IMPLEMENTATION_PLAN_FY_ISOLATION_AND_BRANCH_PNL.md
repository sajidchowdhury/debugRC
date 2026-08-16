# RC ERP — Implementation Plan
## Fiscal Year Isolation + Auto-Backup (Q1) and Inter-Branch Pricing & P&L (Q2)

| Field | Value |
|---|---|
| **Project** | RC ERP (repo: `sajidchowdhury/debugRC`) |
| **Stack** | Laravel 12 · PHP 8.2+ · PostgreSQL 16 (pg_cron, pg_partman, pg_trgm) · Redis 7 · Blade + Bootstrap 5 |
| **Audience** | Project Manager (status tracking) + Dev team (technical execution) |
| **Session length** | 1 working day (~8 focused hours) |
| **Total sessions** | 9 (1 pre-flight + 4 for Q1 + 4 for Q2) |
| **Estimated duration** | ~9 working days (~2 calendar weeks with buffer) |
| **Depth** | Detailed — files to touch, schema changes, function signatures, acceptance tests. No full code blocks. |
| **Source of truth** | `docs/worklog.md` Task IDs 3-a and 4-a (codebase audit, 16 Oct 2025) |

---

## 0. Executive Summary

This plan delivers two client requirements that the current RC ERP codebase does **not** yet satisfy:

**Q1 — Fiscal Year Isolation + Auto-Backup.** The client wants the system to operate only on the *running* fiscal year. Once a fiscal year is closed, **no user — not even the super admin — may view that year's data through the application UI**. Previous-year dues, stock, and balances must still carry forward correctly so the new fiscal year opens with accurate opening positions. On year close, the system must automatically produce a database backup file saved on the PC, so the only way to view closed-year data is to restore that backup file separately without touching live data.

**Q2 — Min/Max/Default Pricing + Inter-Branch Profit & Loss.** Each product carries a `min / max / default` price range. When Branch B raises a demand on Branch A, A dispatches at the **min** price — that becomes the locked inter-branch cost B owes A. B then resells at min, default, max, or (with admin approval) **below min**. The system must classify every sale line by price tier, compute per-demand profit/loss (profit if `rate > cost`, breakeven if `rate = cost` = min, loss if `rate < cost`), and give A a consolidated view of B's due, sales mix, and performance.

### Why these two are sequenced Q1 → Q2 (not parallel)

Q2's profit/loss report must respect Q1's fiscal-year isolation. If we build the Branch P&L report first, then add FY scoping later, we will have to retrofit the report's queries with `fiscal_year_id` filters and re-test. Doing Q1 first means Q2 is built natively on the scoped schema. There is also a human-resource argument: Q1's partition-detach work changes how every operational table is queried, so any Q2 work that touches those tables must happen after Q1 stabilises.

### What is already in the codebase (do NOT rebuild)

The audit found substantial existing infrastructure. Re-implementing any of the following is a waste of effort and a regression risk:

- **Fiscal year lifecycle**: `fiscal_years` / `fiscal_periods` / `period_close_log` tables with `draft → active → closed → locked` and `open → closed → locked` states. `FiscalYearService` (475 lines) and `FiscalYearController` (258 lines) already exist.
- **Year-end close**: `AccountingPeriodService::yearEndClose()` zeroes Income/Expense ledgers and posts a balanced JE to Retained Earnings with `skip_period_check=true`. Five pre-close gates + three year-end-checklist gates are in place.
- **Partitioning**: ~30 tables partitioned monthly with `archive_partition()` / `restore_partition()` PL/pgSQL functions, Parquet export via DuckDB, `partition_exports` SHA-256 manifest. See `scripts/verify_partitioning.sql`.
- **RBAC**: Three layers — `role:` middleware (`config/roles.php`, 10 roles), `menu.permission:` middleware + `user_menu_permissions` table, 21 Eloquent policies registered in `AppServiceProvider`. Super-admin bypass in `Gate::before()`. PostgreSQL Row-Level Security using `current_setting('app.branch_id')`.
- **Product price range**: `product_price_history` table with `min_rate`, `max_rate`, `default_rate`, `effective_from`, `effective_to`. DDL at `database/sql/01_auth_and_master.sql:184-195`. Model `ProductPriceHistory.php`.
- **Branch demand lifecycle**: `BranchDemandController.php` (773 lines), `BranchDemandService`, `BranchIntercompanyService`, `BranchDemandRepricingService`, `BranchDemandAuditService`. Tables `branch_demands`, `branch_demand_items` (with locked `cost_rate`, `price_min`, `price_max`, `price_default`), `branch_demand_repricing`, `branch_demand_audit_log`.
- **Inter-branch due tracking**: `branch_ledger` table with `debit`, `credit`, `running_balance`. GL accounts `interbranch_receivable` (L-0105) and `interbranch_payable` (L-0303). FIFO settlement auto-triggered by bank customer payments + inter-branch money transfers.
- **Cart-time min/max enforcement**: `SalesCartService::addItem()` throws `RuntimeException` if rate is outside `[min, max]`. Cart stores min/max/default in `sales_draft_carts.items_json`.

### What is missing (this plan delivers these)

| Gap | Phase | Session |
|---|---|---|
| Operational tables have no `fiscal_year_id` column | Q1 | S1 |
| No hard read-block on closed/locked FY data | Q1 | S2 |
| No in-app `db:backup` command; no year-end backup gate | Q1 | S3 |
| No partition DETACH on close; `opening_balance` columns not refreshed | Q1 | S4 |
| `sales_invoice_items` does not persist price classification per line | Q2 | S5 |
| No admin-override workflow for below-min sales | Q2 | S6 |
| No FK from `sales_invoice_items` to `branch_demand_items` (FIFO attribution) | Q2 | S7 |
| No Branch P&L report | Q2 | S8 |

---

## Pre-Flight — Session 0: Audit & Branch Setup

| | |
|---|---|
| **Phase** | Pre-Flight |
| **Duration** | 1 working day |
| **Dependencies** | None |
| **Owner** | Tech lead + PM |
| **Goal** | Confirm audit findings still hold on the live branch, create a clean feature branch, snapshot the dev database, and lock the scope for Sessions 1–8. |

### Files to Touch
- `docs/worklog.md` — append Session 0 entry
- New git branch `feature/fy-isolation-and-branch-pnl` off the current `main`
- `docs/IMPLEMENTATION_PLAN_AUDIT_CONFIRMATION.md` — new doc recording the re-confirmation

### Tasks (in order)

1. **Pull latest `main`** and confirm the working tree is clean. Note the current commit SHA in the worklog — this is the rollback baseline.
2. **Re-verify the eight gaps** listed in §0 against the current code. For each gap, run the specific Grep/Read check listed in worklog Task IDs 3-a and 4-a. If any gap is now closed (e.g., someone added `fiscal_year_id` independently), update this plan before starting Session 1.
3. **Create the feature branch** `feature/fy-isolation-and-branch-pnl`. All eight sessions commit to this branch. Merge to `main` only after Session 8 signoff.
4. **Snapshot the dev database** using `pg_dump -Fc` to a file outside the repo (e.g., `~/rcerp_snapshots/pre_implementation_<date>.dump`). This is your safety net for the schema migrations in Sessions 1, 4, 5, 7.
5. **Confirm `pg_partman` and `pg_cron` extensions are active** by running `\dx` and checking for both. Session 4 depends on `archive_partition()` / `restore_partition()` being available.
6. **Identify the test fiscal year** in the dev DB: pick one `fiscal_years` row with `status='active'` and one with `status='closed'` (or create a synthetic closed one if none exists). Record their IDs — Sessions 2 and 4 will reference them.
7. **Identify two test branches** (A and B) with at least one existing `branch_demands` row between them. Record their IDs — Sessions 5–8 will reference them.
8. **PM checkpoint**: confirm with the client that the hard read-block requirement (super admin also cannot see closed FY data through the UI) is non-negotiable. If the client later wants a "view-only historical report" exception, that scope change must be raised now, not mid-implementation.

### Acceptance Tests
- [ ] Feature branch `feature/fy-isolation-and-branch-pnl` exists and is checked out.
- [ ] `docs/IMPLEMENTATION_PLAN_AUDIT_CONFIRMATION.md` exists and lists all 8 gaps as either "confirmed open" or "now closed (plan updated)".
- [ ] Dev DB snapshot file exists at the documented path and is at least 50% the size of the live DB (sanity check that the dump is not empty/truncated).
- [ ] Two test fiscal-year IDs and two test branch IDs are recorded in the worklog.
- [ ] Client has confirmed (in writing — email or chat) that the hard read-block requirement includes super admin.

### PM Checkpoint (end of day)
Report to the client: "Pre-flight audit complete. Branch created, dev DB snapshotted. All 8 gaps confirmed. Ready to start Session 1 (schema migration) tomorrow. No production impact yet."

---

# Phase 1 — Fiscal Year Isolation + Auto-Backup (Q1)

**Phase goal**: After Phase 1 completes, the system enforces that all operational data (sales, purchases, journals, branch demands, stock movements) is tagged with a `fiscal_year_id`, all application queries are scoped to the running FY by default, closed FY data is invisible to every user through the UI, and a database backup file is automatically produced on year-end close.

**Phase duration**: 4 working days (Sessions 1–4).

---

## Session 1 — Schema: Add `fiscal_year_id` to Operational Tables

| | |
|---|---|
| **Phase** | 1 (Q1) |
| **Duration** | 1 working day |
| **Dependencies** | Session 0 complete |
| **Owner** | Backend dev |
| **Goal** | Add a non-null `fiscal_year_id` foreign-key column to every operational table that holds fiscal-year-sensitive data, default it to the currently-active fiscal year, and backfill all existing rows. |

### Files to Touch
- New migration: `database/migrations/2026_xx_xx_000001_add_fiscal_year_id_to_operational_tables.php`
- New migration: `database/migrations/2026_xx_xx_000002_backfill_fiscal_year_id.php`
- `app/Models/Concerns/BelongsToFiscalYear.php` — new trait (declared but **not yet applied** to models in this session; that happens in Session 2)
- `config/fiscal.php` — new config file holding the canonical list of operational tables that need the column

### Schema Changes

Add `fiscal_year_id BIGINT NOT NULL` (default = currently-active FY id) plus a B-tree index on `(fiscal_year_id)` to the following tables:

**Sales & receivables**
- `sales_invoices`
- `sales_invoice_items`
- `sales_challans`
- `sales_challan_items`
- `sales_returns`
- `sales_return_items`
- `customer_payments`
- `customer_ledger`

**Purchases & payables**
- `purchases`
- `purchase_items`
- `purchase_returns`
- `purchase_return_items`
- `supplier_payments`
- `supplier_ledger`

**Inventory**
- `stock_movements`
- `stock_adjustments`
- `stock_take_sessions`
- `warehouse_transfers`
- `warehouse_transfer_items`
- `damages`

**Inter-branch**
- `branch_demands`
- `branch_demand_items`
- `branch_demand_repricing`
- `branch_ledger`

**Accounting**
- `journal_entries`
- `journal_entry_lines`
- `manual_journals`
- `other_incomes`
- `other_expenses`
- `money_transfers`
- `employee_payments`

**Foreign-key constraint** on each: `FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ON DELETE RESTRICT`.

**Backfill strategy**: For each table, run a single `UPDATE ... SET fiscal_year_id = (SELECT id FROM fiscal_years WHERE <table>.<date_column> BETWEEN fiscal_years.start_date AND fiscal_years.end_date)`. Use the appropriate date column per table (`invoice_date`, `purchase_date`, `entry_date`, `movement_date`, `demand_date`, etc.). For tables with no single date column (e.g., `branch_ledger` which uses `created_at`), use `created_at`.

### Function/Method Signatures (declared, not implemented)
- `App\Models\Concerns\BelongsToFiscalYear::booted(): void` — registers the global scope (body implemented in Session 2)
- `App\Models\Concerns\BelongsToFiscalYear::fiscalYear(): BelongsTo` — relation to `FiscalYear`
- `App\Support\FiscalYearResolver::activeId(): int` — returns the cached active FY id from `cache` (Redis)

### Tasks (in order)

1. **Write the config file** `config/fiscal.php` listing every operational table name and its date column. This is the single source of truth for the migration and for future audits.
2. **Generate the first migration** that adds the column + index + FK to every table in the config. Use `Schema::table()` per table in a loop, not one big raw SQL string — this keeps it reversible by Laravel's `down()` method.
3. **Generate the second migration** that backfills. Run it on dev and verify row counts per FY.
4. **Verify**: run `SELECT fiscal_year_id, COUNT(*) FROM sales_invoices GROUP BY fiscal_year_id;` and confirm the distribution matches the dev data's actual date spread.
5. **Do NOT yet apply the trait to models** — that is Session 2. The column exists but is dormant this session, so production behaviour is unchanged.
6. **Commit**: `git commit -m "feat(fy): add fiscal_year_id to operational tables (S1)"`.

### Acceptance Tests
- [ ] `php artisan migrate` succeeds on dev with no errors.
- [ ] `php artisan migrate:rollback` succeeds and removes the column from every table.
- [ ] `SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id IS NULL` returns 0.
- [ ] `SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id IS NULL` returns 0.
- [ ] For every table in `config/fiscal.php`, `\d <table>` in psql shows the `fiscal_year_id` column, the index, and the FK to `fiscal_years`.
- [ ] Application behaves identically to pre-migration (no query changes yet — the column is dormant). Run the existing test suite (`php artisan test`) and confirm no new failures.

### PM Checkpoint
Report: "Session 1 complete. `fiscal_year_id` column added to 28 operational tables, backfilled with zero nulls. No application behaviour change yet — column is dormant. Ready for Session 2 (global scope + policy) tomorrow."

---

## Session 2 — Read-Block: Global Scope + `FiscalYearPolicy`

| | |
|---|---|
| **Phase** | 1 (Q1) |
| **Duration** | 1 working day |
| **Dependencies** | Session 1 complete |
| **Owner** | Backend dev |
| **Goal** | Make every operational model automatically filter by the running fiscal year, and add a `FiscalYearPolicy` that hard-denies reads on closed/locked fiscal years — including for the super admin. |

### Files to Touch
- `app/Models/Concerns/BelongsToFiscalYear.php` — implement the trait body (declared in S1)
- `app/Support/FiscalYearResolver.php` — implement `activeId()` and `currentRequestFyOverride()` (returns null normally; returns an explicit FY id only when an authorised "view historical" path is invoked — but per client requirement, this path is denied to everyone through the UI)
- Apply the `BelongsToFiscalYear` trait to every operational model listed in `config/fiscal.php`. This is a one-line `use BelongsToFiscalYear;` addition per model file.
- New: `app/Policies/FiscalYearPolicy.php`
- `app/Providers/AppServiceProvider.php` — register `FiscalYearPolicy` via `Gate::policy(FiscalYear::class, FiscalYearPolicy::class)`
- `app/Providers/AuthServiceProvider.php` (or `AppServiceProvider` if gates are registered there) — amend `Gate::before()` so the super-admin bypass does **not** apply to the `viewHistoricalData` ability
- `app/Http/Middleware/EnsureActiveFiscalYear.php` — new middleware that resolves and caches the active FY id at request start

### Schema Changes
None.

### Function/Method Signatures

**`BelongsToFiscalYear` trait:**
- `booted(): void` — registers `static::addGlobalScope('current_fy', fn(Builder $q) => $q->where('fiscal_year_id', FiscalYearResolver::activeId()))`
- `fiscalYear(): BelongsTo` — `return $this->belongsTo(FiscalYear::class);`
- `scopeWithoutFiscalYearScope(Builder $q): Builder` — alias for `withoutGlobalScope('current_fy')` — to be used ONLY inside authorised admin/audit code paths, and every call site must be reviewed against `FiscalYearPolicy::viewHistoricalData()`

**`FiscalYearResolver`:**
- `activeId(): int` — returns `cache()->remember('active_fiscal_year_id', 300, fn() => FiscalYear::where('status','active')->value('id'))`
- `clearCache(): void` — called by `FiscalYearService::activate()` and `yearEndClose()`

**`FiscalYearPolicy`:**
- `viewAny(User $user): bool` — `return false;` (no list-of-FYs UI for anyone)
- `view(User $user, FiscalYear $fy): bool` — `return $fy->status === 'active';`
- `viewHistoricalData(User $user, FiscalYear $fy): bool` — `return false;` (HARD DENY for everyone, including super admin)
- `create(User $user): bool` — `return $user->hasRole('superadmin','admin');`
- `activate(User $user, FiscalYear $fy): bool` — `return $user->hasRole('superadmin','admin');`
- `close(User $user, FiscalYear $fy): bool` — `return $user->hasRole('superadmin','admin','accountant');`

**`Gate::before()` amendment:**
- Existing: `if ($user->isSuperadmin()) return true;`
- New: `if ($user->isSuperadmin()) { if (in_array($ability, ['viewHistoricalData'], true)) return false; return true; }`

### Tasks (in order)

1. **Implement `FiscalYearResolver`** with Redis caching. Wire it into `FiscalYearService::activate()` and `yearEndClose()` so the cache is invalidated on lifecycle transitions.
2. **Implement the `BelongsToFiscalYear` trait body**.
3. **Apply the trait to every operational model.** Use `grep -rln "extends Model" app/Models/` to enumerate candidates, then cross-check against `config/fiscal.php`. Skip models for tables NOT in the config (e.g., `Product`, `Customer`, `Supplier`, `User`, `Branch`, `Ledger` — these are master data, not fiscal-year-scoped).
4. **Implement `FiscalYearPolicy`** and register it in `AppServiceProvider`.
5. **Amend `Gate::before()`** to exclude `viewHistoricalData` from the super-admin bypass. This is the single most important line of code in the entire Q1 phase — without it, super admin still sees everything.
6. **Add the `EnsureActiveFiscalYear` middleware** to the `web` middleware group in `bootstrap/app.php` so every request resolves and caches the active FY id up-front.
7. **Run the existing test suite.** Expect some breakage in tests that create rows without setting a fiscal year — fix those tests by explicitly setting `fiscal_year_id` in their factories.
8. **Manual smoke test**: log in as super admin, navigate to Sales → Invoices, confirm only the current FY's invoices appear. Try the URL `/admin/sales-invoices?from=2024-01-01&to=2024-12-31` — confirm it returns zero rows even though rows exist in the DB.
9. **Commit**: `git commit -m "feat(fy): global scope + FiscalYearPolicy hard read-block (S2)"`.

### Acceptance Tests
- [ ] `php artisan test` passes (with updated factories).
- [ ] Logged in as super admin, the Sales → Invoices list shows ONLY rows where `fiscal_year_id = <active FY id>`. Verified by counting rows in the UI vs. `SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id = <active>`.
- [ ] Logged in as super admin, hitting `/admin/sales-invoices?from=<closed-fy-start>&to=<closed-fy-end>` returns zero rows (not an error, just an empty list).
- [ ] `Gate::denies('viewHistoricalData', $closedFy)` returns `true` (i.e., denied) for a super admin user. Write a one-line tinker test for this.
- [ ] `SalesInvoice::withoutGlobalScope('current_fy')->count()` returns the full count (proves the escape hatch works for authorised admin paths).
- [ ] Existing reconciliation reports (e.g., `/admin/branch-demands/reconcile`) still work because they query the running FY only — verify manually.
- [ ] The `fiscal_years` management UI (`/admin/fiscal-years`) still allows super admin to create/activate/close FYs — verify the create/activate/close buttons are visible, but there is NO "view historical data" button anywhere in the UI.

### PM Checkpoint
Report: "Session 2 complete. All operational models now scoped to running FY. Super admin confirmed unable to view closed-FY data via URL params. The `viewHistoricalData` gate hard-denies for everyone. Ready for Session 3 (auto-backup) tomorrow. **Note**: from this point forward, the dev team cannot use the app UI to inspect old FY data — they must use psql directly. Flag this to the team."

---

## Session 3 — Auto-Backup Command + Year-End Checklist Gate

| | |
|---|---|
| **Phase** | 1 (Q1) |
| **Duration** | 1 working day |
| **Dependencies** | Session 2 complete |
| **Owner** | Backend dev + DevOps |
| **Goal** | Build an in-app `php artisan db:backup-year-end` command that produces a `pg_dump -Fc` file at a configurable path on the PC, with SHA-256 verification, and wire it into `yearEndClose()` as a hard gate — year-end close ABORTS if no fresh backup file exists. |

### Files to Touch
- New: `app/Console/Commands/BackupDatabaseYearEnd.php`
- New: `app/Services/DatabaseBackupService.php`
- New: `config/backup.php` — paths, retention, pg_dump binary location
- Modify: `app/Services/AccountingPeriodService.php` — add backup gate to `yearEndClose()`'s pre-flight checks
- Modify: `app/Http/Controllers/Admin/YearEndChecklistController.php` (or equivalent) — add a "Backup file on disk" item to the checklist
- Modify: the year-end checklist blade view (likely `resources/views/admin/year-end-checklist.blade.php` or similar — locate via `grep -rl "year-end-checklist" resources/views/`)
- New migration: `database/migrations/2026_xx_xx_000003_create_database_backups_table.php`

### Schema Changes

New table `database_backups`:
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `fiscal_year_id` | BIGINT FK → `fiscal_years.id` | |
| `file_path` | TEXT | Absolute path on disk where the dump lives |
| `file_size_bytes` | BIGINT | |
| `sha256_hash` | CHAR(64) | |
| `pg_dump_version` | TEXT | e.g., `pg_dump (PostgreSQL) 16.4` |
| `created_at` | TIMESTAMP | |
| `created_by_user_id` | BIGINT FK → `users.id` | |
| `status` | TEXT CHECK in (`'verified'`, `'failed'`, `'superseded'`) | |

### Function/Method Signatures

**`DatabaseBackupService`:**
- `backupFiscalYear(int $fiscalYearId, ?int $userId = null): array` — runs `pg_dump -Fc`, computes SHA-256, writes a row to `database_backups`, returns `['file_path' => ..., 'sha256' => ..., 'size' => ...]`
- `verifyBackup(int $backupId): bool` — re-reads the file, recomputes SHA-256, compares to stored value
- `latestBackupForFiscalYear(int $fiscalYearId): ?DatabaseBackup` — returns the most recent `verified` backup for that FY
- `isBackupFresh(int $fiscalYearId, int $maxAgeHours = 24): bool` — used by the year-end close gate

**`BackupDatabaseYearEnd` command:**
- Signature: `db:backup-year-end {--fiscal-year= : Fiscal year ID (defaults to active)}`
- Output: writes a row to `database_backups` with status `verified` or `failed`, prints the file path and SHA-256 to the console

**`AccountingPeriodService::yearEndClose()` amendment:**
- Add a 6th pre-flight check: `if (!$this->databaseBackupService->isBackupFresh($fiscalYearId)) throw new YearEndCloseException('No fresh backup on file — run php artisan db:backup-year-end --fiscal-year=X first')`

### Tasks (in order)

1. **Add `config/backup.php`** with keys: `pg_dump_binary` (default `/usr/bin/pg_dump`), `backup_path` (default `storage_path('app/backups')` for dev; on production this is overridden to a path on the PC — typically `C:\rcerp\backups\` on Windows or `/var/rcerp/backups/` on Linux, configurable via `.env` `BACKUP_PATH`).
2. **Write the `database_backups` migration** and run it.
3. **Implement `DatabaseBackupService`**. Use `Symfony\Component\Process\Process` to invoke `pg_dump -Fc` with proper env vars (`PGPASSWORD` etc.). Capture stdout/stderr. On non-zero exit, throw with the stderr contents.
4. **Implement the `BackupDatabaseYearEnd` command**. On success, print a summary line. On failure, exit code 1 and print the error.
5. **Wire the gate into `yearEndClose()`**. Add the check BEFORE the existing 5 pre-flight checks so a missing backup fails fast without doing the heavier reconciliation work.
6. **Add the checklist UI item**. The year-end checklist blade should show "Database backup file (≤ 24h old, SHA-256 verified)" with a green check or red cross. If red, the close button is disabled.
7. **Test the full flow**: create a synthetic closed-FY scenario in dev, attempt `yearEndClose()` without a backup (should fail), run `db:backup-year-end`, re-attempt `yearEndClose()` (should succeed).
8. **Commit**: `git commit -m "feat(fy): auto-backup command + year-end close gate (S3)"`.

### Acceptance Tests
- [ ] `php artisan db:backup-year-end --fiscal-year=<test-fy-id>` produces a `.dump` file at `storage_path('app/backups')` (dev) and a row in `database_backups` with `status='verified'`.
- [ ] The file size is non-zero and > 1KB.
- [ ] `pg_restore -l <file>` lists the expected tables (sanity check that the dump is valid).
- [ ] `yearEndClose()` called WITHOUT a fresh backup throws `YearEndCloseException` with the expected message.
- [ ] `yearEndClose()` called WITH a fresh backup proceeds past the gate (it may still fail on other pre-flight checks — that's fine; the test is only that the backup gate passes).
- [ ] Year-end checklist UI shows the backup item with correct status (green when fresh, red when stale/missing).
- [ ] Running `db:backup-year-end` twice produces two distinct files with distinct SHA-256 hashes (proves no caching/clobbering).
- [ ] `DatabaseBackupService::verifyBackup($id)` returns `true` when the file is intact and `false` after the file is manually deleted or corrupted (test by truncating the file).

### PM Checkpoint
Report: "Session 3 complete. Auto-backup command built and gated into year-end close. Verified the gate blocks close when no backup exists. The backup file lands at `BACKUP_PATH` (configurable per environment — client's PC path on production). Ready for Session 4 (partition detach + carry-forward refresh + UAT) tomorrow."

---

## Session 4 — Partition DETACH on Close + Carry-Forward Refresh + Phase-1 UAT

| | |
|---|---|
| **Phase** | 1 (Q1) |
| **Duration** | 1 working day |
| **Dependencies** | Sessions 1, 2, 3 complete |
| **Owner** | Backend dev + DBA + QA |
| **Goal** | On year-end close success, automatically DETACH the closed fiscal year's monthly partitions from the parent operational tables and move them to the `archive` schema — making them physically invisible to normal queries. Also refresh the static `opening_balance` columns on `ledgers`, `customers`, `suppliers` so the new FY opens with correct balances. Then run a full Phase-1 UAT. |

### Files to Touch
- New: `app/Services/FiscalYearPartitionService.php`
- New: `app/Console/Commands/DetachFiscalYearPartitions.php`
- Modify: `app/Services/AccountingPeriodService.php` — call `FiscalYearPartitionService::detachAndArchive($fiscalYearId)` at the end of `yearEndClose()` (after the closing JE is posted and the backup is verified)
- Modify: `app/Services/AccountingPeriodService::yearEndClose()` — after the closing JE, refresh `opening_balance` columns
- Modify: `app/Services/FiscalYearService::activate()` — when activating a new FY, also refresh `ledgers.opening_balance` for balance-sheet ledgers from the closing balance of the prior FY

### Schema Changes
- No new tables. The `archive` schema and `archive_partition()` / `restore_partition()` PL/pgSQL functions already exist (verified in audit Task 3-a §6).
- New config: `config/fiscal.php` — add `'partitioned_tables' => [...]` listing the partitioned operational tables whose closed-FY monthly partitions should be detached.

### Function/Method Signatures

**`FiscalYearPartitionService`:**
- `detachAndArchive(int $fiscalYearId): array` — for each table in `config('fiscal.partitioned_tables')`, for each month in the FY's date range, calls `SELECT archive_partition('<table>_<YYYY>_<MM>')`. Returns `['detached' => [...], 'skipped' => [...]]`.
- `restoreForViewing(int $fiscalYearId): void` — calls `restore_partition()` for each archived partition. This is the "upload the database / restore to view" path. **This method must be invokable ONLY via artisan command, NEVER via web UI.** Document this in the method's PHPDoc with a `@internal` tag.
- `isFiscalYearArchived(int $fiscalYearId): bool` — checks whether all expected partitions are in the `archive` schema

**`DetachFiscalYearPartitions` command:**
- Signature: `fy:detach-archived {--fiscal-year= : Fiscal year ID}`
- `@internal` — only for manual ops, not called from the UI

**`AccountingPeriodService::yearEndClose()` final amendment (S4):**
- After the closing JE posts successfully: call `$this->refreshOpeningBalances($fiscalYearId)` which updates `ledgers.opening_balance`, `customers.opening_balance`, `suppliers.opening_balance` to equal the closing balance as of the FY end date.
- After opening balances refresh: call `$this->partitionService->detachAndArchive($fiscalYearId)`.
- If detach fails partway, log the partial state to `period_close_log` and throw — do NOT silently continue. The next invocation must be idempotent (skip already-archived partitions).

### Tasks (in order)

1. **Implement `FiscalYearPartitionService`**. Wrap `archive_partition()` calls in a DB transaction where possible (note: DDL is transactional in PostgreSQL, so this is safe). Log every detach to `period_close_log` with the partition name and outcome.
2. **Implement the `DetachFiscalYearPartitions` artisan command** as a manual ops tool. Mark `@internal` in the docblock.
3. **Wire into `yearEndClose()`**. Order of operations at close: (a) 6 pre-flight gates (including backup from S3), (b) post closing JE, (c) refresh opening balances, (d) detach + archive partitions, (e) mark FY as `closed` then `locked`, (f) auto-activate the next FY.
4. **Implement `refreshOpeningBalances()`** — for each ledger of type `Asset`, `Liability` (balance-sheet accounts — NOT income/expense, those are zeroed by the closing JE), compute `SUM(debit) - SUM(credit)` as of the FY end date and write it to `ledgers.opening_balance`. Same for `customers.opening_balance` (= `CustomerLedger::getBalance($customerId, $fyEndDate)`) and `suppliers.opening_balance`.
5. **Auto-activate next FY**: in `FiscalYearService::activate()`, after marking the new FY as `active`, also invalidate the `FiscalYearResolver` cache (S2) so the next request resolves to the new FY.
6. **Full Phase-1 UAT** (see Acceptance Tests below). This is the gate for merging Phase 1 to `main`.
7. **Commit**: `git commit -m "feat(fy): partition detach + opening balance refresh + UAT (S4)"`.

### Acceptance Tests (Phase-1 UAT)

**Backup & close flow:**
- [ ] Running `php artisan db:backup-year-end --fiscal-year=<test-closing-fy>` creates a backup file and a `database_backups` row.
- [ ] Running `yearEndClose()` on a test FY with a fresh backup succeeds end-to-end: posts closing JE, refreshes opening balances, detaches partitions, marks FY as `closed` then `locked`, activates the next FY.
- [ ] The `period_close_log` table has entries for every detached partition, plus the closing JE id, plus the backup file SHA-256.

**Hard read-block (the client's main concern):**
- [ ] Logged in as super admin, navigate to every operational UI (Sales, Purchases, Inventory, Branch Demands, Reports). Confirm zero rows from the closed FY appear anywhere.
- [ ] Try direct URL params (`?from=...&to=...`) on every list endpoint — all return zero rows for the closed FY.
- [ ] In psql (NOT through the app), `SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id = <closed-fy-id>` returns the expected non-zero count — proving the data still exists in the DB but is invisible to the app. **This is the key test of the client's requirement.**
- [ ] In psql, `\dt archive.*` shows the detached partitions (e.g., `archive.sales_invoices_2025_07`).
- [ ] `SalesInvoice::withoutGlobalScope('current_fy')->where('fiscal_year_id', $closedFyId)->count()` returns 0 (because the partition is detached — even the escape hatch cannot see them without `restore_partition()`). **This is the strongest guarantee.**

**Carry-forward correctness:**
- [ ] After close, `ledgers.opening_balance` for the Retained Earnings ledger equals the net P&L of the closed FY.
- [ ] `customers.opening_balance` for a test customer equals the customer's outstanding due as of the FY end date (manually verified against `CustomerLedger::getBalance()`).
- [ ] `WarehouseStock` for a test product in a test branch shows the same qty after close as before (stock is perpetual, not reset).
- [ ] `branch_ledger.running_balance` for a test branch pair (A↔B) is unchanged after close (inter-branch due is perpetual).

**Restore path (the "upload database to view" flow):**
- [ ] Running `php artisan fy:detach-archived --fiscal-year=<closed-fy-id>` is idempotent (no-op if already archived).
- [ ] Manually calling `FiscalYearPartitionService::restoreForViewing($closedFyId)` re-attaches the partitions, after which `SalesInvoice::withoutGlobalScope('current_fy')->where('fiscal_year_id', $closedFyId)->count()` returns the expected count.
- [ ] Re-running `detachAndArchive()` after `restoreForViewing()` returns the system to the archived state.

### PM Checkpoint
Report: "Phase 1 (Q1) complete and UAT-passed. The system now satisfies the client's hard requirement: closed-FY data is invisible to every user including super admin, both via UI and via the global scope escape hatch. Backup is auto-produced on year-end close. Opening balances carry forward correctly. Stock, AR, AP, inter-branch due all carry forward correctly. Ready to start Phase 2 (Q2 — pricing & P&L) tomorrow. **Recommend a client demo of Phase 1 before starting Phase 2** — the client should personally try to view closed-FY data and confirm they cannot."

---

# Phase 2 — Inter-Branch Pricing & P&L (Q2)

**Phase goal**: After Phase 2 completes, every sale line carries a `price_classification` (min / default / max / below_min) and a `cost_rate` snapshot. Below-min sales require admin approval with a logged reason. Sale lines are linked deterministically to the demand items that supplied the goods (FIFO). A new Branch P&L report gives A a consolidated view of B's due, sales mix, and per-demand profit/loss.

**Phase duration**: 4 working days (Sessions 5–8).

---

## Session 5 — Sale-Line Price Classification + Cost Snapshot

| | |
|---|---|
| **Phase** | 2 (Q2) |
| **Duration** | 1 working day |
| **Dependencies** | Phase 1 complete (S4 signoff) |
| **Owner** | Backend dev |
| **Goal** | Add columns to `sales_invoice_items` that snapshot the min/max/default/cost context at the moment of sale, and compute a `price_classification` per line. Backfill historical rows where possible. |

### Files to Touch
- New migration: `database/migrations/2026_xx_xx_000004_add_price_classification_to_sales_invoice_items.php`
- New migration: `database/migrations/2026_xx_xx_000005_backfill_price_classification.php`
- Modify: `app/Services/SalesCartService.php` — when finalising a cart into an invoice, populate the new columns
- Modify: `app/Services/SalesInvoiceFinalizeService.php` (locate via `grep -rl "finalize\|finalise" app/Services/ | grep -i sale`) — same
- New: `app/Support/PriceClassifier.php` — pure function that takes `(rate, min, max, default, cost)` and returns a classification string

### Schema Changes

Add to `sales_invoice_items`:
| Column | Type | Notes |
|---|---|---|
| `price_min` | NUMERIC(12,2) | Snapshot of product's min_rate at sale time |
| `price_max` | NUMERIC(12,2) | Snapshot of product's max_rate at sale time |
| `price_default` | NUMERIC(12,2) | Snapshot of product's default_rate at sale time |
| `cost_rate` | NUMERIC(12,2) | Snapshot of the locked inter-branch cost from the demand item that supplied this sale (NULL if the product was not sourced via a branch demand — e.g., direct purchase) |
| `price_classification` | TEXT CHECK in (`'min'`, `'default'`, `'max'`, `'below_min'`) | Computed at finalize time |
| `branch_demand_item_id` | BIGINT FK → `branch_demand_items.id`, NULL | Populated in Session 7; column is created here but left NULL for now |
| `below_min_override_id` | BIGINT FK → `user_audit_log.id`, NULL | Populated in Session 6; column is created here but left NULL for now |

All new columns are nullable in this migration so the backfill can leave them NULL for rows where the historical price context cannot be reconstructed.

### Function/Method Signatures

**`PriceClassifier`:**
- `classify(float $rate, float $min, float $max, float $default, ?float $cost = null): string` — returns `'below_min'` if `$rate < $min`, `'min'` if `$rate == $min`, `'default'` if `$rate == $default`, `'max'` if `$rate == $max` or `$rate > $default`, with tie-breaker rules documented in the method's docblock
- The profit/loss threshold is `cost_rate` (not `min`), but for classification we use `min` because that is what the cashier sees in the cart. The two coincide when A dispatches at min price, which is the standard flow.

**`SalesCartService::finalize()` amendment:**
- When building each `SalesInvoiceItem` from a cart line, populate `price_min`, `price_max`, `price_default` from the cart line's stored values (already in `items_json`).
- Populate `cost_rate` by looking up the active `branch_demand_items.cost_rate` for the product in the selling branch (FIFO — oldest open demand item). If no demand item exists (e.g., product was directly purchased by the branch from a supplier), use `products.cost_price` or leave NULL.
- Compute `price_classification` via `PriceClassifier::classify()`.

### Tasks (in order)

1. **Write the migration** adding the 7 new columns. All nullable for now.
2. **Implement `PriceClassifier`** as a pure, unit-testable class. Write a PHPUnit data-provider test covering: rate below min, rate = min, rate between min and default, rate = default, rate between default and max, rate = max, rate > max (should not happen — cart blocks it, but classify as `max` defensively).
3. **Amend `SalesCartService::finalize()`** to populate the new columns. The cart's `items_json` already has `min`, `max`, `default` per line — extract them. For `cost_rate`, add a helper `BranchDemandService::getActiveCostRate($branchId, $productId): ?float` that returns the oldest open demand item's locked `cost_rate`.
4. **Write the backfill migration** for historical rows. For each existing `sales_invoice_items` row, look up the `product_price_history` row that was effective on the invoice date to get min/max/default. Look up the demand item that was open at that time for cost_rate (best-effort — may be NULL). Compute classification. Log rows where backfill was impossible to a `backfill_gaps` log table or to Laravel's `log` channel.
5. **Test manually**: create a new sale in dev through the cart at min/default/max prices, finalize, and confirm the new columns are populated correctly.
6. **Commit**: `git commit -m "feat(pricing): persist price classification + cost snapshot on sale lines (S5)"`.

### Acceptance Tests
- [ ] Migration runs forward and backward cleanly.
- [ ] New sale at min price → `price_classification='min'`, `price_min`/`price_max`/`price_default` populated, `cost_rate` = active demand's `cost_rate`.
- [ ] New sale at max price → `price_classification='max'`.
- [ ] New sale at default price → `price_classification='default'`.
- [ ] Attempted sale below min via the cart → still blocked by `SalesCartService::addItem()` (the below-min override workflow lands in S6; for now the cart still hard-blocks).
- [ ] Backfill migration populates > 95% of historical rows. The remaining < 5% are logged with reasons.
- [ ] `PriceClassifier` PHPUnit test passes for all 7 boundary cases.
- [ ] Existing sales reports (e.g., today-invoice analysis) still render correctly — the new columns do not break existing queries.

### PM Checkpoint
Report: "Session 5 complete. Sale lines now carry price classification and cost snapshot. Historical rows backfilled (95%+ coverage). Cart still hard-blocks below-min — the override workflow is tomorrow (S6). Ready to proceed."

---

## Session 6 — Below-Min Admin Override Workflow

| | |
|---|---|
| **Phase** | 2 (Q2) |
| **Duration** | 1 working day |
| **Dependencies** | Session 5 complete |
| **Owner** | Backend dev + Frontend dev |
| **Goal** | Allow a sale below the min price ONLY when an admin or manager actively approves it with a reason (≥ 10 chars). The approval is logged to `user_audit_log`, and the sale line's `below_min_override_id` points to that log row. |

### Files to Touch
- New migration: `database/migrations/2026_xx_xx_000006_add_below_min_override_fields_to_user_audit_log.php` — adds `action_type` (if not present), `subject_type`, `subject_id`, `payload JSONB` columns to `user_audit_log` if they don't already exist (audit Task 4-a confirms `user_audit_log` exists and is used by `credit_limit_override` and `period_close_admin_override`)
- Modify: `app/Services/SalesCartService.php` — `addItem()` no longer hard-throws on below-min; instead it stores the line with a `pending_below_min` flag in `items_json` and the cart cannot be finalized until either the line is removed or an approval is attached
- New: `app/Services/BelowMinApprovalService.php`
- New: `app/Http/Controllers/Admin/SalesBelowMinApprovalController.php` — endpoints: `store` (approve a pending line), `index` (list pending approvals for the current user's branch)
- New routes in `routes/web.php` under `role:admin,manager` middleware: `POST /admin/sales-below-min-approvals`, `GET /admin/sales-below-min-approvals`
- Modify: `resources/views/admin/sales/pos.blade.php` (or the cart view — locate via `grep -rl "sales-cart\|salesCart" resources/views/`) — add a modal that appears when a below-min rate is entered, prompting for approver credentials + reason
- Modify: `public/assets/js/sales-create.js` (or the relevant cart JS) — handle the modal, call the approval endpoint, then re-render the cart

### Schema Changes

Audit `user_audit_log` for existing columns. If `action_type`, `subject_type`, `subject_id`, `payload` are missing, add them in this migration. The `payload` column will hold JSON like:
```json
{
  "product_id": 123,
  "requested_rate": 8.00,
  "min_rate": 10.00,
  "reason": "Customer is a bulk buyer, special discount approved by phone",
  "cart_id": 456,
  "sale_line_index": 2
}
```

### Function/Method Signatures

**`BelowMinApprovalService`:**
- `requestApproval(int $cartId, int $lineIndex, int $productId, float $requestedRate, float $minRate): void` — marks the cart line as `pending_below_min` and creates a pending approval row
- `approve(int $approvalId, int $approverUserId, string $reason): int` — validates `strlen($reason) >= 10`, validates `$approverUserId` has `role:admin,manager`, writes a row to `user_audit_log` with `action_type='below_min_sale_approval'`, returns the audit log id
- `reject(int $approvalId): void` — removes the cart line
- `pendingForBranch(int $branchId): Collection`

**`SalesCartService::addItem()` amendment:**
- If `$rate < $min`: instead of throwing, store the line in the cart with `['below_min_pending' => true, 'requested_rate' => $rate, 'min_rate' => $min]`. The cart cannot be finalised while any line has `below_min_pending=true`.
- `finalize()` checks for pending lines and throws if any exist without an attached `below_min_override_id`.

**`SalesInvoiceFinalizeService::finalize()` amendment:**
- For each line with `below_min_override_id` set, populate `price_classification='below_min'`.

### Tasks (in order)

1. **Audit `user_audit_log` schema** with `\d user_audit_log` in psql. Determine which columns already exist vs. need adding.
2. **Write the migration** to add any missing columns.
3. **Implement `BelowMinApprovalService`** with the four methods above. The `approve()` method must be transactional and must check the approver's role at approval time (not just at request time) — prevents privilege escalation if the approver's role was revoked between request and approval.
4. **Amend `SalesCartService::addItem()`** to not throw on below-min. Update the existing PHPUnit test that expects the throw — it should now expect the line to be added with `below_min_pending=true`.
5. **Add the controller and routes**. The `store` endpoint requires `role:admin,manager` middleware. The request payload includes `approval_id`, `reason`. The response returns the `user_audit_log.id` to be attached to the cart line.
6. **Add the modal UI**. When the cashier enters a rate below min, a SweetAlert2 modal (the repo already uses SweetAlert2 — see `public/assets/js/bootstrep/sweetalert2@11.js`) appears: "Below minimum price. Admin/Manager approval required." with fields for approver username, approver password (re-authentication), and reason (textarea, min 10 chars). On submit, POST to the approval endpoint. On success, the cart line is marked approved and the modal closes. On failure, the line is removed.
7. **Update `finalize()`** to set `price_classification='below_min'` and `below_min_override_id` for approved lines.
8. **Test the full flow**: cashier adds a below-min line → modal → admin approves → cart finalizes → invoice line has `price_classification='below_min'`, `below_min_override_id` pointing to the audit log row, `user_audit_log` row has the reason and approver id.
9. **Commit**: `git commit -m "feat(pricing): below-min admin override workflow with audit log (S6)"`.

### Acceptance Tests
- [ ] Cashier enters rate below min → modal appears (no hard throw).
- [ ] Modal rejects reason < 10 chars with a validation error.
- [ ] Modal rejects non-admin/non-manager approver credentials.
- [ ] Successful approval → cart line marked approved, modal closes, cart can be finalised.
- [ ] Rejected approval → cart line removed.
- [ ] Finalised invoice line has `price_classification='below_min'`, `below_min_override_id` set, and the `user_audit_log` row has `action_type='below_min_sale_approval'`, the reason, the approver id, and the payload JSON.
- [ ] Existing PHPUnit tests updated and passing.
- [ ] Manual test: try to approve with a user whose role was changed from manager to cashier between request and approval — should fail with a 403.

### PM Checkpoint
Report: "Session 6 complete. Below-min sales are now possible with admin/manager approval + reason. Every below-min sale is fully auditable. Ready for Session 7 (demand-item linkage) tomorrow."

---

## Session 7 — Demand-Item Linkage (FIFO `consumed_qty`)

| | |
|---|---|
| **Phase** | 2 (Q2) |
| **Duration** | 1 working day |
| **Dependencies** | Session 6 complete |
| **Owner** | Backend dev |
| **Goal** | Add a `consumed_qty` column to `branch_demand_items` and populate `sales_invoice_items.branch_demand_item_id` at finalize time using FIFO (oldest open demand item for that product in the selling branch). This makes per-demand profit/loss attribution deterministic. |

### Files to Touch
- New migration: `database/migrations/2026_xx_xx_000007_add_consumed_qty_to_branch_demand_items.php`
- New migration: `database/migrations/2026_xx_xx_000008_backfill_branch_demand_item_id.php`
- New: `app/Services/DemandItemFifoResolver.php`
- Modify: `app/Services/SalesInvoiceFinalizeService.php` — call `DemandItemFifoResolver::consume($branchId, $productId, $qty)` per sale line, get back a list of `[demand_item_id, qty_consumed]` pairs (may span multiple demand items if the oldest doesn't have enough remaining)
- Modify: `app/Services/SalesCartService.php` — if a single sale line spans multiple demand items, split it into multiple `sales_invoice_items` rows at finalize time (one per demand item) so each row has a single `branch_demand_item_id`. This preserves the 1:1 relationship between sale lines and demand items for clean P&L attribution.

### Schema Changes

Add to `branch_demand_items`:
| Column | Type | Notes |
|---|---|---|
| `consumed_qty` | NUMERIC(14,3) DEFAULT 0 | How much of this demand item has been sold by the receiving branch. `fulfilled_qty - consumed_qty` = remaining stock available for sale attribution. |
| `consumed_qty_updated_at` | TIMESTAMP NULL | For debugging / audit |

Add a partial index: `CREATE INDEX ... ON branch_demand_items (receiving_branch_id, product_id) WHERE fulfilled_qty > consumed_qty` — this is the hot-path index for FIFO resolution.

### Function/Method Signatures

**`DemandItemFifoResolver`:**
- `consume(int $branchId, int $productId, float $qty): array` — returns `[['demand_item_id' => 1, 'qty' => 5], ['demand_item_id' => 2, 'qty' => 3]]` if the sale of 8 units spans two demand items. Atomically increments `consumed_qty` on each demand item. Throws if total available < requested (indicates a data-integrity issue — stock exists but no demand item is open, e.g., direct purchase by the branch — in that case return an empty array and leave `branch_demand_item_id` NULL on the sale line).
- `peek(int $branchId, int $productId, float $qty): array` — same as `consume` but read-only (for cart preview before finalize)
- `release(int $saleInvoiceItemId): void` — when a sale is reversed/returned, decrement `consumed_qty` on the linked demand items. Called by the existing sales-return reversal logic.

### Tasks (in order)

1. **Write the migration** adding `consumed_qty` and the partial index.
2. **Implement `DemandItemFifoResolver`**. The `consume()` method must be wrapped in a DB transaction with `SELECT ... FOR UPDATE` on the demand items to prevent race conditions when two sales finalise simultaneously.
3. **Amend `SalesInvoiceFinalizeService`**. For each cart line, call `DemandItemFifoResolver::consume()`. If the result spans multiple demand items, split the sale line into multiple `sales_invoice_items` rows (same `sales_invoice_id`, same `product_id`, same `rate`, but different `qty` and `branch_demand_item_id`).
4. **Amend the sales-return reversal logic** (locate via `grep -rn "SalesReturn" app/Services/ | grep -i reverse\|reverse`) to call `DemandItemFifoResolver::release()` for each returned line.
5. **Write the backfill migration**. For each existing `sales_invoice_items` row with `branch_demand_item_id IS NULL`, run the FIFO resolver against the historical demand items open at the sale date. This is best-effort — some rows may not be attributable (e.g., the demand items have been fully consumed by later sales in the historical sequence). Log gaps.
6. **Test**: create a demand A→B for 10 units at cost 10. Sell 5 at min, 3 at max, 2 below min (with approval). Verify the 3 sale lines link to the demand item and `consumed_qty = 10` on the demand item.
7. **Test the multi-demand-item case**: demand #1 supplies 4 units, demand #2 supplies 6 units. Sell 8 units → should produce 2 sale lines (4 from demand #1, 4 from demand #2) with the same rate.
8. **Test the return case**: reverse one of the sales → `consumed_qty` decreases accordingly.
9. **Commit**: `git commit -m "feat(pricing): FIFO demand-item linkage on sale lines (S7)"`.

### Acceptance Tests
- [ ] Migration runs forward and backward cleanly.
- [ ] Single-demand sale: 10 units demanded, 5 sold → `branch_demand_items.consumed_qty = 5`, sale line has `branch_demand_item_id` set.
- [ ] Multi-demand sale: 4 from demand #1 + 6 from demand #2, sell 8 → 2 sale lines created, `consumed_qty` = 4 on demand #1 and 4 on demand #2.
- [ ] Sale return: `consumed_qty` decreases by the returned qty.
- [ ] Sale of a product with NO open demand item (direct branch purchase) → `branch_demand_item_id` is NULL, `consumed_qty` unchanged, no error thrown.
- [ ] Backfill migration populates `branch_demand_item_id` for > 80% of historical sale lines. The remaining < 20% are logged.
- [ ] Concurrent finalize test: two simultaneous sales of the same product from the same branch → both succeed, `consumed_qty` ends up at the sum of both sales' quantities (no lost update). Use a database-level lock test or a Laravel `DB::transaction()` isolation test.
- [ ] Existing sales reports still render correctly — the line-splitting for multi-demand sales does not break aggregate queries (they should `SUM(qty)` and `SUM(amount)` which are unchanged at the invoice level).

### PM Checkpoint
Report: "Session 7 complete. Every sale line is now deterministically linked to the demand item that supplied the goods, via FIFO. Multi-demand sales are split into multiple lines transparently. Sales returns release the consumed qty. Ready for Session 8 (Branch P&L report + final UAT) tomorrow."

---

## Session 8 — Branch P&L Report + Final Integration UAT

| | |
|---|---|
| **Phase** | 2 (Q2) |
| **Duration** | 1 working day |
| **Dependencies** | Sessions 5, 6, 7 complete |
| **Owner** | Backend dev + Frontend dev + QA |
| **Goal** | Build the Branch P&L report that gives Branch A a consolidated view of Branch B's demand, sales mix, profit/loss, and outstanding due. Then run a full end-to-end UAT covering both Q1 and Q2 together. |

### Files to Touch
- New: `app/Services/BranchPnlReportService.php`
- New: `app/Http/Controllers/Admin/BranchPnlReportController.php`
- New routes in `routes/web.php` under `role:admin,manager,accountant`: `GET /admin/branches/{branch_id}/pnl`, `GET /admin/branch-demands/{id}/pnl`
- New: `resources/views/admin/branches/pnl.blade.php` — the report view
- New: `resources/views/admin/branch-demands/pnl.blade.php` — per-demand drilldown view
- New: `public/assets/css/branch-pnl.css`
- New: `app/Exports/BranchPnlExport.php` — Excel/CSV export (the repo uses `maatwebsite/excel` or similar — verify with `composer show | grep excel`)

### Schema Changes
None.

### Function/Method Signatures

**`BranchPnlReportService`:**
- `forBranch(int $branchAId, int $branchBId, int $fiscalYearId, ?Carbon $from = null, ?Carbon $to = null): array` — returns:
  ```
  [
    'demand_summary' => [
      'total_demanded_qty' => ...,
      'total_demanded_value' => ...,  // sum of qty * cost_rate
      'outstanding_due' => ...,        // from branch_ledger running_balance
      'settled_amount' => ...,
    ],
    'sales_summary' => [
      'total_sold_qty' => ...,
      'total_revenue' => ...,
      'total_cost' => ...,
      'net_pl' => ...,
      'qty_at_min' => ...,
      'qty_at_default' => ...,
      'qty_at_max' => ...,
      'qty_below_min' => ...,
      'override_count' => ...,
    ],
    'per_demand' => [
      [
        'demand_id' => ...,
        'demand_date' => ...,
        'demanded_qty' => ...,
        'cost_rate' => ...,
        'sold_qty' => ...,
        'revenue' => ...,
        'cost' => ...,
        'pl' => ...,
        'classification_breakdown' => ['min' => ..., 'default' => ..., 'max' => ..., 'below_min' => ...],
        'overrides' => [...],
      ],
      ...
    ],
  ]
  ```
- `forDemand(int $demandId): array` — single-demand drilldown with per-sale-line detail.

**`BranchPnlReportController`:**
- `show(Request $request, int $branchId)` — renders `branches/pnl.blade.php` with the report data for the selected counterparty branch (the user picks "view as Branch A, report on Branch B" via a dropdown)
- `showForDemand(Request $request, int $demandId)` — renders `branch-demands/pnl.blade.php`
- `export(Request $request, int $branchId)` — returns Excel/CSV download

### Tasks (in order)

1. **Implement `BranchPnlReportService::forBranch()`**. The core SQL joins `branch_demand_items` (what B owes A) with `sales_invoice_items` (what B sold, classified) via the `branch_demand_item_id` FK (from S7), and with `branch_ledger` (settlement state). All queries are automatically scoped to the running FY by the `BelongsToFiscalYear` trait (from S2) — no manual `WHERE fiscal_year_id` needed.
2. **Implement `forDemand()`** — same data but filtered to a single demand, with per-sale-line detail.
3. **Build the controller and routes**. Add a nav entry under "Reports" → "Branch Performance".
4. **Build the Blade views**. The branch-level view shows the summary cards (demanded value, outstanding due, net P&L, override count) + a table of per-demand rows. The per-demand drilldown shows per-sale-line detail with the approver/reason for any below-min lines.
5. **Add Excel/CSV export**. Use whichever export library the repo already has (`maatwebsite/excel` is most common in Laravel — check `composer.json`).
6. **Full end-to-end UAT** covering both Q1 and Q2 together (see Acceptance Tests below).
7. **Commit**: `git commit -m "feat(pnl): Branch P&L report + final integration UAT (S8)"`.
8. **Merge `feature/fy-isolation-and-branch-pnl` to `main`** after UAT passes.

### Acceptance Tests (Phase-2 + Final Integration UAT)

**Branch P&L report:**
- [ ] Given a demand A→B of 10 units at cost 10 (= min price), and B sells 5 at min, 2 below min (approved), 3 at max: the report shows revenue 102, cost 100, net P&L +2, qty_at_min=5, qty_below_min=2, qty_at_max=3, override_count=1.
- [ ] The per-demand drilldown shows each sale line with its rate, classification, and (for below-min) the approver name + reason.
- [ ] The outstanding due matches `branch_ledger.running_balance` for the A↔B pair.
- [ ] Excel/CSV export downloads and opens correctly with the same data.
- [ ] The report respects Q1's fiscal-year scoping — only the running FY's demands/sales appear. Manually verify by attempting to view a closed-FY demand via URL params (should return 404 or empty).

**Cross-phase integration (Q1 + Q2 together):**
- [ ] Run a full year-end close on a test FY that has branch demands and sales. Confirm: closing JE posts, opening balances refresh, partitions detach, backup file produced. After close, the Branch P&L report shows ZERO rows for the closed FY (because the partitions are detached and the global scope blocks reads).
- [ ] Run a sale at below-min with admin approval BEFORE year-end close → the audit log row survives the close (audit logs are in a partitioned table that may or may not be detached — verify the `user_audit_log` is NOT detached on close, so historical approvals remain queryable for compliance).
- [ ] After year-end close, the new FY's Branch P&L report correctly shows the carried-forward outstanding due from the closed FY (because `branch_ledger.running_balance` is perpetual and not detached).
- [ ] After year-end close, stock levels in the new FY are correct (because `WarehouseStock` is perpetual and not detached).
- [ ] Super admin attempts to view the closed FY's Branch P&L report via URL params → returns empty (Q1 hard-block holds).
- [ ] Super admin attempts to view the closed FY's Branch P&L report via `withoutGlobalScope('current_fy')` → still returns empty (because partitions are detached — the strongest guarantee from Q1).

**Client signoff scenarios (demo to client):**
- [ ] Demo 1: Super admin logs in, tries every URL and filter combination to view last year's sales — confirms cannot see anything.
- [ ] Demo 2: PM runs `php artisan db:backup-year-end` and shows the backup file on disk.
- [ ] Demo 3: PM runs `yearEndClose()` end-to-end on a test FY, shows the closing JE, the refreshed opening balances, the detached partitions, and the new FY auto-activating.
- [ ] Demo 4: Cashier creates a sale below min → admin approval modal → approval with reason → sale finalises with `below_min` classification.
- [ ] Demo 5: Branch A manager opens the Branch P&L report for Branch B → sees the demand, the sales mix, the profit/loss, the outstanding due, and the below-min overrides with reasons.
- [ ] Demo 6: PM demonstrates the "restore to view historical data" path by running `php artisan fy:detach-archived` reverse flow (i.e., `FiscalYearPartitionService::restoreForViewing()`) — confirms this is a manual artisan-only operation, not exposed in the UI.

### PM Checkpoint (final)
Report: "Phase 2 (Q2) complete and full integration UAT passed. Both client requirements delivered. Feature branch merged to `main`. Recommend scheduling a client demo session to walk through the 6 demo scenarios above. Post-merge, recommend a 1-week production hardening period before the first real year-end close."

---

## Risk Register (cross-phase)

| Risk | Phase | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Backfill migration takes too long on large tables, causing downtime | S1, S5, S7 | Medium | High | Run backfill in batches of 10,000 rows with `sleep()` between batches. Schedule during off-peak. Test on a prod-sized dev DB first. |
| Global scope breaks an existing report/query that legitimately needs cross-FY data (e.g., a multi-year trend report) | S2 | Medium | Medium | Audit all queries before S2 — `grep -rln "SalesInvoice::\|Purchase::\|JournalEntry::" app/` and review each. Add `withoutGlobalScope('current_fy')` ONLY where a policy review approves it. |
| `Gate::before()` amendment has a typo and super-admin bypass still applies to `viewHistoricalData` | S2 | Low | Critical | Write a dedicated PHPUnit test: `test_super_admin_cannot_view_historical_data()`. Run it in CI. |
| `pg_dump` binary path differs on production (Windows PC) vs. dev (Linux) | S3 | High | Medium | `config/backup.php` reads `PG_DUMP_BINARY` from `.env`. Document the Windows path (`C:\Program Files\PostgreSQL\16\bin\pg_dump.exe`) in the deployment guide. |
| Partition detach fails partway through `yearEndClose()`, leaving the FY in a half-closed state | S4 | Low | High | `detachAndArchive()` is idempotent — re-running skips already-archived partitions. The `period_close_log` records every detach, so a partial state is recoverable. Add a `fy:detach-archived` artisan command for manual retry. |
| Carry-forward of `opening_balance` is computed incorrectly due to a bug in `CustomerLedger::getBalance()` | S4 | Medium | High | Before S4, audit `CustomerLedger::getBalance()` and `SupplierLedger` balance methods. Add PHPUnit tests covering: customer with no payments, customer with partial payment, customer with credit note. |
| Below-min approval modal is bypassed by a custom API client (no UI enforcement) | S6 | Low | Medium | The `BelowMinApprovalService::approve()` method is the source of truth, not the modal. The cart's `finalize()` method hard-fails if any line is `below_min_pending` without an attached `below_min_override_id`. API clients cannot bypass this. |
| FIFO `consumed_qty` race condition under concurrent sales | S7 | Medium | High | `DemandItemFifoResolver::consume()` uses `SELECT ... FOR UPDATE` inside a DB transaction. Add a concurrent-finalize integration test. |
| Branch P&L report is slow on large datasets (many demands, many sale lines) | S8 | Medium | Low | Add DB indexes on `sales_invoice_items(branch_demand_item_id)` and `branch_demand_items(receiving_branch_id, product_id) WHERE fulfilled_qty > consumed_qty` (already added in S7). Cache the report result for 5 minutes per `(branchA, branchB, fiscalYearId)` key. |
| `user_audit_log` is partitioned and gets detached on year-end close, losing below-min approval history | S8 | Low | Critical | Audit `user_audit_log` partitioning config. If it's set to detach on close, EXCLUDE it from `config('fiscal.partitioned_tables')` — audit logs must be retained for compliance regardless of FY close. |

---

## Signoff Matrix

| Signoff | Owner | Trigger | Artefact |
|---|---|---|---|
| Phase 1 complete | Tech lead + PM | S4 acceptance tests pass | Worklog entry + commit tag `phase-1-complete` |
| Phase 1 client demo | Client | Phase 1 signoff | Email confirmation from client |
| Phase 2 complete | Tech lead + PM | S8 acceptance tests pass | Worklog entry + commit tag `phase-2-complete` |
| Final integration UAT | QA + PM | S8 cross-phase tests pass | UAT report document |
| Merge to `main` | Tech lead | Final UAT pass | PR merge + tag `v<next>-fy-and-pnl` |
| Production deploy | DevOps | Merge to main + 1-week hardening | Deployment runbook signoff |
| First real year-end close | Tech lead + Client | Production deploy + first real FY close | `period_close_log` review + client confirmation that closed FY is invisible |
