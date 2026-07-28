# Warehouse Transfer — Inner-Branch Implementation Plan

**Document version:** 1.2
**Date:** 2025-07-28  
**Scope:** Warehouse-to-Warehouse Transfer (inner-branch / intra-branch only)  
**Context:** Branch-A has 10 warehouses, Branch-B has 5 warehouses. Transfers are only allowed between warehouses that belong to the **same branch**. Cross-branch transfers are handled by the separate **Branch Demand** module, not by this module.  
**Target stack:** Laravel 11 + PostgreSQL 16  
**Current state:** Phase 6.5 + **Phase 1 COMPLETE** + **Phase 2 COMPLETE** (pipeline-aware stock availability)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Legacy System Analysis](#2-legacy-system-analysis)
3. [Laravel System Analysis](#3-laravel-system-analysis)
4. [Gap Analysis](#4-gap-analysis)
5. [Implementation Phases](#5-implementation-phases)
6. [Phase 1 — Same-Branch Enforcement & Server-Side Guards](#6-phase-1--same-branch-enforcement--server-side-guards)
7. [Phase 2 — Pipeline-Aware Stock Availability](#7-phase-2--pipeline-aware-stock-availability)
8. [Phase 3 — Reversal Safety & Ordering](#8-phase-3--reversal-safety--ordering)
9. [Phase 4 — Audit Trail & Data Integrity](#9-phase-4--audit-trail--data-integrity)
10. [Phase 5 — UI Parity & UX Improvements](#10-phase-5--ui-parity--ux-improvements)
11. [Phase 6 — Export, Reporting & Branch Ledger Settlement](#11-phase-6--export-reporting--branch-ledger-settlement)
12. [Phase 7 — Test Coverage & Shadow Mode](#12-phase-7--test-coverage--shadow-mode)
13. [Phase 8 — API Routes & Mobile Support](#13-phase-8--api-routes--mobile-support)
14. [Appendix A — Database Schema Reference](#appendix-a--database-schema-reference)
15. [Appendix B — Business Rule Matrix](#appendix-b--business-rule-matrix)
16. [Appendix C — File Inventory](#appendix-c--file-inventory)

---

## 1. Executive Summary

The Warehouse Transfer module in the legacy PHP/MySQL system is a **strictly same-branch, single-phase** operation: a user creates a transfer and stock moves immediately. Cross-branch transfers are impossible through this module — they must go through the separate **Branch Demand** workflow.

The current Laravel implementation (Phase 6.5) introduced a **two-phase draft → confirm → cancel** flow, which is a significant improvement. However, it has **critical gaps** that undermine the inner-branch constraint:

| # | Gap | Severity | Risk |
|---|-----|----------|------|
| G1 | No server-side same-branch enforcement — Laravel allows cross-branch transfers via WarehouseTransfer | **Critical → ✅ FIXED** | Wrong module for cross-branch; GL posted incorrectly; bypasses Branch Demand |
| G2 | No pipeline-aware stock availability check — uses simple `getWarehouseQty` instead of `StockAvailabilityService` | **High → ✅ FIXED** | Over-commitment of stock (transfers + sales competing for same qty) |
| G3 | No reversal ordering — dest IN and source OUT reversed in arbitrary order | **High** | Insufficient stock at receiver warehouse during reversal |
| G4 | No dedicated audit trail — service uses `DB::table()` bypassing Eloquent events | **Medium** | No audit log for who did what when |
| G5 | No CSV export — legacy has it, Laravel doesn't | **Medium** | Operational gap |
| G6 | No branch ledger settlement mechanism visible | **Medium** | Intercompany balances remain unsettled |
| G7 | No test coverage for WarehouseTransfer workflow | **High** | Regressions undetected |
| G8 | No API routes for warehouse transfers | **Low** | Mobile/API users cannot create/confirm transfers |
| G9 | `WarehouseTransfer` model doesn't apply `BranchScope` global scope | **Medium → ✅ FIXED** | Branch isolation relies solely on RLS (single-layer defense) |
| G10 | `WarehouseBelongsToBranch` and `WarehouseHasStock` rules exist but are not used in transfer validation | **Medium → ✅ FIXED** | Duplicate validation logic, not reusing existing rules |

This document provides a **phase-by-phase implementation plan** to close every gap, resulting in a **production-ready inner-branch warehouse transfer** module that matches and exceeds the legacy system's safeguards.

---

## 2. Legacy System Analysis

### 2.1 Architecture

```
WarehouseTransferController  →  WarehouseTransferModel  →  StockTransactionModel
                                    ↓                          ↓
                            JournalPostingService        StockService (SSOT)
                                    ↓
                            WarehouseTransferAuditModel
```

### 2.2 Business Logic Flow (Legacy — Same-Branch Only)

**Creation (single-phase, immediate):**

```
1. Validate from_warehouse_id ≠ to_warehouse_id
2. Resolve both warehouses' branch_id from MySQL
3. ★ ENFORCE: from_branch_id === to_branch_id (throws Exception if not)
4. ★ ENFORCE: user's session branch matches from-branch (unless admin override)
5. Build line items with rate defaulting to source avg_cost
6. Assert stock availability via Assert_Warehouse_Lines_Available (pipeline-aware)
7. Generate transfer code: WT-YYYYMMDD-NNNN (random 4-digit)
8. INSERT warehouse_transfers (status = 'transferred' or 'received' if demand-linked)
9. INSERT warehouse_transfer_items
10. IMMEDIATELY move stock:
    - Source OUT: updateWarehouseStock(fromWh, product, -qty, 0)
    - Source OUT: logMovement(..., qty=-qty, 'Transfer out #WT-...')
    - Dest IN:    updateWarehouseStock(toWh, product, +qty, rate)
    - Dest IN:    logMovement(..., qty=+qty, 'Transfer in #WT-...')
11. COMMIT transaction
```

**Reversal:**

```
1. Validate: transfer exists, not already reversed, not linked to branch demand
2. Require reason (min 3 chars)
3. Get all stock_transactions for this transfer
4. ★ SORT for reversal: dest IN (positive qty) reversed FIRST, then source OUT
   - This prevents "insufficient stock at receiver" during reversal
5. Reverse each movement via StockTransactionModel::reverseTransaction
6. Reverse GL journals if present (both creditor + debtor entries)
7. UPDATE warehouse_transfers: is_reversed=1, status='reversed'
```

### 2.3 Key Legacy Safeguards

| Safeguard | Implementation | Location |
|-----------|---------------|----------|
| Same-branch enforcement | `resolveWarehouseBranches()` throws if branches differ | `WarehouseTransferModel::createTransfer()` line 111-113 |
| Session branch check | `sessionBranchId()` must match from-branch | `WarehouseTransferModel::createTransfer()` line 116-122 |
| Admin override | `canOverrideBranch()` allows admin to select any branch | `WarehouseTransferModel::createTransfer()` line 116 |
| Pipeline-aware availability | `Assert_Warehouse_Lines_Available` → `StockAvailabilityService::getWarehouseAvailableQty` | `Helper.php` |
| Reversal ordering | `sortMovementsForReversal()` — dest IN before source OUT | `WarehouseTransferModel::sortMovementsForReversal()` |
| Demand-linked protection | Cannot reverse transfers linked to Branch Demand | `WarehouseTransferModel::canUserReverseTransfer()` |
| Audit checks | `WarehouseTransferAuditModel::runHealthChecks()` | Separate audit model |
| CSV export | `WarehouseTransferController::export()` | Controller |

### 2.4 MySQL Schema (Legacy)

```sql
-- warehouse_transfers
CREATE TABLE warehouse_transfers (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    transfer_code         VARCHAR(50),
    transfer_date         DATE,
    from_warehouse_id     INT,
    to_warehouse_id       INT,
    branch_demand_id      INT NULL,           -- FK to branch_demands (if linked)
    total_amount          DECIMAL(14,2),       -- Stored column
    journal_entry_id      BIGINT NULL,         -- Sender creditor journal
    journal_entry_id_debtor BIGINT NULL,       -- Receiver debtor journal
    status                ENUM('transferred','received','reversed'),
    is_reversed           TINYINT(1) DEFAULT 0,
    reversed_at           DATETIME NULL,
    reversed_by           INT NULL,
    reverse_reason        TEXT NULL,
    created_by            INT,
    created_at            DATETIME,
    updated_at            DATETIME
);

-- warehouse_transfer_items
CREATE TABLE warehouse_transfer_items (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    warehouse_transfer_id BIGINT,
    product_id            INT,
    qty                   DECIMAL(14,4),
    rate                  DECIMAL(12,2)
);

-- warehouse_stock (composite PK, no id column)
CREATE TABLE warehouse_stock (
    warehouse_id          INT,
    product_id            INT,
    qty                   DECIMAL(14,4) DEFAULT 0,
    avg_cost              DECIMAL(12,2) DEFAULT 0,
    last_updated          TIMESTAMP,
    PRIMARY KEY (warehouse_id, product_id)
);

-- stock_transactions (append-only ledger)
CREATE TABLE stock_transactions (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_date      DATE,
    product_id            INT,
    warehouse_id          INT,
    qty                   DECIMAL(14,4),       -- Signed: +ve IN, -ve OUT
    rate                  DECIMAL(12,2) DEFAULT 0,
    reference_type        VARCHAR(30),          -- 'warehouse_transfer', 'demand_send', etc.
    reference_id          BIGINT,
    branch_demand_item_id INT NULL,
    remarks               TEXT,
    is_reversed           TINYINT(1) DEFAULT 0,
    reversed_at           DATETIME NULL,
    reversed_by           INT NULL,
    reverse_reason        TEXT NULL,
    created_by            INT,
    created_at            DATETIME
);
```

### 2.5 How Legacy Handles Stock Movement

**Moving Average Costing:**

- **Stock IN (positive qty):** `avg_cost = (old_qty × old_avg + new_qty × new_rate) / (old_qty + new_qty)`
- **Stock OUT (negative qty):** `qty -= issueQty`, `avg_cost unchanged`

**Transfer-specific:**
- Source OUT: rate = source avg_cost (avg_cost unchanged at source)
- Dest IN: rate = source avg_cost (dest avg_cost recalculated using weighted average)

**Availability check:**
```
available_qty = physical_qty (warehouse_stock.qty) - pipeline_qty (open sales dispatches not yet challan_completed)
```

**Non-negative constraint:** MySQL triggers prevent `qty < -0.0001`

### 2.6 Branch-Warehouse Relationship (Legacy)

```
Branch (1) ←→ (N) Warehouse
  branches.id ←── warehouses.branch_id
```

- FK enforced by migration 035: `warehouses.branch_id → branches.id`
- Warehouse cannot change branch if it has stock or pending dispatches
- Warehouse cannot be deactivated if it has stock, pending dispatches, or active stock-take

### 2.7 Cross-Branch Transfer via Branch Demand (Legacy)

Cross-branch transfers are handled by a **completely separate module** — `BranchDemand`:

1. **Create Demand:** User creates a demand from their branch to another branch
2. **Send Goods:** Stock moves OUT from sender warehouse, IN to receiver warehouse + intercompany GL posted
3. **Receive:** Status updates to 'received'
4. **Reverse:** Reverse stock + GL + intercompany ledger

The `WarehouseTransfer` module is **never** used for cross-branch transfers. The `BranchDemand` module creates a documentary `warehouse_transfers` row as a side-effect (with `branch_demand_id` set), but this is a read-only record — it cannot be reversed through the WarehouseTransfer module.

---

## 3. Laravel System Analysis

### 3.1 Architecture

```
WarehouseTransferController  →  WarehouseTransferService  →  StockService
                                    ↓                           ↓
                            JournalPostingService         DocumentSequenceService
                                    ↓
                            WarehouseTransfer (Model)
                            WarehouseTransferItem (Model)
```

### 3.2 Business Logic Flow (Laravel — Two-Phase)

**Phase 1 — Create Draft:**

```
1. Validate input (from_warehouse_id, to_warehouse_id, items)
2. Resolve branches from warehouses table
3. Determine is_interbranch = (from_branch_id !== to_branch_id)
   ★ BUG: No enforcement that branches must be the same
4. Build line items with rate defaulting to source avg_cost
5. Pre-check availability at source (simple getWarehouseQty, NOT pipeline-aware)
6. Generate transfer code: WT-YYYYMMDD-NNNN (atomic via DocumentSequenceService)
7. INSERT warehouse_transfers (status='draft', NO stock movement, NO GL)
8. INSERT warehouse_transfer_items
```

**Phase 2 — Confirm:**

```
1. Lock transfer row (lockForUpdate)
2. Verify status = 'draft'
3. For each item:
   - Source OUT: StockService::applyTransaction(qty=-qty, rate=avg_cost)
   - Dest IN:    StockService::applyTransaction(qty=+qty, rate=avg_cost)
4. If is_interbranch:
   - Post TWO intercompany GL journals (creditor + debtor)
   - Record in branch_ledger
5. If same-branch: NO GL
6. Update status = 'confirmed'
```

**Phase 3 — Cancel:**

```
If draft: just mark status='cancelled'
If confirmed:
  1. Reverse GL (both journals if interbranch)
  2. Get all stock_transactions for this transfer
  3. ★ BUG: No reversal ordering — reverses in arbitrary DB order
  4. Reverse each stock_transaction
  5. Mark is_reversed=true, set reversal metadata
  6. Update status='cancelled'
```

### 3.3 Laravel Schema (PostgreSQL)

```sql
CREATE TABLE warehouse_transfers (
    id                    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_code         varchar(30) NOT NULL UNIQUE,
    transfer_date         date NOT NULL,
    from_warehouse_id     integer NOT NULL REFERENCES warehouses(id),
    to_warehouse_id       integer NOT NULL REFERENCES warehouses(id),
    from_branch_id        integer NOT NULL REFERENCES branches(id),
    to_branch_id          integer NOT NULL REFERENCES branches(id),
    is_interbranch        boolean NOT NULL DEFAULT false,
    status                varchar(20) NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id      integer REFERENCES journal_entries(id),
    journal_entry_id_debtor integer REFERENCES journal_entries(id),
    is_reversed           boolean NOT NULL DEFAULT false,
    reversed_at           timestamp(0),
    reversed_by           integer,
    reverse_reason        text,
    notes                 text,
    created_by            integer,
    created_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at            timestamp(0)
);
-- ★ Missing: branch_demand_id column
-- ★ Missing: total_amount column (computed accessor instead — design choice, not a bug)
-- RLS policy: SELECT where from_branch_id or to_branch_id = current_setting('app.branch_id')
```

### 3.4 What Laravel Does Better Than Legacy

| Feature | Legacy | Laravel |
|---------|--------|---------|
| Two-phase flow | No (immediate) | Yes (draft → confirm → cancel) |
| Transfer code generation | Random 4-digit | Atomic sequence via advisory locks |
| Soft deletes | No | Yes |
| Warehouse freeze check | No | Yes (blocks OUT during stock-take) |
| from_branch_id / to_branch_id stored | No (derived at runtime) | Yes (stored in DB columns) |
| DocumentSequenceService | No | Yes (atomic, no race conditions) |
| PostgreSQL RLS | N/A | Yes (branch isolation at DB level) |

### 3.5 What Laravel Is Missing vs. Legacy

| Feature | Legacy Has | Laravel Has | Gap |
|---------|-----------|-------------|-----|
| Same-branch enforcement | ✅ Model + JS | ❌ Neither | **Critical** |
| Session branch check | ✅ Model level | ❌ Missing | **Critical** |
| Pipeline-aware availability | ✅ StockAvailabilityService | ❌ Simple getWarehouseQty | **High** |
| Reversal ordering | ✅ sortMovementsForReversal | ❌ Arbitrary order | **High** |
| Audit model | ✅ WarehouseTransferAuditModel | ❌ None | **Medium** |
| CSV export | ✅ Controller | ❌ Missing | **Medium** |
| branch_demand_id column | ✅ Yes | ❌ Missing | **Medium** |
| Demand-linked reversal protection | ✅ Yes | ❌ Missing | **Medium** |
| BranchScope on model | N/A | ❌ Not applied | **Medium** |
| WarehouseBelongsToBranch rule | N/A | ❌ Exists but unused | **Medium** |
| WarehouseHasStock rule | N/A | ❌ Exists but unused | **Medium** |
| Test coverage for transfers | ✅ Indirect | ❌ None | **High** |

---

## 4. Gap Analysis

### G1 — Same-Branch Enforcement (Critical)

**Problem:** The Laravel `WarehouseTransferService::createTransfer()` computes `is_interbranch = fromBranchId !== toBranchId` but **does not throw an exception** when branches differ. It allows cross-branch transfers to proceed, posting intercompany GL. This is **wrong** because:

1. Cross-branch transfers should go through the **Branch Demand** module, which has its own workflow (create → send → receive), approval chain, and intercompany settlement tracking.
2. The WarehouseTransfer module's intercompany GL posting is a simplified version that doesn't match the Branch Demand's full intercompany accounting.
3. The legacy JS (`WarehouseTransfer.js` line 176-179) blocks cross-branch transfers on the client side, but the Laravel Blade template (`create.blade.php`) only shows an informational banner — no enforcement.

**Fix:** Add a hard server-side check in `WarehouseTransferService::createTransfer()` that throws if `from_branch_id !== to_branch_id`. Also add a server-side check in `confirmTransfer()` as a defense-in-depth measure.

### G2 — Pipeline-Aware Stock Availability (High)

**Problem:** The Laravel `WarehouseTransferService::createTransfer()` uses `StockService::getWarehouseQty()` which returns the **physical** qty only. The legacy uses `StockAvailabilityService::getWarehouseAvailableQty()` which returns `physical - pipeline` (open sales dispatches not yet completed). This means:

- A warehouse has 100 units of Product X
- 30 units are committed to open sales dispatches (pipeline)
- Legacy: available = 100 - 30 = 70 → transfer of 80 would be rejected ✅
- Laravel: available = 100 → transfer of 80 would be allowed ❌ → over-commitment

**Fix:** Use `StockAvailabilityService` in both `createTransfer()` (pre-check) and `confirmTransfer()` (final check). The `WarehouseHasStock` rule already exists but is not used.

### G3 — Reversal Ordering (High)

**Problem:** When cancelling a confirmed transfer, the Laravel `cancelTransfer()` fetches stock transactions and reverses them in arbitrary DB order. The legacy `sortMovementsForReversal()` sorts dest IN (positive qty) before source OUT (negative qty) to prevent insufficient stock at the receiver warehouse during reversal.

Example of the problem:
- Transfer moved 50 units from W-1 to W-2
- W-2 currently has exactly 50 units (all from this transfer)
- If we reverse source OUT first: W-1 gets 50 back (W-1 qty increases), but then reversing dest IN requires taking 50 from W-2, which has 50 → OK
- But if W-2 had 50 total and 30 were already sold, W-2 only has 20 → reversing dest IN would fail with "insufficient stock"
- By reversing dest IN first (taking 50 out of W-2), we reduce W-2 to 0, then reverse source OUT (adding 50 back to W-1) — this is the correct order

**Fix:** Implement `sortMovementsForReversal()` in the Laravel service, matching the legacy logic.

### G4 — Audit Trail (Medium)

**Problem:** The `WarehouseTransferService` uses `DB::table()->insertGetId()` and `DB::table()->insert()` instead of Eloquent `create()` / `save()`. This bypasses Eloquent model events, so the `AuditableMasterData` trait on the `WarehouseTransfer` model is never triggered. There is no audit log of who created, confirmed, or cancelled a transfer.

**Fix:** Either (a) refactor service to use Eloquent methods, or (b) add explicit audit logging in the service. Option (b) is preferred for the immediate fix since the service's transactional integrity relies on `DB::transaction()`.

### G5 — CSV Export (Medium)

**Problem:** The legacy controller has `export()` method that generates a CSV with transfer details. The Laravel controller has no equivalent.

**Fix:** Add an `export()` method to the Laravel controller.

### G6 — Branch Ledger Settlement (Medium)

**Problem:** The `branch_ledger` table has `is_settled = false` and `settled_at = null` columns, but there is no visible service or route to settle intercompany balances. This is a broader accounting concern, not specific to warehouse transfers, but it should be noted.

**Fix:** Out of scope for this document — this is a separate accounting module. However, the warehouse transfer module should correctly create the `branch_ledger` entry (which it already does for cross-branch transfers). Since we're enforcing same-branch only, this becomes moot for inner-branch transfers.

### G7 — Test Coverage (High)

**Problem:** No dedicated test files for the WarehouseTransfer workflow. The `tests/Feature/Warehouse/` directory only tests Warehouse CRUD.

**Fix:** Create comprehensive test suite covering all scenarios.

### G8 — API Routes (Low)

**Problem:** No API routes for warehouse transfers. Mobile/API users cannot create/confirm/cancel transfers.

**Fix:** Add API routes with proper authentication and branch isolation.

### G9 — BranchScope (Medium)

**Problem:** The `WarehouseTransfer` model doesn't apply the `BranchScope` global Eloquent scope. Branch isolation relies entirely on PostgreSQL RLS policies. This is a single-layer defense — if the RLS GUC is not set correctly for a request, the user could see transfers from other branches.

**Fix:** Apply `BranchScope` to the `WarehouseTransfer` model. Since transfers involve both a from-branch and to-branch, the scope should allow visibility where either branch matches the user's session branch.

### G10 — Unused Validation Rules (Medium)

**Problem:** `WarehouseBelongsToBranch` and `WarehouseHasStock` rules exist in `app/Rules/` but are not used in the WarehouseTransfer validation. These rules were designed for sales invoices but are generic enough to be reused.

**Fix:** Integrate these rules into the transfer validation flow.

---

## 5. Implementation Phases

### Phase Overview

| Phase | Name | Duration | Priority | Dependencies |
|-------|------|----------|----------|-------------|
| 1 | Same-Branch Enforcement & Server-Side Guards | 2-3 days | **Critical** ✅ DONE | None |
| 2 | Pipeline-Aware Stock Availability | 1-2 days | **High** ✅ DONE | Phase 1 |
| 3 | Reversal Safety & Ordering | 1-2 days | **High** | Phase 1 |
| 4 | Audit Trail & Data Integrity | 2-3 days | **Medium** | Phase 1, 2, 3 |
| 5 | UI Parity & UX Improvements | 2-3 days | **Medium** | Phase 1 |
| 6 | Export, Reporting & Branch Ledger Settlement | 2-3 days | **Medium** | Phase 4 |
| 7 | Test Coverage & Shadow Mode | 3-4 days | **High** | Phase 1-6 |
| 8 | API Routes & Mobile Support | 2-3 days | **Low** | Phase 7 |

**Total estimated duration:** ~15-20 days

### Phase Dependencies

```
Phase 1 ──→ Phase 2 ──→ Phase 4 ──→ Phase 6 ──→ Phase 7 ──→ Phase 8
          ──→ Phase 3 ──↗                       ↗
          ──→ Phase 5 ──────────────────────────↗
```

---

## 6. Phase 1 — Same-Branch Enforcement & Server-Side Guards ✅ DONE

**Priority:** Critical  
**Duration:** 2-3 days  
**Status:** ✅ **COMPLETED** (2025-07-28)  
**Goal:** Ensure that the WarehouseTransfer module can ONLY create same-branch transfers. Cross-branch transfers must be rejected at every layer.

**Implementation summary:**

| # | Deliverable | Status | File |
|---|-------------|--------|------|
| 1.1 | Same-branch throw in service create | ✅ Done | `WarehouseTransferService.php` |
| 1.2 | Same-branch throw in service confirm (defense-in-depth) | ✅ Done | `WarehouseTransferService.php` |
| 1.3 | Controller branch guard | ✅ Done | `WarehouseTransferController.php` |
| 1.4 | WarehouseBelongsToBranch rule integration (new `mode='branch'` parameter) | ✅ Done | `WarehouseBelongsToBranch.php` |
| 1.5 | WarehouseTransferBranchScope on WarehouseTransfer model | ✅ Done | `WarehouseTransferBranchScope.php` + `WarehouseTransfer.php` |
| 1.6 | PostgreSQL trigger for same-branch | ✅ Done | New migration `2025_07_28_000010_add_same_branch_trigger_to_warehouse_transfers.php` |
| 1.7 | Client-side branch guard (SweetAlert + banner + submit button disable) | ✅ Done | `create.blade.php` |
| 1.8 | Warehouse dropdown filtered by branch + branch name in hero header | ✅ Done | `WarehouseTransferController.php` |

**Key changes:**

1. **Service level:** `WarehouseTransferService::createTransfer()` now throws `InvalidArgumentException` if `from_branch_id !== to_branch_id`. `confirmTransfer()` adds defense-in-depth check + blocks `is_interbranch=true`. GL posting is blocked for same-branch transfers (no intercompany GL).

2. **Controller level:** `WarehouseTransferController::store()` resolves both warehouses and checks `branch_id` match before calling service. `confirm()` adds similar check. `getUserBranchId()` helper returns the user's branch (null for admin). Warehouse dropdown filtered by user's branch.

3. **Validation rule:** `WarehouseBelongsToBranch` extended with `mode='branch'` parameter. When `mode='branch'`, the `$contextId` is interpreted as a direct `branch_id` instead of an invoice ID. Used in transfer store validation.

4. **Model scope:** New `WarehouseTransferBranchScope` created (different from `BranchScope` because transfers have `from_branch_id` + `to_branch_id` instead of a single `branch_id`). Applied to `WarehouseTransfer` model via `booted()`.

5. **DB trigger:** Migration creates `enforce_same_branch_transfer()` trigger function + `trg_enforce_same_branch_transfer` trigger on `warehouse_transfers` table. Fires before INSERT or UPDATE, raises `check_violation` with friendly error message if `from_branch_id != to_branch_id`.

6. **Client-side:** `create.blade.php` now shows a red "Cross-branch transfer — NOT ALLOWED" banner when branches differ, disables submit button, and blocks form submission via SweetAlert. Same-branch shows green banner with branch name.

### Verification Checklist (Phase 1)

- [ ] Creating a same-branch transfer succeeds (draft → confirm → cancelled)
- [ ] Creating a cross-branch transfer fails at service level with clear error
- [ ] Creating a cross-branch transfer fails at controller level with validation error
- [ ] Creating a cross-branch transfer fails at DB level with trigger error
- [ ] Creating a cross-branch transfer fails at client level with SweetAlert
- [ ] Confirming a cross-branch transfer (if somehow created) fails
- [ ] Warehouse dropdown only shows user's branch warehouses

---

## 7. Phase 2 — Pipeline-Aware Stock Availability ✅ DONE

**Priority:** High  
**Duration:** 1-2 days  
**Status:** ✅ **COMPLETED** (2025-07-28)  
**Goal:** Prevent over-commitment of stock by using pipeline-aware availability checks in both draft creation and confirm.

### 2.1 Use StockAvailabilityService in createTransfer

**File:** `app/Services/Stock/WarehouseTransferService.php`

**Current code:**

```php
$available = $this->stockService->getWarehouseQty($fromWarehouseId, $item['product_id']);
```

**New code:**

```php
$available = $this->stockAvailabilityService->getWarehouseAvailableQty(
    $fromWarehouseId,
    $item['product_id']
);
```

Inject `StockAvailabilityService` into the service constructor:

```php
public function __construct(
    private StockService $stockService,
    private StockAvailabilityService $stockAvailabilityService,
    private JournalPostingService $journalPosting
) {}
```

### 2.2 Use StockAvailabilityService in confirmTransfer

Add a final availability check at confirm time (stock may have changed between draft creation and confirm):

```php
// In confirmTransfer(), before applying stock movements:
foreach ($transfer->items as $item) {
    $available = $this->stockAvailabilityService->getWarehouseAvailableQty(
        $fromWh,
        $item->product_id
    );
    if ((float) $item->qty > $available + 0.0001) {
        throw new \RuntimeException(
            "Insufficient available stock for product {$item->product_id}: " .
            "available {$available}, requested {$item->qty}"
        );
    }
}
```

### 2.3 Integrate WarehouseHasStock Rule

**File:** `app/Rules/WarehouseHasStock.php`

This rule already exists and uses `StockAvailabilityService`. Use it in the controller validation:

```php
// In store() validation:
'items.*.product_id' => [
    'required', 'integer', 'exists:products,id',
],
'items.*.qty' => [
    'required', 'numeric', 'min:0.001',
    new WarehouseHasStock($request->from_warehouse_id),
],
```

### 2.4 API Endpoint for Available Stock

**File:** `app/Http/Controllers/Admin/WarehouseTransferController.php`

The `getProductStock()` method should return pipeline-aware availability:

```php
public function getProductStock(Request $request)
{
    $product = Product::findOrFail($request->product_id);
    $warehouse = Warehouse::findOrFail($request->warehouse_id);

    $available = $this->stockAvailabilityService->getWarehouseAvailableQty(
        $warehouse->id,
        $product->id
    );
    $physical = $this->stockService->getWarehouseQty($warehouse->id, $product->id);
    $avgCost = $this->stockService->getWarehouseAvgCost($warehouse->id, $product->id);

    return response()->json([
        'available_qty' => $available,
        'physical_qty' => $physical,
        'pipeline_qty' => $physical - $available,
        'rate' => $avgCost,
    ]);
}
```

### 2.5 Show Pipeline Info in UI

**File:** `resources/views/admin/warehouse-transfers/create.blade.php`

Update the product stock display to show both physical and available:

```
Stock: 70 available (100 physical, 30 in pipeline)
```

This helps users understand why they can't transfer more than the available amount.

### Deliverables

| # | Deliverable | Status | File |
|---|-------------|--------|------|
| 2.1 | StockAvailabilityService in createTransfer | ✅ Done | `WarehouseTransferService.php` |
| 2.2 | StockAvailabilityService in confirmTransfer (defense-in-depth) | ✅ Done | `WarehouseTransferService.php` |
| 2.3 | WarehouseTransferItemHasAvailableStock rule integration | ✅ Done | New `WarehouseTransferItemHasAvailableStock.php` + `WarehouseTransferController.php` |
| 2.4 | Pipeline-aware getProductStock API (returns physical + pipeline breakdown) | ✅ Done | `WarehouseTransferController.php` |
| 2.5 | Pipeline info in UI (available / physical / pipeline breakdown + qty warning) | ✅ Done | `create.blade.php` |

**Key changes:**

1. **Service level (2.1 + 2.2):** `WarehouseTransferService` now injects `StockAvailabilityService` via constructor. Both `createTransfer()` and `confirmTransfer()` use `getWarehouseAvailableQty()` instead of `getWarehouseQty()` for availability checks. Error messages now show the breakdown (available, physical, pipeline). The `confirmTransfer()` check is defense-in-depth — stock may change between draft creation and confirm time.

2. **Validation rule (2.3):** New `WarehouseTransferItemHasAvailableStock` rule created (different from existing `WarehouseHasStock` which is tied to `SalesInvoice` items). This rule takes `from_warehouse_id`, resolves the corresponding `product_id` from the request array path, and validates that the qty doesn't exceed pipeline-aware available qty. Integrated into `WarehouseTransferController::store()` validation on `items.*.qty`.

3. **Controller (2.4):** `getProductStock()` now injects `StockAvailabilityService` and returns `physical_qty`, `available_qty`, and `pipeline_qty` instead of just `available_qty`. This enables the frontend to show the pipeline breakdown.

4. **UI (2.5):** `create.blade.php` item table now has an "Available (physical / pipeline)" column showing a breakdown like `70.00 (100.00 physical, 30.00 pipeline)`. Real-time qty-vs-available warning highlights the qty input and available cell in red when over-committed. A SweetAlert submit guard blocks form submission if any row requests more than available. An info banner explains the pipeline concept.

### Verification

- [ ] Transfer of 80 units rejected when only 70 available (100 physical, 30 pipeline)
- [ ] Transfer of 70 units accepted when 70 available
- [ ] Confirm rejected when stock changed between draft creation and confirm
- [ ] UI shows both physical and available quantities
- [ ] API returns pipeline-aware availability

---

## 8. Phase 3 — Reversal Safety & Ordering

**Priority:** High  
**Duration:** 1-2 days  
**Goal:** Ensure that reversal of stock movements follows the correct order (dest IN before source OUT) to prevent insufficient stock errors.

### 3.1 Implement sortMovementsForReversal

**File:** `app/Services/Stock/WarehouseTransferService.php`

Add the same sorting logic as the legacy:

```php
/**
 * Sort stock movements for safe reversal.
 * Destination IN (positive qty) movements are reversed FIRST,
 * then source OUT (negative qty) movements.
 * This prevents "insufficient stock at receiver" errors during reversal.
 *
 * @param \Illuminate\Support\Collection $movements
 * @return \Illuminate\Support\Collection
 */
private function sortMovementsForReversal($movements)
{
    return $movements->sort(function ($a, $b) {
        $qa = (float) $a->qty;
        $qb = (float) $b->qty;
        // Positive qty (dest IN) first
        if ($qa > 0 && $qb <= 0) return -1;
        if ($qa <= 0 && $qb > 0) return 1;
        // Secondary: by ID descending (most recent first)
        return (int) $b->id <=> (int) $a->id;
    })->values();
}
```

### 3.2 Use sortMovementsForReversal in cancelTransfer

**File:** `app/Services/Stock/WarehouseTransferService.php`

```php
// In cancelTransfer(), replace:
$stockTxs = DB::table('stock_transactions')
    ->where('reference_type', 'warehouse_transfer')
    ->where('reference_id', $transferId)
    ->where('is_reversed', false)
    ->get();

// With:
$stockTxs = $this->sortMovementsForReversal(
    DB::table('stock_transactions')
        ->where('reference_type', 'warehouse_transfer')
        ->where('reference_id', $transferId)
        ->where('is_reversed', false)
        ->get()
);
```

### 3.3 Add Demand-Linked Reversal Protection

**File:** `app/Services/Stock/WarehouseTransferService.php`

The legacy prevents reversing transfers linked to a Branch Demand. Since we're adding `branch_demand_id` support (Phase 4), add this check:

```php
// In cancelTransfer():
if ($transfer->branch_demand_id) {
    throw new \RuntimeException(
        'This transfer is linked to a branch demand. ' .
        'Reverse the demand instead of the transfer.'
    );
}
```

### 3.4 Warehouse Freeze Check During Transfer Creation

**File:** `app/Services/Stock/WarehouseTransferService.php`

The Laravel `StockService::applyTransaction()` already checks for warehouse freeze during OUT movements. However, the draft creation doesn't check. Add a pre-check:

```php
// In createTransfer():
$fromWarehouse = Warehouse::find($fromWarehouseId);
if ($fromWarehouse->is_frozen_for_count) {
    throw new \RuntimeException(
        'Source warehouse is frozen for stock counting. ' .
        'Transfers cannot be created until the count is completed.'
    );
}
```

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 3.1 | sortMovementsForReversal method | `WarehouseTransferService.php` |
| 3.2 | Use sorted movements in cancelTransfer | `WarehouseTransferService.php` |
| 3.3 | Demand-linked reversal protection | `WarehouseTransferService.php` |
| 3.4 | Warehouse freeze check on creation | `WarehouseTransferService.php` |

### Verification

- [ ] Reversal processes dest IN movements before source OUT movements
- [ ] Demand-linked transfers cannot be cancelled via WarehouseTransfer
- [ ] Draft creation fails when source warehouse is frozen for stock-take
- [ ] Reversal of a confirmed transfer works correctly (stock restored to source, removed from dest)

---

## 9. Phase 4 — Audit Trail & Data Integrity

**Priority:** Medium  
**Duration:** 2-3 days  
**Goal:** Ensure every transfer operation has a complete audit trail and data integrity is verified.

### 4.1 Add branch_demand_id Column

**New migration:**

```php
Schema::table('warehouse_transfers', function (Blueprint $table) {
    $table->unsignedBigInteger('branch_demand_id')->nullable()->after('to_warehouse_id');
    $table->foreign('branch_demand_id')
        ->references('id')->on('branch_demands')
        ->nullOnDelete();
});
```

This allows the Branch Demand module to create documentary `warehouse_transfers` rows (as it does in the legacy), while preventing reversal through the WarehouseTransfer module.

### 4.2 Add Audit Logging to Service

**File:** `app/Services/Stock/WarehouseTransferService.php`

Since the service uses `DB::table()` which bypasses Eloquent events, add explicit audit logging:

```php
// After creating a transfer:
DB::table('audit_log')->insert([
    'auditable_type' => 'WarehouseTransfer',
    'auditable_id' => $transferId,
    'action' => 'created',
    'old_values' => null,
    'new_values' => json_encode([
        'transfer_code' => $transferCode,
        'from_warehouse_id' => $fromWarehouseId,
        'to_warehouse_id' => $toWarehouseId,
        'items_count' => count($validatedItems),
    ]),
    'user_id' => $data['created_by'],
    'created_at' => now(),
]);

// After confirming:
DB::table('audit_log')->insert([
    'auditable_type' => 'WarehouseTransfer',
    'auditable_id' => $transferId,
    'action' => 'confirmed',
    'old_values' => json_encode(['status' => 'draft']),
    'new_values' => json_encode(['status' => 'confirmed']),
    'user_id' => $confirmedBy,
    'created_at' => now(),
]);

// After cancelling:
DB::table('audit_log')->insert([
    'auditable_type' => 'WarehouseTransfer',
    'auditable_id' => $transferId,
    'action' => 'cancelled',
    'old_values' => json_encode(['status' => $transfer->status]),
    'new_values' => json_encode([
        'status' => 'cancelled',
        'is_reversed' => $transfer->isConfirmed(),
        'reverse_reason' => $reason,
    ]),
    'user_id' => $cancelledBy,
    'created_at' => now(),
]);
```

### 4.3 WarehouseTransferAuditModel Equivalent

Create a new service class for transfer-specific health checks:

**File:** `app/Services/Stock/WarehouseTransferAuditService.php`

```php
class WarehouseTransferAuditService
{
    /**
     * Run health checks for all transfers.
     */
    public function runHealthChecks(): array
    {
        return [
            'same_branch' => $this->checkSameBranch(),
            'stock_movements' => $this->checkStockMovements(),
            'data_quality' => $this->checkDataQuality(),
            'gl_integrity' => $this->checkGlIntegrity(),
        ];
    }

    /**
     * Check: All transfers should be same-branch.
     */
    private function checkSameBranch(): array
    {
        $violations = DB::table('warehouse_transfers')
            ->whereColumn('from_branch_id', '!=', 'to_branch_id')
            ->whereNull('deleted_at')
            ->count();

        return [
            'section' => 'Same-Branch Rule',
            'status' => $violations === 0 ? 'PASS' : 'FAIL',
            'message' => $violations === 0
                ? 'All transfers are same-branch.'
                : "Found {$violations} cross-branch transfers.",
            'count' => $violations,
        ];
    }

    /**
     * Check: Every confirmed transfer must have stock movements.
     */
    private function checkStockMovements(): array
    {
        // ... (check that every confirmed transfer has stock_transactions)
    }

    /**
     * Check: No zero-rate items on confirmed transfers.
     */
    private function checkDataQuality(): array
    {
        // ... (check for zero-rate items)
    }

    /**
     * Check: Same-branch transfers should NOT have GL journals.
     */
    private function checkGlIntegrity(): array
    {
        // ... (same-branch: no GL; cross-branch: both journals present)
    }
}
```

### 4.4 Add Audit Checklist Route

**File:** `routes/web.php`

```php
Route::get('admin/warehouse-transfers/checklist', [WarehouseTransferController::class, 'checklist'])
    ->name('admin.warehouse-transfers.checklist');
Route::post('admin/warehouse-transfers/run-checks', [WarehouseTransferController::class, 'runChecks'])
    ->name('admin.warehouse-transfers.run-checks');
```

### 4.5 Stock Reconciliation Helper

Add a helper method that verifies the fundamental invariant:

```
For every warehouse W and product P:
  SUM(stock_transactions.qty) WHERE warehouse_id=W AND product_id=P AND is_reversed=false
  = warehouse_stock.qty WHERE warehouse_id=W AND product_id=P
```

This can be run as a scheduled job or on-demand.

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 4.1 | branch_demand_id column | New migration |
| 4.2 | Audit logging in service | `WarehouseTransferService.php` |
| 4.3 | WarehouseTransferAuditService | New file |
| 4.4 | Audit checklist routes | `web.php` + Controller |
| 4.5 | Stock reconciliation helper | New file |

### Verification

- [ ] Every transfer create/confirm/cancel creates an audit_log entry
- [ ] Health checks detect cross-branch transfers
- [ ] Health checks detect missing stock movements
- [ ] Health checks detect zero-rate items
- [ ] branch_demand_id column exists and is nullable
- [ ] Stock reconciliation invariant holds

---

## 10. Phase 5 — UI Parity & UX Improvements

**Priority:** Medium  
**Duration:** 2-3 days  
**Goal:** Ensure the Laravel UI matches the legacy UI's functionality and adds the same-branch guard.

### 5.1 Update Create Form — Same-Branch Guard

**File:** `resources/views/admin/warehouse-transfers/create.blade.php`

- Remove the interbranch informational banner (since we're enforcing same-branch only)
- Add a clear "Same-branch transfer" indicator
- Show the branch name next to each warehouse dropdown
- Add client-side validation that blocks cross-branch selections

### 5.2 Update Create Form — Stock Info Display

- Show available qty (pipeline-aware) next to each product
- Show physical qty and pipeline qty separately
- Color-code: green if available > 0, red if available = 0

### 5.3 Update Index View — Filters

**File:** `resources/views/admin/warehouse-transfers/index.blade.php`

- Add date range filter (default: today)
- Add from/to warehouse filter
- Add status filter (All / Draft / Confirmed / Cancelled / Reversed)
- Remove interbranch filter (no longer relevant)
- Add search by transfer code

### 5.4 Update Show View — Reversal Info

**File:** `resources/views/admin/warehouse-transfers/show.blade.php`

- Show reversal info (if reversed): reason, timestamp, user
- Show "Same-branch transfer" badge (instead of interbranch badge)
- Remove interbranch GL section (not applicable for same-branch)

### 5.5 Add Warehouse Name + Branch Name in Dropdowns

Each warehouse option should show:
```
W-1 (Warehouse Name) — Branch-A
```

This makes it clear which branch each warehouse belongs to.

### 5.6 Confirm Button UX

- Draft transfers: Show "Confirm" button (green)
- Confirmed transfers: Show "Cancel" button (red) with reason input
- Cancelled/Reversed transfers: Show "Reversed" badge (no action buttons)

### 5.7 Transfer Print View

Add a printable transfer document (similar to the legacy's challan/godown copy concept):

- Transfer code, date, route
- From warehouse → To warehouse
- Branch name
- Item table: Product, Qty, Rate, Amount
- Total amount
- Created by, Confirmed by

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 5.1 | Same-branch guard in create form | `create.blade.php` |
| 5.2 | Pipeline-aware stock info | `create.blade.php` |
| 5.3 | Index filters | `index.blade.php` |
| 5.4 | Reversal info in show view | `show.blade.php` |
| 5.5 | Warehouse + branch in dropdowns | `create.blade.php` |
| 5.6 | Confirm/cancel button UX | `show.blade.php` |
| 5.7 | Print view | New blade file |

### Verification

- [ ] Create form only shows same-branch warehouses
- [ ] Cross-branch selection blocked with clear error message
- [ ] Stock info shows available (pipeline-aware) qty
- [ ] Index page has all filters
- [ ] Show page has reversal info
- [ ] Print view generates correctly

---

## 11. Phase 6 — Export, Reporting & Branch Ledger Settlement

**Priority:** Medium  
**Duration:** 2-3 days  
**Goal:** Add CSV export and reporting capabilities matching the legacy system.

### 6.1 CSV Export

**File:** `app/Http/Controllers/Admin/WarehouseTransferController.php`

```php
public function export(Request $request)
{
    $filters = $request->only([
        'date_from', 'date_to', 'from_warehouse_id',
        'to_warehouse_id', 'status'
    ]);

    $transfers = $this->getFilteredTransfers($filters);

    $filename = 'Warehouse_Transfers_' . now()->format('Y-m-d_His') . '.csv';

    $headers = [
        'Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => "attachment; filename=\"{$filename}\"",
    ];

    $callback = function () use ($transfers) {
        $file = fopen('php://output', 'w');
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel

        fputcsv($file, [
            'Date', 'Code', 'From WH', 'To WH',
            'Branch', 'Amount', 'Demand', 'Status',
            'Created By'
        ]);

        foreach ($transfers as $t) {
            fputcsv($file, [
                $t->transfer_date,
                $t->transfer_code,
                $t->fromWarehouse?->warehouse_name,
                $t->toWarehouse?->warehouse_name,
                $t->fromBranch?->branch_name,
                $t->total_amount,
                $t->branch_demand_id ? 'Yes' : 'No',
                $t->is_reversed ? 'Reversed' : $t->status,
                $t->createdBy?->username ?? 'System',
            ]);
        }

        fclose($file);
    };

    return response()->stream($callback, 200, $headers);
}
```

### 6.2 Add Export Route

**File:** `routes/web.php`

```php
Route::get('admin/warehouse-transfers/export', [WarehouseTransferController::class, 'export'])
    ->name('admin.warehouse-transfers.export');
```

### 6.3 Transfer Summary Report

Add a summary report showing:
- Total transfers per branch per period
- Total value per branch per period
- Most transferred products
- Average transfer size

### 6.4 Branch Ledger Settlement Note

**Out of scope for this document.** The branch ledger settlement is a separate accounting module. However, since we're enforcing same-branch only transfers, **no branch_ledger entries will be created** for warehouse transfers (same-branch = no intercompany GL). This gap is effectively closed by Phase 1.

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 6.1 | CSV export method | `WarehouseTransferController.php` |
| 6.2 | Export route | `web.php` |
| 6.3 | Summary report | New blade file + controller method |

### Verification

- [ ] CSV export generates correct file with all columns
- [ ] CSV respects current filters
- [ ] BOM character present for Excel compatibility
- [ ] Summary report shows correct aggregates

---

## 12. Phase 7 — Test Coverage & Shadow Mode

**Priority:** High  
**Duration:** 3-4 days  
**Goal:** Comprehensive test coverage for the WarehouseTransfer workflow, including edge cases.

### 7.1 Test File Structure

```
tests/
  Feature/
    WarehouseTransfer/
      CreateTransferTest.php       — Draft creation tests
      ConfirmTransferTest.php      — Confirm flow tests
      CancelTransferTest.php       — Cancel/reversal tests
      SameBranchGuardTest.php      — Same-branch enforcement tests
      StockAvailabilityTest.php    — Pipeline-aware availability tests
      ReversalOrderingTest.php     — Reversal ordering tests
      AuditTrailTest.php           — Audit logging tests
      ExportTest.php               — CSV export tests
      BranchIsolationTest.php      — RLS + BranchScope tests
```

### 7.2 Key Test Scenarios

**CreateTransferTest:**
- [ ] Create draft with valid same-branch warehouses succeeds
- [ ] Create draft with cross-branch warehouses fails
- [ ] Create draft with same from/to warehouse fails
- [ ] Create draft with no items fails
- [ ] Create draft with zero qty items fails
- [ ] Create draft with rate auto-fill from avg_cost
- [ ] Create draft with insufficient stock at source fails
- [ ] Create draft with frozen source warehouse fails

**ConfirmTransferTest:**
- [ ] Confirm draft succeeds (stock moves, status=confirmed)
- [ ] Confirm non-draft fails
- [ ] Confirm already confirmed fails
- [ ] Confirm with insufficient stock at confirm time fails
- [ ] Confirm same-branch transfer has no GL journals
- [ ] Stock movements created with correct qty and rate
- [ ] Destination avg_cost recalculated correctly

**CancelTransferTest:**
- [ ] Cancel draft succeeds (status=cancelled, no stock movement)
- [ ] Cancel confirmed transfer succeeds (stock reversed, GL reversed)
- [ ] Cancel already cancelled fails
- [ ] Cancel requires reason for confirmed transfers
- [ ] Reversal ordering: dest IN before source OUT
- [ ] Demand-linked transfer cannot be cancelled

**SameBranchGuardTest:**
- [ ] Cross-branch transfer rejected at service level
- [ ] Cross-branch transfer rejected at controller level
- [ ] Cross-branch transfer rejected at DB level (trigger)
- [ ] Cross-branch transfer rejected at client level
- [ ] Admin cannot create cross-branch transfer
- [ ] Warehouse dropdown only shows user's branch

**StockAvailabilityTest:**
- [ ] Transfer respects pipeline-aware availability
- [ ] Transfer of 80 rejected when only 70 available (100 physical, 30 pipeline)
- [ ] Confirm-time availability check catches stock changes

**ReversalOrderingTest:**
- [ ] Dest IN movements reversed before source OUT
- [ ] Reversal of partial transfer (some items already sold) handled gracefully

**BranchIsolationTest:**
- [ ] User can only see transfers involving their branch
- [ ] User cannot confirm transfers from other branches
- [ ] RLS policy blocks cross-branch reads

### 7.3 Shadow Mode Integration

After all tests pass, enable shadow mode for the WarehouseTransfer module:

1. Legacy and Laravel both run against the same PostgreSQL database
2. Every transfer operation is executed by both systems
3. Results are compared (stock movements, GL, status)
4. Zero diffs for 7 consecutive days → cutover

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 7.1 | Test file structure | `tests/Feature/WarehouseTransfer/` |
| 7.2 | All test scenarios | 8 test files |
| 7.3 | Shadow mode integration | Documentation |

### Verification

- [ ] All tests pass
- [ ] Code coverage ≥ 85% for WarehouseTransfer module
- [ ] Shadow mode produces zero diffs for 7 days

---

## 13. Phase 8 — API Routes & Mobile Support

**Priority:** Low  
**Duration:** 2-3 days  
**Goal:** Add API routes for warehouse transfers so mobile/API users can create/confirm/cancel transfers.

### 8.1 API Routes

**File:** `routes/api.php`

```php
Route::prefix('v1/warehouse-transfers')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WarehouseTransferApiController::class, 'index']);
    Route::post('/', [WarehouseTransferApiController::class, 'store']);
    Route::get('/{id}', [WarehouseTransferApiController::class, 'show']);
    Route::post('/{id}/confirm', [WarehouseTransferApiController::class, 'confirm']);
    Route::post('/{id}/cancel', [WarehouseTransferApiController::class, 'cancel']);
    Route::get('/product-stock', [WarehouseTransferApiController::class, 'productStock']);
});
```

### 8.2 API Controller

**File:** `app/Http/Controllers/Api/WarehouseTransferApiController.php`

- Reuse the same `WarehouseTransferService` as the web controller
- Add proper API resource responses
- Apply same-branch enforcement
- Apply branch isolation middleware

### 8.3 API Resources

**File:** `app/Http/Resources/WarehouseTransferResource.php`

```php
class WarehouseTransferResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'transfer_code' => $this->transfer_code,
            'transfer_date' => $this->transfer_date->format('Y-m-d'),
            'from_warehouse' => [
                'id' => $this->from_warehouse_id,
                'name' => $this->fromWarehouse?->warehouse_name,
            ],
            'to_warehouse' => [
                'id' => $this->to_warehouse_id,
                'name' => $this->toWarehouse?->warehouse_name,
            ],
            'branch' => $this->fromBranch?->branch_name,
            'status' => $this->status,
            'is_reversed' => $this->is_reversed,
            'total_amount' => $this->total_amount,
            'items' => WarehouseTransferItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

### Deliverables

| # | Deliverable | File |
|---|-------------|------|
| 8.1 | API routes | `routes/api.php` |
| 8.2 | API controller | New file |
| 8.3 | API resources | New files |

### Verification

- [ ] API index returns paginated transfers for user's branch
- [ ] API store creates same-branch draft
- [ ] API store rejects cross-branch transfer
- [ ] API confirm applies stock movements
- [ ] API cancel reverses stock movements
- [ ] API product-stock returns pipeline-aware availability
- [ ] Proper authentication required

---

## Appendix A — Database Schema Reference

### Current PostgreSQL Schema (warehouse_transfers)

```sql
CREATE TABLE warehouse_transfers (
    id                    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_code         varchar(30) NOT NULL UNIQUE,
    transfer_date         date NOT NULL,
    from_warehouse_id     integer NOT NULL REFERENCES warehouses(id),
    to_warehouse_id       integer NOT NULL REFERENCES warehouses(id),
    from_branch_id        integer NOT NULL REFERENCES branches(id),
    to_branch_id          integer NOT NULL REFERENCES branches(id),
    is_interbranch        boolean NOT NULL DEFAULT false,
    status                varchar(20) NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft','confirmed','cancelled')),
    journal_entry_id      integer REFERENCES journal_entries(id),
    journal_entry_id_debtor integer REFERENCES journal_entries(id),
    is_reversed           boolean NOT NULL DEFAULT false,
    reversed_at           timestamp(0),
    reversed_by           integer,
    reverse_reason        text,
    notes                 text,
    created_by            integer,
    created_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    deleted_at            timestamp(0)
);
```

### Schema Changes After All Phases

```sql
-- Phase 1: Same-branch trigger
CREATE TRIGGER trg_enforce_same_branch_transfer
BEFORE INSERT OR UPDATE ON warehouse_transfers
FOR EACH ROW EXECUTE FUNCTION enforce_same_branch_transfer();

-- Phase 4: Add branch_demand_id column
ALTER TABLE warehouse_transfers
ADD COLUMN branch_demand_id integer REFERENCES branch_demands(id) ON DELETE SET NULL;

-- Phase 4: Audit log table (if not exists)
CREATE TABLE IF NOT EXISTS audit_log (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    auditable_type varchar(100) NOT NULL,
    auditable_id integer NOT NULL,
    action varchar(50) NOT NULL,
    old_values jsonb,
    new_values jsonb,
    user_id integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_log_auditable ON audit_log(auditable_type, auditable_id);
CREATE INDEX idx_audit_log_user ON audit_log(user_id);
```

---

## Appendix B — Business Rule Matrix

### Same-Branch Transfer Rules

| # | Rule | Enforced At | Phase |
|---|------|-------------|-------|
| R1 | Both warehouses must belong to the same branch | Service + Controller + DB trigger + Client JS | 1 |
| R2 | User can only transfer between warehouses in their branch | Service + Controller | 1 |
| R3 | Admin can see all branches but cannot create cross-branch transfers | Service | 1 |
| R4 | From and To warehouse must be different | Controller + Service + Client JS | Existing |
| R5 | At least one product line required | Controller + Service | Existing |
| R6 | Rate defaults to source warehouse avg_cost | Service | Existing |
| R7 | Stock must be available at source (pipeline-aware) | Service + Validation Rule | 2 |
| R8 | Re-check availability at confirm time | Service | 2 |
| R9 | Source warehouse cannot be frozen for stock-take | Service | 3 |
| R10 | Reversal order: dest IN before source OUT | Service | 3 |
| R11 | Demand-linked transfers cannot be cancelled | Service | 3 |
| R12 | Every operation creates audit log entry | Service | 4 |
| R13 | Same-branch transfers have NO GL | Service | Existing |
| R14 | Transfer code is atomic (no duplicates) | DocumentSequenceService | Existing |
| R15 | Soft deletes supported | Model | Existing |
| R16 | Two-phase flow: draft → confirm → cancel | Service | Existing |

### Stock Movement Rules

| # | Rule | Detail |
|---|------|--------|
| S1 | Source OUT: qty = -itemQty, rate = source avg_cost | avg_cost unchanged at source |
| S2 | Dest IN: qty = +itemQty, rate = source avg_cost | avg_cost recalculated at dest |
| S3 | Moving average costing: IN recalculates avg_cost | Weighted average formula |
| S4 | Non-negative stock: qty >= -0.0001 | DB CHECK + trigger + application check |
| S5 | Pipeline-aware: available = physical - open dispatches | StockAvailabilityService |
| S6 | Stock movements are immutable (append-only) | stock_transactions table |
| S7 | Reversal creates opposite-sign rows | Original rows never mutated |

### GL Rules (Same-Branch — No GL)

| # | Rule | Detail |
|---|------|--------|
| G1 | Same-branch transfer: NO GL journal | Inventory is just reallocated within the branch |
| G2 | Cross-branch transfer: NOT ALLOWED in this module | Use Branch Demand instead |
| G3 | If a cross-branch transfer somehow exists: no GL should be posted | Data cleanup required |

---

## Appendix C — File Inventory

### Files to Modify

| File | Phase | Changes |
|------|-------|---------|
| `app/Services/Stock/WarehouseTransferService.php` | 1, 2, 3, 4 | Same-branch enforcement, pipeline-aware availability, reversal ordering, audit logging |
| `app/Http/Controllers/Admin/WarehouseTransferController.php` | 1, 2, 5, 6 | Branch guard, validation rules, warehouse filtering, pipeline-aware API, export |
| `app/Models/WarehouseTransfer.php` | 1 | BranchScope, branch_demand_id to fillable |
| `resources/views/admin/warehouse-transfers/create.blade.php` | 1, 5 | Same-branch guard, pipeline info, warehouse dropdown |
| `resources/views/admin/warehouse-transfers/index.blade.php` | 5 | Filters, remove interbranch |
| `resources/views/admin/warehouse-transfers/show.blade.php` | 5 | Reversal info, same-branch badge |
| `routes/web.php` | 4, 6 | Audit checklist routes, export route |
| `routes/api.php` | 8 | API routes |

### Files to Create

| File | Phase | Purpose |
|------|-------|---------|
| `database/migrations/2025_XX_XX_add_branch_demand_id_to_warehouse_transfers.php` | 4 | branch_demand_id column |
| `database/migrations/2025_07_28_000010_add_same_branch_trigger_to_warehouse_transfers.php` | 1 ✅ | Same-branch enforcement trigger |
| `app/Models/Scopes/WarehouseTransferBranchScope.php` | 1 ✅ | Branch scope for WarehouseTransfer model |
| `app/Rules/WarehouseTransferItemHasAvailableStock.php` | 2 ✅ | Pipeline-aware stock availability validation rule for transfer items |
| `database/migrations/2025_XX_XX_create_audit_log_table.php` | 4 | Audit log table |
| `app/Services/Stock/WarehouseTransferAuditService.php` | 4 | Health checks |
| `app/Http/Controllers/Api/WarehouseTransferApiController.php` | 8 | API controller |
| `app/Http/Resources/WarehouseTransferResource.php` | 8 | API resource |
| `app/Http/Resources/WarehouseTransferItemResource.php` | 8 | API resource |
| `resources/views/admin/warehouse-transfers/checklist.blade.php` | 4 | Audit checklist view |
| `resources/views/admin/warehouse-transfers/print.blade.php` | 5 | Print view |
| `tests/Feature/WarehouseTransfer/CreateTransferTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/ConfirmTransferTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/CancelTransferTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/SameBranchGuardTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/StockAvailabilityTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/ReversalOrderingTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/AuditTrailTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/ExportTest.php` | 7 | Tests |
| `tests/Feature/WarehouseTransfer/BranchIsolationTest.php` | 7 | Tests |

---

## Post-Implementation State

After all 8 phases are complete, the Warehouse Transfer module will have:

1. ✅ **Strict same-branch enforcement** at every layer (service, controller, DB, client)
2. ✅ **Two-phase flow** (draft → confirm → cancel) with proper stock movement
3. ✅ **Pipeline-aware stock availability** preventing over-commitment (Phase 2 complete)
4. ✅ **Correct reversal ordering** (dest IN before source OUT)
5. ✅ **Complete audit trail** for every operation
6. ✅ **Data integrity checks** via WarehouseTransferAuditService
7. ✅ **UI parity** with legacy system plus improvements
8. ✅ **CSV export** and reporting
9. ✅ **Comprehensive test coverage** (≥ 85%)
10. ✅ **API routes** for mobile/API users
11. ✅ **Defense-in-depth** (BranchScope + RLS + DB trigger + validation rules)

The module will be a **production-ready inner-branch warehouse transfer** system that matches and exceeds the legacy system's safeguards, built on Laravel 11 + PostgreSQL 16 with proper accounting principles.
