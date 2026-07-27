# Stock Take — Go-Live Checklist

**Feature:** Physical Count / Stock Take (cycle count + full-warehouse count)
**Module:** RCERP Laravel/PostgreSQL Stock Take
**Document version:** 1.0 (Phase 12 — Testing, Monitoring, and Go-Live)
**Audience:** Release manager, QA lead, DevOps engineer, Finance controller
**Sign-off process:** All sections must be checked. Sign-off table at the
end must be completed by all four roles before the feature is declared
production-ready. Roll back is allowed up to 24 hours after go-live if any
_severe_ issue is found (see §12 Rollback Plan).

---

## 1. Overview

### 1.1 Purpose

This checklist verifies that every Phase 0–12 deliverable of the Stock
Take feature is installed, configured, and operating correctly in the
target environment before the feature is announced to end users. It
covers schema, RLS, policies, materialized views, scheduled jobs,
reports, API, health-check dashboard, training, and rollback.

### 1.2 Audience

| Role | Responsibility |
|------|----------------|
| Release manager | Drives the checklist; collects sign-offs |
| QA lead | Executes test suite + manual smoke tests |
| DevOps engineer | Runs migrations, schedules pg_cron, monitors jobs |
| Finance controller | Verifies GL posting + reversal flows; approves policy values |

### 1.3 Sign-off process

1. Each section's checkboxes must all be `[x]` before sign-off.
2. The sign-off table at the end (§13) captures name + date + signature
   for each role.
3. Sign-offs are stored alongside the release tag in the ERP releases
   folder (`docs/releases/STOCK_TAKE_v<n>.<m>.<p>.md`).
4. A go-live is _blocked_ until all four sign-offs are present.

---

## 2. Pre-flight: schema & migrations

### 2.1 Stock-take migrations (Phase 0 → Phase 12)

Run `php artisan migrate` from the `laravel/` directory. Every migration
must be idempotent (uses `IF EXISTS` / `IF NOT EXISTS` guards) so re-runs
are safe. After the run, `php artisan migrate:status` must show all
stock-take migrations as `Ran`.

- [ ] **Phase 0** — `2025_01_01_000001_create_rcerp_schema.php` executed
  (loads `database/sql/03_stock.sql` which creates `stock_take_sessions`,
  `stock_take_warehouses`, `stock_take_items`, `stock_take_policies`).
- [ ] **Phase 1** — `2025_07_26_000003_add_reversal_columns_to_stock_take_sessions.php`
  (reversal columns on sessions).
- [ ] **Phase 1** — `2025_07_26_000004_add_journal_line_id_to_stock_take_items.php`
  (per-line GL traceability).
- [ ] **Phase 2** — `2025_07_26_000005_create_stock_take_audit_log_table.php`
  (`stock_take_audit_log` table).
- [ ] **Phase 3** — `2025_07_27_000001_add_freeze_columns_to_stock_take_sessions.php`
  (`freeze_outbound`, `frozen_at`).
- [ ] **Phase 4** — `2025_07_28_000001_add_approval_workflow_to_stock_take_sessions.php`
  (submit/approve/reject columns + default policies).
- [ ] **Phase 5** — `2025_07_29_000001_add_cycle_count_scope_and_abc_classification.php`
  (cycle count scope columns + ABC materialized view + ABC policies).
- [ ] **Phase 7** — `2025_07_30_000001_add_recount_columns_to_stock_take.php`
  (recount columns + recount policy).
- [ ] **Phase 8** — `2025_08_01_000001_phase8_concurrency_rls_locking_hardening.php`
  (RLS on stock_take_* tables, denormalized branch_id, no-overlap freeze
  trigger, advisory lock).
- [ ] **Phase 9** — `2025_08_02_000001_phase9_post_time_cost_and_revaluation.php`
  (post-time system_rate/post_rate/revaluation_amount + revaluation
  journal line + epsilon policy).
- [ ] **Phase 10** — `2025_08_03_000001_phase10_reversal_vs_cancel_reopen.php`
  (reversed status + re-open audit columns + max_reopens policy).
- [ ] **Phase 11** — _no migrations_ (API + mobile foundation).
- [ ] **Phase 12** — `2025_08_04_000001_phase12_pg_cron_stock_take_jobs.php`
  (pg_cron jobs + monitoring functions).

### 2.2 Idempotency check

- [ ] `php artisan migrate:fresh --seed` runs to completion without
  errors (only on a staging database — never on production).
- [ ] `php artisan migrate` (no fresh) re-runs without errors after a
  successful run (idempotency proof).

### 2.3 Schema verification queries

```sql
-- All stock-take tables present
SELECT table_name FROM information_schema.tables
 WHERE table_schema = 'public' AND table_name LIKE 'stock_take%'
 ORDER BY table_name;
-- Expected: stock_take_audit_log, stock_take_items, stock_take_policies,
--           stock_take_sessions, stock_take_warehouses

-- RLS enabled + forced on all four row-level tables
SELECT relname, relrowsecurity, relforcerowsecurity
  FROM pg_class
 WHERE relname IN ('stock_take_sessions','stock_take_warehouses',
                   'stock_take_items','stock_take_audit_log')
 ORDER BY relname;
-- Expected: relrowsecurity=t, relforcerowsecurity=t for all four.
```

---

## 3. RLS & security

### 3.1 Row-Level Security

- [ ] `stock_take_sessions` — RLS ENABLED + FORCED (Phase 8).
- [ ] `stock_take_warehouses` — RLS ENABLED + FORCED (Phase 8).
- [ ] `stock_take_items` — RLS ENABLED + FORCED (Phase 8).
- [ ] `stock_take_audit_log` — RLS ENABLED + FORCED (Phase 8 / 07_views_triggers_constraints.sql).

### 3.2 Denormalized `branch_id` populated

Phase 8 denormalized `branch_id` onto `stock_take_items` and
`stock_take_warehouses` so RLS can scope by branch without joining.
Verify every row has a non-null `branch_id` that matches its parent
session:

```sql
-- Should return 0 rows
SELECT 'sti_null' AS issue, COUNT(*) FROM stock_take_items WHERE branch_id IS NULL
UNION ALL
SELECT 'stw_null', COUNT(*) FROM stock_take_warehouses WHERE branch_id IS NULL
UNION ALL
SELECT 'sti_mismatch', COUNT(*)
  FROM stock_take_items sti
  JOIN stock_take_sessions sts ON sts.id = sti.stock_take_session_id
 WHERE sti.branch_id <> sts.branch_id
UNION ALL
SELECT 'stw_mismatch', COUNT(*)
  FROM stock_take_warehouses stw
  JOIN stock_take_sessions sts ON sts.id = stw.stock_take_session_id
 WHERE stw.branch_id <> sts.branch_id;
```

- [ ] All four counts are 0.

### 3.3 Route middleware

Inspect `routes/web.php` (admin/stock-take prefix group) and
`routes/api.php` (api/v1/stock-take prefix group):

- [ ] Read routes (`index`, `show`, `checklist`, `audit`, `abc-report`)
  require `role:admin,manager,warehouse_manager,accountant`.
- [ ] Write routes (`create`, `store`, `setupCounts`, `saveCounts`,
  `scanCount`, `bulkPaste`, `importCounts`, `recount`, `autosave`,
  `post`, `submit`) require `role:admin,manager,warehouse_manager`.
- [ ] Destructive routes (`cancel`, `reverse`, `reOpen`, `approve`,
  `reject`, `abc/refresh`) require `role:admin,manager`.
- [ ] All POST write routes carry `branch.isolation` (so non-admins
  cannot forge cross-branch writes).
- [ ] `health-summary` route requires `role:admin,manager,accountant`.

### 3.4 API authentication

- [ ] All `/api/v1/stock-take/*` routes carry `api.auth` (bearer token).
- [ ] All `/api/v1/stock-take/*` routes carry `set.api.branch` (Phase 11
  middleware that sets the `app.branch_id` GUC so RLS filters by the
  authenticated user's branch — closes the global-SetAppBranchId-skips-
  API gap).
- [ ] Rate limits applied: writes = 30/min, reads = 60/min.

---

## 4. System policies

Every `stock_take.*` policy key must be present in the
`stock_take_policies` table with a sane default value. Verify:

```sql
SELECT key, value, description
  FROM stock_take_policies
 WHERE key LIKE 'stock_take.%'
 ORDER BY key;
```

### 4.1 Expected policy keys + defaults

| Key | Expected default | Phase | Purpose |
|-----|------------------|-------|---------|
| `stock_take.require_approval` | `true` | 4 | Gate counting → posted behind submit+approve |
| `stock_take.auto_approve_below_value` | `0` (disabled) | 4 | Auto-approve sessions whose total variance value is below this |
| `stock_take.approver_roles` | `["admin","manager"]` | 4 | Roles allowed to approve submitted sessions |
| `stock_take.variance_threshold_block` | `0` (disabled) | 4 | Block post if any line's |value| exceeds this |
| `stock_take.abc_threshold_a` | `0.80` | 5 | Cumulative usage-value share for class A |
| `stock_take.abc_threshold_b` | `0.95` | 5 | Cumulative usage-value share for A+B (B = 80–95%) |
| `stock_take.abc_lookback_days` | `365` | 5 | Lookback window for ABC usage-value calc |
| `stock_take.recount_reset_to_system` | `false` | 7 | On recount, reset physical_qty to current system_qty |
| `stock_take.max_reopens` | `1` | 10 | Max re-open attempts after reversal |
| `stock_take.revaluation_epsilon` | `0.01` | 9 | Min |post_rate − system_rate| that triggers a revaluation entry |

- [ ] All 10 keys present in `stock_take_policies`.
- [ ] Each value matches the production-agreed default (Finance
  controller + Release manager sign off).
- [ ] Each row has a non-empty `description`.

---

## 5. Materialized views

### 5.1 ABC classification view

- [ ] `mv_product_abc_classification` exists.
- [ ] UNIQUE index `mv_product_abc_classification_wh_prod_uidx` on
  `(warehouse_id, product_id)` exists (required for `REFRESH
  CONCURRENTLY`).
- [ ] Secondary indexes on `abc_class` and `product_id` exist.

Verify:

```sql
SELECT matviewname FROM pg_matviews WHERE matviewname = 'mv_product_abc_classification';

SELECT indexname, indexdef
  FROM pg_indexes
 WHERE tablename = 'mv_product_abc_classification'
 ORDER BY indexname;
```

### 5.2 Refresh

- [ ] `REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification`
  runs without error (top-level only — not inside a function or
  transaction block).
- [ ] After refresh, `SELECT MAX(computed_at), COUNT(*) FROM
  mv_product_abc_classification` returns a non-null computed_at and > 0
  rows (assuming the database has any outbound stock movement in the
  lookback window).
- [ ] Manual refresh button on the ABC report page
  (`/admin/stock-take/abc-report`) works (calls
  `AbcClassificationService::refresh()`).

---

## 6. pg_cron jobs

### 6.1 Phase 12 stock-take jobs

| Job name | Schedule | Command | Purpose |
|----------|----------|---------|---------|
| `stock-take-stale-session-reminder` | `0 6 * * *` (daily 06:00) | `SELECT * FROM stock_take_mark_stale_sessions(30)` | List open sessions > 30 days old (admin reminder) |
| `stock-take-abc-refresh` | `30 3 * * *` (nightly 03:30) | `SELECT * FROM stock_take_refresh_abc_classification()` | Refresh ABC MV (note: see §5.2 — REFRESH CONCURRENTLY inside a plpgsql function has a known limitation; if `refreshed=false`, use the manual refresh button) |
| `stock-take-reconciliation-sweep` | `0 * * * *` (hourly) | `SELECT * FROM stock_take_reconciliation_alert_sweep()` | UNION of three GL reconciliation checks |

### 6.2 Existing Phase-0 jobs (must still be present)

| Job name | Schedule | Purpose |
|----------|----------|---------|
| `cancel-stale-drafts` | `0 2 * * *` | Cancel stale sales draft invoices |
| `refresh-report-views` | `*/5 * * * *` | Refresh 7 financial report MVs |
| `refresh-rb-checks` | `0 * * * *` | Refresh 4 running-balance check MVs |
| `purge-old-notifications` | `0 3 * * *` | Purge read notifications > 90 days |
| `analyze-high-write-tables` | `0 4 * * *` | ANALYZE high-write tables |

### 6.3 Verify

```sql
SELECT jobname, schedule, active, last_status, last_return_message,
       last_start, last_end, last_duration_seconds
  FROM v_pg_cron_jobs
 ORDER BY jobname;
```

- [ ] All 8 jobs (5 Phase-0 + 3 Phase-12) present.
- [ ] All 8 jobs have `active = true`.
- [ ] Phase-12 jobs have at least one successful run recorded
  (`last_status = 'succeeded'`). For `stock-take-abc-refresh`,
  `last_status` may be `'succeeded'` even if the function returned
  `refreshed=false` (the function call succeeded; the refresh inside
  did not — check `last_return_message` for the SQLERRM).

### 6.4 Laravel scheduler fallback

The Laravel scheduler at `routes/console.php` (or `app/Console/Kernel.php`)
should still define the same jobs as a fallback for environments where
`pg_cron` is not installed:

- [ ] `stock_take:refresh-abc` artisan command exists and is scheduled
  nightly at 03:30.
- [ ] `stock_take:stale-session-reminder` (or equivalent) is scheduled
  daily at 06:00.
- [ ] `stock_take:reconciliation-sweep` (or equivalent) is scheduled
  hourly.

---

## 7. Reports registered

### 7.1 Variance report

- [ ] Route `admin.stock-take.index` shows a per-session variance link.
- [ ] Variance report view `resources/views/admin/reports/stocktake_variance.blade.php`
  renders with filter-by-session / filter-by-warehouse / filter-by-product.
- [ ] Menu entry under Reports → Stock → "Stock Take Variance" exists
  (verify via `menu_items` table or the admin Reports index page).

### 7.2 Weekly report

- [ ] Route `admin.reports.stocktake-weekly` (or equivalent) exists.
- [ ] View `resources/views/admin/reports/stocktake_weekly.blade.php`
  renders with date-range picker.
- [ ] Menu entry under Reports → Stock → "Stock Take Weekly" exists.

### 7.3 ABC report

- [ ] Route `admin.stock-take.abc-report` exists.
- [ ] View `resources/views/admin/stock-take/abc-report.blade.php`
  renders with per-class counts + total usage value + computed_at badge.
- [ ] "Refresh ABC" button calls `admin.stock-take.abc.refresh` and
  re-renders the page.

---

## 8. API docs

- [ ] Visit `/api/docs` — page renders without error.
- [ ] Stock-take section lists all 17 endpoints (13 session-level + 4
  item-level) under `/api/v1/stock-take/*`.
- [ ] Each endpoint entry has: method, path, description, required role,
  params, body schema, response schema, error codes.
- [ ] "Try it out" flow works for at least one endpoint (e.g.
  `GET /api/v1/stock-take/sessions`) using a test bearer token.

---

## 9. Test suite

### 9.1 Automated tests

```bash
cd laravel
php artisan test --filter=StockTake
```

- [ ] All StockTake-tagged tests pass (green).
- [ ] No skipped tests without an inline `// TODO: …` explanation.

### 9.2 Coverage

```bash
php artisan test --coverage --filter=StockTakeService
```

- [ ] `StockTakeService` line coverage ≥ 90%.
- [ ] `StockTakeHealthCheckService` line coverage ≥ 80%.
- [ ] `AbcClassificationService` line coverage ≥ 80%.
- [ ] `StockTakePolicyService` line coverage ≥ 80%.

### 9.3 Manual smoke tests

Each flow below must be exercised end-to-end on the staging environment
with at least two branches and at least two users (counter + approver):

- [ ] Full-warehouse count: create → setup → count → submit → approve →
  post → verify stock_transactions + journal_entries rows.
- [ ] Cycle count (ad_hoc scope): create with ad_hoc picker → only
  selected products appear in setup.
- [ ] Cycle count (abc scope): create with class A only → only class-A
  products appear.
- [ ] Recount: post a session → reverse → re-open → re-count → re-post.
- [ ] Cancellation: cancel a `counting` session → no GL impact, status
  = `cancelled`.
- [ ] Reversal: reverse a `posted` session → stock + GL reversed, status
  = `reversed`, `is_reversed = true`.
- [ ] Freeze: session with `freeze_outbound=true` blocks outbound stock
  movements for the duration of the count.
- [ ] RLS: non-admin user (manager role, branch A) cannot see branch B's
  sessions.

---

## 10. Health-check dashboard

### 10.1 Health tile

- [ ] Admin dashboard (`/dashboard`) renders the "Stock Take Health"
  tile at the top of the page for admin / manager / accountant roles.
- [ ] Tile shows pass / warn / fail badges after AJAX fetch completes
  (skeleton shown during fetch).
- [ ] Tile does NOT render for `warehouse_manager`, `salesman`,
  `dispatcher`, `hr`, `user` roles (route middleware blocks the AJAX
  call; the partial hides itself via the role check).
- [ ] "Full checklist →" link navigates to `/admin/stock-take/checklist`.
- [ ] If the AJAX endpoint fails (e.g. DB down), the tile shows a small
  error note rather than breaking the dashboard.

### 10.2 Checklist page

- [ ] `/admin/stock-take/checklist` renders all six sections
  (Workflow / Data integrity / GL journal links / Shrinkage & surplus
  ledgers / Stock & GL alignment / Operations & reports).
- [ ] Each item shows a status badge (pass / warn / fail / info) with
  the appropriate icon and color.
- [ ] "Ran at" timestamp is current (within the last few seconds).
- [ ] For admins, "All branches" toggle works (RLS bypass shows
  cross-branch counts).
- [ ] "Missing session journals" list at the bottom shows real session
  rows when present (or "None" when all posted sessions have journals).

### 10.3 Audit page

- [ ] `/admin/stock-take/audit` renders the audit-log table with
  filters (date range, actor, action, session, search).
- [ ] Filter by action = `posted` returns only post-action rows.
- [ ] Filter by session_id scopes correctly.

---

## 11. Training material

### 11.1 Counter guide

- [ ] Step-by-step guide for warehouse counters: how to access the
  count page, enter counts, save partial progress, mark a warehouse
  complete, submit for approval.
- [ ] Screenshots of the count page UI (scan / bulk paste / CSV import
  tabs).
- [ ] Common error messages and their resolutions (negative stock,
  frozen warehouse, missing reason for large variance).

### 11.2 Approver guide

- [ ] Step-by-step guide for managers: how to view submitted sessions,
  review variance, approve or reject with comments.
- [ ] Explanation of the segregation-of-duties rule (submitter ≠
  approver).

### 11.3 Admin guide

- [ ] How to configure `stock_take.*` policies (System Policy admin
  page).
- [ ] How to run the health-check checklist and interpret each item.
- [ ] How to interpret the dashboard health tile.
- [ ] How to refresh the ABC materialized view manually.
- [ ] How to reverse + re-open a posted session (and the `max_reopens`
  limit).
- [ ] How to read the audit log.

---

## 12. Rollback plan

### 12.1 Pause pg_cron jobs (soft rollback)

If a Phase 12 monitoring job is misbehaving (e.g. sending too many
alert emails, consuming too much CPU), disable it without rolling back
the migration:

```sql
SELECT cron.alter_job(
  (SELECT jobid FROM cron.job WHERE jobname = 'stock-take-stale-session-reminder'),
  active => false
);
-- Repeat for 'stock-take-abc-refresh' and 'stock-take-reconciliation-sweep'.
```

- [ ] Verify with: `SELECT jobname, active FROM v_pg_cron_jobs WHERE
  jobname LIKE 'stock-take-%';` — `active = false` for the paused job.

### 12.2 Roll back the Phase 12 migration

If the migration itself caused a problem (e.g. function creation
failed mid-way leaving partial state):

```bash
cd laravel
php artisan migrate:rollback --step=1
```

This runs the `down()` method of `2025_08_04_000001_phase12_pg_cron_stock_take_jobs.php`:

- Unschedules the three stock-take pg_cron jobs.
- Drops the three functions (`stock_take_mark_stale_sessions`,
  `stock_take_reconciliation_alert_sweep`,
  `stock_take_refresh_abc_classification`) with CASCADE.
- Re-creates the `v_pg_cron_jobs` monitoring view (no-op since the view
  is owned by the older migration; harmless).

- [ ] After rollback, verify the three functions are gone:
  ```sql
  SELECT proname FROM pg_proc WHERE proname LIKE 'stock_take_%' ORDER BY proname;
  -- Expected: stock_take_abc_lookback_days, stock_take_abc_threshold_a,
  --           stock_take_abc_threshold_b (the Phase 5 helpers remain —
  --           they were not created by Phase 12).
  ```
- [ ] Verify the three jobs are gone:
  ```sql
  SELECT jobname FROM cron.job WHERE jobname LIKE 'stock-take-%';
  -- Expected: 0 rows.
  ```

### 12.3 Roll back to before Phase 12 dashboard tile

If the dashboard tile is causing problems (e.g. JS error breaking the
dashboard for all users):

- [ ] Comment out the `@include('admin.stock-take._health_tile')` line
  in `resources/views/dashboard/index.blade.php` (line ~35, inside the
  "Phase 12: Stock Take Health tile" section).
- [ ] Clear the view cache: `php artisan view:clear`.
- [ ] No code deploy needed — the partial can be removed safely without
  affecting any other dashboard functionality.

### 12.4 Roll back further (Phase 11 / 10 / 9 / 8)

These are progressively more invasive rollbacks and require DevOps +
Finance controller sign-off. Each step requires `php artisan
migrate:rollback --step=1` and a corresponding database integrity
check. **Do NOT roll back past Phase 8 without a full database backup**
(Phase 8 added RLS — rolling it back disables branch isolation).

---

## 13. Sign-off table

All four signatures are required before the feature is declared
production-ready.

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Release manager | ________________________ | ____________ | ________________________ |
| QA lead | ________________________ | ____________ | ________________________ |
| DevOps engineer | ________________________ | ____________ | ________________________ |
| Finance controller | ________________________ | ____________ | ________________________ |

### 13.1 Sign-off notes

- _Release manager_: confirm all sections 1–11 are checked and §13
  signatures collected.
- _QA lead_: confirm §9 test suite is green + §9.3 smoke tests executed
  on staging.
- _DevOps engineer_: confirm §2 migrations applied, §6 pg_cron jobs
  scheduled, §6.4 Laravel scheduler fallback in place.
- _Finance controller_: confirm §4 policy values match production
  decisions + §10 health-check surfaces real data + §11 training
  material is published.

### 13.2 Final go-live declaration

> **Stock Take feature is declared production-ready.**
>
> Sign-off date: ____________
>
> Release tag: ____________
>
> Rollback window closes (24h after go-live): ____________

---

_End of checklist._
