# Sales Module — Implementation Progress Tracker

> **Companion to:** `SALES_MODULE_ROADMAP.md` (full roadmap with detailed tasks, acceptance criteria, and sign-off tables — see worklog for the original).
>
> **Purpose:** Track completion status of each roadmap task. Update as work progresses.
>
> **Audit Verdict:** 🔴 NOT READY FOR PRODUCTION
>
> **Last Updated:** 2025-01-08 (P0-1 through P0-4 complete)

---

## Phase 0 — Critical Blockers

| Task | Description | Status | Notes |
|------|-------------|--------|-------|
| **P0-1** | Fix `sales_invoices.transport_cost` mismatch | ✅ Done | Migration `2025_01_08_000001` — added `transport_cost numeric(12,2) DEFAULT 0` |
| **P0-2** | Fix `sales_invoice_dispatches` (ordered_qty/dispatched_qty/created_by) | ✅ Done | Migration `2025_01_08_000002` + 3 service code fixes (qty now populated) |
| **P0-3** | Fix `sales_returns` (cogs_amount/reason) + `sales_return_items` (sales_invoice_item_id) | ✅ Done | Migration `2025_01_08_000003` — added 3 columns + FK + partial index |
| **P0-4** | Fix `customer_payments.reference_no` | ✅ Done | Migration `2025_01_08_000004` — added column + partial index |
| **P0-5** | Add missing `sales_challan_items` table | ✅ Done | Migration `2025_01_08_000005` + `SalesChallanItem` model + service populate + helper |
| **P0-6** | Wire cart finalize button to backend | ✅ Done | SweetAlert dialog with editable fields + credit pre-check + POST to `/admin/sales/finalize` |
| **P0-7** | Add RBAC to all sales routes | ✅ Done | 19 role middleware assignments across 5 route groups, mirroring legacy `route_roles.php` |
| **P0-8** | Add branch isolation (middleware + policy + scope) | ⬜ Pending | No `assertInvoiceAccessible` equivalent |

### P0-1 Through P0-4 — Detailed Completion Notes

**4 migrations created** in `laravel/database/migrations/`:

1. **`2025_01_08_000001_add_transport_cost_to_sales_invoices.php`**
   - Adds `transport_cost numeric(12,2) DEFAULT 0` after `tax_amount`
   - Idempotent (guarded by `Schema::hasColumn`)
   - Decision: Option B (add column back) — lower risk than rewriting service + views

2. **`2025_01_08_000002_restore_dispatch_quantity_columns.php`**
   - Adds `ordered_qty numeric(14,4) DEFAULT 0`, `dispatched_qty numeric(14,4) DEFAULT 0`, `created_by integer`
   - Backfills existing rows: `ordered_qty = qty WHERE ordered_qty = 0 AND qty > 0`
   - Adds partial index `idx_sdis_pipeline ON (sales_invoice_id, product_id) WHERE dispatched_qty < ordered_qty`
   - **3 service code fixes** to also populate the existing `qty` column (feeds the `GENERATED amount` column):
     - `SalesInvoiceService::finalizeFromCart:184` — adds `'qty' => item.qty` alongside `ordered_qty`
     - `SalesChallanService::issueChallan:192` — adds `'qty' => DB::raw('ordered_qty')` alongside `dispatched_qty`
     - `SalesChallanService::cancelChallan:273` — adds `'qty' => 0` alongside `dispatched_qty => 0`

3. **`2025_01_08_000003_fix_sales_return_schema_mismatches.php`**
   - Adds `cogs_amount numeric(14,2) DEFAULT 0` + `reason text` to `sales_returns`
   - Adds `sales_invoice_item_id integer` FK to `sales_return_items` (ON DELETE SET NULL)
   - Adds partial index `idx_sri_invoice_item WHERE sales_invoice_item_id IS NOT NULL`
   - Note: `reason` and `notes` coexist (reason = user rationale, notes = internal)

4. **`2025_01_08_000004_add_reference_no_to_customer_payments.php`**
   - Adds `reference_no varchar(100)` after `payment_mode`
   - Adds partial index `idx_cp_reference_no WHERE reference_no IS NOT NULL`
   - Captures cheque no, bank txn ID, mobile banking txn ID

### P0-5 — Detailed Completion Notes

**Problem:** Legacy migration 040 created `sales_challan_items` (per-line issue-rate SSOT). Laravel `04_sales.sql` omitted it, collapsing to aggregate `sales_challans.issue_cost`. This broke per-line COGS reporting, the `challan_reversal_smoke` test, and lost ETL fidelity.

**Solution (3 deliverables):**

1. **Migration `2025_01_08_000005_create_sales_challan_items_table.php`**
   - Creates `sales_challan_items` table mirroring legacy migration 040 schema, adapted to PG:
     - `integer GENERATED ALWAYS AS IDENTITY` PK
     - `numeric(14,4)` qty, `numeric(12,2)` issue_rate, `numeric(14,2)` cogs_amount
     - FKs to `sales_challans` (CASCADE), `products` (RESTRICT), `warehouses` (RESTRICT)
   - 3 indexes: `idx_sci_challan`, `idx_sci_product`, `idx_sci_wh`
   - `updated_at` trigger via shared `update_updated_at_column()` function
   - **Backfill**: reconstructs rows from `stock_transactions WHERE reference_type='sales_challan'` for existing challans (same approach as legacy migration 040)
   - Idempotent + reversible

2. **`SalesChallanItem` Eloquent model** (`laravel/app/Models/SalesChallanItem.php`)
   - `fillable`: sales_challan_id, product_id, warehouse_id, qty, issue_rate, cogs_amount
   - `casts`: decimal:4 / decimal:2 / integer
   - Relations: `challan()`, `product()`, `warehouse()`
   - Scope: `forActiveChallans()` (excludes reversed challans)

3. **`SalesChallan` model** — added `items()` HasMany relation

4. **`SalesChallanService` updates:**
   - `issueChallan`: after each stock OUT, INSERTs a `sales_challan_items` row with `issue_rate = avgCost` (the current avg_cost snapshot) + `cogs_amount = qty × avgCost`
   - `issueChallan` return: eager-loads `items.product` + `items.warehouse`
   - New `getChallanLineItems($challanId)` helper: returns per-line items joined with product + warehouse names (for reporting/audit)

**Design notes:**
- The Laravel `StockService::reverseTransaction` already reverses stock at the original `stock_transaction.rate` (which IS the original issue_rate), so per-line cost restoration on challan cancel is already correct via the stock_transactions path. The `sales_challan_items` table serves as:
  1. A denormalized per-line audit snapshot (human-readable)
  2. The source for GrossMargin per-line breakdown
  3. A legacy-compatible table for the `challan_reversal_smoke` test
  4. ETL fidelity (legacy data migrates cleanly)
- On `cancelChallan`, the `sales_challan_items` rows are retained (not deleted) as an audit trail — the challan is marked `is_reversed=true` but its line items persist.

### Verification Status (P0-5)
- [x] Migration file created with correct schema (matches legacy migration 040, adapted to PG)
- [x] Model created with correct fillable/casts/relations
- [x] SalesChallan model updated with `items()` relation
- [x] SalesChallanService::issueChallan populates `sales_challan_items` after each stock OUT
- [x] Helper method `getChallanLineItems` added for reporting
- [x] Backfill logic included for existing challans
- [ ] `php artisan migrate` — **PENDING** (no PHP runtime in current sandbox)
- [ ] End-to-end test (finalize → godown → challan → verify sales_challan_items populated) — **PENDING**

### Files Modified (P0-5)
| File | Change |
|------|--------|
| `laravel/database/migrations/2025_01_08_000005_create_sales_challan_items_table.php` | NEW |
| `laravel/app/Models/SalesChallanItem.php` | NEW |
| `laravel/app/Models/SalesChallan.php` | Added `items()` HasMany relation |
| `laravel/app/Services/Sales/SalesChallanService.php` | `issueChallan`: insert per-line rows; eager-load items; new `getChallanLineItems` helper |

---

### P0-6 — Detailed Completion Notes

**Problem:** The `#btnFinalize` button in `sales/cart.blade.php:919-926` showed a "Coming in Phase 8.2" SweetAlert stub and did NOT call the backend. The `SalesInvoiceController::finalize` endpoint was fully implemented but unreachable from the UI — the entire sales workflow was blocked at the frontend.

**Solution:** Replaced the stub with a complete 5-step `finalizeInvoice()` flow using SweetAlert2 + the existing `ajaxPost`/`ajaxGet` helpers:

1. **Pre-flight checks** — cart must be non-empty, validated, and have a customer selected. If any check fails, show a warning SweetAlert and abort.

2. **Finalize dialog** — a SweetAlert popup with editable fields:
   - Invoice Date (default: today)
   - Discount (Tk) — with validation: ≥0, ≤ subtotal
   - Transport (Tk) — with validation: ≥0
   - Sales Person (free-text, optional)
   - Notes (textarea, optional)
   - Soft-hold checkbox
   - Credit limit override checkbox + reason field (reason enabled only when checkbox ticked; min 10 chars if overriding)
   - Live total recalculation (subtotal − discount + transport) as user types

3. **Credit limit pre-check** — before POSTing to finalize, calls `GET /admin/sales/credit-check?customer_id=X&amount=Y`. If credit exceeded and user didn't tick override → shows validation message with current balance / limit / new balance and asks to tick override + provide reason.

4. **POST to finalize** — calls `POST /admin/sales/finalize` with all fields (`customer_id, branch_id, invoice_date, sales_person, discount_amount, transport_cost, notes, is_soft_hold, credit_limit_override, override_reason`).

5. **Success / error handling**:
   - On success: shows success SweetAlert with invoice code + "View Invoice" button (redirects to `admin.sales-invoices.show`) or "Stay on Cart" (reloads page to reflect empty cart)
   - On error: shows SweetAlert error with the server message, re-enables the button
   - Button disabled + shows spinner during the AJAX request (double-submit mitigation; P2-6 will add a proper idempotency token)

**Additional changes:**
- Added `finalize`, `creditCheck`, `invoiceShow` to the `ENDPOINTS` JS object (using Blade `route()` helpers)
- Updated button tooltip from "Coming in Phase 8.2" to "Create a draft sales invoice from this cart (GL posted)"
- Fixed a `state.subtotal` reference → `state.cart.subtotal` (subtotal lives on the cart object, not directly on state)

**Design notes:**
- The dialog collects fields the cart doesn't have (invoice_date, discount, transport, notes, override) — these are invoice-level, not cart-level
- `salesman_id` is intentionally omitted from the dialog — the controller accepts it as nullable, and the service stores the authenticated user's context. The `sales_person` free-text field captures the salesman name (matches legacy pattern)
- Credit check is fail-open: if the credit-check endpoint itself errors, the finalize still proceeds (the server-side `finalizeFromCart` has its own hard credit-limit check that will block if exceeded without override)
- The button is disabled during the AJAX request to mitigate double-submit; a proper idempotency token is deferred to P2-6

### Verification Status (P0-6)
- [x] Stub replaced with full `finalizeInvoice()` function
- [x] Pre-flight checks (cart non-empty, validated, customer selected)
- [x] SweetAlert dialog with all required fields
- [x] Live total recalculation
- [x] Credit limit pre-check with override flow
- [x] POST to `/admin/sales/finalize` with correct payload
- [x] Success → redirect to invoice show page
- [x] Error → SweetAlert message + button re-enabled
- [x] Button disabled + spinner during request
- [x] Brace/paren balance verified (0/0)
- [x] All helper functions (`ajaxPost`, `ajaxGet`, `fmtMoney`, `escHtml`, `Swal`, `state`) referenced correctly
- [x] `state.cart.subtotal` reference fixed
- [ ] End-to-end browser test — **PENDING** (no PHP/browser runtime in current sandbox)

### Files Modified (P0-6)
| File | Change |
|------|--------|
| `laravel/resources/views/admin/sales/cart.blade.php` | Replaced finalize stub (lines 919-926) with full `finalizeInvoice()` flow (~210 lines); added 3 endpoints to ENDPOINTS object; updated button tooltip; fixed `state.subtotal` → `state.cart.subtotal` |

---

### P0-7 — Detailed Completion Notes

**Problem:** All sales routes used only the `auth` middleware (line 62 of `web.php`). Any authenticated user — including `user`, `hr`, `other` roles — could finalize invoices, issue challans, post payments, reverse returns, and cancel invoices. Legacy had a 455-line route-role matrix in `app/config/route_roles.php` covering 35 sales actions.

**Solution:** Applied `->middleware('role:...')` to every sales route, mirroring the legacy `route_roles.php` matrix. 19 role middleware assignments across 5 route groups:

| Route Group | Action | Allowed Roles | Legacy Equivalent |
|-------------|--------|---------------|-------------------|
| `admin/sales` (group) | cart, cart/*, finalize, cart-data, credit-check | salesman, manager, admin | search_customer, add_to_cart, validate_cart, final_sales |
| `admin/sales-invoices` | index, show (resource) | salesman, accountant, manager, admin | today, datatable_invoices, show |
| `admin/sales-invoices` | cancel (POST) | salesman, manager, admin | delete_invoice |
| `admin/sales-challans` | index (resource) | warehouse_manager, dispatcher, manager, admin | ChallanController::index |
| `admin/sales-challans` | show (resource) | accountant, warehouse_manager, manager, admin | ChallanController::details |
| `admin/sales-challans` | godown, storeGodown, challan-form, issueChallan | warehouse_manager, dispatcher, manager, admin | prepare_godown, create_final_challan |
| `admin/sales-challans` | cancel (POST) | manager, admin | reverse_challan |
| `admin/customer-payments` | index, create, store, show (resource) | salesman, accountant, manager, admin | PaymentController::receive, store |
| `admin/customer-payments` | outstanding-invoices (GET) | salesman, accountant, manager, admin | (AJAX helper) |
| `admin/customer-payments` | cancel (POST) | accountant, manager, admin | reverse_payment |
| `admin/sales-returns` | index (resource) | salesman, accountant, warehouse_manager, manager, admin | SalesReturnController::index |
| `admin/sales-returns` | create, store (resource) | salesman, manager, admin | SalesReturnController::create, store |
| `admin/sales-returns` | show (resource) | accountant, warehouse_manager, manager, admin | SalesReturnController::details |
| `admin/sales-returns` | invoice-details (GET) | salesman, manager, admin | get_invoice_for_return |
| `admin/sales-returns` | confirm (POST) | warehouse_manager, accountant, manager, admin | confirm_store |
| `admin/sales-returns` | reverse (POST) | accountant, manager, admin | SalesReturnController::reverse |

**How the `EnsureRole` middleware works:**
1. Superadmin → always passes (bypass)
2. If `admin` is in the allowed roles list AND user role is `admin` → passes (admin tier bypass via `getRoleTiers()`)
3. If user role is in the allowed roles list → passes (exact match)
4. Otherwise → 403 JSON for AJAX, redirect to dashboard for non-AJAX

All role lists include `admin` explicitly, so admin users always pass. `superadmin` always passes regardless. Roles `user`, `hr`, `other` are NEVER in any sales role list → they get 403 on all sales routes.

**Implementation approach:**
- Group-level middleware: `admin/sales` group gets `->middleware('role:salesman,manager,admin')` at the prefix level — applies to all 12 routes in the group
- Per-route middleware: custom routes (cancel, confirm, reverse, etc.) get `->middleware('role:...')` chained after `->name(...)`
- Resource splitting: resources with different roles per action (e.g., `sales-returns` where index/create+store/show need different roles) are split into multiple `->only([...])` declarations, each with its own middleware

### Verification Status (P0-7)
- [x] `EnsureRole` middleware exists at `app/Http/Middleware/EnsureRole.php`
- [x] `role` alias registered in `bootstrap/app.php:34`
- [x] `config/roles.php` confirms 3 tiers (superadmin/admin/operational) + 10 roles
- [x] 19 role middleware assignments applied across 5 route groups
- [x] All route names unique (no duplicates)
- [x] Brace/paren balance: 84/84, 499/499
- [x] Admin/superadmin bypass works (all role lists include `admin`; superadmin always passes)
- [x] `user`, `hr`, `other` roles excluded from ALL sales routes
- [ ] End-to-end test with each role — **PENDING** (no PHP runtime in current sandbox)

### Files Modified (P0-7)
| File | Change |
|------|--------|
| `laravel/routes/web.php` | Added `->middleware('role:...')` to 19 sales routes across 5 groups (lines 294-412); split resources by role requirement; added P0-7 comments |

---

## Phase 1 — Operational Completeness

| Task | Description | Status |
|------|-------------|--------|
| P1-1 | Invoice edit/update flow | ⬜ Pending |
| P1-2 | Stale draft cancellation (Artisan + cron) | ⬜ Pending |
| P1-3 | Fix audit logging (9 business events) | ⬜ Pending |
| P1-4 | Fix double-bookkeeping (allocations tables) | ⬜ Pending |
| P1-5 | Linked damage write-off for Damage returns | ⬜ Pending |
| P1-6 | Print views (invoice/challan/receipt/slip) | ⬜ Pending |
| P1-7 | Sales notifications (return events) | ⬜ Pending |

---

## Phase 2 — Refinements & Edge Cases

| Task | Description | Status |
|------|-------------|--------|
| P2-1 | Period-close admin bypass | ⬜ Pending |
| P2-2 | Invoice state machine (path back to draft) | ⬜ Pending |
| P2-3 | Transport snapshot workflow | ⬜ Pending |
| P2-4 | ETL data conversion plan | ⬜ Pending |
| P2-5 | Restore transaction_type or document alternative | ⬜ Pending |
| P2-6 | Idempotency token on finalize | ⬜ Pending |
| P2-7 | Cache branch pipeline qty | ⬜ Pending |

---

## Phase 3 — Verification & QA

| Task | Description | Status |
|------|-------------|--------|
| P3-1 | Stock replay verification | ⬜ Pending |
| P3-2 | Journal replay verification | ⬜ Pending |
| P3-3 | Reconciliation (6 sections) | ⬜ Pending |
| P3-4 | Shadow mode (7 days) | ⬜ Pending |
| P3-5 | Reversal verification | ⬜ Pending |
| P3-6 | Penetration test (RBAC + branch) | ⬜ Pending |
| P3-7 | Final cutover sign-off | ⬜ Pending |

---

## Next Steps

1. **Immediate:** Run `php artisan migrate` in a PHP/PostgreSQL environment to apply the 4 new migrations
2. **Immediate:** Test the full sales workflow end-to-end (cart → finalize → godown → challan → payment → return)
3. **Then:** Proceed to P0-5 (add `sales_challan_items` table) and P0-6 (wire finalize button)
4. **Then:** P0-7 (RBAC) and P0-8 (branch isolation) — the two critical security fixes
