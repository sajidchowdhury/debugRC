# RC-ERP Sales Module — Complete Documentation & Gap Analysis

> **Document Version**: 1.1 — Updated with verified implementation status  
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

**Supporting controllers**: `ReconciliationController` (GL tie-out), `DashboardController` (summary stats)

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
| 11 | `SalesNotificationService` | `services/Notification/` (101 lines) | FCM push notifications to warehouse managers |
| 12 | `SalesTelegramNotifier` | `services/Notification/` (436 lines) | Business-event Telegram alerts |
| 13 | `FcmTokenService` | `services/Notification/` (49 lines) | Firebase token upsert |

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
- `RevenueOverview.php` — Executive KPI dashboard (Chart.js)
- `SalesFunnelPipeline.php` — Pipeline/funnel dashboard
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

**SalesController (16):** search_customer, search_product, product_by_code, get_branch, product_stock_at_branch, get_warehouse_stock, get_employees, customer_details, add_to_cart, load_cart, validate_cart, hydrate_edit_cart, list_draft_carts, clear_tab_cart, delete_tab_cart, delete_from_cart, update_cart_item, save_fcm_token, final_sales, update, today_filter_summary, cancel_stale_drafts, datatable_invoices, call_it_a_day, delete_invoice, get_invoice_for_edit, save_payment, reverse_payment

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
   - c. Generate invoice code (e.g., `SI-20250120-001`) via `document_sequences SELECT FOR UPDATE`
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
   - b. Generate challan code via `document_sequences SELECT FOR UPDATE`
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
   - c. Generate payment_code via `document_sequences`
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
| 3 | `sales_invoice_dispatchers` | Legacy | 04_sales.sql | ✅ Active (no UI code) |
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
| P2-5 | `customer_payments.transaction_type` | Payment/discount/write_off types missing |

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

### 5.1 Controllers (7)

| # | Controller | Key Methods | Delegates To |
|---|-----------|-------------|-------------|
| 1 | `SalesCartController` | index, load, add, update, remove, clear, validateCart, softHold, checkAvailability | SalesCartService + StockAvailabilityService |
| 2 | `SalesInvoiceController` | index, finalize, edit, update, cancel, cancelStaleDrafts, auditTrail, printInvoice, printGodown, show, getCartData, checkCreditLimit | SalesInvoiceService |
| 3 | `SalesChallanController` | index, godown, storeGodown, challanForm, issueChallan, show, cancel, printChallan | SalesChallanService |
| 4 | `CustomerPaymentController` | index, create, store, show, cancel, printReceipt, getOutstandingInvoices | CustomerPaymentService |
| 5 | `SalesReturnController` | index, create, store, show, confirm, reverse, printSlip, getInvoiceDetails | SalesReturnService |
| 6 | `CustomerController` | CRUD (extends BaseMasterDataController) | CodeGenerator, Customer model |
| 7 | `SalesGuideController` | guide (Bengali/English guideline page) | None (static Blade view) |

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

### 5.4 Blade Views (21 views — ALL FULLY IMPLEMENTED)

| View | Lines | Purpose |
|------|-------|---------|
| sales/guide.blade.php | 290 | Bengali/English sales guideline (search + sticky nav) |
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

### 5.5 Routes (5 groups)

| Group | Prefix | Middleware | Key Routes |
|-------|--------|-----------|------------|
| Cart | admin/sales | role:salesman,manager,admin + branch.isolation | cart, load, add, update, remove, clear, validate, soft-hold, availability, finalize, cart-data, credit-check, cancel-stale-drafts, audit, **guide** |
| Invoices | admin/sales-invoices | role-based per route | index, show, edit, update, cancel, print-invoice, print-godown |
| Challans | admin/sales-challans | role:warehouse_manager,dispatcher,manager,admin | index, show, godown, storeGodown, challan-form, issueChallan, cancel, print-challan |
| Payments | admin/customer-payments | role:salesman,accountant,manager,admin | index, create, store, show, cancel, print-receipt, outstanding-invoices |
| Returns | admin/sales-returns | role:varies per action | index, create, store, show, confirm, reverse, print-slip, invoice-details |

### 5.6 Artisan Commands

| Command | Schedule | Purpose |
|---------|----------|---------|
| `sales:cancel-stale-drafts` | Daily 02:00 | Cancel drafts >14 days (configurable, dry-run, max 200/run) |
| `sales:pen-test` | On-demand | 5 automated security tests (RBAC, BranchScope, excluded roles, mass assignment) |
| `reports:refresh` | Every 5 min | Refresh materialized views |

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
| G-1 | **Sales services bypass SubLedgerService** | Inline customer_ledger writes | Same inline pattern, SubLedgerService exists but unused | 🔴 VERIFIED — 14 raw `customer_ledger` inserts in SalesInvoiceService, zero SubLedgerService refs | Single point of control missing; journal_entry_id not consistently linked |
| G-2 | **Sales services bypass JournalReversalService** | Manual GL + sub-ledger reversal inline | Same inline pattern | 🔴 VERIFIED — JournalReversalService exists but sales services reverse GL+ledger manually | Duplicated reversal logic that can drift |
| G-3 | **journal_entry_id not always linked in customer_ledger** | Some paths link, some don't | SalesInvoiceService does NOT pass journal_entry_id to customer_ledger debit | 🔴 VERIFIED — CustomerPaymentService DOES pass it; SalesInvoiceService DOES NOT | Breaks reconciliation chain |
| G-4 | **SQL injection in ReconciliationService** | Parameterized in legacy | String-interpolated date filters (`$asOfDate`) | 🔴 VERIFIED — Lines 105, 163, 220 use raw interpolation in `DB::select()` | Security vulnerability |

### 6.2 HIGH Priority Gaps (Should Fix)

| # | Gap | Legacy Has | Laravel Status | Verified? | Impact |
|---|-----|-----------|---------------|-----------|--------|
| G-5 | **Invoice dispatchers not assigned** | `sales_invoice_dispatchers` populated in godown/challan flow | Table exists but NO UI or service code for assignment | ❌ VERIFIED — SalesChallanService/Controller have zero dispatcher refs; only deletion on edit exists | Dispatcher feature broken |
| G-6 | **Multi-payment allocation** | One payment can be allocated to multiple invoices | `confirmPayment()` accepts single invoiceId only | ⚠️ VERIFIED — Service has `?int $invoiceId` param, no loop for multi-allocation | Cannot split payment across invoices |
| G-7 | **Payment transaction types** | receive/payment/discount/write_off | Column exists (CHECK constraint) but only 'receive' is set by service | ⚠️ VERIFIED — `transaction_type` column added by P2-5 but service hardcodes 'receive' | Discount, write-off, refund features missing |
| G-8 | **Discount GL posting on payment** | `postCustomerDiscount()` in JournalPostingService | discount_amount field exists but no GL posted on confirm | ❌ VERIFIED — No discount GL in CustomerPaymentService::confirmPayment() | Discounts not reflected in GL |
| G-9 | **Salesman commission tracking** | salesman_id tracked on invoices | No commission calculation service | ❌ Not implemented | Commission reports not possible |
| G-10 | **Call It A Day** batch operation | `callItADay()` in SalesInvoiceOperationsTrait | Not implemented in Laravel service | ❌ VERIFIED — Zero matches for callItADay/call_it_a_day in Laravel codebase | End-of-day workflow missing |
| G-11 | **Customer 360 hub** | `CustomerController::show()` with summary, ledger, invoices, payments | Basic show view (name, contact, credit limit) — NO ledger/invoice/payment tabs | ⚠️ VERIFIED — customers/show.blade.php (197 lines) is a static detail page; comment says "placeholder for future customer-ledger widget" | No unified customer view |

### 6.3 MEDIUM Priority Gaps (Nice to Have)

| # | Gap | Legacy Has | Laravel Status | Verified? |
|---|-----|-----------|---------------|-----------|
| G-12 | **Sales API write endpoints** | Full AJAX CRUD | Only read-only dashboard + lookups | ❌ Not implemented |
| G-13 | **Telegram/FCM notifications** | Full notification suite (5 event types) | Schema + UI placeholders exist (fcm_tokens table, telegram_user_id in user CRUD) but NO dispatch code | ⚠️ VERIFIED — No Telegram bot API calls, no FCM push service |
| G-14 | **Sales guideline page** | Bengali/English user guide | ✅ IMPLEMENTED — `SalesGuideController::guide()`, `admin/sales/guide.blade.php`, route `admin/sales/guide` (all sales-module roles) | ✅ VERIFIED — Blade view migrated from legacy guide.php with Bengali/English content, search filter, sticky nav; reuses existing sales-guide.css + sales-guide.js |
| G-15 | **Go-live checklist** | Manager sign-off checklist | Not implemented | ❌ Not implemented |
| G-16 | **Revenue Overview dashboard** | Chart.js KPI dashboard with filters | Basic report view only | ⚠️ Partial |
| G-17 | **Sales Funnel/Pipeline dashboard** | Pipeline stages, win rate, velocity | Not implemented | ❌ Not implemented |
| G-18 | **Customer Performance dashboard** | CLV, churn risk, segment breakdown | Basic report view only | ⚠️ Partial |
| G-19 | **Blank godown copy print** | Handwrite template with blank cells | ✅ IMPLEMENTED — print_godown.blade.php has blank Picked Qty + Signature columns | ✅ VERIFIED — This was previously marked wrong |
| G-20 | **Invoice CSV export** | `export()` in SalesController | Not implemented | ❌ Not implemented |
| G-21 | **Challan CSV export** | `export()` in ChallanController | Not implemented | ❌ Not implemented |
| G-22 | **Dead model cleanup** | N/A | `CustomerPaymentSettlement` model still exists (table dropped by P1-4) | ⚠️ VERIFIED — Model file exists but orphaned; service uses invoice_payment_allocations |

### 6.4 Design Improvements in Laravel (Better Than Legacy)

| # | Improvement | Description |
|---|------------|-------------|
| ✅ | **Two-phase flow everywhere** | Draft → confirm/cancel (vs legacy immediate post). Major correctness gain. |
| ✅ | **Append-only reversals** | Originals never mutated, only `is_reversed` flag. Clean audit trail. |
| ✅ | **Atomic document code generation** | `document_sequences` with `SELECT FOR UPDATE` replaces legacy `COUNT+1` race condition. |
| ✅ | **Proper pessimistic locking** | `lockForUpdate()` on key rows prevents concurrent-write corruption. |
| ✅ | **Pipeline-aware availability** | `physical - pipeline` prevents overselling (legacy had no pipeline concept). |
| ✅ | **Redis-cached pipeline** | 5-min TTL with invalidation avoids repeated JOINs. |
| ✅ | **Per-line COGS snapshot** | `sales_challan_items.issue_rate` captures exact avg_cost at time of OUT. |
| ✅ | **Original cost lookup for returns** | Ensures COGS reversal matches exactly; avg_cost restored correctly. |
| ✅ | **Linked damage write-offs** | Properly chains damage invoices to sales returns with correct reversal ordering. |
| ✅ | **GENERATED STORED columns** | `sales_invoice_items.amount`, `sales_return_items.amount` — eliminates PHP calculation drift. |
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

Currently only `amount` on `sales_invoice_items` and `sales_return_items` is GENERATED. Additional candidates:

| Table | Column | Expression | Benefit |
|-------|--------|-----------|---------|
| `sales_invoices` | `due_amount` | `GREATEST(0, total_amount - paid_amount)` | Always in sync, never stale |
| `sales_challans` | `issue_cost` | `(SELECT SUM(cogs_amount) FROM sales_challan_items WHERE sales_challan_id = sales_challans.id)` | Eliminates denormalization drift |
| `sales_returns` | `cogs_amount` | `(SELECT SUM(qty * original_cost) FROM sales_return_items WHERE sales_return_id = sales_returns.id)` | Auto-computed, no service maintenance |
| `warehouse_stock` | `stock_value` | `qty * avg_cost` | Always-current inventory valuation |

**Why this matters for long-term**: GENERATED columns are **always correct** — no service can forget to update them, no bug can make them stale. This eliminates an entire class of data inconsistency bugs.

### 7.2 Partial Indexes — Expand for Query Patterns

Currently 5 partial indexes exist. Additional high-value candidates:

```sql
-- Open invoices (most queried subset)
CREATE INDEX idx_si_open ON sales_invoices (branch_id, invoice_date)
  WHERE status IN ('draft', 'confirmed') AND is_reversed = false;

-- Unpaid invoices (AR aging, collection priority)
CREATE INDEX idx_si_unpaid ON sales_invoices (customer_id, due_amount)
  WHERE due_amount > 0 AND is_reversed = false;

-- Pending returns (warehouse action needed)
CREATE INDEX idx_sr_pending ON sales_returns (branch_id, return_date)
  WHERE status = 'created' AND is_reversed = false;

-- Non-reversed customer ledger (99% of queries)
CREATE INDEX idx_cl_active ON customer_ledger (customer_id, transaction_date)
  WHERE is_reversed = false;

-- Bank-mode payments (intercompany settlement queries)
CREATE INDEX idx_cp_bank ON customer_payments (bank_id, payment_date)
  WHERE payment_mode = 'bank' AND is_reversed = false;
```

**Why this matters for long-term**: Partial indexes are **smaller** (only relevant rows) and **faster** (fewer pages to scan). As data grows, the gap between full and partial indexes widens dramatically.

### 7.3 Covering Indexes (INCLUDE) — Avoid Heap Lookups

```sql
-- Today's invoices DataTable: needs code, date, customer, total, status, paid, due
CREATE INDEX idx_si_today ON sales_invoices (branch_id, invoice_date)
  INCLUDE (invoice_code, customer_id, total_amount, status, paid_amount, due_amount, is_godown_prepared, is_challan_issued);

-- Customer balance lookup: needs latest running_balance
CREATE INDEX idx_cl_balance ON customer_ledger (customer_id, id DESC)
  INCLUDE (balance, is_reversed);

-- Stock availability: needs qty + avg_cost
CREATE INDEX idx_ws_stock ON warehouse_stock (warehouse_id, product_id)
  INCLUDE (qty, avg_cost);
```

**Why this matters for long-term**: Covering indexes enable **index-only scans** — PostgreSQL never needs to visit the heap (actual table pages). For frequently queried columns, this can be 5-10x faster on large tables.

### 7.4 BRIN Indexes — For Time-Series Data

```sql
-- sales_invoices inserted chronologically → BRIN is ideal
CREATE INDEX idx_si_created_brin ON sales_invoices USING BRIN (created_at)
  WITH (pages_per_range = 32);

-- stock_transactions appended chronologically
CREATE INDEX idx_st_date_brin ON stock_transactions USING BRIN (transaction_date)
  WITH (pages_per_range = 32);

-- customer_ledger appended chronologically
CREATE INDEX idx_cl_date_brin ON customer_ledger USING BRIN (created_at)
  WITH (pages_per_range = 32);
```

**Why this matters for long-term**: BRIN indexes are **tiny** (~0.1% of table size vs ~10% for B-tree) and perfect for append-only time-series data. They're essentially free to maintain and dramatically speed up date-range queries on large tables.

### 7.5 JSONB Indexing — Cart + Configuration Queries

```sql
-- GIN index for JSONB cart items (product lookups within cart)
CREATE INDEX idx_sdc_items ON sales_draft_carts USING GIN (items_json jsonb_path_ops);

-- Example query this enables:
-- SELECT * FROM sales_draft_carts WHERE items_json @> '[{"product_id": 42}]';
```

**Why this matters for long-term**: As cart complexity grows (multi-warehouse, condition tracking), GIN indexes enable fast lookups within JSON structures without full table scans.

### 7.6 Advisory Locks — Replace SELECT FOR UPDATE for Code Generation

Currently `document_sequences` uses `SELECT FOR UPDATE` for atomic code generation. PostgreSQL advisory locks are faster:

```sql
-- Instead of: SELECT counter FROM document_sequences WHERE ... FOR UPDATE
-- Use: pg_advisory_xact_lock(hash) → then SELECT/UPDATE without FOR UPDATE

-- Laravel:
DB::select("SELECT pg_advisory_xact_lock(?, ?)", [$branchId, $typeHash]);
$next = DB::selectOne("SELECT nextval('doc_seq_$type')");
```

**Why this matters for long-term**: Advisory locks are **in-memory** (no disk I/O), **transaction-scoped** (auto-released on commit/rollback), and **don't block reads**. Under high concurrency, they outperform row-level locks significantly.

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

### 7.8 Window Functions — Replace Running Balance Denormalization

Currently `customer_ledger.balance` is a denormalized running total maintained by application code. This is **fragile** — if any row is inserted out of order or modified, all subsequent balances are wrong.

Replace with window-function computed balance:

```sql
-- Replace: SELECT balance FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1
-- With:
CREATE FUNCTION customer_current_balance(p_customer_id INT) RETURNS NUMERIC AS $$
  SELECT COALESCE(SUM(debit) - SUM(credit), 0)
  FROM customer_ledger
  WHERE customer_id = p_customer_id AND is_reversed = false;
$$ LANGUAGE SQL STABLE;

-- Or for full running balance verification:
SELECT id, debit, credit,
  SUM(debit - credit) OVER (PARTITION BY customer_id ORDER BY id) AS computed_balance,
  balance AS stored_balance
FROM customer_ledger
WHERE customer_id = ? AND is_reversed = false;
```

**Strategy**: Keep `balance` column for fast reads, but add a **reconciliation job** that verifies `stored_balance = computed_balance` using window functions. Run daily. This gives the performance of denormalization with the safety of recomputation.

**Why this matters for long-term**: Running balance denormalization is the **#1 source of data drift** in accounting systems. Window functions provide a mathematically correct verification that catches any drift early.

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

### 7.10 Row-Level Security (RLS) — Defense-in-Depth for Branch Isolation

Currently branch isolation is enforced at middleware + model scope level. RLS provides **DB-level enforcement** that cannot be bypassed even by direct SQL:

```sql
ALTER TABLE sales_invoices ENABLE ROW LEVEL SECURITY;

CREATE POLICY branch_isolation ON sales_invoices
  USING (branch_id = current_setting('app.branch_id')::int);

-- Set on each request:
DB::statement("SET app.branch_id = ?", [session('branch_id')]);
```

**Why this matters for long-term**: If any developer forgets `BranchScope`, writes raw SQL, or a bug in middleware allows cross-branch access, RLS **still prevents data leakage** at the database level. This is the ultimate defense-in-depth.

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

### 7.14 pg_cron — Replace Laravel Scheduler for DB-Level Jobs

```sql
-- Install pg_cron extension
CREATE EXTENSION pg_cron;

-- Schedule stale draft cleanup (runs even if Laravel queue is down)
SELECT cron.schedule(
  'cancel-stale-drafts',
  '0 2 * * *',
  $$SELECT cancel_stale_sales_drafts(14)$$
);

-- Schedule materialized view refresh
SELECT cron.schedule(
  'refresh-ar-aging',
  '*/5 * * * *',
  $$REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ar_aging$$
);
```

**Why this matters for long-term**: pg_cron runs **inside the database** — no dependency on Laravel scheduler, queue workers, or supervisor. Even if the app server crashes, DB-level maintenance continues.

### 7.15 Full-Text Search — Replace LIKE for Product/Customer Search

Currently customer/product search uses `LIKE '%term%'` which cannot use indexes. PostgreSQL full-text search is vastly superior:

```sql
-- Add tsvector columns
ALTER TABLE products ADD COLUMN search_vector tsvector
  GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(product_name, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(product_code, '')), 'B')
  ) STORED;

ALTER TABLE customers ADD COLUMN search_vector tsvector
  GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(customer_name, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(shop_name, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(mobile, '')), 'C')
  ) STORED;

-- GIN indexes for fast search
CREATE INDEX idx_products_search ON products USING GIN (search_vector);
CREATE INDEX idx_customers_search ON customers USING GIN (search_vector);

-- Search query
SELECT * FROM products
WHERE search_vector @@ plainto_tsquery('english', 'cement')
ORDER BY ts_rank(search_vector, plainto_tsquery('english', 'cement')) DESC
LIMIT 30;
```

**Why this matters for long-term**: Full-text search supports:
- **Stemming** ("running" matches "run")
- **Ranking** (best matches first)
- **Multi-language** (Bengali + English)
- **Index-accelerated** (GIN index, no sequential scan)
- **Scalability** (millions of rows, sub-millisecond)

---

## 8. Implementation Priority & Phase Plan

### Phase 1A: Critical Bug Fixes (Week 1)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 1 | Fix SubLedgerService integration — Sales services should use SubLedgerService instead of inline customer_ledger writes | 🔴 Critical | 2 days | Business Logic |
| 2 | Fix JournalReversalService integration — Sales services should delegate reversals to JournalReversalService::reverseByJournalEntry() | 🔴 Critical | 2 days | Business Logic |
| 3 | Fix journal_entry_id linking in customer_ledger — Ensure every customer_ledger row has journal_entry_id | 🔴 Critical | 1 day | Business Logic |
| 4 | Fix SQL injection in ReconciliationService — Parameterize all date filters | 🔴 Critical | 0.5 day | Security |
| 5 | Remove dead CustomerPaymentSettlement model | Medium | 0.5 day | Cleanup |

### Phase 1B: Missing Business Logic (Week 2-3)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 6 | Implement invoice dispatchers assignment (UI + service) | ⚠️ High | 2 days | Business Logic + UI |
| 7 | Implement multi-invoice payment allocation | ⚠️ High | 3 days | Business Logic + UI |
| 8 | Implement payment transaction types (discount, write_off, refund) with GL | ⚠️ High | 3 days | Business Logic |
| 9 | Implement discount_amount GL posting on payment confirm | ⚠️ High | 1 day | Business Logic |
| 10 | Implement Call It A Day batch operation | ⚠️ High | 1 day | Business Logic |
| 11 | Implement Customer 360 hub view | ⚠️ High | 2 days | UI |

### Phase 1C: PostgreSQL Power Features (Week 3-4)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 12 | Add GENERATED columns for due_amount, issue_cost, cogs_amount, stock_value | High | 2 days | Database |
| 13 | Add partial indexes (open invoices, unpaid, pending returns, active ledger) | High | 1 day | Database |
| 14 | Add covering indexes (INCLUDE) for high-frequency queries | High | 1 day | Database |
| 15 | Add BRIN indexes for time-series tables | High | 0.5 day | Database |
| 16 | Add GIN index on sales_draft_carts.items_json | Medium | 0.5 day | Database |
| 17 | Implement full-text search for products + customers (tsvector + GIN) | High | 2 days | Database + Business Logic |
| 18 | Add window-function running balance reconciliation job | High | 2 days | Database + Business Logic |
| 19 | Implement Row-Level Security (RLS) for branch isolation | High | 2 days | Database + Business Logic |
| 20 | Replace document_sequences SELECT FOR UPDATE with advisory locks | Medium | 1 day | Business Logic |
| 21 | Add pg_cron for stale draft cleanup + materialized view refresh | Medium | 1 day | Database |

### Phase 1D: Notifications & Reports (Week 4-5)

| # | Task | Priority | Effort | Type |
|---|------|----------|--------|------|
| 22 | Implement Telegram notifications (5 event types) | Medium | 2 days | Business Logic |
| 23 | Implement FCM push notifications | Medium | 1 day | Business Logic |
| 24 | Implement Revenue Overview dashboard (Chart.js KPIs) | Medium | 3 days | UI |
| 25 | Implement Sales Funnel/Pipeline dashboard | Medium | 3 days | UI |
| 26 | Implement Customer Performance dashboard | Medium | 3 days | UI |
| 27 | Implement blank godown copy print template | Low | 1 day | UI |
| 28 | Implement CSV export for invoices + challans | Low | 1 day | Business Logic |
| 29 | ~~Implement~~ Sales guideline page (Bengali/English) ✅ DONE | Low | 2 days | UI |
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
| Multi-invoice allocation | ✅ CustomerTransactionController | ❌ Single invoice only | HIGH |
| Payment types (discount/write-off) | ✅ transaction_type column | ❌ Only 'receive' set | HIGH |
| Invoice dispatchers | ✅ Assigned in godown flow | ❌ No UI/service code | HIGH |
| Sales return create | ✅ Two-phase | ✅ Two-phase | None |
| Return warehouse confirm | ✅ Good/Damage condition | ✅ Good/Damage + damage linkage | None |
| Return reversal | ✅ Full (stock + GL + damage) | ✅ Full | None |
| Return slip print | ✅ Bengali | ✅ Full | None |
| Damage write-off | ✅ Manual + auto from return | ✅ Manual + auto from return | None |
| Customer CRUD | ✅ Full with deactivation safety | ✅ Full with deactivation safety | None |
| Customer 360 hub | ✅ Summary + ledger + invoices + payments | ⚠️ Basic show view only (no ledger/invoice/payment tabs) | MEDIUM |
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
| Call It A Day | ✅ Batch operation | ❌ Not implemented | MEDIUM |
| Telegram notifications | ✅ 5 event types | ⚠️ Schema+UI placeholders exist, no dispatch code | MEDIUM |
| FCM notifications | ✅ Push to warehouse managers | ⚠️ fcm_tokens table exists, no push service | MEDIUM |
| Revenue Overview dashboard | ✅ Chart.js KPIs + filters | ⚠️ Basic report only | MEDIUM |
| Sales Funnel dashboard | ✅ Pipeline stages, win rate | ❌ Not implemented | MEDIUM |
| Customer Performance | ✅ CLV, churn, segments | ⚠️ Basic report only | MEDIUM |
| Sales API (mobile) | ❌ No API | ⚠️ Read-only (dashboard + lookups) | MEDIUM |
| Sales guideline page | ✅ Bengali/English | ✅ IMPLEMENTED — SalesGuideController + guide.blade.php | LOW |
| Go-live checklist | ✅ Manager sign-off | ❌ Not implemented | LOW |
| CSV export | ✅ Invoices + challans | ❌ Not implemented | LOW |
| Salesman commission | ❌ Not in legacy | ❌ Not implemented | LOW |

---

> **Last Verified**: 2025-07-20 — All gap items manually checked against Laravel codebase by running targeted searches on service files, controllers, models, and views.

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
| `sales_discount` | Debit | Discount on payment (contra-revenue) |
| `transport_revenue` | Credit | Transport adjustment at challan |
| `inventory_shrinkage` | Debit | Damage, stock take loss |
| `inventory_surplus` | Credit | Stock take gain |
| `damage_loss` | Debit | Damage write-off (falls back to shrinkage) |
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
