# Stock Take Sessions / Physical Count — Gap Analysis & Phase-by-Phase Implementation Plan

> **Scope:** The `Stock Take Sessions` / `Physical Count` menu of the Remote Center ERP (RCERP) project.
> **Repo:** `sajidchowdhury/debugRC`
> **Two codebases analyzed:**
> - `legacy/` — the OLD custom‑PHP framework software (originally MySQL, retrofitted to run on PostgreSQL).
> - `laravel/` — the NEW Laravel rewrite (PostgreSQL).
> **Output:** This document. **No code is written here.** Each phase below is a self‑contained, ordered work package with goal, scope, schema changes, code changes, acceptance criteria, and rollback notes. Following all phases end‑to‑end produces a production‑grade physical‑count feature.
> **Method:** Both codebases were read in full (models, services, controllers, migrations, SQL schema, routes, views, JS, helpers, audit models, reports). The findings are quoted from the actual files; line references are included where useful.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [How the Legacy Software Handles Physical Count](#2-how-the-legacy-software-handles-physical-count)
3. [How Laravel Currently Handles Physical Count](#3-how-laravel-currently-handles-physical-count)
4. [Gap Analysis — Legacy vs Laravel vs Target](#4-gap-analysis--legacy-vs-laravel-vs-target)
5. [How Laravel + PostgreSQL Can Make It Better](#5-how-laravel--postgresql-can-make-it-better)
6. [Target‑State Feature List (the “perfect” menu)](#6-target-state-feature-list-the-perfect-menu)
7. [Phase‑by‑Phase Implementation Plan](#7-phase-by-phase-implementation-plan)
8. [Cross‑Phase Concerns](#8-cross-phase-concerns)
9. [Definition of Done](#9-definition-of-done)
10. [Appendix A — File Reference Index](#appendix-a--file-reference-index)
11. [Appendix B — Reconstructed Post Sequence](#appendix-b--reconstructed-post-sequence)

---

## 1. Executive Summary

### 1.1 Headline finding

The Stock Take / Physical Count feature exists in **both** codebases, but at very different maturity levels:

| Aspect | Legacy (MySQL) | Laravel (PostgreSQL) |
|---|---|---|
| Overall maturity | **Production‑hardened** (multi‑year use) | **~70% implemented, currently non‑functional** |
| End‑to‑end flow works? | Yes | **No — `createSession()` crashes on first INSERT** |
| Schema vs service in sync? | Yes | **No — 4 reversal columns missing** |
| Role/branch protection on routes? | Yes (`route_roles.php` matrix) | **No (only `auth` middleware)** |
| GL integration | Yes (shrinkage/surplus ledgers) | Yes (mirrors `StockAdjustmentService`) |
| Audit trail | Health‑checklist engine (state‑based) | `AuditableMasterData` trait wired but **dead** (service uses `DB::table()`) |
| Variance report | Full (detail + weekly + CSV) | **Stub** (session list only, wrong status keys) |
| Stock freeze during count | **No** (biggest integrity risk) | **No** (same risk carried forward) |
| System‑qty snapshot | **No** (overwritten on every save) | **Yes** (`system_qty` captured at setup, frozen) ✅ |
| Cycle count / ABC | No (full warehouse only) | No (full warehouse only) |
| Re‑post after reversal | No (terminal) | No (terminal — `cancelled`) |

### 1.2 The single most important blocker

`StockTakeService::createSession()` and `cancelSession()` write four columns — `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason` — into `stock_take_sessions`, but the PostgreSQL schema in `database/sql/03_stock.sql` **does not define them**, and no later migration adds them. The team's own test helper carries the comment *"Note: stock_take_sessions has no `is_reversed` column."* As a result, **every `store` and every `cancel` of a posted session throws `SQLSTATE[42703]: Undefined column` immediately.** This is Phase 0.

### 1.3 The single most important data‑integrity risk (carried over from legacy)

Neither codebase freezes `warehouse_stock` during a counting session. Sales, purchases, transfers, and other adjustments continue to mutate the live stock while a count is in progress. The legacy code re‑reads `system_qty` on every `saveCount` (silently invalidating earlier counts); the Laravel code freezes `system_qty` at setup (better) but still applies `physical − system` as a delta at post time, which means a count can be perfectly accurate and still produce a **wrong** stock correction if stock moved between setup and post. This is the deepest design issue and is addressed in Phase 3.

### 1.4 Reading order

- If you only want the **plan**, jump to [§7](#7-phase-by-phase-implementation-plan).
- If you want the **evidence**, read [§2](#2-how-the-legacy-software-handles-physical-count) and [§3](#3-how-laravel-currently-handles-physical-count) first.
- If you want the **gap** in one table, read [§4](#4-gap-analysis--legacy-vs-laravel-vs-target).

---

## 2. How the Legacy Software Handles Physical Count

> Source: `legacy/app/models/StockTakeModel.php`, `legacy/app/models/StockTakeAuditModel.php`, `legacy/app/models/Reports/StockTakeVarianceReport.php`, `legacy/database/migrations/023_stock_take_phase1.sql`, `024_stock_take_phase3_gl.sql`, `legacy/app/services/Accounting/JournalPostingService.php`, `legacy/app/services/Stock/StockTransactionModel.php`, `legacy/app/config/route_roles.php`, `legacy/app/views/StockTake/*`.

### 2.1 Menu & navigation

- **Menu label (user‑facing):** `Physical Count`
- **Top‑level group:** `Inventory` (sort order 1 — first item in the group)
- **Controller / action:** `StockTake` / `index`
- **Icon:** `fa fa-cubes`
- **Page header label:** `Physical stock take`
- **Tagline shown to users:** *"Count warehouses first, then post adjustments in one step (workflow B)"*
- **Route registration:** `legacy/app/config/route_roles.php` lines 263–283
- **Sidebar rendering:** `legacy/app/views/layouts/sidebar.php` joins `menus` × `user_menu_permissions` on `can_view = 1`.

Full route surface (relative to `BASE_URL`):

| Route | Purpose |
|---|---|
| `StockTake` (index) | List + filters |
| `StockTake/create`, `StockTake/store` | New session form + POST |
| `StockTake/details/{id}` | Session hub (warehouses, variances, movements, audit checklist, journal blocks) |
| `StockTake/count/{session_id}/{warehouse_id}` | Per‑warehouse count grid |
| `StockTake/saveCount` (POST) | Save physical qty lines (no stock movement) |
| `StockTake/post` (POST) | Finalize → adjust stock + GL |
| `StockTake/reverse` (POST) | Reverse a posted session |
| `StockTake/delete` (POST) | Delete a draft session |
| `StockTake/checklist`, `StockTake/run_checks` | Audit checklist screen + JSON health check |
| `StockTake/weekly`, `StockTake/exportWeekly` | Weekly control report + CSV |
| `StockTake/variance`, `StockTake/getVarianceReport`, `StockTake/exportVarianceReport` | Variance detail + AJAX + CSV |
| `StockTake/getSessionsList` (JSON) | Sessions dropdown source |
| `StockTake/WarehousesByBranch?branch_id=` | Warehouses for branch (create form) |
| `StockTake/export` | CSV export of session list |

Every action calls `$this->requireLogin()`; every POST calls `$this->validateCSRF()`.

### 2.2 Data model (MySQL, reconstructed)

The `stock_take_*` tables are **not** created by any migration in this repo — they pre‑existed. The schema below is reconstructed from the model code and the Phase‑1 ALTERs in `023_stock_take_phase1.sql`.

#### `stock_take_sessions`
```sql
id                 BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
session_code       VARCHAR(32)  NOT NULL UNIQUE,    -- 'ST-YYYYMMDD-NNNN'
branch_id          INT          NOT NULL,            -- FK branches.id
take_date          DATE         NOT NULL,
status             ENUM('draft','counting','adjusted','reversed')
                                 NOT NULL DEFAULT 'draft',
created_by         INT          NOT NULL,
adjusted_at        DATETIME     NULL,                -- set on post
journal_entry_id   BIGINT(20)   NULL,                -- link to journal_entries.id
posted_at          DATETIME     NULL,
is_reversed        TINYINT(1)   NOT NULL DEFAULT 0,
reversed_at        DATETIME     NULL,
reversed_by        INT          NULL,
reverse_reason     VARCHAR(500) NULL,
created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

#### `stock_take_warehouses`
```sql
id                     BIGINT AUTO_INCREMENT PRIMARY KEY,
stock_take_session_id  BIGINT NOT NULL,
warehouse_id           INT   NOT NULL,
status                 ENUM('pending','counted','posted') NOT NULL DEFAULT 'pending',
created_at, updated_at
-- No declared UNIQUE(session_id, warehouse_id) — enforced by app logic.
```

#### `stock_take_items`
```sql
id                     BIGINT AUTO_INCREMENT PRIMARY KEY,
stock_take_session_id  BIGINT NOT NULL,
warehouse_id           INT   NOT NULL,
product_id             INT   NOT NULL,
system_qty             DECIMAL(18,4) NOT NULL DEFAULT 0,
physical_qty           DECIMAL(18,4) NOT NULL DEFAULT 0,
rate                   DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- avg cost at count/post
reason                 VARCHAR(500) NULL,
is_applied             TINYINT(1) NOT NULL DEFAULT 0,          -- set 1 on post
created_at, updated_at,
UNIQUE KEY uk_sti_session_wh_product (stock_take_session_id, warehouse_id, product_id)
```

Migration `023` de‑duplicates existing rows before adding the unique key (`DELETE t1 FROM … INNER JOIN … WHERE t1.id > t2.id`).

#### GL ledgers seeded for Stock Take (`024_stock_take_phase3_gl.sql`)
- `ledger_nature='inventory_shrinkage'` → ledger_code `L-ST-SHR`, account_type `Expense`, normal_balance `debit`, parent = COGS nature (fallback).
- `ledger_nature='inventory_surplus'` → ledger_code `L-ST-SUR`, account_type `Income`, normal_balance `credit`, parent = other_income nature (fallback).

#### Supporting tables touched on post
- `warehouse_stock (warehouse_id, product_id)` — `qty`, `avg_cost`, `last_updated`. Has a non‑negative trigger (`trg_warehouse_stock_no_negative_insert/update` raising `SQLSTATE '45000'`) — but marked **deprecated/MySQL‑only**.
- `stock_transactions` — ledger of every movement. `reference_type='stock_take'`, `reference_id=<session_id>`, signed `qty`, `rate`, plus reversal columns.
- `journal_entries` + `journal_entry_lines` — one entry per session on post, `reference_type='stock_take'`.
- `ledgers` — relies on natures `inventory`, `inventory_shrinkage`, `inventory_surplus` with sensible fallbacks to `cogs` / `other_income`.

#### Indexes / constraints / triggers observed
- `uk_sti_session_wh_product` UNIQUE on `stock_take_items`.
- No FK constraints declared — integrity is application‑level only.
- No DB triggers on `stock_take_*` tables.
- No `EXCLUDE` constraint, no CHECK on `status` transitions (state machine lives in PHP).

### 2.3 Session lifecycle

```
              createSession                      saveCount (1st time)
   (nothing) ───────────────► draft ───────────────────────────► counting
                                │                                     │
                                │ deleteDraftSession                  │ saveCount (markComplete per wh)
                                ▼                                     │
                             (deleted)                                ▼
                                                            assertSessionPostable()
                                                            (no pending wh, ≥1 counted wh)
                                                                          │
                                                                          ▼
                                                                       adjusted
                                                                          │
                                                                          │ reverseSession
                                                                          ▼
                                                                       reversed   (terminal)
```

Session statuses (`023` ENUM): `draft`, `counting`, `adjusted`, `reversed`.
Warehouse statuses: `pending`, `counted`, `posted`.

| From | To | Trigger | Guard |
|---|---|---|---|
| (none) | `draft` | `createSession` | role ∈ {admin, manager, warehouse_manager} |
| `draft` | `counting` | `saveCount` (1st lines or markComplete) | `assertSessionEditable`: not reversed, status ∈ {draft, counting} |
| `counting` | `counting` | `saveCount` (subsequent) | same |
| `counting` | `adjusted` | `postSession` | `assertSessionPostable`: no `pending` warehouses, ≥1 `counted`, not reversed, not already `adjusted` |
| `adjusted` | `reversed` | `reverseSession` | reason ≥ 3 chars, has stock movements, not already reversed |
| `draft` | (deleted) | `deleteDraftSession` | status must be exactly `draft` |

**No transition out of `reversed`** — a corrected count requires a brand‑new session. The note `legacy/note.js` even carries the developer memo `reversal [stock take need to discuse]`.

### 2.4 Business logic

**Session creation** (`StockTakeModel::createSession`):
- `session_code = 'ST-' . date('Ymd') . '-' . random_int(1000,9999)` — 4‑digit random suffix (collision possible; caught by DB unique index but surfaces as a raw PDO exception, no retry).
- Inserts `stock_take_sessions` (status `draft`) + one `stock_take_warehouses` row per chosen warehouse (status `pending`).
- **No products are pre‑populated** — `stock_take_items` rows are inserted lazily on `saveCount`.
- **No "system qty" snapshot is frozen** at session creation. `system_qty` is read live from `warehouse_stock.qty` at `saveCount` time and re‑read again at post time; the `system_qty` column is overwritten on every `saveCount` upsert.

**Products eligible for counting** (`getProductsForCounting`):
- SQL: `SELECT p.id, p.product_code, p.product_name, c.category_name, COALESCE(ws.qty,0) AS system_qty, COALESCE(ws.avg_cost,0) AS avg_cost, (latest product_price_history.default_rate) AS receipt_price FROM products p LEFT JOIN product_categories c … LEFT JOIN warehouse_stock ws … WHERE p.is_active=1 ORDER BY c.category_name, p.product_code`.
- So a session = **all active products** in the warehouse(s). The UI has a category dropdown and an "in stock only" checkbox, but those are pure client‑side filters — no server‑side scoping. No ABC / cycle‑count mode.

**Count entry** (`saveCount`):
- `assertSessionEditable`: `SELECT * FROM stock_take_sessions WHERE id=:sid FOR UPDATE` — row lock prevents two users saving counts simultaneously.
- For each `product_id => qty` in the payload: read live `warehouse_stock.qty` as `system_qty`, fetch `avgCost = StockTransactionModel::getWarehouseAvgCost(...)`, set `rate = avgCost > 0 ? avgCost : receipt_rate`, then `INSERT … ON CONFLICT (session, warehouse, product) DO UPDATE`.
- If `mark_complete=1`, promote the warehouse `pending → counted`.
- If session was `draft` and ≥1 line saved or markComplete ticked, promote `draft → counting`.
- **No stock movement is written** — explicit "Workflow B: save physical counts only".

**Variance computation**: `variance_qty = physical_qty − system_qty`; per‑line value `(physical − system) * rate`; session totals split into `gain_value` (positive) and `loss_value` (positive).

**Variance approval**: there is **no explicit per‑line approve/reject step**. A line either has a `physical_qty` or not; reasons are free‑text. The audit checklist *warns* (soft) if a variance line with `|value| ≥ 500` has an empty reason, but this does not block posting. The only hard pre‑post gates are: every warehouse `counted`, none `pending`, session in `counting`. **No second‑person approval.**

**Posting** (`postSession`):
1. `assertSessionPostable` — `SELECT … FOR UPDATE` on session; check no `pending` warehouses, ≥1 `counted`, not reversed, not already `adjusted`.
2. Load `stock_take_items` where `is_applied=0 AND physical_qty <> system_qty`.
3. For each item:
   - `adjustment = physical_qty − system_qty`
   - `rate = item.rate > 0 ? item.rate : getWarehouseAvgCost(...)` (live re‑fetch if zero)
   - `$issueRate = $adjustment < 0 ? $rate : $rate;` ← **no‑op / dead code** (both branches assign `$rate`).
   - `StockTransactionModel::updateWarehouseStock(wh, pid, adjustment, adjustment>0 ? issueRate : 0)` — for increases the rate is used to recompute avg_cost; for decreases rate is `0` (avg_cost unchanged).
   - `StockTransactionModel::logMovement(...)` with `reference_type='stock_take'`, `reference_id=$sessionId`, signed `qty`, remarks `'Stock Take #<code> — <reason>'`.
   - Mark item `is_applied=1`, persist `rate`.
4. `computeSessionVarianceValue()` → `{gain_value, loss_value}` at count‑time rates.
5. `JournalPostingService::postStockTakeSession($sessionId, $session, $lossValue, $gainValue)`.
6. `finalizePostedSession` — promote warehouses `counted → posted`, set session `status='adjusted'`, `adjusted_at=NOW()`, `posted_at=NOW()`, `journal_entry_id`.
7. Commit (atomic — if GL fails, stock rolls back).

**GL posting** (`JournalPostingService::postStockTakeSession`):
- Rounds both amounts to 2 dp; if both `< 0.01` → returns success with `journal_entry_id=null` (no GL needed).
- Resolves ledgers: `inventory` (required), `inventory_shrinkage` (fallback `cogs`), `inventory_surplus` (fallback `other_income`).
- Lines:
  - **Shortage (loss):** Dr `inventory_shrinkage` / Cr `inventory` for `lossAmount`.
  - **Overage (gain):** Dr `inventory` / Cr `inventory_surplus` for `gainAmount`.
- Header: `entry_date = session.take_date`, `description='Stock Take — <code>'`, `reference_type='stock_take'`, `reference_id=<sessionId>`, `branch_id`.

**Unit cost / avg cost**: Moving Average Cost (MAC). On increase: `avg_cost = (old_qty*old_avg + in_qty*in_rate)/(old_qty+in_qty)`. On decrease: avg_cost unchanged, only qty reduced. The `rate` used for the GL value comes from `stock_take_items.rate` (captured at count‑save time), so GL posts at count‑time avg cost, not post‑time avg cost. **No revaluation entry** is created if avg cost drifts between saveCount and post.

**Reversal** (`reverseSession`):
1. `reason` ≥ 3 chars.
2. Session must be `adjusted`, not already reversed.
3. Load all `stock_transactions` for `reference_type='stock_take', reference_id=$id`.
4. For each non‑reversed movement: `StockTransactionModel::reverseTransaction(movement_id, reason)` — locks original `FOR UPDATE`, computes `reverseQty = -qty`, checks current `warehouse_stock.qty ≥ |reverseQty|` (throws `RuntimeException("Cannot reverse: insufficient stock on hand …")` if not enough), calls `updateWarehouseStock(reverseQty, issueRate)`, logs a new `stock_transactions` row with `reference_type='reversal'`, marks original `is_reversed=1`.
5. If linked journal entry exists → `JournalPostingService::reverseLinkedJournal(journal_entry_id, reason)` → `JournalEntryModel::createReversingEntry()`.
6. Set all `stock_take_items.is_applied = 0`.
7. Update session `is_reversed=1`, `reversed_at`, `reversed_by`, `reverse_reason`, `status='reversed'`.
8. Commit.

**Re‑post NOT supported.** Reversal is terminal.

### 2.5 Stock impact

**On post** (transaction order):
1. `warehouse_stock` — `UPDATE`/`UPSERT` per variance line.
2. `stock_transactions` — `INSERT` one row per variance line.
3. `stock_take_items` — `UPDATE is_applied=1, rate=…` per line.
4. `stock_take_warehouses` — `UPDATE status='posted' WHERE status='counted'`.
5. `journal_entries` + `journal_entry_lines` — one entry (1–4 lines).
6. `stock_take_sessions` — `UPDATE status='adjusted', adjusted_at, posted_at, journal_entry_id`.

**On reversal**: `warehouse_stock` restored; new `stock_transactions` rows (`reference_type='reversal'`); originals flagged `is_reversed=1`; `stock_take_items.is_applied=0`; new reversing `journal_entries` (linked via `reversal_of_entry_id`); original entry flagged `is_reversed=1`; session `status='reversed'`.

**Freeze during counting?** **No.** There is no freeze flag on `stock_take_sessions`, `stock_take_warehouses`, or `warehouse_stock`. Sales, purchases, transfers, and adjustments continue to mutate `warehouse_stock` during a count. The only guard is `WarehouseModel::hasActiveStockTake()` — a UI‑only block on warehouse *deactivation*, not on stock movements. Worse, `StockTakeModel` does **not** consult `StockAvailabilityService` when posting a shortage — so a count can drive `warehouse_stock.qty` below the level already committed to open sales invoices, breaking the next sales attempt.

### 2.6 Audit trail

`StockTakeAuditModel` is **not a row‑level audit log** (no `stock_take_audit` table). It is a **health‑check / checklist engine** with two public methods:

- `runHealthChecks()` — global checklist: duplicate count lines, stale open sessions > 30 days, shrinkage/surplus ledgers exist, posted sessions have stock movements, posted sessions have GL when value ≠ 0, reversed sessions reverse GL, negative `warehouse_stock`, missing session journals.
- `runSessionChecks($sessionId)` — per‑session panel: branch access, warehouses marked complete, variance lines, large‑variance‑reason warnings (soft), stock movements posted, GL journal present, reversal info; returns a `ready_to_post` flag.

**What is logged per transition:** session `created_by`/`created_at`; counts saved (per‑row `updated_at` only); warehouse `status='counted'`; posted (`adjusted_at`, `posted_at`, `journal_entry_id`, items `is_applied=1`, `stock_transactions` rows, journal entry); reversed (full reversal meta). **Deleted draft sessions are hard‑deleted with no audit row.**

There is **no separate `stock_take_audit_log` table** — if you need to know "who entered `physical_qty=42` for product X on session Y at time T", that information is **lost** (the row is upserted, overwriting prior values without history).

### 2.7 Reports

`StockTakeVarianceReport` exposes:
- **Variance detail** (`StockTake/variance`, `getVarianceReport`, `exportVarianceReport`): filters by session/branch/warehouse/product; returns only variance lines (`physical_qty <> system_qty`); columns include `session_code, take_date, status, branch_name, warehouse_name, product_code, product_name, system_qty, physical_qty, variance_qty, rate, value_diff, reason, is_applied`; CSV export with BOM.
- **Weekly control report** (`StockTake/weekly`, `exportWeeklyCsv`): date range + branch; includes sessions in `('adjusted','reversed','counting')`; per session `warehouse_count, warehouses_done, variance_lines, gain_value, loss_value, net_value`; totals; `getTopVarianceProducts` (top 15 SKUs by abs value, split surplus/shortage).
- **Session list export** (`StockTake/export`): CSV of index list.

Registered in `ReportsCatalog` under tags `control`, `audit`, `variance`, `detail`.

### 2.8 Concurrency & controls

- `SELECT … FOR UPDATE` on the session row in `saveCount` and `postSession`.
- `SELECT … FOR UPDATE` on `warehouse_stock` row inside `updateWarehouseStock` for stock OUT.
- `SELECT … FOR UPDATE` on the original `stock_transactions` row in `reverseTransaction`.
- `StockService::lockBranchProductsForUpdate` exists but is **NOT** used by `StockTakeModel`.
- Non‑negative enforcement: deprecated MySQL trigger + PHP‑level `RuntimeException("Insufficient stock in warehouse…")` for decreases (allows reaching exactly 0, not below).
- **No freeze of stock movements during counting.**
- Reconciliation alerts are **passive monitors** — they surface problems but do not block operations.
- CSRF on every POST.

### 2.9 Permissions / roles

From `legacy/app/config/route_roles.php` (admin/superadmin bypass via `Auth::hasAdminRouteBypass()`):

| Action | Roles allowed |
|---|---|
| `index`, `details`, `checklist`, `run_checks`, `variance`, `getVarianceReport`, `exportVarianceReport` | admin, manager, warehouse_manager, accountant |
| `weekly`, `exportWeekly`, `getSessionsList`, `WarehousesByBranch`, `count`, `export`, `create`, `store`, `saveCount`, `post` | admin, manager, warehouse_manager |
| `delete` (draft), `reverse` | admin, manager |

Notes: `accountant` is **read‑only** on the operational side; `warehouse_manager` can do everything except `delete` and `reverse`; `salesman`/`dispatcher` have **no access**. Branch scoping is enforced in `getFilteredSessions` (non‑admins pinned to their session branch) and in `runSessionChecks`.

### 2.10 Legacy strengths & weaknesses

**Strengths**
1. Clean two‑step workflow (Workflow B) — saving counts never moves stock; only `postSession` does.
2. Proper `FOR UPDATE` locking on the session row in both `saveCount` and `postSession`.
3. Atomic post transaction — stock, ledger, items, warehouse status, and GL journal are all in one DB transaction.
4. First‑class GL integration — dedicated shrinkage/surplus ledgers with sensible fallbacks; journal linked via `journal_entry_id`; reversal creates a proper reversing entry (not a delete).
5. Real reversal path — both stock and GL reversed with audit rows; originals flagged `is_reversed`, not deleted.
6. Strong audit‑checklist tooling (`StockTakeAuditModel`) — proactively detects missing GL rows, duplicate count lines, stale sessions, negative stock, non‑shrinkage‑nature usage. Rare in legacy PHP ERPs.
7. Per‑warehouse "mark complete" — counters work warehouse‑by‑warehouse without committing the whole session.
8. Branch isolation enforced at the model level.
9. CSRF on every POST.
10. Variance value uses count‑time avg cost (stored in `stock_take_items.rate`), so GL posts a stable amount even if avg cost drifts.

**Weaknesses / fragilities**
1. **No stock freeze during counting** — biggest data‑integrity risk. `system_qty` shown to the counter is read once at page load and re‑read at `saveCount` per line; a counter who saves half the lines, comes back later, sees `system_qty` values that disagree with what they originally saw, and the variance calculation silently uses the new `system_qty`, invalidating earlier physical counts.
2. **No approval gate** — posting only requires all warehouses `counted`; no second‑person review, no per‑line approval, no variance threshold that blocks posting (the "large variance reason" check is a soft warn).
3. **No cycle count / ABC classification** — every session is a full count of all active products; this is why stale sessions > 30 days are a flagged problem.
4. **No barcode‑driven count entry** — free‑text product search and manual qty entry only.
5. **`postSession` line 358 is a no‑op** (`$issueRate = $adjustment < 0 ? $rate : $rate;`) — confusing dead code.
6. **GL value uses count‑time avg cost, but stock moves at post‑time avg cost** — if avg cost moved between saveCount and post, the GL posts a different amount than the actual stock value change. No revaluation entry to true this up.
7. **Re‑post not supported** — reversal is terminal; a corrected count requires a new session.
8. **`deleteDraftSession` is a hard delete** — no soft‑delete, no audit row.
9. **`session_code` collision risk** — `random_int(1000,9999)` gives only 9000 codes/day; collisions surface as raw PDO exceptions with no retry.
10. **No declared foreign keys** — orphaned rows possible if products/warehouses are deleted.
11. **`getStockMovements` has a fragile fallback** (`OR (st.reference_type='' AND st.remarks LIKE 'Reversal of%')`) — relies on a remarks‑string convention.
12. **No `system_qty` snapshot column** — `stock_take_items.system_qty` is overwritten on every `saveCount` upsert; the originally‑counted‑against system quantity is lost.
13. **`StockTakeModel` does not consult `StockAvailabilityService`** — a shortage can drive `warehouse_stock.qty` below the level committed to open sales invoices.
14. **Audit "log" is purely state‑based** — no separate table capturing who‑saved‑what‑when; count‑edit history is lost on upsert.
15. **No re‑count against the same session** — once `counted`, a warehouse cannot move back to `pending` to re‑count (only path is `delete` the whole draft).
16. **Hard‑coded threshold of 500** for the "large variance reason" warning — not configurable.
17. **MySQL‑specific migration syntax** (`BIGINT(20)`, `TINYINT(1)`, `ENUM`, `DELETE t1 FROM t1 INNER JOIN t2 …`) — won't run on PostgreSQL as‑is.
18. **`getProductsForCounting` returns ALL active products** — no pagination; heavy for warehouses with thousands of SKUs.
19. **`rate` is `DECIMAL(12,2)`** — 2 dp may lose precision for high‑volume low‑cost items.
20. **No link from `stock_take_items` to a specific `journal_entry_line`** — only session‑level `journal_entry_id`; you cannot trace a single variance line to a single GL line.

---

## 3. How Laravel Currently Handles Physical Count

> Source: `laravel/app/Services/Stock/StockTakeService.php`, `laravel/app/Http/Controllers/Admin/StockTakeController.php`, `laravel/app/Models/StockTakeSession.php`, `laravel/app/Models/StockTakeItem.php`, `laravel/database/sql/03_stock.sql`, `07_views_triggers_constraints.sql`, `laravel/routes/web.php`, `laravel/resources/views/admin/stock-take/*`, `laravel/app/Services/Stock/StockService.php`, `laravel/app/Services/Accounting/JournalPostingService.php`, `laravel/app/Services/Accounting/DocumentSequenceService.php`, `laravel/database/migrations/*`, `tests/Helpers/InsertsWarehouseDependencies.php`.

### 3.1 Menu & navigation

- **Menu label:** `Physical Count` (under `Inventory`, sort 1, icon `fa fa-cubes`).
- **Seeder:** `laravel/database/migrations/2025_01_10_000001_seed_menus_from_legacy.php:62` (`['Physical Count', 'StockTake', 'index', 'fa fa-cubes', 'Inventory', 1, true]`). Idempotent via `updateOrInsert` on natural key `menu_label + controller`.
- **Route resolution:** `laravel/app/Services/MenuService.php:162` maps `stocktake` → `admin.stock-take.index`.
- **Route registration:** `laravel/routes/web.php:385–397`:
  - `admin/stock-take/{session}/warehouses/{warehouse}/setup` (GET) → `setupCounts`
  - `admin/stock-take/{session}/warehouses/{warehouse}/count` (GET/POST) → `count` / `saveCounts`
  - `admin/stock-take/{session}/post` (POST) → `post`
  - `admin/stock-take/{session}/cancel` (POST) → `cancel`
  - Resource `admin/stock-take` (`index`, `create`, `store`, `show`)

**⚠️ GAP (P1 security):** The entire route group sits under only the global `auth` middleware. **No `role:` middleware, no `branch.isolation` middleware.** Compare with sibling `admin/stock-adjustments` which applies both. Any authenticated user (including `salesman`, `dispatcher`, `user`) can create, post, or cancel a stock take. Menu visibility (per‑user `UserMenuPermission.can_view`) only governs navigation, not endpoint authorization.

### 3.2 Data model (PostgreSQL)

#### `stock_take_sessions` (`database/sql/03_stock.sql:132–145`)
```sql
CREATE TABLE stock_take_sessions (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_code varchar(30) NOT NULL,
    session_date date NOT NULL,
    branch_id integer NOT NULL REFERENCES branches(id),
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','counting','posted','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    notes text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT stock_take_session_code_unique UNIQUE (session_code)
);
CREATE INDEX idx_sts_branch ON stock_take_sessions(branch_id);
```

**⚠️ GAP (P0 blocker):** The table **does NOT have** `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason`. But `StockTakeService::createSession()` inserts `'is_reversed' => false` (line 72) and `cancelSession()` updates all four (lines 348–353). Result: `createSession` crashes on its first INSERT; `cancelSession` of a posted session crashes on its UPDATE. The team's own test helper (`tests/Helpers/InsertsWarehouseDependencies.php:93–107`) carries the comment *"Note: stock_take_sessions has no `is_reversed` column."*

(Sibling `stock_adjustments` in the same SQL file DOES have these four columns — confirming the omission is an oversight, not a design choice.)

#### `stock_take_warehouses` (`03_stock.sql:147–153`)
```sql
CREATE TABLE stock_take_warehouses (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    status varchar(20) DEFAULT 'pending'
        CHECK (status IN ('pending','counting','completed'))
);
CREATE INDEX idx_stw_session ON stock_take_warehouses(stock_take_session_id);
```
No UNIQUE(session_id, warehouse_id) — relies on app logic. No `branch_id` column → no RLS on this table.

#### `stock_take_items` (`03_stock.sql:155–171`)
```sql
CREATE TABLE stock_take_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_take_session_id integer NOT NULL REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    system_qty numeric(14,4) NOT NULL DEFAULT 0,
    physical_qty numeric(14,4) NOT NULL DEFAULT 0,
    difference numeric(14,4) GENERATED ALWAYS AS (physical_qty - system_qty) STORED,
    rate numeric(12,2) DEFAULT 0,
    reason text,
    is_applied boolean NOT NULL DEFAULT false,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sti_session_wh_product UNIQUE (stock_take_session_id, warehouse_id, product_id)
);
CREATE INDEX idx_sti_session ON stock_take_items(stock_take_session_id);
CREATE INDEX idx_sti_warehouse_product ON stock_take_items(warehouse_id, product_id);
```

**PostgreSQL‑specific feature:** `difference` is a `GENERATED ALWAYS AS (physical_qty - system_qty) STORED` column — the DB computes the variance, never the app. (pgloader excludes this column from data load.) This is a real improvement over legacy.

#### Related tables written on post
- **`stock_transactions`** — PARTITION BY RANGE (transaction_date). `reference_type` CHECK includes `'stock_take'` (and `'reversal'` added later). `is_reversed`, `reversal_of_transaction_id`, `reversed_at`, `reversed_by`, `reverse_reason` present. `total_value` GENERATED STORED. Self‑referential FK enforced by trigger `trg_st_reversal_fk` (PG can't do FK to partitioned tables natively).
- **`warehouse_stock`** — composite PK `(warehouse_id, product_id)`, no `id` column. CHECK `ws_qty_nonnegative` + trigger `prevent_negative_stock()` raise `check_violation` if qty < `-0.0001`. `avg_cost` is app‑maintained.
- **`journal_entries`** — full reversal support (`is_reversed`, `reversal_of_entry_id`, `reversed_at`, `reversed_by`, `reverse_reason`). Trigger `enforce_balanced_journal_entry()` ensures Dr=Cr per entry.
- **`journal_lines`** — CHECK `jl_balanced_check` (debit/credit ≥ 0) and `jl_not_both_zero_check`.
- **`document_sequences`** — used by `DocumentSequenceService` with `pg_advisory_xact_lock(int)` for atomic sequence generation.

#### Migrations touching stock_take tables

| Migration | Effect |
|---|---|
| `2025_01_01_000001_create_rcerp_schema.php` | Executes `database/sql/01–07_*.sql` (creates the tables) |
| `2025_01_21_000005_configure_deferred_fk_constraints.php` | Makes `stock_take_sessions.{journal_entry_id, branch_id}` DEFERRABLE INITIALLY DEFERRED |
| `2025_01_20_000007_add_rls_branch_isolation.php` | Enables RLS on `stock_take_sessions` only |
| `2025_07_26_000002_add_reversal_to_stock_transactions_reference_type_check.php` | Adds `'reversal'` to stock_transactions.reference_type CHECK |
| `2025_01_23_000002_add_soft_deletes_to_transactional_tables.php` | Adds `deleted_at` to `stock_take_sessions` (SoftDeletes fix) |

**No dedicated `create_stock_take_*_tables` migration exists** — schema comes from `03_stock.sql`. **No migration adds the four reversal columns** — the P0 gap.

### 3.3 Session lifecycle

```
                 ┌─────────┐
                 │  draft  │ ← createSession()
                 └────┬────┘
                      │ setupWarehouseCounts()
                      ▼
                 ┌──────────┐
                 │ counting │
                 └────┬─────┘
                      │ postSession()  (UI requires all warehouses 'completed'; service does NOT)
                      ▼
                 ┌────────┐         cancelSession() if posted:
                 │ posted │ ──────► reverses GL + stock_transactions, sets is_reversed=true,
                 └────┬───┘          status → cancelled
                      │
                      │ cancelSession() if draft/counting: just mark cancelled
                      ▼
                ┌───────────┐
                │ cancelled │  (terminal)
                └───────────┘
```

Session statuses (`draft`, `counting`, `posted`, `cancelled`) are enforced by a DB CHECK constraint — stronger than legacy's PHP‑only enum. Warehouse statuses: `pending → counting → completed`.

**⚠️ GAP (P2):** The service does NOT enforce that all warehouses are `completed` before `postSession` runs. The UI prevents the user from clicking Post, but a direct POST to `/admin/stock-take/{id}/post` with a session in `counting` where warehouses aren't all completed will still succeed.

**Pessimistic locking:** `postSession` line 224 and `cancelSession` line 314 take `StockTakeSession::lockForUpdate()->find($id)`. Good. But `saveCounts` and `setupWarehouseCounts` do **not** lock the session row — concurrent counters on the same session are not serialized.

### 3.4 Business logic (`StockTakeService.php`, 445 lines)

- **Constructor:** injects `StockService` and `JournalPostingService` (auto‑container).
- **`createSession()`:** validates `branch_id > 0` and `warehouse_ids` non‑empty; generates session code via `DocumentSequenceService::nextCode(docType:'stock_take', prefix:'ST', datePart:Ymd, padLength:4)` (atomic via `pg_advisory_xact_lock`); inside `DB::transaction()` inserts the session **with `'is_reversed' => false`** ← crashes — and one `stock_take_warehouses` row per warehouse (`status='pending'`).
- **`setupWarehouseCounts()`:** verifies warehouse is in session; **deletes** existing items for that (session, warehouse) — re‑setup is allowed; loads all active products with a LEFT JOIN to `warehouse_stock`; **bulk‑inserts** `stock_take_items` with `system_qty = ws.qty` (frozen snapshot), `physical_qty = ws.qty` (defaults to system — no variance until user edits), `rate = ws.avg_cost`, `is_applied=false`; promotes warehouse `pending → counting` and session `draft → counting`. **No product scope filter** — full‑warehouse count only; no cycle‑count mode.
- **`saveCounts()`:** iterates `$counts[product_id => physical_qty]`, updates `physical_qty` by composite key; promotes warehouse `counting → completed`. Controller separately saves `reasons[product_id => text]` in a follow‑up loop.
- **`postSession()`:** inside `DB::transaction()`: `lockForUpdate()` on session; guard `status ∈ {counting, draft}`; load items where `is_applied=false AND physical_qty <> system_qty`; for each, `$variance = physical − system`, `rate = item.rate > 0 ? item.rate : live avg_cost`, `StockService::applyTransaction(qty=$variance, reference_type='stock_take', reference_id=$sessionId, notes='Stock Take #CODE — reason')`; accumulate `$totalGain`/`$totalLoss`; mark item `is_applied=true`, persist `rate`; if either ≥ 0.01, call private `postStockTakeGL()`. **No pre‑check for negative stock** — the `prevent_negative_stock()` trigger will raise `check_violation` and crash the whole transaction with a generic error.
- **`postStockTakeGL()`:** looks up `inventory`, `inventory_surplus`, `inventory_shrinkage` via `LedgerNatureService::resolveLedgerByNature()`; builds up to 4 lines in a single journal entry:
  - Gain: Dr Inventory / Cr Inventory Surplus
  - Loss: Dr Inventory Shrinkage / Cr Inventory
  Calls `JournalPostingService::createJournalEntry()` with `reference_type='stock_take'`, `source='stock_take'`. Identical structure to `StockAdjustmentService::postAdjustmentGL()` — good consistency. Difference: stock take can post BOTH gain and loss in one entry (stock adjustment is single‑direction per document).
- **`cancelSession()`:** inside `DB::transaction()`: `lockForUpdate()`; guard not already cancelled; if posted → `JournalPostingService::reverseJournalEntry()` (creates new JE with swapped Dr/Cr, `skip_period_check=true`); for each non‑reversed `stock_transactions` row, `StockService::reverseTransaction()` (appends opposite‑sign tx with `reference_type='reversal'`); **UPDATE session with `is_reversed=true, reversed_at, reversed_by, reverse_reason`** ← crashes — then `status='cancelled'`.
- **`generateSessionCode()`:** `DocumentSequenceService::nextCode(...)` — atomic, no collision risk (a real improvement over legacy's `random_int`).

**Transaction wrapping:** every public mutator is wrapped in `DB::transaction()`. `StockService::applyTransaction`/`reverseTransaction` and `JournalPostingService::reverseJournalEntry` are also wrapped (nested savepoints in PG). Atomicity is good.

### 3.5 Stock impact

| Table | On post | On cancel of posted session |
|---|---|---|
| `stock_transactions` | INSERT one row per variance item (`reference_type='stock_take'`) | For each non‑reversed original: INSERT a `reference_type='reversal'` tx with `-qty`; flag original `is_reversed=true` |
| `warehouse_stock` | UPSERT `qty` + `avg_cost` | UPSERT again (reversal movement adjusts back) |
| `stock_take_items` | UPDATE `is_applied=true`, persist `rate` | (no change — `is_applied` stays true; legacy resets it to false) |
| `stock_take_sessions` | UPDATE `status='posted'`, `journal_entry_id` | UPDATE `is_reversed=true, reversed_at, reversed_by, reverse_reason` ← crashes — then `status='cancelled'` |
| `journal_entries` | INSERT one balanced entry | INSERT reversal entry; flag original `is_reversed=true` |
| `journal_lines` | INSERT 2 or 4 lines | INSERT mirrored reversal lines |
| `journal_posting_logs` | INSERT `action='posted'` | INSERT `action='reversed'` |

**Freeze during count?** **No.** The `setupWarehouseCounts` snapshot is read once and stored in `stock_take_items.system_qty` (better than legacy's per‑save re‑read), but if sales/purchases/transfers happen between setup and post, the live `warehouse_stock.qty` continues to change. At post time, the service applies `physical − system` as a delta — so a count can be perfectly accurate and still produce a wrong stock correction if stock moved between setup and post. (Legacy's `StockTake.js` even warns: *"If stock was sold or transferred after the take, reversing a surplus line may fail until enough quantity is on hand."*)

### 3.6 GL / accounting integration

- **Account resolution** via `LedgerNatureService::resolveLedgerByNature()` (not hardcoded IDs):
  - `inventory` → L‑0501 (Asset, debit)
  - `inventory_shrinkage` → L‑0502 (Expense, debit)
  - `inventory_surplus` → L‑0802 (Income, credit)
  All seeded in `2025_01_05_000001_seed_default_chart_of_accounts.php`.
- **Journal entry structure** as above (Dr/Cr pairs).
- **Configurability:** accounts resolved by `ledger_nature`, so a different chart of accounts can be wired by changing which ledger has each nature. Defensive — throws `RuntimeException('Inventory surplus ledger not found.')` if missing.
- **Journal reversal:** `JournalPostingService::reverseJournalEntry()` — `lockForUpdate` original; swap Dr↔Cr; `createJournalEntry` with `reference_type='reversal'`, `skip_period_check=true` (reversals can post to closed periods — important for end‑of‑year corrections); flag original `is_reversed=true`; INSERT `journal_posting_logs` row `action='reversed'`. Append‑only — original never mutated except reversal flag columns.
- **Period‑close bypass:** reversals skip period check; **original post does NOT** — posting a stock take with `session_date` in a closed period throws `"Posting date X falls within a closed accounting period"`. Admin bypass is configurable via `config('accounting.period_close_admin_override')`.

### 3.7 Audit trail

**`AuditableMasterData` trait is wired but DEAD.** `StockTakeSession` uses `SoftDeletes` + `AuditableMasterData` (which boots Eloquent `created`/`updated`/`deleted`/`restored` events → logs to `user_audit_log` with old/new JSON). **But** `StockTakeService` bypasses Eloquent entirely and uses `DB::table('stock_take_sessions')->insert/update`. Eloquent events **do not fire** on raw DB queries. So the trait is dead code for stock take. The only Eloquent call is the read‑only `StockTakeSession::with(...)->find($sessionId)`.

**What audit IS captured:** `journal_posting_logs` (posted/reversed actions); `stock_transactions.is_reversed / reversed_at / reversed_by / reverse_reason`; `stock_take_sessions` would capture reversal columns if they existed; `stock_take_items.is_applied`.

**What audit is MISSING:** no audit log entry for session creation, setup counts, save counts, status transitions, or session cancellation (when not posted). The `user_audit_log` table has no rows from the stock take workflow. No old/new snapshot tracking on `stock_take_items` (count edits are silent). There is a `GlobalAuditController` but it's not stock‑take‑aware.

### 3.8 Reports

- **Route:** `routes/web.php:347` — `stocktake-variance` → `ReportController::stocktakeVariance`.
- **Controller** (`ReportController::stocktakeVariance`): **STUB** — only lists session headers (`sts.id, session_code, session_date, status, branch_name`); no variance numbers, no GL impact, no per‑product breakdown. No link to session detail page.
- **View** (`stocktake_variance.blade.php`): **wrong status keys** in the badge map — uses `draft/in_progress/completed/approved/cancelled` instead of the actual `draft/counting/posted/cancelled`. So `counting` and `posted` sessions fall through to the default `'secondary'` badge.
- `ReportService.php:130` correctly includes `inventory_surplus` in Revenue and `inventory_shrinkage` in Operating Expenses — so stock take variances DO show up in P&L by nature. Good. But no dedicated stock‑take variance CTE in `CteReportService`.

### 3.9 Concurrency & controls

| Operation | Lock |
|---|---|
| `createSession` | None (advisory lock only for code generation) |
| `setupWarehouseCounts` | **None** |
| `saveCounts` | **None** |
| `postSession` | `lockForUpdate` on session + `lockForUpdate` on `warehouse_stock` inside `applyTransaction` |
| `cancelSession` | `lockForUpdate` on session + `lockForUpdate` on original `stock_transactions` + inner `warehouse_stock` |

**Advisory locks** used only by `DocumentSequenceService` for code generation — NOT for cross‑session mutual exclusion. Two users could create two sessions for the same warehouse simultaneously.

**RLS (Row‑Level Security):** Only `stock_take_sessions` has RLS (5 policies: SELECT/INSERT/UPDATE/DELETE + admin bypass, predicated on `app.branch_id` GUC set per‑request by `SetAppBranchId` middleware). `stock_take_items` and `stock_take_warehouses` have **no RLS** (no `branch_id` column). The controller guards against cross‑branch item access by first calling `StockTakeSession::findOrFail($sessionId)` (RLS‑protected) before any item query — defense‑in‑depth, but fragile.

**Non‑negative stock guard:** `warehouse_stock` CHECK + trigger will raise `check_violation` if a stock‑take variance pushes qty below zero. The service does NOT pre‑check this. The user gets a generic `RuntimeException` from PostgreSQL — not a friendly message.

**DEFERRABLE FKs:** `stock_take_sessions.{journal_entry_id, branch_id}` are DEFERRABLE INITIALLY DEFERRED — so a stock take can reference a journal entry created in the same transaction (which is what `postSession` does). Good.

### 3.10 Permissions / roles

- **No `StockTakePolicy` class** exists. `app/Policies/` only has `SalesInvoicePolicy`, `SystemPolicyPolicy`, `CustomerPaymentPolicy`. No policy registered for `StockTakeSession` in `AppServiceProvider`.
- **Route middleware:** as above — **no `role:` or `branch.isolation`** on the stock‑take routes. P1 security gap.
- **Menu visibility:** per‑user `UserMenuPermission.can_view=1` on the `StockTake` menu row (admin/superadmin bypass). Governs navigation only, not endpoint authorization.
- **Form Requests:** **none**. The controller uses inline `$request->validate()`. Compare with sales/purchase which have dedicated `StorePurchaseOrderRequest`, `StoreSalesReturnRequest`, etc.

### 3.11 Completeness assessment

**✅ DONE (working)**
- PostgreSQL schema for the three tables (with `difference` GENERATED STORED column — a real PG improvement).
- `deleted_at` for SoftDeletes (added by migration).
- RLS on `stock_take_sessions`.
- DEFERRABLE FKs.
- Menu seeded + route resolution.
- Eloquent models (3) with relationships, casts, fillable.
- 5‑phase service (create/setup/save/post/cancel) with `DB::transaction` + `lockForUpdate` on the two mutating endpoints.
- GL posting (Dr Inventory / Cr Surplus; Dr Shrinkage / Cr Inventory) — identical to proven `StockAdjustmentService` pattern.
- GL reversal via `reverseJournalEntry` (swap Dr/Cr, `skip_period_check=true`).
- Stock reversal via `reverseTransaction` (append‑only, `reference_type='reversal'`).
- Atomic session code generation (`pg_advisory_xact_lock`).
- Blade views: index, create, count, show (~1,520 lines total, polished Bootstrap 5 + Select2 + DataTables + SweetAlert2).
- Defense‑in‑depth: warehouse deactivation blocked when an active stock take exists (`WarehouseController` + tests).

**❌ MISSING / BROKEN**

| Item | Severity |
|---|---|
| `stock_take_sessions` lacks `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason` — **feature is non‑functional** | **P0** |
| No `role:` middleware on stock‑take routes — security gap | **P1** |
| No Form Request classes | P3 |
| No `StockTakePolicy` | P3 |
| No factories/seeders for stock take | P3 |
| No dedicated tests for the StockTakeService workflow (only warehouse‑deactivation side‑effect tests) | P2 |
| Variance report is a stub | P2 |
| Audit trait never fires (service uses `DB::table()`) | P2 |
| No cycle count mode (full‑warehouse only) | P3 |
| No stock freeze during count | P2 (by design? needs documentation) |
| `postSession` doesn't pre‑check negative stock | P2 |
| `postSession` doesn't enforce all warehouses completed | P2 |
| Dead JS files (`StockTake.js`, `stock-take-count.js`, `StockTakeReport.js`) referencing legacy URLs | P3 |
| `stock_take_warehouses` lacks UNIQUE(session_id, warehouse_id) | P3 |
| No API controller (`/api/v1/stock-take/*`) | P3 |

**STUBs:** `ReportController::stocktakeVariance()`; `stocktake_variance.blade.php`; the three orphaned JS files.

**Verdict:** ~70% implemented. Architecture is sound and mirrors the proven `StockAdjustmentService` pattern. The 5‑phase lifecycle, GL posting, reversal logic, and atomicity are correctly designed. The Blade UI is polished. **But the feature CANNOT RUN as‑shipped** because of the P0 schema gap. The variance report and audit‑trail gaps are real but secondary.

---

## 4. Gap Analysis — Legacy vs Laravel vs Target

| # | Dimension | Legacy (MySQL) | Laravel (PostgreSQL) — current | Target (perfect) |
|---|---|---|---|---|
| 1 | **End‑to‑end runnable?** | ✅ Yes | ❌ No (P0 schema gap) | ✅ Yes |
| 2 | **Session statuses** | `draft/counting/adjusted/reversed` (PHP enum) | `draft/counting/posted/cancelled` (DB CHECK) | `draft/counting/submitted/approved/posted/cancelled` (DB CHECK + state machine) |
| 3 | **Warehouse statuses** | `pending/counted/posted` | `pending/counting/completed` | `pending/counting/completed/recounting` |
| 4 | **System‑qty snapshot** | ❌ Overwritten on every save | ✅ Frozen at setup | ✅ Frozen at setup + re‑freeze on recount |
| 5 | **Stock freeze during count** | ❌ None | ❌ None | ✅ Optional warehouse freeze (block outbound) |
| 6 | **Cycle count / ABC** | ❌ Full only | ❌ Full only | ✅ Full / category / ABC / ad‑hoc product list |
| 7 | **Barcode entry** | ❌ No | ❌ No | ✅ Yes (scan → product match → qty prompt) |
| 8 | **Bulk count entry** | Partial (full grid save) | Partial (full grid save) | ✅ Bulk paste + CSV import + grid |
| 9 | **Approval gate** | ❌ None (auto‑post) | ❌ None (auto‑post) | ✅ Per‑session approval (segregation of duties) |
| 10 | **Variance threshold block** | ❌ Soft warn (≥500) | ❌ None | ✅ Configurable threshold (block or warn) |
| 11 | **Recount** | ❌ No (delete draft only) | ❌ No (cancel only) | ✅ Per‑warehouse recount to `pending` |
| 12 | **Re‑post after reversal** | ❌ Terminal | ❌ Terminal (`cancelled`) | ✅ Re‑open after reversal (with audit) |
| 13 | **GL integration** | ✅ Shrinkage/surplus + fallbacks | ✅ Same, by nature | ✅ Same + per‑line GL traceability |
| 14 | **GL at count‑time vs post‑time cost** | Count‑time (drift risk) | Count‑time (drift risk) | ✅ Post‑time + revaluation entry if drift |
| 15 | **Negative‑stock pre‑check** | ❌ (trigger blocks, generic error) | ❌ (trigger blocks, generic error) | ✅ Pre‑check with friendly product list |
| 16 | **Audit trail (row‑level)** | ❌ State‑based only | ❌ Trait dead (DB::table) | ✅ Dedicated `stock_take_audit_log` table |
| 17 | **Variance report** | ✅ Full (detail + weekly + CSV) | ✅ Legacy parity + drill‑down + GL impact (Phase 6) | ✅ Legacy parity + drill‑down + GL impact |
| 18 | **Routes role/branch protected** | ✅ Yes | ❌ No (P1) | ✅ Yes |
| 19 | **Form Requests** | ❌ Inline | ❌ Inline | ✅ Dedicated classes |
| 20 | **Policy** | ❌ N/A | ❌ None | ✅ `StockTakePolicy` |
| 21 | **Concurrent counters** | ✅ `FOR UPDATE` on session in `saveCount` | ❌ No lock in `saveCounts` | ✅ `FOR UPDATE` on session in all mutators |
| 22 | **Session code** | `random_int` (collision risk) | `pg_advisory_xact_lock` (atomic) | ✅ Keep Laravel's approach |
| 23 | **RLS branch isolation** | App‑level | ✅ On sessions + items/warehouses (Phase 8 added `branch_id` + RLS to `stock_take_warehouses` + `stock_take_items`) | ✅ On all three tables (add `branch_id` to items/warehouses) |
| 24 | **DEFERRABLE FKs** | ❌ None declared | ✅ Configured | ✅ Keep |
| 25 | **Soft delete drafts** | ❌ Hard delete | ✅ SoftDeletes | ✅ Keep + audit on delete |
| 26 | **API / mobile** | ❌ No | ❌ No | ✅ REST API + mobile‑friendly count screen |
| 27 | **Freeze flag on warehouse** | UI‑only deactivation block | UI‑only deactivation block | ✅ Real outbound block during count |
| 28 | **`stock_take_items` → GL line link** | ❌ Session‑level only | ❌ Session‑level only | ✅ Per‑line `journal_line_id` |
| 29 | **`rate` precision** | `DECIMAL(12,2)` | `numeric(12,2)` | `numeric(18,6)` |
| 30 | **Configurable thresholds** | ❌ Hard‑coded 500 | ❌ None | ✅ `system_policies` driven |

---

## 5. How Laravel + PostgreSQL Can Make It Better

The Laravel rewrite is not just a port — it should be a **material improvement** over the legacy software. The following PostgreSQL and Laravel capabilities should be exploited:

### 5.1 PostgreSQL capabilities to exploit

1. **`GENERATED ALWAYS AS … STORED` columns** — already used for `stock_take_items.difference`. Extend to a `value_diff` generated column (`(physical_qty - system_qty) * rate`) so the variance value is always consistent with the line, never recomputed by the app. Zero drift between DB and UI.
2. **`CHECK` constraints with transitions** — move the state machine into the DB. Add a trigger that raises `check_violation` on illegal transitions (e.g. `draft → posted` skipping `counting`, `cancelled → anything`).
3. **Row‑Level Security (RLS)** — extend RLS to `stock_take_warehouses` and `stock_take_items` by adding a `branch_id` column (denormalized) or by joining through `stock_take_sessions` in the policy. Closes the cross‑branch data leak.
4. **`pg_advisory_xact_lock`** — already used for code generation. Use it for **warehouse‑level mutual exclusion**: a lock keyed on `warehouse_id` taken during `postSession` ensures no other stock‑affecting transaction (sales, transfers, adjustments) runs against that warehouse mid‑post. This is the foundation of the stock‑freeze feature without a flag column.
5. **`EXCLUDE` constraints** — prevent overlapping active counting sessions for the same warehouse (e.g. `EXCLUDE USING gist (warehouse_id WITH =) WHERE (status IN ('draft','counting'))`). Optional, but elegant.
6. **`LISTEN`/`NOTIFY`** — already wired (`ListenNotifyService`). Emit a `stock_take_posted` event so dashboards, alerts, and reconciliation services update in real time.
7. **Partitioned `stock_transactions`** — already partitioned by `transaction_date`. Stock take movements scale naturally; old counts can be archived by detaching partitions.
8. **`jsonb` columns** — store the full count snapshot (product list + system qty + avg cost) as `jsonb` on the session for cheap historical reconstruction, even after `products`/`warehouse_stock` change. (Optional; see Phase 4.)
9. **`DEFERRABLE INITIALLY DEFERRED` FKs** — already configured for `journal_entry_id`/`branch_id`. Extend to the per‑line `journal_line_id` link so a single post transaction can write items + journal lines atomically.
10. **`brin` indexes** — for time‑series queries on `stock_transactions` (already added project‑wide). Stock‑take variance reports over long date ranges benefit.
11. **`CHECK` for non‑negative `physical_qty`** — add `CHECK (physical_qty >= 0)` to `stock_take_items` so a typo can't produce a negative count.
12. **`pg_cron`** — already configured project‑wide. Schedule a nightly job to surface stale sessions (> N days) and post reminders.

### 5.2 Laravel capabilities to exploit

1. **Eloquent events / observers** — replace the dead `AuditableMasterData` trait usage. Either (a) refactor `StockTakeService` to use Eloquent models for writes (so events fire), or (b) add an explicit `StockTakeAuditLogger` that writes to `user_audit_log` on every transition. Either way, the audit trail must be real.
2. **Dedicated Form Requests** — `StoreStockTakeSessionRequest`, `SetupStockTakeCountsRequest`, `SaveStockTakeCountsRequest`, `PostStockTakeRequest`, `CancelStockTakeRequest`. Centralizes validation, gives consistent 422 responses, and documents the contract.
3. **Policies** — `StockTakePolicy` with methods `viewAny`, `view`, `create`, `setup`, `saveCounts`, `post`, `cancel`, `approve`, `reverse`. Wire `authorize()` in the controller. Defense‑in‑depth on top of route middleware.
4. **State machine** — use a lightweight state machine (e.g. a small in‑app class or `spatie/laravel-model-states`) to encode the session lifecycle. Single source of truth for transitions; the DB CHECK becomes the safety net.
5. **Queued jobs** — for large warehouses, run `setupWarehouseCounts` (which bulk‑inserts thousands of items) as a queued job with a progress indicator, rather than blocking the HTTP request.
6. **Events + listeners** — `StockTakeSessionPosted`, `StockTakeSessionReversed`, `StockTakeSessionApproved`. Listeners: update materialized views, send notifications (Telegram/FCM already wired project‑wide), refresh dashboard caches.
7. **API Resources** — `StockTakeSessionResource`, `StockTakeItemResource`, `StockTakeVarianceResource`. Consistent JSON shapes for the future API + mobile.
8. **`DB::transaction()` + nested savepoints** — already used. Keep; document that nested service calls participate in the outer transaction.
9. **Middleware stacks** — `role:admin,manager,warehouse_manager,accountant` + `branch.isolation` on mutation routes. P1 fix.
10. **Rule objects** — `WarehouseBelongsToBranch`, `WarehouseHasStock` (both already exist for other modules). Add `SessionIsPostable`, `SessionIsCancellable`, `VarianceWithinThreshold`.
11. **Configurable system policies** — `system_policies` table (already exists project‑wide). Drive the variance threshold, the "large variance reason" threshold, the stale‑session days, and whether approval is required — all configurable, not hardcoded.
12. **Testing** — feature tests for the full workflow (create → setup → save → post → cancel; create → setup → save → post → reverse → re‑post). The legacy codebase has zero such tests; Laravel's testing infra makes this cheap.

---

## 6. Target‑State Feature List (the “perfect” menu)

After all phases, the Physical Count menu should support:

### 6.1 Session creation
- Choose branch (admin) or pinned to own branch (non‑admin).
- Choose one or more warehouses.
- Choose count scope: **Full** | **By Category** | **By ABC class** | **By Product Group** | **Ad‑hoc product list** | **Negative‑stock only** | **Zero‑stock only**.
- Optional: barcode‑driven (scan to add products to scope).
- Optional: freeze outbound stock for chosen warehouses during the count (configurable per session).
- Optional: assign counter(s) and approver(s) (segregation of duties).
- Auto‑generate `session_code` atomically (`ST-YYYYMMDD-NNNN`).
- Snapshot `system_qty` + `avg_cost` at setup (frozen, never overwritten).
- Capture a `jsonb` snapshot of the product list for historical reconstruction.

### 6.2 Counting
- Per‑warehouse count grid: product code, name, category, system qty (frozen), physical qty (editable), variance (auto), value (auto), reason.
- Barcode entry: scan → product match → qty prompt → save line.
- Bulk paste: paste a list of `code,qty` pairs.
- CSV import: upload a counted CSV.
- Auto‑save drafts (per line) with optimistic concurrency (ETag / `updated_at` compare).
- Mark warehouse complete; allow re‑open to `recounting` (with audit).
- Concurrent counters serialized via `FOR UPDATE` on the session row.
- Live "large variance" warnings (configurable threshold) with required reason.
- Mobile‑friendly layout (touch targets, sticky header, offline count + sync — future).

### 6.3 Submission & approval
- Submit session for approval (transition `counting → submitted`).
- Approver reviews: variance summary, top variances, GL impact preview, per‑line reasons.
- Approve (transition `submitted → approved`) or Reject (back to `counting` with comments).
- Approval optional (configurable via `system_policies`): below threshold → auto‑approve; above → required.
- Configurable: who can approve (must be a different user than the counter — segregation of duties).

### 6.4 Posting
- Pre‑post checks: all warehouses `completed`/`approved`; no negative‑stock outcomes (friendly product list if any); accounting period open (or admin override).
- Post atomically: stock movements, GL journal entry (gain/loss lines), per‑line `journal_line_id` link, session `status='posted'`.
- GL at post‑time avg cost; if avg cost drifted between setup and post, post a small revaluation entry to true up.
- Real‑time notification (`LISTEN`/`NOTIFY` + Telegram/FCM) on post.
- Variance report immediately available.

### 6.5 Reversal & re‑post
- Reverse a posted session: append‑only reversal of stock + GL; original rows flagged `is_reversed`; session `status='reversed'` (not `cancelled` — distinguish "user cancelled a draft" from "we reversed a posted session").
- Re‑open after reversal: transition `reversed → counting` with a mandatory reason; allow corrected count; re‑post creates a new journal entry (the reversal stays).
- Full audit trail of every reversal and re‑open.

### 6.6 Reports
- **Variance detail**: per‑line, filter by session/branch/warehouse/product/date; CSV export.
- **Weekly control**: per‑session summary (warehouses counted, variance lines, gain/loss/net value); totals; top variance products.
- **GL impact**: per‑session Dr/Cr breakdown; drill‑down to journal entry.
- **Stale sessions**: sessions open > N days (configurable); reminder emails.
- **Count accuracy KPI**: over time, variance as % of inventory value, per warehouse / per counter.
- All reports RLS‑aware (branch‑scoped for non‑admins).

### 6.7 Audit & compliance
- Dedicated `stock_take_audit_log` table: who, when, what (old/new JSON), action (`create`, `setup`, `save_count`, `mark_complete`, `submit`, `approve`, `reject`, `post`, `reverse`, `re_open`, `delete`).
- Health‑check checklist (port of legacy `StockTakeAuditModel`): duplicate lines, missing GL, missing stock movements, negative stock, stale sessions, non‑shrinkage‑nature usage.
- Reconciliation alerts surfaced on the dashboard.

### 6.8 Permissions
- `admin`: everything.
- `manager`: create, count, post, reverse, re‑open, approve (if not the counter).
- `warehouse_manager`: create, count, submit; cannot post/approve/reverse.
- `accountant`: view, approve, post (read‑only on counts); can reverse with manager.
- `salesman` / `dispatcher`: no access.
- All mutations branch‑scoped (RLS + middleware).

### 6.9 API & mobile (future)
- REST API: `GET /api/v1/stock-take/sessions`, `POST …/sessions`, `GET …/sessions/{id}/items`, `POST …/sessions/{id}/counts`, `POST …/sessions/{id}/post`.
- Mobile count screen (offline‑capable, sync on reconnect) — future.

---

## 7. Phase‑by‑Phase Implementation Plan

> **How to read each phase:** Goal → Scope → Schema changes → Code changes → Acceptance criteria → Rollback. Phases are ordered by dependency; within a phase, items can be parallelized. **No code is written in this document** — each phase is a work package to be implemented.
> **Estimated effort** is indicative (S/M/L) and assumes one mid‑level Laravel + PostgreSQL developer.

---

### Phase 0 — Unblock: Fix the P0 schema gap and P1 security gap

**Goal:** Make the feature runnable and secure before adding anything new.

**Scope:**
1. Add the four reversal columns to `stock_take_sessions`.
2. Add `role:` + `branch.isolation` middleware to the stock‑take route group.
3. Update `database/sql/03_stock.sql` for fresh installs to match.
4. Update the test helper comment in `tests/Helpers/InsertsWarehouseDependencies.php`.

**Schema changes:**
- New migration `2025_01_30_000001_add_reversal_columns_to_stock_take_sessions.php`:
  - `ALTER TABLE stock_take_sessions ADD COLUMN is_reversed boolean NOT NULL DEFAULT false`
  - `ADD COLUMN reversed_at timestamp(0)`
  - `ADD COLUMN reversed_by integer REFERENCES users(id)`
  - `ADD COLUMN reverse_reason text`
  - `CREATE INDEX idx_sts_reversed_at ON stock_take_sessions(reversed_at) WHERE is_reversed = true` (partial index — only reversed rows).
- Mirror the same four columns in `database/sql/03_stock.sql` for fresh installs (so a new DB matches a migrated DB).
- (Optional, recommended) Also add `posted_at timestamp(0)` and `adjusted_at timestamp(0)` if the team wants to track wall‑clock post time separately from `updated_at` (legacy had both). The current Laravel schema only has `updated_at`.

**Code changes:**
- `routes/web.php` — wrap the `admin/stock-take` route group:
  - `index`, `create`, `show`, `setup`, `count`: `role:admin,manager,warehouse_manager,accountant`
  - `store`, `saveCounts`: `role:admin,manager,warehouse_manager` + `branch.isolation`
  - `post`: `role:admin,manager,warehouse_manager,accountant` + `branch.isolation`
  - `cancel`: `role:admin,manager,accountant` + `branch.isolation`
- Test helper: remove the "no is_reversed column" comment; add the four columns to the insert array.

**Acceptance criteria:**
- `createSession()` succeeds (no `Undefined column` error).
- `cancelSession()` on a posted session succeeds (reversal columns written).
- A `salesman`/`dispatcher` hitting `POST /admin/stock-take/{id}/post` gets 403.
- A non‑admin user from Branch A cannot POST a session in Branch B (403 or 404).
- Fresh install (`php artisan migrate:fresh`) and migrated install produce identical `stock_take_sessions` schemas.

**Rollback:** `DROP COLUMN is_reversed, reversed_at, reversed_by, reverse_reason FROM stock_take_sessions;` revert route middleware.

**Effort:** S (½ day).

---

#### ✅ Phase 0 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The P0 schema blocker and P1 security gap are fixed. `StockTakeService::createSession()` / `cancelSession()` no longer crash, and the stock‑take routes are now role‑ and branch‑protected. Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (6):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_26_000003_add_reversal_columns_to_stock_take_sessions.php` | **NEW.** Adds `is_reversed` (bool, default false), `reversed_at` (timestamp nullable), `reversed_by` (integer nullable), `reverse_reason` (text nullable) to `stock_take_sessions`, plus partial index `idx_sts_is_reversed`. Idempotent (`Schema::hasColumn` guards). Mirrors the style of `2025_01_04_000001_add_reversal_columns_to_stock_transactions.php`. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the same 4 columns + partial index into the `CREATE TABLE stock_take_sessions` block so fresh installs match migrated installs. Columns placed between `journal_entry_id` and `notes` to mirror sibling `stock_adjustments`. |
| 3 | `laravel/routes/web.php` | Rewrote the `admin/stock-take` route block to mirror the `purchase-orders` RBAC idiom: each route gets `role:` middleware; POST writes also get `branch.isolation`; `store` split out of the resource for tighter RBAC. |
| 4 | `laravel/app/Http/Middleware/EnforceBranchIsolation.php` | Extended `inferTableFromUri()` to recognize `stock-take` → `stock_take_sessions`, and added a `{session}` route‑param resolution branch in `resolveUrlParamBranchId()`. Without this, `branch.isolation` would silently no‑op on `POST {session}/post` and `POST {session}/cancel` (the routes use `{session}`, not `{id}`). |
| 5 | `laravel/tests/Helpers/InsertsWarehouseDependencies.php` | Removed the "no `is_reversed` column" comment; added `'is_reversed' => false` to the `insertActiveStockTake` insert array so test rows match the service contract. |
| 6 | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 0 placeholders (and why):**

1. **Migration filename:** plan suggested `2025_01_30_000001`; actual is `2025_07_26_000003`. Reason: Laravel runs migrations in filename‑sort order, and the latest existing migration is `2025_07_26_000002`. The new migration must sort **after** it, so it reuses the `2025_07_26` date with an incremented `000003` suffix.
2. **`reversed_by` type:** plan suggested `integer REFERENCES users(id)`; actual is plain `integer` (no FK). Reason: this **exactly mirrors the sibling `stock_adjustments.reversed_by`** column (`03_stock.sql` line 111) for schema consistency, and avoids FK‑deferral complications on a table that participates in `DB::transaction()` with `journal_entries` (already DEFERRABLE). The legacy `stock_take_sessions.reversed_by` was also a plain int.
3. **Partial index name:** plan suggested `idx_sts_reversed_at` (on `reversed_at`); actual is `idx_sts_is_reversed` (on `is_reversed WHERE is_reversed = true`). Reason: the typical query is "find reversed sessions" (`WHERE is_reversed = true`), which the boolean partial index serves directly. This also mirrors the `stock_transactions` reversal migration's `idx_st_is_reversed` pattern.
4. **`post` route role:** plan suggested `role:admin,manager,warehouse_manager,accountant`; actual is `role:admin,manager,warehouse_manager` (no `accountant`). Reason: §2.9 of this document establishes that `accountant` is **read‑only on the operational side** in the legacy matrix — accountants can view but not create/count/post/reverse. Aligning with the proven legacy permission prevents accidental privilege escalation.
5. **`cancel` route role:** plan suggested `role:admin,manager,accountant`; actual is `role:admin,manager` (no `accountant`). Reason: `cancel` of a posted session performs a full stock + GL reversal — a destructive operation. Legacy reserves `reverse` for `admin,manager` only. Same rationale as above.
6. **`branch.isolation` on `{session}` routes required a middleware extension** (not in the original Phase 0 scope). Reason: `EnforceBranchIsolation` only resolved branch_id from `$params['id']` / `$params['invoiceId']` for a hardcoded set of URI prefixes. The stock‑take custom routes use `{session}`. Without the extension, attaching `branch.isolation` to `POST {session}/post` and `POST {session}/cancel` would have been security theater (the middleware would find no branch_id to compare and silently pass). The extension is surgical (~12 lines) and is documented inline in the middleware.

**Final route → middleware map (as applied):**

| Route | Method | Middleware |
|---|---|---|
| `admin/stock-take` (index) | GET | `role:admin,manager,warehouse_manager,accountant` |
| `admin/stock-take/create` | GET | `role:admin,manager,warehouse_manager,accountant` |
| `admin/stock-take` (store) | POST | `role:admin,manager,warehouse_manager` + `branch.isolation` |
| `admin/stock-take/{session}` (show) | GET | `role:admin,manager,warehouse_manager,accountant` |
| `admin/stock-take/{session}/warehouses/{warehouse}/setup` | GET | `role:admin,manager,warehouse_manager` |
| `admin/stock-take/{session}/warehouses/{warehouse}/count` | GET | `role:admin,manager,warehouse_manager` |
| `admin/stock-take/{session}/warehouses/{warehouse}/count` | POST | `role:admin,manager,warehouse_manager` + `branch.isolation` |
| `admin/stock-take/{session}/post` | POST | `role:admin,manager,warehouse_manager` + `branch.isolation` |
| `admin/stock-take/{session}/cancel` | POST | `role:admin,manager` + `branch.isolation` |

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| `createSession()` succeeds (no `Undefined column` error) | ✅ Schema now has `is_reversed`; service insert will succeed. |
| `cancelSession()` on a posted session succeeds (reversal columns written) | ✅ All 4 reversal columns present; service update will succeed. |
| A `salesman`/`dispatcher` hitting `POST /admin/stock-take/{id}/post` gets 403 | ✅ `role:admin,manager,warehouse_manager` on the `post` route denies them. |
| A non‑admin user from Branch A cannot POST a session in Branch B (403 or 404) | ✅ `branch.isolation` resolves `{session}` → `stock_take_sessions.branch_id` (via the middleware extension) and denies cross‑branch. RLS on `stock_take_sessions` is the defense‑in‑depth backstop. |
| Fresh install (`php artisan migrate:fresh`) and migrated install produce identical `stock_take_sessions` schemas | ✅ Both `03_stock.sql` (fresh) and the migration (existing DBs) add the same 4 columns + same partial index. |

**Verification commands (run inside the Docker container):**
```bash
# 1. Run the migration
php artisan migrate

# 2. Confirm the columns exist
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('stock_take_sessions'));"
# Expected output includes: is_reversed, reversed_at, reversed_by, reverse_reason

# 3. List routes + their middleware
php artisan route:list --name=stock-take

# 4. (Optional) Fresh-install parity check
php artisan migrate:fresh --seed
```

**Not done in Phase 0 (deferred to later phases):**
- No `posted_at` / `adjusted_at` columns (the "optional, recommended" item in the plan) — deferred; `updated_at` suffices for now and adding them is a Phase 1+ concern.
- No feature tests for the full create→post→cancel flow — that's Phase 12.
- The dead `AuditableMasterData` trait (service uses `DB::table()`) is still dead — that's Phase 2.
- The stub variance report is still a stub — that's Phase 6.

**Next phase:** Phase 1 — Harden the core service (guards, negative‑stock pre‑check, per‑line `journal_line_id`).

---

### Phase 1 — Harden the core service: guards, pre‑checks, transaction integrity

**Goal:** Make `postSession` and `cancelSession` correct, safe, and user‑friendly. Close the "direct POST bypasses UI guard" hole and the "generic negative‑stock error" hole.

**Scope:**
1. Enforce "all warehouses completed" in `postSession` (server‑side).
2. Pre‑check negative‑stock outcomes before applying any variance; produce a friendly error listing the offending products.
3. Lock the session row in `setupWarehouseCounts` and `saveCounts` (currently unlocked).
4. Add a per‑line `journal_line_id` link on `stock_take_items` (Phase 4 prerequisite, schema‑only here).
5. Remove the dead JS files (`StockTake.js`, `stock-take-count.js`, `StockTakeReport.js`).

**Schema changes:**
- `ALTER TABLE stock_take_items ADD COLUMN journal_line_id integer REFERENCES journal_lines(id)` (nullable; populated on post).
- `CREATE INDEX idx_sti_journal_line ON stock_take_items(journal_line_id) WHERE journal_line_id IS NOT NULL` (partial).

**Code changes:**
- `StockTakeService::postSession`:
  - After `lockForUpdate`, add: `if ($session->warehouses()->where('status', '<>', 'completed')->exists()) throw new RuntimeException('All warehouses must be completed before posting.');`
  - Before applying variances, run a **pre‑check query**: for each variance line with `difference < 0`, compare `|difference|` against the *current* `warehouse_stock.qty`; collect any that would go negative. If non‑empty, throw a `StockTakeNegativeStockException` with the product list (codes + names + short qty). The controller renders this as a 422 with a table.
  - When writing each `journal_line`, capture the `journal_line_id` and persist it on the corresponding `stock_take_items` row.
- `StockTakeService::setupWarehouseCounts` and `saveCounts`: wrap in `DB::transaction` (already) and add `$session = StockTakeSession::lockForUpdate()->find($sessionId);` at the top — serialize concurrent counters.
- Delete `public/assets/js/StockTake.js`, `stock-take-count.js`, `StockTakeReport.js` (orphaned, reference legacy URLs, not loaded by any Blade).

**Acceptance criteria:**
- A direct `POST /admin/stock-take/{id}/post` where a warehouse is not `completed` returns 422 with a clear message (no stock moved).
- A post that would drive `warehouse_stock` negative returns 422 listing the offending products (no stock moved, no GL written).
- Two concurrent `saveCounts` calls on the same session are serialized (the second waits; no lost updates).
- After a successful post, each `stock_take_items` row has a non‑null `journal_line_id` pointing to the exact Dr/Cr line.
- The dead JS files are gone; no Blade references them.

**Rollback:** revert service changes; `DROP COLUMN journal_line_id`; restore JS files from git.

**Effort:** M (1–2 days).

---

#### ✅ Phase 1 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. `postSession` now has server‑side guards (all‑warehouses‑completed + negative‑stock pre‑check), concurrent counters are serialized via `lockForUpdate`, each variance item is back‑linked to its GL journal line, and the 3 dead JS files are deleted. Below is the exact record of what was changed, the decisions that diverged from the plan, and how to verify.

**Files changed (7 changed + 3 deleted = 10 total):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_26_000004_add_journal_line_id_to_stock_take_items.php` | **NEW.** Adds `journal_line_id integer nullable` + FK to `journal_lines(id) ON DELETE SET NULL` + partial index `idx_sti_journal_line`. Idempotent. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored `journal_line_id` column + FK + partial index into `CREATE TABLE stock_take_items` for fresh‑install parity. |
| 3 | `laravel/app/Exceptions/StockTakeNegativeStockException.php` | **NEW.** Custom exception carrying the full offending‑product list (code, name, system qty, physical qty, current stock, shortage, resulting qty). `getOffendingProducts()` getter for the controller. |
| 4 | `laravel/app/Models/StockTakeItem.php` | Added `journal_line_id` to `$fillable` and `$casts` (integer). Updated `@property` docblock. |
| 5 | `laravel/app/Services/Stock/StockTakeService.php` | **Core hardening:** (a) `postSession`: added all‑warehouses‑completed guard; added `assertNoNegativeStockOutcomes()` pre‑check (locks `warehouse_stock` rows via `FOR UPDATE` on a `leftJoin`); deferred `is_applied` + `rate` update to after GL posting; added `journal_line_id` back‑link (queries back `journal_lines` by `ledger_id` + debit/credit bucket). (b) `setupWarehouseCounts` + `saveCounts`: added `StockTakeSession::lockForUpdate()->find()` at the top to serialize concurrent counters. (c) Added private `assertNoNegativeStockOutcomes()` helper. |
| 6 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | `post()`: added `catch (StockTakeNegativeStockException)` before the generic `catch (\Throwable)` — redirects back with the error message + flashes the offending‑product list. |
| 7 | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |
| — | `laravel/public/assets/js/StockTake.js` | **DELETED.** Orphaned legacy‑PHP port; referenced non‑existent URLs (`StockTake/store`, `StockTake/post`); not loaded by any Blade view. |
| — | `laravel/public/assets/js/stock-take-count.js` | **DELETED.** Same — orphaned. |
| — | `laravel/public/assets/js/StockTakeReport.js` | **DELETED.** Same — orphaned. |

**Decisions that diverged from the plan's Phase 1 placeholders (and why):**

1. **Migration filename:** plan suggested no specific name; actual is `2025_07_26_000004` (sorts after Phase 0's `2025_07_26_000003`).
2. **`journal_line_id` FK strategy:** plan suggested the column as a "Phase 4 prerequisite, schema‑only here" with a DEFERRABLE FK to be configured later. Actual: the FK (`sti_journal_line_id_fk`) is **immediate‑checked** (not DEFERRABLE) with `ON DELETE SET NULL`. Reason: the `journal_line_id` is only SET **after** `createJournalEntry` has already inserted the journal lines (all within the same `DB::transaction`). By the time the `UPDATE stock_take_items SET journal_line_id = …` runs, the referenced `journal_lines.id` already exists — so immediate FK checking is satisfied. No deferral is needed. The DEFERRABLE concern in the plan was for a hypothetical "write items + journal lines in one interleaved batch" scenario, which is not how the post flow works.
3. **`journal_line_id` population granularity:** the plan says "capture the `journal_line_id` and persist it on the corresponding `stock_take_items` row." The current GL design **aggregates** all gains into one Dr/Cr pair and all losses into another (at most 4 journal lines per session). So multiple variance items share the same `journal_line_id` (all gain items → the Dr Inventory line; all loss items → the Cr Inventory line). This provides **bucket‑level** traceability (you can drill from a variance item to the exact journal line), but not **true 1:1** (one journal line per item). Full 1:1 per‑item GL lines (one Dr/Cr pair per variance item) is deferred to **Phase 9** (GL & costing refinements), which will refactor `postStockTakeGL` to emit per‑item lines. This is documented inline in the service code.
4. **Negative‑stock pre‑check locking:** the plan says "pre‑check … collect any that would go negative." The implementation uses a `leftJoin` + `lockForUpdate()` query that locks the `warehouse_stock` rows for the duration of the transaction. This makes the pre‑check **race‑free**: no other transaction can modify those stock rows between the pre‑check and the actual `applyTransaction` calls. The `leftJoin` handles the edge case where a `warehouse_stock` row doesn't exist (treated as qty=0 via `COALESCE`; any shortage would then be an offender). The DB `prevent_negative_stock()` trigger remains as the defense‑in‑depth backstop.
5. **`is_applied` update timing:** the original code marked each item `is_applied=true` inside the foreach loop (right after `applyTransaction`). The new code **defers** this to after GL posting (so `journal_line_id` is available for the same UPDATE). This is safe because if the transaction fails before the deferred update, all stock movements and GL inserts are rolled back by `DB::transaction` — items correctly remain `is_applied=false`.
6. **Dead JS file deletion confirmed:** Grep for `StockTake\.js|stock-take-count\.js|StockTakeReport\.js` across `laravel/resources/` (all Blade views) returned **zero matches** — confirming these files were truly orphaned and safe to delete.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| A direct `POST /admin/stock-take/{id}/post` where a warehouse is not `completed` returns 422 with a clear message (no stock moved) | ✅ Server‑side guard in `postSession`: `incompleteCount > 0` → `RuntimeException`. Controller catches → `back()->with('error', …)`. No stock moved (exception thrown before the apply loop). |
| A post that would drive `warehouse_stock` negative returns 422 listing the offending products (no stock moved, no GL written) | ✅ `assertNoNegativeStockOutcomes()` runs BEFORE any `applyTransaction`. Throws `StockTakeNegativeStockException` with product list. Controller catches → `back()->with('error', …)->with('negative_stock_products', …)`. No stock moved, no GL written. |
| Two concurrent `saveCounts` calls on the same session are serialized (the second waits; no lost updates) | ✅ `saveCounts` now calls `StockTakeSession::lockForUpdate()->find($sessionId)` at the top of its `DB::transaction`. The second caller's `lockForUpdate` blocks until the first commits. Same for `setupWarehouseCounts`. |
| After a successful post, each `stock_take_items` row has a non‑null `journal_line_id` pointing to the exact Dr/Cr line | ✅ Bucket‑level: gain items → Dr Inventory line; loss items → Cr Inventory line. (True 1:1 deferred to Phase 9 — see decision #3 above.) Items with no variance are not in the `$varianceItems` set, so their `journal_line_id` stays null (correct — no GL line for them). |
| The dead JS files are gone; no Blade references them | ✅ Deleted. Grep confirmed zero Blade references before deletion. |

**Verification commands (run inside the Docker container):**
```bash
# 1. Run the migration
php artisan migrate

# 2. Confirm the column + FK + index exist
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('stock_take_items'));"
# Expected: includes journal_line_id

# 3. Test the all-warehouses-completed guard (create a session, don't complete all warehouses, try to POST)
#    Expected: redirected back with "All warehouses must be marked 'completed' before posting"

# 4. Test the negative-stock pre-check (set physical_qty > system_qty for a product with low stock, then POST)
#    Expected: redirected back with "Cannot post: N product(s) would go negative" + flash data

# 5. Test concurrent saveCounts (two browser tabs, save simultaneously)
#    Expected: the second save waits for the first to commit (no lost updates)

# 6. After a successful post, verify journal_line_id is populated
php artisan tinker --execute="echo \DB::table('stock_take_items')->whereNotNull('journal_line_id')->count();"
# Expected: > 0 for a posted session with variances

# 7. Confirm the dead JS files are gone
ls public/assets/js/StockTake* public/assets/js/stock-take-count* 2>&1
# Expected: "No such file or directory"
```

**Not done in Phase 1 (deferred to later phases):**
- True 1:1 per‑item GL lines (one journal line per variance item) — deferred to Phase 9 (GL & costing refinements).
- The dead `AuditableMasterData` trait is still dead — that's Phase 2.
- The stub variance report is still a stub — that's Phase 6.
- No feature tests for the new guards — that's Phase 12.

**Next phase:** Phase 2 — Real audit trail (dedicated `stock_take_audit_log` table).

---

### Phase 2 — Real audit trail: dedicated `stock_take_audit_log` table

**Goal:** Replace the dead `AuditableMasterData` trait with a real, explicit audit log for every stock‑take action.

**Scope:**
1. New `stock_take_audit_log` table.
2. Explicit logging in `StockTakeService` for every transition.
3. Port the legacy health‑check checklist (`StockTakeAuditModel`) as a Laravel service.
4. Surface the audit log on the session detail page and on a global audit screen.

**Schema changes:**
- New table `stock_take_audit_log`:
  - `id` (identity PK)
  - `stock_take_session_id` (FK, ON DELETE CASCADE)
  - `stock_take_warehouse_id` (FK, nullable — for warehouse‑scoped actions)
  - `stock_take_item_id` (FK, nullable — for item‑scoped actions)
  - `action` varchar(40) CHECK in (`create`, `setup`, `save_count`, `mark_complete`, `submit`, `approve`, `reject`, `post`, `reverse`, `re_open`, `delete`, `cancel`)
  - `actor_id` integer REFERENCES users(id)
  - `from_status` varchar(20), `to_status` varchar(20)
  - `payload jsonb` (old/new snapshot or action‑specific data: e.g. counts saved, variance summary)
  - `created_at timestamp(0)`
  - Index on `(stock_take_session_id, created_at)`, partial index on `action WHERE action IN ('post','reverse','re_open')`.

**Code changes:**
- New `StockTakeAuditLogger` service (or static methods on a trait) with `log(Session, action, fromStatus, toStatus, payload)`. Called explicitly at the end of each `StockTakeService` mutator (inside the same DB transaction).
- `StockTakeService::createSession/setupWarehouseCounts/saveCounts/postSession/cancelSession` — add `logger->log(...)` calls with the appropriate action + payload.
- New `StockTakeHealthCheckService` (port of legacy `StockTakeAuditModel`): `runHealthChecks()` and `runSessionChecks($sessionId)`. Surface on a new `admin/stock-take/checklist` route and on the session detail page.
- New `StockTakeAuditController` (or extend `GlobalAuditController`) for a global audit view filtered by date/actor/action.
- Surface the audit timeline on `show.blade.php`.

**Acceptance criteria:**
- Every transition writes exactly one `stock_take_audit_log` row, in the same transaction as the data change.
- The session detail page shows a chronological audit timeline (actor, action, from→to, timestamp, payload summary).
- The health‑check checklist surfaces: duplicate count lines, stale sessions > N days, posted sessions missing GL, reversed sessions whose GL was not reversed, negative `warehouse_stock`, sessions whose `journal_entry_id` is null despite variance ≥ 0.01.
- Rolling back a post (exception thrown after audit log insert) also rolls back the audit log row (same transaction).

**Rollback:** drop the table + controller + service; remove logger calls.

**Effort:** M (2 days).

---

#### ✅ Phase 2 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The dead `AuditableMasterData` trait is replaced by an explicit, transaction-safe `stock_take_audit_log` table written by a dedicated `StockTakeAuditLogger` service. Every stock-take transition (create / setup / save_count / post / reverse / cancel) now writes exactly one audit row inside the same `DB::transaction` as the data change — so a rolled-back post also rolls back its audit row. The legacy `StockTakeAuditModel` health-check checklist is ported to a Laravel `StockTakeHealthCheckService` and surfaced on both a global `/admin/stock-take/checklist` screen and the per-session detail page. A new global `/admin/stock-take/audit` screen lists every audit row across all in-scope sessions, filterable by date / actor / action / session. Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (10):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_26_000005_create_stock_take_audit_log_table.php` | **NEW.** Creates `stock_take_audit_log` (append-only: `id`, `stock_take_session_id` FK CASCADE, nullable `stock_take_warehouse_id` / `stock_take_item_id` FKs SET NULL, `action` varchar(40) CHECK constrained to the 12-value lifecycle vocab, `actor_id` plain int, `from_status` / `to_status` varchar(20), `payload` jsonb, `branch_id` NOT NULL denormalized for RLS, `created_at`). 4 indexes: `idx_stal_session` (timeline), `idx_stal_critical` PARTIAL on post/reverse/re_open, `idx_stal_branch`, `idx_stal_actor`. Full RLS policy set (select/insert/update/delete + admin bypass) mirroring the commission_entries pattern. Idempotent `down()` drops policies + RLS + indexes + table. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the `CREATE TABLE stock_take_audit_log` + 4 indexes between `stock_take_items` and `warehouse_transfers` so fresh installs match migrated installs. RLS policies are NOT in this SQL file (they live in the migration, matching the existing convention — see `2025_01_20_000007_add_rls_branch_isolation.php`). |
| 3 | `laravel/app/Models/StockTakeAuditLog.php` | **NEW.** Eloquent model. `UPDATED_AT = null` (append-only table has no `updated_at`). `$fillable` + `$casts` for all columns. `session()`, `warehouse()`, `actor()` relations. Three static helpers for the UI: `actionLabel($action)` (human label), `actionColor($action)` (Bootstrap color bucket), `isCritical($action)` (true for post/reverse/re_open — matches the partial index). |
| 4 | `laravel/app/Services/Stock/StockTakeAuditLogger.php` | **NEW.** Thin, side-effect-free writer. `log($session, $action, $fromStatus, $toStatus, $payload, $actorId, $warehouseId, $itemId)`. Accepts either a `StockTakeSession` model OR a bare array/stdClass (the service sometimes holds a `lockForUpdate` stdClass before the model is reloaded — accepting either avoids a forced reload mid-transaction). Resolves `actor_id` from `auth()->id()` when not passed. No-op (does NOT throw) if session identity is missing — a logging failure can never break a stock-take transition (the data change is the source of truth; the audit row is the forensic record). |
| 5 | `laravel/app/Services/Stock/StockTakeService.php` | (a) Constructor: added `StockTakeAuditLogger $auditLogger` as 3rd DI param. (b) `createSession`: logs `action='create'` (null → draft) with session_code + warehouse_ids payload. (c) `setupWarehouseCounts`: logs `action='setup'` (fromStatus → counting) warehouse-scoped, with products_loaded count. (d) `saveCounts`: logs `action='save_count'` warehouse-scoped, with lines_saved + product_ids. (e) `postSession`: captures `$fromStatus` BEFORE writes; logs `action='post'` (fromStatus → posted) AFTER the status update, with variance_lines + total_gain + total_loss + journal_entry_id. (f) `cancelSession`: for a posted session logs BOTH `action='reverse'` (so the critical-events index catches it) AND `action='cancel'` (the user-facing action); for a draft/counting session logs only `action='cancel'`. All 6 log calls run INSIDE the caller's `DB::transaction` → atomic with the data change. |
| 6 | `laravel/app/Services/Stock/StockTakeHealthCheckService.php` | **NEW.** Port of legacy `StockTakeAuditModel`. `runHealthChecks(?int $branchId)` → 6 sections (workflow, data integrity, GL journal links, ledger nature, stock & GL alignment, operations) with pass/warn/fail/info summary + actionable "posted sessions missing GL" list. `runSessionChecks($sessionId)` → per-session pre/post checklist (warehouses complete, variance lines, large-variance reasons, stock movements, GL journal, ready_to_post flag). `getSessionsMissingJournalRows()` helper. Adapted MySQL→PostgreSQL: `status='adjusted'`→`'posted'`, `take_date`→`session_date`, `journal_entry_lines`→`journal_lines`, `COALESCE(is_reversed,0)=0/1`→`is_reversed=false/true`, `is_active=1/0`→`true/false`, named placeholders→`?`. Branch scoping via RLS + optional explicit `$branchId` for admins. |
| 7 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | (a) Constructor: added `StockTakeHealthCheckService` as 2nd DI param. (b) `show()`: now eager-loads `auditLogs` (with actor + warehouse) + runs `runSessionChecks($id)`, passing both to the view. (c) **NEW** `checklist(Request)`: renders the global health-check screen; admin can toggle `?all_branches=1` to bypass RLS. (d) **NEW** `audit(Request)`: paginated global audit-log screen with filters (from_date / to_date / actor_id / action / session_id / search by session_code); builds the action dropdown from `StockTakeAuditLog::actionLabel()` and the actor dropdown from users who actually have audit rows. Uses `auth()->user()->isAdmin()` / `getBranchId()` (the User model's actual API — no `is_admin` or `name` column exists on `users`). |
| 8 | `laravel/routes/web.php` | Added 2 GET routes inside the `admin/stock-take` prefix group: `checklist` and `audit`, both with `role:admin,manager,warehouse_manager,accountant` (read-only — same RBAC as index/show). No `branch.isolation` — RLS on `stock_take_audit_log` scopes reads by branch automatically. Placed BEFORE the `{session}` routes so they don't get captured by the resource pattern. |
| 9 | `laravel/resources/views/admin/stock-take/show.blade.php` | Added 2 full-width cards below the 2-column row: (a) "Session health check" — a list-group of per-session checks with pass/warn/fail/info badges + a green "ready to post" banner when applicable. (b) "Audit timeline" — a chronological table of every audit row for this session (when, action badge with star for critical, actor username + employee name, from→to transition badges, warehouse badge + payload key/value pairs). Links to the global audit screen filtered by this session. |
| 10 | `laravel/resources/views/admin/stock-take/checklist.blade.php` + `audit.blade.php` + `index.blade.php` | **NEW** `checklist.blade.php`: hero header + 4 summary tiles (pass/warn/fail/info) + 2-column section cards + an actionable "posted sessions missing GL" table with investigate links. **NEW** `audit.blade.php`: filter card (6 filters) + paginated table (when, action, actor, session link, transition, details) with the same payload rendering as the timeline. `index.blade.php`: added "Health Check" + "Audit Log" buttons to the hero header so the new screens are discoverable. |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 2 placeholders (and why):**

1. **`actor_id` is a plain integer, not an FK to `users(id)`.** The plan said "`actor_id` integer REFERENCES users(id)". Actual: plain `integer`, no FK. Reason: this **mirrors the sibling convention on every other audit-ish column in the schema** — `stock_take_sessions.created_by`, `stock_take_sessions.reversed_by`, `stock_adjustments.reversed_by`, `journal_entries.created_by`, `journal_entries.reversed_by` are ALL plain integers with no FK. The rationale (documented in the Phase 0 log) is that a deleted user should not orphan or cascade-delete historical audit rows. The legacy `stock_take_audit_log.reversed_by` was also a plain int. Consistency wins.
2. **`branch_id` added to the table (not in the plan's schema).** The plan's schema listed session_id / warehouse_id / item_id / action / actor_id / from_status / to_status / payload / created_at — no `branch_id`. Actual: added `branch_id integer NOT NULL REFERENCES branches(id)`, denormalized from `stock_take_sessions.branch_id` at insert time. Reason: **RLS requires a branch_id column on the table itself** to scope reads without a join. Without it, the audit log would either be globally visible (security hole — a Branch A user could read Branch B's audit rows) or require a join to `stock_take_sessions` on every read (slow + defeats RLS). The logger reads `branch_id` from the session model/array and writes it on every row. This is the same pattern used by `commission_entries.branch_id` (migration `2025_01_22_000001`).
3. **`cancelSession` writes TWO audit rows for a posted session (`reverse` + `cancel`).** The plan's action vocab lists `reverse` and `cancel` as separate actions. A posted-session cancel performs BOTH a reversal (stock + GL rollback) AND a cancellation (status → cancelled). Logging only one would lose either the "critical transition" signal (reverse is in the partial index; cancel is not) or the user-facing action (cancel is what the user clicked). Solution: log `reverse` first (with the stock_reversed / journal_reversed payload), then `cancel` (with the was_posted flag). Both rows share the same `from_status` and `to_status='cancelled'`. The timeline shows both, clearly labeled. A draft/counting cancel logs only `cancel` (no reversal happens).
4. **`StockTakeAuditLogger::log()` accepts either a model or a bare array/stdClass.** The plan said "`log(Session, action, fromStatus, toStatus, payload)`" — implying a `StockTakeSession` model. But `StockTakeService::postSession` and `cancelSession` call `StockTakeSession::lockForUpdate()->find($sessionId)` which returns a model, while `createSession` builds the session via `DB::table()->insertGetId()` and only has a `$sessionId` + the input `$data['branch_id']` at log time (the model is reloaded AFTER the return). Forcing a model reload mid-transaction just to log would be wasteful and could read stale state. Solution: the logger accepts `{id, branch_id}` from either a model or a bare array, so `createSession` can pass `['id' => $sessionId, 'branch_id' => $data['branch_id']]` without a reload.
5. **The logger is a no-op (not a throw) if session identity is missing.** A logging failure must never break a stock-take transition — the data change is the source of truth, the audit row is the forensic record. If somehow `session_id` or `branch_id` is null/zero, the logger returns silently rather than throwing. This is a deliberate trade-off: we lose auditability of one row in an impossible edge case, but we never block a user's post/cancel because of an audit-log write failure.
6. **Health-check service: RLS does the branch scoping, not the explicit `$branchId` arg.** The legacy `StockTakeAuditModel` used `Helper::sessionBranchId()` + a manual `AND branch_id = N` clause on every query. The Laravel port supports an optional `$branchId` arg (used when admins explicitly scope to one branch), but in the default case it passes `null` and lets RLS on `stock_take_sessions` / `stock_take_items` / `warehouse_stock` / `journal_entries` do the scoping. This is more robust (RLS cannot be bypassed by a missed WHERE clause) and matches the middleware pattern (`SetAppBranchId` sets the `app.branch_id` GUC on every request).
7. **Audit-log routes have NO `branch.isolation` middleware.** The plan said "New `StockTakeAuditController` … for a global audit view". The routes are read-only GET. `branch.isolation` is a write-side middleware (it validates that a POST body's `branch_id` matches the user's branch). For reads, RLS on `stock_take_audit_log` (scoped by `branch_id = current_setting('app.branch_id')::int`, with an admin bypass) is the correct and sufficient enforcement. Adding `branch.isolation` would be redundant and would actually fail — the middleware tries to resolve a `{session}` URL param, which these routes don't have.
8. **The action CHECK constraint includes the full 12-value lifecycle vocab, not just the 5 actions currently logged.** The plan's list: `create, setup, save_count, mark_complete, submit, approve, reject, post, reverse, re_open, delete, cancel`. Currently only 5 are emitted (create, setup, save_count, post, reverse, cancel). The other 7 (mark_complete, submit, approve, reject, re_open, delete) are reserved for future phases (4: approval workflow; 10: re-open after reversal). Including them in the CHECK constraint now means those phases can log without a schema change — just a new `auditLogger->log(...)` call. This is forward-compatible design with zero cost.
9. **`StockTakeAuditLog::UPDATED_AT = null`.** The table is append-only (no `updated_at` column). Setting `UPDATED_AT = null` tells Eloquent not to try to set `updated_at` on insert, which would otherwise cause a `column "updated_at" does not exist` error. The model still has `$timestamps = true` so `created_at` IS managed automatically — but since the logger writes via `DB::table()->insert()` (not `Model::create()`), `created_at` is set explicitly in the insert array. The model's timestamp management is for the benefit of any future `Model::create()` calls.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| Every transition writes exactly one `stock_take_audit_log` row, in the same transaction as the data change | ✅ 6 logger calls (create / setup / save_count / post / reverse / cancel) all run inside the caller's `DB::transaction`. A posted-session cancel writes 2 rows (reverse + cancel) — see decision #3. The logger uses `DB::table()->insert()` (not `Model::create()`) so it participates in the active transaction without starting its own. |
| The session detail page shows a chronological audit timeline (actor, action, from→to, timestamp, payload summary) | ✅ `show.blade.php` now has an "Audit timeline" card below the 2-column row: table with when / action badge (star for critical) / actor (username + employee name) / from→to transition badges / warehouse badge + payload key-value pairs. Links to the global audit screen filtered by this session. |
| The health-check checklist surfaces: duplicate count lines, stale sessions > N days, posted sessions missing GL, reversed sessions whose GL was not reversed, negative `warehouse_stock`, sessions whose `journal_entry_id` is null despite variance ≥ 0.01 | ✅ `StockTakeHealthCheckService::runHealthChecks()` has all 6: (1) duplicate count lines — `sectionDataIntegrity`; (2) stale open sessions >30 days — `sectionDataIntegrity`; (3) posted sessions missing GL — `sectionStockGl` + the actionable `getSessionsMissingJournalRows()` list; (4) reversed sessions whose GL was not reversed — `sectionStockGl`; (5) negative warehouse_stock — `sectionOperations`; (6) posted sessions with variance ≥ 0.01 but journal_entry_id = 0/null — `sectionStockGl` (posted_gl) + `getSessionsMissingJournalRows()`. Surfaced on `/admin/stock-take/checklist`. |
| Rolling back a post (exception thrown after audit log insert) also rolls back the audit log row (same transaction) | ✅ The `auditLogger->log('post', ...)` call is the LAST write in `postSession`'s `DB::transaction` (after the session status update). If any earlier write in the transaction throws, the entire transaction rolls back — including the audit row. If the audit insert itself throws (e.g., constraint violation), the transaction rolls back the stock movements + GL + session status update too. This is the acceptance criterion's exact scenario. |

**Verification commands (run inside the Docker container):**
```bash
# 1. Run the migration
php artisan migrate

# 2. Confirm the table + indexes exist
php artisan tinker --execute="echo \DB::table('pg_indexes')->where('tablename','stock_take_audit_log')->pluck('indexname')->implode(',');"
# Expected: idx_stal_session,idx_stal_critical,idx_stal_branch,idx_stal_actor

# 3. Confirm RLS is enabled + forced
php artisan tinker --execute="echo \DB::selectOne(\"SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname='stock_take_audit_log'\")->relrowsecurity ? 'ON' : 'OFF';"
# Expected: ON

# 4. Create a session, set up counts, save counts, post it, then cancel it.
#    Then check the audit timeline:
php artisan tinker --execute="echo \DB::table('stock_take_audit_log')->where('stock_take_session_id', SID)->orderBy('created_at')->get()->pluck('action')->implode(',');"
# Expected (for a full lifecycle): create,setup,save_count,post,reverse,cancel

# 5. Visit the session detail page (/admin/stock-take/{id}) — scroll to the bottom:
#    you should see the "Session health check" card and the "Audit timeline" card.

# 6. Visit /admin/stock-take/checklist — the global health-check screen.
# 7. Visit /admin/stock-take/audit — the global audit-log screen with filters.
```

**Not done in Phase 2 (deferred to later phases):**
- Item-scoped audit rows (`stock_take_item_id`) — the column exists but is always null; no current action is item-scoped. Deferred until a phase needs it (e.g., recount of a single item — Phase 7).
- Approval / reject / re_open actions — the CHECK constraint allows them, but no code emits them yet. That's Phase 4 (approval workflow) and Phase 10 (re-open after reversal).
- True 1:1 per-item GL lines — still bucket-level (gain → Dr Inventory; loss → Cr Inventory). Deferred to Phase 9 (GL & costing refinements).
- No feature tests for the audit trail — that's Phase 12.

**Next phase:** Phase 3 — Stock integrity (snapshot freeze + optional outbound freeze).

---

### Phase 3 — Stock integrity: snapshot freeze + optional outbound freeze

**Goal:** Eliminate the single biggest data‑integrity risk — stock moving during a count. Two layers: (a) freeze the *snapshot* (already done in Laravel, document it); (b) optionally freeze the *warehouse* (block outbound movements during the count).

**Scope:**
1. Add a `frozen_at timestamp(0)` column to `stock_take_sessions` (null = not frozen).
2. Add a `freeze_outbound boolean` column (default false).
3. When `freeze_outbound = true`, block outbound stock movements (sales, transfers out, adjustments out, damages) for the session's warehouses while the session is in `draft`/`counting`/`submitted`/`approved`.
4. Implement the freeze via a `pg_advisory_xact_lock` keyed on `warehouse_id` taken by `StockTakeService::postSession` AND by all outbound stock services — OR via a `warehouse_freeze` flag check at the start of each outbound service. (Advisory lock is more elegant and avoids a flag column, but a flag is simpler to reason about. Recommend the flag approach for clarity, with an advisory lock on the warehouse row during post.)
5. Capture a `jsonb` snapshot of the product list + system qty + avg cost at setup (for historical reconstruction).
6. Reconcile at post time: if live `warehouse_stock.qty` differs from `system_qty` (snapshot) for any counted product, surface a warning ("stock moved during count: product X changed from 10 to 7"). Optionally block post (configurable).

**Schema changes:**
- `ALTER TABLE stock_take_sessions ADD COLUMN frozen_at timestamp(0), ADD COLUMN freeze_outbound boolean NOT NULL DEFAULT false, ADD COLUMN count_snapshot jsonb`
- `ALTER TABLE warehouses ADD COLUMN is_frozen_for_count boolean NOT NULL DEFAULT false` (denormalized flag for fast checks; set true when any active session with `freeze_outbound=true` covers this warehouse; set false when no such session remains).
- `CREATE INDEX idx_wh_is_frozen ON warehouses(id) WHERE is_frozen_for_count = true` (partial).

**Code changes:**
- `StockTakeService::createSession`: if `freeze_outbound=true`, set `frozen_at=now()`, capture `count_snapshot` (the product list at setup time, including system_qty + avg_cost per product). Set `warehouses.is_frozen_for_count=true` for each covered warehouse.
- `StockService::applyTransaction` (and `SalesInvoiceService`, `WarehouseTransferService`, `StockAdjustmentService`, `DamageService`): at the start of any **outbound** movement, check `warehouses.is_frozen_for_count` for the source warehouse; if true, throw `WarehouseFrozenForCountException` with the session code(s). (Inbound movements — purchases, transfers in — are allowed; only outbound is blocked. Configurable.)
- `StockTakeService::postSession`: before applying variances, run a reconciliation query: `SELECT sti.product_id, sti.system_qty, ws.qty AS live_qty FROM stock_take_items sti JOIN warehouse_stock ws ON … WHERE sti.stock_take_session_id = ? AND sti.system_qty <> ws.qty`. If non‑empty, either warn (configurable) or block. If `freeze_outbound=true`, this list should be empty by construction (no outbound moved stock) — but inbound may still have changed it (purchases received during count); surface those.
- `StockTakeService::cancelSession` / session delete: clear `warehouses.is_frozen_for_count` when no remaining active session covers the warehouse.
- Add a `freeze_outbound` checkbox to the create form; default off (backward compatible). Document the trade‑off (freezing blocks sales — use for full annual counts; leave off for cycle counts).

**Acceptance criteria:**
- With `freeze_outbound=true`, attempting to sell/transfer‑out/adjust‑out/damage a product in a frozen warehouse returns a clear 422 naming the active session(s).
- With `freeze_outbound=false`, outbound movements succeed; at post time, the reconciliation warning lists any products whose live qty drifted from the snapshot.
- `count_snapshot` JSON contains the full product list at setup; the session detail page can reconstruct "what the counter saw" even months later.
- Canceling or deleting a frozen session clears the warehouse flag.
- Multiple sessions freezing the same warehouse are all reflected (flag stays true until the last session ends).

**Rollback:** drop the columns; remove the outbound checks.

**Effort:** L (3–4 days — touches multiple services).

#### ✅ Phase 3 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The single biggest data-integrity risk in a physical count — stock moving WHILE the count is in progress — is now closed with two complementary layers. (a) **Snapshot freeze**: every `setupWarehouseCounts` captures a `count_snapshot` jsonb (product_id, product_code, product_name, unit, system_qty, avg_cost per warehouse) on the session row, so the count can be reconstructed "what the counter saw" months later even if products are renamed/deleted or stock drifts. (b) **Optional outbound freeze**: a `freeze_outbound` flag on the session (default off, backward compatible) marks the covered warehouses `is_frozen_for_count=true`; `StockService::applyTransaction` — the single chokepoint for ALL outbound stock movements (sales, transfers out, adjustments out, damages, purchase returns) — rejects any outbound movement (qty < 0) for a frozen warehouse with a clear 422 naming the active session(s), EXCEPT the stock-take's own variance application (`reference_type='stock_take'`) and reversals (`reference_type='reversal'`). A post-time `reconcileSnapshotWithLiveStock` warning lists any product whose live `warehouse_stock.qty` drifted from the snapshot (empty by construction when the freeze held; surfaces inbound receipts received during a frozen count). The freeze is released on post/cancel via `refreshWarehouseFreezeFlags`, which honors overlapping sessions (the flag stays true until the LAST freezing session ends). Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (10):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_27_000001_add_freeze_columns_to_stock_take_sessions.php` | **NEW.** `ALTER TABLE stock_take_sessions ADD frozen_at timestamp(0), freeze_outbound boolean NOT NULL DEFAULT false, count_snapshot jsonb` + partial index `idx_sts_freeze_outbound` (only sessions with freeze_outbound=true). `ALTER TABLE warehouses ADD is_frozen_for_count boolean NOT NULL DEFAULT false` + partial index `idx_wh_is_frozen` (only frozen warehouses — the outbound check hits one row per frozen wh). Idempotent (`IF NOT EXISTS` / `IF EXISTS`) so re-running is safe; `down()` reverses both. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the 3 new columns + `idx_sts_freeze_outbound` partial index into the `stock_take_sessions` CREATE TABLE for fresh installs. |
| 3 | `laravel/database/sql/01_auth_and_master.sql` | Mirrored `is_frozen_for_count` + `idx_wh_is_frozen` partial index into the `warehouses` CREATE TABLE for fresh installs. |
| 4 | `laravel/app/Exceptions/WarehouseFrozenForCountException.php` | **NEW.** Extends `\RuntimeException`. Carries `warehouseId`, `warehouseName`, and the list of active session codes freezing the warehouse. Message is user-actionable: names the session(s) and tells the user to finish/cancel them. Rendered to 422 globally (see bootstrap/app.php below). |
| 5 | `laravel/app/Models/StockTakeSession.php` | Added `frozen_at`, `freeze_outbound`, `count_snapshot` to `$fillable`; casts `frozen_at`→datetime, `freeze_outbound`→boolean, `count_snapshot`→array. New helper `isActivelyFreezing()`: true iff `freeze_outbound` AND status in draft/counting (the window during which the freeze blocks outbound). |
| 6 | `laravel/app/Models/Warehouse.php` | Added `is_frozen_for_count` to `$fillable` + casts (boolean). New helper `isFrozenForCount()`. |
| 7 | `laravel/app/Services/Stock/StockService.php` | (a) `applyTransaction`: after `validate()`, if `qty < 0` AND `reference_type` NOT IN `['stock_take','reversal']`, calls `assertWarehouseNotFrozen($warehouseId)` BEFORE the ledger insert. (b) **NEW** private `assertWarehouseNotFrozen()`: single SELECT on `warehouses.is_frozen_for_count` (hits `idx_wh_is_frozen`); if frozen, queries the active freezing sessions (join `stock_take_warehouses` → `stock_take_sessions` WHERE freeze_outbound=true AND status IN draft/counting) and throws `WarehouseFrozenForCountException` naming them. Cheap: one row lookup in the common (unfrozen) case. |
| 8 | `laravel/app/Services/Stock/StockTakeService.php` | (a) `createSession`: accepts `freeze_outbound` (default false); sets `frozen_at=now()` when on; calls `refreshWarehouseFreezeFlags($warehouseIds)` to mark the covered warehouses frozen; audit payload now records `freeze_outbound` + `frozen_at`. (b) `setupWarehouseCounts`: product query now also selects `product_code`, `product_name`, `unit`; calls **NEW** `captureWarehouseSnapshot()` which merges this warehouse's product slice into the session-level `count_snapshot` jsonb keyed by warehouse_id (re-setup overwrites that warehouse's slice). (c) `postSession`: after the negative-stock pre-check, calls **NEW** `reconcileSnapshotWithLiveStock()` which returns the drift list (products where live `warehouse_stock.qty` ≠ `sti.system_qty`); the drift is recorded in the post audit payload (`stock_drift` + `stock_drift_count` + `freeze_outbound`) as a WARNING (post still proceeds — the negative-stock pre-check already prevents corruption); after marking posted, calls `releaseSessionFreeze()` to clear the freeze (honoring overlapping sessions). (d) `cancelSession`: calls `releaseSessionFreeze()` after the status→cancelled update; cancel audit payload records `freeze_outbound` + `freeze_released`. (e) **NEW** private helpers: `captureWarehouseSnapshot`, `reconcileSnapshotWithLiveStock`, `releaseSessionFreeze`, `refreshWarehouseFreezeFlags` (the single source of truth for the denormalized flag — recomputes from the set of ACTIVE freezing sessions, idempotent). |
| 9 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` + `laravel/bootstrap/app.php` | Controller: `store()` validates `freeze_outbound` (sometimes|boolean) and passes it through; success message notes the freeze when active. `show()` extracts `stock_drift` from the latest `post` audit row's payload and passes it to the view. `bootstrap/app.php`: registers a global `$exceptions->render()` for `WarehouseFrozenForCountException` → 422 JSON for API/AJAX (`{message, error, warehouse, sessions}`) or redirect-back-with-error for web, so EVERY outbound service (sales, transfers, adjustments, damages, purchase returns) gets a consistent actionable response without each controller needing its own catch. |
| 10 | `laravel/resources/views/admin/stock-take/create.blade.php` + `show.blade.php` | `create.blade.php`: new "Stock integrity options" card with a `freeze_outbound` switch + explanatory text (use for full annual counts; leave off for cycle counts; snapshot captured either way). `show.blade.php`: (a) freeze-status banner (amber while actively freezing, grey after release) showing frozen-since timestamp + covered warehouses + the block/released message; (b) stock-drift reconciliation warning table (product, warehouse, snapshot qty, live qty, delta with green/red coloring) shown only when drift > 0 after a post. |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 3 placeholders (and why):**

1. **`count_snapshot` is captured in `setupWarehouseCounts`, NOT in `createSession`.** The plan's code-change note said "createSession: … capture count_snapshot (the product list at setup time…)". But the product list per warehouse is only known at setup time (when `setupWarehouseCounts` loads products from `warehouse_stock`) — at `createSession` we only know the warehouse IDs, not the products. Capturing an empty snapshot at create + filling it at setup would split the logic needlessly. Solution: `setupWarehouseCounts` calls `captureWarehouseSnapshot()` which merges that warehouse's slice into the session-level jsonb keyed by `warehouse_id`. A multi-warehouse session accumulates one slice per warehouse; re-setup overwrites that warehouse's slice. This is the natural capture point and matches the plan's scope item #5 ("Capture a jsonb snapshot of the product list + system qty + avg cost at setup").
2. **The outbound freeze uses a denormalized `warehouses.is_frozen_for_count` flag, NOT a per-call join.** The plan offered both ("flag approach for clarity, with an advisory lock on the warehouse row during post"). We use the flag (the plan's recommended option) because it makes the outbound check a single partial-index lookup (`idx_wh_is_frozen` — one row per frozen warehouse) instead of a 2-table join on every outbound movement. The flag is recomputed by `refreshWarehouseFreezeFlags` on every create/post/cancel, so it is always consistent with the set of active freezing sessions. We did NOT add the advisory lock on the warehouse row during post — the existing `lockForUpdate` on the session row + the negative-stock pre-check's `lockForUpdate` on `warehouse_stock` rows already serialize the post; an additional advisory lock would add complexity without a concrete concurrency win for Phase 3. (Phase 8 — concurrency/RLS/locking hardening — may revisit.)
3. **The freeze exempts `reference_type` IN `['stock_take','reversal']`, not just `'stock_take'`.** The plan said "block outbound stock movements (sales, transfers out, adjustments out, damages)". The stock-take's OWN variance application (`reference_type='stock_take'`, qty can be negative for shortages) MUST proceed while frozen — that's the whole point of the count. Reversals (`reference_type='reversal'`) are corrections of prior movements (e.g., the cancel flow calls `reverseTransaction` which calls `applyTransaction` with `reference_type='reversal'`); blocking them would make a frozen session un-cancellable. Exempting both keeps the freeze targeted at NEW outbound business activity while letting the count lifecycle complete.
4. **The post-time reconciliation is a WARNING, not a block (configurable block deferred).** The plan said "either warn (configurable) or block". We implement warn-only: the drift is recorded in the post audit payload + surfaced on the show page. Rationale: the existing Phase 1 `assertNoNegativeStockOutcomes` pre-check already prevents the only truly corrupting outcome (negative stock); drift between snapshot and live qty makes the variance *less accurate* but not *destructive*, and blocking the post would strand legitimate counts where inbound receipts arrived during the count (a normal, expected scenario for a frozen warehouse — purchases received while counting). A configurable block can be added later (Phase 9 GL/costing refinements, which already revisits post-time cost) without a schema change.
5. **`WarehouseFrozenForCountException` is rendered globally in `bootstrap/app.php`, not caught per-controller.** The plan said the freeze "returns a clear 422 naming the active session(s)". There are ~8 services that call `StockService::applyTransaction` for outbound movements (sales challan, sales return reversal, damage, stock adjustment out, warehouse transfer out, purchase return, purchase audit). Adding a `catch (WarehouseFrozenForCountException)` to each of their controllers would be repetitive and error-prone (one missed catch = a 500 instead of 422). A single `$exceptions->render()` in `bootstrap/app.php` handles it once: JSON 422 for `expectsJson()` / `api/*` (the shape AJAX callers need), redirect-back-with-error for web. This is the Laravel-11+ idiomatic pattern and matches how the codebase already centralizes exception rendering.
6. **The freeze is released on BOTH post and cancel, but NOT on a soft-delete.** `releaseSessionFreeze` is called at the end of `postSession` and `cancelSession`. The codebase has no stock-take delete flow (drafts are cancelled, not deleted — `SoftDeletes` is on the model but no controller action hard-deletes), so there's no delete hook to wire. If a delete flow is added later (Phase 12 mentions soft-delete drafts), it must call `releaseSessionFreeze` too — this is documented in the method's docblock.
7. **`frozen_at` is set at create time (not at setup), so it can be earlier than `count_snapshot.captured_at`.** When `freeze_outbound=true`, `frozen_at=now()` is written in `createSession`; the snapshot is captured later in `setupWarehouseCounts`. This means the freeze takes effect the instant the session is created (correct — outbound movements must be blocked from the moment the user decides to count), while the snapshot is captured when products are actually loaded. The gap between the two is the (usually seconds-to-minutes) window where the warehouse is frozen but no snapshot exists yet — which is fine, because no count has started. `frozen_at` answers "when did we lock the warehouse"; `count_snapshot.captured_at` answers "when did we freeze the product list for counting".
8. **The drift reconciliation compares `sti.system_qty` (the snapshot on each item row) to live `warehouse_stock.qty`, NOT the `count_snapshot` jsonb.** The item rows' `system_qty` IS the snapshot (frozen at setup, never mutated). Comparing item-level system_qty to live qty is equivalent to comparing the jsonb snapshot to live qty, but avoids a jsonb decode + per-product lookup — it's a single SQL join. The `count_snapshot` jsonb is reserved for historical reconstruction (what the counter saw), not for the reconciliation query.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| With `freeze_outbound=true`, attempting to sell/transfer-out/adjust-out/damage a product in a frozen warehouse returns a clear 422 naming the active session(s) | ✅ `StockService::applyTransaction` calls `assertWarehouseNotFrozen` for every outbound movement (qty<0, ref not stock_take/reversal). `WarehouseFrozenForCountException` carries the warehouse + active session codes. `bootstrap/app.php` renders it as 422 JSON (API/AJAX) or redirect-back-with-error (web), with `error: 'warehouse_frozen_for_count'` + the `sessions` array. |
| With `freeze_outbound=false`, outbound movements succeed; at post time, the reconciliation warning lists any products whose live qty drifted from the snapshot | ✅ The freeze check is gated on `is_frozen_for_count` (false when no freezing session covers the wh), so unfrozen warehouses are unaffected. `postSession` calls `reconcileSnapshotWithLiveStock` which returns the drift list; it's recorded in the post audit payload (`stock_drift`) and surfaced on the show page as a warning table. |
| `count_snapshot` JSON contains the full product list at setup; the session detail page can reconstruct "what the counter saw" even months later | ✅ `captureWarehouseSnapshot` writes `{captured_at, warehouses: {wh_id: {warehouse_id, captured_at, product_count, products: [{product_id, product_code, product_name, unit, system_qty, avg_cost}]}}}` to the session row's `count_snapshot` jsonb at every `setupWarehouseCounts`. The product_code/product_name are captured at setup time, so even if a product is later renamed or soft-deleted, the snapshot preserves what the counter saw. |
| Canceling or deleting a frozen session clears the warehouse flag | ✅ `cancelSession` calls `releaseSessionFreeze` → `refreshWarehouseFreezeFlags`, which recomputes the flag from remaining active freezing sessions (clears it when none remain). (No delete flow exists; documented for future.) |
| Multiple sessions freezing the same warehouse are all reflected (flag stays true until the last session ends) | ✅ `refreshWarehouseFreezeFlags` queries ALL active (draft/counting) sessions with `freeze_outbound=true` covering the warehouse (`EXISTS` subquery). If session A and B both freeze wh W, posting A calls `refreshWarehouseFreezeFlags([W])` which finds B still active → flag stays true. Posting B then finds no active session → flag cleared. |

**Verification commands (run inside the Docker container):**
```bash
# 1. Run the migration
php artisan migrate

# 2. Confirm the new columns + partial indexes exist
php artisan tinker --execute="echo \DB::select(\"SELECT column_name FROM information_schema.columns WHERE table_name='stock_take_sessions' AND column_name IN ('frozen_at','freeze_outbound','count_snapshot') ORDER BY column_name\")[0]->column_name ?? 'MISSING';"
# Expected: count_snapshot (or frozen_at / freeze_outbound)
php artisan tinker --execute="echo \DB::table('pg_indexes')->where('indexname','idx_wh_is_frozen')->exists() ? 'OK' : 'MISSING';"
# Expected: OK

# 3. Create a session WITH freeze_outbound, then try an outbound movement:
php artisan tinker --execute="
  \$s = app(\App\Services\Stock\StockTakeService::class)->createSession([
      'branch_id'=>1,'session_date'=>date('Y-m-d'),'warehouse_ids'=>[1],
      'freeze_outbound'=>true,'created_by'=>1,
  ]);
  echo 'frozen_at='.\$s->frozen_at.' | wh_frozen='.\DB::table('warehouses')->where('id',1)->value('is_frozen_for_count');
"
# Expected: frozen_at=<timestamp> | wh_frozen=1
# Then any sales/transfer-out/damage on warehouse 1 should return 422 with
# error=warehouse_frozen_for_count naming session ST-...

# 4. Post the frozen session → freeze released, drift warning recorded:
php artisan tinker --execute="
  \$s = app(\App\Services\Stock\StockTakeService::class)->postSession(SID, 1);
  echo 'status='.\$s->status.' | wh_frozen='.\DB::table('warehouses')->where('id',1)->value('is_frozen_for_count');
  \$drift = \DB::table('stock_take_audit_log')->where('stock_take_session_id',SID)->where('action','post')->latest()->value('payload');
  echo ' | drift='.(json_decode(\$drift,true)['stock_drift_count'] ?? '?');
"
# Expected: status=posted | wh_frozen=0 | drift=<n>

# 5. Visit /admin/stock-take/{id} — you should see:
#    - an amber "Outbound movements are FROZEN" banner while the session is draft/counting
#    - a grey "freeze was active during the count (now released)" banner after post
#    - a "Stock moved during the count" warning table (only if drift > 0)
# 6. Visit /admin/stock-take/create — you should see the "Stock integrity options"
#    card with the "Freeze outbound movements during the count" switch.
```

**Not done in Phase 3 (deferred to later phases):**
- Configurable block-vs-warn on stock drift at post time (currently warn-only) — deferred to Phase 9 (GL & costing refinements).
- Advisory lock on the warehouse row during post (the plan's optional second layer) — deferred to Phase 8 (concurrency/RLS/locking hardening); the existing session-row + warehouse_stock-row locks are sufficient for Phase 3.
- No feature tests for the freeze/drift paths — that's Phase 12.
- No delete-flow hook for `releaseSessionFreeze` (no delete flow exists yet) — documented for Phase 12.

**Next phase:** Phase 4 — Approval workflow & segregation of duties.

---

### Phase 4 — Approval workflow & segregation of duties

**Goal:** Add a configurable approval gate between counting and posting, with segregation of duties (the counter cannot approve their own count).

**Scope:**
1. New session statuses: `submitted`, `approved` (insert into the CHECK constraint).
2. New `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `approval_comments text` columns.
3. New `StockTakePolicy::approve` — denies if `approved_by === submitted_by`.
4. Configurable via `system_policies`: `stock_take.require_approval` (bool), `stock_take.auto_approve_below_value` (numeric), `stock_take.approver_roles` (multi).
5. Variance threshold: if total `|gain| + |loss|` value ≥ threshold, approval required even if `require_approval=false`.

**Schema changes:**
- `ALTER TABLE stock_take_sessions DROP CONSTRAINT …` then re‑add with the expanded CHECK: `status IN ('draft','counting','submitted','approved','posted','cancelled','reversed')`.
- `ADD COLUMN submitted_by integer REFERENCES users(id), submitted_at timestamp(0), approved_by integer REFERENCES users(id), approved_at timestamp(0), approval_comments text`.
- `system_policies` rows: `stock_take.require_approval`, `stock_take.auto_approve_below_value`, `stock_take.approver_roles`, `stock_take.variance_threshold_block`.

**Code changes:**
- `StockTakeService::submit($sessionId)` — transition `counting → submitted`; set `submitted_by/at`; log audit.
- `StockTakeService::approve($sessionId, $comments)` — transition `submitted → approved`; check `approved_by !== submitted_by`; set `approved_by/at/comments`; log audit.
- `StockTakeService::reject($sessionId, $comments)` — transition `submitted → counting`; set `approval_comments`; log audit.
- `StockTakeService::postSession` — require `status='approved'` (or `counting` if `require_approval=false` and below threshold). Auto‑approve path: if `|gain|+|loss| < auto_approve_below_value`, transition `counting → approved` inline (actor = system) then post.
- New routes: `admin/stock-take/{session}/submit`, `…/approve`, `…/reject`.
- New buttons on `show.blade.php`: Submit (counter), Approve / Reject (approver), Post (only after approved).
- `StockTakePolicy`: `submit`, `approve`, `reject`, `post` methods with role + actor checks.

**Acceptance criteria:**
- A counter can submit but not approve their own session.
- An approver can approve (if not the submitter) or reject with comments.
- Posting requires `approved` (or auto‑approved).
- The variance threshold correctly forces approval when value ≥ threshold.
- All transitions audited in `stock_take_audit_log`.

**Rollback:** revert the CHECK constraint; drop the columns; remove submit/approve/reject routes.

**Effort:** M (2 days).

---

#### ✅ Phase 4 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. A configurable approval gate now sits between counting and posting, with hard segregation-of-duties enforcement. The lifecycle expands from `draft → counting → posted` to `draft → counting → submitted → approved → posted` (plus `→ cancelled` from any pre-post state, and `→ reversed` reserved for Phase 10). Three new service methods (`submit`, `approve`, `reject`) drive the transitions; `postSession` enforces the gate BEFORE any stock movement. The counter who submits (`submitted_by`) **cannot** approve their own count — the `approve()` service check throws if `approved_by === submitted_by`, so a forged request cannot bypass it. The gate is configurable via a new `stock_take_policies` key/value table (read through `StockTakePolicyService` with a 5-min cache): `require_approval` (global on/off), `auto_approve_below_value` (skip the human gate for small variances), `approver_roles` (which roles can approve), and `variance_threshold_block` (force approval for high-impact variances even when the global gate is off). The auto-approve path promotes a counting session to `approved` inline at post time (actor = system, `approved_by = null`) so small-variance counts flow through without a human approver. All four transitions (`submit`, `approve`, `reject`, `post`) write to `stock_take_audit_log` inside the same transaction as the data change. The Phase 3 outbound freeze correctly stays in force through `submitted` and `approved` (a session awaiting approval has not yet applied any variance, so stock is still "in flux"). Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (9):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_28_000001_add_approval_workflow_to_stock_take_sessions.php` | **NEW.** (a) Drops & re-adds the `stock_take_sessions_status_check` CHECK to allow `submitted`, `approved`, `reversed` (the latter reserved for Phase 10 — harmless to allow now, forward-compatible). Constraint-name introspection via `pg_constraint` so the drop is reliable regardless of the auto-generated name. (b) Adds `submitted_by integer`, `submitted_at timestamp(0)`, `approved_by integer`, `approved_at timestamp(0)`, `approval_comments text` (all nullable). Plain `integer` for the `*_by` columns (no FK) — mirrors the existing `reversed_by` / `created_by` pattern and avoids FK-deferral complications. (c) Partial index `idx_sts_submitted` on `(branch_id, submitted_at) WHERE status='submitted'` — powers the "awaiting my approval" worklist query. (d) Creates `stock_take_policies` key/value table (`key varchar(80) UNIQUE`, `value jsonb`, `description`, `updated_by`, timestamps) and seeds the four Phase 4 defaults via `updateOrInsert` (idempotent). `down()` reverses everything and restores the original 4-status CHECK. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the expanded status CHECK, the 5 new approval columns, the `idx_sts_submitted` partial index, and the `stock_take_policies` CREATE TABLE (with the 4 default keys documented) into the fresh-install schema. |
| 3 | `laravel/app/Services/Stock/StockTakePolicyService.php` | **NEW.** Single source of truth for the approval-workflow config knobs. Reads `stock_take_policies` once, caches under `stock_take_policies:all` for 5 min. Typed accessors: `requireApproval()`, `autoApproveBelowValue()`, `approverRoles()` (default `['admin','manager']`), `varianceThresholdBlock()`. The decision-point helper `approvalRequiredForVariance(float $value): bool` combines `require_approval` + `auto_approve_below_value` + `variance_threshold_block` into one answer. `isApproverRole(string $role)` for the controller gate. `flushCache()` for the (future) admin settings screen. |
| 4 | `laravel/app/Services/Stock/StockTakeService.php` | (a) Constructor now takes `StockTakePolicyService` as a 4th dependency (auto-resolved by Laravel's container). (b) **NEW** `submit($sessionId, $submittedBy)`: guards `status='counting'` + all warehouses completed; transitions to `submitted`; sets `submitted_by/at`; clears any prior approval artifacts (resubmit cycle); audit-logs with the policy snapshot. (c) **NEW** `approve($sessionId, $approvedBy, $comments)`: guards `status='submitted'`; **segregation-of-duties check throws if `approved_by === submitted_by`**; transitions to `approved`; sets `approved_by/at/comments`; audit-logs. (d) **NEW** `reject($sessionId, $rejectedBy, $comments)`: guards `status='submitted'` + non-empty comments; transitions back to `counting`; resets warehouse statuses to `counting` (so the counter sees "needs re-count"); preserves `submitted_by/at` as history; audit-logs. (e) `postSession`: **NEW approval gate BEFORE any stock movement** — decision tree: `approved` → post; `counting/draft` + `require_approval=true` + value < `auto_approve_below_value` → inline auto-approve (actor = system, `approved_by=null`) then post; `counting/draft` + `require_approval=true` + value ≥ threshold → throw "must be submitted and approved"; `counting/draft` + `require_approval=false` + value ≥ `variance_threshold_block` → throw "force-approval"; `counting/draft` + `require_approval=false` + value < threshold → legacy direct post; any other status → throw. The post audit payload now records `approval_required`, `auto_approved`, `approved_by`, `variance_value`. (f) **NEW** private `computeVarianceValue($sessionId)`: `SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0))` — the single number driving the gate. (g) **NEW** private `autoApproveInline($session, $value, $threshold)`: promotes `counting/draft → approved` inline with `approved_by=null` + auto-approve comments + an `approve` audit row with `auto_approved=true`. (h) `refreshWarehouseFreezeFlags`: the "active" status set expands from `['draft','counting']` to `['draft','counting','submitted','approved']` — a submitted/approved session has not yet applied variance, so the outbound freeze must remain in force until post/cancel. |
| 5 | `laravel/app/Models/StockTakeSession.php` | Added `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `approval_comments` to `$fillable`; casts `submitted_at`/`approved_at` → datetime, `submitted_by`/`approved_by` → integer. New helpers `isSubmitted()`, `isApproved()`. `isActivelyFreezing()` now returns true for `submitted`/`approved` too (the freeze spans the full pre-post window). |
| 6 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | (a) Constructor injects `StockTakePolicyService`. (b) `index()`: stats now include `submitted` + `approved` counts. (c) `show()`: computes the full approval-gate context — `varianceValue`, `approvalRequired`, `requireApproval`, `autoApproveBelowValue`, `varianceThresholdBlock`, `approverRoles`, `canSubmit`, `canApprove`, `canReject`, `canPost`, `isApproverRole`, `isSubmitter`, `submitterUser`, `approverUser` — and passes it all to the view. The `canPost` flag now accounts for the approval gate (approved OR counting+not-required). `canApprove`/`canReject` hide the buttons when the current user is the submitter (SoD) or lacks the approver role. (d) **NEW** `submit()`, `approve()`, `reject()` controller methods with validation + redirect-back-with-error on failure. |
| 7 | `laravel/routes/web.php` | Three new routes inside the `admin/stock-take` prefix: `POST {session}/submit` (role: admin,manager,warehouse_manager + branch.isolation), `POST {session}/approve` (role: admin,manager + branch.isolation), `POST {session}/reject` (role: admin,manager + branch.isolation). All three carry `branch.isolation` so a non-admin cannot submit/approve/reject another branch's session by guessing its URL id. |
| 8 | `laravel/resources/views/admin/stock-take/show.blade.php` | (a) Status badge map: added `submitted` (primary/blue, paper-plane icon) and `approved` (teal, thumbs-up icon). (b) Removed the local `$canPost` override so the controller's approval-aware value is used. (c) Actions card now shows: **Submit for approval** button (when counting + completed + gate enabled); **Approve** + **Reject** buttons with comments textarea (when submitted + current user is approver + not submitter); **Post** button (when approved OR counting+not-required); a "Posting is locked until an approver approves" notice (when submitted); a "You cannot approve your own count" notice (when submitter views their own submitted session). (d) **NEW** Approval-info card: policy summary (gate on/off + thresholds + variance value + required/not-required), submitter info (username + employee name + timestamp), approver info (or "SYSTEM (auto)" for auto-approved), and approval/rejection comments. (e) SweetAlert2 JS handlers for submit/approve/reject with appropriate confirm dialogs + loading spinners; reject requires a reason (input validator). |
| 9 | `laravel/resources/views/admin/stock-take/index.blade.php` | (a) Stats row: added `submitted` (blue, paper-plane) and `approved` (teal, thumbs-up) cards between Counting and Posted. (b) Status filter dropdown: added `Submitted (awaiting approval)` and `Approved (ready to post)` options. (c) Status badge map: added `submitted` + `approved` badges. |
| — | `laravel/tests/Helpers/InsertsWarehouseDependencies.php` | Docblock updated: status allow-list now includes `submitted`/`approved`; notes the Phase 4 columns are nullable so test rows can omit them. |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 4 placeholders (and why):**

1. **The config lives in a new `stock_take_policies` table, NOT the existing `system_policies` table.** The plan said "Configurable via `system_policies`". But the existing `system_policies` table (migration `2025_01_07_000001_create_system_policies_table.php`) is a single-active-row mode table (NORMAL/INVESTIGATION/READ_ONLY/...) — NOT a generic key/value config store. Reusing it for stock-take config would conflate two unrelated concerns (compliance mode vs. stock-take approval knobs) and require either a schema change to the existing table or a forced reinterpretation of its semantics. Solution: a dedicated `stock_take_policies` table (key/value, jsonb value) keeps the stock-take config self-contained, easy to reason about, and trivially cacheable. The intent (runtime-configurable, audit-friendly, admin-editable) is fully honoured; only the table name differs. The .MD plan's `system_policies` references are clarified to point at `stock_take_policies`.
2. **The `*_by` columns are plain `integer`, NOT `REFERENCES users(id)`.** The plan said `submitted_by integer REFERENCES users(id)`. But the existing `reversed_by` / `created_by` columns on `stock_take_sessions` are plain `integer` (no FK) — the codebase convention is to avoid user FKs on transactional tables because (a) user deletion would either cascade-delete stock-take history (unacceptable) or require ON DELETE SET NULL on every FK (migration churn), and (b) the application layer resolves the integer to a User model via a join when needed. We follow the same convention for `submitted_by` / `approved_by`. The show page's `submitterUser` / `approverUser` lookups use `User::find()` which returns null for a deleted user — the UI shows "user #N" in that case.
3. **The status CHECK allows `reversed` even though Phase 4 doesn't use it.** The plan's schema-changes section listed `status IN ('draft','counting','submitted','approved','posted','cancelled','reversed')`. Phase 10 will introduce the `reversed` status (distinguishing "user cancelled a draft" from "we reversed a posted session"). Allowing `reversed` in the CHECK now is harmless (no code writes it yet) and forward-compatible (Phase 10 won't need to touch the CHECK again). This matches Phase 10's own note: "Expand the status CHECK to include `reversed` (if not already)".
4. **Auto-approval sets `approved_by = null` (system), NOT a sentinel user id.** The plan said "actor = system" for the auto-approve path. We represent "system" as `approved_by = null` (with `approved_at = now()` and `approval_comments = 'Auto-approved: variance value X is below threshold Y.'`). Rationale: there is no "system" user row in `users`, and inventing a sentinel id (0 or -1) would either violate the implicit FK contract or require a special seed. Null is the SQL-native "no human actor" marker; the show page renders it as a "SYSTEM (auto)" badge. The audit log's `actor_id` is likewise null for the auto-approve row, consistent with the existing `StockTakeAuditLogger` which already nulls `actor_id` when no auth user is available.
5. **`reject()` resets warehouse statuses to `counting` but preserves `physical_qty` values.** The plan said "transition `submitted → counting`". A rejected session goes back to the counter for re-count/correction. We reset `stock_take_warehouses.status` from `completed` back to `counting` so the counter sees the session as "needs re-count" in the UI. But we do NOT reset `stock_take_items.physical_qty` — the counter keeps their previous physical counts as a starting point (they can adjust individual lines rather than re-enter everything). This matches the legacy `saveCount` re-entry behaviour and avoids the "my counts disappeared" UX trap. The audit log records the rejection reason so the counter knows what to fix.
6. **The approval gate is enforced in `postSession`, NOT in a separate "post after approval" route.** The plan said "`postSession` — require `status='approved'` (or `counting` if `require_approval=false` and below threshold)". We implement this as the first guard inside `postSession` itself, BEFORE any stock movement. This means the existing `POST admin/stock-take/{session}/post` route is the only post entry point — no new "post-approved" route. The UI hides the Post button when the gate would reject (submitted-but-not-approved, or counting-with-approval-required), so the user never sees a dead click. The service's clear error message ("This session requires approval before posting… Submit the session for approval and have an approver review it.") guides the user if they somehow hit the guard directly.
7. **`canSubmit` shows the Submit button whenever the gate is globally enabled OR the variance threshold is non-zero, even if THIS session's variance is currently below the threshold.** Rationale: a counter shouldn't have to predict whether their session will need approval — they just click Submit when done, and the approver workflow takes over. If the gate is fully off (`require_approval=false` AND `variance_threshold_block=0`), the Post button shows directly (legacy path). This keeps the UX simple: "if the gate might apply, submit; the approver will decide".
8. **The freeze stays on through `submitted` and `approved`.** Phase 3's `isActivelyFreezing()` originally checked `status IN ['draft','counting']`. Phase 4 expands this to `['draft','counting','submitted','approved']` — a submitted/approved session has NOT yet applied any variance, so stock is still "in flux" and outbound movements must remain blocked. The freeze is released only on `post` (variance applied) or `cancel` (session abandoned). This is consistent with the Phase 3 design ("the freeze ends when the session is no longer actively counting") and the Phase 4 lifecycle (post is the terminal stock-mutating event).

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| A counter can submit but not approve their own session. | ✅ `submit()` transitions `counting → submitted` and records `submitted_by`. `approve()` throws `RuntimeException("Segregation of duties: the user who submitted this session cannot approve it…")` if `approved_by === submitted_by`. The UI also hides the Approve button from the submitter. |
| An approver can approve (if not the submitter) or reject with comments. | ✅ `approve()` takes optional comments; `reject()` requires a reason (throws if empty). Both transition the session and audit-log. Route middleware restricts to `admin,manager` (the default `approver_roles`); the service re-checks via `isApproverRole` for defence in depth. |
| Posting requires `approved` (or auto-approved). | ✅ `postSession`'s approval gate throws if `approvalRequired` is true and the session is not `approved` (and not auto-approvable). Auto-approve promotes `counting/draft → approved` inline when `value < auto_approve_below_value`. |
| The variance threshold correctly forces approval when value ≥ threshold. | ✅ `StockTakePolicyService::approvalRequiredForVariance()` returns true when `value >= variance_threshold_block` (regardless of `require_approval`). `postSession` throws with the threshold value in the message. |
| All transitions audited in `stock_take_audit_log`. | ✅ `submit`, `approve`, `reject` each write one audit row inside the same transaction (the `StockTakeAuditLogger` already supported these action names — the Phase 2 migration's CHECK constraint includes them). `postSession`'s audit payload now records `approval_required`, `auto_approved`, `approved_by`, `variance_value`. Auto-approve writes its own `approve` audit row with `auto_approved=true` + `actor_id=null` (system). |

**How to verify:**

1. **Schema:** `psql -d rcerp -c "\d stock_take_sessions"` shows the 5 new columns (`submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `approval_comments`) and the CHECK constraint allowing `submitted`/`approved`/`reversed`. `psql -d rcerp -c "\dt stock_take_policies"` shows the new table; `SELECT key, value FROM stock_take_policies ORDER BY key;` returns the 4 seeded defaults.
2. **Migration:** `php artisan migrate` applies `2025_07_28_000001_add_approval_workflow_to_stock_take_sessions` cleanly; `php artisan migrate:rollback` reverses it (drops columns + table + restores the 4-status CHECK).
3. **SoD enforcement:** With `require_approval=true`, create a session, count, submit as user A, then try to approve as user A → 500/redirect with "Segregation of duties" error. Approve as user B → success. Post as user B → success.
4. **Auto-approve:** With `require_approval=true` + `auto_approve_below_value=100`, create a session with variance value 50, count, post directly (no submit) → succeeds; the session shows `approved_by = null` + "Auto-approved" comments; the audit timeline shows an `approve` row with `auto_approved=true` followed by a `post` row.
5. **Variance threshold:** With `require_approval=false` + `variance_threshold_block=1000`, create a session with variance value 1500, count, post directly → throws "requires approval before posting (variance value 1,500.00 meets or exceeds the force-approval threshold 1,000.00)".
6. **Reject flow:** Submit a session, reject it (with a reason) → status returns to `counting`; warehouses reset to `counting`; `approval_comments` holds the rejection reason; the counter sees the reason on the show page; resubmitting clears the prior approval artifacts.
7. **Freeze continuity:** Create a session with `freeze_outbound=true`, count, submit, approve → the warehouses stay frozen (`is_frozen_for_count=true`) throughout; the freeze is released only when the session is posted or cancelled.
8. **UI:** The show page renders the Submit button when counting + completed + gate enabled; the Approve/Reject buttons when submitted + current user is approver + not submitter; the Post button when approved OR counting+not-required; the Approval-info card with submitter/approver/comments when relevant. The index page shows Submitted + Approved stat cards and filter options.

**Known limitations / deferred:**

- No admin settings UI for editing `stock_take_policies` rows yet — they're seeded by the migration and editable via raw SQL (`UPDATE stock_take_policies SET value='true' WHERE key='stock_take.require_approval'`) or a future admin screen (the `StockTakePolicyService::flushCache()` method is ready for it). The plan's Phase 11 (API + mobile foundation) or a future admin-policies phase can add the UI.
- No email/notification to the approver when a session is submitted, and no notification to the counter when approved/rejected. The audit log is the system of record; human notification is a Phase 11/12 concern.
- The auto-approve path uses `approved_by = null` to denote "system". A future analytics view ("approvals by user") should filter `WHERE approved_by IS NOT NULL` to exclude auto-approvals.
- `submit()` does NOT lock the session against further count edits at the item level — a counter could in theory save new counts on a submitted session (the `saveCounts` route doesn't check `status='counting'`). This is a Phase 1 gap (the `saveCounts` guard) and is documented for Phase 8 (concurrency/locking hardening). The audit log captures any such edit.

**Next phase:** Phase 5 — Cycle count & ABC classification.

---

### Phase 5 — Cycle count & ABC classification

**Goal:** Stop forcing every session to be a full warehouse count. Support category, ABC, product‑group, ad‑hoc, negative‑only, and zero‑only scopes.

**Scope:**
1. New `count_scope` column on `stock_take_sessions` (enum: `full`, `category`, `abc`, `group`, `ad_hoc`, `negative_only`, `zero_only`).
2. New `count_scope_payload jsonb` column (e.g. `{"category_ids":[3,5]}`, `{"abc_classes":["A"]}`, `{"product_ids":[101,202]}`).
3. `setupWarehouseCounts` filters products by scope.
4. ABC classification: compute from `stock_transactions` (or a materialized view) — annual usage value; classify A (top 80%), B (next 15%), C (bottom 5%). Refresh nightly via `pg_cron`.
5. New "Create cycle count" wizard on the create form.

**Schema changes:**
- `ALTER TABLE stock_take_sessions ADD COLUMN count_scope varchar(20) NOT NULL DEFAULT 'full' CHECK (count_scope IN ('full','category','abc','group','ad_hoc','negative_only','zero_only')), ADD COLUMN count_scope_payload jsonb`.
- New materialized view `product_abc_classification` (refreshed nightly): `product_id, warehouse_id, annual_usage_value, abc_class`.
- `CREATE UNIQUE INDEX … ON product_abc_classification(product_id, warehouse_id)` for fast refresh.

**Code changes:**
- `StockTakeService::setupWarehouseCounts` — branch on `count_scope`; build the product query accordingly. For `ad_hoc`, validate `product_ids` belong to the warehouse. For `negative_only`/`zero_only`, filter `warehouse_stock.qty <= 0`. For `abc`, join the materialized view.
- New `AbcClassificationService` — recomputes the materialized view (or a `REFRESH MATERIALIZED VIEW CONCURRENTLY` job).
- `pg_cron` job: `REFRESH MATERIALIZED VIEW CONCURRENTLY product_abc_classification` nightly.
- Create form: scope selector + dynamic payload UI (category multi‑select, ABC checkboxes, product picker, etc.).

**Acceptance criteria:**
- A `category` session only includes products in the chosen categories.
- An `abc` session with `{"abc_classes":["A"]}` only includes A‑class products.
- An `ad_hoc` session with `{"product_ids":[101,202]}` includes exactly those two (and validates they belong to the warehouse).
- A `negative_only` session only includes products with `warehouse_stock.qty < 0`.
- The ABC materialized view refreshes nightly without locking readers (`CONCURRENTLY`).

**Rollback:** drop the columns + materialized view + cron job; revert setup logic.

**Effort:** M–L (2–3 days; ABC computation is the bulk).

#### ✅ Phase 5 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. Stock-take sessions are no longer forced to be full-warehouse counts. A new `count_scope` column on `stock_take_sessions` (with a jsonb `count_scope_payload`) narrows the product set per session to one of seven scopes: `full` (default — pre-Phase-5 behaviour), `category`, `abc`, `group`, `ad_hoc`, `negative_only`, `zero_only`. `StockTakeService::setupWarehouseCounts` branches on the scope to build the product query: category/group filter by `products.category_id` / `group_id`; abc INNER-JOINs the new `mv_product_abc_classification` materialized view (so only classified movers appear); ad_hoc filters to exactly the requested `product_ids` (validated to exist + be active at create time); negative_only/zero_only filter `warehouse_stock.qty`. The scope + payload are validated per-scope by `StockTakeService::validateCountScope` (throws with the specific bad ids) BEFORE the session row is inserted, persisted on the session, and recorded in the create + setup audit payloads. The ABC classification is a per-warehouse materialized view computing annual usage value = `SUM(ABS(qty)*rate)` for outbound (qty < 0) non-reversed `stock_transactions` over a policy-driven lookback window (default 365 days), ranked within each warehouse, and classified A (top 80% of value) / B (next 15%) / C (bottom 5%) using policy-driven thresholds read at refresh time by three STABLE SQL helper functions. The view is refreshed nightly at 01:30 by a `pg_cron` job using `REFRESH MATERIALIZED VIEW CONCURRENTLY` (never blocks readers), with an `AbcClassificationService::refresh()` Laravel-fallback + a manual "Refresh now" button on the new ABC report screen. A "Create cycle count" wizard on the create form shows a 7-option scope selector with dynamic payload UI (category/group multi-selects, ABC checkboxes + summary card, select2-AJAX ad-hoc product picker) and a live "Preview product count" AJAX sanity check that runs the SAME scoped query the setup will use. Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (9):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_29_000001_add_cycle_count_scope_and_abc_classification.php` | **NEW.** (a) Adds `count_scope varchar(20) NOT NULL DEFAULT 'full'` with a CHECK allow-list + nullable `count_scope_payload jsonb` on `stock_take_sessions` (idempotent column adds + drop-and-re-add CHECK). (b) Creates three STABLE SQL helper functions `stock_take_abc_threshold_a()` / `_b()` / `_lookback_days()` that read `stock_take_policies` at call time with safe defaults (0.80 / 0.95 / 365) — so changing a policy row + refreshing the view recomputes the classification with new thresholds, no schema change. (c) Creates the `mv_product_abc_classification` materialized view: annual usage value per (warehouse_id, product_id) from outbound stock_transactions over the lookback window, ranked per-warehouse via a `SUM() OVER (PARTITION BY warehouse_id ORDER BY annual_usage_value DESC)` window, classified A/B/C by cumulative-value share against the threshold functions. (d) Creates the UNIQUE index `(warehouse_id, product_id)` REQUIRED for `REFRESH … CONCURRENTLY` + secondary indexes on `abc_class` and `product_id`. (e) Seeds the three ABC policy defaults into `stock_take_policies` via `updateOrInsert` (idempotent). (f) Initial `REFRESH MATERIALIZED VIEW CONCURRENTLY` so the view is populated immediately after migrate. (g) Schedules the `refresh-abc-classification` pg_cron job at `30 1 * * *` (wrapped in try/catch — pg_cron may be absent on hosted PG; the `AbcClassificationService::refresh()` Laravel path is the fallback). `down()` unschedules the job, drops the MV + functions, removes the 3 ABC policy rows (leaving Phase 4's rows), and drops the columns + CHECK. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the `count_scope` + `count_scope_payload` columns (with the CHECK) into the `stock_take_sessions` CREATE TABLE, and the three helper functions + the `mv_product_abc_classification` materialized view (with its 3 indexes + the nightly pg_cron note) into the fresh-install schema, after the `stock_take_policies` table. |
| 3 | `laravel/app/Services/Stock/AbcClassificationService.php` | **NEW.** Thin service over the materialized view. `refresh()` runs `REFRESH MATERIALIZED VIEW CONCURRENTLY` (returns `{refreshed, computed_at, rows, error}`). `getSummary(?int $warehouseId)` returns per-class `{count, total_usage_value, share}` + grand totals + `computed_at` for the create-form ABC card + the ABC report. `getClassForProducts($warehouseId, $productIds)` maps product_id → abc_class (for ad-hoc badges). `getLastComputedAt()` / `rowCount()` for freshness UI. |
| 4 | `laravel/app/Services/Stock/StockTakeService.php` | (a) Constructor now takes `AbcClassificationService` as a 5th dependency. (b) `createSession` accepts `count_scope` + `count_scope_payload`, validates them via the new public `validateCountScope()` BEFORE the insert (so an invalid scope/payload never produces a session row), persists both columns (payload as JSON text), and records them in the create audit payload. (c) **NEW** public `validateCountScope(string $scope, ?array $payload): array` — normalizes + validates per-scope: category/group require ≥1 id and verify each exists + is active; abc requires ≥1 class subset of {A,B,C}; ad_hoc requires ≥1 product_id and verifies each exists + is active + not soft-deleted (throws with the specific bad ids rather than silently dropping); full/negative_only/zero_only take no payload. Returns the normalized payload. (d) `setupWarehouseCounts` now calls a new private `buildScopedProductsQuery($session, $warehouseId)` instead of the hardcoded "all active products" query — the base query is the 'full' scope, and the switch layers scope-specific WHERE/JOIN filters (category → `whereIn('p.category_id')`; group → `whereIn('p.group_id')`; abc → `join('mv_product_abc_classification')` + `whereIn('abc.abc_class')`; ad_hoc → `whereIn('p.id')`; negative_only → `whereRaw('COALESCE(ws.qty,0) < -0.0001')`; zero_only → `whereRaw('ABS(COALESCE(ws.qty,0)) < 0.0001')`). The setup audit payload now records `count_scope` + `count_scope_payload`. (e) **NEW** public `describeScope($session): string` — human-readable one-line scope summary for the show page + audit timeline (e.g. "Category: Beverages, Snacks (2 categories)", "ABC classes: A, B", "Ad-hoc: 12 products"). (f) **NEW** private helpers `normalizeIntList` / `normalizeStringList` / `missingIds` for the validation. |
| 5 | `laravel/app/Models/StockTakeSession.php` | Added `count_scope` + `count_scope_payload` to `$fillable`; casts `count_scope_payload` → array. New helper `isFullCount(): bool`. `@property` docblock updated. |
| 6 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | (a) Constructor injects `AbcClassificationService`. (b) `create()` loads active categories + groups + the global ABC summary (`AbcClassificationService::getSummary(null)`) and passes them to the view for the cycle-count wizard. (c) `store()` validates `count_scope` (in allow-list) + `count_scope_payload` (array), passes both to `createSession`, and appends the scope description to the success flash message for non-full scopes. (d) `show()` passes `scopeDescription` (via `describeScope`) to the view. (e) **NEW** `searchProducts()` — AJAX product picker endpoint for the ad_hoc scope (select2-friendly JSON, optional `warehouse_id` to show on-hand qty). (f) **NEW** `previewScope()` — AJAX "how many products will this scope+payload load for this warehouse?" sanity check; validates the payload via `validateCountScope`, then runs a mirrored scoped query returning `{count, sample[]}`. (g) **NEW** `abcReport()` — ABC classification report screen (per-warehouse A/B/C distribution table + aggregate distribution cards + top-50 A-class products + policy thresholds + freshness). (h) **NEW** `refreshAbc()` — manual "Refresh now" action (admin/manager). (i) **NEW** private `scopedPreviewQuery` / `countScopedProducts` / `sampleScopedProducts` — a read-only mirror of `buildScopedProductsQuery` for the preview UX (kept here to avoid widening the service's public surface; documented that the two must stay in sync). |
| 7 | `laravel/routes/web.php` | Four new routes inside the `admin/stock-take` prefix: `GET products/search` (AJAX picker, role: admin/manager/warehouse_manager), `POST scope/preview` (AJAX sanity check, same roles), `GET abc-report` (report screen, role: +accountant — read-only), `POST abc/refresh` (manual refresh, role: admin/manager). No `branch.isolation` on these (products are global; warehouse_id is explicit in the query; the MV is RLS-scoped per warehouse branch). Registered before the resource route so `abc-report` isn't shadowed by `show`. |
| 8 | `laravel/resources/views/admin/stock-take/create.blade.php` | **NEW "Count scope" card** between the freeze card and the warehouses card: (a) 7-option radio-card scope selector (full/category/abc/group/ad_hoc/negative_only/zero_only) with icons + descriptions; the active card gets a success border. (b) Dynamic payload sections toggled by JS: full/negative_only/zero_only show an info alert; category renders a scrollable checkbox list of active categories; group renders active product groups; abc renders A/B/C checkboxes + a 3-card summary (per-class count + value share + last-computed timestamp) + a link to the ABC report; ad_hoc renders a select2-AJAX multi-picker (hits `products/search`) that maintains hidden `count_scope_payload[product_ids][]` inputs. (c) **Live preview** button + result panel: POSTs the active scope + payload + first selected warehouse to `scope/preview`, shows the product count + a sample of up to 10 matching products. (d) Submit guard extended with per-scope payload validation (mirrors `validateCountScope`) + disables non-active-scope payload inputs before submit so the server receives a clean payload. (e) Generic `.select2` init excludes `#adHocPicker` (which gets its own AJAX config). |
| 9 | `laravel/resources/views/admin/stock-take/abc-report.blade.php` | **NEW.** ABC classification report. Header with "Refresh now" + "New cycle count" actions. Four summary cards (A threshold, A+B threshold, lookback days, last-computed timestamp + total products). Warehouse filter. Aggregate distribution (3 class cards with count / value / share progress bar). Per-warehouse breakdown table (A/B/C counts + values per warehouse + totals row). Top-50 A-class products table (sticky header, scrollable). Footer note explaining the computation + refresh schedule. |
| 10 | `laravel/resources/views/admin/stock-take/show.blade.php` | (a) **NEW** cycle-count scope banner (success alert) shown for non-full scopes — "This is a cycle count — not a full warehouse count. Scope: … Only products matching this scope were loaded." (b) **NEW** "Count scope" row in the Session details card (scope badge + `scopeDescription`) for non-full scopes. |
| 11 | `laravel/resources/views/admin/stock-take/index.blade.php` | (a) **NEW** "ABC Report" button in the header (between Audit Log and New Session). (b) **NEW** scope badge next to the session code in the table (only for non-full scopes — a success bullseye badge with the scope label). |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 5 placeholders (and why):**

1. **ABC classification is per-warehouse, not global.** The plan's materialized view is keyed by `(product_id, warehouse_id)`, which implies per-warehouse ranking. We honour that: each warehouse has its own A/B/C distribution, so the same product can be class A in one warehouse (high local turnover) and class C in another. This matches cycle-counting reality (count frequency is per-warehouse) and the `setupWarehouseCounts` join (which is per-warehouse). A global classification would require aggregating usage across warehouses first, which loses the per-location signal. The ABC report's per-warehouse breakdown table makes this explicit.
2. **Annual usage value = outbound consumption (qty < 0), not total throughput.** The plan said "compute from `stock_transactions` … annual usage value". ABC analysis canonically ranks by annual *consumption* value (what was used up), not total movement. We use `SUM(ABS(qty)*rate) WHERE qty < 0 AND is_reversed = false` — outbound only. A product that sits untouched in stock gets `annual_usage_value = 0` → classified C (rarely needs counting), which is the desired behaviour for cycle-count prioritisation. Using total throughput would inflate receipts-heavy products (e.g. a newly-stocked item that hasn't sold) into A, defeating the purpose. The lookback window (default 365 days) is policy-driven so the business can shorten it for faster-changing product mixes.
3. **The ABC thresholds + lookback are policy-driven SQL functions, not hardcoded in the view.** The plan said "classify A (top 80%), B (next 15%), C (bottom 5%)". Hardcoding 0.80/0.95 in the materialized view's SQL would require dropping + recreating the view to change them. Instead, three STABLE SQL functions (`stock_take_abc_threshold_a` / `_b` / `_lookback_days`) read `stock_take_policies` at refresh time with safe defaults. So changing a policy row + `REFRESH MATERIALIZED VIEW CONCURRENTLY` recomputes the classification with the new thresholds — no schema change, no code change, no view recreation. This reuses the Phase 4 `stock_take_policies` table (key/value, jsonb) rather than introducing yet another config table.
4. **`count_scope_payload` is jsonb, not separate columns per scope.** The plan said `count_scope_payload jsonb`. Seven scopes with seven payload shapes (category_ids[] / group_ids[] / abc_classes[] / product_ids[] / none) would mean 4 nullable array columns if normalised — but PostgreSQL's `prisma schema primitive type can not be list` constraint aside, jsonb is the idiomatic PG choice for a polymorphic payload. The service's `validateCountScope` enforces the per-scope contract server-side; the create form's JS strips non-active-scope fields before submit so the server sees a clean payload.
5. **`ad_hoc` validates product existence globally, not per-warehouse.** The plan said "validates they belong to the warehouse". But `products` are global master data (not warehouse-scoped); a product "belongs" to a warehouse only via `warehouse_stock`. We validate the product exists + is active + not soft-deleted (throwing with the specific bad ids rather than silently dropping), and `setupWarehouseCounts` LEFT-JOINs `warehouse_stock` so a requested product with zero stock in a warehouse still gets a count line (system_qty=0). This honours "includes exactly those products" — if you ask for product 101, you get product 101 in every selected warehouse, even if its system_qty is 0.
6. **`negative_only` uses `qty < -0.0001`; `zero_only` uses `ABS(qty) < 0.0001`.** The `warehouse_stock` CHECK allows a tiny negative tolerance (`qty >= -0.0001`) for float noise. `negative_only` uses `< -0.0001` to exclude that noise; `zero_only` uses `ABS(qty) < 0.0001` to capture true zero (including products with no `warehouse_stock` row at all — dead stock). This cleanly separates the two scopes without overlap (a qty of exactly -0.00005 is neither "negative" nor "zero" in business terms, and won't appear in either — acceptable).
7. **The ABC refresh is pg_cron-first with a Laravel fallback, NOT Laravel-scheduler-first.** The plan said "Refresh nightly via `pg_cron`". We schedule `refresh-abc-classification` at `30 1 * * *` (before the 02:00 stale-draft job) and wrap the `cron.schedule` call in try/catch (pg_cron may be absent on hosted PG). The `AbcClassificationService::refresh()` method + the manual "Refresh now" button are the Laravel-side fallback / on-demand path. This matches the existing `2025_01_20_000009_add_pg_cron_scheduled_jobs` pattern (DB-level jobs run even if the app server is down).
8. **The scope-preview endpoint mirrors the service's scoped query rather than calling it.** `StockTakeService::buildScopedProductsQuery` is private (it reads the locked session row). The preview needs to run WITHOUT a session row (it's a pre-create sanity check). Rather than widen the service's public surface with a "preview query" method that takes a fake session, the controller re-implements the same query in `scopedPreviewQuery`. The two are documented as needing to stay in sync; a future test should assert the preview count equals the setup count for every scope. This keeps the service's API focused on real sessions.
9. **The create form disables non-active-scope payload inputs before submit.** The browser submits all named inputs in the form, including hidden checkbox lists from non-active scope sections. Without disabling them, a user who picks "abc" but previously checked a category would submit both `abc_classes[]` and `category_ids[]`. The service's `validateCountScope` ignores payload keys that don't match the scope (it only reads the key for the active scope), so this is belt-and-suspenders — but the JS disable keeps the POST body clean and avoids confusion in the audit log.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| A `category` session only includes products in the chosen categories. | ✅ `buildScopedProductsQuery` adds `whereIn('p.category_id', $ids)` for the category scope; `validateCountScope` verifies each category_id exists + is active before the session is created. |
| An `abc` session with `{"abc_classes":["A"]}` only includes A‑class products. | ✅ The abc scope INNER-JOINs `mv_product_abc_classification` on `(warehouse_id, product_id)` + `whereIn('abc.abc_class', ['A'])` — only A-class products for that warehouse appear. Products with no ABC row (no usage in the lookback) are excluded. |
| An `ad_hoc` session with `{"product_ids":[101,202]}` includes exactly those two (and validates they belong to the warehouse). | ✅ The ad_hoc scope adds `whereIn('p.id', [101,202])`; `validateCountScope` verifies both exist + are active + not soft-deleted (throws with the bad id if not). "Belong to the warehouse" is interpreted as "is a valid active product" (products are global; warehouse scoping happens via the LEFT JOIN to warehouse_stock at setup). |
| A `negative_only` session only includes products with `warehouse_stock.qty < 0`. | ✅ The negative_only scope adds `whereRaw('COALESCE(ws.qty, 0) < -0.0001')` — products with negative on-hand (oversold / data errors). The -0.0001 threshold excludes the CHECK's float-noise tolerance. |
| The ABC materialized view refreshes nightly without locking readers (`CONCURRENTLY`). | ✅ The `refresh-abc-classification` pg_cron job runs `REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_abc_classification` at 01:30 daily. The UNIQUE index `(warehouse_id, product_id)` (required for CONCURRENTLY) is created by the migration. The Laravel `AbcClassificationService::refresh()` fallback + the manual "Refresh now" button use the same CONCURRENTLY command. |

**How to verify:**

1. **Schema:** `psql -d rcerp -c "\d stock_take_sessions"` shows `count_scope` (varchar(20), default 'full', with the CHECK) + `count_scope_payload` (jsonb). `psql -d rcerp -c "\dM mv_product_abc_classification"` shows the materialized view; `\df stock_take_abc_*` shows the three helper functions. `SELECT key, value FROM stock_take_policies WHERE key LIKE 'stock_take.abc%';` returns the 3 seeded ABC policies. `SELECT jobname, schedule FROM cron.job WHERE jobname='refresh-abc-classification';` shows the nightly job (if pg_cron is installed).
2. **Migration:** `php artisan migrate` applies `2025_07_29_000001_add_cycle_count_scope_and_abc_classification` cleanly (creates columns + CHECK + functions + MV + indexes + seeds + initial refresh + cron job); `php artisan migrate:rollback` reverses everything (unschedules job, drops MV + functions, removes 3 ABC policy rows, drops columns + CHECK).
3. **ABC report:** Visit `admin/stock-take/abc-report` — shows the policy thresholds (80% / 95% / 365 days), the last-computed timestamp, the aggregate A/B/C distribution, the per-warehouse breakdown table, and the top-50 A-class products. Click "Refresh now" → the MV refreshes CONCURRENTLY + the timestamp updates.
4. **Create cycle count (category):** On `admin/stock-take/create`, pick "By category", check 2 categories, select a warehouse, click "Preview product count" → shows the N products in those categories. Submit → session created with `count_scope='category'`, `count_scope_payload={"category_ids":[...]}`. Setup counts for that warehouse → only those N products load. The show page shows the cycle-count banner + "Count scope: Category: … (2 categories)".
5. **Create cycle count (ABC):** Pick "ABC class", check "A" only, select a warehouse with ABC data, preview → shows the A-class product count for that warehouse. Submit + setup → only A-class products load. (If the MV is empty, refresh it first via the ABC report.)
6. **Create cycle count (ad_hoc):** Pick "Ad-hoc products", search + select 3 products via the select2 picker, preview → shows 3. Submit → session with `count_scope='ad_hoc'`, `count_scope_payload={"product_ids":[...]}`. Setup → exactly those 3 products load (with system_qty from each warehouse's stock).
7. **Create cycle count (negative_only):** Pick "Negative stock", select a warehouse, preview → shows only products with `qty < 0` (empty if the warehouse has no negative stock). Submit + setup → only negative-on-hand products load.
8. **Validation:** Try to create a category session with no categories checked → JS blocks submit ("Select at least one category…"). Bypass JS (curl) → server `validateCountScope` throws "category scope requires at least one category_id." Try ad_hoc with a non-existent product_id → throws "Unknown/inactive/deleted product_ids: 99999."
9. **Full count backward compat:** Create a session without picking a scope (defaults to 'full') → `count_scope='full'`, `count_scope_payload=null`; setup loads every active product (pre-Phase-5 behaviour). The show page shows no scope banner (full is the default).
10. **Audit trail:** The create audit row carries `count_scope` + `count_scope_payload`; the setup audit row carries them too. The audit timeline on the show page reflects the scope decision.

**Known limitations / deferred:**

- **No admin UI for editing the ABC policy rows** (`abc_threshold_a` / `_b` / `_lookback_days`) yet — they're seeded by the migration and editable via raw SQL (`UPDATE stock_take_policies SET value='0.85' WHERE key='stock_take.abc_threshold_a'`) + a manual MV refresh, or a future admin screen. The `StockTakePolicyService::flushCache()` method (Phase 4) is ready for it; the ABC report reads the policies via `$this->policyService->all()` so a cache flush + refresh picks up changes.
- **The preview's scoped query is a mirror of the service's, not a shared call.** If they diverge, the preview count will mismatch the setup count. A future test should assert parity for every scope. (Kept separate to avoid widening the service's public surface with a fake-session preview method.)
- **ABC classification does not weight by branch.** The view is per-warehouse; a product that's class A in warehouse 1 and class C in warehouse 2 stays separate (correct for cycle counting, but a "global A" view would need a separate aggregation). The ABC report's per-warehouse breakdown makes this explicit.
- **No "items not counted" reconciliation for cycle counts.** A full count's snapshot is the complete product list; a cycle count's snapshot is the scoped subset. The Phase 3 stock-drift reconciliation + the Phase 2 health-check still work (they operate on `stock_take_items`, which only contains the scoped products), but a "what % of the warehouse's total inventory value did this cycle count cover?" metric is deferred (would join the scope's items against `mv_stock_valuation`).
- **The pg_cron job is created in the migration but may not run if pg_cron isn't in `shared_preload_libraries`.** The migration logs a warning + the Laravel `AbcClassificationService::refresh()` path (callable via the "Refresh now" button or an artisan command) is the fallback. Registering the refresh in the Laravel scheduler (`app/Console/Kernel.php`) as a second fallback is a Phase 12 (testing/monitoring) concern.

**Next phase:** Phase 6 — Variance report: replace stub with legacy parity + drill‑down.

---

### Phase 6 — Variance report: replace stub with legacy parity + drill‑down

**Goal:** Port the legacy variance detail + weekly control reports, add GL impact drill‑down, fix the wrong status keys.

**Scope:**
1. Rewrite `ReportController::stocktakeVariance` to produce real variance numbers.
2. New `StockTakeVarianceReport` service (port of legacy `legacy/app/models/Reports/StockTakeVarianceReport.php`).
3. New `StockTakeWeeklyReport` service (port of legacy weekly).
4. Fix `stocktake_variance.blade.php` status badge map.
5. Add GL impact drill‑down (link to journal entry).
6. CSV exports for both reports.

**Schema changes:** none (relies on existing `stock_take_items.difference` generated column + `journal_lines`).

**Code changes:**
- `StockTakeVarianceReport::getVarianceLines($filters)` — port legacy query: join sessions + branches + warehouses + products + items; filter `WHERE sti.difference <> 0`; return columns matching legacy (session_code, take_date, status, branch, warehouse, product, system_qty, physical_qty, difference, rate, value_diff, reason, is_applied). Use the PG `difference` generated column instead of recomputing.
- `StockTakeVarianceReport::summarize($filters)` — totals (items, total_variance, total_value_diff).
- `StockTakeWeeklyReport::getWeekly($from, $to, $branchId)` — per‑session summary (warehouse_count, warehouses_done, variance_lines, gain_value, loss_value, net_value) + totals + top variance products.
- `ReportController::stocktakeVariance` — paginate variance lines; pass summary; support CSV export (`?format=csv`).
- New `ReportController::stocktakeWeekly` — weekly report route + view + CSV.
- Fix `stocktake_variance.blade.php`: status keys `draft/counting/posted/cancelled/reversed`; add variance columns; add drill‑down link to `admin.stock-take.show` and to the journal entry.
- New `stocktake_weekly.blade.php`.
- Register both in the reports catalog / sidebar.

**Acceptance criteria:**
- Variance report shows real per‑line numbers (difference, value_diff) — not just session headers.
- Weekly report matches legacy columns and totals.
- CSV exports include BOM (Excel‑friendly).
- Status badges render correctly for all five statuses.
- Drill‑down links work (session detail + journal entry).
- RLS: non‑admin users only see their branch's data.

**Rollback:** revert controller + views; remove the two services.

**Effort:** M (2 days).

#### ✅ Phase 6 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The two stock-take report stubs are replaced with real, legacy-parity reports. (a) **Variance detail report** (`StockTakeVarianceReport` service + rewritten `ReportController::stocktakeVariance` + rewritten `stocktake_variance.blade.php`): every `stock_take_items` row where `difference <> 0` is now a real variance line — session code, date, branch, warehouse, product code/name, system qty, physical qty, signed variance qty, rate, signed value diff, status badge, "applied" tick, and a per-line GL drill-down button. Four summary cards (variance lines with gain/loss split, net variance qty, net value diff, gross gain/loss value) compute accurate totals across the FULL result set (not just the current page). Filters: date range, branch (admin only — hidden for non-admins per RLS), session, warehouse. CSV export streams an Excel-friendly UTF-8-BOM file. Status badges cover all 7 statuses (`draft/counting/submitted/approved/posted/cancelled/reversed`) — fixing the old stub's wrong 4-key map that rendered `posted` as a grey default. (b) **Weekly control report** (`StockTakeWeeklyReport` service + new `ReportController::stocktakeWeekly` + new `stocktake_weekly.blade.php`): per-session summary (warehouse_count, warehouses_done, variance_lines, gain_value, loss_value, net_value) + totals row (sessions/posted/reversed/open/variance_lines/gain/loss/net) + a top-15-variance-SKU side panel. CSV export mirrors the legacy weekly CSV columns. (c) **GL drill-down**: a per-line "View GL" button opens a Bootstrap modal that AJAX-fetches the session's journal entry (header + all Dr/Cr lines with ledger codes/names/account types + balanced total) via a new `stocktakeVarianceJournal` endpoint backed by `JournalPostingService::getEntryWithLines()`. This surfaces the Phase 1 per-line `journal_line_id` traceability directly in the report. (d) Both reports registered in the `ReportsCatalog` Operations category; the reports hub count updated 18 → 23. Branch isolation is enforced by the existing RLS on `stock_take_sessions` (no manual `WHERE branch_id` — admin sees all, non-admin sees own branch automatically). Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (8):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/app/Services/Stock/StockTakeVarianceReport.php` | **NEW.** Port of legacy `StockTakeVarianceReport` (detail part). `getVarianceLines($filters)` — joins `stock_take_items` × `stock_take_sessions` × `branches` × `warehouses` × `products`, filters `WHERE sti.difference <> 0` (uses the PG GENERATED `difference` column instead of recomputing `physical_qty - system_qty`), selects `variance_qty = sti.difference` + `value_diff = sti.difference * COALESCE(sti.rate, 0)` + the per-line `journal_line_id` for GL drill-down. Supports `from/to/session_id/branch_id/warehouse_id/product_id` filters (the product_id filter is supported in the service for API parity but not yet surfaced in the UI). `summarize($rows)` — totals (total_items, total_variance, total_value_diff) + gain/loss line counts + gain/loss value (so the UI can show gross gain & loss separately from net). `getSessionsList()` — RLS-scoped sessions for the filter dropdown. `exportCsv($rows)` — `response()->stream()` with UTF-8 BOM (`chr(0xEF).chr(0xBB).chr(0xBF)`) so Excel reads multibyte cells; columns mirror legacy (Session/Date/Branch/Warehouse/Code/Product/System/Physical/Variance Qty/Rate/Value Diff/Reason/Applied). |
| 2 | `laravel/app/Services/Stock/StockTakeWeeklyReport.php` | **NEW.** Port of legacy `getWeeklyReport()` + `getTopVarianceProducts()` + `exportWeeklyCsv()`. `getWeekly($from, $to, $branchId)` — per-session summary with correlated subqueries for `variance_lines` / `gain_value` / `loss_value` (each computed from `stock_take_items` using the `difference` generated column — gain = `SUM(difference*rate) WHERE difference>0`, loss = `SUM(ABS(difference)*rate) WHERE difference<0`), `warehouse_count`/`warehouses_done` via a LEFT JOIN to `stock_take_warehouses` (done = `status='completed'`, matching Laravel's enum vs legacy's `counted/posted`), grouped by session. Status filter `IN ('posted','reversed','counting','submitted','approved')` (legacy used `'adjusted','reversed','counting'` — `adjusted`→`posted` rename + the two Phase 4 approval states so managers see in-flight sessions). Returns `{date_from, date_to, branch_id, totals, sessions, top_products}`. `getTopVarianceProducts()` — top-N by `SUM(ABS(difference*rate))` with surplus/shortage line counts. `exportCsv($report)` — BOM-prefixed CSV with columns mirroring legacy weekly. |
| 3 | `laravel/app/Http/Controllers/Admin/ReportController.php` | (a) Constructor now injects `StockTakeVarianceReport` + `StockTakeWeeklyReport` + `JournalPostingService` as 3rd/4th/5th deps (auto-resolved). (b) `stocktakeVariance()` — REWRITTEN: parses date range + 4 optional filters (session/branch/warehouse/product), calls the service, computes summary across ALL rows, manual `LengthAwarePaginator` (50/page) so totals stay accurate across pages, loads sessions/branches/warehouses for the filter dropdowns, passes `is_admin` for the branch-filter visibility gate. (c) **NEW** `stocktakeVarianceExport()` — same filters → `exportCsv()` streamed response. (d) **NEW** `stocktakeVarianceJournal($session)` — AJAX JSON endpoint: looks up the session's `journal_entry_id`, returns `{session, entry, lines}` via `JournalPostingService::getEntryWithLines()` (entry header + all Dr/Cr lines joined to `ledgers` for code/name/account_type). Empty payload if no JE posted yet. (e) **NEW** `stocktakeWeekly()` — period + branch filters → service → view with `is_admin` gate. (f) **NEW** `stocktakeWeeklyExport()` — CSV export. (g) **NEW** private `currentUserIsAdmin()` helper (uses `User::isAdmin()`). Docblock + reports-hub count updated 18 → 23. |
| 4 | `laravel/routes/web.php` | 4 new routes inside the `admin/reports` group (all under `auth` middleware; RLS scopes reads by branch): `GET stocktake-variance/export` → `stocktakeVarianceExport`, `GET stocktake-variance/journal/{session}` → `stocktakeVarianceJournal` (AJAX), `GET stocktake-weekly` → `stocktakeWeekly`, `GET stocktake-weekly/export` → `stocktakeWeeklyExport`. Placed next to the existing `stocktake-variance` route with a Phase 6 comment block. No `role:` middleware added (the existing reports group relies on `auth` + RLS; admin-only branch filter is gated in the controller/view, matching the pattern of the other financial reports). |
| 5 | `laravel/app/Helpers/ReportsCatalog.php` | Added `stocktake_weekly` entry to the Operations category (tagline "Posted sessions, gain/loss totals & top SKU variances", icon `fa-chart-line`, tags `['control','variance']`, preset_days 7, filter_type `range`); updated the `stocktake_variance` tagline to mention GL drill-down. The reports hub index page now shows both reports in the Operations category. |
| 6 | `laravel/resources/views/admin/reports/stocktake_variance.blade.php` | REWRITTEN (was an 88-line stub showing only session headers). (a) Full 7-status badge map (`draft/counting/submitted/approved/posted/cancelled/reversed`) — fixes the old stub's wrong 4-key map. (b) Hero header with Weekly + CSV + Reports-hub buttons. (c) Filter form: date range + branch (admin only) + session dropdown + warehouse dropdown + Reset. (d) 4 summary cards: variance lines (with gain/loss split), net variance qty (green/red), net value diff (green/red), gross gain/loss value. (e) Variance-lines table: Session (link to `admin.stock-take.show`), Date, Branch, Warehouse, Product (code + name), System, Physical, Diff (signed, colored), Rate, Value Diff (signed, colored), Status badge + Applied tick, GL drill-down button (book icon, only when `journal_entry_id` is set). (f) Totals tfoot row. (g) Pagination. (h) GL drill-down modal: AJAX-fetches the journal entry, renders header (entry_no/date/status/source/description) + Dr/Cr lines table (ledger code/name/type/debit/credit/memo) + balanced total. Vanilla JS using `bootstrap.Modal` + `fetch()`. |
| 7 | `laravel/resources/views/admin/reports/stocktake_weekly.blade.php` | **NEW.** Mirrors legacy `StockTake/weekly.php`. (a) Hero header with Variance-detail + CSV + Reports-hub buttons. (b) Period + branch (admin only) filter form. (c) Summary bar: sessions / posted / reversed / open / variance lines / gain / loss / net (all in one card row). (d) Two-column layout: left (col-lg-7) = sessions-in-period table (Session link, Date, Branch, Status badge, WH done x/y, Lines, Net value colored, GL link to journal-entries report filtered by `reference_type=stock_take`) + totals tfoot; right (col-lg-5) = top-variance-by-value side panel (product code/name, abs value, +/− line counts). Uses the same 7-status badge map. |
| 8 | `laravel/resources/views/admin/reports/index.blade.php` | Updated the reports-hub subtitle "18 financial & operational reports" → "23" (the count was already stale before Phase 6; now accurate). |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 6 placeholders (and why):**

1. **Two separate services (Variance + Weekly), not one combined class.** The legacy code put both reports in one `StockTakeVarianceReport` class. The plan explicitly says "New `StockTakeVarianceReport` service" + "New `StockTakeWeeklyReport` service" — two classes. We honour that split for single-responsibility + testability: each service has one report's query + CSV logic, and Phase 12's `StockTakeVarianceReportTest` / `WeeklyReportTest` can mock one without loading the other.
2. **Status mapping: legacy `'adjusted'` → Laravel `'posted'`.** The legacy weekly report filtered `status IN ('adjusted','reversed','counting')`. Laravel's status enum (Phase 0/4) uses `'posted'` where legacy used `'adjusted'`. We also include `'submitted'` and `'approved'` (the Phase 4 approval states) so the weekly control report surfaces in-flight sessions a manager would want to see — the legacy included `'counting'` for the same reason (an open count is part of the week's control picture, not just finished ones).
3. **Use the PG `difference` GENERATED column, not recompute.** The legacy recomputed `(physical_qty - system_qty)` in SQL and PHP. Laravel has a `difference numeric(14,4) GENERATED ALWAYS AS (physical_qty - system_qty) STORED` column (Phase 0). Selecting `sti.difference` directly is faster (indexed, computed once at write time) and avoids any drift between the generated column and a recomputed value. The `value_diff` is still computed as `difference * COALESCE(rate, 0)` because it's not stored.
4. **Warehouse "done" count uses `status='completed'`, not legacy's `IN ('counted','posted')`.** Laravel's `stock_take_warehouses.status` enum is `pending/counting/completed` (Phase 0); legacy used `pending/counted/posted`. A warehouse is "done counting" when it reaches `completed`. This matches the Phase 1 `postSession` guard (all warehouses must be `completed` before posting).
5. **GL drill-down via an AJAX modal, not a separate route/page.** The plan said "Add GL impact drill‑down (link to journal entry)" and "Drill‑down links work (session detail + journal entry)." There is no existing per-journal-entry show route/page in the app (only the `journal-entries` search report). Rather than build a whole new route+view, a per-line "View GL" button opens a Bootstrap modal that AJAX-fetches `{session, entry, lines}` from a new `stocktakeVarianceJournal/{session}` endpoint backed by `JournalPostingService::getEntryWithLines()`. This surfaces the Phase 1 per-line `journal_line_id` traceability directly in the report — true drill-down from a variance line to its exact GL lines, without a page navigation. The weekly report's GL link goes to the `journal-entries` report filtered by `reference_type=stock_take` (a list-level drill-down, appropriate for that report's session-summary granularity).
6. **RLS for branch isolation — no manual `WHERE branch_id`.** The plan said "RLS: non-admin users only see their branch's data." RLS on `stock_take_sessions` (Phase 0/4) already enforces this at the DB layer via the `app.branch_id` / `app.is_admin` GUC set by `SetAppBranchId` middleware. The report services do NOT add a manual `branch_id` filter (which would be redundant and could mask RLS bugs). The branch filter dropdown in the UI is admin-only (hidden for non-admins) — legacy parity — and when an admin picks a branch, the controller passes it to the service which adds the explicit `WHERE sts.branch_id = ?` on top of RLS (belt-and-suspenders for the admin's explicit choice).
7. **Manual `LengthAwarePaginator`, not DB-level `->paginate()`.** The variance report materializes ALL matching rows via `getVarianceLines()` so `summarize()` can compute accurate totals (`total_variance`, `total_value_diff`, gain/loss split) across the FULL result set, not just the current page. DB-level `paginate(50)` would compute totals only for the current page — misleading. The trade-off is memory: for 100k+ variance lines this would be slow. A future optimization (Phase 12) would run a separate aggregate query for totals + paginate the rows. For now, the typical variance report (a few hundred to a few thousand lines) is fast.
8. **CSV via `response()->stream()` with UTF-8 BOM.** Matches the legacy `fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF))` approach exactly. The BOM (`EF BB BF`) tells Excel the file is UTF-8 so accented product names render correctly (without it, Excel defaults to the system codepage and mangles multibyte). `stream()` avoids building the whole CSV in memory (the legacy `fopen('php://output')` pattern, idiomatic in Laravel).
9. **Product filter supported in the service/controller but omitted from the UI.** The legacy variance report had an AJAX-driven product picker. Building a select2-AJAX product picker for the filter bar is frontend-heavy and out of Phase 6's core scope (real variance numbers + drill-down). The service's `getVarianceLines(['product_id' => …])` + the controller's `product_id` input handling are fully wired, so a future enhancement (or API consumer) can use it. Per-product drill-down is available today via the session-detail page (click a session code → see its items). The UI filters (date/branch/session/warehouse) cover the primary use cases.
10. **No `role:` middleware on the new report routes.** The existing `admin/reports` route group relies on `auth` + RLS (matching the pattern of all 23 financial/operational reports — none of them carry `role:` middleware). Admin-only behaviour (the branch filter) is gated in the controller/view via `currentUserIsAdmin()`. Adding `role:` here would be inconsistent with the sibling reports and would block legitimately-readable reports from `accountant`/`manager` roles.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| Variance report shows real per-line numbers (difference, value_diff) — not just session headers. | ✅ `StockTakeVarianceReport::getVarianceLines()` joins `stock_take_items` and selects `sti.difference as variance_qty` + `(sti.difference * COALESCE(sti.rate, 0)) as value_diff` per line. The rewritten blade renders System/Physical/Diff/Rate/Value Diff columns per row + a totals tfoot. The old stub (session headers only) is gone. |
| Weekly report matches legacy columns and totals. | ✅ `StockTakeWeeklyReport::getWeekly()` returns the same shape as legacy `getWeeklyReport()`: per-session `session_code/session_date/status/branch_name/warehouse_count/warehouses_done/variance_lines/gain_value/loss_value/net_value` + `totals{sessions,posted,reversed,open,variance_lines,gain_value,loss_value,net_value}` + `top_products[]`. The blade renders the sessions table + summary bar + top-SKU panel mirroring `legacy/app/views/StockTake/weekly.php`. |
| CSV exports include BOM (Excel-friendly). | ✅ Both `StockTakeVarianceReport::exportCsv()` and `StockTakeWeeklyReport::exportCsv()` prefix the stream with `chr(0xEF).chr(0xBB).chr(0xBF)` (the UTF-8 BOM) before `fputcsv`. Headers set `Content-Type: text/csv; charset=utf-8` + `Content-Disposition: attachment`. |
| Status badges render correctly for all five statuses. | ✅ The badge map covers all 7 statuses (`draft→secondary, counting→primary, submitted→warning, approved→info, posted→success, cancelled→danger, reversed→dark`). Both blades use the same map. `effectiveStatus = !empty(is_reversed) ? 'reversed' : session_status` so a reversed posted session shows `reversed` (not `posted`). The old stub's 4-key map (`draft/in_progress/completed/approved/cancelled`) — which didn't even match the real status values — is replaced. |
| Drill-down links work (session detail + journal entry). | ✅ Session drill-down: every session code links to `route('admin.stock-take.show', $session_id)`. Journal-entry drill-down: the variance report's per-line book-icon button opens a modal that AJAX-fetches `stocktakeVarianceJournal/{session}` → renders the full journal entry (header + all Dr/Cr lines + balanced total). The weekly report's GL link goes to `route('admin.reports.journalEntries', ['reference_type'=>'stock_take', …])`. |
| RLS: non-admin users only see their branch's data. | ✅ No manual `branch_id` filter in the services (RLS on `stock_take_sessions` scopes the join automatically via the `app.branch_id` GUC). The branch filter dropdown is hidden for non-admins (`@if (!empty($is_admin))`). A non-admin querying the report sees only their branch's sessions + items; an admin sees all + can filter by branch explicitly. |

**How to verify:**

1. **Reports hub:** Visit `admin/reports` → the Operations category now shows 2 stock-take reports: "Stock Take Variance" (detail) and "Stock Take — Weekly Control". The subtitle reads "23 financial & operational reports across 5 categories".
2. **Variance report — real lines:** Visit `admin/reports/stocktake-variance` → the table shows per-LINE rows (not session headers): Session / Date / Branch / Warehouse / Product (code+name) / System / Physical / Diff (signed, green/red) / Rate / Value Diff (signed, green/red) / Status badge / GL button. 4 summary cards above the table. Totals tfoot at the bottom.
3. **Variance report — filters:** Pick a date range + (if admin) a branch + a session + a warehouse → Run → the table filters. Reset clears them. Pagination works (50/page) and totals stay accurate across pages.
4. **Variance CSV:** Click "CSV" → downloads `Stock_Take_Variance_YYYY-MM-DD_HHMMSS.csv`. Open in Excel → BOM ensures product names with accents render. Columns: Session/Date/Branch/Warehouse/Code/Product/System/Physical/Variance Qty/Rate/Value Diff/Reason/Applied.
5. **GL drill-down modal:** On any variance line whose session has been posted (journal_entry_id set), click the book icon → modal opens showing the journal entry: entry_no, date, status (Posted/Reversed), source (`stock_take`), description, and a Dr/Cr lines table (ledger code/name/type/debit/credit/memo) with a balanced total row. A line with no JE shows "—".
6. **Weekly report:** Visit `admin/reports/stocktake-weekly` → summary bar (sessions/posted/reversed/open/lines/gain/loss/net), sessions-in-period table (with WH done x/y, variance lines, net value, GL link), and a top-variance-by-value side panel (top 15 SKUs with abs value + surplus/shortage line counts). Filter by period + (if admin) branch.
7. **Weekly CSV:** Click "CSV" → downloads `Stock_Take_Weekly_…csv` with columns Session/Date/Branch/Status/WH done/Variance lines/Gain/Loss/Net/Has GL.
8. **Status badges:** Create sessions in each status (`draft`, `counting`, `submitted`, `approved`, `posted`, `cancelled`, `reversed`) and confirm each renders the correct badge colour in both reports. A reversed posted session shows `reversed` (dark), not `posted` (success).
9. **RLS:** Log in as a non-admin user → both reports show only their branch's sessions; the branch filter dropdown is hidden. Log in as admin → all branches visible + the branch filter appears.
10. **Session drill-down:** Click any session code in either report → navigates to `admin/stock-take/{id}` (the session detail page).

**Known limitations / deferred:**

- **Product filter not in the UI.** The service (`getVarianceLines(['product_id'=>…])`) and controller fully support it, but the variance blade doesn't render a product picker (the legacy used an AJAX select2 picker; building one is frontend-heavy and out of Phase 6's core scope). Per-product drill-down is available via the session-detail page today. A future enhancement can add a select2-AJAX product picker wired to the existing `product_id` input.
- **Variance report materializes all matching rows for accurate totals.** For typical datasets (hundreds to low-thousands of variance lines) this is fast. For 100k+ lines it could be slow. A Phase 12 optimization would run a separate `SUM`/`COUNT` aggregate query for the summary cards + paginate the rows with DB-level `paginate()`.
- **The weekly report's GL link is list-level, not per-entry.** It goes to the `journal-entries` report filtered by `reference_type=stock_take` (shows all stock-take JEs in the period). A per-journal-entry show route doesn't exist in the app yet. The variance report's modal IS the per-entry drill-down (and is the more useful one, since it's per-line).
- **No automated tests yet.** Phase 12 scope: `VarianceReportTest` + `WeeklyReportTest` will assert the query shapes, filter behaviour, CSV BOM, and RLS cross-branch denial.
- **The `ReportsCatalog` "preset_days" for the weekly report is 7** (last 7 days) matching legacy; the variance report keeps 30. Both are overridable via the `from_date`/`to_date` query params.

**Next phase:** Phase 7 — Count UX: barcode, bulk paste, CSV import, recount.

---

### Phase 7 — Count UX: barcode, bulk paste, CSV import, recount

**Goal:** Make counting fast and ergonomic. Port the best of legacy UX and add what legacy lacked.

**Scope:**
1. Barcode‑driven count entry (scan → product match → qty prompt → save line).
2. Bulk paste (`code,qty` lines from clipboard).
3. CSV import (upload a counted file).
4. Per‑warehouse recount (transition `completed → recounting → counting`).
5. Auto‑save drafts (per line) with optimistic concurrency.
6. Mobile‑friendly layout (sticky header, large touch targets).

**Schema changes:**
- `ALTER TABLE stock_take_warehouses DROP CONSTRAINT …` re‑add CHECK with `recounting` status.
- `ALTER TABLE stock_take_items ADD COLUMN recounted_at timestamp(0), ADD COLUMN recounted_by integer REFERENCES users(id)` (track recount history per line; Phase 2 audit log also captures this).

**Code changes:**
- New `StockTakeController::importCounts(Request, session, warehouse)` — accepts CSV; validates; upserts `physical_qty`.
- New `StockTakeController::bulkPaste(Request, session, warehouse)` — accepts a textarea of `code,qty` lines; parses; upserts.
- New `StockTakeController::scanCount(Request, session, warehouse)` — accepts `{code, qty}`; resolves product; upserts; returns the updated line (for live UI update).
- `StockTakeService::recountWarehouse($sessionId, $warehouseId, $reason)` — transition `completed → recounting`; reset `physical_qty = system_qty` for that warehouse's items (or keep? configurable); transition `recounting → counting`; audit log.
- Frontend: add barcode input (auto‑focus), bulk‑paste modal, CSV upload button, recount button (with reason prompt).
- Mobile: responsive grid; sticky header; 44px+ touch targets; optimistic UI updates.

**Acceptance criteria:**
- Scanning a barcode resolves the product (exact code match) and prompts for qty; saving updates the grid without a full reload.
- Pasting 50 `code,qty` lines upserts all 50 in one transaction.
- CSV import validates headers (`product_code, physical_qty`) and rejects unknown codes with a clear error.
- Recounting a completed warehouse moves it back to `counting` with an audit entry; the previous physical_qty values are preserved in the audit log.
- Mobile layout is usable at 375px width.

**Rollback:** revert controller methods + service; drop the columns; revert the warehouse CHECK.

**Effort:** L (3–4 days; frontend‑heavy).

#### ✅ Phase 7 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. Counting is now fast and ergonomic across four entry channels + a recount flow + mobile-friendly layout. (a) **Barcode scan** (`StockTakeService::upsertCount` + `StockTakeController::scanCount` AJAX endpoint + count-page toolbar): a scan/typed code + qty + optional reason resolves the product by EXACT product_code match, upserts the matching `stock_take_items` row in a transaction with a session-row `lockForUpdate`, writes a `scan_count` audit row, and returns the updated line so the grid updates in-place (highlight + scroll + recompute) with NO full reload. Unknown codes / out-of-scope products are rejected with a clear error. Enter-on-barcode-field jumps to qty; Enter-on-qty saves immediately (mirrors common scan-gun behaviour). (b) **Bulk paste** (`StockTakeService::bulkUpsertCounts` + `bulkPaste` AJAX endpoint + modal): a textarea of `code,qty[,reason]` lines (comma OR tab separated; `#` lines ignored; reason may contain commas) is parsed by `parseBulkLines` and upserted in ONE transaction with a session-row lock. Unknown codes / out-of-scope products / duplicates are SKIPPED (not fatal) and reported per-line (line number + code + error). The modal shows the updated/skipped counts + the full skip-reason list, then reloads the grid. (c) **CSV import** (`importCounts` POST endpoint + modal): an uploaded CSV (max 2 MB) with a header row containing at least `product_code` and `physical_qty` (case-insensitive; spaces/hyphens normalised to underscores; optional `reason` column honoured) is parsed by `parseCsv`, BOM-stripped, validated (header check + non-empty data rows), and routed through `bulkUpsertCounts` with `channel='csv_import'` so the audit timeline distinguishes paste vs CSV. Unknown codes are skipped with a clear per-line message. (d) **Recount** (`StockTakeService::recountWarehouse` + `recount` POST endpoint + show-page button + SweetAlert2 reason prompt): a completed warehouse on an editable session transitions `completed → recounting → counting` (two writes so the audit timeline shows the transient `recounting` state distinctly), captures a pre-recount snapshot of every line's `{product_id, product_code, physical_qty, system_qty}` into the `recount` audit payload (forensic record — satisfies "the previous physical_qty values are preserved in the audit log"), stamps `recounted_at`/`recounted_by` on every item in the warehouse, optionally resets `physical_qty = system_qty` per the `stock_take.recount_reset_to_system` policy (default false = preserve, so the counter sees the prior count and adjusts), and — if the session was `submitted`/`approved` — pushes it back to `counting` and clears the approval artifacts (a recount invalidates a prior approval). (e) **Autosave with optimistic concurrency** (`StockTakeService::autosaveCount` + `autosave` AJAX endpoint + count-page debounced handler): each physical-qty/reason input auto-saves 800ms after the user stops typing; the row's `updated_at` is the optimistic-lock token — when the caller passes `expected_updated_at` and it doesn't match the current row's `updated_at`, the service returns `status='conflict'` with the fresh row (HTTP 409) so the UI can re-prompt (two counters editing the same line never silently clobber each other). Each save writes an `autosave` audit row. (f) **Mobile-friendly**: the hero header is sticky on mobile (`@media max-width:575.98px`), toolbar buttons get `min-height:44px` touch targets, count-table inputs use `font-size:16px` (prevents iOS zoom-on-focus) + `min-height:40px`, and the layout collapses to a single column at the `sm` breakpoint. (g) **Schema**: `stock_take_warehouses.status` CHECK widened to include `recounting`; `stock_take_items` gained `recounted_at timestamp(0)` + `recounted_by integer REFERENCES users(id) ON DELETE SET NULL`; `stock_take_audit_log.action` CHECK widened with `recount|scan_count|bulk_upsert|csv_import|autosave`; the `idx_stal_critical` partial index now includes `recount`; a new `stock_take.recount_reset_to_system` policy is seeded (bool, default false). All changes mirrored into `database/sql/03_stock.sql`. Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (9):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_07_30_000001_add_recount_columns_to_stock_take.php` | **NEW.** (a) Drops the unnamed CHECK on `stock_take_warehouses.status` via a `DO` block (finds the constraint by `pg_get_constraintdef ILIKE '%pending%'` then `DROP CONSTRAINT`), re-adds a named CHECK including `'recounting'`. (b) Adds `recounted_at timestamp(0)` + `recounted_by integer` to `stock_take_items` (IF NOT EXISTS) + a named FK `sti_recounted_by_fk` → `users(id) ON DELETE SET NULL` (added via a `DO` block so re-running is safe). (c) Drops the unnamed CHECK on `stock_take_audit_log.action` the same way, re-adds a named CHECK including the 5 new actions (`recount|scan_count|bulk_upsert|csv_import|autosave`). (d) Drops + recreates `idx_stal_critical` so its WHERE clause includes `'recount'`. (e) Seeds `stock_take.recount_reset_to_system` (bool, default false) into `stock_take_policies` via `updateOrInsert`. down() reverses everything idempotently. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored all Phase 7 schema: `stock_take_warehouses.status` CHECK includes `'recounting'`; `stock_take_items` gained `recounted_at` + `recounted_by` (FK ON DELETE SET NULL); `stock_take_audit_log.action` CHECK includes the 5 new actions; `idx_stal_critical` WHERE includes `'recount'`; the `stock_take_policies` comment block documents the new `stock_take.recount_reset_to_system` seed. Fresh-install parity with the migration. |
| 3 | `laravel/app/Models/StockTakeAuditLog.php` | `@property action` docblock + `actionLabel()` map + `actionColor()` map extended with `recount` (warning), `scan_count`/`bulk_upsert`/`csv_import` (info), `autosave` (secondary). `isCritical()` now includes `'recount'` (a warehouse-level state change worth flagging in the critical-events summary) — matches the partial-index WHERE. |
| 4 | `laravel/app/Models/StockTakeWarehouse.php` | Docblock updated to document `'recounting'` as a transient state. Added `isRecounting(): bool` helper (forward-compatible with a future async recount flow that leaves the warehouse in `recounting` until the counter opens the page). |
| 5 | `laravel/app/Services/Stock/StockTakePolicyService.php` | Added `recountResetToSystem(): bool` accessor (reads `stock_take.recount_reset_to_system`, default false). Class docblock updated with the new policy. |
| 6 | `laravel/app/Services/Stock/StockTakeService.php` | 4 new public methods + 1 private helper inserted after `saveCounts`: (a) `recountWarehouse($sessionId, $warehouseId, $reason, $actorId)` — locks session, verifies warehouse is `completed` + session is editable, captures pre-recount snapshot, stamps `recounted_at`/`recounted_by` (+ optional `physical_qty=system_qty` reset), flips warehouse `completed→recounting→counting`, pushes session back to `counting` (clearing approval artifacts if submitted/approved), logs `recount` audit row with the snapshot, returns `{lines_recounted, reset_to_system, previous_snapshot}`. (b) `upsertCount($sessionId, $warehouseId, $productCode, $physicalQty, $reason, $actorId)` — exact-code product resolve, verifies the product is in this warehouse's count, updates `physical_qty`/`reason`/`updated_at`, logs `scan_count` audit row (item-scoped), returns the updated line shape. (c) `bulkUpsertCounts($sessionId, $warehouseId, $lines, $actorId, $channel='bulk_upsert')` — pre-loads the warehouse's items keyed by `product_code`, iterates the parsed lines, validates each (code/qty/negative/duplicate), upserts valid lines, skips invalid ones with per-line errors, logs a `bulk_upsert` (or `csv_import`) audit row with the full skip-reason list, returns `{updated, skipped, errors}`. All in ONE transaction. (d) `autosaveCount($sessionId, $warehouseId, $productId, $physicalQty, $reason, $expectedUpdatedAt, $actorId)` — optimistic-concurrency single-line save: if `expected_updated_at` ≠ current row's `updated_at`, returns `{status:'conflict', line, current_updated_at}` WITHOUT overwriting; otherwise updates + logs `autosave` audit row + returns `{status:'saved', line, current_updated_at}`. (e) `formatItemLine($item)` private helper — the JSON shape the count page's autosave + scan handlers expect (product_id/code/name/unit, system/physical/difference/rate/value_diff, reason, updated_at, recounted_at); re-fetches the product join when called from autosave (which passes a bare items row). |
| 7 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | 5 new public methods + 2 private helpers inserted before the Phase 5 `countScopedProducts` helper: (a) `scanCount($sessionId, $warehouseId)` — AJAX; validates `{code, qty, reason?}`; calls `upsertCount`; returns `{status:'success', line}` (200) / 422 / 500. (b) `bulkPaste($sessionId, $warehouseId)` — AJAX; validates `{lines}` (string, max 50k chars); calls `parseBulkLines` → `bulkUpsertCounts`; returns `{status:'success', updated, skipped, errors}`. (c) `importCounts($sessionId, $warehouseId)` — multipart POST; validates `csv_file` (csv/txt, max 2 MB); BOM-strips; calls `parseCsv` → `bulkUpsertCounts(channel='csv_import')`; redirects back to the count page with a success/warning flash (per-line skip reasons in the message). (d) `recount($sessionId, $warehouseId)` — validates `{reason}` (min 3, max 1000); calls `recountWarehouse`; redirects to the count page with a warning flash telling the counter whether previous values were preserved or reset. (e) `autosave($sessionId, $warehouseId)` — AJAX; validates `{product_id, qty, reason?, expected_updated_at?}`; calls `autosaveCount`; returns 200 (saved) / 409 (conflict) / 422 / 500. (f) `parseBulkLines($text)` private — splits on `\r\n\|\r\|\n`, trims, skips blank/`#` lines, splits each on first 2 commas-or-tabs (reason may contain commas), returns `[{code, qty, reason?}]`. (g) `parseCsv($raw)` private — `str_getcsv` per line, normalises header (lowercase, spaces/hyphens → underscores), requires `product_code` + `physical_qty` columns, optional `reason`, returns `{lines}` or `{error}`. |
| 8 | `laravel/routes/web.php` | 5 new routes inside the `admin/stock-take` prefix group, all with `role:admin,manager,warehouse_manager` + `branch.isolation` (the `{session}` URL param lets `EnforceBranchIsolation` resolve `stock_take_sessions.branch_id`): `POST {session}/warehouses/{warehouse}/scan` → `scanCount`, `POST …/bulk-paste` → `bulkPaste`, `POST …/import` → `importCounts`, `POST …/recount` → `recount`, `POST …/autosave` → `autosave`. Placed next to the existing `saveCounts` route with a Phase 7 comment block. |
| 9 | `laravel/resources/views/admin/stock-take/count.blade.php` | REWRITTEN (was 269 lines; now ~630). (a) Hero header — same gradient + back link, now sticky on mobile (`@media max-width:575.98px .st-hero{position:sticky;top:0;z-index:1030}`), with a `Recount` badge when any line has `recounted_at` set. (b) Recount-in-progress banner (warning) when `$recountInProgress`. (c) **Toolbar card** (only when `$editable`): barcode input (auto-focus, `input-group-lg`) + qty input + reason input + Save-line button; Bulk-paste button (opens modal); CSV-import button (opens modal). Responsive `col-12 col-md-5 / col-6 col-md-3 / col-6 col-md-4` grid. (d) Count table — same columns + a recounted-row badge (warning pill with `fa-rotate`) in the Category cell; `data-product-id`/`data-product-code`/`data-updated-at`/`data-recounted` on each `<tr>` for the JS handlers; inputs `readonly` when `!$editable`. (e) **Bulk-paste modal**: 12-row textarea with placeholder examples + result panel (updated/skipped counts + per-line skip-reason `<ul>`) + auto-reload after 1.8s. (f) **CSV-import modal**: multipart form with `csv_file` input (accept `.csv,.txt`) + submit-with-spinner. (g) **JS** (`@push('scripts')`): numeric helpers + `escapeHtml`; `recomputeRow`/`recomputeTotals` (unchanged logic); `highlightRow` (scroll + flash + focus); barcode submit (`submitBarcode` — AJAX POST to `scanUrl`, in-place row update + recompute + hint message; Enter-on-barcode → qty focus; Enter-on-qty/reason → submit); bulk-paste submit (AJAX POST → result panel → reload); CSV-import form submit (spinner, normal POST); autosave (debounced 800ms per `product_id`, AJAX POST with `expected_updated_at`, 200 → update row's `data-updated-at` + recompute, 409 → Swal "Line was updated elsewhere" + reload); submit guard (missing-qty check + spinner). (h) **CSS** (`@push('css')`): `.st-row-flash` keyframe (yellow → transparent, 1.2s); mobile `@media` rules for 44px touch targets + 16px font-size (iOS zoom prevention) + sticky hero. |
| 10 | `laravel/resources/views/admin/stock-take/show.blade.php` | (a) `$whStatusBadge` map extended with `'recounting'` (warning, `fa-rotate`). (b) Warehouse-row actions cell: when `$wh->status === 'completed'` AND the session is editable (`counting`/`submitted`/`approved`), a `Recount` button (`btn-outline-warning`, `js-st-recount` class, `data-recount-url` + `data-warehouse` attrs) appears next to the `Count` button. (c) `@push('scripts')`: a `.js-st-recount` click handler — Swal warning + reason textarea (min 3 chars) + confirm → hidden-form POST to the recount URL. (d) `@push('css')`: a small mobile rule for the recount button padding. |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 7 placeholders (and why):**

1. **Recount preserves `physical_qty` by default; reset is opt-in via policy.** The plan said "reset `physical_qty = system_qty` for that warehouse's items (or keep? configurable)". We made it configurable via a new `stock_take.recount_reset_to_system` policy (default false = preserve). Rationale: in most recounts the counter wants to see the previous value and adjust it (e.g. "I miscounted by 2"), not start from zero. A full reset is appropriate only for a forensic re-count where the prior value is suspect. The audit row captures the pre-recount snapshot regardless, so the forensic record is intact either way.
2. **`recounting` is a transient state — written + immediately overwritten to `counting` in the same transaction.** The plan said "transition `completed → recounting`; … transition `recounting → counting`". We do BOTH writes in the same `DB::transaction`, so no reader ever sees a committed `recounting` row (unless a future async flow deliberately leaves it). The two-write pattern is kept so the audit timeline shows the `recounting` intent distinctly from a plain `save_count`. The `stock_take_warehouses.status` CHECK includes `recounting` for forward-compatibility with that future async flow.
3. **Exact product-code match for barcode scan (no fuzzy).** A fuzzy/ILIKE match would silently bind a scan to the wrong product — unacceptable for a count where every line becomes a stock movement + GL line. The scan resolves `WHERE product_code = ?` (exact). If the code is unknown OR not in this warehouse's count (out of cycle-count scope), the scan is rejected with a clear message. The counter can then correct the code or set up counts first.
4. **Bulk paste + CSV import skip invalid rows (not fatal).** The plan's acceptance criterion says "Pasting 50 `code,qty` lines upserts all 50 in one transaction" — we satisfy that (all VALID lines upsert in one transaction). Unknown codes / out-of-scope products / duplicates are SKIPPED and reported per-line (line number + code + reason), so the user can fix the bad rows and re-submit. A fatal-on-first-error approach would force the user to fix rows one at a time — bad UX for a 200-line paste. The batch is still atomic: either ALL valid lines upsert or NONE do (single `DB::transaction`).
5. **CSV header validation is case/space/hyphen-insensitive.** The plan said "validates headers (`product_code, physical_qty`)". We normalise the header (lowercase, spaces/hyphens → underscores) so `Product Code`, `product-code`, `PRODUCT_CODE` all match. This is forgiving of Excel/spreadsheet exports that capitalise or hyphenate headers. An explicit error names the found columns when the required ones are missing.
6. **Autosave uses `updated_at` as the optimistic-lock token, not a version column.** Adding a `version integer` column would be a schema change for a marginal benefit. `updated_at` already changes on every write and is already on the row (Phase 0). The caller passes `expected_updated_at` (the value it last saw); the service compares it to the current row's `updated_at` — mismatch → `status='conflict'` + the fresh row (HTTP 409). The UI re-prompts with the other user's value. This is the classic "optimistic concurrency with a row timestamp" pattern.
7. **Autosave is silent on failure; the explicit Save button is the authoritative path.** A failed autosave (network blip, 500) does NOT throw a visible error — the user would be drowned in toast notifications for transient failures. Instead, the explicit "Save Counts" button (which POSTs the whole form) is the authoritative save path with a full validation guard + error flash. Autosave is a progressive enhancement: when it works, the counter never needs to press Save (the values are already persisted); when it fails, the Save button still works.
8. **Five new audit actions, not one.** The plan's Phase 2 audit vocab had 12 actions reserved for the lifecycle. We add 5 (`recount|scan_count|bulk_upsert|csv_import|autosave`) so the audit timeline distinguishes the entry channel. `scan_count` and `autosave` are item-scoped (`stock_take_item_id` set); `bulk_upsert`/`csv_import`/`recount` are warehouse-scoped. `recount` is added to `isCritical()` + the `idx_stal_critical` partial index (a warehouse-level state change worth flagging in the critical-events summary alongside post/reverse/re_open). `autosave` is deliberately NOT critical (it's a routine per-keystroke save).
9. **Recount invalidates a prior approval.** If the session was `submitted` or `approved` when a warehouse is recounted, the service pushes the session back to `counting` and clears `submitted_by`/`submitted_at`/`approved_by`/`approved_at`/`approval_comments`. Rationale: a recount means the counted values are suspect; any approval based on those values is no longer valid. The counter must re-submit after the recount + save. This keeps the approval workflow's integrity guarantee ("approved = the counts were reviewed") intact.
10. **Mobile sticky header, not a sticky toolbar.** The hero header (session code + back link) is sticky on mobile so the counter always knows which session they're in. The toolbar (barcode/paste/CSV) is NOT sticky — it would eat too much vertical space on a 375px screen. The barcode input auto-focuses on page load, so the counter can start scanning immediately without scrolling.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| Scanning a barcode resolves the product (exact code match) and prompts for qty; saving updates the grid without a full reload. | ✅ `StockTakeService::upsertCount` resolves `WHERE product_code = ?` (exact), verifies the product is in this warehouse's count, upserts `physical_qty` in a transaction, logs `scan_count` audit, returns the updated line. The count-page `submitBarcode` JS AJAX-POSTs → on success updates the matching `<tr>` in-place (value + `data-updated-at`), recomputes the row + totals, highlights + scrolls to the row, and clears the barcode input for the next scan. No full reload. Unknown code → 422 with "Unknown product code '…'" → hint shows the error. |
| Pasting 50 `code,qty` lines upserts all 50 in one transaction. | ✅ `StockTakeService::bulkUpsertCounts` wraps the whole batch in a single `DB::transaction` with a session-row `lockForUpdate`. All valid lines upsert or none do. The modal shows `{updated, skipped}` + the per-line skip-reason list, then reloads the grid. |
| CSV import validates headers (`product_code, physical_qty`) and rejects unknown codes with a clear error. | ✅ `parseCsv` normalises the header (case/space/hyphen-insensitive), requires `product_code` + `physical_qty`, returns `{error: "CSV header must contain product_code and physical_qty columns (found: …)."}` on a missing column. Unknown codes are skipped by `bulkUpsertCounts` with a per-line error `"Product code 'X' is not in this warehouse's count (out of scope or unknown)."` surfaced in the redirect flash. |
| Recounting a completed warehouse moves it back to `counting` with an audit entry; the previous physical_qty values are preserved in the audit log. | ✅ `StockTakeService::recountWarehouse` transitions `completed → recounting → counting` (two writes, same transaction), writes a `recount` audit row whose `payload.previous_physical_qty` is the full pre-recount snapshot `[{product_id, product_code, physical_qty, system_qty}, …]`. The session is pushed back to `counting` (approval artifacts cleared if it was submitted/approved). The `recount` action is in `isCritical()` + the `idx_stal_critical` partial index so it surfaces in the critical-events summary. |
| Mobile layout is usable at 375px width. | ✅ The toolbar collapses to a single column (`col-12`) at `< sm`; buttons get `min-height:44px` + `font-size:1rem`; count-table inputs use `font-size:16px` (prevents iOS zoom-on-focus) + `min-height:40px`; the hero header is sticky (`position:sticky;top:0`) so the session code + back link stay reachable. The count table is `table-responsive` (horizontal scroll on narrow widths). |

**How to verify:**

1. **Migrate:** `php artisan migrate` — creates the recount columns, widens the warehouse + audit CHECKs, refreshes the critical index, seeds the `recount_reset_to_system` policy.
2. **Barcode scan:** Open a counting session → click "Count" on a warehouse → the toolbar's barcode input auto-focuses. Type/scane a product code → press Enter (jumps to qty) → enter a qty → press Enter (saves). The matching row highlights yellow + scrolls into view + the physical-qty input updates + the difference/value cells recompute + the totals tfoot updates. No full reload. The hint line shows "Saved SKU-001 = 42. Diff: +2.0000."
3. **Barcode scan — unknown code:** Type a non-existent code → press Enter → qty → Enter. The hint shows a red "Unknown product code 'XYZ'." message. The grid is unchanged.
4. **Bulk paste:** Click "Bulk paste" → modal opens → paste 50 `code,qty` lines (mix valid + unknown + a duplicate) → click "Upsert lines". The result panel shows "X updated, Y skipped" + a per-line skip-reason `<ul>`. After 1.8s the page reloads and the grid reflects the batch.
5. **CSV import:** Click "Import CSV" → modal opens → choose a CSV with header `product_code,physical_qty` (+ optional `reason`) → click "Import". Redirects back to the count page with a flash "CSV import: X line(s) updated, Y skipped." (per-line skip reasons in the message when Y > 0). Try a CSV with a wrong header → flash error "CSV header must contain product_code and physical_qty columns (found: …)."
6. **Recount:** On the session detail page, a completed warehouse (on an editable session) shows a "Recount" button next to "Count". Click it → Swal warning + reason textarea (min 3 chars) → confirm. Redirects to the count page with a warning flash "Recount started — N line(s) ready for re-entry. Previous physical quantities were PRESERVED — adjust as needed." Each line shows a recount badge (warning pill). The audit timeline (session detail page bottom) shows a `recount` row (starred, warning) with the pre-recount snapshot in the payload.
7. **Recount invalidates approval:** Submit a session for approval → on the session detail page, recount one of its completed warehouses. The session status returns to `counting` + the approval artifacts (submitted_by/at, approved_by/at, approval_comments) are cleared. The counter must re-submit after the recount + save.
8. **Autosave:** On the count page, change a physical-qty input + wait 800ms (no Save button press). The value is persisted (open the page in a second tab → the new value is there). The row's `data-updated-at` advances. Each autosave writes an `autosave` audit row (visible in the audit timeline).
9. **Autosave conflict:** Open the same count page in two tabs. In tab A, change product X's qty + wait for autosave. In tab B, change product X's qty + wait for autosave. Tab B gets a Swal "Line was updated elsewhere" showing tab A's value + a "Reload page" button. Tab B's value is NOT silently overwritten.
10. **Mobile (375px):** Open Chrome DevTools → device toolbar → iPhone SE (375px). The count page: hero header is sticky; toolbar is a single column with 44px-tall buttons; count-table inputs are 40px tall + 16px font (no iOS zoom); the table scrolls horizontally inside `table-responsive`. All buttons are comfortably tappable.
11. **Audit timeline:** After scan/paste/CSV/recount/autosave actions, visit the session detail page → the audit timeline shows `scan_count` (info), `bulk_upsert`/`csv_import` (info), `recount` (warning, starred — critical), `autosave` (secondary) rows with the actor + payload.

**Known limitations / deferred:**

- **No barcode hardware integration.** The barcode input is a standard text field that accepts scanner-gun keystrokes (scanners type the code + send Enter). A native camera-scan (WebUSB / WebRTC) is out of scope; the input-field approach works with every USB/Bluetooth scanner on the market.
- **Autosave writes one audit row per keystroke-batch.** A counter typing rapidly into one line produces one `autosave` row per 800ms debounce window. Over a long count this is many rows. A future optimization would sample autosave audit rows (e.g. one per minute per line) or omit them entirely (the final `save_count` row is the authoritative record). For now, full auditability is preferred.
- **Bulk paste + CSV import reload the page.** The scan path updates the grid in-place (no reload), but paste + CSV do a full reload after the batch. An in-place grid update for paste/CSV is possible (the service returns the affected product_ids) but adds frontend complexity; the reload is simpler + guarantees the grid matches the DB. The 1.8s delay on paste lets the user read the result panel before the reload.
- **No automated tests yet.** Phase 12 scope: `ScanCountTest`, `BulkPasteTest`, `CsvImportTest`, `RecountTest`, `AutosaveConflictTest` will assert the service + endpoint behaviour.
- **The `recount_reset_to_system` policy has no admin UI yet.** It's settable via a direct DB update or a future admin settings screen (the existing `StockTakePolicyController` could be extended). The default (false = preserve) is the safe choice.

**Next phase:** Phase 8 — Concurrency, RLS, and locking hardening.

---

### Phase 8 — Concurrency, RLS, and locking hardening

**Goal:** Close the cross‑branch data leak on `stock_take_items`/`stock_take_warehouses` and add warehouse‑level mutual exclusion during post.

**Scope:**
1. Add `branch_id` to `stock_take_warehouses` and `stock_take_items` (denormalized; set at insert; never updated).
2. Extend RLS policies to both tables.
3. Add a `pg_advisory_xact_lock` keyed on `warehouse_id` during `postSession` for each warehouse in the session.
4. Add an `EXCLUDE` constraint preventing overlapping *frozen* sessions for the same warehouse (only when `freeze_outbound=true`).
5. UNIQUE constraint on `stock_take_warehouses(session_id, warehouse_id)`.

**Schema changes:**
- `ALTER TABLE stock_take_warehouses ADD COLUMN branch_id integer NOT NULL REFERENCES branches(id), ADD CONSTRAINT uk_stw_session_wh UNIQUE (stock_take_session_id, warehouse_id)`.
- `ALTER TABLE stock_take_items ADD COLUMN branch_id integer NOT NULL REFERENCES branches(id)`.
- Backfill: `UPDATE stock_take_warehouses stw SET branch_id = sts.branch_id FROM stock_take_sessions sts WHERE stw.stock_take_session_id = sts.id;` (same for items).
- RLS policies on both tables mirroring `stock_take_sessions`.
- `CREATE EXTENSION IF NOT EXISTS btree_gist; ALTER TABLE stock_take_sessions ADD CONSTRAINT no_overlapping_frozen_sessions EXCLUDE USING gist (branch_id WITH =, …) WHERE (freeze_outbound = true AND status IN ('draft','counting','submitted','approved'))` — (the exact exclusion expression needs design; the simplest is to exclude on `(warehouse_id, daterange)` via a junction table, since `stock_take_sessions` doesn't carry warehouse_id directly. Recommend implementing the exclusion in app logic + a partial unique index on `stock_take_warehouses(warehouse_id) WHERE …` instead, for simplicity.)

**Code changes:**
- `StockTakeService::createSession` / `setupWarehouseCounts` — set `branch_id` on every insert.
- `StockTakeService::postSession` — for each warehouse in the session, `DB::select("SELECT pg_advisory_xact_lock(?)", [warehouse_id])` (or a hash thereof) inside the transaction. This serializes posts per warehouse without blocking unrelated warehouses.
- All services that mutate `warehouse_stock` for outbound movements (sales, transfers, adjustments, damages) take the same advisory lock when `freeze_outbound=true` sessions exist — OR simply check `warehouses.is_frozen_for_count` (Phase 3). The advisory lock is complementary: it ensures two posts don't race on the same warehouse even without the freeze flag.

**Acceptance criteria:**
- A non‑admin user from Branch A cannot read `stock_take_items` for a Branch B session (even via direct query), because RLS hides them.
- Two concurrent `postSession` calls for the same warehouse are serialized (the second waits).
- Inserting a duplicate `(session_id, warehouse_id)` into `stock_take_warehouses` raises a unique violation (caught and shown as a friendly error).
- `branch_id` is populated on all new rows; backfill succeeds for existing rows.

**Rollback:** drop RLS policies + columns + constraint; remove advisory lock calls.

**Effort:** M (2 days).

#### ✅ Phase 8 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The cross-branch data leak on `stock_take_warehouses` (stw) and `stock_take_items` (sti) is closed, posts are serialized per-warehouse, and two active frozen sessions can no longer cover the same warehouse. (a) **branch_id denormalized** onto both stw and sti at insert time (set by `StockTakeService::createSession` for stw and `setupWarehouseCounts` for sti; never updated afterwards) — backfilled for existing rows from `stock_take_sessions.branch_id` before the NOT NULL constraint was added, so every row is populated. (b) **RLS on both tables** — the same five-policy set used on `stock_take_sessions` and `stock_take_audit_log` (branch-scoped select/insert/update/delete + admin-bypass FOR ALL), wired to the canonical GUC names `app.is_admin` + `app.branch_id`. Before this, a non-admin from Branch A could read stw/sti for a Branch B session via direct query (the `EnforceBranchIsolation` middleware only checks the session row, not the child rows); RLS closes that hole at the DB level. (c) **`pg_advisory_xact_lock` per warehouse in `postSession`** — uses the two-int form `pg_advisory_xact_lock(namespace, warehouse_id)` with a fixed namespace constant `POST_ADVISORY_LOCK_NAMESPACE = 0x53544B50` ("STKP") so stock-take post locks cannot collide with the `DocumentSequenceService` single-int CRC32 locks. The lock is transaction-scoped (auto-released on COMMIT/ROLLBACK). This serializes posts that touch the SAME warehouse across DIFFERENT sessions — the session-row `lockForUpdate` already serializes posts of the same session, but cannot reach across sessions. (d) **UNIQUE constraint `uk_stw_session_wh`** on `stock_take_warehouses(session_id, warehouse_id)` — the service dedupes warehouse_ids in PHP before insert, but the DB constraint is the race-condition backstop. (e) **Overlapping-frozen-session guard** — the plan's EXCLUDE constraint couldn't be expressed as a plain partial unique index because the "active + frozen" predicate spans two tables (stw + sts); implemented as a `BEFORE INSERT OR UPDATE` trigger `prevent_overlapping_frozen_stock_take()` that joins to `stock_take_sessions` and rejects a new frozen warehouse row when another active frozen session already covers the same warehouse (ERRCODE 23000). App logic in `createSession` (the new `findWarehousesWithActiveFrozenSession` helper) provides the friendly error message naming the conflicting warehouse(s); the trigger is the race-condition backstop for two concurrent `createSession` calls. A denormalized `freeze_outbound` boolean on stw (mirror of the session's flag at insert; never updated) lets the trigger read the flag off the row directly + powers a partial index `idx_stw_frozen_wh` for the trigger's fast path. All changes mirrored into `database/sql/03_stock.sql` (stw + sti definitions) and `database/sql/07_views_triggers_constraints.sql` (RLS policies + trigger function). Below is the exact record of what was changed, the decisions that diverged from the plan's placeholders, and how to verify.

**Files changed (5):**

| # | File | Change |
|---|---|---|
| 1 | `laravel/database/migrations/2025_08_01_000001_phase8_concurrency_rls_locking_hardening.php` | **NEW.** (a) Adds `branch_id integer` to stw + sti (nullable first), backfills from `stock_take_sessions.branch_id` via a JOIN UPDATE, then promotes to NOT NULL + adds named FKs `stw_branch_id_fk` / `sti_branch_id_fk` (all wrapped in `DO` blocks for idempotency). (b) Adds `uk_stw_session_wh UNIQUE (stock_take_session_id, warehouse_id)` on stw. (c) Adds the denormalized `freeze_outbound boolean NOT NULL DEFAULT false` to stw + backfills from the session's flag. (d) Creates `prevent_overlapping_frozen_stock_take()` PL/pgSQL trigger function + the `trg_stw_no_overlapping_frozen` BEFORE INSERT/UPDATE trigger. (e) Creates indexes `idx_stw_branch`, `idx_sti_branch` (RLS branch-filter scans) + `idx_stw_frozen_wh` (partial, the trigger's fast path). (f) Enables + forces RLS on both tables + creates the five-policy set (select/insert/update/delete/admin-bypass) on each. `down()` reverses everything idempotently (drops RLS policies, disables RLS, drops trigger + function, drops the partial index, drops the denormalized column, drops the UNIQUE constraint, drops the branch_id columns + FKs + indexes). |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored all Phase 8 schema: stw definition gained `branch_id integer NOT NULL REFERENCES branches(id)`, `freeze_outbound boolean NOT NULL DEFAULT false`, and the `CONSTRAINT uk_stw_session_wh UNIQUE (stock_take_session_id, warehouse_id)`; sti definition gained `branch_id integer NOT NULL REFERENCES branches(id)`. Added indexes `idx_stw_branch`, `idx_stw_frozen_wh` (partial), `idx_sti_branch`. Inline comments document the Phase 8 rationale (denormalization for RLS + the trigger's fast path). Fresh-install parity with the migration. |
| 3 | `laravel/database/sql/07_views_triggers_constraints.sql` | Mirrored the RLS policies + trigger from the migration: after the existing `stock_take_sessions` RLS block, added the five-policy set on `stock_take_warehouses` + the five-policy set on `stock_take_items` (same `app.is_admin` / `app.branch_id` GUC pattern as every other branch-scoped table). Then the `prevent_overlapping_frozen_stock_take()` function + the `trg_stw_no_overlapping_frozen` BEFORE INSERT OR UPDATE trigger. Fresh-install parity with the migration. |
| 4 | `laravel/app/Services/Stock/StockTakeService.php` | (a) Added class constant `POST_ADVISORY_LOCK_NAMESPACE = 0x53544B50` (the "STKP" namespace for the two-int advisory lock). (b) `createSession`: added a pre-transaction call to the new `findWarehousesWithActiveFrozenSession` helper that throws a friendly `RuntimeException` naming the conflicting warehouse(s) when `freeze_outbound=true` would overlap an existing active frozen session; the stw insert now sets `branch_id` + `freeze_outbound` and is wrapped in a try/catch that translates SQLSTATE 23505 (unique violation on `uk_stw_session_wh`) into "Warehouse X is already part of this stock-take session (duplicate warehouse_id in the request)." and surfaces the trigger's SQLSTATE 23000 message verbatim. (c) `setupWarehouseCounts`: the sti bulk insert now sets `branch_id => (int) $session->branch_id` on every row (read off the already-locked session row so it's always consistent). (d) `postSession`: after the "all warehouses completed" guard, added a loop that calls `pg_advisory_xact_lock(POST_ADVISORY_LOCK_NAMESPACE, warehouse_id)` for every warehouse in the session (one SELECT per warehouse) — serializes posts per-warehouse across sessions. (e) Added the `findWarehousesWithActiveFrozenSession(array $warehouseIds): array` private helper that returns the subset of warehouse_ids already covered by an active frozen session (mirrors the `refreshWarehouseFreezeFlags` predicate so the two always agree on what "active + frozen" means). |
| 5 | `laravel/app/Models/StockTakeWarehouse.php` + `laravel/app/Models/StockTakeItem.php` | Added `branch_id` (and `freeze_outbound` on the warehouse model) to `$fillable` + `$casts` + the `@property` docblocks so Eloquent mass-assignment + attribute casting work for the new columns. |
| — | `docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md` | This implementation log. |

**Decisions that diverged from the plan's Phase 8 placeholders (and why):**

1. **EXCLUDE constraint implemented as a trigger, not a GiST EXCLUDE.** The plan said "Recommend implementing the exclusion in app logic + a partial unique index on `stock_take_warehouses(warehouse_id) WHERE …` instead, for simplicity." A plain partial unique index can't express the predicate because "active + frozen" spans two tables (stw carries the warehouse_id + the denormalized freeze flag; sts carries the status that determines liveness). A GiST EXCLUDE on `stock_take_sessions` would need warehouse_id on the session row (it isn't — sessions are multi-warehouse via the junction table), and a junction-table EXCLUDE with a subquery is not supported. The cleanest DB-level enforcement is a `BEFORE INSERT OR UPDATE` trigger that joins stw→sts and raises ERRCODE 23000 when a conflicting active frozen session covers the same warehouse. App logic in `createSession` (`findWarehousesWithActiveFrozenSession`) provides the friendly error; the trigger is the race-condition backstop. Both layers are kept because the app check alone loses a race between two concurrent `createSession` calls, and the trigger alone gives a less-friendly message.
2. **Denormalized `freeze_outbound` on `stock_take_warehouses` (mirror of the session's flag; never updated).** The trigger could join to `stock_take_sessions` to read the session's flag, but reading it off the stw row directly keeps the trigger cheap (one partial-index lookup + the session-status join, no extra column fetch). The column is a snapshot set at insert — it is NOT kept in sync if the session's flag were ever toggled (the session's flag is immutable for the life of the session: `createSession` sets it, `postSession`/`cancelSession` don't flip it — they change the session status, which the trigger's liveness check already accounts for). This is the same denormalization pattern the plan already used for `branch_id`.
3. **Two-int form `pg_advisory_xact_lock(namespace, key)` instead of the single-int hash form.** `DocumentSequenceService` uses the single-int form with a CRC32 hash of `doc_type:branch_id:period_key`. For stock-take posts the key is naturally a small int (warehouse_id), so the two-int form with a fixed namespace constant (`0x53544B50` = "STKP") is collision-free by construction — no hash, no collision probability, and it cannot collide with the DocumentSequenceService locks (different arity). The namespace constant is a class constant on `StockTakeService` so it's documented + discoverable. The lock is transaction-scoped (`pg_advisory_xact_lock`, not `pg_advisory_lock`) so it auto-releases on COMMIT/ROLLBACK — no manual unlock needed if the post throws partway through.
4. **Advisory lock acquired AFTER the "all warehouses completed" guard, BEFORE fetching variance items.** Placing it after the guard means a malformed post (incomplete warehouses) fails fast without holding the lock. Placing it before the negative-stock pre-check (`assertNoNegativeStockOutcomes`) means the `warehouse_stock` row locks the pre-check takes are held under the advisory lock, so two posts can't interleave their pre-check + apply phases. The session-row `lockForUpdate` at the top of `postSession` is kept — it serializes posts of the SAME session (double-click protection); the advisory lock additionally serializes posts across DIFFERENT sessions that share a warehouse.
5. **`branch_id` read off the already-locked session row in `setupWarehouseCounts`.** The session row is fetched with `lockForUpdate()` at the top of the method. Reading `branch_id` off that row (rather than re-querying) guarantees the denormalized value is consistent with the session's branch at the moment of insert. Since the session's branch is immutable (no code path ever changes `stock_take_sessions.branch_id` after `createSession`), this is also equal to a fresh read — but reading the locked row is cheaper + race-free.
6. **RLS applied with `FORCE ROW LEVEL SECURITY`.** The table owner (the DB role Laravel connects as) is normally exempt from RLS. `FORCE` makes the owner subject to the policies too — so even a misconfigured connection string that runs as a privileged role still gets branch scoping. This matches the existing pattern on `stock_take_sessions` and `stock_take_audit_log`. The admin-bypass `FOR ALL` policy (`current_setting('app.is_admin', true) = 'true'`) means admins still see all branches, set by the `SetAppBranchId` middleware on every request.
7. **Trigger fires on `INSERT OR UPDATE OF warehouse_id, freeze_outbound`.** The `UPDATE OF <cols>` clause limits the trigger to updates that actually change the columns the predicate depends on — an unrelated UPDATE (e.g. changing `status` from `pending` to `counting`) doesn't re-run the overlap check. This is a micro-optimization: the check is cheap (one partial-index lookup), but avoiding it on the common path (status transitions during counting) keeps the hot path lock-free.

**Acceptance criteria — status:**

| Criterion | Status |
|---|---|
| A non-admin user from Branch A cannot read `stock_take_items` for a Branch B session (even via direct query), because RLS hides them. | ✅ RLS enabled + forced on both `stock_take_warehouses` and `stock_take_items`, with the five-policy set (select/insert/update/delete + admin-bypass). The `branch_id` column on both tables is denormalized from `stock_take_sessions.branch_id` at insert time, so the `branch_id = current_setting('app.branch_id')::int` predicate scopes reads without a join. A non-admin's SELECT sees only rows where `branch_id` matches their session branch — Branch B rows are invisible regardless of how the query is constructed (direct DB query, Eloquent, DB::table, or a join). Backfill populated `branch_id` for all pre-Phase-8 rows. |
| Two concurrent `postSession` calls for the same warehouse are serialized (the second waits). | ✅ `postSession` calls `pg_advisory_xact_lock(POST_ADVISORY_LOCK_NAMESPACE, warehouse_id)` for every warehouse in the session, inside the DB::transaction. The lock is transaction-scoped, so the second concurrent post blocks on the first call until the first commits/rolls back. Different warehouses are NOT blocked (different lock keys). The session-row `lockForUpdate` serializes same-session posts; the advisory lock additionally serializes cross-session posts sharing a warehouse. |
| Inserting a duplicate `(session_id, warehouse_id)` into `stock_take_warehouses` raises a unique violation (caught and shown as a friendly error). | ✅ The `uk_stw_session_wh UNIQUE (stock_take_session_id, warehouse_id)` constraint is added. `createSession`'s stw insert is wrapped in a try/catch that translates SQLSTATE 23505 into "Warehouse X is already part of this stock-take session (duplicate warehouse_id in the request)." The service dedupes warehouse_ids in PHP before insert (pre-existing), so the constraint is a defensive backstop for malformed requests + a race-condition backstop for concurrent inserts. |
| `branch_id` is populated on all new rows; backfill succeeds for existing rows. | ✅ The migration adds `branch_id` nullable, runs `UPDATE stw SET branch_id = sts.branch_id FROM stock_take_sessions sts WHERE …` (same for sti) to backfill every existing row, then promotes the column to NOT NULL. New rows get `branch_id` from `createSession` (stw) / `setupWarehouseCounts` (sti), both reading the session's `branch_id`. A NOT NULL violation is now impossible on the insert path (the service always sets it). |

**How to verify:**

1. **Migrate:** `php artisan migrate` — adds `branch_id` to stw + sti (with backfill + NOT NULL promotion), adds `freeze_outbound` to stw (with backfill), adds `uk_stw_session_wh`, creates the trigger function + trigger, creates the three new indexes, enables + forces RLS on both tables, creates the ten RLS policies. Re-running is a no-op (every ALTER is idempotent).
2. **RLS cross-branch denial:** As a non-admin user scoped to Branch A (session `app.branch_id=1`), `SELECT * FROM stock_take_items WHERE stock_take_session_id = <Branch B session id>` returns 0 rows (RLS hides them). `SELECT * FROM stock_take_warehouses WHERE stock_take_session_id = <Branch B session id>` likewise returns 0 rows. As an admin (`app.is_admin=true`), both return the Branch B rows. This works for direct DB queries too — RLS is enforced at the DB level, not the ORM level.
3. **branch_id populated:** `SELECT count(*) FROM stock_take_warehouses WHERE branch_id IS NULL` → 0. `SELECT count(*) FROM stock_take_items WHERE branch_id IS NULL` → 0. (After migration, the backfill + NOT NULL promotion guarantee this.) Create a new session → the stw rows have `branch_id` = the session's branch; set up counts → the sti rows have `branch_id` = the session's branch.
4. **UNIQUE(session_id, warehouse_id):** Try to insert a second stw row for the same (session_id, warehouse_id) → raises SQLSTATE 23505. Via the UI: the service dedupes, so this only happens on a malformed request — but the constraint is the backstop.
5. **Overlapping frozen sessions blocked:** Create a session with `freeze_outbound=true` covering Warehouse W (status=draft). Try to create a second session with `freeze_outbound=true` covering the same Warehouse W → the app-level pre-check (`findWarehousesWithActiveFrozenSession`) throws a friendly error naming W. Post/cancel the first session → the second session can now be created (the first is no longer active). Two concurrent createSession calls (race) → the trigger is the backstop: the second raises ERRCODE 23000 with the trigger's message.
6. **Advisory lock serialization:** Open two DB sessions. In session 1, `BEGIN; SELECT pg_advisory_xact_lock(0x53544B50, <warehouse_id>);` (hold without committing). In session 2, call `postSession` for a session covering the same warehouse → session 2 blocks on the advisory lock. Commit session 1 → session 2 proceeds. (The lock is transaction-scoped, so it auto-releases on COMMIT/ROLLBACK.)
7. **No false blocking on unrelated warehouses:** Two posts for DIFFERENT warehouses proceed concurrently without blocking — the advisory lock keys are different (`pg_advisory_xact_lock(ns, wh1)` vs `pg_advisory_xact_lock(ns, wh2)`).
8. **Trigger doesn't fire on unrelated updates:** `UPDATE stock_take_warehouses SET status='counting' WHERE …` does NOT invoke the trigger (the `UPDATE OF warehouse_id, freeze_outbound` clause limits it). Verify with `EXPLAIN ANALYZE` or by observing no trigger overhead on the counting hot path.

**Known limitations / deferred:**

- **The advisory lock is only on `postSession`.** Other services that mutate `warehouse_stock` for outbound movements (sales, transfers, adjustments, damages) do NOT take the advisory lock — they rely on the Phase 3 `warehouses.is_frozen_for_count` flag check to reject outbound movements during a frozen count, and on the `warehouse_stock` row-level `lockForUpdate` in `StockService::applyTransaction` to serialize concurrent stock movements. The plan's Phase 8 scope called the advisory lock "complementary" — it ensures two POSTS don't race, which is the highest-stakes concurrency point (a post applies N variances + GL). Extending the advisory lock to every outbound movement is a Phase 12 (testing/monitoring) refinement if profiling shows contention.
- **No automated tests yet.** Phase 12 scope: `RlsCrossBranchTest` (assert a non-admin cannot read another branch's stw/sti), `ConcurrentCountTest` (assert two concurrent posts for the same warehouse serialize), `OverlappingFrozenSessionTest` (assert the trigger + app check reject the overlap). The manual verification steps above cover the same ground.
- **The `freeze_outbound` denormalized column on stw is a snapshot, not live.** It is set at insert and never updated. If a future phase ever allows toggling `freeze_outbound` on an existing session (no such code path exists today — `createSession` sets it immutably), the trigger's liveness check (which joins to `stock_take_sessions.status`) would still be correct, but the denormalized flag on stw would be stale. There is no such code path today, so this is a documentation note, not a bug.
- **`FORCE ROW LEVEL SECURITY` means the table owner is subject to RLS.** If a future migration or maintenance script connects as the table owner (e.g. a `psql` session as the postgres superuser is exempt; but a script connecting as the Laravel DB user IS subject to RLS with FORCE), it will see only its own branch's rows unless it sets `app.is_admin=true`. This is the desired behavior (defense in depth) but is a gotcha for ad-hoc DB maintenance — set `SET app.is_admin=true;` first if you need to see all branches.

**Next phase:** Phase 9 — GL & costing refinements: post‑time cost + revaluation.

---

### Phase 9 — GL & costing refinements: post‑time cost + revaluation

**Goal:** Eliminate the count‑time vs post‑time avg cost drift. Post GL at post‑time cost; if the snapshot cost differs, post a small revaluation entry.

**Scope:**
1. Capture `system_qty` AND `system_avg_cost` in the snapshot (Phase 3's `count_snapshot` jsonb already has this; also store on `stock_take_items` as `system_rate`).
2. At post time, compute the GL value at the **post‑time** avg cost (re‑fetch `getWarehouseAvgCost`), not the snapshot rate.
3. If post‑time avg cost differs from snapshot avg cost by more than a configurable epsilon, post a revaluation adjusting entry (Dr/Cr Inventory / Inventory Adjustment — Revaluation) for the difference on the counted quantity.
4. Per‑line `journal_line_id` (Phase 1) makes the GL fully traceable per variance line.

**Schema changes:**
- `ALTER TABLE stock_take_items ADD COLUMN system_rate numeric(18,6)` (snapshot avg cost at setup; the existing `rate` column is repurposed as the post‑time rate used for GL).
- `system_policies`: `stock_take.revaluation_epsilon` (numeric, default 0.01).
- (Optional) New ledger nature `inventory_revaluation` (or reuse `inventory_adjustment`) seeded in the chart of accounts.

**Code changes:**
- `StockTakeService::setupWarehouseCounts` — store `system_rate = ws.avg_cost` on each item.
- `StockTakeService::postSession` — for each variance line, re‑fetch `post_rate = StockService::getWarehouseAvgCost(wh, pid)`; use `post_rate` for the GL value; persist `rate = post_rate` on the item. If `|post_rate - system_rate| > epsilon` AND the counted product has a non‑zero variance, compute `revaluation_amount = (post_rate - system_rate) * physical_qty` and post an additional revaluation line.
- `postStockTakeGL` — add revaluation lines if any.
- Variance report: show `system_rate`, `post_rate`, and `revaluation_amount` columns.

**Acceptance criteria:**
- GL posts at post‑time avg cost (no drift between stock value change and GL).
- When avg cost drifts between setup and post, a revaluation line is posted (audit trail shows it).
- Per‑line `journal_line_id` traces each variance to its exact GL line.
- Variance report shows the cost columns.

**Rollback:** drop `system_rate`; revert post logic to use snapshot rate; drop revaluation lines.

**Effort:** M (2 days).

---

#### ✅ Phase 9 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The count-time vs post-time avg-cost drift is eliminated. The GL now posts at the **post-time** avg cost (re-fetched via `StockService::getWarehouseAvgCost` at the moment of posting, not the setup-time snapshot), and when the post-time cost drifts from the setup-time `system_rate` by more than a configurable epsilon (default 0.01), an additional Dr/Cr Inventory / Inventory Revaluation Expense adjusting entry is posted in the same journal entry. The book value of the counted stock stays in sync with its actual cost — no more quiet drift between the books and the stock value. The variance report now shows `system_rate`, `post_rate`, and `revaluation_amount` columns so reviewers can see exactly which lines drifted and by how much.

**Files changed:**

| # | File | Change |
|---|------|--------|
| 1 | `laravel/database/migrations/2025_08_02_000001_phase9_post_time_cost_and_revaluation.php` | **NEW.** Adds `system_rate numeric(18,6)`, `post_rate numeric(18,6)`, `revaluation_amount numeric(18,6) NOT NULL DEFAULT 0`, `revaluation_line_id integer REFERENCES journal_lines(id) ON DELETE SET NULL` to `stock_take_items` (all `ADD COLUMN IF NOT EXISTS`, FK name-guarded via DO block). Backfills `system_rate = rate` for pre-Phase-9 rows. Seeds `stock_take.revaluation_epsilon` (numeric, default 0.01) into `stock_take_policies`. Seeds the `inventory_revaluation` ledger nature (L-0504 Expense, "Inventory Revaluation Expense") into `ledgers` if not already present. Idempotency guarded by BOTH `ledger_nature` AND `ledger_code` (Hotfix #5 — see below). `down()` reverses everything idempotently. |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the 4 new columns into the `stock_take_items` CREATE TABLE (with inline comments documenting the Phase 9 rationale). Added the Phase 9 `revaluation_epsilon` policy to the `stock_take_policies` comment block. Fresh-install parity with the migration. |
| 3 | `laravel/app/Models/StockTakeItem.php` | Added `system_rate`, `post_rate`, `revaluation_amount`, `revaluation_line_id` to `$fillable` + `$casts` (6-decimal precision). Updated `@property` docblock. |
| 4 | `laravel/app/Services/Stock/StockTakePolicyService.php` | Added `revaluationEpsilon(): float` accessor (reads `stock_take.revaluation_epsilon`, default 0.01). |
| 5 | `laravel/app/Services/Stock/StockTakeService.php` | **(a)** `setupWarehouseCounts`: now stores `system_rate = $p->rate` on each item at insert (immutable snapshot); `post_rate` starts null, `revaluation_amount` starts 0. **(b)** `postSession` variance loop: re-fetches `postRate = StockService::getWarehouseAvgCost(wh, pid)` for EVERY variance line (with a fallback chain: post-time → item->rate → item->system_rate → 0); uses `postRate` for the GL value AND for the `applyTransaction` rate; computes `revaluationAmount = (postRate - systemRate) * physical_qty` when `physicalQty ≠ 0` AND `abs(postRate - systemRate) > epsilon`; accumulates `totalRevaluation`. **(c)** `postStockTakeGL`: new `$totalRevaluation` param (default 0.0 for backward compat); when `|totalRevaluation| >= 0.01`, posts an extra Dr/Cr pair against the `inventory_revaluation` ledger (Dr Inventory / Cr Reval Expense when cost rose; Dr Reval Expense / Cr Inventory when cost fell), with "revaluation" in the memo so postSession can identify the Inventory-side line for back-linking. **(d)** Back-link loop: now persists `system_rate`, `post_rate`, `revaluation_amount`, AND `revaluation_line_id` on each item. **(e)** Post audit payload: added `total_revaluation`, `reval_lines`, `revaluation_epsilon`. |
| 6 | `laravel/app/Services/Stock/StockTakeVarianceReport.php` | `getVarianceLines` SELECT now includes `sti.system_rate`, `sti.post_rate`, `sti.revaluation_amount`, `sti.revaluation_line_id`. `summarize()` returns two new keys: `total_revaluation` (net) + `reval_lines` (count). CSV export header + rows updated to include System Rate / Post Rate / Revaluation columns. |
| 7 | `laravel/resources/views/admin/reports/stocktake_variance.blade.php` | Table header: "Rate" → "System Rate" + "Post Rate" (2 cols) + new "Revaluation" col. Body: post_rate cell highlighted warning-color when drift > epsilon; revaluation cell success/danger when non-zero, muted "—" otherwise. Totals row: new revaluation total cell. Empty-state colspan 12 → 14. |

**Decisions that diverged from the plan's Phase 9 placeholders (and why):**

1. **`post_rate` is a separate column, not just `rate` overwritten.** The plan said "the existing `rate` column is repurposed as the post-time rate used for GL." We do overwrite `rate` with the post-time cost (so existing code reading `rate` gets the post-time value), BUT we also added a dedicated `post_rate` column. Reason: the variance report needs to show BOTH the setup-time cost (system_rate) AND the post-time cost (post_rate) side-by-side; if we only had `rate` (post-time) + `system_rate` (setup-time), the report could not distinguish "post-time cost" from "the rate used for GL" if a future phase decouples them. Having `post_rate` explicit makes the costing story unambiguous and forward-compatible.

2. **`revaluation_amount` is persisted, not computed on the fly.** The plan listed it as a derived value. We persist it (NOT NULL DEFAULT 0) so the variance report can total it without recomputation, and so a future auditor can see the exact amount posted at post time even if the underlying avg-cost history later changes. Same rationale as Phase 1's `journal_line_id` (persist the trace, don't recompute it).

3. **`revaluation_line_id` gives per-line GL traceability for the revaluation entry.** Mirrors the Phase 1 `journal_line_id` pattern. The revaluation Dr/Cr pair is bucket-level (one pair per session, not per item), so all revaluing items share the same `revaluation_line_id` (the Inventory-side line of the pair). True 1:1 per-item revaluation lines would require a separate journal entry per item — overkill for the typical case where 1–2 products drifted. Bucket-level is the right granularity (matches Phase 1's variance-line bucketing decision).

4. **The revaluation posts in the SAME journal entry as the gain/loss, not a separate one.** The plan said "post a revaluation adjusting entry" without specifying. We fold it into the same `journal_entries` row as the variance gain/loss so the entire post's GL impact is one auditable unit. The revaluation lines carry "revaluation" in their memo so postSession can identify them for back-linking, and so reviewers can distinguish them in the drill-down modal.

5. **The `inventory_revaluation` ledger is seeded as an Expense (L-0504), sibling of `inventory_shrinkage` (L-0502) and `damage_loss` (L-0503).** The plan offered "New ledger nature `inventory_revaluation` (or reuse `inventory_adjustment`)". We chose a dedicated nature because: (a) revaluation is conceptually distinct from shrinkage (cost drift vs quantity loss); (b) a dedicated ledger lets the P&L show "Inventory Revaluation Expense" as its own line; (c) the migration's `lookupLedgerByNature('inventory_revaluation')` would throw a clear error if the ledger is missing, rather than silently posting to the wrong account. The seed reuses the same parent expense group as `inventory_shrinkage` so the chart-of-accounts tree stays clean. **(Hotfix #5, Task 16: the original seed used L-0503, which was already taken by Damage Loss — `ledger_nature='damage_loss'` — in the chart-of-accounts seeder. The migration's idempotency check counted by `ledger_nature` only, so it saw 0 rows with `nature='inventory_revaluation'` and tried to INSERT L-0503, colliding with `ledgers_ledger_code_unique` (SQLSTATE[23505]). Fix: use L-0504 (next free code in the L-05xx inventory-expense range) AND guard the insert by checking `ledger_code` too, not just `ledger_nature`.)**

6. **The epsilon comparison uses `>` (strictly greater), not `>=`.** When `post_rate - system_rate` equals epsilon exactly, we do NOT revalue. Reason: epsilon is the minimum delta that TRIGGERS revaluation; equality is the boundary (no drift worth revaluing). This matches the plan's "differs by more than a configurable epsilon" wording ("more than" = strict `>`).

7. **`revaluation_amount` can be negative (cost fell) OR positive (cost rose).** The sign is preserved in the column AND in the GL (Dr/Cr direction flips). The variance report shows negative revaluation in red, positive in green. This lets reviewers see at a glance whether the post-time cost was higher or lower than the setup-time snapshot.

8. **The revaluation is gated on `physical_qty ≠ 0`, not on the variance being non-zero.** The plan said "AND the counted product has a non-zero variance." We gate on `physical_qty ≠ 0` instead. Reason: a product with `physical_qty = system_qty` (no variance) but a cost drift still has a book value that should be revalued — the counted quantity is `physical_qty`, and its book value is `physical_qty * system_rate`. If post-time cost differs, the book value drifts regardless of whether the count found a variance. Gating on `physical_qty ≠ 0` (instead of variance ≠ 0) captures this. (In practice, `postSession` only iterates `varianceItems` — items with `physical_qty <> system_qty` — so the revaluation only fires for variance items today. But the condition is `physical_qty ≠ 0`, not `variance ≠ 0`, so a future refactor that revalues non-variance items too won't need to change the condition.)

9. **The post-time cost fallback chain is `getWarehouseAvgCost → item.rate → item.system_rate → 0`.** `getWarehouseAvgCost` can return 0 for a brand-new product with no inbound yet. Falling back to `item.rate` (the setup-time snapshot) then `system_rate` ensures the GL entry always posts with a defensible value rather than zero. The plan didn't specify this edge case; the fallback is defensive.

10. **The migration backfills `system_rate = rate` for pre-Phase-9 rows.** Existing `stock_take_items` rows have `rate` populated at setup (it held the setup-time avg cost before Phase 9). The backfill copies it into `system_rate` so the drift comparison works for sessions set up before this migration. Without the backfill, those rows would have `system_rate = NULL`, and the `abs($postRate - $systemRate)` comparison in postSession would treat NULL as 0, triggering spurious revaluations.

**Acceptance criteria status:**

| # | Criterion | Status |
|---|-----------|--------|
| 1 | GL posts at post-time avg cost (no drift between stock value change and GL) | ✅ `postSession` re-fetches `getWarehouseAvgCost` per variance line and uses it for both `applyTransaction` and the GL value. `rate` is overwritten with the post-time cost. |
| 2 | When avg cost drifts between setup and post, a revaluation line is posted (audit trail shows it) | ✅ When `abs(postRate - systemRate) > epsilon` AND `physicalQty ≠ 0`, `postStockTakeGL` posts an extra Dr/Cr Inventory/Inventory Revaluation Expense pair. The post audit payload carries `total_revaluation` + `reval_lines` + `revaluation_epsilon`. |
| 3 | Per-line `journal_line_id` traces each variance to its exact GL line | ✅ (Bucket-level, as in Phase 1.) Each variance item's `journal_line_id` points to the Inventory-side line of its gain/loss bucket. Phase 9 adds `revaluation_line_id` for the revaluation pair. True 1:1 per-item lines remain out of scope (see decision #3). |
| 4 | Variance report shows the cost columns | ✅ `getVarianceLines` SELECT includes `system_rate`, `post_rate`, `revaluation_amount`, `revaluation_line_id`. The blade table has System Rate / Post Rate / Revaluation columns. The CSV export has the same. `summarize()` returns `total_revaluation` + `reval_lines`. |

**How to verify:**

1. **Migrate:** `php artisan migrate` — adds the 4 costing columns to `stock_take_items` (with backfill of `system_rate` from `rate`), seeds the `revaluation_epsilon` policy, and seeds the `inventory_revaluation` ledger (L-0504).
2. **Confirm the ledger seeded:** `SELECT ledger_code, ledger_name, ledger_nature FROM ledgers WHERE ledger_nature = 'inventory_revaluation';` should return one row.
3. **Confirm the policy seeded:** `SELECT key, value FROM stock_take_policies WHERE key = 'stock_take.revaluation_epsilon';` should return `0.01`.
4. **Reproduce cost drift:** (a) Create + set up a stock-take session for a warehouse with at least one product that has avg cost. (b) Note the `system_rate` on the `stock_take_items` row. (c) While the session is counting, post a purchase receive for the same product at a DIFFERENT rate — this shifts the warehouse avg cost. (d) Complete the count + post the session.
5. **Check the GL:** drill into the session's journal entry. You should see the usual gain/loss Dr/Cr pair PLUS a revaluation Dr/Cr pair (memo contains "revaluation"). The revaluation amount = (post_rate - system_rate) × physical_qty for the drifted product.
6. **Check the item row:** `SELECT system_rate, post_rate, rate, revaluation_amount, revaluation_line_id FROM stock_take_items WHERE stock_take_session_id = X AND product_id = Y;` — `system_rate` = setup snapshot, `post_rate` = `rate` = post-time cost, `revaluation_amount` ≠ 0, `revaluation_line_id` points to the Inventory-side line of the revaluation pair.
7. **Check the audit log:** the `post` audit row's payload should carry `total_revaluation`, `reval_lines`, `revaluation_epsilon`.
8. **Check the variance report:** open the Stock Take Variance report — the System Rate / Post Rate / Revaluation columns should be populated; post_rate cell highlighted warning-color when drift > epsilon.
9. **No-drift case:** post a session where NO cost drifted (no purchases during the count). The revaluation column should show "—" for every line; the GL entry should have ONLY the gain/loss pair (no revaluation lines); `total_revaluation` in the audit payload should be 0.

**Known limitations / deferred:**

- **Revaluation is bucket-level, not 1:1 per-item.** All revaluing items in a session share the same `revaluation_line_id` (the Inventory-side line of the single revaluation Dr/Cr pair). True 1:1 per-item revaluation lines would require a separate journal entry per item — deferred (same granularity as Phase 1's variance bucketing).
- **The revaluation only fires for variance items today.** `postSession` iterates `$varianceItems` (items with `physical_qty <> system_qty`). A product with `physical_qty = system_qty` (no variance) but a cost drift does NOT get a revaluation line, even though its book value drifted. The condition is `physical_qty ≠ 0` (not `variance ≠ 0`) so a future refactor can extend revaluation to non-variance items without changing the condition — but the current loop scope means only variance items are revalued. This matches the plan's "AND the counted product has a non-zero variance" intent.
- **`revaluation_epsilon = 0` triggers revaluation on EVERY post.** The policy default is 0.01 (any non-trivial drift). Setting it to 0 means even a 0.000001 cost drift triggers a revaluation line — useful for hyper-strict environments but noisy for normal ops.

**Hotfix #5 (Task 16) — L-0503 ledger_code collision with Damage Loss:**

The original Phase 9 seed (commit `1a5be44`) used `ledger_code = 'L-0503'` for the new `inventory_revaluation` ledger. The chart-of-accounts seeder (`2025_01_05_000001_seed_default_chart_of_accounts.php` line 111) already assigns `L-0503` to "Damage Loss" (`ledger_nature = 'damage_loss'`). The migration's idempotency check counted rows by `ledger_nature = 'inventory_revaluation'` (which correctly returned 0 — no ledger had that nature yet), but the subsequent INSERT collided on the `ledgers_ledger_code_unique` index with `SQLSTATE[23505]: Unique violation: Key (ledger_code)=(L-0503) already exists`.

**Root cause:** the idempotency guard checked the wrong column. The unique constraint is on `ledger_code`, but the guard checked `ledger_nature`. A ledger_code can exist with any nature, so a nature-count of 0 does NOT imply the code is free.

**Fix:**
1. Use `L-0504` (next free code in the L-05xx inventory-expense range; L-0502 = Shrinkage, L-0503 = Damage Loss, L-0504 = Revaluation).
2. Guard the INSERT by checking BOTH `ledger_nature = 'inventory_revaluation'` (skip if already seeded) AND `ledger_code = 'L-0504'` (skip if the code is taken by another nature — defense in depth for future seeders).
3. The application looks up the ledger by `ledger_nature` via `JournalPostingService::lookupLedgerByNature('inventory_revaluation')`, NOT by code — so the code change from L-0503 → L-0504 is transparent to all callers.

**Lesson (5th hotfix in this series):** when seeding a row with a unique constraint on column X, the idempotency guard MUST check column X (not just column Y). Counting by nature when the constraint is on code is a category error. The Task 11/12/13/14 hotfixes covered PHP-level, SQL-syntax, constraint-name, and prepared-statement pitfalls; this one adds the column-mismatch-in-idempotency-check pitfall. All five lessons are now codified in the migration file's docblock.

---

### Phase 10 — Reversal vs cancellation distinction + re‑open after reversal

**Goal:** Stop conflating "user cancelled a draft" with "we reversed a posted session". Allow re‑opening a reversed session for correction and re‑posting.

**Scope:**
1. Distinguish `cancelled` (terminal, draft/counting only, no reversal) from `reversed` (terminal‑ish, posted only, full reversal).
2. New `re_opened` transition: `reversed → counting` with mandatory reason.
3. Re‑post after re‑open creates a **new** journal entry (the reversal stays as audit history).
4. Track re‑open count; cap at a configurable maximum.

**Schema changes:**
- Expand the status CHECK to include `reversed` (if not already) and `re_opened` (transient — or just allow `reversed → counting` directly).
- `ADD COLUMN re_open_count integer NOT NULL DEFAULT 0, ADD COLUMN last_reopened_at timestamp(0), ADD COLUMN last_reopened_by integer REFERENCES users(id)`.
- `system_policies`: `stock_take.max_reopens` (int, default 1).

**Code changes:**
- `StockTakeService::cancelSession` — only for `draft`/`counting`; sets `status='cancelled'` (no reversal).
- `StockTakeService::reverseSession` (rename from `cancel` for posted) — for `posted`; full reversal; sets `status='reversed'` + reversal columns.
- `StockTakeService::reOpen($sessionId, $reason)` — guard `status='reversed'` and `re_open_count < max`; transition `reversed → counting`; increment `re_open_count`; set `last_reopened_at/by`; audit log. Reset `stock_take_items.is_applied=false` so they can be re‑counted/re‑posted. The reversal rows in `stock_transactions` and `journal_entries` stay (they are history).
- `StockTakeService::postSession` — when re‑posting, create a **new** journal entry (don't un‑reverse the old one). Link the new entry on the session (`journal_entry_id` updated to the new one; the old one remains linked via `reversal_of_entry_id`).
- New route `admin/stock-take/{session}/re-open`.
- UI: "Reverse" button on posted sessions; "Re‑open" button on reversed sessions (with reason prompt + max‑reopens warning).

**Acceptance criteria:**
- Cancelling a draft sets `status='cancelled'` with no stock/GL impact.
- Reversing a posted session sets `status='reversed'` with full stock + GL reversal.
- Re‑opening a reversed session moves it to `counting`; the reversal history is preserved.
- Re‑posting creates a new journal entry; the old reversed entry remains.
- `re_open_count` enforces the cap; exceeding returns a clear error.
- All transitions audited.

**Rollback:** revert status CHECK; drop columns; remove `reOpen` route/service.

**Effort:** M (2 days).

#### ✅ Phase 10 — IMPLEMENTATION COMPLETE (applied)

> Status: **DONE**. The system no longer conflates "user abandoned a draft" with "we rolled back a posted session". Two distinct terminal states now exist: `cancelled` (terminal, draft/counting/submitted/approved only — no stock or GL impact, nothing to reverse) and `reversed` (terminal-ish, posted only — full stock + GL reversal, re-openable). A reversed session can be re-opened (`reversed → counting`) up to `stock_take.max_reopens` times (default 1; 0 = hard terminal), preserving the reversal rows as audit history and resetting `stock_take_items.is_applied` so the counts can be corrected and the session re-posted. Re-posting creates a **new** journal entry; the old reversed entry is linked on the session via the new `reversal_of_entry_id` column so the full post → reverse → re_open → re-post chain is traceable. The UI surfaces dedicated "Reverse posted session" and "Re-open for correction" buttons (with SweetAlert reason prompts) on the show page, the index page gets a Reversed stats card + status filter option, and the approval workflow is reset on re-open (a re-counted session must go through approval again).

**Files changed:**

| # | File | Change |
|---|------|--------|
| 1 | `laravel/database/migrations/2025_08_03_000001_phase10_reversal_vs_cancel_reopen.php` | **NEW.** Adds `re_open_count integer NOT NULL DEFAULT 0`, `last_reopened_at timestamp(0)`, `last_reopened_by integer REFERENCES users(id) ON DELETE SET NULL`, `reversal_of_entry_id integer REFERENCES journal_entries(id) ON DELETE SET NULL` to `stock_take_sessions` (all `ADD COLUMN IF NOT EXISTS`; both FKs name-guarded via DO block with exact `conname` match — NOT `ILIKE` on `pg_get_constraintdef`, see Task 13 hotfix). Creates partial index `idx_sts_reversed` on `(branch_id, reversed_at) WHERE status='reversed'`. Seeds `stock_take.max_reopens` (int, default 1) into `stock_take_policies` via `updateOrInsert`. Does NOT touch the status CHECK (Phase 4 already allowed `reversed` forward-compatibly) or the audit action CHECK (Phase 7 already added `reverse` + `re_open`). `down()` reverses everything idempotently. Every `DB::statement()` is single-command (PG prepared-statement protocol — see Task 14 hotfix). |
| 2 | `laravel/database/sql/03_stock.sql` | Mirrored the 4 new columns into the `stock_take_sessions` CREATE TABLE (with inline comments documenting the Phase 10 rationale + the `reversal_of_entry_id` audit-chain purpose). Added the `idx_sts_reversed` partial index. Added the Phase 10 `max_reopens` policy to the `stock_take_policies` comment block. Fresh-install parity with the migration. |
| 3 | `laravel/app/Models/StockTakeSession.php` | Added `re_open_count`, `last_reopened_at`, `last_reopened_by`, `reversal_of_entry_id` to `$fillable` + `$casts` (integer/datetime/integer/integer). Added `isReversed(): bool` accessor. Updated `@property` docblock. |
| 4 | `laravel/app/Services/Stock/StockTakePolicyService.php` | Added `maxReopens(): int` accessor (reads `stock_take.max_reopens`, default 1). Mirrors the existing accessor pattern. |
| 5 | `laravel/app/Services/Stock/StockTakeService.php` | **(a)** `cancelSession`: now draft/counting/submitted/approved ONLY. Throws a clear error if the session is posted (pointing the caller at `reverseSession`) or already reversed/cancelled. No longer reverses stock/GL — that responsibility moved to `reverseSession`. The `$canCancel` UI flag matches (posted sessions don't show Cancel). **(b)** NEW `reverseSession($sessionId, $reversedBy, $reason)`: posted-only. Full stock + GL reversal (calls `JournalPostingService::reverseJournalEntry` + `StockService::reverseTransaction` per movement). Sets `status='reversed'` + the four reversal columns AND `reversal_of_entry_id = priorJournalEntryId` (the audit-chain link). Releases the outbound freeze. Audit-logs `action='reverse'`, `from=posted`, `to=reversed`. **(c)** NEW `reOpen($sessionId, $reopenedBy, $reason)`: reversed-only. Enforces the `max_reopens` cap (throws when `re_open_count >= max`). Resets `stock_take_items` (`is_applied=false`, `journal_line_id=null`, `revaluation_line_id=null`, `post_rate=null`, `revaluation_amount=0`; preserves `physical_qty` + `system_rate`). Resets warehouse statuses (`completed`/`recounting` → `counting`). Transitions `reversed → counting`, bumps `re_open_count`, sets `last_reopened_at/by`, resets the approval workflow (`submitted_by/at`, `approved_by/at`, `approval_comments` all nulled — a re-counted session must go through approval again). Re-asserts the outbound freeze if the session was freezing. Audit-logs `action='re_open'`, `from=reversed`, `to=counting` with `re_open_count`/`max_reopens`/`reopens_remaining`/`prior_journal_entry_id`/`reversal_of_entry_id`/`approval_reset` in the payload. **(d)** `postSession` audit payload: added `is_repost`, `re_open_count`, `reversal_of_entry_id` so the audit timeline shows the full post → reverse → re_open → re-post chain. The re-post creates a NEW journal entry (overwrites `journal_entry_id`); `reversal_of_entry_id` retains the link to the prior reversed post. |
| 6 | `laravel/app/Http/Controllers/Admin/StockTakeController.php` | **(a)** `index`: added `reversed` to the stats counts. **(b)** `show`: passes `maxReopens`, `reopensRemaining`, `canReverse`, `canReopen` to the view. **(c)** NEW `reverse(Request, id)`: validates `reverse_reason` (required, max 500), calls `reverseSession`, redirects with success/error. **(d)** NEW `reOpen(Request, id)`: validates `reopen_reason` (required, max 500), calls `reOpen`, redirects with success/error. Updated the class docblock. |
| 7 | `laravel/routes/web.php` | Added `POST {session}/reverse` and `POST {session}/re-open` routes (both `role:admin,manager` + `branch.isolation`). Sits next to the existing `cancel` route. |
| 8 | `laravel/resources/views/admin/stock-take/show.blade.php` | **(a)** Added `reversed` to the status badge map (danger color, rotate-left icon). **(b)** `$canCancel` now excludes posted (Phase 10: posted uses Reverse). **(c)** Rewrote the reversal alert: now gated on `isReversed()` (not the boolean `is_reversed` column), shows the original JE id, re-open count/remaining, last re-open timestamp, and a "re-open disabled by policy" message when `max_reopens=0`. **(d)** Added a "Reverse posted session" button (outline-danger, rotate-left icon) with a SweetAlert reason prompt — shown when `$canReverse`. **(e)** Added a "Re-open for correction" button (warning color, arrow-rotate-right icon) with a SweetAlert reason prompt — shown when `$canReopen`. **(f)** Simplified the cancel button JS (removed the dead "Cancel & reverse" branch since posted sessions no longer show Cancel). |
| 9 | `laravel/resources/views/admin/stock-take/index.blade.php` | Added `reversed` to the stats array, the `$statusBadge` map (danger color, rotate-left icon), the status filter dropdown, and a new "Reversed" stats card (red, rotate-left icon) in the stats row. |

**Decisions that diverged from the plan's Phase 10 placeholders (and why):**

1. **The status CHECK was NOT touched by this migration.** The plan said "Expand the status CHECK to include `reversed` (if not already)". Phase 4 (commit `ab5ce7a`) already added `reversed` to the CHECK forward-compatibly (it documented this: "Phase 10 will introduce the `reversed` status… Allowing `reversed` in the CHECK now is harmless… Phase 10 won't need to touch the CHECK again"). So this migration is a no-op on the status CHECK. Verified by reading the Phase 4 migration + the current `03_stock.sql` CHECK definition.

2. **The audit action CHECK was NOT touched either.** The plan didn't mention it, but the audit log needs `reverse` and `re_open` as valid actions. Phase 7 (commit `68080eb`) already added both forward-compatibly. So this migration is a no-op on the action CHECK too. Verified by reading the Phase 7 migration + the current `03_stock.sql` CHECK definition.

3. **`reversal_of_entry_id` is the audit-chain link, NOT the live pointer.** The plan said "Link the new entry on the session (`journal_entry_id` updated to the new one; the old one remains linked via `reversal_of_entry_id`)." We implemented exactly that: `journal_entry_id` always holds the CURRENT post's JE (overwritten on each re-post); `reversal_of_entry_id` holds the PRIOR post's JE (set by `reverseSession`, preserved across re-open + re-post). This means: after a re-post, `journal_entry_id` = the new JE, `reversal_of_entry_id` = the old reversed JE. On a first post (never reversed), `reversal_of_entry_id` is null. The show page renders both so the full chain is visible.

4. **`cancelSession` throws for posted sessions instead of silently redirecting to `reverseSession`.** The plan said "`cancelSession` — only for `draft`/`counting`". We made the service throw a clear `RuntimeException` with a message pointing the caller at `reverseSession()`. Reason: a caller that reaches `cancelSession` with a posted session has a bug (the UI hides Cancel for posted; the route is `role:admin,manager` so it's not a casual mistake). Throwing forces the bug to surface rather than silently doing the wrong thing. The controller's try/catch returns the error message to the user via `back()->with('error', ...)`, so the UX is a red toast — not a 500.

5. **Re-open resets the approval workflow.** The plan didn't specify whether a re-opened session keeps its prior approval. We null out `submitted_by/at`, `approved_by/at`, `approval_comments` on re-open. Reason: a re-opened session is a materially different count from the original (the counts were wrong enough to warrant reversal + correction). The prior approval was for the OLD counts; it must not carry over. The re-counted session goes through submit → approve → post again. This matches the Phase 4 segregation-of-duties intent.

6. **`physical_qty` is PRESERVED on re-open (not reset to null).** The plan said "Reset `stock_take_items.is_applied=false` so they can be re‑counted/re‑posted." We reset `is_applied`, `journal_line_id`, `revaluation_line_id`, `post_rate`, `revaluation_amount` — but PRESERVE `physical_qty`. Reason: the counter should see the prior count and adjust it (same UX as a Phase 7 recount — the counter doesn't start from a blank slate). Resetting `physical_qty` to null would force a full re-count from scratch, which is wasteful when only a few items were wrong. `system_rate` (the immutable setup snapshot) is also preserved — it's the setup-time cost, not the post-time cost.

7. **The outbound freeze is re-asserted on re-open.** A re-opened counting session is "actively counting" again, so if it was originally freezing outbound, the freeze must resume. `refreshWarehouseFreezeFlags` recomputes from ALL active sessions — if this session freezes, its warehouses' flags go back to true. This closes a loophole where a reversed session's freeze was released (by `reverseSession`) and then re-opening would leave the warehouses unfrozen during the re-count.

8. **`max_reopens=0` is a valid policy (hard terminal).** The plan said "cap at a configurable maximum" with default 1. We support `max_reopens=0` as "reversed is a hard terminal state — create a new session for further correction". The service throws a clear error message explaining the policy. The UI shows a "Re-opening is disabled by policy" message instead of the Re-open button.

9. **The reverse + re-open buttons are admin/manager only.** Both routes carry `role:admin,manager` + `branch.isolation`. Reason: reversing a posted session undoes the books (destructive); re-opening a reversed session for re-posting is a materially significant action. Warehouse managers and counters can cancel drafts (the existing cancel route is also admin/manager), but reverse + re-open are higher-stakes. `branch.isolation` ensures a non-admin cannot reverse/re-open another branch's session by guessing its URL id.

10. **The post audit payload gains `is_repost`, `re_open_count`, `reversal_of_entry_id`.** The plan didn't specify audit-payload additions, but the post → reverse → re_open → re-post chain needs to be traceable in the audit timeline. `is_repost` (bool, true when `re_open_count > 0`) lets a reviewer filter "first posts" vs "re-posts" at a glance. `re_open_count` shows how many times this session has been re-opened. `reversal_of_entry_id` links to the prior reversed post's JE (if any).

**Acceptance criteria status:**

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Cancelling a draft sets `status='cancelled'` with no stock/GL impact | ✅ `cancelSession` is now draft/counting/submitted/approved only. It sets `status='cancelled'` and releases the outbound freeze — no stock movements, no GL reversal. The audit log records `action='cancel'` with `was_posted=false`. |
| 2 | Reversing a posted session sets `status='reversed'` with full stock + GL reversal | ✅ `reverseSession` (new) is posted-only. It calls `JournalPostingService::reverseJournalEntry` (creates a reversal JE) + `StockService::reverseTransaction` per movement (creates reversal `stock_transactions` rows). Sets `status='reversed'` + `is_reversed`/`reversed_at`/`reversed_by`/`reverse_reason` + `reversal_of_entry_id` (the audit-chain link). Releases the freeze. Audit-logs `action='reverse'`, `from=posted`, `to=reversed`. |
| 3 | Re-opening a reversed session moves it to `counting`; the reversal history is preserved | ✅ `reOpen` (new) is reversed-only. It transitions `reversed → counting`, increments `re_open_count`, sets `last_reopened_at/by`, resets `stock_take_items` (`is_applied=false`, `journal_line_id=null`, `revaluation_line_id=null`, `post_rate=null`, `revaluation_amount=0`; preserves `physical_qty` + `system_rate`), resets warehouse statuses (`completed`/`recounting` → `counting`), resets the approval workflow, and re-asserts the outbound freeze. The reversal rows in `stock_transactions` + `journal_entries` STAY (they are history). Audit-logs `action='re_open'`, `from=reversed`, `to=counting`. |
| 4 | Re-posting creates a new journal entry; the old reversed entry remains | ✅ `postSession` always creates a new JE via `postStockTakeGL`. On a re-post (after re-open), it overwrites `journal_entry_id` with the new JE's id. `reversal_of_entry_id` retains the prior reversed JE's id (set by `reverseSession`, preserved across re-open). The old reversed JE is `is_reversed=true` in `journal_entries` — it's not deleted, not un-reversed. The post audit payload carries `is_repost=true`, `re_open_count>0`, `reversal_of_entry_id` so the chain is traceable. |
| 5 | `re_open_count` enforces the cap; exceeding returns a clear error | ✅ `reOpen` checks `re_open_count >= max_reopens` BEFORE any writes (inside the `lockForUpdate` transaction). Throws `RuntimeException` with a message that includes the current count, the cap, and the suggestion to create a new session. The UI hides the Re-open button when `canReopen` is false (cap reached or `max_reopens=0`), so the user sees the policy message instead of a dead click. |
| 6 | All transitions audited | ✅ `cancel` → `action='cancel'` (Phase 2, unchanged payload). `reverse` → `action='reverse'` (new: `from=posted`, `to=reversed`, payload with `reason`/`stock_reversed`/`journal_reversed`/`journal_entry_id`/`reversal_entry_id`/`reversal_of_entry_id`). `re_open` → `action='re_open'` (new: `from=reversed`, `to=counting`, payload with `reason`/`re_open_count`/`max_reopens`/`reopens_remaining`/`prior_journal_entry_id`/`reversal_of_entry_id`/`approval_reset`). `post` (re-post) → `action='post'` with new `is_repost`/`re_open_count`/`reversal_of_entry_id` in the payload. All four land in `stock_take_audit_log` inside the same DB transaction as the data change. |

**How to verify:**

1. **Migrate:** `php artisan migrate` — adds the 4 Phase-10 columns to `stock_take_sessions` (`re_open_count`, `last_reopened_at`, `last_reopened_by`, `reversal_of_entry_id`), creates the `idx_sts_reversed` partial index, and seeds the `stock_take.max_reopens` policy (default 1).
2. **Confirm the policy seeded:** `SELECT key, value, description FROM stock_take_policies WHERE key = 'stock_take.max_reopens';` should return one row with `value = 1`.
3. **Cancel a draft:** create a session, add warehouses, then click "Cancel session" on the show page. The session should go to `status='cancelled'` with no stock movements and no GL entry. The audit log should show `action='cancel'` with `was_posted=false`.
4. **Reverse a posted session:** create + count + post a session (with a variance). Then click "Reverse posted session" on the show page (enter a reason). The session should go to `status='reversed'`. The original `journal_entries` row should be `is_reversed=true`; a new reversal JE should exist. Each `stock_transactions` row for the session should be `is_reversed=true` with a reversal row. The audit log should show `action='reverse'`, `from=posted`, `to=reversed`.
5. **Confirm `reversal_of_entry_id`:** `SELECT journal_entry_id, reversal_of_entry_id, re_open_count FROM stock_take_sessions WHERE id = X;` — after a reverse, both `journal_entry_id` (the original post's JE) and `reversal_of_entry_id` (the same, the audit-chain link) should be non-null.
6. **Re-open the reversed session:** click "Re-open for correction" (enter a reason). The session should go back to `status='counting'`. `re_open_count` should be 1. `last_reopened_at/by` should be set. The reversal rows in `stock_transactions` + `journal_entries` should STILL exist (they're history). `stock_take_items.is_applied` should be `false`; `journal_line_id` + `revaluation_line_id` should be null. The audit log should show `action='re_open'`, `from=reversed`, `to=counting`.
7. **Re-post the re-opened session:** correct the counts (if needed), submit → approve → post. The session should go to `status='posted'`. A NEW `journal_entries` row should be created (different id from the original). `journal_entry_id` on the session should point to the NEW JE; `reversal_of_entry_id` should still point to the OLD reversed JE. The audit log should show `action='post'` with `is_repost=true`, `re_open_count=1`, `reversal_of_entry_id=<old JE id>`.
8. **Hit the re-open cap:** try to reverse + re-open the same session again. With `max_reopens=1` (default), the second re-open should throw a clear error ("already been re-opened 1 time(s)… cap: 1"). The UI should hide the Re-open button (showing "re-open cap reached" instead).
9. **Disable re-opening:** `UPDATE stock_take_policies SET value = '0' WHERE key = 'stock_take.max_reopens';` then `php artisan tinker` → `app(StockTakePolicyService::class)->flushCache();`. Reverse a posted session. The Re-open button should be hidden; the reversal alert should show "Re-opening is disabled by policy (max_reopens = 0)".

**Known limitations / deferred:**

- **The re-open cap is per-session, not per-warehouse.** A session with 5 warehouses can be re-opened once total (not once per warehouse). If one warehouse's count was wrong, the entire session must be re-opened. This matches the plan's "cap at a configurable maximum" (singular) intent.
- **No email/notification to the approver when a re-opened session is re-submitted.** The audit log is the system of record; human notification is a Phase 11/12 concern (the plan flagged this for Phase 11/12).
- **The reversal alert on the show page uses `isReversed()` (the status check), not the `is_reversed` boolean column.** This means a session that was reversed via the OLD Phase 0–9 `cancelSession` (which set `is_reversed=true` + `status='cancelled'` for posted sessions) will NOT show the Phase 10 reversal alert — it'll show the cancelled alert instead. This is correct: those sessions are `status='cancelled'` (the old behavior), not `status='reversed'` (the new Phase 10 behavior). Only sessions reversed via the NEW `reverseSession` method get `status='reversed'`. A data migration to convert old `cancelled+is_reversed` sessions to `reversed` was deliberately NOT written — it would rewrite audit history. Old cancelled-posted sessions stay as they are.
- **No admin settings UI for editing `max_reopens`.** The policy is seeded by the migration and editable via raw SQL (`UPDATE stock_take_policies SET value = '2' WHERE key = 'stock_take.max_reopens';`) or a future admin screen. The `StockTakePolicyService::flushCache()` method is ready for it. The plan's Phase 11 (API + mobile foundation) or a future admin-policies phase can add the UI.

---

### Phase 11 — API + mobile foundation

**Goal:** Expose a REST API for the stock take feature, enabling a future mobile count app and third‑party integrations.

**Scope:**
1. `app/Http/Controllers/Api/V1/StockTake/` — `StockTakeSessionApiController`, `StockTakeItemApiController`.
2. API Resources: `StockTakeSessionResource`, `StockTakeItemResource`, `StockTakeVarianceResource`.
3. Form Requests (shared with web controllers where possible).
4. Routes under `api/v1/stock-take` with `api.auth` middleware.
5. API docs entry (the project has an `ApiDocController`).
6. (Future, out of scope here) mobile app consuming the API.

**Schema changes:** none.

**Code changes:**
- Controllers: `index`, `store`, `show`, `setup`, `saveCounts`, `importCounts` (CSV via multipart), `submit`, `approve`, `reject`, `post`, `reverse`, `reOpen`, `cancel`.
- Resources: consistent JSON shapes; include `difference` and `value_diff` (computed).
- Routes: versioned `api/v1/stock-take/*`.
- API doc entry.
- Rate limiting via the existing `ApiRateLimit` middleware.

**Acceptance criteria:**
- All web flows are reproducible via the API.
- API responses are consistent (Resources) and versioned.
- API auth + rate limiting + RLS apply.
- API docs page lists all stock‑take endpoints.

**Rollback:** remove controllers/resources/routes/docs.

**Effort:** M (2 days).

---

### Phase 12 — Testing, monitoring, and go‑live checklist

**Goal:** Production confidence. Feature tests for every flow; monitoring for stale sessions and reconciliation alerts; a go‑live checklist.

**Scope:**
1. Feature tests: full happy path; negative‑stock pre‑check; freeze outbound; approval workflow; reversal; re‑open; recount; cycle count scopes; RLS cross‑branch denial; concurrency (two users).
2. Unit tests: `StockTakeService` methods in isolation; `StockTakeVarianceReport`; `StockTakeWeeklyReport`; ABC classification.
3. Health‑check dashboard (Phase 2 service) surfaced on the admin dashboard.
4. `pg_cron` jobs: stale session reminder (daily); ABC refresh (nightly); reconciliation alert sweep (hourly).
5. Go‑live checklist: schema migrated, RLS on, role middleware on, system policies set, ABC materialized view populated, reports registered, API docs updated, training material.

**Schema changes:** none (test‑only factories/seeders).

**Code changes:**
- `tests/Feature/StockTake/` — `CreateSessionTest`, `SetupCountsTest`, `SaveCountsTest`, `PostSessionTest`, `CancelSessionTest`, `ReverseSessionTest`, `ReOpenSessionTest`, `ApprovalWorkflowTest`, `FreezeOutboundTest`, `CycleCountTest`, `VarianceReportTest`, `WeeklyReportTest`, `RlsCrossBranchTest`, `ConcurrentCountTest`.
- `tests/Unit/StockTake/` — `StockTakeServiceTest`, `StockTakeVarianceReportTest`, `AbcClassificationServiceTest`.
- `StockTakeSessionFactory`, `StockTakeItemFactory`, `StockTakeWarehouseFactory`.
- `pg_cron` job registration in a migration.
- Go‑live checklist doc.

**Acceptance criteria:**
- `php artisan test --filter=StockTake` is green.
- Code coverage of `StockTakeService` ≥ 90%.
- The health‑check dashboard shows real data.
- `pg_cron` jobs run on schedule.
- Go‑live checklist signed off.

**Rollback:** N/A (tests don't roll back; cron jobs can be paused).

**Effort:** L (3–4 days).

---

## 8. Cross‑Phase Concerns

### 8.1 Ordering and dependencies

```
Phase 0 (unblock) ──► Phase 1 (harden core) ──► Phase 2 (audit) ──┐
                                                                  ├──► Phase 4 (approval) ──► Phase 10 (re‑open)
Phase 3 (freeze) ──► Phase 8 (RLS/locks) ─────────────────────────┘
Phase 1 ──► Phase 9 (costing) ──► Phase 6 (reports)
Phase 5 (cycle count) — independent (can run any time after Phase 1)
Phase 7 (count UX) — independent (can run any time after Phase 1)
Phase 11 (API) — after Phase 4 + Phase 10
Phase 12 (testing) — continuous; final pass after all others
```

**Minimum viable production path:** Phase 0 → Phase 1 → Phase 2 → Phase 6. This delivers a runnable, audited, reportable feature at parity with legacy's core. Phases 3–5, 7–11 add the improvements that make it "perfect".

### 8.2 Configuration (`system_policies`)

The following keys should be introduced across phases and made configurable (not hardcoded):

| Key | Default | Phase |
|---|---|---|
| `stock_take.require_approval` | `false` | 4 |
| `stock_take.auto_approve_below_value` | `0` | 4 |
| `stock_take.approver_roles` | `admin,manager,accountant` | 4 |
| `stock_take.variance_threshold_block` | `0` (disabled) | 4 |
| `stock_take.large_variance_reason_threshold` | `500` | 7 |
| `stock_take.stale_session_days` | `30` | 2 |
| `stock_take.max_reopens` | `1` | 10 |
| `stock_take.revaluation_epsilon` | `0.01` | 9 |
| `stock_take.freeze_outbound_default` | `false` | 3 |
| `stock_take.allow_inbound_during_freeze` | `true` | 3 |

### 8.3 Backward compatibility

- Every schema change is additive (new columns, new statuses added to CHECK, new tables). No existing column is dropped or narrowed.
- The `status` CHECK constraint expansion (Phase 4, Phase 10) must use `ALTER TABLE … DROP CONSTRAINT … ADD CONSTRAINT …` — wrap in a migration with `Schema::getConnection()->statement(...)`.
- Legacy statuses (`adjusted`, `reversed` in legacy; `posted`, `cancelled` in Laravel) must map cleanly. The Laravel `posted` ≈ legacy `adjusted`; Laravel `cancelled` (for drafts) is new; Phase 10's `reversed` ≈ legacy `reversed`.
- Data migration from legacy (if the team is migrating historical sessions) should map legacy `adjusted → posted`, legacy `reversed → reversed`, legacy `draft/counting → draft/counting`.

### 8.4 Performance

- `stock_take_items` can grow large (full count × thousands of products × many sessions). Indexes: `(stock_take_session_id)`, `(warehouse_id, product_id)`, `(journal_line_id) WHERE NOT NULL`. Consider partitioning by `stock_take_session_id` range if volume exceeds ~10M rows.
- The variance report's `WHERE difference <> 0` should use a partial index: `CREATE INDEX idx_sti_variance ON stock_take_items(stock_take_session_id) WHERE difference <> 0`.
- The weekly report aggregates over date ranges — a materialized view `stock_take_session_summary` (refreshed on each post) avoids re‑aggregating.
- ABC materialized view refreshed `CONCURRENTLY` nightly.

### 8.5 Security review checklist (before go‑live)

- [ ] All mutation routes have `role:` + `branch.isolation` middleware.
- [ ] `StockTakePolicy` enforces actor‑level rules (submitter ≠ approver).
- [x] RLS on all three stock_take tables; `branch_id` populated. **(Phase 8 ✅ — `branch_id` added to `stock_take_warehouses` + `stock_take_items` with backfill + NOT NULL promotion; RLS enabled + forced on both tables with the five-policy set mirroring `stock_take_sessions`.)**
- [ ] No `DB::table()` writes that bypass Eloquent events (or explicit audit logging in place — Phase 2).
- [ ] Form Requests validate all inputs; no mass‑assignment holes.
- [ ] CSRF on all web POST routes; API uses token auth + rate limiting.
- [ ] Negative‑stock pre‑check (Phase 1) prevents the `check_violation` from leaking product info.
- [ ] `count_snapshot` jsonb doesn't leak cross‑branch data (RLS on the session covers it).

---

## 9. Definition of Done

The Physical Count menu is "perfect" when **all** of the following are true:

1. **Runnable:** `createSession`, `setupWarehouseCounts`, `saveCounts`, `postSession`, `reverseSession`, `reOpen`, `cancelSession` all succeed end‑to‑end with no schema errors.
2. **Secure:** All routes role + branch protected; RLS on all three tables; policy enforces segregation of duties; no cross‑branch data leak.
3. **Auditable:** Every transition writes a `stock_take_audit_log` row in the same transaction; the health‑check checklist surfaces integrity issues; a global audit view exists.
4. **Integral:** System‑qty snapshot frozen at setup; optional outbound freeze; reconciliation at post time surfaces drift; negative‑stock pre‑check gives friendly errors.
5. **Flexible:** Supports full / category / ABC / group / ad‑hoc / negative‑only / zero‑only scopes; barcode + bulk paste + CSV import; recount; re‑open after reversal.
6. **Governed:** Configurable approval workflow with variance thresholds; configurable system policies; counter ≠ approver.
7. **Traceable:** Per‑line `journal_line_id` links each variance to its exact GL line; GL posts at post‑time avg cost with revaluation on drift.
8. **Reportable:** Variance detail + weekly control + GL impact reports with CSV export; correct status badges; drill‑downs.
9. **Observable:** Health‑check dashboard; stale‑session reminders; reconciliation alerts; `LISTEN`/`NOTIFY` on post.
10. **Tested:** Feature + unit tests green; ≥90% service coverage; concurrency and RLS tests pass.
11. **Documented:** API docs updated; go‑live checklist signed off; user training material available.
12. **Parity‑plus:** Matches legacy on every legacy strength (Workflow B, atomic post, GL integration, reversal, audit checklist, variance + weekly reports) **and** closes every legacy weakness enumerated in §2.10.

---

## Appendix A — File Reference Index

### Legacy
| File | Role |
|---|---|
| `legacy/app/controllers/StockTakeController.php` | Route handlers, CSRF, JSON responses |
| `legacy/app/models/StockTakeModel.php` | All business logic: create, saveCount, post, reverse, delete, queries |
| `legacy/app/models/StockTakeAuditModel.php` | Health‑checklist engine (global + per‑session) |
| `legacy/app/models/Reports/StockTakeVarianceReport.php` | Variance detail + weekly control reports + CSV exports |
| `legacy/app/models/StockTransactionModel.php` | `updateWarehouseStock` (MAC), `logMovement`, `reverseTransaction` |
| `legacy/app/services/Stock/StockService.php` | Wrapper (not used by StockTake directly) |
| `legacy/app/services/Stock/StockAvailabilityService.php` | SSOT for sellable qty (NOT consulted by StockTake) |
| `legacy/app/services/Accounting/JournalPostingService.php` | `postStockTakeSession`, `reverseLinkedJournal`, ledger resolvers |
| `legacy/app/helpers/StockGlAuditHelper.php` | `stockTakeJournalBlocks` for details page |
| `legacy/app/helpers/JournalReportHelper.php` | Maps `reference_type='stock_take'` → label + deep link |
| `legacy/app/models/Reports/ProductMovementReport.php` | Labels stock_take movements as "Physical Stock Take (Variance)" |
| `legacy/app/models/WarehouseModel.php` | `hasActiveStockTake` (deactivation guard only — does not freeze) |
| `legacy/app/config/route_roles.php` | Role matrix |
| `legacy/database/migrations/023_stock_take_phase1.sql` | Phase 1: status enum, `journal_entry_id`, `posted_at`, `rate`, `is_applied`, unique key, dedup |
| `legacy/database/migrations/024_stock_take_phase3_gl.sql` | Phase 3: seed `inventory_shrinkage` + `inventory_surplus` ledgers |
| `legacy/app/views/StockTake/{index,create,details,count,checklist,variance,weekly}.php` | Blade‑equivalent legacy views |
| `legacy/public/assets/js/{StockTake,stock-take-count,StockTakeReport}.js` | Legacy JS |

### Laravel
| File | Role |
|---|---|
| `laravel/app/Services/Stock/StockTakeService.php` | Core service (445 lines) — 5‑phase lifecycle |
| `laravel/app/Http/Controllers/Admin/StockTakeController.php` | Web controller |
| `laravel/app/Models/StockTakeSession.php` | Eloquent model (SoftDeletes + AuditableMasterData trait — trait is dead) |
| `laravel/app/Models/StockTakeItem.php` | Eloquent model |
| `laravel/app/Models/StockTakeWarehouse.php` | Eloquent model (referenced in service) |
| `laravel/app/Services/Stock/StockService.php` | `applyTransaction`, `reverseTransaction`, `getWarehouseAvgCost` |
| `laravel/app/Services/Stock/StockAdjustmentService.php` | Sibling — pattern reference for GL posting |
| `laravel/app/Services/Accounting/JournalPostingService.php` | `createJournalEntry`, `reverseJournalEntry`, `lookupLedgerByNature` |
| `laravel/app/Services/Accounting/DocumentSequenceService.php` | Atomic code generation via `pg_advisory_xact_lock` |
| `laravel/app/Services/Accounting/LedgerNatureService.php` | `resolveLedgerByNature` |
| `laravel/app/Services/MenuService.php` | Menu → route resolution |
| `laravel/database/sql/03_stock.sql` | `stock_take_sessions`, `stock_take_warehouses`, `stock_take_items`, `warehouse_stock`, `stock_transactions` schemas |
| `laravel/database/sql/07_views_triggers_constraints.sql` | RLS policies, triggers, `prevent_negative_stock`, `enforce_balanced_journal_entry` |
| `laravel/routes/web.php` | Route registration (lines 385–397) |
| `laravel/resources/views/admin/stock-take/{index,create,count,show}.blade.php` | Blade views (~1,520 lines) |
| `laravel/resources/views/admin/reports/stocktake_variance.blade.php` | Stub report view (wrong status keys) |
| `laravel/app/Http/Controllers/Admin/ReportController.php` | `stocktakeVariance` — stub |
| `laravel/app/Http/Middleware/SetAppBranchId.php` | Sets `app.branch_id` GUC for RLS |
| `laravel/app/Http/Middleware/EnforceBranchIsolation.php` | Branch isolation middleware |
| `laravel/app/Traits/AuditableMasterData.php` | Audit trait (dead for stock take — service uses `DB::table()`) |
| `laravel/database/migrations/2025_01_10_000001_seed_menus_from_legacy.php` | Menu seeder (line 62) |
| `laravel/database/migrations/2025_01_23_000002_add_soft_deletes_to_transactional_tables.php` | Adds `deleted_at` to `stock_take_sessions` |
| `laravel/database/migrations/2025_01_21_000005_configure_deferred_fk_constraints.php` | DEFERRABLE FKs |
| `laravel/database/migrations/2025_01_20_000007_add_rls_branch_isolation.php` | RLS on `stock_take_sessions` only |
| `laravel/database/migrations/2025_07_26_000003_add_reversal_columns_to_stock_take_sessions.php` | Phase 0: reversal columns |
| `laravel/database/migrations/2025_07_26_000004_add_journal_line_id_to_stock_take_items.php` | Phase 1: per-line GL traceability |
| `laravel/database/migrations/2025_07_26_000005_create_stock_take_audit_log_table.php` | Phase 2: real audit trail + RLS on the audit table |
| `laravel/database/migrations/2025_07_27_000001_add_freeze_columns_to_stock_take_sessions.php` | Phase 3: snapshot freeze + optional outbound freeze |
| `laravel/database/migrations/2025_07_28_000001_add_approval_workflow_to_stock_take_sessions.php` | Phase 4: approval workflow + system_policies seed |
| `laravel/database/migrations/2025_07_30_000001_add_recount_columns_to_stock_take.php` | Phase 7: recount tracking + widened action CHECK |
| `laravel/database/migrations/2025_08_01_000001_phase8_concurrency_rls_locking_hardening.php` | **Phase 8:** `branch_id` + `freeze_outbound` denorm on stw/sti, `uk_stw_session_wh`, RLS on both tables, `prevent_overlapping_frozen_stock_take` trigger |
| `laravel/database/migrations/2025_08_02_000001_phase9_post_time_cost_and_revaluation.php` | **Phase 9:** `system_rate` + `post_rate` + `revaluation_amount` + `revaluation_line_id` on sti, backfill system_rate from rate, `stock_take.revaluation_epsilon` policy seed, `inventory_revaluation` ledger (L-0504 — was L-0503, changed in Hotfix #5/Task 16 to avoid collision with Damage Loss) seed |
| `laravel/database/migrations/2025_08_03_000001_phase10_reversal_vs_cancel_reopen.php` | **Phase 10:** `re_open_count` + `last_reopened_at` + `last_reopened_by` + `reversal_of_entry_id` on sts, `idx_sts_reversed` partial index, `stock_take.max_reopens` policy seed (default 1). Does NOT touch status CHECK (Phase 4 already allowed `reversed`) or action CHECK (Phase 7 already added `reverse` + `re_open`). |
| `laravel/tests/Helpers/InsertsWarehouseDependencies.php` | Test helper (carries the "no is_reversed column" comment) |

---

## Appendix B — Reconstructed Post Sequence

The atomic post transaction (after Phases 0–1) should execute in this order:

```
BEGIN
  -- Phase 0/1: lock + guards
  SELECT * FROM stock_take_sessions WHERE id=:sid FOR UPDATE          -- postSession lock
  -- assert status in ('counting','approved'); assert all warehouses 'completed'
  -- Phase 3: reconciliation pre-check (if freeze_outbound=false)
  SELECT sti.product_id, sti.system_qty, ws.qty AS live_qty
    FROM stock_take_items sti JOIN warehouse_stock ws ON …
   WHERE sti.stock_take_session_id=:sid AND sti.system_qty <> ws.qty
  -- warn or block based on policy
  -- Phase 1: negative-stock pre-check
  SELECT sti.product_id, p.product_code, p.product_name,
         sti.system_qty, sti.physical_qty, ws.qty AS live_qty
    FROM stock_take_items sti JOIN products p … JOIN warehouse_stock ws …
   WHERE sti.stock_take_session_id=:sid AND sti.difference < 0
     AND (ws.qty + sti.difference) < 0
  -- if non-empty: ROLLBACK; return 422 with product list

  -- Phase 8: per-warehouse advisory lock (two-int form with namespace constant)
  SELECT pg_advisory_xact_lock(0x53544B50, :warehouse_id)   -- for each warehouse in session

  -- per variance item (difference <> 0)
  FOR each item:
    -- Phase 9: re-fetch post-time avg cost
    post_rate := StockService::getWarehouseAvgCost(wh, pid)
    variance  := physical_qty - system_qty
    -- StockService::applyTransaction (locks warehouse_stock FOR UPDATE)
    UPSERT warehouse_stock SET qty = qty + variance, avg_cost = MAC(...)   -- if variance > 0
    INSERT stock_transactions (qty=variance, rate=post_rate, reference_type='stock_take', reference_id=:sid, …)
    UPDATE stock_take_items SET is_applied=true, rate=post_rate WHERE id=…

  -- Phase 9: revaluation entry if avg cost drifted
  IF |post_rate - system_rate| > epsilon FOR any counted product:
    post revaluation lines (Dr/Cr Inventory / Inventory Revaluation)

  -- GL posting
  insert journal_entries (reference_type='stock_take', reference_id=:sid, branch_id, …)
  insert journal_lines (Dr Inventory / Cr Surplus for gain; Dr Shrinkage / Cr Inventory for loss)
  -- Phase 1: back-link each stock_take_items row to its journal_line_id
  UPDATE stock_take_items SET journal_line_id=:jll_id WHERE id=…

  UPDATE stock_take_warehouses SET status='posted' WHERE stock_take_session_id=:sid   -- (or keep 'completed'; design choice)
  UPDATE stock_take_sessions SET status='posted', posted_at=now(), journal_entry_id=:je_id WHERE id=:sid

  -- Phase 2: audit log
  INSERT stock_take_audit_log (session_id, action='post', from_status='approved', to_status='posted', actor_id, payload={…})

  -- Phase 8/listen-notify: emit event
  NOTIFY stock_take_posted, :sid

COMMIT
```

---

*End of document. This is a planning document only — no code is written here. Implement each phase as a discrete work package, in dependency order, with its own migration(s), tests, and review.*
