# Branch Demand — Complete Implementation Plan for Laravel ERP

**Document version:** 1.0  
**Date:** 2026-07-29  
**Scope:** Cross-Branch Demand / Supply Transfer System with Accountability, Audit, and Price Range Handling  
**Target stack:** Laravel 11 + PostgreSQL 16  
**Source of truth:** Legacy PHP/MySQL system (fully functional) + User-provided Excel audit sheet ("MAIN BILL SHIT1.xlsx")  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Business Context & Real-World Scenario](#2-business-context--real-world-scenario)
3. [Legacy System Analysis](#3-legacy-system-analysis)
4. [Laravel System Analysis](#4-laravel-system-analysis)
5. [Gap Analysis](#5-gap-analysis)
6. [The Excel Audit Sheet — What the Business Actually Needs](#6-the-excel-audit-sheet--what-the-business-actually-needs)
7. [Price Range Challenge](#7-price-range-challenge)
8. [Implementation Phases](#8-implementation-phases)
   - [Phase 1 — Schema Alignment & Foundation](#phase-1--schema-alignment--foundation)
   - [Phase 2 — Core Demand CRUD & Send Flow](#phase-2--core-demand-crud--send-flow)
   - [Phase 3 — Intercompany GL & Branch Ledger](#phase-3--intercompany-gl--branch-ledger)
   - [Phase 4 — FIFO Settlement (Bank Payments + Money Transfers)](#phase-4--fifo-settlement-bank-payments--money-transfers)
   - [Phase 5 — Warehouse Manager Confirmation (Receipt Acknowledgment)](#phase-5--warehouse-manager-confirmation-receipt-acknowledgment)
   - [Phase 6 — Weekly Audit Report (The Excel Sheet Replication)](#phase-6--weekly-audit-report-the-excel-sheet-replication)
   - [Phase 7 — Price Range Handling & Repricing Logic](#phase-7--price-range-handling--repricing-logic)
   - [Phase 8 — Anti-Gaming & Accountability Controls](#phase-8--anti-gaming--accountability-controls)
   - [Phase 9 — UI, Views & Frontend](#phase-9--ui-views--frontend)
   - [Phase 10 — API Routes, Test Coverage & Shadow Mode](#phase-10--api-routes-test-coverage--shadow-mode)
9. [Database Schema — Final Target State](#9-database-schema--final-target-state)
10. [Risk Matrix](#10-risk-matrix)
11. [Appendix A — Excel Sheet Column Mapping](#appendix-a--excel-sheet-column-mapping)
12. [Appendix B — Legacy vs Laravel Status Enum Mapping](#appendix-b--legacy-vs-laravel-status-enum-mapping)
13. [Appendix C — Key Business Rules Reference](#appendix-c--key-business-rules-reference)

---

## 1. Executive Summary

The Branch Demand module is the **cross-branch product supply system** in the ERP. It enables one branch (the debtor/requester) to request products from another branch (the creditor/supplier), have those products transferred between warehouses, track the financial liability between branches, and settle the outstanding balance through bank payments or inter-branch money transfers.

**Current state:**
- **Legacy system** — Fully functional with demand creation, send flow, reversal, intercompany GL, branch ledger, FIFO settlement, anti-gaming controls, weekly reports, and audit checklists.
- **Laravel system** — Only the database tables exist (PostgreSQL). No models, no controllers, no services, no views. The `WarehouseTransfer::branchDemand()` relationship references a non-existent `BranchDemand` model, causing a runtime break. The weekly report is a minimal stub that only shows a paginated list of demands.

**Target state:**
- A production-ready Branch Demand module in Laravel that replicates and improves upon the legacy system.
- Proper handling of the **price range** (min/max/default) challenge that the legacy system does not address.
- A **weekly audit report** that replicates the user's Excel sheet ("MAIN BILL SHIT1.xlsx") on a single page, eliminating the need to visit multiple reports and manually compile data.
- **Warehouse manager confirmation** flow so the receiving branch cannot later claim ignorance of product receipt.
- Complete **accountability and audit trail** so both branches can understand the full financial position at any time.

---

## 2. Business Context & Real-World Scenario

### 2.1 The Problem

Consider two branches: **Branch A** (supplier) and **Branch B** (requester).

- Branch A has five warehouses: a, b, c, d, e, f
- Branch B has three warehouses: x, y, z

**Scenario:**
Branch B's sales manager discovers that a product is needed for a sale but is not in stock at any of Branch B's warehouses. The manager must create a **demand** to Branch A, requesting that Branch A supply the product directly to one of Branch B's warehouses so that the product can be sold.

**The demand flow:**
1. Sales manager of Branch B creates a demand selecting products and quantities needed.
2. Branch A's warehouse manager opens the pending demand, selects per-product which warehouse to send FROM (a, b, c, d, e, f) and which warehouse of Branch B to send TO (x, y, z).
3. A **stock availability check** ensures no negative stock happens — only available stock (physical minus sales pipeline) can be transferred.
4. After the goods are marked as "sent," Branch B's warehouse manager and sales manager can see the stock increase and can now sell the product.

### 2.2 Accountability Questions

After the goods are sent, several accountability questions arise:

1. **Did the warehouse manager actually receive the product?** — The receiving warehouse manager must confirm receipt, so they cannot later claim ignorance of the transfer.
2. **How does Branch B ensure accountability of product stock and money after sale?** — A weekly audit must answer:
   - **(a)** How many demands were approved from A to B, and what is the total product worth?
   - **(b)** How much stock does Branch B currently have?
   - **(c)** How much does Branch B owe (due) to Branch A?
   - **(d)** Any costs Branch B incurred from sales (expenses)?
   - **(e)** How much money was received from customers by Bank? (Bank is central, not branch-specific — if B receives a customer payment by Bank, it means B has already paid it toward the obligation.)
   - **(f)** How much money was transferred from Cash of B to Cash of A, or Cash of B to Bank? (Separate money transfer menu exists.)
   - **(g)** How much damage occurred?
   - **(h)** How much stock adjustment happened?
   - **(i)** Were any products transferred from Branch B back to Branch A during the period?

From these data points, both A and B can understand the full transparency of the transaction and process.

### 2.3 Price Range Challenge

The ERP does not maintain a single price per product. Instead, it maintains a **price range**: max, min, and default.

**Example:** During demand approval, the price range for a product was: min=100, max=120, default=115. Branch B's sales manager can sell the product within this range. However, when the weekly audit happens, the product price may have changed. This raises the question: **Is Branch B giving Branch A the accurate money they owe?** How will a new price (increase or decrease) affect the audit system?

This is a gap in the legacy system that the Laravel implementation must address.

---

## 3. Legacy System Analysis

### 3.1 Architecture

The legacy system is a procedural PHP/MySQL application with the following structure:

| Component | File | Role |
|-----------|------|------|
| Controller | `app/controllers/BranchDemandController.php` | HTTP routing, all CRUD + send/reverse/delete/export |
| Model | `app/models/BranchDemandModel.php` | Core business logic: create, send, reverse, delete, filter, settlements, stock trace |
| Intercompany Service | `app/services/Branch/BranchIntercompanyService.php` | FIFO settlement, ledger pair posting, running balance, reversal |
| Journal Service | `app/services/Accounting/JournalPostingService.php` | `postBranchDemandFulfillment()`, `postBranchDemandSettlement()`, `postWarehouseTransferInterbranch()` |
| Helper | `app/helpers/Helper.php` | `Assert_Warehouse_Lines_Available()`, `Get_Warehouse_Wise_Product_Stock()`, `Get_Warehouse_By_Branch()` |
| Stock Service | `app/services/Stock/StockService.php` | `updateWarehouseStock()`, `logMovement()`, `getWarehouseAvgCost()`, `assertAvailable()` |
| Audit Model | `app/models/BranchIntercompanyAuditModel.php` | GL health checks: journal links, ledger nature, demand GL alignment, JE balance |
| Weekly Report | `app/models/Reports/BranchIntercompanyWeeklyReport.php` | Weekly control report: demands, settlements, floor stock, anti-gaming flags |
| GL Helper | `app/helpers/InterbranchGlAuditHelper.php` | Demand/WT journal block rendering for detail views |
| Views | `app/views/BranchDemand/` | create.php, index.php, pending.php, details.php, weekly.php, checklist.php |
| Frontend JS | `public/assets/js/BranchDemand.js` | Create form, send goods, reverse, delete, warehouse selectors |
| CSS | `public/assets/css/branch-demand.css`, `branch-demand-weekly.css` | Styling |

### 3.2 Database Schema (MySQL)

#### `branch_demands`
```sql
CREATE TABLE branch_demands (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    demand_code           VARCHAR(50) NOT NULL,
    from_branch_id        INT NOT NULL,                  -- Requester (debtor)
    to_branch_id          INT NOT NULL,                  -- Supplier (creditor)
    demand_date           DATE NOT NULL,
    status                ENUM('pending','received','rejected','reversed') DEFAULT 'pending',
    total_value           DECIMAL(12,2) DEFAULT NULL,    -- Set when goods sent (locked cost)
    settlement_amount     DECIMAL(12,2) DEFAULT 0,       -- Running total of FIFO settlements
    warehouse_transfer_id BIGINT(20) DEFAULT NULL,       -- Linked warehouse_transfers.id
    journal_entry_id      BIGINT(20) DEFAULT NULL,       -- Creditor-branch fulfillment journal
    journal_entry_id_debtor BIGINT(20) DEFAULT NULL,     -- Debtor-branch fulfillment journal
    is_reversed           TINYINT(1) DEFAULT 0,
    reversed_at           DATETIME DEFAULT NULL,
    reversed_by           INT DEFAULT NULL,
    reverse_reason        TEXT DEFAULT NULL,
    created_by            INT DEFAULT NULL,
    created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `branch_demand_items`
```sql
CREATE TABLE branch_demand_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    branch_demand_id    INT NOT NULL,
    product_id          INT NOT NULL,
    qty                 DECIMAL(12,2) NOT NULL,
    cost_rate           DECIMAL(12,4) DEFAULT 0,         -- Locked at send time (warehouse avg cost)
    from_warehouse_id   INT DEFAULT NULL,                 -- Set when goods sent
    to_warehouse_id     INT DEFAULT NULL                  -- Set when goods sent
);
```

#### `branch_ledger`
```sql
CREATE TABLE branch_ledger (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_date  DATE NOT NULL,
    from_branch_id    INT NOT NULL,                      -- Debtor branch
    to_branch_id      INT NOT NULL,                      -- Creditor branch
    reference_type    VARCHAR(50) DEFAULT 'adjustment',  -- 'demand_transfer', 'demand_settlement'
    reference_id      INT DEFAULT NULL,
    journal_entry_id  BIGINT DEFAULT NULL,
    debit             DECIMAL(12,2) DEFAULT 0,
    credit            DECIMAL(12,2) DEFAULT 0,
    running_balance   DECIMAL(12,2) DEFAULT NULL,
    remarks           TEXT,
    is_reversed       TINYINT(1) DEFAULT 0,
    created_by        INT DEFAULT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### `money_transfer_settlements`
```sql
CREATE TABLE money_transfer_settlements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transfer_id     BIGINT NOT NULL,
    demand_id       INT NOT NULL,
    settled_amount  DECIMAL(12,2) NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### `customer_payment_settlements`
```sql
CREATE TABLE customer_payment_settlements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    payment_id      BIGINT NOT NULL,
    demand_id       INT NOT NULL,
    settled_amount  DECIMAL(12,2) NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 3.3 Business Logic Flow (Legacy)

```
1. CREATE DEMAND (from_branch = requester)
   ├─ from_branch_id = session user's branch (debtor/requester)
   ├─ to_branch_id = selected supplying branch (creditor/supplier)
   ├─ status = 'pending'
   ├─ items = product_id + qty (cost_rate = 0, no warehouse yet)
   └─ demand_code = "DEM-YYYYMMDD-NNNN"

2. SEND GOODS (to_branch = supplier fulfills)
   ├─ For each item: select from_warehouse_id (supplier's) + to_warehouse_id (requester's)
   ├─ Pipeline-aware availability check (Assert_Warehouse_Lines_Available)
   ├─ Rate = source warehouse avg_cost (LOCKED at send time)
   ├─ Stock OUT from sender warehouse (reference_type = 'demand_send')
   ├─ Stock IN to receiver warehouse (reference_type = 'demand_receive')
   ├─ Create documentary warehouse_transfers row (status='received', branch_demand_id set)
   ├─ Post intercompany GL (two journals: creditor + debtor)
   ├─ Record in branch_ledger (running balance)
   └─ Update status = 'received', total_value, warehouse_transfer_id, journal IDs

3. REVERSE DEMAND (undo sent/received demand)
   ├─ Reverse stock: put back to sender warehouse, take out from receiver warehouse
   ├─ Reverse intercompany ledger (branch_ledger.is_reversed = 1)
   ├─ Reverse both GL journals (creditor + debtor)
   └─ Update status = 'reversed', is_reversed = 1

4. DELETE DRAFT (only pending demands)
   ├─ Delete items + demand row
   └─ No stock/GL impact

5. SETTLEMENT (FIFO allocation)
   ├─ Customer payments (bank mode) → auto-settle branch demands
   ├─ Money transfers (cash_to_cash, cash_to_bank) → auto-settle branch demands
   ├─ FIFO: oldest open demand first
   ├─ settlement_amount tracks running total
   └─ Settlement journals posted per allocation
```

### 3.4 Key Legacy Features

| Feature | Status | Notes |
|---------|--------|-------|
| Demand create + send | ✅ Working | Two-phase flow with warehouse selection |
| Pipeline-aware stock check | ✅ Working | Physical minus sales pipeline |
| Moving average cost | ✅ Working | Locked at send time from source warehouse |
| Documentary warehouse transfer | ✅ Working | Auto-created, status='received' |
| Intercompany GL (dual journal) | ✅ Working | Creditor + Debtor journal entries |
| Branch ledger (running balance) | ✅ Working | Debit/credit pairs with running balance |
| FIFO settlement | ✅ Working | Bank payments + money transfers |
| Reversal | ✅ Working | Stock, GL, and ledger all reversed |
| Anti-gaming flags | ✅ Working | Catalog drops, below-cost sales, stale debt |
| Weekly report | ✅ Working | Demands, settlements, floor stock, anti-gaming |
| Audit checklist | ✅ Working | GL health, journal links, ledger nature |
| CSV export | ✅ Working | Full demand list export |
| Price range handling | ❌ Not implemented | Uses locked avg_cost; no min/max/default range |
| Warehouse receipt confirmation | ❌ Not implemented | Stock moves immediately; no "confirm receipt" step |
| Excel-style audit report | ❌ Not implemented | Weekly report is different from the Excel sheet |

---

## 4. Laravel System Analysis

### 4.1 What Exists

| Component | Status | Notes |
|-----------|--------|-------|
| `branch_demands` table | ✅ Schema exists | PostgreSQL, but missing many columns vs legacy |
| `branch_demand_items` table | ✅ Schema exists | PostgreSQL, but schema mismatch (warehouse_id vs from/to_warehouse_id) |
| `warehouse_transfers.branch_demand_id` | ✅ Migration exists | FK added, same-branch trigger relaxed for demand-linked |
| `stock_transactions.branch_demand_item_id` | ✅ Column exists | Ready for demand-linked stock trace |
| `stock_transactions.reference_type` | ✅ CHECK includes 'branch_demand' | But only 'branch_demand', not the three sub-types |
| `WarehouseTransfer::branchDemand()` | ⚠️ Breaks | References non-existent `BranchDemand` model |
| `StockService::applyTransaction()` | ✅ Ready | Accepts `branch_demand_item_id` |
| `StockAvailabilityService` | ✅ Ready | Pipeline-aware availability checks |
| `WarehouseTransferService` | ✅ Ready | Same-branch enforcement, demand-linked reversal protection |
| `WarehouseTransferAuditService` | ✅ Ready | Checks for `branch_demand_id` in audits |
| `ReportController::branchDemandWeekly()` | ⚠️ Minimal stub | Only paginated list, no financial data |
| Frontend JS/CSS | ✅ Exists | `BranchDemand.js`, `branch-demand.css`, `branch-demand-weekly.css` |
| `WarehouseTransferBranchScope` | ✅ Ready | Filters by from/to branch |

### 4.2 What Does NOT Exist

| Component | Priority |
|-----------|----------|
| `BranchDemand` model | 🔴 Critical |
| `BranchDemandItem` model | 🔴 Critical |
| `BranchDemandController` (web) | 🔴 Critical |
| `BranchDemandApiController` (API) | 🟡 Medium |
| `BranchDemandService` | 🔴 Critical |
| `BranchIntercompanyService` | 🔴 Critical |
| `BranchDemandAuditService` | 🟠 High |
| `BranchDemandAuditLogger` | 🟠 High |
| `StoreBranchDemandRequest` | 🟡 Medium |
| `BranchDemandResource` (API) | 🟡 Medium |
| Views (index, create, show, pending, checklist, weekly) | 🔴 Critical |
| Routes (web + API) | 🔴 Critical |
| `branch_ledger` table | 🔴 Critical |
| `money_transfer_settlements` table | 🟠 High |
| `customer_payment_settlements` table | 🟠 High |
| `branch_demand_receipts` table (new) | 🟠 High |
| `product_price_range_audit` table (new) | 🟡 Medium |
| Test coverage | 🟡 Medium |

### 4.3 PostgreSQL Schema Discrepancies vs Legacy

#### `branch_demands` — Missing Columns

| Column | Legacy MySQL | Laravel PostgreSQL | Status |
|--------|-------------|-------------------|--------|
| `total_value` | DECIMAL(12,2) | ❌ Missing | Must add |
| `settlement_amount` | DECIMAL(12,2) | ❌ Missing | Must add |
| `warehouse_transfer_id` | BIGINT | ❌ Missing | Must add |
| `journal_entry_id_debtor` | BIGINT | ❌ Missing | Must add |
| `reversed_at` | DATETIME | ❌ Missing | Must add |
| `reversed_by` | INT | ❌ Missing | Must add |
| `reverse_reason` | TEXT | ❌ Missing | Must add |
| `status` CHECK | `('pending','received','rejected','reversed')` | `('pending','approved','rejected','fulfilled','cancelled')` | Must align |

#### `branch_demand_items` — Schema Mismatch

| Column | Legacy MySQL | Laravel PostgreSQL | Status |
|--------|-------------|-------------------|--------|
| `from_warehouse_id` | INT | ❌ Missing (has `warehouse_id`) | Must rename/split |
| `to_warehouse_id` | INT | ❌ Missing | Must add |
| `cost_rate` | DECIMAL(12,4) | ❌ Missing (has `rate` DECIMAL(12,2)) | Must add/rename |
| `fulfilled_qty` | ❌ Missing | DECIMAL(14,4) | Not needed (single send, no partial) |
| `warehouse_id` | ❌ Not in legacy | Exists | Rename to `to_warehouse_id` or remove |

#### `stock_transactions.reference_type` — Enum Mismatch

| Legacy MySQL | Laravel PostgreSQL CHECK |
|-------------|------------------------|
| `demand_send` | ❌ Only `branch_demand` exists |
| `demand_receive` | ❌ Only `branch_demand` exists |
| `demand_reversal` | ❌ Only `branch_demand` exists |

**Decision:** Add `demand_send`, `demand_receive`, `demand_reversal` to the PostgreSQL CHECK constraint. This provides granular traceability that the generic `branch_demand` does not.

---

## 5. Gap Analysis

### 5.1 Critical Gaps (Must Fix Before Any Business Logic)

| # | Gap | Severity | Impact |
|---|-----|----------|--------|
| G1 | No `BranchDemand` model — `WarehouseTransfer::branchDemand()` breaks | 🔴 Critical | Runtime error on any WarehouseTransfer page that eager-loads the relationship |
| G2 | No `BranchDemandController` — no CRUD routes | 🔴 Critical | Entire module is inaccessible |
| G3 | No `BranchDemandService` — no business logic | 🔴 Critical | No demand creation, send, or reversal |
| G4 | No `BranchIntercompanyService` — no GL posting, no settlement | 🔴 Critical | No financial tracking between branches |
| G5 | `branch_demand_items` schema mismatch — `warehouse_id` vs `from_warehouse_id`/`to_warehouse_id` | 🔴 Critical | Cannot store per-item warehouse selection |
| G6 | `branch_demands` missing columns — `total_value`, `settlement_amount`, etc. | 🔴 Critical | Cannot track financial position |

### 5.2 High-Priority Gaps (Core Business Logic)

| # | Gap | Severity | Impact |
|---|-----|----------|--------|
| G7 | No `branch_ledger` table | 🟠 High | No running balance tracking between branches |
| G8 | No `money_transfer_settlements` table | 🟠 High | No FIFO settlement from money transfers |
| G9 | No `customer_payment_settlements` table | 🟠 High | No FIFO settlement from bank payments |
| G10 | Status enum mismatch — `fulfilled`/`cancelled` vs `received`/`reversed` | 🟠 High | Business logic incompatibility |
| G11 | No warehouse receipt confirmation flow | 🟠 High | No accountability for receiving warehouse manager |
| G12 | `stock_transactions.reference_type` missing sub-types | 🟠 High | Cannot trace specific send/receive/reversal movements |

### 5.3 Medium-Priority Gaps (Reporting & Audit)

| # | Gap | Severity | Impact |
|---|-----|----------|--------|
| G13 | No audit service or checklist | 🟡 Medium | No data integrity checks |
| G14 | Weekly report is a minimal stub | 🟡 Medium | No financial data, no settlement tracking |
| G15 | No Excel-style audit report | 🟡 Medium | Users still need to compile data manually |
| G16 | No price range handling | 🟡 Medium | No min/max/default tracking for inter-branch pricing |
| G17 | No anti-gaming controls | 🟡 Medium | No detection of suspicious patterns |
| G18 | No API routes | 🟡 Medium | No mobile/API support |
| G19 | No test coverage | 🟡 Medium | No automated verification |

---

## 6. The Excel Audit Sheet — What the Business Actually Needs

### 6.1 Sheet Structure

The user's Excel sheet ("MAIN BILL SHIT1.xlsx") is titled **"REMOTE CENTER - NAWABPUR BRANCH — DAILY BASES MONTHLY REPORT"**. It tracks a single branch's daily financial position for a month (e.g., April 2026).

**Columns (A-Z):**

| Col | Header | What It Tracks | Source in ERP |
|-----|--------|---------------|--------------|
| A | DATE | Calendar date | — |
| B | CASH SALE | Cash sales for the day | `sales_invoices` where `payment_mode = 'cash'` |
| C | COLLECTION (CASH) | Cash collected from customers (against dues) | `customer_payments` where `payment_mode = 'cash'` |
| D | COLLECTION (BANK) | Bank payments received from customers | `customer_payments` where `payment_mode = 'bank'` |
| E | EXPENSES | Day's expenses | `expenses` table |
| F | MONEY TRANSFER BY HEAD OFFICE | Money transferred from HO to this branch | `money_transfers` where `to_branch_id = this branch` |
| G | NAWABPUR WAREHOUSE SALE | Sales from Nawabpur warehouse specifically | `sales_invoices` filtered by warehouse |
| H | TARABO WAREHOUSE SALE | Sales from Tarabo warehouse specifically | `sales_invoices` filtered by warehouse |
| I | DEMAND BILL | Value of demand bills received (stock transferred in) | `branch_demands` where `to_branch_id = this branch`, status = 'received' |
| J | PRICE (ADD) | Price increase adjustments | `product_price_history` + `stock_adjustments` |
| K | PRICE (LESS) | Price decrease adjustments | `product_price_history` + `stock_adjustments` |
| L | PROFIT (NP) | Profit from Nawabpur warehouse | Derived from sales minus cost |
| M | PROFIT (TARABO) | Profit from Tarabo warehouse | Derived from sales minus cost |
| N | DISCOUNT | General discount given | `sales_invoice_items.discount` |
| O | DISCOUNT (NP) | Discount on Nawabpur sales | `sales_invoice_items.discount` filtered |
| P | DISCOUNT (TARABO) | Discount on Tarabo sales | `sales_invoice_items.discount` filtered |
| Q | SALES RETURN | Value of sales returns | `sales_returns` |
| R | PRODUCT TRANSFER (TARABO) | Products transferred to/from Tarabo | `warehouse_transfers` (same-branch) |
| S | MISSING AMOUNT OF BANK | Bank amount not yet accounted for | Reconciliation gap |
| T | HEAD OFFICE BILL (BF) Yesterday | Brought-forward balance from HO | `branch_ledger` running balance |
| U | HEAD OFFICE TOTAL BILL Today | Today's total bill with HO | `branch_ledger` derived |
| V | CASH IN HAND Today | Physical cash at branch | `cash_register` or derived |
| W | WAREHOUSE STOCK VALUE Today | Total stock value at branch warehouses | `warehouse_stock` × `avg_cost` |
| X | CUSTOMER DUE Today | Outstanding customer dues | `sales_invoices` balance |
| Y | NAWABPUR CURRENT VALUE | Current total value of Nawabpur operations | Derived composite |
| Z | GAP | Reconciliation gap (should be zero) | `Y - U` or derived |

### 6.2 What the Business Actually Does Today

Currently, the branch staff must:
1. Visit the **sales report** → note cash sales, warehouse-wise sales, discounts, profits
2. Visit the **collection report** → note cash and bank collections
3. Visit the **expense report** → note expenses
4. Visit the **money transfer report** → note HO transfers
5. Visit the **demand report** → note demand bills received
6. Visit the **price adjustment report** → note price increases and decreases
7. Visit the **sales return report** → note returns
8. Visit the **warehouse transfer report** → note same-branch transfers
9. Visit the **stock report** → note warehouse stock value
10. Visit the **customer due report** → note outstanding dues
11. Manually compile all of this into a single Excel sheet
12. Calculate the GAP column to verify reconciliation

**This is the core pain point.** The Laravel system should produce this entire report on a single page, automatically.

### 6.3 Summary Row

The Excel sheet has a summary row with totals for:
- Total PROFIT (NP): 17,615
- Total PROFIT (TARABO): 31,450
- Total DISCOUNT: 41,721
- Total DISCOUNT (NP): 312,565
- Total DISCOUNT (TARABO): 4,750

And three final rows:
- HEAD OFFICE BILL
- STOCK OF NAWABPUR IN SOFTWARE
- STOCK OF PHYSICAL

---

## 7. Price Range Challenge

### 7.1 The Problem

The ERP maintains a **price range** per product: min, max, and default. During demand approval, the price range is recorded. The sales manager of Branch B can sell the product within this range. However, when the weekly audit happens, the product price may have changed. This creates a gap:

- **At send time:** Product min=100, max=120, default=115. The locked cost is 115 (avg_cost).
- **At sale time:** Branch B sells the product at 110 (within the range). Branch B collected 110, but owes Branch A 115 (the locked cost). There is a 5-unit gap.
- **At audit time:** The product price may have changed to min=105, max=125, default=120. The locked cost is still 115. If Branch B pays 115, but the current price is 120, who absorbs the 5-unit difference?

### 7.2 The Legacy System's Approach

The legacy system uses **locked avg_cost** at send time. This is the transfer cost that Branch B owes to Branch A. It does not change regardless of:
- Price range changes
- Sales price changes
- Market price changes

This is a **conservative approach** — the debt is fixed at the time of transfer. The price range is used only for **sales control** (ensuring Branch B sells within the allowed range), not for inter-branch settlement.

### 7.3 The Proposed Laravel Approach

The Laravel system should implement a **two-tier pricing model**:

#### Tier 1: Transfer Cost (Locked at Send Time)
- The `cost_rate` on `branch_demand_items` is the **locked avg_cost** from the source warehouse.
- This is the amount Branch B owes to Branch A for the product.
- **This never changes** — it is the basis for the inter-branch debt.
- This is already how the legacy system works.

#### Tier 2: Price Range (For Sales Control & Audit)
- At send time, also record the **price range** (min, max, default) from `product_price_history` onto `branch_demand_items`.
- This allows the audit system to:
  - Verify that Branch B sold within the allowed range
  - Calculate the **margin variance** (actual sale price vs locked cost)
  - Flag any sales below the locked cost (anti-gaming)
  - Track price changes over time and their impact on the audit

#### Tier 3: Repricing Adjustment (New)
- For the scenario where the product price changes between send and settlement:
  - **If the price increases:** Branch B still owes the locked cost. The increase is Branch B's gain.
  - **If the price decreases:** Branch B still owes the locked cost. The decrease is Branch B's loss.
  - **Exception:** If there is a formal repricing agreement between branches, a **repricing adjustment** can be created. This is a separate transaction that adjusts the outstanding principal.
  - The repricing adjustment posts a new GL entry and branch ledger entry.

### 7.4 New Tables Needed

```sql
-- Record price range at send time
ALTER TABLE branch_demand_items ADD COLUMN price_min numeric(12,2) DEFAULT 0;
ALTER TABLE branch_demand_items ADD COLUMN price_max numeric(12,2) DEFAULT 0;
ALTER TABLE branch_demand_items ADD COLUMN price_default numeric(12,2) DEFAULT 0;

-- Repricing adjustments (if branches agree to reprice)
CREATE TABLE branch_demand_repricing (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id integer NOT NULL REFERENCES branch_demands(id),
    original_total_value numeric(12,2) NOT NULL,
    new_total_value numeric(12,2) NOT NULL,
    adjustment_amount numeric(12,2) NOT NULL,
    reason text,
    approved_by integer,
    journal_entry_id integer REFERENCES journal_entries(id),
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
```

---

## 8. Implementation Phases

### Phase 1 — Schema Alignment & Foundation

**Goal:** Fix all schema discrepancies so the database is ready for the business logic.

**Tasks:**

1. **Create migration to align `branch_demands` table:**
   - Add `total_value` numeric(12,2) DEFAULT NULL
   - Add `settlement_amount` numeric(12,2) DEFAULT 0
   - Add `warehouse_transfer_id` integer REFERENCES warehouse_transfers(id) ON DELETE SET NULL
   - Add `journal_entry_id_debtor` integer REFERENCES journal_entries(id)
   - Add `reversed_at` timestamp(0) DEFAULT NULL
   - Add `reversed_by` integer DEFAULT NULL
   - Add `reverse_reason` text DEFAULT NULL
   - Change status CHECK from `('pending','approved','rejected','fulfilled','cancelled')` to `('pending','received','rejected','reversed')` — align with legacy
   - Add `received_at` timestamp(0) DEFAULT NULL (for warehouse manager confirmation)
   - Add `received_by` integer DEFAULT NULL (for warehouse manager confirmation)

2. **Create migration to align `branch_demand_items` table:**
   - Rename `warehouse_id` to `to_warehouse_id` (or add `to_warehouse_id` and drop `warehouse_id`)
   - Add `from_warehouse_id` integer REFERENCES warehouses(id)
   - Rename `rate` to `cost_rate` and change type to numeric(12,4)
   - Drop `fulfilled_qty` (not needed — single send, no partial fulfillment)
   - Add `price_min` numeric(12,2) DEFAULT 0
   - Add `price_max` numeric(12,2) DEFAULT 0
   - Add `price_default` numeric(12,2) DEFAULT 0

3. **Create migration to update `stock_transactions.reference_type` CHECK:**
   - Add `demand_send`, `demand_receive`, `demand_reversal` to the CHECK constraint
   - Keep `branch_demand` as a fallback for any generic usage

4. **Create `branch_ledger` table:**
   - `id`, `transaction_date`, `from_branch_id`, `to_branch_id`, `reference_type`, `reference_id`, `journal_entry_id`, `debit`, `credit`, `running_balance`, `remarks`, `is_reversed`, `created_by`, `created_at`

5. **Create `money_transfer_settlements` table:**
   - `id`, `transfer_id`, `demand_id`, `settled_amount`, `created_at`

6. **Create `customer_payment_settlements` table:**
   - `id`, `payment_id`, `demand_id`, `settled_amount`, `created_at`

7. **Create `branch_demand_repricing` table:**
   - `id`, `branch_demand_id`, `original_total_value`, `new_total_value`, `adjustment_amount`, `reason`, `approved_by`, `journal_entry_id`, `created_by`, `created_at`

8. **Create `BranchDemand` model:**
   - Fillable, casts, relationships (items, fromBranch, toBranch, warehouseTransfer, journalEntry, debtorJournalEntry, createdBy, receivedBy)
   - Scopes: `pending()`, `received()`, `forBranch()`, `byBranch()`

9. **Create `BranchDemandItem` model:**
   - Fillable, casts, relationships (demand, product, fromWarehouse, toWarehouse)

10. **Fix `WarehouseTransfer::branchDemand()` — ensure it references the new `BranchDemand` model.**

**Exit criteria:**
- All migrations run without error
- `BranchDemand` and `BranchDemandItem` models exist with all relationships
- `php artisan tinker` → `BranchDemand::first()` works without error
- `WarehouseTransfer::first()->branchDemand` does not throw

---

### Phase 2 — Core Demand CRUD & Send Flow

**Goal:** Implement the full demand lifecycle: create, send, reverse, delete, with pipeline-aware stock checking.

**Tasks:**

1. **Create `BranchDemandService`:**
   - `createDemand(array $data, array $items): BranchDemand` — Creates demand with items, status='pending'
   - `sendGoodsWithWarehouses(int $demandId, array $items): BranchDemand` — The core send flow:
     - Validate demand is pending
     - For each item: validate from_warehouse_id and to_warehouse_id
     - Pipeline-aware availability check using `StockAvailabilityService`
     - Lock cost_rate = source warehouse avg_cost
     - Record price range (min, max, default) from `product_price_history`
     - Stock OUT from sender warehouse (reference_type = 'demand_send')
     - Stock IN to receiver warehouse (reference_type = 'demand_receive')
     - Create documentary `warehouse_transfers` row
     - Update `branch_demand_items` with from_warehouse_id, to_warehouse_id, cost_rate
     - Update `branch_demands` with total_value, warehouse_transfer_id, status='received'
   - `reverseDemand(int $demandId, string $reason): BranchDemand` — Full reversal:
     - Reverse stock movements (reference_type = 'demand_reversal')
     - Reverse intercompany ledger
     - Reverse GL journals
     - Update status='reversed'
   - `deleteDraftDemand(int $demandId): void` — Delete pending demand only
   - `rejectDemand(int $demandId, string $reason): BranchDemand` — Reject a pending demand

2. **Create `BranchDemandController` (web):**
   - `index()` — Filtered list of demands (my demands + incoming)
   - `pending()` — Pending demands for my branch
   - `create()` — Create demand form
   - `store()` — Store new demand
   - `show()` — Full detail view (items, warehouse pickers, settlements, stock trace, GL)
   - `send()` — Send goods with warehouse selection
   - `reverse()` — Reverse a sent/received demand
   - `delete()` — Delete a pending demand
   - `reject()` — Reject a pending demand
   - `getBranches()` — JSON: other active branches
   - `getProducts()` — JSON: active products
   - `getWarehousesByBranch()` — JSON: warehouses for a branch
   - `getWarehouseStock()` — JSON: warehouse-wise product stock

3. **Create `StoreBranchDemandRequest` form validation:**
   - `to_branch_id` required, different from current branch
   - `demand_date` required, date
   - `items` required, array, min 1
   - `items.*.product_id` required, exists
   - `items.*.qty` required, numeric, min 0.01

4. **Create `SendBranchDemandRequest` form validation:**
   - `items.*.from_warehouse_id` required, belongs to supplier branch
   - `items.*.to_warehouse_id` required, belongs to requester branch
   - `items.*.qty` required, numeric, matches demand item qty

5. **Add web routes:**
   ```
   GET    /admin/branch-demands                    → index
   GET    /admin/branch-demands/pending            → pending
   GET    /admin/branch-demands/create             → create
   POST   /admin/branch-demands                    → store
   GET    /admin/branch-demands/{id}               → show
   POST   /admin/branch-demands/{id}/send          → send
   POST   /admin/branch-demands/{id}/reverse       → reverse
   DELETE /admin/branch-demands/{id}               → delete
   POST   /admin/branch-demands/{id}/reject        → reject
   GET    /admin/branch-demands/branches           → getBranches
   GET    /admin/branch-demands/products            → getProducts
   GET    /admin/branch-demands/warehouses/{id}    → getWarehousesByBranch
   GET    /admin/branch-demands/stock/{pid}/{bid}  → getWarehouseStock
   ```

**Exit criteria:**
- Can create a demand from Branch B to Branch A
- Can send goods with warehouse selection (stock moves, WT created)
- Stock availability check prevents negative stock
- Can reverse a sent demand (stock restored, GL reversed)
- Can delete a pending demand
- Can reject a pending demand
- Warehouse stock is accurate after each operation

---

### Phase 3 — Intercompany GL & Branch Ledger

**Goal:** Implement the full intercompany accounting: dual GL journals, branch ledger with running balance.

**Tasks:**

1. **Create `BranchIntercompanyService`:**
   - `postDemandFulfillmentJournals()` — Post two journal entries:
     - **Creditor (supplier) journal:** Dr Due from Branches / Cr Inventory
     - **Debtor (requester) journal:** Dr Inventory / Cr Due to Branches
   - `recordDemandTransfer()` — Insert branch_ledger pair with running balance
   - `recordDemandSettlement()` — Insert branch_ledger pair for settlement
   - `reverseLedgerByReference()` — Mark ledger rows as reversed
   - `reverseDemandJournals()` — Reverse both creditor and debtor journals

2. **Ensure GL control accounts exist:**
   - `L-0105` Due from Branches (interbranch_receivable) — Asset, normal balance = debit
   - `L-0302` Due to Branches (interbranch_payable) — Liability, normal balance = credit

3. **Integrate with `JournalPostingService`:**
   - `postBranchDemandFulfillment()` — Already exists in legacy
   - `postBranchDemandSettlement()` — Already exists in legacy
   - Port to Laravel's `JournalPostingService`

4. **Add `branch_ledger` query methods to `BranchIntercompanyService`:**
   - `getRunningBalance(debtorBranchId, creditorBranchId)` — Get current running balance
   - `getLedgerHistory(debtorBranchId, creditorBranchId, dateFrom, dateTo)` — Get ledger entries for a period
   - `getOutstandingByBranch(branchId)` — Get all outstanding amounts owed to/from a branch

**Exit criteria:**
- Sending goods posts two GL journal entries (creditor + debtor)
- Branch ledger records the transfer with running balance
- Both branches can see the financial impact in their respective GL
- Running balance is accurate after multiple transfers
- Reversing a demand reverses both GL journals and ledger entries

---

### Phase 4 — FIFO Settlement (Bank Payments + Money Transfers)

**Goal:** Implement automatic FIFO settlement of branch demands through bank customer payments and inter-branch money transfers.

**Tasks:**

1. **Implement `BranchIntercompanyService::settleFromCustomerPayment()`:**
   - Triggered when a customer payment with `payment_mode = 'bank'` is recorded at the debtor branch
   - Cash payments do NOT settle demands (they use money transfer instead)
   - Get all creditor branches with open demands for the debtor branch
   - For each creditor branch, allocate settlement FIFO (oldest demand first)
   - Create `customer_payment_settlements` rows
   - Update `branch_demands.settlement_amount`
   - Post settlement journal: Dr Due to Branches / Cr Due from Branches
   - Record branch_ledger pair (credit on debtor, debit on creditor)

2. **Implement `BranchIntercompanyService::settleFromMoneyTransfer()`:**
   - Triggered when a `cash_to_cash` or `cash_to_bank` money transfer is made between branches
   - `bank_to_cash` / `bank_to_bank` at the same branch do not settle demands
   - Allocate settlement FIFO (oldest demand first)
   - Create `money_transfer_settlements` rows
   - Same journal and ledger pattern as customer payment settlement

3. **Implement settlement preview:**
   - `previewDemandSettlement(fromBranchId, toBranchId, amount)` — Show which demands would be settled
   - Use in the money transfer UI to show the user what will happen before they confirm

4. **Implement settlement reversal:**
   - `reverseCustomerPaymentSettlements(paymentId)` — When a customer payment is reversed
   - `reverseMoneyTransferSettlements(transferId)` — When a money transfer is reversed
   - Reverse settlement journals, reverse ledger entries, reduce settlement_amount

5. **Integrate with existing payment/transfer flows:**
   - Hook into `CustomerPaymentController::store()` to trigger settlement
   - Hook into `MoneyTransferController::store()` to trigger settlement
   - Hook into their reversal methods to trigger settlement reversal

**Exit criteria:**
- Bank customer payment at debtor branch auto-settles branch demands (FIFO)
- Money transfer (cash_to_cash, cash_to_bank) auto-settles branch demands (FIFO)
- Settlement preview shows exact allocation before confirmation
- Reversing a payment/transfer reverses the associated settlements
- `branch_demands.settlement_amount` is accurate after all operations
- `outstanding = total_value - settlement_amount` is always correct

---

### Phase 5 — Warehouse Manager Confirmation (Receipt Acknowledgment)

**Goal:** Add a confirmation step so the receiving warehouse manager must acknowledge receipt of the products. This prevents the "I don't know when it happened" problem.

**Tasks:**

1. **Modify the send flow:**
   - After sending goods, the demand status changes to `received` (stock has moved)
   - But the `received_at` and `received_by` columns remain NULL until the receiving warehouse manager confirms

2. **Create `BranchDemandController::confirmReceipt()`:**
   - Only the receiving branch's warehouse manager can confirm
   - Validates that the demand is in `received` status and `received_at` is NULL
   - Sets `received_at = now()`, `received_by = auth()->id()`
   - Logs an audit event

3. **Add a "Pending Receipt Confirmation" view:**
   - Shows all demands where `to_branch_id = my branch` AND `status = 'received'` AND `received_at IS NULL`
   - The warehouse manager can view the items and confirm receipt

4. **Add business rule:**
   - A demand that has not been confirmed by the receiving warehouse manager cannot be reversed by the sending branch
   - This ensures the receiving branch has acknowledged the transfer before any reversal can happen

5. **Add audit logging:**
   - `BranchDemandAuditLogger::logReceiptConfirmation()` — Log who confirmed and when

**Exit criteria:**
- Receiving warehouse manager must confirm receipt of products
- Unconfirmed demands are visible in a "Pending Receipt" view
- Reversal is blocked until receipt is confirmed
- Audit trail records who confirmed and when

---

### Phase 6 — Weekly Audit Report (The Excel Sheet Replication)

**Goal:** Create a single-page report that replicates the user's Excel sheet, eliminating the need to visit multiple reports and manually compile data.

**Tasks:**

1. **Create `BranchDemandWeeklyReportService`:**
   - Takes a branch_id and date range as input
   - Returns a structured array with all the columns from the Excel sheet

2. **Implement each column as a query/method:**

   | Column | Method | Data Source |
   |--------|--------|------------|
   | CASH SALE | `getCashSales(branchId, date)` | `sales_invoices` where `payment_mode = 'cash'` AND `branch_id` |
   | COLLECTION (CASH) | `getCashCollections(branchId, date)` | `customer_payments` where `payment_mode = 'cash'` AND `branch_id` |
   | COLLECTION (BANK) | `getBankCollections(branchId, date)` | `customer_payments` where `payment_mode = 'bank'` AND `branch_id` |
   | EXPENSES | `getExpenses(branchId, date)` | `expenses` where `branch_id` |
   | MONEY TRANSFER BY HO | `getMoneyTransfersFromHO(branchId, date)` | `money_transfers` where `to_branch_id` |
   | WAREHOUSE-WISE SALE | `getWarehouseWiseSales(branchId, date)` | `sales_invoices` joined with `warehouse_id` |
   | DEMAND BILL | `getDemandBills(branchId, date)` | `branch_demands` where `to_branch_id` AND `status = 'received'` |
   | PRICE (ADD) | `getPriceIncreases(branchId, date)` | `stock_adjustments` + `product_price_history` |
   | PRICE (LESS) | `getPriceDecreases(branchId, date)` | `stock_adjustments` + `product_price_history` |
   | PROFIT | `getProfit(branchId, date, warehouseId)` | Sales minus cost |
   | DISCOUNT | `getDiscounts(branchId, date, warehouseId)` | `sales_invoice_items.discount` |
   | SALES RETURN | `getSalesReturns(branchId, date)` | `sales_returns` |
   | PRODUCT TRANSFER | `getWarehouseTransfers(branchId, date)` | `warehouse_transfers` (same-branch) |
   | MISSING BANK AMOUNT | `getMissingBankAmount(branchId, date)` | Reconciliation gap |
   | HO BILL (BF) | `getHOBillBroughtForward(branchId, date)` | `branch_ledger` running balance from yesterday |
   | HO TOTAL BILL | `getHOTotalBill(branchId, date)` | `branch_ledger` running balance today |
   | CASH IN HAND | `getCashInHand(branchId, date)` | `cash_register` or derived |
   | WAREHOUSE STOCK VALUE | `getWarehouseStockValue(branchId, date)` | `warehouse_stock` × `avg_cost` |
   | CUSTOMER DUE | `getCustomerDue(branchId, date)` | `sales_invoices` balance |
   | CURRENT VALUE | `getCurrentValue(branchId, date)` | Derived composite |
   | GAP | `getGap(branchId, date)` | `Y - U` (reconciliation check) |

3. **Create the daily report view:**
   - A single page with a date picker and branch selector
   - Shows all columns in a table format (matching the Excel layout)
   - Each row is a day; columns are the financial metrics
   - Summary row at the bottom with totals
   - Three final rows: HO Bill, Stock in Software, Stock Physical

4. **Add CSV/Excel export:**
   - Export the report in the same format as the Excel sheet
   - One-click download

5. **Add drill-down capability:**
   - Click on any cell to see the underlying transactions
   - E.g., click on "CASH SALE" to see the individual invoices
   - Click on "DEMAND BILL" to see the individual demands

6. **Add comparative view:**
   - Compare current month vs previous month
   - Compare Branch A vs Branch B

**Exit criteria:**
- The weekly/daily report shows all columns from the Excel sheet
- Data is accurate and matches the individual reports
- CSV/Excel export works
- Drill-down to individual transactions works
- GAP column is zero (or close to zero) for a reconciled branch

---

### Phase 7 — Price Range Handling & Repricing Logic

**Goal:** Implement the price range recording at send time and the repricing adjustment mechanism.

**Tasks:**

1. **Modify `sendGoodsWithWarehouses()` to record price range:**
   - At send time, for each product, look up the current price range from `product_price_history`
   - Store `price_min`, `price_max`, `price_default` on `branch_demand_items`

2. **Create `BranchDemandRepricingService`:**
   - `createRepricingAdjustment(demandId, newTotalValue, reason)` — Create a repricing adjustment
   - Validates that the new total value is different from the current total value
   - Records the adjustment in `branch_demand_repricing`
   - Updates `branch_demands.total_value` to the new value
   - Posts a GL adjustment journal
   - Records a branch_ledger adjustment entry

3. **Add price range validation in sales:**
   - When Branch B sells a product that was received via branch demand, check if the sale price is within the recorded price range
   - If the sale price is below `price_min` or above `price_max`, flag it as a warning (not a hard block)
   - This is for visibility and accountability, not to prevent the sale

4. **Add price range audit to the weekly report:**
   - Show products where the current price range differs from the locked price range
   - Calculate the impact of the price change on the outstanding balance
   - Show the margin variance (actual sale price vs locked cost)

5. **Add repricing history to the demand detail view:**
   - Show any repricing adjustments made to the demand
   - Show the original vs new total value
   - Show the GL journal for the adjustment

**Exit criteria:**
- Price range is recorded on each demand item at send time
- Repricing adjustments can be created with proper GL and ledger entries
- Sales below the price range are flagged as warnings
- The weekly report shows price range changes and their impact
- Repricing history is visible on the demand detail page

---

### Phase 8 — Anti-Gaming & Accountability Controls

**Goal:** Implement anti-gaming controls to detect suspicious patterns and ensure accountability.

**Tasks:**

1. **Create `BranchDemandAuditService`:**
   - `getDemandAntiGamingFlags(branchId, dateFrom, dateTo)` — Three types of flags:
     - **Catalog below locked rate:** When current `product_price_history.default_rate` < `branch_demand_items.cost_rate` on open received demands
     - **Sales below locked cost:** When receiver branch sells at a rate below the locked demand cost
     - **Stale outstanding:** Open principal > 30 days old

2. **Create `BranchDemandAuditLogger`:**
   - `logDemandCreated(demandId)` — Log demand creation
   - `logDemandSent(demandId)` — Log demand send
   - `logDemandReceived(demandId)` — Log receipt confirmation
   - `logDemandReversed(demandId, reason)` — Log reversal
   - `logDemandDeleted(demandId)` — Log deletion
   - `logSettlementAllocated(demandId, amount, source)` — Log settlement
   - `logRepricingAdjustment(demandId, adjustment)` — Log repricing

3. **Create audit checklist view:**
   - `BranchDemandController::checklist()` — Show all health checks
   - GL Journal Links: `journal_entry_id` and `journal_entry_id_debtor` exist
   - Ledger Nature: `interbranch_receivable`, `interbranch_payable` accounts exist
   - Demand GL Alignment: All received demands have both journal entries
   - Journal Balance: Each journal entry has total Dr = total Cr

4. **Add per-demand audit route:**
   - `BranchDemandController::audit(id)` — Show audit details for a specific demand
   - Stock trace, settlement trace, GL journal blocks, anti-gaming flags

5. **Add reconciliation route:**
   - `BranchDemandController::reconcile()` — Show branch-level reconciliation
   - Compare demand outstanding vs branch_ledger running balance
   - Identify any discrepancies

**Exit criteria:**
- Anti-gaming flags are visible on the weekly report
- Audit checklist shows all health checks
- Per-demand audit shows full traceability
- Reconciliation shows any discrepancies between demand and ledger
- All demand operations are logged in the audit trail

---

### Phase 9 — UI, Views & Frontend

**Goal:** Create all Blade views and ensure the frontend JS/CSS works with the Laravel backend.

**Tasks:**

1. **Create views:**
   - `resources/views/admin/branch-demands/index.blade.php` — Filtered list of demands
   - `resources/views/admin/branch-demands/create.blade.php` — Create demand form
   - `resources/views/admin/branch-demands/show.blade.php` — Full detail view
   - `resources/views/admin/branch-demands/pending.blade.php` — Pending demands for my branch
   - `resources/views/admin/branch-demands/receipts.blade.php` — Pending receipt confirmations
   - `resources/views/admin/branch-demands/checklist.blade.php` — Audit checklist
   - `resources/views/admin/branch-demands/weekly.blade.php` — Weekly/daily audit report (replaces the minimal stub)
   - `resources/views/admin/branch-demands/reconcile.blade.php` — Reconciliation view

2. **Adapt legacy JS:**
   - `public/assets/js/BranchDemand.js` — Already exists, needs to point to Laravel routes
   - Ensure warehouse selectors, product search, and send flow work with the Laravel API

3. **Ensure CSS works:**
   - `public/assets/css/branch-demand.css` — Already exists
   - `public/assets/css/branch-demand-weekly.css` — Already exists

4. **Add sidebar menu entry:**
   - Add "Branch Demand" to the admin sidebar with sub-items:
     - My Demands
     - Pending for Me
     - Receipt Confirmations
     - Weekly Report
     - Audit Checklist

5. **Add role-based access:**
   - `admin`, `manager` — Full access
   - `warehouse_manager` — Create, send, confirm receipt, view
   - `accountant` — View, audit checklist, weekly report

**Exit criteria:**
- All views render correctly
- Demand creation, send, reverse, delete work from the UI
- Warehouse selectors populate correctly
- Weekly report shows all columns from the Excel sheet
- Sidebar menu entries are visible and working
- Role-based access is enforced

---

### Phase 10 — API Routes, Test Coverage & Shadow Mode

**Goal:** Add API routes for mobile support, comprehensive test coverage, and a shadow mode for safe rollout.

**Tasks:**

1. **Create `BranchDemandApiController`:**
   - `index()` — List demands
   - `store()` — Create demand
   - `show()` — Demand detail
   - `send()` — Send goods
   - `reverse()` — Reverse demand
   - `delete()` — Delete draft
   - `confirmReceipt()` — Confirm receipt
   - `settlements()` — Get settlements for a demand
   - `stockTrace()` — Get stock trace for a demand

2. **Create `BranchDemandResource`:**
   - JSON representation of a demand with items, branches, settlements

3. **Add API routes:**
   ```
   GET    /api/v1/branch-demands
   POST   /api/v1/branch-demands
   GET    /api/v1/branch-demands/{id}
   POST   /api/v1/branch-demands/{id}/send
   POST   /api/v1/branch-demands/{id}/reverse
   DELETE /api/v1/branch-demands/{id}
   POST   /api/v1/branch-demands/{id}/confirm-receipt
   GET    /api/v1/branch-demands/{id}/settlements
   GET    /api/v1/branch-demands/{id}/stock-trace
   ```

4. **Create feature tests:**
   - `BranchDemandCreateTest` — Test demand creation
   - `BranchDemandSendTest` — Test send flow with warehouse selection
   - `BranchDemandReverseTest` — Test reversal
   - `BranchDemandDeleteTest` — Test draft deletion
   - `BranchDemandSettlementTest` — Test FIFO settlement
   - `BranchDemandReceiptConfirmationTest` — Test receipt confirmation
   - `BranchDemandWeeklyReportTest` — Test weekly report data accuracy
   - `BranchDemandPriceRangeTest` — Test price range recording and audit
   - `BranchDemandAntiGamingTest` — Test anti-gaming flags
   - `BranchDemandReconciliationTest` — Test reconciliation

5. **Shadow mode:**
   - Create `BranchDemandShadowService` — Compare legacy vs Laravel results side by side
   - For each demand operation, log the legacy result and the Laravel result
   - Flag any discrepancies

**Exit criteria:**
- API routes work with proper authentication
- Feature tests pass with > 90% coverage of the Branch Demand service
- Shadow mode can compare legacy vs Laravel results
- No discrepancies between legacy and Laravel for the same data

---

## 9. Database Schema — Final Target State

### `branch_demands` (PostgreSQL — Final)

```sql
CREATE TABLE branch_demands (
    id                       integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    demand_code              varchar(30) NOT NULL,
    demand_date              date NOT NULL,
    from_branch_id           integer NOT NULL REFERENCES branches(id),
    to_branch_id             integer NOT NULL REFERENCES branches(id),
    status                   varchar(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','received','rejected','reversed')),
    total_value              numeric(12,2) DEFAULT NULL,
    settlement_amount        numeric(12,2) DEFAULT 0,
    warehouse_transfer_id    integer REFERENCES warehouse_transfers(id) ON DELETE SET NULL,
    journal_entry_id         integer REFERENCES journal_entries(id),
    journal_entry_id_debtor  integer REFERENCES journal_entries(id),
    is_reversed              boolean NOT NULL DEFAULT false,
    reversed_at              timestamp(0) DEFAULT NULL,
    reversed_by              integer DEFAULT NULL,
    reverse_reason           text DEFAULT NULL,
    received_at              timestamp(0) DEFAULT NULL,
    received_by              integer DEFAULT NULL,
    notes                    text,
    created_by               integer,
    created_at               timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    updated_at               timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT branch_demands_code_unique UNIQUE (demand_code)
);
CREATE INDEX idx_bd_branches ON branch_demands(from_branch_id, to_branch_id);
CREATE INDEX idx_bd_status ON branch_demands(status);
```

### `branch_demand_items` (PostgreSQL — Final)

```sql
CREATE TABLE branch_demand_items (
    id                  integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id    integer NOT NULL REFERENCES branch_demands(id) ON DELETE CASCADE,
    product_id          integer NOT NULL REFERENCES products(id),
    qty                 numeric(14,4) NOT NULL,
    cost_rate           numeric(12,4) DEFAULT 0,
    from_warehouse_id   integer REFERENCES warehouses(id),
    to_warehouse_id     integer REFERENCES warehouses(id),
    price_min           numeric(12,2) DEFAULT 0,
    price_max           numeric(12,2) DEFAULT 0,
    price_default       numeric(12,2) DEFAULT 0,
    notes               text
);
CREATE INDEX idx_bdi_demand ON branch_demand_items(branch_demand_id);
CREATE INDEX idx_bdi_product ON branch_demand_items(product_id);
```

### `branch_ledger` (PostgreSQL — New)

```sql
CREATE TABLE branch_ledger (
    id                integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_date  date NOT NULL,
    from_branch_id    integer NOT NULL REFERENCES branches(id),
    to_branch_id      integer NOT NULL REFERENCES branches(id),
    reference_type    varchar(50) NOT NULL DEFAULT 'adjustment',
    reference_id      integer DEFAULT NULL,
    journal_entry_id  integer REFERENCES journal_entries(id),
    debit             numeric(12,2) DEFAULT 0,
    credit            numeric(12,2) DEFAULT 0,
    running_balance   numeric(12,2) DEFAULT NULL,
    remarks           text,
    is_reversed       boolean NOT NULL DEFAULT false,
    created_by        integer DEFAULT NULL,
    created_at        timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bl_branches ON branch_ledger(from_branch_id, to_branch_id);
CREATE INDEX idx_bl_reference ON branch_ledger(reference_type, reference_id);
CREATE INDEX idx_bl_date ON branch_ledger(transaction_date);
```

### `money_transfer_settlements` (PostgreSQL — New)

```sql
CREATE TABLE money_transfer_settlements (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_id     integer NOT NULL REFERENCES money_transfers(id),
    demand_id       integer NOT NULL REFERENCES branch_demands(id),
    settled_amount  numeric(12,2) NOT NULL,
    created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_mts_demand ON money_transfer_settlements(demand_id);
CREATE INDEX idx_mts_transfer ON money_transfer_settlements(transfer_id);
```

### `customer_payment_settlements` (PostgreSQL — New)

```sql
CREATE TABLE customer_payment_settlements (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payment_id      integer NOT NULL REFERENCES customer_payments(id),
    demand_id       integer NOT NULL REFERENCES branch_demands(id),
    settled_amount  numeric(12,2) NOT NULL,
    created_at      timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_cps_demand ON customer_payment_settlements(demand_id);
CREATE INDEX idx_cps_payment ON customer_payment_settlements(payment_id);
```

### `branch_demand_repricing` (PostgreSQL — New)

```sql
CREATE TABLE branch_demand_repricing (
    id                    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    branch_demand_id      integer NOT NULL REFERENCES branch_demands(id),
    original_total_value  numeric(12,2) NOT NULL,
    new_total_value       numeric(12,2) NOT NULL,
    adjustment_amount     numeric(12,2) NOT NULL,
    reason                text,
    approved_by           integer DEFAULT NULL,
    journal_entry_id      integer REFERENCES journal_entries(id),
    created_by            integer DEFAULT NULL,
    created_at            timestamp(0) DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bdr_demand ON branch_demand_repricing(branch_demand_id);
```

---

## 10. Risk Matrix

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| Schema migration breaks existing data | Low | High | Test migration on a copy of production data first; use `IF NOT EXISTS` / `IF EXISTS` guards |
| Stock double-counting (demand + WT both move stock) | Medium | Critical | Demand sends stock directly via `StockService`; WT is documentary only (status='received', no stock movement) |
| FIFO settlement race condition | Medium | High | Use `SELECT ... FOR UPDATE` in the settlement transaction; lock the demand row |
| Price range changes affect outstanding balance | Medium | Medium | Locked cost is the basis for settlement; price range is for audit only |
| Reversal of partially settled demand | Low | High | Block reversal if any settlement exists; reverse settlements first, then reverse the demand |
| Missing bank reconciliation (S column in Excel) | Medium | Medium | Build a reconciliation report that compares bank deposits vs recorded bank payments |
| Branch ledger running balance drift | Low | High | Periodic reconciliation check; compare `SUM(debit) - SUM(credit)` vs `running_balance` for the last entry |

---

## Appendix A — Excel Sheet Column Mapping

| Excel Column | Header | ERP Query | Table(s) |
|-------------|--------|-----------|----------|
| A | DATE | — | Filter parameter |
| B | CASH SALE | `SUM(amount) WHERE payment_mode='cash' AND branch_id=X AND date=Y` | `sales_invoices` |
| C | COLLECTION (CASH) | `SUM(amount) WHERE payment_mode='cash' AND branch_id=X AND date=Y` | `customer_payments` |
| D | COLLECTION (BANK) | `SUM(amount) WHERE payment_mode='bank' AND branch_id=X AND date=Y` | `customer_payments` |
| E | EXPENSES | `SUM(amount) WHERE branch_id=X AND date=Y` | `expenses` |
| F | MONEY TRANSFER BY HO | `SUM(amount) WHERE to_branch_id=X AND date=Y` | `money_transfers` |
| G | NAWABPUR WH SALE | `SUM(amount) WHERE warehouse_id=N AND date=Y` | `sales_invoices` |
| H | TARABO WH SALE | `SUM(amount) WHERE warehouse_id=T AND date=Y` | `sales_invoices` |
| I | DEMAND BILL | `SUM(total_value) WHERE to_branch_id=X AND status='received' AND date=Y` | `branch_demands` |
| J | PRICE (ADD) | `SUM(adjustment) WHERE type='increase' AND branch_id=X AND date=Y` | `stock_adjustments` + `product_price_history` |
| K | PRICE (LESS) | `SUM(adjustment) WHERE type='decrease' AND branch_id=X AND date=Y` | `stock_adjustments` + `product_price_history` |
| L | PROFIT (NP) | `SUM(sale_amount - cost_amount) WHERE warehouse_id=N AND date=Y` | Derived from `sales_invoices` + `warehouse_stock.avg_cost` |
| M | PROFIT (TARABO) | `SUM(sale_amount - cost_amount) WHERE warehouse_id=T AND date=Y` | Derived from `sales_invoices` + `warehouse_stock.avg_cost` |
| N | DISCOUNT | `SUM(discount) WHERE branch_id=X AND date=Y` | `sales_invoice_items` |
| O | DISCOUNT (NP) | `SUM(discount) WHERE warehouse_id=N AND date=Y` | `sales_invoice_items` |
| P | DISCOUNT (TARABO) | `SUM(discount) WHERE warehouse_id=T AND date=Y` | `sales_invoice_items` |
| Q | SALES RETURN | `SUM(amount) WHERE branch_id=X AND date=Y` | `sales_returns` |
| R | PRODUCT TRANSFER (TARABO) | `SUM(amount) WHERE (from_wh=T OR to_wh=T) AND date=Y` | `warehouse_transfers` |
| S | MISSING BANK AMOUNT | `bank_collections - bank_deposits` | Reconciliation gap |
| T | HO BILL (BF) Yesterday | `running_balance from branch_ledger WHERE date=Y-1` | `branch_ledger` |
| U | HO TOTAL BILL Today | `running_balance from branch_ledger WHERE date=Y` | `branch_ledger` |
| V | CASH IN HAND | `cash_register balance` | `cash_register` / derived |
| W | WAREHOUSE STOCK VALUE | `SUM(qty * avg_cost) WHERE warehouse_id IN (branch warehouses)` | `warehouse_stock` |
| X | CUSTOMER DUE | `SUM(balance) WHERE branch_id=X` | `sales_invoices` |
| Y | CURRENT VALUE | `V + W + X` (derived composite) | Derived |
| Z | GAP | `Y - U` (should be zero) | Derived |

---

## Appendix B — Legacy vs Laravel Status Enum Mapping

| Legacy MySQL | Laravel PostgreSQL (Current) | Laravel PostgreSQL (Final) | Meaning |
|-------------|---------------------------|--------------------------|---------|
| `pending` | `pending` | `pending` | Demand created, awaiting fulfillment |
| `received` | ❌ Not present | `received` | Goods sent by supplier branch, stock moved |
| `rejected` | `rejected` | `rejected` | Demand rejected by supplier branch |
| `reversed` | ❌ Not present | `reversed` | Sent demand reversed (stock restored, GL reversed) |
| ❌ Not present | `approved` | ❌ Remove | Not needed — use `received` |
| ❌ Not present | `fulfilled` | ❌ Remove | Replaced by `received` |
| ❌ Not present | `cancelled` | ❌ Remove | Replaced by `reversed` for sent demands, `rejected` for pending |

**Decision:** Align with the legacy system. Remove `approved`, `fulfilled`, and `cancelled` from the CHECK constraint. Use `received` and `reversed` instead.

---

## Appendix C — Key Business Rules Reference

### BR-01: Cross-Branch Only
Branch demands must always be between two different branches. `from_branch_id != to_branch_id` is enforced at the application, validation, and database levels.

### BR-02: Two-Phase Flow
1. **Create** — Demand is created with items (no stock movement, no GL)
2. **Send** — Goods are sent with warehouse selection (stock moves, GL posted, ledger updated)

### BR-03: Locked Cost
The transfer cost is locked at send time from the source warehouse's moving average cost. This cost never changes, even if the product price changes later.

### BR-04: Pipeline-Aware Availability
Available stock = physical stock - sales pipeline (open invoices). Only available stock can be sent.

### BR-05: FIFO Settlement
Bank customer payments and inter-branch money transfers auto-settle branch demands in FIFO order (oldest first).

### BR-06: Cash vs Bank
- Cash customer payments do NOT settle branch demands (they must go through money transfer)
- Bank customer payments DO settle branch demands (bank is central, not branch-specific)

### BR-07: Documentary Warehouse Transfer
When goods are sent, a `warehouse_transfers` row is created as a documentary record. Its status is 'received' (auto-completed). It cannot be reversed independently — must reverse the demand.

### BR-08: Dual GL Journals
Each demand fulfillment posts two journal entries:
1. Creditor (supplier) journal: Dr Due from Branches / Cr Inventory
2. Debtor (requester) journal: Dr Inventory / Cr Due to Branches

### BR-09: Branch Ledger Running Balance
Each transfer and settlement creates a pair of `branch_ledger` rows with a running balance. The running balance represents the net owed between the two branches.

### BR-10: Receipt Confirmation
The receiving warehouse manager must confirm receipt of products. This prevents the "I don't know when it happened" problem and creates an audit trail.

### BR-11: Price Range
At send time, the price range (min, max, default) is recorded on each demand item. This is used for:
- Sales control (ensuring Branch B sells within the range)
- Audit (detecting below-cost sales)
- Repricing adjustments (if branches agree to reprice)

### BR-12: Reversal Safety
- Only sent/received demands can be reversed
- If any settlement exists, settlements must be reversed first
- Demand-linked warehouse transfers cannot be reversed independently

### BR-13: Anti-Gaming
Three types of flags are monitored:
1. Catalog below locked rate (current price < locked cost)
2. Sales below locked cost (receiver selling below cost)
3. Stale outstanding (open principal > 30 days)

### BR-14: No Negative Stock
Stock availability is checked before sending goods. If any product is not available in sufficient quantity, the entire send operation fails (atomic transaction).

### BR-15: Zero-Rate Guard
If a product has no warehouse cost (avg_cost = 0) in the source warehouse, the send operation fails. The product must be received into the warehouse first (with a cost) before it can be sent via branch demand.

---

*End of Branch Demand Implementation Plan*
