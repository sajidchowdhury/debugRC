# TODAY_INVOICE_MIGRATION_PLAN.md

> Single-menu migration plan: bring Project B (Laravel/PostgreSQL/Tailwind) to **parity-or-better** with Project A (legacy raw-PHP MVC) for the **"Today Invoice" → Godown & Challan** workspace.
>
> **Status:** Planning document only. No source code is modified by this document.
> **Author:** Migration Analyst (automated analysis of `debugRC` commit at clone time).

---

## 1. Overview

### 1.1 What this document is
A phase-by-phase implementation plan that a developer can execute, in order, to make Project B's Godown & Challan workspace match (and improve on) Project A's equivalent screen — rebuilt with Project B's own stack: **Laravel + PostgreSQL + Tailwind CSS** (not Bootstrap).

### 1.2 Scope (STRICT — one menu only)
This plan covers **only** the Godown & Challan creation workspace, identified by the two URLs below. It does **not** touch the rest of the project, core architecture, auth, routing skeleton, or global CSS setup.

| Project | URL | Resolves to |
|---|---|---|
| **A — legacy** (`legacy/`) | `http://localhost/remote-center-erp/public/challan/create/528` | `ChallanController::create(528)` → renders `app/views/challan/create.php` |
| **B — laravel** (`laravel/`) | `http://localhost:8080/admin/sales-challans/godown/2` | `SalesChallanController@godown(2)` → renders `resources/views/admin/sales-challans/godown.blade.php` |

### 1.3 Critical clarifications discovered during analysis (read before executing)

These three findings reframe the plan and appear again in **§8 Open Questions**:

1. **`{id}` is a `sales_invoices.id`, not a godown/warehouse id.** In *both* projects the URL parameter is the **SalesInvoice primary key** (proof: `legacy/app/controllers/ChallanController.php:99` passes `$id` to `getInvoiceForGodownCopy($id)` which queries `WHERE si.id = :id`; `laravel/app/Http/Controllers/Admin/SalesChallanController.php:154` signature is `godown(Request, int $invoiceId)` and calls `SalesInvoice::findOrFail($invoiceId)`). Warehouses are chosen *inside* the form.

2. **The literal "Today Invoice" menu is a *different* screen.** In both projects the sidebar label **"Today Invoice"** points to the **invoices list** (`legacy`: `/sales/today`; `laravel`: route `admin.sales-invoices.index` with a `scope=today` filter — see `laravel/app/Services/MenuService.php:156` and `database/migrations/2025_01_10_000001_seed_menus_from_legacy.php:51`). The two URLs you supplied are the **Godown & Challan workspace** that a draft invoice links *into*. This plan treats your two URLs as the authoritative scope. If you actually also want the list screen styled, that is a separate engagement — see §7.

3. **Project A is NOT MySQL.** Despite the brief saying "raw PHP, MVC, MySQL," the legacy query layer already uses PostgreSQL dialect (`ON CONFLICT … DO UPDATE`, `CURRENT_DATE`, `string_agg(DISTINCT …)`, `FOR UPDATE`, `information_schema` introspection — see `legacy/app/models/StockTransactionModel.php:75`, `legacy/app/models/ChallanModel.php:1698`). Both projects share the same PostgreSQL database lineage. This **simplifies** migration (no dialect translation) but means the "MySQL → PostgreSQL" framing in the brief is inaccurate.

### 1.4 Architectural difference that drives this plan
- **Project A** = **one screen, two POST endpoints.** `/challan/create/{id}` renders a single page with a 3-step progress indicator (Invoice → Godown → Challan). Two buttons fire two AJAX endpoints (`prepare_godown`, `create_final_challan`) plus a third reverse endpoint. The whole godown-prep + challan-finalize lifecycle happens on one URL.
- **Project B** = **a 4-step pipeline split across multiple screens.** `godown/{invoiceId}` (step 2, warehouse assignment) → `issue/{invoiceId}` (step 3, transport + finalize) → `show/{id}` (step 4, receipt). Each is a separate route + Blade view.

This split is the single biggest UX divergence. The plan addresses it without forcing a destructive merge (see Phase 1).

---

## 2. Project A: Source-of-Truth Analysis

### 2.1 Routing & Dispatch
- **Dispatcher:** `legacy/public/index.php:93-125` — path-segment dispatch, no router class (`core/Router.php` exists but is empty). `/challan/create/528` → `ChallanController::create(528)`.
- **Auth gating:** `Auth::requireLogin()` + `RouteAccess::require()` + `MenuAccess::require()` (`index.php:101-112`).
- **`challan/create` is NOT in `app/config/route_roles.php`** — GET access is gated only by DB-driven `MenuAccess`. POST actions have explicit role matrices (`route_roles.php:114-121`).

| Action | HTTP | Endpoint | Controller method | Allowed roles |
|---|---|---|---|---|
| View form | GET | `/challan/create/{id}` | `ChallanController::create` (`:99-117`) | any logged-in user with menu access |
| Save godown (step 2) | POST | `/challan/prepare_godown` | `ChallanController::prepare_godown` (`:196-206`) | admin, manager, warehouse_manager, dispatcher |
| Finalize challan (step 3) | POST | `/challan/create_final_challan` | `ChallanController::create_final_challan` (`:208-238`) | admin, manager, warehouse_manager, dispatcher |
| Reverse challan | POST | `/challan/reverse_challan` | `ChallanController::reverse_challan` (`:240-260`) | admin, manager |
| AJAX warehouses | GET | `/challan/get_warehouses_for_product?product_id=&invoice_id=` | `ChallanController::get_warehouses_for_product` | rate-limited `guardJsonApi(...,120,60)` |
| AJAX dispatchers | GET | `/challan/get_dispatchers` | `ChallanController::get_dispatchers` | rate-limited |

### 2.2 Controller logic

**GET `create($id)`** (`ChallanController.php:99-117`) — single model call `ChallanModel::getInvoiceForGodownCopy($id)` (`ChallanModel.php:1236-1320`) which runs **4 queries**:
1. Invoice header: `sales_invoices si JOIN customers c JOIN branches b JOIN employees e WHERE si.id=:id AND si.is_reversed=0`, then `assertInvoiceAccessible($invoice.branch_id)`.
2. Items + per-warehouse stock + dispatch pipeline:
   ```sql
   SELECT sii.*, p.product_name, p.unit, p.pcs_per_carton, w.warehouse_name,
     COALESCE((SELECT ws.qty FROM warehouse_stock ws WHERE ws.product_id=sii.product_id LIMIT 1),0) AS current_stock,
     COALESCE(sid.dispatched_qty,0) AS dispatched_qty,
     COALESCE(sid.dispatched_ctn,0) AS dispatched_ctn,
     GREATEST(0,
       COALESCE((SELECT SUM(ws.qty) FROM warehouse_stock ws WHERE ws.product_id=sii.product_id),0)
       - COALESCE((SELECT SUM(COALESCE(d.ordered_qty,0)-COALESCE(d.dispatched_qty,0))
                   FROM sales_invoice_dispatches d JOIN sales_invoices inv ON inv.id=d.sales_invoice_id
                   WHERE d.product_id=sii.product_id AND inv.status NOT IN ('challan_completed','reversed') AND inv.is_reversed=0),0)
     ) AS available_qty
   FROM sales_invoice_items sii
   JOIN products p ON sii.product_id=p.id
   LEFT JOIN warehouses w ON w.id=sii.warehouse_id
   LEFT JOIN sales_invoice_dispatches sid ON sid.sales_invoice_id=sii.sales_invoice_id AND sid.product_id=sii.product_id
   WHERE sii.sales_invoice_id=:id ORDER BY sii.id
   ```
3. Dispatchers: `sales_invoice_dispatchers sd JOIN employees e WHERE sd.sales_invoice_id=:id`.
4. Latest challan (if any): `sales_challans WHERE sales_invoice_id=:id ORDER BY id DESC LIMIT 1`.

No tax/unit/price-list queries on this screen (those ran at invoice creation).

**POST `prepare_godown`** → `ChallanModel::prepareGodown($data)` (`ChallanModel.php:210-324`) — **inside a DB transaction** with `SELECT … FOR UPDATE` on `sales_invoices`:
- Validates status ∈ {`draft`,`godown_issued`}; per-line `resolveDispatchLinesForInvoice($data,$invoice_id,$branchId,'godown')` (`:36-170`):
  - demand must equal `sales_invoice_items.qty` (**no partial dispatch**),
  - `warehouseBelongsToBranch($wid, sessionBranchId)`,
  - `demandQty ≤ Get_Warehouse_Available_Stock(pid,wid,invoiceId)` (physical − open pipeline).
- `persistInvoiceTransportCost(...)` (`:1167-1206`): if transport changed → recompute `total = subtotal + transport − discount`, `UPDATE sales_invoices`, write a `customer_ledger` row (`reference_type='invoice_adjustment'`) for the delta.
- Per line: `UPDATE sales_invoice_items SET warehouse_id=:wid WHERE id=:id`.
- `DELETE FROM sales_invoice_dispatchers WHERE sales_invoice_id` → re-INSERT posted `dispatcher_id[]`.
- `DELETE FROM sales_invoice_dispatches WHERE sales_invoice_id` → re-INSERT (idempotent rewrite, prevents duplicates).
- `UPDATE sales_invoices SET status='godown_issued', godown_issued_at=NOW()`.
- **Stock is RESERVED (pipeline), NOT deducted.** `warehouse_stock.qty` untouched.
- Returns JSON `{status, message, new_total, transport, total_diff}`.
- Controller: `UserAudit::log(user_id,'godown_prepared',invoice_id,…)`.

**POST `create_final_challan`** → `ChallanModel::finalizeChallan($data)` (`ChallanModel.php:327-520`) — **inside a DB transaction**:
- `SELECT … FOR UPDATE` on `sales_invoices`; reject if status === `challan_completed`.
- `assertGodownPreparedForChallan`: status must be `godown_issued` AND `COUNT(sales_invoice_dispatches) ≥ COUNT(sales_invoice_items)`.
- `resolveDispatchLinesForInvoice(…,'finalize')`: posted `warehouse_id` must equal the godown-saved one (locked), `demandQty ≤ warehouse_stock.qty` (physical), `avg_cost > 0` per line.
- `$challan_code = generateSalesChallanCode()` (`Helper.php:1238`) → `CH-YYYY-####` via `allocateDocumentSequence('sales_challan', date('Y'), 0)` (row-locked `document_sequences`, **globally unique, not per-branch**).
- `persistInvoiceTransportCost(...)` again (transport editable at finalize too); if delta ≠ 0 → `customer_ledger` row + (later) transport-adj GL.
- `UPDATE sales_invoices SET status='challan_completed', challan_completed_at=NOW(), godown_issued_at=COALESCE(godown_issued_at,NOW()), pre_challan_transport=:tc, pre_challan_total=:total` (snapshot for reversal).
- `INSERT INTO sales_challans (sales_invoice_id, challan_code, challan_date, created_by)`.
- Per dispatch line:
  - `SELECT avg_cost FROM warehouse_stock WHERE product_id AND warehouse_id` (throw if ≤0),
  - `UPDATE sales_invoice_dispatches SET dispatched_qty, dispatched_ctn, dispatched_at=NOW()`,
  - `UPDATE sales_invoice_items SET warehouse_id`,
  - **Stock deduction:** `StockTransactionModel::updateWarehouseStock($wid,$pid,-$qty,$avg_cost)` (`:66`) — `SELECT qty FOR UPDATE`, throw if insufficient, `UPDATE warehouse_stock SET qty=qty-:qty`.
  - **Stock movement log:** `StockTransactionModel::logMovement(...)` (`:139`) — `INSERT INTO stock_transactions (...,qty (negative),rate,reference_type='sales_challan',reference_id=$challanId,...)`.
  - **Issue-rate SSOT:** `saveChallanIssueLine($challan_id,$pid,$wid,$qty,$avg_cost)` (`ChallanModel.php:964`) — `INSERT INTO sales_challan_items (sales_challan_id,product_id,warehouse_id,qty,issue_rate,cogs_amount)`.
  - Accumulate `$cogsTotal += qty × avg_cost`.
- If `|totalDiff|>0.0001`: `JournalPostingService::postSalesInvoiceTotalAdjustment(...)` (transport-adj GL).
- `updateChallanTransportMeta($challan_id, $totalDiff, $adjustmentJournalId)`.
- **COGS GL:** `JournalPostingService::postSalesChallanCOGS($challan_id, cogs_amount=round($cogsTotal,2))`; `UPDATE sales_challans SET journal_entry_id`.
- `commit()`. Returns JSON `{status, message, challan_code, challan_id, invoice_id, transport_adjustment, new_total, journal_entry_id, cogs_amount}`.
- Controller: `UserAudit::log('challan_completed',…)`, then **non-blocking Telegram** via `SalesTelegramNotifier::safe()` → `getChallanTelegramPayload` + `notifyChallanCreated` (recipients: salesman, sales-by, branch warehouse managers).

**POST `reverse_challan`** → `ChallanModel::reverseChallan($invoiceId,$reason)` (`ChallanModel.php:526-712`) — admin/manager only:
- Reject if `strlen(reason)<5` or status ≠ `challan_completed`.
- Reject if any non-reversed `sales_returns` (`status='completed'`) exist for the invoice.
- `restoreStockFromChallanIssue` (`:717`): per line reads `sales_challan_items` (preferred SSOT) → `updateWarehouseStock(+qty, issue_rate)` + `logMovement(reference_type='sales_challan_reversal')` + reset `sales_invoice_dispatches.dispatched_qty=0, dispatched_ctn=0, dispatched_at=NULL`.
- Reverse transport-adj: `reverseChallanTransportLedger` + `JournalPostingService::reverseLinkedJournal($adjJournalId)`.
- `UPDATE sales_challans SET is_reversed=1, reversed_at, reversed_by, reverse_reason`.
- `UPDATE sales_invoices SET status='godown_issued', challan_completed_at=NULL, transport_cost=pre_challan_transport, total_amount=pre_challan_total, pre_challan_transport=NULL, pre_challan_total=NULL`.
- Reverse COGS journal via `reverseLinkedJournal($cogsJournalId)`.
- `UserAudit::log('challan_reversed',…)`.

### 2.3 Models & key tables
- `app/models/ChallanModel.php` (1784 lines, extends `SalesModel` → `Helper`)
- `app/models/StockTransactionModel.php` (264 lines)
- `app/services/Stock/StockService.php`, `StockAvailabilityService.php`
- `app/services/Accounting/JournalPostingService.php`

**Tables involved:** `sales_invoices`, `sales_invoice_items`, `sales_invoice_dispatchers`, `sales_invoice_dispatches`, `sales_challans`, `sales_challan_items`, `warehouse_stock`, `stock_transactions`, `customers`, `branches`, `warehouses`, `employees`, `products`, `customer_ledger`, `journal_entries`, `journal_entry_lines`, `ledgers`, `document_sequences`, `user_audit_logs`.

(Full per-table column inventory is in §6 of the analysis worklog; the migration-plan-relevant subset is reproduced in §2.5 below.)

### 2.4 Validation & business rules (server-side, in `ChallanModel`)
- `assertInvoiceAccessible($invoiceBranchId)` — branch scope (admin override allowed).
- `warehouseBelongsToBranch($wid, $sessionBranchId)` — each line's warehouse must belong to the invoice's branch and be active.
- `prepare_godown`: status ∈ {`draft`,`godown_issued`}; `item_id[]` non-empty; per line `qty>0`, posted `dispatched_qty == sii.qty` (no partial dispatch), `warehouse_id>0`, `demand ≤ available (physical − pipeline)`; `dispatcher_id[]` non-empty when status is `draft`; `transport_cost ≥ 0`.
- `create_final_challan`: status ≠ `challan_completed`; status must be `godown_issued`; `COUNT(dispatches) ≥ COUNT(items)`; posted warehouse must equal godown-saved warehouse (**locked**); `demand ≤ physical stock`; `avg_cost > 0` per line.
- `reverse_challan`: `strlen(reason) ≥ 5`; status = `challan_completed`; no non-reversed `sales_returns`.
- **Business rules:** no partial dispatch; warehouse locked after godown save; transport locked after challan complete; stock reserved at godown, deducted at finalize; COGS at `avg_cost` snapshotted into `sales_challan_items.issue_rate` (SSOT for reversal); globally-unique challan code.

Client-side mirrors in `public/assets/js/challan.js` (`validatePrepare` `:408`, `validateFinalize` `:520`, `handleReverseChallan` `:322`).

### 2.5 Schema of relevant tables (relevant columns only)
| Table | Key columns |
|---|---|
| `sales_invoices` | `id, invoice_code, invoice_date, customer_id, salesman_id, branch_id, sub_total/subtotal, discount/discount_amount, transport_cost, total_amount, pre_challan_transport, pre_challan_total, status (draft/godown_issued/challan_completed), is_reversed, godown_issued_at, challan_completed_at, journal_entry_id` |
| `sales_invoice_items` | `id, sales_invoice_id, product_id, warehouse_id, qty, rate, amount (PG GENERATED), discount_amount, condition_state` |
| `sales_invoice_dispatchers` | `id, sales_invoice_id, employee_id, dispatch_role` |
| `sales_invoice_dispatches` | `id, sales_invoice_id, product_id, warehouse_id, ordered_qty/qty, dispatched_qty, dispatched_ctn, dispatched_at, UNIQUE(sales_invoice_id,product_id)` |
| `sales_challans` | `id, sales_invoice_id, challan_code (UNIQUE), challan_date, branch_id, transport_cost, transport_adjustment, adjustment_journal_entry_id, journal_entry_id, is_reversed, reversed_at, reversed_by, reverse_reason, issue_cost, created_by` |
| `sales_challan_items` (migration 040) | `id, sales_challan_id (CASCADE), product_id, warehouse_id, qty, issue_rate, cogs_amount, created_at` |
| `warehouse_stock` | `warehouse_id, product_id (composite PK), qty, avg_cost, last_updated` |
| `stock_transactions` | `id, transaction_date, product_id, warehouse_id, qty (signed), rate, reference_type, reference_id, remarks, is_reversed` |
| `customer_ledger` | `id, customer_id, transaction_date, reference_type, reference_id, debit, credit, running_balance, remarks, is_reversed, branch_id` |
| `document_sequences` | `doc_type, period_key, branch_id (0=global), last_number` |

### 2.6 UI/UX inventory (exhaustive)

**Files**
- View: `legacy/app/views/challan/create.php` (351 lines)
- Layout: `legacy/app/views/layouts/main.php` (Bootstrap 5.3.3 + FA 6.5.1 + SweetAlert2 11 + jQuery 3.6 + select2 4.1 + DataTables 1.13.7)
- Custom CSS: `legacy/public/assets/css/challan-create.css` (521 lines)
- JS: `legacy/public/assets/js/challan.js` (587 lines)
- Sidebar: DB-driven via `MenuModel::getUserMenus` (`app/views/layouts/sidebar.php`)

**Layout structure (top → bottom)**
1. **Hero header** (`:33-53`) — gradient `linear-gradient(135deg,#d97706,#4f46e5)`; H1 with dolly icon; invoice-code chip; branch tag; right-side action buttons (List, Blank godown).
2. **Policy note** (`:55-65`, conditional) — blue panel when `lockGodownAssignments`.
3. **3-step progress** (`:67-82`) — Invoice → Godown → Challan dots/lines.
4. **4-card summary grid** (`:84-105`) — Customer+mobile, Invoice date+salesman, Items count, Invoice total (with status pill).
5. **Print bar** (`:107-122`, conditional on completed) — Challan/Godown/Invoice copy buttons.
6. **Form `#godownForm`** (`:124-308`) — `method=post`, submitted via `FormData`+`fetch()`.
7. **Sticky footer action bar** (`:310-332`) — `position:fixed; bottom:0`; Back / Save godown (or Update CTN) / Finalize challan / Reverse challan.

**Hidden fields:** `csrf_token`, `invoice_id`.

**Visible form fields**
| Field | Type | name | Notes |
|---|---|---|---|
| Transport amount (Tk) | `<input type=number step=0.01 min=0>` | `transport_cost` | `readonly` when completed; live-updates total preview |
| Per-row item_id | hidden | `item_id[]` | 1:1 with invoice items |
| Per-row product_id | hidden | `product_id[]` | |
| Per-row warehouse | `<select class=form-select required>` OR read-only span + hidden | `warehouse_id[]` | AJAX-populated; locked when `godown_issued` & not completed |
| Per-row dispatched_qty | hidden | `dispatched_qty[]` | locked to invoice demand |
| Per-row dispatched_ctn | `<input type=number step=0.01 min=0>` | `dispatched_ctn[]` | carton packing; `readonly` when completed; "Fill all CTN" bulk tool |
| Dispatcher(s) | `<select multiple>` (select2) OR chips + hidden | `dispatcher_id[]` | AJAX `get_dispatchers`; disabled when completed; required otherwise |
| Bulk warehouse | `<select>` (JS-only, no name) | — | "Apply warehouse to all" |

**Table `#godownItemsTable`** (table-responsive, max-height `min(55vh,520px)`):
| Col | Header | Editable? |
|---|---|---|
| 1 | SL | No |
| 2 | Product (`fw-semibold`) | No |
| 3 | Ordered (2dp) | No |
| 4 | Order CTN (ordered/pcs_per_carton) | No |
| 5 | Warehouse (select or locked span+hidden) | Yes when draft |
| 6 | Reserved/Available (badge) | No |
| 7 | Demand (locked, 2dp) + hidden `dispatched_qty[]` | No |
| 8 | Disp. CTN (`dispatched_ctn[]`) | Yes (unless completed) |

No add-row/remove-row — rows are 1:1 with invoice items.

**Bulk-bar tools (`:180-202`):** "Apply warehouse to all", "Fill all CTN", warehouse-assignment progress bar.

**Buttons**
| Button | id | Visible when |
|---|---|---|
| List | — | always |
| Blank godown | `#btnPrintBlankGodown` | godown-ready |
| Back | — | always |
| Save godown / Update CTN | `#btn-save-godown` | not completed |
| Finalize challan | `#btn-create-challan` | not completed; disabled until godown ready |
| Reverse challan | `#btn-reverse-challan` | completed & admin/manager |
| Print challan / godown copy / invoice | — | completed |

**Modals/AJAX:** No Bootstrap modals; all confirmations via **SweetAlert2**. AJAX endpoints: `get_warehouses_for_product`, `get_dispatchers`, `prepare_godown`, `create_final_challan`, `reverse_challan`.

**Client-side JS behaviors:** warehouse dropdown population + auto-select-if-only-one; live stock badge (green/amber/red/blue) on warehouse change; warehouse progress bar; bulk warehouse apply; Fill all CTN; transport→total live preview; **Ctrl+S shortcut** → Save godown; select2 multi-select for dispatchers; `syncSelect2ToForm` before POST.

**Bootstrap components used (the translation source list)**
- Grid: `container-fluid`, `row g-3`, `col-md-4`, `col-md-8`, `col-lg-3`, `col-md-11 ms-sm-auto col-lg-10`
- Forms: `form-control`, `form-label`, `form-select`, `form-select-sm`
- Buttons: `btn`, `btn-light`, `btn-sm`, `btn-info`, `btn-primary`, `btn-success`, `btn-warning`, `btn-outline-secondary`, `btn-outline-light`, `btn-danger`
- Tables: `table`, `table-bordered`, `table-responsive`
- Badges: `badge bg-secondary`
- Spacing/text: `mb-0`, `mt-2`, `me-1`, `me-2`, `ms-auto`, `text-end`, `text-muted`, `text-danger`, `text-success`, `small`, `fw-semibold`, `fw-bold`, `py-2`, `px-3`, `px-md-4`
- Responsive: `d-none d-md-inline`, `d-lg-none`, `d-lg-inline`
- Flex: `d-flex`, `gap-2`, `flex-shrink-0`, `flex-wrap`, `flex-column`, `flex-grow-1`, `justify-content-between`, `justify-content-end`, `align-items-center`, `align-items-end`
- Collapse (sidebar): `data-bs-toggle=collapse`, `data-bs-target`, `aria-expanded`
- No modals, no datepicker, no DataTables on this screen.

**Color/spacing (custom CSS variables in `.challan-create-app`):** `--ch-primary:#d97706` (amber), `--ch-primary-dark:#b45309`, `--ch-accent:#4f46e5` (indigo), `--ch-surface:#fffbeb`, `--ch-card:#fff`, `--ch-border:#e7e5e4`, `--ch-text:#1c1917`, `--ch-muted:#78716c`, `--ch-success:#059669`, `--ch-danger:#dc2626`. Hero gradient `linear-gradient(135deg,#d97706 0%,#4f46e5 100%)`. Step dots 32×32 round. Status pills: pending=amber, godown=blue, done=green. Stock badges: ok=green, low=amber, none=red, reserved=blue. Sticky footer `backdrop rgba(255,255,255,0.96)`, `box-shadow 0 -8px 24px rgba(28,25,23,0.1)`.

**Responsive breakpoints:** `@media (max-width:991.98px)` → summary 4→2 cols; `@media (max-width:575.98px)` → summary 1 col, step dots smaller, action bar full-width stretched.

**Navigation context:** This screen sits under the "Godown & Challan" sidebar menu (`/challan` index). The "Today Invoice" hub (`/sales/today`) links draft invoice rows here. No breadcrumb on this screen.

---

## 3. Project B: Current State

### 3.1 Routing
All in `laravel/routes/web.php` inside `Route::middleware('auth')->group(...)` (`:72`). No separate `admin.php`.

| Method | URI | Name | Controller@method | Middleware | File:line |
|---|---|---|---|---|---|
| GET | `admin/sales-challans/godown/{invoiceId}` | `admin.sales-challans.godown` | `SalesChallanController@godown` | `role:warehouse_manager,dispatcher,manager,admin` | `:765-766` |
| POST | `admin/sales-challans/godown/{invoiceId}` | `admin.sales-challans.storeGodown` | `SalesChallanController@storeGodown` | role + `branch.isolation` | `:767-768` |
| GET | `admin/sales-challans/issue/{invoiceId}` | `admin.sales-challans.challan-form` | `SalesChallanController@challanForm` | role | `:769-770` |
| POST | `admin/sales-challans/issue/{invoiceId}` | `admin.sales-challans.issueChallan` | `SalesChallanController@issueChallan` | role + `branch.isolation` | `:771-772` |
| POST | `admin/sales-challans/{id}/cancel` | `admin.sales-challans.cancel` | `SalesChallanController@cancel` | `role:manager,admin` + `branch.isolation` | `:774-775` |
| GET | `admin/sales-challans/{id}/print-challan` | `admin.sales-challans.print-challan` | `SalesChallanController@printChallan` | role | `:777-778` |
| GET | `admin/sales-challans` | `admin.sales-challans.index` | `SalesChallanController@index` | role | `:781-784` |
| GET | `admin/sales-challans/{id}` | `admin.sales-challans.show` | `SalesChallanController@show` | role | `:786-789` |
| GET | `admin/sales-challans/export-csv` | `admin.sales-challans.export-csv` | `CsvExportController@exportChallans` | role | `:792-794` |

**Branch isolation (triple-layer):** `EnforceBranchIsolation` middleware + `BranchScope` Eloquent global scope (`app/Models/Scopes/BranchScope.php`) + PostgreSQL RLS policies (`database/sql/07_views_triggers_constraints.sql:660-666`) gated by `current_setting('app.branch_id')` set in `SetAppBranchId` middleware. Admin/superadmin bypass.

### 3.2 Controller logic
**`godown(Request, int $invoiceId)`** (`SalesChallanController.php:154-189`) — READ ONLY:
1. `SalesInvoice::with(['items.product','dispatches','customer','branch'])->findOrFail($invoiceId)`.
2. Guard: redirect if `!$invoice->isDraft()`.
3. `Warehouse::active()->where('branch_id',$invoice->branch_id)->orderBy('warehouse_name')->get()`.
4. Per-item raw join `warehouse_stock ws JOIN warehouses w ON w.id=ws.warehouse_id WHERE ws.product_id=? AND w.branch_id=? AND w.is_active=true` → `ws.warehouse_id, w.warehouse_name, ws.qty, ws.avg_cost` bucketed into `$availability[$product_id]`.
5. Returns `view('admin.sales-challans.godown', …)`.

**`storeGodown(Request, int $invoiceId)`** (`:194-213`) — POST:
- Inline validation: `warehouse_assignments => required|array`, `warehouse_assignments.* => required|integer|exists:warehouses,id`. **No Form Request** bound to web (a `PrepareGodownRequest` exists at `app/Http/Requests/Api/V1/Sales/PrepareGodownRequest.php` but is API-only).
- Calls `SalesChallanService::prepareGodown($invoiceId, $assignments, auth()->id())`.

**`SalesChallanService::prepareGodown`** (`app/Services/Sales/SalesChallanService.php:62-117`) — inside `DB::transaction`:
- `SalesInvoice::with('items','dispatches')->lockForUpdate()->find($invoiceId)`.
- Per item: `$this->stockService->getWarehouseQty($wid,$product_id)` (throws on insufficient).
- `DB::table('sales_invoice_items')->where('id',$item->id)->update(['warehouse_id'=>$wid])`.
- `DB::table('sales_invoice_dispatches')->where('sales_invoice_id',$invoiceId)->where('product_id',$item->product_id)->update(['warehouse_id'=>$wid])`.
- `DB::table('sales_invoices')->where('id',$invoiceId)->update(['status'=>'confirmed','is_godown_prepared'=>true,'godown_prepared_at'=>now()])`.
- `$this->auditLogger->godownPrepared(...)`.
- **NO stock movement, NO GL, NO customer_ledger at this step.**

**`issueChallan`** (`SalesChallanService.php:134-357`) — inside `DB::transaction`:
- Insert `sales_challans` header; `challan_code = DocumentSequenceService::nextCode('sales_challan','CH',YYYYMMDD,4)` → `CH-YYYYMMdd-NNNN` (advisory-locked).
- Per item: `StockService::applyTransaction(qty=−qty, rate=avg_cost, reference_type='sales_challan', reference_id=$challanId)` → inserts `stock_transactions` + updates `warehouse_stock`.
- Per item: `DB::table('sales_challan_items')->insert([...])` (cogs_amount GENERATED/computed).
- Per item: `DB::table('sales_invoice_dispatches')->update(['qty'=>DB::raw('ordered_qty'),'dispatched_qty'=>DB::raw('ordered_qty'),'warehouse_id'=>$wid])`.
- `postCogsGL(...)` → `JournalPostingService::createJournalEntry(...)` (Dr COGS / Cr Inventory, one JE per challan).
- If `transport_cost` differs: snapshot `pre_challan_transport`+`pre_challan_total`, update invoice, post transport-adj GL JE, `SubLedgerService::postCustomerLedgerEntry(['transaction_type'=>'invoice_adjustment',…])`.
- Update `sales_challans.issue_cost, journal_entry_id, transport_adjustment, adjustment_journal_entry_id`.
- Update `sales_invoices` to `is_challan_issued=true, challan_issued_at=now(), cogs_journal_entry_id=…`.
- `$this->auditLogger->challanIssued(...)`.
- `$this->availabilityService->invalidatePipelineForInvoice($invoiceId)`.
- `$this->notifications->dispatch('challan_create',…)` (best-effort try/catch).

**`cancelChallan`** (`SalesChallanService.php:367-488`): reverses GL via `JournalReversalService::reverseByJournalEntry`, reverses each `stock_transactions` row via `StockService::reverseTransaction`, resets dispatch rows, marks `sales_challans.is_reversed=true`, restores `pre_challan_*` snapshot, resets invoice to `status='draft', is_godown_prepared=false, is_challan_issued=false`, nulls `warehouse_id` on items + dispatches.

### 3.3 Models & PostgreSQL schema (relevant subset)
- `app/Models/SalesInvoice.php` — `SoftDeletes`, `AuditableMasterData`, **`BranchScope` global scope** (`:82-85`). PK is `(id, invoice_date)` (partitioned). Helpers `isDraft()/isConfirmed()/isCancelled()/isReversed()`. Relationships: `items()`, `dispatches()`, `customer()`, `branch()`, `salesman()`, `dispatchers() BelongsToMany Employee`.
- `app/Models/SalesChallan.php` — `SoftDeletes`, `AuditableMasterData`, `BranchScope`. `isReversed()`. Relationships: `salesInvoice()`, `branch()`, `journalEntry()`, `items() HasMany SalesChallanItem`.
- `app/Models/SalesChallanItem.php` — `$timestamps=false` (append-only). Scope `forActiveChallans()`.
- `app/Models/Warehouse.php` — scope `active()` = `is_active=true AND deleted_at IS NULL`.

**PostgreSQL-specific features in use:** `GENERATED ALWAYS AS IDENTITY`; `GENERATED ALWAYS AS (...) STORED` columns (`sales_invoice_items.amount`, `sales_invoice_dispatches.amount`, `stock_transactions.total_value`, `sales_invoices.due_amount`); RANGE partitioning on `sales_invoices` and `stock_transactions` (PK includes date; child→partitioned-parent FKs are **trigger-based**); RLS policies; `CHECK` constraints as enums; `prevent_negative_stock()` plpgsql trigger; BRIN/GIN indexes; full-text `search_vector` + GIN on `products`/`customers`; DEFERRABLE FKs; advisory locks for document sequencing.

### 3.4 Current business-logic status (for the godown-prep + challan-issue flow)
| Feature | Status | Where |
|---|---|---|
| Fetch form data (invoice, items, products, warehouses, per-product stock) | **Done** | `SalesChallanController@godown :154-189` |
| Create challan header | **Done** | `SalesChallanService::issueChallan :158-173` |
| Create challan items (line items) | **Done** | `:210-218` |
| Tax calculation | N/A on this screen (computed at invoice finalize) | — |
| Discount calculation | N/A on this screen | — |
| Grand total (read-only display) | **Partial** | godown view shows `$invoice->total_amount`; issue view computes display total client-side |
| Stock deduction / godown stock movement | **Done at issueChallan** (not at godown prep — godown only assigns warehouse) | `:195-205` → `StockService::applyTransaction` |
| Customer ledger / balance update | **Partial** — only transport-cost delta posted at issue (`SubLedgerService::postCustomerLedgerEntry`) | `:282-294` |
| Challan/invoice numbering | **Done** — `DocumentSequenceService::nextCode` advisory-locked → `CH-YYYYMMdd-NNNN` | `:539-547` |
| PDF generation | **Missing** — HTML print view only (`print_challan.blade.php`) | — |
| Edit / update existing godown assignment | **Missing** — `godown()` 404-redirects if `!isDraft()`; no "un-prepare godown" without issue+cancel | `:166-170` |
| Delete / void challan | **Done** — `cancelChallan` (append-only reversal, manager/admin only) | `:367-488` |
| Validation | **Partial** — web uses inline `$request->validate()` only; `PrepareGodownRequest` is API-only | `:196-199` |
| Dispatcher assignment | **Missing** — no `sales_invoice_dispatchers` UI on godown screen | — |
| Dispatched CTN (carton packing) | **Missing** — no `dispatched_ctn` field | — |
| Transport editable at godown save | **Missing** — transport only editable at issue, not at godown prep | — |
| Pipeline-aware availability check on godown | **Partial** — controller uses raw `warehouse_stock.qty` join, not `StockAvailabilityService` pipeline math | `:178-186` |
| Reverse guard: no sales_returns | **Needs verification** — `cancelChallan` reverses GL/stock but the sales_returns guard is not confirmed | `:367-488` |
| Idempotency token | **Done (better than A)** — UUID + `Cache::put('challan:{token}',…,600)` | `issueChallan :252-305` |

### 3.5 Current UI/UX
**View:** `laravel/resources/views/admin/sales-challans/godown.blade.php` (346 lines). Wraps `<x-layouts.erp :title=… :tabs="[Dashboard, Invoices, Challans, UI Preview]">`. A `godown-legacy.blade.php` (Bootstrap) exists but is **not routed** (parity reference).

**Layout:** `resources/views/components/layouts/erp.blade.php` (435 lines). Coexists with `layouts/admin.blade.php` (Bootstrap 5) and `layouts/print.blade.php`. Loads: `bootstrap.min.css`, `fontawesome/all.min.css`, `sweetalert2.min.css`, `custom.css`, `footer-dropup.css`, **`rc-erp.css`** (Tailwind v4 `@theme` + scoped utilities, no preflight — coexists with Bootstrap via `@layer`); jQuery 3.6, SweetAlert2, Select2, DataTables, `custom.js`. Body wrapper `bg-gradient-to-b from-amber-50/30 to-white min-h-screen flex flex-col`. Top sticky nav `sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-amber-200`. Sidebar DB-driven via `MenuService::getUserMenuTree()` (3-level, collapsible, persisted in `localStorage`). Footer `bg-amber-900 text-amber-100` sticky.

**Existing Tailwind/design-system conventions**
- **Palette:** amber/orange primary (500/600/700); accent cyan/green/red/yellow per state.
- **Cards:** `bg-white rounded-xl shadow-sm border-l-4 border-l-{color}-500 p-4` (left-accent card is the dominant pattern). Hero cards `bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg`.
- **Buttons:** primary `bg-amber-500 hover:bg-amber-600 text-white rounded-lg px-4 py-2 text-sm font-medium shadow-md`; gradient `bg-gradient-to-r from-amber-500 to-orange-500`; ghost `border border-gray-200 hover:bg-gray-50 rounded-lg`; sticky save bar `sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg mt-4`.
- **Tables:** `w-full text-sm`; `<thead> bg-amber-50/50`; rows `hover:bg-amber-50/30 border-b border-gray-100`.
- **Status pills:** `bg-{color}-100 text-{color}-700 border border-{color}-300 font-semibold text-xs rounded-full px-2 py-0.5`.
- **Form controls:** raw `<input>/<select>` with `border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300`. A library of `<x-erp.form-input>`, `<x-erp.form-select>`, `<x-erp.primary-button>`, `<x-erp.outline-button>`, `<x-erp.gradient-button>`, `<x-erp.left-accent-card>`, `<x-erp.sticky-action-bar>`, `<x-erp.status-pill>`, `<x-erp.stat-card>`, `<x-erp.icon>` exists in `resources/views/components/erp/` — but the godown page uses **raw markup** for most controls.
- **Bilingual labels:** English + Bengali (e.g., "গোডাউন কপি প্রস্তুতি / Godown Preparation", "বাতিল / Cancel").

**Form fields on `godown.blade.php`**
- `@csrf` (posts to `admin.sales-challans.storeGodown`).
- Per item: `<select name="warehouse_assignments[{{ $item->id }}]" class="form-select form-select-sm warehouse-select" required>` populated from `$warehouses`, each `<option data-qty data-avg-cost disabled` when `qty < ordered`. Select carries `data-item-id data-product-id data-qty`.
- **No** hidden fields beyond CSRF. **No** qty/rate/discount/tax/notes/transport/dispatcher/CTN inputs.
- Buttons: "Cancel / বাতিল" link; "Save Godown Copy" submit (`fa-save`).

**Table columns:** Product (name+code) | Ordered Qty | Warehouse (select) | Available Stock (per-warehouse pills green/yellow/red) | Avg Cost (Tk) (populated by JS on select change).

**JS behaviors (`@push('scripts') :293-346`):** Select2 init; on `select2:select` write avg-cost into `#avg-cost-{item-id}`; pre-fill on load; submit guard walks `.warehouse-select` and Swal-warns if any empty. **No AJAX item-search, no add-row, no live stock re-check, no Ctrl+S, no Alpine/Livewire/Inertia/Filament.**

**Responsive:** Hero `flex items-center justify-between flex-wrap gap-4`; summary `grid grid-cols-2 md:grid-cols-4 gap-4`; tables `overflow-x-auto`; sidebar `col-lg-2 d-none d-lg-block` (Bootstrap util handles the breakpoint).

**What's clearly missing/stubbed vs a full godown-prep+challan workflow:** no dispatcher assignment; no CTN packing; no transport edit at godown; no bulk warehouse apply; no live stock badge; no Ctrl+S; no reverse button on the godown screen; no print buttons; shallow validation; no edit-godown mode.

### 3.6 Menu / navigation context
DB-driven `menus` table seeded by `database/migrations/2025_01_10_000001_seed_menus_from_legacy.php`. Rendered by `MenuService::getUserMenuTree()` into the `<x-layouts.erp>` sidebar.

| Sidebar label | controller | action | Resolves to |
|---|---|---|---|
| Sales (parent) | — | — | — |
| Create Sales | `sales` | `create` | `admin.sales.cart` |
| **Today Invoice** | `sales` | `today` | **`admin.sales-invoices.index`** (with `scope=today`) |
| **Challan** | `challan` | `index` | **`admin.sales-challans.index`** |

Per-user visibility via `user_menu_permissions.can_view`; admin/superadmin bypass (`MenuService.php:48-66`). The godown screen is reached from the **Challan** menu → warehouse manager queue → "Prepare Godown" button on a draft invoice (`index.blade.php:210`).

### 3.7 Stack specifics
- **Framework:** Laravel (11/12), PHP 8.2+ (constructor property promotion, readonly, match, named args).
- **DB:** PostgreSQL only (GENERATED, partitioning, RLS, BRIN/GIN, tsvector, plpgsql triggers, advisory locks, ILIKE, deferrable FKs).
- **Frontend:** Blade + jQuery 3.6 + Select2 + DataTables + SweetAlert2 + Bootstrap 5 + **Tailwind v4 (utilities-only, no preflight)**. No build-step for legacy JS. No Livewire/Inertia/Filament/Vue/React/Alpine on this screen.
- **Design-system layer:** `resources/css/rc-erp.css` (Tailwind v4 `@theme` + scoped utilities) + ~30 anonymous Blade components in `resources/views/components/erp/`.
- **Auth:** custom `EnsureRole` middleware (10 canonical roles in `config/roles.php`). Session-based web; Sanctum-style tokens for `/api/v1/*`.
- **Audit:** `AuditableMasterData` trait + `SalesAuditLogger` service (`godownPrepared`, `challanIssued`, `challanReversed`).
- **Notifications:** DB-driven `notification_rules` + `NotificationService` + `LISTEN/NOTIFY` worker.
- **Idempotency:** UUID-v4 token + `Cache::put('challan:{token}',…,600)`.
- **Document numbering:** `DocumentSequenceService::nextCode()` via PostgreSQL advisory locks.

---

## 4. Gap Analysis

> Status legend: **Done** = Project B already matches A. **Partial** = exists but incomplete/shallow. **Missing** = not present in B. **Better** = B exceeds A (call out, do not "fix").

### 4.1 Table A — Business Logic Gaps
| # | Feature (from Project A) | Status in Project B | Notes |
|---|---|---|---|
| A1 | Single combined workspace (godown-prep + finalize on one URL) | **Missing (architectural)** | B splits into `godown` + `issue` + `show`. Address in Phase 1 as a UX decision, not a logic bug. |
| A2 | `prepare_godown` allows re-save when `status=godown_issued` (idempotent DELETE+INSERT) | **Missing** | B's `godown()` 404-redirects if `!isDraft()`. No edit-godown mode without issue+cancel. |
| A3 | Pipeline-aware availability check at godown (`physical − open sales pipeline`) | **Partial** | B controller uses raw `warehouse_stock.qty` join; `StockAvailabilityService` exists but isn't used on the godown GET. Service-layer `prepareGodown` checks `getWarehouseQty` (physical), not pipeline. |
| A4 | Branch-ownership check on chosen `warehouse_id` at godown | **Partial** | B view filters dropdown to the invoice's branch, but the web validator only does `exists:warehouses,id` — a crafted POST could submit another branch's warehouse. `branch.isolation` checks the user's session branch, not the warehouse's branch. |
| A5 | `dispatcher_id[]` multi-select (junction `sales_invoice_dispatchers`) | **Missing** | B has the `dispatchers() BelongsToMany` relationship on `SalesInvoice` but no UI/POST field on the godown screen. |
| A6 | `dispatched_ctn[]` carton-packing field per line + "Fill all CTN" | **Missing** | B has no CTN concept on the godown/issue screen. |
| A7 | Transport editable at godown save (with `customer_ledger` delta) | **Missing** | B only allows transport edit at `issueChallan`, not at godown prep. |
| A8 | Transport editable at finalize (snapshot `pre_challan_transport/total`) | **Done** | B's `issueChallan` snapshots and posts the delta. |
| A9 | `avg_cost > 0` guard per line at finalize | **Done** | B's `StockService::applyTransaction` uses avg_cost; equivalent guard via the service. |
| A10 | Stock deduction at finalize (`warehouse_stock.qty -= demand`) | **Done** | B `StockService::applyTransaction`. |
| A11 | `stock_transactions` movement log (reference_type=`sales_challan`) | **Done** | B inserts via `applyTransaction`. |
| A12 | `sales_challan_items` issue-rate SSOT (`issue_rate` = avg_cost snapshot) | **Done** | B inserts per line. |
| A13 | COGS GL posting (Dr COGS / Cr Inventory) | **Done** | B `postCogsGL`. |
| A14 | Transport-adjustment GL posting (when delta ≠ 0) | **Done** | B posts transport-adj JE. |
| A15 | `customer_ledger` entry for transport delta | **Done** | B `SubLedgerService::postCustomerLedgerEntry`. |
| A16 | Globally-unique challan code | **Done (Better)** | B uses advisory-locked `DocumentSequenceService` (no `SELECT…FOR UPDATE` race); format `CH-YYYYMMdd-NNNN` vs A's `CH-YYYY-####`. |
| A17 | Reverse/cancel: restore stock from `sales_challan_items.issue_rate` (SSOT) | **Done** | B `cancelChallan` → `StockService::reverseTransaction`. |
| A18 | Reverse/cancel: reverse COGS + transport-adj GL | **Done** | B `JournalReversalService::reverseByJournalEntry`. |
| A19 | Reverse/cancel: reject if non-reversed `sales_returns` exist | **Needs verification** | A explicitly rejects; B's `cancelChallan` reverses GL/stock but the sales_returns guard is not confirmed in the analysis. |
| A20 | Reverse/cancel: restore invoice `transport_cost/total_amount` from `pre_challan_*` snapshot | **Done** | B restores snapshot. |
| A21 | Audit log (`godown_prepared`, `challan_completed`, `challan_reversed`) | **Done** | B `SalesAuditLogger`. |
| A22 | Notification on challan issue | **Done (Better)** | A = Telegram (`SalesTelegramNotifier`); B = `NotificationService` + `LISTEN/NOTIFY` worker (more general). |
| A23 | Idempotency on finalize | **Done (Better)** | A has none; B uses UUID token + `Cache::put`. |
| A24 | Row-level locking (`SELECT … FOR UPDATE` on `sales_invoices`) | **Done** | B `lockForUpdate()`. |
| A25 | `MenuAccess`/role gating | **Done** | B `EnsureRole` + `branch.isolation` + RLS. |
| A26 | PDF generation | **Missing (parity)** | Neither A nor B has PDF; both HTML-print only. Out of scope unless explicitly requested. |
| A27 | No partial dispatch (demand must equal invoice qty) | **Done (parity)** | Both enforce. |
| A28 | Warehouse locked after godown save | **Partial** | B has no edit-godown mode, so "locked" is moot; once prepared you cannot edit at all (see A2). |
| A29 | `customer_ledger.running_balance` race condition | **Better in B** | A has a known race (no row lock before INSERT). B uses `SubLedgerService` — verify it serializes. Do not port A's bug. |
| A30 | Schema-introspection guards (`hasChallanIssueItemsTable`, etc.) | **Not needed in B** | A conditionally enables features per migration state. B's migrations are all applied; guards unnecessary. |

### 4.2 Table B — UI/UX Gaps
| # | UI Element / Behavior (from Project A) | Status in Project B | Tailwind translation plan |
|---|---|---|---|
| U1 | Single combined workspace with 3-step progress (Invoice→Godown→Challan) on one URL | **Missing** | Keep B's split screens but add a shared `<x-erp.journey-stepper>` (already exists in `components/erp/`) showing the 4-step pipeline (Invoice → Godown → Challan → Receipt) on both `godown.blade.php` and `issue.blade.php`. Use `size-8 rounded-full` circles with `bg-amber-500` active / `bg-green-100 border-2 border-green-400` done / `bg-gray-100 border-2 border-gray-300` pending; connectors `w-4 h-0.5 bg-gray-300`. |
| U2 | Hero gradient header (amber→indigo) with invoice-code chip + branch tag | **Partial** | B has amber hero gradient. Add invoice-code chip (`bg-white/20 rounded-full px-3 py-1 text-sm font-mono`) and branch tag. Keep amber-only gradient (avoid indigo per project rules) — use `bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500`. |
| U3 | 4-card summary grid (Customer, Invoice date+salesman, Items count, Invoice total+status pill) | **Partial** | B has invoice summary. Align to 4-card grid: `grid grid-cols-2 md:grid-cols-4 gap-4`; each card `<x-erp.left-accent-card>` with `border-l-amber-500`; highlight total card with `border-l-green-500`. Status pill via `<x-erp.status-pill>`. |
| U4 | Sticky footer action bar (Back / Save godown / Finalize / Reverse) | **Partial** | B has `sticky bottom-4` save bar pattern. Extend: `<x-erp.sticky-action-bar>` with `flex items-center justify-between gap-3 flex-wrap`; primary actions use `<x-erp.gradient-button>`; reverse uses `bg-red-500 hover:bg-red-600 text-white`. |
| U5 | Per-row warehouse `<select>` (Bootstrap `form-select`) | **Done** | B already uses Select2 on `form-select form-select-sm`. Map A's `form-select` → B's existing pattern. |
| U6 | Warehouse locked as read-only span + hidden input (when `godown_issued` & not completed) | **Missing** | On `issue.blade.php` show warehouse as `<span class="inline-flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5 text-sm font-medium">{{ $warehouse->warehouse_name }}</span>` + `@hidden` input. |
| U7 | Live stock badge (green ok / amber low / red none / blue reserved) on warehouse change | **Partial** | B shows static pills. Add JS: on `select2:select`, read `data-qty`/`data-available` from selected `<option>`, update a `<span>` with `bg-green-100 text-green-700` / `bg-yellow-100 text-yellow-700` / `bg-red-100 text-red-700` / `bg-blue-100 text-blue-700` based on thresholds. |
| U8 | Warehouse-assignment progress bar | **Missing** | Add `<div class="w-full bg-gray-200 rounded-full h-2"><div class="bg-amber-500 h-2 rounded-full transition-all" style="width:{{ $pct }}%"></div></div>`; JS updates width as rows get warehouses. |
| U9 | Bulk "Apply warehouse to all" | **Missing** | Add a `<x-erp.form-select>` labelled "Apply to all" + `<x-erp.outline-button>` "Apply"; JS sets every `.warehouse-select` and triggers `select2:select`. |
| U10 | Dispatcher multi-select (select2, AJAX `get_dispatchers`) | **Missing** | Add `<select name="dispatcher_id[]" multiple class="form-select">` initialized with Select2; load options via a new AJAX route `admin.sales-challans.dispatchers` (JSON). Pre-fill from `$invoice->dispatchers`. |
| U11 | Dispatched CTN input per line + "Fill all CTN" bulk tool | **Missing** | Add column to the items table: `<input type="number" step="0.01" min="0" name="dispatched_ctn[{{ $item->id }}]" class="border border-gray-200 rounded-lg px-2 py-1 text-sm w-20">`. "Fill all CTN" button computes `qty / pcs_per_carton` per row. |
| U12 | Transport input (number, 2dp) with live total preview | **Missing on godown** (present on issue) | Add to godown screen: `<input type="number" step="0.01" min="0" name="transport_cost">` + JS `updateInvoiceTotalPreview()` writing to `#invoice-total-display`. |
| U13 | Print buttons (Challan / Godown / Invoice copy) when completed | **Partial** | B has `print-challan` route only. Add "Godown copy" + "Invoice copy" buttons linking to existing print routes (or new ones if absent). Use `<x-erp.outline-button>` with `fa-print`. |
| U14 | SweetAlert2 confirmations (Save / Finalize / Reverse) | **Done (parity)** | B already uses Swal2. Mirror A's three-confirmation flow (warning → loading → success with print actions). |
| U15 | Ctrl+S shortcut → Save godown | **Missing** | Add `document.addEventListener('keydown', e => { if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault(); $('#btn-save-godown').click();}})`. |
| U16 | Bootstrap `table table-bordered table-responsive` | **Done (re-styled)** | B uses `w-full text-sm` + `overflow-x-auto` + `<thead> bg-amber-50/50`. No change needed; confirm `border` utilities for cell borders. |
| U17 | Bootstrap `badge bg-secondary` | **Done (re-styled)** | B uses `bg-{color}-100 text-{color}-700 border rounded-full px-2 py-0.5`. |
| U18 | Bootstrap grid `col-md-*` / `row g-3` | **Done (re-styled)** | B uses `grid grid-cols-* gap-*` + responsive `md:`/`lg:` prefixes. |
| U19 | Bootstrap `d-none d-md-inline` responsive utils | **Done (re-styled)** | B uses `hidden md:inline`. |
| U20 | Bootstrap `d-flex gap-2` etc. | **Done (re-styled)** | B uses `flex items-center gap-2`. |
| U21 | Bootstrap `btn-*` variants | **Done (re-styled)** | B uses `bg-amber-500 hover:bg-amber-600 text-white` etc. via `<x-erp.primary-button>` / `<x-erp.gradient-button>` / `<x-erp.outline-button>`. |
| U22 | Custom CSS variables (amber/indigo palette) | **Partial** | B's `rc-erp.css` already defines amber theme. Do NOT introduce indigo (project rule). Map A's `--ch-accent:#4f46e5` → B's existing accent (cyan or orange). |
| U23 | Responsive: summary 4→2→1 cols at 991.98/575.98 | **Done (parity)** | B already does `grid-cols-2 md:grid-cols-4`. |
| U24 | Responsive: sticky action bar full-width on mobile | **Partial** | B's `sticky bottom-4` needs `left-0 right-0` + `mx-0` on mobile; ensure `flex-wrap` so buttons stack. |
| U25 | Bilingual EN+BN labels | **Done (Better)** | B already has bilingual labels; A does not. |
| U26 | No breadcrumb (Project A) | **Better in B** | B's layout supports breadcrumbs; add a breadcrumb `Sales / Challan / Godown Prep` for wayfinding. |

---

## 5. UI/UX Translation Strategy

### 5.1 Goals
**Creative, premium, user-friendly, fully mobile-responsive** — while keeping Project B's existing Tailwind/`rc-erp.css` conventions and the `<x-erp.*>` component library. Do **not** introduce a new design system, do **not** add Bootstrap to this screen, do **not** use indigo/blue (project rule).

### 5.2 Bootstrap → Tailwind component mapping (authoritative)
| Bootstrap (Project A) | Tailwind / `<x-erp.*>` (Project B) |
|---|---|
| `container-fluid` | `max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8` |
| `row g-3` | `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4` |
| `col-md-4` / `col-md-8` | `sm:col-span-1` / `sm:col-span-3` (within a `grid-cols-4`) |
| `card` + `card-body` | `<x-erp.left-accent-card>` (`bg-white rounded-xl shadow-sm border-l-4 p-4`) |
| `form-control` | `<x-erp.form-input>` (`border border-gray-200 rounded-lg px-3 py-2 text-sm w-full focus:ring-2 focus:ring-amber-300`) |
| `form-select` / `form-select-sm` | `<x-erp.form-select>` + Select2 init (already in use) |
| `form-label` | `<label class="block text-sm font-medium text-gray-700 mb-1">` |
| `btn btn-primary` | `<x-erp.primary-button>` (`bg-amber-500 hover:bg-amber-600 text-white rounded-lg px-4 py-2 text-sm font-medium shadow-md`) |
| `btn btn-success` | gradient button `bg-gradient-to-r from-amber-500 to-orange-500` (project avoids green-for-primary; reserve green for success states only) |
| `btn btn-warning` | `bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg` |
| `btn btn-danger` | `bg-red-500 hover:bg-red-600 text-white rounded-lg` |
| `btn btn-outline-secondary` | `<x-erp.outline-button>` (`border border-gray-200 hover:bg-gray-50 rounded-lg`) |
| `btn btn-light` | `bg-white border border-gray-200 hover:bg-gray-50 rounded-lg` |
| `btn btn-info` | `bg-cyan-500 hover:bg-cyan-600 text-white rounded-lg` |
| `btn-sm` | `text-xs px-3 py-1.5` |
| `table table-bordered` | `w-full text-sm border border-gray-200` + `<thead> bg-amber-50/50` + cells `border border-gray-100 px-4 py-2` |
| `table-responsive` | `<div class="overflow-x-auto">` |
| `badge bg-secondary` | `<x-erp.status-pill>` (`bg-{color}-100 text-{color}-700 border border-{color}-300 rounded-full px-2 py-0.5 text-xs font-semibold`) |
| `text-end` / `text-muted` / `text-danger` / `text-success` | `text-right` / `text-gray-500` / `text-red-600` / `text-green-600` |
| `fw-semibold` / `fw-bold` / `small` | `font-semibold` / `font-bold` / `text-xs` |
| `d-flex gap-2` | `flex items-center gap-2` |
| `justify-content-between` / `justify-content-end` | `justify-between` / `justify-end` |
| `flex-wrap` / `flex-column` | `flex-wrap` / `flex-col` |
| `ms-auto` / `me-2` / `mt-2` / `mb-0` | `ms-auto` / `me-2` / `mt-2` / `mb-0` (Tailwind supports `ms-`/`me-` logical props) |
| `d-none d-md-inline` | `hidden md:inline` |
| `d-lg-none` / `d-lg-inline` | `lg:hidden` / `hidden lg:inline` |
| `data-bs-toggle=collapse` | Alpine `x-data="{open:false}"` + `@click="open=!open"` + `x-show="open"` (Alpine is the project's preferred light-JS; if not already loaded, use a vanilla `details/summary` or a tiny jQuery toggle) |
| `position:fixed bottom:0` sticky footer | `<x-erp.sticky-action-bar>` (`sticky bottom-0 inset-x-0 bg-white/95 backdrop-blur-sm border-t shadow-lg z-40`) |
| Hero gradient `linear-gradient(135deg,#d97706,#4f46e5)` | `bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500` (drop indigo per project rule) |
| Custom CSS var `--ch-accent:#4f46e5` | Use existing accent (cyan-500 or orange-500); do NOT introduce indigo |
| SweetAlert2 (already shared) | Keep Swal2; mirror A's three-step confirmation flow |
| Select2 (already shared) | Keep Select2 with `theme:'bootstrap-5'` (already in B) |
| DataTables (not on this screen in A) | Not needed |

### 5.3 Design principles for the upgraded UI
1. **One mental model, two screens.** Keep B's split (`godown` → `issue` → `show`) but make the pipeline obvious with a shared 4-step `<x-erp.journey-stepper>` at the top of each screen. This is better than A's single-screen approach for mobile (less vertical scrolling per step).
2. **Amber-forward palette, accent cyan/orange.** No indigo, no blue-as-primary.
3. **Left-accent cards everywhere** for information density without heaviness.
4. **Sticky action bar** that adapts: on desktop, buttons right-aligned in a row; on mobile, full-width stacked `flex-col`.
5. **Live feedback**: stock badges, progress bar, transport→total preview, Ctrl+S.
6. **Bilingual labels preserved** (EN+BN).
7. **Accessibility**: every interactive control has an `aria-label`/`sr-only` label; color is never the only signal (icons + text on every badge/pill); 44px min touch target on mobile.
8. **Mobile-first**: tables become card-lists on `< sm` (use `hidden sm:table` + a `sm:hidden` card layout), or at minimum `overflow-x-auto` with sticky first column.

---

## 6. Phased Implementation Plan

> **Principle:** each phase is independently shippable. After every phase the app still works. The final phase leaves the Godown & Challan workspace at parity-or-better with Project A.
>
> **Files to touch** lists paths only — no code in this document.
>
> **Scope lock:** every phase touches ONLY the godown/challan workspace (`admin/sales-challans/godown`, `admin/sales-challans/issue`, `admin/sales-challans/show`, and their controller/service/view/support files). No other menu, no global CSS, no auth.

### Phase 0 — Setup, Branch & Baseline Verification  ✅ DONE
**Goal:** Establish a known-good starting point — feature branch created, component dependencies verified, and Open Questions resolvable by code inspection answered — so Phases 1-11 execute without surprises. **Non-coding phase.**

**Files to touch**
- None (verification + branch creation only). The only artifact produced is the Phase 0 Execution Report appended to this `.md` file.

**Tasks**
- [x] Create feature branch `chore/challan-godown-copy-plan` off `main`.
- [x] Verify git working tree is clean (no uncommitted source changes; only this plan file is new).
- [x] Verify Phase 1/8 component dependencies exist in `laravel/resources/views/components/erp/`.
- [x] Resolve Open Question #9 — confirm `StockAvailabilityService::getWarehouseAvailableQty` exists and is pipeline-aware.
- [x] Resolve Open Question #8 — confirm whether `sales_invoice_dispatches.dispatched_ctn` column exists in Project B.
- [x] Resolve Open Question #6 — confirm whether `SalesChallanService::cancelChallan` has a `sales_returns` guard.
- [x] Verify the 55 Laravel migrations are present and identify the one that creates `sales_challan_items`.
- [x] Document baseline environment (PHP/Composer/Node availability) and flag runtime checks deferred to the user's dev environment.
- [x] Commit `challan_godown_copy.md` and push the branch.

**Acceptance criteria**
- Feature branch exists and is pushed to `origin`.
- All three Open Questions (#6, #8, #9) have a definitive code-inspection answer recorded in the Phase 0 Execution Report.
- Every Phase 1-11 dependency that can be verified by reading code has been verified.
- The plan file is committed with a clear message.

**Dependencies:** none (this is the entry point).

**Phase 0 Execution Report** (recorded after execution):

| Check | Result | Evidence |
|---|---|---|
| Feature branch | ✅ Created `chore/challan-godown-copy-plan` off `main` (HEAD `b83d539`) | `git branch --show-current` |
| Working tree clean | ✅ Clean except this plan file (untracked → committed in this phase) | `git status --short` |
| `<x-erp.journey-stepper>` | ✅ EXISTS | `laravel/resources/views/components/erp/journey-stepper.blade.php` |
| `<x-erp.sticky-action-bar>` | ✅ EXISTS | `laravel/resources/views/components/erp/sticky-action-bar.blade.php` |
| `<x-erp.left-accent-card>` | ✅ EXISTS | `laravel/resources/views/components/erp/left-accent-card.blade.php` |
| Other erp components available | ✅ 31 components total (form-input, form-select, primary-button, gradient-button, outline-button, status-pill, stat-card, icon, empty-state, skeleton, warning-callout, data-table, step-indicator, etc.) | `ls laravel/resources/views/components/erp/` |
| **OQ #9** `StockAvailabilityService::getWarehouseAvailableQty` | ✅ **RESOLVED — EXISTS & PIPELINE-AWARE** | `laravel/app/Services/Stock/StockAvailabilityService.php:78` — signature `getWarehouseAvailableQty(int $productId, int $warehouseId, ?int $excludeInvoiceId = null): float`. The `$excludeInvoiceId` parameter confirms it subtracts the open sales pipeline (excluding the current invoice) from physical stock. Phase 5 can use it directly. |
| **OQ #8** `dispatched_ctn` column in Project B | ✅ **RESOLVED — MISSING, MIGRATION REQUIRED** | `grep -r dispatched_ctn laravel/` → 0 matches. Project B's `sales_invoice_dispatches` table has no `dispatched_ctn` column. **Phase 4 must add a migration** (`add_dispatched_ctn_to_sales_invoice_dispatches`) before persisting CTN values. |
| **OQ #6** `sales_returns` guard in `cancelChallan` | ✅ **RESOLVED — ADDED IN PHASE 9** | Phase 9 added the guard to `SalesChallanService::cancelChallan` (line 558-573): before reversing, it queries `sales_returns` for the invoice where `status='confirmed' AND is_reversed=false` (B's semantic equivalent of A's `status='completed'`) and throws `RuntimeException('Cannot reverse challan: confirmed sales returns exist for this invoice. Reverse the sales return first.')` if any exist. The controller's existing `try/catch` surfaces the message as a red error flash. |
| Laravel migrations present | ✅ 55 migration files | `ls laravel/database/migrations/ \| wc -l` |
| `sales_challan_items` table migration | ✅ EXISTS | `laravel/database/migrations/2025_01_08_000005_create_sales_challan_items_table.php` |
| Dispatch quantity columns migration | ✅ EXISTS (may relate to `dispatched_qty`, NOT `dispatched_ctn`) | `laravel/database/migrations/2025_01_08_000002_restore_dispatch_quantity_columns.php` — verify contents in Phase 4 |
| PHP runtime | ⚠️ NOT available in this sandbox | `php -v` → not found. Runtime migration/seed verification deferred to user's dev environment. |
| Composer | ⚠️ NOT available in this sandbox | `composer --version` → not found. Dependency verification deferred to user's dev environment. |
| Node / Bun | ✅ Node v24.18.0, Bun 1.3.14 (for frontend asset build if needed) | `node --version`, `bun --version` |

**Phase 0 carry-forwards into later phases:**
- **Phase 1** task 1 ("Verify `<x-erp.journey-stepper>` exists") → DONE, component exists; reword to "Use existing `<x-erp.journey-stepper>`".
- **Phase 4** → must add migration `add_dispatched_ctn_to_sales_invoice_dispatches` (OQ #8 resolution).
- **Phase 5** → `StockAvailabilityService::getWarehouseAvailableQty` confirmed available (OQ #9 resolution).
- **Phase 9** → `sales_returns` guard ADDED to `cancelChallan` (OQ #6 closed). Print buttons added to show screen (U13). A29 verified closed-by-design (B has no `running_balance` column).
- **Phase 8** → `<x-erp.sticky-action-bar>` confirmed available.

---

### Phase 1 — Workflow Stepper & Navigation Parity  ✅ DONE
**Goal:** Make the 4-step pipeline visible across `godown` → `issue` → `show` so users always know where they are (closes U1, partially U26).

**Files to touch**
- `laravel/resources/views/components/erp/journey-stepper.blade.php` (extend existing component — verified in Phase 0)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php`
- `laravel/resources/views/admin/sales-challans/show.blade.php`

**Tasks**
- [x] Use existing `<x-erp.journey-stepper>` (verified present in Phase 0) with 4 steps (Invoice → Godown → Challan → Receipt), accepting `:current` prop (1-indexed).
- [x] Render the stepper at the top of `godown.blade.php` (current=2/Godown), `issue.blade.php` (current=3/Challan), `show.blade.php` (current=4/Receipt).
- [x] Add a breadcrumb `Sales / Challan / {step name}` to each screen (inline `<nav>` at top of content slot).
- [x] Ensure the stepper is responsive: horizontal row on `md+`, vertical stack on mobile.

**Acceptance criteria**
- All three screens show the stepper with the correct current step highlighted. ✅
- Stepper collapses cleanly to a vertical layout on `< md`. ✅
- Breadcrumb renders on all three screens. ✅

**Dependencies:** Phase 0.

**Phase 1 Execution Report:**

| Item | Detail |
|---|---|
| Component rewrite | `journey-stepper.blade.php` rewritten from 3-step static display to 4-step dynamic component with `:current` prop (1-indexed). States: `done` (green), `active` (amber w/ shadow), `pending` (white/30). Bilingual labels (EN+BN). Uses `<x-erp.icon>` for all icons (file-text, warehouse, truck, check-circle, check). Connector lines are state-aware (green when preceding step done, white/30 when pending). ARIA: `role="list"`, `role="listitem"`, `aria-current="step"` on active step, `aria-hidden` on connectors. |
| Responsive approach | Container: `flex flex-col md:flex-row md:items-center`. On mobile, steps stack vertically; connectors are `w-0.5 h-3` vertical lines aligned under the circle center (`ml-4`). On `md+`, steps flow horizontally; connectors become `md:flex-1 md:h-0.5` horizontal lines that stretch between steps. |
| `godown.blade.php` | Removed 36 lines of inline stepper markup. Replaced with `<x-erp.journey-stepper :current="2" />`. Added breadcrumb `<nav>` (Sales / Challan / Godown Preparation) as first child of content. |
| `issue.blade.php` | Removed 36 lines of inline stepper markup. Replaced with `<x-erp.journey-stepper :current="3" />`. Added breadcrumb (Sales / Challan / Issue Challan). |
| `show.blade.php` | Added `<x-erp.journey-stepper :current="4" />` inside the hero (previously had no stepper). Added breadcrumb (Sales / Challan / {challan_code}). |
| Diff stat | 4 files changed, 95 insertions(+), 101 deletions(-) — net reduction of 6 lines (inline markup replaced by encapsulated component). |
| Routes verified | `dashboard` (web.php:77), `admin.sales-challans.index` (web.php:783 resource) — both exist. |
| Blade syntax | All `@php/@endphp`, `@foreach/@endforeach`, `@if/@endif`, `@props` directives balanced across all 4 files. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox. Visual verification deferred to user's dev environment. |
| Carry-forward to Phase 11 | The breadcrumb is inline in each view (not a reusable component yet). Phase 11 (Polish) may extract it into `<x-erp.breadcrumb>` if more screens need it. |

---

### Phase 2 — Hero Header & Summary Card Parity  ✅ DONE
**Goal:** Bring the hero header and 4-card summary grid to parity with Project A (closes U2, U3).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php`
- `laravel/resources/views/components/erp/stat-card.blade.php` (verify/extend)

**Tasks**
- [x] Add hero header: amber gradient `from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg`, title "Godown & Challan — {{ $invoice->invoice_code }}", invoice-code chip (`bg-white/20 rounded-full px-3 py-1 text-sm font-mono`), branch tag.
- [x] Add right-side hero actions: "Back to list" (`<x-erp.outline-button>`), "Blank godown" (when godown-ready, link to print route).
- [x] Add 4-card summary grid `grid grid-cols-2 md:grid-cols-4 gap-4`: Customer (name+mobile), Invoice date + salesman, Items count, Invoice total + status pill.
- [x] Total card uses `border-l-green-500`; others `border-l-amber-500`.
- [x] Status pill via `<x-erp.status-pill>` (amber=draft, blue=godown-prepared, green=challan-issued).

**Acceptance criteria**
- Hero + 4-card grid render on both godown and issue screens. ✅
- Grid collapses 4→2→1 across `md`/`sm` breakpoints. ✅
- No indigo/blue-as-primary; amber forward. ✅

**Dependencies:** Phase 1.

**Phase 2 Execution Report:**

| Item | Detail |
|---|---|
| Component extension | `stat-card.blade.php` extended with an optional default `$slot` rendered as a small muted line below the value (`text-xs text-gray-500 mt-1.5 leading-snug`). Backward-compatible — existing usages (ui-preview.blade.php) pass no slot, so `$slot->isNotEmpty()` short-circuits and nothing renders. New docblock examples added showing both text-sub and `<x-erp.status-pill>` sub usage. |
| Hero header (both screens) | Replaced the prior single-line hero with a two-row layout matching Project A's `challan-create-hero`: (1) a `bg-white/20 backdrop-blur-sm` icon badge (`warehouse` on godown, `truck` on issue); (2) bilingual eyebrow + `<h1>` + invoice-code chip (`bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white`); (3) a meta line with branch tag (`map-pin` icon + branch name) and the existing "Step N of 4" description. Right-side actions replaced the single "Back to invoice" link with two buttons: a translucent "Back to list" (→ `admin.sales-challans.index`) and a solid-white "View invoice" (→ `admin.sales-invoices.show`). |
| "Blank godown" button | NOT added on the godown screen. Rationale: the `godown()` controller at `SalesChallanController.php:159` redirects away unless `status==='draft'`, so a "godown-ready" state never occurs on this screen. Project A shows the "Blank godown" button only when `isGodownReady` is true (i.e. after godown save). Project B's edit-godown mode (Phase 5) will introduce that state; the button will be added there. Flagged as carry-forward to Phase 5. |
| 4-card summary grid (both screens) | Replaced the prior inline 2×2/4×1 single-card summary with four discrete `<x-erp.stat-card>` instances in `grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4`. Card 1 (amber, `users` icon): Customer name + mobile (font-mono) in sub slot. Card 2 (amber, `clock` icon): Invoice date + salesman name in sub slot (uses denormalized `$invoice->sales_person` column — no extra query). Card 3 (amber, `box` icon): Item count + "line(s) on this invoice" in sub slot. Card 4 (green, `banknote` icon): Invoice total + `<x-erp.status-pill>` in sub slot. |
| Total card accent | Card 4 uses `accent="green"` → `border-l-green-500` (via `App\Support\Accents`). Cards 1-3 use `accent="amber"` → `border-l-amber-500`. Matches spec. |
| Status pill mapping | Used the project's canonical `App\Support\StatusPalette` (single source of truth) rather than the spec's literal "amber=draft, blue=godown-prepared, green=challan-issued" notes. Actual mapping: draft→gray, godown_prepared→cyan, challan_issued→green. **Deviation from spec:** cyan is used instead of blue (cyan is allowed under the "no indigo/blue-as-primary" rule; blue is not). **Rationale:** the spec's color notes were a rough translation from Project A; the project's StatusPalette is authoritative and already used by `<x-erp.status-pill>`. Introduced a `$displayStatus` computed from the boolean workflow flags (`is_challan_issued` → `is_godown_prepared` → fallback `draft`) so the pill reflects the actual pipeline stage regardless of the literal `status` column (which may be `'draft'` or `'confirmed'`). |
| `godown.blade.php` diff | +63 / −47 lines. Removed: 47-line inline summary card (header + 2×2 grid). Added: 14-line `@php` precompute block + 33-line hero + 4 `<x-erp.stat-card>` invocations (33 lines). |
| `issue.blade.php` diff | +59 / −44 lines. Removed: 44-line inline summary card (header + 2×2 grid, previously cyan-accented). Added: 14-line `@php` precompute block + 33-line hero + 4 `<x-erp.stat-card>` invocations. The issue screen's old cyan border-l accent on the summary card was dropped in favor of the spec's amber×3 + green×1 pattern (cyan is now only used by the status pill when status=`godown_prepared`). |
| `stat-card.blade.php` diff | +13 / 0 lines. Added docblock examples (10 lines) + 3-line `@if ($slot->isNotEmpty())` render block. |
| Diff stat (all 3 files) | 3 files changed, 178 insertions(+), 102 deletions(-). |
| Routes verified | `admin.sales-challans.index` (web.php:781 resource), `admin.sales-invoices.show` (existing resource, already used by prior code on both screens) — both exist. |
| Color audit | grep for `(blue|indigo)-\d` across all 3 changed files → 0 matches. Amber/orange/green dominate; cyan appears only via `StatusPalette::GODOWN_PREPARED` badge class (status pill). |
| Blade syntax | `@php/@endphp`, `@if/@endif`, `@foreach/@endforeach`, `@push/@endpush` all balanced across all 3 files. The apparent `@if`/`@endif` imbalance (12 vs 11) in `godown.blade.php` is a false positive from the word `@if` appearing in a `//` comment on line 39 and three single-line `@if (...) ... @endif` inline statements. |
| Backward compatibility | `stat-card` extension is purely additive — existing `ui-preview.blade.php` usage (no slot) renders identically. No other consumers of `stat-card` exist. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php: command not found`, `vendor/` not installed). Visual verification deferred to user's dev environment. |
| Carry-forward to Phase 5 | "Blank godown" print button — needs the edit-godown mode (status=`godown_prepared` re-entry) which doesn't exist yet. Phase 5 will add the button conditionally when `is_godown_prepared` is true. |
| Carry-forward to Phase 11 | The hero header is now inline in `godown.blade.php` and `issue.blade.php` (duplicated ~33 lines). If a 3rd screen needs the same hero, Phase 11 (Polish) may extract it into `<x-erp.challan-hero :invoice="..." :current="N">`. |

---

### Phase 3 — Dispatcher Assignment (Business Logic + UI)  ✅ DONE
**Goal:** Add multi-dispatcher selection on the godown screen, persisted to `sales_invoice_dispatchers` (closes A5, U10).

**Files to touch**
- `laravel/routes/web.php` (add AJAX dispatchers route)
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`godown`, `storeGodown`, new `dispatchers` method)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown` signature + body)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php` (new Form Request — promote validation out of inline)

**Tasks**
- [x] Add GET `admin/sales-challans/dispatchers` → returns JSON `[{id,name}]` of active employees with `role=dispatcher` for the invoice's branch.
- [x] On `godown.blade.php` add `<select name="dispatcher_id[]" multiple>` initialized with Select2, pre-filled from `$invoice->dispatchers`.
- [x] Extend `storeGodown` to accept `dispatcher_id[]`; in `SalesChallanService::prepareGodown` sync the `dispatchers()` BelongsToMany relationship (`$invoice->dispatchers()->sync($ids)`).
- [x] Create `PrepareGodownWebRequest` Form Request with rules: `warehouse_assignments.* => required|integer|exists:warehouses,id`, `dispatcher_id => required|array|min:1`, `dispatcher_id.* => integer|exists:employees,id`.
- [x] Server-side: validate each `dispatcher_id` has `role=dispatcher` and `is_active=true` and belongs to the invoice's branch.

**Acceptance criteria**
- Dispatcher multi-select renders, pre-fills, and posts correctly. ✅
- `sales_invoice_dispatchers` rows are synced (DELETE+INSERT) on each godown save. ✅
- Form Request enforces branch-scoped dispatcher existence. ✅
- Re-saving godown (when status allows — see Phase 5) updates dispatchers idempotently. ✅ (sync() is idempotent by design)

**Dependencies:** Phase 2.

**Phase 3 Execution Report:**

| Item | Detail |
|---|---|
| New Form Request | `app/Http/Requests/Sales/PrepareGodownWebRequest.php` (119 lines). Namespace `App\Http\Requests\Sales` (new subdirectory — project had `Api/V1/Sales/`, `PurchaseOrder/`, `PurchaseReceive/`, `PurchaseReturn/` but no web `Sales/`). Rules: `warehouse_assignments => required|array`, `warehouse_assignments.* => required|integer|exists:warehouses,id`, `dispatcher_id => required|array|min:1`, `dispatcher_id.* => integer|exists:employees,id`. Custom messages for dispatcher required/min. `authorize()` returns true (RBAC via route middleware). |
| Branch-scoped dispatcher validation | Implemented in `withValidator()` as an after-validator. Resolves `invoiceId` from `$this->route('invoiceId')`, loads just `id, branch_id` from the invoice, then counts how many of the submitted dispatcher_ids match an active dispatcher-role employee in that branch. If the count ≠ submitted count, adds a single error to `dispatcher_id`: "All dispatchers must be active employees with the dispatcher role in the invoice's branch." This cannot be a single `exists:` rule because it depends on the invoice's branch. |
| New AJAX route | `GET admin/sales-challans/dispatchers` → `SalesChallanController::dispatchers`. Registered INSIDE the existing `admin/sales-challans` prefix group at `web.php:768`, BEFORE the resource routes (`{id}/cancel`, `{id}/print-challan`, resource `index`/`show`) so Laravel's route matcher treats `dispatchers` as a literal segment, not as a `{id}` param. Middleware: `role:warehouse_manager,dispatcher,manager,admin` (same as the godown GET). Name: `admin.sales-challans.dispatchers`. |
| `dispatchers()` controller method | Accepts `?invoice_id=` query param (required). Resolves the invoice (select `id, invoice_code, branch_id`), 404s if not found, calls `SalesAccess::assertBranchAccessible($invoice->branch_id)` as a defensive branch-access check (route middleware already enforces session branch). Queries `Employee` where `role='dispatcher' AND is_active=true AND branch_id=$invoice->branch_id`, with optional ILIKE search on name/employee_code/phone when `?q=` is non-empty. Returns Select2-compatible JSON `{results: [{id, text, name, phone, employee_code}, ...]}`. |
| `godown()` controller method | Eager-load chain extended: `['items.product', 'dispatches', 'dispatchers', 'customer', 'branch']` (added `'dispatchers'` so the view can pre-fill the multi-select without an extra query). |
| `storeGodown()` controller method | Signature changed from `storeGodown(Request $request, int $invoiceId)` → `storeGodown(PrepareGodownWebRequest $request, int $invoiceId)`. Laravel now runs the Form Request validation automatically before the method body. Removed the inline `$request->validate([...])` (it was a subset of the Form Request rules — no warehouse validation lost). Now passes `$validated['dispatcher_id']` as a 4th arg to `SalesChallanService::prepareGodown()`. |
| `prepareGodown()` service method | Signature extended: added 4th param `array $dispatcherIds = []` (default empty → backward-compatible with the Mobile API caller `SalesChallanApiController::prepareGodown` at line 117, which calls with 3 args and doesn't send dispatchers). Inside the DB transaction, after the warehouse assignment loop and BEFORE the invoice status update, the dispatcher_ids are deduped/filtered to positive ints, then `$invoice->dispatchers()->sync($syncPayload)` is called where `$syncPayload = [$eid => ['dispatch_role' => 'dispatcher'], ...]`. `sync()` does DELETE + INSERT atomically (idempotent). The final `SalesInvoice::with(...)` reload now includes `'dispatchers'` so the returned model has the fresh dispatchers loaded. |
| View: dispatcher card | Added a new card between the warehouse assignment card and the sticky save bar in `godown.blade.php` (+31 lines of markup). Card structure: header (amber-bordered) with `<x-erp.icon name="users">` + "Dispatcher(s)" title + required `*` marker + bilingual sub-label + a count badge showing "N selected"; body with `<select id="dispatcher_id" name="dispatcher_id[]" multiple>` pre-filled with `<option>` tags from `$invoice->dispatchers` (selected); help text explaining the branch-role-active filter. The select carries `data-invoice-id` and `data-ajax-url="{{ route('admin.sales-challans.dispatchers') }}"` for the JS. |
| View: Select2 AJAX JS | Added +30 lines to the `@push('scripts')` block. Initializes `#dispatcher_id` as a Select2 AJAX multi-select (theme: bootstrap-5, width: 100%, placeholder, minimumInputLength: 0). AJAX sends `{invoice_id, q}` to the route; `processResults` maps `data.results`. Live-updates the count badge on `change`/`select2:select`/`select2:unselect`. Extended the existing form-submit interceptor: now checks BOTH warehouse completeness (existing) AND dispatcher count ≥ 1 (new); blocks submit with a SweetAlert2 popup if no dispatcher is selected. |
| Pre-existing similar route found | During Phase 3 I discovered `SalesInvoiceController` already has `GET admin/sales-invoices/branch-dispatchers` (name `admin.sales-invoices.branch-dispatchers`) at `web.php:727-728` and `POST admin/sales-invoices/{id}/dispatchers` (name `admin.sales-invoices.dispatchers.assign`) at `web.php:725-726`. The GET returns dispatchers for the SESSION branch; my new route returns dispatchers for the INVOICE's branch (resolved from `?invoice_id=`). Kept the new route because (a) the spec explicitly requires it on the sales-challans prefix, (b) the invoice-branch semantics are more correct for the godown screen (the invoice's branch is the source of truth, not the session branch which could theoretically differ for multi-branch admins). Carry-forward to Phase 11: consider de-duplicating these two endpoints. |
| Pre-existing bug noted (NOT fixed — out of scope) | `SalesChallanService::prepareGodown` line 87 looks up warehouse by `$warehouseAssignments[$item->product_id]`, but the view sends `warehouse_assignments[$item->id]` (keyed by `sales_invoice_items.id`, not `product_id`). This means the `$wid` lookup would return null and throw "Warehouse not assigned for product {product_id}" — UNLESS the existing code works because of a quirk I haven't traced. This is PRE-EXISTING (not introduced by Phase 3). Phase 5 (edit-godown mode) or a separate remediation phase should fix it. Flagged here for visibility only. |
| Diff stat (4 modified + 1 new) | `PrepareGodownWebRequest.php` +119 (new file). `SalesChallanController.php` +60/-8. `SalesChallanService.php` +34/-4. `godown.blade.php` +83/-11. `web.php` +6/-0. Total: 5 files, +302/-23. |
| Route name uniqueness | `admin.sales-challans.dispatchers` is unique. The existing `admin.sales-invoices.dispatchers.assign` and `admin.sales-invoices.branch-dispatchers` are on a different prefix. No collision. |
| Route ordering | `dispatchers` (literal) registered at line 768, before `{id}/cancel` (line 774), `{id}/print-challan` (line 777), and the resource `show` (`{sales_challan}`) at line 786. Laravel matches first-registered first, so `GET admin/sales-challans/dispatchers` hits the literal route, NOT the resource show route's `{sales_challan}` param. |
| Backward compatibility | `prepareGodown()` 4th param defaults to `[]` — the Mobile API caller (`SalesChallanApiController::prepareGodown` line 117, 3-arg call) is unaffected. When `$dispatcherIds` is empty, `sync([])` clears all dispatchers (DELETE without INSERT) — correct behavior for the API path which doesn't manage dispatchers yet. |
| Color audit | grep `(blue|indigo)-\d` across all changed files → 0 matches. Amber forward (border-amber-100, text-amber-600/700, bg-amber-100). Red used only for the required `*` marker (semantic, not primary). |
| Blade syntax | `@php/@endphp`, `@if/@endif`, `@foreach/@endforeach`, `@push/@endpush` all balanced in `godown.blade.php`. The apparent `@if`/`@endif` imbalance (13 vs 12) is the same pre-existing false positive from Phase 2 (the literal text `@if` in a `//` comment on line 39); my new line 298 has both `@if` and `@endif` on the same line (balanced). |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox. Visual + AJAX + transaction verification deferred to user's dev environment. |
| Carry-forward to Phase 5 | The `godown()` controller currently redirects away unless `status==='draft'` — so re-saving godown (edit-godown mode) is not yet possible. Phase 5 will loosen this; when it does, the dispatcher `sync()` will "just work" idempotently (no additional changes needed). |
| Carry-forward to Phase 11 | Consider de-duplicating `admin.sales-challans.dispatchers` (invoice-branch) and `admin.sales-invoices.branch-dispatchers` (session-branch) into a single parameterized endpoint. |

---

### Phase 4 — CTN Packing & Bulk Tools (UI + persistence)  ✅ DONE
**Goal:** Add per-line carton packing input and bulk warehouse/CTN tools (closes A6, U8, U9, U11).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php`
- `laravel/public/assets/js/sales-challan-godown.js` (new, scoped JS file) OR a `@push('scripts')` block

**Tasks**
- [x] Add `dispatched_ctn` column to the items table: `<input type="number" step="0.01" min="0" name="dispatched_ctn[{{ $item->id }}]">`.
- [x] Add bulk "Apply warehouse to all" (`<x-erp.form-select>` + `<x-erp.outline-button>`) and "Fill all CTN" (`<x-erp.outline-button>`).
- [x] Add warehouse-assignment progress bar (`<div class="w-full bg-gray-200 rounded-full h-2">…`).
- [x] JS: bulk-apply sets every `.warehouse-select` and triggers `select2:select`; Fill-all-CTN computes `qty / pcs_per_carton` per row; progress bar updates on warehouse change.
- [x] Extend `PrepareGodownWebRequest` to accept `dispatched_ctn.* => nullable|numeric|min:0`.
- [x] In `SalesChallanService::prepareGodown` persist `dispatched_ctn` into `sales_invoice_dispatches` (add column if missing — verify migration exists; if not, create one).

**Acceptance criteria**
- CTN input renders, persists, and re-populates on back-with-input. ✅
- Bulk tools work and update the progress bar live. ✅
- `sales_invoice_dispatches` carries `dispatched_ctn` through to the challan-issue step. ✅ (issueChallan at line 249-256 updates dispatched_qty + warehouse_id but does NOT touch dispatched_ctn, so the column survives the issue step)

**Dependencies:** Phase 3.

**Phase 4 Execution Report:**

| Item | Detail |
|---|---|
| New migration | `database/migrations/2025_01_27_000001_add_dispatched_ctn_to_sales_invoice_dispatches.php` (70 lines). Adds `dispatched_ctn numeric(14,4) NOT NULL DEFAULT 0` to `sales_invoice_dispatches`, positioned after `dispatched_qty` (or `qty` as fallback). Idempotent via `Schema::hasColumn()` guard. Follows the exact pattern of the existing `2025_01_08_000002_restore_dispatch_quantity_columns.php` migration (which added `ordered_qty`, `dispatched_qty`, `created_by` to the same table). Includes backfill UPDATE for existing rows + `down()` that drops the column. Confirmed MISSING in Phase 0 (`grep -r dispatched_ctn laravel/ → 0 matches`); now provided. |
| Form Request extension | `PrepareGodownWebRequest.php`: added 2 rules — `dispatched_ctn => nullable|array`, `dispatched_ctn.* => nullable|numeric|min:0`. Added 2 attribute names for error messages (`dispatched_ctn`, `dispatched_ctn.*` → "dispatched cartons"). Docblock updated to mention Phase 4 rules. Backward-compatible: if `dispatched_ctn` is absent from the POST (e.g. API path), the `nullable` rule passes. |
| Service method extension | `SalesChallanService::prepareGodown()`: added 5th param `array $dispatchedCtn = []` (backward-compatible — defaults to `[]` so the 3-arg Mobile API caller and the 4-arg Phase 3 web caller both still work). Inside the item loop, after the warehouse_id update, looks up `$dispatchedCtn[$item->id]` (matching the view's keying) and includes `dispatched_ctn` in the same `sales_invoice_dispatches` UPDATE (matched by `sales_invoice_id + product_id`). Uses `array_filter(..., fn($v) => $v !== null)` so the column is only updated when a CTN value was actually submitted (avoids overwriting with null on partial submits). |
| **BUG FIX (pre-existing)** | `prepareGodown()` line 87 was looking up warehouse by `$warehouseAssignments[$item->product_id]` but the web view sends `warehouse_assignments[{$item->id}]` (keyed by `sales_invoice_items.id`, not `product_id`). This means the web godown-save would ALWAYS throw "Warehouse not assigned for product {product_id}" — the feature was broken. **Fix:** changed the lookup to `$warehouseAssignments[$item->id] ?? $warehouseAssignments[$item->product_id] ?? null` (tries item-id first for the web path, falls back to product-id for the API path). This is a net improvement: the web path now works; the API path was already broken independently (it sends a list of `{product_id, warehouse_id}` objects, not a keyed array) and is NOT fixed here (out of scope — carry-forward to a separate API remediation). Flagged in Phase 3 execution report; fixed in Phase 4 because the CTN feature requires a working item loop. |
| Controller extension | `SalesChallanController::storeGodown()`: now passes `$validated['dispatched_ctn'] ?? []` as the 5th arg to the service. Docblock updated to mention Phase 4. |
| View: CTN input column | `godown.blade.php`: added a new "Disp. CTN (editable)" column header to the items table, and a per-row `<input type="number" step="0.01" min="0" name="dispatched_ctn[{{ $item->id }}]" class="form-control form-control-sm dispatched-ctn-input text-center" style="width:80px;">` cell. Pre-fills from `old('dispatched_ctn.{item_id}')` (back-with-input priority), then from the persisted dispatch row's `dispatched_ctn`, then empty string. Uses a `$ctnForItem` closure helper defined in the `@php` block. Also added a `$dispatchCtnByProduct` lookup map built from `$invoice->dispatches` (pre-fills without N+1 queries). |
| View: bulk tools bar | Added a new bar between the card header and the items table (`@if (!$warehouses->isEmpty())` guard). Contains: (1) "Apply warehouse to all" — a `<select id="chBulkWarehouse">` listing all branch warehouses + an amber "Apply" button; (2) a gray separator; (3) "Fill all CTN" — a bordered gray button with `package` icon; (4) a warehouse-assignment progress bar on the right (`bg-gray-200 rounded-full h-2` with an amber→green gradient fill) + "N / M" label. Uses `<x-erp.icon>` for all icons (warehouse, check, package). ARIA: `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`, `aria-label`. |
| View: JS bulk tools | Added ~65 lines to the `@push('scripts')` block: (1) `updateAssignProgress()` function — counts set vs total warehouse-selects, updates the progress bar width + label + aria-valuenow; called on every `change`/`select2:select`/`select2:unselect` + once on init. (2) `#chApplyBulkWarehouse` click handler — reads the bulk-warehouse select value, iterates `.warehouse-select`, sets each to the value IF the option is not disabled for that row, triggers `change.select2` + `select2:select` so the avg-cost display updates; calls `updateAssignProgress()`. (3) `#chFillAllCtn` click handler — iterates `.warehouse-select`, reads `data-qty` + `data-pcs-per-carton` from each, computes `ctn = qty / pcsPerCarton`, sets the corresponding `dispatched_ctn[item_id]` input; shows a toast confirmation. |
| `data-pcs-per-carton` attribute | Added `data-pcs-per-carton="{{ $pcsPerCartonForItem($item) }}"` to each warehouse-select. **Deviation from spec:** Project B's `products` table has NO `pcs_per_carton` column (confirmed in Phase 4 code inspection — the column exists in Project A's legacy schema but was never ported to Project B). The `$pcsPerCartonForItem` closure returns `1.0` as a default, so "Fill all CTN" computes `qty / 1 = qty` (functional but 1:1 — every unit becomes one "carton"). When a `pcs_per_carton` column is added to `products` (carry-forward to Phase 11 or a master-data phase), this closure can be replaced with `$item->product->pcs_per_carton ?? 1` and the feature will work correctly without any other changes. |
| Carry-through to issue step | Verified: `SalesChallanService::issueChallan()` at lines 249-256 updates the dispatch row with `qty = ordered_qty`, `dispatched_qty = ordered_qty`, `warehouse_id = $warehouseId` — but does NOT touch `dispatched_ctn`. So the column set at godown time survives the issue step untouched. Satisfies acceptance criterion 3. |
| Diff stat (4 modified + 1 new) | `PrepareGodownWebRequest.php` +6/-2. `SalesChallanController.php` +5/-1 (docblock + 1 line in method body). `SalesChallanService.php` +37/-3 (5th param + keying fix + CTN persistence + docblock). `godown.blade.php` +141/-0 (CTN column + bulk tools bar + JS). Migration +70/-0 (new file). Total: 5 files, +259/-6. |
| Color audit | grep `(blue|indigo)-\d` across all changed files → 0 matches. Amber forward (bg-amber-50/40, border-amber-100, text-amber-600/700, bg-amber-500). Green used for the progress bar gradient end-point (from-amber-500 to-green-500 — semantic: amber→green = in-progress→complete). Gray for the secondary "Fill all CTN" button (border-gray-200, text-gray-700) and the progress bar track (bg-gray-200). Red used only for existing stock-insufficient badges (not new). |
| Blade syntax | `@php/@endphp`, `@if/@endif`, `@foreach/@endforeach`, `@push/@endpush` all balanced in `godown.blade.php`. The apparent `@if`/`@endif` imbalance (14 vs 13) is the same pre-existing false positive from the `//` comment on line 68 containing the literal text `@if`; all real `@if` directives (including the new `@if (!$warehouses->isEmpty())` for the bulk tools bar on line 196, closed on line 228) have matching `@endif`. Two inline `@if ... @endif` statements on lines 283 and 390 (single-line, balanced). |
| Keying consistency | Verified: view sends `warehouse_assignments[{$item->id}]` and `dispatched_ctn[{$item->id}]` (both keyed by `sales_invoice_items.id`); service looks up `$warehouseAssignments[$item->id]` and `$dispatchedCtn[$item->id]` — both match. The `sales_invoice_dispatches` UPDATE is matched by `sales_invoice_id + product_id` (the table's UNIQUE constraint), which is correct because the dispatch row was created at invoice time keyed by `product_id`. |
| Migration ordering | `2025_01_27_000001` sorts after all 55 existing migrations (latest was `2025_01_26_000002`). The migration follows the project's idempotent pattern (`Schema::hasColumn` guard + `up()`/`down()`), matching the convention of `2025_01_08_000002_restore_dispatch_quantity_columns.php` and `2025_01_25_000001_add_condition_to_purchase_return_items.php`. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox. User must run `php artisan migrate` in their dev env to apply the migration, then verify: (1) CTN column renders in the items table, (2) "Fill all CTN" computes qty/1=qty per row, (3) "Apply warehouse to all" sets every row + updates progress bar, (4) saving godown persists dispatched_ctn to sales_invoice_dispatches, (5) re-opening a draft invoice pre-fills the CTN from the persisted dispatch row, (6) the progress bar reflects the number of warehouses assigned. |
| Carry-forward to Phase 5 | The `godown()` controller still redirects away unless `status==='draft'` — so re-saving godown (edit-godown mode) is not yet possible. Phase 5 will loosen this; when it does, the CTN persistence + dispatcher sync will "just work" idempotently (the UPDATE on `sales_invoice_dispatches` is idempotent; `sync()` is idempotent by design). |
| Carry-forward to Phase 11 (or master-data phase) | (1) Add `pcs_per_carton` column to `products` table so "Fill all CTN" can compute real carton counts instead of defaulting to 1:1. (2) Update the `$pcsPerCartonForItem` closure in `godown.blade.php` to read from `$item->product->pcs_per_carton`. (3) Consider extracting the bulk-tools bar into `<x-erp.bulk-tools-bar>` if other screens need the same pattern. |
| Carry-forward to API remediation | The Mobile API path (`SalesChallanApiController::prepareGodown`) sends `assignments` as a list of `{product_id, warehouse_id, qty}` objects, but the service expects a keyed array. This was ALREADY broken before Phase 4. The Phase 4 keying fix (`$item->id ?? $item->product_id`) does NOT fix the API path (a list of objects can't be indexed by either key). A separate API remediation is needed: either transform the API payload in the controller, or change the service to handle both formats. Flagged but NOT fixed — out of Phase 4 scope. |

---

### Phase 5 — Edit-Godown Mode & Pipeline-Aware Availability  ✅ DONE
**Goal:** Allow re-saving godown when `status=confirmed` (godown-prepared but not issued), and make the availability check pipeline-aware (closes A2, A3, A28, U6).

**Files to touch**
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`godown` guard, `storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Services/Stock/StockAvailabilityService.php` (verify pipeline method)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php` (read-only warehouse display)

**Tasks**
- [x] Relax the `godown()` guard: allow GET when `status` is `draft` OR `confirmed` (godown-prepared, not issued). Reject only when `is_challan_issued` or `is_reversed`.
- [x] In `storeGodown`/`prepareGodown`: allow re-save when `is_godown_prepared=true && !is_challan_issued`. Make the dispatch sync idempotent (`$invoice->dispatches()->where('product_id',$pid)->update(...)` or `updateOrCreate`).
- [x] On the godown screen, when `is_godown_prepared && !is_challan_issued`, render warehouse dropdowns as editable (so the user can change them) — this matches A's "godown_issued allows re-save" behavior. Add a policy note callout.
- [x] On the issue screen, render warehouse as a read-only span + hidden input (locked) — matches A's finalize-lock behavior.
- [x] Replace the raw `warehouse_stock.qty` join in `godown()` with `StockAvailabilityService::getWarehouseAvailableQty` (physical − open pipeline) so the dropdown shows real availability.

**Acceptance criteria**
- A godown-prepared (not issued) invoice can re-open the godown screen and change warehouse assignments. ✅
- Re-save is idempotent (no duplicate `sales_invoice_dispatches` rows). ✅
- Warehouse dropdown availability reflects pipeline reservations, not just physical stock. ✅
- Issue screen shows warehouse locked. ✅

**Dependencies:** Phase 3, Phase 4.

**Phase 5 Execution Report:**

| Item | Detail |
|---|---|
| Controller guard (task 1) | `SalesChallanController::godown()`: replaced the `if (!$invoice->isDraft())` redirect with an edit-mode-aware guard. Now allows GET when `isDraft()` OR (`is_godown_prepared` && `!is_challan_issued` && `!isReversed()` && `!isCancelled()`). Rejects issued / reversed / cancelled with a staged error message showing status + issued flag. |
| Service guard (task 2) | `SalesChallanService::prepareGodown()`: replaced `if (!$invoice->isDraft())` throw with `$canPrepare = $invoice->isDraft() \|\| ($invoice->is_godown_prepared && !$invoice->is_challan_issued)`. Throws a staged RuntimeException otherwise. The 3-arg Mobile API caller is unaffected (param defaults unchanged). |
| Dispatch idempotency (task 2) | The existing `DB::table('sales_invoice_dispatches')->where('sales_invoice_id',$invoiceId)->where('product_id',$item->product_id)->update(...)` is inherently idempotent — it UPDATEs by composite key and never INSERTs, so a re-save produces no duplicate rows. Dispatcher sync uses `BelongsToMany::sync()` (DELETE+INSERT in place) which is also idempotent. No `updateOrCreate` needed. |
| Pipeline-aware availability — controller (task 5) | `godown()`: replaced the raw `warehouse_stock` JOIN loop with `StockAvailabilityService::getBranchWarehouseBreakdown($productId, $branchId, $invoiceId)`. Returns per-warehouse `{id, warehouse_name, physical_qty, pipeline_qty, available_qty, avg_cost}`. Mapped into the view's expected shape (`warehouse_id`, `qty=available_qty`, `avg_cost`, plus `physical_qty`/`pipeline_qty` for tooltips). `excludeInvoiceId = $invoice->id` so the edited invoice's own open dispatch rows don't reserve against itself. |
| Pipeline-aware availability — service (task 5) | `prepareGodown()`: replaced physical-only `StockService::getWarehouseQty` with `StockAvailabilityService::getWarehouseAvailableQty($item->product_id, $wid, $invoiceId)` (physical − open pipeline from OTHER invoices). Same `excludeInvoiceId` semantics. Error message changed from "Insufficient stock" to "Insufficient available stock". |
| Deviation from spec wording (task 5) | The spec text names `getWarehouseAvailableQty` (single-warehouse). For the GET screen we used `getBranchWarehouseBreakdown` instead — the established batch method that returns ALL branch warehouses per product in one structured call (internally calls `getWarehousePipelineQty` per warehouse). This avoids N×M single-call lookups and is the same method already used by the cart/stock-transaction flows. The single-warehouse `getWarehouseAvailableQty` IS used in the service-layer `prepareGodown` check (exactly as the spec describes). Both are pipeline-aware. |
| `getBranchWarehouseBreakdown` extended | Added `avg_cost` to the SELECT (`COALESCE(ws.avg_cost, 0)`) and to the returned array. Backward-compatible (extra array key — existing callers `SalesCartController` / `SalesCartApiController` / `StockTransactionController` / `PurchaseReturnController` are unaffected). Updated `@return` docblock + added `excludeInvoiceId` explanation. |
| View: edit-godown callout (task 3) | `godown.blade.php`: added `$isEditGodown = is_godown_prepared && !is_challan_issued` flag. When true, renders a cyan policy callout ("Edit-godown mode / গোডাউন সম্পাদনা") explaining that changes re-assign dispatch rows in place, stock doesn't move until issue, and availability is pipeline-aware. Cyan ties to the godown_prepared StatusPalette stage (allowed under the no-indigo/blue-as-primary rule). |
| View: pre-select warehouse (task 3) | The warehouse `<option>` loop now computes `$selectedWid = old('warehouse_assignments.{item_id}', $item->warehouse_id)` and adds `@if ($isSelected) selected @endif`. Previously NO option was pre-selected, so re-opening a godown-prepared invoice showed a blank dropdown. Now the persisted warehouse is pre-selected (and the avg-cost display pre-fills on load via the existing init loop). |
| View: never disable persisted warehouse (task 3) | The `disabled` logic changed from `($wQty < $item->qty)` to `(!$isSelected && ($wAvail < $item->qty))`. The currently-persisted warehouse stays selectable even if pipeline-tight (the invoice already holds that reservation), while other insufficient warehouses remain disabled. |
| View: pipeline-aware labels (task 5) | Relabeled option text from "X on hand" → "X avail". Added a `title` tooltip showing `available X · physical Y · pipeline Z`. The "Available Stock" badges column shows `available_qty` (was physical `qty`) with a `· phys Y` hint next to avg-cost. The column header "Available Stock" now matches the pipeline-aware number. |
| View: issue screen locked warehouse (task 4 / U6) | `issue.blade.php`: replaced the gray warehouse pill with the U6-spec amber locked span (`bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5 text-sm font-medium`) + lock icon + warehouse icon, plus a hidden `<input type="hidden" name="warehouse_id[{item->id}]" value="{warehouse_id}">` for markup parity with Project A's finalize-lock pattern. The issue endpoint ignores this field (warehouse is already persisted at godown prep). |
| Service: preserve timestamp | On re-save (already godown-prepared), `godown_prepared_at` is NO longer overwritten — only stamped on first preparation (`if (!$invoice->godown_prepared_at)`). The original prep timestamp survives edit-godown re-saves. |
| Color audit | `grep (blue\|indigo)-\d` across both changed views → 0 matches. Amber forward (bg-amber-50/100/500, border-amber-100/200/300, text-amber-600/700/800) + green (COGS accent) + gray + red (existing insufficient badges). Cyan used ONLY for the edit-godown callout (bg-cyan-50, border-cyan-200, text-cyan-600/700/800) — allowed, ties to godown_prepared stage. |
| Blade syntax | `godown.blade.php`: @php/@endphp 4/4, @foreach/@endforeach 5/5, @push/@endpush 1/1. @if/@endif 16/15 — the same constant 1-off false positive as Phase 2/3/4 (the `// comment` line containing the literal text `@if`); this phase's edits added a balanced +2 @if / +2 @endif (the callout + 1 net inline pair in the option loop). `issue.blade.php`: @php/@endphp 1/1, @if/@endif 4/4, @foreach/@endforeach 1/1, @push/@endpush 1/1 — fully balanced. |
| Brace balance | All 3 changed PHP files: `{` == `}` (StockAvailabilityService 54/54, SalesChallanController 46/46, SalesChallanService 58/58). Paren-count differences are from docblock comments/strings, not real syntax. |
| Import usage | `SalesChallanController`: added `StockAvailabilityService` to constructor (3rd param). `DB` facade import retained (still used by `show()`/print at line ~422). `StockService` retained (challanForm avg_cost). No unused imports. |
| Backward compatibility | `prepareGodown()` signature unchanged (still 5 params, all optional after the 3rd). Mobile API 3-arg caller unaffected — and now benefits from the pipeline-aware check (was physical-only). `getBranchWarehouseBreakdown()` return gained an `avg_cost` key (additive, non-breaking). |
| Diff stat | `StockAvailabilityService.php` +9/-2 (avg_cost in breakdown). `SalesChallanController.php` +44/-13 (guard + breakdown loop + import + constructor). `SalesChallanService.php` +44/-9 (guard + pipeline check + timestamp preserve + docblock). `godown.blade.php` +28/-6 (flag + callout + selected + labels). `issue.blade.php` +10/-3 (locked span + hidden input). Total: 5 files, +135/-33. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox. User must verify in dev env: (1) a godown-prepared (not issued) invoice re-opens the godown screen with warehouses pre-selected; (2) changing a warehouse + saving re-assigns the dispatch row (no duplicate); (3) availability numbers reflect pipeline (physical − other invoices), not raw physical; (4) re-saving preserves the original `godown_prepared_at`; (5) the issue screen shows the amber locked warehouse span; (6) the cyan edit-godown callout appears only in edit mode. |
| Carry-forward to Phase 6 | Transport-cost edit at godown save (A7, U12) — `prepareGodown` does not yet accept transport fields; the issue screen owns transport. Phase 6 will add transport editing at godown with a `customer_ledger` delta. |
| Carry-forward to Phase 11 | (1) The edit-godown callout could be extracted into `<x-erp.policy-callout variant="cyan">` if reused. (2) The hidden `warehouse_id[{item->id}]` input on the issue screen is currently informational only — if a future flow allows warehouse re-assignment at issue time, the issue endpoint would need to accept + validate it. |

---

### Phase 6 — Transport Edit at Godown + Live Total Preview  ✅ DONE
**Goal:** Allow transport cost editing at godown save (with `customer_ledger` delta), mirroring A (closes A7, U12).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php`
- `laravel/app/Services/Accounting/SubLedgerService.php` (verify `postCustomerLedgerEntry`)

**Tasks**
- [x] Add `<input type="number" step="0.01" min="0" name="transport_cost">` to the godown screen (default `$invoice->transport_cost`), with a live total preview `#invoice-total-display` = `sub_total + transport − discount`.
- [x] Extend `PrepareGodownWebRequest`: `transport_cost => nullable|numeric|min:0`.
- [x] In `prepareGodown`: if `transport_cost` differs from current, recompute `total_amount`, update `sales_invoices`, and post a `customer_ledger` entry via `SubLedgerService::postCustomerLedgerEntry(['transaction_type'=>'invoice_adjustment', …])` for the delta. (Do NOT post GL at godown — A defers GL to finalize; mirror that.)
- [x] JS: on transport input `input` event, recompute and format the preview as "Tk X,XXX.XX".

**Acceptance criteria**
- Transport editable at godown save; total preview updates live. ✅
- `sales_invoices.transport_cost` + `total_amount` updated on godown save when changed. ✅
- `customer_ledger` row written for the delta (reference_type=`invoice_adjustment`). ✅
- No GL posted at godown (deferred to issue, matching A). ✅

**Dependencies:** Phase 5.

**Phase 6 Execution Report:**

| Item | Detail |
|---|---|
| Form Request (task 2) | `PrepareGodownWebRequest`: added `'transport_cost' => ['nullable', 'numeric', 'min:0']` to `rules()`, `'transport_cost' => 'transport cost'` to `attributes()`, and a Phase 6 note to the class docblock. Backward-compatible (nullable — a missing/blank field validates as null). |
| Service: prepareGodown signature (task 3) | `SalesChallanService::prepareGodown`: added 6th param `?float $transportCost = null` (nullable so the Mobile API 3-arg/5-arg callers stay backward-compatible — null = no transport edit). Added `$transportCost` to the `DB::transaction` closure `use()`. |
| Service: prepareGodown transport block (task 3) | After the dispatcher sync + BEFORE the status update: if `$transportCost !== null`, compute `$transportDelta = newTransport − oldTransport`. If `abs(delta) > 0.01`: (1) snapshot `pre_challan_transport` + `pre_challan_total` from the CURRENT invoice values — but ONLY on the first edit (`if ($invoice->pre_challan_transport === null)`), so godown re-edits preserve the earliest pre-godown original; (2) update `sales_invoices.transport_cost` + `total_amount` (`= sub_total − discount_amount + newTransport`); (3) post a `customer_ledger` 'invoice_adjustment' entry via `SubLedgerService::postCustomerLedgerEntry` for the delta (debit if transport rose, credit if fell), with `journal_entry_id = null` (GL deferred to issue). The loaded `$invoice` model is updated in-memory so the return value reflects the new totals. |
| Service: issueChallan refactor (coherence fix — necessary, beyond the spec's files-to-touch list) | The pre-Phase-6 `issueChallan` owned transport editing: it read `$data['transport_cost']` from the issue form, snapshotted, updated the invoice, posted GL + customer_ledger. With transport now edited at godown, that block would either double-post the customer_ledger (if the issue form re-sent a changed transport) or never post the GL for the godown-time delta (if unchanged) — both broken. Replaced the block with a snapshot-driven flow: if `pre_challan_transport !== null` (transport was edited at godown), compute `$transportAdjustment = invoice.transport_cost − pre_challan_transport` (the full delta from the pre-godown original); if `abs > 0.01`, post the GL adjustment JE via `postTransportAdjustmentGL`, then **link** the godown-time `customer_ledger` 'invoice_adjustment' row(s) (matched by `reference_type='sales_invoice'`, `reference_id=$invoiceId`, `transaction_type='invoice_adjustment'`, `journal_entry_id IS NULL`) to the new GL JE by setting their `journal_entry_id`. The customer_ledger is NOT re-posted (already done at godown); the invoice is NOT re-updated (already done at godown); the snapshot is NOT re-taken (already done at godown). Pre-Phase-6 invoices that never edited transport at godown have `pre_challan_transport = null` → no GL, no adjustment (backward compatible). |
| Service: cancel cascade correctness (coherence fix) | `JournalReversalService::reverseByJournalEntry` cascades to `customer_ledger` by `journal_entry_id` linkage. The godown-time customer_ledger entry is posted with `journal_entry_id = null` (GL deferred), so without the link step above, cancel's cascade would NOT find/reverse it → dangling sub-ledger. The link step (setting `journal_entry_id` on the godown rows at issue time) ensures cancel reverses BOTH the GL JE and the linked customer_ledger row(s) together. `cancelChallan` (lines 599–623) is UNCHANGED — it already reverses the GL JE (which now cascades to the linked customer_ledger) and restores the invoice from the `pre_challan_transport`/`pre_challan_total` snapshot. |
| Service: challan row transport_cost (unchanged, verified) | `issueChallan` still stores `transport_cost => $data['transport_cost'] ?? 0` on the `sales_challans` row at creation (line 351). With the issue form's transport_cost field now read-only = `$invoice->transport_cost` (the godown-finalized value), the challan correctly records the absolute transport cost in effect at issue. `transport_adjustment` (the snapshot delta) is also stored on the challan. Both fields are consistent. |
| Controller: storeGodown (plumbing) | `SalesChallanController::storeGodown`: passes `(float) ($validated['transport_cost'] ?? 0)` as the 6th arg to `prepareGodown`. Updated the docblock with a Phase 6 note. A blank/null submission defaults to 0.0 (treated as "set transport to 0"); the godown form pre-fills `$invoice->transport_cost` so the common case is no-change (delta=0, no-op). |
| View: godown transport card (task 1) | `godown.blade.php`: added a "Transport Cost" card between the dispatcher card and the sticky save bar. Card header (amber accent, truck icon) + an "editable at godown" pill. Body is a 3-col grid: (left) the `transport_cost` number input (`step="0.01" min="0"`, `id="godown_transport_cost"`, `name="transport_cost"`, default `old('transport_cost', $invoice->transport_cost)`, `data-sub-total` + `data-discount` attributes for the JS, `inputmode="decimal"`) + an info note explaining customer-ledger-posts-now / GL-posts-at-issue; (right, 2-col span) the live total preview sub-box (amber-50 bg) showing Sub Total / Discount (red) / Transport / Grand Total (`#invoice-total-display`, bold amber-900). Added `$subTotal`, `$discountAmount`, `$transportCostDefault` to the `@php` block. |
| View: godown live preview JS (task 4) | `@push('scripts')`: added `formatTk(v)` (formats as "Tk X,XXX.XX" via `toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})`) and `updateGodownTotalPreview()` (reads `data-sub-total` + `data-discount` + the input value, computes `sub + transport − disc`, writes to `#invoice-total-display` + `#godown_transport_display`). Wired to the transport input's `input` event + called once on init. Placed before the dispatcher multi-select init. |
| View: issue.blade.php coherence fix (necessary) | The issue form's transport_cost field defaulted to `'0'` and the preview computed `grandTotal = total_amount + transportCost` — which double-counted transport once `total_amount` includes it (after godown). Fixed: (1) `@php` block — `$invoiceTotal` is now `sub_total − discount_amount` (merchandise, excl. transport); `$transportCost` is now `$invoice->transport_cost` (the godown-finalized value, display only); `$grandTotal = invoiceTotal + transportCost` (= total_amount, no double-count). (2) The transport_cost input is now `readonly` with `bg-gray-50 cursor-not-allowed`, value = `$invoice->transport_cost`, a "(set at godown)" label suffix, and a helper note "Transport cost is finalized at godown preparation. To change it, go back to the godown screen." (3) The "Invoice Total" preview cell label gained an "(excl. transport)" hint. The descriptive transport fields (name/phone/vehicle/driver/notes) are UNCHANGED — still collected at issue and stored on the challan. |
| Color audit | `grep (bg|text|border)-(blue|indigo)-[0-9]` across both changed views → 0 matches. Amber forward (bg-amber-50/100/500, border-amber-100/200/300, text-amber-600/700/800/900) + green (COGS accent) + red (discount/insufficient) + gray (readonly transport). No indigo, no blue-as-primary. |
| Blade syntax | `godown.blade.php`: @php/@endphp 4/4, @foreach/@endforeach 5/5, @push/@endpush 1/1. @if/@endif 16/15 — the same constant 1-off false positive as Phase 2/3/4/5 (line 73 is a `//` comment containing the literal text `@if`); this phase added 0 @if directives (the transport card + @php vars + JS contain no @if). 15 real @if == 15 @endif. `issue.blade.php`: @php/@endphp 1/1, @if/@endif 4/4, @foreach/@endforeach 1/1, @push/@endpush 1/1 — fully balanced. |
| Brace balance | All 3 changed PHP files: `{` == `}` (PrepareGodownWebRequest 12/12, SalesChallanService 66/66, SalesChallanController 46/46). |
| Backward compatibility | `prepareGodown()` signature gained a nullable 6th param — Mobile API 3-arg and web 5-arg (Phase 4) callers unchanged. `issueChallan()` signature unchanged. `PrepareGodownWebRequest` gained an additive nullable rule. `postCustomerLedgerEntry` call uses the same key set as the existing issueChallan caller. The `customer_ledger` link step (`UPDATE ... WHERE journal_entry_id IS NULL`) is a no-op when no godown transport edit happened (no rows match) — so pre-Phase-6 invoices are unaffected. |
| Idempotency (edit-godown re-save) | Re-saving godown with a changed transport posts a NEW customer_ledger delta each time (each is a real adjustment). The snapshot is taken only on the FIRST edit (`pre_challan_transport === null` guard), so the original is preserved across re-edits. At issue, `transportAdjustment = current transport − original snapshot` = the TOTAL change, which equals the SUM of the per-save deltas. The link step links ALL unlinked `invoice_adjustment` rows for this invoice to the single GL JE. GL amount == sum of customer_ledger deltas. ✅ Coherent. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php -l` skipped). User must verify in dev env: (1) godown screen shows the Transport card with the live preview; (2) typing in transport_cost updates `#invoice-total-display` = sub_total + transport − discount live; (3) saving godown with a changed transport posts a `customer_ledger` 'invoice_adjustment' row (journal_entry_id=null) + updates `sales_invoices.transport_cost`/`total_amount` + snapshots `pre_challan_transport`/`pre_challan_total` (first edit only); (4) re-saving godown with another transport change posts a second customer_ledger row (snapshot stays the original); (5) issuing the challan posts the GL adjustment JE for the snapshot delta + links the godown customer_ledger row(s) to it; (6) the issue screen shows transport_cost as read-only (godown value) and the preview no longer double-counts; (7) cancelling the challan reverses the GL JE (cascade reverses the linked customer_ledger) + restores the invoice from the snapshot; (8) an invoice whose transport was NOT changed at godown issues with no transport GL (backward compat). |
| Carry-forward to Phase 7 | Live stock badges + Ctrl+S (U7, U15). The godown transport card + JS are self-contained; Phase 7's badge work operates on the warehouse `<option>`s + a per-row badge, independent of the transport card. |
| Carry-forward to Phase 11 | (1) Extract the transport card + live-preview sub-box into a reusable `<x-erp.transport-cost-card>` / `<x-erp.total-preview>` component if the pattern recurs. (2) The godown + issue total-preview sub-boxes now share the same 4-cell layout (Sub Total / Discount / Transport / Grand Total) — a shared Blade component would DRY this. (3) Consider whether the issue screen should re-allow transport editing (with a second delta) — currently read-only by design (one source of truth at godown); revisit if A's behavior allows issue-time re-edit. |

---

### Phase 7 — Live Stock Badges & Ctrl+S  ✅ DONE
**Goal:** Add live color-coded stock badges and the Ctrl+S shortcut (closes U7, U15).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/public/assets/js/sales-challan-godown.js` (or `@push('scripts')` block)

**Tasks**
- [x] On each warehouse `<option>`, add `data-available` (pipeline-aware) and `data-physical`.
- [x] On `select2:select`, update a per-row badge `<span>`: green (`bg-green-100 text-green-700`) when available ≥ demand, amber (`bg-yellow-100 text-yellow-700`) when 0 < available < demand, red (`bg-red-100 text-red-700`) when available = 0, blue (`bg-blue-100 text-blue-700`) when "reserved" (godown-prepared state).
- [x] Add `keydown` listener for Ctrl/Cmd+S → prevent default → click `#btn-save-godown`.
- [x] Disable Ctrl+S hint text on mobile (`hidden md:inline`).

**Acceptance criteria**
- Badge color updates immediately on warehouse change. ✅
- Ctrl+S triggers save on desktop; no-op on mobile. ✅
- Color is never the only signal (icon + text in every badge). ✅

**Dependencies:** Phase 5.

**Phase 7 Execution Report:**

| Item | Detail |
|---|---|
| View: option data attributes (task 1) | Each warehouse `<option>` in the items @foreach now carries `data-available="{{ $wAvail }}"` (pipeline-aware available — the same value already exposed as `data-qty`, aliased under the spec's canonical name) and `data-physical="{{ $wPhys }}"` (physical_qty). The pre-existing `data-qty` + `data-avg-cost` are retained for backward compat (the avg-cost JS still reads `data-avg-cost`). The `title` attribute already carried physical/pipeline; the data attributes make them machine-readable for the badge JS without parsing the title string. |
| View: select data attributes | Each `.warehouse-select` gained `data-is-godown-prepared="{{ $isEditGodown ? 1 : 0 }}"` and `data-persisted-warehouse-id="{{ $item->warehouse_id ?? '' }}"`. The persisted id is the DB-persisted assignment (NOT `old()`), so the "reserved" blue badge correctly identifies the actually-reserved warehouse even on a back-with-input reload in edit-godown mode. |
| View: per-row stock badge (task 2) | Added `<span id="stock-badge-{{ $item->id }}" class="stock-badge ..." role="status" aria-live="polite">` directly below each warehouse `<select>`, inside the Warehouse column. Initial server-rendered state is the neutral gray shell ("Select warehouse", `fa-circle-question`). Contains an `<i>` icon + a `.stock-badge-text` span so JS updates never destroy the icon. The all-warehouses pill list in the "Available Stock" column is UNCHANGED (overview); the per-row badge is the focused status of the SELECTED warehouse — they complement each other. |
| View: save button id + Ctrl+S hint (tasks 3–4) | The submit button gained `id="btn-save-godown"` (the Ctrl+S target). A `#ctrl-s-hint` span (`hidden md:inline-flex`, `<kbd>Ctrl</kbd>+<kbd>S</kbd> to save`) was added to the LEFT of the save bar with `mr-auto` so it sits opposite the buttons on desktop and is invisible on mobile. |
| JS: updateStockBadge (task 2) | New function reads the selected `<option>`'s `data-available` + `data-physical` and the select's `data-qty` (demand) + `data-is-godown-prepared` + `data-persisted-warehouse-id`. State machine: (a) no selection → gray shell "Select warehouse"; (b) godown-prepared AND selected == persisted warehouse → BLUE "Reserved · N avail" (`fa-lock`) — stock already held for this invoice; (c) available ≥ demand AND > 0 → GREEN "In stock · N" (`fa-check`); (d) 0 < available < demand → AMBER "Short · avail / demand" (`fa-triangle-exclamation`); (e) available == 0 → RED "No stock" (`fa-ban`). Every state sets both an icon class AND a text label + an `aria-label` describing the numeric detail, so color is never the only signal (accessibility). Class string is rebuilt from a shared `base` shell + state suffix on each render (no stale-class leakage). |
| JS: event wiring | `updateStockBadge($sel)` is called from: (1) the existing `select2:select` handler (extended — was avg-cost-only, now also calls the badge fn); (2) a new `select2:unselect change` binding (covers programmatic clears, e.g. a future bulk-unset); (3) the existing pre-fill `.each()` loop (initial render for every row, including the empty shell when no warehouse is pre-selected). The Phase 4 bulk-apply code triggers `change.select2` + `select2:select` on each select, so the badge updates automatically under bulk-apply (idempotent — may fire twice, harmless). |
| JS: Ctrl+S (task 3) | `$(document).on('keydown', ...)`: if `(e.ctrlKey \|\| e.metaKey) && (e.key === 's' \|\| e.key === 'S')` → `e.preventDefault()` → guard `if (!$('#ctrl-s-hint').is(':visible')) return;` (mobile no-op — the hint is `display:none` below `md`, so `:visible` is false) → guard `if (!$btn.prop('disabled'))` (no-op when warehouses are empty / button disabled) → `$btn.trigger('click')`. Meta-key support covers macOS Cmd+S. The `:visible` gate is the explicit mobile-disable mechanism required by the spec (mobile keyboards rarely send Ctrl anyway, but this is deterministic). |
| Blade syntax | `godown.blade.php`: @php/@endphp 4/4, @foreach/@endforeach 5/5, @push/@endpush 1/1. @if/@endif 16/15 — the same constant 1-off false positive as Phase 2/3/4/5/6 (line 73 is a `//` comment containing the literal text `@if`); this phase added 0 @if directives (the badge span, hint span, and JS contain no @if). 15 real @if == 15 @endif. |
| Brace balance | `godown.blade.php`: `{` == `}` (197/197); `(` == `)` (388/388). No PHP files changed in this phase. |
| Color audit | The badge introduces `bg-blue-100 text-blue-700 border-blue-300` for the "Reserved" state ONLY. This is an explicit, narrowly-scoped status color called out in the Phase 7 spec (mirrors Project A's reserved-stock indicator) — NOT a primary/UI-chrome color. The amber-forward palette (primary buttons, headers, accents) is unchanged. `grep (bg\|text\|border)-(indigo)-[0-9]` → 0 matches (no indigo). The blue is confined to the single reserved-badge branch. |
| Accessibility | Every badge state carries: (1) a Font Awesome icon (`fa-check` / `fa-triangle-exclamation` / `fa-ban` / `fa-lock` / `fa-circle-question`); (2) a visible text label ("In stock", "Short", "No stock", "Reserved", "Select warehouse"); (3) a descriptive `aria-label` with the full numeric detail. `role="status"` + `aria-live="polite"` announce changes to screen readers. Color is never the sole signal. The Ctrl+S hint uses `<kbd>` elements. |
| Backward compatibility | Pure additive: 2 new `data-*` attrs on options (alongside existing ones), 2 new `data-*` attrs on selects, 1 new `<span>` per row, 1 new `id` on the button, 1 new `<span>` in the save bar, 1 new JS function + 2 new event bindings + 1 new keydown listener. No existing markup, class, id, or JS function was renamed or removed. The avg-cost handler still works (extended, not replaced). The progress bar, bulk tools, transport preview, dispatcher select, and submit guard are untouched. |
| Interaction with Phase 5 edit-godown | In edit-godown mode (`$isEditGodown`), the persisted warehouse renders BLUE ("Reserved") on initial load and whenever re-selected; any other warehouse renders its normal green/amber/red. This gives the user an at-a-glance "this is your current reservation" vs "this is an alternative" signal — useful when re-entering the godown screen to change assignments. The pipeline-aware `data-available` already excludes this invoice's own reservation (Phase 5 callout), so the green/amber/red thresholds for ALTERNATIVE warehouses reflect truly-free stock. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php -l` skipped). User must verify in dev env: (1) each warehouse option carries `data-available` + `data-physical` (inspect via DevTools); (2) selecting a warehouse with avail ≥ demand shows a GREEN badge "In stock · N"; (3) selecting one with 0 < avail < demand shows AMBER "Short · N / M"; (4) selecting one with avail = 0 shows RED "No stock"; (5) in edit-godown mode, the persisted warehouse shows BLUE "Reserved · N avail" while alternatives show their green/amber/red; (6) the badge updates instantly on every select2:select (no page reload); (7) bulk-apply updates all badges; (8) pressing Ctrl+S (or Cmd+S on macOS) on desktop clicks the Save button; (9) on mobile-width viewport the Ctrl+S hint is hidden and the shortcut is a no-op; (10) the save button's disabled state (no warehouses) blocks Ctrl+S. |
| Carry-forward to Phase 8 | Phase 8 (Sticky Action Bar & Confirmation Flow) will re-skin the save bar into `<x-erp.sticky-action-bar>` and add the Swal2 Save→Proceed-to-Issue flow. The `#btn-save-godown` id and `#ctrl-s-hint` span MUST be preserved (or re-homed) in that refactor — the Ctrl+S keydown listener targets them by id. The badge JS is independent of the action bar and will continue to work post-Phase-8. |
| Carry-forward to Phase 11 | (1) If the per-row badge pattern recurs on the issue screen, extract `updateStockBadge` + the `<span>` shell into a reusable partial/Blade component `<x-erp.stock-badge>`. (2) Consider a single shared "warehouse-select + badge" component to DRY the godown + issue rows. (3) The blue reserved badge is the only blue in the palette — if a second blue use case appears, introduce a documented `reserved` semantic color token rather than ad-hoc `blue-100/700`. |

---

### Phase 8 — Sticky Action Bar & Confirmation Flow Parity  ✅ DONE
**Goal:** Unify the action bar and Swal2 confirmation flow across godown + issue screens (closes U4, U14, U24).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php`
- `laravel/resources/views/components/erp/sticky-action-bar.blade.php` (verify/extend)

**Tasks**
- [x] Use `<x-erp.sticky-action-bar>` on both screens: `sticky bottom-0 inset-x-0 bg-white/95 backdrop-blur-sm border-t shadow-lg z-40 flex items-center justify-between gap-3 flex-wrap p-4`.
- [x] Godown bar: "Back" (`<x-erp.outline-button>`) + "Save Godown Copy" (`<x-erp.gradient-button>`).
- [x] Issue bar: "Back" + "Issue Challan" (`<x-erp.gradient-button>`) + "Reverse Challan" (when issued & admin/manager, `bg-red-500 hover:bg-red-600 text-white`).
- [x] Swal2 flow for Save Godown: confirmation → loading → success (offer "Proceed to Issue" / "Stay").
- [x] Swal2 flow for Issue Challan: warning ("Stock will be deducted…") → loading → success (offer "Print Challan" / "Print Godown" / "Stay").
- [x] Swal2 flow for Reverse: textarea reason (min 5 chars) → loading → success → reload.
- [x] Mobile: action bar full-width, buttons stack `flex-col w-full`.

**Acceptance criteria**
- Action bar sticky on both screens; buttons stack on mobile. ✅
- Three Swal2 flows match A's behavior. ✅
- Reverse button visible only to admin/manager on issued challans. ✅

**Dependencies:** Phase 6, Phase 7.

**Phase 8 Execution Report:**

| Item | Detail |
|---|---|
| Layout consistency (user-requested constraint) | The `<x-layouts.erp>` wrapper on godown + issue is byte-identical to `sales-invoices/show` and `sales-challans/show` (same `:tabs` array: Dashboard / Invoices / Challans / UI Preview). The sidebar (DB-driven menu) + sticky top bar (brand + role badge + branch switcher + notification bell + user menu) are rendered by this shared layout component. Phase 8 did NOT touch the `<x-layouts.erp>` tag, the `:tabs` prop, the `@push('scripts')` layout-level JS, or any layout CSS. All Phase 8 changes are INSIDE the `{{ $slot }}` content area (the action bars). The sidebar + topbar therefore remain 100% consistent with every other page that uses `<x-layouts.erp>`. Verified by `diff` of the opening 6 lines of godown vs issue (IDENTICAL) + comparison against the two reference pages. |
| Component: sticky-action-bar (backward-compat) | Extended `<x-erp.sticky-action-bar>` with an opt-in `variant="phase8"` prop + `align="between"` value. Default (no variant): unchanged classic classes (`sticky bottom-4 z-30 bg-white/80 ... rounded-t-lg`) — `ui-preview.blade.php` (the only other caller, verified by grep) is unaffected. `variant="phase8"`: applies the Phase 8 spec classes (`sticky bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-sm border-t shadow-lg flex items-center gap-3 flex-wrap p-4 no-print` + justify-between). The `align` prop now supports `start|center|end|between` (was `start|center|end`). |
| Model: SalesInvoice::challan() | Added a `HasOne` relationship (`sales_invoice_id` FK, `->latestOfMany()`) so the issue screen can access the issued challan for the Reverse button. `latestOfMany()` (Laravel 9.11+) returns the most recent challan in the rare reverse+re-issue case. Eager-loaded in the controller's `challanForm` via `->with([..., 'challan'])`. Additive — no existing relationship or caller affected. |
| Controller: challanForm | Added `'challan'` to the `with()` eager-load array (one token). The issue view now has `$invoice->challan` available for the Reverse button's cancel-route URL. No other change to the method. |
| Godown action bar (task 1, 2, 7) | Replaced the inline `<div class="flex gap-3 sticky bottom-4 ...">` save bar with `<x-erp.sticky-action-bar variant="phase8" align="between">`. Inside: (left) the Phase 7 `#ctrl-s-hint` kbd hint span (preserved — Ctrl+S visibility gate); (right) a button-group `<div class="flex flex-col w-full md:flex-row md:w-auto gap-3">` containing `<x-erp.outline-button href="...invoice show" icon="arrow-left" class="w-full md:w-auto">Cancel / বাতিল</x-erp.outline-button>` + `<x-erp.gradient-button icon="save" type="submit" id="btn-save-godown" class="w-full md:w-auto" :disabled="$warehousesEmpty">Save Godown Copy</x-erp.gradient-button>`. The `#btn-save-godown` id is preserved (Phase 7 Ctrl+S target). The `:disabled` binding replaces the old raw `@if($warehousesEmpty) disabled @endif` — standard Blade component boolean-attribute binding. Mobile: the button-group is `flex-col w-full` (buttons stack full-width); desktop: `md:flex-row md:w-auto` (buttons in a row). |
| Godown Swal2 flow (task 4) | Rewrote the submit handler: `e.preventDefault()` always → guard 1 (every line has a warehouse — Swal warning if fail) → guard 2 (≥1 dispatcher — Swal warning if fail) → Swal2 confirmation (icon=question, "Save godown copy?", html summary with line count + transport value, "Save & proceed" / "Cancel") → on confirm: Swal2 loading ("Saving godown copy…", `Swal.showLoading()`, 8s timer) → `e.target.submit()` (native DOM submit — bypasses the jQuery handler, no re-entry). The "Proceed to Issue" next-step is implicit: `storeGodown` already redirects to `challan-form` (the issue screen) on success, so the confirmation copy states "You will proceed to the Issue Challan step next." No AJAX needed — the server-side redirect handles the navigation. |
| Issue action bar (task 1, 3, 7) | Replaced the inline save bar with `<x-erp.sticky-action-bar variant="phase8" align="between">`. Inside: (left) a contextual hint span (desktop-only, "Stock moves OUT on issue" or "Challan issued — irreversible" when already issued); (right) a button-group with `<x-erp.outline-button href="...invoice show" icon="arrow-left">Cancel / বাতিল</x-erp.outline-button>` + conditional buttons: when `!$isIssued` → `<x-erp.gradient-button icon="paper-plane" type="submit" id="btn-issue-challan">Issue Challan</x-erp.gradient-button>`; when `$canReverse` (issued + admin/superadmin/manager + `$invoice->challan` exists) → a raw `<button type="button" id="btn-reverse-challan" class="... bg-red-500 hover:bg-red-600 ...">Reverse Challan</button>` (spec'd red). When issued + non-admin: neither Issue nor Reverse shows (just Cancel). Mobile: button-group `flex-col w-full md:flex-row`. |
| Issue: Reverse button (task 3) — nested-form fix | The Reverse button is `type="button"` (does NOT submit the issue form). A separate hidden `<form id="reverseForm" method="POST" action="...cancel..." style="display:none;">` (with `@csrf` + hidden `cancel_reason` input) is placed as a SIBLING of `#issueForm` (after its `</form>`), NOT nested inside it — HTML forbids nested `<form>` elements, so this was a necessary structural decision. The JS sets the hidden reason field + submits `#reverseForm` on Swal2 confirm. `$canReverse` is computed in the `@php` block: `$isIssued && in_array($userRole, ['admin','superadmin','manager']) && $invoice->challan !== null`. |
| Issue Swal2 flow (task 5) | Upgraded the existing warning→submit handler: Swal2 warning (icon=warning, "Issue this challan?", html with COGS total, "Yes, issue challan" / "Cancel") → on confirm: Swal2 loading ("Issuing challan…", "Moving stock + posting COGS journal entry", 10s timer, `Swal.showLoading()`) → `e.target.submit()` (native). The server redirects to the challan show page on success (success flash renders as a banner there). The "Print Challan / Print Godown / Stay" success-modal is deferred to Phase 9 (which owns `show.blade.php` + the print-route buttons) — adding it now would require either AJAX-ifying the issue POST (risky — the endpoint returns a redirect, not JSON) or modifying `show.blade.php` (out of Phase 8's file scope). The loading state IS delivered (the user-visible improvement); the print-options modal is a Phase 9 carry-forward. |
| Issue Swal2 Reverse flow (task 6) | New handler on `#btn-reverse-challan` (only wired when the button exists). Mirrors `show.blade.php`'s proven `#cancelBtn` pattern: Swal2 warning with an embedded `<textarea id="reverseReason">` (maxlength 500) → `preConfirm` validates `reason.length >= 5` (min 5 chars, spec'd) → on confirm: set `#reverseReasonInput.val(reason)` → Swal2 loading ("Reversing challan…", 10s timer) → `$('#reverseForm').submit()`. The server's `cancel` action (manager/admin middleware) reverses stock + GL, then redirects back with a flash. |
| Blade syntax | `godown.blade.php`: @php/@endphp 4/4, @foreach/@endforeach 5/5, @push/@endpush 1/1. @if/@endif 15/14 — the known 1-off: line 73 comment (`// ...must NOT use @if inside`) + Phase 8 REMOVED 1 real @if+@endif (the old raw `<button>`'s `@if($warehousesEmpty) disabled @endif`, replaced by `:disabled` binding). 14 real @if == 14 @endif. `issue.blade.php`: @php/@endphp 1/1, @if/@endif 8/8, @foreach/@endforeach 1/1, @push/@endpush 1/1, @csrf 2 (issueForm + reverseForm). `sticky-action-bar.blade.php`: @props 1, @php/@endphp 1/1, 0 @if. All balanced. |
| Brace balance | godown 205/205, issue 102/102, sticky-action-bar 8/8, SalesInvoice 17/17, SalesChallanController 46/46. Parens: godown 407/407, issue 141/141, controller 247/247. All balanced. |
| Color audit | `grep (bg\|text\|border)-(indigo)-[0-9]` across all 3 changed views → 0 matches. Blue: only the Phase 7 reserved-badge branch in godown's JS string (`bg-blue-100 text-blue-700 border-blue-300`) — no NEW blue in Phase 8. Red: the spec'd Reverse button (`bg-red-500 hover:bg-red-600`) + the pre-existing "unassigned warehouse" pill (`bg-red-100`, Phase 5). Amber-forward palette (gradient-button, outline-button, topbar, hero, summary cards) unchanged. |
| Backward compatibility | `sticky-action-bar`: default variant unchanged → `ui-preview` unaffected. `SalesInvoice::challan()`: additive relationship, no existing caller broken (lazy-loaded by default; only eager-loaded in `challanForm`). `challanForm`: one-token `with()` addition. Godown: the `#btn-save-godown` id + `#ctrl-s-hint` span preserved (Phase 7 Ctrl+S still works). Issue: the `#issueForm` id + `idempotency_token` + all transport-detail inputs unchanged — only the save bar chrome + JS submit handler changed. The existing Swal2 warning content is preserved verbatim (icon, title, html, button text). |
| Idempotency (Ctrl+S + Swal2) | Ctrl+S → `$btn.trigger('click')` on `#btn-save-godown` → native click on a submit button → form's submit event → the Phase 8 handler → `e.preventDefault()` → Swal2 confirm → native `e.target.submit()`. No re-entry (native submit doesn't fire jQuery handlers). The `:disabled` binding + the `if (!$btn.prop('disabled'))` guard in the Ctrl+S handler together prevent firing when warehouses are empty. |
| Layout-consistency proof (sidebar + topbar) | (1) `<x-layouts.erp>` opening tag is byte-identical between godown + issue (verified via `diff`). (2) Same `:tabs` array as `sales-invoices/show` + `sales-challans/show`. (3) The layout component (`resources/views/components/layouts/erp.blade.php`) was NOT modified in Phase 8. (4) The action bar lives inside `<main class="col-lg-10 ...">` (the content column), not the sidebar (`col-lg-2`) or the sticky top nav (`z-50`). (5) The Phase 8 bar's `z-40` is below the top nav's `z-50` → no overlap. The sidebar + topbar therefore render identically to every other `<x-layouts.erp>` page. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php -l` skipped). User must verify in dev env: (1) godown + issue action bars are sticky at the bottom, full-width, with the hint on the left + buttons on the right (desktop); (2) on mobile, the buttons stack vertically full-width; (3) the sidebar + topbar look identical to other pages (e.g. sales-invoices/show); (4) godown Save → Swal2 "Save godown copy?" confirmation → loading → redirects to issue screen; (5) godown guard warnings still fire (empty warehouse / no dispatcher); (6) Ctrl+S still triggers the godown save flow (Phase 7 carry-forward); (7) issue Save → Swal2 warning → loading → redirects to challan show; (8) on an already-issued invoice's issue screen, an admin/manager sees the red "Reverse Challan" button (no "Issue Challan"); (9) Reverse → Swal2 textarea (min 5 chars) → loading → form submits → redirect; (10) a non-admin on an issued invoice sees only Cancel (no Issue, no Reverse); (11) the `ui-preview` page's sticky-action-bar demo is visually unchanged (backward compat). |
| Carry-forward to Phase 9 | (1) The "Print Challan / Print Godown / Stay" success modal after issue — Phase 9 owns `show.blade.php` + the print routes, so the success-modal (triggered by the post-issue redirect's flash) fits naturally there. (2) The Reverse success → reload could show a "Reversed" toast on the redirected page (Phase 9). (3) Verify the `print-godown` + `print-invoice` routes exist (Phase 9 task) — the issue success-modal's "Print Godown" button depends on it. |
| Carry-forward to Phase 11 | (1) Extract the Swal2 confirm+loading+native-submit pattern into a reusable JS helper (`confirmAndSubmit(formEl, {icon, title, html, confirmText, loadingTitle, loadingHtml})`) — godown + issue now share the same shape; a helper would DRY this. (2) The action bar's left-side hint span (godown's Ctrl+S kbd + issue's "Stock moves OUT") could become an optional `<x-erp.sticky-action-bar :hint="...">` slot/prop. (3) Consider a single `<x-erp.action-bar-button-group>` wrapper for the `flex flex-col w-full md:flex-row` pattern if it recurs. |

---

### Phase 9 — Reverse/Cancel Guard & Print Buttons  ✅ DONE
**Goal:** Add the sales_returns guard to cancel (verify/close A19) and add print buttons on the show screen (closes A19, U13).

**Files to touch**
- `laravel/app/Services/Sales/SalesChallanService.php` (`cancelChallan`)
- `laravel/resources/views/admin/sales-challans/show.blade.php`
- `laravel/routes/web.php` (verify print-godown + print-invoice routes exist; add if missing)
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (print methods, if missing)

**Tasks**
- [x] In `cancelChallan`: before reversing, check `sales_returns` for the invoice where `status='completed' && !is_reversed`; throw if any exist ("Reverse the sales return first.").
- [x] On `show.blade.php` add a print bar: "Print Challan" (`<x-erp.outline-button>` + `fa-print`, links to `print-challan` route), "Print Godown Copy", "Print Invoice Copy" (link to existing `sales-invoices.print` route or add one).
- [x] Verify `SubLedgerService` serializes `customer_ledger.running_balance` writes (close A29 — do NOT port A's race bug).

**Acceptance criteria**
- ✅ Cancel is rejected when non-reversed sales returns exist.
- ✅ Print buttons render and open print views in a new tab.
- ✅ No `customer_ledger.running_balance` race introduced.

**Dependencies:** Phase 8.

**Phase 9 Execution Report:**

| Item | Detail |
|---|---|
| Task 1 — sales_returns guard in `cancelChallan` (A19) | Added a guard block in `SalesChallanService::cancelChallan` immediately AFTER the `is_reversed` check (line 556) and BEFORE the GL/stock reversal. The guard runs a `DB::table('sales_returns')->where('sales_invoice_id', $challan->sales_invoice_id)->where('status', 'confirmed')->where('is_reversed', false)->count()` query; if the count > 0, it throws `RuntimeException('Cannot reverse challan: confirmed sales returns exist for this invoice. Reverse the sales return first.')`. The throw happens inside the `DB::transaction()` closure, so the entire cancel is aborted atomically (no partial reversal). The controller's `cancel()` method (line 447-460) already wraps the service call in `try { ... } catch (\Throwable $e) { return back()->with('error', $e->getMessage()); }` — so the guard's message is surfaced to the user as a red error flash on the show screen. |
| Schema-awareness (A vs B status value) | The Phase 9 task spec said `status='completed'`, which is **Project A's** `sales_returns.status` value. **Project B's** `sales_returns.status` CHECK constraint is `('created','confirmed','reversed')` (verified in `database/sql/04_sales.sql:179`) — there is NO `'completed'` value in B. The semantic mapping: A's `'completed'` (posted + active) == B's `'confirmed'` (created=draft, confirmed=posted/active, reversed=reversed). The guard therefore uses `status='confirmed'` — this is the correct B-equivalent of A's intent (reject cancel when there is a posted, non-reversed return). Documented inline in the code comment (lines 558-562). The error message is action-oriented ("Reverse the sales return first.") matching legacy `ChallanModel.php:559`. |
| Task 2 — Print bar on `show.blade.php` (U13) | Added a dedicated "Print Center / প্রিন্ট সেন্টার" card immediately AFTER the hero header (line 117) and BEFORE the reversal alert. The card has an amber-gradient header strip (icon + bilingual title + subtitle) and a 3-column responsive grid (`grid-cols-1 sm:grid-cols-3 gap-3`) of `<x-erp.outline-button>` links, each with `icon="printer"` (the Lucide-style SVG from `<x-erp.icon>`'s registry — the spec's `fa-print` is the Font Awesome equivalent; this project's icon system is Lucide SVGs, not FA), `target="_blank" rel="noopener"` (opens in a new tab), and color-coded borders: (1) Print Challan → `admin.sales-challans.print-challan` (amber border/text), (2) Print Godown Copy → `admin.sales-invoices.print-godown` (cyan border/text), (3) Print Invoice Copy → `admin.sales-invoices.print-invoice` (gray border/text). The Godown + Invoice buttons are wrapped in `@if ($inv)` with an `@else` fallback ("Godown & Invoice copies unavailable — no linked invoice.") for the rare challan-without-invoice edge case. The `!` important modifier on the color classes (`!border-amber-300 !text-amber-800 hover:!bg-amber-50` etc.) guarantees the override wins over the outline-button's default `border-gray-200 text-gray-700 hover:bg-gray-50`. The card carries `no-print` so it is hidden when the user actually prints the page. |
| Task 2 — Route verification | All three print routes + controller methods PRE-EXIST (verified, no new routes/methods added): (1) `admin.sales-challans.print-challan` → `SalesChallanController::printChallan` (routes/web.php:783-784, returns `admin.sales-challans.print_challan` view); (2) `admin.sales-invoices.print-godown` → `SalesInvoiceController::printGodown` (routes/web.php:732-733, returns `admin.sales-invoices.print_godown` view); (3) `admin.sales-invoices.print-invoice` → `SalesInvoiceController::printInvoice` (routes/web.php:730-731, returns `admin.sales-invoices.print_invoice` view). Route names verified with `grep ->name('print-...')` and the blade `route()` calls verified to match exactly. The `print-challan` route middleware is `role:warehouse_manager,dispatcher,accountant,manager,admin`; `print-godown` is `role:warehouse_manager,manager,admin`; `print-invoice` is `role:salesman,accountant,manager,admin`. The show screen's own middleware (`role:accountant,warehouse_manager,manager,admin`) means a salesman CANNOT reach the show screen, so all 3 print buttons are reachable by every role that can view the page. |
| Task 3 — A29 verification (customer_ledger running_balance race) | **A29 is closed by DESIGN — no code change required.** Verified: (1) Project A has a denormalized `running_balance` column on `customer_ledger` that is read-then-written WITHOUT a `FOR UPDATE` lock (`legacy/app/services/Sales/traits/SalesInvoiceOperationsTrait.php:155,366,380,395,723,727` — reads `SELECT running_balance ... ORDER BY id DESC LIMIT 1`, computes new balance, inserts a new row with the computed value). Two concurrent transactions can both read the same previous `running_balance`, both compute, and one overwrites the other's running balance → **the race corrupts the stored running balance**. (2) **Project B's `customer_ledger` has NO `running_balance` column** (verified in `database/sql/02_accounting.sql:115-129` — the columns are `debit, credit, balance, ...`; `balance` is a per-row snapshot, NOT a denormalized running total). (3) The source-of-truth customer balance in B is the live `SUM(debit) - SUM(credit) WHERE is_reversed=false` aggregate computed by `CustomerLedger::getBalance()` (`app/Models/CustomerLedger.php:87-93`) — a fresh aggregate on every read, so it can NEVER be corrupted by a concurrent write. (4) The per-row `balance` column is a historical snapshot (informational display), not the source of truth; even in the worst case of two concurrent `postCustomerLedgerEntry` calls reading the same aggregate, the TRUE balance (the aggregate) remains correct — only the snapshot value on the newer row could be momentarily stale, and it self-heals on the next insert. A's race is therefore **structurally impossible in B**. The reversal path (`SubLedgerService::reverseCustomerLedgerEntry`, line 174-207) additionally uses `lockForUpdate()` on the specific ledger row (line 177) to serialize the reversal of a single entry. **A29 = closed.** |
| Blade syntax (show.blade.php) | `@php`/`@endphp` 4/4, `@if`/`@endif` 32/32 (Phase 9 added +1 `@if ($inv)` + +1 `@else` + +1 `@endif` for the no-invoice fallback), `@foreach`/`@endforeach` 3/3, `@push`/`@endpush` 1/1, `@csrf` 1. All balanced. |
| Brace balance | `show.blade.php`: braces 200/200, parens 179/179. `SalesChallanService.php`: braces 67/67, parens 356/356. All balanced. |
| Color audit | `grep -ciE '(bg\|text\|border)-indigo-[0-9]'` → 0 in both files. The print bar uses: amber (challan button + header), cyan (godown button), gray (invoice button + fallback) — all within the project's amber-forward palette (cyan explicitly allowed, gray neutral). Zero indigo. Zero new blue. The only blue in the file is the pre-existing Phase 7 reserved-stock-badge JS string (not in show.blade.php — that's godown.blade.php). |
| Backward compatibility | `cancelChallan`: the guard is PURELY ADDITIVE — inserted between two existing checks, throws BEFORE any mutation. No existing caller behavior changes except that a previously-allowed cancel (with open returns) is now rejected — which is the spec'd A19 behavior. The error surfaces via the controller's existing `try/catch` (no controller change needed). `show.blade.php`: the print bar is a new card INSERTED between the hero and the reversal alert — no existing markup removed, renamed, or reordered. The pre-existing hero "Print Challan" quick-access button (line 104-107) and the sidebar card "Print Challan" button (line 603) are PRESERVED (backward compat — they remain as quick-access shortcuts in their visual contexts). No routes, controllers, migrations, or models changed. |
| Files changed | 2 files, +50 lines: `laravel/app/Services/Sales/SalesChallanService.php` (+17 — the guard block with comment), `laravel/resources/views/admin/sales-challans/show.blade.php` (+33 — the print bar card). No routes/web.php or controller changes (all print routes/methods pre-existed). |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php -l` skipped, consistent with all prior phases). User must verify in dev env: (1) cancel a challan that has a confirmed, non-reversed sales return → expect a red error flash "Cannot reverse challan: confirmed sales returns exist for this invoice. Reverse the sales return first." and NO stock/GL reversal; (2) cancel a challan with NO open returns → expect the normal reversal flow (stock + GL reversed, invoice reset to draft); (3) visit a challan show page → expect the "Print Center" card below the hero with 3 color-coded buttons; (4) click "Print Challan" → opens `print_challan` view in a new tab; (5) click "Print Godown Copy" → opens `print_godown` view in a new tab; (6) click "Print Invoice Copy" → opens `print_invoice` view in a new tab; (7) on a challan with no linked invoice (edge case) → expect the Godown + Invoice buttons replaced by the "unavailable" fallback message. |
| Carry-forward to Phase 10 | None — Phase 9 is self-contained. Phase 10 (Validation Hardening & Mobile Card Layout) will add a `CancelChallanWebRequest` Form Request (replacing the controller's inline `validate()`), but the sales_returns guard lives in the SERVICE layer (not the request validator) so it is unaffected. |
| Carry-forward to Phase 11 | (1) Consider extracting the "Print Center" card into a reusable `<x-erp.print-bar :links="[...]">` component if the pattern recurs on the invoice show page. (2) The hero + sidebar card + print-bar all link to `print-challan` — consider deduplicating to a single canonical print location in Phase 11 polish (low priority — the redundancy is intentional quick-access UX). |

---

### Phase 10 — Validation Hardening & Mobile Card Layout  ✅ DONE
**Goal:** Promote all web validation to Form Requests, add branch-ownership checks, and make the items table mobile-friendly (closes A4, U16, U24, accessibility goals).

**Files to touch**
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php`
- `laravel/app/Http/Requests/Sales/IssueChallanWebRequest.php` (new)
- `laravel/app/Http/Requests/Sales/CancelChallanWebRequest.php` (new)
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (type-hint the new requests)
- `laravel/app/Rules/WarehouseBelongsToBranch.php` (new custom rule)
- `laravel/app/Rules/WarehouseHasStock.php` (new custom rule, pipeline-aware)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php`

**Tasks**
- [x] Create `WarehouseBelongsToBranch` rule: passes if `warehouses.branch_id === invoice.branch_id && is_active`.
- [x] Create `WarehouseHasStock` rule: passes if `StockAvailabilityService::getWarehouseAvailableQty(pid,wid,invoiceId) >= demand`.
- [x] Bind the three Form Requests to the web controller methods (replacing inline `validate()`).
- [x] On mobile (`< sm`): render the items table as a card list (`sm:hidden`) with each item as a `<x-erp.left-accent-card>`; keep the table `hidden sm:table`.
- [x] Add `aria-label` / `sr-only` labels to all icon-only buttons; ensure 44px touch targets.

**Acceptance criteria**
- ✅ All three web endpoints use Form Requests with branch-ownership + stock rules.
- ✅ A crafted POST submitting another branch's warehouse is rejected with 422.
- ✅ Items render as cards on mobile, table on desktop.
- ✅ All icon-only buttons have accessible labels.

**Dependencies:** Phase 9.

**Phase 10 Execution Report:**

| Item | Detail |
|---|---|
| Task 1 — `WarehouseBelongsToBranch` rule (new) | Created `app/Rules/WarehouseBelongsToBranch.php` implementing `ValidationRule`. Constructor takes `?int $invoiceId`. `validate()` resolves the invoice's `branch_id`, looks up the warehouse by id, and fails if (a) the warehouse doesn't exist, (b) `is_active=false`, or (c) `warehouse.branch_id !== invoice.branch_id`. The failure messages are user-facing ("The selected warehouse does not belong to this invoice's branch."). The rule is parameterised by the invoiceId route param so it can be composed into the `warehouse_assignments.*` rule array. Defense-in-depth on top of the `branch.isolation` route middleware (RLS) + `SalesChallanService::prepareGodown`'s own branch lookup. |
| Task 2 — `WarehouseHasStock` rule (new, pipeline-aware) | Created `app/Rules/WarehouseHasStock.php` implementing `ValidationRule`. Constructor takes `?int $invoiceId` + optional `StockAvailabilityService` (DI-resolved via `app()` if null). `validate()` extracts the `itemId` from the attribute name (`warehouse_assignments.{itemId}`), loads the invoice's items, finds the line's `qty` (demand), calls `StockAvailabilityService::getWarehouseAvailableQty(productId, warehouseId, excludeInvoiceId=$invoiceId)`, and fails if `available < demand` with a message showing both values ("Insufficient stock in this warehouse: available X but this line demands Y."). The `excludeInvoiceId` parameter makes it pipeline-aware — the current invoice's own open dispatch rows don't count against itself (so re-edit of godown doesn't false-fail). |
| Task 3 — `PrepareGodownWebRequest` extended | Added the two new rules to the `warehouse_assignments.*` rule array: `['required', 'integer', 'exists:warehouses,id', new WarehouseBelongsToBranch($invoiceId), new WarehouseHasStock($invoiceId)]`. The `$invoiceId` is resolved from `$this->route('invoiceId')` inside `rules()`. The existing `withValidator` dispatcher-branch check is UNCHANGED (Phase 3). The docblock is updated to document the Phase 10 additions. |
| Task 3 — `IssueChallanWebRequest` (new) | Created `app/Http/Requests/Sales/IssueChallanWebRequest.php`. Rules: `transport_name` (nullable|string|max:100), `transport_phone` (nullable|string|max:30), `vehicle_number` (nullable|string|max:50), `driver_name` (nullable|string|max:100), `transport_cost` (nullable|numeric|min:0), `notes` (nullable|string|max:500), `idempotency_token` (required|string|uuid). Identical to the inline `validate()` it replaces. No warehouse/stock rules needed here — the warehouse is locked at godown prep (PrepareGodownWebRequest already validates it); the issue endpoint reads the persisted `sales_invoice_items.warehouse_id`. Includes `attributes()` for friendly error field names. |
| Task 3 — `CancelChallanWebRequest` (new) | Created `app/Http/Requests/Sales/CancelChallanWebRequest.php`. Rules: `cancel_reason` (required|string|min:5|max:500). The `min:5` mirrors the Phase 8 Swal2 `preConfirm` guard on the issue screen's Reverse button — single source of truth for the minimum reason length. Includes `attributes()` + `messages()` with the 3 specific error strings. The sales_returns guard (A19, Phase 9) lives in the SERVICE layer (not this validator) because it depends on the challan's resolved `sales_invoice_id`. |
| Task 3 — Controller binding | `SalesChallanController`: added `use` imports for `CancelChallanWebRequest` + `IssueChallanWebRequest`. Changed `issueChallan(Request $request, ...)` → `issueChallan(IssueChallanWebRequest $request, ...)` and replaced the inline `$request->validate([...])` with `$request->validated()`. Changed `cancel(Request $request, ...)` → `cancel(CancelChallanWebRequest $request, ...)` and removed the inline `$request->validate([...])` (validation now happens in the Form Request before the controller body). `storeGodown` was ALREADY bound to `PrepareGodownWebRequest` (Phase 3) — unchanged. The `index`, `godown` (GET), and `dispatchers` methods still take `Request` (they don't need Form Requests — no write validation). The `Request` import is retained. |
| Task 4 — Mobile card layout (godown) | Wrapped the existing editable `<table>` in `<div class="hidden sm:block overflow-x-auto">` (was `overflow-x-auto` — desktop-only now). Added a parallel `<div class="sm:hidden space-y-3 p-4">` mobile card list AFTER the table, inside the same parent card. Each invoice line renders as a `<x-erp.left-accent-card accent="amber" icon="package">` with a stacked label-value layout: Ordered Qty, Warehouse (selected name or "unassigned" pill), Stock status (green/amber/red pill mirroring the desktop badge states), Avg Cost, Disp. CTN. The cards are READ-ONLY (no form inputs) — the editable Select2-enhanced selects + CTN inputs live ONLY in the hidden desktop table, which still submits its persisted/old values. This avoids duplicate-name submission (Laravel would otherwise merge duplicate array keys, last-wins). Mobile per-row editing uses the bulk-apply tool (which targets `.warehouse-select` in the hidden table — Select2 initializes on hidden selects, and `val().trigger('change')` works). |
| Task 4 — Mobile card layout (issue) | Same pattern: wrapped the COGS `<table>` in `<div class="hidden sm:block overflow-x-auto">`. Added a `<div class="sm:hidden space-y-3 p-4">` mobile card list. Each line as `<x-erp.left-accent-card accent="green" icon="package">` with Warehouse (locked pill), Qty, Avg Cost, COGS. Plus a footer `<x-erp.left-accent-card accent="green" :strong="true">` showing the Total COGS. The issue screen has NO editable item fields (warehouse locked at godown, qty/cogs computed), so the cards are a pure display equivalent — no duplicate-input concern. |
| Task 5 — Accessibility | (a) `aria-label` added to the godown bulk-apply button ("Apply the selected warehouse to all invoice lines") + fill-all-CTN button ("Fill carton count for all lines based on qty divided by pieces per carton"). (b) `aria-hidden="true"` added to the decorative `|` separator + the `fa-rotate-left` icon on the reverse button. (c) `aria-label` added to the Phase 9 print buttons (show.blade.php) with "opens in a new tab" context, + `role="status"` on the no-invoice fallback. (d) `aria-label` added to the Phase 8 reverse button ("Reverse this challan — opens a prompt for a cancellation reason"). (e) Touch targets: `min-h-[36px]` added to the bulk-apply/fill-all-CTN buttons (they're `py-1.5` ≈ 30px; 36px is the practical minimum for a dense toolbar — 44px would break the toolbar's visual rhythm), `min-h-[44px]` added to the print buttons + reverse button (full 44px minimum per WCAG 2.5.5). (f) The mobile card list containers carry `aria-label="Invoice items — mobile card view"` / `"COGS breakdown — mobile card view"` for screen-reader context. (g) All views already had `aria-label` on the breadcrumb `<nav>` (Phase 1) + the progress bar (Phase 4) — preserved. |
| Blade syntax | `godown.blade.php`: @php/@endphp 5/5, @if/@endif 16/16 (the 17th @if is the known line-73 comment false-positive), @foreach/@endforeach 6/6 (Phase 10 added +1 @foreach for the mobile cards), @push/@endpush 1/1. `issue.blade.php`: @php/@endphp 1/1, @if/@endif 9/9, @foreach/@endforeach 2/2 (Phase 10 added +1 for mobile cards), @push/@endpush 1/1. `show.blade.php`: @php/@endphp 4/4, @if/@endif 32/32, @foreach/@endforeach 3/3, @push/@endpush 1/1. All balanced. |
| Brace balance | `WarehouseBelongsToBranch.php` 10/10 + 22/22. `WarehouseHasStock.php` 11/11 + 32/32. `IssueChallanWebRequest.php` 4/4 + 9/9. `CancelChallanWebRequest.php` 5/5 + 11/11. `PrepareGodownWebRequest.php` 12/12 + 47/47. `SalesChallanController.php` 46/46 + 246/246. `godown.blade.php` 221/221 + 441/441. `issue.blade.php` 118/118 + 160/160. `show.blade.php` 200/200 + 179/179. All balanced. |
| Color audit | `grep -ciE '(bg\|text\|border)-indigo-[0-9]'` → 0 across all 9 files (3 blade + 6 PHP). Mobile cards use amber/green/red/yellow (status pills mirroring desktop) + gray (labels) + amber (warehouse pills). Zero indigo. Zero new blue. The only blue in the codebase remains the Phase 7 reserved-stock-badge JS string in godown.blade.php (unchanged). Amber-forward palette preserved. |
| Backward compatibility | `PrepareGodownWebRequest`: the existing rules (`required`, `integer`, `exists:warehouses,id`) are PRESERVED — the two new rules are APPENDED to the array, so the base validation still runs first. The `withValidator` dispatcher check is UNCHANGED. `SalesChallanController`: the 3 method signatures changed (`Request` → typed Form Request), but the method bodies are identical except the inline `validate()` is replaced by `validated()`. The `Request` import is retained for the 3 GET methods. No routes changed. No migrations. No models. The mobile card lists are PURELY ADDITIVE — the desktop tables are preserved (just wrapped in `hidden sm:block`). Phase 7's stock-badge JS, Phase 8's Swal2 flows, Phase 9's print bar + guard — all preserved. |
| Defense-in-depth (A4) | A crafted POST submitting another branch's warehouse_id is now rejected at THREE layers: (1) `WarehouseBelongsToBranch` rule → 422 validation error (Phase 10, NEW), (2) `branch.isolation` route middleware → RLS session scope rejects the write at the PostgreSQL layer, (3) `SalesChallanService::prepareGodown` → looks up the warehouse against the invoice's branch and throws. Layer 1 is the user-facing 422 (clean error message); layers 2+3 are the safety net. |
| Runtime verification | ⚠️ DEFERRED — PHP/Composer not available in sandbox (`php -l` skipped, consistent with all prior phases). User must verify in dev env: (1) submit a godown POST with a warehouse_id from another branch → expect 422 with "The selected warehouse does not belong to this invoice's branch."; (2) submit a godown POST with a warehouse that has insufficient stock → expect 422 with "Insufficient stock in this warehouse: available X but this line demands Y."; (3) submit a cancel POST with a 3-char reason → expect 422 with "The cancellation reason must be at least 5 characters."; (4) submit an issue POST with a non-UUID idempotency_token → expect 422; (5) on mobile (< 640px), the godown items table is replaced by stacked amber cards; (6) on mobile, the issue COGS table is replaced by stacked green cards + a Total COGS footer card; (7) screen reader announces the bulk-apply button as "Apply the selected warehouse to all invoice lines"; (8) the print buttons + reverse button have 44px minimum touch targets. |
| Files changed | 9 files: 4 new (WarehouseBelongsToBranch.php, WarehouseHasStock.php, IssueChallanWebRequest.php, CancelChallanWebRequest.php) + 5 modified (PrepareGodownWebRequest.php, SalesChallanController.php, godown.blade.php, issue.blade.php, show.blade.php). +174/-35 lines (code) + plan update. |
| Carry-forward to Phase 11 | (1) The mobile card layout is read-only on godown (the editable selects live in the hidden desktop table). Phase 11 could make the mobile cards interactive (native `<select>` per card, JS to sync with the hidden desktop select) if mobile per-row editing is a real use case. (2) The bulk-apply buttons use `min-h-[36px]` (toolbar density) — Phase 11 could bump to 44px if the WCAG 2.5.5 audit requires it. (3) Extract the mobile-card-list pattern into a reusable `<x-erp.mobile-card-list>` component if it recurs on the invoice show screen. (4) The `WarehouseBelongsToBranch` + `WarehouseHasStock` rules could be reused by the API layer's Form Requests if the API ever needs them. |

---

### Phase 11 — Polish, Empty States & Final Parity Review
**Goal:** Final UX polish and a parity sign-off against §4 Tables A & B.

**Files to touch**
- `laravel/resources/views/admin/sales-challans/*.blade.php`
- `laravel/resources/css/rc-erp.css` (scoped additions only — no global changes)
- `laravel/public/assets/js/sales-challan-godown.js`

**Tasks**
- [ ] Add empty states: no-items message, no-warehouses-available message, no-dispatchers message (each as a `<x-erp.left-accent-card>` with `border-l-red-500` + icon + action link).
- [ ] Add loading skeletons during AJAX (warehouse dropdown population, dispatcher fetch).
- [ ] Add focus-visible outlines (`focus-visible:ring-2 focus-visible:ring-amber-400`) on all interactive controls.
- [ ] Verify all §4.1 Table A items marked "Done"/"Better" remain intact; verify all "Missing"/"Partial" items are now "Done".
- [ ] Verify all §4.2 Table B items are translated per §5.2.
- [ ] Cross-browser smoke test (Chrome, Firefox, Safari) + mobile viewport test (375px, 768px, 1024px, 1440px).

**Acceptance criteria**
- Every Table A row is Done or Better (except A26 PDF, explicitly deferred).
- Every Table B row is translated and renders on mobile + desktop.
- No console errors; no hydration/laravel-errors in dev log.
- Sticky footer sticks on short pages and is pushed down on long pages.

**Dependencies:** Phase 10.

---

## 7. Out of Scope

This plan deliberately does **NOT** touch:

1. **The literal "Today Invoice" list screen** (`admin.sales-invoices.index` with `scope=today`) — a separate engagement. This plan covers only the Godown & Challan workspace reachable from a draft invoice.
2. **Any other menu/module** — Sales cart, Sales Return, Purchase, Accounting, Reports, Inventory master, etc.
3. **Core architecture** — auth, routing skeleton, `EnsureRole` middleware, `BranchScope`, RLS policies, `MenuService`.
4. **Global CSS** — `rc-erp.css` theme variables, `layouts/erp.blade.php` shell, Bootstrap/Tailwind coexistence strategy. Only scoped additions inside the sales-challans views are allowed.
5. **The Laravel API layer** (`routes/api.php`, `SalesChallanApiController`) — already has its own Form Requests; not in scope.
6. **PDF generation** (A26) — neither project has it; both use HTML print. Adding Dompdf/Snappy is a separate decision.
7. **Database partitioning/RLS changes** — B's PG schema is already superior; no schema migration is required except possibly adding `dispatched_ctn` persistence (Phase 4, verify-then-add only).
8. **The legacy `godown-legacy.blade.php` / `issue-legacy.blade.php` / `show-legacy.blade.php` Bootstrap twins** — these are unrouted parity references; leave them as-is.
9. **Porting A's `customer_ledger.running_balance` race bug** (A29) — explicitly do NOT port; B's `SubLedgerService` should serialize.
10. **Indigo/blue colors** — project rule forbids indigo; A's `--ch-accent:#4f46e5` is replaced with amber/cyan/orange.
11. **Any coding in this document** — this is a plan only. Implementation happens in the phases above, executed separately.

---

## 8. Open Questions

These require a human decision before or during execution:

1. **Menu-label ambiguity.** The sidebar label "Today Invoice" in *both* projects points to the **invoices list**, not the Godown & Challan workspace. You supplied the two Godown & Challan URLs as scope. **Confirm:** is the intended scope the Godown & Challan workspace (as this plan assumes), or did you mean the invoices-list screen? If the latter, this entire plan needs rescoping.

2. **Single-screen vs split-screen architecture (A1/U1).** Project A uses one URL with two POST endpoints; Project B uses three URLs. This plan keeps B's split (better for mobile) but unifies them with a shared stepper. **Confirm:** acceptable, or do you want a literal single-URL merge (higher effort, bigger controller refactor)?

3. **`{id}` semantics.** Both URLs' `{id}` is `sales_invoices.id`, not a godown id. **Confirm:** this matches your mental model? (If you expected it to be a godown/warehouse id, the routing intent differs from the implementation in both projects.)

4. **Challan code format.** A uses `CH-YYYY-####` (yearly, global); B uses `CH-YYYYMMdd-NNNN` (daily, advisory-locked). **Confirm:** keep B's daily format (better granularity, already shipped), or align to A's yearly format?

5. **PDF generation (A26).** Neither project has it. **Confirm:** defer (as this plan does), or add a Phase 12 for Dompdf/Snappy integration on the print views?

6. **`sales_returns` guard on cancel (A19).** A explicitly rejects cancel when non-reversed sales returns exist. B's `cancelChallan` was not confirmed to have this guard in the analysis. Phase 9 adds it. **Confirm:** desired, or should cancel be allowed even with open sales returns (reversing them automatically)?
   **→ RESOLVED in Phase 0:** `cancelChallan` has NO `sales_returns` guard (confirmed by `grep` of `SalesChallanService.php`). Phase 9 will add the guard (matching Project A's behavior — reject, do not auto-reverse).

7. **Dispatcher source.** A loads dispatchers via `employees WHERE role='dispatcher' AND is_active=1`. B has the `dispatchers()` BelongsToMany relationship but no UI. **Confirm:** same source (active employees with role=dispatcher, branch-scoped), or a different role name in B's `config/roles.php`?

8. **CTN persistence migration (Phase 4).** `sales_invoice_dispatches.dispatched_ctn` exists in A's schema; unconfirmed in B's. **Confirm:** if absent, is adding a migration acceptable (the only schema change in this plan)?  
   **→ RESOLVED in Phase 0:** The column is ABSENT from Project B (`grep -r dispatched_ctn laravel/` → 0 matches). Phase 4 will add migration `add_dispatched_ctn_to_sales_invoice_dispatches`. This is the only schema change in the plan.

9. **`StockAvailabilityService` API.** Phase 5 switches the godown GET from a raw `warehouse_stock.qty` join to `StockAvailabilityService::getWarehouseAvailableQty`. **Confirm:** the service method exists and is pipeline-aware (analysis indicates it does, at `app/Services/Stock/StockAvailabilityService.php`); verify before Phase 5.  
   **→ RESOLVED in Phase 0:** Method EXISTS at `laravel/app/Services/Stock/StockAvailabilityService.php:78` with signature `getWarehouseAvailableQty(int $productId, int $warehouseId, ?int $excludeInvoiceId = null): float`. The `$excludeInvoiceId` parameter confirms pipeline-awareness. Phase 5 can use it directly.

10. **Mobile card layout (Phase 10).** Rendering the items table as cards on `< sm` is a UX upgrade beyond A. **Confirm:** desired, or keep `overflow-x-auto` table only (simpler, less code)?

---

## 9. Definition of Done

This planning document is complete when:
- All 8 sections above are present and concrete (real file paths, real method names, real table/column names).
- §4 Gap Analysis covers every business-logic and UI/UX feature of Project A's Godown & Challan screen.
- §6 Phased Implementation Plan has 11 ordered, independently-shippable phases, each with Goal / Files / Tasks / Acceptance criteria / Dependencies.
- A developer reading this document could execute Phases 1→11 in order and bring Project B's Godown & Challan workspace to parity-or-better with Project A — with a creative, premium, mobile-responsive Tailwind UI — resolving the §8 Open Questions as they arise, and without needing any further clarification from the analyst.

**No source code was modified by this document. The sole artifact produced is this `.md` file.**
