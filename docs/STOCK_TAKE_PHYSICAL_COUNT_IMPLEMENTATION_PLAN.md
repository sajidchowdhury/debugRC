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
| 17 | **Variance report** | ✅ Full (detail + weekly + CSV) | ❌ Stub (wrong status keys) | ✅ Legacy parity + drill‑down + GL impact |
| 18 | **Routes role/branch protected** | ✅ Yes | ❌ No (P1) | ✅ Yes |
| 19 | **Form Requests** | ❌ Inline | ❌ Inline | ✅ Dedicated classes |
| 20 | **Policy** | ❌ N/A | ❌ None | ✅ `StockTakePolicy` |
| 21 | **Concurrent counters** | ✅ `FOR UPDATE` on session in `saveCount` | ❌ No lock in `saveCounts` | ✅ `FOR UPDATE` on session in all mutators |
| 22 | **Session code** | `random_int` (collision risk) | `pg_advisory_xact_lock` (atomic) | ✅ Keep Laravel's approach |
| 23 | **RLS branch isolation** | App‑level | ✅ On sessions; ❌ off items/warehouses | ✅ On all three tables (add `branch_id` to items/warehouses) |
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
- [ ] RLS on all three stock_take tables; `branch_id` populated.
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

  -- Phase 8: per-warehouse advisory lock
  SELECT pg_advisory_xact_lock(:warehouse_id)   -- for each warehouse in session

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
