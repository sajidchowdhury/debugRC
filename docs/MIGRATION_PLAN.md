# RC_ERP Migration Plan
## PHP/MySQL (Custom MVC) → Laravel 11 + PostgreSQL 16

**Document version:** 1.0
**Prepared for:** RC_ERP modernization initiative
**Source codebase:** `/home/z/RC_ERP` (416 PHP files, ~95.6K LOC, 66 MySQL tables, 48 migrations)
**Target stack:** Laravel 11 (PHP 8.3) + PostgreSQL 16 + Redis 7 + Nginx + Ubuntu 22.04 VPS (BDIX)
**Estimated total duration:** 7–9 months (incremental, ship-as-you-go)

---

## 0. Executive Summary

This document defines a phased, low-risk migration of the RC_ERP system from a hand-rolled PHP/MySQL codebase to **Laravel 11 + PostgreSQL 16**, deployed on a BDIX VPS.

The migration is **incremental, not a big-bang rewrite**. The legacy PHP app and the new Laravel app will run side-by-side against the **same PostgreSQL database** behind a single Nginx gateway. Modules are ported one at a time, each independently shippable, each verified for correctness before cutover.

### The four non-negotiable principles

1. **Database conversion (MySQL → PostgreSQL).** PostgreSQL becomes the single source of truth from Phase 2 onward. The legacy PHP app runs against PostgreSQL during the transition.
2. **Application conversion (Custom PHP MVC → Laravel 11).** All controllers, models, services, and views are rebuilt on Laravel. Eloquent ORM, Laravel migrations, queues, Sanctum auth.
3. **Keep the existing UI exactly as-is.** The current Bootstrap 5 + jQuery 3.6 + Select2 + SweetAlert2 interface is modern, familiar to users, and good. We do **not** rewrite it as an SPA. Laravel Blade views will reproduce the existing markup, assets, and JS files verbatim. Users should not be able to tell the difference visually.
4. **Re-derive business logic correctly — do not copy-paste.** Stock counting, moving-average cost, double-entry journal posting, ledger balancing, reconciliation, period close, intercompany settlement — these are **re-derived from accounting/inventory first principles** in Laravel, then **verified against legacy output in shadow mode** before going live. Blind line-by-line ports are forbidden for financial logic.

### Explicit removal: Telegram login + OTP/2FA

The following are **removed completely** and will not exist in the Laravel target:

- **TOTP 2FA on login** (Google Authenticator codes) — removed.
- **`PendingLogin` intermediate 2FA state** — removed.
- **Telegram-based login notifications / login alerts** — removed.
- **`verify_2fa` view and route** — removed.
- **`users.totp_secret`, `users.totp_enabled` columns** — dropped.

The simplified login flow is: **username + password → rate-limit → account-lockout → credential-version check → session**. (Optional "remember me" via selector/validator cookie is retained.)

> **Note:** Telegram **business alerts** (sales alerts, reconciliation alerts, accounting alerts in `app/services/Notification/*TelegramNotifier.php`) are **kept** — they are not part of login and are valuable. Only Telegram-as-a-login-mechanism and TOTP-2FA are removed.

> **Note:** `InvestigationMode` currently uses QR-activation + email-OTP-deactivation. The email-OTP deactivation is **not** a login OTP, but in the spirit of the user's request, Investigation Mode will be simplified to an **admin-toggled boolean** with no QR and no email OTP. See Phase 11.

---

## 1. Guiding Principles (applies to every phase)

| # | Principle | What it means in practice |
|---|---|---|
| P1 | **Correctness over speed** | A phase is not "done" when code compiles. It is done when shadow-mode reconciliation shows zero divergence from legacy for 7 consecutive days on production data. |
| P2 | **Incremental, ship-as-you-go** | Every phase produces a deployable state. No long-lived feature branches. Legacy + Laravel coexist behind one gateway. |
| P3 | **Re-derive, don't copy-paste** | Financial/inventory logic is rewritten from documented accounting rules (double-entry, moving-average cost, FIFO intercompany). Legacy PHP is a **reference**, not a source. |
| P4 | **Shadow mode is mandatory** | Every transactional module runs in shadow mode (writes to both legacy and Laravel, compares output) before cutover. See §3. |
| P5 | **Keep the UI** | Blade views copy existing PHP view markup + reuse existing `public/assets/` JS/CSS. No SPA, no design system change. |
| P6 | **Database is the contract** | PostgreSQL schema (with FKs, CHECKs, generated columns, triggers) enforces invariants. Application code is defense-in-depth, not the only guard. |
| P7 | **No silent data loss** | Every ETL step produces a row-count + checksum report. Divergence blocks cutover. |
| P8 | **Rollback always possible** | Until Phase 12 cutover, the legacy app remains the system of record. Any Laravel module can be disabled via Nginx routing without data loss. |

---

## 2. Phase Overview

| Phase | Name | Duration | Risk | Shippable? |
|---|---|---|---|---|
| 0 | Pre-Migration Security Cleanup | 1 week | Low | Yes (hotfix on current prod) |
| 1 | VPS BDIX Provisioning | 1 week | Low | Infra only |
| 2 | Database Migration to PostgreSQL | 3–4 weeks | Medium | Yes (legacy runs on PG) |
| 3 | Laravel Foundation + Auth | 2–3 weeks | Medium | Yes (auth + routing) |
| 4 | Master Data Modules | 3–4 weeks | Low | Yes (per sub-module) |
| 5 | Reporting Layer | 2 weeks | Low | Yes |
| 6 | Inventory Module | 3–4 weeks | **High** | Yes (per sub-module) |
| 7 | Purchase Module | 2–3 weeks | Medium | Yes (per sub-module) |
| 8 | Sales Module | 4–5 weeks | **High** | Yes (per sub-module) |
| 9 | Accounting Engine | 5–6 weeks | **Critical** | Yes (per sub-module) |
| 10 | Notifications (Alerts only) | 1 week | Low | Yes |
| 11 | Investigation Mode (simplified) | 0.5 week | Low | Yes |
| 12 | Cutover & Decommission | 1–2 weeks | **Critical** | Final go-live |
| 13 | AI Sidecar (post-go-live) | Ongoing | Low | Incremental |

**Total:** ~7–9 months to full cutover (Phase 12). AI features (Phase 13) are post-go-live.

---

## 3. Cross-Cutting: Correctness Verification Framework

This framework is referenced by every transactional phase (6, 7, 8, 9). It is the heart of principle P1/P3/P4.

### 3.1 Shadow Mode

For each module being ported, the Laravel version runs in **shadow mode** for a minimum of 7 calendar days before cutover:

- **Read shadow:** Laravel computes the same operation as legacy (e.g. "compute avg cost for product X in warehouse Y") and the result is compared to legacy output. Logged to `shadow_diffs` table.
- **Write shadow:** On a transaction (e.g. new sales invoice), legacy writes first (system of record), then Laravel replays the same input and writes to a **parallel shadow schema** (`shadow_*` tables). A reconciliation job compares the two writes field-by-field and line-by-line.
- **Cutover gate:** Shadow mode must produce **zero diffs** for 7 consecutive days AND pass a signed-off reconciliation report before the module is cut over (Laravel becomes system of record, legacy read-only).

### 3.2 Reconciliation Gates (per module)

Each transactional module has a **reconciliation gate** — a set of invariant checks that must balance:

| Module | Invariants to verify |
|---|---|
| Inventory | Σ `stock_transactions.qty` per (product, warehouse) == `warehouse_stock.qty`; `warehouse_stock.avg_cost` recomputed from scratch matches stored value; Σ stock value = Σ (qty × avg_cost) |
| Sales | Every `sales_invoice` (non-draft) has exactly 1 GL journal entry with Dr=Cr; AR sub-ledger balance == GL AR control account balance; COGS + Inventory reversal nets to zero per invoice |
| Purchase | Every `purchase_receive` has GL journal (Dr Inventory / Cr AP); AP sub-ledger == GL AP control; PO `received_qty` == Σ GRN received - returns |
| Accounting | Trial balance debits == credits; Balance Sheet A = L + E; Cash Flow indirect method plugs to Δ GL cash; AR aging total == GL AR; AP aging total == GL AP; bank ledger balances == GL cash_bank per branch |
| Intercompany | Σ `branch_ledger` Due-from-Branches == Σ Due-to-Branches across all branches (zero-sum) |

### 3.3 Test Fixtures

Before any module ports, a **golden dataset** is built:
- 50 products across 5 categories, 20 customers, 10 suppliers, 4 branches, 6 warehouses
- 200 sales invoices (mix of cash/credit, returns, partial payments)
- 100 purchase receives with returns
- 50 stock adjustments, 10 stock takes, 20 warehouse transfers, 15 damages
- 6 months of journal entries
- Expected outputs computed **manually by the accountant** (not by the legacy system) for: trial balance, P&L, balance sheet, cash flow, AR aging, AP aging, stock valuation

These expected outputs are the **acceptance criteria**. Laravel must reproduce them to the cent.

### 3.4 Sign-off Protocol

Every module cutover requires a 3-way sign-off:
1. **Lead developer** — code review + test pass
2. **Accountant / domain owner** — reconciliation report reviewed and approved
3. **Project owner** — shadow-mode 7-day zero-diff confirmation

---

## Phase 0 — Pre-Migration Security Cleanup
**Duration:** 1 week | **Risk:** Low | **Done on:** current Bluehost production (hotfix)

### Goal
Stop the bleeding from leaked secrets and known security gaps **before** touching anything else. These are independent of the migration and should ship to production immediately.

### Tasks
1. **Rotate leaked credentials** (all present in committed `config/local.php`):
   - Telegram bot token — revoke in BotFather, issue new token, store in new secret store (not committed).
   - FCM server key (legacy) — revoke, replace with Firebase Admin SDK service-account JSON.
   - FCM VAPID key pair — regenerate in Firebase console.
   - `INVESTIGATION_QR_SECRET` — will be removed entirely in Phase 11; for now set to a 32-byte random value.
   - Set `INVESTIGATION_SHOW_OTP_ON_FAIL=false`.
2. **Scrub git history** with `git filter-repo`:
   - Remove `config/local.php`, `osudlagb_remotecenter.sql`, `logs/*.log`, `tmp_*.txt` from all 4 commits.
   - Force-push (coordinate — this rewrites history; anyone with a clone must re-clone).
3. **Reset all user passwords** in production. The 3 known bcrypt hashes for users `123`, `222`, `333` are public. Force password reset on next login.
4. **Enforce username policy:** minimum 4 characters, alphanumeric, in `UserModel::create()`.
5. **Add `validateCSRF()` to the 16 controllers missing it:** `ReportController`, `StockTakeController`, `PurchaseOrderController`, `PurchaseReturnController`, `PurchaseReceiveController`, `DamageController`, `StockAdjustmentController`, `WarehouseTransferController`, `BranchDemandController`, `ReconciliationController`, `SalesAuditController`, `PurchaseAuditController`, `DashboardController`, `AccountingController`, `AccountingPeriodController`, `PaymentController`.
6. **Fix `core/Database.php`** — remove hard-coded `localhost/root/''/osudlagb_remotecenter`; read from `config.php` constants as already intended.
7. **Remove TOTP 2FA + PendingLogin + verify_2fa (initial cut, on legacy):**
   - Delete `core/Totp.php`, `core/TwoFactorAuth.php`, `core/PendingLogin.php`, `app/views/auth/verify_2fa.php`.
   - In `AuthController.php`: remove `require_once` for the 3 deleted files; remove the `if (!empty($user['totp_enabled']))` branch in `login()`; remove `verify_2fa()` method and `showVerify2faForm()` helper; remove `PendingLogin::isActive()` check in constructor; remove `PendingLogin::clear()` in `logout()`.
   - Drop `users.totp_secret` and `users.totp_enabled` columns (migration `030_auth_phase5_features.sql` added them).
   - Remove the "Two-Factor Authentication" section from `app/views/user/two_factor.php` and the `user/two_factor` route.
8. **Remove Telegram login notifications** (if any): grep `AuthController` and `LoginAudit` for Telegram usage on login events; remove. Keep `core/Telegram.php` for business alerts.

### Deliverables
- New git history without secrets.
- New production credentials stored in a secret manager (or at minimum a non-committed `config/local.php` with `chmod 600`).
- Patched legacy app deployed to Bluehost with CSRF + DB-credentials fix + 2FA removed.

### Acceptance Criteria
- `git log --all -p | grep -i "bot_token\|FCM_SERVER_KEY\|VAPID\|1234567890"` returns nothing.
- Login works with username+password only; no 2FA prompt.
- All 16 patched controllers reject POST without CSRF token.

### Risks
- Git history rewrite requires all clones to re-clone. Communicate to all devs.

---

## Phase 1 — VPS BDIX Provisioning
**Duration:** 1 week | **Risk:** Low

### Goal
Stand up the target VPS with the full target stack, hardened, with backups — ready to receive PostgreSQL and Laravel.

### Tasks
1. **Provision VPS** (BDIX provider): minimum 4 vCPU, 8 GB RAM, 100 GB SSD, Ubuntu 22.04 LTS.
2. **Base hardening:**
   - Non-root sudo user, SSH key-only auth, disable password login.
   - `ufw` firewall: allow 22, 80, 443 only.
   - `fail2ban` for SSH.
   - Automatic security updates (`unattended-upgrades`).
3. **Install stack:**
   - PHP 8.3-fpm + extensions (pdo-pgsql, bcmath, gd, intl, mbstring, opcache, redis, zip).
   - PostgreSQL 16.
   - Redis 7.
   - Nginx 1.22.
   - Supervisor (for Laravel Horizon queue workers).
   - Certbot (Let's Encrypt) for TLS.
4. **PostgreSQL configuration:**
   - Separate role `rcerp_app` with limited privileges (no superuser).
   - Database `rcerp`.
   - `pg_hba.conf`: scram-sha-256, local + VPS-only.
   - `postgresql.conf`: tune `shared_buffers` (25% RAM), `effective_cache_size` (75% RAM), `work_mem` (16MB), `maintenance_work_mem` (256MB), `max_connections` (100).
5. **Backup strategy:**
   - `pg_dump` cron: full backup nightly at 02:00 Asia/Dhaka, retained 30 days.
   - WAL archiving (or `pgbackrest`) for point-in-time recovery.
   - Weekly backup restore test on a staging DB.
6. **Staging environment:** replicate the stack on a second smaller VPS (or same VPS, different DB `rcerp_staging`) for shadow-mode testing.

### Deliverables
- Live VPS accessible via HTTPS, PostgreSQL + Redis running, Laravel-ready.
- Backup cron verified (perform one restore test).

### Acceptance Criteria
- `psql -U rcerp_app -d rcerp -c "SELECT version();"` returns PostgreSQL 16.x.
- `redis-cli ping` returns PONG.
- Nightly backup file exists and a restore to `rcerp_test` succeeds.

---

## Phase 2 — Database Migration to PostgreSQL
**Duration:** 3–4 weeks | **Risk:** Medium

This is the largest single infrastructure change. Split into 5 sub-phases.

### Phase 2.1 — Schema Mapping Document
**Duration:** 3–4 days

#### Goal
Produce a written, reviewed mapping from every MySQL object to its PostgreSQL equivalent. No conversion happens until this document is signed off.

#### Tasks
1. For each of the 66 tables, document:
   - MySQL DDL excerpt → PostgreSQL DDL.
   - Type mapping (see appendix §A1).
   - FK additions (legacy sales/customer/supplier tables currently have none — decide which to add; some orphans may exist in data and must be cleaned first).
   - Index additions (at minimum: `sales_invoices.customer_id`, `sales_invoices.invoice_date`, `customer_ledger.customer_id`).
2. Decide ENUM strategy: **`VARCHAR(50) + CHECK (col IN (...))`** for extensibility (recommended over `CREATE TYPE` because adding values doesn't require a migration type alteration).
3. Document the 2 triggers (`warehouse_stock` non-negative) → PostgreSQL `CHECK (qty >= 0)` constraint + a `BEFORE INSERT OR UPDATE` trigger that raises an exception with a meaningful message (CHECK gives generic message; trigger gives business message).
4. Document the 1 view (`v_journal_entries_with_lines`) → identical PostgreSQL view.
5. Document the 2 generated columns (`purchase_order_items.amount`, `stock_transactions.total_value`) → PostgreSQL `GENERATED ALWAYS AS (...) STORED` (identical syntax, PG 12+).
6. Document MySQL-specific cleanups:
   - `'0000-00-00 00:00:00'` → `NULL` during ETL.
   - `banks.balance FLOAT(20,2)` → `numeric(18,2)`.
   - `banks.updated_at INT(11) YYYYMMDD` → `date`.
   - `sales_invoices.updated_at DATE` → `timestamp(0)`.
   - Collation mismatch (`general_ci` vs `unicode_ci`) → single UTF-8.
   - `int(11)` display widths → drop (PG ignores anyway).
   - `tinyint(1)` → `boolean`.
7. Define the **migration tracking** strategy: Laravel migrations become the source of truth. Production's empty `schema_migrations` table is replaced by Laravel's `migrations` table. Baseline = the full converted schema as a single initial migration.

#### Deliverables
- `docs/migration/schema_mapping.md` — the signed-off mapping document.

#### Acceptance Criteria
- Mapping reviewed by lead dev + DBA. Every table accounted for.

---

### Phase 2.2 — PostgreSQL Schema Creation (DDL)
**Duration:** 4–5 days

#### Goal
Create the empty PostgreSQL schema from the mapping document. This becomes the **baseline Laravel migration**.

#### Tasks
1. Create a fresh Laravel 11 project at `/var/www/rcerp/laravel` (this is the start of Phase 3 work too, but the schema migration is done here first).
2. Write Laravel migrations producing the full schema:
   - Group logically: `0001_auth_core.php`, `0002_master_data.php`, `0003_accounting_core.php`, `0004_sales.php`, `0005_purchase.php`, `0006_stock.php`, `0007_payments.php`, `0008_notifications.php`, `0009_branch_demand.php`, `0010_investigation.php`, `0011_accounting_periods.php`, `0012_manual_journals.php`, `0013_product_groups.php`.
   - Every FK as a proper `$table->foreign('...')->references('id')->on('...')` with explicit `onDelete` (CASCADE for line-items, RESTRICT for master-data references).
   - Every money column as `numeric(15,2)` (or `numeric(18,2)` for bank balances).
   - Every quantity column as `numeric(14,4)` (standardize — legacy mixes `decimal(10,2)` and `decimal(12,4)`).
   - CHECK constraints for ENUMs, non-negative quantities where applicable.
   - Generated columns for `purchase_order_items.amount` and `stock_transactions.total_value`.
   - The `warehouse_stock` non-negative trigger as a PG function + trigger.
   - The `v_journal_entries_with_lines` view.
3. Add the missing indexes identified in Phase 2.1.
4. Add `updated_at timestamp NOT NULL DEFAULT now()` with a trigger to replicate MySQL `ON UPDATE CURRENT_TIMESTAMP` (Laravel's `$table->timestamps()` handles this at the ORM level, but DB-level trigger is belt-and-suspenders).

#### Deliverables
- Laravel migrations in `database/migrations/`.
- `php artisan migrate` produces the full schema on a clean PG database.

#### Acceptance Criteria
- `php artisan migrate:fresh` on staging PG succeeds.
- Schema diff vs mapping document: zero discrepancies.
- All FK constraints valid (`\d+ <table>` in psql shows them).

---

### Phase 2.3 — Data ETL (MySQL → PostgreSQL)
**Duration:** 5–7 days

#### Goal
Migrate all production data from MySQL to PostgreSQL with zero loss and full traceability.

#### Tasks
1. **Use `pgloader`** for the bulk load (handles type conversion, `0000-00-00` → NULL, charset).
   - Config file `docs/migration/pgloader.load` listing all 66 tables with column-type overrides.
   - Run on a full copy of production (take a `mysqldump` first, restore to a staging MySQL, run pgloader from there to avoid prod load).
2. **Post-load fixes** (custom SQL scripts, each idempotent):
   - `banks.balance` float→numeric: recompute from `money_transfers` + `customer_payments` + `supplier_payments` + `other_incomes` + `other_expenses` to verify (do not trust float value). Log deltas > 0.01.
   - `banks.updated_at` int YYYYMMDD → date: `UPDATE banks SET updated_at = to_date(updated_at_old::text, 'YYYYMMDD')`.
   - `sales_invoices.updated_at` date → timestamp: cast.
   - Drop `users.totp_secret`, `users.totp_enabled` (already done in Phase 0 on legacy; PG schema doesn't have them).
   - Reconcile `schema_migrations` (legacy empty) — populate Laravel's `migrations` table with the baseline migration row.
3. **Sequence synchronization:** for every table with `GENERATED ALWAYS AS IDENTITY`, set the sequence to `MAX(id)`:
   ```sql
   SELECT setval(pg_get_serial_sequence('customers','id'), (SELECT MAX(id) FROM customers));
   ```
   Script loops over all 40 identity tables.
4. **Row-count + checksum report:** for each table, log `MySQL rowcount`, `PG rowcount`, `MD5 of sorted concat(all columns)`. Any mismatch blocks Phase 2.4.

#### Deliverables
- `docs/migration/etl_report.md` with per-table row counts and checksums.
- Populated PostgreSQL `rcerp` database on staging.

#### Acceptance Criteria
- All 66 tables: MySQL rowcount == PG rowcount.
- All money totals (Σ `sales_invoice_items.qty * rate`, Σ `journal_lines.amount`, Σ `warehouse_stock.qty * avg_cost`) match between MySQL and PG to the cent.
- Accountant signs off on a sample of 20 customer balances, 10 supplier balances, 5 product stock values: legacy == PG.

#### Risks
- `pgloader` may choke on Bangla text in `customers.customer_name` if MySQL connection charset is wrong. Force `utf8mb4` on the MySQL source DSN.
- Float→numeric on `banks.balance` may surface as rounding deltas. The recomputation in step 2 is the authoritative fix.

---

### Phase 2.4 — Legacy PHP Running Against PostgreSQL
**Duration:** 5–7 days

#### Goal
Switch the legacy PHP app to connect to PostgreSQL instead of MySQL. Validate every screen works.

#### Tasks
1. Install `pdo_pgsql` extension on Bluehost (or, more likely, do this on the new VPS — run legacy PHP on VPS against PG). Decision: **move legacy app to VPS at this point** (Phase 1 VPS is ready; running legacy on VPS+PG gives you BDIX speed immediately as a bonus win).
2. Audit all raw SQL in `app/models/*.php` for MySQL-isms:
   - Backticks → double-quotes or remove.
   - `LIMIT offset, count` → `LIMIT count OFFSET offset`.
   - `NOW()` works in both.
   - `IFNULL` → `COALESCE`.
   - `IF(...)` → `CASE WHEN ... THEN ... ELSE ... END`.
   - `DATE_FORMAT` → `to_char`.
   - `STR_TO_DATE` → `to_date`.
   - `GROUP_CONCAT` → `string_agg`.
   - `UNIX_TIMESTAMP` → `extract(epoch from ...)`.
   - `LIKE` collation behavior differences.
   - `ORDER BY` with `FIELD()` function → `CASE` or array position.
   - `AUTO_INCREMENT` → identity (handled in schema).
   - `SHOW COLUMNS FROM` (used in some helpers) → `information_schema.columns`.
3. Fix each occurrence. ~50–100 SQL patches expected.
4. Deploy legacy-on-PG to VPS. Run smoke tests on every module.
5. Run the 9 existing `database/tests/*_smoke.php` scripts — they must all pass on PG.

#### Deliverables
- Patched legacy PHP codebase (committed as `legacy-pg-compat` branch).
- Legacy app live on VPS+PG (Nginx → PHP-FPM → PG).

#### Acceptance Criteria
- All 218 views render without SQL errors.
- Accountant exercises 20 representative workflows (create invoice, receive payment, post manual journal, run trial balance, run P&L, stock adjustment, warehouse transfer, purchase receive, purchase return, sales return). All produce correct output.

#### Risks
- Hidden MySQL-isms in deeply nested report queries. Mitigation: the 18 reports get extra attention; run each one on both MySQL and PG, diff the output.

---

### Phase 2.5 — Phase 2 Sign-off
**Duration:** 1 day

- Reconciliation report (§3.2 for Accounting module, run on real data): Trial Balance balances, Balance Sheet balances, AR aging == GL AR, AP aging == GL AP, bank balances recomputed.
- Sign-off by lead dev + accountant + project owner.
- **At this point: production is running on VPS + PostgreSQL. Bluehost is decommissioned for the DB.** The legacy PHP app is still the application, but the DB modernization goal is achieved.

---

## Phase 3 — Laravel Foundation + Auth
**Duration:** 2–3 weeks | **Risk:** Medium

### Goal
Stand up the Laravel app alongside legacy, sharing the same PG database and session/cookie domain. Port the (simplified) auth system. Set up Nginx routing split.

### Phase 3.1 — Laravel Scaffold + Config
**Duration:** 2–3 days

#### Tasks
1. `composer create-project laravel/laravel rcerp-laravel` (already started in Phase 2.2).
2. Install packages:
   - `laravel/sanctum` (API tokens for future mobile/AI).
   - `laravel/horizon` (queue dashboard + supervisor config).
   - `predis/predis` or `phpredis` (Redis driver).
   - `barryvdh/laravel-debugbar` (dev only).
   - `larastan/larastan` (PHPStan for Laravel) — target level 6.
   - `laravel/pint` (code style).
3. Configure `.env`: DB, Redis, mail, queue=redis, session=redis, `APP_ENV=production`, `APP_DEBUG=false`.
4. Configure session cookie: **same name, same domain, same path** as legacy PHP (`rcerp_session`), so a user logged into legacy is also logged into Laravel and vice versa. **Critical:** the session serialization format differs (PHP native vs Laravel), so we use a **shared session bridge** — see Phase 3.2.
5. Set up Nginx:
   - `/admin/*` → Laravel `public/index.php`
   - `/api/*` → Laravel `public/index.php`
   - `/*` → legacy `public/index.php`
   - All static assets (`/assets/*`, `/uploads/*`) → shared `public/` dir used by both apps (Laravel's `public/` symlinks to legacy `public/assets/`).

#### Deliverables
- Laravel app reachable at `/admin/` returning a test page.
- Nginx config committed.

---

### Phase 3.2 — Shared Session Bridge
**Duration:** 3–4 days

#### Goal
Users log in once (via legacy or Laravel) and stay logged in across both. This is essential for incremental module porting — a user might create a sales invoice in legacy and view it in Laravel.

#### Tasks
1. **Decision:** Use a **custom session driver** in Laravel that reads/writes the legacy PHP `$_SESSION` format (file-based or, better, switch legacy to Redis sessions in Phase 0/1 and have Laravel read the same Redis keys).
   - Recommended: switch legacy PHP `session.save_handler = redis` (one-line `php.ini` change in Phase 1). Laravel reads the same Redis session store via a custom `SessionHandler`.
2. Implement `App\Session\LegacyRedisSessionHandler` implementing `SessionHandlerInterface`, reading the same Redis keys legacy writes (`PHPREDIS_SESSION:<id>`). Laravel's `Auth::loginUsingId($userId)` after a successful session check.
3. Middleware `App\Http\Middleware\SyncLegacySession`: on Laravel request, if a legacy session exists and Laravel isn't authed, log Laravel in. If Laravel authed and legacy session expired, refresh it.
4. Credential-version check: replicate `core/CredentialVersion.php` logic in Laravel middleware — if `users.credential_version` != session's stored version, log out.

#### Deliverables
- A user logs in via legacy, navigates to `/admin/` (Laravel), is recognized as logged in.
- Logout from either side logs out from both.
- Password change in Laravel invalidates legacy session.

#### Acceptance Criteria
- Cross-app session continuity verified for 5 user roles.

---

### Phase 3.3 — Auth System Port (Simplified, No 2FA/OTP)
**Duration:** 4–5 days

#### Goal
Port the auth system to Laravel with the simplified flow: username + password + rate-limit + lockout + credential-version + remember-me. **No TOTP, no PendingLogin, no verify_2fa, no Telegram login.**

#### Tasks
1. Eloquent `User` model mapped to existing `users` table (no schema change — `totp_*` columns already dropped in Phase 0).
2. Login flow (Laravel controller `AuthController@login`):
   - Rate-limit by IP + username (replicate `core/RateLimiter.php` logic, but use Laravel's `RateLimiter` facade + Redis).
   - Account lockout after 5 failures / 15 min (replicate `core/AccountLockout.php` — columns `failed_login_count`, `locked_until` on `users`).
   - bcrypt verify (Laravel's `Hash::check`).
   - Credential-version check.
   - Session regenerate (Laravel's `Auth::login()` + `session()->regenerate(true)`).
   - Remember-me via Laravel's built-in `Auth::loginUsingRecaller` (replicate selector:validator hashing from `core/RememberMe.php` — or adopt Laravel's native remember-me which is functionally equivalent; choose one, document).
   - Login audit: write to `user_audit_log` table (same schema as legacy) + file log.
3. Password policy: replicate `core/PasswordPolicy.php` — 8–128 chars, letter+number+special, **HIBP k-anonymity check** (call `https://api.pwnedpasswords.com/range/<prefix>`, compare suffixes). Wrap in Laravel `Rule`.
4. Password reset: replicate `core/PasswordReset.php` — SHA-256 hashed tokens, 1hr expiry, single-use, transaction-wrapped, clears lockout + revokes remember tokens + bumps credential_version.
5. Logout: clear session, revoke remember-me cookie, write audit log.
6. RBAC: replicate `app/config/roles.php` (10 roles) + `route_roles.php` matrix as Laravel middleware `role:<role>` + a `RouteAccess` policy. Replicate `MenuAccess` (menu ACL from `user_menu_permissions` table) as a Blade directive `@canMenu('menu_slug')`.
7. Routes:
   - `GET /login`, `POST /login`, `POST /logout`, `GET /forgot`, `POST /forgot`, `GET /reset/{token}`, `POST /reset`.
   - No `verify_2fa` route.

#### Deliverables
- Laravel auth fully functional.
- All 10 roles tested.
- Login audit entries appearing in `user_audit_log`.

#### Acceptance Criteria
- Login works with username+password only.
- 5 failed attempts lock account for 15 min.
- Password change invalidates other sessions.
- Remember-me works across browser restart.
- No reference to `totp`, `2fa`, `otp`, `telegram` anywhere in Laravel auth code (`grep -ri` returns nothing).

---

### Phase 3.4 — Phase 3 Sign-off
- Accountant + admin log in via Laravel, navigate to legacy, navigate back — session intact.
- Penetration test: run `wpscan`-style auth attack (or manual) — lockout fires, rate-limit fires.

---

## Phase 4 — Master Data Modules
**Duration:** 3–4 weeks | **Risk:** Low

Port the master-data CRUD modules. These are lowest risk — no financial logic, just CRUD + soft-delete + audit. Each sub-module is independently shippable.

### Common pattern for every master-data module
1. Eloquent model with soft-delete (`SoftDeletes` trait), fillable, casts (money → `decimal:2`, dates → `date`).
2. Resource controller (index, create, store, show, edit, update, destroy, restore, audit).
3. Form Request validation classes.
4. Blade views **copying the existing PHP view markup** — same HTML structure, same Bootstrap classes, same Select2/SweetAlert2 JS. Replace `<?= htmlspecialchars($x) ?>` with `{{ $x }}` (Blade auto-escapes). Replace `<?php foreach` with `@foreach`.
5. Audit log via Eloquent events (creating/updating/deleting → write to `*_audit` or `user_audit_log`).
6. Master-data audit helper (replicate `app/helpers/MasterDataAuditHelper.php`).

### Phase 4.1 — Products (groups, categories, price history)
**Duration:** 5 days
- Models: `Product`, `ProductCategory`, `ProductGroup`, `ProductPriceHistory`.
- Controllers + views: products/{index,create,edit,price_history,audit}, products/groups/{index,create,edit}, products/categories/{index,create,edit}.
- Image upload: replicate the secure upload (MIME sniff, random filename, 2MB limit, `.htaccess` exec block — in Laravel, use `Storage::disk('public')` with a validation rule).
- Price history: min/max/default + effective_from, with current-effective lookup.

### Phase 4.2 — Customers
**Duration:** 3 days
- Model: `Customer` (with credit_limit, soft-delete).
- Controllers + views: customer/{index,create,edit,show,audit}.
- Credit limit field carried over (enforcement happens in Sales Phase 8).

### Phase 4.3 — Suppliers
**Duration:** 2 days
- Mirror of customers.

### Phase 4.4 — Employees
**Duration:** 3 days
- Model: `Employee` with photo upload + linked-user sync (when an employee is linked to a user, sync name/photo).
- Controllers + views: employee/{index,create,edit,account,audit}.

### Phase 4.5 — Banks & Ledgers (Chart of Accounts)
**Duration:** 4 days
- `Bank` model with GL ledger mapping (each bank maps to a `ledgers` row of nature `cash_bank`).
- `Ledger` model — hierarchical chart of accounts (`parent_id`), `account_type`, `ledger_nature`, `is_control_account`, `control_account_type`.
- Validation: the 7 "critical natures" must resolve to exactly one active ledger (replicate the validation in `LedgerModel`).
- Controllers + views: bank/{index,create,edit,show,audit}, ledger/{index,create,edit,show,audit}.

### Phase 4.6 — Branches & Warehouses
**Duration:** 2 days
- `Branch` (with `branch_code`, intercompany control ledgers).
- `Warehouse` (belongs to branch).
- Controllers + views: branch/{index,create,edit,show,audit}, warehouse/{index,create,edit,show,audit}.

### Phase 4 Acceptance (every sub-module)
- CRUD works identically to legacy (same fields, same validations, same audit entries).
- Soft-delete + restore works.
- Audit page shows change history.
- Shadow mode N/A (no financial logic), but: each record created in Laravel must appear in legacy and vice versa (shared DB).

---

## Phase 5 — Reporting Layer
**Duration:** 2 weeks | **Risk:** Low (read-only)

### Goal
Rebuild the 18 reports on Laravel + PostgreSQL with materialized views for performance. Reports are read-only, so no shadow-write needed — only **shadow-read diff** against legacy.

### Phase 5.1 — Materialized Views for Financial Reports
**Duration:** 4 days
- Create PG materialized views for:
  - `mv_trial_balance` — opening/period/closing per ledger.
  - `mv_profit_and_loss` — revenue/COGS/expenses by period.
  - `mv_balance_sheet` — assets/liabilities/equity as-of date.
  - `mv_cash_flow_indirect` — indirect method with working-capital changes.
  - `mv_ar_aging` / `mv_ap_aging` — bucketed by 0–30/31–60/61–90/90+ days.
  - `mv_general_ledger` — per-ledger transaction listing.
- Refresh strategy: `REFRESH MATERIALIZED VIEW CONCURRENTLY` on cron every 5 min + on-demand after any posting.

### Phase 5.2 — Report Controllers Port
**Duration:** 5 days
- Port `ReportController` (1,387 LOC) and `app/helpers/ReportsCatalog.php`.
- Each report: Laravel route + controller + Blade view copying legacy markup.
- Date-range filters respect Investigation Mode clamping (Phase 11).
- Reports use the materialized views where available, fall back to direct queries for real-time.

### Phase 5.3 — Reconciliation Hub
**Duration:** 3 days
- Port `ReconciliationController` + `app/services/Accounting/ReconciliationService.php`.
- 6 sections: AR, AP, employee, cash/bank, inventory, COGS. Each ties sub-ledger to GL control with tolerance.
- Dashboard shows green/red per section.

### Phase 5 Acceptance
- Each of the 18 reports: run on legacy and Laravel with same date range, diff the output. Zero diffs on golden dataset.
- Reconciliation hub: all 6 sections green on golden dataset.
- Performance: P&L + Balance Sheet render < 1s on 1 year of data (legacy takes 5–10s on MySQL).

---

## Phase 6 — Inventory Module
**Duration:** 3–4 weeks | **Risk:** High

This module holds the **moving-average cost** logic — re-derive carefully (principle P3).

### Phase 6.1 — Stock Transactions (SSOT)
**Duration:** 4 days
- `StockTransaction` model — the immutable inventory ledger. Signed `qty` (negative = OUT). `rate` snapshotted at transaction time. `reference_type` ENUM (12 values) + `reference_id` polymorphic.
- Replicate `StockService` + `StockAvailabilityService`:
  - **Availability** = `warehouse_stock.qty` − Σ open sales dispatches (challans not yet finalized). Re-derive from first principles, do not copy.
  - `FOR UPDATE` row lock on `warehouse_stock` during availability check.

### Phase 6.2 — Warehouse Stock + Moving-Average Cost (RE-DERIVE)
**Duration:** 5 days

#### Goal
Re-derive the moving-average cost calculation from inventory accounting principles, verify against legacy, then ship.

#### The re-derived rule (document this in `docs/migration/avg_cost_rule.md`)
```
On IN (qty > 0):
  new_qty = old_qty + in_qty
  new_avg_cost = (old_qty * old_avg_cost + in_qty * in_rate) / new_qty
  (in_rate = purchase net rate after return/discount, OR transfer source avg cost,
   OR adjustment cost — depends on reference_type)

On OUT (qty < 0):
  new_qty = old_qty - out_qty
  avg_cost UNCHANGED (cost flows out at current average)
  total_value_removed = out_qty * old_avg_cost

On NEGATIVE qty (allowed only transiently for outbound before inbound):
  avg_cost UNCHANGED; warn but do not block (legacy allows it via trigger tolerance -0.0001)
```

#### Tasks
1. Implement `WarehouseStockService::applyTransaction(StockTransaction $tx)` implementing the above rule inside a DB transaction with `SELECT ... FOR UPDATE` on the `warehouse_stock` row.
2. **Replay test:** take every `stock_transaction` from production, sort by `(created_at, id)`, replay through the new service into a shadow `warehouse_stock_shadow` table. Compare to live `warehouse_stock` for every (warehouse_id, product_id). Zero diffs required.
3. **Drift detection:** if any product's computed avg_cost diverges from stored by > 0.0001, log to `avg_cost_drift` table with the transaction that caused it. Investigate each.
4. Non-negative guard: PG `CHECK (qty >= -0.0001)` (matches legacy tolerance) + trigger raising business message.

#### Acceptance Criteria
- Replay of 38,775 production stock transactions produces zero drift on 1,529 warehouse_stock rows.
- Accountant signs off on 10 sample products: manual avg-cost calculation matches.

---

### Phase 6.3 — Stock Adjustments
**Duration:** 3 days
- Stock adjustment (qty +/- with reason) posts a `stock_transaction` + GL journal (Dr/Cr Inventory vs Adjustment Gain/Loss ledger).
- Replicate `StockAdjustmentModel` + `StockGlAuditHelper`.
- Two-phase: create (draft) → confirm (posts). Cancel reverses.

### Phase 6.4 — Stock Take (Variance)
**Duration:** 4 days
- Session → count (per warehouse) → variance calculation → post (adjustment + GL).
- Replicate `StockTakeModel`, `StockTakeAuditModel`, `StockTakeVarianceReport`.
- Variance = counted_qty − system_qty. Posts an adjustment for the variance.

### Phase 6.5 — Warehouse Transfers (cross-branch intercompany)
**Duration:** 4 days
- Same-branch transfer: stock moves, **no GL**.
- Cross-branch transfer: stock moves + intercompany GL (Dr Inventory-to / Cr Inventory-from + Dr Due-from-Branch / Cr Due-to-Branch).
- Replicate `WarehouseTransferModel` + `InterbranchGlAuditHelper`.

### Phase 6.6 — Damages
**Duration:** 2 days
- Damage invoice posts stock OUT + GL (Dr Damage Loss / Cr Inventory).
- Replicate `DamageModel` + `DamageAuditModel`.

### Phase 6 Acceptance
- Each sub-module: 7-day shadow-write against legacy, zero diffs.
- Reconciliation gate: Σ stock_transactions.qty per (product, warehouse) == warehouse_stock.qty for ALL products (run as a scheduled job, alert on drift).

---

## Phase 7 — Purchase Module
**Duration:** 2–3 weeks | **Risk:** Medium

### Phase 7.1 — Purchase Orders
**Duration:** 3 days
- PO is a draft document — no stock, no GL.
- `PurchaseOrder` + `PurchaseOrderItem` (with `amount` generated column).
- Document sequence: `PO-YYYY-NNNNNN` atomic via `SELECT ... FOR UPDATE` on `document_sequences`.
- Replicate `PurchaseOrderModel`.

### Phase 7.2 — Purchase Receive (GRN)
**Duration:** 5 days
- GRN is the economic event: stock IN (avg-cost recalc) + GL (Dr Inventory / Cr AP) + supplier_ledger credit + PO `received_qty` update.
- Replicate `PurchaseReceiveModel` + `PurchaseGlAuditHelper`.
- Cancel reverses: stock OUT (at the avg-cost it came in at — re-derive this carefully), GL reversal, supplier_ledger debit, PO received_qty decrease.
- Return cap: `returnable_qty = received_qty - already_returned`.

### Phase 7.3 — Purchase Returns
**Duration:** 4 days
- Return posts stock OUT + GL (Dr AP / Cr Inventory) + supplier_ledger debit.
- Replicate `PurchaseReturnModel`.

### Phase 7 Acceptance
- Shadow-write 7 days, zero diffs.
- Reconciliation gate: AP sub-ledger == GL AP control; PO received_qty == Σ GRN − returns.

---

## Phase 8 — Sales Module
**Duration:** 4–5 weeks | **Risk:** High

### Phase 8.1 — Sales Cart Service
**Duration:** 4 days
- Cart = draft in `sales_draft_carts` (JSON items).
- Replicate `SalesCartService` + `SalesCartOperationsTrait`.
- Availability check on add-to-cart (call `StockAvailabilityService`).

### Phase 8.2 — Invoice Finalize (draft + soft-hold + GL AR/Revenue)
**Duration:** 5 days
- Finalize: create `sales_invoice` (status=draft) + items + GL journal (Dr AR / Cr Revenue per item).
- Soft-hold flag for drafts awaiting godown.
- Credit-limit enforcement: block if `customer_ledger.balance + invoice_total > customer.credit_limit`, with audit-logged override.
- Document sequence: `INV-YYYY-NNNNNN` atomic.
- Replicate `SalesInvoiceService` + `SalesGlAuditHelper`.

### Phase 8.3 — Challan (godown, stock OUT, COGS)
**Duration:** 5 days
- `prepareGodown`: mark invoice as ready for dispatch.
- `finalizeChallan`: stock OUT (avg-cost) + GL (Dr COGS / Cr Inventory per item) + create `sales_challan` with transport snapshot.
- Replicate `ChallanModel`.
- Cancel challan: reverse stock + GL.

### Phase 8.4 — Customer Payments
**Duration:** 4 days
- `recordCustomerPayment`: Dr Bank / Cr AR + `customer_payment` + `customer_ledger` debit + settlement against invoices (FIFO or manual allocation).
- Bank-mode payment triggers intercompany settlement (call `BranchIntercompanyService`).
- Document sequence: `PAY-YYYY-NNNNNN`.
- Replicate `SalesPaymentService` + `CustomerTransactionModel`.

### Phase 8.5 — Sales Returns (two-phase)
**Duration:** 5 days
- **Create** (no GL): stock IN at **original avg cost** (re-derive: look up the cost at the time of the original challan's stock OUT, not current avg cost — this is a critical correctness point). Create `sales_return` + items.
- **Confirm**: GL (Dr Sales Return / Cr AR) + (Dr Inventory / Cr COGS at original cost). `FOR UPDATE` idempotency on return status.
- Replicate `SalesReturnModel` (1,812 LOC — the largest model). This is where most re-derivation effort goes.
- Reverse: undoes a confirmed return.

### Phase 8 Acceptance
- Shadow-write 7 days, zero diffs on 200 sample invoices (mix of cash/credit/returns/partial payments).
- Reconciliation gate: AR sub-ledger == GL AR; every non-draft invoice has exactly 1 GL journal with Dr=Cr; COGS+Inventory reversal nets to zero per invoice.
- **Critical test:** create invoice → challan → return → re-verify avg_cost on the product == pre-invoice avg_cost (return at original cost must restore it).

---

## Phase 9 — Accounting Engine
**Duration:** 5–6 weeks | **Risk:** Critical

This is the crown jewel. Port last, when all sub-ledgers are already on Laravel and reconciled. Re-derive from double-entry first principles.

### Phase 9.1 — Chart of Accounts + Ledger Natures
**Duration:** 4 days
- `Ledger` model with hierarchy, natures, control accounts.
- Validate the 7 critical natures resolve to exactly 1 active ledger each (cash_bank, ar, ap, inventory, sales, cogs, retained_earnings — confirm exact list with accountant).
- Migration script: seed `ledgers` from current production (37 rows).

### Phase 9.2 — Journal Posting Service (RE-DERIVE)
**Duration:** 8 days

#### Goal
Re-derive `JournalPostingService` (1,979 LOC, ~40 methods) from double-entry bookkeeping first principles.

#### The re-derived core rule (document in `docs/migration/journal_posting_rules.md`)
```
1. Every journal entry has >= 2 lines.
2. SUM(debit) == SUM(credit) EXACTLY (enforced by CHECK constraint + service-level assertion).
3. Every line references a ledger_id that exists and is_active.
4. Posting date must fall within an open accounting period for the branch
   (accounting_periods.closed_through_date < posting_date).
5. Reversal: create a new entry with debits/credits swapped, mark original is_reversed=1,
   reversal_of_entry_id points back. Original is NEVER mutated.
6. Period-close: once a period is closed for a branch, no entry with posting_date <= closed_date
   can be created (enforced at service level + DB trigger as defense).
7. Reference: every entry has reference_type + reference_id linking to the source transaction
   (sales_invoice, purchase_receive, stock_adjustment, etc.) for traceability.
```

#### Tasks
1. Implement `JournalPostingService` in Laravel with the above rules.
2. Port each of the ~40 posting methods (one per `ledger_nature`), documenting the Dr/Cr for each:
   - `sales_invoice_finalize`: Dr AR / Cr Sales Revenue (per item, optionally per product group).
   - `sales_challan_finalize`: Dr COGS / Cr Inventory (per item at avg_cost).
   - `customer_payment`: Dr Bank / Cr AR.
   - `purchase_receive`: Dr Inventory / Cr AP.
   - `stock_adjustment_gain`: Dr Inventory / Cr Adjustment Gain.
   - `stock_adjustment_loss`: Dr Adjustment Loss / Cr Inventory.
   - `warehouse_transfer_cross_branch`: Dr Inventory (dest) / Cr Inventory (src) + Dr Due-from-Branch / Cr Due-to-Branch.
   - `damage`: Dr Damage Loss / Cr Inventory.
   - `manual_journal`: user-defined (subject to period + balance check).
   - `sales_return_confirm`: Dr Sales Return / Cr AR + Dr Inventory / Cr COGS (at original cost).
   - `purchase_return`: Dr AP / Cr Inventory.
   - `other_income`: Dr Bank / Cr Other Income.
   - `other_expense`: Dr Other Expense / Cr Bank.
   - `money_transfer_cash_to_cash`: no GL (within same cash ledger).
   - `money_transfer_cash_to_bank`: Dr Bank / Cr Cash.
   - `employee_payment`: Dr Salary Expense / Cr Bank + Employee Ledger.
   - `intercompany_settlement`: Dr Due-to-Branch / Cr Due-from-Branch.
   - (and the rest — enumerate in the posting rules doc)
3. **Replay test:** take every business transaction from production (521 invoices, 311 GRNs, 550 payments, all adjustments/transfers/damages), replay through Laravel `JournalPostingService` into shadow `journal_entries_shadow` + `journal_lines_shadow`. Compare to live `journal_entries` + `journal_lines` row-by-row. Zero diffs required.
4. Entry number generation: `JE-YYYY-NNNNNN` — re-derive atomically using `SELECT ... FOR UPDATE` on a `document_sequences` row (replace legacy's non-atomic `COUNT(*)+1` — this is a **bug fix**, not a copy).

#### Acceptance Criteria
- Replay produces zero diffs on all historical journal entries.
- Trial balance on shadow entries == trial balance on live entries.
- Accountant signs the posting rules document before any code is written.

---

### Phase 9.3 — Sub-Ledgers (customer / supplier / employee)
**Duration:** 3 days
- `CustomerLedger`, `SupplierLedger`, `EmployeeLedger` models — running balance per entity per branch.
- Every posting that touches AR/AP/Employee control also writes to the corresponding sub-ledger.
- Replicate the dual-write pattern (GL journal line + sub-ledger row in the same DB transaction).

### Phase 9.4 — Reversal Engine
**Duration:** 2 days
- `JournalReversalService::reverse($entryId, $reason)`: creates the swap entry, marks original, cascades to sub-ledgers.
- Verify reversals net to zero on TB.

### Phase 9.5 — Period Close + Year-End
**Duration:** 3 days
- `AccountingPeriodService::closeThrough($branchId, $date)`: pre-close gate (TB balanced + reconciliation green + backup on file) → set `accounting_periods.closed_through_date`.
- Reopen requires superadmin + audit log.
- Year-end: close income-statement ledgers to retained earnings, rollover balance-sheet.
- Replicate `YearEndChecklistService`.

### Phase 9.6 — Reconciliation (6 sections)
**Duration:** 4 days
- Port `ReconciliationService` (already started in Phase 5.3, finalize here).
- 6 sections: AR, AP, employee, cash/bank, inventory, COGS.
- Tolerance: configurable per section (default 0.01).
- Cron job: every hour, refresh reconciliation status, alert on red via Telegram (business alert, kept).

### Phase 9 Acceptance
- Replay: 7-day shadow-write of all new transactions, zero diffs vs legacy.
- Final reconciliation: all 6 sections green on full production data.
- Trial Balance, P&L, Balance Sheet, Cash Flow all balance and match legacy to the cent.
- **This is the gate for Phase 12 cutover.** Accountant + project owner sign.

---

## Phase 10 — Notifications (Alerts Only)
**Duration:** 1 week | **Risk:** Low

### Goal
Port the notification system. **No Telegram login, no OTP.** Keep Telegram for business alerts.

### Tasks
1. `Notification` model (in-app notifications table).
2. `FcmTokenService` + FCM push (port `public/firebase-messaging-sw.js` + `notification.js`).
3. `SalesTelegramNotifier` + `AccountingTelegramNotifier` + `TelegramNotificationService` (port `core/Telegram.php` + the 3 notifiers). Use the **new** bot token from Phase 0.
4. `safe()` wrapper: notification failures never break the parent DB transaction (replicate).
5. Alert types: 5 sales alerts, reconciliation cron alert, accounting alerts.

### Acceptance
- New sales invoice triggers Telegram alert to branch recipients.
- Reconciliation cron red alert fires.

---

## Phase 11 — Investigation Mode (Simplified)
**Duration:** 0.5 week | **Risk:** Low

### Goal
Replace the QR-activate + email-OTP-deactivate Investigation Mode with a simple **admin-toggled boolean**.

### Tasks
1. `investigation_mode` table (or a key-value `settings` table): single row `is_active (bool)`, `activated_by`, `activated_at`, `deactivated_by`, `deactivated_at`.
2. Superadmin-only toggle in `/admin/investigation`.
3. When active: report date ranges clamp to current fiscal year (Jul–Jun). Everything else works normally (same as legacy behavior — legacy's session-scoping was stubbed no-ops anyway).
4. Remove `core/InvestigationMode.php` QR/OTP logic. Remove `INVESTIGATION_QR_SECRET`, `INVESTIGATION_SHOW_OTP_ON_FAIL`, `INVESTIGATION_COMPANY_EMAIL` config.
5. Audit log on activate/deactivate.

### Acceptance
- Toggle works. Reports clamp to fiscal year when active.

---

## Phase 12 — Cutover & Decommission
**Duration:** 1–2 weeks | **Risk:** Critical

### Phase 12.1 — Final Data Sync & Dual-Run
**Duration:** 4 days
- Both legacy and Laravel have been running against the same PG DB throughout. No data sync needed — they share the DB.
- Final 7-day dual-run: all writes go to both (legacy is system of record, Laravel shadow-writes). Confirm zero diffs across all modules.

### Phase 12.2 — Cutover (per module, sequenced)
**Duration:** 3 days
- Flip Nginx routing: each module's routes move from legacy to Laravel one at a time.
- Order: master data → reports → inventory → purchase → sales → accounting.
- After each flip, 24h observation with rollback ready.

### Phase 12.3 — Legacy Read-Only
**Duration:** 2 days
- Legacy app set to read-only mode (all POST routes return 410 Gone).
- Keep accessible for 30 days as historical reference.

### Phase 12.4 — Decommission
**Duration:** 1 day
- After 30 days clean: archive legacy code, remove Nginx legacy routes, remove legacy PHP-FPM pool.
- Keep the `legacy_*` shadow tables for 90 more days, then drop.

### Acceptance
- 30 days post-cutover: zero incidents, zero rollback events.
- Accountant + project owner final sign-off.

---

## Phase 13 — AI Sidecar (Post Go-Live)
**Duration:** Ongoing | **Risk:** Low

### Goal
Add AI capabilities as a separate Python microservice, called by Laravel over HTTP. Laravel stays the system of record; AI is read-only + suggest-only.

### Tasks
1. **Python FastAPI service** in `mini-services/ai-service/` on its own port. Uses `z-ai-web-dev-sdk` or direct LLM API.
2. **Report chatbot:** "What were sales last week by branch?" → LLM with function-calling over a read-only PG connection. Returns natural language + table.
3. **Demand forecasting:** Prophet/XGBoost on `sales_invoices` + `stock_transactions` history. Weekly forecast per product per branch. Surfaces in `branch_demands` suggestion.
4. **Reconciliation auto-matching:** ML model to suggest invoice↔payment matches for unreconciled customer payments. Accountant approves.
5. **Invoice OCR:** mobile photo of supplier invoice → draft `purchase_receive`. Uses VLM skill.
6. **Anomaly detection:** flag unusual sales (below cost, sudden volume spikes) for review. Uses the existing audit trails.

### Acceptance
- Chatbot answers 20 sample queries correctly.
- Forecast MAPE < 25% on 3-month backtest.

---

## 4. Risk Register

| ID | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Moving-average cost re-derivation produces drift | Medium | Critical | Replay all 38,775 historical stock transactions; zero-drift gate before Phase 6.2 sign-off |
| R2 | Journal posting re-derivation produces unbalanced entries | Low | Critical | DB CHECK constraint (SUM(dr)=SUM(cr)) + service assertion + full historical replay |
| R3 | Sales return at original avg_cost is wrong | Medium | High | Explicit test: invoice→challan→return must restore product avg_cost to pre-invoice value |
| R4 | Shared session bridge breaks (user logged out crossing apps) | Medium | Medium | 5-role session-continuity test in Phase 3.2; fallback: re-login |
| R5 | pgloader corrupts Bangla text | Low | High | Force utf8mb4 on source DSN; checksum verification per table |
| R6 | banks.balance FLOAT→numeric introduces rounding | High | Medium | Recompute balance from transactions in ETL; do not trust float value |
| R7 | Hidden MySQL-ism in report queries breaks on PG | Medium | Medium | Run all 18 reports on both, diff outputs |
| R8 | Accountant unavailable for sign-off delays critical path | Medium | High | Engage accountant from Phase 0; book recurring weekly review |
| R9 | Legacy + Laravel write-skew during dual-run | Low | High | Both apps write to same DB in same transaction; shadow-write comparator alerts on diff |
| R10 | VPS BDIX provider reliability | Low | High | Nightly offsite backup to a second provider; DNS failover plan |
| R11 | Team unfamiliar with Laravel | Medium | Medium | 1-week Laravel training in Phase 1; pair-programming on Phase 3 |

---

## 5. Rollback Strategy

At every phase boundary, rollback is possible:

- **Phase 0–1:** Legacy on Bluehost untouched. Rollback = do nothing.
- **Phase 2:** Legacy still runs; if PG migration fails, point legacy back at MySQL (Bluehost DB still alive for 30 days post-VPS-move).
- **Phase 3–11:** Nginx routes per-module. A failing Laravel module is rolled back by removing its Nginx route — legacy serves it again within minutes. No data loss (shared DB).
- **Phase 12:** Legacy kept read-only for 30 days. Rollback = flip Nginx back. After 30 days clean, legacy decommissioned.

---

## 6. Appendix

### A1. MySQL → PostgreSQL Type Mapping (reference)

| MySQL | PostgreSQL | Notes |
|---|---|---|
| `int(11)` | `integer` | display width ignored |
| `tinyint(1)` | `boolean` | |
| `tinyint(4)` | `smallint` | |
| `bigint` | `bigint` | |
| `varchar(n)` | `varchar(n)` | |
| `text` / `longtext` | `text` | |
| `decimal(p,s)` | `numeric(p,s)` | identical semantics |
| `float(20,2)` | `numeric(18,2)` | **fix: never use float for money** |
| `date` | `date` | |
| `datetime` | `timestamp(0)` | without time zone |
| `timestamp` | `timestamp(0)` | |
| `int(11) YYYYMMDD` | `date` | **convert in ETL** |
| `enum(...)` | `varchar(50) + CHECK (col IN (...))` | extensible |
| `json` / `longtext CHECK json_valid` | `jsonb` | |
| `AUTO_INCREMENT` | `GENERATED ALWAYS AS IDENTITY` | |
| `ON UPDATE CURRENT_TIMESTAMP` | trigger | or rely on Laravel timestamps |
| `ENGINE=InnoDB` | (drop) | |
| `DEFAULT CHARSET=utf8mb4` | (drop; DB-wide UTF-8) | |
| backtick identifiers | double-quote or unquoted | |
| `0000-00-00 00:00:00` | `NULL` | **convert in ETL** |

### A2. Files to DELETE (Telegram login + OTP/2FA removal)

**Core files (delete entirely):**
- `core/Totp.php`
- `core/TwoFactorAuth.php`
- `core/PendingLogin.php`
- `app/views/auth/verify_2fa.php`
- `app/views/user/two_factor.php` (or remove just the 2FA section)

**DB columns to DROP (migration):**
- `users.totp_secret`
- `users.totp_enabled`
- Any `totp_*` columns added by migration `030_auth_phase5_features.sql`

**Code to remove from `app/controllers/AuthController.php`:**
- `require_once` lines for the 3 deleted core files
- Constructor check `if (PendingLogin::isActive()) { $this->redirect('auth/verify_2fa'); }`
- In `login()`: the `if (!empty($user['totp_enabled']))` branch that calls `PendingLogin::start()` and redirects to `verify_2fa`
- `verify_2fa()` method
- `showVerify2faForm()` helper
- `PendingLogin::clear()` calls in `logout()` and `resetPassword()`
- SELECT list: drop `u.totp_enabled` from the user query

**Code to remove from `core/InvestigationMode.php`:**
- QR activation logic (uses `INVESTIGATION_QR_SECRET`)
- Email-OTP deactivation logic (uses `INVESTIGATION_SHOW_OTP_ON_FAIL`, `INVESTIGATION_COMPANY_EMAIL`)
- Replace with simple admin-toggled boolean (Phase 11)

**Config keys to remove from `config/local.php`:**
- `INVESTIGATION_QR_SECRET`
- `INVESTIGATION_SHOW_OTP_ON_FAIL`
- `INVESTIGATION_COMPANY_EMAIL`
- Any Telegram-bot-for-login keys (if present)

**KEEP (these are business alerts, not login):**
- `core/Telegram.php` (the Telegram HTTP client)
- `app/services/Notification/TelegramNotificationService.php`
- `app/services/Notification/SalesTelegramNotifier.php`
- `app/services/Notification/AccountingTelegramNotifier.php`
- `TELEGRAM_BOT_TOKEN` config key (for business alerts — but rotated in Phase 0)

### A3. Files to KEEP (auth mechanisms retained)

- `core/Auth.php` → Laravel `Auth` + custom middleware
- `core/Session.php` → Laravel session + custom Redis handler
- `core/RateLimiter.php` → Laravel `RateLimiter` facade
- `core/AccountLockout.php` → Laravel logic in `AuthController`
- `core/PasswordPolicy.php` → Laravel `Rule`
- `core/PasswordReset.php` → Laravel password broker (custom)
- `core/RememberMe.php` → Laravel native remember-me (or custom if selector:validator format must match)
- `core/CredentialVersion.php` → Laravel middleware
- `core/LoginAudit.php` + `core/UserAudit.php` → Laravel listener on `Login`/`Logout` events
- `core/RoleRegistry.php` + `app/config/roles.php` + `app/config/route_roles.php` → Laravel middleware + policies
- `app/services/Security/RouteAccess.php` + `MenuAccess.php` → Laravel middleware + Blade directive

### A4. Business Logic to RE-DERIVE (not copy-paste) — checklist

- [ ] Moving-average cost (Phase 6.2) — document in `docs/migration/avg_cost_rule.md`
- [ ] Stock availability = physical − open dispatches (Phase 6.1)
- [ ] Sales return at ORIGINAL avg_cost (Phase 8.5) — critical correctness
- [ ] Journal posting Dr=Cr + period validation (Phase 9.2) — document in `docs/migration/journal_posting_rules.md`
- [ ] Reversal = swap Dr/Cr, mark original, never mutate (Phase 9.4)
- [ ] Intercompany FIFO settlement (Phase 9 + sales payment)
- [ ] Period-close pre-close gate (Phase 9.5)
- [ ] Reconciliation 6-section (Phase 9.6)
- [ ] Document sequence atomicity (replace legacy `COUNT(*)+1` and `random_int` suffixes with `SELECT ... FOR UPDATE`)
- [ ] Credit limit enforcement with audit-logged override (Phase 8.2)

Each item above must have:
1. A first-principles document.
2. A replay test against historical production data.
3. Accountant sign-off on the document before implementation.

### A5. Phase Sign-off Template

```
Phase: <number>
Sub-phase: <number>
Module: <name>

Lead developer: ______________  Date: ______
Accountant:      ______________  Date: ______
Project owner:   ______________  Date: ______

Reconciliation report attached: [ ] Yes
Shadow-mode 7-day zero-diff:    [ ] Yes
Test fixtures passing:          [ ] Yes

Notes / known issues:
_______________________________________________

Rollback plan if needed:
_______________________________________________
```

---

## 7. Quick-Reference: What Ships When

| After Phase | What the user sees | What's running |
|---|---|---|
| 0 | Same UI, login without 2FA | Legacy on Bluehost (patched) |
| 1 | Nothing visible | VPS provisioned |
| 2 | **Same UI, faster** (BDIX) | Legacy on VPS + PostgreSQL |
| 3 | Same UI; `/admin/` shows Laravel test page | Legacy + Laravel (auth) on shared PG |
| 4 | `/admin/` master data working in Laravel | Master data dual-write |
| 5 | `/admin/reports` in Laravel, faster | Reports on Laravel + materialized views |
| 6 | Inventory in Laravel | Inventory dual-write |
| 7 | Purchase in Laravel | Purchase dual-write |
| 8 | Sales in Laravel | Sales dual-write |
| 9 | Accounting in Laravel | Full dual-write, reconciliation green |
| 10–11 | Notifications + investigation toggle | All Laravel |
| 12 | Legacy gone | Laravel only |
| 13 | AI chatbot + forecasting | Laravel + Python AI sidecar |

---

*End of migration plan. This document is the single source of truth for the migration. Update version number on any change.*
