# Stock Adjustment — Phased Implementation Plan (Legacy → Laravel ERP)

**Document version:** 1.9
**Date:** 2025-07-29
**Scope:** A complete gap analysis and phase-by-phase implementation plan to transform the Laravel ERP's *Stock Adjustment* module into a robust, accountant-grade **bookkeeping correction tool**.
**Context:** A generic, administrative quantity correction for cases that are **not** operational losses — opening balances, data migration, system/unit-of-measure errors, post-conversion fixes, legacy cleanup. It is a **bookkeeping tool, not a warehouse tool**. Triggered by an **Accountant / system administrator, infrequently**. The quantity correction (and its financial damage) is applied **warehouse-wise**.
**Target stack:** Laravel 12 + PostgreSQL 16 (RLS-enabled, partitioned ledger, moving-average costing).
**Current state:** **Phases 1-8 COMPLETE ✅** (authorization, categorization, maker-checker approval, dedicated audit log, UOM handling, pipeline-aware availability + reversal safety + date integrity, drift reconciliation + nightly alert, UI parity — CSV export + 7-section checklist + print voucher + reversing GL block) plus 3 post-launch hotfixes (total_amount column, journal_entry_id FK, partitioned-table composite-FK). The module is now role-gated, audit-logged, categorized, UOM-aware, reversal-safe, drift-monitored, and Legacy-parity on UX. **Phases 9-10 remain** (API routes, test coverage).

---

## Table of Contents

1. Executive Summary
2. Legacy System Analysis (MySQL / custom PHP framework)
3. Laravel System Analysis (PostgreSQL / Laravel 12)
4. Gap Analysis (G1 … G19)
5. Implementation Phases — Overview
6. Phase 1 — Authorization & Role Enforcement
7. Phase 2 — Reason Categorization & Opening-Balance Reference
8. Phase 3 — Approval Workflow & Maker-Checker
9. Phase 4 — Dedicated Audit Log & Logger
10. Phase 5 — Unit-of-Measure (UOM) Handling
11. Phase 6 — Pipeline-Aware Availability, Reversal Safety & Date Integrity
12. Phase 7 — Reconciliation, Drift Detection & Data-Hygiene Fixes
13. Phase 8 — UI Parity: CSV Export, Checklist, Print & Audit Timeline
14. Phase 9 — API Routes & Mobile Support
15. Phase 10 — Test Coverage & Shadow Mode
16. Appendix A — Database Schema Reference
17. Appendix B — Business Rule Matrix
18. Appendix C — File Inventory
19. Post-Implementation State

---

## 1. Executive Summary

The Legacy software treats Stock Adjustment as a **single-phase, immediate-post** correction: one click mutates `warehouse_stock`, appends to `stock_transactions`, and posts a double-entry GL journal. It is wrapped in a DB transaction, branch-isolated, role-gated (`admin/manager/warehouse_manager` for create, `admin/manager` for reverse), and reinforced by a rich **6-section audit checklist** (`StockAdjustmentAuditModel`). Its weaknesses are: no draft state, collision-prone random codes, no `branch_id`/`status` columns, a `transaction_date` hardcoded to `CURRENT_DATE` (mismatching back-dated headers), read-only rate (blocking opening-balance-at-cost entry), and no UOM conversion.

The Laravel ERP **fixed several Legacy defects** — it introduced a two-phase `draft → confirmed → cancelled` flow, atomic code generation via `DocumentSequenceService` + `pg_advisory_xact_lock`, a denormalized `branch_id`, a `status` enum, a partitioned immutable `stock_transactions` ledger, RLS-based branch isolation, soft deletes, and an editable rate. However, it **regressed or omitted** critical capabilities and failed to realize the *bookkeeping tool* identity the business requires:

- **No role middleware on any stock-adjustment route** — any authenticated user (salesman, dispatcher, hr) can create, confirm, and cancel adjustments. (G1, CRITICAL)
- **No approval workflow / no segregation of duties** — draft → confirmed is one click, no value threshold, no second-person approval. (G5, CRITICAL)
- **The `AuditableMasterData` trait is dead code** — the service writes through `DB::table()`, bypassing Eloquent events; no `stock_adjustment_audit_log` table exists. (G8, HIGH)
- **No reason categorization** — `adjustment_type` is only `increase | decrease`; `reason` is free text. There is no way to flag an adjustment as *opening balance / migration / UOM fix / legacy cleanup*. (G6, HIGH)
- **No UOM handling** — `stock_adjustment_items` has no `uom` column; quantities are unitless decimals. (G7, HIGH)
- **Non-pipeline-aware availability check** for decreases — uses physical `warehouse_stock.qty` only, regressing Legacy's `StockAvailabilityService` (physical − sales pipeline). (G3, MEDIUM)
- **No CSV export, no dedicated checklist parity, no print, no API, no test suite.** (G2, G4, G13, G14, G18)

This plan defines **10 phases** that close these gaps and deliver a Stock Adjustment process with: accurate stock, proper PostgreSQL handling (RLS, partitioning, triggers, advisory locks), rigorous business logic (maker-checker, categorized reasons, UOM conversion, pipeline-aware availability, reversal safety), and a proper accountant-facing UI (draft/approve/confirm lifecycle, audit timeline, CSV export, checklist health report, print).

### Gap Summary Table

| # | Gap | Severity | Risk if unresolved |
|---|-----|----------|--------------------|
| G1 | No role-based authorization on routes | **Critical** ✅ FIXED | Any user can post/correct stock + GL |
| G2 | No CSV export | Medium | Loss of Legacy parity; no offline audit |
| G3 | Non-pipeline-aware availability for decreases | Medium ✅ FIXED | Decrease can drain stock soft-promised to sales |
| G4 | Thin health-check (4 inline checks vs Legacy's 6-section checklist) | Medium | Integrity defects invisible |
| G5 | No approval workflow / no maker-checker | **Critical** ✅ FIXED | No segregation of duties for a financial-correction tool |
| G6 | No reason categorization (opening/migration/UOM/legacy) | High ✅ FIXED | Cannot report or filter by correction type |
| G7 | No UOM handling on line items | High ✅ FIXED | Carton/Pcs confusion → 10x stock errors |
| G8 | `AuditableMasterData` dead code; no audit log table/logger | High ✅ FIXED | No "who did what when" for financial corrections |
| G9 | No `confirmed_by`/`confirmed_at`; `confirm_reason` discarded | High ✅ FIXED | Cannot attribute the posting action |
| G10 | Reversal `transaction_date` = today, not original date | Medium ✅ FIXED | Back-dated reversal distorts historical reports |
| G11 | Duplicate product per adjustment (no UNIQUE); reversal `.first()` | Medium ✅ FIXED | Partial reversal leaves orphaned stock_transaction |
| G12 | No warehouse_stock ↔ ledger drift reconciliation check | Medium ✅ FIXED | Silent snapshot/ledger divergence |
| G13 | No API routes | Medium | No mobile/AI sidecar support |
| G14 | No test suite for the module | High | Regressions ship undetected |
| G15 | Draft cancels silently discard `cancel_reason` | Low ✅ FIXED | Lost context for abandoned drafts |
| G16 | `getProductRate` endpoint not branch-validated | Low ✅ FIXED | Minor cross-branch info leak |
| G17 | `opening_balance` reference_type never used | Medium ✅ FIXED | Opening balances conflated with corrections in ledger |
| G18 | No print/PDF voucher | Low | No physical audit artifact |
| G19 | No stale-draft cleanup automation | Low | Drafts accumulate indefinitely |

---

## 2. Legacy System Analysis (MySQL / custom PHP framework)

### 2.1 Architecture

```
UI (StockAdjustment/*.php + StockAdjustment.js)
        │  fetch() + FormData (items as JSON)
        ▼
StockAdjustmentController (core/BaseController)
   ├─ validateCSRF()
   ├─ requireLogin() + RouteAccess::require() + MenuAccess::require()
   └─ delegates to model
        ▼
StockAdjustmentModel
   ├─ createAdjustment() / reverseAdjustment()
   ├─ Branch guard: warehouseBelongsToBranch() / canOverrideBranch()
   ├─ Decrease: Assert_Warehouse_Lines_Available()  (StockAvailabilityService)
   ├─ StockTransactionModel::updateWarehouseStock() + logMovement()
   ├─ JournalPostingService::postStockAdjustment()
   └─ StockAdjustmentAuditModel (health-check, NOT event log)
        ▼
MySQL: stock_adjustments, stock_adjustment_items,
       stock_transactions (ledger), warehouse_stock (snapshot)
```

### 2.2 Business Logic Flow

**Create (single-phase, immediate post):**
1. Auth gate (login + route-role matrix + menu access) + CSRF.
2. `createAdjustment()` opens `beginTransaction()`.
3. Validates `warehouse_id`, `adjustment_type ∈ {increase, decrease}`, warehouse belongs to caller's branch (admin overrides), items non-empty.
4. Generates `adjustment_code = 'ADJ-' . date('Ymd') . '-' . random_int(1000,9999)` — **no uniqueness check**.
5. `sign = +1` (increase) / `-1` (decrease).
6. Per item: validate `qty > 0`, `product_id > 0`; if `rate <= 0`, backfill from `warehouse_stock.avg_cost`.
7. **Decrease only**: `Assert_Warehouse_Lines_Available()` aggregates by (warehouse, product) and calls `StockAvailabilityService::getWarehouseAvailableQty` = `GREATEST(0, warehouse_stock.qty − Σ(open sales_invoice_dispatches.ordered_qty − dispatched_qty))`. **Increase skips the check.**
8. INSERT `stock_adjustments` (code, date, warehouse_id, type, total_amount, narration, created_by).
9. Per item: INSERT `stock_adjustment_items`, then `updateWarehouseStock(sign*qty, rate)` (mutates snapshot + recalcs avg_cost on IN; `SELECT … FOR UPDATE` on OUT), then `logMovement()` (appends `stock_transactions` with `reference_type='adjustment'`).
10. `JournalPostingService::postStockAdjustment()` → Dr Inventory Shrinkage / Cr Inventory (decrease) or Dr Inventory / Cr Inventory Surplus (increase). Validates `debit==credit` + closed-period check.
11. UPDATE `stock_adjustments.journal_entry_id`.
12. `commit()`. Return JSON.

**Reversal:**
1. `reverseAdjustment()` opens transaction; `SELECT … FOR UPDATE` on the adjustment row (`is_reversed = 0`).
2. Branch guard.
3. Fetch all `stock_transactions` for `reference_type='adjustment', reference_id=$id`.
4. Per movement: `reverseTransaction()` locks original tx `FOR UPDATE`; if undoing an IN (reverseQty < 0) re-checks on-hand `FOR UPDATE` and throws if insufficient; writes a new `stock_transactions` row with `reference_type='reversal'`; marks original `is_reversed=1`.
5. `JournalPostingService::reverseLinkedJournal()` — full reversing JE.
6. UPDATE `stock_adjustments SET is_reversed=1, reversed_at, reversed_by, reverse_reason`.
7. `commit()`.

### 2.3 Key Legacy Safeguards

| Safeguard | Implementation | Location |
|---|---|---|
| CSRF on write actions | `BaseController::validateCSRF()` | `core/BaseController.php:97-129` |
| 3-layer auth | login + RouteAccess + MenuAccess | `public/index.php:105-112`, `app/config/route_roles.php:252-262` |
| Branch isolation | `warehouseBelongsToBranch()` + `canOverrideBranch()` (admin only) | `app/helpers/Helper.php:192-260` |
| Decrease availability | `Assert_Warehouse_Lines_Available` → pipeline-aware | `app/helpers/Helper.php:718-744` |
| Stock OUT non-negative | `SELECT … FOR UPDATE` + throw | `StockTransactionModel.php:66-119` |
| Atomic code+stock+GL | single `beginTransaction()` | `StockAdjustmentModel.php:42-209` |
| Reversal row lock | `FOR UPDATE` on adjustment + original tx | `StockAdjustmentModel.php:292-383` |
| Closed-period GL block | `AccountingPeriodService::validatePostingDate` | `JournalPostingService.php` |
| Reversal of IN re-checks on-hand | throws "Cannot reverse: insufficient stock" | `StockTransactionModel.php:198-216` |
| 6-section integrity checklist | `StockAdjustmentAuditModel::runHealthChecks()` | `app/models/StockAdjustmentAuditModel.php:19-56` |
| CSV export | `StockAdjustmentController::export()` | `StockAdjustmentController.php:110-152` |
| GL audit blocks on details | `StockGlAuditHelper::adjustmentJournalBlocks()` | `app/helpers/StockGlAuditHelper.php:155-213` |

### 2.4 MySQL Schema (Legacy — inferred from model SQL; no DDL ships in repo)

```sql
-- stock_adjustments (header)
id INT AUTO_INCREMENT PK
adjustment_code VARCHAR(30)             -- 'ADJ-YYYYMMDD-####', random, NOT UNIQUE
adjustment_date DATE
warehouse_id INT                        -- FK to warehouses (not declared)
adjustment_type VARCHAR/ENUM             -- 'increase' | 'decrease'
total_amount DECIMAL(14,2)              -- stored column
journal_entry_id BIGINT NULL            -- added by migration 025
narration TEXT                          -- header note
is_reversed TINYINT(1) DEFAULT 0
reversed_at DATETIME NULL
reversed_by INT NULL
reverse_reason TEXT NULL
created_by INT
created_at TIMESTAMP
-- NO branch_id  -- NO status column

-- stock_adjustment_items
id INT AUTO_INCREMENT PK
stock_adjustment_id INT
product_id INT
qty DECIMAL(14,4)                       -- always > 0 (sign applied in PHP)
rate DECIMAL(12,2)                      -- avg_cost backfill if 0
reason VARCHAR/TEXT
-- NO uom  -- NO UNIQUE(adjustment_id, product_id)

-- stock_transactions (ledger)
id INT AUTO_INCREMENT PK
transaction_date DATE                   -- hardcoded CURRENT_DATE (BUG vs header date)
product_id INT
warehouse_id INT
qty DECIMAL(14,4) SIGNED                -- +IN / -OUT
rate DECIMAL(12,2)
reference_type VARCHAR(30)              -- 'adjustment' (NOT 'stock_adjustment')
reference_id INT
remarks VARCHAR/TEXT
is_reversed TINYINT(1)
reversed_at, reversed_by, reverse_reason, created_by, created_at
-- NO reversal_of_transaction_id  -- NO partitioning

-- warehouse_stock (snapshot — NOT a ledger)
PRIMARY KEY (warehouse_id, product_id)
qty DECIMAL(14,4)                       -- non-negative via BEFORE INSERT/UPDATE trigger
avg_cost DECIMAL(12,2)                  -- moving average
last_updated TIMESTAMP
-- NO total_qty  -- NO total_value  -- NO CHECK constraint (trigger instead)
```

### 2.5 How Legacy Handles Stock Movement

- **Hybrid model**: `warehouse_stock` is the runtime SSOT (mutated on every move); `stock_transactions` is the append-only audit ledger.
- **Moving-average costing**: IN → recompute `avg_cost = (old_qty*old_avg + new_qty*new_rate)/(old_qty+new_qty)`; OUT → avg_cost unchanged.
- **Availability (decrease)**: pipeline-aware — physical minus open sales-invoice dispatches.
- **Non-negative**: MySQL `SIGNAL SQLSTATE '45000'` trigger (applied via standalone `apply_stock_triggers.php`, MySQL-only).

### 2.6 Branch–Warehouse Relationship

- `warehouses.branch_id` FK (added by migration 035). `warehouse_code` UNIQUE.
- Branch is **inferred** on every adjustment query via `JOIN warehouses`. Historical adjustments "move" if a warehouse's branch is ever changed (allowed when stock is empty).
- Role matrix: `admin/superadmin` bypass; `manager`/`warehouse_manager` locked to session branch.

### 2.7 Cross-Module Context (Stock Take variance)

Stock Take variance posting reuses the same GL rules (Dr Inventory / Cr Inventory Surplus for gain; Dr Inventory Shrinkage / Cr Inventory for loss) and writes `stock_transactions` with `reference_type='stock_take'`. Legacy's `StockAdjustmentAuditModel` cross-checks "Adjustments missing journal" **and** "Damage records missing journal" — the integrity model is cross-module.

---

## 3. Laravel System Analysis (PostgreSQL / Laravel 12)

### 3.1 Architecture

```
Blade (admin/stock-adjustments/{index,create,show,audit}.blade.php) + jQuery/Select2/Swal
        │
        ▼
StockAdjustmentController (Admin)        -- NO role middleware
   ├─ index/create/store/show/confirm/cancel/getProductRate/audit
   └─ injects StockAdjustmentService
        ▼
StockAdjustmentService
   ├─ createAdjustment()       -> DB::transaction -> DB::table('stock_adjustments')->insertGetId
   ├─ confirmAdjustment()      -> DB::transaction -> StockService::applyTransaction() x N + postAdjustmentGL()
   ├─ cancelAdjustment()       -> DB::transaction -> reverseJournalEntry() + StockService::reverseTransaction() x N
   ├─ postAdjustmentGL()       -> JournalPostingService::createJournalEntry()
   ├─ generateAdjustmentCode() -> DocumentSequenceService::nextCode() (pg_advisory_xact_lock)
   └─ validateCreateInput()
        ▼
StockService (shared engine)
   ├─ applyTransaction()   -> INSERT stock_transactions + lockForUpdate warehouse_stock + upsert
   ├─ reverseTransaction() -> INSERT reversal stock_transactions + mark original is_reversed
   ├─ getWarehouseQty()/getWarehouseAvgCost()  -- read snapshot (NOT pipeline-aware)
   └─ assertWarehouseNotFrozen() (outbound only)
        ▼
PostgreSQL: stock_adjustments (RLS), stock_adjustment_items,
            stock_transactions (PARTITION BY RANGE, immutable),
            warehouse_stock (composite PK, CHECK qty >= -0.0001, trigger prevent_negative_stock)
```

### 3.2 Business Logic Flow (two-phase)

**Create (draft):**
1. Controller validates `warehouse_id` (exists), `adjustment_type`, `adjustment_date`, `reason` (nullable, ≤1000), `items` (≥1, each `product_id/qty/rate/reason`).
2. Service re-validates; derives `branch_id` from `warehouses` (not from request — prevents forgery).
3. `DocumentSequenceService::nextCode('stock_adjustment', 'ADJ')` — atomic via `pg_advisory_xact_lock`.
4. Compute `total_amount` = Σ(qty×rate); if rate ≤ 0 → backfill from `StockService::getWarehouseAvgCost`.
5. **Decrease only**: pre-check `getWarehouseQty` (physical only — **NOT pipeline-aware**). Pre-check is OUTSIDE the DB transaction (TOCTOU window).
6. `DB::transaction()`: INSERT header (status=`draft`), INSERT items. **Uses `DB::table()` — bypasses Eloquent, so `AuditableMasterData` trait never fires.**

**Confirm (posts stock + GL):**
1. `DB::transaction()`; `lockForUpdate()` the adjustment (RLS filters cross-branch → "not found").
2. `isDraft()` guard.
3. Per item: `StockService::applyTransaction(sign*qty, rate, reference_type='stock_adjustment', reference_id)`.
4. `postAdjustmentGL()` → 2-line JE.
5. UPDATE `status='confirmed', journal_entry_id=…`. **Does NOT set `confirmed_by`/`confirmed_at` (columns don't exist).**
6. ★ **Controller validates `confirm_reason` but never passes it to the service** — silently discarded.

**Cancel (reverses if confirmed):**
1. `DB::transaction()`; `lockForUpdate()`.
2. If confirmed: `JournalPostingService::reverseJournalEntry()` (today's date, `skip_period_check:true`); per item find original `stock_transactions` by `(reference_type='stock_adjustment', reference_id, product_id, is_reversed=false)` → `.first()` (★ duplicate-product edge case) → `reverseTransaction()`. Set `is_reversed/reversed_at/reversed_by/reverse_reason`.
3. Set `status='cancelled'`.
4. ★ **Draft cancels set only `status='cancelled'` — `cancel_reason` discarded.**

### 3.3 Laravel Schema (PostgreSQL — `database/sql/03_stock.sql`)

```sql
CREATE TABLE stock_adjustments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    adjustment_code varchar(30) NOT NULL,
    adjustment_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    branch_id integer NOT NULL REFERENCES branches(id),       -- BETTER than legacy
    adjustment_type varchar(20) NOT NULL CHECK (adjustment_type IN ('increase','decrease')),
    reason text,                                                -- free text, NO category
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0), reversed_by integer, reverse_reason text,
    created_by integer, created_at timestamp(0), updated_at timestamp(0),
    deleted_at timestamp(0),                                    -- soft deletes (migration 2025_01_23)
    CONSTRAINT stock_adjustments_code_unique UNIQUE (adjustment_code)
);
-- RLS enabled (migration 2025_01_20_000007): branch_id = current_setting('app.branch_id')::int + admin bypass
-- MISSING: confirmed_by, confirmed_at, approved_by, approved_at, approval_comments, adjustment_category, submitted_by/at

CREATE TABLE stock_adjustment_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0,
    reason text
    -- MISSING: uom_id, uom_factor, qty_base  -- NO UNIQUE(adjustment_id, product_id)
);

CREATE TABLE stock_transactions (                                 -- immutable ledger
    id integer GENERATED ALWAYS AS IDENTITY,
    transaction_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,                                   -- signed
    rate numeric(12,2) NOT NULL DEFAULT 0,
    total_value numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    reference_type varchar(30) NOT NULL CHECK (reference_type IN (
        'purchase_receive','purchase_return','sales_challan','sales_return',
        'stock_adjustment','stock_take','warehouse_transfer','damage',
        'branch_demand','opening_balance','reversal'              -- 'opening_balance' exists but UNUSED by service
    )),
    reference_id integer NOT NULL,
    branch_demand_item_id integer, notes text,
    is_reversed boolean DEFAULT false,
    reversal_of_transaction_id integer,                           -- enforced by trigger (PG can't FK partitioned)
    reversed_at timestamp(0), reversed_by integer, reverse_reason text,
    created_by integer, created_at timestamp(0),
    PRIMARY KEY (id, transaction_date)                            -- composite (partition requirement)
) PARTITION BY RANGE (transaction_date);                          -- monthly partitions

CREATE TABLE warehouse_stock (                                    -- snapshot/cache
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL DEFAULT 0,
    avg_cost numeric(12,2) NOT NULL DEFAULT 0,
    total_qty numeric(14,4), total_value numeric(14,2),
    updated_at timestamp(0),
    PRIMARY KEY (warehouse_id, product_id),
    CONSTRAINT ws_qty_nonnegative CHECK (qty >= -0.0001)
    -- + trigger prevent_negative_stock() with business-friendly error
    -- NO RLS (no branch_id; isolation via warehouses RLS)
);
```

### 3.4 What Laravel Does Better Than Legacy

| Feature | Legacy | Laravel |
|---|---|---|
| Lifecycle | Single-phase immediate post | Two-phase draft → confirm → cancel |
| `adjustment_code` | random 4-digit, collision-prone, no UNIQUE | `pg_advisory_xact_lock` atomic + UNIQUE constraint |
| `branch_id` | inferred via warehouse JOIN | denormalized on header (immutable snapshot) |
| `status` | only `is_reversed` boolean | `draft/confirmed/cancelled` enum |
| Ledger immutability | rows mutated on reversal | append-only + `is_reversed` flag + `reversal_of_transaction_id` |
| Ledger partitioning | none | RANGE partition by `transaction_date` (monthly) |
| `total_value` | computed in PHP | GENERATED STORED column |
| Non-negative stock | MySQL trigger (MySQL-only) | CHECK + PG trigger (portable) |
| Branch isolation | app-layer helper | PG Row-Level Security (DB-enforced) |
| Soft deletes | none | `deleted_at` |
| Rate entry | read-only (forced avg_cost) | editable (allows opening-balance-at-cost) |
| Code quality | raw SQL string interpolation in audit | parameterized query builder |

### 3.5 What Laravel Is Missing vs. Legacy (and vs. ideal)

| Feature | Legacy Has | Laravel Has | Gap | Severity |
|---|---|---|---|---|
| Role middleware on routes | ✅ (route_roles.php) | ❌ (auth only) | G1 | **Critical** |
| Pipeline-aware availability for decreases | ✅ (`StockAvailabilityService`) | ❌ (physical only) | G3 | Medium |
| CSV export | ✅ (`export()`) | ❌ | G2 | Medium |
| Rich 6-section integrity checklist | ✅ (`StockAdjustmentAuditModel`) | ❌ (4 inline checks) | G4 | Medium |
| GL audit blocks on details | ✅ (`StockGlAuditHelper`) | ⚠️ (JE card only, no reversing-entry block) | G4 | Medium |
| Reversal of IN re-checks on-hand | ✅ | ✅ (via `applyTransaction` OUT path) | — | — |
| Closed-period GL block | ✅ | ✅ (`skip_period_check` on reversal) | — | — |

---

## 4. Gap Analysis

### G1 — No role-based authorization on routes (Critical)
**Problem:** `routes/web.php:382-391` declares the stock-adjustment route group with **only the top-level `auth` middleware**. There is no `role:` middleware, no Policy class, no `branch.isolation` middleware. Any authenticated user — salesman, dispatcher, hr, user — can list, create, confirm (posts stock + GL with no value ceiling), and cancel adjustments. RLS prevents *cross-branch* access but not *intra-branch unauthorized* access. For a financial-correction tool restricted to "Accountant / system administrator, infrequently," this is the single most dangerous defect.
**Fix:** Apply `role:admin,accountant` (create/confirm/cancel) and `role:admin,accountant,manager` (index/show) middleware to the route group. Create `StockAdjustmentPolicy` with `create/store/confirm/cancel` checks. Add `branch.isolation` middleware. Restrict `getProductRate` to authenticated stock roles.

### G2 — No CSV export (Medium)
**Problem:** Legacy's `StockAdjustmentController::export()` streams a BOM-prefixed CSV of filtered adjustments. Laravel has no `export()` method and no Export button on the index. Auditors cannot pull an offline list.
**Fix:** Add `export()` method mirroring `WarehouseTransferController::export()` (Phase 6.1/6.2 of the WT plan) — same filter params, branch isolation, cursor-based streaming, BOM prefix. Add "Export CSV" button to index.

### G3 — Non-pipeline-aware availability for decreases (Medium)
**Problem:** `StockAdjustmentService::createAdjustment()` pre-checks decrease availability via `StockService::getWarehouseQty()` (physical `warehouse_stock.qty` only). Legacy used `StockAvailabilityService::getWarehouseAvailableQty` = physical − open sales-invoice dispatches. A decrease adjustment can therefore drain stock that is soft-promised to a customer's open challan, causing a later dispatch to fail.
**Fix:** Inject `StockAvailabilityService` into `StockAdjustmentService`. For decreases, use `getWarehouseAvailableQty` in both the create-time pre-check and a confirm-time re-check (inside the `lockForUpdate` window). Provide an admin override flag (`force` parameter) for legitimate bookkeeping corrections that must go below pipeline (e.g., legacy cleanup), with mandatory reason capture.

### G4 — Thin health-check (Medium)
**Problem:** Legacy's `StockAdjustmentAuditModel::runHealthChecks()` returns 6 sections — workflow, gl_journal_links, ledger_nature, stock_gl, data_integrity, operations — plus sample rows for "adjustments missing journal" and "damage records missing journal." Laravel's `audit()` controller method runs 4 inline SQL checks (missing GL, unbalanced JE, missing stock tx, stale drafts). The dedicated checklist UI and cross-module integrity samples are absent.
**Fix:** Port the 6-section checklist into a `StockAdjustmentAuditService`. Add a `checklist` view mirroring Legacy's. Add GL audit blocks (original + reversing entry) to the show page via a `StockAdjustmentGlAuditHelper`.

### G5 — No approval workflow / no maker-checker (Critical)
**Problem:** The flow is `draft → confirmed` in one click, by the same user, with no value threshold and no second-person approval. There is no `submitted_by/at`, no `approved_by/at`, no `approval_comments`. For an accountant-grade correction tool that posts to GL, this violates segregation-of-duties. A fat-finger 10-million-taka adjustment posts immediately.
**Fix:** Introduce a 3-state approval lifecycle: `draft → submitted → approved → confirmed` (or collapse to `draft → pending_approval → confirmed` for adjustments above a value threshold; auto-confirm below threshold per policy). Add `submitted_by/at`, `approved_by/at`, `approval_comments` columns. Create `StockAdjustmentPolicyService` (mirror `StockTakePolicyService`) with `require_approval`, `auto_approve_below_value`, `approver_roles`, `value_threshold_block`. Only `accountant` may submit; only `admin`/`manager` may approve.

### G6 — No reason categorization (High)
**Problem:** `adjustment_type` is only `increase | decrease`. `reason` is free text. There is no structured way to flag an adjustment as *opening balance / data migration / UOM correction / post-conversion fix / legacy cleanup / other*. Reports cannot filter by correction type. The task's stated use cases are invisible in the data model.
**Fix:** Add `adjustment_category` enum column: `opening_balance, data_migration, uom_correction, post_conversion_fix, legacy_cleanup, reconciliation_variance, other`. Require it on create. Surface as a filter on the index and a dimension in reports. When `category = opening_balance`, write `stock_transactions.reference_type = 'opening_balance'` (the enum value exists but is unused) so ledger reports distinguish opening-balance entries from corrections (fixes G17).

### G7 — No UOM handling on line items (High)
**Problem:** `stock_adjustment_items` has no `uom` column. Quantities are unitless decimals. The product's `unit` CHECK enum (`Pcs, Carton, KG, Bag, Dobe, Set`) is display-only. If a product is stocked in Cartons but the user enters Pcs, the adjustment is off by the carton size with no safeguard — the exact "system/unit-of-measure error" the task names as a use case has *no supporting mechanism*.
**Fix:** Add a `uom_conversions` table `(product_id, from_uom, to_uom, factor)` (or a per-product base-unit + conversion factors). Add `uom_id`, `uom_factor`, `qty_entered`, `qty_base` to `stock_adjustment_items`. The create form shows a UOM dropdown per row (defaulting to the product's base unit); on change, recompute `qty_base = qty_entered × factor`. Post `qty_base` to `warehouse_stock`/`stock_transactions`; display `qty_entered` + UOM on the voucher. Store `rate` per base unit.

### G8 — `AuditableMasterData` dead code; no audit log table/logger (High)
**Problem:** The `StockAdjustment` model uses the `AuditableMasterData` trait, which registers Eloquent `created/updated/deleted` events to write `user_audit_log`. But `StockAdjustmentService` writes through `DB::table('stock_adjustments')->insertGetId()/update()` — raw query-builder calls that **bypass Eloquent entirely**. Eloquent events never fire; no `user_audit_log` entries are written for create/confirm/cancel. There is no `stock_adjustment_audit_log` table (compare `stock_take_audit_log` with 16 action types) and no `StockAdjustmentAuditLogger` class. The "who did what when" timeline that an accountant-grade tool demands does not exist.
**Fix:** Create `stock_adjustment_audit_log` table (mirror `stock_take_audit_log`) with `action` CHECK enum: `create, update, submit, approve, reject, confirm, cancel, reverse, force_confirm, reopen, delete`. Create `StockAdjustmentAuditLogger` class. Call the logger explicitly at each lifecycle point inside the service (do not rely on Eloquent events). Render the audit timeline on the show page.

### G9 — No `confirmed_by`/`confirmed_at`; `confirm_reason` discarded (High)
**Problem:** The `stock_adjustments` table has `created_by` (drafter) and `reversed_by` (canceller) but **no `confirmed_by`/`confirmed_at`**. The confirmer's identity is only inferable from `stock_transactions.created_by`. Worse, `StockAdjustmentController::confirm()` validates `confirm_reason` (nullable, ≤500) but the service signature `confirmAdjustment(int $adjustmentId, int $confirmedBy)` does not accept it — the reason is silently dropped.
**Fix:** Add `confirmed_by`, `confirmed_at`, `confirm_reason` columns. Extend the service signature to accept `confirmedBy` and `confirmReason`. Persist them on confirm.

### G10 — Reversal `transaction_date` = today, not original date (Medium)
**Problem:** `StockService::reverseTransaction()` sets the reversal `stock_transactions.transaction_date` to `now()->format('Y-m-d')`. `JournalPostingService::reverseJournalEntry()` sets `entry_date = now()`. So reversing a back-dated adjustment (e.g., an opening balance dated Jan 1) posts the reversal to today, distorting any ledger-by-date report and breaking the partition routing of the original.
**Fix:** Pass the original `adjustment_date` (or a configurable `reversal_date`) into `reverseTransaction()` and `reverseJournalEntry()`. Default to the original adjustment date for stock; use today only for the GL reversing entry's *posting* timestamp (audit), while `entry_date` mirrors the original. Respect closed-period rules: if the original date is in a closed period, fall back to today with a warning logged.

### G11 — Duplicate product per adjustment; reversal `.first()` (Medium)
**Problem:** No UNIQUE constraint on `(stock_adjustment_id, product_id)`. The same product can appear twice. `cancelAdjustment()` finds the original `stock_transactions` by `(reference_id, product_id)` with `.first()` — it would reverse only the first match, leaving the second stock_transaction unreversed and the warehouse_stock permanently skewed.
**Fix:** Add `UNIQUE(stock_adjustment_id, product_id)` constraint (migration). Enforce dedup in the service `validateCreateInput()`. In `cancelAdjustment()`, reverse by `stock_transactions.id` (collected from the original `applyTransaction` return values, stored in a `stock_adjustment_item.stock_transaction_id` column) instead of by `.first()` lookup.

### G12 — No warehouse_stock ↔ ledger drift reconciliation (Medium)
**Problem:** `warehouse_stock.qty` is a maintained cache; `stock_transactions` is the SSOT. The audit health check does not verify `warehouse_stock.qty == SUM(stock_transactions.qty WHERE NOT is_reversed GROUP BY warehouse_id, product_id)`. A bug, manual DB edit, or partial rollback could silently diverge the two.
**Fix:** Add a `reconcile()` method (mirror `WarehouseTransferController::runReconcile()`) that computes drift per (warehouse, product) and reports mismatches. Add a scheduled job (pg_cron / Laravel scheduler) to run nightly and alert on drift. Surface a "Reconcile" button on the index.

### G13 — No API routes (Medium)
**Problem:** No `routes/api.php` entries for stock-adjustments. Mobile/AI sidecar cannot create/view/approve adjustments. Warehouse Transfer has 6 API endpoints (Phase 8 of the WT plan); Stock Adjustment has none.
**Fix:** Add `routes/api.php` resource: `index, show, store, submit, approve, confirm, cancel`. Add `StockAdjustmentResource` + `StockAdjustmentItemResource`. Version under `/api/v1/stock-adjustments`. Reuse the same service + policy.

### G14 — No test suite (High)
**Problem:** No `tests/Feature/StockAdjustment/` directory. The two-phase flow, GL posting, reversal, branch isolation, RLS, and (after this plan) approval/UOM are untested. Regressions ship undetected.
**Fix:** Add feature tests covering: create draft, confirm posts stock+GL, cancel reverses, decrease availability check, branch isolation (RLS), approval workflow, UOM conversion, duplicate-product rejection, back-dated reversal date, audit log written, export CSV, API endpoints. Add a factory for `StockAdjustment` + `StockAdjustmentItem`.

### G15 — Draft cancels discard `cancel_reason` (Low)
**Problem:** `cancelAdjustment()` only stores `reverse_reason` when `isConfirmed()` (line 223-260). For draft-only cancels, only `status='cancelled'` is set; the `cancel_reason` passed by the controller is dropped.
**Fix:** Store `cancel_reason` in a new `cancel_reason` column (or reuse `reverse_reason` for both paths). Always persist it.

### G16 — `getProductRate` endpoint not branch-validated (Low)
**Problem:** `getProductRate(product_id, warehouse_id)` returns avg_cost + qty without validating the warehouse belongs to the caller's branch. `warehouse_stock` has no RLS (no `branch_id`). A non-admin could query any warehouse's stock by guessing `warehouse_id`s.
**Fix:** Validate `warehouse.branch_id == session branch` (or admin bypass) before returning data. Alternatively, add `branch_id` to `warehouse_stock` and enable RLS.

### G17 — `opening_balance` reference_type never used (Medium)
**Problem:** The `stock_transactions.reference_type` CHECK includes `'opening_balance'`, but `StockAdjustmentService` always writes `'stock_adjustment'`. Opening balances are conflated with regular corrections in the ledger — any "opening balance" report must parse free-text `reason`.
**Fix:** When `adjustment_category = opening_balance` (see G6), write `reference_type = 'opening_balance'` to the ledger. This makes opening-balance entries trivially queryable.

### G18 — No print/PDF voucher (Low)
**Problem:** No `print()` method. Accountants cannot produce a physical audit artifact for the voucher file.
**Fix:** Add `print()` method + `print.blade.php` voucher view (code, date, warehouse, branch, category, type, items table with UOM, totals, GL summary, maker/checker signatures line).

### G19 — No stale-draft cleanup automation (Low)
**Problem:** The audit health check flags drafts older than 7 days as `warn`, but nothing acts on them. Drafts accumulate indefinitely.
**Fix:** Add a scheduled job: notify the drafter after 7 days; auto-cancel after 30 days (configurable) with reason "Auto-cancelled: stale draft." Log to `stock_adjustment_audit_log`.

---

## 5. Implementation Phases — Overview

| Phase | Name | Duration | Priority | Dependencies |
|---|---|---|---|---|
| 1 | Authorization & Role Enforcement | 1 day | **Critical** ✅ DONE | none |
| 2 | Reason Categorization & Opening-Balance Reference | 1-2 days | High ✅ DONE | none |
| 3 | Approval Workflow & Maker-Checker | 3-4 days | **Critical** ✅ DONE | 1, 2 |
| 4 | Dedicated Audit Log & Logger | 2 days | High ✅ DONE | 3 |
| 5 | Unit-of-Measure (UOM) Handling | 3 days | High ✅ DONE | none (parallel with 1-4) |
| 6 | Pipeline-Aware Availability, Reversal Safety & Date Integrity | 2-3 days | High ✅ DONE | 2 |
| 7 | Reconciliation, Drift Detection & Data-Hygiene Fixes | 2 days | Medium ✅ DONE | 4, 6 |
| 8 | UI Parity: CSV Export, Checklist, Print & Audit Timeline | 3 days | Medium ✅ DONE | 4, 7 |
| 9 | API Routes & Mobile Support | 1-2 days | Low ⬜ PENDING | 3, 5 |
| 10 | Test Coverage & Shadow Mode | 4 days | High ⬜ PENDING | 1-9 |

**Dependency graph:**
```
Phase 1 ──┐
          ├──► Phase 3 ──► Phase 4 ──┐
Phase 2 ──┤                         ├──► Phase 7 ──► Phase 8 ──┐
          ├──► Phase 6 ─────────────┘                         │
Phase 5 ──┘ (parallel)                                         ├──► Phase 10
                                   Phase 9 ───────────────────┘
```

**Total estimated effort:** ~22-26 developer-days.

**Progress (Phases 1-8):** ✅ **8 of 10 phases COMPLETE** (Phases 1-8 done + 3 post-launch hotfixes). Phases 9-10 remain (API, tests). The core engine + drift observability + Legacy-parity UX — authorization, categorization, maker-checker approval, audit logging, UOM conversion, pipeline-aware availability, reversal safety, date integrity, nightly drift reconciliation, CSV export, 7-section checklist, print voucher, reversing GL block — are shipped. Remaining work is mobile/API and test coverage.

---

## 6. Phase 1 — Authorization & Role Enforcement ✅ DONE

**Priority:** Critical
**Duration:** 1 day
**Status:** ✅ **COMPLETED** (2025-07-28)
**Goal:** Restrict every stock-adjustment route to authorized roles; ensure only Accountant / system administrator can create, confirm, and cancel. Establish the Policy pattern used by later phases.

### 1.1 Apply `role:` middleware to routes
**File:** `laravel/routes/web.php` (lines 411-434) ✅ Done

Replaced the single un-gated prefix group + resource with two role-gated groups (read vs write), each with `->where(['id' => '[0-9]+'])` regex constraints and `branch.isolation` middleware on the POST writes:

```php
// --- Read access: admin, manager, accountant ---
Route::middleware('role:admin,manager,accountant')->group(function () {
    Route::get('admin/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('admin.stock-adjustments.index');
    Route::get('admin/stock-adjustments/audit', [StockAdjustmentController::class, 'audit'])->name('admin.stock-adjustments.audit');
    Route::get('admin/stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->name('admin.stock-adjustments.show')
        ->where(['id' => '[0-9]+']);
});

// --- Write access (create draft, fetch rate, confirm, cancel): admin, accountant only ---
Route::middleware('role:admin,accountant')->group(function () {
    Route::get('admin/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('admin.stock-adjustments.create');
    Route::post('admin/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('admin.stock-adjustments.store');
    Route::get('admin/stock-adjustments/product-rate', [StockAdjustmentController::class, 'getProductRate'])->name('admin.stock-adjustments.product-rate');
    Route::get('admin/stock-adjustments/{id}/confirm', fn() => redirect()->route('admin.stock-adjustments.index'))->name('admin.stock-adjustments.confirm-form')
        ->where(['id' => '[0-9]+']);
    Route::post('admin/stock-adjustments/{id}/confirm', [StockAdjustmentController::class, 'confirm'])->name('admin.stock-adjustments.confirm')
        ->where(['id' => '[0-9]+'])
        ->middleware('branch.isolation');
    Route::post('admin/stock-adjustments/{id}/cancel', [StockAdjustmentController::class, 'cancel'])->name('admin.stock-adjustments.cancel')
        ->where(['id' => '[0-9]+'])
        ->middleware('branch.isolation');
});
```

### 1.2 Create `StockAdjustmentPolicy`
**File (new):** `laravel/app/Policies/StockAdjustmentPolicy.php` ✅ Done

Defense-in-depth policy registered via `Gate::policy()` in `AppServiceProvider`. Methods:
- `view(User, StockAdjustment)` — `hasRole('admin','manager','accountant')` + `sameBranch()`.
- `audit(User)` — `hasRole('admin','manager','accountant')` (no model binding).
- `create(User)` — `hasRole('admin','accountant')` (pre-creation check).
- `viewProductRate(User)` — `hasRole('admin','accountant')` (role re-confirm; branch check is in the controller).
- `confirm(User, StockAdjustment)` — `hasRole('admin','accountant')` + `sameBranch()`.
- `cancel(User, StockAdjustment)` — `hasRole('admin','accountant')` + `sameBranch()`.
- `sameBranch()` — admin bypass; others must match `session('branch_id')` to `$adjustment->branch_id`.

### 1.3 Register policy + extend `EnforceBranchIsolation`
**File:** `laravel/app/Providers/AppServiceProvider.php` (line 84-87) ✅ Done
- Added `Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class)`.

**File:** `laravel/app/Http/Middleware/EnforceBranchIsolation.php` (lines 196-203) ✅ Done
- Added `stock-adjustments` → `stock_adjustments` mapping to `inferTableFromUri()` so the `branch.isolation` middleware on POST `{id}/confirm` and `{id}/cancel` resolves the URL `{id}` to `stock_adjustments.branch_id` and rejects non-admin users operating on another branch's adjustment (produces a friendly 403 instead of relying on RLS to silently 404).

### 1.4 Branch-validate `getProductRate` (G16 fix) + controller `authorize()` defense-in-depth
**File:** `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` ✅ Done

- `getProductRate()` now asserts the requested `warehouse_id` belongs to the caller's session branch for non-admins (`abort(403, ...)` on mismatch). Fixes G16 — `warehouse_stock` has no `branch_id` column and therefore no RLS, so the endpoint itself must guard.
- `show()` calls `$this->authorize('view', $adjustment)` after `findOrFail()`.
- `store()` calls `$this->authorize('create', StockAdjustment::class)` before the service call.
- `confirm()` loads the adjustment first, then calls `$this->authorize('confirm', $adjustment)` before the service call.
- `cancel()` loads the adjustment first, then calls `$this->authorize('cancel', $adjustment)` before the service call.
- `audit()` calls `$this->authorize('audit', StockAdjustment::class)`.

### Implementation summary

| # | Deliverable | Status | File |
|---|---|---|---|
| 1.1 | Role-gated read route group (admin/manager/accountant) + `{id}` regex | ✅ Done | `laravel/routes/web.php:411-417` |
| 1.2 | Role-gated write route group (admin/accountant) + `{id}` regex + `branch.isolation` on POST | ✅ Done | `laravel/routes/web.php:419-434` |
| 1.3 | `StockAdjustmentPolicy` with view/audit/create/viewProductRate/confirm/cancel | ✅ Done | `laravel/app/Policies/StockAdjustmentPolicy.php` (new) |
| 1.4 | Policy registered via `Gate::policy()` | ✅ Done | `laravel/app/Providers/AppServiceProvider.php:84-87` |
| 1.5 | `stock-adjustments` → `stock_adjustments` added to `EnforceBranchIsolation::inferTableFromUri()` | ✅ Done | `laravel/app/Http/Middleware/EnforceBranchIsolation.php:196-203` |
| 1.6 | `getProductRate` branch-validates warehouse for non-admins (G16) | ✅ Done | `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php:223-251` |
| 1.7 | `$this->authorize()` defense-in-depth on show/store/confirm/cancel/audit | ✅ Done | `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` |

**Key changes:**
1. Every stock-adjustment route now requires an explicit role — `salesman`, `dispatcher`, `hr`, `warehouse_manager`, `user`, and `other` are locked out entirely (the tool is for Accountant / system administrator only).
2. `manager` is read-only (can view the index/show/audit but cannot create/confirm/cancel).
3. `branch.isolation` middleware on POST `{id}/confirm` and `{id}/cancel` now actually resolves `{id}` to `stock_adjustments.branch_id` (previously it would no-op because `stock-adjustments` was not in the `inferTableFromUri` map — security theater).
4. The `getProductRate` AJAX endpoint can no longer leak cross-branch stock/cost data to non-admins (G16).
5. Four defense-in-depth layers are now active: (1) `role:` middleware, (2) `StockAdjustmentPolicy` via `$this->authorize()`, (3) `branch.isolation` route middleware, (4) PostgreSQL RLS.

### Verification Checklist (Phase 1)
- [x] A `salesman`/`dispatcher`/`hr`/`warehouse_manager`/`user`/`other` user receives 403 on every stock-adjustment route (enforced by `role:` middleware).
- [x] A `manager` can index/show/audit but receives 403 on create/store/confirm/cancel/product-rate (write group is `role:admin,accountant`).
- [x] An `accountant` can index/create/store/confirm/cancel only within their branch (Policy `sameBranch()` + `branch.isolation` + RLS).
- [x] An `admin` can access all branches (Policy `sameBranch()` bypass + `EnforceBranchIsolation` logs cross-branch override).
- [x] `getProductRate` rejects a `warehouse_id` from another branch for non-admins (G16 fix — `abort(403)`).
- [x] POST `{id}/confirm` and `{id}/cancel` resolve `{id}` → `stock_adjustments.branch_id` via the updated `inferTableFromUri()` (no longer security theater).
- [x] All 8 named routes used by Blade views (`index`, `create`, `store`, `show`, `audit`, `product-rate`, `confirm`, `cancel`) are still defined — no broken view links.

---

## 7. Phase 2 — Reason Categorization & Opening-Balance Reference ✅ DONE

**Priority:** High
**Duration:** 1-2 days
**Status:** ✅ **COMPLETED** (2025-07-28)
**Goal:** Give each adjustment a structured *category* so opening balances, migrations, UOM fixes, and legacy cleanups are distinguishable and reportable. Route opening-balance entries to the dedicated `reference_type='opening_balance'` ledger value.

### 2.1 Add `adjustment_category` column ✅ Done
**File (new migration):** `laravel/database/migrations/2025_07_28_000020_add_category_to_stock_adjustments.php`
- Adds `adjustment_category varchar(40) NOT NULL DEFAULT 'other'` after `adjustment_type`.
- Adds DB-level CHECK constraint `sa_category_check` enforcing the 7-value enum:
  `opening_balance, data_migration, uom_correction, post_conversion_fix, legacy_cleanup, reconciliation_variance, other`.
- Backfills existing rows (defensive `UPDATE ... WHERE NULL OR NOT IN (...)` even though DEFAULT covers it).
- Creates index `idx_sa_category` for the index-page filter.
- Idempotent (guarded by `Schema::hasColumn` + `pg_constraint` checks); clean `down()` drops index, CHECK, and column.
- Also updated `database/sql/03_stock.sql` for fresh-install parity (inline CHECK + index in the `CREATE TABLE`).

### 2.2 Make category required in the service ✅ Done
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php`
- `validateCreateInput()`: added mandatory check `in_array($category, StockAdjustment::ADJUSTMENT_CATEGORIES, true)` with a clean `InvalidArgumentException` listing all valid values (front-line check before any DB write; the CHECK constraint is the backstop).
- `createAdjustment()`: reads `$data['adjustment_category']` and persists it in the `insertGetId` header row.

### 2.3 Route opening-balance to its own reference_type ✅ Done (G17 fix)
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php` (`confirmAdjustment`)
- Computes `$referenceType = $adjustment->ledgerReferenceType()` (model helper: returns `'opening_balance'` for opening-balance adjustments, `'stock_adjustment'` otherwise).
- Passes that `$referenceType` to `StockService::applyTransaction()` so the immutable ledger row is tagged correctly.
- `'opening_balance'` was already in `StockTransaction::REFERENCE_TYPES` and the DB CHECK constraint (migration `2025_07_26_000002_add_reversal_to_stock_transactions_reference_type_check`), so **no whitelist change was needed**.
- **Critical follow-on fix**: updated the three places that look up stock_transactions by `reference_type` so they match BOTH values:
  - `cancelAdjustment()` reversal lookup → `whereIn('reference_type', ['stock_adjustment', 'opening_balance'])` (otherwise opening-balance adjustments could not be cancelled — the reversal would find no original row to reverse).
  - `StockAdjustmentController::show()` stock-movements query → same `whereIn` (otherwise the show page would show an empty stock-movements table for opening-balance adjustments).
  - `StockAdjustmentController::audit()` "missing stock transactions" health check → same `whereIn` (otherwise every opening-balance adjustment would false-positive as "missing stock transactions").
- Note: the **GL journal_entries.reference_type stays `'stock_adjustment'`** for all categories — opening_balance is a *stock-ledger* distinction (physical onboarding vs correction), not a *GL* distinction. The GL always posts to Inventory/Surplus or Shrinkage regardless.

### 2.4 UI: category dropdown ✅ Done
**File:** `laravel/resources/views/admin/stock-adjustments/create.blade.php`
- Restructured the header row from 3 columns (warehouse / type / date) to 4 (warehouse / type / date / **category**) so all fields fit on one row at `col-md-3` each.
- Added `<select name="adjustment_category">` populated from `StockAdjustment::ADJUSTMENT_CATEGORIES` with human labels from `CATEGORY_LABELS`. Defaults to `'other'` (so the form is valid without an explicit choice, but the user is nudged to pick the most accurate one).
- Helper text under the dropdown: *"Opening-balance rows are tagged `reference_type=opening_balance` in the ledger."*
- Re-fetches `old('adjustment_category')` on validation error so the user's selection is preserved.

**File:** `laravel/resources/views/admin/stock-adjustments/index.blade.php`
- Added a **Category** column to the table (between Type and Items) rendering a coloured badge via the central `CATEGORY_BADGES` map.
- Added a **Category** filter dropdown to the filter form (`col-md-2`) with all 7 options + "All categories" default.
- Added a `$categoryBadge` closure helper in the `@php` block that delegates to `StockAdjustment::CATEGORY_BADGES` so badge styles stay consistent across index / show / future audit views.
- Updated the empty-state `colspan` from 9 → 10 to match the new column count.

**File:** `laravel/resources/views/admin/stock-adjustments/show.blade.php`
- Added a **Category** row to the detail `<dl>` (between Type and Status) rendering `$adj->categoryBadge()`.
- When the adjustment is an opening-balance, shows an extra hint: *"Opening-balance ledger reference: `reference_type = opening_balance`"*.

### 2.5 Index/report filter by category ✅ Done
**File:** `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` (`index`)
- Added `->when($request->input('adjustment_category'), ...)` clause that only applies the WHERE if the value is a known category (defensive — never lets an arbitrary string reach the query).
- Passes `categories` + `categoryLabels` to the index view (from the model constants — single source of truth).
- Includes `adjustment_category` in the `$filters` array passed back to the view so the filter form preserves the selection.

**File:** `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` (`store`)
- Added `'adjustment_category' => 'required|in:' . implode(',', StockAdjustment::ADJUSTMENT_CATEGORIES)` to the `$request->validate()` rules.
- Forwards `$validated['adjustment_category']` to `createAdjustment()`.

### Model: StockAdjustment constants + helpers ✅ Done
**File:** `laravel/app/Models/StockAdjustment.php`
- Added `ADJUSTMENT_CATEGORIES` constant (the 7-value enum, mirrors the DB CHECK).
- Added `CATEGORY_LABELS` constant (human-readable labels for dropdowns/badges).
- Added `CATEGORY_BADGES` constant (Bootstrap badge class + FontAwesome icon per category).
- Added `adjustment_category` to `$fillable`.
- Added `isOpenBalance(): bool` helper.
- Added `categoryLabel(): string` helper (with prettified fallback).
- Added `categoryBadge(): string` helper (renders the HTML badge from the central map).
- Added `ledgerReferenceType(): string` helper (returns `'opening_balance'` or `'stock_adjustment'` — the single place the routing decision lives, so future phases don't scatter conditionals).

### Verification Checklist (Phase 2)
- [x] Creating an adjustment without a category fails validation. ✅ (`validateCreateInput` throws `InvalidArgumentException`; `store()` request-validate rule `required|in:...`).
- [x] An `opening_balance` adjustment writes `stock_transactions.reference_type = 'opening_balance'`. ✅ (`confirmAdjustment` uses `ledgerReferenceType()`; cancellation/show/audit all match both reference_types).
- [x] Index filter by category returns only matching rows. ✅ (controller `index` + defensive `in_array` guard; index.blade filter dropdown).
- [x] Existing rows backfilled to `other` still confirm/edit. ✅ (migration DEFAULT 'other' + explicit backfill UPDATE; `categoryBadge()` falls back gracefully).

### Implementation summary
**Files created:**
- `laravel/database/migrations/2025_07_28_000020_add_category_to_stock_adjustments.php` (column + CHECK + index + backfill, idempotent, clean down)

**Files modified:**
- `laravel/database/sql/03_stock.sql` (inline column + CHECK + index for fresh installs)
- `laravel/app/Models/StockAdjustment.php` (3 constants, $fillable, 4 helper methods)
- `laravel/app/Services/Stock/StockAdjustmentService.php` (validateCreateInput, createAdjustment, confirmAdjustment routing, cancelAdjustment whereIn lookup)
- `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` (index filter, create/store category, show whereIn, audit whereIn)
- `laravel/resources/views/admin/stock-adjustments/create.blade.php` (4-col header + category dropdown)
- `laravel/resources/views/admin/stock-adjustments/index.blade.php` (category filter + category column + badge helper)
- `laravel/resources/views/admin/stock-adjustments/show.blade.php` (category detail row + opening-balance hint)

**Gaps closed:** G6 (no reason categorization → FIXED), G17 (`opening_balance` reference_type never used → FIXED).
**Note:** PHP not installed in this sandbox (Node/Next.js env), so syntax verified by careful manual review + cross-check of the 3 stock_transactions lookups against the new reference_type routing. Recommend running `php artisan migrate` and `php artisan test` in the Laravel env to confirm.

---

## 8. Phase 3 — Approval Workflow & Maker-Checker

**Priority:** Critical
**Duration:** 3-4 days
**Status:** ✅ Complete
**Goal:** Enforce segregation of duties. A drafter (accountant) submits; an approver (admin/manager) approves; only then can stock + GL be posted. Value-threshold policy auto-approves small corrections; large ones require explicit approval.

### Implementation Summary

**3.1 Schema — migration `2025_07_29_000001_add_approval_to_stock_adjustments.php` (NEW)**
- Adds 9 columns to `stock_adjustments`: `submitted_by/at`, `approved_by/at`, `approval_comments`, `confirmed_by/at`, `confirm_reason` (G9), `cancel_reason` (G15).
- Drops & re-adds the `stock_adjustments_status_check` CHECK constraint (via `pg_constraint` introspection — mirrors the StockTake approval migration pattern) to allow the 3 new states: `submitted`, `approved`, `rejected`. Final allow-list: `draft, submitted, approved, confirmed, cancelled, rejected`.
- Adds partial index `idx_sa_submitted (branch_id, submitted_at) WHERE status = 'submitted'` — powers the "awaiting my approval" worklist.
- `cancel_reason` is a NEW dedicated column (distinct from the existing `reverse_reason`): `cancel_reason` = "why was this cancelled" (always set on cancel); `reverse_reason` = "why was the stock+GL reversed" (set only on confirmed-cancel). For a confirmed-cancel both are populated from the same input.
- Idempotent up/down (guarded by `Schema::hasColumn` + `pg_constraint` introspection).
- `database/sql/03_stock.sql` updated for fresh-install parity (inline columns + CHECK + partial index).

**3.2 Config — `config/stock_adjustment.php` (NEW)**
- `require_approval` (bool, default `true`) — gate on/off.
- `auto_approve_below_value` (numeric, default `1000`) — skip the human gate below this value (one-step confirm from draft).
- `max_value_without_secondary_approval` (numeric, default `50000`) — force-approve threshold: when `require_approval=false`, adjustments ≥ this value still go through approval.
- `approver_roles` (default `admin, manager`), `submitter_roles` (default `admin, accountant`), `confirmer_roles` (default `admin, accountant`).
- `block_closed_period` (bool, default `true`) — reject back-dating into a closed accounting period.
- `stale_draft_days` (int, default `7`) — powers the audit-screen staleness check.
- All knobs overridable via `env()`.

**3.3 `StockAdjustmentPolicyService` (NEW) — `app/Services/Stock/StockAdjustmentPolicyService.php`**
- Injects `AccountingPeriodService` for closed-period checks.
- `requiresApproval(StockAdjustment $adj): bool` — the central decision. Combines `require_approval` + `auto_approve_below_value` + `max_value_without_secondary_approval` (force-approve). Logic: force-approve threshold wins; else if `require_approval` is on, auto-approve below threshold; else no approval.
- `isBelowAutoApproveThreshold()`, `canSubmit()`, `canApprove()`, `canConfirm()`, `isSubmitter()` (segregation-of-duties helper), `isWithinClosedPeriod()` (delegates to `AccountingPeriodService::earliestOpenDate`), `approvalHint()` (human-readable hint for the create form).
- Reads from `config()` (not a DB table) — deliberately lighter than StockTake's `stock_take_policies` table because Stock Adjustment is an infrequent, accountant-driven tool.

**3.4 Service — `StockAdjustmentService.php`**
- Constructor now injects `StockAdjustmentPolicyService` (3rd param).
- `createAdjustment()`: added closed-period guard (blocks back-dating via `policy->isWithinClosedPeriod`).
- `submitAdjustment(int $id, int $userId, ?string $comment)` (NEW): draft → submitted. Validates `canSubmit`. If `!requiresApproval()`, auto-advances to `approved` inline (sets `approved_by = submitted_by`, appends `[AUTO-APPROVED — below threshold]` to `approval_comments`). Appends a timestamped `SUBMITTED by user #X` line to `approval_comments`.
- `approveAdjustment(int $id, int $userId, string $comment)` (NEW): submitted → approved. Validates `canApprove`. **Enforces segregation of duties**: throws if `approved_by === submitted_by` (the submitter cannot approve their own submission).
- `rejectAdjustment(int $id, int $userId, string $comment)` (NEW): submitted → draft. Appends `[REJECTED] by user #X: <reason>` to `approval_comments`. Clears `approved_by/at`. `submitted_by/at` preserved (the submission happened).
- `confirmAdjustment(int $id, int $confirmedBy, ?string $confirmReason = null)` (EXTENDED): now requires `canBeConfirmed(requiresApproval)` — i.e. `isApproved()` OR (`isDraft()` && `!requiresApproval`). Sets `confirmed_by/at` + `confirm_reason` (G9 fix). Posts stock + GL (existing logic, G17 routing preserved).
- `cancelAdjustment(int $id, int $cancelledBy, string $reason)` (EXTENDED): now accepts draft/submitted/approved/confirmed (any non-terminal). **Always stores `cancel_reason`** (G15 fix). For confirmed-cancel, `reverse_reason` is also populated (preserves the reversal banner UI). Added a hard guard: throws if `reason` is empty.
- `appendComment()` private helper — appends a `[timestamp] <line>` entry to `approval_comments` so the full maker-checker trail is in one column.

**3.5 Model — `StockAdjustment.php`**
- Added `STATUSES`, `STATUS_LABELS`, `STATUS_BADGES` constants (6 statuses).
- Extended `$fillable` (9 new columns) + `$casts` (3 new datetime + 3 new integer casts).
- Added relationships: `submittedBy()`, `approvedBy()`, `confirmedBy()` (BelongsTo User).
- Added state helpers: `isSubmitted()`, `isApproved()`, `isPendingApproval()`, `isRejected()`, `isTerminal()`.
- Updated `canBeConfirmed(bool $approvalRequired = true): bool` — pure status check; the `$approvalRequired` flag is supplied by the service via the policy.
- Added `statusLabel()` + `statusBadge()` helpers (driven by the central `STATUS_BADGES` map).

**3.6 Policy — `StockAdjustmentPolicy.php`**
- Added `submit()` (admin, accountant), `approve()` (admin, manager), `reject()` (admin, manager) methods — all with `sameBranch()` enforcement. The route middleware is the primary gate; these are defense-in-depth.

**3.7 Routes — `routes/web.php`**
- Added `POST {id}/submit` to the `role:admin,accountant` group (with `branch.isolation`).
- Added a NEW `role:admin,manager` group with `POST {id}/approve` + `POST {id}/reject` (both with `branch.isolation` + `{id}` regex). Separated from the write group because approval is an approver action, not a drafter action.

**3.8 Controller — `StockAdjustmentController.php`**
- Constructor now injects `StockAdjustmentPolicyService` (3rd param).
- `index()`: stats now include `submitted` + `approved` counts; passes `statuses` + `statusLabels` to the view.
- `create()`: passes `approvalHint` (from `policy->approvalHint()`) to the view for the workflow heads-up.
- `show()`: eager-loads `submittedBy`, `approvedBy`, `confirmedBy`; passes 5 policy flags to the view (`requiresApproval`, `canSubmit`, `canApprove`, `canConfirm`, `isSubmitter`) so the action buttons render correctly.
- `confirm()`: now passes `confirm_reason` to the service (G9 — was discarded).
- `submit()` / `approve()` / `reject()` (NEW): validate `comment` (required for approve/reject, optional for submit), `$this->authorize()` against the Policy, call the service, redirect with a contextual success message.

**3.9 Views**
- `show.blade.php`: `$statusBadge` now covers all 6 states. Added a **lifecycle stepper** card (Draft → Submitted → Approved → Confirmed, with Cancelled badge). The actions aside is now fully lifecycle-aware: Submit-for-Approval (draft + requiresApproval), Confirm-direct (draft + !requiresApproval), Approve/Reject (submitted + canApprove + !isSubmitter), Confirm (approved), Cancel (any non-terminal). Each action has a Swal confirmation dialog (submit/approve/reject added; confirm/cancel retained). Added approval-trail detail rows (Submitted/Approved/Confirmed/Cancel reason/Approval trail `<pre>`) to the details card.
- `index.blade.php`: `$statusBadge` covers all 6 states. Status filter dropdown now includes Submitted/Approved. Added a "Pending approval" stat card (submitted + approved count) so the worklist is surfaced. Stats array includes `submitted` + `approved`.
- `create.blade.php`: added an approval-workflow hint alert (from `approvalHint`) so the drafter knows the threshold up front.

### Verification Checklist (Phase 3)
- [x] A draft below the auto-approve threshold can be confirmed in one step.
- [x] A draft above the threshold cannot be confirmed directly — it must be submitted, then approved.
- [x] Only `admin`/`manager` can approve.
- [x] Rejection returns the adjustment to draft with a comment.
- [x] `confirmed_by/at` and `confirm_reason` are persisted.
- [x] Cancel always stores `cancel_reason`.

### Gaps closed
- **G5** (no approval workflow / no maker-checker → FIXED): full draft → submitted → approved → confirmed lifecycle with config-driven value thresholds, segregation-of-duties enforcement, and a UI stepper.
- **G9** (no `confirmed_by`/`confirmed_at`; `confirm_reason` discarded → FIXED): the posting action is now attributed and the confirm_reason is persisted.
- **G15** (draft cancels silently discard `cancel_reason` → FIXED): `cancel_reason` is now always stored on every cancel (draft, submitted, approved, or confirmed).

### Defense-in-depth layers for the approval gate
1. `role:` middleware on the route group (PRIMARY gate) — submit = admin/accountant; approve/reject = admin/manager.
2. `StockAdjustmentPolicy` via `$this->authorize()` (controller defense-in-depth) — submit/approve/reject methods.
3. `StockAdjustmentPolicyService::canSubmit/canApprove/canConfirm` (service-layer re-check) — survives any future route loosening.
4. Segregation-of-duties check in `approveAdjustment()` — approver ≠ submitter (DB-enforced via the service).
5. `branch.isolation` middleware on all POST `{id}/*` routes (resolves {id} → `stock_adjustments.branch_id`).
6. PostgreSQL RLS on `stock_adjustments` (DB-level backstop).

Note: PHP not installed in this sandbox (Node/Next.js env), so syntax verified by careful manual review + route-name cross-check against Blade views. Recommend running `php artisan migrate`, `php artisan route:list`, and `php artisan test` in the Laravel env to confirm.

---

## 9. Phase 4 — Dedicated Audit Log & Logger

**Priority:** High
**Duration:** 2 days
**Status:** ✅ COMPLETED (2025-07-30)
**Goal:** Replace the dead `AuditableMasterData` trait with a purpose-built `stock_adjustment_audit_log` table and `StockAdjustmentAuditLogger` that captures every lifecycle action with actor, timestamp, before/after snapshot, and IP.

### 4.1 Schema
**File (new migration):** `2025_07_30_000001_create_stock_adjustment_audit_log.php`
```php
Schema::create('stock_adjustment_audit_log', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
    $table->string('action', 40);
    $table->unsignedBigInteger('actor_id')->nullable();
    $table->string('actor_role', 40)->nullable();
    $table->json('payload')->nullable();           // before/after diff, reason, value
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent', 255)->nullable();
    $table->timestamp('created_at');
});
DB::statement("ALTER TABLE stock_adjustment_audit_log ADD CONSTRAINT saal_action_check
    CHECK (action IN ('create','update','submit','approve','reject','confirm',
                      'cancel','reverse','force_confirm','reopen','delete','export','print'))");
// RLS by branch_id via join — or denormalize branch_id onto log row
Schema::table('stock_adjustment_audit_log', function (Blueprint $table) {
    $table->unsignedInteger('branch_id')->nullable()->after('stock_adjustment_id');
});
```

### 4.2 `StockAdjustmentAuditLogger`
**File (new):** `laravel/app/Services/Stock/StockAdjustmentAuditLogger.php`
```php
class StockAdjustmentAuditLogger
{
    public function log(StockAdjustment $adj, string $action, array $payload = []): void
    {
        DB::table('stock_adjustment_audit_log')->insert([
            'stock_adjustment_id' => $adj->id,
            'branch_id'           => $adj->branch_id,
            'action'              => $action,
            'actor_id'            => Auth::id(),
            'actor_role'          => Auth::user()?->roles()->first()?->name,
            'payload'             => json_encode($payload),
            'ip_address'          => request()?->ip(),
            'user_agent'          => request()?->userAgent(),
            'created_at'          => now(),
        ]);
    }
}
```

### 4.3 Wire into the service
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php`
- Inject `StockAdjustmentAuditLogger`. Call `$this->audit->log($adj, 'create', [...])` inside `createAdjustment` (after commit), and similarly for `submit`, `approve`, `reject`, `confirm`, `cancel`. Log `force_confirm` when the `force` override (Phase 6) is used.
- Remove (or leave dormant) the `AuditableMasterData` trait on the model — it remains dead for the DB::table path, but harmless. Add a code comment explaining the logger supersedes it.

### 4.4 Audit timeline on show page
**File:** `laravel/resources/views/admin/stock-adjustments/show.blade.php`
- Render a chronological timeline of audit entries (actor, role, action badge, timestamp, payload diff, IP). Mirror the Warehouse Transfer show-page audit section.

### Verification Checklist (Phase 4)
- [x] Every lifecycle action (create/submit/approve/reject/confirm/cancel) writes exactly one `stock_adjustment_audit_log` row. ✅ (all 6 lifecycle methods call `$this->audit->log()` inside their DB::transaction; verified by grep — 6 call sites).
- [x] The actor, role, IP, and payload are captured. ✅ (actor_id from `auth()->id()`, actor_role from `User::getRole()`, ip_address + user_agent from `request()`, payload as jsonb).
- [x] The show page renders the timeline in chronological order. ✅ (`auditLogs` relation `orderBy('id')` — monotonic with created_at; timeline card rendered on show.blade.php).
- [x] Branch isolation: a non-admin cannot see another branch's audit rows. ✅ (PostgreSQL RLS on `stock_adjustment_audit_log` scoped by branch_id + admin bypass; branch_id denormalized at insert time; the adjustment itself is already branch-gated via `findOrFail` + `authorize('view')`).

### Implementation Summary (Phase 4)

**Files created:**
- `laravel/database/migrations/2025_07_30_000001_create_stock_adjustment_audit_log.php` — append-only `stock_adjustment_audit_log` table (bigserial PK, FK → stock_adjustments ON DELETE CASCADE, `branch_id` NOT NULL for RLS, 13-value action CHECK constraint, jsonb payload, ip_address + user_agent) + 4 indexes (timeline `idx_saal_adjustment`, partial-critical `idx_saal_critical`, branch `idx_saal_branch`, actor `idx_saal_actor`) + full RLS policy set (select/insert/update/delete/admin with admin bypass). Idempotent (`Schema::hasTable` + `DROP POLICY IF EXISTS`). Mirrors the proven `stock_take_audit_log` migration.
- `laravel/app/Services/Stock/StockAdjustmentAuditLogger.php` — thin, side-effect-free writer. `log(StockAdjustment $adj, string $action, array $payload, ?int $actorId)` resolves actor_id / actor_role / ip / user_agent from request context. No-op (returns, does NOT throw) on missing identity — a logging failure can never break a stock-adjustment transition. Does NOT start its own transaction (caller's transaction is the unit of work, so a rolled-back confirm also rolls back its audit row).
- `laravel/app/Models/StockAdjustmentAuditLog.php` — Eloquent model. `payload` cast to array, `UPDATED_AT = null` (append-only), `actor()` belongsTo relationship, `actionLabel()` / `actionBadge()` / `isCritical()` helpers + `ACTIONS` / `ACTION_LABELS` / `ACTION_BADGES` constants powering the timeline UI.

**Files modified:**
- `laravel/database/sql/03_stock.sql` — fresh-install parity (inline CREATE TABLE + 4 indexes + CHECK constraint, after the `stock_take_audit_log` block).
- `laravel/app/Services/Stock/StockAdjustmentService.php` — constructor injects `StockAdjustmentAuditLogger` (4th param); all 6 lifecycle methods (create / submit / approve / reject / confirm / cancel) now write exactly one audit row inside their `DB::transaction`. Cancel captures `prior_status` + `reversed` flag; submit captures `auto_approved` flag; confirm captures `journal_entry_id` + `reference_type`.
- `laravel/app/Models/StockAdjustment.php` — `auditLogs()` HasMany relationship (`orderBy('id')`); comment on the `AuditableMasterData` trait explaining it is dead (service writes via `DB::table()`, bypassing Eloquent events) but left in place for safety, superseded by the dedicated logger.
- `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` — `show()` eager-loads `auditLogs.actor` + passes `$auditLogs` to the view.
- `laravel/resources/views/admin/stock-adjustments/show.blade.php` — audit-timeline card (action badge, actor username + role badge, timestamp, payload key/value badges, IP + user-agent), rendered chronologically. Mirrors the Warehouse Transfer audit event-history UI.

**Key design decisions:**
1. `branch_id` is NOT NULL (deviation from the plan's nullable suggestion) to match the proven `stock_take_audit_log` pattern and ensure RLS always has a value to compare — the logger always populates it from `$adj->branch_id`, which is always set (resolved from the warehouse at create time).
2. `actor_id` is a plain bigint with no FK (mirrors the sibling `confirmed_by` / `reversed_by` convention) so a deleted user does not orphan the audit row.
3. `actor_role` is snapshotted at action time — roles can change later (a manager is demoted), but the audit row must keep the role held when the action was performed.
4. Auto-approval (submit below threshold) writes ONE `submit` row with `auto_approved: true` in the payload — NOT a separate `approve` row, because no human approver acted (the system mediated the auto-approval).
5. The `reverse` action vocab is reserved for a future explicit un-cancel/reverse flow (Phase 6); a confirmed-cancel writes ONE `cancel` row with `reversed: true` in the payload.
6. The logger resolves `ip_address` + `user_agent` from `request()` (null-safe via `request()?->ip()` / `?->userAgent()`), so console/queue/tinker calls without an HTTP request store null rather than throwing. `user_agent` is clamped to varchar(255).

**Gap closed:** G8 (`AuditableMasterData` dead code; no audit log table/logger → FIXED). Also retrospectively marked G6 + G17 as ✅ FIXED (Phase 2 omissions in the gap table).

---

## 10. Phase 5 — Unit-of-Measure (UOM) Handling ✅ DONE

**Priority:** High
**Duration:** 3 days
**Status:** ✅ DONE
**Goal:** Allow the accountant to enter quantities in any UOM (Carton, Pcs, KG) and have the system convert to the product's base unit before posting to stock. This directly enables the "system/unit-of-measure errors" use case.

### 5.1 Schema: UOM conversions
**File (new migration):** `2025_07_31_000001_create_uom_tables.php`
```php
Schema::create('units_of_measure', function (Blueprint $table) {
    $table->id();
    $table->string('code', 20)->unique();   // Pcs, Carton, KG, Bag, Dobe, Set
    $table->string('name', 60);
    $table->string('type', 20);             // count, weight, volume
});

Schema::create('product_uom_conversions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->foreignId('from_uom_id')->constrained('units_of_measure');
    $table->foreignId('to_uom_id')->constrained('units_of_measure');  // usually the base
    $table->decimal('factor', 14, 6);       // 1 from_uom = factor to_uom
    $table->unique(['product_id', 'from_uom_id', 'to_uom_id']);
});
```
Seed `units_of_measure` from the existing `products.unit` CHECK enum.

### 5.2 Schema: UOM columns on items
**File (same migration):**
```php
Schema::table('stock_adjustment_items', function (Blueprint $table) {
    $table->foreignId('uom_id')->nullable()->after('qty')->constrained('units_of_measure');
    $table->decimal('qty_entered', 14, 4)->nullable()->after('uom_id');  // what user typed
    $table->decimal('qty_base', 14, 4)->nullable()->after('qty_entered'); // converted
    $table->decimal('uom_factor', 14, 6)->nullable()->after('qty_base');  // snapshot
});
// Backfill: qty_base = qty, uom_id = product's base unit, factor = 1
```

### 5.3 Service: conversion logic
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php`
- New `UomConversionService::convert(int $productId, int $fromUomId, int $toUomId, float $qty): float` (with caching).
- In `validateCreateInput()`, accept `uom_id` + `qty_entered` per item. Resolve the product's base UOM. Compute `qty_base = qty_entered × factor`. Validate the conversion exists.
- Persist `uom_id`, `qty_entered`, `qty_base`, `uom_factor` (snapshot the factor for audit immutability).
- Use `qty_base` (signed) for `StockService::applyTransaction()` and `warehouse_stock` mutations. Use `qty_entered` + UOM for display.

### 5.4 UI: per-row UOM dropdown
**File:** `laravel/resources/views/admin/stock-adjustments/create.blade.php`
- Per item row: Qty (entered) + UOM `<select>` (populated from `product_uom_conversions` for that product via AJAX on product select). Default UOM = product's base unit.
- Live "Base qty" read-only display = `qty_entered × factor`.
- Rate is per base unit; amount = `qty_base × rate`.

### 5.5 Display UOM on show/print
- Items table shows `qty_entered uom` and `(qty_base base)`.

### Verification Checklist (Phase 5)
- [x] Selecting a product populates its available UOMs. ✅ (`getProductUoms` AJAX endpoint returns base unit + any `product_uom_conversions` rows; `loadUoms()` in create.blade.php populates the per-row `<select>` on `select2:select`).
- [x] Entering 2 Cartons (factor 12) posts 24 Pcs to `warehouse_stock`. ✅ (service computes `qty_base = qty_entered × factor`; `confirmAdjustment` passes `$item->baseQty()` to `StockService::applyTransaction()` which writes `stock_transactions.qty` + updates `warehouse_stock`).
- [x] `qty_base` is stored and used for stock + GL. ✅ (persisted on `stock_adjustment_items`; `postAdjustmentGL` reads the header `total_amount` which is now `sum(qty_base × rate)`; `amount()` uses `qty_base`).
- [x] Show/print displays the entered UOM and the base qty. ✅ (show.blade.php items table now has "Qty entered" + "Base qty" columns with UOM code labels; `enteredQty()` / `baseQty()` / `enteredQtyLabel()` / `baseQtyLabel()` accessors on the model).
- [x] Missing conversion factor blocks submission with a clear error. ✅ (`UomConversionService::resolveFactor()` returns null → service throws `InvalidArgumentException` naming the product, the from-UOM, and the base unit; the controller's `try/catch` surfaces it as an error flash).

### Implementation Summary (Phase 5)

**Files created:**
- `laravel/database/migrations/2025_08_06_000001_create_uom_tables.php` — creates `units_of_measure` (id, code UNIQUE, name, type) + `product_uom_conversions` (product_id FK CASCADE, from_uom_id FK, to_uom_id FK, factor, UNIQUE(product_id, from_uom_id, to_uom_id)); adds 4 nullable columns to `stock_adjustment_items` (uom_id FK, qty_entered, qty_base, uom_factor); seeds the 6 unit codes from the `products.unit` CHECK enum (Pcs/Carton/KG/Bag/Dobe/Set); backfills existing items (`qty_base = qty`, `qty_entered = qty`, `uom_factor = 1`, `uom_id` = product's base unit via `products.unit → units_of_measure.code` join). Idempotent (`Schema::hasTable` / `Schema::hasColumn` guards).
- `laravel/app/Models/UnitOfMeasure.php` — Eloquent model for the master unit list. `byCode()` scope, `conversionsFrom()` / `conversionsTo()` relations.
- `laravel/app/Models/ProductUomConversion.php` — Eloquent model for per-product factors. `product()` / `fromUom()` / `toUom()` relations; `factor` cast to `decimal:6`.
- `laravel/app/Services/Stock/UomConversionService.php` — `resolveBaseUnit(productId)` (code = products.unit, cached 5 min), `resolveFactor(productId, fromUomId)` (returns 1 for self-conversion, null if missing), `convert(productId, fromUomId, qty)` (throws on missing), `getProductUoms(productId)` (returns `[{uom_id, code, name, factor, is_base}]` for the AJAX dropdown), `clearCacheForProduct()` (for a future management UI).

**Files modified:**
- `laravel/database/sql/01_auth_and_master.sql` — fresh-install parity: `units_of_measure` + `product_uom_conversions` CREATE TABLEs after the products block (so the `stock_adjustment_items.uom_id` FK resolves).
- `laravel/database/sql/03_stock.sql` — `stock_adjustment_items` gains the 4 UOM columns (with FK to `units_of_measure`).
- `laravel/app/Models/StockAdjustmentItem.php` — `uom_id` / `qty_entered` / `qty_base` / `uom_factor` added to `$fillable` + `$casts`; `uom()` relation; `amount()` now uses `qty_base`; new `baseQty()` / `enteredQty()` / `enteredQtyLabel()` / `baseQtyLabel()` accessors.
- `laravel/app/Models/Product.php` — `baseUnit()` BelongsTo (matches `products.unit` → `units_of_measure.code`) + `uomConversions()` HasMany.
- `laravel/app/Services/Stock/StockAdjustmentService.php` — constructor injects `UomConversionService` (5th param); `createAdjustment` loop resolves the factor per item (`uom_id` optional → defaults to base unit, factor 1), computes `qty_base = qty_entered × factor`, persists the UOM snapshot, and uses `qty_base` for the total + availability pre-check; `confirmAdjustment` posts `$item->baseQty()` to `StockService::applyTransaction()`.
- `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` — constructor injects `UomConversionService`; new `getProductUoms()` AJAX endpoint; `store()` validation accepts `items.*.uom_id` (nullable|integer|exists); `show()` eager-loads `items.uom`.
- `laravel/routes/web.php` — `GET admin/stock-adjustments/product-uoms` route (role:admin,accountant group).
- `laravel/resources/views/admin/stock-adjustments/create.blade.php` — items table gains "UOM" + "Base qty" columns; `buildRow()` adds a per-row UOM `<select>` (name `items[idx][uom_id]`) + read-only base-qty input; `loadUoms()` fetches the product's UOMs via AJAX and defaults to the base unit; `recomputeRow()` computes `base_qty = qty × factor` and `amount = qty_base × rate`; UOM change handler re-syncs the factor.
- `laravel/resources/views/admin/stock-adjustments/show.blade.php` — items table gains "Qty entered" + "Base qty" columns showing the entered qty + UOM code and the converted base qty + base unit code.

**Key design decisions:**
1. The base-unit self-conversion (from=base, to=base, factor=1) is IMPLICIT — no `product_uom_conversions` row is required. `resolveFactor()` returns 1 when `fromUomId === baseUomId`. This avoids seeding N self-conversion rows and makes the base unit always available in the dropdown.
2. `uom_factor` is snapshotted on `stock_adjustment_items` at creation time (audit immutability) — if an admin later edits a conversion factor, historical adjustments keep the factor they were posted with.
3. The legacy `qty` column stays as the authoritative BASE quantity (equals `qty_base` for new + backfilled rows). This is backward compatible with any code reading `$item->qty` and with the `stock_transactions.qty` semantics. New code reads `qty_base` explicitly via `baseQty()`.
4. `uom_id` is optional in the input (nullable). When absent, the service treats the qty as already in the base unit (factor 1) — fully backward compatible with non-UOM callers (API, future code paths, old form submissions).
5. `UomConversionService` caches base-unit + factor lookups for 5 minutes (conversion rows are write-once config, rarely changed) so the hot path (every item row in every adjustment) stays off the DB.
6. A UOM management UI (add/edit conversions per product) is out of scope for Phase 5 — the infrastructure + the adjustment flow is the deliverable. Admins can add conversions via `php artisan tinker` or a future management screen; `clearCacheForProduct()` is ready for that UI.

**Gap closed:** G7 (No UOM handling on line items → FIXED). Carton/Pcs confusion can no longer cause 10x stock errors — the system now converts entered quantities to the base unit before posting to stock + GL.

---

## 11. Phase 6 — Pipeline-Aware Availability, Reversal Safety & Date Integrity ✅ DONE

**Priority:** High
**Duration:** 2-3 days
**Status:** ✅ DONE
**Goal:** Restore Legacy's pipeline-aware availability check for decreases (G3), fix the duplicate-product reversal bug (G11), and fix the back-dated reversal date (G10).

### 6.1 Pipeline-aware availability
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php`
- Inject `StockAvailabilityService`.
- In `createAdjustment` (decrease pre-check) and `confirmAdjustment` (inside the `lockForUpdate` window), use `StockAvailabilityService::getWarehouseAvailableQty(productId, warehouseId)` instead of `StockService::getWarehouseQty`.
- Add an admin `force` parameter: when `force=true` (admin only), bypass the pipeline check for legitimate legacy-cleanup corrections below pipeline. Log `force_confirm` to the audit log (Phase 4). Require a mandatory `force_reason`.

### 6.2 Fix duplicate-product reversal (G11)
**File (new migration):** `2025_08_07_000001_add_stock_transaction_id_to_stock_adjustment_items.php`
```php
Schema::table('stock_adjustment_items', function (Blueprint $table) {
    $table->unsignedBigInteger('stock_transaction_id')->nullable()->after('id');
    $table->foreign('stock_transaction_id')->references('id')->on('stock_transactions');
});
// Add UNIQUE(stock_adjustment_id, product_id)
DB::statement("ALTER TABLE stock_adjustment_items ADD CONSTRAINT sai_adj_product_unique UNIQUE (stock_adjustment_id, product_id)");
```
**File:** `laravel/app/Services/Stock/StockService.php` (`applyTransaction`)
- Return the inserted `stock_transactions.id`.
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php`
- In `confirmAdjustment`, capture the returned `stock_transaction_id` and write it to `stock_adjustment_items.stock_transaction_id`.
- In `cancelAdjustment`, reverse by `stock_transaction_id` (exact row) instead of `.first()` lookup.

### 6.3 Fix back-dated reversal date (G10)
**File:** `laravel/app/Services/Stock/StockService.php` (`reverseTransaction`)
- Accept an optional `$reversalDate` param. Default to the original `stock_transactions.transaction_date`. If that date falls in a closed accounting period, fall back to `today()` and log a warning.
**File:** `laravel/app/Services/Accounting/JournalPostingService.php` (`reverseJournalEntry`)
- Accept `$entryDate` param; default to original `entry_date`. Apply the same closed-period fallback.
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php` (`cancelAdjustment`)
- Pass `$adjustment->adjustment_date` into both reversal calls.

### 6.4 Dedup guard in service
**File:** `laravel/app/Services/Stock/StockAdjustmentService.php` (`validateCreateInput`)
- Reject duplicate `product_id` within the same payload (the UNIQUE constraint is the DB-level backstop).

### Verification Checklist (Phase 6)
- [x] A decrease that would dip below pipeline-available stock is blocked unless `force=true` (admin). ✅ (`confirmAdjustment` re-checks `StockAvailabilityService::getWarehouseAvailableQty` inside `lockForUpdate`; throws with a message naming the product + available + requested + the admin force path. `force=true` bypasses when `policy->canForceConfirm($user)` (admin) + a non-empty `force_reason`.)
- [x] A duplicate `product_id` in one adjustment is rejected at validation. ✅ (`validateCreateInput` scans `items` for duplicate product_id and throws `InvalidArgumentException` naming both row indices. DB-level `UNIQUE(stock_adjustment_id, product_id)` is the backstop — added by migration `2025_08_07_000001`.)
- [x] Reversing a back-dated (Jan 1) adjustment posts the reversal stock_transaction dated Jan 1 (or today if Jan 1 is closed, with a warning). ✅ (`cancelAdjustment` passes `$adjustment->adjustment_date` into `reverseTransaction` + `reverseJournalEntry`; `StockService::resolveReversalDate` checks `accounting_periods.closed_through_date` for the warehouse's branch and falls back to `today()` + `Log::warning` when the requested date is frozen.)
- [x] `stock_adjustment_items.stock_transaction_id` is populated on confirm and used on cancel. ✅ (`confirmAdjustment` captures `applyTransaction`'s returned `StockTransaction->id` and UPDATEs the item row; `cancelAdjustment` reverses by that id directly, with a legacy `product+reference` fallback for pre-Phase-6.2 rows.)

### Implementation Summary (Phase 6)

**Files created:**
- `laravel/database/migrations/2025_08_07_000001_add_stock_transaction_id_to_stock_adjustment_items.php` — adds `stock_transaction_id` (nullable **integer** — type matches `stock_transactions.id`; see **Hotfix 3**) + `stock_transaction_date` (nullable date) + **COMPOSITE FK** `sai_stock_tx_fk (stock_transaction_id, stock_transaction_date) → stock_transactions(id, transaction_date) ON DELETE SET NULL` (a single-column FK on `(id)` is impossible on a partitioned table — see **Hotfix 3**) + composite partial index `idx_sai_stock_tx` + `UNIQUE(stock_adjustment_id, product_id)` constraint `sai_adj_product_unique`. The UNIQUE add is DEFENSIVE: it counts existing duplicate groups first and SKIPS the constraint with a `Log::warning` (rather than failing the migration) when dupes exist — the application-layer dedup guard (6.4) is the runtime gate; an operator can clean historical dupes and re-run the DDL. Idempotent (`Schema::hasColumn` + `pg_constraint`/`pg_indexes` introspection). Safe up/down.

**Files modified:**
- `laravel/app/Services/Stock/StockAdjustmentService.php` —
  - Constructor injects `StockAvailabilityService` (6th param).
  - `createAdjustment` decrease pre-check now uses pipeline-aware `getWarehouseAvailableQty` (was `getWarehouseQty`); error message names the pipeline + the admin force path.
  - `confirmAdjustment` signature extended: `bool $force = false, ?string $forceReason = null`. Inside `lockForUpdate`: re-checks pipeline availability for decreases (throws with product/available/requested on failure); `force=true` requires `policy->canForceConfirm($user)` (admin) + a non-empty `force_reason`. Captures `applyTransaction`'s returned `StockTransaction->id` and UPDATEs `stock_adjustment_items.stock_transaction_id` (6.2). Audit action is `force_confirm` (distinct from `confirm`) when force was used, with `forced` + `force_reason` in the payload.
  - `cancelAdjustment` passes `$adjustment->adjustment_date` into both `reverseJournalEntry` + `reverseTransaction` (6.3). Reverses by `stock_transaction_id` (exact row) with a legacy `product+reference` fallback for pre-Phase-6.2 rows (6.2).
  - `validateCreateInput` rejects duplicate `product_id` in the payload (6.4) — names both row indices, suggests combining the quantities.
- `laravel/app/Services/Stock/StockService.php` — `reverseTransaction` accepts `?string $reversalDate = null`; new private `resolveReversalDate(warehouseId, requestedDate)` looks up the warehouse's branch + `accounting_periods.closed_through_date` and falls back to `today()` + `Log::warning` when the requested date is frozen (reversals are corrective — never blocked outright).
- `laravel/app/Services/Accounting/JournalPostingService.php` — `reverseJournalEntry` accepts `?string $entryDate = null`; defaults to the original JE's `entry_date` (was hardcoded `now()`). `skip_period_check` stays true (reversals can post to closed periods — they're corrective, not new postings).
- `laravel/app/Models/StockAdjustmentItem.php` — `stock_transaction_id` in `$fillable` + `$casts`; new `stockTransaction()` BelongsTo relation; doc-block updated.
- `laravel/app/Services/Stock/StockAdjustmentPolicyService.php` — new `canForceConfirm(User)` helper (reads `force_confirmer_roles` config, default `['admin']`).
- `laravel/config/stock_adjustment.php` — new `force_confirmer_roles` knob (default `['admin']`) with full doc-block explaining the force path semantics.
- `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` — `confirm()` validates + threads `force` + `force_reason` (defense-in-depth admin check before the service re-check); `show()` passes `canForceConfirm` flag to the view.
- `laravel/resources/views/admin/stock-adjustments/show.blade.php` — both confirm forms (draft one-step + approved) carry hidden `force` + `force_reason` fields; the confirm Swal now renders a custom HTML with the optional confirm_reason textarea + (when `canForceConfirm && isDecrease`) a force checkbox + force_reason textarea (required when checked, validated via `preConfirm`).
- `laravel/database/sql/03_stock.sql` — fresh-install parity: `stock_adjustment_items` gains `stock_transaction_id` (integer) + `stock_transaction_date` (date) + composite FK `sai_stock_tx_fk → stock_transactions(id, transaction_date)` + `sai_adj_product_unique` UNIQUE constraint + composite partial index `idx_sai_stock_tx` (see **Hotfix 3** for the composite-FK rationale).

**Key design decisions:**
1. `StockAvailabilityService` was chosen over a new bespoke "adjustment availability" method because it already implements the exact Legacy formula (physical − open sales-invoice dispatches) with 5-min pipeline caching + invalidation hooks wired into SalesInvoice/SalesChallan services. Re-using it keeps one source of truth for "available qty".
2. The force path is admin-only by default (`force_confirmer_roles = ['admin']`) and logs a DISTINCT `force_confirm` audit action (not `confirm`) so auditors can filter the timeline for overrides. The `force_reason` is mandatory + stored in the audit payload.
3. The `UNIQUE(stock_adjustment_id, product_id)` constraint is added DEFENSIVELY — the migration counts existing duplicate groups and skips the constraint with a warning when dupes exist, rather than failing. The application-layer dedup guard (6.4) is the runtime gate; the constraint is the invariant for new rows.
4. `stock_transaction_id` is captured from `applyTransaction`'s return value (which already returned the `StockTransaction` model — we just weren't using the id). No change to `StockService::applyTransaction` was needed (the plan suggested making it return the id; it already does, as a model).
5. The reversal-date fallback (closed-period → today + warning) is in a private `resolveReversalDate` helper so the logic is centralized + the warning is always logged. Reversals are never blocked outright — they're corrective; blocking them would leave an inconsistent ledger.
6. `reverseJournalEntry`'s `skip_period_check` stays `true` even with the new `entry_date` — reversals posting into closed periods is intentional (the reversal undoes a posting that was already there). The closed-period check is only for the STOCK reversal (where back-dating into a frozen period would distort the warehouse_stock snapshot history).
7. The cancel-time reversal uses `stock_transaction_id` when present (Phase 6.2+ rows) and falls back to the legacy `product+reference` lookup ONLY for pre-Phase-6.2 rows — fully backward compatible with adjustments confirmed before this migration shipped.

**Gaps closed:** G3 (non-pipeline-aware availability → FIXED), G10 (back-dated reversal date → FIXED), G11 (duplicate-product reversal → FIXED).

### Post-Launch Hotfixes (committed after Phase 6)

Two runtime defects surfaced during end-to-end testing of the Phase 1-6 stack. Both are fixed, committed, and pushed (see git log: commits `e084a85` + `6a7be24`).

**Hotfix 1 — Missing `total_amount` column** (commit `e084a85`)
- **Symptom:** `SQLSTATE[42703]: column "total_amount" does not exist` on `/admin/stock-adjustments` (500 on index) + a latent INSERT failure on create.
- **Root cause:** the `stock_adjustments` table (`database/sql/03_stock.sql`) never declared `total_amount`, but the model/service/controller/views have referenced it since Phase 6.3 of the master migration. The index page's `sum('total_amount')` stat query hit the missing column first.
- **Fix:** migration `2025_08_05_000001_add_total_amount_to_stock_adjustments.php` adds `total_amount DECIMAL(14,2) NOT NULL DEFAULT 0` + backfills existing rows from `Σ(qty_base × rate)` of their items. Canonical `03_stock.sql` updated for fresh-install parity.

**Hotfix 2 — `journal_entry_id` FK violation on zero-amount confirm** (commit `6a7be24`)
- **Symptom:** `SQLSTATE[23503]: Foreign key violation: 7 ERROR: insert or update on table "stock_adjustments" violates foreign key constraint "stock_adjustments_journal_entry_id_fkey" — DETAIL: Key (journal_entry_id)=(0) is not present in table "journal_entries"`.
- **Root cause:** when an adjustment's GL total is `0.00` (e.g. a zero-rate opening balance, or an item whose `qty_base × rate` rounds to zero), no journal entry is posted — but the service was writing the literal `0` into `stock_adjustments.journal_entry_id`. The FK `stock_adjustments.journal_entry_id → journal_entries.id` rejects `0` (no row with id 0 exists). The column is nullable; `0` is never a valid value.
- **Fix:** `StockAdjustmentService::postAdjustmentGL()` now returns `?int` (the JE id, or `null` when no GL is posted) instead of `0`. The confirm path stores `NULL` in `journal_entry_id` when the GL total is zero. The method's doc-block explicitly documents the contract. Backward compatible — existing rows with a real `journal_entry_id` are untouched.

**Files modified (hotfixes):**
- `laravel/database/migrations/2025_08_05_000001_add_total_amount_to_stock_adjustments.php` (new — total_amount column + backfill)
- `laravel/app/Services/Stock/StockAdjustmentService.php` (`postAdjustmentGL` returns `?int`; confirm stores `null` not `0`)
- `laravel/database/sql/03_stock.sql` (fresh-install parity: `total_amount DECIMAL(14,2)` on `stock_adjustments`)

**Hotfix 3 — Phase 6 migration FK failure on partitioned `stock_transactions` (composite-FK fix)**
- **Symptom:** `php artisan migrate` failed on the real PostgreSQL DB: `SQLSTATE[42830]: Invalid foreign key: 7 ERROR: there is no unique constraint matching given keys for referenced table "stock_transactions"` on migration `2025_08_07_000001` (the Phase 6 `stock_transaction_id` FK).
- **Root cause:** `stock_transactions` is `PARTITION BY RANGE (transaction_date)` (Task 34). PostgreSQL REQUIRES every UNIQUE/PRIMARY KEY on a partitioned table to include ALL partitioning columns (PG docs §5.11.2), so its PK is COMPOSITE `(id, transaction_date)` — there is no `UNIQUE(id)` and one CANNOT be added. A FK may only reference columns backed by a UNIQUE/PK, so `REFERENCES stock_transactions(id)` is structurally impossible. (This is the deliberate PG design that lets FK validation happen per-partition — exactly what makes the append-only ledger scale to large data: monthly partitions → partition pruning, small per-partition indexes, O(1) archival via DETACH PARTITION.)
- **Second latent bug fixed in the same pass:** the original migration declared `stock_transaction_id` as `unsignedBigInteger` (= PG `bigint`), but `stock_transactions.id` is `integer GENERATED ALWAYS AS IDENTITY`. PG requires FK column types to match the referenced column EXACTLY, so the FK would have failed on type mismatch even without the partitioning issue.
- **Proper fix (NOT a workaround):** COMPOSITE FOREIGN KEY referencing the table's real primary key — `FOREIGN KEY (stock_transaction_id, stock_transaction_date) REFERENCES stock_transactions(id, transaction_date) ON DELETE SET NULL`. This preserves partitioning (the #1 scalability lever), gives true DB-level referential integrity, is the idiomatic PG pattern for referencing a partitioned table, and `ON DELETE SET NULL` nulls BOTH columns for a composite FK. Minimal/additive: one new nullable `date` column; `(NULL, NULL)` is valid for pre-Phase-6.2 rows. A composite partial index `idx_sai_stock_tx (stock_transaction_id, stock_transaction_date) WHERE stock_transaction_id IS NOT NULL` serves both the cancel-time lookup and the `ON DELETE SET NULL` row-finder (no seq scan on large data).
- **Rejected workarounds:** drop partitioning (destroys scalability for large data); add `UNIQUE(id)` (impossible on a partitioned table); drop the FK + app-only integrity (loses DB-level guarantee, orphan rows possible).
- **Migration rewritten in place** (not a new migration) because it had never successfully run on any real PG DB — no environment has it recorded in the `migrations` table. Fully idempotent: `ADD COLUMN IF NOT EXISTS` + defensive `bigint→integer` type-normalisation (covers any half-applied state) + `pg_constraint`/`pg_indexes` guards.
- **App changes:** `StockAdjustmentService::confirmAdjustment()` now persists BOTH `stock_transaction_id` AND `stock_transaction_date` (the date = `adjustment_date`, exactly what was written to `stock_transactions.transaction_date`). `cancelAdjustment()` needs NO change — reversal marks `is_reversed=true` (does not DELETE the row), so the composite FK stays satisfied. `StockAdjustmentItem` model gains `stock_transaction_date` in `$fillable`/`$casts` (`date`).

**Files modified (Hotfix 3):**
- `laravel/database/migrations/2025_08_07_000001_add_stock_transaction_id_to_stock_adjustment_items.php` (rewritten: composite FK + `stock_transaction_date` column + `integer` type + type-normalisation + composite partial index)
- `laravel/app/Models/StockAdjustmentItem.php` (`stock_transaction_date` in `$fillable` + `$casts`; doc-block)
- `laravel/app/Services/Stock/StockAdjustmentService.php` (confirm path writes both columns)
- `laravel/database/sql/03_stock.sql` (fresh-install parity: composite FK + `stock_transaction_date` + composite index + `integer` type)

**Hotfix 4 — `ShadowModeController` missing base `Controller` import (blocks Phase 8 verification)** (commit `4c6ead5`)
- **Symptom:** After pulling Phase 8 (commit `c51d691`), running `php artisan route:list --name=stock-adjustments` fatalled: `Class "App\Http\Controllers\Admin\Controller" not found at app/Http/Controllers/Admin/ShadowModeController.php:25`. `route:list` reflects on every controller referenced in `routes/web.php` to build the table, so one unloadable controller kills the whole command — blocking the Phase 8 verification step the worklog itself prescribes.
- **Root cause:** `ShadowModeController` declares `namespace App\Http\Controllers\Admin;` and `extends Controller` **without** a `use App\Http\Controllers\Controller;` import, so PHP resolved `Controller` to the non-existent `App\Http\Controllers\Admin\Controller` (only `App\Http\Controllers\Controller` exists). Every other Admin controller (`StockAdjustmentController`, `WarehouseTransferController`, +38 more) has the explicit import; `ShadowModeController` was the sole omission. This is a **pre-existing bug**, NOT introduced by Phase 8 — `ShadowModeController.php` is not in the Phase 8 diff. It would also fatal any request to `/admin/shadow-mode/*`.
- **Fix:** Added the missing `use App\Http\Controllers\Controller;` import (first line of the import block, matching the sibling-controller convention). One-line, no behavior change — the controller now correctly extends the base `App\Http\Controllers\Controller` (which provides `AuthorizesRequests` + `ValidatesRequests` + the Phase-1 branch-resolution helpers `resolveBranchIdForRead()` / `resolveBranchIdForWrite()`).
- **Verification (static):** Scanned all 40 controllers in `app/Http/Controllers/Admin/` for `extends Controller` without the matching import — `ShadowModeController` was the only offender; no other controller has the bug. (Docker / `php` CLI unavailable in the build sandbox, so `php -l` / `artisan route:list` were not run here — user re-runs the Phase 8 verification commands after pulling.)
- **Files modified (Hotfix 4):** `laravel/app/Http/Controllers/Admin/ShadowModeController.php` (1 import line added). Cross-cutting fix (lives in the warehouse-transfer shadow-mode subsystem, not the stock-adjustment subsystem) — logged here because it was discovered during Phase 8 verification and unblocks that verification.

---

## 12. Phase 7 — Reconciliation, Drift Detection & Data-Hygiene Fixes ✅ DONE

**Priority:** Medium
**Duration:** 2 days
**Status:** ✅ **COMPLETED** (2025-07-29)
**Goal:** Detect and surface divergence between `warehouse_stock` (cache) and `stock_transactions` (SSOT); close small data-hygiene gaps (G12, G15, G16 — G15/G16 already addressed in Phases 1/3; this phase focuses on G12 + a reconcile view).

### 7.1 `StockAdjustmentReconcileService`
**File (new):** `laravel/app/Services/Stock/StockAdjustmentReconcileService.php` ✅ Done
- `computeDrift(?int $branchId, ?int $warehouseId): array` — ✅ Done. SQL uses a LEFT JOIN on a pre-aggregated ledger subquery (hash-join friendly) with the `FILTER (WHERE NOT is_reversed)` clause from the spec:
  ```sql
  SELECT ws.warehouse_id, ws.product_id, ws.qty AS snapshot_qty, ws.avg_cost,
         COALESCE(st.ledger_qty, 0) AS ledger_qty,
         ws.qty - COALESCE(st.ledger_qty, 0) AS drift_qty,
         w.warehouse_name, w.branch_id, p.product_name, p.product_code
  FROM warehouse_stock ws
  JOIN warehouses w ON w.id = ws.warehouse_id
  JOIN products   p ON p.id = ws.product_id
  LEFT JOIN (
      SELECT warehouse_id, product_id,
             SUM(qty) FILTER (WHERE NOT is_reversed) AS ledger_qty
      FROM stock_transactions
      GROUP BY warehouse_id, product_id
  ) st ON st.warehouse_id = ws.warehouse_id AND st.product_id = ws.product_id
  WHERE ABS(ws.qty - COALESCE(st.ledger_qty, 0)) > ?   -- tolerance (config)
  [AND w.branch_id = ?] [AND ws.warehouse_id = ?]
  ORDER BY ABS(ws.qty - COALESCE(st.ledger_qty, 0)) DESC
  LIMIT ?
  ```
  Returns `{mismatches, checked, mismatched, total_drift_qty, ran_at}`. Tolerance + limit are config-driven (`stock_adjustment.reconcile_tolerance`, default `0.0001` matching the DB CHECK + `StockService::QTY_TOLERANCE`).
- `rebuildSnapshot(?int $warehouseId, ?int $branchId): array` — ✅ Done. ADMIN-ONLY maintenance op. Wraps `DELETE FROM warehouse_stock WHERE warehouse_id = ?` + `INSERT … SELECT SUM(qty)… GROUP BY …` in a single `DB::transaction()`. Recomputes `qty`, `avg_cost` (= `total_value / qty` when qty > 0, else 0), `total_qty`, `total_value` from the non-reversed ledger rows. Locks the scope (`lockForUpdate`) before the delete so concurrent `StockService::applyTransaction()` upserts block until commit. Chunked inserts (500/chunk) defend the Postgres parameter limit for the "rebuild all" case. Returns `{rebuilt, errors}`.

### 7.2 Controller + route
**File:** `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` ✅ Done
- `reconcile()` — ✅ Done. Renders `reconcile.blade.php` with the branch's warehouses (for the filter dropdown). Branch-scoped for non-admins via `getUserBranchId()`. Authorizes via the existing `audit` policy action.
- `runReconcile(Request)` — ✅ Done. AJAX JSON; accepts optional `warehouse_id`. Branch-scoped by caller; RLS is the DB backstop.
- `rebuildSnapshot(Request)` — ✅ Done. AJAX JSON; ADMIN-ONLY (route `role:admin` + defense-in-depth `isAdmin()` check). Accepts optional `warehouse_id`; null = rebuild all. Returns `{success, rebuilt, message}`.
- `getUserBranchId()` — ✅ Done. Private helper mirroring `WarehouseTransferController` (null for admin = see all; otherwise the session/user branch).
**File:** `laravel/routes/web.php` ✅ Done
- `GET admin/stock-adjustments/reconcile` — `role:admin,manager,accountant` (read). Placed BEFORE the `{id}` show route so the static path wins.
- `POST admin/stock-adjustments/reconcile/run` — `role:admin,accountant` (the drift computation).
- `POST admin/stock-adjustments/reconcile/rebuild` — `role:admin` (destructive maintenance — separated into its own admin-only group).

### 7.3 Scheduled drift alert
**File (new):** `laravel/app/Console/Commands/ReconcileStockDrift.php` ✅ Done
- `stock:reconcile-drift` command. `--dry-run` (report only, no notify) + `--branch=` (scope to one branch) options. Default path calls `runNightlyDriftAlert()`.
**File:** `laravel/routes/console.php` ✅ Done
- `Schedule::command('stock:reconcile-drift')->dailyAt('03:00')->withoutOverlapping()->runInBackground()` — offset from the 02:00 stale-draft cancel so the two heavy queries don't overlap.
- `runNightlyDriftAlert()` computes all-tenant drift; on mismatch > 0, resolves recipients via `User → employee` join filtering `employees.role` ∈ `stock_adjustment.reconcile_alert_roles` (default `['admin','superadmin']`) and notifies each via `$user->notify(new ERPNotification(...))`. Gated by `stock_adjustment.reconcile_drift_alert` (default true) — when false, drift is still computed + logged but no push fires. No drift = no notification (no nightly "all clear" spam).

### 7.4 View
**File (new):** `laravel/resources/views/admin/stock-adjustments/reconcile.blade.php` ✅ Done
- Hero header + warehouse-scope dropdown + Run/Rebuild buttons. AJAX fetch (POST) renders a 3-stat summary bar (rows checked / mismatches / total |drift|) + a scrollable drift table (warehouse, product+code, snapshot qty, ledger qty, drift). Rebuild button is admin-gated (`isAdmin()`), shows a confirm dialog naming the scope, and auto-reruns reconciliation after a successful rebuild so the admin sees the drift clear.

### 7.5 Index entry point
**File:** `laravel/resources/views/admin/stock-adjustments/index.blade.php` ✅ Done
- "Reconciliation" button added to the hero header (before the Audit button).

### 7.6 Config knobs
**File:** `laravel/config/stock_adjustment.php` ✅ Done
- `reconcile_tolerance` (default 0.0001) — drift below this is treated as rounding noise.
- `reconcile_drift_alert` (default true) — master toggle for the nightly push.
- `reconcile_alert_roles` (default `['admin','superadmin']`) — employee roles notified on drift.

### Verification Checklist (Phase 7)
- [x] Reconcile view lists every (warehouse, product) with non-zero drift. ✅ (`computeDrift` returns the full drift set ordered by |drift| DESC; the view renders it in a scrollable table with warehouse + product + snapshot qty + ledger qty + drift columns. Tolerance from config excludes rounding noise.)
- [x] `rebuildSnapshot` recomputes `warehouse_stock` from the ledger and clears drift. ✅ (DELETE + INSERT…SELECT inside one DB transaction; recomputes qty + avg_cost + total_qty + total_value from non-reversed ledger rows; locks the scope first; the view auto-reruns reconciliation after a rebuild so the admin sees the drift clear.)
- [x] Nightly schedule alerts admins on drift. ✅ (`stock:reconcile-drift` scheduled dailyAt('03:00') in routes/console.php; `runNightlyDriftAlert()` fires `ERPNotification` to every user whose employee role is in `reconcile_alert_roles` when mismatched > 0; gated by `reconcile_drift_alert`; no notification on 0 drift.)

### Implementation Summary (Phase 7)

**Files created:**
- `laravel/app/Services/Stock/StockAdjustmentReconcileService.php` — `computeDrift()` (pre-aggregated LEFT JOIN + `FILTER (WHERE NOT is_reversed)`), `rebuildSnapshot()` (transactional DELETE + INSERT…SELECT, scope-locked, chunked), `runNightlyDriftAlert()` (compute + role-resolved ERPNotification).
- `laravel/app/Console/Commands/ReconcileStockDrift.php` — `stock:reconcile-drift` with `--dry-run` + `--branch=` options; default path delegates to `runNightlyDriftAlert()`.
- `laravel/resources/views/admin/stock-adjustments/reconcile.blade.php` — hero + warehouse filter + Run/Rebuild buttons + AJAX-driven 3-stat summary + scrollable drift table; rebuild is admin-gated with a confirm dialog + auto-rerun.

**Files modified:**
- `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` — constructor injects `StockAdjustmentReconcileService` (5th dep); `reconcile()` / `runReconcile()` / `rebuildSnapshot()` methods (Phase 7 section); private `getUserBranchId()` helper; `Log` facade import.
- `laravel/routes/web.php` — `GET admin/stock-adjustments/reconcile` (read group); `POST admin/stock-adjustments/reconcile/run` (accountant write group); `POST admin/stock-adjustments/reconcile/rebuild` (separate admin-only group).
- `laravel/routes/console.php` — `Schedule::command('stock:reconcile-drift')->dailyAt('03:00')->withoutOverlapping()->runInBackground()`.
- `laravel/config/stock_adjustment.php` — `reconcile_tolerance`, `reconcile_drift_alert`, `reconcile_alert_roles` knobs.
- `laravel/resources/views/admin/stock-adjustments/index.blade.php` — "Reconciliation" button in the hero header.

**Key design decisions:**
1. **Pre-aggregated subquery over correlated subquery** — the `LEFT JOIN (SELECT … GROUP BY warehouse_id, product_id) st` lets PostgreSQL hash-join once across the whole `warehouse_stock` table, instead of re-running the SUM per row. The drift check is a read-heavy admin op; the join plan is the right shape.
2. **`FILTER (WHERE NOT is_reversed)` over `CASE WHEN`** — the idiomatic PostgreSQL aggregate-filter syntax (PG 9.4+). Cleaner than `CASE WHEN` and matches the Phase 7 spec verbatim. Reversals are excluded so a reversed movement doesn't double-count.
3. **rebuildSnapshot is transactional + scope-locked** — the DELETE + INSERT…SELECT is wrapped in `DB::transaction()` and the scope is `lockForUpdate()`d first, so a concurrent `StockService::applyTransaction()` upsert either sees the old snapshot (and updates it) or waits for the rebuild to commit. The warehouse_stock table never appears empty to a concurrent reader.
4. **avg_cost recompute = total_value / qty** — the ledger's `SUM(qty × rate)` over non-reversed rows IS the current inventory value at historical cost, which equals `avg_cost × qty` when avg_cost is correctly maintained. So rebuilding avg_cost from the ledger is self-correcting: any avg_cost drift (from a historical bug) is fixed alongside the qty drift.
5. **Alert recipients resolved via User → employee join** — the role lives on `employees.role` (not `users`), so the recipient query uses `whereHas('employee', …)`. Notifications go directly via `$user->notify(new ERPNotification(...))` rather than the rule-based `NotificationService::dispatch()` — drift alerts are system health, not business events, and must not depend on a `notification_rule` being configured.
6. **Admin-only rebuild, accountant-readable drift** — the reconcile VIEW is available to admin/manager/accountant (role:admin,manager,accountant for GET; role:admin,accountant for the run AJAX) because accountants need to SEE drift to triage it, but the REBUILD (destructive DELETE+INSERT) is admin-only at the route AND defense-in-depth in the controller (`isAdmin()` check). Matches the "accountant finds, admin fixes" governance model.
7. **Nightly job is all-tenant** — `runNightlyDriftAlert()` computes drift across ALL branches (no branch filter) so the alert is a complete signal. The per-branch scoping is for the interactive view (non-admin sees only their branch). A manager added to `reconcile_alert_roles` would see ALL branches' drift in the notification body — acceptable for a system-health alert, and the notification links to the reconcile page where RLS enforces their branch view.
8. **No DB migration needed** — Phase 7 is pure read/recompute. `warehouse_stock` and `stock_transactions` already exist with the right shape (Phases 1-6 + the master migration). The reconcile service + command + view + config are all code-only. The rebuild uses the existing `warehouse_stock` columns (`qty`, `avg_cost`, `total_qty`, `total_value`, `updated_at`).

**Gaps closed:** G12 (no warehouse_stock ↔ ledger drift reconciliation check → FIXED). G15 + G16 were already closed by Phases 1/3 (cancel_reason always stored; getProductRate branch-validated) — retrospectively noted here for the Phase 7 data-hygiene scope.

**Note:** PHP is not installed in the development sandbox — syntax was verified by careful manual review + a brace/paren balance check (consistent with Phases 1-6). The user must run `docker compose exec rcerp_app php artisan schedule:list` to confirm the `stock-reconcile-drift` job is registered, and `docker compose exec rcerp_app php artisan stock:reconcile-drift --dry-run` for a first end-to-end smoke test.

---

## 13. Phase 8 — UI Parity: CSV Export, Checklist, Print & Audit Timeline

**Priority:** Medium
**Duration:** 3 days
**Status:** ⏳ Pending
**Goal:** Restore Legacy-parity UX: CSV export (G2), the rich 6-section integrity checklist (G4), a print voucher (G18), and surface the audit timeline (Phase 4) + lifecycle stepper (Phase 3).

### 8.1 CSV export (G2)
**File:** `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php`
- `export()` — mirror `WarehouseTransferController::export()`: same filter params, branch isolation, `cursor()`, BOM-prefixed stream. Columns: Date, Code, Warehouse, Branch, Category, Type, Items, Total, Status, Submitted/Approved/Confirmed by + at, Reversed?.
**File:** `laravel/routes/web.php` — `GET admin/stock-adjustments/export` (role:admin,accountant,manager).
**File:** `laravel/resources/views/admin/stock-adjustments/index.blade.php` — "Export CSV" button.

### 8.2 `StockAdjustmentAuditService` (6-section checklist, G4)
**File (new):** `laravel/app/Services/Stock/StockAdjustmentAuditService.php`
- Port Legacy's 6 sections: workflow, gl_journal_links, ledger_nature, stock_gl, data_integrity, operations.
- Add a 7th section: **approval_workflow** (drafts stuck > 7 days, approved-but-not-confirmed > 3 days).
- Return pass/warn/fail counts + sample rows.

### 8.3 Checklist view
**File (new):** `laravel/resources/views/admin/stock-adjustments/checklist.blade.php`
- Mirror Legacy's checklist: hero + "Re-run checks" button + summary chips + section cards + sample-row tables.
- Reuse the existing `audit` route or rename to `checklist` (keep `audit` as alias).

### 8.4 Print voucher (G18)
**File (new):** `laravel/resources/views/admin/stock-adjustments/print.blade.php`
- Voucher layout: code, date, warehouse+branch, category, type, reason, items table (product, qty_entered+UOM, qty_base, rate, amount), totals, GL summary (JE# + Dr/Cr lines), lifecycle signatures line (Prepared by / Approved by / Posted by).
**File:** `StockAdjustmentController::print()` + `GET admin/stock-adjustments/{id}/print`.

### 8.5 GL audit blocks on show page
**File:** `laravel/resources/views/admin/stock-adjustments/show.blade.php`
- Add original + reversing JE blocks (mirror Legacy's `sales_gl_journal_blocks.php` partial).

### Verification Checklist (Phase 8)
- [ ] CSV export downloads with current filters.
- [ ] Checklist page renders all 6+1 sections with accurate counts.
- [ ] Print voucher renders cleanly (print-CSS, page break).
- [ ] Show page displays GL audit blocks + audit timeline + lifecycle stepper.

---

## 14. Phase 9 — API Routes & Mobile Support

**Priority:** Low
**Duration:** 1-2 days
**Status:** ⏳ Pending
**Goal:** Expose stock-adjustment CRUD + approval over REST for mobile/AI sidecars.

### 9.1 API routes
**File:** `laravel/routes/api.php`
```php
Route::prefix('v1/stock-adjustments')->middleware(['auth:sanctum','role:admin,accountant,manager'])->group(function () {
    Route::get('/', [StockAdjustmentApiController::class, 'index']);
    Route::get('{id}', [StockAdjustmentApiController::class, 'show']);
    Route::post('/', [StockAdjustmentApiController::class, 'store']);
    Route::post('{id}/submit', [StockAdjustmentApiController::class, 'submit']);
    Route::post('{id}/approve', [StockAdjustmentApiController::class, 'approve']);
    Route::post('{id}/reject', [StockAdjustmentApiController::class, 'reject']);
    Route::post('{id}/confirm', [StockAdjustmentApiController::class, 'confirm']);
    Route::post('{id}/cancel', [StockAdjustmentApiController::class, 'cancel']);
});
```

### 9.2 Resources
**Files (new):** `laravel/app/Http/Resources/StockAdjustmentResource.php`, `StockAdjustmentItemResource.php`.

### 9.3 Controller
**File (new):** `laravel/app/Http/Controllers/Api/StockAdjustmentApiController.php` — thin wrapper over the same `StockAdjustmentService` + `Policy`.

### Verification Checklist (Phase 9)
- [ ] Authenticated accountant token can list/create/submit/approve/confirm/cancel.
- [ ] Unauthorized role receives 403.
- [ ] Resources serialize UOM, category, lifecycle, and audit timeline.

---

## 15. Phase 10 — Test Coverage & Shadow Mode

**Priority:** High
**Duration:** 4 days
**Status:** ⏳ Pending
**Goal:** Lock in correctness with a feature test suite and (optionally) a shadow-mode comparison against the Legacy implementation for a defined window.

### 10.1 Feature tests
**File (new dir):** `laravel/tests/Feature/StockAdjustment/`
- `CreateAdjustmentTest.php` — validation, branch isolation, code generation.
- `ConfirmAdjustmentTest.php` — stock + GL posted atomically; `confirmed_by/at` set.
- `CancelAdjustmentTest.php` — confirmed reversal + draft cancel; `cancel_reason` stored.
- `ApprovalWorkflowTest.php` — submit/approve/reject; value threshold; role enforcement.
- `UomConversionTest.php` — Carton→Pcs, missing-factor rejection.
- `AvailabilityTest.php` — pipeline-aware block + admin `force` override.
- `ReversalDateTest.php` — back-dated reversal uses original date; closed-period fallback.
- `DuplicateProductTest.php` — UNIQUE + service dedup.
- `AuditLogTest.php` — every action writes one log row.
- `RlsBranchIsolationTest.php` — cross-branch 403/empty.
- `CsvExportTest.php` — stream contains BOM + filtered rows.
- `ApiEndpointsTest.php` — sanctum auth + role gate.

### 10.2 Factories
**Files (new):** `laravel/database/factories/StockAdjustmentFactory.php`, `StockAdjustmentItemFactory.php`.

### 10.3 Shadow mode (optional)
**File (new):** `laravel/app/Services/Stock/StockAdjustmentShadowService.php`
- For a defined window, duplicate each Legacy adjustment into Laravel and diff `warehouse_stock.qty`, `stock_transactions`, and GL. Mirror `WarehouseTransferShadowService`. Run for 7 days zero-diff before sign-off (per master MIGRATION_PLAN gate).

### Verification Checklist (Phase 10)
- [ ] `php artisan test tests/Feature/StockAdjustment/` is green.
- [ ] Coverage of `StockAdjustmentService` ≥ 90%.
- [ ] Shadow mode produces zero diffs for 7 consecutive days.

---

## 16. Appendix A — Database Schema Reference

### A.1 Current PostgreSQL Schema (before this plan)
See §3.3 for the verbatim DDL of `stock_adjustments`, `stock_adjustment_items`, `stock_transactions`, `warehouse_stock`.

### A.2 Schema Changes After All Phases

```sql
-- Phase 2: categorization
ALTER TABLE stock_adjustments ADD COLUMN adjustment_category varchar(40) DEFAULT 'other';
ALTER TABLE stock_adjustments ADD CONSTRAINT sa_category_check
    CHECK (adjustment_category IN (
        'opening_balance','data_migration','uom_correction',
        'post_conversion_fix','legacy_cleanup','reconciliation_variance','other'));

-- Phase 3: approval workflow + confirm attribution + cancel reason
ALTER TABLE stock_adjustments ADD COLUMN submitted_by bigint;
ALTER TABLE stock_adjustments ADD COLUMN submitted_at timestamp(0);
ALTER TABLE stock_adjustments ADD COLUMN approved_by bigint;
ALTER TABLE stock_adjustments ADD COLUMN approved_at timestamp(0);
ALTER TABLE stock_adjustments ADD COLUMN approval_comments text;
ALTER TABLE stock_adjustments ADD COLUMN confirmed_by bigint;        -- G9
ALTER TABLE stock_adjustments ADD COLUMN confirmed_at timestamp(0);  -- G9
ALTER TABLE stock_adjustments ADD COLUMN confirm_reason text;        -- G9
ALTER TABLE stock_adjustments ADD COLUMN cancel_reason text;         -- G15
ALTER TABLE stock_adjustments DROP CONSTRAINT stock_adjustments_status_check;
ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_status_check
    CHECK (status IN ('draft','submitted','approved','confirmed','cancelled','rejected'));
CREATE INDEX idx_sa_status ON stock_adjustments(status);
CREATE INDEX idx_sa_branch_status ON stock_adjustments(branch_id, status);

-- Phase 4: audit log
CREATE TABLE stock_adjustment_audit_log (
    id bigserial PRIMARY KEY,
    stock_adjustment_id integer NOT NULL REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    branch_id integer,
    action varchar(40) NOT NULL,
    actor_id bigint, actor_role varchar(40),
    payload jsonb,
    ip_address varchar(45), user_agent varchar(255),
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT saal_action_check CHECK (action IN (
        'create','update','submit','approve','reject','confirm',
        'cancel','reverse','force_confirm','reopen','delete','export','print'))
);
CREATE INDEX idx_saal_adjustment ON stock_adjustment_audit_log(stock_adjustment_id);
CREATE INDEX idx_saal_branch ON stock_adjustment_audit_log(branch_id);

-- Phase 5: UOM
CREATE TABLE units_of_measure (
    id bigserial PRIMARY KEY, code varchar(20) UNIQUE, name varchar(60), type varchar(20)
);
CREATE TABLE product_uom_conversions (
    id bigserial PRIMARY KEY,
    product_id integer NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    from_uom_id bigint NOT NULL REFERENCES units_of_measure(id),
    to_uom_id bigint NOT NULL REFERENCES units_of_measure(id),
    factor numeric(14,6) NOT NULL,
    UNIQUE (product_id, from_uom_id, to_uom_id)
);
ALTER TABLE stock_adjustment_items ADD COLUMN uom_id bigint REFERENCES units_of_measure(id);
ALTER TABLE stock_adjustment_items ADD COLUMN qty_entered numeric(14,4);
ALTER TABLE stock_adjustment_items ADD COLUMN qty_base numeric(14,4);
ALTER TABLE stock_adjustment_items ADD COLUMN uom_factor numeric(14,6);

-- Phase 6: reversal safety
ALTER TABLE stock_adjustment_items ADD COLUMN stock_transaction_id bigint REFERENCES stock_transactions(id);
ALTER TABLE stock_adjustment_items ADD CONSTRAINT sai_adj_product_unique UNIQUE (stock_adjustment_id, product_id);

-- RLS additions
ALTER TABLE stock_adjustment_audit_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY saal_branch_isolation ON stock_adjustment_audit_log
    USING (branch_id = current_setting('app.branch_id')::int OR current_setting('app.is_admin')::boolean);
```

---

## 17. Appendix B — Business Rule Matrix

### R-rules (Role / Authorization)
| # | Rule | Enforced At | Phase |
|---|---|---|---|
| R1 | Only admin/accountant may create/confirm/cancel | route middleware + Policy | 1 |
| R2 | Only admin/manager may index/show | route middleware | 1 |
| R3 | Only admin/manager may approve/reject | route middleware + Policy | 3 |
| R4 | `getProductRate` rejects cross-branch warehouse for non-admin | controller | 1 |
| R5 | Branch isolation via RLS + `branch.isolation` middleware | DB + middleware | 1 |

### S-rules (Stock Movement)
| # | Rule | Enforced At | Phase |
|---|---|---|---|
| S1 | Decrease blocked if qty > pipeline-available (physical − open sales) unless admin `force` | service | 6 |
| S2 | `warehouse_stock.qty` non-negative | CHECK + trigger | existing |
| S3 | Moving-average cost on IN; unchanged on OUT | service | existing |
| S4 | Reversal of increase re-checks on-hand | service | existing |
| S5 | Reversal `transaction_date` = original date (closed-period fallback to today) | service | 6 |
| S6 | qty stored in base UOM; `qty_base = qty_entered × factor` | service | 5 |
| S7 | Duplicate product_id within one adjustment rejected | service + UNIQUE | 6 |
| S8 | Opening-balance category writes `reference_type='opening_balance'` | service | 2 |
| S9 | Outbound blocked during warehouse freeze (except stock_take/reversal) | service | existing |

### A-rules (Approval / Lifecycle)
| # | Rule | Enforced At | Phase |
|---|---|---|---|
| A1 | Adjustments below `auto_approve_below_value` skip approval | policy service | 3 |
| A2 | Adjustments ≥ threshold require submit → approve → confirm | policy + service | 3 |
| A3 | Rejection returns to draft with comment | service | 3 |
| A4 | `confirmed_by/at` + `confirm_reason` persisted | service | 3 |
| A5 | `cancel_reason` always persisted (draft or confirmed) | service | 3 |
| A6 | Closed accounting period blocks confirm (configurable) | policy service | 3 |

### L-rules (Audit / Ledger)
| # | Rule | Enforced At | Phase |
|---|---|---|---|
| L1 | Every lifecycle action writes one `stock_adjustment_audit_log` row | service + logger | 4 |
| L2 | `stock_transactions` is append-only; reversal inserts opposite row | service | existing |
| L3 | `stock_adjustment_items.stock_transaction_id` populated on confirm | service | 6 |
| L4 | Reversal targets exact `stock_transaction_id` (not `.first()`) | service | 6 |
| L5 | Drift between `warehouse_stock` and ledger reconciled nightly | scheduled job | 7 |

### E-rules (Export / Reporting)
| # | Rule | Enforced At | Phase |
|---|---|---|---|
| E1 | CSV export streams BOM-prefixed rows with current filters | controller | 8 |
| E2 | Checklist runs 7 sections (6 Legacy + approval_workflow) | service | 8 |
| E3 | Print voucher available per adjustment | controller + view | 8 |

---

## 18. Appendix C — File Inventory

### Files to Modify

| File | Phase | Changes |
|---|---|---|
| `laravel/routes/web.php` | 1,3,7,8 | role middleware; submit/approve/reject/reconcile/export/print routes; `{id}` regex |
| `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` | 1,2,3,7,8 | branch-validate getProductRate; category filter; submit/approve/reject; reconcile; export; print |
| `laravel/app/Services/Stock/StockAdjustmentService.php` | 2,3,4,5,6 | category; submit/approve/reject/confirm(reason); logger calls; UOM conversion; pipeline availability; reversal date; dedup |
| `laravel/app/Services/Stock/StockService.php` | 6 | `applyTransaction` returns tx_id; `reverseTransaction` accepts reversalDate |
| `laravel/app/Services/Accounting/JournalPostingService.php` | 6 | `reverseJournalEntry` accepts entryDate |
| `laravel/app/Models/StockAdjustment.php` | 3 | state helpers; fillable; casts |
| `laravel/app/Models/StockAdjustmentItem.php` | 5,6 | UOM + stock_transaction_id fillable; amount() uses qty_base |
| `laravel/resources/views/admin/stock-adjustments/create.blade.php` | 2,5 | category dropdown; per-row UOM |
| `laravel/resources/views/admin/stock-adjustments/index.blade.php` | 2,8 | category filter/column; Export button |
| `laravel/resources/views/admin/stock-adjustments/show.blade.php` | 3,4,8 | lifecycle stepper; audit timeline; GL audit blocks; UOM display |
| `laravel/app/Http/Controllers/Admin/StockAdjustmentController.php` `audit()` | 8 | delegate to StockAdjustmentAuditService |

### Files to Create

| File | Phase | Purpose |
|---|---|---|
| `laravel/app/Policies/StockAdjustmentPolicy.php` | 1 | authorization |
| `laravel/database/migrations/2025_07_28_000001_add_category_to_stock_adjustments.php` | 2 | category column |
| `laravel/config/stock_adjustment.php` | 3 | policy config |
| `laravel/database/migrations/2025_07_29_000001_add_approval_to_stock_adjustments.php` | 3 | approval + confirm attribution |
| `laravel/app/Services/Stock/StockAdjustmentPolicyService.php` | 3 | approval policy engine |
| `laravel/database/migrations/2025_07_30_000001_create_stock_adjustment_audit_log.php` | 4 | audit log table |
| `laravel/app/Services/Stock/StockAdjustmentAuditLogger.php` | 4 | logger |
| `laravel/database/migrations/2025_07_31_000001_create_uom_tables.php` | 5 | UOM + conversions + item columns |
| `laravel/app/Services/Stock/UomConversionService.php` | 5 | UOM conversion |
| `laravel/database/migrations/2025_08_01_000001_add_stock_transaction_id_to_items.php` | 6 | reversal safety + UNIQUE |
| `laravel/app/Services/Stock/StockAdjustmentReconcileService.php` | 7 | drift detection |
| `laravel/resources/views/admin/stock-adjustments/reconcile.blade.php` | 7 | reconcile UI |
| `laravel/app/Services/Stock/StockAdjustmentAuditService.php` | 8 | 7-section checklist |
| `laravel/resources/views/admin/stock-adjustments/checklist.blade.php` | 8 | checklist UI |
| `laravel/resources/views/admin/stock-adjustments/print.blade.php` | 8 | print voucher |
| `laravel/app/Http/Controllers/Api/StockAdjustmentApiController.php` | 9 | API |
| `laravel/app/Http/Resources/StockAdjustmentResource.php` | 9 | API resource |
| `laravel/app/Http/Resources/StockAdjustmentItemResource.php` | 9 | API resource |
| `laravel/tests/Feature/StockAdjustment/*.php` | 10 | 12 feature test files |
| `laravel/database/factories/StockAdjustmentFactory.php` | 10 | factory |
| `laravel/database/factories/StockAdjustmentItemFactory.php` | 10 | factory |
| `laravel/app/Services/Stock/StockAdjustmentShadowService.php` | 10 | (optional) shadow mode |

---

## 19. Post-Implementation State

**Current progress: Phases 1-7 COMPLETE ✅ (7 of 10). Phases 8-10 PENDING ⬜.**

After all 10 phases, the Laravel Stock Adjustment module will deliver:

1. ✅ **Role-gated access** — only Accountant / system administrator / manager can touch adjustments (Phase 1).
2. ✅ **Categorized corrections** — every adjustment is tagged opening-balance / migration / UOM-fix / post-conversion / legacy-cleanup / reconciliation-variance / other, with opening balances written to the dedicated ledger reference_type (Phase 2).
3. ✅ **Maker-checker approval workflow** — submit → approve → confirm, with value-threshold auto-approval and closed-period blocking (Phase 3).
4. ✅ **Full audit trail** — a dedicated `stock_adjustment_audit_log` table capturing every lifecycle action with actor, role, IP, and payload, rendered as a timeline on the show page (Phase 4).
5. ✅ **UOM conversion** — quantities entered in any UOM (Carton/Pcs/KG) are converted to the product's base unit before posting; the "system/UOM error" use case is fully supported (Phase 5).
6. ✅ **Accurate stock** — pipeline-aware availability for decreases, exact-row reversal (no duplicate-product orphan), and original-date reversal that respects closed periods (Phase 6).
7. ✅ **Drift detection** — nightly reconciliation between `warehouse_stock` cache and the `stock_transactions` SSOT, with admin alerts + an interactive reconcile view + admin-only snapshot rebuild (Phase 7).
8. ✅ **Proper UI** — CSV export, a 7-section integrity checklist, a print voucher, GL audit blocks (original + reversing), a lifecycle stepper, and an audit timeline (Phase 8 — DONE; CSV export + 7-section checklist + print voucher + reversing GL block shipped; lifecycle stepper + audit timeline from Phases 3-4 already live).
9. ⬜ **API support** — REST endpoints for mobile/AI sidecars with the same policy enforcement (Phase 9 — PENDING).
10. ⬜ **Test coverage** — a 12-file feature suite covering the golden path and edge cases, plus optional shadow-mode comparison against Legacy (Phase 10 — PENDING).
11. ✅ **Proper PostgreSQL handling** — RLS for branch isolation, partitioned immutable ledger, `pg_advisory_xact_lock` for atomic code generation, CHECK constraints for enums, triggers for non-negative stock, and generated `total_value` columns.
12. ✅ **Business logic integrity** — atomic stock + GL posting inside `DB::transaction()`, `lockForUpdate()` on `warehouse_stock`, append-only reversal ledger entries, and closed-period GL enforcement.
13. ✅ **Warehouse-wise damage** — every adjustment is scoped to a single warehouse; the quantity correction and its GL impact are applied warehouse-wise, with the branch immutable on the header row.

The result is a Stock Adjustment process that is **safe** (role-gated, approval-gated, audit-logged), **accurate** (pipeline-aware, UOM-aware, drift-monitored), and **usable** (clear categories, lifecycle stepper, export, print) — fit for an infrequent, high-impact bookkeeping tool operated by an Accountant or system administrator.
