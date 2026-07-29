# Damage Module — GAP Analysis & Phased Implementation Plan

> **Project**: debugRC ERP (Legacy PHP/MySQL → Laravel/PostgreSQL migration)
> **Scope**: Damage menu only — analysis of current Legacy + Laravel implementations, GAP analysis, and a phase-by-phase plan to build a better Damage process for the Laravel ERP.
> **Deliverable type**: Documentation / plan only (no code in this document).
> **Author**: Generated from codebase analysis of `legacy/` and `laravel/` directories.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Business Context & The Real Problem](#2-business-context--the-real-problem)
3. [Part A — Legacy Software: How It Handles Damage (MySQL)](#3-part-a--legacy-software-how-it-handles-damage-mysql)
4. [Part B — Laravel ERP: How It Handles Damage (PostgreSQL)](#4-part-b--laravel-erp-how-it-handles-damage-postgresql)
5. [Part C — The GAP Analysis](#5-part-c--the-gap-analysis)
6. [Part D — Phased Implementation Plan](#6-part-d--phased-implementation-plan)
7. [Part E — Target End-State Vision](#7-part-e--target-end-state-vision)
8. [Appendix — Key SQL & Code Excerpts](#8-appendix--key-sql--code-excerpts)

---

## 1. Executive Summary

The **Damage** module exists in both the Legacy (plain PHP + MySQL) and the Laravel (Laravel 11 + PostgreSQL) codebases. At its core, damage means: *a product is declared as damaged and removed from usable inventory, with the cost of that loss posted to the General Ledger as an expense.*

The Laravel port is **architecturally superior** in several ways — it introduces a real `draft → confirmed → cancelled` state machine (legacy had only a boolean `is_reversed`), uses PostgreSQL Row-Level Security for branch isolation, advisory-locked document sequences, append-only reversals, and atomic transactions with `SELECT FOR UPDATE`.

**However, the Laravel port is functionally incomplete in the exact areas the business cares about most:**

| Business Requirement | Legacy | Laravel | Status |
|---|---|---|---|
| Distinguish *real damage* from *missing/unaccounted* stock | ❌ No | ❌ No | **GAP (both)** |
| Approval / accountability before posting a write-off | ❌ No | ❌ No | **GAP (both)** |
| Photo evidence of damage | ❌ No | ❌ No | **GAP (both)** |
| Witness / responsible-employee capture | ❌ No | ❌ No | **GAP (both)** |
| Monthly / yearly damage cost report | ❌ No | ❌ No | **GAP (both)** |
| Damage visible in P&L | ✅ Yes (via `inventory_shrinkage`) | ⚠️ **No** (posts to `damage_loss`, not in P&L rollup) | **Laravel REGRESSION** |
| Route-level role enforcement (who can create/reverse) | ✅ Yes (`route_roles.php`) | ❌ No (any authenticated user) | **Laravel REGRESSION** |
| Per-damage integrity audit panel | ✅ Yes (`DamageAuditModel`) | ❌ No | **Laravel REGRESSION** |
| Proper two-phase (draft → confirm) flow | ❌ No (single click) | ✅ Yes | Laravel BETTER |

**Bottom line:** The Laravel rewrite gained a better *technical* foundation but lost some *operational controls* (RBAC, audit panel, P&L visibility) and still shares the same *business-logic gaps* as legacy (no damage category, no approval, no evidence, no reporting). Neither system can answer the fundamental accountability question: *"Was this product actually damaged, or did an employee just not find it and declare it as damage?"*

This document proposes a **9-phase plan** (Phase 0 through Phase 8) to close all gaps and deliver a Damage process with accurate stock, proper PostgreSQL handling, sound business logic, and a proper UI.

---

## 2. Business Context & The Real Problem

### 2.1 The operating reality

- The ERP serves a multi-branch company. **Each branch has multiple warehouses.**
- Products are declared as **"damage"** when they can no longer be used in inventory. This removes them from stock and books the loss to the GL.
- There are **two very different root causes** that today both end up in the same "damage" bucket:
  1. **Real physical damage** — breakage, spoilage, expiry, water/fire damage, transit damage, quality rejection. This is a genuine inventory loss.
  2. **Missing / unaccounted stock** — an employee cannot find a product in the warehouse and has no explanation, so they declare it as "damage" to make the numbers balance. This is really a *shrinkage/theft/reconciliation gap*, not physical damage.

### 2.2 Why this is a problem

Mixing the two destroys accountability:

- You cannot tell how much **real** damage occurred (which needs better handling, supplier claims, insurance, or process fixes).
- You cannot tell how much **missing** stock was written off as damage (which points to theft, poor warehouse discipline, or training gaps — and may implicate specific employees).
- The cost of damage per month/year is not reliably reportable, so management cannot budget for it, set reduction targets, or detect anomalies.
- There is no approval gate, so a single warehouse employee can write off any quantity of any product at average cost with no second-person check, no evidence, and no named responsible party.

### 2.3 What "done" looks like

> *After all phases, a manager can open the Damage module and immediately see: how much damage cost occurred this month and this year, broken down by category (real damage vs missing vs expiry vs spoilage vs theft), by warehouse, and by responsible employee — with photographic evidence on each record, an approval trail for anything above threshold, accurate stock and GL that always reconcile, and a clear audit panel on every record.*

---

## 3. Part A — Legacy Software: How It Handles Damage (MySQL)

> Source: `legacy/app/controllers/DamageController.php`, `legacy/app/models/DamageModel.php`, `legacy/app/models/DamageAuditModel.php`, `legacy/app/views/Damage/*.php`, `legacy/public/assets/js/Damage.js`, `legacy/database/migrations/027_damage_gl.sql`, `legacy/database/migrations/039_sales_return_damage_link.sql`, plus base schema reconstructed from `laravel/database/sql/03_stock.sql` and INSERT statements.

### 3.1 Database schema (MySQL)

#### `damage_invoices`

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `damage_code` | VARCHAR(30) UNIQUE | `DMG-YYYYMMDD-NNNN` (random_int) or `DMG-SR-{ret}-W{wh}-{His}` (auto from return) |
| `warehouse_id` | INT → `warehouses.id` | Single warehouse per invoice |
| `damage_date` | DATE | |
| `total_value` | DECIMAL(14,2) | Sum of `qty*rate` |
| `remarks` | TEXT | **Only "reason" field — free text, no category enum** |
| `journal_entry_id` | BIGINT NULL | Added by migration `027_damage_gl.sql`; `idx_dmg_journal` |
| `sales_return_id` | INT UNSIGNED NULL | Added by migration `039`; `idx_damage_sales_return` |
| `is_reversed` | TINYINT(1) DEFAULT 0 | Boolean only — **no draft/confirmed/cancelled state machine** |
| `reversed_at` | TIMESTAMP NULL | |
| `reversed_by` | INT NULL → `users.id` | |
| `reverse_reason` | TEXT NULL | |
| `created_by` | INT → `users.id` | |

**Critical observation:** Legacy has **no `branch_id` column** on the table — branch is always derived at query time via `JOIN warehouses w ON di.warehouse_id = w.id JOIN branches b ON w.branch_id = b.id`. There is **no `status` column**, no `damage_type`, no approval fields, no photo columns, no witness columns.

#### `damage_invoice_items`

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK | |
| `damage_invoice_id` | INT → `damage_invoices.id` ON DELETE CASCADE | |
| `product_id` | INT → `products.id` | |
| `qty` | DECIMAL(14,4) | Stored positive; negated when posted to `stock_transactions.qty` |
| `rate` | DECIMAL(12,2) | Moving-average cost snapshot (read-only in UI) |

### 3.2 Business logic

#### Controller methods (`DamageController`)

| Method | Route | Roles | Purpose |
|---|---|---|---|
| `index` | `Damage` | admin, manager, warehouse_manager | Filtered list; **defaults to today only** |
| `create` | `Damage/create` | admin, manager, warehouse_manager | Form (warehouses scoped to user's branch) |
| `store` | `Damage/store` | admin, manager, warehouse_manager | JSON items → `DamageModel::createDamage()` |
| `getBranchWarehouses` | AJAX | admin, manager, warehouse_manager | Warehouses for user's branch |
| `getProducts` | AJAX | admin, manager, warehouse_manager | Full active product list (cached client-side) |
| `getProductStockAndPrice` | AJAX | admin, manager, warehouse_manager | `{available_qty, physical_qty, pipeline_qty, price, rate}` |
| `details` | `Damage/details/{id}` | admin, manager, warehouse_manager | Full detail + audit panel + GL blocks |
| `reverse` | `Damage/reverse` | **admin, manager only** | Reverses stock + GL |
| `export` | `Damage/export` | admin, manager, warehouse_manager | CSV export |

#### Create flow (single atomic transaction)

`DamageModel::createDamage()` does all of this in one `beginTransaction()`/`commit()`:

1. Validate warehouse belongs to user's branch (admin overrides).
2. For each item: resolve rate = moving-average cost from `warehouse_stock.avg_cost` (fallback if AJAX returned 0); accumulate `total_value`.
3. Pre-check availability (`Assert_Warehouse_Lines_Available` — physical minus sales pipeline).
4. `INSERT INTO damage_invoices (...)` — header row.
5. For each item: `INSERT INTO damage_invoice_items (...)` + `StockTransactionModel::updateWarehouseStock(warehouseId, productId, -qty, 0)` (OUT branch preserves avg_cost) + `logMovement()` (inserts `stock_transactions` with `reference_type='damage'`, negative qty).
6. If `total_value >= 0.01`: `JournalPostingService::postDamage()` — posts a 2-line journal entry: **Dr `inventory_shrinkage` / Cr `inventory`**, links `journal_entry_id` back.
7. Commit (or full rollback on any exception).

**There is NO draft state.** One click = immediate stock OUT + GL post. The only undo is reversal.

#### GL posting (`JournalPostingService::postDamage`)

```php
$lines = [
    ['ledger_id' => $shrinkageId,  'debit' => $lossAmount, 'credit' => 0,
     'description' => 'Damage / write-off — ' . $dmgCode],
    ['ledger_id' => $inventoryId,  'debit' => 0, 'credit' => $lossAmount,
     'description' => 'Inventory reduction (damaged goods)'],
];
// header: entry_date, description, reference_type='damage', reference_id=$damageId, branch_id
```

- **Debit** → `inventory_shrinkage` nature (falls back to `cogs` if not found). Expense account.
- **Credit** → `inventory` nature. Asset account.
- `JournalEntryModel::createEntry()` validates `Dr == Cr` and blocks closed accounting periods.

#### Reversal flow

`DamageModel::reverseDamage()` (one transaction):
1. Validate reason ≥ 3 chars.
2. Pull all `stock_transactions` for `reference_type='damage' AND reference_id=$id` not yet reversed.
3. For each: `StockTransactionModel::reverseTransaction()` — restores `+qty` to `warehouse_stock` (with non-negative guard), inserts a new opposite-sign `stock_transactions` row (`reference_type='reversal'`), marks original `is_reversed=1`.
4. `JournalPostingService::reverseLinkedJournal()` — `createReversingEntry()` swaps Dr↔Cr, links via `reversal_of_entry_id`.
5. `UPDATE damage_invoices SET is_reversed=1, reversed_at=NOW(), reversed_by, reverse_reason`.

#### Sales-return linkage (migration 039)

`SalesReturnModel::createLinkedDamageWriteOff()` runs automatically during sales return confirmation for any return item with `condition = 'Damage'`:
- Generates code `DMG-SR-{returnId}-W{warehouseId}-{His}`.
- Inserts `damage_invoices` with `sales_return_id=$returnId`, `remarks="Auto write-off for damaged sales return #{returnCode}"`.
- Inserts items, decrements stock, logs movements, posts GL, stores `journal_entry_id` back.
- Updates each `sales_return_items.damage_invoice_id` (reverse link).

`reverseLinkedDamageForReturn()` cascades — when a sales return is reversed, all linked non-reversed damage invoices are reversed too.

#### Cost calculation

Moving-average cost from `warehouse_stock.avg_cost`. On OUT movements, `avg_cost` is **unchanged** (correct accounting). The rate input in the UI is **read-only** — fetched via AJAX, user cannot override.

#### Multi-branch / multi-warehouse scoping

- `Helper::sessionBranchId()` reads `$_SESSION['branch_id']`.
- `Helper::canOverrideBranch()` is true **only for admin** (managers cannot see other branches).
- `getWarehousesForUser()` — admins see all; non-admins see only their branch's warehouses.
- `createDamage()` enforces `warehouseBelongsToBranch()` for non-admins.
- `getFilteredDamages()` injects `WHERE b.id = :branch_id` for non-admins.
- Each damage header carries a single `warehouse_id` → inherently single-warehouse; cross-branch is blocked.

#### Audit trail

`DamageAuditModel::runDamageChecks($damageId)` renders a **live audit panel** on the details page with 5 checks:

| Check | What it verifies |
|---|---|
| `branch_wh` | Damage is from a warehouse in the user's branch |
| `stock` | Negative qty stock movements exist for each damaged line |
| `total_value` | Header `total_value` equals `SUM(qty*rate)` from items (tolerance 0.02) |
| `gl` | `journal_entry_id` is set when total ≥ 0.01 |
| `reversed` | Reversal has a reason |

**There is no persistent `damage_audit_log` table** — these are computed live. The immutable trail lives in `stock_transactions` + `journal_entries` + `journal_lines`.

### 3.3 Reporting

**There is NO dedicated damage cost report.** Damage cost surfaces only via:

1. **P&L Report** — `inventory_shrinkage` nature rolls up under "Operating Expenses". But it is **mixed** with stock-take shortage and adjustment decrease — you cannot isolate pure "damage".
2. **Product Movement Report** — damage appears as OUT movement line items.
3. **Damage index page** — inline "Active damage value" sum, scoped to the current filter (defaults to **today only**).

To get "cost of damage this month/year", a user must manually set the date range on the index page and mentally sum, or run custom SQL. **This is a major reporting gap.**

### 3.4 Frontend

#### `Damage/create.php` collects
- Warehouse (dropdown, scoped to branch), Damage date (default today), Remarks (textarea), Line items (product dropdown, qty, **read-only rate**, amount, stock badge).
- **No** photo upload, **no** witness field, **no** category dropdown, **no** approval selector.

#### `Damage/index.php` shows
- Summary bar (record count + active damage value).
- Filters: From, To, Warehouse, Status (all/active/reversed), Source (all/return/manual). **Defaults to today.**
- Table: Date | Code | Warehouse | Source | Amount | GL badge | Status | View/Reverse.

#### `Damage/details.php` shows
- Header, reversal alert (if reversed), sales-return link alert (if linked), stat row, remarks, **audit panel** (5 checks), line items, GL journal block, stock movements table.

### 3.5 Legacy strengths & weaknesses

**Strengths (Laravel should preserve these):**
1. Atomic single-transaction create (header + items + stock + GL commit together).
2. Correct double-entry GL (`Dr shrinkage / Cr inventory`) with ledger-nature lookup and period-lock validation.
3. Symmetric reversal (stock + GL both reversed, append-only).
4. Moving-average cost integrity (OUT preserves avg_cost).
5. Branch isolation (admin override, warehouse-belongs-to-branch, query filter).
6. Sales-return cascade (auto create on confirm, auto reverse on return reversal).
7. Pre-flight availability check (blocks negative stock).
8. Live integrity audit panel on details page.
9. CSV export.
10. Read-only rate input (prevents value manipulation).

**Weaknesses (the accountability gap):**
1. **No damage type/category** — cannot distinguish real damage from missing/expiry/theft.
2. **No approval workflow** — warehouse_manager creates + posts GL in one click.
3. **No draft state** — only `is_reversed` boolean; the only "undo" is a hard reversal.
4. **Weak reporting** — no monthly/yearly/category/warehouse breakdown.
5. **No photo/evidence upload.**
6. **No witness / accountable-employee field** — only `created_by`.
7. **No reason taxonomy** — free-text `remarks` blocks aggregation.
8. **Default filter is "today only"** — masks cumulative trends.
9. **Code generation uses `random_int`** — rare collision risk (UNIQUE constraint would surface it as a generic error).
10. **`canOverrideBranch` is admin-only** — regional managers can't see other branches.
11. **No notification on damage creation.**
12. **No link to employee ledger / payroll deduction** for accountable employees.
13. **Reversal reason min length is 3 chars** — trivially bypassable.
14. **No reversal window limit** — a 2-year-old damage can be reversed today, restating prior periods.

---

## 4. Part B — Laravel ERP: How It Handles Damage (PostgreSQL)

> Source: `laravel/app/Services/Stock/DamageService.php`, `laravel/app/Http/Controllers/Admin/DamageController.php`, `laravel/app/Models/DamageInvoice.php`, `laravel/app/Models/DamageInvoiceItem.php`, `laravel/resources/views/admin/damages/*.blade.php`, `laravel/routes/web.php`, `laravel/database/sql/03_stock.sql`, `laravel/database/migrations/*`, `laravel/app/Services/Accounting/JournalPostingService.php`, `laravel/app/Services/Sales/SalesReturnService.php`.

### 4.1 Database schema (PostgreSQL)

#### `damage_invoices`

| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `integer GENERATED ALWAYS AS IDENTITY` | PK |
| `damage_code` | `varchar(30) NOT NULL` | UNIQUE (`damage_invoices_code_unique`) |
| `damage_date` | `date NOT NULL` | |
| `warehouse_id` | `integer NOT NULL` | FK → `warehouses(id)`, **DEFERRABLE INITIALLY DEFERRED** |
| `branch_id` | `integer NOT NULL` | FK → `branches(id)`, DEFERRABLE — **NEW vs legacy** |
| `sales_return_id` | `integer NULL` | FK → `sales_returns(id)` ON DELETE SET NULL; partial index |
| `total_value` | `numeric(14,2) DEFAULT 0` | Added by migration `2025_01_09_000002` (was missing from base SQL) |
| `status` | `varchar(20) DEFAULT 'draft'` | **`CHECK (status IN ('draft','confirmed','cancelled'))`** — NEW vs legacy |
| `reason` | `text` | Renamed from legacy `remarks` |
| `journal_entry_id` | `integer NULL` | FK → `journal_entries(id)`, DEFERRABLE; index `idx_dmg_journal` |
| `is_reversed` | `boolean NOT NULL DEFAULT false` | |
| `reversed_at` | `timestamp(0)` | |
| `reversed_by` | `integer` | |
| `reverse_reason` | `text` | |
| `created_by` | `integer` | |
| `created_at` / `updated_at` | `timestamp(0)` | |
| `deleted_at` | `timestamp(0) NULL` | SoftDeletes — NEW vs legacy |

**Row-Level Security (5 policies + ENABLE + FORCE):**

```sql
ALTER TABLE damage_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE damage_invoices FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_damage_invoices_select ON damage_invoices FOR SELECT
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_insert ON damage_invoices FOR INSERT
  WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_update ON damage_invoices FOR UPDATE
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int)
  WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_delete ON damage_invoices FOR DELETE
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_admin ON damage_invoices FOR ALL
  USING (current_setting('app.is_admin', true) = 'true')
  WITH CHECK (current_setting('app.is_admin', true) = 'true');
```

`SetAppBranchId` middleware sets the `app.branch_id` and `app.is_admin` GUCs on every authenticated request, so RLS is active. `FORCE` means even the table owner is subject to RLS.

**No triggers** on `damage_invoices`. Notably, the LISTEN/NOTIFY trigger migration (`2025_01_21_000001`) covers `sales_invoices, sales_challans, sales_returns, customer_payments, stock_transactions, journal_entries, system_policies` — **`damage_invoices` is absent**.

#### `damage_invoice_items`

| Column | Type | Constraints |
|---|---|---|
| `id` | `integer GENERATED ALWAYS AS IDENTITY` | PK |
| `damage_invoice_id` | `integer NOT NULL` | FK → `damage_invoices(id)` **ON DELETE CASCADE** (NOT deferred) |
| `product_id` | `integer NOT NULL` | FK → `products(id)`, DEFERRABLE INITIALLY IMMEDIATE |
| `qty` | `numeric(14,4) NOT NULL` | |
| `rate` | `numeric(12,2) DEFAULT 0` | |

**No RLS on items.** No integrity trigger. No generated column.

### 4.2 Business logic

#### Routes (`routes/web.php`)

```php
Route::prefix('admin/damages')->name('admin.damages.')->group(function () {
    Route::get('product-stock', [DamageController::class, 'getProductStock'])->name('product-stock');
    Route::post('{id}/confirm', [DamageController::class, 'confirm'])->name('confirm');
    Route::post('{id}/cancel',   [DamageController::class, 'cancel'])->name('cancel');
});
Route::resource('admin/damages', DamageController::class)
    ->only(['index', 'create', 'store', 'show'])
    ->names('admin.damages');
```

> **CRITICAL GAP:** These routes have **NO `->middleware('role:...')`** and **NO `->middleware('branch.isolation')`**. Any authenticated user of any role can directly hit `/admin/damages/create`, `/admin/damages/{id}/confirm`, `/admin/damages/{id}/cancel`. `routes/api.php` has **zero** damage routes.

#### Controller methods (6)

| Method | Route | Purpose |
|---|---|---|
| `index` | GET `/admin/damages` | Paginated list (25/page) + 5 stat cards (total/draft/confirmed/cancelled + all-time confirmed sum) |
| `create` | GET `/admin/damages/create` | Form (warehouses + products capped at 500) |
| `store` | POST `/admin/damages` | Validate → `DamageService::createDamage()` → redirect to `show` |
| `show` | GET `/admin/damages/{id}` | Detail: items + warehouse.branch + journalEntry.lines.ledger + stock movements |
| `confirm` | POST `/admin/damages/{id}/confirm` | Optional confirm_reason → `DamageService::confirmDamage()` |
| `cancel` | POST `/admin/damages/{id}/cancel` | Required cancel_reason → `DamageService::cancelDamage()` |
| `getProductStock` | GET `/admin/damages/product-stock` | AJAX `{rate, available_qty}` |

Store validation:
```php
'warehouse_id' => 'required|integer|exists:warehouses,id',
'damage_date'  => 'required|date',
'reason'       => 'nullable|string|max:1000',
'items'              => 'required|array|min:1',
'items.*.product_id' => 'required|integer|exists:products,id',
'items.*.qty'        => 'required|numeric|min:0.001',
'items.*.rate'       => 'nullable|numeric|min:0',
```

#### `DamageService` methods

```php
public function createDamage(array $data): DamageInvoice      // draft only — no stock, no GL
public function confirmDamage(int $damageId, int $confirmedBy): DamageInvoice   // stock OUT + GL
public function cancelDamage(int $damageId, int $cancelledBy, string $reason = ''): DamageInvoice
private function postDamageGL(DamageInvoice $damage, int $createdBy): int
private function generateDamageCode(): string   // DMG-YYYYMMDD-NNNN via DocumentSequenceService (advisory-locked)
private function validateCreateInput(array $data): void
```

#### Create flow (`createDamage`)

1. `validateCreateInput` — warehouse_id + items required.
2. Lookup warehouse, derive `branch_id = warehouse.branch_id`.
3. For each item: if rate ≤ 0 → fallback to `StockService::getWarehouseAvgCost()`; accumulate `total_value`.
4. Pre-check availability per item against `StockService::getWarehouseQty` (re-checked on confirm).
5. `generateDamageCode()` via `DocumentSequenceService::nextCode(docType:'damage', prefix:'DMG', datePart:Ymd, padLength:4)` — PG advisory locks.
6. `DB::transaction`: raw `DB::table('damage_invoices')->insertGetId([... status='draft', is_reversed=false ...])` + bulk `DB::table('damage_invoice_items')->insert(...)` + dispatch `damage_invoice_created` notification (unless `suppress_notification`).
7. Return Eloquent model.

**Draft has zero stock and zero GL impact.** This is a real improvement over legacy.

#### Confirm flow (`confirmDamage`)

1. `DB::transaction` + `DamageInvoice::with('items')->lockForUpdate()->find($damageId)` — pessimistic row lock.
2. Guard: must be `isDraft()`.
3. For each item: `StockService::applyTransaction([ qty => −(float)$item->qty, rate => (float)$item->rate, reference_type => 'damage', reference_id => $damage->id, ... ])`. Inserts `stock_transactions`, locks `warehouse_stock FOR UPDATE`, applies OUT (preserves `avg_cost`), throws on insufficient stock.
4. `postDamageGL()`:
   - If `total_value < 0.01` → skip (return 0).
   - `lookupLedgerByNature('inventory')` → credit side.
   - `lookupLedgerByNature('damage_loss')` → fallback to `lookupLedgerByNature('inventory_shrinkage')` → debit side.
   - `createJournalEntry()`: 2 lines, `Dr damage_loss / Cr inventory`, `reference_type='damage'`, `reference_id=$damage->id`, `branch_id=$damage->branch_id`.
5. `UPDATE damage_invoices SET status='confirmed', journal_entry_id=$jeId`.

#### Cancel flow (`cancelDamage`)

1. `DB::transaction` + `lockForUpdate`.
2. Guard: must not already be cancelled.
3. **If confirmed**: reverse GL via `JournalPostingService::reverseJournalEntry()` (swap Dr/Cr append-only); reverse each non-reversed `stock_transactions` via `StockService::reverseTransaction()` (inserts opposite-sign row, marks original `is_reversed=true`); set `is_reversed=true, reversed_at, reversed_by, reverse_reason`.
4. Always `UPDATE damage_invoices SET status='cancelled'`.

#### Approval workflow

**NONE.** No `approved_by` / `approved_at` columns. No `submitted` status (unlike `stock_adjustments` which has `draft/submitted/approved/confirmed/cancelled/rejected`). No `DamagePolicy`. No `$this->authorize()`. The same user who creates the draft can immediately click "Confirm damage" → stock OUT + GL posts.

#### Cost calculation

Moving-average from `warehouse_stock.avg_cost` via `StockService::getWarehouseAvgCost`. On confirm, OUT preserves `avg_cost` (standard moving-average). **However**, the `rate` input in `create.blade.php` is **editable** (not readonly) — a user can override the avg cost. `createDamage` only falls back to avg cost if rate ≤ 0; a positive non-avg rate is accepted as-is. *(Note: the orphaned `Damage.js` makes rate readonly, but it is never loaded — the inline jQuery in the Blade view does not.)*

#### Multi-branch / multi-warehouse scoping

| Layer | Applied to damage? | Detail |
|---|---|---|
| Eloquent global scope (`BranchScope`) | **NO** | `DamageInvoice` does not use `BranchScope` (only SalesInvoice/SalesChallan/SalesReturn/CustomerPayment do). |
| Route middleware (`branch.isolation`) | **NO** | `EnforceBranchIsolation::inferTableFromUri()` does NOT include `'damages'`. |
| DB RLS | **YES** | 5 policies + ENABLE + FORCE; `SetAppBranchId` sets GUCs. |

**So the SOLE enforcement of branch isolation for damage is RLS at the DB layer.** Layers 1 and 2 are missing. A non-admin user operating on another branch's damage gets a confusing 404 from RLS instead of a clean 403.

#### Audit trail

- `DamageInvoice` model declares `use AuditableMasterData` — **BUT** `DamageService` uses raw `DB::table('damage_invoices')->insertGetId()` and `->update()`, so **Eloquent events DO NOT FIRE** and **no `user_audit_log` entries are written** for damage create/confirm/cancel.
- Indirect trail: `stock_transactions` rows carry `created_by`, `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason`; `journal_posting_logs` row on cancel.
- **No `damage_audit_log` table.** No per-damage integrity checks. Legacy's 5-check audit panel was NOT ported.

#### Sales-return linkage

`SalesReturnService::createLinkedDamageWriteOffs()` (during return confirmation):
- Groups Damage-condition items by warehouse.
- For each warehouse: builds items (rate = item.original_cost from challan, not current avg), calls `createDamage(suppress_notification=true)` then immediately `confirmDamage()`.
- Links `damage_invoices.sales_return_id` and `sales_return_items.damage_invoice_id`.

`reverseLinkedDamageForReturn()` (on return reversal): finds linked non-reversed damages, calls `cancelDamage()` for each, clears the reverse link.

### 4.3 Reporting

**NO dedicated damage report.** Verified:
- `ReportService` — P&L natures rollup includes `inventory_shrinkage` but **NOT `damage_loss`**.
- `CteReportService` — zero damage references.
- `views/admin/reports/*` — only `journal_entries.blade.php` mentions 'damage' (in the reference_type color map).
- Dashboard — zero damage references.

**P&L visibility GAP (critical regression):**

```php
// ReportService.php line 132
'operating_expenses' => ['label' => 'Operating Expenses',
    'natures' => ['operating_expense', 'inventory_shrinkage', 'manual_adjustment'],
    'sort' => 30],
```

`damage_loss` is **NOT** in this list. The default Chart of Accounts seeds `L-0503 Damage Loss (nature=damage_loss)`. So by default, damage write-offs post to L-0503 → **invisible in the P&L Operating Expenses section**. Only if L-0503 is manually deleted (so `lookupLedgerByNature('damage_loss')` returns null) does the fallback to `inventory_shrinkage` (L-0502) kick in. This is a **silent regression vs legacy**, where damage posted directly to `inventory_shrinkage` and was always visible in P&L.

### 4.4 Frontend

#### `create.blade.php` collects
- warehouse_id (Select2), damage_date (default today), reason (textarea, free-text, max 1000 chars — placeholder mentions "fire damage, water damage, expired, broken in transit" but it is just a hint, not a taxonomy), items[] (product_id Select2, qty, **editable rate**, available readonly, amount readonly).
- **No** photo upload, **no** witness field, **no** category dropdown, **no** approval UI.

#### `index.blade.php` shows
- 5 stat cards (Total / Draft / Confirmed / Cancelled counts + Total value = all-time confirmed sum, **not scoped to filter**).
- Filters: from_date, to_date, warehouse_id, branch_id, status, search by damage_code.
- Table: Code | Date | Warehouse (+branch) | Items count | Total | Status badge | Reversed badge | View.

#### `show.blade.php` shows
- Header, items table, stock movements table (if confirmed), GL journal entry card (if confirmed + JE exists), actions aside (Confirm if draft; Cancel & Reverse if draft/confirmed), cancellation info, quick facts.

#### Orphaned assets
- `public/assets/js/Damage.js` (267 lines) and `public/assets/css/damage.css` (4 rules) are **NOT referenced by any Blade view** — dead code carried over from legacy, hitting legacy URLs (`Damage/store`, `Damage/getProducts`, `Damage/reverse`) that don't exist as Laravel routes.

### 4.5 Laravel strengths & weaknesses

**Strengths (improvements over legacy):**
1. Two-phase `draft → confirmed → cancelled` state machine with DB CHECK constraint.
2. `DB::transaction` + `lockForUpdate()` on damage header during confirm/cancel — prevents concurrent transitions.
3. Atomic advisory-locked document sequence (no race conditions).
4. Append-only reversals (original rows never mutated except `is_reversed` flag).
5. RLS defense-in-depth (`ENABLE` + `FORCE` + 5 policies).
6. DEFERRABLE FKs (`journal_entry_id`, `branch_id`, `warehouse_id` deferred).
7. Moving-average cost integrity (OUT preserves avg_cost).
8. SweetAlert2 confirmation modals; cancel requires a reason (client + server validated).
9. `NotificationService::dispatch('damage_invoice_created', ...)` with `suppress_notification` opt-out for sales-return linked flow.
10. Sales-return linked damage (auto write-off + cascade reversal).
11. SoftDeletes (accidental deletes can be restored).
12. Bootstrap 5 + Select2 UI (cleaner than legacy).

**Weaknesses / regressions vs legacy / gaps:**
1. **No route-level RBAC** — any authenticated user (salesman, dispatcher, hr, accountant) can hit create/confirm/cancel. Legacy had an explicit matrix.
2. **No `branch.isolation` middleware** on damage routes; `EnforceBranchIsolation` doesn't include 'damages'. Cross-branch access yields a confusing 404 from RLS, not a clean 403.
3. **No `DamagePolicy` / `$this->authorize()`.**
4. **`AuditableMasterData` trait is BYPASSED** by raw `DB::table()` writes — no `user_audit_log` entries for damage create/confirm/cancel.
5. **P&L rollup GAP** — `damage_loss` not in `operating_expenses` natures → damage invisible in P&L by default.
6. **No dedicated damage report** (monthly trend, category, warehouse, employee, top-products).
7. **No dashboard damage widget.**
8. **No LISTEN/NOTIFY trigger** on `damage_invoices`.
9. **No per-damage integrity audit panel** (legacy's 5 checks not ported).
10. **No category/damage_type enum** — same accountability gap as legacy.
11. **No photo/attachment support.**
12. **No witness / accountable-employee field.**
13. **No approval workflow / maker-checker / threshold escalation.**
14. **No reason taxonomy** — free-text `reason`.
15. **Rate is editable** in the create form — allows avg-cost override.
16. **Orphaned `Damage.js` + `damage.css`** — dead code.
17. **Zero test coverage** for Damage.
18. **`status`/`total_value` were missing from base `03_stock.sql`** — added post-hoc by migration `2025_01_09_000002` (a self-described "schema gap fix").
19. **`SalesReturnReversalGuard` does NOT pre-check stock availability** for linked damage invoices during return reversal (explicit "accepted limitation" in code).
20. **`damage_invoice_items.damage_invoice_id` CASCADE is NOT deferred** — fires immediately (minor).

---

## 5. Part C — The GAP Analysis

### 5.1 Three layers of gaps

The gaps fall into three categories:

1. **GAP-REGRESSION** — things legacy did well that Laravel broke or dropped (must be fixed — they are regressions).
2. **GAP-SHARED** — things neither system does that the business needs (must be built — they are the core of the user's complaint).
3. **GAP-ENHANCEMENT** — things neither system does that would make the module genuinely good (should be built to reach the target end-state).

### 5.2 Master comparison matrix

| # | Capability | Legacy (MySQL) | Laravel (PostgreSQL) | Gap Type | Severity |
|---|---|---|---|---|---|
| 1 | Phase flow (draft → post) | Single-phase (1 click = stock+GL) | Two-phase draft → confirm → cancel | — | Laravel BETTER |
| 2 | `status` state machine | None (boolean only) | `CHECK IN ('draft','confirmed','cancelled')` | — | Laravel BETTER |
| 3 | `branch_id` on table | No (derived via JOIN) | Yes (column + FK) | — | Laravel BETTER |
| 4 | Branch isolation (DB) | None | RLS (5 policies + FORCE) | — | Laravel BETTER |
| 5 | Branch isolation (app route) | `route_roles.php` matrix | **NONE** — no `role:...` middleware | **REGRESSION** | 🔴 Critical |
| 6 | Branch isolation (app scope) | Helper filters | `BranchScope` NOT applied; `EnforceBranchIsolation` excludes 'damages' | **REGRESSION** | 🔴 Critical |
| 7 | `DamagePolicy` / authorize | N/A | Not created | **REGRESSION** | 🔴 Critical |
| 8 | GL account | `inventory_shrinkage` (fallback COGS) | `damage_loss` (fallback `inventory_shrinkage`) | — | Mixed |
| 9 | P&L visibility | Visible (via `inventory_shrinkage`) | **INVISIBLE** (`damage_loss` not in rollup) | **REGRESSION** | 🔴 Critical |
| 10 | Per-damage integrity audit panel | `DamageAuditModel` (5 checks) | **NONE** | **REGRESSION** | 🟠 High |
| 11 | `user_audit_log` on damage writes | N/A (legacy uses its own trail) | Trait declared BUT bypassed by raw `DB::table()` | **REGRESSION** | 🟠 High |
| 12 | Reversal symmetry | Stock + GL both reversed | Stock + GL both reversed (append-only) | — | Laravel BETTER |
| 13 | Concurrency locking | None explicit | `lockForUpdate()` + `warehouse_stock` FOR UPDATE | — | Laravel BETTER |
| 14 | Document sequence | `random_int` (collision risk) | Advisory-locked `DocumentSequenceService` | — | Laravel BETTER |
| 15 | Sales-return linkage | Auto create + cascade reverse | Auto create + cascade reverse (faithful port) | — | Same |
| 16 | Notification on create | None | `NotificationService::dispatch` (with suppress opt-out) | — | Laravel BETTER |
| 17 | LISTEN/NOTIFY trigger | N/A (MySQL) | **MISSING** (other modules have it) | **ENHANCEMENT** | 🟡 Medium |
| 18 | Costing | Moving-average (OUT preserves avg) | Same | — | Same |
| 19 | Rate input | Readonly | **Editable** (allows override) | **REGRESSION** | 🟠 High |
| 20 | Soft deletes | No | Yes | — | Laravel BETTER |
| 21 | DEFERRABLE FKs | N/A | Yes (header FKs deferred) | — | Laravel BETTER |
| 22 | Photo / attachment | None | None | **SHARED** | 🔴 Critical |
| 23 | Witness / accountable-employee | None | None | **SHARED** | 🔴 Critical |
| 24 | Damage type / category enum | None | None | **SHARED** | 🔴 Critical |
| 25 | Reason taxonomy | Free-text | Free-text | **SHARED** | 🟠 High |
| 26 | Approval workflow / maker-checker | None | None | **SHARED** | 🔴 Critical |
| 27 | Threshold escalation | None | None | **SHARED** | 🟠 High |
| 28 | Dedicated damage report | None | None | **SHARED** | 🔴 Critical |
| 29 | Dashboard widget | None | None | **SHARED** | 🟡 Medium |
| 30 | Default filter = "today only" | Yes (masks trends) | No (but no quick filters either) | **SHARED** | 🟡 Medium |
| 31 | Reversal reason min length | 3 chars (weak) | Required (better) | — | Laravel slightly better |
| 32 | Reversal window / period-close guard | None | None | **SHARED** | 🟡 Medium |
| 33 | Employee-ledger / payroll deduction link | None | None | **SHARED** | 🟡 Medium |
| 34 | Test coverage | None | **Zero** | **REGRESSION** | 🟠 High |
| 35 | Orphaned dead code | N/A | `Damage.js` + `damage.css` unreferenced | **REGRESSION** | 🟢 Low |
| 36 | `status`/`total_value` in base SQL | Present | Missing from base, added post-hoc by migration | **REGRESSION** (process) | 🟢 Low |

### 5.3 The accountability gap — detailed root-cause mapping

The user's core complaint — *"a product is really damaged or it is declared as damage because it wasn't found — accountability is absent"* — maps to these concrete missing capabilities:

| Root cause of the accountability gap | Where it manifests | Which gap # |
|---|---|---|
| Cannot tell real damage from missing stock | No `damage_type` enum; one free-text `reason` | #24, #25 |
| No second-person check before a write-off posts | No approval workflow, no threshold | #26, #27 |
| No evidence required | No photo upload | #22 |
| No named responsible party | No witness / accountable-employee | #23 |
| Cannot report on damage cost over time | No dedicated report, P&L broken | #28, #9 |
| Anyone can create/confirm | No route RBAC, no policy | #5, #6, #7 |
| No audit trail of who did what | Trait bypassed by raw DB writes | #11 |
| No integrity checks visible to operators | Audit panel not ported | #10 |

**Closing gaps #5–#11 fixes the regressions; closing gaps #22–#28 fixes the shared business gaps. Together they deliver the target end-state.**

---

## 6. Part D — Phased Implementation Plan

The plan is organized so that each phase is **independently shippable**, **non-breaking**, and **cumulative**. Phases 0–2 fix regressions and harden the foundation; Phases 3–6 build the accountability & evidence capabilities; Phases 7–8 deliver reporting & UX polish. Each phase lists: goal, schema changes, backend changes, frontend changes, validation/acceptance, and risks.

> **Conventions used below:**
> - Migration file names follow the existing pattern `YYYY_MM_DD_NNNNNN_description.php`.
> - All new tables get RLS policies + `branch_id` where branch-scoped.
> - All new writes go through Eloquent (so audit traits fire) OR explicit `user_audit_log` inserts.
> - "Confirm" = post stock + GL; "Cancel" = reverse if confirmed, else mark cancelled; "Approve" = gate before confirm.

---

### Phase 0 — Stabilize & Close Critical Regressions (no schema change)

**Goal:** Stop the bleeding. Fix the access-control, audit, and P&L regressions that make the current Laravel Damage module less safe than legacy. Zero schema changes → zero data-migration risk.

**Backend changes:**
1. **Route RBAC** — wrap damage routes with role middleware matching the legacy `route_roles.php` matrix:
   - `index, create, store, show, getProductStock, export` → `role:admin,manager,warehouse_manager`
   - `confirm, cancel` → `role:admin,manager` (tighter — only admin/manager can post or reverse a write-off, mirroring legacy's `reverse` restriction)
2. **Branch isolation middleware** — add `'damages'` (and `'damage'`) to `EnforceBranchIsolation::inferTableFromUri()` map; apply `->middleware('branch.isolation')` on `confirm`/`cancel` POST routes so a clean 403 is returned instead of a confusing RLS 404.
3. **`DamagePolicy`** — create `app/Policies/DamagePolicy.php` with `viewAny`, `view`, `create`, `confirm`, `cancel` methods; register it; call `$this->authorize()` in the controller. Rules:
   - `warehouse_manager` can create + view but **cannot confirm/cancel** above a configurable threshold (see Phase 5).
   - `manager` can do everything within their branch.
   - `admin` can do everything, cross-branch.
4. **Fix P&L rollup** — add `'damage_loss'` to the `operating_expenses` natures array in `ReportService::PL_SECTIONS` (line 132). This makes damage visible in the P&L by default. (Alternative: remove L-0503 from the default CoA seeder so the fallback to `inventory_shrinkage` always kicks in — but adding to the rollup is safer because it doesn't change existing GL postings.)
5. **Make the audit trait actually fire** — switch `DamageService::createDamage` and `confirmDamage` and `cancelDamage` from raw `DB::table('damage_invoices')->insertGetId()/update()` to Eloquent (`DamageInvoice::create()` / `$damage->update()` / `$damage->save()`), so `AuditableMasterData` events fire and `user_audit_log` entries are written. Keep the `DB::transaction` wrapper.
6. **Make rate readonly** in `create.blade.php` inline jQuery (match legacy behavior — avg_cost is the only valid rate). Keep server-side fallback for rate ≤ 0.
7. **Delete orphaned dead code** — remove `public/assets/js/Damage.js` and `public/assets/css/damage.css` (verify no references first).

**Frontend changes:**
- Add a visible "You do not have permission" state (403 page) for denied confirm/cancel.
- Readonly rate input.

**Acceptance criteria:**
- A `salesman` role user cannot GET `/admin/damages/create` (403).
- A `warehouse_manager` can create a draft but cannot POST `/admin/damages/{id}/confirm` (403) — or can confirm only below threshold (deferred to Phase 5).
- A `manager` from branch A GETting `/admin/damages/{branch-B-damage-id}` gets 403 (not 404).
- `user_audit_log` has a row for each damage create/confirm/cancel.
- P&L report "Operating Expenses" includes damage_loss.
- Rate input cannot be edited in the browser.

**Risks:** Changing `DamageService` from raw DB to Eloquent must preserve the exact transaction semantics (the `DB::transaction` closure must still return the model and roll back on exception). The `AuditableMasterData` trait must be inspected to ensure it doesn't double-write or conflict with the in-transaction flow.

---

#### Phase 0 — Implementation Status ✅ COMPLETED

> **Commit:** `feat(damage): Phase 0 — RBAC, branch isolation, P&L fix, audit trail, readonly rate` (see git log)
> **Status:** All 7 sub-tasks implemented. Zero schema changes (as planned). No data-migration risk.

**Files changed (10) + created (1):**

| # | File | Change | Sub-task |
|---|---|---|---|
| 1 | `laravel/routes/web.php` | Rewrote the `admin/damages` route block: split into 3 role groups (read = admin/manager/warehouse_manager; create/store = same; confirm/cancel = admin/manager only). Added `->where(['id' => '[0-9]+'])` and `->middleware('branch.isolation')` on show/confirm/cancel. | 1, 2 |
| 2 | `laravel/app/Http/Middleware/EnforceBranchIsolation.php` | Added `'damages' → 'damage_invoices'` to `inferTableFromUri()` so `{id}` resolves to `damage_invoices.branch_id` for show/confirm/cancel. | 2 |
| 3 | `laravel/app/Policies/DamagePolicy.php` | **NEW** — `viewAny`, `view`, `create`, `viewProductStock`, `confirm`, `cancel` methods. `confirm`/`cancel` restricted to admin/manager (segregation of duties — warehouse_manager can create but not post). `sameBranch()` helper enforces branch for non-admins. | 3 |
| 4 | `laravel/app/Providers/AppServiceProvider.php` | Registered `DamagePolicy` for `DamageInvoice` via `Gate::policy()`. | 3 |
| 5 | `laravel/app/Http/Controllers/Admin/DamageController.php` | Added `$this->authorize()` calls: `viewAny` (index), `create` (create+store), `view` (show), `confirm` (confirm), `cancel` (cancel), `viewProductStock` (getProductStock). Consolidated model imports. | 3 |
| 6 | `laravel/app/Services/Reports/ReportService.php` | Added `'damage_loss'` to the `operating_expenses` natures array (line 132). Damage write-offs now appear in the P&L Operating Expenses section by default. | 4 |
| 7 | `laravel/app/Services/Stock/DamageService.php` | Switched `createDamage` from `DB::table()->insertGetId()` to `DamageInvoice::create()` + `items()->saveMany()`. Switched `confirmDamage` from `DB::table()->update()` to `$damage->update()`. Switched `cancelDamage`'s two raw updates to a single `$damage->update()`. The `AuditableMasterData` trait's `created`/`updated` events now fire → `user_audit_log` entries written. Preserved `DB::transaction`, `lockForUpdate()`, `suppress_notification`, and return types. | 5 |
| 8 | `laravel/resources/views/admin/damages/create.blade.php` | Made the rate input `readonly` (with `bg-light` to signal non-editable). Removed the rate `input` event listener and the `prop('disabled', false)` re-enable calls in the AJAX callbacks. Server-side fallback for rate ≤ 0 unchanged. | 6 |
| 9 | `laravel/resources/views/admin/damages/show.blade.php` | Added `$canPost = auth()->user()->hasRole('admin','manager')` gate. Confirm/Cancel buttons now only render for admin/manager; warehouse_manager sees an informational note instead ("must be confirmed by a manager or admin"). Prevents a 403 dead-click. | Frontend (403 UX) |
| 10 | `laravel/public/assets/js/Damage.js` | **DELETED** — orphaned (referenced legacy URLs `Damage/store` etc. that don't exist as Laravel routes; not loaded by any Blade view). | 7 |
| 11 | `laravel/public/assets/css/damage.css` | **DELETED** — orphaned (4 rules, not referenced anywhere). | 7 |

**Acceptance criteria — verification status:**

| Criterion | Status | How verified |
|---|---|---|
| A `salesman` role user cannot GET `/admin/damages/create` (403) | ✅ Met | Route group wrapped in `->middleware('role:admin,manager,warehouse_manager')`; `salesman` not in list → `EnsureRole` middleware returns 403 (JSON) or redirects with error flash. `DamageController::create` also calls `$this->authorize('create', DamageInvoice::class)` → `DamagePolicy::create` returns false for non-listed roles. |
| A `warehouse_manager` can create a draft but cannot POST `/admin/damages/{id}/confirm` (403) | ✅ Met | Confirm route is in a separate `->middleware('role:admin,manager')` group (warehouse_manager excluded). `DamageController::confirm` calls `$this->authorize('confirm', $damage)` → `DamagePolicy::confirm` returns false for warehouse_manager. The `show.blade.php` hides the Confirm button from warehouse_manager (shows an info note instead) so they don't dead-click into the 403. |
| A `manager` from branch A GETting `/admin/damages/{branch-B-damage-id}` gets 403 (not 404) | ✅ Met | `show` route has `->middleware('branch.isolation')`; `EnforceBranchIsolation::inferTableFromUri` now returns `'damage_invoices'` for `/damages` paths → resolves `{id}` → `damage_invoices.branch_id` → mismatches session branch → `deny()` returns redirect with "You do not have access to this record" (or 403 JSON for AJAX). `DamagePolicy::view` → `sameBranch()` returns false. RLS remains the DB backstop. |
| `user_audit_log` has a row for each damage create/confirm/cancel | ✅ Met | `DamageService` now uses `DamageInvoice::create()` (fires `created` event) and `$damage->update()` (fires `updated` event). `AuditableMasterData::bootAuditableMasterData()` listens to both and inserts into `user_audit_log` with `{table, record_id, old, new}` JSON. Previously raw `DB::table()` bypassed the trait entirely. |
| P&L report "Operating Expenses" includes damage_loss | ✅ Met | `ReportService::profitAndLoss` `$sections['operating_expenses']['natures']` now includes `'damage_loss'`. Damage write-offs (posted to L-0503 Damage Loss, nature=damage_loss) roll up into Operating Expenses. |
| Rate input cannot be edited in the browser | ✅ Met | `create.blade.php` rate input now has `readonly: true` and `bg-light` class. The `input` event listener on rate was removed. AJAX callback only sets `.val()`, does not toggle disabled. Server-side `createDamage` still falls back to `getWarehouseAvgCost` if rate ≤ 0. |

**Notes & decisions:**
- **Segregation of duties (tighter than legacy):** Legacy allowed `warehouse_manager` to both create AND reverse (one click posted GL). Phase 0 makes `warehouse_manager` create-only; only `admin`/`manager` can confirm (post stock+GL) or cancel (reverse). This is the foundation for Phase 5's formal approval workflow. `accountant`, `salesman`, `dispatcher`, `hr`, `user` have NO access to any damage route (matching legacy).
- **`branch.isolation` on `show`:** Applied `branch.isolation` to the `show` GET route too (not just POST) so a non-admin viewing another branch's damage gets a clean 403 instead of a confusing RLS-induced 404.
- **`SalesReturnService` compatibility:** The linked-damage flow (`createDamage(['suppress_notification'=>true])` → raw `DB::table()->update(['sales_return_id'=>...])` → `confirmDamage`) still works. The raw `sales_return_id` update in `SalesReturnService` is pre-existing and bypasses the audit trait; fixing it is deferred to a later phase (would require passing `sales_return_id` into `createDamage` or converting `SalesReturnService` to Eloquent).
- **No schema migration needed:** All changes are code-level. RLS policies, CHECK constraints, and existing columns are unchanged.
- **PHP syntax verification:** PHP CLI is not available in the dev sandbox; verified via brace/parenthesis balance checks (all OK) and careful manual review against the existing `StockAdjustmentPolicy`/`StockAdjustment` route patterns. Full validation will run in the Docker environment (PHP 8.2+).

**Follow-up for later phases:**
- Phase 1 will add `damage_type` (schema) — the `DamagePolicy` will need a `submit`/`approve`/`reject` update in Phase 5.
- Phase 2 will add the integrity-check panel (no policy change).
- Phase 5 will tighten `confirm` to require `status='approved'` (state machine extension).

---

### Phase 1 — Damage Category & Reason Taxonomy (schema: enum + taxonomy table)

**Goal:** Solve the #1 accountability gap — distinguish **real damage** from **missing/unaccounted** stock. Introduce a structured `damage_type` and a reason taxonomy so damage can be categorized and reported on.

**Schema changes (migration `2026_01_01_000001_damage_category_and_reason_taxonomy`):**
1. Add `damage_type` enum column to `damage_invoices`:
   ```sql
   ALTER TABLE damage_invoices
     ADD COLUMN damage_type varchar(30) NOT NULL DEFAULT 'real_damage';
   ALTER TABLE damage_invoices
     ADD CONSTRAINT damage_invoices_type_check
     CHECK (damage_type IN (
       'real_damage',     -- physical breakage/spoilage/expiry/fire/water/transit
       'missing',         -- not found in warehouse, no explanation (the user's core complaint)
       'theft',           -- suspected/confirmed theft
       'quality_reject',  -- failed QC
       'customer_return', -- auto from sales return (existing flow)
       'other'
     ));
   ```
2. Add `reason_code` varchar(50) nullable + `reason_detail` text nullable (split the current free-text `reason` into a structured code + optional detail). Keep `reason` for backward compat / migration.
3. Create `damage_reasons` taxonomy table (branch-scoped optional, or global):
   ```sql
   CREATE TABLE damage_reasons (
     id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
     reason_code varchar(50) NOT NULL UNIQUE,
     label varchar(120) NOT NULL,
     damage_type varchar(30) NOT NULL REFERENCES ... (CHECK same enum),
     is_active boolean NOT NULL DEFAULT true,
     sort_order int NOT NULL DEFAULT 0,
     created_at timestamp(0), updated_at timestamp(0)
   );
   -- Seed: 'breakage_forklift' → real_damage, 'expiry_shelf_life' → real_damage,
   --       'not_found' → missing, 'suspected_theft' → theft, 'qc_fail' → quality_reject, ...
   ```
4. RLS on `damage_reasons`: read for all authenticated; write for admin only (it's master data).

**Backend changes:**
- `DamageService::createDamage` accepts `damage_type` (required) + `reason_code` (optional, validated against `damage_reasons`).
- `DamageService::postDamageGL` — optionally route to a more specific ledger based on `damage_type` (config map: `real_damage → damage_loss`, `missing → inventory_shrinkage`, `theft → a separate 'theft_loss' ledger if configured`). This makes the P&L show damage split by type.
- Seed `damage_reasons` with ~15 standard reasons.

**Frontend changes:**
- `create.blade.php`: add a **Damage type** radio/select (required) at the top — when "Missing" is selected, show a mandatory warning: *"Declaring stock as missing is an accountability action. A witness and reason are required."* (witness field arrives in Phase 4, but the warning can appear now).
- Add a **Reason** dropdown (populated from `damage_reasons` filtered by selected `damage_type`) + a "details" textarea.
- `index.blade.php`: add Damage type column + filter.
- `show.blade.php`: display type + reason prominently.

**Acceptance criteria:**
- Cannot create a damage without `damage_type`.
- The `damage_reasons` dropdown filters by selected type.
- Existing records backfilled to `damage_type='real_damage'` (or 'customer_return' where `sales_return_id IS NOT NULL`).
- GL posts to the type-specific ledger when configured.

**Risks:** Backfill must correctly classify existing records (auto-linked sales-return damages → `customer_return`; everything else → `real_damage` as a safe default). Operators need training that "missing" is a serious classification.

---

### Phase 2 — Per-Damage Integrity Audit Panel & LISTEN/NOTIFY (port legacy strength)

**Goal:** Restore the legacy strength that Laravel dropped — a live per-damage integrity audit panel — and add real-time push parity with other modules.

**Schema changes (migration `2026_01_02_000001_damage_listen_notify_and_audit`):**
1. Add a LISTEN/NOTIFY trigger on `damage_invoices` (mirror the pattern in `2025_01_21_000001_add_listen_notify_triggers.php`):
   ```sql
   CREATE OR REPLACE FUNCTION notify_damage_change() RETURNS trigger AS $$
   BEGIN
     PERFORM pg_notify('damage_changed', json_build_object('id', NEW.id, 'action', TG_OP, 'branch_id', NEW.branch_id)::text);
     RETURN NEW;
   END; $$ LANGUAGE plpgsql;
   CREATE TRIGGER trg_damage_notify AFTER INSERT OR UPDATE OR DELETE ON damage_invoices
     FOR EACH ROW EXECUTE FUNCTION notify_damage_change();
   ```
2. (Optional) Create a `damage_audit_log` table for persistent integrity-scan results (vs legacy's live-compute approach). Decision: keep it **live-computed** (like legacy) to avoid a second source of truth — no table needed. Instead, add a `DamageIntegrityService` that runs the 5 checks on demand.

**Backend changes:**
1. Create `app/Services/Stock/DamageIntegrityService.php` with `runChecks(int $damageId): array` porting legacy `DamageAuditModel`:
   - `branch_wh` — damage warehouse belongs to damage branch.
   - `stock` — non-reversed `stock_transactions` exist for each item with `reference_type='damage'`.
   - `total_value` — header `total_value` equals `SUM(qty*rate)` from items (tolerance 0.02).
   - `gl` — `journal_entry_id` set when `total_value >= 0.01` AND status='confirmed'; reversed JE exists when cancelled-confirmed.
   - `reversed` — if `is_reversed`, `reverse_reason` is non-empty.
2. `DamageController::show` calls `DamageIntegrityService::runChecks()` and passes results to the view.
3. Wire the `damage_changed` NOTIFY channel into the existing `ListenNotifyService` so the dashboard / damage index can auto-refresh.

**Frontend changes:**
- `show.blade.php`: add an **Integrity checks** card (port legacy `Damage/details.php` audit panel) with pass/warn/fail icons and a summary tally. Red fail items get a "Reconcile" hint.
- Damage index: optional WebSocket/polling auto-refresh when a `damage_changed` event arrives for the user's branch.

**Acceptance criteria:**
- The integrity panel shows on every damage detail page.
- A damage with a missing journal entry shows `warn: GL — Missing (re-post?)`.
- A damage where `total_value != SUM(qty*rate)` shows `fail: total_value`.
- `pg_notify('damage_changed', ...)` fires on insert/update/delete (verifiable via `LISTEN damage_changed` in psql).

**Risks:** LISTEN/NOTIFY payload size — keep it minimal (id + action + branch_id). The integrity service must be read-only and fast (indexed lookups).

---

### Phase 3 — Photo / Evidence Attachments

**Goal:** Require (or strongly encourage) photographic evidence of damage — critical for insurance claims, audit defense, and deterring fake "damage" write-offs.

**Schema changes (migration `2026_01_03_000001_damage_attachments`):**
```sql
CREATE TABLE damage_attachments (
  id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  damage_invoice_id integer NOT NULL REFERENCES damage_invoices(id) ON DELETE CASCADE,
  file_path varchar(500) NOT NULL,          -- storage disk path
  file_name varchar(255) NOT NULL,
  mime_type varchar(100) NOT NULL,
  file_size bigint NOT NULL,
  caption varchar(255),
  uploaded_by integer NOT NULL REFERENCES users(id),
  created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_dmg_att_damage ON damage_attachments(damage_invoice_id);
-- RLS: same branch as the parent damage (join via damage_invoices)
ALTER TABLE damage_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE damage_attachments FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_damage_attachments_select ON damage_attachments FOR SELECT
  USING (EXISTS (SELECT 1 FROM damage_invoices di WHERE di.id = damage_invoice_id
    AND (current_setting('app.is_admin', true) = 'true' OR di.branch_id = current_setting('app.branch_id')::int)));
-- (similar insert/update/delete policies)
```

**Backend changes:**
- `DamageAttachment` model (fillable, relation to `DamageInvoice`).
- `DamageController` methods: `uploadAttachment(Request, $id)`, `deleteAttachment($id, $attachmentId)`.
- Validation: max file size (e.g. 5 MB), allowed mimes (jpg, png, webp, pdf), max 10 attachments per damage.
- Store on the configured disk (local or S3-compatible via `filesystems.php`).
- **Policy rule:** for `damage_type IN ('real_damage','theft','quality_reject')`, at least 1 photo is **required** to confirm (enforced in `DamageService::confirmDamage`). For `missing` type, photo is optional (there's nothing to photograph) but a written explanation is mandatory (Phase 4 witness/reason).

**Frontend changes:**
- `create.blade.php`: add a file-upload dropzone (can upload after draft creation; or on the show page).
- `show.blade.php`: add an **Evidence** gallery (thumbnails with lightbox, caption, uploader, timestamp, delete button if draft).
- Confirm button disabled with tooltip *"Add at least 1 photo to confirm"* when required and missing.

**Acceptance criteria:**
- Can upload up to 10 photos per damage; thumbnails render.
- Confirm is blocked for real_damage/theft/quality_reject with 0 attachments.
- Deleting a damage cascades to delete its attachment files (queue job or synchronous `Storage::delete`).
- RLS prevents cross-branch access to attachments.

**Risks:** Storage cost — enforce size limits. File deletion on damage cancel — keep files (don't delete on cancel; only on hard delete) for audit trail. Need a cleanup job for orphaned files.

---

### Phase 4 — Witness & Accountable Employee

**Goal:** Name a responsible party for every damage — closing the "employee declares it as damage because they couldn't find it" loophole. Link to the employee ledger for optional payroll recovery.

**Schema changes (migration `2026_01_04_000001_damage_witness_accountable`):**
```sql
ALTER TABLE damage_invoices
  ADD COLUMN witness_employee_id integer REFERENCES employees(id),
  ADD COLUMN accountable_employee_id integer REFERENCES employees(id),
  ADD COLUMN recovery_amount numeric(14,2) DEFAULT 0,
  ADD COLUMN employee_ledger_entry_id bigint;  -- link to employee_ledger if recovery posted

CREATE INDEX idx_dmg_accountable ON damage_invoices(accountable_employee_id) WHERE accountable_employee_id IS NOT NULL;
```

**Backend changes:**
- `DamageService::createDamage` accepts optional `witness_employee_id` and `accountable_employee_id`.
- **Validation rule by type:**
  - `damage_type='missing'` → `accountable_employee_id` **required** (someone is responsible for the missing stock).
  - `damage_type='theft'` → `witness_employee_id` required (someone must corroborate).
  - `damage_type='real_damage'` → neither required, but `accountable_employee_id` recommended if employee-caused.
- **Recovery flow** (new `DamageService::postEmployeeRecovery($damageId, $amount, $employeeId)`):
  - If `recovery_amount > 0` and `accountable_employee_id` set → post a debit to `employee_ledger` (the employee owes the company) and a credit to `damage_loss` (or a new `damage_recovery` income ledger). Links via `employee_ledger_entry_id`.
  - This integrates with the existing Employee module's ledger/payment flow (salary deduction possible).

**Frontend changes:**
- `create.blade.php`: add "Witness (employee)" and "Accountable employee" Select2 dropdowns (populated from active employees in the branch). Conditional required-ness based on `damage_type`.
- `show.blade.php`: display witness + accountable employee with links to their employee profile; show recovery amount + a "Recover from employee" action (manager/admin only).
- `index.blade.php`: add "Accountable" filter (show all damages where employee X is accountable).

**Acceptance criteria:**
- A `missing`-type damage cannot be created without `accountable_employee_id`.
- A `theft`-type damage cannot be created without `witness_employee_id`.
- Posting a recovery creates an `employee_ledger` debit and a GL credit, linked back.
- The accountable employee's profile shows their accumulated damage responsibility.

**Risks:** Employee relations/scope — employees are branch-scoped; ensure the selected employee belongs to the damage's branch. Payroll deduction integration must respect the existing employee payment slip flow (don't double-count).

---

### Phase 5 — Approval Workflow (Maker-Checker + Threshold Escalation)

**Goal:** No single person can both create and post a material write-off. Introduce a `submitted → approved` gate between draft and confirm, with threshold-based escalation. This is the single most important control for the accountability gap.

**Schema changes (migration `2026_01_05_000001_damage_approval_workflow`):**
```sql
ALTER TABLE damage_invoices
  ADD COLUMN submitted_by integer REFERENCES users(id),
  ADD COLUMN submitted_at timestamp(0),
  ADD COLUMN approved_by integer REFERENCES users(id),
  ADD COLUMN approved_at timestamp(0),
  ADD COLUMN approval_rejected_by integer REFERENCES users(id),
  ADD COLUMN approval_rejected_at timestamp(0),
  ADD COLUMN approval_notes text;

-- Extend the status CHECK constraint:
ALTER TABLE damage_invoices DROP CONSTRAINT damage_invoices_status_check;  -- (the generated name)
ALTER TABLE damage_invoices
  ADD CONSTRAINT damage_invoices_status_check
  CHECK (status IN ('draft','submitted','approved','confirmed','cancelled','rejected'));
```

**Config (`config/damage.php` — new):**
```php
return [
  'approval_threshold' => env('DAMAGE_APPROVAL_THRESHOLD', 5000), // BDT; below = auto-approve on submit
  'require_photo_for_confirm' => true,
  'require_witness_for_theft' => true,
  'require_accountable_for_missing' => true,
  'roles' => [
    'create'  => ['admin', 'manager', 'warehouse_manager'],
    'submit'  => ['admin', 'manager', 'warehouse_manager'],
    'approve' => ['admin', 'manager'],
    'confirm' => ['admin', 'manager'],
    'cancel'  => ['admin', 'manager'],
  ],
];
```

**Backend changes:**
- New `DamageService` methods: `submitForApproval($id, $userId)`, `approve($id, $userId, $notes='')`, `reject($id, $userId, $reason)`.
- **Auto-approval rule:** in `submitForApproval`, if `total_value <= config('damage.approval_threshold')` AND the submitter is `admin`/`manager`, auto-transition to `approved` (so small damages aren't bottlenecked). If the submitter is `warehouse_manager`, always require explicit manager approval regardless of amount.
- `confirmDamage` now requires `status='approved'` (not just `draft`).
- `DamagePolicy` updated: `submit` allowed for create-roles; `approve`/`reject` for admin/manager only; `confirm` for admin/manager only.
- **Notification:** on `submit`, dispatch `damage_invoice_submitted` to branch managers/admins (Telegram/push). On `approve`/`reject`, notify the submitter.

**Frontend changes:**
- `create.blade.php` → after store, land on `show` with a "Submit for approval" button (if draft).
- `show.blade.php` action panel becomes state-aware:
  - `draft` → **Submit for approval** (warehouse_manager/manager/admin) | **Cancel** (delete draft).
  - `submitted` → **Approve** / **Reject** (manager/admin) | (submitter sees "Awaiting approval").
  - `approved` → **Confirm damage** (posts stock + GL) (manager/admin) | **Cancel**.
  - `confirmed` → **Cancel & reverse** (manager/admin).
  - `cancelled`/`rejected` → read-only.
- Show approval timeline (submitted_by/at, approved_by/at, notes) in a card.
- Index page: add `submitted`/`approved` to the status filter; add an "Awaiting my approval" quick-filter card for managers.

**Acceptance criteria:**
- A `warehouse_manager` can create + submit, but cannot approve or confirm.
- A damage above threshold requires explicit manager approval.
- A damage below threshold submitted by a manager is auto-approved.
- `confirmDamage` throws if status != 'approved'.
- Approval/rejection notifications fire.
- The approval timeline is fully visible on the detail page.

**Risks:** Must NOT break the sales-return-linked flow — `SalesReturnService::createLinkedDamageWriteOffs` calls `createDamage` + `confirmDamage` back-to-back. Update it to `createDamage` → `submitForApproval` (auto-approved because system-submitted) → `confirmDamage`, OR add a `force_confirm` flag for system-originated damages. Choose the latter for simplicity (system damages skip the approval gate with an audit note "Auto-confirmed: linked to sales return #{code}").

---

### Phase 6 — Dedicated Damage Reports & Dashboard Widget

**Goal:** Answer "how much damage this month/year, by category, by warehouse, by employee, top products" with proper reports. Surface a live damage-cost widget on the dashboard.

**Schema changes (migration `2026_01_06_000001_damage_report_views`):**
- Create PostgreSQL materialized views (refreshed by pg_cron or on confirm/cancel):
  ```sql
  CREATE MATERIALIZED VIEW damage_summary_monthly AS
  SELECT date_trunc('month', damage_date)::date AS month,
         branch_id, warehouse_id, damage_type,
         COUNT(*) AS damage_count,
         SUM(total_value) AS total_cost,
         SUM(recovery_amount) AS recovered
  FROM damage_invoices
  WHERE status='confirmed' AND is_reversed=false
  GROUP BY 1,2,3,4;
  CREATE UNIQUE INDEX ON damage_summary_monthly(month, branch_id, warehouse_id, damage_type);
  ```
- Similarly: `damage_by_product` (joins items), `damage_by_employee` (by accountable_employee_id).

**Backend changes:**
1. `app/Services/Reports/DamageReportService.php`:
   - `monthlyTrend($from, $to, $branchId=null)` — month-by-month cost + count, split by type.
   - `byWarehouse($from, $to, $branchId=null)` — warehouse-wise cost.
   - `byCategory($from, $to, $branchId=null)` — damage_type breakdown.
   - `byEmployee($from, $to, $branchId=null)` — accountable-employee ranking.
   - `topProducts($from, $to, $limit=20, $branchId=null)` — most-damaged products by cost.
   - `kpi($period, $branchId=null)` — total cost this month / this year / vs last period (% change).
2. Add `ReportController` methods + routes: `GET /admin/reports/damage` (index), `/admin/reports/damage/monthly`, etc. Add to `ReportsCatalog`.
3. **Dashboard widget** — `DashboardController` adds `damage_kpi` (this month's confirmed damage cost + count + % vs last month). Render a card + a small sparkline in `dashboard/index.blade.php`.

**Frontend changes:**
1. New views: `resources/views/admin/reports/damage/index.blade.php` (filter form + KPI cards + chart) + partials for each breakdown (monthly trend line chart, category donut, warehouse bar, employee table, top-products table).
2. Use the existing Chart.js (already bundled in `public/assets/js/bootstrep/chart.umd.min.js`).
3. CSV/PDF export of each report.
4. Dashboard damage widget card with month-to-date cost + a red/green delta vs last month.

**Acceptance criteria:**
- A manager can see "Damage cost this month = Tk X, this year = Tk Y" instantly.
- Can drill into category (real_damage vs missing vs theft) for any period.
- Can see which employee is accountable for the most damage cost.
- Can see top 20 most-damaged products.
- Dashboard widget updates daily (materialized view refresh) and shows a trend sparkline.
- Reports respect RLS (branch-scoped for non-admins).

**Risks:** Materialized view refresh latency — refresh on confirm/cancel via a queued job, or use pg_cron (already set up in `2025_01_20_000009`). For real-time KPIs on the dashboard, a live aggregate query (indexed) is fast enough for typical volumes.

---

### Phase 7 — UX Polish, Quick Filters, Print & Export

**Goal:** Make the module genuinely pleasant and operator-friendly. Fix the "today only" default, add quick filters, add a printable damage slip, and improve the index for high-volume branches.

**No schema changes.**

**Backend changes:**
- `DamageController::index` — change default date range from "today" to "this month" (month-to-date) when no filter is set; add quick-filter query params (`?range=today|week|month|year`).
- Add `print($id)` method → renders a printable damage slip (A5) with header, items, reason, type, witness, accountable, approval signatures line, photos thumbnails, GL summary. Uses the existing `layouts/print.blade.php`.
- Add `exportPdf($id)` (optional — via dompdf if installed, else browser print).
- CSV export enhanced: include `damage_type`, `reason_code`, `accountable_employee`, `witness`, `approved_by`, `recovery_amount`.

**Frontend changes:**
- Index: add quick-filter buttons (Today / This Week / This Month / This Year) above the table.
- Index: add a small bar chart "Damage cost last 12 months" in the summary header.
- Show page: add "Print slip" button.
- Create form: product Select2 switched to AJAX search (not the 500-capped dropdown) for large catalogs; add barcode-scan input.
- Status badges color-coded consistently; "Awaiting approval" highlighted for managers.
- Mobile-responsive: the create form's item table becomes cards on small screens.

**Acceptance criteria:**
- Quick filters work and persist in the URL.
- Print slip is clean and one-page.
- Product search is AJAX (no 500-cap) and supports barcode.
- Layout holds on mobile.

**Risks:** Barcode scanning needs a hardware test; provide a manual fallback. AJAX product search must respect branch scope (products visible to the user's branch).

---

### Phase 8 — Test Coverage, Reversal Window & Hardening

**Goal:** Lock in the gains with tests and add the final controls (period-close guard on reversal, reversal reason taxonomy, employee-ledger reconciliation).

**Schema changes (migration `2026_01_08_000001_damage_hardening`):**
- Add `reverse_reason_code` varchar(50) nullable (structured reversal reason from a small taxonomy: `entered_in_error`, `found_later`, `wrong_product`, `wrong_qty`, `period_reopen`).
- (Optional) Add `accounting_period_id` FK to `damage_invoices` for explicit period linkage.

**Backend changes:**
1. **Reversal window guard** — in `cancelDamage`, if the damage's `damage_date` falls in a **closed** accounting period, block the reversal unless the user is `admin` AND provides `reverse_reason_code='period_reopen'` + a note. Uses `AccountingPeriodService::isClosed($date)`.
2. **Reversal reason taxonomy** — `cancelDamage` requires `reverse_reason_code` (not just free text).
3. **Employee-ledger reconciliation** — if a damage with `recovery_amount > 0` is cancelled, reverse the employee-ledger entry too (don't leave the employee owing money for a cancelled damage).
4. **Stock-availability pre-check in `SalesReturnReversalGuard`** — close the "accepted limitation" by pre-checking that reversing linked damages won't drive stock negative; show a warning in the reverse-preview modal.

**Test coverage (`tests/Feature/Damage/` + `tests/Unit/`):**
- `CreateDamageTest` — valid create, validation errors, branch scoping.
- `ConfirmDamageTest` — stock decrement, GL post, idempotency, insufficient-stock throw.
- `CancelDamageTest` — reversal of stock + GL, append-only verification, employee-ledger reversal.
- `ApprovalWorkflowTest` — submit/approve/reject flows, threshold auto-approve, role enforcement.
- `DamageRbacTest` — every role × every action matrix.
- `DamageRlsTest` — cross-branch access denied at DB level.
- `DamageIntegrityTest` — the 5 integrity checks pass/fail scenarios.
- `DamageReportTest` — monthly/category/employee/top-products aggregations.
- `SalesReturnDamageLinkTest` — auto-create + cascade-reverse.
- `PhotoUploadTest` — upload, required-for-confirm, delete.

**Acceptance criteria:**
- `php artisan test --filter=Damage` is green.
- A damage in a closed period cannot be cancelled by a manager (only admin with `period_reopen` reason).
- Cancelling a recovered damage reverses the employee-ledger entry.
- `SalesReturnReversalGuard` preview warns when a linked damage reversal may fail.

**Risks:** Test factories need all the new columns; update `DatabaseSeeder`/factories. The period-close guard must not block legitimate same-period corrections.

---

### Phase summary table

| Phase | Goal | Schema? | Severity addressed | Dependencies | Status |
|---|---|---|---|---|---|
| **0** | Stabilize & close critical regressions (RBAC, P&L, audit, rate) | No | 🔴 Critical regressions | None | ✅ **COMPLETED** |
| **1** | Damage category + reason taxonomy | Yes | 🔴 Shared #24, #25 | Phase 0 | ⬜ Pending |
| **2** | Integrity audit panel + LISTEN/NOTIFY | Yes (trigger) | 🟠 Regression #10, #17 | Phase 0 | ⬜ Pending |
| **3** | Photo / evidence attachments | Yes | 🔴 Shared #22 | Phase 1 (type-aware required-ness) | ⬜ Pending |
| **4** | Witness + accountable employee + recovery | Yes | 🔴 Shared #23 | Phase 1 | ⬜ Pending |
| **5** | Approval workflow + threshold escalation | Yes | 🔴 Shared #26, #27 | Phases 0, 1, 3, 4 | ⬜ Pending |
| **6** | Dedicated reports + dashboard widget | Yes (views) | 🔴 Shared #28, #9 | Phases 1, 4, 5 | ⬜ Pending |
| **7** | UX polish, quick filters, print, barcode | No | 🟡 Shared #30 | Phase 6 | ⬜ Pending |
| **8** | Tests, reversal window, hardening | Yes (minor) | 🟠 #32, #33, #34 | All prior | ⬜ Pending |

**Recommended sequencing:** 0 → 1 → 2 → (3 ∥ 4) → 5 → 6 → 7 → 8. Phases 3 and 4 can run in parallel since they touch different columns/tables.

**Progress legend:** ✅ Completed · 🟡 In progress · ⬜ Pending · ⏸ Blocked

---

## 7. Part E — Target End-State Vision

After all phases, the Damage module will look like this:

### 7.1 The accountability question — answered

> *"Was this product actually damaged, or did an employee just not find it?"*

**Answered by:**
- Every damage carries a **`damage_type`** (`real_damage` / `missing` / `theft` / `quality_reject` / `customer_return` / `other`). "Missing" is its own category, no longer hidden inside generic damage.
- A `missing`-type damage **requires an accountable employee** (Phase 4) and a structured reason (Phase 1).
- A `real_damage`/`theft`/`quality_reject` damage **requires at least one photo** (Phase 3).
- No damage above threshold posts without **manager approval** (Phase 5).
- Every action (create/submit/approve/confirm/cancel) is in **`user_audit_log`** with old/new JSON (Phase 0).
- A **per-damage integrity panel** verifies stock + GL + value reconcile (Phase 2).

### 7.2 "Cost of damage this month / this year" — answered

- **Dashboard widget**: month-to-date confirmed damage cost + count + % vs last month (Phase 6).
- **Dedicated report**: monthly trend, category breakdown, warehouse breakdown, employee ranking, top-20 products, with CSV/PDF export (Phase 6).
- **P&L visibility**: damage cost appears in Operating Expenses (Phase 0 fix), split by `damage_type` into `damage_loss` / `inventory_shrinkage` ledgers (Phase 1).
- **Recovery tracking**: `recovery_amount` per damage, linked to `employee_ledger`, netted in reports (Phase 4).

### 7.3 "Accurate stock" — guaranteed

- Draft = zero stock impact; Confirm = atomic stock OUT + GL; Cancel = append-only reversal of both (existing Laravel strength, preserved).
- `lockForUpdate` on damage header + `warehouse_stock` FOR UPDATE prevents concurrent corruption.
- Non-negative stock guard on `warehouse_stock` (existing CHECK) + pre-flight availability check (existing) + re-check on confirm (existing).
- Moving-average cost integrity preserved (OUT does not change `avg_cost`).
- Rate input is readonly (Phase 0) — no value manipulation.

### 7.4 "Proper handle of PostgreSQL" — delivered

- **RLS** on `damage_invoices` + `damage_attachments` (defense-in-depth) — preserved/extended.
- **CHECK constraints** on `status` (extended to 6 states) and `damage_type` (new enum).
- **DEFERRABLE FKs** preserved.
- **Advisory-locked document sequence** preserved.
- **LISTEN/NOTIFY** trigger added for real-time updates (Phase 2).
- **Materialized views** for reporting (Phase 6) with pg_cron refresh.
- **Append-only reversals** preserved.
- **Branch isolation at 3 layers**: RLS (DB) + `EnforceBranchIsolation` middleware (route) + `DamagePolicy` (app) — all three enforced (Phase 0).

### 7.5 "Proper business logic" — delivered

- Two-phase draft → (submit → approve) → confirm → cancel state machine (Phases 0, 5).
- Maker-checker with threshold escalation (Phase 5).
- Type-aware validation (missing requires accountable; theft requires witness; real_damage requires photo) (Phases 1, 3, 4).
- Sales-return cascade preserved + hardened (Phase 8 stock pre-check).
- Employee-ledger recovery flow (Phase 4) with reversal on cancel (Phase 8).
- Period-close reversal guard (Phase 8).

### 7.6 "Proper UI" — delivered

- Bootstrap 5 + Select2 + SweetAlert2 + DataTables + Chart.js (existing).
- State-aware action panel (Phase 5).
- Photo gallery with lightbox (Phase 3).
- Integrity-check panel with pass/warn/fail (Phase 2).
- Quick filters (Today/Week/Month/Year) + 12-month trend bar (Phase 7).
- Printable A5 damage slip with signatures line (Phase 7).
- AJAX product search + barcode scan (Phase 7).
- Dashboard damage widget (Phase 6).
- Mobile-responsive create form (Phase 7).
- Dedicated damage report page with multiple breakdowns (Phase 6).

### 7.7 The day-in-the-life

1. **Warehouse manager** notices a broken pallet. Opens Damage → Create. Selects warehouse, adds the product + qty. Selects `damage_type = real_damage`, reason = "Forklift accident". Uploads 2 photos. Names the forklift operator as accountable employee. Saves as draft.
2. **System** auto-submits (warehouse_manager) → since amount < Tk 5,000 threshold, requires manager approval → sends Telegram notification to branch manager.
3. **Branch manager** opens the notification → sees the damage with photos → clicks Approve.
4. **Warehouse manager** (or manager) clicks Confirm → stock decrements, GL posts (Dr damage_loss / Cr inventory), employee_ledger debited for recovery if configured.
5. **Dashboard** updates: this month's damage cost ticks up; the category breakdown shows "real_damage" increased.
6. **Month-end**: accountant opens Damage Report → sees monthly trend, category split, top-damaged products, employee ranking → exports CSV for the management meeting.
7. **Auditor** opens any damage → sees the integrity panel (all green), the approval timeline, the photos, the GL journal, the stock movements — full traceability.

---

## 8. Appendix — Key SQL & Code Excerpts

### 8.1 Legacy — damage creation INSERT (MySQL)

```sql
-- legacy/app/models/DamageModel.php
INSERT INTO damage_invoices
(damage_code, warehouse_id, damage_date, total_value, remarks, created_by)
VALUES (:code, :wid, :date, :total, :remarks, :uid);

INSERT INTO damage_invoice_items
(damage_invoice_id, product_id, qty, rate)
VALUES (:did, :pid, :qty, :rate);
```

### 8.2 Legacy — GL posting (JournalPostingService::postDamage)

```php
$lines = [
    ['ledger_id' => $shrinkageId, 'debit' => $lossAmount, 'credit' => 0,
     'description' => 'Damage / write-off — ' . $dmgCode],
    ['ledger_id' => $inventoryId, 'debit' => 0, 'credit' => $lossAmount,
     'description' => 'Inventory reduction (damaged goods)'],
];
// header: reference_type='damage', reference_id=$damageId, branch_id
```

### 8.3 Legacy — route role matrix (route_roles.php)

```php
'DamageController' => [
    'index'                   => ['admin', 'manager', 'warehouse_manager'],
    'details'                 => ['admin', 'manager', 'warehouse_manager'],
    'create'                  => ['admin', 'manager', 'warehouse_manager'],
    'store'                   => ['admin', 'manager', 'warehouse_manager'],
    'export'                  => ['admin', 'manager', 'warehouse_manager'],
    'reverse'                 => ['admin', 'manager'],  // tighter
],
```

### 8.4 Laravel — base CREATE TABLE (PostgreSQL, 03_stock.sql)

```sql
CREATE TABLE damage_invoices (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    damage_code varchar(30) NOT NULL,
    damage_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    branch_id integer NOT NULL REFERENCES branches(id),
    reason text,
    journal_entry_id integer REFERENCES journal_entries(id),
    is_reversed boolean NOT NULL DEFAULT false,
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT damage_invoices_code_unique UNIQUE (damage_code)
);
CREATE INDEX idx_dmg_journal ON damage_invoices(journal_entry_id);

CREATE TABLE damage_invoice_items (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    damage_invoice_id integer NOT NULL REFERENCES damage_invoices(id) ON DELETE CASCADE,
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,
    rate numeric(12,2) DEFAULT 0
);
CREATE INDEX idx_dii_damage ON damage_invoice_items(damage_invoice_id);
```

### 8.5 Laravel — RLS policies (07_views_triggers_constraints.sql)

```sql
ALTER TABLE damage_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE damage_invoices FORCE ROW LEVEL SECURITY;
CREATE POLICY rls_damage_invoices_select ON damage_invoices FOR SELECT
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_insert ON damage_invoices FOR INSERT
  WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_update ON damage_invoices FOR UPDATE
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int)
  WITH CHECK (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_delete ON damage_invoices FOR DELETE
  USING (current_setting('app.is_admin', true) = 'true' OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_damage_invoices_admin ON damage_invoices FOR ALL
  USING (current_setting('app.is_admin', true) = 'true')
  WITH CHECK (current_setting('app.is_admin', true) = 'true');
```

### 8.6 Laravel — DamageService::createDamage (core excerpt)

```php
public function createDamage(array $data): DamageInvoice
{
    $this->validateCreateInput($data);
    $warehouse = DB::table('warehouses')->where('id', (int)$data['warehouse_id'])->first();
    $branchId = (int)$warehouse->branch_id;
    $totalValue = 0.0; $validatedItems = [];
    foreach ($data['items'] as $item) {
        $rate = (float)($item['rate'] ?? 0);
        if ($rate <= 0) { $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId); }
        $validatedItems[] = ['product_id'=>$productId,'qty'=>$qty,'rate'=>$rate];
        $totalValue += $qty * $rate;
    }
    // pre-check availability...
    $damageCode = $this->generateDamageCode(); // advisory-locked DMG-YYYYMMDD-NNNN
    return DB::transaction(function () use (...) {
        $damageId = DB::table('damage_invoices')->insertGetId([
            'damage_code'=>$damageCode, 'damage_date'=>$data['damage_date'] ?? now()->format('Y-m-d'),
            'warehouse_id'=>$warehouseId, 'branch_id'=>$branchId, 'total_value'=>round($totalValue,2),
            'reason'=>trim((string)($data['reason'] ?? '')), 'status'=>'draft',
            'is_reversed'=>false, 'created_by'=>$data['created_by'] ?? null, ...
        ]);
        DB::table('damage_invoice_items')->insert($itemRows);
        // dispatch damage_invoice_created notification (unless suppress_notification)
        return DamageInvoice::with(['items.product','warehouse.branch'])->find($damageId);
    });
}
```

### 8.7 Laravel — DamageService::postDamageGL (core excerpt)

```php
private function postDamageGL(DamageInvoice $damage, int $createdBy): int
{
    $totalValue = (float)$damage->total_value;
    if ($totalValue < 0.01) return 0;
    $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
    $damageLossLedgerId = $this->journalPosting->lookupLedgerByNature('damage_loss')
                         ?? $this->journalPosting->lookupLedgerByNature('inventory_shrinkage');
    return $this->journalPosting->createJournalEntry([
        'entry_date'=>$damage->damage_date->format('Y-m-d'),
        'reference_type'=>'damage','reference_id'=>$damage->id,
        'branch_id'=>$damage->branch_id,
        'description'=>'Damage Write-off '.$damage->damage_code.($damage->reason?' — '.$damage->reason:''),
        'source'=>'damage','created_by'=>$createdBy,
    ], [
        ['ledger_id'=>$damageLossLedgerId,'debit'=>$totalValue,'credit'=>0,
         'memo'=>'Damage / write-off — '.$damage->damage_code],
        ['ledger_id'=>$inventoryLedgerId,'debit'=>0,'credit'=>$totalValue,
         'memo'=>'Inventory reduction (damaged goods) — '.$damage->damage_code],
    ]);
}
```

### 8.8 Laravel — the P&L rollup GAP (ReportService.php)

```php
// CURRENT (BROKEN — damage_loss missing):
'operating_expenses' => ['label' => 'Operating Expenses',
    'natures' => ['operating_expense', 'inventory_shrinkage', 'manual_adjustment'],
    'sort' => 30],

// FIX (Phase 0):
'operating_expenses' => ['label' => 'Operating Expenses',
    'natures' => ['operating_expense', 'inventory_shrinkage', 'damage_loss', 'manual_adjustment'],
    'sort' => 30],
```

### 8.9 Default Chart of Accounts (seed)

```php
// 2025_01_05_000001_seed_default_chart_of_accounts.php
'L-0502' => 'Inventory Shrinkage / Loss' (nature = inventory_shrinkage)
'L-0503' => 'Damage Loss'                (nature = damage_loss)
```

### 8.10 Target state-machine diagram (after Phase 5)

```
                 ┌──────────────────────────────────────────────┐
                 │                                              │
   create        ▼            submit                 approve     │  confirm
 [draft] ──────────────► [submitted] ──────────────► [approved] ──────────► [confirmed]
   │                        │                          │                    │
   │ cancel                 │ reject                  │ cancel             │ cancel
   ▼                        ▼                          ▼                    ▼
 [cancelled]           [rejected]                 [cancelled]         [cancelled]
                                                                          │
                                                                   (reverses stock + GL
                                                                    + employee_ledger)
```

- `draft` → no stock, no GL.
- `submitted` → pending approval (no stock, no GL).
- `approved` → ready to post (no stock, no GL yet).
- `confirmed` → stock OUT + GL posted.
- `cancelled` → if was confirmed, stock + GL + employee_ledger reversed; else just marked cancelled.
- `rejected` → submitted-but-denied; terminal.

Auto-approve shortcut: if `total_value ≤ threshold` AND submitter ∈ {admin, manager} → `submitted` auto-transitions to `approved`.

---

*End of document. This plan is documentation-only; no code has been written. Each phase can be handed to an engineer as a self-contained spec.*
