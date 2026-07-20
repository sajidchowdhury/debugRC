# RC-ERP Sales Module — Legacy → Laravel Migration Documentation

> **Document Purpose**: Complete analysis of the legacy CodeIgniter sales module, the current Laravel conversion status, and the gap between them. This document serves as the engineering blueprint for completing the migration.

> **Date**: 2025-07-20  
> **Project**: RC-ERP v2 (Remote Center ERP)  
> **Scope**: Sales Entry, Challan/Godown Copy, Invoice, Payment Receive, Returns, and all supporting subsystems

---

## Table of Contents

1. [Legacy Sales Module — Complete Feature Inventory](#1-legacy-sales-module--complete-feature-inventory)
2. [Legacy Business Logic — Critical Rules & Calculations](#2-legacy-business-logic--critical-rules--calculations)
3. [Legacy Database Schema (MySQL)](#3-legacy-database-schema-mysql)
4. [Legacy UI/UX — View Files & Design Patterns](#4-legacy-uiux--view-files--design-patterns)
5. [Current Laravel Sales Module — What Exists](#5-current-laravel-sales-module--what-exists)
6. [Gap Analysis — Legacy vs Laravel](#6-gap-analysis--legacy-vs-laravel)
7. [Database Migration — MySQL → PostgreSQL Differences](#7-database-migration--mysql--postgresql-differences)
8. [Business Logic Gaps — What Must Be Fixed or Improved](#8-business-logic-gaps--what-must-be-fixed-or-improved)
9. [UI Migration Plan — Matching Legacy Look & Feel](#9-ui-migration-plan--matching-legacy-look--feel)
10. [Implementation Roadmap — Prioritized Phases](#10-implementation-roadmap--prioritized-phases)

---

## 1. Legacy Sales Module — Complete Feature Inventory

### 1.1 Controllers & Their Methods

The legacy system has **11 controllers** with sales-related functionality:

| Controller | Methods | Core Purpose |
|---|---|---|
| `SalesController` | 40 methods | Sales invoice lifecycle, cart management, payment receive, print views, audit |
| `ChallanController` | 14 methods | Godown preparation, challan finalization/reversal, print views |
| `SalesReturnController` | 15 methods | Two-phase returns (create → confirm), reversal, print |
| `CustomerController` | 10 methods | Customer CRUD, credit limit, deactivation safety |
| `CustomerTransactionController` | 9 methods | Standalone payments from Accounting module |
| `PaymentController` | 2 methods | Legacy payment wrapper (deprecated) |
| `BranchDemandController` | 15 methods | Inter-branch dispatch, anti-gaming, weekly reports |
| `WarehouseTransferController` | 12 methods | Same-branch warehouse transfers |
| `DamageController` | 9 methods | Damage write-offs (auto-linked from returns) |
| `SalesAuditController` | 3 methods | Module health checks, stale draft cleanup |
| `ReconciliationController` | 1 method | GL reconciliation hub |

### 1.2 Invoice Lifecycle (State Machine)

```
draft ──[finalize]──▶ posted ──[prepare_godown]──▶ godown_issued ──[create_final_challan]──▶ challan_completed
  │                                                │                              │
  │[delete_invoice]                                │[reverse_challan]             │[reverse_challan]
  ▼                                                ▼                              ▼
deleted                                      godown_issued (restored)    ◄── godown_issued (restored)
```

**Status Values in Legacy MySQL**: `draft`, `godown_issued`, `challan_completed`  
**Status Values in Laravel PostgreSQL**: `draft`, `confirmed`, `cancelled`, `reversed`  
⚠️ **MISMATCH** — The Laravel schema uses `confirmed` where legacy uses `godown_issued`, and doesn't have `challan_completed` as a status. The Laravel model uses boolean flags `is_godown_prepared` and `is_challan_issued` instead.

### 1.3 Cart System

The legacy system implements a **dual-store cart** (session + database) with these capabilities:

- **Multi-tab POS**: Multiple customers can have open carts simultaneously per user
- **Session ↔ DB sync**: Session is primary; DB is persistence backup for page refresh
- **DB draft carts table**: `sales_draft_carts` with UPSERT on `(user_id, customer_id)`
- **Soft hold**: Park a cart to prevent accidental finalization
- **Cart validation**: Stock availability + price range validation before finalize
- **Edit mode cart**: Hydrate cart from existing draft invoice items

### 1.4 Price Range System

Every product has a **price band** from `product_price_history`:
- `min_rate` — absolute minimum selling price
- `max_rate` — maximum selling price  
- `default_rate` — suggested price
- `effective_from` — date-based lookup (latest entry wins)

**Business Rule**: Cart rate MUST be `min_rate ≤ rate ≤ max_rate`. Violations block finalization.

### 1.5 Credit Limit Enforcement

Before invoice finalization:
```
current_due = latest customer_ledger.running_balance
projected_due = current_due + new_invoice_total
if projected_due > credit_limit → BLOCK (unless override with reason ≥ 10 chars)
```

Override is logged as `sale_credit_limit_override` audit event separately from the sale event.

### 1.6 Stock Availability (SSOT Formula)

```
available_qty = GREATEST(0, physical_qty - pipeline_qty)

physical_qty = SUM(warehouse_stock.qty) for branch warehouses
pipeline_qty = SUM(ordered_qty - dispatched_qty) 
               FROM sales_invoice_dispatches 
               WHERE invoice status NOT IN ('challan_completed', 'reversed')
               AND invoice.is_reversed = 0
```

This formula accounts for "soft reservations" — items on open invoices that haven't been physically dispatched yet.

### 1.7 Moving Average Costing

The `warehouse_stock` table maintains `avg_cost` per product per warehouse:

**Stock IN** (purchases, returns):
```
new_avg_cost = (old_qty × old_avg + new_qty × new_rate) / (old_qty + new_qty)
```

**Stock OUT** (challans, damage, transfers):
```
qty reduced at current avg_cost; avg_cost unchanged
COGS = qty × avg_cost
```

**Insufficient stock check**: Before any OUT, verify `abs(qty) ≤ current_qty`. Throws `RuntimeException` on shortage.

### 1.8 Customer Ledger (AR Subledger)

Append-only ledger with running balance:
```
running_balance = previous_balance + debit - credit

Invoice:      debit=total_amount, credit=0     → increases AR
Payment:      debit=0, credit=amount           → decreases AR
Return:       debit=0, credit=amount           → decreases AR
Reversal:     opposite sides of original       → undoes original
Adjustment:   debit/credit based on delta      → transport cost changes
```

### 1.9 Challan/Godown Workflow (Two-Step Dispatch)

**Step 1 — Godown Preparation** (`prepareGodown`):
- Assign `warehouse_id` to each invoice item
- Set dispatchers (employees who will deliver)
- Create `sales_invoice_dispatches` rows (ordered_qty=demand, dispatched_qty=0)
- Change invoice status to `godown_issued`
- Handle transport cost changes (adjust customer_ledger + GL if total changes)

**Step 2 — Challan Issue** (`finalizeChallan`):
- For each dispatch line:
  - Get `avg_cost` from `warehouse_stock` (MUST be > 0)
  - Deduct stock: `updateWarehouseStock(warehouse, product, -qty, avg_cost)`
  - Log stock movement
  - Save challan item: `INSERT sales_challan_items (qty, issue_rate, cogs_amount)`
  - Accumulate COGS: `totalCOGS += qty × avg_cost`
- Post COGS GL journal: `Dr COGS / Cr Inventory`
- Change invoice status to `challan_completed`

**Transport Adjustment**: If transport cost changes between godown and challan, the delta is posted as a separate GL adjustment.

### 1.10 Payment Receive

**From Sales Screen** (`SalesController->save_payment`):
- Payment allocated to specific invoice
- Amount cannot exceed invoice outstanding (`total - sum(allocated)`)
- Creates: `customer_payments` + `customer_ledger` credit + `invoice_payment_allocations`
- Posts GL: `Dr Cash/Bank / Cr AR`
- Triggers intercompany settlement for cross-branch bank deposits

**From Accounting Module** (`CustomerTransactionController->store`):
- Supports: `receive`, `payment`, `discount`, `write_off` transaction types
- Discount/write-off cannot exceed customer due
- Standalone (not tied to a specific invoice)

### 1.11 Sales Returns (Two-Phase)

**Phase 1 — Create Return** (no stock/GL movement):
- Invoice must be `challan_completed` (delivered)
- `returnable_qty = invoice_qty - sum(non-reversed return qtys)`
- Return items with qty and rate, condition defaults to `Good`
- Status: `pending`

**Phase 2 — Confirm Return** (stock IN + GL):
- Each item gets warehouse assignment and condition (Good/Damage)
- **Good condition**: Stock IN at ORIGINAL avg_cost (from challan's stock_transaction)
- **Damage condition**: Stock IN temporarily → auto-creates `damage_invoices` write-off
- Customer ledger credit (reduces AR)
- GL: Revenue reversal + COGS reversal + Inventory adjustment
- Status: `completed`

**Reversal**: Reverses all confirm operations. Blocked if insufficient physical stock to remove returned items.

### 1.12 Notification System

| Event | FCM Push | Telegram |
|---|---|---|
| Invoice created | ✅ To salesman | ✅ To channel |
| Challan completed | — | ✅ To salesman + sales-by + warehouse managers |
| Payment received | — | ✅ To channel |
| Return created | — | ✅ To channel |
| Return confirmed | — | ✅ To channel |

### 1.13 Audit & Reconciliation

- **Audit trail**: 13+ event types with JSON details, IP, user agent
- **Sales Audit Checklist**: Automated health checks across 11 sections (products, customers, stock SSOT, invoices, godown, challan, returns, payments, reports)
- **Repair functions**: Auto-fix missing GL links, backfill customer_ledger, repair dispatch warehouses
- **GL Reconciliation**: AR/AP/Inventory/Cash-Bank/COGS tie-outs with tolerance-based mismatch detection
- **Stale draft cleanup**: Auto-cancel drafts older than 14 days

### 1.14 Auto-Code Generation

| Entity | Format | Scope |
|---|---|---|
| Invoice | `SI-XXXX` | Branch-scoped |
| Challan | `CH-XXXX` | Branch-scoped |
| Return | `SR-XXXX` | Branch-scoped |
| Payment | `PAY-XXXX` | Branch-scoped |
| Customer | `C-XXXX` | Global |
| Product | `P-XXXX` | Global |

### 1.15 Inter-Branch Operations

- **Branch Demands**: Request products from another branch → send goods → receive confirmation
- **Warehouse Transfers**: Move products between warehouses within same branch
- **Anti-gaming risk flags**: Weekly control report detects unusual inter-branch patterns
- **Intercompany GL**: Automatic journal entries for cross-branch transactions

### 1.16 Damage Write-Offs

- Can be created standalone or **auto-created from sales return confirmation** when condition = `Damage`
- Linked via `damage_invoices.sales_return_id` → `sales_returns.id`
- Stock IN to warehouse then immediately OUT as write-off
- GL: `Dr Shrinkage/Loss / Cr Inventory`

---

## 2. Legacy Business Logic — Critical Rules & Calculations

### 2.1 Invoice Total Calculation
```
subtotal = Σ(item.qty × item.rate)
total_amount = subtotal + transport_cost - discount_amount
```

### 2.2 Credit Limit Check
```
current_due = customer_ledger.running_balance (latest row)
projected_due = current_due + new_invoice_total
exceeds = projected_due > customer.credit_limit
Override requires: flag + reason_text (min 10 chars)
Override logged as separate audit event: sale_credit_limit_override
```

### 2.3 Stock Availability (Single Source of Truth)
```
available_qty = MAX(0, physical_qty - pipeline_qty)

physical_qty = SUM(warehouse_stock.qty) 
               WHERE warehouse.branch_id = session_branch
               
pipeline_qty = SUM(dispatch.ordered_qty - dispatch.dispatched_qty)
               FROM sales_invoice_dispatches dispatch
               JOIN sales_invoices inv ON inv.id = dispatch.sales_invoice_id
               WHERE dispatch.product_id = ?
                 AND dispatch.ordered_qty > dispatch.dispatched_qty
                 AND inv.status NOT IN ('challan_completed', 'reversed')
                 AND inv.is_reversed = 0
                 AND inv.branch_id = session_branch
                 [AND inv.id != exclude_invoice_id]  -- for edit mode
```

**Key Exclusion**: Completed challans (`challan_completed`) are excluded from pipeline because stock has already been physically removed.

### 2.4 Moving Average Cost Update (Stock IN)
```sql
INSERT INTO warehouse_stock (warehouse_id, product_id, qty, avg_cost)
VALUES (:wid, :pid, :qty, :rate)
ON CONFLICT (warehouse_id, product_id) DO UPDATE
SET qty = warehouse_stock.qty + EXCLUDED.qty,
    avg_cost = CASE
        WHEN (warehouse_stock.qty + EXCLUDED.qty) > 0
        THEN (warehouse_stock.qty * warehouse_stock.avg_cost + EXCLUDED.qty * EXCLUDED.avg_cost) 
             / (warehouse_stock.qty + EXCLUDED.qty)
        ELSE EXCLUDED.avg_cost
    END
```

### 2.5 Stock OUT (No avg_cost change)
```sql
-- Pre-check: sufficient stock
SELECT COALESCE(qty, 0) FROM warehouse_stock WHERE ... FOR UPDATE
-- If abs(qty) > current_qty → THROW RuntimeException

-- Deduct
UPDATE warehouse_stock 
SET qty = qty + :negative_qty, last_updated = CURRENT_TIMESTAMP
WHERE warehouse_id = :wid AND product_id = :pid
-- avg_cost is NOT changed on stock OUT
```

### 2.6 COGS at Challan Completion
```
per_line: cogs_amount = dispatched_qty × warehouse_stock.avg_cost
total_cogs = sum of all line cogs_amounts
GL: Dr COGS / Cr Inventory (total_cogs)
```

**Zero cost check**: If `avg_cost ≤ 0`, challan is blocked with error "Receive stock or set cost first."

### 2.7 Customer Ledger Running Balance
```
running_balance = previous_running_balance + debit - credit
```
The `running_balance` column is the authoritative AR balance — NOT a computed sum. Every financial event appends a row.

### 2.8 Payment Outstanding Check
```
paid_so_far = SUM(invoice_payment_allocations.allocated_amount) 
              WHERE payment.is_reversed = 0
balance_due = total_amount - paid_so_far
payment_amount ≤ balance_due + 0.01 (tolerance for floating point)
```

### 2.9 Returnable Quantity
```
returnable_qty = invoice_item.qty 
                 - SUM(sales_return_items.return_qty 
                        WHERE sales_return.status != 'reversed' 
                        AND sales_return.is_reversed = 0)
```

### 2.10 Return Stock Cost (CRITICAL)
```
Returns use the ORIGINAL avg_cost from the challan's stock_transaction,
NOT the current warehouse avg_cost.

This is documented in avg_cost_rule.md §3:
"Stock returns use the original cost at which the goods were sold,
 not the current replacement cost, to maintain inventory valuation integrity."
```

### 2.11 Damage Auto-Write-Off from Returns
```
On return confirmation with condition='Damage':
1. Stock IN to warehouse (temporarily) at original avg_cost
2. Auto-create damage_invoices record linked to the return
3. Stock OUT immediately (write-off)
4. GL: Dr Shrinkage/Loss / Cr Inventory at original cost
5. Link: damage_invoices.sales_return_id = sales_returns.id
```

### 2.12 Transport Cost Adjustment
```
If transport_cost changes between invoice creation and challan completion:
1. Snapshot original: sales_invoices.pre_challan_transport, pre_challan_total
2. Post delta to customer_ledger (debit if increase, credit if decrease)
3. Post GL adjustment journal
4. On challan reversal: reverse the adjustment
```

### 2.13 Idempotency (Legacy lacks this)
The legacy system does NOT have idempotency protection on finalization. Double-submit is possible if user clicks "Finalize" twice quickly. The Laravel version adds an idempotency token.

### 2.14 Branch Scope Enforcement
```
All data access scoped to session user's branch_id:
- Warehouses must belong to session branch
- Invoices must belong to session branch
- Stock queries filtered by branch
- Admin/manager can override (canOverrideBranch)
```

---

## 3. Legacy Database Schema (MySQL)

### 3.1 Core Sales Tables

| Table | Purpose | Key Columns |
|---|---|---|
| `sales_invoices` | Invoice master | `invoice_code`, `customer_id`, `salesman_id`, `branch_id`, `sub_total`, `discount`, `transport_cost`, `total_amount`, `status` (draft/godown_issued/challan_completed), `is_reversed`, `journal_entry_id` |
| `sales_invoice_items` | Invoice line items | `sales_invoice_id`, `product_id`, `warehouse_id` (NULL until godown), `qty`, `rate` |
| `sales_invoice_dispatches` | Dispatch pipeline | `sales_invoice_id`, `product_id`, `warehouse_id`, `ordered_qty`, `dispatched_qty` |
| `sales_invoice_dispatchers` | Delivery employees | `sales_invoice_id`, `employee_id`, `dispatch_role` |
| `sales_challans` | Challan records | `challan_code`, `sales_invoice_id`, `transport_adjustment`, `journal_entry_id`, `is_reversed` |
| `sales_challan_items` | Per-line issue cost SSOT | `challan_id`, `product_id`, `warehouse_id`, `qty`, `issue_rate`, `cogs_amount` |
| `sales_returns` | Return master | `return_code`, `sales_invoice_id`, `customer_id`, `status` (pending/completed), `is_reversed`, `journal_entry_id`, `cogs_journal_entry_id` |
| `sales_return_items` | Return line items | `sales_return_id`, `product_id`, `warehouse_id`, `return_qty`, `rate`, `condition_state` (Good/Damage), `original_cost` |
| `sales_draft_carts` | Persistent POS carts | `user_id`, `customer_id`, `items_json`, `is_soft_hold` |
| `customer_payments` | Payment records | `payment_code`, `customer_id`, `transaction_type` (receive/payment/discount/write_off), `amount`, `payment_mode` (cash/bank), `is_reversed` |
| `invoice_payment_allocations` | Payment→Invoice link | `payment_id`, `invoice_id`, `allocated_amount` |
| `customer_ledger` | AR subledger | `customer_id`, `debit`, `credit`, `running_balance`, `reference_type`, `reference_id`, `is_reversed` |
| `damage_invoices` | Damage write-offs | `sales_return_id`, `total_value`, `status` |

### 3.2 Supporting Tables

| Table | Purpose |
|---|---|
| `customers` | Customer master with `credit_limit` |
| `products` | Product master with `safety_stock` |
| `product_price_history` | Append-only price band (min_rate, max_rate, default_rate, effective_from) |
| `warehouse_stock` | Per-warehouse qty + avg_cost |
| `stock_transactions` | Immutable audit log of all stock movements |
| `warehouses` | Warehouse master with branch_id |
| `journal_entries` + `journal_lines` | GL double-entry bookkeeping |
| `banks` | Bank accounts with balance |
| `bank_ledger_mappings` | Bank → GL ledger mapping |

---

## 4. Legacy UI/UX — View Files & Design Patterns

### 4.1 View File Inventory

| Directory | Files | Purpose |
|---|---|---|
| `views/sales/` | 14 files | Create/edit invoice, today's sales, print views, audit, guide, cockpit dashboards |
| `views/challan/` | 6 files | Godown/challan workspace, queue listing, print views |
| `views/SalesReturn/` | 8 files | Create/confirm return, reverse, slip, audit |
| `views/SalesAudit/` | 1 file | Health checklist |
| `views/customer/` | 6 files | CRUD, hub/profile, audit |
| `views/Accounting/customer/` | 5 files | Payment CRUD, slip, details, audit |
| `views/partials/` | 2 files | GL journal blocks, branch SVG header |

### 4.2 Key UI Patterns

**Sales Entry (POS)**:
- Customer search with autocomplete (AJAX, rate-limited 90 req/60s)
- Product search with barcode scanner support
- Price range visual slider (min/default/max with drag thumb)
- Multi-cart tab dock (switch between customers)
- Sticky finalize bar with credit limit warning
- Branch override dropdown for admin/manager

**Today's Sales**:
- Smart sort (unpaid first by default)
- Filter chips: awaiting_payment, open_pipeline, pending, godown_copy, challan_generated
- Inline payment receive modal (no page navigation)
- Call It A Day button (UI filter, no accounting effect)
- DataTables server-side processing (180 req/60s rate limit)

**Challan/Godown**:
- 3-step progress indicator (Invoice → Godown → Challan)
- Warehouse assignment with available/reserved stock display
- Bulk warehouse apply bar
- Dispatcher multi-select
- Ctrl+S keyboard shortcut
- Transport cost locked after completion

**Returns**:
- 2-step journey (Receive from customer → Warehouse confirm)
- Offcanvas quick-create from index page
- Condition select per item (Good/Damage)
- Side panel with "On confirm" effects preview
- Stock shortage detection (red rows)

**Print Views**:
- All standalone HTML (no main layout)
- Branch-branded SVG headers (4 themes: HO red, Patuatuli blue, Nowabpur green, Tarabo orange)
- Bengali labels with English subtitles
- Multi-page pagination (17 items/page)
- Signature lines for delivery/receipt

### 4.3 JavaScript Architecture

| JS File | Purpose |
|---|---|
| `sales.js` | Shared sales utilities (AJAX helpers, validation) |
| `sales-create.js` | Cart management, product search, finalize flow |
| `sales-edit.js` | Edit mode cart hydration, update flow |
| `sales-today-index.js` | DataTable, filters, smart sort |
| `sales-receive-payment.js` | Payment modal, quick amounts, bank panel |
| `challan.js` | Godown/challan workspace, warehouse assignment |
| `challan-index.js` | Challan queue listing |
| `SalesReturn.js` | Return creation, invoice search |
| `sales-return-index.js` | Return listing with offcanvas |
| `sales-return-confirm.js` | Warehouse confirmation, condition select |
| `sales-return-reverse.js` | Reversal with stock preview |
| `sales-guide.js` | Search filtering for guideline pages |

**Boot Data Pattern**: PHP → JS via `window.*_BOOT` JSON globals for initial state, `window.CSRF_TOKEN` for all POST requests.

### 4.4 CSS Assets

| File | Purpose |
|---|---|
| `sales-pos.css` | POS layout, cart, price range slider |
| `sales-today-index.css` | Today's sales layout, filter chips |
| `sales-receive-payment.css` | Payment modal, quick amount chips |
| `challan-create.css` | Godown/challan workspace |
| `challan-index.css` | Challan queue |
| `sales-return-*.css` | Return-specific layouts |
| `invoice-print.css` | All print views |
| `sales-guide.css` | Guideline/checklist pages |
| `custom.css` | Shared ERP styles |
| `accounting-money-flow.css` | GL journal visualization |

---

## 5. Current Laravel Sales Module — What Exists

### 5.1 Controllers (5 files, ALL COMPLETE)

| Controller | Phase | Methods | Status |
|---|---|---|---|
| `SalesCartController` | 8.1 | 9 (index, load, add, update, remove, clear, validate, softHold, checkAvailability) | ✅ COMPLETE |
| `SalesInvoiceController` | 8.2 | 12 (index, finalize, show, edit, update, cancel, cancelStaleDrafts, auditTrail, printInvoice, printGodown, getCartData, checkCreditLimit) | ✅ COMPLETE |
| `SalesChallanController` | 8.3 | 8 (index, godown, storeGodown, challanForm, issueChallan, show, cancel, printChallan) | ✅ COMPLETE |
| `SalesReturnController` | 8.5 | 8 (index, create, store, confirm, reverse, show, printSlip, getInvoiceDetails) | ✅ COMPLETE |
| `CustomerPaymentController` | 8.4 | 7 (index, create, store, show, cancel, printReceipt, getOutstandingInvoices) | ✅ COMPLETE |

### 5.2 Models (8 files, ALL COMPLETE)

All models have `BranchScope` global scope, `SoftDeletes`, and `AuditableMasterData` trait where applicable.

### 5.3 Services (7 files, ALL COMPLETE)

| Service | Purpose |
|---|---|
| `SalesCartService` | DB-backed cart with price range + stock validation |
| `SalesInvoiceService` | Finalize pipeline (9 steps), cancel, update |
| `SalesChallanService` | Godown prep, challan issue (stock OUT + COGS GL), cancel |
| `SalesReturnService` | Two-phase return at ORIGINAL avg_cost, damage auto-link |
| `CustomerPaymentService` | Bank/Cash GL posting, intercompany settlement |
| `SalesAuditLogger` | 13 event types, dual-write (DB + file) |
| `SalesAccess` | Branch isolation defense-in-depth |

### 5.4 Views (16 blade templates, MOSTLY COMPLETE)

⚠️ **MISSING**: Customer Payment views (`admin.customer-payments.*`) — controller references them but they don't exist in the views directory.

### 5.5 Routes (COMPLETE)

All routes defined in `routes/web.php` with proper middleware:
- `role:salesman,manager,admin` for sales operations
- `role:warehouse_manager,manager,admin` for godown/challan
- `branch.isolation` on all routes

### 5.6 Migrations (6 files for sales-specific schema adjustments)

### 5.7 Console Commands (2)

| Command | Schedule |
|---|---|
| `sales:cancel-stale-drafts` | Daily at 02:00 |
| `sales:pen-test` | Manual (security testing) |

---

## 6. Gap Analysis — Legacy vs Laravel

### 6.1 🔴 CRITICAL GAPS (Must Fix Before Go-Live)

| # | Gap | Legacy Has | Laravel Has | Impact | Fix Required |
|---|---|---|---|---|---|
| G1 | **Customer Payment views missing** | 5 views (index, create, slip, details, audit) | ❌ None — controller returns 404 | Users cannot record or view payments from UI | Create all 5 blade templates |
| G2 | **Invoice status mismatch** | `draft` → `godown_issued` → `challan_completed` | `draft` → `confirmed` → booleans `is_godown_prepared`, `is_challan_issued` | Status queries, filter chips, and UI badges will show wrong values | Align status values OR ensure UI/service layer maps correctly |
| G3 | **sales_invoice_dispatches schema mismatch** | `ordered_qty`, `dispatched_qty` pipeline columns | Schema has `qty`, `rate`, `amount` only — no pipeline tracking | Stock availability SSOT formula cannot work without pipeline columns | Add `ordered_qty` and `dispatched_qty` columns via migration |
| G4 | **No customer_ledger table in SQL schema** | Full AR subledger with `running_balance` | ❌ Not in `01_auth_and_master.sql` or any SQL file | No AR tracking, no credit limit check, no customer balance | Verify if added by migration; if not, create migration |
| G5 | **No customer_payments table in SQL schema** | Full payment tracking with allocations | ❌ Not in SQL files | Payment receive won't work | Verify migration coverage; add if missing |
| G6 | **No invoice_payment_allocations table** | Links payments to specific invoices | ❌ Not in SQL files | Cannot allocate payments to invoices | Add via migration |
| G7 | **No damage_invoices table** | Damage write-offs auto-linked to returns | ❌ Not in SQL files | Damaged return items cannot be processed | Add via migration |
| G8 | **No FCM/Telegram notifications** | Full notification system | ❌ No notification implementation | Warehouse managers not alerted of new challans | Implement notification service |

### 6.2 🟡 MODERATE GAPS (Should Fix for Production Quality)

| # | Gap | Legacy Has | Laravel Has | Impact |
|---|---|---|---|---|
| G9 | **No Form Request classes** | Inline validation in controllers | Inline validation in controllers | Not reusable, hard to test, no auto-documentation |
| G10 | **No Policy classes** | Role-based access via middleware | Role-based access via middleware only | No per-record authorization (e.g., "only creator can edit") |
| G11 | **No Sales API endpoints** | AJAX endpoints (rate-limited JSON) | Only 2 dashboard read endpoints | Mobile app can't create invoices/challans/returns |
| G12 | **No Intelligent Sales Cockpit** | Revenue Overview, Funnel Pipeline, Customer Performance dashboards | ❌ None | Executive dashboards missing |
| G13 | **No Sales Guideline/Go-Live Checklist** | 2 comprehensive Bengali help pages | ❌ None | User onboarding and training materials missing |
| G14 | **No GL Reconciliation hub** | Full AR/AP/Inventory/Cash/Bank tie-out | ❌ None (ReconciliationController stub exists but no sales reconciliation view) | Accountants cannot verify GL integrity |
| G15 | **No sales-specific audit checklist** | Automated 11-section health check with repair | Basic audit trail only | Cannot detect data integrity issues |
| G16 | **No branch demand / inter-branch dispatch** | Full inter-branch workflow with anti-gaming | ❌ None (separate module, not sales-specific) | Multi-branch operations not supported |
| G17 | **No warehouse transfer from sales** | Same-branch transfers | ❌ None (separate module) | Warehouse rebalancing not available |
| G18 | **Bengali localization missing** | All print views in Bengali | English only | Bangladeshi users need Bengali labels |
| G19 | **Branch-themed SVG headers** | 4 branch color themes in print views | Generic header | Branding consistency |
| G20 | **No CSV export** | Export for invoices, challans, returns | ❌ None | Data export for accounting/reconciliation |

### 6.3 🟢 MINOR GAPS (Nice to Have)

| # | Gap | Legacy Has | Laravel Has |
|---|---|---|---|
| G21 | No dispatcher assignment on godown | `sales_invoice_dispatchers` table + multi-select UI | ❌ |
| G22 | No blank godown copy print | Pre-formatted hand-write picking sheet | ❌ |
| G23 | No "Call It A Day" feature | Batch UI filter for end-of-day review | ❌ |
| G24 | No print multi-page pagination | 17 items/page with continuation notes | ❌ |
| G25 | No sidebar accounting nav | Conditional accounting sidebar section | Standard admin sidebar |

---

## 7. Database Migration — MySQL → PostgreSQL Differences

### 7.1 Schema Conversion Rules Applied

| MySQL Type | PostgreSQL Type | Notes |
|---|---|---|
| `int(11)` | `integer` | No display width in PG |
| `bigint(20)` | `bigint` | No display width |
| `tinyint(1)` | `boolean` | MySQL boolean emulation |
| `decimal(p,s)` | `numeric(p,s)` | PG standard |
| `float(20,2)` | `numeric(18,2)` | **CRITICAL FIX** — MySQL float loses precision for money |
| `datetime` | `timestamp(0)` | PG standard |
| `enum(...)` | `varchar(50) CHECK (col IN (...))` | PG has no ENUM type |
| `AUTO_INCREMENT` | `GENERATED ALWAYS AS IDENTITY` | PG standard (no manual sequence management) |
| `0000-00-00` | `NULL` | PG doesn't allow zero dates |

### 7.2 Specific Sales Schema Differences

| Issue | MySQL (Legacy) | PostgreSQL (Laravel) | Impact |
|---|---|---|---|
| Invoice status values | `draft`, `godown_issued`, `challan_completed` | `draft`, `confirmed`, `cancelled`, `reversed` + boolean flags | **MISMATCH** — code expecting legacy values will break |
| Dispatch pipeline | `ordered_qty`, `dispatched_qty` separate columns | `qty` only in base schema | **MISSING** — pipeline calculation impossible |
| Invoice item amount | Computed in PHP | `GENERATED ALWAYS AS (qty * rate) STORED` | Good — PG auto-computes |
| `sales_invoice_dispatchers` | Full table with `dispatch_role` | Schema exists but no Model/Controller | Dead schema |
| `deleted_at` on returns | No soft delete column | Model uses `SoftDeletes` but SQL may not have column | Migration needed |
| `cogs_amount` on returns | Added later via migration | Migration exists | OK |
| `pre_challan_transport/total` | Added later via migration | Migration exists | OK |

### 7.3 Missing Tables in PostgreSQL Schema

The following tables exist in legacy MySQL but are NOT in the base PostgreSQL SQL files:

| Table | Required For | Fix |
|---|---|---|
| `customer_ledger` | AR subledger, credit limits, customer balance | Must add via migration or verify existing migration |
| `customer_payments` | Payment recording | Must add via migration |
| `invoice_payment_allocations` | Payment→Invoice linking | Must add via migration |
| `damage_invoices` | Return damage write-offs | Must add via migration |
| `stock_transactions` | Stock movement audit log | Must add via migration (may be in 03_stock.sql) |
| `warehouse_stock` | Moving average inventory | Must add via migration (may be in 03_stock.sql) |
| `customer_payment_settlements` | Inter-branch settlement | Must add via migration |

---

## 8. Business Logic Gaps — What Must Be Fixed or Improved

### 8.1 Improvements Over Legacy (Already Done in Laravel)

| Feature | Legacy Implementation | Laravel Improvement |
|---|---|---|
| Idempotency | ❌ None (double-submit risk) | ✅ Idempotency token on finalize |
| Stale draft cleanup | Manual only | ✅ Scheduled command (daily 02:00) |
| Branch isolation | Session-based only | ✅ Defense-in-depth: global scope + middleware + service-level |
| Security testing | Manual | ✅ Automated pen-test command |
| Audit logging | Single-write (DB) | ✅ Dual-write (DB + file) |
| Transport adjustment | Complex with multiple fallbacks | ✅ Simplified snapshot + single GL adjustment |
| Stock costing on returns | Complex fallback chain | ✅ Direct lookup from challan's stock_transaction |

### 8.2 Business Logic That Must Be Verified/Fixed

| # | Logic | Risk | Action Required |
|---|---|---|---|
| BL1 | Stock availability SSOT formula | HIGH — if pipeline columns missing, formula returns wrong values | Verify `ordered_qty`/`dispatched_qty` exist; add migration if missing |
| BL2 | Moving average costing | HIGH — wrong COGS if upsert logic differs | Compare Laravel `StockService` with legacy `StockTransactionModel` line by line |
| BL3 | Credit limit check | MEDIUM — depends on `customer_ledger.running_balance` existing | Verify table exists and running_balance is maintained |
| BL4 | Return stock at ORIGINAL avg_cost | HIGH — using current cost instead would inflate/deflate inventory | Verify `SalesReturnService::confirmReturn` uses original cost from challan |
| BL5 | Damage auto-write-off | MEDIUM — return flow breaks if damage_invoices table missing | Verify table exists; verify auto-creation logic in service |
| BL6 | Payment allocation to invoices | HIGH — without `invoice_payment_allocations`, outstanding calculation wrong | Verify table exists and allocation logic works |
| BL7 | Transport adjustment journal | MEDIUM — complex workflow with snapshot + delta | Verify `pre_challan_transport/total` columns exist; test with changed transport |
| BL8 | Challan reversal with stock restore | HIGH — must restore stock at original issue_rate, not current avg_cost | Verify reversal uses `sales_challan_items.issue_rate` |
| BL9 | Customer ledger dual-entry | HIGH — every financial event must append to ledger | Verify all paths (invoice, payment, return, reversal, adjustment) post to ledger |
| BL10 | Branch scope on all queries | MEDIUM — data leakage if scope missing | Verify `BranchScope` is registered on ALL models that need it |

### 8.3 Potential Business Logic Improvements

| # | Improvement | Current Legacy Behavior | Proposed Laravel Improvement |
|---|---|---|---|
| I1 | Partial dispatch | ❌ Not allowed (dispatched_qty MUST equal ordered_qty) | ✅ Allow partial dispatch with "short ship" reason — better for real-world where items may be out of stock |
| I2 | Credit limit override audit | Override logged as separate event | ✅ Already improved in Laravel — override captured inline with sale event + idempotency |
| I3 | Payment settlement priority | No explicit priority (FIFO implied) | ✅ Add configurable settlement method: FIFO, LIFO, or manual allocation |
| I4 | Return reason tracking | Free-text only | ✅ Add categorized reasons (defective, wrong_item, damaged_in_transit, customer_change) with analytics |
| I5 | Stock reservation timeout | Pipeline reservations never expire (until challan or cancellation) | ✅ Add optional reservation timeout (e.g., 48h) for high-demand items |
| I6 | Price override audit | Only credit limit override audited | ✅ Audit ALL price changes that deviate from default_rate (not just outside min/max) |
| I7 | Batch payment allocation | One invoice at a time | ✅ Allow selecting multiple invoices in single payment with auto-allocation |

---

## 9. UI Migration Plan — Matching Legacy Look & Feel

### 9.1 Design Principles

1. **Same UI/UX** — The new Laravel views must look and feel identical to the legacy views
2. **Bengali localization** — All print views and key labels must be in Bengali
3. **Branch themes** — SVG headers with branch-specific colors
4. **Bootstrap 5.3.3** — Same CSS framework as legacy
5. **Same JS libraries** — jQuery 3.6, Select2, DataTables, SweetAlert2, Chart.js

### 9.2 View-by-View Mapping

| Legacy View | Laravel Blade | Status | Notes |
|---|---|---|---|
| `sales/create.php` | `sales/cart.blade.php` | ✅ Exists but different name | Must verify same POS workflow |
| `sales/edit.php` | `sales-invoices/edit.blade.php` | ✅ Exists | Verify draft-only guard UI |
| `sales/today.php` | `sales-invoices/index.blade.php` | ✅ Exists | Must add smart sort, filter chips, receive modal |
| `sales/show.php` | `sales-invoices/show.blade.php` | ✅ Exists | Must add GL journal blocks partial |
| `sales/invoice_copy.php` | `sales-invoices/print_invoice.blade.php` | ✅ Exists | Must add Bengali, multi-page, branch SVG |
| `sales/receive_modal.php` | ❌ Missing | Must create | Inline modal for today's sales page |
| `sales/print_receipt.php` | ❌ Missing (customer-payments view) | Must create | Payment receipt print |
| `sales/reconcile.php` | ❌ Missing | Must create | GL reconciliation dashboard |
| `sales/RevenueOverview.php` | ❌ Missing | Must create | Intelligent Sales Cockpit |
| `sales/CustomerPerformance.php` | ❌ Missing | Must create | Customer intelligence |
| `sales/SalesFunnelPipeline.php` | ❌ Missing | Must create | Pipeline dashboard |
| `sales/guide.php` | ❌ Missing | Must create | User manual in Bengali |
| `sales/go_live_checklist.php` | ❌ Missing | Must create | Go-live checklist |
| `sales/audit.php` | `sales-audit/index.blade.php` | ✅ Exists | Verify event type coverage |
| `challan/create.php` | `sales-challans/godown.blade.php` | ✅ Exists | Must add 3-step progress, dispatcher select |
| `challan/index.php` | `sales-challans/index.blade.php` | ✅ Exists | Must add queue badges, filter chips |
| `challan/details.php` | `sales-challans/show.blade.php` | ✅ Exists | Must add GL journal blocks |
| `challan/godown_copy.php` | `sales-invoices/print_godown.blade.php` | ✅ Exists | Must add Bengali, branch SVG, signatures |
| `challan/challan_copy.php` | `sales-challans/print_challan.blade.php` | ✅ Exists | Must add Bengali, branch SVG, signatures |
| `challan/print_blank_godown_copy.php` | ❌ Missing | Must create | Hand-write picking sheet |
| `SalesReturn/create.php` | `sales-returns/create.blade.php` | ✅ Exists | Must add offcanvas mode for quick-create |
| `SalesReturn/index.php` | `sales-returns/index.blade.php` | ✅ Exists | Must add inline offcanvas create |
| `SalesReturn/confirm.php` | ❌ Missing | Must create | Warehouse confirm with condition select |
| `SalesReturn/details.php` | `sales-returns/show.blade.php` | ✅ Exists | Must add GL journal blocks |
| `SalesReturn/slip.php` | `sales-returns/print_slip.blade.php` | ✅ Exists | Must add Bengali, branch SVG |
| `SalesReturn/reverse.php` | ❌ Missing | Must create | Reversal with stock preview |
| `SalesAudit/checklist.php` | `reports/sales_audit_checklist.blade.php` | ✅ Exists | Verify section coverage |
| `customer/*` | ❌ Not in sales scope | Separate module | Customer CRUD is separate |
| `Accounting/customer/*` | ❌ Missing | Must create | Customer payment views |

### 9.3 CSS/JS Assets to Port

| Asset | Source | Destination |
|---|---|---|
| `sales-pos.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `sales-today-index.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `sales-receive-payment.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `challan-create.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `challan-index.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `sales-return-*.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `invoice-print.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `sales-guide.css` | `legacy/public/assets/css/` | `laravel/public/assets/css/` |
| `sales.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-create.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-edit.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-today-index.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-receive-payment.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `challan.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `challan-index.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `SalesReturn.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-return-*.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| `sales-guide.js` | `legacy/public/assets/js/` | `laravel/public/assets/js/` |
| Branch SVG header partial | `legacy/app/views/sales/partials/` | Blade include component |
| GL journal blocks partial | `legacy/app/views/partials/` | Blade include component |

---

## 10. Implementation Roadmap — Prioritized Phases

### Phase A: Database Schema Fixes (HIGHEST PRIORITY — blocks everything else)

**Goal**: Ensure the PostgreSQL schema matches all the tables and columns the Laravel services expect.

| Task | Description | Est. Effort |
|---|---|---|
| A1 | Verify `customer_ledger` table exists (check if in 02_accounting.sql or migration) | 0.5h |
| A2 | Verify `customer_payments` table exists | 0.5h |
| A3 | Verify `invoice_payment_allocations` table exists | 0.5h |
| A4 | Add `ordered_qty` + `dispatched_qty` to `sales_invoice_dispatches` if missing | 1h |
| A5 | Verify `damage_invoices` table exists with `sales_return_id` + `total_value` + `status` columns | 0.5h |
| A6 | Verify `warehouse_stock` and `stock_transactions` tables exist (check 03_stock.sql) | 0.5h |
| A7 | Add `customer_payment_settlements` table if needed for inter-branch | 1h |
| A8 | Resolve invoice status mismatch (legacy string vs Laravel boolean+string) | 2h |
| A9 | Verify `sales_invoice_dispatchers` — decide if keeping or dropping | 0.5h |
| A10 | Add `deleted_at` to `sales_returns` if SoftDeletes is used | 0.5h |

**Total: ~7.5h**

### Phase B: Critical Missing Views (HIGH PRIORITY — users can't use the system)

| Task | Description | Est. Effort |
|---|---|---|
| B1 | Create `customer-payments/index.blade.php` | 4h |
| B2 | Create `customer-payments/create.blade.php` | 4h |
| B3 | Create `customer-payments/show.blade.php` | 3h |
| B4 | Create `customer-payments/print_receipt.blade.php` | 2h |
| B5 | Create `sales-returns/confirm.blade.php` | 3h |
| B6 | Create `sales-returns/reverse.blade.php` | 2h |
| B7 | Create payment receive modal partial for today's sales | 2h |
| B8 | Port legacy CSS/JS assets to Laravel public directory | 2h |

**Total: ~22h**

### Phase C: Business Logic Verification (HIGH PRIORITY — correctness)

| Task | Description | Est. Effort |
|---|---|---|
| C1 | Line-by-line verify `SalesInvoiceService::finalizeFromCart` vs legacy `SalesInvoiceOperationsTrait::finalizeSales` | 3h |
| C2 | Verify `StockAvailabilityService` SSOT formula matches legacy exactly | 2h |
| C3 | Verify moving average costing upsert logic matches legacy | 2h |
| C4 | Verify `SalesReturnService::confirmReturn` uses ORIGINAL avg_cost | 1h |
| C5 | Verify `CustomerPaymentService` matches legacy payment allocation | 2h |
| C6 | Verify `SalesChallanService::issueChallan` COGS calculation | 2h |
| C7 | Verify customer ledger posting on all financial events | 3h |
| C8 | Verify transport adjustment journal posting | 1h |
| C9 | Verify challan reversal restores stock at issue_rate | 1h |
| C10 | End-to-end integration test: Invoice → Godown → Challan → Payment → Return → Reverse | 4h |

**Total: ~21h**

### Phase D: UI Polish — Bengali + Print Views (MEDIUM PRIORITY)

| Task | Description | Est. Effort |
|---|---|---|
| D1 | Bengali localization for all print views (invoice, godown, challan, return slip, receipt) | 4h |
| D2 | Branch-themed SVG headers for print views | 2h |
| D3 | Multi-page pagination for print views (17 items/page) | 2h |
| D4 | Signature lines on all print documents | 1h |
| D5 | Create blank godown copy print view | 1h |
| D6 | Port Intelligent Sales Cockpit dashboards (Revenue Overview, Funnel, Customer Performance) | 12h |

**Total: ~22h**

### Phase E: Notification & Audit Enhancements (MEDIUM PRIORITY)

| Task | Description | Est. Effort |
|---|---|---|
| E1 | Implement FCM push notifications for invoice/challan events | 4h |
| E2 | Implement Telegram bot notifications | 4h |
| E3 | Build GL Reconciliation hub view | 6h |
| E4 | Enhance sales audit checklist with repair functions | 4h |
| E5 | Create Sales Guideline page (Bengali) | 3h |
| E6 | Create Go-Live Checklist page (Bengali) | 2h |
| E7 | Add CSV export for invoices, challans, returns | 3h |

**Total: ~26h**

### Phase F: Business Logic Improvements (LOW PRIORITY — nice to have)

| Task | Description | Est. Effort |
|---|---|---|
| F1 | Implement partial dispatch with "short ship" reason | 6h |
| F2 | Add configurable payment settlement priority (FIFO/LIFO/manual) | 4h |
| F3 | Add categorized return reasons with analytics | 3h |
| F4 | Add stock reservation timeout for high-demand items | 4h |
| F5 | Add price override audit (deviations from default_rate) | 2h |
| F6 | Add batch payment allocation (multiple invoices in one payment) | 4h |
| F7 | Build Sales REST API for mobile/AI integration | 12h |
| F8 | Add Form Request classes for all sales endpoints | 4h |
| F9 | Add Policy classes for per-record authorization | 4h |

**Total: ~43h**

---

## Grand Total Estimate

| Phase | Priority | Est. Hours |
|---|---|---|
| A: Database Schema Fixes | 🔴 CRITICAL | 7.5h |
| B: Critical Missing Views | 🔴 CRITICAL | 22h |
| C: Business Logic Verification | 🔴 CRITICAL | 21h |
| D: UI Polish (Bengali + Print) | 🟡 MEDIUM | 22h |
| E: Notification & Audit | 🟡 MEDIUM | 26h |
| F: Improvements | 🟢 LOW | 43h |
| **TOTAL** | | **~141.5h** |

---

*End of Sales Module Migration Documentation*
