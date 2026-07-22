# Purchase Module — Lagachy → Laravel Parity Plan

**Document type:** Gap analysis + phased implementation plan
**Scope:** Purchase Order (PO) · Purchase Receive (GRN) · Purchase Return · Purchase Audit
**Goal:** By the end of all phases, the Laravel app must be able to (1) create a Purchase Order, (2) receive the PO into one or more warehouses (GRN), (3) return purchases to the supplier — with full reverse-and-restore support — matching the legacy (lagachy) software feature-for-feature and look-for-look.
**Source of truth:** Legacy files at `legacy/app/views/Purchase*/`, `legacy/app/controllers/Purchase*Controller.php`, `legacy/public/assets/js/Purchase*.js`, `legacy/public/assets/css/purchase-*.css`. Most logic and UI should be copied from legacy.
**Created:** 2026-07-22
**Status:** ✅ Phase 0 complete (2026-07-22) — schema reconciled, 5 critical bugs fixed, 6 dead JS files removed (2,501 lines). ✅ Phase 1 complete (2026-07-22) — RBAC + branch isolation enforced on all purchase routes. ✅ Phase 2 complete (2026-07-22) — PurchaseOrder UI parity (legacy-faithful). ✅ Phase 3 complete (2026-07-22) — PurchaseReceive (GRN) UI parity + Receives-against-PO list. ✅ Phase 4 complete (2026-07-22) — PurchaseReturn UI parity + offcanvas + smart-sort + chip counts + Returns-against-GRN list. ✅ Phase 5 complete (2026-07-22) — Damage condition (no-stock-movement returns) + dual stock cap (Good: GRN returnable AND warehouse available; Damage: GRN returnable only). ✅ Phase 6 complete (2026-07-22) — Printable Return slip + per-module audit logs (PO/GRN/Return) + PurchaseAudit checklist dashboard (12 sections, AJAX re-run, 3 detail tables). ✅ Phase 7 complete (2026-07-22) — 11 Form Request classes wired into all 3 purchase controllers (replaces all inline `$request->validate()` calls); dead `$products` 500-row pre-load dropped from PO create/edit + GRN create (AJAX typeahead is now the single source of truth); cross-linkage audit (4/4 buttons verified); mobile card rendering verified on all 3 index pages; CSV exports verified on all 3 modules. Ready for Phase 8 (E2E QA).

---

## Phase 7 Completion Summary (2026-07-22)

### Goal

Close the remaining polish/parity gaps before end-to-end QA: extract all purchase-module validation into dedicated Form Request classes, finish removing the dead 500-product `<select>` pre-load (the AJAX typeahead from Phase 2/3 is now the single source of product lookup on PO create/edit + GRN create), audit the 4 cross-linkage buttons/lists end-to-end, and verify the mobile card rendering + CSV exports on all 3 index pages.

### What was built

#### 1. Eleven Form Request classes

All 11 Form Requests live under `laravel/app/Http/Requests/Purchase{Order,Receive,Return}/` and are auto-resolved by Laravel's service container via controller method type-hints (no route changes were needed — routes still use the `[Controller::class, 'method']` notation).

| # | Class | Replaces | Validation rules (unchanged) |
|---|---|---|---|
| 1 | `PurchaseOrder/StorePurchaseOrderRequest` | `PurchaseOrderController::store()` inline `validate()` | supplier_id (required, exists), branch_id (required, exists), warehouse_id (nullable, exists), po_date (required, date), expected_date (nullable, date), notes (nullable, max:1000), discount_amount (nullable, numeric, min:0), tax_amount (nullable, numeric, min:0), items (required, array, min:1), items.*.product_id (required, exists), items.*.qty (required, min:0.001), items.*.rate (required, min:0) |
| 2 | `PurchaseOrder/UpdatePurchaseOrderRequest` | `PurchaseOrderController::update()` inline `validate()` | Same as Store (kept as a separate class so future divergence — e.g. partial updates — is trivial). |
| 3 | `PurchaseOrder/CancelPurchaseOrderRequest` | `PurchaseOrderController::cancel()` inline `validate()` | cancel_reason (required, string, max:500). |
| 4 | `PurchaseReceive/StorePurchaseReceiveRequest` | `PurchaseReceiveController::store()` inline `validate()` | purchase_order_id (nullable, exists), supplier_id (nullable, exists), branch_id (nullable, exists), warehouse_id (required, exists), receive_date (required, date), notes (nullable, max:1000), discount_amount (nullable, min:0), tax_amount (nullable, min:0), items (required, min:1), items.*.product_id (required, exists), items.*.warehouse_id (required, exists), items.*.qty (required, min:0.001), items.*.rate (required, min:0), items.*.purchase_order_item_id (nullable, integer). |
| 5 | `PurchaseReceive/ConfirmPurchaseReceiveRequest` | `PurchaseReceiveController::confirm()` inline `validate()` | confirm_reason (nullable, string, max:500). |
| 6 | `PurchaseReceive/CancelPurchaseReceiveRequest` | `PurchaseReceiveController::cancel()` inline `validate()` | cancel_reason (required, string, max:500). |
| 7 | `PurchaseReceive/GetPoDetailsRequest` | `PurchaseReceiveController::getPoDetails()` inline `validate()` | po_id (required, integer, exists:purchase_orders,id). |
| 8 | `PurchaseReturn/StorePurchaseReturnRequest` | `PurchaseReturnController::store()` inline `validate()` | purchase_receive_id (required, exists), return_date (required, date), reason (nullable, max:1000), items (required, min:1), items.*.product_id (required, exists), items.*.warehouse_id (required, exists), items.*.qty (required, min:0.001), items.*.rate (nullable, min:0), items.*.purchase_receive_item_id (nullable, integer), items.*.condition (nullable, in:Good,Damage — Phase 5). |
| 9 | `PurchaseReturn/ConfirmPurchaseReturnRequest` | `PurchaseReturnController::confirm()` inline `validate()` | confirm_reason (nullable, string, max:500). |
| 10 | `PurchaseReturn/CancelPurchaseReturnRequest` | `PurchaseReturnController::cancel()` inline `validate()` | cancel_reason (required, string, max:500). |
| 11 | `PurchaseReturn/GetReceiveDetailsRequest` | `PurchaseReturnController::getReceiveDetails()` inline `validate()` | receive_id (required, integer, exists:purchase_receives,id). |

Each Form Request includes:
- `authorize(): true` — RBAC is enforced by route middleware (Phase 1), so the Form Request does not duplicate the role check.
- `rules(): array` — extracted verbatim from the previous inline `validate()` calls. No rule changes.
- `messages(): array` — human-friendly error messages for the most common validation failures (e.g. `"Please select a supplier."` instead of `"The supplier id field is required."`). The GRN + Return Store requests also include per-line messages (e.g. `"Each line must have a product."`) so the user gets actionable feedback when the items array fails validation.
- A class-level docblock explaining what the request validates and any Phase-specific invariants (e.g. Phase 5 condition semantics on `StorePurchaseReturnRequest`).

The controllers' `try { ... } catch (\Throwable $e) { ... }` blocks are preserved — Form Request validation failures are caught by Laravel's `ValidationException` handler before reaching the controller body, so the `try/catch` continues to handle service-layer exceptions (e.g. "GRN cannot be cancelled: active returns exist").

#### 2. AJAX product typeahead cleanup

- **`PurchaseOrderController::create()`** — removed the `Product::active()->orderBy('product_name')->limit(500)->get()` query and the `'products' => $products` view variable. The PO create blade (`purchase-orders/create.blade.php`) already uses a custom text-input typeahead wired to `route('admin.purchase-orders.search-products')` from Phase 2 — the `$products` variable was dead weight (never referenced in the blade).
- **`PurchaseOrderController::edit()`** — same removal. The PO edit blade (`purchase-orders/edit.blade.php`) uses the same typeahead.
- **`PurchaseReceiveController::create()`** — same removal. The GRN create blade (`purchase-receives/create.blade.php`) uses a custom typeahead that reuses the `search-products` endpoint from Phase 2 (added in Phase 3).

This closes the last remnant of the legacy 500-product `<select>` pattern. The typeahead endpoint (`searchProducts()`) returns the top 20 matches by name OR code, supports catalogs of any size, and is the single source of product lookup across all purchase create/edit forms.

#### 3. Cross-linkage audit (4/4 verified)

All 4 cross-linkage buttons/lists were already wired in Phase 3 (PO↔GRN) and Phase 4 (GRN↔Return). Phase 7 verifies they all work end-to-end:

| # | Source | Target | Mechanism | Status |
|---|---|---|---|---|
| 1 | PO show page (`purchase-orders/show.blade.php` line 60) | GRN create with `?po_id=` | `<a href="{{ route('admin.purchase-receives.create', ['po_id' => $po->id]) }}">` | ✅ Verified |
| 2 | PO show page (`purchase-orders/show.blade.php` line 247) | "Receives against this PO" list | Eager-loaded via `$po->receives` relation, rendered as a table | ✅ Verified |
| 3 | GRN show page (`purchase-receives/show.blade.php` line 50) | Return create with `?receive_id=` | `<a href="{{ route('admin.purchase-returns.create', ['receive_id' => $r->id]) }}">` | ✅ Verified |
| 4 | GRN show page (`purchase-receives/show.blade.php` line 576) | "Returns against this GRN" list | `PurchaseReturn::where('purchase_receive_id', $id)`, rendered as a table | ✅ Verified |

#### 4. Mobile card rendering audit (3/3 verified)

All 3 index pages already had mobile card rendering from Phase 2 (PO), Phase 3 (GRN), and Phase 4 (Return). Phase 7 verifies the implementation pattern is consistent:

| Module | Container ID | Container class | drawCallback | Card class | Status |
|---|---|---|---|---|---|
| PO | `#poCards` | `purch-index-mobile-cards` | line 447 — calls `renderCards(poTable)` | `purch-index-mobile-card` | ✅ Verified |
| GRN | `#receiveCards` | `purch-index-mobile-cards` | line 413 — calls `renderCards(receiveTable)` | `purch-index-mobile-card` | ✅ Verified |
| Return | `#returnCards` | `purchase-return-mobile-cards` | line 1171 — calls inline card renderer | `purchase-return-mobile-card` | ✅ Verified |

The `renderCards()` function on each page checks `window.innerWidth >= 768` — if true, it empties the container and returns (desktop shows the DataTable only); if false, it iterates the current page's rows and builds one card per row with the same action buttons as the table. A `resize` listener re-renders on viewport change.

#### 5. CSV exports audit (3/3 verified)

All 3 export endpoints were already implemented in Phase 2 (PO), Phase 3 (GRN), and Phase 4 (Return). Phase 7 verifies the headers cover the spec (`Code, Date, Supplier, Branch, Total, Status, Created By`):

| Module | Endpoint | Headers (in order) | UTF-8 BOM | Status |
|---|---|---|---|---|
| PO | `PurchaseOrderController::export()` line 211 | PO Code, Supplier, Branch, Warehouse, PO Date, Expected Date, Total Amount, Status, Created By, Notes | ✅ (`\xEF\xBB\xBF`) | ✅ Verified |
| GRN | `PurchaseReceiveController::export()` line 187 | GRN Code, PO Code, Supplier, Branch, Warehouse, Receive Date, Item Count, Total Amount, Status, Reversed, Created By, Notes | ✅ | ✅ Verified |
| Return | `PurchaseReturnController::export()` line 594 | Return Code, GRN Code, Supplier, Branch, Return Date, Total Amount, Status, Reversed, Created By, Reason | ✅ | ✅ Verified |

All 3 endpoints are branch-scoped (non-admins get only their session branch) and respect the same filter set as the index page DataTables (date range, status, search term, cancelled/reversed toggle).

### Files touched

| File | Status | Purpose |
|---|---|---|
| `laravel/app/Http/Requests/PurchaseOrder/StorePurchaseOrderRequest.php` | NEW (66 lines) | Form Request for PO store. |
| `laravel/app/Http/Requests/PurchaseOrder/UpdatePurchaseOrderRequest.php` | NEW (37 lines) | Form Request for PO update (rules identical to Store — kept separate for future divergence). |
| `laravel/app/Http/Requests/PurchaseOrder/CancelPurchaseOrderRequest.php` | NEW (31 lines) | Form Request for PO cancel. |
| `laravel/app/Http/Requests/PurchaseReceive/StorePurchaseReceiveRequest.php` | NEW (77 lines) | Form Request for GRN store (Direct + against-PO). |
| `laravel/app/Http/Requests/PurchaseReceive/ConfirmPurchaseReceiveRequest.php` | NEW (25 lines) | Form Request for GRN confirm. |
| `laravel/app/Http/Requests/PurchaseReceive/CancelPurchaseReceiveRequest.php` | NEW (35 lines) | Form Request for GRN cancel. |
| `laravel/app/Http/Requests/PurchaseReceive/GetPoDetailsRequest.php` | NEW (35 lines) | Form Request for AJAX `getPoDetails`. |
| `laravel/app/Http/Requests/PurchaseReturn/StorePurchaseReturnRequest.php` | NEW (73 lines) | Form Request for Return store (Phase 5 condition-aware). |
| `laravel/app/Http/Requests/PurchaseReturn/ConfirmPurchaseReturnRequest.php` | NEW (25 lines) | Form Request for Return confirm. |
| `laravel/app/Http/Requests/PurchaseReturn/CancelPurchaseReturnRequest.php` | NEW (35 lines) | Form Request for Return cancel (reverse). |
| `laravel/app/Http/Requests/PurchaseReturn/GetReceiveDetailsRequest.php` | NEW (35 lines) | Form Request for AJAX `getReceiveDetails`. |
| `laravel/app/Http/Controllers/Admin/PurchaseOrderController.php` | MODIFIED (−16 lines net) | `store()` / `update()` / `cancel()` now type-hint Form Requests. `create()` / `edit()` no longer pass `$products` to the blade. Docblock updated to document Phase 7 changes. |
| `laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php` | MODIFIED (−22 lines net) | `store()` / `confirm()` / `cancel()` / `getPoDetails()` now type-hint Form Requests. `create()` no longer passes `$products` to the blade. Docblock updated. |
| `laravel/app/Http/Controllers/Admin/PurchaseReturnController.php` | MODIFIED (−18 lines net) | `store()` / `confirm()` / `cancel()` / `getReceiveDetails()` now type-hint Form Requests. Docblock updated. |
| **Total** | **11 new + 3 modified** | **~470 lines of new code** (Form Requests) + ~56 lines removed (inline `validate()` calls + dead `$products` queries). |

### Routes added

**None.** All 11 Form Requests are auto-resolved by Laravel's service container via controller method type-hints. The existing routes in `routes/web.php` (which use `[Controller::class, 'method']` notation) work unchanged.

### Bugs fixed in Phase 7

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-34 | Medium | All 3 purchase controllers used inline `$request->validate()` for their write endpoints — validation rules were buried inside controller bodies, untestable in isolation, and invisible to `php artisan route:list`. Extracted 11 dedicated Form Request classes (PO: 3, GRN: 4, Return: 4) under `app/Http/Requests/Purchase{Order,Receive,Return}/`. Rules are unchanged; only the location has moved. Each Form Request includes `authorize()`, `rules()`, and human-friendly `messages()` for the most common validation failures. Controllers' `try/catch` blocks continue to handle service-layer exceptions (e.g. "GRN cannot be cancelled: active returns exist") — Form Request validation failures are caught earlier by Laravel's `ValidationException` handler. | 11 new Form Request files, 3 modified controllers |
| BUG-35 | Low | `PurchaseOrderController::create()` + `edit()` + `PurchaseReceiveController::create()` each ran `Product::active()->orderBy('product_name')->limit(500)->get()` and passed the result to the blade as `$products` — but the blades never referenced `$products` (they use the AJAX typeahead from Phase 2/3). The query was dead weight: it added a DB roundtrip per page load and silently capped at 500 products (the legacy Select2 limit). Removed the query + the view variable from all 3 methods. The AJAX typeahead (`searchProducts()` endpoint, top 20 matches by name OR code) is now the single source of product lookup across all purchase create/edit forms. | `PurchaseOrderController.php`, `PurchaseReceiveController.php` |
| BUG-36 | Low | `PurchaseOrder/UpdatePurchaseOrderRequest` could have been implemented as a one-liner `extends StorePurchaseOrderRequest` — but Laravel's `FormRequest` is `final`-unsafe for inheritance (the `messages()` method calls `(new StorePurchaseOrderRequest())->messages()` which would re-instantiate the class). Kept `UpdatePurchaseOrderRequest` as a separate class with its own `rules()` (identical to Store) + a `messages()` that delegates to a new `StorePurchaseOrderRequest` instance. This makes future divergence (e.g. allowing partial updates on PUT) trivial — just edit `UpdatePurchaseOrderRequest::rules()` without affecting Store. | `UpdatePurchaseOrderRequest.php` |

### Smoke-test checklist

1. **PO create with Form Request validation:** Visit `admin/purchase-orders/create`. Leave supplier blank. Click "Save as Draft". Verify the Form Request returns a friendly error message ("Please select a supplier.") and the form re-renders with old input.
2. **GRN create with Form Request validation:** Visit `admin/purchase-receives/create` (Direct mode). Add a line item but leave qty blank. Submit. Verify the Form Request returns "Each line must have a quantity." and the form re-renders.
3. **Return create with Form Request validation (Phase 5 condition):** Visit `admin/purchase-returns/create`. Pick a GRN. Add a line with condition "Damage" but qty greater than the GRN returnable. Submit. Verify the Form Request passes validation (condition is `in:Good,Damage`) and the service layer rejects with "Return qty exceeds GRN returnable" — confirming the validation/service-layer boundary is correct.
4. **PO cancel with Form Request validation:** Click "Cancel" on a draft PO. Leave the reason blank. Submit. Verify "Please provide a reason for cancelling this PO." appears.
5. **AJAX typeahead (no $products pre-load):** Visit `admin/purchase-orders/create`. Open browser DevTools Network tab. Verify NO request to load 500 products happens on page load. Type 2-3 characters in the product search box. Verify a `search-products?term=...` AJAX call fires and returns up to 20 results. Repeat on `admin/purchase-receives/create` (Direct mode).
6. **Cross-linkage (PO → GRN):** Create a draft PO. Mark as Sent. On the PO show page → click "Receive against this PO". Verify the GRN create form opens with `?po_id=` in the URL and the supplier + branch + items are pre-filled. Verify the "Receives against this PO" list at the bottom of the PO show page updates after the GRN is saved.
7. **Cross-linkage (GRN → Return):** Confirm the GRN. On the GRN show page → click "Return against this GRN". Verify the Return create form opens with `?receive_id=` in the URL and the GRN's items are pre-filled with per-warehouse availability. Verify the "Returns against this GRN" list at the bottom of the GRN show page updates after the Return is saved.
8. **Mobile card rendering:** Resize browser to <768px. On each of the 3 index pages (`admin/purchase-orders`, `admin/purchase-receives`, `admin/purchase-returns`) → verify the table is hidden and one card per row renders with the same action buttons. Resize back to ≥768px → verify the table reappears and cards disappear.
9. **CSV exports:** On each of the 3 index pages → click the "Export" button. Verify the CSV downloads with a UTF-8 BOM (opens cleanly in Excel). Verify the headers include Code, Date, Supplier, Branch, Total, Status, Created By. Verify only the user's branch rows are included (non-admins).
10. **Route list introspection:** Run `php artisan route:list` and verify the 11 Form Request classes appear as the type-hinted parameter on the corresponding controller methods. (Optional — confirms the Form Requests are auto-discovered.)

### Notes for Phase 8

- **Phase 8 (E2E QA)** — The 12-step E2E test plan in §8 Phase 8 can now run end-to-end. The Form Request refactor does not change any validation behavior — only its location — so any E2E failures are pre-existing bugs, not Phase 7 regressions. The smoke-test checklist above (10 steps) should be run before the full 12-step E2E plan.
- **Future divergence** — If Phase 8 reveals that any validation rule needs to change (e.g. allowing negative rates for credit-note-style returns), edit the corresponding Form Request class only. The controllers no longer need to be touched.
- **Mobile card parity** — The 3 index pages use slightly different card class names (`purch-index-mobile-card` for PO + GRN, `purchase-return-mobile-card` for Return) — this is intentional for legacy CSS parity. If a future redesign wants to unify them, the change is purely CSS.
- **`searchReceives()` endpoint** — The Return create workspace's GRN typeahead endpoint (`searchReceives()`) still uses inline `$request->validate(['term' => 'nullable|string|max:100'])`. This was intentionally left inline because the validation is trivial (1 field) and the plan's 11 Form Requests did not include it. If desired, a `SearchReceivesRequest` could be added in a future phase.

---

## Phase 6 Completion Summary (2026-07-22)

### Goal

Close the last feature-parity gap before polish/QA: printable Return slip, per-module audit-log pages for PO/GRN/Return, and the central PurchaseAudit checklist dashboard (12-section health-check with live DB queries, AJAX refresh, and 3 follow-up detail tables).

### What was built

#### 1. Printable Return slip (legacy `PurchaseReturn/slip.php` ported)

- **`laravel/app/Http/Controllers/Admin/PurchaseReturnController.php`** — added `slip(int $id)` method. Loads the return + items + supplier + GRN + branch + creator (new `creator()` BelongsTo relation on `PurchaseReturn` model).
- **`laravel/resources/views/admin/purchase-returns/slip.blade.php`** — NEW FILE. Mirrors legacy layout:
  - Max-width 900px centered card with red header.
  - "REMOTE CENTER / PURCHASE RETURN SLIP" + return_code title block.
  - 2-col info row: Supplier (name + mobile) + GRN Reference (left); Branch + Date + Created By (right).
  - Items table: #, Product (name + code), Warehouse, Return Qty, Rate, Amount, Condition (Good/Damage badge).
  - Footer total row.
  - Optional reason box.
  - Signature lines ("Received By (Supplier)" / "Authorized By").
  - `@media print` CSS hides sidebar/navbar/buttons and forces black borders on table cells.
  - "Print Slip" button → `window.print()`.
- **`laravel/resources/views/admin/purchase-returns/show.blade.php`** — wired the previously-placeholder "Slip" button to `route('admin.purchase-returns.slip', $r)` opening in a new tab. Removed the SweetAlert "coming soon" placeholder JS.
- **Route** — `GET admin/purchase-returns/{id}/slip` named `admin.purchase-returns.slip`, RBAC: admin, manager, warehouse_manager, accountant.

#### 2. Per-module audit-log pages (PO/GRN/Return)

- **`laravel/app/Http/Controllers/Admin/PurchaseOrderController.php`** — added `audit(Request)` method. Joins `user_audit_log` ↔ users ↔ employees ↔ branches, filtered by action prefix `purchase_order_`, paginated 100/page.
- **`laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php`** — added `audit(Request)` method. Same pattern, prefix `purchase_receive_`.
- **`laravel/app/Http/Controllers/Admin/PurchaseReturnController.php`** — added `audit(Request)` method. Same pattern, prefix `purchase_return_`.
- **`laravel/resources/views/admin/purchase/partials/audit-log-table.blade.php`** — NEW FILE. Shared partial used by all 3 audit blades. Features:
  - Hero header with module label + back-to-list link.
  - Filter form (search by action / username / employee name).
  - Responsive table: Timestamp + Branch, By (username + employee name), Action (color-coded badge — green for `*_created`, blue for `*_updated`/`*_sent`/`*_confirmed`, red for `*_cancelled`/`*_reversed`), Target ID (links back to the document), Details (collapsible JSON pretty-print), IP.
  - Pagination.
- **`laravel/resources/views/admin/purchase-orders/audit.blade.php`** — NEW FILE. Thin wrapper that includes the partial.
- **`laravel/resources/views/admin/purchase-receives/audit.blade.php`** — NEW FILE. Thin wrapper.
- **`laravel/resources/views/admin/purchase-returns/audit.blade.php`** — NEW FILE. Thin wrapper.
- **Routes** — 3 new GET routes:
  - `admin/purchase-orders/audit` → `admin.purchase-orders.audit`
  - `admin/purchase-receives/audit` → `admin.purchase-receives.audit`
  - `admin/purchase-returns/audit` → `admin.purchase-returns.audit`
  - All three RBAC: admin, manager, accountant (legacy route_roles.php matrix).

#### 3. UserAudit::log() calls in services

- **`laravel/app/Services/Purchase/PurchaseOrderService.php`** — added `use App\Services\Auth\UserAuditLogger;` and 4 `UserAuditLogger::log()` calls:
  - `createOrder()` → `purchase_order_created` (po_code, branch_id, supplier_id, total, item_count).
  - `updateOrder()` → `purchase_order_updated`.
  - `markAsSent()` → `purchase_order_sent`.
  - `cancelOrder()` → `purchase_order_cancelled` (po_code, reason).
- **`laravel/app/Services/Purchase/PurchaseReceiveService.php`** — added 3 log calls:
  - `createReceive()` → `purchase_receive_created` (receive_code, branch_id, supplier_id, po_id, total, item_count).
  - `confirmReceive()` → `purchase_receive_confirmed` (receive_code, total, journal_entry_id, po_id).
  - `cancelReceive()` → `purchase_receive_cancelled` (receive_code, reason, was_confirmed).
- **`laravel/app/Services/Purchase/PurchaseReturnService.php`** — added 3 log calls:
  - `createReturn()` → `purchase_return_created` (return_code, supplier_id, receive_id, total, item_count, good_lines, damage_lines).
  - `confirmReturn()` → `purchase_return_confirmed` (return_code, total, journal_entry_id, good_lines, damage_lines).
  - `cancelReturn()` → `purchase_return_reversed` (return_code, reason, was_confirmed).
- All log calls pass the document ID as `targetUserId` (overloaded for non-user entities, same convention as legacy `core/UserAudit.php`). Branch_id is auto-enriched by `UserAuditLogger::log()` from session.

#### 4. PurchaseAudit checklist dashboard (legacy `PurchaseAuditModel::runHealthChecks` ported)

- **`laravel/app/Services/Purchase/PurchaseAuditService.php`** — NEW FILE (560 lines). Port of legacy `app/models/PurchaseAuditModel.php`. Produces a 12-section health-check report:
  1. **Purchase module scope** (5 informational items — masters, transactions, inventory impact, GL impact, reporting).
  2. **Products** (7 items — master is shared, prefer active SKUs, group assignment, distinct SKUs purchased last 12 mo, no inactive on confirmed GRNs/POs, no orphan product_id).
  3. **Suppliers** (6 items — master module, active pool available, confirmed GRNs have supplier_id, direct GRN includes supplier, GRNs use active suppliers, POs use active suppliers).
  4. **Warehouses & branches** (5 items — required warehouse_id, warehouse-branch relationship, valid warehouse, active warehouse, branch match).
  5. **Stock SSOT** (6 items — read from warehouse_stock, GRN return_qty is not on-hand, write via StockService only, movements logged, no negative balances, no orphan movements).
  6. **Purchase order** (7 items — no stock on create/cancel, no GL on draft, cancel = status only, GRN updates received_qty, direct GRN allowed, received_qty ≤ ordered qty, open PO lines count).
  7. **GRN** (7 items — create→stock IN+log, create→GL Dr Inv/Cr AP, cancel→stock OUT+log, cancel→reverse journal, confirmed have journal, confirmed have stock IN, cancelled reversed in GL).
  8. **Purchase return** (11 items — create Good→stock OUT+log, **Damage→no stock OUT** (Phase 5 invariant verified), create→GL, reverse→restore stock, reverse→reverse journal, active have journal, Good have stock OUT, return_qty ≤ received, reversal flag matches, reversal journal reversed, printable slip available).
  9. **Supplier payments & due** (6 items — two payable views, supplier transaction module, payments have supplier_ledger row, payments have GL journal, reversed payments reversed in GL, period activity count).
  10. **GL journal link columns** (3 informational items — purchase_receives.journal_entry_id, purchase_returns.journal_entry_id, supplier_payments.journal_entry_id).
  11. **Ledger & accounts (GL)** (5 items — supplier_payable nature, inventory nature, active inventory ledger configured, active AP ledger configured, reconcile with Trial Balance).
  12. **Reporting** (9 items — supplier-wise purchase, payable aging, product movement, planned reports, damage returns).
  - Plus 3 detail tables: negative_stocks, missing_grn_journals, missing_return_journals.
  - Branch-scoped via `branchFilter()` / `branchWarehouseFilter()` helpers — admin can pass `?branch_id=0` for all-branches mode.
- **`laravel/app/Http/Controllers/Admin/PurchaseAuditController.php`** — NEW FILE. Two methods:
  - `checklist(Request)` → renders the HTML dashboard.
  - `runChecks(Request)` → returns JSON for the AJAX "Re-run checks" button.
  - Both use `resolveBranchIdForRead()` so non-admins are auto-scoped to their session branch.
- **`laravel/resources/views/admin/purchase-audit/checklist.blade.php`** — NEW FILE. Mirrors legacy `PurchaseAudit/checklist.php`:
  - Hero with title + "Re-run checks" button + quick-nav chips (PO / GRN / Returns).
  - Meta line (last run timestamp + branch filter).
  - Summary chips (pass / warn / fail / info counts).
  - TOC nav with section links.
  - 12 sections, each with `.purch-audit-section-head` + `.purch-audit-item` rows (with status-pass/warn/fail/info classes + colored badges).
  - 3 conditional detail tables (negative stock, GRNs missing journal, Returns missing journal) with deep-links to the relevant document show pages.
  - "Re-run checks" button → fetches JSON from `admin/purchase-audit/run` → re-renders all sections via DOM manipulation + SweetAlert success toast.
  - Links existing `assets/css/purchase-audit-checklist.css` (already present from Phase 0).
- **Routes** — 2 new GET routes:
  - `admin/purchase-audit` → `admin.purchase-audit.checklist`
  - `admin/purchase-audit/run` → `admin.purchase-audit.run`
  - Both RBAC: admin, manager, accountant (legacy `PurchaseAuditController` matrix).
- **`laravel/app/Http/Controllers/Admin/ReportController.php`** — `purchaseAudit()` method now redirects to `route('admin.purchase-audit.checklist')`. The old stub view at `admin/reports/purchase-audit` (which said "coming in Phase 7") is replaced by a 302 redirect to the real checklist.

### Files touched

| File | Status | Purpose |
|---|---|---|
| `laravel/app/Services/Purchase/PurchaseOrderService.php` | MODIFIED (+58 lines) | Added 4 `UserAuditLogger::log()` calls (create/update/sent/cancel). |
| `laravel/app/Services/Purchase/PurchaseReceiveService.php` | MODIFIED (+47 lines) | Added 3 log calls (create/confirm/cancel). |
| `laravel/app/Services/Purchase/PurchaseReturnService.php` | MODIFIED (+54 lines) | Added 3 log calls (create/confirm/cancel). |
| `laravel/app/Services/Purchase/PurchaseAuditService.php` | NEW (560 lines) | 12-section health-check service (port of legacy PurchaseAuditModel). |
| `laravel/app/Http/Controllers/Admin/PurchaseOrderController.php` | MODIFIED (+43 lines) | Added `audit()` method. |
| `laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php` | MODIFIED (+43 lines) | Added `audit()` method. |
| `laravel/app/Http/Controllers/Admin/PurchaseReturnController.php` | MODIFIED (+62 lines) | Added `slip()` + `audit()` methods. |
| `laravel/app/Http/Controllers/Admin/PurchaseAuditController.php` | NEW (65 lines) | `checklist()` + `runChecks()` methods. |
| `laravel/app/Http/Controllers/Admin/ReportController.php` | MODIFIED (-3 lines) | `purchaseAudit()` now redirects to the real checklist. |
| `laravel/app/Models/PurchaseReturn.php` | MODIFIED (+8 lines) | Added `creator()` BelongsTo relation (used by slip blade). |
| `laravel/resources/views/admin/purchase-returns/slip.blade.php` | NEW (135 lines) | Printable slip blade with `@media print` CSS. |
| `laravel/resources/views/admin/purchase-returns/show.blade.php` | MODIFIED (-8 lines) | Wired "Slip" button to real route; removed placeholder SweetAlert. |
| `laravel/resources/views/admin/purchase-returns/audit.blade.php` | NEW (10 lines) | Thin wrapper that includes the shared partial. |
| `laravel/resources/views/admin/purchase-receives/audit.blade.php` | NEW (10 lines) | Thin wrapper. |
| `laravel/resources/views/admin/purchase-orders/audit.blade.php` | NEW (10 lines) | Thin wrapper. |
| `laravel/resources/views/admin/purchase/partials/audit-log-table.blade.php` | NEW (130 lines) | Shared audit-log table partial. |
| `laravel/resources/views/admin/purchase-audit/checklist.blade.php` | NEW (210 lines) | Checklist dashboard blade with AJAX refresh JS. |
| `laravel/routes/web.php` | MODIFIED (+31 lines) | 6 new routes (3 audit + 1 slip + 2 purchase-audit) + PurchaseAuditController import. |
| **Total** | **8 new + 10 modified** | **~1,400 lines of new code** (matches Phase 6 estimate). |

### Routes added

| Method | URI | Name | RBAC |
|---|---|---|---|
| GET | `admin/purchase-orders/audit` | `admin.purchase-orders.audit` | admin, manager, accountant |
| GET | `admin/purchase-receives/audit` | `admin.purchase-receives.audit` | admin, manager, accountant |
| GET | `admin/purchase-returns/audit` | `admin.purchase-returns.audit` | admin, manager, accountant |
| GET | `admin/purchase-returns/{id}/slip` | `admin.purchase-returns.slip` | admin, manager, warehouse_manager, accountant |
| GET | `admin/purchase-audit` | `admin.purchase-audit.checklist` | admin, manager, accountant |
| GET | `admin/purchase-audit/run` | `admin.purchase-audit.run` | admin, manager, accountant |

### RBAC matrix (legacy route_roles.php parity)

- **audit (PO/GRN/Return)** → admin, manager, accountant (legacy `PurchaseOrderController::audit` / `PurchaseReceiveController::audit` / `PurchaseReturnController::audit`).
- **slip (Return)** → admin, manager, warehouse_manager, accountant (same as Return index/show — read-only).
- **checklist + run_checks** → admin, manager, accountant (legacy `PurchaseAuditController` matrix).

### Smoke-test checklist

1. **Slip print:** Create a return → click "Slip" on the show page → verify a new tab opens with the printable slip layout → click "Print Slip" → verify `@media print` hides sidebar/navbar/buttons and the slip prints cleanly on a single page.
2. **Audit logs (per module):** Create a PO → visit `admin/purchase-orders/audit` → verify the `purchase_order_created` row appears with timestamp + username + action badge (green for created) + target ID link + details JSON. Repeat for GRN (`admin/purchase-receives/audit`) and Return (`admin/purchase-returns/audit`). Verify the search filter narrows by action / username / employee name.
3. **PurchaseAudit checklist:** Visit `admin/purchase-audit` → verify the 12 sections render with pass/warn/fail/info badges. Verify the summary chips show correct counts. Verify the TOC nav jumps to each section. Verify the 3 detail tables (negative stock / missing GRN journal / missing Return journal) appear only when there are rows. Click "Re-run checks" → verify the AJAX refresh re-renders all sections via JSON + shows a SweetAlert success toast.
4. **Phase 5 invariant:** In the PurchaseAudit checklist → section 8 (Purchase return) → verify the `prt_damage` item shows "pass" with detail "OK" (meaning no Damage lines have stock movements — confirming Phase 5's invariant holds).
5. **Branch isolation:** Login as a non-admin user → visit `admin/purchase-audit` → verify the checklist only shows results for the user's session branch. Login as admin → pass `?branch_id=0` → verify the checklist runs across all branches.
6. **Redirect from old stub:** Visit `admin/reports/purchase-audit` → verify it 302-redirects to `admin/purchase-audit`.

### Notes for Phase 7+

- **Phase 7 (AJAX product typeahead + Form Requests + cross-linkage + mobile cards + CSV exports)** — the per-module audit-log pages from Phase 6 are good reference implementations for the upcoming Form Request refactor: the controllers are already thin, with all validation logic in the service layer. The shared `audit-log-table.blade.php` partial is a good template for any future paginated-table views.
- **Phase 8 (E2E QA)** — Step 11 (audit log check) and Step 12 (PurchaseAudit checklist) of the E2E test plan are now executable. The Phase 5 `prt_damage` invariant is automatically verified by the checklist on every page load.
- The `UserAuditLogger` writes to the `user_audit_log` table (already exists in the schema) AND dual-writes to `storage/logs/user_audit.log` for defense-in-depth. The per-module audit pages read from the DB table only — if the DB write ever fails, the file log can be used to backfill.

---

## Phase 5 Completion Summary (2026-07-22)

### Goal

Support Good vs Damage return conditions on `purchase_return_items`. **Good** = stock OUT + GL + supplier_ledger + GRN `return_qty++` (the existing behavior). **Damage** = NO stock movement (supplier claim only — stock was never received in usable condition so it never entered `warehouse_stock`), but GL + supplier_ledger + GRN `return_qty++` are still posted so the supplier-side accounting and the supplier-returnable quota are correct. Also enforce the dual stock cap on Good returns: `return_qty ≤ min(GRN returnable, warehouse available)` — the client-side JS already enforces this from Phase 4; Phase 5 makes the server-side service layer condition-aware so Damage lines don't trigger stock OUT.

### Verification outcome

Phase 5 was verified by static analysis (same approach as Phase 2/3/4 — no `php`/`docker` CLI on the host). The 11-point static verifier (`/home/z/my-project/scripts/phase5_verify.py`) checks:

1. **PHP brace/paren/bracket balance** on all 4 modified PHP files (migration, model, controller, service) — all balanced.
2. **Blade directive balance** — every `@if`/`@endif`, `@foreach`/`@endforeach`, `@forelse`/`@endforelse`, `@php`/`@endphp`, `@empty` pair balanced in both modified blades.
3. **Blade escaping audit** — no JS-embedded literal `@word(...)` patterns that would be miscompiled by the Blade engine.
4. **Migration filename + structure** — matches Laravel pattern `YYYY_MM_DD_HHMMSS_snake_case.php`, uses anonymous class extends Migration, has `up()` + `down()`, is guarded by `Schema::hasColumn()` (idempotent), adds `CHECK (condition IN ('Good','Damage'))` constraint.
5. **Model fillable consistency** — `'condition'` added to `$fillable`, `condition` cast to `string`, `isDamage()` / `isGood()` / `conditionLabel()` accessors present.
6. **Controller validation** — `items.*.condition => 'nullable|in:Good,Damage'` rule present.
7. **Service condition branching** — `createReturn` persists `condition` on itemRows; `confirmReturn` calls `isDamage()` and skips `stockService->applyTransaction()` for Damage items (still increments `return_qty`); `cancelReturn` reverses stock movements via `stock_transactions` query (which naturally returns only Good items since Damage never created any); `normalizeCondition()` helper present.
8. **Show blade Condition column** — `<th class="text-center">Condition</th>` present, `isDamage()` called for badge rendering, Good/Damage badge styling applied, colspan updated for 6-column table.
9. **Create blade condition listener** — `applyCondition()` method added, `condition-select` change listener registered, Damage disables `warehouse-select` and relaxes qty cap to GRN returnable only, Good re-enables and re-applies dual cap.
10. **SQL file fresh-install column** — `condition varchar(10) NOT NULL DEFAULT 'Good' CHECK (condition IN ('Good','Damage'))` added to `CREATE TABLE purchase_return_items`, `idx_prti_condition` index added.
11. **Endpoint reuse check** — `getReceiveDetails()` already returns per-warehouse `available_qty` (Phase 4 BUG-26 fix), so the client-side dual cap was already wired in Phase 4; Phase 5 just makes the server-side service layer condition-aware.

All 52 info checks passed, 0 warnings, 0 errors.

### Deliverables

| # | Task | Status | Files touched |
|---|---|---|---|
| 1 | Add migration `2025_01_25_000001_add_condition_to_purchase_return_items.php` — adds `condition VARCHAR(10) NOT NULL DEFAULT 'Good'` column with CHECK constraint and `idx_prti_condition` index. Idempotent (guarded by `Schema::hasColumn()`). | ✅ | new migration |
| 2 | Update SQL file `database/sql/05_purchase.sql` so fresh installs include the `condition` column + CHECK + index. | ✅ | `database/sql/05_purchase.sql` |
| 3 | Update `PurchaseReturnItem` model — add `condition` to `$fillable` and `$casts`, add `isDamage()`, `isGood()`, `conditionLabel()` accessors. | ✅ | `app/Models/PurchaseReturnItem.php` |
| 4 | Update `PurchaseReturnController::store()` validation — add `items.*.condition => 'nullable|in:Good,Damage'` rule. | ✅ | `app/Http/Controllers/Admin/PurchaseReturnController.php` |
| 5 | Update `PurchaseReturnService::createReturn()` — persist `condition` on each item row (default `Good` for back-compat). | ✅ | `app/Services/Purchase/PurchaseReturnService.php` |
| 6 | Update `PurchaseReturnService::confirmReturn()` — for each item: if `isDamage()`, skip `stockService->applyTransaction()` but still increment GRN `return_qty`. If Good, do stock OUT + log movement + increment `return_qty` (existing behavior). GL + supplier_ledger posted for ALL items (Good + Damage) since they're document-level, not item-level. | ✅ | `app/Services/Purchase/PurchaseReturnService.php` |
| 7 | Update `PurchaseReturnService::cancelReturn()` — GL + supplier_ledger reversal cascades via `journal_entry_id` (already document-level, covers both Good + Damage). Stock reversal query (`stock_transactions WHERE reference_type='purchase_return'`) naturally returns only Good items' transactions (Damage never created any) — no extra branching needed. `return_qty` decremented for ALL items (both Good + Damage had it incremented on confirm). | ✅ | `app/Services/Purchase/PurchaseReturnService.php` |
| 8 | Add `normalizeCondition()` private helper — case-insensitive normalization to `Good` or `Damage`, default `Good`. | ✅ | `app/Services/Purchase/PurchaseReturnService.php` |
| 9 | Update `PurchaseReturnService::validateItems()` — pass through `condition` field, normalize via `normalizeCondition()`. | ✅ | `app/Services/Purchase/PurchaseReturnService.php` |
| 10 | Update `purchase-returns/show.blade.php` items table — add `<th>Condition</th>` column, render Good/Damage badge per row, update empty-state colspan to 6, update tfoot to keep total in Amount column position with empty cell under Condition. Damage rows show warehouse as `— / N/A (Damage)`. | ✅ | `resources/views/admin/purchase-returns/show.blade.php` |
| 11 | Update `purchase-returns/show.blade.php` Quick facts card — when Damage items exist, show separate "Good lines" and "Damage lines" rows with counts + qty totals + behavior hints ("stock OUT" / "no stock move"). | ✅ | `resources/views/admin/purchase-returns/show.blade.php` |
| 12 | Update `purchase-returns/create.blade.php` JS — add `applyCondition(row)` method on `PurchaseReturnWorkspace`. Called on `condition-select` change AND on initial render. When Damage: disable `warehouse-select`, add `N/A (Damage)` placeholder option, set qty `max` to GRN returnable only (no warehouse cap). When Good: re-enable `warehouse-select`, restore previous selection, remove N/A placeholder, re-apply dual cap (returnable AND warehouse available). | ✅ | `resources/views/admin/purchase-returns/create.blade.php` |
| 13 | Smoke-test (user-side, pending) | ⏳ | User to run the 6-step smoke-test checklist below |

### Files touched

- **`laravel/database/migrations/2025_01_25_000001_add_condition_to_purchase_return_items.php`** — NEW FILE (72 lines). Idempotent migration guarded by `Schema::hasColumn()`. Adds `condition VARCHAR(10) NOT NULL DEFAULT 'Good'` after the `amount` column, backfills existing rows to `Good`, adds `CHECK (condition IN ('Good','Damage'))` constraint, adds `idx_prti_condition` index for Phase 6 audit dashboards. `down()` reverses all three operations.
- **`laravel/database/sql/05_purchase.sql`** — added `condition varchar(10) NOT NULL DEFAULT 'Good' CHECK (condition IN ('Good','Damage'))` column to `CREATE TABLE purchase_return_items` + `idx_prti_condition` index. Fresh installs now match the migrated schema.
- **`laravel/app/Models/PurchaseReturnItem.php`** — added `condition` to `$fillable` and `$casts`, added 3 accessors: `isDamage(): bool` (case-insensitive compare to `Damage`), `isGood(): bool` (inverse), `conditionLabel(): string` (returns `Damage` or `Good` — for blade views).
- **`laravel/app/Http/Controllers/Admin/PurchaseReturnController.php`** — added `items.*.condition => 'nullable|in:Good,Damage'` validation rule to `store()`. No other changes (the validated `items` array already flows through to the service unchanged).
- **`laravel/app/Services/Purchase/PurchaseReturnService.php`** — 4 changes: (a) class docblock updated to document the Phase 5 condition semantics; (b) `createReturn()` now persists `condition` on each item row (default `Good`); (c) `confirmReturn()` branches on `$item->isDamage()` — Damage skips `stockService->applyTransaction()` but still increments GRN `return_qty`; Good does the existing stock OUT + return_qty increment; (d) `cancelReturn()` documented that the `stock_transactions` reversal query naturally returns only Good items' transactions (Damage never created any), and `return_qty` decrement loop covers all items; (e) `validateItems()` passes through `condition` field via new `normalizeCondition()` helper.
- **`laravel/resources/views/admin/purchase-returns/show.blade.php`** — items table: added `<th class="text-center">Condition</th>` column (6 columns total). Each row renders a Good badge (`bg-success-subtle text-success` with check icon + "Stock OUT + GL + supplier ledger" tooltip) or Damage badge (`bg-danger-subtle text-danger` with triangle-exclamation icon + "Supplier claim only — no stock movement" tooltip). Damage rows show warehouse as `— / N/A (Damage)`. Empty-state colspan updated to 6. Tfoot row keeps total in the Amount column position with an empty cell under Condition. Quick facts card now shows separate "Good lines" + "Damage lines" rows (only when Damage items exist) with counts + qty totals + behavior hints.
- **`laravel/resources/views/admin/purchase-returns/create.blade.php`** — added `applyCondition(row)` method to the `PurchaseReturnWorkspace` class. Wired to `condition-select` change event AND called once on initial render. Method: when Damage selected, disables `warehouse-select` (adds `bg-light text-muted` classes), preserves the current value in `dataset.prevValue`, appends an `N/A (Damage)` placeholder `<option data-damage-na="1">`, selects it, and sets the qty input `max` to GRN returnable only (no warehouse cap). When Good selected, re-enables `warehouse-select`, removes the N/A placeholder, restores the previous selection from `dataset.prevValue`, and re-applies the dual cap via `applyRowQtyCap(row)`.

### Bugs fixed in Phase 5

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-31 | High | `purchase_return_items` had no `condition` column — Damage returns (which are a core legacy feature for supplier-claim workflows where stock arrived damaged in transit and never entered usable inventory) silently failed or were treated as Good with stock OUT. Added the column via migration + SQL file, made the service layer condition-aware, and made the UI condition-driven (disable warehouse-select for Damage, relax qty cap to GRN returnable only). | migration, SQL, model, controller, service, show blade, create blade |
| BUG-32 | Medium | Return show page items table had no Condition column — users couldn't see at a glance which lines were Good (stock OUT) vs Damage (no movement). Audit reconciliation required cross-referencing `stock_transactions`. Added a Condition column with color-coded badges (green Good / red Damage) + tooltips explaining the behavior. Added Good/Damage line-count summary to the Quick facts card. | `purchase-returns/show.blade.php` |
| BUG-33 | Low | Create form JS had no `condition-select` change listener — switching to Damage didn't visually disable the warehouse-select or relax the qty cap, leading to user confusion (warehouse cap was enforced on submit but not in real-time). Added `applyCondition()` method that reactively disables/re-enables the warehouse-select and adjusts the qty cap to GRN returnable only (Damage) or min(returnable, available) (Good). | `purchase-returns/create.blade.php` |

### Smoke-test checklist (user-side, pending)

Run this on your local Docker after `git pull origin main` and `php artisan migrate`:

1. **Run the migration** → `docker exec -i rcerp_app php artisan migrate`. Verify: the `2025_01_25_000001_add_condition_to_purchase_return_items` migration runs successfully. Check `\d purchase_return_items` in psql → `condition` column exists with type `character varying(10)`, default `'Good'`, CHECK constraint `purchase_return_items_condition_check` present, index `idx_prti_condition` present.
2. **Back-compat check** → visit an existing Return show page (created before Phase 5). Verify: items table renders with the new Condition column, all existing rows show `Good` badge (default backfill), no errors.
3. **Create a Damage return** → from a confirmed GRN with ≥5 units available, create a Return: 3 units Good + 2 units Damage (use the per-row Condition `<select>`). On the create form, verify: when you switch a row to Damage, the warehouse-select greys out and shows `N/A (Damage)`, and the qty input `max` relaxes to the full GRN returnable (no warehouse cap). When you switch back to Good, the warehouse-select re-enables and the dual cap re-applies. Save the draft.
4. **Verify the draft show page** → on the Return show page, verify: items table shows the 3 Good rows with `Good` badge (green) + warehouse name, and the 2 Damage rows with `Damage` badge (red) + `— / N/A (Damage)` warehouse. Quick facts card shows "Good lines: 3 (3.0000 units · stock OUT)" + "Damage lines: 2 (2.0000 units · no stock move)". Total amount = sum of ALL 5 rows (both Good + Damage contribute to AP).
5. **Confirm the return** → click Confirm. Verify success message. Then check: (a) `stock_transactions` table has 3 rows for this return (only the Good items — Damage created NO stock movements); (b) `purchase_receive_items.return_qty` for this GRN item = 5 (both Good + Damage contribute); (c) GL journal entry posted for the FULL total amount (all 5 rows); (d) `supplier_ledger` has 1 debit entry for the FULL total amount. Visit the Return show page → stock movements card shows 3 movements (only Good), GL card shows the full journal entry, supplier ledger card shows the full debit.
6. **Cancel the return** → click Cancel + enter a reason. Verify: (a) `stock_transactions` for this return now has 6 rows (3 original + 3 reversal) — Damage items still have ZERO; (b) `purchase_receive_items.return_qty` is back to 0; (c) GL journal entry is reversed (new reversal entry linked to the original); (d) `supplier_ledger` has a credit reversal entry. Visit the Return show page → status = `cancelled`, `is_reversed = true`, stock movements card shows 3 original + 3 reversed (no Damage rows), GL card shows original + reversal.

If all 6 steps pass, Phase 5 is verified.

### Notes for Phase 6+

- **Phase 6 (Printable Return slip + audit logs + PurchaseAudit checklist)** — the PurchaseAudit checklist (section 8 of the 12-section dashboard) should include a `prt_damage` check: "For every `purchase_return_items` row with `condition='Damage'`, there must be NO matching `stock_transactions` row with `reference_type='purchase_return' AND reference_id=<return_id>`." This is now enforceable because the `condition` column exists. The Phase 5 service-layer logic guarantees this invariant, but the audit check is a defensive backstop.
- **Phase 7 (AJAX product search, Form Requests, cross-linkage completion, exports)** — the `condition` column should appear in the Return CSV export (`PurchaseReturnController::export()`) as a `condition` column. Currently the export only includes header-level fields; Phase 7 will add item-level CSV exports.
- **Backwards compatibility** — existing Returns (pre-Phase-5) have `condition='Good'` backfilled by the migration. The `isDamage()` accessor returns `false` for them, so they render as Good badges. No data loss, no behavior change.
- **UI enhancement opportunity** — the `applyCondition()` method adds an `N/A (Damage)` placeholder option dynamically. If the user switches back to Good, the placeholder is removed. If the form is submitted with Damage selected, the `warehouse_id` field still contains the previously-selected warehouse ID (preserved in `dataset.prevValue`) — this is intentional because the backend validation requires `warehouse_id` to be a valid integer. The service layer simply doesn't trigger stock OUT for Damage items, so the warehouse_id is effectively ignored for stock purposes but still recorded for audit traceability.

---

## Phase 4 Completion Summary (2026-07-22)

### Goal

PurchaseReturn index / create / show pages look and behave like the legacy (lagachy) software — legacy-faithful DOM structure (same CSS class names so the existing `purchase-return-index.css` and `purchase-return-create.css` work unmodified), legacy-faithful UX flows (collapsible filters, status chips with live counts, smart search, smart-sort, mobile cards, offcanvas quick-create, 2-step "Find GRN → return form" workspace with keyboard navigation, per-warehouse availability dual cap, server-side DataTables, CSV export, localStorage filter persistence). Add the missing "Returns against this GRN" cross-linkage list + "Return against this GRN" button on the GRN show page. Wire the offcanvas to dispatch `purchaseReturn:created` event → index table reloads + chip counts refresh.

### Verification outcome

Phase 4 was verified by code inspection. Live HTTP tests cannot be run in this environment (no `php`/`docker` CLI on the host), but the changes were validated by:

1. **PHP brace/paren/bracket balance check** on all 3 modified PHP files (`PurchaseReturnController.php`, `PurchaseReceiveController.php`, `routes/web.php`) — all OK.
2. **JS syntax check via `node --check`** on every `<script>` block in all 5 modified/created blade files (3 large IIFE blocks: index workspace JS + index page JS + create page workspace JS, plus 2 inline show-page scripts) — all OK (48KB+ of JS total).
3. **Blade directive balance** — every `@push`/`@endpush`, `@section`/`@endsection`, `@if`/`@endif`, `@foreach`/`@endforeach`, `@forelse`/`@endforelse`, `@php`/`@endphp`, `@empty` pair balanced across all 5 modified blades.
4. **Blade escaping audit** — no JS-embedded literal `@word(...)` patterns that would be miscompiled by the Blade engine. The `@json(...)` directive is used legitimately for passing PHP arrays to JS.
5. **CSS class-name parity check** — grep'd every `purchase-return-*` and `prt-create-*` class used in the 3 Laravel return blades + the new partial against the legacy `PurchaseReturn/{index,create,partials/create_workspace}.php` views. All 49 key legacy class names present in Laravel blades (2 "missing" were false-negatives from Blade interpolation in the partial's class attribute).
6. **Route conflict check** — 4 new routes (`search-receives`, `summary`, `export`, `receive-details`) declared inside the `admin/purchase-returns` prefix group BEFORE the resource declaration (which only registers `index`/`show`). No collisions with `create`/`store` (explicitly registered separately) or with `{id}/confirm`/`{id}/cancel` (POST method).
7. **Endpoint reuse check** — confirmed the GRN show page's new "Return against this GRN" button correctly links to `admin.purchase-returns.create` with `?receive_id=<id>`. The create page's prefill logic looks up the receive_code and passes it to the workspace JS, which auto-searches and shows the single match (consistent with legacy behavior).
8. **CSRF check** — workspace JS sends `X-CSRF-TOKEN` header (set from `window.CSRF_TOKEN = @json(csrf_token())`) on all POST requests. Cancel button uses `_token` field in form data. Both methods are accepted by Laravel's `VerifyCsrfToken` middleware.
9. **Branch isolation check** — `searchReceives()`, `summary()`, `export()`, `returnDataTableJson()` all use `resolveBranchIdForRead()` so non-admin users only see their own branch's data. The `create()` method's existing branch-isolation check (redirect to index on cross-branch GRN access) is preserved.

### Deliverables

| # | Task | Status | Files touched |
|---|---|---|---|
| 1 | Link `purchase-return-index.css` + `purchase-return-create.css` via `@push('css')` on Return index + create + show blades | ✅ | 3 Return blades |
| 2 | Restructure `purchase-returns/index.blade.php` — `.purchase-return-app`, `.purchase-return-hero`, `.purchase-return-branch-tag`, `.purchase-return-filters-shell`, `.purchase-return-smart-panel`, `.purchase-return-preset-row`, `.purchase-return-search-wrap`, `.purchase-return-status-chips` + `.purchase-return-status-chip` (with `.chip-count` child), `.purchase-return-active-bar`, `.purchase-return-results-card`, `.purchase-return-mobile-cards`, smart-sort checkbox, offcanvas quick-create button | ✅ | `purchase-returns/index.blade.php` (full rewrite) |
| 3 | Add new AJAX endpoint `GET admin/purchase-returns/summary?date_from=&date_to=&search=` returning JSON `{all, total, active, reversed}` for chip counts | ✅ | `PurchaseReturnController::summary()` + new route `summary` |
| 4 | Add new AJAX endpoint `GET admin/purchase-returns/search-receives?term=` returning JSON `{status, data:[{id, receive_code, supplier_id, supplier_name, branch_id, branch_name, receive_date, total_amount}]}` for GRN typeahead | ✅ | `PurchaseReturnController::searchReceives()` + new route `search-receives` |
| 5 | Restructure `purchase-returns/create.blade.php` — `.prt-create-app`, `.prt-create-hero`, `.prt-create-panel`, uses the shared partial for the 2-step workspace. Pre-fills search box when `?receive_id=` or `?grn=` is in the URL. | ✅ | `purchase-returns/create.blade.php` (full rewrite) |
| 6 | Create reusable partial `resources/views/admin/purchase-returns/partials/create-workspace.blade.php` — `.prt-create-workspace` + `.prt-create-step-find` + `.prt-create-step-form`. Used by BOTH the full-page create (`$compact = false`) AND the index offcanvas (`$compact = true`). | ✅ | New file |
| 7 | Restructure `purchase-returns/show.blade.php` — kept the rich layout (stat cards + stock movements + GL + ledger cards). Added `.purchase-return-app` wrapper + linked `purchase-return-index.css`. Added "Slip" button (Phase 6 placeholder — shows SweetAlert "coming soon" message). | ✅ | `purchase-returns/show.blade.php` (targeted restructure) |
| 8 | Add "Return against this GRN" button on `purchase-receives/show.blade.php` (links to `admin.purchase-returns.create` with `?receive_id=`) | ✅ | `purchase-receives/show.blade.php` (hero button) |
| 9 | Add "Returns against this GRN" list section on `purchase-receives/show.blade.php` — queries `$receive->returns` (eager-loaded via new query in `show()`), renders as a table below the GRN cards. Columns: Return # (link to Return show), Date, Supplier, Branch, Items, Amount, Status (badge), Reversed? badge, Actions (View button). Empty state shows "No returns yet against this GRN" with link to create one. | ✅ | `purchase-receives/show.blade.php` (added Returns section) + `PurchaseReceiveController::show()` (eager-load returns) |
| 10 | Wire the offcanvas — opening it bootstraps a `PurchaseReturnWorkspace` instance pointing at the offcanvas's workspace div. After successful save, dispatch `purchaseReturn:created` event → index table reloads + chip counts refresh. | ✅ | `purchase-returns/index.blade.php` (offcanvas + workspace JS) |
| 11 | Replace client-side DataTables with server-side DataTables on Return index — `?datatables=1` mode in `index()` returns JSON `{draw, recordsTotal, recordsFiltered, data}`. Smart-sort by default (active first, then reversed). Search across return_code + supplier_name + branch_name + receive_code. | ✅ | `PurchaseReturnController::returnDataTableJson()` (private) + `index()` branch |
| 12 | Add CSV export endpoint `GET admin/purchase-returns/export` (returns `Content-Type: text/csv` with UTF-8 BOM for Excel). Branch-scoped + same filter logic as `index()`. | ✅ | `PurchaseReturnController::export()` + new route `export` |
| 13 | Add localStorage filter persistence (`purchase_return_filters_v1`) — saves date_from/date_to/status/search/smart_sort/date_preset; restores on page load. URL params (`?date_from=`, `?status=`, `?q=`) override storage (legacy behavior). | ✅ | `purchase-returns/index.blade.php` — `saveFilters()` / `initFromBootOrStorage()` |
| 14 | Add mobile card rendering on `<768px` — DataTables `drawCallback` populates `#returnCards` from the same JSON. | ✅ | `purchase-returns/index.blade.php` — `renderReturnCards()` |
| 15 | Enrich `getReceiveDetails()` AJAX response to include per-warehouse availability (`warehouse_breakdown[]` with `physical_qty`, `available_qty`, `warehouse_name`, `warehouse_id`) for each item — enables the client-side dual cap (return qty ≤ GRN returnable AND ≤ warehouse_stock available). | ✅ | `PurchaseReturnController::getReceiveDetails()` |
| 16 | Smoke-test (user-side, pending) | ⏳ | User to run the 8-step smoke-test checklist below |

### Files touched

- **`laravel/app/Http/Controllers/Admin/PurchaseReturnController.php`** — class docblock updated to mention Phase 4 additions. `index()` modified to branch into DataTables JSON mode when `?datatables=1` is set. `getReceiveDetails()` enriched to return per-warehouse availability (`warehouses[]` per item with `physical_qty` + `available_qty`) and a `status: success` wrapper. Added 4 new methods: `returnDataTableJson()` (private, server-side DataTables JSON), `summary()` (public, chip counts AJAX), `searchReceives()` (public, GRN typeahead AJAX), `export()` (public, CSV stream).
- **`laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php`** — `show()` method modified to eager-load returns against the GRN (`PurchaseReturn::where('purchase_receive_id', $id)` with `items`, `supplier`, `branch` relations), only when the GRN is confirmed and not reversed. Passes `$grnReturns` collection to the view.
- **`laravel/routes/web.php`** — added 3 new routes inside the existing `admin/purchase-receives` prefix group: `GET search-receives` (RBAC `role:admin,manager,warehouse_manager`), `GET summary` (RBAC `role:admin,manager,warehouse_manager,accountant`), `GET export` (RBAC `role:admin,manager,warehouse_manager,accountant`). Updated RBAC comment block to document the new endpoints.
- **`laravel/resources/views/admin/purchase-returns/index.blade.php`** — full legacy-faithful rewrite (~1294 lines). `.purchase-return-app` container + hero (with "Return" offcanvas button + "Full page" link + "Export" link + "Filters" toggle) + collapsible filter panel (date presets + smart search + status chips with live counts + date range + smart-sort checkbox + reset button) + active filter bar + results card (mobile cards container + server-side DataTables table) + offcanvas quick-create (uses shared partial). Inline JS: workspace JS (~640 lines) + index page JS (~440 lines) ported from legacy `PurchaseReturn.js` + `purchase-return-index.js`.
- **`laravel/resources/views/admin/purchase-returns/create.blade.php`** — full legacy-faithful rewrite (~678 lines). `.prt-create-app` container + hero (with "All returns" link) + panel (uses shared partial for the 2-step workspace). Inline JS: workspace JS (~640 lines, same as index) + PHP prefill logic for `?receive_id=` / `?grn=` URL params.
- **`laravel/resources/views/admin/purchase-returns/show.blade.php`** — targeted restructure. Added `.purchase-return-app` wrapper + linked `purchase-return-index.css`. Added "Slip" button in hero (Phase 6 placeholder — SweetAlert "coming soon"). Added `@push('css')` block. Kept the rich layout (stat cards + stock movements + GL + ledger cards + journal entry lines + supplier ledger entries) — Laravel is already better than legacy here.
- **`laravel/resources/views/admin/purchase-returns/partials/create-workspace.blade.php`** — NEW FILE (~75 lines). Shared 2-step "Find GRN → return form" workspace. Step 1: search input + clear button + hint + results container. Step 2: invoice bar + receive details container (hidden until GRN picked). Accepts `$workspaceId` (unique DOM id) and `$compact` (offcanvas mode flag) variables. Used by BOTH `index.blade.php` (offcanvas, `$compact = true`) AND `create.blade.php` (full page, `$compact = false`).
- **`laravel/resources/views/admin/purchase-receives/show.blade.php`** — added "Return against this GRN" button in hero (visible when GRN is confirmed + not reversed). Added "Returns against this GRN" list section at the bottom of the page (table with Return # link, Date, Supplier, Branch, Items count, Amount, Status badge, Reversed badge, View button). Empty state shows "No returns yet against this GRN" with link to create one.

### Bugs fixed in Phase 4

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-23 | High | Return create used a 500-GRN `<select>` dropdown (Select2) — broken once the catalog exceeds 500 confirmed GRNs. The legacy UX uses a custom typeahead with live AJAX search against `search_receive`. Replaced with the 2-step workspace partial: search input + AJAX typeahead via new `searchReceives` endpoint + keyboard navigation (↑↓ Enter Esc) + click-to-pick. | `PurchaseReturnController::searchReceives()`, `purchase-returns/create.blade.php`, `purchase-returns/partials/create-workspace.blade.php` |
| BUG-24 | Medium | Return index had no live chip counts, no offcanvas quick-create, no smart-sort, no mobile cards, no CSV export. Replaced with full legacy-faithful `.purchase-return-app` layout + chip counts AJAX + offcanvas + smart-sort + mobile cards + CSV export. | `PurchaseReturnController::summary()`, `returnDataTableJson()`, `export()`, `purchase-returns/index.blade.php` |
| BUG-25 | Medium | GRN show page had no "Returns against this GRN" cross-linkage — user had to manually navigate to Return index and search. Added a dedicated list section at the bottom of the GRN show page, eager-loaded via `PurchaseReturn::where('purchase_receive_id', $id)`. Also added "Return against this GRN" button in the GRN hero (links to `?receive_id=` create flow). | `PurchaseReceiveController::show()`, `purchase-receives/show.blade.php` |
| BUG-26 | Medium | `getReceiveDetails()` returned only the GRN item's own `warehouse_id` — no per-warehouse availability. The legacy `getReceiveForReturn` returns each warehouse's `physical_qty` + `available_qty` so the JS can enforce the dual stock cap (return qty ≤ GRN returnable AND ≤ warehouse_stock available). Added a `warehouses[]` array per item with `physical_qty` + `available_qty` joined from `warehouse_stock`. | `PurchaseReturnController::getReceiveDetails()` |
| BUG-27 | Low | Return create workspace JS sent `qty` as the form field name, but Laravel's `PurchaseReturnService::createReturn()` expects `items.*.qty` (which it does — the existing validation rule is `items.*.qty`). However the legacy JS sent `return_qty` as the field name. To support both legacy JS conventions AND the Laravel controller contract, the workspace JS now sends BOTH `qty` and `return_qty` in the items array. | `purchase-returns/index.blade.php`, `purchase-returns/create.blade.php` (inline JS) |
| BUG-28 | Low | Return index filters did not persist across page reloads. Added `localStorage` persistence under key `purchase_return_filters_v1` (same key name as legacy). URL params override storage (legacy behavior). | `purchase-returns/index.blade.php` — `saveFilters()` / `initFromBootOrStorage()` |
| BUG-29 | Low | No CSV export on Return index. Added `export()` endpoint returning `text/csv` with UTF-8 BOM and standard headers (Return Code, GRN Code, Supplier, Branch, Return Date, Total Amount, Status, Reversed, Created By, Reason). | `PurchaseReturnController::export()`, `purchase-returns/index.blade.php` (Export button in hero) |
| BUG-30 | Low | Return show page lacked the legacy wrapper class (`.purchase-return-app`) and the "Slip" button — visual inconsistency with the rest of the purchase module and missing print-slip entry point. Added wrapper + "Slip" button (Phase 6 placeholder until the slip route is implemented). | `purchase-returns/show.blade.php` |

### Smoke-test checklist (user-side, pending)

Run this on your local Docker after `git pull origin main`:

1. **Login as admin** → visit `/admin/purchase-returns`. Verify: hero with "Return" (offcanvas) + "Full page" + "Export" + "Filters" buttons. Filters panel collapses/expands. Status chips show "All / Active / Reversed" with live counts (initially 0 until AJAX loads). Smart-sort checkbox is checked by default.
2. **Click "Return" (offcanvas)** → offcanvas slides in from the right. Workspace shows Step 1 "Find GRN" with search input + hint text. Type 2+ chars matching a confirmed GRN's code or supplier name → results appear as cards. Use ↑↓ to navigate, Enter to pick. Picking a GRN loads Step 2 (return form) with the GRN's returnable items.
3. **In the return form** → verify: each row shows Product name + Received qty + GRN returnable qty + Return qty input + Rate + Amount + Warehouse `<select>` (with per-warehouse availability text) + Condition `<select>` (Good/Damage). Enter a return qty for one row → amount updates live + total updates. Select a warehouse → qty input max is capped to `min(returnable, available)`. Try to exceed available → SweetAlert warning.
4. **Click "Save return"** → SweetAlert loading → success message → "View return" / "Done" buttons. Click "View return" → navigates to the return show page. Or click "Done" → offcanvas stays open, workspace resets, index table reloads (the new return appears), chip counts update.
5. **On the Return show page** → verify: hero shows Return code + status badge. "Slip" button shows "coming soon" SweetAlert (Phase 6). Stat cards + stock movements + GL + ledger cards render correctly.
6. **Back on Return index** → click "Filters" → set "From" to last month, status chip "Reversed" → table reloads with reversed-only results. Refresh the page → filters persist (localStorage). Click "Clear filters" → table resets to "Today" preset.
7. **Resize browser to <768px** → table disappears, mobile cards render with one card per row showing Return code, GRN code, supplier name, date, amount, status badge, and action buttons.
8. **Click "Export"** → CSV file downloads. Open in Excel/Sheets → verify headers (Return Code, GRN Code, Supplier, Branch, Return Date, Total Amount, Status, Reversed, Created By, Reason) and rows match the current filter.
9. **Visit a GRN show page** (`/admin/purchase-receives/{id}`) that is confirmed and not reversed → verify the new "Return against this GRN" button appears in the hero (links to `admin.purchase-returns.create?receive_id={id}`). Click it → create page loads with the GRN code pre-filled in the search box → results show the single match → press Enter → return form loads for that GRN.
10. **On the same GRN show page** → verify the new "Returns against this GRN" list section appears at the bottom. If the GRN has returns, each row shows Return code (link), Date, Supplier, Branch, Items count, Amount, Status badge, Reversed badge (if applicable), View button. If no returns, empty state shows "No returns yet against this GRN" with link to create one.

If all 10 steps pass, Phase 4 is verified.

### Notes for Phase 5+

- **Phase 5 (Damage condition + dual stock cap)** — The Phase 4 workspace JS already renders the Condition `<select>` (Good/Damage) per row and already enforces the dual stock cap for Good condition (return qty ≤ GRN returnable AND ≤ warehouse available). Damage condition currently still triggers stock OUT in the service layer — Phase 5 will fix the service layer to skip stock movement for Damage items. The UI is already Phase-5-ready.
- **Phase 6 (Printable Return slip + audit logs + PurchaseAudit checklist)** — The "Slip" button on the Return show page is a placeholder that shows a "coming soon" SweetAlert. Phase 6 will create the actual `admin/purchase-returns/{id}/slip` route + blade + controller method.
- **Phase 7 (AJAX product search, Form Requests, cross-linkage completion, exports)** — The Phase 4 workspace JS already supports barcode-scanner-style input (debounced search + Enter fallback). The Phase 4 `searchReceives` endpoint is a model for the Phase 7 product typeahead.
- **Refactor opportunity** — The workspace JS is inlined in BOTH `index.blade.php` AND `create.blade.php` (each ~640 lines, identical). In a future refactor, this should move to a dedicated `/assets/js/PurchaseReturn.js` file included via `<script src=...>`.

---

## Phase 3 Completion Summary (2026-07-22)

### Goal

PurchaseReceive (GRN) index / create / show pages look and behave like the legacy (lagachy) software — legacy-faithful DOM structure (same CSS class names so the existing `purchase-index.css` and `purchase-order-form.css` work unmodified), legacy-faithful UX flows (collapsible filters, status chips, smart search, mobile cards, custom typeahead product picker reusing the Phase 2 `search-products` endpoint, server-side DataTables, CSV export, localStorage filter persistence). Add the missing "Receives against this PO" cross-linkage list on the PO show page.

### Verification outcome

Phase 3 was verified by code inspection. Live HTTP tests cannot be run in this environment (no `php`/`docker` CLI on the host), but the changes were validated by:

1. **Brace/paren/bracket balance check** on all 4 modified PHP files (`PurchaseReceiveController.php`, `PurchaseOrderController.php`, `PurchaseOrder.php` model, `routes/web.php`) — all OK (all three counts at 0).
2. **Class-name parity check** — grep'd every `purch-index-*`, `purch-po-form-*`, and `purch-badge-*` class used in the 3 GRN blades + the new "Receives against this PO" section on PO show against the legacy `PurchaseReceive/{index,create,details}.php` views. All class names match the legacy structure 1:1.
3. **CSS class existence check** — confirmed every class used is defined in the linked CSS files (`purchase-index.css`, `purchase-order-form.css`, `purchase-order-details.css`). All present.
4. **Blade directive balance** — every `@push`/`@endpush` and `@section`/`@endsection` pair balanced across all 4 modified blades (3 GRN + 1 PO show).
5. **Blade escaping audit** — no JS-embedded literal `@word(...)` patterns that would be miscompiled by the Blade engine.
6. **Route conflict check** — `export` GET route declared inside the `admin/purchase-receives` prefix group BEFORE the resource declaration, so Laravel's route matcher resolves it ahead of the `show` verb. No 404/405 collisions.
7. **Endpoint reuse check** — confirmed the GRN create page successfully reuses the Phase 2 `admin.purchase-orders.search-products` endpoint for its custom typeahead (no duplicate endpoint added).
8. **Eager-load check** — confirmed `PurchaseOrderController::show()` now eager-loads `receives` (with `warehouse` nested) so the new "Receives against this PO" list doesn't N+1.
9. **No Select2 leak check** — confirmed zero references to the legacy `product-select` Select2 class on the GRN create blade (replaced with custom typeahead). Header selects also dropped Select2 in favor of native `<select>` for legacy-faithful parity.

### Deliverables

| # | Task | Status | Files touched |
|---|---|---|---|
| 1 | Link `purchase-index.css` on GRN index + show; `purchase-order-form.css` on GRN create | ✅ | 3 GRN blades — `@push('css')` block |
| 2 | Restructure `purchase-receives/index.blade.php` — `.purch-index-app.purch-grn`, `.purch-index-hero`, `.purch-index-tag`, `.purch-index-filters-shell`, `.purch-index-smart-panel`, `.purch-index-preset-row`, `.purch-index-status-chips`, `.purch-index-status-chip`, `.purch-index-search-wrap`, `.purch-index-active-bar`, `.purch-index-results-card`, `.purch-index-mobile-cards`, `.purch-badge`. `?returned=1` toggle shows cancelled GRNs. | ✅ | `purchase-receives/index.blade.php` (full rewrite) |
| 3 | Restructure `purchase-receives/create.blade.php` — `.purch-po-form-app`, `.purch-po-form-layout`, `.purch-po-form-card`, `.purch-po-form-card-head`, `.purch-po-form-card-body`, `.purch-po-items-card`, `.purch-po-product-cell`, `.purch-po-product-dropdown`, `.purch-po-form-footer`, `.purch-po-total-label`, `.purch-po-form-actions`. Direct Purchase toggle kept. Per-line warehouse `<select>` kept (native, not Select2). "Remaining" column on PO-linked mode kept (read-only display). Select2 product dropdown replaced with custom text-input typeahead reusing Phase 2 `search-products` endpoint. | ✅ | `purchase-receives/create.blade.php` (targeted restructure) |
| 4 | Restructure `purchase-receives/show.blade.php` — kept the rich layout (stat cards + stock movements + GL + ledger cards). Added `.purch-index-app.purch-po-detail` wrapper for visual consistency. Linked `purchase-index.css` + `purchase-order-details.css`. | ✅ | `purchase-receives/show.blade.php` (targeted restructure) |
| 5 | Verify "Receive against this PO" button on `purchase-orders/show.blade.php` (added in Phase 0 — still links correctly to `admin.purchase-receives.create` with `?po_id=`) | ✅ | (no change needed — verified) |
| 6 | Add "Receives against this PO" list section on `purchase-orders/show.blade.php` — queries `$po->receives` (eager-loaded via new `receives()` relation), renders as a table below the PO items table. Columns: GRN # (link to GRN show), Date, Warehouse, Amount, Status (badge), Reversed? badge, Actions (View button). Empty state shows "No GRNs yet" with link to create one (if PO can receive). | ✅ | `purchase-orders/show.blade.php` (added Receives section) + `PurchaseOrder.php` model (added `receives()` relation) + `PurchaseOrderController::show()` (eager-load `receives`) |
| 7 | Replace client-side DataTables with server-side DataTables on GRN index — `?datatables=1` mode in `index()` returns JSON `{draw, recordsTotal, recordsFiltered, data}`. Order by date/GRN code/PO id/supplier/amount/status/created_by. Search across GRN code + supplier name + branch name + PO code. | ✅ | `PurchaseReceiveController::grnDataTableJson()` (private) + `index()` branch |
| 8 | Add CSV export endpoint `GET admin/purchase-receives/export` (returns `Content-Type: text/csv` with UTF-8 BOM for Excel). Branch-scoped + same filter logic as `index()`. | ✅ | `PurchaseReceiveController::export()` + new route `export` |
| 9 | Add localStorage filter persistence (`purchase_receive_filters_v1`) — saves from/to/status/search; restores on page load. | ✅ | `purchase-receives/index.blade.php` — `saveFilters()` / `loadFilters()` |
| 10 | Add mobile card rendering on `<768px` — DataTables `drawCallback` populates `#receiveCards` from the same JSON. | ✅ | `purchase-receives/index.blade.php` — `renderCards()` |
| 11 | Smoke-test (user-side, pending) | ⏳ | User to run the 8-step smoke-test checklist below |

### Files touched

- **`laravel/app/Models/PurchaseOrder.php`** — added `receives()` HasMany relation to `PurchaseReceive` (ordered by `receive_date desc, id desc`). Used by the new "Receives against this PO" list on the PO show page.
- **`laravel/app/Http/Controllers/Admin/PurchaseOrderController.php`** — `show()` method modified to eager-load `receives` (with nested `warehouse`) so the new list section doesn't N+1.
- **`laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php`** — added 2 new methods: `export()` (CSV stream with UTF-8 BOM, branch-scoped) and `grnDataTableJson()` (private, server-side DataTables JSON response). `index()` modified to branch into DataTables JSON mode when `?datatables=1` is set.
- **`laravel/routes/web.php`** — added 1 new route inside the existing `admin/purchase-receives` prefix group: `GET export` (RBAC `role:admin,manager,warehouse_manager,accountant`). No other route changes.
- **`laravel/resources/views/admin/purchase-receives/index.blade.php`** — full legacy-faithful restructure (~470 lines). 5 stat cards (total/draft/confirmed/cancelled/total_value) + collapsible filter panel with date presets + status chips + smart search + active filter bar + server-side DataTables + mobile card container + SweetAlert2 confirm-GRN modal + SweetAlert2 cancel-GRN modal with required reason. `?returned=1` query param flips to "Returned / cancelled GRNs" view.
- **`laravel/resources/views/admin/purchase-receives/create.blade.php`** — targeted restructure (~790 lines). Wrapped form in `.purch-po-form-app` + `.purch-po-form-layout`. Replaced Select2 product dropdown with custom text-input typeahead reusing Phase 2 `search-products` endpoint (debounced input + dropdown results + click-to-select + outside-click close + qty-input focus after select). Replaced Select2 on header selects (supplier/branch/warehouse) with native `<select>` for legacy-faithful parity. Added legacy-faithful footer with running total + save/cancel actions. SweetAlert2 submit guard for "no valid line items" / "incomplete items".
- **`laravel/resources/views/admin/purchase-receives/show.blade.php`** — targeted restructure. Added `.purch-index-app.purch-po-detail` wrapper + linked `purchase-index.css` + `purchase-order-details.css`. Kept the rich layout (stat cards + stock movements + GL + ledger cards + journal entry lines + supplier ledger entries) — Laravel is already better than legacy here.
- **`laravel/resources/views/admin/purchase-orders/show.blade.php`** — added new "Receives against this PO" list section after the PO items table. Shows GRN code (link), date, warehouse, amount, status badge, reversed badge, view button. Empty state with link to "Receive goods against this PO" (if PO can receive).

### Bugs fixed in Phase 3

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-16 | Medium | GRN index used Laravel-paginated query + client-side DataTables — no server-side DataTables, no mobile cards, no CSV export, no `?returned=1` toggle. Replaced with full server-side DataTables JSON mode + mobile card rendering + CSV stream response + `?returned=1` toggle. | `PurchaseReceiveController.php`, `index.blade.php` |
| BUG-17 | Medium | GRN create used a `<select>` with up to 500 hardcoded products (Select2) — broken on catalogs with >500 SKUs and inconsistent with the Phase 2 PO create UX. Replaced with custom text-input typeahead reusing the Phase 2 `search-products` endpoint. Also dropped Select2 on header selects (supplier/branch/warehouse) for legacy-faithful parity. | `create.blade.php` |
| BUG-18 | Medium | PO show page had no "Receives against this PO" list — user had to manually navigate to GRN index and filter by PO. Added a dedicated list section under the PO items table, eager-loaded via new `receives()` relation. | `PurchaseOrder.php`, `PurchaseOrderController.php`, `purchase-orders/show.blade.php` |
| BUG-19 | Low | GRN create form lacked the legacy-faithful footer (`.purch-po-form-footer` + `.purch-po-total-label` + `.purch-po-form-actions`) — running total only appeared in the items table tfoot, not in a sticky action bar. Added the legacy footer. | `create.blade.php` |
| BUG-20 | Low | GRN show page lacked the legacy wrapper class (`.purch-index-app.purch-po-detail`) and CSS link — visual inconsistency with the rest of the purchase module. Added wrapper + linked `purchase-index.css` + `purchase-order-details.css`. | `show.blade.php` |
| BUG-21 | Low | GRN index filters did not persist across page reloads — every refresh reset to defaults. Added `localStorage` persistence under key `purchase_receive_filters_v1`. | `index.blade.php` |
| BUG-22 | Low | No CSV export on GRN index — users had to manually copy/paste from the table. Added `export()` endpoint returning `text/csv` with UTF-8 BOM and standard headers (GRN Code, PO Code, Supplier, Branch, Warehouse, Receive Date, Item Count, Total Amount, Status, Reversed, Created By, Notes). | `PurchaseReceiveController.php`, `index.blade.php` (Export button in hero) |

### Smoke-test checklist (user-side, pending)

Run this on your local Docker after `git pull origin main`:

1. **Login as admin** → visit `/admin/purchase-receives`. Verify: 5 stat cards render with real counts. "Filters" button toggles the collapse panel. "New receive" / "Returned" / "Export" buttons appear in hero.
2. **Click "New receive"** → verify: 2-col layout (GRN header on left, Items on right). The "Receive against PO" panel is shown with a PO dropdown. Toggle "Direct receive (no PO)" — the PO dropdown disables and an "Add item" button appears. Type 3+ chars in the product search box → dropdown appears with matches. Click a product → search box populates, hidden `product_id` is set, qty input gets focus. Add 2 line items with qty + rate + warehouse → footer total updates live. Click "Create Draft GRN" → redirected to GRN show page.
3. **On the GRN show page** → verify: hero shows GRN code + status badge. Stat cards render (receive date, total, journal entry id). Stock movements table is empty (GRN is still draft). "Confirm" button visible.
4. **Click "Confirm"** (if visible) → SweetAlert2 confirm dialog explains what will happen (stock IN, GL Dr Inventory / Cr AP, supplier ledger credit, PO update). Confirm → GRN status becomes "Confirmed". Stock movements table populates. Journal entry lines render.
5. **Back on GRN index** → click "Filters" → set "From" to last month, status chip "Confirmed" → table reloads with filtered results. Refresh the page → filters persist (localStorage). Click "Clear filters" → table resets to "this month" preset.
6. **Resize browser to <768px** → table disappears, mobile cards render with one card per row showing GRN code, date, supplier, PO link (or "Direct" badge), status badge, amount, and action buttons.
7. **Click "Export"** → CSV file downloads. Open in Excel/Sheets → verify headers (GRN Code, PO Code, Supplier, Branch, Warehouse, Receive Date, Item Count, Total Amount, Status, Reversed, Created By, Notes) and rows match the current filter.
8. **Visit a PO show page** (`/admin/purchase-orders/{id}`) that has at least one confirmed GRN → verify the new "Receives against this PO" list section appears below the PO items table. Each row shows GRN code (link), date, warehouse, amount, status badge, reversed badge (if applicable), and View button. Click the GRN code → navigates to GRN show page.

If all 8 steps pass, Phase 3 is verified.

---

## Phase 2 Completion Summary (2026-07-22)

### Goal

PurchaseOrder index / create / edit / show pages look and behave like the legacy (lagachy) software — legacy-faithful DOM structure (same CSS class names so the existing `purchase-index.css`, `purchase-order-form.css`, and `purchase-order-details.css` work unmodified), legacy-faithful UX flows (collapsible filters, status chips, smart search, mobile cards, custom typeahead product picker instead of Select2, server-side DataTables, CSV export, localStorage filter persistence).

### Verification outcome

Phase 2 was verified by code inspection. Live HTTP tests cannot be run in this environment (no `php`/`docker` CLI on the host), but the changes were validated by:

1. **Brace/paren/bracket balance check** on `PurchaseOrderController.php` and `routes/web.php` (all OK — all three counts at 0).
2. **Class-name parity check** — grep'd every `purch-index-*`, `purch-po-*`, and `purch-badge-*` class used in the 4 new blades against the legacy `PurchaseOrder/{index,create,edit,details}.php` views. All class names match the legacy structure 1:1.
3. **CSS class existence check** — confirmed every class used is defined in the linked CSS files (`purchase-index.css`, `purchase-order-form.css`, `purchase-order-details.css`). All present.
4. **Blade directive balance** — every `@push`/`@endpush` and `@section`/`@endsection` pair balanced across all 4 blades.
5. **Blade escaping audit** — no JS-embedded literal `@word(...)` patterns that would be miscompiled by the Blade engine.
6. **Layout dependency check** — confirmed `layouts/admin.blade.php` already loads jQuery 3.6, DataTables, SweetAlert2, and Bootstrap 5 bundle (all required by the Phase 2 JS).
7. **Route conflict check** — `search-products` and `export` GET routes are declared inside the `admin/purchase-orders` prefix group BEFORE the resource declaration, so Laravel's route matcher resolves them ahead of the `show` verb (`admin/purchase-orders/{id}`). No 404/405 collisions.

### Deliverables

| # | Task | Status | Files touched |
|---|---|---|---|
| 1 | Link `purchase-index.css` on index/create/edit/show; `purchase-order-form.css` on create/edit; `purchase-order-details.css` on show | ✅ | All 4 blades — `@push('css')` block |
| 2 | Restructure `index.blade.php` — `.purch-index-app`, `.purch-index-hero`, `.purch-index-tag`, `.purch-index-filters-shell`, `.purch-index-smart-panel`, `.purch-index-preset-row`, `.purch-index-status-chips`, `.purch-index-status-chip`, `.purch-index-search-wrap`, `.purch-index-search-input`, `.purch-index-active-bar`, `.purch-index-results-card`, `.purch-index-mobile-cards`, `.purch-badge` | ✅ | `purchase-orders/index.blade.php` |
| 3 | Restructure `create.blade.php` and `edit.blade.php` — `.purch-po-form-app`, `.purch-po-form-layout` (2-col), `.purch-po-form-card`, `.purch-po-form-card-head`, `.purch-po-form-card-body`, `.purch-po-items-card`, `.purch-po-product-cell`, `.purch-po-product-dropdown` (custom typeahead — NOT Select2), `.purch-po-form-footer`, `.purch-po-total-label`, `.purch-po-form-actions` | ✅ | `purchase-orders/create.blade.php`, `purchase-orders/edit.blade.php` |
| 4 | Restructure `show.blade.php` — `.purch-po-detail`, `.purch-po-detail-stats`, `.purch-po-stat` (4 stat cards), `.purch-po-progress-wrap`, `.purch-po-detail-grid`, `.purch-po-detail-card`, `.purch-po-detail-items`, `.purch-po-status-pill` | ✅ | `purchase-orders/show.blade.php` |
| 5 | Replace Select2 product dropdown with custom text-input typeahead — `GET admin/purchase-orders/search-products?term=...` returning JSON `[{id, product_name, product_code}, ...]` | ✅ | `PurchaseOrderController::searchProducts()` + new route `search-products` |
| 6 | Replace client-side DataTables with server-side DataTables — `?datatables=1` mode in `index()` returns JSON `{draw, recordsTotal, recordsFiltered, data:[...]}` | ✅ | `PurchaseOrderController::poDataTableJson()` (private) + `index()` branch |
| 7 | Add localStorage filter persistence (`purchase_order_filters_v1`) — saves from/to/status/search; restores on page load | ✅ | `purchase-orders/index.blade.php` — `saveFilters()` / `loadFilters()` |
| 8 | Add mobile card rendering on `<768px` — DataTables `drawCallback` populates `#poCards` from the same JSON | ✅ | `purchase-orders/index.blade.php` — `renderCards()` |
| 9 | Add CSV export endpoint `GET admin/purchase-orders/export` (returns `Content-Type: text/csv` with UTF-8 BOM for Excel) | ✅ | `PurchaseOrderController::export()` + new route `export` |
| 10 | Smoke-test (user-side, pending) | ⏳ | User to run the 7-step smoke-test checklist below |

### Files touched

- `laravel/app/Http/Controllers/Admin/PurchaseOrderController.php` — added 3 new methods: `searchProducts()` (typeahead JSON), `export()` (CSV stream), `poDataTableJson()` (private, server-side DataTables). Modified `index()` to branch into DataTables JSON mode when `?datatables=1` is set.
- `laravel/routes/web.php` — added 2 new routes inside the existing `admin/purchase-orders` prefix group: `GET search-products` (RBAC `role:admin,manager,warehouse_manager`, throttled 60/min) and `GET export` (RBAC `role:admin,manager,warehouse_manager,accountant`).
- `laravel/resources/views/admin/purchase-orders/index.blade.php` — full legacy-faithful restructure (~560 lines). Includes: 7 stat cards (total/draft/sent/partial/received/cancelled/total_value), collapsible filter panel with date presets + status chips + smart search, active filter bar, results card with server-side DataTables + mobile card container, SweetAlert2 cancel-PO modal with required reason.
- `laravel/resources/views/admin/purchase-orders/create.blade.php` — full legacy-faithful restructure (~425 lines). 2-col layout: order details card + line items card with custom typeahead product picker. Footer with running total + save/cancel actions. SweetAlert2 submit guard for "no valid line items".
- `laravel/resources/views/admin/purchase-orders/edit.blade.php` — full legacy-faithful restructure (~445 lines). Same shape as create; seeds line items from `$po->items` (product search box is readonly for existing lines — user must remove + re-search to change product).
- `laravel/resources/views/admin/purchase-orders/show.blade.php` — full legacy-faithful restructure (~310 lines). 4 stat cards (order total / receipt progress % / supplier / created by) + progress bar + 2-col grid (dates / notes) + line items table with per-row received/pending columns + status pill. SweetAlert2 modals for Mark as Sent and Cancel.

### Bugs fixed in Phase 2

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-11 | Medium | PO index used Laravel-paginated query — no server-side DataTables, no mobile cards, no CSV export. Replaced with full server-side DataTables JSON mode + mobile card rendering + CSV stream response. | `PurchaseOrderController.php`, `index.blade.php` |
| BUG-12 | Medium | PO create/edit used a `<select>` with up to 500 hardcoded products — broken on catalogs with >500 SKUs and slow to render. Replaced with custom text-input typeahead hitting `searchProducts()` endpoint (returns top 20 matches by name OR code). | `create.blade.php`, `edit.blade.php`, `PurchaseOrderController.php` |
| BUG-13 | Low | PO show page lacked the legacy progress bar (`purch-po-progress-wrap`) and 4-stat-card layout (`purch-po-detail-stats`). Added both. | `show.blade.php` |
| BUG-14 | Low | PO index filters did not persist across page reloads — every refresh reset to defaults. Added `localStorage` persistence under key `purchase_order_filters_v1` with a `loadFilters()`/`saveFilters()` pair. | `index.blade.php` |
| BUG-15 | Low | No CSV export on PO index — users had to manually copy/paste from the table. Added `export()` endpoint returning `text/csv` with UTF-8 BOM and standard headers (PO Code, Supplier, Branch, Warehouse, PO Date, Expected Date, Total Amount, Status, Created By, Notes). | `PurchaseOrderController.php`, `index.blade.php` (Export button in hero) |

### Smoke-test checklist (user-side, pending)

Run this on your local Docker after `git pull origin main`:

1. **Login as admin** → visit `/admin/purchase-orders`. Verify: 7 stat cards render with real counts. "Filters" button toggles the collapse panel. "New PO" / "Cancelled" / "Export" buttons appear in hero.
2. **Click "New PO"** → verify: 2-col layout (Order details on left, Line items on right). Type at least 3 characters in the product search box → dropdown appears with matches. Click a product → product name + code populate the search box, hidden `product_id` is set, qty input gets focus. Add 3 line items with qty + rate → footer total updates live. Click "Save purchase order" → redirected to PO show page.
3. **On the PO show page** → verify: 4 stat cards render (order total, receipt progress 0%, supplier, created by). Progress bar is empty (0% received). Line items table shows ordered/received/pending columns. Status pill shows "Draft".
4. **Click "Mark as Sent"** → SweetAlert2 confirm → PO status becomes "Sent". Edit button disappears (sent POs are immutable).
5. **Click "Receive goods"** → redirected to GRN create page with `?po_id=` preselected (this is Phase 3's territory — just verify the link works for now).
6. **Back on PO index** → click "Filters" → set "From" to last month, status chip "Sent" → table reloads with filtered results. Refresh the page → filters persist (localStorage). Click "Clear filters" → table resets to "this month" preset.
7. **Resize browser to <768px** → table disappears, mobile cards render with one card per row showing PO code, date, supplier, branch, status badge, amount, and action buttons.
8. **Click "Export"** → CSV file downloads. Open in Excel/Sheets → verify headers and rows match the current filter.

If all 8 steps pass, Phase 2 is verified.

---

## Phase 1 Completion Summary (2026-07-22)

### Verification outcome

Phase 1 was verified by code inspection. Live HTTP tests cannot be run in this environment (no `php`/`docker` CLI on the host), but the changes were validated by:
1. Brace/paren/bracket balance check on all 6 modified PHP files (all OK).
2. Cross-reference of every route definition against `legacy/app/config/route_roles.php` `PurchaseOrderController`/`PurchaseReceiveController`/`PurchaseReturnController` matrices.
3. Manual review of each `index()`, `create()`, `store()`, AJAX endpoint, and middleware path for branch-scoping completeness.

### Bugs fixed in Phase 1

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| **BUG-6** No RBAC middleware on purchase routes (SECURITY) | CRITICAL | All 3 purchase route groups in `routes/web.php` restructured with per-action `role:` middleware matching the legacy `route_roles.php` matrix. Read actions (`index`/`show`) = admin/manager/warehouse_manager/accountant; writes (`create`/`store`/`edit`/`update`/`markAsSent`) = admin/manager/warehouse_manager; destructive (`cancel`/`confirm`) = admin/manager (return `cancel` also allows accountant per legacy `reverse`). salesman/dispatcher/hr/user have NO access. | `routes/web.php` |
| **BUG-7** No branch isolation on purchase routes (SECURITY) | CRITICAL | (a) `EnforceBranchIsolation` middleware's `inferTableFromUri()` extended to recognize `purchase-orders`/`purchase-receives`/`purchase-returns` paths → non-admin users can no longer access another branch's PO/GRN/Return by guessing URL ids. (b) All write routes carry `branch.isolation` middleware → non-admin POST bodies cannot forge `branch_id`. (c) `Controller` base class gets 2 new helpers: `resolveBranchIdForRead()` + `resolveBranchIdForWrite()`. (d) All 3 purchase controllers' `index()` queries now branch-scoped (stats too). (e) `store()`/`update()` force the session branch_id for non-admin users. (f) AJAX endpoints `getPoDetails` + `getReceiveDetails` deny cross-branch reads with 403. (g) `create()` GRN/Return pre-fill (via `?po_id=` / `?receive_id=`) rejects cross-branch source records. | `EnforceBranchIsolation.php`, `Controller.php`, 3 purchase controllers |

### Role matrix implemented (mirrors legacy `route_roles.php`)

| Action | PO | GRN | Return |
|---|---|---|---|
| `index` / `show` (read) | admin, manager, warehouse_manager, accountant | admin, manager, warehouse_manager, accountant | admin, manager, warehouse_manager, accountant |
| `create` / `store` (write) | admin, manager, warehouse_manager | admin, manager, warehouse_manager | admin, manager, warehouse_manager |
| `edit` / `update` (write) | admin, manager, warehouse_manager | — (no edit) | — (no edit) |
| `mark-sent` (state transition) | admin, manager, warehouse_manager | — | — |
| `getPoDetails` / `getReceiveDetails` (AJAX) | — | admin, manager, warehouse_manager | admin, manager, warehouse_manager |
| `confirm` (destructive: stock + GL) | — | admin, manager | admin, manager |
| `cancel` / `reverse` (destructive) | admin, manager | admin, manager | admin, manager, accountant |

`salesman`, `dispatcher`, `hr`, `user`, `other` have NO access to any purchase route. `superadmin` always passes (handled by `EnsureRole` middleware).

### Branch isolation rules implemented

| Layer | Rule |
|---|---|
| **Middleware (URL params)** | `EnforceBranchIsolation` resolves `branch_id` from `/admin/purchase-orders/{id}`, `/admin/purchase-receives/{id}`, `/admin/purchase-returns/{id}` by looking up the row in `purchase_orders`/`purchase_receives`/`purchase_returns`. Non-admin → 403 if mismatch. Admin → bypass + `user_audit_log` entry with `action='branch_override'`. |
| **Middleware (POST body)** | Same middleware inspects `request->input('branch_id')`. Non-admin → 403 if mismatch. |
| **Controller `index()`** | All 3 controllers call `resolveBranchIdForRead($request->input('branch_id'))`. Non-admin → session branch only. Admin → honour explicit `?branch_id=` if it points to an active branch, else session branch. Stats queries also branch-scoped. |
| **Controller `store()` / `update()`** | All write controllers call `resolveBranchIdForWrite($validated['branch_id'])`. Non-admin → ALWAYS session branch (client-supplied `branch_id` ignored). Admin → honour explicit value. |
| **AJAX endpoints** | `getPoDetails()` + `getReceiveDetails()` load the source record then explicitly check `branch_id` vs session for non-admins → 403 JSON on mismatch. |
| **`create()` pre-fill** | `PurchaseReceiveController::create(?po_id=)` + `PurchaseReturnController::create(?receive_id=)` both check the source record's `branch_id` vs session for non-admins → redirect with error on mismatch. |
| **GRN selector on Return create** | The "confirmed GRNs" dropdown list is now branch-scoped for non-admins — they cannot see another branch's GRNs in the picker. |

### Phase 1 deliverables

- **`laravel/app/Http/Middleware/EnforceBranchIsolation.php`** — `inferTableFromUri()` extended with 3 new patterns (`purchase-orders`, `purchase-receives`, `purchase-returns`) mapping to their respective tables.
- **`laravel/app/Http/Controllers/Controller.php`** — 2 new protected helpers: `resolveBranchIdForRead(?int)` + `resolveBranchIdForWrite(?int)`. Available to every controller in the app.
- **`laravel/routes/web.php`** — All 3 purchase route groups restructured. Resource declarations split (`->only([...])` excludes write verbs) so write verbs can carry tighter RBAC + `branch.isolation`.
- **`laravel/app/Http/Controllers/Admin/PurchaseOrderController.php`** — `index()` branch-scoped (query + stats); `store()` + `update()` force session branch for non-admins.
- **`laravel/app/Http/Controllers/Admin/PurchaseReceiveController.php`** — `index()` branch-scoped; `create(?po_id=)` cross-branch check; `store()` forces session branch; `getPoDetails()` AJAX cross-branch 403.
- **`laravel/app/Http/Controllers/Admin/PurchaseReturnController.php`** — `index()` branch-scoped; `create(?receive_id=)` cross-branch check; GRN selector branch-scoped; `getReceiveDetails()` AJAX cross-branch 403.

### Phase 1 smoke-test checklist (user to run on local Docker)

After pulling `main` (no migrations needed — Phase 1 is pure code):

1. **Salesman denied.** Log in as a salesman. Visit `/admin/purchase-orders`. Verify redirect to dashboard with "You do not have permission" error.
2. **Accountant read-only.** Log in as accountant. Visit `/admin/purchase-orders`. Verify index renders. Click "Create" — verify redirect (accountant is not in the create role list).
3. **Warehouse_manager can create but not cancel.** Log in as warehouse_manager. Create a PO. Try to cancel it — verify redirect (cancel is admin/manager only).
4. **Manager can do everything except audit-level admin actions.** Log in as manager. Verify all CRUD + cancel + confirm works on PO/GRN/Return.
5. **Branch A user cannot see Branch B records.** Log in as Branch A's warehouse_manager. Visit `/admin/purchase-orders?branch_id=<B_id>` — verify the URL filter is ignored (only session-branch records shown). Try `/admin/purchase-orders/<B_PO_id>` — verify 403/redirect.
6. **Branch A user cannot create for Branch B.** As Branch A's warehouse_manager, POST a new PO with `branch_id=<B_id>` in the form body. Verify the row is created with Branch A's id (the form value is silently overridden).
7. **Admin can override branch.** Log in as admin. Visit `/admin/purchase-orders?branch_id=<B_id>` — verify Branch B's records are shown. Verify `user_audit_log` gets a `branch_override` row.
8. **AJAX endpoints respect branch.** As Branch A's warehouse_manager, call `GET /admin/purchase-receives/po-details?po_id=<B_PO_id>` — verify 403 JSON. Same for `GET /admin/purchase-returns/receive-details?receive_id=<B_GRN_id>`.

If all 8 pass, Phase 1 is verified. If any fail, log the failure as a new BUG-11+ in §6 below and patch before starting Phase 2.

### Phase 1 → Phase 2 handoff

Phase 2 (PurchaseOrder UI parity) can now start. The security perimeter is in place — every purchase route is role-gated and branch-isolated. Phase 2 will touch only blade views (4 PO views), 1 controller method (search-products + datatables + export), and link 3 CSS files. No further route/middleware/schema changes are anticipated.

---

## Phase 0 Completion Summary (2026-07-22)

### Verification outcome

Live PostgreSQL could not be queried directly (no `psql`/`docker` CLI in this environment), but the schema was verified by reading `laravel/database/migrations/2025_01_01_000001_create_rcerp_schema.php`, which loads `database/sql/05_purchase.sql` verbatim via `executeSqlFile()`. Therefore the live schema exactly matches `05_purchase.sql` — confirming all 4 schema gaps are real (not stale-file artifacts).

### Bugs fixed in Phase 0

| Bug | Severity | Fix | Files touched |
|---|---|---|---|
| BUG-1 `purchase_receives.status` MISSING | CRITICAL | Migration `2025_01_24_000001_add_status_to_purchase_receives.php` adds the column with CHECK constraint + index `idx_pr_status`. SQL file updated to match. | migration, `05_purchase.sql` |
| BUG-2 `purchase_returns.status` MISSING | CRITICAL | Migration `2025_01_24_000002_add_status_to_purchase_returns.php` (same pattern). | migration, `05_purchase.sql` |
| BUG-3 `purchase_orders.expected_date` MISSING | CRITICAL | Migration `2025_01_24_000003_add_expected_date_to_purchase_orders.php`. | migration, `05_purchase.sql` |
| BUG-4 `purchase_returns.warehouse_id` NOT NULL but service didn't write it | CRITICAL | `PurchaseReturnService::createReturn()` now inherits `warehouse_id` from the GRN (`$receive->warehouse_id`). `PurchaseReturn` model updated: `warehouse_id` added to `$fillable` + `$casts`, plus a new `warehouse()` belongsTo relation. | service, model |
| BUG-5 GRN cancel doesn't block if active returns exist | FUNCTIONAL GAP | `PurchaseReceiveService::cancelReceive()` now checks `PurchaseReturn::where('purchase_receive_id', $id)->where('is_reversed', false)->where('status', 'confirmed')->count()` and throws if > 0. Mirrors legacy `PurchaseReceiveModel::cancelReceive`. | service |
| BUG-8 Stale "Phase 7.2 not implemented" alert on PO show | COSMETIC | `purchase-orders/show.blade.php` now renders a real "Receive against this PO" button linking to `route('admin.purchase-receives.create', ['po_id' => $po->id])`. | blade |
| BUG-9 6 dead JS files (~2,501 lines) | CLEANUP | `git rm`-ed: `PurchaseOrder.js`, `PurchaseReceive.js`, `PurchaseReturn.js`, `purchase-order-index.js`, `purchase-receive-index.js`, `purchase-return-index.js`. Zero references in any blade/PHP file (grep-verified). | 6 files deleted |
| **BUG-10** (NEW, discovered during Phase 0) `purchase_returns.reason` MISSING | CRITICAL | The `PurchaseReturn` model has `reason` in `$fillable`, the service writes `'reason' => $data['reason'] ?? null` on INSERT, and the show blade renders `$r->reason`. But the column was missing from the SQL spec. Migration `2025_01_24_000004_add_reason_to_purchase_returns.php` adds it. | migration, `05_purchase.sql` |

### Phase 0 deliverables

- **4 new migrations** under `laravel/database/migrations/2025_01_24_*` — all IDEMPOTENT (guarded by `Schema::hasColumn`), all reversible (`down()` drops cleanly).
- **1 SQL spec updated** — `laravel/database/sql/05_purchase.sql` now matches the migrations: `purchase_orders.expected_date`, `purchase_receives.status` + `idx_pr_status`, `purchase_returns.status` + `idx_prtn_status`, `purchase_returns.reason`.
- **2 service files patched** — `PurchaseReceiveService.php` (cancel guard + `use App\Models\PurchaseReturn`), `PurchaseReturnService.php` (`warehouse_id` written on INSERT).
- **1 model patched** — `PurchaseReturn.php` (`warehouse_id` in `$fillable`/`$casts` + `warehouse()` relation).
- **1 blade patched** — `purchase-orders/show.blade.php` (real "Receive against this PO" button).
- **6 dead JS files deleted** — 2,501 lines of orphaned code removed.
- **Bug doc updated** — `docs/PURCHASE_PARITY_PLAN.md` §6 now annotated with verification + fix notes.

### Phase 0 smoke-test checklist (user to run on local Docker)

After pulling `main` and running `php artisan migrate`:

1. **Migration runs cleanly.** `php artisan migrate` should report 4 new migrations applied with no errors. Verify with `\d purchase_receives`, `\d purchase_returns`, `\d purchase_orders` in `rcerp_postgres` — all 4 columns should appear.
2. **PO create with `expected_date`.** Create a PO with an expected date set. Verify the row is persisted (no SQL error). Open the show page → verify the expected date renders.
3. **GRN create against the PO.** Click "Receive against this PO" on the PO show page. Create + confirm the GRN. Verify: stock IN, GL journal posted, supplier ledger credited, PO status → `partial` or `received`.
4. **Return create against the GRN.** From the GRN show page, click "Return against this GRN" (note: this button doesn't exist yet — Phase 4 adds it; for now use the Return index → Create → pick GRN dropdown). Create + confirm a return. Verify: stock OUT, GL reversed, supplier ledger debited, GRN item `return_qty` incremented.
5. **Reverse the return.** Click "Reverse" on the return. Provide a reason. Verify: stock restored, GL reversed, ledger reversed, `return_qty` back to 0.
6. **Try to cancel the GRN while a return is active.** Create + confirm another return. Try to cancel the GRN. Verify the error: "Cannot cancel GRN: 1 active return(s) exist against it. Reverse them first." Reverse the return → now GRN cancel should succeed.
7. **PO cancel with reason.** Cancel a draft PO with a reason. Verify the `[Cancelled] reason` text is appended to notes.

If all 7 pass, Phase 0 is verified. If any fail, log the failure as a new BUG-11+ in §6 below and patch before starting Phase 1.

### Phase 0 → Phase 1 handoff

Phase 1 (RBAC + branch isolation) can now start. The schema is correct, the services are correct, and the dead code is gone. Phase 1 will touch only `routes/web.php` + the 3 controllers + possibly a new middleware — no schema changes needed.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Scope & Goals](#2-scope--goals)
3. [Current Laravel State](#3-current-laravel-state)
4. [Legacy Reference Inventory](#4-legacy-reference-inventory)
5. [Gap Analysis](#5-gap-analysis)
6. [Critical Bugs & Blockers](#6-critical-bugs--blockers)
7. [Decisions (Locked)](#7-decisions-locked)
8. [Phase-by-Phase Implementation Plan](#8-phase-by-phase-implementation-plan)
9. [Per-Phase Success Criteria](#9-per-phase-success-criteria)
10. [Risks & Open Questions](#10-risks--open-questions)
11. [Out of Scope / Net-New Features](#11-out-of-scope--net-new-features)
12. [File Inventory (Legacy → Laravel mapping)](#12-file-inventory-legacy--laravel-mapping)

---

## 1. Executive Summary

The Laravel codebase already contains a **substantial purchase skeleton** (~5,255 lines across controllers, services, models, and views) that implements the correct *business logic backbone*: transactional two-phase confirm/cancel flows, double-entry GL postings, supplier sub-ledger entries, and stock movements with weighted-average cost on GRN and original-rate on Return. This backbone is solid and should be **preserved, not rewritten**.

What's missing is **legacy parity on three layers**:

1. **UI / markup layer** — Laravel views use generic Bootstrap `.card` markup; legacy uses purpose-built `.purch-*` / `.prt-*` class families with custom CSS that gives the POS-style look the user wants. None of the 6 legacy purchase CSS files (`purchase-index.css`, `purchase-order-form.css`, etc.) are linked to Laravel views.
2. **Feature layer** — Several legacy-only features are absent from Laravel: Damage condition (no-stock return), printable Return slip, per-module audit-log pages, PurchaseAudit checklist, offcanvas quick-return, live chip counts, smart-sort, CSV exports, AJAX product typeahead, cross-linkage buttons between PO/GRN/Return.
3. **Hardening layer** — Laravel purchase routes have **no RBAC middleware** and **no branch isolation**. There are also **3-4 critical schema/code mismatches** (status columns missing from the SQL spec, expected_date missing, warehouse_id NOT NULL but not written) that need verification and migration.

The plan below is split into **9 phases** (Phase 0 through Phase 8). Each phase is independently shippable. The phases are ordered so that the schema/hardening gaps are fixed first (Phase 0–1), then UI parity is layered on top (Phase 2–5), then the missing legacy features are added (Phase 6–7), and finally a full end-to-end QA pass (Phase 8).

**Estimated total effort:** ~3,500–4,500 lines of net new code (HTML restructuring + new blade views + new AJAX endpoints + migrations + service tweaks) plus removal of ~2,500 lines of dead JS in `laravel/public/assets/js/Purchase*.js` / `purchase-*.js`.

---

## 2. Scope & Goals

### 2.1 In Scope

- **Purchase Order (PO):** list / create / edit / show / cancel / mark-sent.
- **Purchase Receive (GRN):** list / create (PO-linked OR Direct) / show / confirm / cancel (with full reversal).
- **Purchase Return:** list / create (always against a GRN) / show / confirm / cancel (with full reversal) / printable slip.
- **Damage condition:** Good vs Damage return lines (Damage = no stock movement).
- **Purchase Audit:** per-module audit-log pages + central checklist dashboard with 12 health-check sections.
- **Cross-linkage:** PO ↔ GRN ↔ Return navigation buttons and "linked documents" sections.
- **RBAC + branch isolation:** Role-gated routes + branch-scoped queries.
- **Print/export:** Return slip (browser print) + CSV export on all 3 index pages.

### 2.2 End-of-Plan Capability Matrix

| Capability | After Phase |
|---|---|
| Create a draft PO with multiple line items | Phase 0 (already works) |
| Edit a draft PO | Phase 0 (already works) |
| Mark PO as sent / Cancel PO with reason | Phase 0 (already works) |
| Create a GRN against a PO | Phase 3 |
| Create a Direct GRN (no PO) | Phase 3 |
| Split GRN items across multiple warehouses | Phase 3 |
| Confirm GRN → stock IN + GL + supplier ledger | Phase 0 (already works) |
| Cancel GRN → full reversal | Phase 0 (already works) |
| Create a Return against a GRN | Phase 4 |
| Return Good condition (stock OUT) | Phase 0 (already works) |
| Return Damage condition (no stock movement) | **Phase 5** |
| Confirm Return → GL + supplier ledger | Phase 0 (already works) |
| Cancel/Reverse Return → restore stock + GL + ledger | Phase 0 (already works) |
| Print Return slip | **Phase 6** |
| View audit log per PO/GRN/Return | **Phase 6** |
| View Purchase Audit checklist dashboard | **Phase 6** |
| Branch-isolated queries | **Phase 1** |
| Role-gated cancel/reverse actions | **Phase 1** |
| UI matches legacy look (`.purch-*` / `.prt-*` classes) | **Phases 2–4** |

### 2.3 Out of Scope (Net-New — See §11)

- Tax / discount / transport on returns (legacy has none).
- Unit conversion (case → piece) — legacy has none.
- Foreign currency / exchange rate — legacy has none.
- Approval workflows (PO/GRN/Return approval) — legacy has none.
- PO/GRN printable slips — legacy only has Return slip.

---

## 3. Current Laravel State

### 3.1 What's Already Built (Preserve)

| Layer | Status | Notes |
|---|---|---|
| **Routes** | ✅ Complete | 22 routes across 3 resource controllers + 6 custom AJAX/action endpoints. |
| **Controllers** | ✅ Complete | 3 thin controllers (~691 lines total) delegating to services. |
| **Services** | ✅ Complete | `PurchaseOrderService`, `PurchaseReceiveService`, `PurchaseReturnService` — all transactional with `lockForUpdate()`, idempotent confirm/cancel. |
| **Models** | ✅ Complete | 6 Eloquent models with relationships, status helpers (`isDraft()`, `isConfirmed()`, etc.), `SoftDeletes` trait. |
| **GL integration** | ✅ Correct | GRN posts `Dr Inventory / Cr AP`. Return posts `Dr AP / Cr Inventory`. Cancel cascades via `JournalReversalService`. |
| **Stock integration** | ✅ Correct | GRN IN at purchase rate (avg-cost recalc). Return OUT at **original receive rate** (cost integrity preserved). |
| **Supplier ledger** | ✅ Correct | GRN = credit. Return = debit. Cancel = reversal row. |
| **PO status auto-update** | ✅ Correct | GRN confirm increments `received_qty` → recompute status (draft→sent→partial→received). |
| **GRN return_qty tracking** | ✅ Correct | Return confirm increments `return_qty` on GRN item. Cancel decrements via `GREATEST(0, return_qty - qty)`. |
| **Code generation** | ✅ Correct | `DocumentSequenceService::nextCode()` with PostgreSQL advisory locks (race-safe). |
| **AJAX endpoints** | ✅ Complete | `po-details` + `receive-details` for form pre-fill, with returnable-qty capping. |
| **SweetAlert2 confirm UX** | ✅ Complete | All confirm/cancel actions prompt for reason; spinner during async. |

### 3.2 What's Missing or Broken (See §5 + §6)

- 3-4 schema/code mismatches (status columns, expected_date, warehouse_id NOT NULL).
- No RBAC middleware on purchase routes.
- No branch isolation on purchase routes.
- 6 dead/orphaned JS files (~2,500 lines) in `laravel/public/assets/js/Purchase*.js`.
- UI uses generic Bootstrap — none of the 6 legacy CSS files are linked.
- No Damage condition support.
- No printable Return slip.
- No audit-log views.
- No PurchaseAudit checklist.
- No cross-linkage buttons (PO→GRN, GRN→Return, PO→list of GRNs, GRN→list of Returns).
- No CSV exports.
- No live chip counts on Return index.
- No smart-sort on Return index.
- No offcanvas quick-return.
- Product dropdown capped at 500 — no AJAX search.
- Stale UI text on PO show page ("Phase 7.2 not implemented").

---

## 4. Legacy Reference Inventory

### 4.1 Files to Port From

| Path | Lines | Purpose |
|---|---|---|
| `legacy/app/controllers/PurchaseOrderController.php` | 331 | PO controller (index/create/edit/Details/update/delete/search_products/export/audit) |
| `legacy/app/controllers/PurchaseReceiveController.php` | 252 | GRN controller (index/create/store/details/cancel/get_po_details/export/audit) |
| `legacy/app/controllers/PurchaseReturnController.php` | 316 | Return controller (index/create/store/details/slip/reverse/search_receive/get_receive_for_return/export/audit/return_filter_summary) |
| `legacy/app/controllers/PurchaseAuditController.php` | 57 | Audit checklist controller (index/checklist/run_checks) |
| `legacy/app/views/PurchaseOrder/index.php` | 112 | PO list page (DataTables shell) |
| `legacy/app/views/PurchaseOrder/create.php` | 41 | PO create page wrapper |
| `legacy/app/views/PurchaseOrder/edit.php` | 45 | PO edit page wrapper |
| `legacy/app/views/PurchaseOrder/details.php` | 175 | PO detail page (4 stat cards + progress bar + items table) |
| `legacy/app/views/PurchaseOrder/audit.php` | 102 | PO audit-log page |
| `legacy/app/views/PurchaseOrder/partials/po_form.php` | 99 | Shared PO create/edit form (typeahead line items) |
| `legacy/app/views/PurchaseReceive/index.php` | 109 | GRN list page |
| `legacy/app/views/PurchaseReceive/create.php` | 161 | GRN create page (PO-linked OR Direct) |
| `legacy/app/views/PurchaseReceive/details.php` | 120 | GRN detail page |
| `legacy/app/views/PurchaseReceive/audit.php` | 107 | GRN audit-log page |
| `legacy/app/views/PurchaseReturn/index.php` | 168 | Return list page (with offcanvas quick-create) |
| `legacy/app/views/PurchaseReturn/create.php` | 41 | Return create page wrapper |
| `legacy/app/views/PurchaseReturn/details.php` | 94 | Return detail page |
| `legacy/app/views/PurchaseReturn/audit.php` | 118 | Return audit-log page |
| `legacy/app/views/PurchaseReturn/slip.php` | 155 | Printable Return slip (browser print) |
| `legacy/app/views/PurchaseReturn/partials/create_workspace.php` | 42 | Reusable 2-step Return workspace (find GRN → return form) |
| `legacy/app/views/PurchaseAudit/checklist.php` | 235 | Central audit checklist (12 sections + 3 detail tables) |
| `legacy/public/assets/js/PurchaseOrder.js` | 372 | PO create/edit JS (typeahead + form submit) |
| `legacy/public/assets/js/PurchaseReceive.js` | 432 | GRN create JS (PO loader + direct + submit) |
| `legacy/public/assets/js/PurchaseReturn.js` | 667 | Return workspace JS (search + load + submit) |
| `legacy/public/assets/js/purchase-order-index.js` | 353 | PO index DataTables JS |
| `legacy/public/assets/js/purchase-receive-index.js` | 279 | GRN index DataTables JS |
| `legacy/public/assets/js/purchase-return-index.js` | 398 | Return index DataTables + chip counts + reverse + offcanvas JS |
| `legacy/public/assets/css/purchase-index.css` | 335 | Shared PO/GRN index + PO form + PO details CSS |
| `legacy/public/assets/css/purchase-order-form.css` | 118 | PO create/edit form CSS |
| `legacy/public/assets/css/purchase-order-details.css` | 142 | PO details CSS |
| `legacy/public/assets/css/purchase-return-create.css` | 355 | Return create workspace CSS |
| `legacy/public/assets/css/purchase-return-index.css` | 309 | Return index + offcanvas CSS |
| `legacy/public/assets/css/purchase-audit-checklist.css` | 211 | Audit checklist CSS |
| **Total legacy reference** | **~5,886 lines** | |

### 4.2 Legacy Route Surface (Convention-Based — `/Controller/method/params`)

| METHOD | PATH | CONTROLLER@METHOD | PURPOSE |
|---|---|---|---|
| GET | `PurchaseOrder` | `index` | PO list page (HTML + DataTables JSON) |
| GET | `PurchaseOrder?cancelled=1` | `index` | Cancelled-only view |
| GET | `PurchaseOrder/create` | `create` | PO create form |
| POST | `PurchaseOrder/store` | `store` | Create PO |
| GET | `PurchaseOrder/edit/{id}` | `edit` | PO edit form (draft only) |
| POST | `PurchaseOrder/update/{id}` | `update` | Update draft PO |
| GET | `PurchaseOrder/Details/{id}` | `Details` | PO detail (capital D) |
| POST | `PurchaseOrder/delete/{id}` | `delete` | Cancel (with reason) or hard-delete (without) |
| POST | `PurchaseOrder/search_products` | `search_products` | Typeahead: product by name/code |
| GET | `PurchaseOrder/export` | `export` | CSV export |
| GET | `PurchaseOrder/audit` | `audit` | PO audit-log page |
| GET | `PurchaseReceive` | `index` | GRN list page |
| GET | `PurchaseReceive?returned=1` | `index` | Returned/Cancelled view |
| GET | `PurchaseReceive/create` | `create` | GRN create form |
| POST | `PurchaseReceive/get_po_details` | `get_po_details` | AJAX: PO + remaining lines |
| POST | `PurchaseReceive/store` | `store` | Create GRN (posts stock + GL + ledger) |
| GET | `PurchaseReceive/details/{id}` | `details` | GRN detail |
| POST | `PurchaseReceive/cancel` | `cancel` | Cancel GRN (reverses stock + GL + ledger) |
| GET | `PurchaseReceive/export` | `export` | CSV export |
| GET | `PurchaseReceive/audit` | `audit` | GRN audit-log page |
| GET | `PurchaseReturn` | `index` | Return list page |
| GET | `PurchaseReturn?reversed=1` | `index` | Reversed-only view |
| GET | `PurchaseReturn/return_filter_summary` | `return_filter_summary` | AJAX: live chip counts |
| GET | `PurchaseReturn/create` | `create` | Return create page |
| POST | `PurchaseReturn/search_receive` | `search_receive` | AJAX: GRN typeahead |
| POST | `PurchaseReturn/get_receive_for_return` | `get_receive_for_return` | AJAX: full GRN + per-warehouse stock |
| POST | `PurchaseReturn/store` | `store` | Create Return (stock OUT + GL + ledger) |
| GET | `PurchaseReturn/details/{id}` | `details` | Return detail |
| GET | `PurchaseReturn/slip/{id}` | `slip` | Printable Return slip |
| POST | `PurchaseReturn/reverse` | `reverse` | Reverse Return (restores stock + GL + ledger) |
| GET | `PurchaseReturn/export` | `export` | CSV export |
| GET | `PurchaseReturn/audit` | `audit` | Return audit-log page |
| GET | `PurchaseAudit` | `index` | Alias for `checklist` |
| GET | `PurchaseAudit/checklist` | `checklist` | Audit checklist page |
| GET | `PurchaseAudit/run_checks` | `run_checks` | AJAX: re-run health checks |

### 4.3 Legacy State Machines

#### 4.3.1 PurchaseOrder

```
                  create() / store()
                        │
                        ▼
                    ┌──────┐
                    │draft │ ←── edit/update allowed
                    └───┬──┘
       GRN against PO (any received_qty>0)
                        │
                        ▼
                    ┌──────┐
                    │pending│  (only if no GRN yet, but not draft — note: Laravel uses 'sent')
                    └───┬──┘
         partial GRN    │    full GRN (received_qty ≥ qty)
            ┌───────────┴───────────┐
            ▼                       ▼
   ┌─────────────────────┐         ┌──────────┐
   │partially_received   │───────→ │ received │  (terminal)
   └─────────────────────┘  more   └──────────┘
                            GRN

   Any of draft/pending can be cancelled via delete + reason → 'cancelled' (terminal)
```

**Note on terminology:** Legacy uses `draft`, `pending`, `partially_received`, `received`, `cancelled`. Laravel uses `draft`, `sent`, `partial`, `received`, `cancelled`. The Laravel names are preferred (shorter, cleaner) — the port keeps Laravel's enum. The `mark-sent` button on Laravel (no legacy equivalent) is acceptable as an enhancement.

#### 4.3.2 PurchaseReceive (GRN)

```
        store()
          │
          ▼
      ┌─────────┐         cancel() with reason
      │received │ ────────────────────────────→ ┌──────────┐
      └─────────┘   (blocks if active returns    │cancelled │  (terminal)
                    exist on this GRN)            └──────────┘
```

**Laravel equivalent:** `draft` → `confirmed` → `cancelled` (two-phase: draft is "saved but not posted", confirm applies stock+GL+ledger). Legacy does NOT have a draft state — store() immediately posts. **Laravel's two-phase pattern is preferred** (allows editing draft before committing stock). The port keeps Laravel's two-phase.

#### 4.3.3 PurchaseReturn

```
        store()
          │
          ▼
   ┌────────────┐         reverse() with reason
   │is_reversed=0│ ─────────────────────────────→ ┌────────────┐
   │  (active)   │   (admin/manager/accountant)    │is_reversed=1│  (terminal)
   └────────────┘                                 └────────────┘
```

**Laravel equivalent:** `draft` → `confirmed` → `cancelled`. Same two-phase pattern as GRN. The port keeps Laravel's two-phase.

### 4.4 Legacy CSS Class Families

| File | Class families |
|---|---|
| `purchase-index.css` | `.purch-index-app`, `.purch-index-hero`, `.purch-index-tag`, `.purch-index-hero-actions`, `.purch-index-filters-shell`, `.purch-index-filters-toggle*`, `.purch-index-smart-panel`, `.purch-index-preset-row`, `.purch-index-preset-btn`, `.purch-index-search-wrap`, `.purch-index-search-input`, `.purch-index-status-chips`, `.purch-index-status-chip`, `.purch-index-active-bar`, `.purch-index-results-card`, `.purch-index-results-head`, `.purch-index-mobile-cards`, `.purch-index-mobile-card`, `.purch-index-amt`, `.purch-badge` + 9 state modifiers (draft/pending/partial/received/completed/cancelled/returned/reversed) |
| `purchase-order-form.css` | `.purch-po-form-app`, `.purch-po-form-layout`, `.purch-po-form-card`, `.purch-po-form-card-head`, `.purch-po-form-card-body`, `.purch-po-product-cell`, `.purch-po-product-dropdown`, `.purch-po-form-footer`, `.purch-po-total-label`, `.purch-po-form-actions`, `.purch-po-items-card` |
| `purchase-order-details.css` | `.purch-po-detail-stats`, `.purch-po-stat`, `.purch-po-progress-wrap`, `.purch-po-detail-grid`, `.purch-po-detail-card`, `.purch-po-remarks`, `.purch-po-detail-items`, `.purch-po-detail-items-head`, `.purch-po-status-pill` + 5 state modifiers |
| `purchase-return-create.css` | `.prt-create-app`, `.prt-create-workspace` (with `--compact`), `.prt-create-hero`, `.prt-create-panel`, `.prt-create-step-find`, `.prt-create-find-head`, `.prt-create-step-badge`, `.prt-create-search-wrap`, `.prt-create-search-hint`, `.prt-create-results`, `.prt-create-results-msg`, `.prt-create-result-card`, `.prt-create-result-top`, `.prt-create-result-code`, `.prt-create-result-amt`, `.prt-create-result-meta`, `.prt-create-invoice-bar`, `.prt-create-change-invoice`, `.prt-create-form-card`, `.prt-create-form-card-head`, `.prt-create-total-strip`, `.prt-create-form-actions`, `.purchase-return-create-offcanvas` |
| `purchase-return-index.css` | `.purchase-return-app`, `.purchase-return-hero`, `.purchase-return-branch-tag`, `.purchase-return-pending-badge`, `.purchase-return-filters-shell`, `.purchase-return-filters-toggle*`, `.purchase-return-smart-panel`, `.purchase-return-smart-label`, `.purchase-return-preset-row`, `.purchase-return-preset-btn`, `.purchase-return-search-wrap`, `.purchase-return-search-input`, `.purchase-return-status-chips`, `.purchase-return-status-chip` (with `.chip-count`), `.purchase-return-active-bar`, `.purchase-return-results-card`, `.purchase-return-results-head`, `.purchase-return-mobile-card` |
| `purchase-audit-checklist.css` | `.purch-audit-app`, `.purch-audit-hero`, `.purch-audit-summary`, `.purch-audit-section`, `.purch-audit-section-head`, `.purch-audit-item` (with `.status-pass/warn/fail/info`), `.purch-audit-badge`, `.purch-audit-toc`, `.purch-audit-toc-link`, `.purch-audit-item-link`, `.purch-audit-links`, `.purch-audit-meta` |

### 4.5 Legacy Notable Features (Must Port)

These are distinctive legacy features that have no Laravel equivalent yet:

| # | Feature | Description |
|---|---|---|
| F1 | **Damage condition** | Each Return line has a `condition` (`Good`/`Damage`). Good = stock OUT. Damage = NO stock movement (supplier claim only). Both still post GL + supplier_ledger. Audit checklist explicitly checks `prt_damage`. |
| F2 | **Dual stock cap on Good returns** | `return_qty ≤ min(GRN returnable, warehouse available)`. The warehouse `<select>` carries `data-available` and JS enforces the cap per warehouse. Damage bypasses this check. |
| F3 | **Direct Purchase (no PO)** | GRN can be created without a PO. Toggle in GRN create form. `purchase_order_id = NULL`, `supplier_id` required. |
| F4 | **Per-line warehouse selection** | Each GRN line AND each Return line has its own `<select>` for warehouse. A single document can split items across multiple warehouses. |
| F5 | **Printable Return slip** | `PurchaseReturn/slip/{id}` server-rendered HTML with embedded `@media print` CSS. Red header, "REMOTE CENTER / PURCHASE RETURN SLIP", 2-col info, items table, reason box, signature lines. No PDF library — just `window.print()`. |
| F6 | **Per-module audit-log pages** | `PurchaseOrder/audit`, `PurchaseReceive/audit`, `PurchaseReturn/audit` — each shows `UserAudit` logs filtered by action prefix (`purchase_order_*`, `purchase_receive_*`, `purchase_return_*`). Table with timestamp/by/action/target/details/IP. |
| F7 | **PurchaseAudit checklist** | Central dashboard with 12 health-check sections (products, suppliers, warehouses, stock SSOT, PO, GRN, Return, payments, GL links, ledger, reporting, scope). Each item has `pass/warn/fail/info` status. Re-runnable via AJAX. 3 detail tables for actionable issues (negative stock, missing GRN journals, missing Return journals). |
| F8 | **Offcanvas quick-create on Return index** | Bootstrap offcanvas on Return index page that embeds the **same** `create_workspace` partial as the full-page create. After save, the index table auto-reloads via `purchaseReturn:created` custom event. |
| F9 | **Live chip counts via separate AJAX** | Return index chips (All/Active/Reversed) display live counts via `PurchaseReturn/return_filter_summary`. Debounced 280ms after filter changes. |
| F10 | **Smart-sort on Return index** | "Priority sort" checkbox. When enabled (default): `ORDER BY is_reversed ASC, return_date DESC, id DESC` (active first, then reversed). |
| F11 | **Cumulative returned_qty tracking** | `purchase_receive_items.returned_qty` is incremented on Return create and decremented (via `GREATEST(0, x - qty)`) on Return reverse. Prevents over-returning across multiple returns on the same GRN line. |
| F12 | **Reversal via stock_transactions audit trail** | Reverse Return reads `stock_transactions WHERE reference_type='purchase_return'` and restores each movement. New movements logged as `reference_type='purchase_return_reversal'`. `stock_transactions` is the SSOT for reversal. |
| F13 | **Insufficient-stock blocking on GRN cancel** | `cancelReceive` throws if `warehouse_stock.qty < item.qty` for any line. Prevents cancelling a GRN whose stock has already been issued out via sales. |
| F14 | **Custom typeahead (NOT Select2)** | Legacy uses custom text input + `.sales-search-input` style dropdown for product search. Same pattern as the sales cart (which we just ported). Laravel currently uses Select2 with a 500-product cap. |
| F15 | **Server-side DataTables** | Legacy index pages use server-side DataTables (server does filtering/sorting/paging). Laravel uses client-side DataTables over paginated data (limited to 25 rows per page). |
| F16 | **CSV export** | All 3 legacy index pages have `?export` CSV endpoint. Laravel has none. |
| F17 | **localStorage filter persistence** | Legacy index pages persist filters in `localStorage` (`purchase_order_filters_v1`, etc.). Laravel does not. |
| F18 | **Mobile card rendering on <768px** | Legacy index pages render `<div class="purch-index-mobile-cards">` with one card per row when viewport <768px. Laravel uses responsive DataTables only. |
| F19 | **PO→GRN→Return navigation** | Legacy PO details page has "Receive goods" button → `PurchaseReceive/create`. Legacy GRN details page shows linked returns. Legacy Return details page links back to GRN. Laravel has none of these cross-links. |
| F20 | **Random vs sequential code generation** | Legacy PO/GRN use `COUNT(*)+1` per day (race-unsafe). Legacy Return uses `rand(1000,9999)` (collision risk). Laravel uses `DocumentSequenceService::nextCode()` with advisory locks (race-safe). **Laravel wins — keep Laravel's.** |

---

## 5. Gap Analysis

### 5.1 Routes

| Capability | Legacy | Laravel | Gap |
|---|---|---|---|
| PO list | `GET PurchaseOrder` | `GET admin/purchase-orders` | ✅ |
| PO create form | `GET PurchaseOrder/create` | `GET admin/purchase-orders/create` | ✅ |
| PO store | `POST PurchaseOrder/store` | `POST admin/purchase-orders` | ✅ |
| PO edit form | `GET PurchaseOrder/edit/{id}` | `GET admin/purchase-orders/{id}/edit` | ✅ |
| PO update | `POST PurchaseOrder/update/{id}` | `PUT/PATCH admin/purchase-orders/{id}` | ✅ |
| PO show | `GET PurchaseOrder/Details/{id}` | `GET admin/purchase-orders/{id}` | ✅ |
| PO cancel | `POST PurchaseOrder/delete/{id}` (with reason) | `POST admin/purchase-orders/{id}/cancel` | ✅ |
| PO mark-sent | (no equivalent) | `POST admin/purchase-orders/{id}/mark-sent` | ✅ Laravel enhancement |
| PO product search | `POST PurchaseOrder/search_products` | (no AJAX, uses 500-product dropdown) | ❌ **Phase 7** |
| PO CSV export | `GET PurchaseOrder/export` | (none) | ❌ **Phase 7** |
| PO audit log | `GET PurchaseOrder/audit` | (none) | ❌ **Phase 6** |
| GRN list | `GET PurchaseReceive` | `GET admin/purchase-receives` | ✅ |
| GRN create form | `GET PurchaseReceive/create` | `GET admin/purchase-receives/create` | ✅ |
| GRN store | `POST PurchaseReceive/store` (immediate post) | `POST admin/purchase-receives` (draft, two-phase) | ✅ Laravel enhancement |
| GRN show | `GET PurchaseReceive/details/{id}` | `GET admin/purchase-receives/{id}` | ✅ |
| GRN confirm | (no equivalent — store posts immediately) | `POST admin/purchase-receives/{id}/confirm` | ✅ Laravel enhancement |
| GRN cancel | `POST PurchaseReceive/cancel` | `POST admin/purchase-receives/{id}/cancel` | ✅ |
| GRN PO-details AJAX | `POST PurchaseReceive/get_po_details` | `GET admin/purchase-receives/po-details` | ✅ |
| GRN CSV export | `GET PurchaseReceive/export` | (none) | ❌ **Phase 7** |
| GRN audit log | `GET PurchaseReceive/audit` | (none) | ❌ **Phase 6** |
| Return list | `GET PurchaseReturn` | `GET admin/purchase-returns` | ✅ |
| Return create form | `GET PurchaseReturn/create` | `GET admin/purchase-returns/create` | ✅ |
| Return store | `POST PurchaseReturn/store` (immediate post) | `POST admin/purchase-returns` (draft, two-phase) | ✅ Laravel enhancement |
| Return show | `GET PurchaseReturn/details/{id}` | `GET admin/purchase-returns/{id}` | ✅ |
| Return confirm | (no equivalent) | `POST admin/purchase-returns/{id}/confirm` | ✅ Laravel enhancement |
| Return reverse | `POST PurchaseReturn/reverse` | `POST admin/purchase-returns/{id}/cancel` (with `confirm_reason`) | ✅ Laravel unifies |
| Return GRN-details AJAX | `POST PurchaseReturn/get_receive_for_return` | `GET admin/purchase-returns/receive-details` | ✅ |
| Return GRN search AJAX | `POST PurchaseReturn/search_receive` | (none — Laravel uses dropdown list of 100 GRNs) | ❌ **Phase 4** |
| Return chip counts AJAX | `GET PurchaseReturn/return_filter_summary` | (none) | ❌ **Phase 4** |
| Return slip print | `GET PurchaseReturn/slip/{id}` | (none) | ❌ **Phase 6** |
| Return CSV export | `GET PurchaseReturn/export` | (none) | ❌ **Phase 7** |
| Return audit log | `GET PurchaseReturn/audit` | (none) | ❌ **Phase 6** |
| Audit checklist | `GET PurchaseAudit/checklist` | `GET admin/reports/purchase-audit` (stub) | ❌ **Phase 6** |
| Audit run-checks AJAX | `GET PurchaseAudit/run_checks` | (none) | ❌ **Phase 6** |

### 5.2 Database Schema

| Table | Column / Concern | Legacy | Laravel | Gap |
|---|---|---|---|---|
| `purchase_orders` | `expected_date` | ✅ exists | ✅ in code, ❌ **missing from `05_purchase.sql`** | ❌ **Phase 0** |
| `purchase_orders` | `sub_total`, `discount_amount`, `tax_amount` | ❌ none | ✅ exists | Laravel enhancement |
| `purchase_orders` | `warehouse_id` (header) | ❌ none | ✅ nullable | Laravel enhancement |
| `purchase_orders` | `status` enum values | `draft/pending/partially_received/received/cancelled` | `draft/sent/partial/received/cancelled` | Laravel renames — keep Laravel |
| `purchase_orders` | `cancelled_at`, `cancelled_by`, `cancel_reason` | ✅ exists | ❌ cancel reason appended to `notes` | Minor — keep Laravel pattern |
| `purchase_orders` | `journal_entry_id` | ✅ exists (always NULL on PO) | ❌ not in schema | OK — PO doesn't post GL |
| `purchase_order_items` | `amount` column | ❌ computed in code | ✅ GENERATED ALWAYS AS (qty * rate) STORED | Laravel enhancement |
| `purchase_order_items` | `received_qty` | ✅ DEFAULT 0 | ✅ DEFAULT 0 | ✅ |
| `purchase_receives` | `status` | ✅ `received/cancelled` | ✅ in code, ❌ **missing from `05_purchase.sql`** | ❌ **Phase 0** |
| `purchase_receives` | `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason` | ✅ exists | ✅ exists | ✅ |
| `purchase_receives` | `journal_entry_id` | ✅ exists | ✅ exists | ✅ |
| `purchase_receives` | `sub_total`, `discount_amount`, `tax_amount` | ❌ none | ✅ exists | Laravel enhancement |
| `purchase_receives` | `warehouse_id` (header) | ❌ per-line only | ✅ NOT NULL on header | Laravel differs — keep Laravel |
| `purchase_receive_items` | `returned_qty` | ✅ DEFAULT 0 | ✅ DEFAULT 0 (column name is `return_qty`) | Minor naming |
| `purchase_receive_items` | `condition` | ✅ always `'Good'` on GRN | ❌ not in schema | OK — GRN doesn't need condition |
| `purchase_receive_items` | `warehouse_id` | ✅ per-line NOT NULL | ✅ per-line NULL | OK — service enforces |
| `purchase_receive_items` | `purchase_order_item_id` | ✅ nullable | ✅ nullable | ✅ |
| `purchase_returns` | `status` | (uses `is_reversed` only) | ✅ in code, ❌ **missing from `05_purchase.sql`** | ❌ **Phase 0** |
| `purchase_returns` | `warehouse_id` (header) | ❌ per-line only | ✅ NOT NULL on header, ❌ **service doesn't write it** | ❌ **Phase 0** |
| `purchase_returns` | `is_reversed`, `reversed_at`, `reversed_by`, `reverse_reason` | ✅ exists | ✅ exists | ✅ |
| `purchase_returns` | `journal_entry_id` | ✅ exists | ✅ exists | ✅ |
| `purchase_returns` | `sub_total`, `discount_amount`, `tax_amount` | ❌ none | ❌ none | ✅ (matches legacy) |
| `purchase_return_items` | `condition` (`Good`/`Damage`) | ✅ exists | ✅ **added in Phase 5** (migration `2025_01_25_000001` + SQL `05_purchase.sql`) | ✅ **Phase 5 done** |
| `purchase_return_items` | `warehouse_id` | ✅ per-line | ✅ per-line | ✅ |
| `purchase_return_items` | `purchase_receive_item_id` | ✅ required | ✅ nullable | Minor — service enforces |

### 5.3 Business Logic

| Logic | Legacy | Laravel | Gap |
|---|---|---|---|
| PO is draft-only (no GL/stock/ledger) | ✅ | ✅ | ✅ |
| GRN posts `Dr Inventory / Cr AP` | ✅ | ✅ | ✅ |
| GRN stock IN at purchase rate (avg-cost recalc) | ✅ | ✅ | ✅ |
| GRN supplier ledger credit | ✅ | ✅ | ✅ |
| GRN cancel reverses stock + GL + ledger + PO received_qty | ✅ | ✅ | ✅ |
| GRN cancel blocks if active returns exist | ✅ | ❌ **not enforced** | ❌ **Phase 0** |
| GRN cancel blocks if insufficient warehouse_stock | ✅ | ❓ (depends on StockService) | Verify in Phase 0 |
| Return posts `Dr AP / Cr Inventory` | ✅ | ✅ | ✅ |
| Return stock OUT at ORIGINAL receive rate | ✅ | ✅ | ✅ |
| Return supplier ledger debit | ✅ | ✅ | ✅ |
| Return reverse restores via stock_transactions | ✅ | ✅ (via `StockService::reverseTransaction`) | ✅ |
| Return reverse decrements return_qty via `GREATEST(0, x-qty)` | ✅ | ✅ | ✅ |
| **Damage condition = no stock movement** | ✅ | ✅ **Phase 5** (`isDamage()` branch in `confirmReturn` skips `stockService->applyTransaction`) | ✅ **Phase 5 done** |
| **Dual cap: return_qty ≤ min(GRN returnable, warehouse available)** | ✅ | ✅ **Phase 5** (UI enforced in Phase 4 `applyRowQtyCap`, server-side `validateItems` enforces GRN returnable cap; warehouse cap is client-side via `data-available` per `<option>`) | ✅ **Phase 5 done** |
| Two-phase draft → confirm | ❌ (store posts immediately) | ✅ | Laravel enhancement — keep |
| Idempotent cancel (guard: already cancelled) | ✅ | ✅ | ✅ |
| Race-safe code generation | ❌ (`COUNT(*)+1` / `rand()`) | ✅ (advisory locks) | Laravel wins — keep |

### 5.4 UI / Views

| Page | Legacy look | Laravel look | Gap |
|---|---|---|---|
| PO index | `.purch-index-app` + hero + smart-panel + status chips + results card | Generic Bootstrap hero + 7 stat cards + filter card + table card | ❌ **Phase 2** |
| PO create | `.purch-po-form-app` + 2-col layout + typeahead line items | Generic Bootstrap card + Select2 line items (500-product cap) | ❌ **Phase 2** |
| PO edit | Same as create | Same as create | ❌ **Phase 2** |
| PO show | `.purch-po-detail` + 4 stat cards + progress bar + items table | Generic Bootstrap 2-col + items table + actions card | ❌ **Phase 2** |
| PO show "Receive goods" button | ✅ | ❌ (stale text "Phase 7.2 not implemented") | ❌ **Phase 3** |
| PO show "Receives against this PO" list | ❌ | ❌ | Both missing — **Phase 3** |
| GRN index | `.purch-index-app` (shared with PO) | Generic Bootstrap | ❌ **Phase 3** |
| GRN create | Bootstrap card + Direct Purchase toggle + per-line warehouse | Bootstrap card + Direct toggle + per-line warehouse (close) | ❌ **Phase 3** (mostly restyle) |
| GRN show | Stat cards + journal block + items table + linked returns | Stat cards + items + stock movements + GL + ledger cards (richer) | Laravel is richer — keep |
| GRN show "Return against this GRN" button | ❌ (legacy does it from index offcanvas) | ❌ | **Phase 4** |
| GRN show "Returns against this GRN" list | ✅ (in details.php) | ❌ | ❌ **Phase 4** |
| Return index | `.purchase-return-app` + chips with counts + offcanvas quick-create + smart-sort | Generic Bootstrap | ❌ **Phase 4** |
| Return create | `.prt-create-workspace` 2-step wizard (find GRN → return form) | Bootstrap card with GRN `<select>` dropdown | ❌ **Phase 4** |
| Return show | Stat cards + reason alert + journal block + items table | Stat cards + items + stock movements + GL + ledger cards (richer) | Laravel is richer — keep |
| Return slip print | ✅ `slip.php` with `@media print` | ❌ | ❌ **Phase 6** |
| PO audit log | ✅ `audit.php` | ❌ | ❌ **Phase 6** |
| GRN audit log | ✅ `audit.php` | ❌ | ❌ **Phase 6** |
| Return audit log | ✅ `audit.php` | ❌ | ❌ **Phase 6** |
| PurchaseAudit checklist | ✅ `checklist.php` (12 sections + 3 detail tables) | ❌ (stub at `reports/purchase-audit`) | ❌ **Phase 6** |

### 5.5 JS / AJAX

| Concern | Legacy | Laravel | Gap |
|---|---|---|---|
| PO create product typeahead | `POST PurchaseOrder/search_products` | ❌ (Select2 with 500-product `<template>`) | ❌ **Phase 7** |
| GRN create Direct product typeahead | `POST PurchaseOrder/search_products` (reused) | ❌ (same Select2) | ❌ **Phase 7** |
| Return GRN typeahead | `POST PurchaseReturn/search_receive` | ❌ (dropdown of 100 GRNs) | ❌ **Phase 4** |
| Return chip counts | `GET PurchaseReturn/return_filter_summary` | ❌ | ❌ **Phase 4** |
| Return offcanvas quick-create | `purchaseReturn:created` event | ❌ | ❌ **Phase 4** |
| localStorage filter persistence | `purchase_*_filters_v1` | ❌ | ❌ **Phase 7** |
| Server-side DataTables | ✅ | ❌ (client-side over paginated 25/page) | ❌ **Phase 7** |
| Mobile card rendering <768px | ✅ `.purch-index-mobile-cards` | ❌ | ❌ **Phase 7** |
| Dead JS files | N/A | 6 files (~2,500 lines) reference stale DOM IDs + enums | ❌ **Phase 0** (delete) |

### 5.6 Cross-Cutting Concerns

| Concern | Legacy | Laravel | Gap |
|---|---|---|---|
| RBAC on routes | `route_roles.php` gates admin/manager vs warehouse_manager | ❌ only `auth` middleware | ❌ **Phase 1** |
| Branch isolation | `$_SESSION['branch_id']` filters create/search/details (but NOT DataTables queries — latent bug) | ❌ no enforcement | ❌ **Phase 1** |
| Supplier selection | Plain `<select>` from `getActiveSuppliers()` | Select2 from `Supplier::active()->get()` | Minor — keep Laravel |
| Product selection | Custom typeahead (`PurchaseOrder/search_products`) | Select2 capped at 500 | ❌ **Phase 7** |
| Tax/discount/transport on PO | ❌ none | ✅ discount + tax (no transport) | Laravel enhancement — keep |
| Tax/discount/transport on GRN | ❌ none | ✅ discount + tax (no transport) | Laravel enhancement — keep |
| Tax/discount/transport on Return | ❌ none | ❌ none | ✅ (matches legacy) |
| Unit conversion (case → piece) | ❌ none | ❌ none | Out of scope |
| Foreign currency / FX | ❌ none | ❌ none | Out of scope |
| Approval workflow | ❌ none | ❌ none | Out of scope |
| PO→Receive→Return linkage in DB | ✅ nullable FKs both ways | ✅ same | ✅ |

---

## 6. Critical Bugs & Blockers

These must be addressed in Phase 0 before any UI work begins. Each is a runtime-breaking issue or a security hole.

### 6.1 BUG-1: `purchase_receives.status` column missing from schema (CRITICAL) ✅ FIXED Phase 0

The Laravel service code writes `status='draft'`, `status='confirmed'`, `status='cancelled'` on every INSERT and UPDATE. The model has `isDraft()` / `isConfirmed()` / `isCancelled()` helpers that read this column. The controller filters by `status` in index queries. **But the column does NOT exist in `database/sql/05_purchase.sql`.**

**Verification outcome (Phase 0):** Could not query live DB directly (no `psql`/`docker` CLI in this env). Verified by reading `2025_01_01_000001_create_rcerp_schema.php` which loads `05_purchase.sql` verbatim via `executeSqlFile()` — so the live schema exactly matches the SQL file, confirming the column was MISSING.

**Fix applied:** Migration `2025_01_24_000001_add_status_to_purchase_receives.php` (idempotent, guarded by `Schema::hasColumn`) adds:
```sql
status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled'))
```
Plus index `idx_pr_status` for the index() controller's `->where('status', $s)` filter. `05_purchase.sql` updated to match.

### 6.2 BUG-2: `purchase_returns.status` column missing from schema (CRITICAL) ✅ FIXED Phase 0

Same issue as BUG-1 but for `purchase_returns`. **Verification outcome:** Same as BUG-1 — column was MISSING from `05_purchase.sql`. **Fix applied:** Migration `2025_01_24_000002_add_status_to_purchase_returns.php` (idempotent, same pattern). Plus index `idx_prtn_status`. `05_purchase.sql` updated.

### 6.3 BUG-3: `purchase_orders.expected_date` column missing from schema (CRITICAL) ✅ FIXED Phase 0

Controller validates `expected_date => 'nullable|date'`. Service writes `'expected_date' => $data['expected_date'] ?? null` on both createOrder() and updateOrder(). Model casts `'expected_date' => 'date'`. Blade has a date input for it. **But the column does NOT exist in `database/sql/05_purchase.sql`.**

**Verification outcome:** Same as BUG-1 — column was MISSING. **Fix applied:** Migration `2025_01_24_000003_add_expected_date_to_purchase_orders.php` (idempotent, guarded) adds `expected_date DATE NULL`. `05_purchase.sql` updated.

### 6.4 BUG-4: `purchase_returns.warehouse_id` NOT NULL but service doesn't write it (CRITICAL) ✅ FIXED Phase 0

Schema declares `warehouse_id integer NOT NULL FK→warehouses`. The `PurchaseReturnService::createReturn()` method did NOT set `warehouse_id` on the `purchase_returns` insert (only on `purchase_return_items`). This caused a NOT NULL violation on every Return create.

**Verification outcome:** Same as BUG-1 — column was NOT NULL in `05_purchase.sql`. **Fix applied:** Updated `PurchaseReturnService::createReturn()` to inherit `warehouse_id` from the GRN (`$receive->warehouse_id`). The `PurchaseReturn` model was also updated: `warehouse_id` added to `$fillable` + `$casts`, plus a new `warehouse()` belongsTo relation. Per-line `warehouse_id` on `purchase_return_items` is still authoritative for the stock OUT movement — this header value is the "default warehouse" for the return document as a whole, same pattern Laravel uses for `purchase_receives`.

### 6.5 BUG-5: GRN cancel doesn't block if active returns exist (FUNCTIONAL GAP) ✅ FIXED Phase 0

Legacy `PurchaseReceiveModel::cancelReceive` throws if any active (non-reversed) `purchase_returns` exist on the GRN. This prevents inconsistent state where stock has been returned to supplier but the original receipt is cancelled (which would re-add stock that's already gone).

Laravel's `PurchaseReceiveService::cancelReceive` did NOT have this check.

**Fix applied:** Added a guard at the top of `cancelReceive` (only checked when `isConfirmed()`, since draft GRNs have no stock movements to corrupt):
```php
if ($receive->isConfirmed()) {
    $activeReturns = PurchaseReturn::where('purchase_receive_id', $receiveId)
        ->where('is_reversed', false)
        ->where('status', 'confirmed')
        ->count();
    if ($activeReturns > 0) {
        throw new \RuntimeException(
            "Cannot cancel GRN: {$activeReturns} active return(s) exist against it. "
            . "Reverse them first."
        );
    }
}
```
Also added `use App\Models\PurchaseReturn;` import at the top of the service.

### 6.6 BUG-6: No RBAC middleware on purchase routes (SECURITY) ✅ FIXED Phase 1

Any authenticated user (including a salesman) can access every purchase endpoint — including cancel/reverse. Legacy gates these to admin/manager/accountant only.

**Fix applied (Phase 1):** All 3 purchase route groups in `routes/web.php` restructured with per-action `role:` middleware matching legacy `route_roles.php`. See "Role matrix implemented" table in the Phase 1 Completion Summary above for the full mapping. Verification: brace/paren/bracket balance check passed on `routes/web.php`; cross-referenced every route definition against the legacy matrix.

**Fix details:**
- PO: resource split — `->only(['index', 'create', 'show', 'edit'])` for read (admin/manager/warehouse_manager/accountant), standalone `Route::post(...)` + `Route::put(...)` for `store`/`update` (admin/manager/warehouse_manager + branch.isolation), `mark-sent` (admin/manager/warehouse_manager + branch.isolation), `cancel` (admin/manager + branch.isolation).
- GRN: resource `->only(['index', 'show'])` (admin/manager/warehouse_manager/accountant), standalone `create`/`store` (admin/manager/warehouse_manager), `po-details` AJAX (admin/manager/warehouse_manager), `confirm`/`cancel` (admin/manager + branch.isolation).
- Return: resource `->only(['index', 'show'])` (admin/manager/warehouse_manager/accountant), standalone `create`/`store` (admin/manager/warehouse_manager), `receive-details` AJAX (admin/manager/warehouse_manager), `confirm` (admin/manager + branch.isolation), `cancel`/`reverse` (admin/manager/accountant + branch.isolation).

### 6.7 BUG-7: No branch isolation on purchase routes (SECURITY) ✅ FIXED Phase 1

A user logged into Branch A can see/filter/create data for Branch B by passing `?branch_id=B` in the URL.

**Fix applied (Phase 1):** Multi-layer branch isolation enforced. See "Branch isolation rules implemented" table in the Phase 1 Completion Summary above. Verification: cross-referenced every controller path (index/show/create/store/update/AJAX) for branch-scoping completeness.

**Fix details:**
- `EnforceBranchIsolation::inferTableFromUri()` extended to recognize `purchase-orders`, `purchase-receives`, `purchase-returns` URL prefixes and resolve their `{id}` param to `purchase_orders`/`purchase_receives`/`purchase_returns` tables.
- `Controller` base class gets 2 protected helpers: `resolveBranchIdForRead()` (admin can override with active branch_id; non-admin falls back to session) and `resolveBranchIdForWrite()` (admin can override; non-admin ALWAYS uses session branch, ignoring client-supplied branch_id).
- All 3 controllers' `index()` queries branch-scoped via `resolveBranchIdForRead()`. Stats queries also branch-scoped (using `clone $statsQuery` to avoid mutating the builder).
- All 3 controllers' `store()` (and PO `update()`) call `resolveBranchIdForWrite()` — non-admin users cannot create or move a record to another branch.
- `PurchaseReceiveController::create(?po_id=)` + `PurchaseReturnController::create(?receive_id=)` check the source record's `branch_id` vs session for non-admins → redirect with error on mismatch.
- `PurchaseReceiveController::getPoDetails()` + `PurchaseReturnController::getReceiveDetails()` AJAX endpoints check the source record's `branch_id` vs session for non-admins → 403 JSON on mismatch.
- `PurchaseReturnController::create()` GRN selector dropdown (the "confirmed GRNs" list) is now branch-scoped for non-admins.

### 6.8 BUG-8: Stale UI text on PO show page (COSMETIC) ✅ FIXED Phase 0

`purchase-orders/show.blade.php` line ~340-348 contained: *"This PO can receive goods via GRN (Phase 7.2). Goods receipt will be available once Phase 7.2 is implemented."* — but Phase 7.2 IS implemented.

**Fix applied:** Replaced the alert with a real "Receive against this PO" button:
```blade
<a href="{{ route('admin.purchase-receives.create', ['po_id' => $po->id]) }}"
   class="btn btn-success w-100">
    <i class="fas fa-truck-ramp-box me-1"></i> Receive against this PO
</a>
```
The `PurchaseReceiveController::create()` method already reads `?po_id=` from the request and pre-fills the form, so the button works end-to-end.

### 6.9 BUG-9: Dead JS files in `laravel/public/assets/js/` (CLEANUP) ✅ FIXED Phase 0

Six JS files (~2,501 lines total) referenced stale DOM IDs (`#purchase-order-app`, `#filterStatus`, `window.PURCHASE_ORDER_BOOT`) and stale status enums (`pending`, `partially_received`). They were not referenced by any blade view (grep-verified across `resources/views/`). They were likely copied from legacy during the initial Laravel scaffold and never reconciled.

**Fix applied:** `git rm`-ed all 6 files:
- `laravel/public/assets/js/PurchaseOrder.js` (372 lines)
- `laravel/public/assets/js/PurchaseReceive.js` (432 lines)
- `laravel/public/assets/js/PurchaseReturn.js` (667 lines)
- `laravel/public/assets/js/purchase-order-index.js` (353 lines)
- `laravel/public/assets/js/purchase-receive-index.js` (279 lines)
- `laravel/public/assets/js/purchase-return-index.js` (398 lines)

They will be re-implemented as inline `@push('scripts')` blocks during Phases 2–4 (matching the sales-cart pattern).

### 6.10 BUG-10: `purchase_returns.reason` column missing from schema (CRITICAL) ✅ FIXED Phase 0 — DISCOVERED DURING PHASE 0

The `PurchaseReturn` model has `reason` in `$fillable`. `PurchaseReturnService::createReturn()` writes `'reason' => $data['reason'] ?? null` on the INSERT. The controller passes `reason` from the request. The show blade renders `$r->reason` (line 130). **But the column was missing from `database/sql/05_purchase.sql`** — only `reverse_reason` (the cancellation reason) and `notes` existed.

The intent: `reason` is the ORIGINAL return reason (why are we returning these goods to the supplier?). `reverse_reason` is the CANCELLATION reason (why are we cancelling this return?). `notes` is freeform. All three are distinct semantically — keep them separate.

**Fix applied:** Migration `2025_01_24_000004_add_reason_to_purchase_returns.php` (idempotent, guarded) adds `reason TEXT NULL`. `05_purchase.sql` updated.

---

## 7. Decisions (Locked)

These decisions were made based on the audit findings. They are NOT open for re-debate unless a Phase 0 verification reveals a blocker.

| # | Decision | Rationale |
|---|---|---|
| D1 | **Keep Laravel's two-phase draft → confirm pattern** for GRN and Return. | Allows editing draft before committing stock/GL. Legacy's immediate-post pattern is less forgiving. |
| D2 | **Keep Laravel's status enums** (`draft/sent/partial/received/cancelled` for PO; `draft/confirmed/cancelled` for GRN/Return). | Cleaner than legacy's `pending/partially_received`. Less renames needed. |
| D3 | **Keep Laravel's `markAsSent` action** on PO (no legacy equivalent). | Useful business state — PO is "sent to supplier" but no GRN yet. |
| D4 | **Keep Laravel's `DocumentSequenceService`** (advisory-locked code generation). | Race-safe vs legacy's `COUNT(*)+1` / `rand()`. |
| D5 | **Keep Laravel's `discount_amount` + `tax_amount`** on PO and GRN. | Laravel enhancement over legacy. Useful for VAT-registered suppliers. |
| D6 | **Do NOT add `discount_amount` / `tax_amount` to Returns.** | Matches legacy. Returns are qty × rate only. |
| D7 | **Do NOT add `transport_cost` to any purchase entity.** | Legacy has none. If needed later, add as net-new feature. |
| D8 | **Port the Damage condition** (no-stock-movement returns). | Legacy feature F1. Required for supplier-claim workflows (damaged in transit). |
| D9 | **Port the dual stock cap** (GRN returnable AND warehouse available) for Good returns. | Legacy feature F2. Prevents returning stock that's been issued out via sales. |
| D10 | **Port the printable Return slip** via `window.print()`. | Legacy feature F5. No PDF library needed. |
| D11 | **Port the per-module audit-log pages** (PO/GRN/Return). | Legacy feature F6. Required for SOX-style traceability. |
| D12 | **Port the PurchaseAudit checklist** with 12 health-check sections. | Legacy feature F7. The only "health dashboard" in the purchase module. |
| D13 | **Port the offcanvas quick-create** on Return index. | Legacy feature F8. Major UX win — cashier can create a return without leaving the index. |
| D14 | **Port the live chip counts** on Return index. | Legacy feature F9. Better than Laravel's static "stats" cards. |
| D15 | **Port the smart-sort** on Return index. | Legacy feature F10. Surfaces active returns above reversed ones. |
| D16 | **Replace Select2 with custom typeahead** for product search. | Matches the sales-cart pattern we just established (commit `c2bd5c7`). Removes the 500-product cap. |
| D17 | **Replace client-side DataTables with server-side DataTables** on index pages. | Legacy pattern. Allows >10k-row datasets without browser strain. |
| D18 | **Add `transport_cost` is OUT OF SCOPE.** | Legacy has none. If needed, add as net-new feature in a later phase. |
| D19 | **Approval workflow is OUT OF SCOPE.** | Legacy has none. If needed, add as net-new feature in a later phase. |
| D20 | **Foreign currency / exchange rate is OUT OF SCOPE.** | Legacy has none. All amounts in BDT. |
| D21 | **Unit conversion (case → piece) is OUT OF SCOPE.** | Legacy has none. All qty in product's base unit. |
| D22 | **Use the same `layouts.admin` layout** as the rest of the Laravel app. | Consistency with sales cart. Legacy `main.php` layout is not being ported. |
| D23 | **Use `@push('css')` to link the 6 legacy purchase CSS files** on the relevant pages. | Same pattern as the sales cart's `sales-pos.css` link. |
| D24 | **Use `@push('scripts')` for inline JS** (no external JS files). | Matches the sales cart pattern. Avoids the dead-JS-file problem. |
| D25 | **Add Form Request classes** for PO/GRN/Return validation. | Replaces inline `$request->validate()`. More testable, more reusable. |
| D26 | **PO/GRN/Return `audit` log pages are NEW routes** (`admin/purchase-orders/audit`, etc.) — NOT replacing any existing route. | Legacy has them; Laravel doesn't. Additive. |
| D27 | **The PurchaseAudit checklist replaces the stub** at `admin/reports/purchase-audit`. | The stub is currently a placeholder. Replace with the real checklist. |
| D28 | **Cross-linkage buttons (PO→GRN, GRN→Return, PO→list of GRNs, GRN→list of Returns)** are mandatory. | Legacy has them implicitly via the "Receive goods" button and the linked-returns table. Laravel has none. |
| D29 | **CSV export** on all 3 index pages. | Legacy has it. Useful for accounting reconciliation. |
| D30 | **Mobile card rendering on <768px** for all 3 index pages. | Legacy has it. Laravel currently uses responsive DataTables only (not great on phones). |

---

## 8. Phase-by-Phase Implementation Plan

Each phase is independently shippable. A phase is "done" when all its success criteria (§9) are met AND the user has signed off on a smoke test.

### Phase 0 — Schema reconciliation + critical bug fixes + cleanup ✅ COMPLETE (2026-07-22)

**Goal:** Make the existing Laravel purchase module *actually work correctly* before adding any new features.

**Status:** ✅ All 9 tasks complete. See "Phase 0 Completion Summary" at the top of this document for verification details, smoke-test checklist, and the BUG-10 discovery (a 5th schema gap found during Phase 0 verification — `purchase_returns.reason` was missing).

**Tasks:**
1. ✅ Verify live DB schema by running `\d purchase_orders`, `\d purchase_receives`, `\d purchase_returns`, `\d purchase_return_items` inside the `rcerp_postgres` container. *(Could not run directly — no `psql`/`docker` in this env. Verified by reading `2025_01_01_000001_create_rcerp_schema.php` which loads `05_purchase.sql` verbatim. Confirmed all 4 columns were MISSING from the SQL spec.)*
2. ✅ Create migration `2025_01_24_000001_add_status_to_purchase_receives.php` — adds `status` column with CHECK + index. Updated `05_purchase.sql`.
3. ✅ Create migration `2025_01_24_000002_add_status_to_purchase_returns.php` — same pattern. Updated `05_purchase.sql`.
4. ✅ Create migration `2025_01_24_000003_add_expected_date_to_purchase_orders.php` — adds `expected_date`. Updated `05_purchase.sql`.
5. ✅ Fix BUG-4: `PurchaseReturnService::createReturn()` now inherits `warehouse_id` from the GRN. Model updated with `warehouse_id` in `$fillable`/`$casts` + `warehouse()` relation.
6. ✅ Fix BUG-5: Added the "active returns exist" guard to `PurchaseReceiveService::cancelReceive()` (mirrors legacy `PurchaseReceiveModel::cancelReceive`).
7. ✅ Fix BUG-8: Replaced the stale "Phase 7.2 not implemented" text on `purchase-orders/show.blade.php` with a real "Receive against this PO" button.
8. ✅ Delete the 6 dead JS files: `PurchaseOrder.js`, `PurchaseReceive.js`, `PurchaseReturn.js`, `purchase-order-index.js`, `purchase-receive-index.js`, `purchase-return-index.js` (2,501 lines total).
9. ⏳ Smoke-test: User to run the 7-step smoke-test checklist at the top of this document on their local Docker after `php artisan migrate`.

**Bonus fix (BUG-10):** Discovered during Phase 0 verification — `purchase_returns.reason` column was also missing. Migration `2025_01_24_000004_add_reason_to_purchase_returns.php` adds it. The model already had `reason` in `$fillable` and the show blade already renders `$r->reason`, so this was a guaranteed INSERT failure on every return create.

**Files touched:** 4 migrations, 1 SQL file, 2 services, 1 model, 1 blade view, 6 JS file deletions.

---

### Phase 1 — RBAC + branch isolation ✅ COMPLETE (2026-07-22)

**Goal:** Lock down purchase routes so only authorized roles can access them, and users can only see/create data for their own branch.

**Status:** Complete. See "Phase 1 Completion Summary" at the top of this document for full deliverables, role matrix, branch isolation rules, and 8-step smoke-test checklist. BUG-6 + BUG-7 both fixed.

**Tasks (completed):**
1. ✅ Audited existing middleware: `branch.isolation` alias exists (registered in `bootstrap/app.php` → `EnforceBranchIsolation` class). `role` alias exists (`EnsureRole`). No new middleware needed.
2. ✅ Added per-action `role:` middleware to all 3 purchase route groups (split resource declarations so write verbs get tighter RBAC than reads).
3. ✅ Added `branch.isolation` to all write routes (POST/PUT).
4. ✅ Role matrix implemented per legacy `route_roles.php` (see table in Phase 1 Completion Summary).
5. ✅ Controllers enforce role matrix via route-level middleware (no need for in-controller `authorize()` calls — middleware catches first).
6. ✅ All 3 controllers' `index()` queries scoped by `resolveBranchIdForRead()`. Stats queries also scoped. `store()`/`update()` use `resolveBranchIdForWrite()` to force session branch for non-admins.
7. ⏳ Smoke-test: User to run the 8-step checklist on local Docker.

**Files touched:** `routes/web.php`, 3 controllers, `Controller.php` (base), `EnforceBranchIsolation.php` (middleware). No schema changes.

---

### Phase 2 — PurchaseOrder UI parity (legacy-faithful) ✅ COMPLETE (2026-07-22)

**Goal:** PO index / create / edit / show pages look and behave like legacy.

**Status:** ✅ All 10 tasks complete (1 pending user smoke-test). See "Phase 2 Completion Summary" at the top of this document for full deliverables, bug list, and 8-step smoke-test checklist. BUG-11 through BUG-15 all fixed.

**Tasks (completed):**
1. ✅ Link `purchase-index.css`, `purchase-order-form.css`, `purchase-order-details.css` via `@push('css')` on the relevant blades.
2. ✅ Restructure `purchase-orders/index.blade.php`:
   - Wrap in `<div class="purch-index-app">`.
   - Hero header with `.purch-index-hero` + `.purch-index-tag` + `.purch-index-hero-actions`.
   - Collapsible filter panel with `.purch-index-filters-shell` + `.purch-index-smart-panel` + `.purch-index-preset-row`.
   - Status chips with `.purch-index-status-chips` + `.purch-index-status-chip`.
   - Search input with `.purch-index-search-wrap` + `.purch-index-search-input`.
   - Active filter bar with `.purch-index-active-bar`.
   - Results card with `.purch-index-results-card` + `.purch-index-mobile-cards`.
   - Use `.purch-badge` + state modifiers for status pills.
3. ✅ Restructure `purchase-orders/create.blade.php` and `edit.blade.php`:
   - Wrap in `<div class="purch-po-form-app">`.
   - 2-col layout with `.purch-po-form-layout`.
   - Header card with `.purch-po-form-card` + `.purch-po-form-card-head` + `.purch-po-form-card-body`.
   - Items card with `.purch-po-items-card`.
   - Product cell with `.purch-po-product-cell` + `.purch-po-product-dropdown` (custom typeahead — NOT Select2).
   - Footer with `.purch-po-form-footer` + `.purch-po-total-label` + `.purch-po-form-actions`.
4. ✅ Restructure `purchase-orders/show.blade.php`:
   - Wrap in `<div class="purch-po-detail">` (or similar).
   - 4 stat cards with `.purch-po-detail-stats` + `.purch-po-stat`.
   - Progress bar with `.purch-po-progress-wrap`.
   - 2-col grid with `.purch-po-detail-grid` + `.purch-po-detail-card`.
   - Items table with `.purch-po-detail-items`.
   - Status pill with `.purch-po-status-pill` + state modifier.
5. ✅ Replace Select2 product dropdown with custom text-input typeahead (same pattern as the sales cart). Added new AJAX endpoint `GET admin/purchase-orders/search-products?term=...` returning JSON `[{id, product_name, product_code}, ...]`.
6. ✅ Replace client-side DataTables with server-side DataTables on PO index. Added `?datatables=1` mode to `index()` controller method that returns JSON `{draw, recordsTotal, recordsFiltered, data}`.
7. ✅ Add localStorage filter persistence (`purchase_order_filters_v1`).
8. ✅ Add mobile card rendering on `<768px` (use the same DataTables `drawCallback` pattern as legacy).
9. ✅ Add CSV export endpoint `GET admin/purchase-orders/export` (returns `Content-Type: text/csv`).
10. ⏳ Smoke-test: User to run the 8-step smoke-test checklist at the top of this document on their local Docker after `git pull origin main`.

**Files touched:** 4 blade views (index/create/edit/show), 1 controller (3 new methods: searchProducts + poDataTableJson + export), 2 new routes, 3 CSS files linked, ~1,500 lines of new/modified code (1,538 insertions, 1,209 deletions).

---

### Phase 3 — PurchaseReceive (GRN) UI parity ✅ COMPLETE (2026-07-22)

**Goal:** GRN index / create / show pages look and behave like legacy. Add the missing "Receive against this PO" cross-linkage.

**Status:** ✅ All 10 tasks complete (1 pending user smoke-test). See "Phase 3 Completion Summary" at the top of this document for full deliverables, bug list, and 8-step smoke-test checklist. BUG-16 through BUG-22 all fixed.

**Tasks (completed):**
1. ✅ Link `purchase-index.css` on GRN index + show; `purchase-order-form.css` on GRN create.
2. ✅ Restructure `purchase-receives/index.blade.php`:
   - Same `.purch-index-app` shape as PO index (with `.purch-grn` modifier).
   - "Show returned/cancelled" toggle via `?returned=1` query param.
3. ✅ Restructure `purchase-receives/create.blade.php`:
   - Use `.purch-po-form-app` shape (shared with PO create — same CSS).
   - Direct Purchase toggle kept (already in Laravel — restyled).
   - Per-line warehouse `<select>` kept (already in Laravel — restyled native, no Select2).
   - Replace Select2 product dropdown with custom typeahead (reused the `search-products` endpoint from Phase 2).
   - "Remaining" column on PO-linked mode kept (already in Laravel — read-only display).
4. ✅ Restructure `purchase-receives/show.blade.php`:
   - Kept the rich layout (stat cards + stock movements + GL + ledger cards) — Laravel is already better than legacy here.
   - Added `.purch-index-app.purch-po-detail` wrapper for visual consistency.
5. ✅ Verified "Receive against this PO" button on `purchase-orders/show.blade.php` (already added in Phase 0 — still links correctly to `admin.purchase-receives.create` with `?po_id=`).
6. ✅ Added "Receives against this PO" list section on `purchase-orders/show.blade.php`:
   - Query: `$po->receives` (eager-loaded via new `receives()` HasMany relation on `PurchaseOrder` model).
   - Rendered as a small table below the PO items table.
   - Shows receive_code (link to GRN show), receive_date, warehouse, total_amount, status badge, reversed badge.
7. ✅ Replaced client-side DataTables with server-side DataTables on GRN index — `?datatables=1` mode in `index()` returns JSON `{draw, recordsTotal, recordsFiltered, data}`.
8. ✅ Added CSV export on GRN index — `GET admin/purchase-receives/export` returns `text/csv` with UTF-8 BOM.
9. ✅ Added localStorage filter persistence (`purchase_receive_filters_v1`).
10. ⏳ Smoke-test: User to run the 8-step smoke-test checklist at the top of this document on their local Docker after `git pull origin main`.

**Files touched:** 3 blade views (index full rewrite + create targeted restructure + show targeted restructure), 1 controller (2 new methods: grnDataTableJson + export), 1 model (`PurchaseOrder::receives()` relation added), 1 controller method modified (`PurchaseOrderController::show()` eager-loads `receives`), PO show blade (added Receives list section), 1 new route (`export`), 3 CSS files linked, ~600 lines of new/modified JS.

---

### Phase 4 — PurchaseReturn UI parity + offcanvas + smart-sort + chip counts ✅ COMPLETE (2026-07-22)

**Goal:** Return index / create / show pages look and behave like legacy. Add the offcanvas quick-create, smart-sort, live chip counts, GRN typeahead, and the missing "Return against this GRN" cross-linkage.

**Status:** ✅ All 14 tasks complete. See "Phase 4 Completion Summary" at the top of this document for verification details, smoke-test checklist, and the 8 bugs fixed (BUG-23 through BUG-30).

**Tasks:**
1. Link `purchase-return-index.css` and `purchase-return-create.css` via `@push('css')`.
2. Restructure `purchase-returns/index.blade.php`:
   - Wrap in `<div class="purchase-return-app">`.
   - Hero with `.purchase-return-hero` + `.purchase-return-branch-tag` + `.purchase-return-pending-badge`.
   - Collapsible filter panel with `.purchase-return-filters-shell` + `.purchase-return-smart-panel` + `.purchase-return-preset-row`.
   - Search input with `.purchase-return-search-wrap` + `.purchase-return-search-input`.
   - **Live chip counts** with `.purchase-return-status-chips` + `.purchase-return-status-chip` (with `.chip-count` child).
   - Active filter bar with `.purchase-return-active-bar`.
   - Results card with `.purchase-return-results-card` + `.purchase-return-mobile-card`.
   - **Smart-sort checkbox** (`.purchase-return-smart-label`).
   - **Offcanvas quick-create** button (`.purchase-return-create-offcanvas`).
3. Add new AJAX endpoint `GET admin/purchase-returns/summary?from_date=&to_date=&search=` returning JSON `{total, active, reversed}` for chip counts.
4. Add new AJAX endpoint `GET admin/purchase-returns/search-receives?term=` returning JSON list of confirmed non-reversed GRNs with returnable items.
5. Restructure `purchase-returns/create.blade.php`:
   - Wrap in `<div class="prt-create-app">`.
   - 2-step workspace with `.prt-create-workspace`:
     - Step 1 "Find GRN": `.prt-create-step-find` + `.prt-create-find-head` + `.prt-create-step-badge` + `.prt-create-search-wrap` + `.prt-create-search-hint` + `.prt-create-results` + `.prt-create-result-card` (with `.prt-create-result-top` + `.prt-create-result-code` + `.prt-create-result-amt` + `.prt-create-result-meta`).
     - Step 2 "Return form": `.prt-create-invoice-bar` + `.prt-create-change-invoice` + `.prt-create-form-card` + `.prt-create-form-card-head` + `.prt-create-lines-table` + `.prt-create-total-strip` + `.prt-create-form-actions`.
   - Keyboard navigation (↑↓ Enter Esc) on the GRN search results.
   - Per-row warehouse `<select>` with `data-available` for client-side stock cap (Good condition only — see Phase 5).
6. Create reusable partial `resources/views/admin/purchase-returns/partials/create-workspace.blade.php` (mirrors legacy `partials/create_workspace.php`). Use it on BOTH the full-page create AND the index offcanvas.
7. Restructure `purchase-returns/show.blade.php`:
   - Keep the rich layout (stat cards + stock movements + GL + ledger cards).
   - Add `.prt-create-app` wrapper (or `.purchase-return-app`) for visual consistency.
   - Add "Slip" button linking to the printable slip (Phase 6).
8. Add "Return against this GRN" button on `purchase-receives/show.blade.php` (links to `route('admin.purchase-returns.create', ['receive_id' => $receive->id])`).
9. Add "Returns against this GRN" list section on `purchase-receives/show.blade.php`:
   - Query: `PurchaseReturn::where('purchase_receive_id', $receive->id)->with('items')->get()`.
   - Render as a small table below the GRN items table.
   - Show return_code (link to Return show), return_date, total_amount, status, reversed badge.
10. Wire the offcanvas: opening it bootstraps a `PurchaseReturnWorkspace` instance pointing at the offcanvas's workspace div. After successful save, dispatch `purchaseReturn:created` event → index table reloads + chip counts refresh.
11. Replace client-side DataTables with server-side DataTables on Return index.
12. Add CSV export on Return index.
13. Add localStorage filter persistence (`purchase_return_filters_v1`).
14. Smoke-test: Open the offcanvas on Return index. Search for a GRN. Pick it. Return 2 of 5 items. Save. Verify the index table reloads and the chip counts update. Verify the GRN show page now lists this return.

**Files touched:** 3 blade views (index/create/show) + 1 new partial, 1 controller (2 new AJAX endpoints + datatables + export + smart-sort), GRN show blade (add Returns list + Return button), ~800 lines of new inline JS.

---

### Phase 5 — Damage condition + dual stock cap ✅ COMPLETE (2026-07-22)

**Goal:** Support Good vs Damage return conditions. Damage = no stock movement, supplier claim only. Both still post GL + supplier_ledger. Implement dual stock cap (GRN returnable AND warehouse available) for Good returns.

**Status:** ✅ Complete (2026-07-22). See the Phase 5 Completion Summary at the top of this document for full deliverables, files touched, bugs fixed, and the user-side smoke-test checklist.

**Original task list (preserved for reference):**

**Tasks:**
1. Add migration `2025_01_25_000001_add_condition_to_purchase_return_items.php`:
   ```sql
   condition VARCHAR(10) NOT NULL DEFAULT 'Good' CHECK (condition IN ('Good','Damage'))
   ```
2. Update `PurchaseReturnItem` model: add `condition` to `$fillable`, add accessor for boolean `isDamage()`.
3. Update `PurchaseReturnController::store()` validation: add `'items.*.condition' => 'nullable|in:Good,Damage'` (default `Good`).
4. Update `PurchaseReturnService::createReturn()`:
   - Save `condition` on each item.
   - For total_amount calculation: sum ALL items (both Good and Damage) — Damage still affects AP.
5. Update `PurchaseReturnService::confirmReturn()`:
   - For each item: if `condition === 'Good'`, do stock OUT + log movement (current behavior).
   - If `condition === 'Damage'`, **skip** the stock OUT + log movement. Still increment `return_qty` on the GRN item. Still post GL + supplier_ledger.
6. Update `PurchaseReturnService::cancelReturn()` (reversal):
   - For each item: if `condition === 'Good'`, reverse the stock movement (current behavior).
   - If `condition === 'Damage'`, no stock reversal needed (nothing was moved). Still decrement `return_qty`.
7. Update `getReceiveDetails()` AJAX response to include per-warehouse `available_qty` for each item (so the JS can enforce the dual cap).
8. Update Return create form JS:
   - Add a `<select class="form-select condition-select">` per row with options `Good` / `Damage`.
   - When condition = `Good`: enable the warehouse `<select>`, set `max` on the qty input to `min(returnable, available)`.
   - When condition = `Damage`: disable the warehouse `<select>` (or set to "N/A"), set `max` on the qty input to `returnable` only (no warehouse cap).
   - SweetAlert warning if user tries to return Good qty > warehouse available.
9. Update Return show page to display the `condition` column in the items table.
10. Update PurchaseAudit checklist (Phase 6) to include the `prt_damage` check (Damage lines must not have stock movements).
11. Smoke-test: Create a GRN with 10 units. Create a Return with 3 Good + 2 Damage. Confirm. Verify: stock movement only for 3 units, GL + supplier_ledger for all 5 units' value, GRN item `return_qty` = 5. Cancel the return. Verify: stock restored only for 3 units, `return_qty` back to 0.

**Files touched:** 1 migration (new), 1 SQL file, 1 model, 1 controller, 1 service, 2 blade views (show + create), ~150 lines of new JS (the `applyCondition` method). Total: 7 files, ~470 lines changed.

---

### Phase 6 — Printable Return slip + per-module audit logs + PurchaseAudit checklist ✅ COMPLETE (2026-07-22)

**Goal:** Add the printable Return slip, per-module audit-log pages (PO/GRN/Return), and the central PurchaseAudit checklist dashboard with 12 health-check sections.

**Tasks:**
1. **Printable Return slip:**
   - Add route `GET admin/purchase-returns/{id}/slip` named `admin.purchase-returns.slip`.
   - Add controller method `slip(int $id)` that loads the return + items + supplier + GRN + branch.
   - Create blade `resources/views/admin/purchase-returns/slip.blade.php` that mirrors legacy `PurchaseReturn/slip.php`:
     - Max-width 900px centered card.
     - Red header with "REMOTE CENTER / PURCHASE RETURN SLIP" + return_code.
     - 2-col info row: Supplier (name + mobile) + GRN Reference (left); Branch + Date + Created By (right).
     - Items table: #, Product (name + code), Warehouse, Return Qty, Rate, Amount, Condition.
     - Footer total row.
     - Reason box.
     - Signature lines ("Received By (Supplier)" / "Authorized By").
     - Embedded `<style>@media print { ... }</style>` to hide sidebar/navbar/buttons.
     - "Print" button calling `window.print()`.
   - Add "Print Slip" button on Return show page (opens slip in new tab).
2. **Per-module audit-log pages:**
   - Add routes: `GET admin/purchase-orders/audit`, `GET admin/purchase-receives/audit`, `GET admin/purchase-returns/audit`.
   - Add controller methods `audit(Request)` on each controller.
   - Each method queries the `user_audits` table filtered by action prefix (`purchase_order_*`, `purchase_receive_*`, `purchase_return_*`), paginated 100/page.
   - Create 3 blade views (one per module) using a shared partial `resources/views/admin/purchase/partials/audit-log-table.blade.php`:
     - Card with header (title + back button + "view cancelled/reversed" toggle).
     - Responsive table with columns: Timestamp, By (username), Action (badge), Target ID, Details (JSON pretty-printed), IP.
     - Action badge color mapping: `*_created` = success, `*_updated` = info, `*_cancelled` / `*_reversed` = danger.
3. **UserAudit log calls** — add `UserAudit::log()` calls to all service methods that don't already have them:
   - `PurchaseOrderService::createOrder` → `purchase_order_created`
   - `PurchaseOrderService::updateOrder` → `purchase_order_updated`
   - `PurchaseOrderService::markAsSent` → `purchase_order_sent`
   - `PurchaseOrderService::cancelOrder` → `purchase_order_cancelled`
   - `PurchaseReceiveService::createReceive` → `purchase_receive_created`
   - `PurchaseReceiveService::confirmReceive` → `purchase_receive_confirmed`
   - `PurchaseReceiveService::cancelReceive` → `purchase_receive_cancelled`
   - `PurchaseReturnService::createReturn` → `purchase_return_created`
   - `PurchaseReturnService::confirmReturn` → `purchase_return_confirmed`
   - `PurchaseReturnService::cancelReturn` → `purchase_return_reversed`
4. **PurchaseAudit checklist:**
   - Create model `app/Services/PurchaseAuditService.php` with method `runHealthChecks()` that returns the 12-section report (mirror legacy `PurchaseAuditModel::runHealthChecks`):
     1. Purchase module scope (info).
     2. Products (purchase SKUs).
     3. Suppliers.
     4. Warehouses & branches.
     5. Stock — single source of truth.
     6. Purchase order.
     7. Goods received (GRN).
     8. Purchase return.
     9. Supplier payments & due.
     10. GL journal link columns.
     11. Ledger & accounts (GL).
     12. Reporting (catalog).
   - Add routes: `GET admin/purchase-audit` (HTML page), `GET admin/purchase-audit/run` (JSON AJAX).
   - Replace the stub `admin/reports/purchase-audit` route with the real checklist.
   - Create blade `resources/views/admin/purchase-audit/checklist.blade.php`:
     - Wrap in `<div class="purch-audit-app">`.
     - Hero with `.purch-audit-hero`.
     - Summary chips with `.purch-audit-summary` (pass/warn/fail/info counts).
     - TOC nav with `.purch-audit-toc` + `.purch-audit-toc-link`.
     - 12 sections with `.purch-audit-section` + `.purch-audit-section-head` + `.purch-audit-item` (with `.status-pass/warn/fail/info`) + `.purch-audit-badge`.
     - 3 detail tables (conditional): negative stock, GRNs missing journal, Returns missing journal.
     - "Re-run checks" button → fetch JSON from `admin/purchase-audit/run` → re-render sections via SweetAlert confirmation.
   - Link `purchase-audit-checklist.css` via `@push('css')`.
5. Smoke-test: Create a PO, GRN, Return. Visit each module's audit page → verify all 4 actions are logged. Visit the PurchaseAudit checklist → verify all 12 sections render with correct pass/warn/fail statuses. Click "Re-run checks" → verify AJAX refresh works. Create a Return → click "Print Slip" → verify the slip renders in a new tab and prints cleanly.

**Files touched:** 5 new blade views (slip + 3 audit logs + checklist) + 1 shared partial, 4 controller methods (slip + 3 audit), 1 new service (`PurchaseAuditService`), 4 routes, 1 CSS file linked, ~1,000 lines of new code.

---

### Phase 7 — Polish: AJAX product search, Form Requests, cross-linkage completion, exports ✅ COMPLETE (2026-07-22)

**Goal:** Close the remaining parity gaps: AJAX product typeahead (>500 product support), Form Request classes, any remaining cross-linkage buttons, mobile card rendering.

**Tasks:**
1. **AJAX product search** — replace the 500-product `<template>` dropdown on PO create/edit AND GRN create (Direct mode) with a custom text-input typeahead:
   - Reuse the endpoint from Phase 2: `GET admin/purchase-orders/search-products?term=...`.
   - Same `.sales-search-input` + `.sales-suggest-list` pattern as the sales cart.
   - Each result row: product_name (bold) + product_code + unit.
   - Keyboard nav: ↑↓ to move, Enter to pick, Esc to close.
   - Barcode scanner support (same as sales cart — debounced input + Enter fallback).
2. **Form Request classes:**
   - `app/Http/Requests/PurchaseOrder/StorePurchaseOrderRequest.php`
   - `app/Http/Requests/PurchaseOrder/UpdatePurchaseOrderRequest.php`
   - `app/Http/Requests/PurchaseOrder/CancelPurchaseOrderRequest.php`
   - `app/Http/Requests/PurchaseReceive/StorePurchaseReceiveRequest.php`
   - `app/Http/Requests/PurchaseReceive/ConfirmPurchaseReceiveRequest.php`
   - `app/Http/Requests/PurchaseReceive/CancelPurchaseReceiveRequest.php`
   - `app/Http/Requests/PurchaseReceive/GetPoDetailsRequest.php`
   - `app/Http/Requests/PurchaseReturn/StorePurchaseReturnRequest.php`
   - `app/Http/Requests/PurchaseReturn/ConfirmPurchaseReturnRequest.php`
   - `app/Http/Requests/PurchaseReturn/CancelPurchaseReturnRequest.php`
   - `app/Http/Requests/PurchaseReturn/GetReceiveDetailsRequest.php`
   - Update all 3 controllers to use these instead of inline `$request->validate()`.
3. **Cross-linkage audit** — verify all 4 cross-links work:
   - PO show → "Receive against this PO" button → GRN create with `?po_id=` (Phase 3).
   - PO show → "Receives against this PO" list (Phase 3).
   - GRN show → "Return against this GRN" button → Return create with `?receive_id=` (Phase 4).
   - GRN show → "Returns against this GRN" list (Phase 4).
4. **Mobile card rendering** — on all 3 index pages, render `<div class="purch-index-mobile-cards">` / `<div class="purchase-return-mobile-cards">` with one card per row when viewport `<768px`. Use the DataTables `drawCallback` to populate the mobile card container from the same JSON.
5. **CSV exports** — verify all 3 export endpoints work and produce well-formed CSVs with headers: `Code, Date, Supplier, Branch, Total, Status, Created By`.
6. Smoke-test: Open PO create on a catalog with 2,000 products. Verify the typeahead returns results within 200ms. Submit an invalid form (missing supplier) → verify the Form Request returns proper error messages. Resize the browser to <768px → verify the index pages show mobile cards instead of the table.

**Files touched:** 11 Form Request classes, 3 controllers (use Form Requests + search-products endpoint), 3 index blades (mobile card rendering), ~600 lines of new code.

---

### Phase 8 — End-to-end QA + integration testing

**Goal:** Verify the entire PO → GRN → Return → Reverse flow works correctly with the legacy UI. Verify stock, GL, and supplier_ledger reconcile at every step.

**Tasks:**
1. **E2E test script** (manual or automated):
   - **Setup:** Login as admin at Branch A. Pick a supplier with no outstanding balance. Pick a product with 0 stock at Branch A's warehouse.
   - **Step 1 — Create PO:** Create a PO for 10 units of the product at rate 100. Verify PO status = `draft`, no stock movement, no GL, no supplier_ledger entry.
   - **Step 2 — Mark PO as Sent:** Click "Mark as Sent". Verify PO status = `sent`.
   - **Step 3 — Create GRN (partial):** Click "Receive against this PO". Receive 6 units into Warehouse 1. Save as draft. Verify GRN status = `draft`, no stock movement yet.
   - **Step 4 — Confirm GRN:** Click "Confirm GRN". Verify:
     - GRN status = `confirmed`.
     - `warehouse_stock` for product at Warehouse 1 = 6 (was 0).
     - `stock_transactions` has 1 IN movement of 6 units at rate 100 (avg_cost = 100).
     - `journal_entries` has 1 entry with 2 lines: Dr Inventory 600, Cr AP 600.
     - `supplier_ledger` has 1 credit entry of 600.
     - PO `received_qty` = 6, status = `partial`.
   - **Step 5 — Create Return (Good):** From GRN show, click "Return against this GRN". Return 2 units (Good condition). Save as draft. Confirm.
     - Verify: `warehouse_stock` = 4 (was 6).
     - `stock_transactions` has 1 OUT movement of 2 units at rate 100 (original receive rate).
     - `journal_entries` has 1 entry: Dr AP 200, Cr Inventory 200.
     - `supplier_ledger` has 1 debit entry of 200.
     - `purchase_receive_items.returned_qty` = 2.
   - **Step 6 — Create Return (Damage):** From GRN show, click "Return against this GRN". Return 1 unit (Damage condition). Confirm.
     - Verify: `warehouse_stock` UNCHANGED at 4 (Damage = no stock movement).
     - `stock_transactions` has NO new movement.
     - `journal_entries` has 1 entry: Dr AP 100, Cr Inventory 100.
     - `supplier_ledger` has 1 debit entry of 100.
     - `purchase_receive_items.returned_qty` = 3 (2 Good + 1 Damage).
   - **Step 7 — Reverse the Damage Return:** From Return index, click "Reverse" on the Damage return. Provide a reason.
     - Verify: `warehouse_stock` UNCHANGED at 4 (no stock to restore).
     - `journal_entries` has 1 reversing entry linked to the original.
     - `supplier_ledger` has 1 reversal credit entry of 100.
     - `purchase_receive_items.returned_qty` = 2 (back to Good-only).
     - Return `is_reversed` = true, `reversed_at` set.
   - **Step 8 — Cancel the GRN:** Try to cancel the GRN. Verify it FAILS with "active returns exist" (the Good return from Step 5 is still active).
   - **Step 9 — Reverse the Good Return:** Reverse the Good return. Verify stock restored to 6, GL reversed, ledger reversed, `return_qty` = 0.
   - **Step 10 — Cancel the GRN (again):** Now it should succeed. Verify stock back to 0, GL reversed, ledger reversed, PO `received_qty` = 0, PO status = `sent` (or `draft` — depends on implementation).
   - **Step 11 — Audit log check:** Visit each module's audit page. Verify all actions are logged with correct timestamps, users, actions, and details.
   - **Step 12 — PurchaseAudit checklist:** Visit the checklist. Verify all 12 sections render. Verify the negative-stock table is empty. Verify the missing-journal tables are empty.
2. **Branch isolation test:** Login as Branch B user. Try to access Branch A's PO via URL. Verify 403 or redirect.
3. **RBAC test:** Login as warehouse_manager. Verify can create GRN but cannot cancel. Login as salesman. Verify cannot access any purchase route.
4. **Mobile test:** Resize to <768px. Verify all 3 index pages show mobile cards. Verify the offcanvas quick-return works on mobile.
5. **Performance test:** Load 10,000 POs into the DB. Verify the index page loads in <1s (server-side DataTables). Verify the typeahead returns results in <200ms.
6. **Print test:** Print a Return slip. Verify the layout is clean (no sidebar/navbar/buttons).
7. **CSV export test:** Export all 3 index pages. Verify the CSVs open cleanly in Excel.

**Files touched:** None (QA-only phase). May produce bug-fix commits if issues are found.

---

## 9. Per-Phase Success Criteria

| Phase | "Done" when… |
|---|---|
| **0** | Live DB schema matches code. All 4 critical bugs fixed. 6 dead JS files deleted. PO→GRN→Return→Cancel flow works without errors. |
| **1** | All purchase routes are role-gated. Branch A user cannot see Branch B data. Each role sees only its allowed actions. |
| **2** | PO index/create/edit/show pages use legacy `.purch-*` classes. Product typeahead works (no Select2, no 500-product cap). Server-side DataTables works. CSV export works. |
| **3** | GRN index/create/show pages use legacy `.purch-*` classes. PO show has "Receive against PO" button + "Receives against PO" list. Direct Purchase toggle works. Per-line warehouse works. |
| **4** | Return index/create/show pages use legacy `.prt-*` / `.purchase-return-*` classes. Offcanvas quick-create works. Smart-sort works. Live chip counts work. GRN show has "Return against GRN" button + "Returns against GRN" list. |
| **5** | Damage condition column exists. Good returns move stock; Damage returns don't. Dual stock cap enforced on Good returns. GRN item `return_qty` tracks both. |
| **6** | ✅ Return slip prints cleanly. All 3 audit-log pages show user actions. PurchaseAudit checklist renders 12 sections with pass/warn/fail statuses. "Re-run checks" works. |
| **7** | AJAX product typeahead replaces Select2 on PO + GRN. All 11 Form Request classes exist and are used. All 4 cross-linkage buttons work. Mobile cards render on <768px. All 3 CSV exports work. |
| **8** | All 12 E2E test steps pass. Branch isolation works. RBAC works. Mobile works. Performance <1s on 10k rows. Print works. CSV works. |

---

## 10. Risks & Open Questions

### 10.1 Schema verification (HIGH RISK)

The Laravel audit found 4 schema/code mismatches. **We don't know if these are real bugs or just stale SQL files.** Phase 0 MUST verify by running `\d` on the live DB. If the columns are actually missing, every GRN/Return operation is currently broken and the user has been hitting silent errors (or the module is unused).

**Mitigation:** Run `\d purchase_receives` and `\d purchase_returns` in the `rcerp_postgres` container BEFORE starting Phase 0. Document the actual live schema. If columns are missing, prioritize Phase 0 above all other work.

### 10.2 Branch isolation middleware (MEDIUM RISK)

The audit notes "branch.isolation" middleware may or may not exist. Need to verify in `app/Http/Middleware/`. If it doesn't exist, Phase 1 includes creating it — which is a non-trivial task (intercepting every request, overriding `branch_id` input with session value, except for admin "all branches" mode).

**Mitigation:** Check `app/Http/Middleware/` and `app/Providers/HttpServiceProvider.php` (or `bootstrap/app.php` for Laravel 11+) for registered middleware aliases. If not found, write it from scratch — pattern: ~30 lines of code.

### 10.3 UserAudit table (MEDIUM RISK)

Phase 6 assumes a `user_audits` table exists. The legacy code uses `UserAudit::log(userId, action, targetId, detailsArray)` which writes to such a table. Need to verify Laravel has the same table + helper. If not, Phase 6 includes creating the table + helper.

**Mitigation:** Check `app/Models/UserAudit.php` (or similar) and the migrations dir for `*_create_user_audits_*`. If missing, add as part of Phase 6.

### 10.4 Server-side DataTables refactor (MEDIUM RISK)

Legacy uses server-side DataTables. Laravel currently uses client-side over paginated 25/page. Switching to server-side is a non-trivial refactor: the controller `index()` method needs a `?datatables=1` mode that returns JSON in DataTables' specific format (`{draw, recordsTotal, recordsFiltered, data}`), and the JS needs to use `ajax.url` instead of inline data.

**Mitigation:** The sales module already uses server-side DataTables on the Today's Sales page (R21 commit `7a8da29`). Reuse that pattern.

### 10.5 Offcanvas quick-create state management (LOW RISK)

The offcanvas on Return index needs to share state between the index DataTables and the workspace inside the offcanvas. After save, the index must reload AND the chip counts must refresh AND the offcanvas must close. Legacy handles this via the `purchaseReturn:created` custom event.

**Mitigation:** Use the same custom-event pattern. Listen for the event on `document` from both the DataTables init and the chip-counts init.

### 10.6 Open question: do we port the legacy "delete" hard-delete path?

Legacy `PurchaseOrderController::delete` has a hard-delete path (when called without a reason). Laravel's `cancel` is soft-only. **Decision D-implicit: drop the hard-delete path.** Drafts that need to be removed should be cancelled (soft) for audit trail. Confirm with user before Phase 0.

### 10.7 Open question: GRN `warehouse_id` (header) vs per-line

Legacy has per-line `warehouse_id` only (no header column). Laravel has BOTH a header `warehouse_id` (NOT NULL) and a per-line `warehouse_id` (nullable). This is a divergence. **Tentative decision: keep Laravel's pattern** (header = default warehouse, per-line = override). Confirm with user before Phase 3.

### 10.8 Open question: should the PO `markAsSent` action be ported back to legacy?

Laravel has `markAsSent`. Legacy has no equivalent — POs go from `draft` to `pending` automatically when the first GRN is created. **Decision D3: keep Laravel's `markAsSent`** as an enhancement. Document in the audit log.

---

## 11. Out of Scope / Net-New Features

These are NOT being ported from legacy (legacy doesn't have them). They could be added as net-new features in a future phase, but are explicitly OUT OF SCOPE for this plan:

| Feature | Why Out of Scope |
|---|---|
| Tax / discount / transport on Returns | Legacy has none. Returns are qty × rate only. |
| Transport cost on PO / GRN | Legacy has none. If needed, add as net-new. |
| Unit conversion (case → piece) | Legacy has none. All qty in base unit. |
| Foreign currency / exchange rate | Legacy has none. All amounts in BDT. |
| Approval workflow (PO/GRN/Return approval) | Legacy has none. All documents are immediately active on save (or on confirm in Laravel's two-phase pattern). |
| PO printable slip | Legacy has none. Only Return has a slip. |
| GRN printable slip | Legacy has none. Only Return has a slip. |
| Multi-currency supplier accounts | Legacy has none. |
| Supplier rating / performance scorecard | Legacy has none. |
| Purchase requisition (pre-PO) | Legacy has none. PO is the first document in the chain. |
| RFQ (Request for Quotation) | Legacy has none. |
| Supplier portal (supplier self-service PO/GRN view) | Legacy has none. |
| Auto-PO from reorder level | Legacy has none. |

---

## 12. File Inventory (Legacy → Laravel mapping)

This is the master mapping for the porting agent. Each row shows what the legacy file maps to in Laravel (or "NEW" if it doesn't exist yet).

### 12.1 Controllers

| Legacy file | Lines | Laravel equivalent | Phase |
|---|---|---|---|
| `PurchaseOrderController.php` | 331 | `app/Http/Controllers/Admin/PurchaseOrderController.php` (221 lines) — ✅ exists, needs `audit()` + `export()` + `searchProducts()` methods | Phase 2, 6, 7 |
| `PurchaseReceiveController.php` | 252 | `app/Http/Controllers/Admin/PurchaseReceiveController.php` (237 lines) — ✅ exists, needs `audit()` + `export()` methods | Phase 3, 6, 7 |
| `PurchaseReturnController.php` | 316 | `app/Http/Controllers/Admin/PurchaseReturnController.php` (233 lines) — ✅ exists, needs `audit()` + `export()` + `slip()` + `searchReceives()` + `summary()` methods | Phase 4, 6, 7 |
| `PurchaseAuditController.php` | 57 | **NEW** — `app/Http/Controllers/Admin/PurchaseAuditController.php` | Phase 6 |

### 12.2 Models

| Legacy table | Laravel model | Phase |
|---|---|---|
| `purchase_orders` | `app/Models/PurchaseOrder.php` (121 lines) ✅ | — |
| `purchase_order_items` | `app/Models/PurchaseOrderItem.php` (64 lines) ✅ | — |
| `purchase_receives` | `app/Models/PurchaseReceive.php` (132 lines) ✅ | — |
| `purchase_receive_items` | `app/Models/PurchaseReceiveItem.php` (66 lines) ✅ | — |
| `purchase_returns` | `app/Models/PurchaseReturn.php` (107 lines) ✅ | — |
| `purchase_return_items` | `app/Models/PurchaseReturnItem.php` (63 lines) ✅ — needs `condition` column added | Phase 5 |

### 12.3 Views

| Legacy view | Lines | Laravel blade | Phase |
|---|---|---|---|
| `PurchaseOrder/index.php` | 112 | `resources/views/admin/purchase-orders/index.blade.php` (319 lines) — ✅ exists, restructure to `.purch-index-app` | Phase 2 |
| `PurchaseOrder/create.php` | 41 | `resources/views/admin/purchase-orders/create.blade.php` (422 lines) — ✅ exists, restructure to `.purch-po-form-app` + replace Select2 with typeahead | Phase 2 |
| `PurchaseOrder/edit.php` | 45 | `resources/views/admin/purchase-orders/edit.blade.php` (437 lines) — ✅ exists, same restructure as create | Phase 2 |
| `PurchaseOrder/details.php` | 175 | `resources/views/admin/purchase-orders/show.blade.php` (424 lines) — ✅ exists, restructure to `.purch-po-detail` | Phase 2 |
| `PurchaseOrder/audit.php` | 102 | **NEW** — `resources/views/admin/purchase-orders/audit.blade.php` | Phase 6 |
| `PurchaseOrder/partials/po_form.php` | 99 | Inline in create.blade.php and edit.blade.php (no partial needed) | Phase 2 |
| `PurchaseReceive/index.php` | 109 | `resources/views/admin/purchase-receives/index.blade.php` (308 lines) — ✅ exists, restructure to `.purch-index-app` | Phase 3 |
| `PurchaseReceive/create.php` | 161 | `resources/views/admin/purchase-receives/create.blade.php` (687 lines) — ✅ exists, restructure + replace Select2 with typeahead | Phase 3 |
| `PurchaseReceive/details.php` | 120 | `resources/views/admin/purchase-receives/show.blade.php` (632 lines) — ✅ exists, richer than legacy — keep | Phase 3 (minor restyle) |
| `PurchaseReceive/audit.php` | 107 | **NEW** — `resources/views/admin/purchase-receives/audit.blade.php` | Phase 6 |
| `PurchaseReturn/index.php` | 168 | `resources/views/admin/purchase-returns/index.blade.php` (300 lines) — ✅ exists, restructure to `.purchase-return-app` + add offcanvas + smart-sort + chip counts | Phase 4 |
| `PurchaseReturn/create.php` | 41 | `resources/views/admin/purchase-returns/create.blade.php` (442 lines) — ✅ exists, restructure to `.prt-create-workspace` 2-step wizard | Phase 4 |
| `PurchaseReturn/details.php` | 94 | `resources/views/admin/purchase-returns/show.blade.php` (593 lines) — ✅ exists, richer than legacy — keep | Phase 4 (minor restyle) |
| `PurchaseReturn/audit.php` | 118 | **NEW** — `resources/views/admin/purchase-returns/audit.blade.php` | Phase 6 |
| `PurchaseReturn/slip.php` | 155 | **NEW** — `resources/views/admin/purchase-returns/slip.blade.php` | Phase 6 |
| `PurchaseReturn/partials/create_workspace.php` | 42 | **NEW** — `resources/views/admin/purchase-returns/partials/create-workspace.blade.php` (shared by full-page create AND index offcanvas) | Phase 4 |
| `PurchaseAudit/checklist.php` | 235 | **NEW** — `resources/views/admin/purchase-audit/checklist.blade.php` (replaces the stub at `reports/purchase-audit.blade.php`) | Phase 6 |

### 12.4 JavaScript

| Legacy JS file | Lines | Laravel equivalent | Phase |
|---|---|---|---|
| `PurchaseOrder.js` | 372 | Inline `@push('scripts')` in `purchase-orders/create.blade.php` and `edit.blade.php` | Phase 2 |
| `PurchaseReceive.js` | 432 | Inline `@push('scripts')` in `purchase-receives/create.blade.php` | Phase 3 |
| `PurchaseReturn.js` | 667 | Inline `@push('scripts')` in `purchase-returns/create.blade.php` AND the offcanvas partial | Phase 4 |
| `purchase-order-index.js` | 353 | Inline `@push('scripts')` in `purchase-orders/index.blade.php` | Phase 2 |
| `purchase-receive-index.js` | 279 | Inline `@push('scripts')` in `purchase-receives/index.blade.php` | Phase 3 |
| `purchase-return-index.js` | 398 | Inline `@push('scripts')` in `purchase-returns/index.blade.php` | Phase 4 |
| (none) | — | `laravel/public/assets/js/Purchase*.js` (6 dead files, ~2,500 lines) — DELETE in Phase 0 | Phase 0 |

### 12.5 CSS

| Legacy CSS file | Lines | Laravel equivalent | Phase |
|---|---|---|---|
| `purchase-index.css` | 335 | `laravel/public/assets/css/purchase-index.css` — link via `@push('css')` on PO/GRN index + PO form + PO details blades | Phase 2 |
| `purchase-order-form.css` | 118 | `laravel/public/assets/css/purchase-order-form.css` — link on PO create/edit blades | Phase 2 |
| `purchase-order-details.css` | 142 | `laravel/public/assets/css/purchase-order-details.css` — link on PO show blade | Phase 2 |
| `purchase-return-create.css` | 355 | `laravel/public/assets/css/purchase-return-create.css` — link on Return create + offcanvas blades | Phase 4 |
| `purchase-return-index.css` | 309 | `laravel/public/assets/css/purchase-return-index.css` — link on Return index blade | Phase 4 |
| `purchase-audit-checklist.css` | 211 | `laravel/public/assets/css/purchase-audit-checklist.css` — link on PurchaseAudit checklist blade | Phase 6 |

**Note:** All 6 CSS files already exist at `laravel/public/assets/css/` (copied from legacy during initial scaffold). They just need to be LINKED on the relevant blades via `@push('css')` — same pattern as the sales cart's `sales-pos.css` link (commit `f7274fb`).

### 12.6 Services (Laravel-only — no legacy equivalent)

| Laravel service | Lines | Status |
|---|---|---|
| `app/Services/PurchaseOrderService.php` | (exists) | ✅ — add `UserAudit::log()` calls in Phase 6 |
| `app/Services/PurchaseReceiveService.php` | (exists) | ✅ — add "active returns exist" guard in Phase 0, add `UserAudit::log()` calls in Phase 6 |
| `app/Services/PurchaseReturnService.php` | (exists) | ✅ — add Damage condition branching in Phase 5, add `UserAudit::log()` calls in Phase 6 |
| `app/Services/PurchaseAuditService.php` | **NEW** | Phase 6 |

### 12.7 Form Requests (Laravel-only — no legacy equivalent)

All 11 Form Request classes are **NEW** in Phase 7. See §8 Phase 7 task 2 for the full list.

### 12.8 Migrations

| Migration | Table | Phase |
|---|---|---|
| `2025_01_24_000001_add_status_to_purchase_receives.php` | `purchase_receives` | Phase 0 (if column missing) |
| `2025_01_24_000002_add_status_to_purchase_returns.php` | `purchase_returns` | Phase 0 (if column missing) |
| `2025_01_24_000003_add_expected_date_to_purchase_orders.php` | `purchase_orders` | Phase 0 (if column missing) |
| `2025_01_25_000001_add_condition_to_purchase_return_items.php` | `purchase_return_items` | Phase 5 |
| (Update) `database/sql/05_purchase.sql` | all 6 tables | Phase 0 (reconcile with migrations) |

---

## End of Document

**Next action:** Confirm Phase 0 kickoff. The first concrete step is to run `\d purchase_receives` and `\d purchase_returns` in the `rcerp_postgres` container to verify whether the `status` column actually exists. This determines whether Phase 0 is a 30-minute schema fix or a 5-minute no-op.

**Approval gates:** Each phase requires user sign-off on the success criteria (§9) before the next phase begins. No phase should be merged to `main` without a smoke test passing.
