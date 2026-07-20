# Phase 2 — Database Migration to PostgreSQL (Complete)

**Date:** Phase 2 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)
**Sub-phases:** 2.1 Schema Mapping ✅ | 2.2 PG DDL ✅ | 2.3 ETL Scripts ✅ | 2.4 Legacy PHP PG-compat ✅ | 2.5 Sign-off (doc complete; VPS execution pending)

---

## What was done

### Phase 2.1 — Schema Mapping Document ✅
**File:** `docs/migration/schema_mapping.md`

A comprehensive mapping of all 66 tables + 1 view from MySQL to PostgreSQL, documenting:
- Every type conversion (int, decimal, float, enum, datetime, etc.)
- All 38 ENUM columns → VARCHAR + CHECK
- All 8 GENERATED columns (2 were missing in MySQL — now added)
- The `banks.balance` FLOAT → numeric(18,2) fix
- The `warehouse_stock` composite PK (no `id` column)
- All 7 `ON DUPLICATE KEY` → `ON CONFLICT` conversions with conflict targets
- All missing FKs (added post-ETL to avoid orphan-row blocking)
- The double-entry balanced-journal trigger (new DB-level invariant)

### Phase 2.2 — PostgreSQL Schema (DDL) ✅
**Files:** `laravel/database/sql/01-07_*.sql` + `laravel/database/migrations/2025_01_01_000001_create_rcerp_schema.php`

7 SQL files grouped by domain, loaded by a single Laravel baseline migration:
1. `01_auth_and_master.sql` — branches, employees, users (Phase 0: no totp columns), products, customers, suppliers, banks, warehouses, etc.
2. `02_accounting.sql` — ledgers, journal_entries, journal_lines (+ balanced-entry trigger), sub-ledgers, accounting_periods, manual_journals
3. `03_stock.sql` — stock_transactions (SSOT), warehouse_stock (composite PK + non-negative trigger), adjustments, stock_take, transfers, damages, branch_demands
4. `04_sales.sql` — invoices (+missing indexes), items (+amount generated), dispatches, challans, draft_carts, returns (+original_cost for return-at-original-cost)
5. `05_purchase.sql` — orders, receives, returns (+all generated amount columns)
6. `06_payment_and_misc.sql` — customer/supplier payments, money_transfers, other income/expense, employee_transactions, notifications, investigation_activators, login_rate_limits, user_audit_log
7. `07_views_triggers_constraints.sql` — v_journal_entries_with_lines view, updated_at triggers (40+ tables), missing FKs, report performance indexes

**Key DB-level invariants added (stronger than MySQL):**
- `enforce_balanced_journal_entry()` trigger: rejects unbalanced journal entries (debits ≠ credits)
- `prevent_negative_stock()` trigger: rejects negative warehouse_stock.qty
- `jl_balanced_check` + `jl_not_both_zero_check`: CHECK constraints on journal_lines
- `ws_qty_nonnegative`: CHECK on warehouse_stock

### Phase 2.3 — ETL Scripts ✅
**Files:** `laravel/database/etl/`

1. **`pgloader.load`** — pgloader config with:
   - `zero-dates-to-null` cast for datetime/date
   - `tinyint-to-boolean` cast
   - `float → numeric(18,2)` cast (banks.balance fix)
   - `enum → text` cast
   - `json → jsonb` cast
   - EXCLUDING COLUMNS for 8 GENERATED columns (PG computes them)
   - EXCLUDING `users.totp_secret`, `users.totp_enabled` (Phase 0 dropped)
   - `reset sequences` + `data only` + `truncate`

2. **`post_load_fixes.sql`** — idempotent fixes:
   - banks.balance recomputation + delta logging for accountant review
   - banks.updated_at INT(YYYYMMDD) → date conversion
   - sales_invoices.updated_at DATE → timestamp conversion
   - Zero-date cleanup (safety net)
   - Negative warehouse_stock detection (for Phase 6.2 replay)
   - ledgers.parent_id 0 → NULL
   - schema_migrations population

3. **`sync_sequences.sql`** — sets every IDENTITY sequence to `MAX(id)` (safety net after pgloader)

4. **`etl_verify.sql`** — verification report:
   - Row counts per table (for MySQL comparison)
   - Financial checksums (journal debits=credits, AR/AP sub-ledger totals, stock valuation)
   - Integrity checks (orphan journal lines, unbalanced entries, negative stock, null codes)

### Phase 2.4 — Legacy PHP PostgreSQL Compatibility ✅
**Files edited:** 59 models + 12 helpers + 5 core files + 6 service files + 3 model fixes = **85 PHP files**

**5 parallel subagents** (Tasks 4-A through 4-E) audited and fixed every SQL query string in the production codebase:

| Subagent | Scope | Files | Key Fixes |
|---|---|---|---|
| 4-A | Accounting + Reports | 26 | REGEXP→`~`, SUBSTRING-comma→FROM, CAST UNSIGNED→BIGINT, 2× SHOW TABLES |
| 4-B | Sales + Customers | 8 | GROUP_CONCAT→string_agg, DATE_FORMAT→to_char, CURDATE→CURRENT_DATE, SHOW COLUMNS→info_schema |
| 4-C | Purchase + Stock | 17 | CURDATE→CURRENT_DATE, DATE_SUB→interval arithmetic, IFNULL→COALESCE, backtick removal |
| 4-D | Master + Auth + Misc | 21 | ON DUPLICATE KEY→ON CONFLICT, LIMIT swap, SHOW COLUMNS/TABLES→info_schema, double-quote→single-quote |
| 4-E | Core + Services | 11 | ON DUPLICATE KEY→ON CONFLICT (5), DATE_ADD/SUB→interval (7), DATEDIFF→date subtraction |

**Final verification:** `grep -rE 'ON DUPLICATE KEY|SHOW TABLES|SHOW COLUMNS|CURDATE|DATE_SUB|DATE_ADD|DATEDIFF|DATE_FORMAT|IFNULL|GROUP_CONCAT|UNIX_TIMESTAMP|REGEXP' --include='*.php' core/ app/` → **0 matches** in production code.

**Deprecated scripts:** 15 MySQL-specific utility/test scripts in `database/scripts/` and `database/tests/` marked DEPRECATED (will be replaced by Laravel artisan commands + PHPUnit tests in Phase 3+).

### Phase 2.5 — Sign-off (document complete; VPS execution pending)

---

## What still needs to happen ON THE VPS

Phase 2 code/schema/ETL is **100% written**. The remaining steps require the VPS (Phase 1) to be provisioned:

1. **Provision VPS** (Phase 1) — Ubuntu 22.04 + PHP 8.3 + PostgreSQL 16 + Redis + Nginx
2. **Apply PG schema** — `php artisan migrate` (creates all 66 tables + triggers + view)
3. **Run ETL** — `pgloader database/etl/pgloader.load` (loads data from MySQL copy)
4. **Run post-load fixes** — `psql -f database/etl/post_load_fixes.sql`
5. **Sync sequences** — `psql -f database/etl/sync_sequences.sql`
6. **Verify** — `psql -f database/etl/etl_verify.sql` (check row counts + checksums)
7. **Switch legacy PHP to PG** — update `config/local.php` DB credentials to PostgreSQL DSN
8. **7-day dual-run** — legacy PHP on PG, verify all 218 views + 39 controllers work
9. **Accountant sign-off** — verify Trial Balance, P&L, Balance Sheet, AR/AP aging match

---

## Verification summary

| Check | Result |
|---|---|
| MySQL-isms in production PHP code (core/ + app/) | **0** (clean) |
| PG schema SQL files | 7 files, 66 tables + 1 view + 4 triggers + 42+ FKs |
| ETL scripts | 4 files (pgloader config + 3 SQL) |
| Laravel baseline migration | 1 file (loads all 7 SQL files) |
| Schema mapping doc | Complete (all 66 tables documented) |
| Deprecated MySQL scripts | 15 (marked DEPRECATED, will be replaced in Phase 3+) |
| Double-entry balanced-entry trigger | ✅ (new DB-level invariant) |
| Non-negative stock trigger | ✅ (ported from MySQL) |
| banks.balance FLOAT fix | ✅ (→ numeric(18,2)) |
| Missing FKs added | ✅ (7 FKs added post-ETL) |
| Missing indexes added | ✅ (sales_invoices.customer_id, invoice_date, etc.) |
| Phase 0 totp columns excluded | ✅ (not in PG schema, excluded from ETL) |

---

## Next phase

**Phase 3 — Laravel Foundation + Auth.** Scaffold the Laravel 11 application, implement the shared session bridge (legacy ↔ Laravel), port the simplified auth system (username+password, no 2FA/OTP), and set up Nginx routing split.
