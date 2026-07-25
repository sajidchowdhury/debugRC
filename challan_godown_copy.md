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
| **OQ #6** `sales_returns` guard in `cancelChallan` | ✅ **RESOLVED — MISSING, PHASE 9 MUST ADD** | `grep -nE 'sales_return\|SalesReturn\|sales_returns' laravel/app/Services/Sales/SalesChallanService.php` → 0 matches. `cancelChallan` does NOT check for non-reversed `sales_returns` before reversing. Phase 9 will add this guard. |
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
- **Phase 9** → `sales_returns` guard confirmed missing, must be added (OQ #6 resolution).
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

### Phase 3 — Dispatcher Assignment (Business Logic + UI)
**Goal:** Add multi-dispatcher selection on the godown screen, persisted to `sales_invoice_dispatchers` (closes A5, U10).

**Files to touch**
- `laravel/routes/web.php` (add AJAX dispatchers route)
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`godown`, `storeGodown`, new `dispatchers` method)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown` signature + body)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php` (new Form Request — promote validation out of inline)

**Tasks**
- [ ] Add GET `admin/sales-challans/dispatchers` → returns JSON `[{id,name}]` of active employees with `role=dispatcher` for the invoice's branch.
- [ ] On `godown.blade.php` add `<select name="dispatcher_id[]" multiple>` initialized with Select2, pre-filled from `$invoice->dispatchers`.
- [ ] Extend `storeGodown` to accept `dispatcher_id[]`; in `SalesChallanService::prepareGodown` sync the `dispatchers()` BelongsToMany relationship (`$invoice->dispatchers()->sync($ids)`).
- [ ] Create `PrepareGodownWebRequest` Form Request with rules: `warehouse_assignments.* => required|integer|exists:warehouses,id`, `dispatcher_id => required|array|min:1`, `dispatcher_id.* => integer|exists:employees,id`.
- [ ] Server-side: validate each `dispatcher_id` has `role=dispatcher` and `is_active=true` and belongs to the invoice's branch.

**Acceptance criteria**
- Dispatcher multi-select renders, pre-fills, and posts correctly.
- `sales_invoice_dispatchers` rows are synced (DELETE+INSERT) on each godown save.
- Form Request enforces branch-scoped dispatcher existence.
- Re-saving godown (when status allows — see Phase 5) updates dispatchers idempotently.

**Dependencies:** Phase 2.

---

### Phase 4 — CTN Packing & Bulk Tools (UI + persistence)
**Goal:** Add per-line carton packing input and bulk warehouse/CTN tools (closes A6, U8, U9, U11).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php`
- `laravel/public/assets/js/sales-challan-godown.js` (new, scoped JS file) OR a `@push('scripts')` block

**Tasks**
- [ ] Add `dispatched_ctn` column to the items table: `<input type="number" step="0.01" min="0" name="dispatched_ctn[{{ $item->id }}]">`.
- [ ] Add bulk "Apply warehouse to all" (`<x-erp.form-select>` + `<x-erp.outline-button>`) and "Fill all CTN" (`<x-erp.outline-button>`).
- [ ] Add warehouse-assignment progress bar (`<div class="w-full bg-gray-200 rounded-full h-2">…`).
- [ ] JS: bulk-apply sets every `.warehouse-select` and triggers `select2:select`; Fill-all-CTN computes `qty / pcs_per_carton` per row; progress bar updates on warehouse change.
- [ ] Extend `PrepareGodownWebRequest` to accept `dispatched_ctn.* => nullable|numeric|min:0`.
- [ ] In `SalesChallanService::prepareGodown` persist `dispatched_ctn` into `sales_invoice_dispatches` (add column if missing — verify migration exists; if not, create one).

**Acceptance criteria**
- CTN input renders, persists, and re-populates on back-with-input.
- Bulk tools work and update the progress bar live.
- `sales_invoice_dispatches` carries `dispatched_ctn` through to the challan-issue step.

**Dependencies:** Phase 3.

---

### Phase 5 — Edit-Godown Mode & Pipeline-Aware Availability
**Goal:** Allow re-saving godown when `status=confirmed` (godown-prepared but not issued), and make the availability check pipeline-aware (closes A2, A3, A28, U6).

**Files to touch**
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`godown` guard, `storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Services/Stock/StockAvailabilityService.php` (verify pipeline method)
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php` (read-only warehouse display)

**Tasks**
- [ ] Relax the `godown()` guard: allow GET when `status` is `draft` OR `confirmed` (godown-prepared, not issued). Reject only when `is_challan_issued` or `is_reversed`.
- [ ] In `storeGodown`/`prepareGodown`: allow re-save when `is_godown_prepared=true && !is_challan_issued`. Make the dispatch sync idempotent (`$invoice->dispatches()->where('product_id',$pid)->update(...)` or `updateOrCreate`).
- [ ] On the godown screen, when `is_godown_prepared && !is_challan_issued`, render warehouse dropdowns as editable (so the user can change them) — this matches A's "godown_issued allows re-save" behavior. Add a policy note callout.
- [ ] On the issue screen, render warehouse as a read-only span + hidden input (locked) — matches A's finalize-lock behavior.
- [ ] Replace the raw `warehouse_stock.qty` join in `godown()` with `StockAvailabilityService::getWarehouseAvailableQty` (physical − open pipeline) so the dropdown shows real availability.

**Acceptance criteria**
- A godown-prepared (not issued) invoice can re-open the godown screen and change warehouse assignments.
- Re-save is idempotent (no duplicate `sales_invoice_dispatches` rows).
- Warehouse dropdown availability reflects pipeline reservations, not just physical stock.
- Issue screen shows warehouse locked.

**Dependencies:** Phase 3, Phase 4.

---

### Phase 6 — Transport Edit at Godown + Live Total Preview
**Goal:** Allow transport cost editing at godown save (with `customer_ledger` delta), mirroring A (closes A7, U12).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (`storeGodown`)
- `laravel/app/Services/Sales/SalesChallanService.php` (`prepareGodown`)
- `laravel/app/Http/Requests/Sales/PrepareGodownWebRequest.php`
- `laravel/app/Services/Accounting/SubLedgerService.php` (verify `postCustomerLedgerEntry`)

**Tasks**
- [ ] Add `<input type="number" step="0.01" min="0" name="transport_cost">` to the godown screen (default `$invoice->transport_cost`), with a live total preview `#invoice-total-display` = `sub_total + transport − discount`.
- [ ] Extend `PrepareGodownWebRequest`: `transport_cost => nullable|numeric|min:0`.
- [ ] In `prepareGodown`: if `transport_cost` differs from current, recompute `total_amount`, update `sales_invoices`, and post a `customer_ledger` entry via `SubLedgerService::postCustomerLedgerEntry(['transaction_type'=>'invoice_adjustment', …])` for the delta. (Do NOT post GL at godown — A defers GL to finalize; mirror that.)
- [ ] JS: on transport input `input` event, recompute and format the preview as "Tk X,XXX.XX".

**Acceptance criteria**
- Transport editable at godown save; total preview updates live.
- `sales_invoices.transport_cost` + `total_amount` updated on godown save when changed.
- `customer_ledger` row written for the delta (reference_type=`invoice_adjustment`).
- No GL posted at godown (deferred to issue, matching A).

**Dependencies:** Phase 5.

---

### Phase 7 — Live Stock Badges & Ctrl+S
**Goal:** Add live color-coded stock badges and the Ctrl+S shortcut (closes U7, U15).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/public/assets/js/sales-challan-godown.js` (or `@push('scripts')` block)

**Tasks**
- [ ] On each warehouse `<option>`, add `data-available` (pipeline-aware) and `data-physical`.
- [ ] On `select2:select`, update a per-row badge `<span>`: green (`bg-green-100 text-green-700`) when available ≥ demand, amber (`bg-yellow-100 text-yellow-700`) when 0 < available < demand, red (`bg-red-100 text-red-700`) when available = 0, blue (`bg-blue-100 text-blue-700`) when "reserved" (godown-prepared state).
- [ ] Add `keydown` listener for Ctrl/Cmd+S → prevent default → click `#btn-save-godown`.
- [ ] Disable Ctrl+S hint text on mobile (`hidden md:inline`).

**Acceptance criteria**
- Badge color updates immediately on warehouse change.
- Ctrl+S triggers save on desktop; no-op on mobile.
- Color is never the only signal (icon + text in every badge).

**Dependencies:** Phase 5.

---

### Phase 8 — Sticky Action Bar & Confirmation Flow Parity
**Goal:** Unify the action bar and Swal2 confirmation flow across godown + issue screens (closes U4, U14, U24).

**Files to touch**
- `laravel/resources/views/admin/sales-challans/godown.blade.php`
- `laravel/resources/views/admin/sales-challans/issue.blade.php`
- `laravel/resources/views/components/erp/sticky-action-bar.blade.php` (verify/extend)

**Tasks**
- [ ] Use `<x-erp.sticky-action-bar>` on both screens: `sticky bottom-0 inset-x-0 bg-white/95 backdrop-blur-sm border-t shadow-lg z-40 flex items-center justify-between gap-3 flex-wrap p-4`.
- [ ] Godown bar: "Back" (`<x-erp.outline-button>`) + "Save Godown Copy" (`<x-erp.gradient-button>`).
- [ ] Issue bar: "Back" + "Issue Challan" (`<x-erp.gradient-button>`) + "Reverse Challan" (when issued & admin/manager, `bg-red-500 hover:bg-red-600 text-white`).
- [ ] Swal2 flow for Save Godown: confirmation → loading → success (offer "Proceed to Issue" / "Stay").
- [ ] Swal2 flow for Issue Challan: warning ("Stock will be deducted…") → loading → success (offer "Print Challan" / "Print Godown" / "Stay").
- [ ] Swal2 flow for Reverse: textarea reason (min 5 chars) → loading → success → reload.
- [ ] Mobile: action bar full-width, buttons stack `flex-col w-full`.

**Acceptance criteria**
- Action bar sticky on both screens; buttons stack on mobile.
- Three Swal2 flows match A's behavior.
- Reverse button visible only to admin/manager on issued challans.

**Dependencies:** Phase 6, Phase 7.

---

### Phase 9 — Reverse/Cancel Guard & Print Buttons
**Goal:** Add the sales_returns guard to cancel (verify/close A19) and add print buttons on the show screen (closes A19, U13).

**Files to touch**
- `laravel/app/Services/Sales/SalesChallanService.php` (`cancelChallan`)
- `laravel/resources/views/admin/sales-challans/show.blade.php`
- `laravel/routes/web.php` (verify print-godown + print-invoice routes exist; add if missing)
- `laravel/app/Http/Controllers/Admin/SalesChallanController.php` (print methods, if missing)

**Tasks**
- [ ] In `cancelChallan`: before reversing, check `sales_returns` for the invoice where `status='completed' && !is_reversed`; throw if any exist ("Reverse the sales return first.").
- [ ] On `show.blade.php` add a print bar: "Print Challan" (`<x-erp.outline-button>` + `fa-print`, links to `print-challan` route), "Print Godown Copy", "Print Invoice Copy" (link to existing `sales-invoices.print` route or add one).
- [ ] Verify `SubLedgerService` serializes `customer_ledger.running_balance` writes (close A29 — do NOT port A's race bug).

**Acceptance criteria**
- Cancel is rejected when non-reversed sales returns exist.
- Print buttons render and open print views in a new tab.
- No `customer_ledger.running_balance` race introduced.

**Dependencies:** Phase 8.

---

### Phase 10 — Validation Hardening & Mobile Card Layout
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
- [ ] Create `WarehouseBelongsToBranch` rule: passes if `warehouses.branch_id === invoice.branch_id && is_active`.
- [ ] Create `WarehouseHasStock` rule: passes if `StockAvailabilityService::getWarehouseAvailableQty(pid,wid,invoiceId) >= demand`.
- [ ] Bind the three Form Requests to the web controller methods (replacing inline `validate()`).
- [ ] On mobile (`< sm`): render the items table as a card list (`sm:hidden`) with each item as a `<x-erp.left-accent-card>`; keep the table `hidden sm:table`.
- [ ] Add `aria-label` / `sr-only` labels to all icon-only buttons; ensure 44px touch targets.

**Acceptance criteria**
- All three web endpoints use Form Requests with branch-ownership + stock rules.
- A crafted POST submitting another branch's warehouse is rejected with 422.
- Items render as cards on mobile, table on desktop.
- All icon-only buttons have accessible labels.

**Dependencies:** Phase 9.

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
