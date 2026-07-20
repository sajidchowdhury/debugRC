# RC-ERP Sales Module — Complete Documentation & Gap Analysis

> **Document Version**: 1.2 — Updated with Revenue Overview + Sales Funnel dashboard implementations, Telegram/FCM removal
> **Date**: 2025-07-20  
> **Scope**: Legacy CodeIgniter/MySQL → Laravel 12/PostgreSQL migration  
> **Focus**: Sales Entry, Challan/Godown Copy, Invoice, Payment Receive, Sales Return  
> **Rule**: Documentation only — no coding at this stage

---

### Status Key

| Marker | Meaning |
|--------|---------|
| ✅ Done | Fully implemented and working in Laravel |
| ⚠️ Partial | Exists but incomplete or has issues |
| ❌ TODO | Not implemented yet |
| 🔴 Bug | Implemented but has a bug that must be fixed |
| 💡 Better | Laravel implementation is superior to legacy |  

---

## Table of Contents

1. [Legacy Sales Module — Feature Inventory](#1-legacy-sales-module--feature-inventory)
2. [Legacy Sales Workflow — End-to-End Pipeline](#2-legacy-sales-workflow--end-to-end-pipeline)
3. [Legacy Business Logic — Deep Documentation](#3-legacy-business-logic--deep-documentation)
4. [Database Schema — Legacy MySQL vs Laravel PostgreSQL](#4-database-schema--legacy-mysql-vs-laravel-postgresql)
5. [Laravel Implementation — Current State](#5-laravel-implementation--current-state)
6. [Gap Analysis — Legacy vs Laravel](#6-gap-analysis--legacy-vs-laravel)
7. [PostgreSQL Power Features — Optimization Plan](#7-postgresql-power-features--optimization-plan)
8. [Implementation Priority & Phase Plan](#8-implementation-priority--phase-plan)

---

## 1. Legacy Sales Module — Feature Inventory

### 1.1 Controllers (8 primary + supporting)

| # | Controller | File | Purpose |
|---|-----------|------|---------|
| 1 | `SalesController` | `controllers/SalesController.php` (806 lines) | **Primary sales engine**: POS invoice creation, cart, finalize, today's sales hub, payment modal, print, audit |
| 2 | `ChallanController` | `controllers/ChallanController.php` (321 lines) | Godown prep, challan finalize, challan/godown print, reversal |
| 3 | `SalesReturnController` | `controllers/SalesReturnController.php` (410 lines) | Two-phase return (create → warehouse confirm), reversal, damage linkage |
| 4 | `PaymentController` | `controllers/PaymentController.php` (39 lines) | Legacy standalone payment (superseded by SalesController::save_payment) |
| 5 | `CustomerTransactionController` | `controllers/CustomerTransactionController.php` (257 lines) | Customer payment CRUD, GL journal, reversal, intercompany settlement |
| 6 | `CustomerController` | `controllers/CustomerController.php` (287 lines) | Customer master data, credit limit, deactivation safety |
| 7 | `SalesAuditController` | `controllers/SalesAuditController.php` (67 lines) | Ecosystem health checks, stale draft cleanup |
| 8 | `ReportController` | `controllers/ReportController.php` (1388 lines) | Gross margin, revenue overview, sales funnel, customer performance, AR aging, product movement |

**Supporting controllers**: `ReconciliationController` (GL tie-out), `DashboardController` (Revenue Overview dashboard + AJAX trend), `SalesFunnelController` (Pipeline dashboard with KPIs, conversion, forecast)

### 1.2 Models (12 primary)

| # | Model | File | Key Responsibility |
|---|-------|------|-------------------|
| 1 | `SalesModel` | `models/SalesModel.php` (121 lines) | Thin facade using 4 service traits |
| 2 | `ChallanModel` | `models/ChallanModel.php` (1785 lines) | Godown prep, challan finalize/reverse, transport adjustment, COGS |
| 3 | `SalesReturnModel` | `models/SalesReturnModel.php` (1813 lines) | Two-phase return, stock IN, damage write-off linkage |
| 4 | `SalesAuditModel` | `models/SalesAuditModel.php` (1526 lines) | 15-section health checks + auto-repair |
| 5 | `CustomerModel` | `models/CustomerModel.php` (617 lines) | Customer CRUD, credit limit, deactivation safety |
| 6 | `CustomerTransactionModel` | `models/CustomerTransactionModel.php` (777 lines) | Payment CRUD, GL, intercompany settlement |
| 7 | `CustomerTransactionAuditModel` | `models/CustomerTransactionAuditModel.php` (320 lines) | Payment integrity checks |
| 8 | `ProductModel` | `models/ProductModel.php` (~790 lines) | Product CRUD, price history, stock+price |
| 9 | `WarehouseModel` | `models/WarehouseModel.php` (693 lines) | Warehouse CRUD, stock by category/group, deactivation safety |
| 10 | `StockTransactionModel` | `models/StockTransactionModel.php` (264 lines) | Moving average cost, stock IN/OUT, reversal |
| 11 | `BranchDemandModel` | `models/BranchDemandModel.php` (690 lines) | Inter-branch demand, fulfillment, intercompany |
| 12 | `BaseModel` | `models/BaseModel.php` | Abstract base (unused in most models) |

### 1.3 Service Layer (Legacy — Traits + Standalone Services)

| # | Service/Trait | File | Purpose |
|---|--------------|------|---------|
| 1 | `SalesServiceSupportTrait` | `services/Sales/traits/` (shared) | Stock validation, price range, credit balance, rate validation |
| 2 | `SalesCartOperationsTrait` | `services/Sales/traits/` (471 lines) | Multi-tab cart, DB-backed drafts, merge/split, upsert |
| 3 | `SalesInvoiceOperationsTrait` | `services/Sales/traits/` (1249 lines) | Finalize, update, delete, DataTables, stale draft cleanup |
| 4 | `SalesPaymentOperationsTrait` | `services/Sales/traits/` (440 lines) | Payment record, reverse, receipt data |
| 5 | `StockService` | `services/Stock/` (142 lines) | Pessimistic locking, availability assertion |
| 6 | `StockAvailabilityService` | `services/Stock/` (381 lines) | **SSOT for sellable qty**: physical - pipeline |
| 7 | `JournalPostingService` | `services/Accounting/` (1980 lines) | **GL engine**: 20+ double-entry posting methods |
| 8 | `ReconciliationService` | `services/Accounting/` (822+ lines) | 6-section GL tie-out (AR, AP, Employee, Cash/Bank, Inventory, COGS) |
| 9 | `AccountingPeriodService` | `services/Accounting/` (249 lines) | Soft period close, year-end close |
| 10 | `BranchIntercompanyService` | `services/Branch/` (664 lines) | FIFO demand settlement, inter-branch journals |
| 11 | `NotificationService` | `services/Notification/` (160 lines) | Laravel push notifications via database + broadcast channels |
| 12 | `ERPNotification` | `Notifications/` (84 lines) | Unified notification class for all ERP events |

### 1.4 Views (33 sales-related views)

**Sales views (15):**
- `create.php` — POS-style invoice creation (customer search, product search, price slider, multi-tab cart)
- `edit.php` — Edit draft invoice (customer locked)
- `today.php` — **Main daily hub** (filters, payment modal, card/table view, CSV export)
- `index.php` — Legacy invoice list
- `show.php` — Invoice detail with GL journal blocks
- `receive_modal.php` — Payment collection modal (cash/bank, partial/full)
- `invoice_copy.php` — Print invoice (A4, Bengali, multi-page, watermarks)
- `print_receipt.php` — Payment receipt print
- `reconcile.php` — GL reconciliation dashboard
- `guide.php` — Bengali/English sales guideline
- `go_live_checklist.php` — Go-live checklist
- `audit.php` — Sales audit log viewer
- `RevenueOverview.php` — Executive KPI dashboard (Chart.js) — NOW: full Revenue Overview dashboard with 4 Chart.js charts
- `SalesFunnelPipeline.php` — Pipeline/funnel dashboard — NOW: full Sales Funnel dashboard with 4 Chart.js charts, conversion rates, forecast, salesman leaderboard
- `CustomerPerformance.php` — Customer 360° intelligence

**Challan views (6):**
- `create.php` — 3-step godown/challan wizard
- `index.php` — Challan queue with workflow badges
- `details.php` — Challan detail with GL
- `challan_copy.php` — Delivery challan print
- `godown_copy.php` — Godown picking list print
- `print_blank_godown_copy.php` — Blank godown for handwriting

**Sales Return views (8):**
- `create.php` — Step 1: Receive return from customer
- `create_workspace.php` — Reusable partial (full page + offcanvas)
- `index.php` — Returns list with offcanvas creation
- `confirm.php` — Step 2: Warehouse confirm (condition + warehouse)
- `reverse.php` — Reverse a completed return
- `details.php` — Return detail with GL
- `slip.php` — Return slip print
- `audit.php` — Return audit log

**Damage views (3):**
- `create.php` — Manual damage write-off
- `index.php` — Damage records list
- `details.php` — Damage detail with GL + stock movements

**Audit view (1):**
- `SalesAudit/checklist.php` — Ecosystem health checklist (AJAX refresh)

### 1.5 AJAX/API Endpoints (37 total)

**SalesController (16):** search_customer, search_product, product_by_code, get_branch, product_stock_at_branch, get_warehouse_stock, get_employees, customer_details, add_to_cart, load_cart, validate_cart, hydrate_edit_cart, list_draft_carts, clear_tab_cart, delete_tab_cart, delete_from_cart, update_cart_item, ~~save_fcm_token~~ *(removed — FCM replaced by Laravel Notification)*, final_sales, update, today_filter_summary, cancel_stale_drafts, datatable_invoices, call_it_a_day, delete_invoice, get_invoice_for_edit, save_payment, reverse_payment

**DashboardController (1):** salesTrendAjax (7D/30D/90D chart refresh)

**SalesFunnelController (1):** index (funnel KPIs, conversion rates, trend, salesman performance, forecast, open opportunities)

**ChallanController (6):** filter_summary, datatable_challans, prepare_godown, create_final_challan, reverse_challan, get_warehouses_for_product, get_dispatchers

**SalesReturnController (5):** return_filter_summary, datatable_returns, search_invoice, get_invoice_for_return, warehouse_stock_for_receive

**CustomerTransactionController (5):** DataTables, search_customer, store, get_due, reverse

**CustomerController (4):** DataTables, toggle, delete, restore

**SalesAuditController (2):** run_checks, cancel_stale_drafts

### 1.6 Authentication & Authorization

| Mechanism | Scope | Used By |
|-----------|-------|---------|
| `requireLogin()` | All controllers — session `user_id` required | Every controller constructor |
| `requireRouteAccess()` | Write operations — role-based route permission | final_sales, update, save_payment, reverse_payment, prepare_godown, create_final_challan, reverse_challan, store (return), confirm_store, reverse (return) |
| `validateCSRF()` | All POST requests | Every state-changing AJAX/POST |
| `guardJsonApi($key, $limit, $window)` | AJAX GET endpoints — rate limiting | search_customer (90/60s), search_product (90/60s), product_by_code (120/60s), etc. |
| `canOverrideBranch()` | Admin/manager cross-branch access | Branch selection, cross-branch listing |

**Session variables used:** `user_id`, `user_name`, `branch_id`, `branch_name`, `role`

---

## 2. Legacy Sales Workflow — End-to-End Pipeline

### 2.1 Sales Invoice Lifecycle State Machine

```
┌───────────────────────────────────────────────────────────────┐
│                    SALES PIPELINE (4 Stages)                   │
│                                                               │
│  ① DRAFT INVOICE                                              │
│  │  Controller: SalesController::final_sales()                │
│  │  Service:   SalesInvoiceService::finalizeSales()           │
│  │  GL:        Dr AR / Cr Revenue                             │
│  │  Ledger:    customer_ledger DEBIT (running_balance += amt) │
│  │  Stock:     Soft hold via sales_invoice_dispatches          │
│  │             (ordered_qty set, dispatched_qty = 0)          │
│  │                                                             │
│  ↓  (can delete draft, can edit draft)                        │
│                                                               │
│  ② GODOWN ISSUED                                              │
│  │  Controller: ChallanController::prepare_godown()           │
│  │  Service:   ChallanModel::prepareGodown()                  │
│  │  GL:        NONE (warehouse assignment only)               │
│  │  Stock:     NO physical movement yet                       │
│  │  Effect:    warehouse_id assigned to items + dispatches    │
│  │             status → godown_issued                          │
│  │             dispatchers assigned                            │
│  │             transport_cost captured                         │
│  │                                                             │
│  ↓  (can reverse godown → back to draft)                      │
│                                                               │
│  ③ CHALLAN COMPLETED                                          │
│  │  Controller: ChallanController::create_final_challan()     │
│  │  Service:   ChallanModel::finalizeChallan()                │
│  │  GL:        Dr COGS / Cr Inventory (at avg_cost)           │
│  │  Ledger:    customer_ledger ADJUSTMENT (if transport delta)│
│  │  Stock:     Physical OUT from warehouse_stock               │
│  │             dispatched_qty = ordered_qty                    │
│  │  Snapshot:  pre_challan_transport/total saved               │
│  │             sales_challan_items (issue_rate, cogs_amount)   │
│  │                                                             │
│  ↓  (can reverse challan → back to godown_issued)             │
│                                                               │
│  ④ PAYMENT RECEIVED                                           │
│     Controller: SalesController::save_payment()               │
│                OR CustomerTransactionController::store()      │
│     Service:   SalesPaymentService::recordCustomerPayment()   │
│                OR CustomerTransactionModel::createTransaction()│
│     GL:        Dr Cash/Bank / Cr AR                           │
│     Ledger:    customer_ledger CREDIT (running_balance -= amt)│
│     Settlement: invoice_payment_allocations                    │
│     Interco:   BranchIntercompanyService (bank-mode only)     │
└───────────────────────────────────────────────────────────────┘
```

### 2.2 Sales Return Two-Phase Flow

```
┌───────────────────────────────────────────────────────────────┐
│                  SALES RETURN PIPELINE (2 Phases)              │
│                                                               │
│  ① PHASE 1 — CREATE RETURN (Sales Manager)                    │
│  │  Controller: SalesReturnController::store()                │
│  │  Service:   SalesReturnModel::createReturn()               │
│  │  GL:        NONE (draft return)                             │
│  │  Stock:     NO movement yet                                 │
│  │  Validates: invoice status = challan_completed              │
│  │             returnable_qty = invoice_qty - already_returned │
│  │                                                             │
│  ↓                                                             │
│                                                               │
│  ② PHASE 2 — WAREHOUSE CONFIRM                                │
│     Controller: SalesReturnController::confirm_store()        │
│     Service:   SalesReturnModel::confirmReturn()              │
│                                                               │
│     For GOOD condition items:                                  │
│     ├── Stock IN at ORIGINAL avg_cost (not current)            │
│     ├── GL Revenue Reversal: Dr Sales Return / Cr AR          │
│     ├── GL COGS Reversal: Dr Inventory / Cr COGS              │
│     └── Customer ledger CREDIT (customer owes less)           │
│                                                               │
│     For DAMAGE condition items:                                │
│     ├── Stock IN at ORIGINAL avg_cost (temporarily)            │
│     ├── Auto-create damage_invoices via DamageService          │
│     ├── Stock OUT immediately (write-off)                      │
│     ├── GL: Dr Shrinkage / Cr Inventory                       │
│     ├── Link: sales_return_items.damage_invoice_id             │
│     └── Customer still gets credit for the return              │
│                                                               │
│  (can reverse return → undoes all stock + GL + damage)        │
└───────────────────────────────────────────────────────────────┘
```

### 2.3 GL Posting Points Summary

| Action | Debit | Credit | Trigger Point |
|--------|-------|--------|---------------|
| Invoice finalize | AR | Revenue (+ Discount contra, Transport revenue) | `final_sales()` |
| Godown prep | — | — | `prepare_godown()` (NO GL) |
| Challan finalize | COGS | Inventory | `create_final_challan()` |
| Transport adjustment (increase) | AR | Transport Revenue | `create_final_challan()` (if delta) |
| Transport adjustment (decrease) | Transport Revenue | AR (reversal) | `create_final_challan()` (if delta) |
| Payment received (cash) | Cash | AR | `save_payment()` / `store()` |
| Payment received (bank) | Bank | AR | `save_payment()` / `store()` |
| Return confirmed (Good) — Revenue reversal | Sales Return | AR | `confirmReturn()` |
| Return confirmed (Good) — COGS reversal | Inventory | COGS | `confirmReturn()` |
| Return confirmed (Damage) — Write-off | Shrinkage | Inventory | `confirmReturn()` → auto damage |
| Manual damage | Shrinkage | Inventory | DamageService |

### 2.4 Stock Movement Rules

| Event | Direction | Rate | Effect on avg_cost |
|-------|-----------|------|--------------------|
| Purchase receive | IN | Purchase rate | **Recalculated** (weighted average) |
| Challan finalize (stock OUT) | OUT | Current avg_cost | **Unchanged** (standard accounting) |
| Sales return confirm (Good) | IN | ORIGINAL avg_cost from challan | **Recalculated** (weighted average with original cost) |
| Sales return confirm (Damage) | IN then OUT | Original then avg_cost | IN recalculates, OUT unchanged |
| Damage write-off | OUT | Current avg_cost | **Unchanged** |
| Warehouse transfer (source) | OUT | Current avg_cost | **Unchanged** |
| Warehouse transfer (dest) | IN | Source avg_cost | **Recalculated** |
| Branch demand fulfillment (source) | OUT | Current avg_cost | **Unchanged** |
| Branch demand fulfillment (dest) | IN | Source avg_cost | **Recalculated** |

**Moving Average Formula (Stock IN):**
```
new_avg_cost = (old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)
```

**Stock Availability Formula (SSOT):**
```
available_qty = GREATEST(0, physical_qty - pipeline_qty)
pipeline_qty = SUM(ordered_qty - dispatched_qty)
  FROM sales_invoice_dispatches
  JOIN sales_invoices ON ...
  WHERE NOT reversed, NOT challan_completed/cancelled
  AND ordered_qty > dispatched_qty
```

---

## 3. Legacy Business Logic — Deep Documentation

### 3.1 Invoice Finalization (finalizeSales) — 9-Step Atomic

1. **Validate customer + cart not empty** — must have customer_id and at least one item
2. **Calculate totals** — `subtotal = Σ(qty × rate)`, `total_amount = subtotal + transport_cost - discount_amount`
3. **Credit limit enforcement** — `wouldExceedCreditLimit(customerId, newAmount)`:
   - `currentDue = latest customer_ledger.running_balance`
   - If `credit_limit > 0` AND `currentDue + newAmount > credit_limit + 0.01`:
     - Without override → return `{status: 'credit_limit_exceeded'}`
     - With override → require `override_reason` ≥ 10 characters
4. **Pre-transaction stock + rate validation** — `StockAvailabilityService::assertBranchProductsAvailable()` + `validateRateInRange()`
5. **DB TRANSACTION begins**:
   - a. Lock branch products `SELECT warehouse_stock FOR UPDATE`
   - b. Re-verify stock inside lock (race condition protection)
   - c. Generate invoice code (e.g., `SI-20250120-001`) via `DocumentSequenceService` (advisory lock)
   - d. INSERT `sales_invoices` (status='draft')
   - e. INSERT `sales_invoice_items` (warehouse_id=NULL at this stage)
   - f. INSERT `sales_invoice_dispatches` (ordered_qty, dispatched_qty=0) — **soft reservation**
   - g. INSERT `customer_ledger` DEBIT (running_balance = previous + total_amount)
   - h. Post GL via `JournalPostingService::postSalesInvoice()` — Dr AR / Cr Revenue
   - i. Link `journal_entry_id` on `sales_invoices`
6. **COMMIT** → clear session cart
7. **On error** → ROLLBACK

### 3.2 Invoice Update (updateExistingInvoice) — 10-Step Atomic

1. Load old invoice, verify **editability guards**: draft, no godown, no payments, not reversed
2. Load cart items, calculate new totals
3. **Credit limit check on NET INCREASE only** — `max(0, newTotal - oldTotal)` (key improvement over full-amount check)
4. **DB TRANSACTION begins**:
   - a. Re-lock invoice `FOR UPDATE`
   - b. Lock branch products `FOR UPDATE`
   - c. Re-verify stock (exclude current invoice's dispatches)
   - d. Reverse old customer_ledger (credit the old debit)
   - e. New customer_ledger debit
   - f. UPDATE `sales_invoices`
   - g. DELETE old items + dispatches + dispatchers → re-insert new ones
   - h. Reverse prior GL journal → post new journal
   - i. Reset `is_godown_prepared = false` (edit invalidates godown assignment)
5. **COMMIT**

### 3.3 Godown Preparation (prepareGodown) — 5-Step Atomic

1. **DB TRANSACTION begins**:
   - a. Lock invoice `SELECT ... FOR UPDATE` — verify status='draft' (legacy) or 'confirmed' (Laravel), not reversed
   - b. Validate each warehouse assignment has sufficient physical stock
   - c. UPDATE `sales_invoice_items` SET `warehouse_id` for each line
   - d. DELETE + re-INSERT `sales_invoice_dispatches` (with warehouse_id)
   - e. INSERT `sales_invoice_dispatchers` (if dispatchers selected)
   - f. UPDATE `sales_invoices` SET `status = 'godown_issued'` (legacy) / `is_godown_prepared = true` (Laravel), `godown_issued_at = NOW()`
   - g. Capture `transport_cost` (may differ from initial invoice transport)
2. **COMMIT**
3. **NO GL posting** — no financial effect at this stage
4. **NO stock movement** — physical stock unchanged

### 3.4 Challan Finalization (finalizeChallan) — 8-Step Atomic

1. Assert godown is prepared, not already challan-issued
2. **DB TRANSACTION begins**:
   - a. Lock invoice + challan `FOR UPDATE`
   - b. Generate challan code via `DocumentSequenceService` (advisory lock)
   - c. For each item:
      - Get current `avg_cost` from `warehouse_stock`
      - **Stock OUT**: negative qty via `StockTransactionModel::updateWarehouseStock()`
      - Snapshot per-line cost into `sales_challan_items` (issue_rate, cogs_amount)
      - UPDATE `sales_invoice_dispatches` SET `dispatched_qty = ordered_qty`
      - Accumulate `cogsTotal += qty × avg_cost`
   - d. INSERT `sales_challans` header
   - e. **Transport adjustment** (if challan transport ≠ invoice transport):
      - Snapshot original: `pre_challan_transport`, `pre_challan_total`
      - Post `customer_ledger` ADJUSTMENT entry (running_balance delta)
      - Post GL adjustment: Dr AR / Cr Transport Revenue (positive delta) or reverse (negative delta)
   - f. Post GL: **Dr COGS / Cr Inventory** (single journal for all lines)
   - g. Link `journal_entry_id` + `cogs_journal_entry_id` on `sales_invoices`
   - h. UPDATE `sales_invoices` SET `is_challan_issued = true`, `challan_issued_at = NOW()`
3. **COMMIT**
4. Send Telegram notification

**Key insight**: Revenue is recognized at **invoice finalize** (accrual basis), but COGS is recognized at **challan finalize** (when goods physically leave warehouse). This creates a timing gap that gross margin reports must account for.

### 3.5 Payment Recording (recordCustomerPayment) — 7-Step Atomic

1. Parse payment_mode (cash/bank) and bank_id
2. **DB TRANSACTION begins**:
   - a. If invoice_id: lock invoice `FOR UPDATE`, verify not reversed, branch accessible
   - b. Check payment doesn't exceed invoice balance: `paidSoFar + amount <= total_amount + 0.01`
   - c. Generate payment_code via `DocumentSequenceService` (advisory lock)
   - d. INSERT `customer_payments`
   - e. INSERT `customer_ledger` CREDIT (running_balance -= amount)
   - f. If invoice_id: INSERT `invoice_payment_allocations`
   - g. Post GL: **Dr Bank/Cash / Cr AR**
   - h. Link `journal_entry_id` on `customer_payments`
   - i. Inter-branch settlement (bank-mode only): `BranchIntercompanyService::settleFromCustomerPayment()`
3. **COMMIT**
4. Return payment_id, payment_code, balance_due, is_fully_paid

### 3.6 Payment Reversal (reverseCustomerPayment) — 8-Step Atomic

1. **DB TRANSACTION begins**:
   - a. Lock payment `FOR UPDATE`, verify not reversed, transaction_type='receive'
   - b. Load allocations + verify branch access
   - c. Reverse linked GL journal (append-only reversing entry)
   - d. Reverse inter-branch settlements
   - e. INSERT `customer_ledger` DEBIT reversal (running_balance += amount)
   - f. DELETE `invoice_payment_allocations`
   - g. Soft-delete payment: `is_reversed = 1`, `reversed_at`, `reversed_by`, `reverse_reason`
2. **COMMIT**

### 3.7 Sales Return Creation (createReturn) — Phase 1

1. Validates invoice is `challan_completed` (not draft, not already returned fully)
2. **returnable_qty** per item = `invoiceItem.qty - SUM(already returned qty from non-reversed/non-cancelled returns)`
3. Creates `sales_returns` with status='pending' (legacy) / 'created' (Laravel)
4. Snapshots `original_cost` from challan's `stock_transactions` — **NOT current avg_cost**
5. **NO GL, NO stock movement** at this stage
6. Sends Telegram notification to admins

### 3.8 Sales Return Confirmation (confirmReturn) — Phase 2, Multi-Step Atomic

1. **DB TRANSACTION begins**:
   - a. Lock return + items `FOR UPDATE`, verify status='pending'/'created'
   - b. For each Good condition item:
      - Stock IN at `original_cost` (avg_cost recalculated by weighted average rule)
      - GL Revenue Reversal: **Dr Sales Return / Cr AR** (at sales rate)
      - GL COGS Reversal: **Dr Inventory / Cr COGS** (at original avg_cost)
   - c. For each Damage condition item:
      - Stock IN at `original_cost` (temporarily)
      - Auto-create `damage_invoices` via DamageService:
        - Draft damage → immediately confirm → Stock OUT at avg_cost
        - GL: **Dr Shrinkage / Cr Inventory**
      - Link `sales_return_items.damage_invoice_id → damage_invoices.id`
   - d. INSERT `customer_ledger` CREDIT (running_balance -= total_return_amount)
   - e. Link `journal_entry_id` + `cogs_journal_entry_id` on `sales_returns`
2. **COMMIT**
3. Sends Telegram notification

### 3.9 Sales Return Reversal (reverseReturn) — Multi-Step Atomic

1. Pre-check: `can_reverse` flag, sufficient on-hand stock to remove returned items
2. **DB TRANSACTION begins**:
   - a. Lock return `FOR UPDATE`
   - b. **Reverse linked damage write-offs FIRST** (order matters!)
   - c. Reverse each stock movement from the return (Stock OUT for Good items that were returned to warehouse)
   - d. Reverse both GL journals (revenue + COGS)
   - e. Reverse customer_ledger (debit entry, running_balance += amount)
3. **COMMIT**

### 3.10 Credit Limit Enforcement

```
Formula:
  currentDue = latest customer_ledger.running_balance (for customer)
  projectedDue = currentDue + newInvoiceAmount
  exceeds = (credit_limit > 0) AND (projectedDue > credit_limit + 0.01)
  
  If exceeds AND no override → BLOCK with credit_limit_exceeded
  If exceeds AND override + reason ≥ 10 chars → ALLOW with audit log
```

**Edge cases:**
- `credit_limit = 0` → no enforcement (unlimited credit)
- Override is tracked: `sale_credit_limit_override` audit event
- Invoice update only checks net increase: `max(0, newTotal - oldTotal)`

### 3.11 Stale Draft Cleanup

- **Threshold**: Configurable (default 14 days)
- **Criteria**: `status='draft'`, `is_godown_prepared=false`, `is_challan_issued=false`, `is_reversed=false`
- **Batch limit**: 200 per run
- **Dry-run mode**: Supported
- **Scheduled**: Daily at 02:00 (configurable on/off)
- **Audit**: Each cancellation logged to `user_audit_log`

### 3.12 Customer Ledger Running Balance

```
Each financial event posts a row to customer_ledger:
  invoice      → DEBIT  (customer owes more): balance = prev + amount
  payment      → CREDIT (customer owes less): balance = prev - amount
  return       → CREDIT (customer owes less): balance = prev - amount
  reversal     → Opposite of original (compensating entry)
  invoice_adj  → DELTA  (transport change at challan): balance = prev ± delta
  
SSOT for customer due: latest running_balance row for the customer
```

### 3.13 Branch Isolation

All sales operations enforce `sessionBranchId()`:
- `assertInvoiceAccessible()` — throws if invoice belongs to different branch
- `warehouseBelongsToBranch()` — validates warehouse ownership
- Admin/manager can override with `canOverrideBranch()`
- Override is logged: `branch_override` audit event

### 3.14 Inter-Branch Settlement (Bank Payments)

When a bank payment is received at Branch A for a customer who owes Branch B:
1. Payment GL posts normally (Dr Bank / Cr AR) at Branch A
2. `BranchIntercompanyService::settleFromCustomerPayment()` fires:
   - Iterates all creditor branches (branches the customer owes)
   - FIFO settles inter-branch demands: oldest first
   - Creates `branch_ledger` entries (debtor Dr / creditor Cr)
   - Posts intercompany GL: Dr Interbranch Payable / Cr Interbranch Receivable
3. Cash payments do NOT trigger intercompany settlement (use money transfers instead)

---

## 4. Database Schema — Legacy MySQL vs Laravel PostgreSQL

### 4.1 Sales-Related Tables (20 tables)

| # | Table | MySQL Source | PG SQL File | Status |
|---|-------|-------------|-------------|--------|
| 1 | `sales_invoices` | Legacy | 04_sales.sql | ✅ Active |
| 2 | `sales_invoice_items` | Legacy | 04_sales.sql | ✅ Active |
| 3 | `sales_invoice_dispatchers` | Legacy | 04_sales.sql | ✅ Active (UI + service code for assignment) |
| 4 | `sales_invoice_dispatches` | Legacy | 04_sales.sql | ✅ Active |
| 5 | `sales_challans` | Legacy | 04_sales.sql | ✅ Active |
| 6 | `sales_challan_items` | Legacy (late addition) | Migration P0-5 | ✅ Added via migration |
| 7 | `sales_draft_carts` | Legacy | 04_sales.sql | ✅ Active |
| 8 | `sales_returns` | Legacy | 04_sales.sql | ✅ Active |
| 9 | `sales_return_items` | Legacy | 04_sales.sql | ✅ Active |
| 10 | `customers` | Legacy | 01_auth_and_master.sql | ✅ Active |
| 11 | `customer_payments` | Legacy | 06_payment_and_misc.sql | ✅ Active |
| 12 | `customer_payment_settlements` | Legacy | 06_payment_and_misc.sql | ⚠️ DROPPED by P1-4 |
| 13 | `invoice_payment_allocations` | New (Laravel) | 05_purchase.sql | ✅ Active (replacement) |
| 14 | `customer_ledger` | Legacy | 02_accounting.sql | ✅ Active |
| 15 | `products` | Legacy | 01_auth_and_master.sql | ✅ Active |
| 16 | `product_price_history` | Legacy | 01_auth_and_master.sql | ✅ Active |
| 17 | `warehouse_stock` | Legacy | 03_stock.sql | ✅ Active (composite PK) |
| 18 | `stock_transactions` | Legacy | 03_stock.sql | ✅ Active |
| 19 | `damage_invoices` | Legacy | 03_stock.sql | ✅ Active (with sales_return_id via P1-5) |
| 20 | `damage_invoice_items` | Legacy | 03_stock.sql | ✅ Active |

### 4.2 Key Schema Differences (MySQL → PostgreSQL)

#### Status Values Migration

| Table | MySQL ENUM | PG CHECK | ETL Conversion |
|-------|-----------|----------|---------------|
| `sales_invoices.status` | draft, godown_issued, challan_completed, cancelled, reversed | draft, confirmed, cancelled, reversed | `godown_issued` → `confirmed` + `is_godown_prepared=true`; `challan_completed` → `confirmed` + both flags true |
| `sales_returns.status` | pending, completed, reversed | created, confirmed, reversed | `pending` → `created`; `completed` → `confirmed` |

**Key design change**: Legacy used ENUM status progression (draft → godown_issued → challan_completed). Laravel uses **boolean flags** (`is_godown_prepared`, `is_challan_issued`) alongside a simpler status CHECK. This is more flexible and avoids ALTER TYPE for new statuses.

#### MySQL Features Converted to PostgreSQL

| MySQL Feature | PostgreSQL Equivalent | Concern |
|--------------|----------------------|---------|
| `ENUM` types | `VARCHAR + CHECK IN (...)` | ⚠️ Adding new values requires ALTER CHECK constraint |
| `AUTO_INCREMENT` | `GENERATED ALWAYS AS IDENTITY` | ✅ Superior — no gaps from rollbacks |
| `ON UPDATE CURRENT_TIMESTAMP` | Trigger `update_updated_at_column()` + BEFORE UPDATE trigger per table | ⚠️ Must ensure all tables have trigger |
| `float(20,2)` for money | `numeric(18,2)` | 🔴 CRITICAL — Float has precision loss |
| `int(11) storing YYYYMMDD` | `date` with `CURRENT_DATE` | 🔴 CRITICAL — ETL conversion needed |
| `0000-00-00 00:00:00` dates | `NULL` | ⚠️ pgloader zero-dates-to-null |
| `ON DUPLICATE KEY UPDATE` | `INSERT ... ON CONFLICT DO UPDATE` | ✅ Native equivalent |
| `IF()` in GENERATED columns | `CASE WHEN ... THEN ... ELSE ... END` | ✅ Clean |
| `SIGNAL SQLSTATE '45000'` | `RAISE EXCEPTION USING ERRCODE = 'check_violation'` | ✅ Clean |
| Backtick identifiers | Removed (unquoted, PG folds to lowercase) | ✅ Clean |
| `DATE_FORMAT / CURDATE / DATEDIFF` | `to_char() / CURRENT_DATE / (a::date - b::date)` | ✅ Clean |

#### PostgreSQL-Specific Additions (Not in Legacy MySQL)

| Feature | Table | Purpose |
|---------|-------|---------|
| `GENERATED ALWAYS AS (qty * rate) STORED` | sales_invoice_items, sales_return_items | Auto-computed line amount — eliminates PHP calculation drift |
| `PARTIAL INDEX` | sales_invoice_dispatches `WHERE dispatched_qty < ordered_qty` | Fast pipeline query (only open dispatches indexed) |
| `PARTIAL INDEX` | customers `WHERE is_active = true` | Fast active-customer lookups |
| `PARTIAL INDEX` | sales_return_items `WHERE sales_invoice_item_id IS NOT NULL` | Sparse index for FK lookups |
| `PARTIAL INDEX` | sales_return_items `WHERE damage_invoice_id IS NOT NULL` | Sparse index for damage links |
| `PARTIAL INDEX` | customer_payments `WHERE reference_no IS NOT NULL` | Sparse index for cheque/references |
| `IDENTITY` columns | All PKs | Sequence-based, gap-safe auto-increment |
| `jsonb` column | sales_draft_carts.items_json | Efficient JSON storage + indexing |
| Materialized views | mv_ar_aging, mv_product_movement_summary, mv_stock_valuation | Pre-computed reporting |
| `enforce_balanced_journal_entry()` trigger | journal_lines | DB-level double-entry enforcement |
| `prevent_negative_stock()` trigger | warehouse_stock | DB-level inventory floor |

### 4.3 Columns Added by Migrations (Missing from Initial PG Schema)

| Migration | Column(s) Added | Why It Was Missing |
|-----------|----------------|-------------------|
| P0-1 | `sales_invoices.transport_cost` | Omitted from 04_sales.sql but code references it |
| P0-2 | `sales_invoice_dispatches.ordered_qty, dispatched_qty, created_by` | PG redesign collapsed to single `qty`; legacy needs both for pipeline tracking |
| P0-3 | `sales_returns.cogs_amount, reason` + `sales_return_items.sales_invoice_item_id` | Omitted from PG redesign; needed for COGS reversal + returnable_qty tracking |
| P0-4 | `customer_payments.reference_no` | Omitted; needed for cheque/transaction references |
| P0-5 | **Entire `sales_challan_items` table** | Omitted from PG redesign; critical for per-line COGS snapshot |
| P1-4 | **Drops `customer_payment_settlements`** | Replaced by `invoice_payment_allocations` (cleaner naming) |
| P1-5 | `sales_return_items.damage_invoice_id` + `damage_invoices.sales_return_id, total_value, status` | Damage ↔ Return linkage missing |
| P2-3 | `sales_invoices.pre_challan_transport, pre_challan_total` | Transport snapshot for safe challan reversal |
| P2-5 | `customer_payments.transaction_type` | ✅ FIXED — Column + CHECK constraint + full service implementation for receive/discount/write_off/payment types with type-specific GL posting |

### 4.4 Denormalization Patterns

| Pattern | Purpose | Risk |
|---------|---------|------|
| `customer_ledger.balance` (running total) | Fast AR lookup without SUM() | 🔴 Drift risk if rows modified |
| `sales_invoices.sub_total, discount, total, paid, due` | Avoid JOIN+SUM on every read | ⚠️ Must recompute if line items change |
| `sales_invoices.sales_person` (varchar) | Denormalized salesman name alongside FK | ⚠️ Stale if employee name changes |
| `sales_challans.issue_cost` (aggregate) | Fast COGS reporting | ⚠️ Must sync with per-line items |
| `sales_return_items.original_cost` (snapshot) | Return at original cost, not current | ✅ Correct design — prevents COGS drift |
| `sales_invoices.pre_challan_transport/total` | Safe challan reversal | ✅ Correct — enables exact rollback |
| `sales_invoices.paid_amount, due_amount` | Fast balance read | ⚠️ Must update on each payment allocation/reversal |

---

## 5. Laravel Implementation — Current State

### 5.1 Controllers (6)

| # | Controller | Key Methods | Delegates To |
|---|-----------|-------------|-------------|
| 1 | `SalesCartController` | index, load, add, update, remove, clear, validateCart, softHold, checkAvailability | SalesCartService + StockAvailabilityService |
| 2 | `SalesInvoiceController` | index, finalize, edit, update, cancel, cancelStaleDrafts, auditTrail, printInvoice, printGodown, show, getCartData, checkCreditLimit | SalesInvoiceService |
| 3 | `SalesChallanController` | index, godown, storeGodown, challanForm, issueChallan, show, cancel, printChallan | SalesChallanService |
| 4 | `CustomerPaymentController` | index, create, store, show, cancel, printReceipt, getOutstandingInvoices | CustomerPaymentService |
| 5 | `SalesReturnController` | index, create, store, show, confirm, reverse, printSlip, getInvoiceDetails | SalesReturnService |
| 6 | `CustomerController` | CRUD (extends BaseMasterDataController) | CodeGenerator, Customer model |
| 7 | `DashboardController` | index, salesTrendAjax | 6 private KPI methods (getRevenueKPIs, getSalesTrend, getBranchRevenue, getReceivableAging, getTopCustomers, getTopProducts) |
| 8 | `SalesFunnelController` | index (funnel dashboard) | 7 private data methods (getFunnelData, getKPIs, getConversionRates, getPipelineTrend, getSalesmanPerformance, getOpenOpportunities, getForecast) |

### 5.2 Models (10 primary + supporting)

| Model | Key Features |
|-------|-------------|
| `SalesInvoice` | SoftDeletes, BranchScope global scope, AuditableMasterData trait, status helpers (isDraft/isConfirmed/isCancelled/isReversed) |
| `SalesInvoiceItem` | No timestamps, GENERATED amount column, condition_state |
| `SalesInvoiceDispatch` | No timestamps, remainingQty() helper |
| `SalesChallan` | SoftDeletes, BranchScope, transport fields, adjustment JE links |
| `SalesChallanItem` | Created_at only, scopeForActiveChallans, issue_rate + cogs_amount |
| `SalesReturn` | SoftDeletes, BranchScope, status helpers (isCreated/isConfirmed/isReversed) |
| `SalesReturnItem` | No timestamps, original_cost snapshot, damage_invoice_id link |
| `SalesDraftCart` | JSONB items_json, unique(user_id, customer_id), getOrCreate upsert |
| `CustomerPayment` | SoftDeletes, BranchScope, isBankMode() helper |
| `InvoicePaymentAllocation` | Created_at only, invoice ↔ payment many-to-many |

### 5.3 Services (7 sales + 6 stock + 6 accounting)

**Sales Services:**
| Service | Key Methods | Business Logic |
|---------|-------------|---------------|
| SalesCartService | getCart, addItem, updateItem, removeItem, clearCart, validateCart, setSoftHold | JSONB cart, price range validation, stock availability |
| SalesInvoiceService | finalizeFromCart (9-step), updateInvoice (10-step), cancelInvoice | Credit limit, pessimistic locking, atomic GL + ledger |
| SalesChallanService | prepareGodown, issueChallan (COGS), cancelChallan | Stock OUT at avg_cost, transport adjustment, per-line COGS snapshot |
| CustomerPaymentService | createPayment, confirmPayment (5-step), cancelPayment | GL, customer ledger, allocation, intercompany |
| SalesReturnService | createReturn, confirmReturn (4+1-step), reverseReturn | Original cost lookup, linked damage, COGS reversal |
| SalesAuditLogger | 13 event types | Dual-write (DB + file) |
| SalesAccess | assertBranchAccessible, resolveBranchIdForWrite | Branch isolation enforcement |

**Stock Services:**
| Service | Key Methods |
|---------|-------------|
| StockService | applyTransaction (UPSERT + moving average), reverseTransaction, lockBranchProductsForUpdate |
| StockAvailabilityService | getBranchAvailableQty, getWarehouseAvailableQty, Redis pipeline cache (5-min TTL) |
| DamageService | createDamage, confirmDamage, cancelDamage |
| StockTakeService | createSession, setupWarehouseCounts, saveCounts, postSession |
| StockAdjustmentService | createAdjustment, confirmAdjustment, cancelAdjustment |
| WarehouseTransferService | createTransfer, confirmTransfer, cancelTransfer |

**Accounting Services:**
| Service | Key Methods |
|---------|-------------|
| JournalPostingService | createJournalEntry (balanced), reverseJournalEntry, lookupLedgerByNature, verifyAllEntriesBalanced |
| JournalReversalService | reverseByJournalEntry, reverseByReference, verifyReversalNetsToZero |
| SubLedgerService | postCustomerLedgerEntry, postSupplierLedgerEntry, postEmployeeLedgerEntry, reverse methods, reconcileAll |
| LedgerNatureService | 7 critical + 13 extended natures |
| AccountingPeriodService | closePeriod, reopenPeriod, yearEndClose, preCloseGate |
| ReconciliationService | 6-section GL tie-out (AR, AP, Employee, Cash/Bank, Inventory, COGS) |

### 5.4 Blade Views (20 views — ALL FULLY IMPLEMENTED)

| View | Lines | Purpose |
|------|-------|---------|
| sales/cart.blade.php | ~64KB | SPA-like cart workspace |
| sales-invoices/index | 302 | Invoice list with filters/stats |
| sales-invoices/show | 748 | Complete detail + GL journal |
| sales-invoices/edit | 370 | Edit draft |
| sales-invoices/print_invoice | 146 | A4 paginated print |
| sales-invoices/print_godown | 120 | Picking list print |
| sales-challans/index | 266 | Challan list |
| sales-challans/show | 520 | Challan detail + GL |
| sales-challans/godown | 275 | Godown prep form |
| sales-challans/issue | 274 | Challan issue form with COGS preview |
| sales-challans/print_challan | 131 | Delivery note print |
| sales-returns/index | 302 | Returns list |
| sales-returns/create | 447 | Return form with returnable_qty |
| sales-returns/show | 761 | Detail + GL + stock movements |
| sales-returns/print_slip | 139 | Return slip print |
| customer-payments/index | 304 | Payment list |
| customer-payments/create | 527 | Receive payment with allocations |
| customer-payments/show | 615 | Detail + GL + intercompany |
| customer-payments/print_receipt | 116 | Payment receipt print |
| sales-audit/index | 166 | Audit trail with action icons |
| dashboard/index | ~550 | Revenue Overview: 6 KPI cards + 4 Chart.js charts (sales trend line, aging donut, branch bar, revenue vs collection) + 2 mini-tables + 7D/30D/90D AJAX toggle |
| reports/sales_funnel | ~580 | Sales Funnel: 6 KPI cards + 4 Chart.js charts (funnel bar, forecast, pipeline trend, salesman stacked) + conversion rates + opportunities + leaderboard + branch/date filters |

### 5.5 Routes (5 groups)

| Group | Prefix | Middleware | Key Routes |
|-------|--------|-----------|------------|
| Cart | admin/sales | role:salesman,manager,admin + branch.isolation | cart, load, add, update, remove, clear, validate, soft-hold, availability, finalize, cart-data, credit-check, cancel-stale-drafts, audit |
| Invoices | admin/sales-invoices | role-based per route | index, show, edit, update, cancel, print-invoice, print-godown |
| Challans | admin/sales-challans | role:warehouse_manager,dispatcher,manager,admin | index, show, godown, storeGodown, challan-form, issueChallan, cancel, print-challan |
| Payments | admin/customer-payments | role:salesman,accountant,manager,admin | index, create, store, show, cancel, print-receipt, outstanding-invoices |
| Returns | admin/sales-returns | role:varies per action | index, create, store, show, confirm, reverse, print-slip, invoice-details |
| Dashboard | / | auth | index, sales-trend (AJAX 7D/30D/90D) |
| Reports/Funnel | admin/reports | role:manager,admin | sales-funnel (funnel KPIs, conversion, trend, forecast, salesman, opportunities) |

### 5.6 Artisan Commands + pg_cron

| Command / pg_cron Job | Schedule | Purpose |
|----------------------|----------|---------|
| `sales:cancel-stale-drafts` | Daily 02:00 | Cancel drafts >14 days (configurable, dry-run, max 200/run). **Fallback** for pg_cron `cancel-stale-drafts` job |
| `sales:pen-test` | On-demand | 5 automated security tests (RBAC, BranchScope, excluded roles, mass assignment) |
| `reports:refresh` | Every 5 min | Refresh materialized views. **Fallback** for pg_cron `refresh-report-views` job |
| `reconcile:running-balance` | On-demand | Verify running balances in 4 sub-ledgers (refreshes MVs + snapshots) |
| pg_cron: `cancel-stale-drafts` | Daily 02:00 | DB-level stale draft cleanup via `cancel_stale_sales_drafts()` function |
| pg_cron: `refresh-report-views` | Every 5 min | DB-level MV refresh via `refresh_all_report_views()` function |
| pg_cron: `refresh-rb-checks` | Hourly | DB-level running-balance MV refresh (4 MVs) |
| pg_cron: `purge-old-notifications` | Daily 03:00 | Delete read notifications >90 days via `purge_old_notifications()` |
| pg_cron: `analyze-high-write-tables` | Daily 04:00 | ANALYZE 17 high-write tables via `vacuum_analyze_high_write_tables()` |

### 5.7 Middleware Stack

| Middleware | Alias | Purpose |
|-----------|-------|---------|
| EnforceBranchIsolation | branch.isolation | Forged branch_id protection + record-level DB check |
| EnsureRole | role:... | Role-based access control |
| CheckSystemPolicy | (global) | Investigation mode, policy sharing |
| SyncLegacySession | (auth group) | Redis legacy session → Laravel |
| CheckCredentialVersion | (auth group) | Password/role change invalidation |
| ApiAuth | api.auth | Bearer token auth |
| ApiRateLimit | api.rate:N | Redis rate limiting per (token, IP) |

### 5.8 Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `sales.stale_draft_days` | 14 | Days before draft is stale |
| `sales.stale_draft_auto_cancel` | true | Enable nightly auto-cancel |
| `sales.stale_draft_cancelled_by` | 1 | System user for auto-cancel |
| `sales.stale_draft_max_per_run` | 200 | Max cancellations per run |
| `app.gl_reconciliation_tolerance` | 0.02 | GL tolerance threshold |

---

## 6. Gap Analysis — Legacy vs Laravel

### 6.1 CRITICAL Gaps (Must Fix)

| # | Gap | Legacy Has | Laravel Status | Verified? | Impact |
|---|-----|-----------|---------------|-----------|--------|
| G-1 | **Sales services bypass SubLedgerService** | Inline customer_ledger writes | ✅ FIXED — All 8 inline `customer_ledger` inserts replaced with `SubLedgerService::postCustomerLedgerEntry()` calls in SalesInvoiceService (3), CustomerPaymentService (2), SalesReturnService (2), SalesChallanService (1) | ✅ VERIFIED & FIXED | Single point of control restored; journal_entry_id now consistently linked |
| G-2 | **Sales services bypass JournalReversalService** | Manual GL + sub-ledger reversal inline | ✅ FIXED — All 10 inline reversal calls (journalPosting->reverseJournalEntry + manual subLedger reversal) replaced with journalReversal->reverseByJournalEntry() cascade in SalesInvoiceService (2), CustomerPaymentService (2), SalesChallanService (2), SalesReturnService (2), PurchaseReturnService (1), PurchaseReceiveService (1); also replaced 4 direct supplier_ledger inserts with SubLedgerService calls and removed 4 dead private methods | ✅ VERIFIED & FIXED | Single point of control for reversal; original sub-ledger entries now correctly marked is_reversed=true (preventing double-count) |
| G-3 | **journal_entry_id not always linked in customer_ledger** | Some paths link, some don't | ✅ FIXED — SubLedgerService integration ensures journal_entry_id is passed to every customer_ledger insert; GL is posted first, then ledger with the journal_entry_id | ✅ VERIFIED & FIXED | Reconciliation chain now complete |
| G-4 | **SQL injection in ReconciliationService** | Parameterized in legacy | ✅ FIXED — All 16 string-interpolated `$asOfDate` filters replaced with parameterized `?` placeholders + bound params in 6 reconciliation methods; added validateDate() defense-in-depth (rejects non-Y-m-d format) | ✅ VERIFIED & FIXED | SQL injection vector eliminated; parameterized queries prevent arbitrary SQL execution via as_of_date query param |

### 6.2 HIGH Priority Gaps (Should Fix)

| # | Gap | Legacy Has | Laravel Status | Verified? | Impact |
|---|-----|-----------|---------------|-----------|--------|
| G-5 | **Invoice dispatchers not assigned** | `sales_invoice_dispatchers` populated in finalize/edit flow | ✅ FIXED — Full UI + service implementation: dispatchers() relationship, assignDispatchers() method, Select2 multi-select in cart/edit, badge display in show/print, branch isolation + role validation | ✅ VERIFIED & FIXED |
| G-6 | **Multi-payment allocation** | One payment can be allocated to multiple invoices | ✅ FIXED — confirmPayment() now accepts allocations array [{invoice_id, allocated_amount}]; controller reads alloc_invoice_id[] + alloc_amount[] from form; UI already had multi-invoice allocation table | ✅ VERIFIED & FIXED |
| G-7 | **Payment transaction types** | receive/payment/discount/write_off | ✅ FIXED — Full implementation: type-specific GL posting (receive→Dr Bank/Cash/Cr AR, discount→Dr Sales Discount/Cr AR, write_off→Dr Bad Debt Expense/Cr AR, payment→Dr AR/Cr Bank/Cash), type-specific customer ledger (credit for AR reduction, debit for refund), type-specific payment codes (PAY/DISC/WOFF/RFND prefixes), type-specific audit events, dynamic UI with type selector + color-coded badges | ✅ VERIFIED & FIXED |
| G-8 | **Discount GL posting on payment** | `postCustomerDiscount()` in JournalPostingService | ✅ FIXED — Discount GL now posts on confirm: receive+discount→Dr Sales Discount/Cr AR for discount_amount; write_off→Dr Bad Debt Expense/Cr AR; payment→Dr AR/Cr Bank/Cash | ✅ VERIFIED & FIXED |
| G-9 | **Salesman commission tracking** | salesman_id tracked on invoices | No commission calculation service | ❌ Not implemented | Commission reports not possible |
| G-10 | **Call It A Day** batch operation | `callItADay()` in SalesInvoiceOperationsTrait | ✅ Implemented in SalesInvoiceService + SalesInvoiceController | ✅ VERIFIED — SalesInvoiceService::callItADay(), route POST admin/sales-invoices/call-it-a-day, SalesAuditLogger::callItADay(), migration adds call_a_day column | End-of-day workflow complete |
| G-11 | **Customer 360 hub** | `CustomerController::show()` with summary, ledger, invoices, payments | ✅ FIXED — Full 360° hub with 7 KPI cards (AR balance, credit utilization bar, total invoiced, total paid, open invoices, last payment, total returns), 5-tab interface (Overview/Ledger/Invoices/Payments/Returns), server-side DataTables AJAX for each tab, date range filter on ledger, status/type filters on invoices & payments, reverse relationships added to Customer model, new payment shortcut button | ✅ VERIFIED & FIXED |

### 6.3 MEDIUM Priority Gaps (Nice to Have)

| # | Gap | Legacy Has | Laravel Status | Verified? |
|---|-----|-----------|---------------|-----------|
| G-12 | **Sales API write endpoints** | Full AJAX CRUD | Only read-only dashboard + lookups | ❌ Not implemented |
| G-13 | **Telegram/FCM notifications** | Full notification suite (5 event types) | ✅ REPLACED — Custom Telegram/FCM removed; Laravel native Notifiable + ERPNotification via database + broadcast channels. Migration 000007 drops fcm_tokens table + telegram_user_id column. NotificationService dispatches to role-based recipients. | ✅ VERIFIED & FIXED |
| G-14 | **Sales guideline page** | Bengali/English user guide | Not implemented | ❌ Not implemented |
| G-15 | **Go-live checklist** | Manager sign-off checklist | Not implemented | ❌ Not implemented |
| G-16 | **Revenue Overview dashboard** | Chart.js KPI dashboard with filters | ✅ FIXED — Full Revenue Overview dashboard: 6 KPI cards (today revenue, MTD revenue + growth %, MTD collection + rate, MTD outstanding, total outstanding, collection rate), 4 Chart.js charts (sales trend line with 7D/30D/90D toggle, receivable aging donut, branch revenue bar, revenue vs collection horizontal bar), 2 mini-tables (top 5 customers, top 5 products), AJAX trend refresh endpoint | ✅ VERIFIED & FIXED |
| G-17 | **Sales Funnel/Pipeline dashboard** | Pipeline stages, win rate, velocity | ✅ FIXED — Full Sales Funnel dashboard: 6 KPI cards (open pipeline, weighted pipeline, closed won, win rate, pipeline velocity, stale drafts), 4 Chart.js charts (horizontal funnel bar with total+weighted value, revenue forecast 30/60/90d bar, pipeline trend 6-month line, salesman stacked bar), stage conversion rates table, pipeline stage summary table with probability weights, open opportunities table (top 25 with health badges), salesman leaderboard table, branch + date range filters, SalesFunnelController with 7 data methods (getFunnelData, getKPIs, getConversionRates, getPipelineTrend, getSalesmanPerformance, getOpenOpportunities, getForecast). Pipeline stages derived from status + boolean flags: Draft Cart (10%) → Draft Invoice (25%) → Godown Ready (50%) → Delivered (75%) → Paid/Closed (100%) | ✅ VERIFIED & FIXED |
| G-18 | **Customer Performance dashboard** | CLV, churn risk, segment breakdown | Basic report view only | ⚠️ Partial |
| G-19 | **Blank godown copy print** | Handwrite template with blank cells | ✅ IMPLEMENTED — print_godown.blade.php has blank Picked Qty + Signature columns | ✅ VERIFIED — This was previously marked wrong |
| G-20 | **Invoice CSV export** | `export()` in SalesController | Not implemented | ❌ Not implemented |
| G-21 | **Challan CSV export** | `export()` in ChallanController | Not implemented | ❌ Not implemented |
| G-22 | **Dead model cleanup** | N/A | ✅ FIXED — Removed dead `CustomerPaymentSettlement` model (table dropped by P1-4, replaced by `InvoicePaymentAllocation`) | ✅ VERIFIED & FIXED |

### 6.4 Design Improvements in Laravel (Better Than Legacy)

| # | Improvement | Description |
|---|------------|-------------|
| ✅ | **Two-phase flow everywhere** | Draft → confirm/cancel (vs legacy immediate post). Major correctness gain. |
| ✅ | **Append-only reversals** | Originals never mutated, only `is_reversed` flag. Clean audit trail. |
| ✅ | **Atomic document code generation** | `document_sequences` with `pg_advisory_xact_lock` (Task 20) replaces legacy `SELECT FOR UPDATE` + `COUNT+1` race condition. Centralized `DocumentSequenceService` eliminates 12 duplicated code blocks. |
| ✅ | **Proper pessimistic locking** | `lockForUpdate()` on key rows prevents concurrent-write corruption. |
| ✅ | **Pipeline-aware availability** | `physical - pipeline` prevents overselling (legacy had no pipeline concept). |
| ✅ | **Redis-cached pipeline** | 5-min TTL with invalidation avoids repeated JOINs. |
| ✅ | **Per-line COGS snapshot** | `sales_challan_items.issue_rate` captures exact avg_cost at time of OUT. |
| ✅ | **Original cost lookup for returns** | Ensures COGS reversal matches exactly; avg_cost restored correctly. |
| ✅ | **Linked damage write-offs** | Properly chains damage invoices to sales returns with correct reversal ordering. |
| ✅ | **GENERATED STORED columns** | `sales_invoice_items.amount`, `sales_return_items.amount`, `sales_invoices.due_amount`, `sales_challan_items.cogs_amount`, `warehouse_stock.stock_value` — eliminates PHP calculation drift |
| ✅ | **Partial indexes** | Pipeline query, active customers, sparse FK lookups — faster than full indexes. |
| ✅ | **DB-level triggers** | Balanced journal, negative stock prevention — data integrity at DB level. |
| ✅ | **Materialized views** | AR aging, product movement, stock valuation — pre-computed for fast reporting. |
| ✅ | **Pen-test command** | Automated security verification — no equivalent in legacy. |
| ✅ | **Idempotency token** | Invoice finalize checks for duplicate submission — prevents double-creation. |
| ✅ | **Credit limit on net increase** | Invoice update only checks `max(0, newTotal - oldTotal)` — better UX than legacy. |

---

## 7. PostgreSQL Power Features — Optimization Plan

The user specifically requested to **"use the total power of PostgreSQL so we don't have to fall in any issue in long term."** Below is a comprehensive plan for PostgreSQL-specific optimizations that go beyond what's currently implemented.

### 7.1 GENERATED Columns — Expand Usage

Currently `amount` on `sales_invoice_items` and `sales_return_items` is GENERATED. Phase 1C-12 added 3 more:

| Table | Column | Expression | Status |
|-------|--------|-----------|--------|
| `sales_invoices` | `due_amount` | `total_amount - paid_amount` | ✅ DONE — Phase 1C-12 |
| `sales_challan_items` | `cogs_amount` | `ROUND(qty * issue_rate, 2)` | ✅ DONE — Phase 1C-12 |
| `warehouse_stock` | `stock_value` | `ROUND(qty * avg_cost, 2)` | ✅ DONE — Phase 1C-12 (new column) |
| `sales_challans` | `issue_cost` | — | ❌ NOT POSSIBLE — PostgreSQL forbids subqueries in GENERATED expressions; cross-table aggregate |
| `sales_returns` | `cogs_amount` | `(SELECT SUM(qty * original_cost) FROM sales_return_items ...)` | ❌ NOT POSSIBLE — same cross-table subquery restriction |

**Why this matters for long-term**: GENERATED columns are **always correct** — no service can forget to update them, no bug can make them stale. This eliminates an entire class of data inconsistency bugs.

### 7.2 Partial Indexes — ✅ Implemented (Task 13)

13 partial indexes have been added via migration `2025_01_20_000001_add_partial_indexes_business_queries.php`
and mirrored in `07_views_triggers_constraints.sql`. The original 5 master-data partial indexes
(from migration `2025_01_14`) remain unchanged. Total: **18 partial indexes** across the system.

#### Open Invoices (2 indexes)
These cover the AR aging report, collections dashboard, and call-it-a-day list —
the hottest queries in the system. Only confirmed invoices with an outstanding
balance are indexed (typically ~15-30% of all invoice rows).

```sql
-- AR aging / collections dashboard
CREATE INDEX idx_si_open_invoice
  ON sales_invoices (customer_id, due_amount, invoice_date)
  WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0;

-- Branch dashboard / call-it-a-day list
CREATE INDEX idx_si_open_by_branch
  ON sales_invoices (branch_id, invoice_date)
  WHERE status = 'confirmed' AND is_reversed = false AND due_amount > 0;
```

#### Unpaid / Active Payments (4 indexes)
AR/AP dashboards list only non-reversed payments. A partial index covers the
~95% of rows that are live, avoiding wasted index space on reversed records.

```sql
-- Customer payment history (AR)
CREATE INDEX idx_cp_active
  ON customer_payments (customer_id, payment_date)
  WHERE is_reversed = false;

-- Supplier payment history (AP)
CREATE INDEX idx_sp_active
  ON supplier_payments (supplier_id, payment_date)
  WHERE is_reversed = false;

-- Daily collection report (branch-scoped)
CREATE INDEX idx_cp_active_by_branch
  ON customer_payments (branch_id, payment_date)
  WHERE is_reversed = false;

-- Daily payment report (branch-scoped)
CREATE INDEX idx_sp_active_by_branch
  ON supplier_payments (branch_id, payment_date)
  WHERE is_reversed = false;
```

#### Pending Returns (2 indexes)
Sales returns in `created` status need manager review. Purchase returns that
haven't been reversed are actively tracked in the returns dashboard.

```sql
-- Sales returns awaiting confirmation
CREATE INDEX idx_sr_pending
  ON sales_returns (branch_id, return_date)
  WHERE status = 'created' AND is_reversed = false;

-- Active purchase returns
CREATE INDEX idx_prtn_pending
  ON purchase_returns (supplier_id, branch_id)
  WHERE is_reversed = false;
```

#### Active Ledger (5 indexes)
Sub-ledger rows with open balances, unsettled intercompany, non-reversed journal
entries, and the chart-of-accounts filter — all the queries that power the
accounting dashboard and trial balance.

```sql
-- AR outstanding rows
CREATE INDEX idx_cl_outstanding
  ON customer_ledger (customer_id, transaction_date, balance)
  WHERE balance > 0;

-- AP outstanding rows
CREATE INDEX idx_sl_outstanding
  ON supplier_ledger (supplier_id, transaction_date, balance)
  WHERE balance > 0;

-- Unsettled intercompany transactions
CREATE INDEX idx_bl_unsettled
  ON branch_ledger (from_branch_id, to_branch_id, transaction_date)
  WHERE is_settled = false;

-- Non-reversed journal entries (GL reports, trial balance)
CREATE INDEX idx_je_active
  ON journal_entries (entry_date, branch_id, reference_type)
  WHERE is_reversed = false;

-- Chart of accounts by type (active only)
CREATE INDEX idx_ledgers_active_by_type
  ON ledgers (account_type, ledger_code)
  WHERE is_active = true;
```

**Full reference table** is in `docs/migration/schema_mapping.md` Section 3.8.

**Why this matters for long-term**: Partial indexes are **smaller** (only relevant rows) and **faster** (fewer pages to scan). As data grows, the gap between full and partial indexes widens dramatically.

### 7.3 Covering Indexes (INCLUDE) — ✅ Implemented (Task 14)

16 covering indexes have been added via migration `2025_01_20_000002_add_covering_indexes_high_freq_queries.php`
and mirrored in `07_views_triggers_constraints.sql`. Covering indexes use the INCLUDE clause to store
additional columns in the B-tree leaf pages, enabling **index-only scans** — PostgreSQL never
needs to visit the heap (actual table pages), which can be 5-10x faster on large tables.

#### P0 — Critical Path (every invoice finalize + credit check)

```sql
-- Customer ledger balance: SUM(debit) - SUM(credit) per customer
-- Without INCLUDE, PG must visit heap for debit/credit → full-page random I/O
-- With INCLUDE, PG does an index-only scan → sequential leaf read
CREATE INDEX idx_cl_balance_covering
  ON customer_ledger (customer_id, is_reversed)
  INCLUDE (debit, credit);

-- Outstanding invoices per customer (payment allocation AJAX)
CREATE INDEX idx_si_customer_due_covering
  ON sales_invoices (customer_id, is_reversed)
  INCLUDE (id, invoice_code, invoice_date, total_amount, paid_amount, due_amount)
  WHERE due_amount > 0;
```

#### P1 — Per-Transaction Lookups (every reversal, cancel, show page)

```sql
-- Journal entries by reference (reversal, cancel, show page)
CREATE INDEX idx_je_reference_covering
  ON journal_entries (reference_type, reference_id, is_reversed)
  INCLUDE (id, entry_no, entry_date, branch_id, description, source, created_by);

-- Journal lines per-entry detail (every journal show page)
CREATE INDEX idx_jl_entry_covering
  ON journal_lines (journal_entry_id)
  INCLUDE (id, ledger_id, debit, credit, entity_type, entity_id, memo);

-- Journal lines per-ledger reporting (GL report, trial balance)
CREATE INDEX idx_jl_ledger_date_covering
  ON journal_lines (ledger_id, journal_entry_id)
  INCLUDE (debit, credit);
```

#### P2 — Listing Pages (DataTable with branch + status + date filters)

```sql
-- Sales invoices listing (branch_id, status, date range → DataTable)
CREATE INDEX idx_si_listing_covering
  ON sales_invoices (branch_id, status, invoice_date DESC, id DESC)
  INCLUDE (customer_id, invoice_code, total_amount, paid_amount, due_amount,
           is_godown_prepared, is_challan_issued, is_reversed);

-- Customer payments listing
CREATE INDEX idx_cp_listing_covering
  ON customer_payments (branch_id, payment_date DESC, id DESC)
  INCLUDE (customer_id, payment_code, payment_mode, amount, is_reversed);

-- Supplier payments listing
CREATE INDEX idx_sp_listing_covering
  ON supplier_payments (branch_id, payment_date DESC, id DESC)
  INCLUDE (supplier_id, payment_code, payment_mode, amount, is_reversed);

-- Invoice payment allocations (paid-so-far per invoice)
CREATE INDEX idx_ipa_invoice_covering
  ON invoice_payment_allocations (invoice_id)
  INCLUDE (payment_id, allocated_amount);

-- Warehouse stock reverse lookup (product → warehouses with qty + avg_cost)
CREATE INDEX idx_ws_product_covering
  ON warehouse_stock (product_id, warehouse_id)
  INCLUDE (qty, avg_cost);

-- Sales challans listing
CREATE INDEX idx_sc_listing_covering
  ON sales_challans (branch_id, challan_date DESC, id DESC)
  INCLUDE (sales_invoice_id, challan_code, is_reversed, issue_cost, transport_cost);
```

#### P3 — Supporting Queries (by-reference lookups, secondary listings)

```sql
-- Purchase receives listing
CREATE INDEX idx_pr_listing_covering
  ON purchase_receives (branch_id, receive_date DESC, id DESC)
  INCLUDE (supplier_id, receive_code, total_amount, is_reversed, purchase_order_id);

-- Supplier ledger by reference (per purchase receive show/cancel)
CREATE INDEX idx_sl_reference_covering
  ON supplier_ledger (reference_type, reference_id)
  INCLUDE (id, supplier_id, branch_id, transaction_date, transaction_type,
           debit, credit, balance, journal_entry_id, created_by);

-- Stock transactions by reference (per challan show / purchase cancel)
CREATE INDEX idx_st_reference_covering
  ON stock_transactions (reference_type, reference_id)
  INCLUDE (id, warehouse_id, product_id, qty, rate, transaction_date, created_by);

-- Customer ledger by reference (per payment/invoice show page)
CREATE INDEX idx_cl_reference_covering
  ON customer_ledger (reference_type, reference_id)
  INCLUDE (id, customer_id, branch_id, transaction_date, transaction_type,
           debit, credit, balance, journal_entry_id, created_by);

-- Purchase orders listing
CREATE INDEX idx_po_listing_covering
  ON purchase_orders (branch_id, po_date DESC, id DESC)
  INCLUDE (supplier_id, po_code, total_amount, status);
```

**Full reference table** is in `docs/migration/schema_mapping.md` Section 3.9.

**Why this matters for long-term**: Covering indexes enable **index-only scans** — PostgreSQL never needs to visit the heap (actual table pages). For frequently queried columns, this can be 5-10x faster on large tables.

### 7.4 BRIN Indexes — ✅ Implemented (Task 15)

PostgreSQL BRIN (Block Range Index) indexes store only min/max summaries per block range of pages, making them extraordinarily compact — typically ~0.1% of table size versus ~10% for B-tree indexes. They are ideal for columns with natural physical correlation (i.e., rows are appended in roughly increasing order), which is the case for all date/timestamp columns in this ERP. BRIN indexes complement existing B-tree indexes: B-tree handles equality/point lookups efficiently, while BRIN handles date-range scans (e.g., "last 30 days", "this month") at near-zero storage and maintenance cost.

**Design principles applied:**

1. **Only on chronologically-correlated columns** — `created_at`, `*_date` columns where new rows are appended in increasing order. BRIN is useless on columns with random physical ordering (e.g., `customer_id`).
2. **pages_per_range tuning** — 32 for medium tables (~256 KB per range), 64 for very-large or pure-append tables (stock_transactions, audit logs) where coarser summaries still provide excellent correlation.
3. **Complementary to B-tree** — Existing B-tree indexes on `invoice_date`, `payment_date`, `entry_date` handle point lookups; BRIN accelerates the much more common date-range queries used by every dashboard and report.
4. **Dual-column strategy** — Both `*_date` (business date) and `created_at` (system timestamp) are indexed separately because queries use either or both, and each has independent correlation.

Migration: `2025_01_20_000003_add_brin_indexes_time_series_tables.php`
Schema: `07_views_triggers_constraints.sql` — BRIN INDEXES section added.

#### 1. Core Transaction Tables (10 indexes)

These are the highest-traffic tables in the ERP. Every listing page and dashboard filters by date range — today, this week, this month, or a custom range. BRIN allows PostgreSQL to skip entire block ranges of old data that fall outside the query window.

```sql
-- sales_invoices: AR aging, collections dashboard, monthly revenue
CREATE INDEX idx_si_created_at_brin
    ON sales_invoices USING BRIN (created_at)
    WITH (pages_per_range = 32);

CREATE INDEX idx_si_invoice_date_brin
    ON sales_invoices USING BRIN (invoice_date)
    WITH (pages_per_range = 32);

-- customer_payments: daily collection report, payment history
CREATE INDEX idx_cp_payment_date_brin
    ON customer_payments USING BRIN (payment_date)
    WITH (pages_per_range = 32);

CREATE INDEX idx_cp_created_at_brin
    ON customer_payments USING BRIN (created_at)
    WITH (pages_per_range = 32);

-- supplier_payments: AP aging, payment history
CREATE INDEX idx_sp_payment_date_brin
    ON supplier_payments USING BRIN (payment_date)
    WITH (pages_per_range = 32);

CREATE INDEX idx_sp_created_at_brin
    ON supplier_payments USING BRIN (created_at)
    WITH (pages_per_range = 32);

-- sales_returns: returns report by period
CREATE INDEX idx_sr_return_date_brin
    ON sales_returns USING BRIN (return_date)
    WITH (pages_per_range = 32);

-- purchase_receives: GRN listing by date, monthly purchase summary
CREATE INDEX idx_pr_receive_date_brin
    ON purchase_receives USING BRIN (receive_date)
    WITH (pages_per_range = 32);

-- purchase_returns: return date range queries
CREATE INDEX idx_prtn_return_date_brin
    ON purchase_returns USING BRIN (return_date)
    WITH (pages_per_range = 32);

-- purchase_orders: PO listing by date
CREATE INDEX idx_po_po_date_brin
    ON purchase_orders USING BRIN (po_date)
    WITH (pages_per_range = 32);
```

#### 2. Sub-Ledgers (8 indexes)

Sub-ledger rows are appended chronologically per entity. The AR aging report scans `customer_ledger WHERE transaction_date >= ?`, the AP aging scans `supplier_ledger WHERE transaction_date >= ?`, and the employee statement queries `employee_ledger WHERE transaction_date BETWEEN ? AND ?`. BRIN on `transaction_date` allows the planner to skip months or years of historical blocks instantly, while BRIN on `created_at` supports system-level queries (e.g., "entries created in the last hour for reconciliation").

```sql
-- customer_ledger: AR aging, customer 360 ledger tab
CREATE INDEX idx_cl_transaction_date_brin
    ON customer_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX idx_cl_created_at_brin
    ON customer_ledger USING BRIN (created_at)
    WITH (pages_per_range = 32);

-- supplier_ledger: AP aging, supplier payment history
CREATE INDEX idx_sl_transaction_date_brin
    ON supplier_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

CREATE INDEX idx_sl_created_at_brin
    ON supplier_ledger USING BRIN (created_at)
    WITH (pages_per_range = 32);

-- employee_ledger: employee advance/loan/salary statement
CREATE INDEX idx_el_transaction_date_brin
    ON employee_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

-- branch_ledger: intercompany settlement by period
CREATE INDEX idx_bl_transaction_date_brin
    ON branch_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

-- cash_ledger: daily cash position, branch cash history
CREATE INDEX idx_cashl_transaction_date_brin
    ON cash_ledger USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

-- branch_expenses: expense report by period
CREATE INDEX idx_be_expense_date_brin
    ON branch_expenses USING BRIN (expense_date)
    WITH (pages_per_range = 32);
```

#### 3. Inventory Ledger (2 indexes) — pages_per_range = 64

`stock_transactions` is the largest append-only table in the ERP. Every stock movement — sales challan, purchase receive, return, adjustment, transfer — appends a row. The product movement report queries "last 30 days of stock movements for product X", which is a date-range scan that benefits enormously from BRIN. Using `pages_per_range = 64` because this table grows fastest and coarser block ranges still maintain excellent physical correlation.

```sql
CREATE INDEX idx_st_transaction_date_brin
    ON stock_transactions USING BRIN (transaction_date)
    WITH (pages_per_range = 64);

CREATE INDEX idx_st_created_at_brin
    ON stock_transactions USING BRIN (created_at)
    WITH (pages_per_range = 64);
```

#### 4. Audit & Log Tables (3 indexes) — pages_per_range = 64

Audit logs and notification tables are pure append-only — rows are never updated, only inserted. This makes them the ideal BRIN use case with perfect physical correlation. Using `pages_per_range = 64` because these tables accumulate indefinitely and the coarser granularity is acceptable for date-range queries like "show audit events from the last 7 days".

```sql
-- user_audit_log: security audit trail queries by date range
CREATE INDEX idx_ual_created_at_brin
    ON user_audit_log USING BRIN (created_at)
    WITH (pages_per_range = 64);

-- notifications: "show recent notifications" (last 7 days)
CREATE INDEX idx_notif_created_at_brin
    ON notifications USING BRIN (created_at)
    WITH (pages_per_range = 64);

-- journal_posting_logs: audit trail for GL posting actions
CREATE INDEX idx_jpl_performed_at_brin
    ON journal_posting_logs USING BRIN (performed_at)
    WITH (pages_per_range = 64);
```

#### 5. Daily Summaries (1 index)

`daily_warehouse_stock_summary` stores one row per warehouse×product×day. Queries always filter by `summary_date` range (e.g., "stock movement summary for the last 30 days"). Rows are strictly ordered by date, making BRIN extremely effective.

```sql
CREATE INDEX idx_dwss_summary_date_brin
    ON daily_warehouse_stock_summary USING BRIN (summary_date)
    WITH (pages_per_range = 32);
```

#### 6. Other Transaction Tables (6 indexes)

Income, expense, employee transactions, money transfers, sales challans, and manual journals each have their own business date columns and are appended chronologically. These support their respective listing and reporting pages.

```sql
-- other_incomes: income report by period
CREATE INDEX idx_oi_income_date_brin
    ON other_incomes USING BRIN (income_date)
    WITH (pages_per_range = 32);

-- other_expenses: expense report by period
CREATE INDEX idx_oe_expense_date_brin
    ON other_expenses USING BRIN (expense_date)
    WITH (pages_per_range = 32);

-- employee_transactions: employee statement by period
CREATE INDEX idx_et_transaction_date_brin
    ON employee_transactions USING BRIN (transaction_date)
    WITH (pages_per_range = 32);

-- money_transfers: transfer history by date
CREATE INDEX idx_mt_transfer_date_brin
    ON money_transfers USING BRIN (transfer_date)
    WITH (pages_per_range = 32);

-- sales_challans: challan listing by date
CREATE INDEX idx_sc_challan_date_brin
    ON sales_challans USING BRIN (challan_date)
    WITH (pages_per_range = 32);

-- manual_journals: manual journal listing by date
CREATE INDEX idx_mj_journal_date_brin
    ON manual_journals USING BRIN (journal_date)
    WITH (pages_per_range = 32);
```

**Total: 30 BRIN indexes** across 22 tables. At ~0.1% of table size, the total BRIN index footprint is negligible — estimated under 1 MB even for tables with millions of rows. Every date-range query (AR aging, AP aging, daily collection, monthly revenue, product movement, stock summary) now benefits from block-level pruning without the storage cost of additional B-tree indexes.

### 7.5 JSONB Indexing — ✅ Implemented (Task 16)

PostgreSQL GIN (Generalized Inverted Index) indexes are the standard approach for indexing JSONB columns. They support the `@>` containment operator, which checks whether a JSONB document contains a specific key/value pair or nested structure. For `sales_draft_carts.items_json`, this enables queries like "find all carts that contain product_id 42" without scanning every row's JSONB blob.

**Operator class choice: `jsonb_path_ops`**

The default GIN operator class for JSONB supports both containment (`@>`) and existence (`?`, `?|`, `?&`) operators, but at the cost of a larger index. The `jsonb_path_ops` operator class supports only `@>`, but produces an index that is approximately 30% smaller and faster for containment lookups. Since the expected query pattern for cart items is containment ("does this cart contain this product/warehouse?"), `jsonb_path_ops` is the optimal choice.

**Current usage vs. forward-looking value**: Currently, `items_json` is treated as an opaque blob — the application reads the full JSONB array into PHP, mutates it, and writes it back. All WHERE clauses use scalar columns (`user_id`, `customer_id`). However, the GIN index is valuable now for several reasons:

1. **Inventory reservation**: Future stock availability checks will query "which open carts contain product X?" to compute pipeline demand alongside warehouse stock. Without the GIN index, this requires a full table scan of all draft carts.
2. **Multi-warehouse cart tracking**: As the cart system evolves to track warehouse assignment per item, queries like "find carts with items from warehouse 3" become natural containment checks.
3. **Near-zero cost**: The GIN index on `jsonb_path_ops` is compact (~10% of JSONB data size) and has minimal write overhead for the cart usage pattern (create → update a few times → delete on invoice save).
4. **Zero risk**: Adding the index does not change any existing query behavior. The PostgreSQL planner will simply start using it when a compatible `@>` query appears.

Migration: `2025_01_20_000004_add_gin_index_draft_carts_items_json.php`
Schema: `07_views_triggers_constraints.sql` — GIN INDEX section added.

```sql
-- GIN index for JSONB cart items — enables @> containment queries
CREATE INDEX idx_sdc_items_gin
    ON sales_draft_carts USING GIN (items_json jsonb_path_ops);

-- Example queries this enables:

-- 1. Find all carts containing a specific product (inventory reservation)
SELECT user_id, customer_id, items_json
FROM sales_draft_carts
WHERE items_json @> '[{"product_id": 42}]';

-- 2. Find carts with items from a specific warehouse (warehouse workload)
SELECT user_id, customer_id, items_json
FROM sales_draft_carts
WHERE items_json @> '[{"warehouse_id": 3}]';

-- 3. Find carts with a specific product in a specific condition
SELECT user_id, customer_id, items_json
FROM sales_draft_carts
WHERE items_json @> '[{"product_id": 42, "condition_state": "Damage"}]';
```

**Index footprint**: GIN with `jsonb_path_ops` is approximately 30% smaller than default GIN. For a table with ~1,000 active draft carts averaging 5 items each, the index is estimated at under 100 KB. The index is effectively free to maintain — cart rows are created, updated a handful of times during the sales entry process, and then deleted when the invoice is finalized.

### 7.6 Advisory Locks — ✅ Implemented (Task 20) Replace SELECT FOR UPDATE for Code Generation

Previously, `document_sequences` used `SELECT FOR UPDATE` (pessimistic row-level locking) for atomic code generation. This has been replaced with PostgreSQL advisory locks (`pg_advisory_xact_lock`), which are significantly faster and avoid a critical RLS interaction issue.

**What changed**:
- Created `DocumentSequenceService` (centralized service replacing 12 duplicated `lockForUpdate` code blocks across Sales, Purchase, Stock, and Accounting services)
- Advisory lock key = `crc32(doc_type:branch_id:period_key)` mapped to signed int4
- Lock is transaction-scoped (`pg_advisory_xact_lock`) — auto-released on COMMIT/ROLLBACK
- Covering index `idx_doc_seq_covering ON (doc_type, branch_id, period_key) INCLUDE (last_number, id)` makes the SELECT fast without heap access
- SQL helper function `doc_seq_advisory_key()` mirrors the PHP hash for SQL diagnostics

**RLS fix**: The old `lockForUpdate()` on `document_sequences` would fail for non-admin users because RLS policies filtered by `branch_id = current_setting('app.branch_id')::int`, hiding the branch_id=0 rows used by global sequences. Advisory locks bypass this entirely since they operate at the session level, independent of row visibility. New RLS policies explicitly allow branch_id=0 reads/writes for all users.

**Migration**: `2025_01_20_000008_replace_doc_seq_select_for_update_with_advisory_locks.php`

**Service refactoring** (12 services → centralized `DocumentSequenceService::nextCode()`):

| Service | Method | doc_type | Code Format |
|---------|--------|----------|-------------|
| SalesInvoiceService | generateInvoiceCode() | sales_invoice | INV-YYYYMMDD-NNNN |
| SalesChallanService | generateChallanCode() | sales_challan | CH-YYYYMMDD-NNNN |
| SalesReturnService | generateReturnCode() | sales_return | SR-YYYYMMDD-NNNN |
| CustomerPaymentService | generatePaymentCode() | customer_payment_{type} | PAY/DISC/WOFF/RFND-YYYYMMDD-NNNN |
| PurchaseOrderService | generatePoCode() | purchase_order | PO-YYYYMMDD-NNNN |
| PurchaseReceiveService | generateReceiveCode() | purchase_receive | GRN-YYYYMMDD-NNNN |
| PurchaseReturnService | generateReturnCode() | purchase_return | PR-YYYYMMDD-NNNN |
| StockAdjustmentService | generateAdjustmentCode() | stock_adjustment | ADJ-YYYYMMDD-NNNN |
| StockTakeService | generateSessionCode() | stock_take | ST-YYYYMMDD-NNNN |
| WarehouseTransferService | generateTransferCode() | warehouse_transfer | WT-YYYYMMDD-NNNN |
| DamageService | generateDamageCode() | damage | DMG-YYYYMMDD-NNNN |
| JournalPostingService | generateEntryNo() | journal_entry | JE-YYYY-NNNNNN |

**Why advisory locks outperform SELECT FOR UPDATE**:

| Aspect | SELECT FOR UPDATE | Advisory Lock |
|--------|-------------------|---------------|
| Lock storage | Disk (page infomask + WAL) | Shared memory only |
| Read blocking | Blocks all reads on the row | Reads proceed unblocked |
| Lock scope | Row-level | Session/transaction-level |
| RLS interaction | WHERE clause filtered → 0 rows | Independent of RLS |
| Auto-release | On COMMIT/ROLLBACK only | On COMMIT/ROLLBACK (xact) |
| Performance | ~0.5–2ms per lock (disk I/O) | ~0.01–0.05ms (memory only) |
| Collision risk | N/A | ~0.00009% with 20 active types |

```php
// Before (12 copies of this pattern):
return DB::transaction(function () use ($docType, $periodKey, $datePart) {
    $seqRow = DB::table('document_sequences')
        ->where('doc_type', $docType)
        ->where('branch_id', 0)
        ->where('period_key', $periodKey)
        ->lockForUpdate()  // ← replaced by advisory lock
        ->first();
    // ... 20 more lines of identical code
});

// After (1 call per service):
return DocumentSequenceService::nextCode(
    docType:  'sales_invoice',
    prefix:   'INV',
    datePart: now()->format('Ymd'),
    padLength: 4,
);
```

```sql
-- SQL diagnostic: check which advisory locks are currently held
SELECT locktype, classid, objid, mode, granted
FROM pg_locks
WHERE locktype = 'advisory';

-- Compute the same lock key as DocumentSequenceService
SELECT doc_seq_advisory_key('sales_invoice', 0, '2025-01');
```

### 7.7 LISTEN/NOTIFY — Replace Polling for Real-Time Updates

Replace AJAX polling (DataTables refresh, pipeline cache) with PostgreSQL LISTEN/NOTIFY:

```sql
-- On invoice finalize:
NOTIFY sales_invoice_created, '{"invoice_id": 123, "branch_id": 1}';

-- On challan issue:
NOTIFY sales_challan_issued, '{"challan_id": 45, "warehouse_ids": [1,2]}';

-- On payment:
NOTIFY sales_payment_received, '{"payment_id": 78, "customer_id": 5}';
```

Laravel can listen via `pg_notify` PHP extension or Redis pub/sub bridge.

**Why this matters for long-term**: Eliminates **polling overhead** (no more setInterval AJAX calls). Warehouse managers see new invoices **instantly** without 5-30s delay. Stock pipeline cache can be invalidated **precisely** when data changes.

### 7.8 Window Functions — ✅ Implemented (Task 18) Running Balance Reconciliation

Running balance denormalization is the **#1 source of data drift** in accounting systems. Each sub-ledger (customer_ledger, supplier_ledger, employee_ledger, cash_ledger) maintains a `balance` column computed as `prev + debit/credit`. This is efficient for reads but fragile — if any row is inserted out of order, modified, or a reversal doesn't cascade correctly, all subsequent balances become wrong. Window functions provide a mathematically correct verification that catches any drift early, giving the performance of denormalization with the safety of recomputation.

**Strategy**: Keep `balance` column for fast reads, but use PostgreSQL window functions to verify `stored_balance = computed_balance` via materialized views that compute `SUM() OVER (PARTITION BY entity ORDER BY id)`. The `reconcile:running-balance` Artisan command refreshes these views and reports drift with per-entity drill-down.

Schema: `07_views_triggers_constraints.sql` — RUNNING BALANCE RECONCILIATION section. Migration: `2025_01_20_000006_add_running_balance_reconciliation.php`.

#### 1. reconciliation_snapshots Table

A structured audit trail for running balance reconciliation runs. Each run produces one row per ledger type with counts, max drift, and status. This replaces the previous approach of logging reconciliation results as unstructured JSON in `user_audit_log`, enabling SQL-queryable reconciliation history with trend analysis.

```sql
CREATE TABLE reconciliation_snapshots (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_type        varchar(30) NOT NULL DEFAULT 'running_balance',
    ledger_type     varchar(30) NOT NULL,       -- customer|supplier|employee|cash
    entity_id       integer,                     -- null for aggregate runs
    total_rows      integer NOT NULL DEFAULT 0,  -- total non-reversed rows checked
    matched_rows    integer NOT NULL DEFAULT 0,  -- rows where |drift| <= tolerance
    drift_rows      integer NOT NULL DEFAULT 0,  -- rows where |drift| > tolerance
    max_drift       numeric(15,2) DEFAULT 0,     -- worst drift in this run
    max_drift_entity_id integer,
    status          varchar(10) NOT NULL DEFAULT 'green' CHECK (status IN ('green','red','error')),
    tolerance       numeric(15,4) NOT NULL DEFAULT 0.02,
    as_of_date      date,                         -- optional historical cutoff
    details         jsonb,                        -- per-view formula, view name
    ran_at          timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ran_by          integer
);
```

#### 2. Materialized Views (4)

Each materialized view computes the running balance using `SUM() OVER (PARTITION BY entity_id ORDER BY id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)` and compares it against the stored `balance` column. The `drift` column shows `stored_balance - computed_balance`, making it easy to identify both the magnitude and direction of any mismatch.

**customer_ledger** — AR running balance (debit increases, credit decreases):

```sql
CREATE MATERIALIZED VIEW mv_customer_ledger_balance_check AS
SELECT
    id, customer_id, transaction_date, transaction_type, debit, credit,
    balance AS stored_balance,
    SUM(debit - credit) OVER (
        PARTITION BY customer_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(debit - credit) OVER (
        PARTITION BY customer_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM customer_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;
```

**supplier_ledger** — AP running balance (credit increases, debit decreases):

```sql
CREATE MATERIALIZED VIEW mv_supplier_ledger_balance_check AS
SELECT
    id, supplier_id, transaction_date, transaction_type, debit, credit,
    balance AS stored_balance,
    SUM(credit - debit) OVER (
        PARTITION BY supplier_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(credit - debit) OVER (
        PARTITION BY supplier_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM supplier_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;
```

**employee_ledger** — Employee payable running balance (credit increases, debit decreases):

```sql
CREATE MATERIALIZED VIEW mv_employee_ledger_balance_check AS
SELECT
    id, employee_id, transaction_date, transaction_type, debit, credit,
    balance AS stored_balance,
    SUM(credit - debit) OVER (
        PARTITION BY employee_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(credit - debit) OVER (
        PARTITION BY employee_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM employee_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;
```

**cash_ledger** — Cash position running balance (positive = IN, negative = OUT):

```sql
CREATE MATERIALIZED VIEW mv_cash_ledger_balance_check AS
SELECT
    id, branch_id, transaction_date, transaction_type, amount,
    balance AS stored_balance,
    SUM(amount) OVER (
        PARTITION BY branch_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS computed_balance,
    ROUND(balance - SUM(amount) OVER (
        PARTITION BY branch_id ORDER BY id
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ), 2) AS drift
FROM cash_ledger
WHERE COALESCE(is_reversed, false) = false
WITH DATA;
```

Each materialized view has a unique index on `id` (required for `CONCURRENTLY` refresh in future) and can be refreshed via `REFRESH MATERIALIZED VIEW <view_name>`.

#### 3. Artisan Command: `reconcile:running-balance`

File: `laravel/app/Console/Commands/RunningBalanceReconcile.php`

| Option | Description |
|---|---|
| `--fix` | Fix drift by updating `stored_balance = computed_balance` in source tables |
| `--ledger=customer` | Check a single ledger (customer, supplier, employee, cash) |
| `--as-of=2025-01-15` | Only check entries up to this date (historical verification) |
| `--top=10` | Number of top-drift entities to display |

**Workflow**:
1. Refresh all 4 materialized views (`REFRESH MATERIALIZED VIEW`)
2. Count total/matched/drifted rows per view using `COUNT(*) FILTER (WHERE ABS(drift) > tolerance)`
3. If drift detected: show top-N entities by worst drift + top 5 individual rows
4. With `--fix`: update source table `SET balance = computed_balance` for each drifted row, then re-verify
5. Store structured snapshot in `reconciliation_snapshots` table (replaces ad-hoc JSON logging)

**Exit codes**: 0 = all match (green), 1 = drift detected (red).

**Scheduling**: This command should be run daily (e.g., via Laravel Scheduler or cron). It can also be run manually before period close to ensure sub-ledger integrity. Example scheduler entry in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reconcile:running-balance')->dailyAt('02:00')->withoutOverlapping;
```

**Integration with existing reconciliation**: This command complements (not replaces) the existing `subledger:reconcile` (GL vs sub-ledger totals) and `journal:replay-verify` (GL entry-level integrity). The three commands together provide complete financial data integrity verification:
- `reconcile:running-balance` — verifies internal consistency of each sub-ledger's running balance column
- `subledger:reconcile` — verifies sub-ledger totals match GL control accounts
- `journal:replay-verify` — verifies GL journal entries are balanced and correctly linked

### 7.9 CTE (Common Table Expressions) — Complex Sales Queries

Replace multi-query PHP patterns with single CTE queries:

```sql
-- Today's sales summary with payment info (currently 3+ queries)
WITH today_invoices AS (
  SELECT si.*,
    COALESCE(SUM(ipa.allocated_amount), 0) AS paid_total,
    GREATEST(0, si.total_amount - COALESCE(SUM(ipa.allocated_amount), 0)) AS balance_due
  FROM sales_invoices si
  LEFT JOIN invoice_payment_allocations ipa ON ipa.invoice_id = si.id
  LEFT JOIN customer_payments cp ON cp.id = ipa.payment_id AND cp.is_reversed = false
  WHERE si.branch_id = ? AND si.invoice_date = CURRENT_DATE
    AND si.is_reversed = false
  GROUP BY si.id
)
SELECT status,
  COUNT(*) AS invoice_count,
  SUM(total_amount) AS total_revenue,
  SUM(paid_total) AS total_collected,
  SUM(balance_due) AS total_outstanding
FROM today_invoices
GROUP BY status;
```

**Why this matters for long-term**: CTEs reduce **round-trips to DB** (1 query vs 3+), eliminate **race conditions** between sequential reads, and let PostgreSQL's optimizer choose the best execution plan for the entire operation.

### 7.10 Row-Level Security (RLS) — ✅ Implemented (Task 19) Branch Isolation

Row-Level Security provides **database-level branch isolation** that cannot be bypassed even by direct SQL, raw `DB::statement()` calls, or forgotten `BranchScope` in models. This is the ultimate defense-in-depth layer — even if a developer writes `Customer::all()` without BranchScope, or a bug in middleware allows cross-branch access, RLS still prevents data leakage at the PostgreSQL engine level.

**Three-layer defense-in-depth**:

| Layer | Mechanism | Scope | Bypass? |
|---|---|---|---|
| Layer 1 (Query) | `BranchScope` Eloquent global scope | Auto-filters reads for 4 sales models | `withoutGlobalScope()`, raw SQL |
| Layer 2 (Route) | `EnforceBranchIsolation` middleware | Validates branch_id on 14 sales write routes | Admin bypass (logged), missing routes |
| Layer 3 (DB) | RLS policies | All 31 branch-scoped tables, all operations | **None** — PostgreSQL enforces |

Schema: `07_views_triggers_constraints.sql` — ROW-LEVEL SECURITY section. Migration: `2025_01_20_000007_add_rls_branch_isolation.php`. Middleware: `SetAppBranchId.php`.

#### 1. Custom GUC Parameters

The `SetAppBranchId` middleware (registered globally in `bootstrap/app.php`) sets two PostgreSQL session parameters on every authenticated request:

```php
DB::statement("SET app.branch_id = ?", [$branchId]);  // session branch_id
DB::statement("SET app.is_admin = ?", [$isAdmin]);     // 'true' or 'false'
```

These are consumed by RLS policies via `current_setting('app.branch_id')::int` and `current_setting('app.is_admin', true)`. The `missing_ok=true` parameter returns NULL (not error) when the GUC is not set, enabling graceful handling for CLI commands and unauthenticated requests.

**Safe defaults**: Database-level GUC defaults are `app.branch_id=0` and `app.is_admin=false` (set by migration). Direct psql sessions without `SET app.branch_id` will see NO branch data — deny by default.

#### 2. Single branch_id Tables (31 tables)

Each table gets 5 RLS policies: SELECT, INSERT, UPDATE, DELETE + admin bypass. The core condition is:

```sql
current_setting('app.is_admin', true) = 'true'
    OR branch_id = current_setting('app.branch_id')::int
```

This means:
- **Admin users** (`app.is_admin = 'true'`) see all branches (bypass)
- **Non-admin users** (`app.is_admin = 'false'`) see only rows where `branch_id` matches their session branch
- **Unauthenticated/CLI** (GUC not set → NULL) sees no rows (deny by default)

Example for `sales_invoices`:

```sql
ALTER TABLE sales_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales_invoices FORCE ROW LEVEL SECURITY;

CREATE POLICY rls_sales_invoices_select ON sales_invoices
    FOR SELECT USING (
        current_setting('app.is_admin', true) = 'true'
        OR branch_id = current_setting('app.branch_id')::int
    );

CREATE POLICY rls_sales_invoices_insert ON sales_invoices
    FOR INSERT WITH CHECK (
        current_setting('app.is_admin', true) = 'true'
        OR branch_id = current_setting('app.branch_id')::int
    );

CREATE POLICY rls_sales_invoices_update ON sales_invoices
    FOR UPDATE USING (
        current_setting('app.is_admin', true) = 'true'
        OR branch_id = current_setting('app.branch_id')::int
    ) WITH CHECK (
        current_setting('app.is_admin', true) = 'true'
        OR branch_id = current_setting('app.branch_id')::int
    );

CREATE POLICY rls_sales_invoices_delete ON sales_invoices
    FOR DELETE USING (
        current_setting('app.is_admin', true) = 'true'
        OR branch_id = current_setting('app.branch_id')::int
    );

CREATE POLICY rls_sales_invoices_admin ON sales_invoices
    FOR ALL USING (current_setting('app.is_admin', true) = 'true')
    WITH CHECK (current_setting('app.is_admin', true) = 'true');
```

**FORCE ROW LEVEL SECURITY** is critical — without it, the table owner (the PostgreSQL role that Laravel connects as) would bypass all policies. `FORCE` makes even the owner subject to RLS.

**Policy naming convention**: `rls_{table}_{operation}` (e.g., `rls_sales_invoices_select`).

**31 single-branch tables covered**:

| Category | Tables |
|---|---|
| Auth & Master | `employees`, `customers`, `suppliers`, `warehouses` |
| Accounting | `journal_entries`, `document_sequences`, `customer_ledger`, `supplier_ledger`, `employee_ledger`, `branch_cash`, `branch_expenses`, `branch_product_cost`, `cash_ledger`, `accounting_periods`, `manual_journals` |
| Stock | `stock_adjustments`, `stock_take_sessions`, `damage_invoices` |
| Sales | `sales_invoices`, `sales_challans`, `sales_draft_carts`, `sales_returns` |
| Purchase | `purchase_orders`, `purchase_receives`, `purchase_returns` |
| Payment & Misc | `customer_payments`, `supplier_payments`, `other_incomes`, `other_expenses`, `employee_transactions` |

#### 3. Dual branch_id Tables (4 tables — Inter-Branch Operations)

Four tables use `from_branch_id` and `to_branch_id` instead of a single `branch_id`. These model inter-branch operations where both branches need visibility. The policy uses OR logic:

```sql
current_setting('app.is_admin', true) = 'true'
    OR from_branch_id = current_setting('app.branch_id')::int
    OR to_branch_id = current_setting('app.branch_id')::int
```

| Table | Columns | Use Case |
|---|---|---|
| `branch_ledger` | `from_branch_id`, `to_branch_id` | Intercompany settlement tracking |
| `warehouse_transfers` | `from_branch_id`, `to_branch_id` | Stock transfer between branches |
| `money_transfers` | `from_branch_id`, `to_branch_id` | Cash transfer between branches |
| `branch_demands` | `from_branch_id`, `to_branch_id` | Cross-branch stock demand |

#### 4. Tables NOT Covered by RLS

The following tables do NOT have `branch_id` and are therefore not RLS-protected. These are system-wide resources that all branches share:

- `branches` (the branch lookup table itself — needed to resolve branch_id)
- `products`, `product_categories`, `product_groups`, `product_price_history` (shared product catalog)
- `banks`, `bank_ledger_mappings` (shared bank accounts)
- `users`, `menus`, `user_menu_permissions` (auth/ACL system)
- `ledgers` (chart of accounts — shared across branches)
- `journal_lines` (linked to journal_entries which IS RLS-protected)
- `stock_transactions` (linked via reference_type/reference_id; filtered by service layer)
- `warehouse_stock` (filtered by warehouse → branch in service layer)
- `sales_invoice_items`, `sales_challan_items`, `sales_return_items` (child tables — parent is RLS-protected)
- `purchase_order_items`, `purchase_receive_items`, `purchase_return_items` (child tables)
- `invoice_payment_settlements`, `supplier_payment_settlements` (allocation tables)
- `user_audit_log`, `notifications`, `journal_posting_logs` (audit trail — admin-only)
- `sales_draft_carts_items` (child of sales_draft_carts which IS RLS-protected)
- `reconciliation_snapshots` (Task 18 — admin-only reconciliation data)

#### 5. Console/CLI Considerations

The `SetAppBranchId` middleware only runs for HTTP requests. Artisan commands run without `app.branch_id` set, which means RLS denies access to all branch-scoped data (deny by default). This is the correct behavior for most reconciliation/maintenance commands which should operate on all branches.

For CLI commands that need branch-scoped access, explicitly set the GUC before queries:

```php
DB::statement("SET app.branch_id = ?", [$branchId]);
DB::statement("SET app.is_admin = true");  // or false for branch-scoped only
```

Existing commands (`subledger:reconcile`, `journal:replay-verify`, `reconcile:running-balance`) all operate on all branches and should set `app.is_admin = true` at the start to bypass RLS.

### 7.11 Table Partitioning — For Large Tables

As the system grows, these tables will become very large. Range partitioning by date:

```sql
-- Partition sales_invoices by month
CREATE TABLE sales_invoices (
  id BIGINT GENERATED ALWAYS AS IDENTITY,
  invoice_date DATE NOT NULL,
  -- ... other columns ...
  PRIMARY KEY (id, invoice_date)
) PARTITION BY RANGE (invoice_date);

CREATE TABLE sales_invoices_2025_01 PARTITION OF sales_invoices
  FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');
CREATE TABLE sales_invoices_2025_02 PARTITION OF sales_invoices
  FOR VALUES FROM ('2025-02-01') TO ('2025-03-01');
-- ... auto-create future partitions via pg_partman ...
```

**Candidates for partitioning:**
- `sales_invoices` (by invoice_date, monthly)
- `stock_transactions` (by transaction_date, monthly)
- `customer_ledger` (by transaction_date, quarterly)
- `journal_entry_lines` (by created_at, quarterly)

**Why this matters for long-term**: Partitioning provides:
- **Faster queries** — PostgreSQL scans only relevant partitions
- **Faster maintenance** — VACUUM, REINDEX operate per-partition
- **Easy archiving** — Detach old partitions instead of DELETE
- **Parallel query** — Each partition can be scanned in parallel

### 7.12 EXCLUDE Constraints — Prevent Overlapping Allocations

```sql
-- Prevent double-allocation of the same invoice amount
ALTER TABLE invoice_payment_allocations
  ADD CONSTRAINT no_overallocation
  EXCLUDE USING gist (
    invoice_id WITH =,
    numrange(0, allocated_amount, '[]') WITH &&
  );
```

**Why this matters for long-term**: Application-level validation can be bypassed by race conditions. EXCLUDE constraints enforce **mathematical invariants** at the DB level.

### 7.13 Deferred Constraints — For Multi-Table Atomic Operations

Currently, some operations must INSERT rows in specific order due to FK constraints. Deferring constraints allows any order within a transaction:

```sql
ALTER TABLE sales_invoice_items
  ALTER CONSTRAINT sales_invoice_items_invoice_id_foreign
  DEFERRABLE INITIALLY DEFERRED;
```

**Why this matters for long-term**: Deferred constraints let PostgreSQL validate all FKs at COMMIT time rather than at each INSERT. This simplifies service code (no need to worry about insertion order) and enables batch operations.

### 7.14 pg_cron — ✅ Implemented (Task 21)

pg_cron replaces the Laravel scheduler for critical DB-level maintenance jobs, ensuring they run even if the Laravel queue worker, supervisor, or app server crashes. The migration (`2025_01_20_000009`) enables the extension, creates 3 SQL functions, schedules 5 cron jobs, and adds a monitoring view.

**Scheduled jobs:**

| Job Name | Schedule | Command | Purpose |
|----------|----------|---------|---------|
| `cancel-stale-drafts` | Daily 02:00 | `SELECT cancel_stale_sales_drafts(14, 200, NULL)` | Cancel draft invoices >14 days old with no godown/challan |
| `refresh-report-views` | Every 5 min | `SELECT refresh_all_report_views()` | Refresh 7 financial report MVs (AR aging, AP aging, stock valuation, etc.) |
| `refresh-rb-checks` | Hourly | `REFRESH CONCURRENTLY mv_*_balance_check` (4 MVs) | Refresh 4 running-balance verification MVs for drift detection |
| `purge-old-notifications` | Daily 03:00 | `SELECT purge_old_notifications(90)` | Delete read notifications older than 90 days (prevents table bloat) |
| `analyze-high-write-tables` | Daily 04:00 | `SELECT vacuum_analyze_high_write_tables()` | ANALYZE 17 highest-write tables for accurate query planner stats |

**SQL functions created:**

| Function | Parameters | Returns | Purpose |
|----------|-----------|---------|---------|
| `cancel_stale_sales_drafts(days, max, branch)` | days=14, max=200, branch=NULL | TABLE(cancelled, skipped, errors, details) | Pure-SQL stale draft cleanup (mirrors Artisan command, used by pg_cron) |
| `purge_old_notifications(days)` | days=90 | integer (deleted count) | Delete read notifications older than N days |
| `vacuum_analyze_high_write_tables()` | none | void | ANALYZE 17 high-write tables (sales_invoices, customer_ledger, stock_transactions, etc.) |

**Monitoring:** `SELECT * FROM v_pg_cron_jobs` shows all scheduled jobs with their last run status, duration, and return message.

**Graceful fallback:** If pg_cron extension is not available (e.g., managed hosting without `shared_preload_libraries` access), the migration logs a warning and skips silently. The Laravel scheduler in `routes/console.php` continues to handle the same jobs as fallback:
- `sales:cancel-stale-drafts` — daily at 02:00
- `reports:refresh` — every 5 minutes

**Why this matters for long-term**: pg_cron runs **inside the database** — no dependency on Laravel scheduler, queue workers, or supervisor. Even if the app server crashes, DB-level maintenance continues. The dual-scheduler approach (pg_cron primary + Laravel fallback) provides maximum reliability.

### 7.15 Full-Text Search — ✅ Implemented (Task 17)

PostgreSQL full-text search replaces all `LIKE '%term%'` / `ILIKE '%term%'` pattern-matching queries on the `products` and `customers` tables with index-accelerated `tsvector @@ plainto_tsquery` lookups. This is one of the most impactful performance optimizations in the ERP because product and customer search are the highest-frequency AJAX operations — every sales order creation triggers `search_product()` and `search_customer()` on every keystroke.

**Why full-text search is superior to LIKE:**

1. **Index-accelerated**: GIN indexes enable sub-millisecond lookups on millions of rows, vs. full sequential scan with `LIKE '%term%'` (leading wildcard prevents B-tree usage).
2. **Ranking**: `ts_rank()` returns best matches first, so "cement" matches "Cement Rod 12mm" before "Steel Rod with Cement Base".
3. **Weighted columns**: Product name outranks product code; customer name outranks code which outranks phone/address. This matches user expectation — you type a name, you get the name match first.
4. **Scalability**: As product catalog grows from hundreds to tens of thousands, LIKE performance degrades linearly while full-text search stays constant-time via the GIN index.

**Dictionary choice: `simple` vs `english`**

We use the `'simple'` text search configuration instead of `'english'` because:

- Product codes like "PRD-001" must match exactly — English stemming would destroy them
- Customer names are often Bengali transliterations — no English stemming rules apply
- Phone numbers must not be "stemmed" — "01711" should not become "0171"
- The `'simple'` dictionary just lowercases and splits on whitespace, which is exactly what we need for identifier-heavy search

If English-language product descriptions are added in the future, a separate `'english'` tsvector column can be added alongside for description-based search.

Migration: `2025_01_20_000005_add_fulltext_search_products_customers.php`
Schema: `07_views_triggers_constraints.sql` — FULL-TEXT SEARCH section added.

#### Products: search_vector (2 columns, 2 weights)

```sql
-- GENERATED tsvector column — auto-maintained on every INSERT/UPDATE
ALTER TABLE products ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(product_name, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(product_code, '')), 'B')
    ) STORED;

-- GIN index for fast @@ lookups
CREATE INDEX idx_products_search ON products USING GIN (search_vector);
```

**Weight rationale**: `product_name` gets weight A (highest) because users typically search by name. `product_code` gets weight B because code lookups are secondary. This means searching for "cement" returns "Cement Rod" (name match) ranked higher than "CEM-001" (code match).

**Example queries:**

```sql
-- Basic search (plain text, no special syntax)
SELECT *, ts_rank(search_vector, plainto_tsquery('simple', 'cement')) AS rank
FROM products
WHERE search_vector @@ plainto_tsquery('simple', 'cement')
ORDER BY rank DESC LIMIT 30;

-- Search with additional filter (active only)
SELECT *, ts_rank(search_vector, plainto_tsquery('simple', 'rod')) AS rank
FROM products
WHERE search_vector @@ plainto_tsquery('simple', 'rod')
  AND is_active = true AND deleted_at IS NULL
ORDER BY rank DESC LIMIT 30;
```

#### Customers: search_vector (5 columns, 4 weights)

```sql
-- GENERATED tsvector column — auto-maintained on every INSERT/UPDATE
ALTER TABLE customers ADD COLUMN search_vector tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(customer_name, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(customer_code, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(phone, '')), 'C') ||
        setweight(to_tsvector('simple', coalesce(mobile, '')), 'C') ||
        setweight(to_tsvector('simple', coalesce(address, '')), 'D')
    ) STORED;

-- GIN index for fast @@ lookups
CREATE INDEX idx_customers_search ON customers USING GIN (search_vector);
```

**Weight rationale**: `customer_name` gets weight A (primary match — users search by name/shop). `customer_code` gets weight B (secondary — code lookups). `phone` and `mobile` share weight C (tertiary — phone number search, useful for POS customer identification). `address` gets weight D (lowest — keyword match for area-based customer lookup).

**Note on `shop_name`**: The legacy MySQL schema had a `shop_name` column, but the PostgreSQL migration consolidated it into `customer_name`. All legacy queries that searched `shop_name` now search `customer_name` at weight A.

**Example queries:**

```sql
-- Search by name fragment
SELECT *, ts_rank(search_vector, plainto_tsquery('simple', 'rahman')) AS rank
FROM customers
WHERE search_vector @@ plainto_tsquery('simple', 'rahman')
ORDER BY rank DESC LIMIT 30;

-- Search by phone number (partial match)
SELECT *, ts_rank(search_vector, plainto_tsquery('simple', '01711')) AS rank
FROM customers
WHERE search_vector @@ plainto_tsquery('simple', '01711')
  AND is_active = true AND deleted_at IS NULL
ORDER BY rank DESC LIMIT 30;
```

#### Laravel Integration: Model Scopes + Controller Refactoring

**Product and Customer models** each have a `scopeSearch()` method that automatically uses full-text search when the `search_vector` column exists, and falls back to ILIKE when it doesn't (e.g., before migration is run):

```php
// Product::search('cement') → uses tsvector @@ plainto_tsquery when available
// Falls back to ILIKE on product_name, product_code when search_vector doesn't exist
$products = Product::search('cement')->get();

// Customer::search('rahman') → ranked results (name matches first)
$customers = Customer::search('rahman', ranked: true)->limit(30)->get();
```

**BaseMasterDataController** was refactored to use `scopeSearch()` for DataTables and CSV export when `$useFullTextSearch = true` is set on the controller. Both `ProductController` and `CustomerController` enable this flag.

**ArchiveService** was refactored to use `Customer::search()` instead of three separate ILIKE clauses.

**Controllers changed:**

| Controller | Change | Before | After |
|---|---|---|---|
| `ProductController` | `$useFullTextSearch = true` | ILIKE on 2 fields | `scopeSearch()` with tsvector |
| `CustomerController` | `$useFullTextSearch = true` | ILIKE on 4 fields | `scopeSearch()` with tsvector |
| `BaseMasterDataController` | `dataTablesResponse()` + `applyExportSearch()` | ILIKE loop | Full-text first, ILIKE fallback |
| `ArchiveService` | `searchCustomers()` | 3× ILIKE | `Customer::search()` |

**Backward compatibility**: The `scopeSearch()` method checks `information_schema.columns` for `search_vector` existence. If the migration hasn't been run yet, the search gracefully falls back to ILIKE. This means the code can be deployed before the migration runs without breaking anything.

---

## 8. Implementation Priority & Phase Plan

### Phase 1A: Critical Bug Fixes (Week 1)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 1 | Fix SubLedgerService integration — Sales services should use SubLedgerService instead of inline customer_ledger writes | ✅ DONE | Business Logic | 2025-01-18 — All 8 inline writes replaced with SubLedgerService::postCustomerLedgerEntry() |
| 2 | Fix JournalReversalService integration — Sales services should delegate reversals to JournalReversalService::reverseByJournalEntry() | ✅ DONE | Business Logic | 2025-01-18 — All 10 inline reversal calls replaced with JournalReversalService::reverseByJournalEntry() cascade; 4 direct supplier_ledger inserts replaced with SubLedgerService calls; 4 dead private methods removed (-160 lines) |
| 3 | Fix journal_entry_id linking in customer_ledger — Ensure every customer_ledger row has journal_entry_id | ✅ DONE | Business Logic | 2025-01-18 — Fixed as part of SubLedgerService integration (G-1); also added DEFAULT false migration for is_reversed |
| 4 | Fix SQL injection in ReconciliationService — Parameterize all date filters | ✅ DONE | Security | 2025-01-18 — All 16 interpolated date filters replaced with parameterized ? placeholders; added validateDate() defense-in-depth |
| 5 | Remove dead CustomerPaymentSettlement model | ✅ DONE | Cleanup | 2025-01-20 — Model file removed; table already dropped by P1-4 migration; replaced by InvoicePaymentAllocation |

### Phase 1B: Missing Business Logic (Week 2-3)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 6 | ~~Implement invoice dispatchers assignment (UI + service)~~ | ✅ DONE | Business Logic + UI | 2025-01-20 — Full implementation: dispatchers() belongsToMany, assignDispatchers() with branch/role validation, Select2 multi-select in cart+edit, badge display in show+print, AJAX endpoints + routes |
| 7 | ~~Implement multi-invoice payment allocation~~ | ✅ DONE | Business Logic + UI | 2025-01-20 — confirmPayment() now accepts allocations[] array; controller reads parallel arrays from UI; single-invoice legacy code removed |
| 8 | ~~Implement payment transaction types (discount, write_off, refund) with GL~~ | ✅ DONE | Business Logic + UI | 2025-01-20 — Full implementation: type-specific GL posting (4 types with correct Dr/Cr), type-specific customer_ledger entries, type-specific payment code prefixes (PAY/DISC/WOFF/RFND), type-specific audit events (payment_discount/payment_write_off/payment_refund), dynamic create form with type selector + color-coded hero + GL info, show view with type badge + gradient, index with type filter + 7 stats cards, print receipt with type label |
| 9 | ~~Implement discount_amount GL posting on payment confirm~~ | ✅ DONE | Business Logic | 2025-01-20 — Included in Task #8: receive type posts Dr Sales Discount / Cr AR for discount_amount; discount type posts entire amount as Dr Sales Discount / Cr AR |
| 10 | ~~Implement Call It A Day batch operation~~ | ✅ Done | 1 day | Business Logic |
| 11 | ~~Implement Customer 360 hub view~~ | ✅ DONE | UI | 2025-01-20 — Full 360° hub: 7 KPI cards, 5-tab interface (Overview/Ledger/Invoices/Payments/Returns), server-side DataTables AJAX, Customer model reverse relationships, date/status/type filters, New Payment shortcut |

### Phase 1C: PostgreSQL Power Features (Week 3-4)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 12 | ~~Add GENERATED columns for due_amount, issue_cost, cogs_amount, stock_value~~ | ✅ DONE | Database | 2025-01-20 — 3 GENERATED columns added: due_amount (sales_invoices = total_amount - paid_amount), cogs_amount (sales_challan_items = qty × issue_rate), stock_value (warehouse_stock = qty × avg_cost, new column). issue_cost on sales_challans CANNOT be GENERATED (PostgreSQL forbids subqueries in GENERATED expressions — cross-table aggregate). All 6 due_amount manual-write sites refactored to only update paid_amount/total_amount; 4 cogs_amount insert sites cleaned; 4 stock_value DB::raw() queries simplified to use column. Models updated: due_amount & cogs_amount removed from $fillable, stock_value cast added. Partial index on stock_value for non-zero rows. |
| 13 | ~~Add partial indexes (open invoices, unpaid, pending returns, active ledger)~~ | ✅ Done | 1 day | Database |
| 14 | ~~Add covering indexes (INCLUDE) for high-frequency queries~~ | ✅ Done | 1 day | Database |
| 15 | ~~Add BRIN indexes for time-series tables~~ | ✅ Done | 0.5 day | Database |
| 16 | ~~Add GIN index on sales_draft_carts.items_json~~ | ✅ Done | 0.5 day | Database |
| 17 | ~~Implement full-text search for products + customers (tsvector + GIN)~~ | ✅ Done | 2 days | Database + Business Logic |
| 18 | ~~Add window-function running balance reconciliation job~~ | ✅ Done | 2 days | Database + Business Logic |
| 19 | ~~Implement Row-Level Security (RLS) for branch isolation~~ | ✅ Done | 2 days | Database + Business Logic |
| 20 | ~~Replace document_sequences SELECT FOR UPDATE with advisory locks~~ | ✅ Done | 1 day | Business Logic |
| 21 | ~~Add pg_cron for stale draft cleanup + materialized view refresh~~ | ✅ Done | Database |

### Phase 1D: Notifications & Reports (Week 4-5)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 22 | ~~Implement Telegram notifications (5 event types)~~ | ✅ Replaced by Laravel Notification system | Business Logic |
| 23 | ~~Implement FCM push notifications~~ | ✅ Replaced by Laravel Notification system | Business Logic |
| 24 | ~~Implement Revenue Overview dashboard (Chart.js KPIs)~~ | ✅ Done | UI |
| 25 | ~~Implement Sales Funnel/Pipeline dashboard~~ | ✅ Done | UI |
| 26 | Implement Customer Performance dashboard | Medium | 3 days | UI |
| 27 | Implement blank godown copy print template | Low | 1 day | UI |
| 28 | Implement CSV export for invoices + challans | Low | 1 day | Business Logic |
| 29 | Implement Sales guideline page (Bengali/English) | Low | 2 days | UI |
| 30 | Implement Go-live checklist | Low | 1 day | UI |

### Phase 1E: Advanced PostgreSQL (Week 5-6)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 31 | Implement LISTEN/NOTIFY for real-time updates | Medium | 3 days | Database + Business Logic |
| 32 | Implement CTE-based complex queries (today's summary, AR aging) | Medium | 2 days | Business Logic |
| 33 | Add EXCLUDE constraint for invoice_payment_allocations | Low | 1 day | Database |
| 34 | Set up table partitioning for sales_invoices + stock_transactions | Low | 2 days | Database |
| 35 | Configure deferred FK constraints | Low | 1 day | Database |
| 36 | Implement Sales API write endpoints (mobile) | Medium | 5 days | Business Logic + API |
| 37 | Implement salesman commission tracking | Low | 3 days | Business Logic |

### UI Guidelines (All Phases)

**Rule: Same as legacy — no UI/UX changes needed in new Laravel**

This means:
- Keep Bengali/English bilingual labels on all print documents
- Keep SVG branch headers with branch-specific colors (Red, Blue, Green, Orange)
- Keep multi-page print pagination (17 items per page)
- Keep POS-style cart layout (customer search, product search, price slider)
- Keep 3-step godown/challan wizard visual
- Keep 2-step return workflow (create → confirm)
- Keep Bootstrap modals for payment collection
- Keep DataTables server-side processing
- Keep Chart.js for dashboards
- Keep A4 print format for all documents

### Database Guidelines (All Phases)

**Rule: Legacy MySQL → PostgreSQL — maximize PostgreSQL power**

Core principles:
1. **GENERATED columns** over application-maintained denormalization
2. **Partial indexes** over full indexes for filtered queries
3. **BRIN indexes** over B-tree for append-only time-series
4. **Covering indexes** (INCLUDE) for index-only scans
5. **Window functions** for running balance verification
6. **RLS** for defense-in-depth branch isolation
7. **Advisory locks** for atomic code generation
8. **Full-text search** (tsvector + GIN) over LIKE for search
9. **Materialized views** for pre-computed reporting
10. **pg_cron** for DB-level scheduled maintenance
11. **LISTEN/NOTIFY** for real-time cache invalidation
12. **CTE** for complex multi-table queries
13. **EXCLUDE constraints** for mathematical invariants
14. **Partitioning** for large table management (future)

---

## Appendix A: Legacy vs Laravel Feature Matrix

| Feature | Legacy CodeIgniter | Laravel Status | Gap Level |
|---------|-------------------|----------------|-----------|
| POS invoice creation | ✅ Full (create.php) | ✅ Full (cart.blade.php) | None |
| Multi-tab cart | ✅ Full (session + DB drafts) | ✅ Full (JSONB drafts) | None |
| Barcode scanning | ✅ product_by_code | ✅ Via product search | Minor UI |
| Price range slider | ✅ Visual min/default/max | ✅ Price range validation | Minor UI (no visual slider?) |
| Credit limit check | ✅ Full with override | ✅ Full with override | None |
| Invoice finalize | ✅ 9-step atomic | ✅ 9-step atomic | None |
| Invoice edit (draft) | ✅ Full | ✅ Full | None |
| Invoice cancel | ✅ Full (delete + GL reversal) | ✅ Full (cancel + GL reversal) | None |
| Invoice print | ✅ A4 multi-page Bengali | ✅ A4 multi-page | None |
| Godown prep | ✅ Full | ✅ Full | None |
| Godown copy print | ✅ Bengali picking list | ✅ Picking list | None |
| Blank godown copy | ✅ Handwrite template | ✅ print_godown.blade.php has blank Picked Qty + Signature columns | None |
| Challan finalize | ✅ Full (stock OUT + COGS) | ✅ Full (stock OUT + COGS) | None |
| Challan print | ✅ Bengali delivery note | ✅ Delivery note | None |
| Challan reversal | ✅ Full (stock + GL restore) | ✅ Full | None |
| Transport adjustment | ✅ Full (snapshot + delta) | ✅ Full | None |
| Payment receive (modal) | ✅ Cash/Bank, partial/full | ✅ Full page + allocation | None |
| Payment reversal | ✅ Full (GL + intercompany) | ✅ Full | None |
| Payment receipt print | ✅ Full | ✅ Full | None |
| Multi-invoice allocation | ✅ CustomerTransactionController | ✅ allocations[] array in confirmPayment() | None |
| Payment types (discount/write-off) | ✅ transaction_type column + service | ✅ 4 types with GL (receive/discount/write_off/payment) | None |
| Invoice dispatchers | ✅ Assigned in godown flow | ✅ Assigned in finalize/edit flow (UI + service) | None |
| Sales return create | ✅ Two-phase | ✅ Two-phase | None |
| Return warehouse confirm | ✅ Good/Damage condition | ✅ Good/Damage + damage linkage | None |
| Return reversal | ✅ Full (stock + GL + damage) | ✅ Full | None |
| Return slip print | ✅ Bengali | ✅ Full | None |
| Damage write-off | ✅ Manual + auto from return | ✅ Manual + auto from return | None |
| Customer CRUD | ✅ Full with deactivation safety | ✅ Full with deactivation safety | None |
| Customer 360 hub | ✅ Summary + ledger + invoices + payments | ✅ Full 360° hub (7 KPIs, 5 tabs, DataTables AJAX, filters) | None |
| Customer ledger | ✅ Running balance SSOT | ✅ Running balance SSOT | None |
| Credit limit enforcement | ✅ Full | ✅ Full | None |
| Stock availability (pipeline) | ✅ physical - pipeline | ✅ physical - pipeline + Redis cache | Better |
| Moving average cost | ✅ Weighted average | ✅ Weighted average | None |
| GL journal integration | ✅ Full double-entry | ✅ Full double-entry | None |
| GL reconciliation | ✅ 6-section tie-out | ✅ 6-section tie-out | None |
| Branch isolation | ✅ Middleware + session | ✅ Middleware + BranchScope + RLS opportunity | Better |
| RBAC | ✅ requireRouteAccess | ✅ role:middleware + pen-test | Better |
| Audit trail | ✅ UserAudit + file dual-write | ✅ SalesAuditLogger + file dual-write | None |
| Stale draft cleanup | ✅ Full (throttled, batched) | ✅ Full (scheduled, dry-run) | None |
| Call It A Day | ✅ Batch operation | ✅ Batch operation (SalesInvoiceService::callItADay) | None |
| Telegram notifications | ✅ 5 event types | ✅ Laravel Notifiable + ERPNotification (database + broadcast channels) | Equal |
| FCM notifications | ✅ Push to warehouse managers | ✅ Laravel broadcast channel (Reverb WebSocket) | Better |
| Revenue Overview dashboard | ✅ Chart.js KPIs + filters | ✅ 6 KPIs + 4 Chart.js charts + AJAX trend refresh | Better |
| Sales Funnel dashboard | ✅ Pipeline stages, win rate | ✅ 6 KPIs + 4 Chart.js charts + forecast + salesman leaderboard | Better |
| Customer Performance | ✅ CLV, churn, segments | ⚠️ Basic report only | MEDIUM |
| Sales API (mobile) | ❌ No API | ⚠️ Read-only (dashboard + lookups) | MEDIUM |
| Sales guideline page | ✅ Bengali/English | ❌ Not implemented | LOW |
| Go-live checklist | ✅ Manager sign-off | ❌ Not implemented | LOW |
| CSV export | ✅ Invoices + challans | ❌ Not implemented | LOW |
| Salesman commission | ❌ Not in legacy | ❌ Not implemented | LOW |

---

> **Last Verified**: 2025-07-20 — All gap items manually checked against Laravel codebase by running targeted searches on service files, controllers, models, and views. Revenue Overview + Sales Funnel dashboards verified. Telegram/FCM removal verified.

## Appendix B: Key Formulas Reference

| Formula | Expression | Used In |
|---------|-----------|---------|
| Invoice total | `subtotal + transport_cost - discount_amount` | SalesInvoiceService |
| Moving average cost (IN) | `(old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)` | StockService |
| Moving average cost (OUT) | `unchanged` | StockService |
| COGS per line | `qty × avg_cost` at challan time | SalesChallanService |
| Stock availability | `GREATEST(0, physical_qty - pipeline_qty)` | StockAvailabilityService |
| Pipeline qty | `SUM(ordered_qty - dispatched_qty)` for open invoices | StockAvailabilityService |
| Invoice balance due | `GREATEST(0, total_amount - paid_amount)` | SalesInvoiceService |
| Customer running balance | `previous_balance + debit - credit` | SubLedgerService |
| Credit limit check | `(credit_limit > 0) AND (current_due + new_amount > credit_limit + 0.01)` | SalesInvoiceService |
| GL journal balance | `SUM(debit) = SUM(credit)` within ±0.01 tolerance | JournalPostingService |
| AR reconciliation | `customer_ledger net balance = GL AR control net` | ReconciliationService |
| Inventory valuation | `SUM(warehouse_stock.qty × warehouse_stock.avg_cost)` | ReconciliationService |
| Return COGS reversal | `qty × original_cost` (not current avg_cost) | SalesReturnService |
| Transport adjustment delta | `challan_transport - invoice_transport` | SalesChallanService |

## Appendix C: GL Account Mapping (Nature-Based)

| Nature | Normal Balance | Used By |
|--------|---------------|---------|
| `cash_bank` | Debit | Payment receive, money transfers |
| `ar` (customer_receivable) | Debit | Invoice finalize, return, payment |
| `ap` (supplier_payable) | Credit | Purchase receive, supplier payment |
| `inventory` | Debit | Challan COGS, return, damage, stock take |
| `cogs` | Debit | Challan finalize |
| `sales_revenue` | Credit | Invoice finalize, return reversal |
| `sales_return` | Debit | Return confirm (contra-revenue) |
| `sales_discount` | Debit | Discount on payment (contra-revenue) — ✅ Now posted by CustomerPaymentService for receive+discount_amount and discount transaction types |
| `transport_revenue` | Credit | Transport adjustment at challan |
| `inventory_shrinkage` | Debit | Damage, stock take loss |
| `inventory_surplus` | Credit | Stock take gain |
| `damage_loss` | Debit | Damage write-off (falls back to shrinkage) |
| `write_off` | Debit | Bad debt write-off — ✅ New nature, posted by CustomerPaymentService for write_off transaction type |
| `employee_payable` | Credit | Employee transactions |
| `interbranch_receivable` | Debit | Cross-branch demand fulfillment |
| `interbranch_payable` | Credit | Cross-branch demand settlement |
| `other_income` | Credit | Other income entries |
| `operating_expense` | Debit | Operating expense entries |
| `salary_expense` | Debit | Salary entries |
| `finance_cost` | Debit | Finance charges, write-offs |
| `retained_earnings` | Credit | Year-end close |

---

> **End of Sales Module Documentation**  
> This document should be used as the reference for all Phase 1 sales module implementation.  
> Refer to the gap analysis (Section 6) for prioritization and the PostgreSQL plan (Section 7) for database optimization strategy.
