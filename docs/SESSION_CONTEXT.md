# Session Context — debugRC Sales Module Remediation

> **Purpose:** This file is the persistent memory of the long-running
> Super Z ↔ user chat session that is auditing the Legacy vs Laravel
> Sales Entry systems in this repo. When the chat context is lost
> (long conversation, model restart, etc.), any future agent MUST read
> this file FIRST to recover full context before doing any work.
>
> **Last updated:** 2026-07-22 (R24/R25 dropped per user request — Telegram + FCM notifications explicitly NOT being ported. R26/R27/R28 done — `min:10` override_reason + `min:5` reversal reason + PWA installability meta on cart blade.)
> **Maintained by:** Super Z (AI assistant)
> **Repository:** `sajidchowdhury/debugRC` (branch: `main`)

---

## 0. Quick Recovery Checklist (READ THIS FIRST)

If you are a new agent picking up this session, do these in order:

1. `cat /home/z/my-project/debugRC/docs/SESSION_CONTEXT.md` (this file)
2. `cat /home/z/my-project/debugRC/docs/sales_entry_Lg_vs_La.md` (the audit report)
3. `cat /home/z/my-project/debugRC/docs/REMEDIATION_LOG.md` (the R1/R2/… progress log)
4. `cd /home/z/my-project/debugRC && git log --oneline -20`
5. `cd /home/z/my-project/debugRC && git status`
6. Read the **Open Work Items** section below to see what is next.

You do NOT need to re-clone the repository — it is already at
`/home/z/my-project/debugRC/`. You DO need to re-read the audit
report because it contains the full gap analysis that drives the
remediation backlog.

---

## 1. Project Background

**Repository:** `https://github.com/sajidchowdhury/debugRC.git`

The repo is an ERP system ("RCErp") for a Bangladesh electronics
distributor. It has two parallel implementations of the Sales module:

| Tier      | Stack                                                                  | Location                         |
|-----------|------------------------------------------------------------------------|----------------------------------|
| Legacy    | Custom PHP 8 MVC (no framework), MySQL/PDO, jQuery 3.6 + Select2 + SweetAlert2 | `/home/z/my-project/debugRC/legacy/`     |
| Laravel   | Laravel 11, PostgreSQL, Blade, jQuery 3.6 + Select2 + SweetAlert2     | `/home/z/my-project/debugRC/laravel/`    |

The Laravel tier is a **port-in-progress**, not a finished rewrite.
The Legacy tier is the production system. The Laravel tier is being
brought up to feature parity, then beyond, one remediation item (R#)
at a time.

### Repository layout (only the parts that matter)

```
debugRC/
├── legacy/
│   ├── app/controllers/SalesController.php       # Legacy sales endpoints (search_customer, search_product, …)
│   ├── app/services/Sales/                       # SalesCartService, SalesInvoiceService, SalesPaymentService + traits
│   ├── app/services/Stock/StockAvailabilityService.php  # searchProductsWithStock() + findProductByExactCode()
│   ├── app/models/SalesModel.php                 # thin facade over Helper.php SQL
│   ├── app/helpers/Helper.php                    # Search_Customers(), Search_Product_With_Stock()
│   ├── app/views/sales/create.php                # Legacy sales entry UI
│   └── public/assets/js/sales-create.js          # Legacy sales entry JS
├── laravel/
│   ├── app/Http/Controllers/Admin/SalesCartController.php    # Laravel cart controller (R1 owner). R6: clear() + softHold() now pass session branch_id explicitly.
│   ├── app/Http/Controllers/Admin/SalesInvoiceController.php # Laravel invoice finalize/edit/cancel (has idempotency_token since P2-6)
│   ├── app/Http/Controllers/Admin/CustomerPaymentController.php # R2 added idempotency_token + cache check to store()
│   ├── app/Http/Controllers/Admin/SalesChallanController.php    # R3 added idempotency_token + cache check to issueChallan()
│   ├── app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php # R2 added idempotency_token + cache check to store()
│   ├── app/Http/Controllers/Api/V1/Sales/SalesChallanApiController.php    # R3 added idempotency_token + cache check to issue()
│   ├── app/Http/Controllers/Api/V1/Sales/SalesCartApiController.php       # R6: clear() + softHold() now pass resolveBranchId($request) explicitly.
│   ├── app/Http/Requests/Api/V1/Sales/StorePaymentRequest.php # R2 added idempotency_token rule
│   ├── app/Http/Requests/Api/V1/Sales/IssueChallanRequest.php # R3 added idempotency_token rule
│   ├── app/Services/Sales/                                  # SalesCartService, SalesInvoiceService, SalesChallanService, CustomerPaymentService, …
│   ├── app/Services/Sales/SalesAuditLogger.php              # R4 added cartItemAdded/Updated/Removed/Cleared methods + recentSalesEvents entries
│   ├── app/Services/Sales/SalesCartService.php              # R4 wired SalesAuditLogger into addItem/updateItem/removeItem/clearCart
│   ├── app/Services/Sales/SalesInvoiceService.php           # R5 added assertCreditLimitUnderLock() — Customer::lockForUpdate() inside finalize + update transactions. R6: clearCart() now passes branch_id explicitly so the right (user, customer, branch) cart row is cleared.
│   ├── app/Services/Sales/SalesCartService.php              # R4 wired SalesAuditLogger into addItem/updateItem/removeItem/clearCart. R6: setSoftHold() now takes ?int $branchId (consistent with new 3-column unique key).
│   ├── app/Models/SalesDraftCart.php                        # R6: getOrCreate() now includes branch_id in firstOrCreate search attrs and normalizes null → 0 (matching the new uq_sales_draft_user_customer_branch constraint + NOT NULL DEFAULT 0 column).
│   ├── app/Models/CustomerPayment.php                      # H1 bugfix: removed dead isDraft/isConfirmed/isCancelled (status column doesn't exist)
│   ├── app/Http/Controllers/Admin/CustomerController.php   # H1 bugfix: removed whereNotIn('status') on CustomerPayment/SalesReturn queries in show()
│   ├── app/Services/Stock/StockAvailabilityService.php      # R1 added searchProductsWithStock() + findProductByExactCode()
│   ├── app/Models/Customer.php                              # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── app/Models/Product.php                               # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── resources/views/admin/sales/cart.blade.php           # R1 replaced 500-row dropdowns with AJAX select2
│   ├── resources/views/admin/customer-payments/create.blade.php # R2 added hidden idempotency_token input (Str::uuid())
│   ├── resources/views/admin/sales-challans/issue.blade.php # R3 added hidden idempotency_token input (Str::uuid())
│   ├── routes/web.php                                       # R1 added cart/search-customer, cart/search-product, cart/product-by-code
│   ├── database/migrations/2025_01_23_000001_r6_add_branch_id_to_sales_draft_carts_unique_key.php # R6 migration: drops uq_sales_draft_user_customer, adds uq_sales_draft_user_customer_branch, drops FK on branch_id, backfills NULL → 0, makes column NOT NULL DEFAULT 0
│   └── database/sql/04_sales.sql                            # R6 updated sales_draft_carts schema for fresh installs (3-column unique key, NOT NULL DEFAULT 0, no FK on branch_id)
├── docs/
│   ├── sales_entry_Lg_vs_La.md   # The big audit report (1100+ lines)
│   ├── SESSION_CONTEXT.md        # ← this file
│   └── REMEDIATION_LOG.md        # R1/R2/… progress log
└── worklog.md                    # Multi-agent work log (per the system instructions)
```

---

## 2. The Audit Report

`docs/sales_entry_Lg_vs_La.md` is the **authoritative gap analysis** between Legacy and Laravel Sales Entry. It contains 9 sections:

1. Executive Summary
2. Legacy Workflow (full chain: customer → product → cart → invoices → drafts → finalize → payment → stock/due/ledger → print → edit/delete)
3. Laravel Workflow
4. UI/UX Comparison (with verdicts)
5. Business Logic Comparison (with verdicts)
6. Missing Features in Laravel
7. Laravel Improvements over Legacy
8. Risks (wrong stock, wrong customer due, wrong accounting, duplicate invoices, poor UX, multi-user conflicts)
9. Recommendations

The audit identifies a remediation backlog. Each item is labelled
**R1, R2, R3, …** in the order the user wants them tackled.

---

## 3. Remediation Backlog

Items are tackled in order. Each item has its own entry in
`docs/REMEDIATION_LOG.md` (status: ✅ done / 🚧 in-progress / ⏳ pending).

| ID  | Title                                                                     | Status       | Notes |
|-----|---------------------------------------------------------------------------|--------------|-------|
| R1  | Replace select2 500-row dropdowns with live search endpoints              | ✅ done      | Ported `sales/search_customer` + `sales/search_product` + `sales/product_by_code` from Legacy to Laravel. See `REMEDIATION_LOG.md` for full diff. |
| R2  | Add idempotency token to payment create (mirror finalize pattern)         | ✅ done      | Both `POST /admin/customer-payments` (web, 10-min cache) and `POST /api/v1/sales/payments` (API, 5-min cache) now require a UUID v4 `idempotency_token`. Fixes audit risks V2 + V6. See `REMEDIATION_LOG.md` §R2 for full diff. |
| R3  | Add idempotency token to challan issue                                    | ✅ done      | Both `POST /admin/sales-challans/issue/{invoiceId}` (web, 10-min cache) and `POST /api/v1/sales/challans/issue` (API, 5-min cache) now require a UUID v4 `idempotency_token`. Fixes audit risk V3. See `REMEDIATION_LOG.md` §R3 for full diff. |
| R4  | Add cart mutation audit logging                                           | ✅ done      | Extended `SalesAuditLogger` with `cartItemAdded`, `cartItemUpdated`, `cartItemRemoved`, `cartCleared`. Wired into `SalesCartService` via DI — fires one audit event per successful cart mutation. Both Blade + API paths covered (they share the service). Fixes audit risk V4, mitigates C2 (Laravel side). See `REMEDIATION_LOG.md` §R4 for full diff. |
| R5  | Lock the customer row before credit-limit check                          | ✅ done      | Added `assertCreditLimitUnderLock()` helper that does `Customer::lockForUpdate()->find()` + `checkCreditLimit` + throw-on-exceed. Called at the top of the transaction in BOTH `finalizeFromCart` and `updateInvoice`. The pre-transaction `checkCreditLimit` call is kept for fast UX feedback. Fixes audit risk V5, mitigates C1 (Laravel side). See `REMEDIATION_LOG.md` §R5 for full diff. |
| H1  | Hotfix: `customer_payments.status` column does not exist                  | ✅ done      | Production bug: viewing a customer's "360° Hub" page threw `SQLSTATE[42703]` because `CustomerController::show` filtered `CustomerPayment` queries with `->whereNotIn('status', ['cancelled'])`, but `customer_payments` has no `status` column (only `is_reversed`). Removed the broken filters; also removed the dead `isDraft/isConfirmed/isCancelled` methods on `CustomerPayment` model. See `REMEDIATION_LOG.md` §H1 for full diff. |
| R6  | Add `branch_id` to `sales_draft_carts` unique key                          | ✅ done      | New migration `2025_01_23_000001_r6_add_branch_id_to_sales_draft_carts_unique_key.php` drops `uq_sales_draft_user_customer` and adds `uq_sales_draft_user_customer_branch` on `(user_id, customer_id, branch_id)`. Also drops the FK on `branch_id`, backfills NULL → 0, and makes the column `NOT NULL DEFAULT 0` (Legacy "no specific branch" sentinel). `SalesDraftCart::getOrCreate()` updated to include `branch_id` in `firstOrCreate` search attrs + normalize null → 0. All `clearCart()` / `setSoftHold()` callers updated to pass `branch_id` explicitly (Admin web + API V1 + `SalesInvoiceService::finalizeFromCart`). `04_sales.sql` updated for fresh installs. Fixes V11, mitigates C7 (Laravel side). See `REMEDIATION_LOG.md` §R6 for full diff. |
| R10 | Wire up barcode scanning in the cart blade (UI for the R1 endpoint)        | ✅ done      | R1 ported the `sales/product_by_code` endpoint to Laravel but left it without a UI consumer. R10 adds a toggle-revealed `#barcodeInput` field to `cart.blade.php` with an Enter-key + "Scan & Add" button handler that calls the existing endpoint. On success: caches the product in `productCache`, injects a fresh `<option>` into the Select2 and triggers `change` (so rate/qty/availability populate via the existing handlers), then auto-adds to cart if "Auto-add after scan" is checked (default on). Out-of-stock guard matches Legacy `selectProductCreate`. After auto-add the field is cleared and refocused for the next scan. No backend changes — purely additive UI. Fixes audit risk §6.1 item #1 (barcode scanning). See `REMEDIATION_LOG.md` §R10 for full diff. |
| R11 | Port multi-customer cart tabs (`#draft-tabs` dock with per-tab item-count badges) | ✅ done      | New `GET /admin/sales/cart/list-drafts` endpoint (mirrors Legacy `sales/list_draft_carts`) backed by `SalesCartService::listCarts()` — returns all non-empty carts for the current user + session branch, sorted by item_count DESC then updated_at DESC, with customer-name + mobile + subtotal aggregation. New `#draftTabsCard` dock in `cart.blade.php` (above the customer selector) renders one Bootstrap nav-pill per cart with shop_name/mobile label, item-count badge (bg-secondary when 0, bg-primary when >0), and a × close button. On page load, `restoreSessionCarts()` fetches list-drafts + renders one pill per cart + activates the busiest (or the `?customer_id=` one if present). Clicking a pill switches carts in-page by setting `#customerSelect` and triggering `change` (no page reload). The × button shows a SweetAlert confirm, calls the existing `/cart/clear` endpoint (which writes the R4 audit-log entry), then removes the pill and switches to the next remaining tab. Badges update on every successful `add`/`update`/`remove`/`clear` mutation by reading the response payload — no extra round-trip. Fixes audit risk §6.1 item #2 (multi-customer cart tabs). See `REMEDIATION_LOG.md` §R11 for full diff. |
| R12 | Port live customer/product typeahead (debounced AJAX)                      | ✅ done (via R1) | Same root cause as R1. R1 already wired both Select2 widgets into AJAX mode (`minimumInputLength: 1`, `delay: 250`, `processResults` populating `customerCache` + `productCache`). Select2 AJAX mode *is* a debounced AJAX typeahead — no separate typeahead library was introduced. R12 was the audit-tracking label; the implementation was the R1 work. |
| R13 | Port price-range slider band UI (`#priceRangePanel`)                        | ✅ done      | New `#priceRangePanel` dock in `cart.blade.php` (inside Add Product card, below the rate input) renders a visual band: grey track + green→purple gradient fill (current rate position) + indigo default-rate mark + circular thumb that follows `#addRate` on every keystroke (60 ms debounce). Min/Max/Default labels in ৳ above the band; status badge below flips `bg-success` / `bg-warning` (within 10 % of min) / `bg-danger` (out of range). "Use default" button snaps rate back to `default_rate`. Reads from `productCache` (R1 live search + R10 barcode scan) — no extra round-trip. Band auto-hides when the selected product has no usable range (`min_rate ≤ 0` or `max_rate ≤ 0`). Purely informational; server-side rate validation in `SalesCartService::validateCartItems` + finalize flow is still authoritative. New JS: `setActivePriceRange()`, `rateRangeStatus()`, `updatePriceBandUi()` + new state field `activePriceRange`. Fixes audit risk §6.1 item #5 (price-range slider). See `REMEDIATION_LOG.md` §R13 for full diff. |
| R14 | Port live credit-limit display on cart page                                 | ✅ done      | New backend endpoint `GET /admin/sales/cart/customer-details?customer_id=…` (throttle 60/min) + new `SalesCartService::getCustomerDetails()` method compute `current_due = SUM(debit) − SUM(credit)` from `customer_ledger WHERE is_reversed = false` (same formula as `SalesInvoiceService::checkCreditLimit`). New `#customerDetailsPanel` in the customer selector card shows 4 stat cells (Credit limit / Current due / Balance left / Cart subtotal) + a projected new balance row (`current_due + cart subtotal`) with colour-coded status: `bg-success` (OK), `bg-warning` (Tight — within 10 % of limit), `bg-danger` (Will breach — finalize will require override), or "No limit set" when `credit_limit = 0`. Snapshot fetched once per customer change (and on explicit "Refresh" button click); projected row recomputes locally on every cart mutation (add/update/remove/clear) using the cached snapshot — no extra round-trip per mutation. New JS: `fetchCustomerDetails()`, `renderCustomerDetails()` + new state field `customerCredit`. New route `admin.sales.cart.customer-details`. Fixes audit risk §6.1 item #6 (live credit-limit display). See `REMEDIATION_LOG.md` §R14 for full diff. |
| R15 | Port customer recents chips (localStorage)                                   | ✅ done      | New `#customerRecentsRow` + `#customerRecents` block in the customer selector card (below the Select2, above the credit panel). New JS functions `rememberCustomerRecent(id, label)` + `loadCustomerRecents()` + `renderCustomerRecents()` mirror Legacy `sales.js::rememberCustomerRecent` + `renderCustomerRecents`. localStorage key `rcerp_sales_customer_recents` holds `[{id, label, ts}, ...]` capped at 5, deduped by id, most-recent-first. On every `#customerSelect` change, the picked customer is unshifted to the top of the list and the chips are re-rendered. Clicking a chip calls the R11 `switchToCustomer(id)` flow (Select2 value + tab ensure + cart load + credit fetch) — no extra endpoint needed. Chip labels prefer the in-memory `customerCache` (richer — includes shop_name + mobile) over the stored label. Storage failures (private mode, quota) are caught + warned — non-fatal. The server-rendered pre-selected customer is also remembered on first page load. Fixes audit risk §6.1 item #7 (customer recents chips). |
| R16 | Port sticky bottom bar (item count + grand total + Finalize always visible)  | ✅ done      | New `#posStickyBar` fixed-position bottom bar in `cart.blade.php` (outside the container, before `@endsection`). New `@push('css')` block scoped to the cart page: `position: fixed; bottom: 0; left:0; right:0; z-index: 1040; background:#fff; border-top: 1px solid #dee2e6; box-shadow: 0 -4px 16px rgba(0,0,0,.08); padding respects env(safe-area-inset-bottom)`. Body gets a `pos-sticky-visible` class while the bar is visible so the page padding-bottom (5.5rem) keeps the last cart row uncovered even on browsers without `:has()`. New JS `updatePosStickyBar()` called from `renderAll()` on every cart mutation: shows item count + subtotal, enables `#posStickyFinalize` iff cart is valid (mirrors `#btnFinalize` disabled logic). Clicking `#posStickyFinalize` calls the same `finalizeInvoice()` function — same idempotency-token + credit-check flow. Bar hides when cart is empty or no customer is selected. Fixes audit risk §6.1 item #9 (sticky bottom bar). |
| R17 | Port mobile-cart cards with swipe-to-delete                                  | ✅ done      | Cart items now render in TWO views: a desktop `<tbody>` (existing, wrapped in `.sales-cart-desktop`) and a new `#cartItemsMobile` div of `.sales-cart-line` cards (Legacy-style: title + delete button + line meta + rate input + qty input side-by-side with 44px-min tap targets). CSS media query (max-width: 767.98px) toggles which view is visible. Both views share the same `.cart-qty`/`.cart-rate`/`.cart-remove`/`.cart-total` classes, so the existing delegated handlers work for both — no duplicated logic. The `debouncedUpdate(productId)` helper was generalized from `$('#cartItemsBody tr[data-product-id="X"]')` to `$('[data-product-id="X"]').first()` so it reads from whichever view is currently visible. New JS `initCartSwipeRemove()` is re-bound after every `renderCartTable()` call: uses modern Pointer Events (covers touch + pen, ignores mouse) — records `pointerdown` X, on `pointermove` translates the card left (clamped to -120px) with a `.swiping` CSS class for visual feedback, on `pointerup` if delta < -80px and elapsed < 600ms triggers the `.cart-remove` button's click handler. A red `::before` pseudo-element with a trash icon is revealed behind the card during swipe. Fixes audit risk §6.1 item #10 (mobile-cart cards with swipe-to-delete). |
| R10s | Barcode scanning simplified — single product search box                     | ✅ done      | R10's dual-mode UI (separate `#barcodeInput` toggle + "Scan & Add" button + auto-add checkbox) was REMOVED because it duplicated the Select2 search box. The single `#addProduct` Select2 now doubles as the barcode entry via two layers: (1) `selectOnClose: true` makes scanner Enter pick the highlighted first AJAX result (most scans match by `product_code` ILIKE in the R1 search endpoint); (2) a delegated `keydown` handler on `.select2-search__field` falls back to the R1 `productByCode` endpoint for an exact-code lookup when no result is highlighted. New `lookupProductByCodeAndSelect(code)` function injects the matched product as a fresh `<option>` + triggers `change` (so rate/qty/price-band/availability populate via existing handlers) + focuses `#addQty`. Same backend (R1's `SalesCartController::productByCode` + `findProductByExactCode`) — purely a UI simplification, no backend/route/migration changes. The user's brief: "keep only product search just like customer search, no need 2 option searching product and scan, just keep search product and make the UI/UX better like lagachy." |
| R18 | Port keyboard shortcuts (Enter to select, ArrowUp/ArrowDown)                  | ✅ done      | Mirrors Legacy `sales-create.js` keyboard flow (`selectProductCreate` L96–100, L615–621): (a) Select2's built-in ArrowUp/ArrowDown/Enter already covers suggestion-list navigation that Legacy implemented manually — no extra JS needed. (b) R10s already added Enter-on-empty fallback → `productByCode` endpoint for exact-code lookup. (c) New R18 flow: after a product is picked (Select2 `change`), `#addQty` is auto-focused + content-selected so the cashier can immediately type a new qty (or press Enter to accept default of 1). (d) Enter on `#addQty` now focuses + selects `#addRate` (NOT submit) — matches Legacy's two-step confirmation pattern so the cashier can review/override the rate before adding to cart. (e) Enter on `#addRate` calls `addToCart()`. (f) After a successful add, `#addProduct` Select2 is re-opened (`select2('open')`) so the cashier can immediately scan/type the next product without reaching for the mouse. Closes the keyboard-only POS operation gap — a cashier with a barcode scanner + numeric keypad can now run a full session without touching the mouse. Fixes audit risk §6.1 item #11 (keyboard shortcuts). |
| R19 | Port inline receive-payment modal on Today's Sales / sales-invoices index     | ✅ done      | New backend endpoint `GET /admin/sales-invoices/{id}/receive-modal` (mirrors Legacy `sales/receive_modal/{id}`) returns a Blade partial `_receive_modal_body.blade.php` with: invoice summary (3 stat cells: invoice total / paid so far / balance due), payment form (amount with quick-amount chips [25%/50%/Full due/Clear], payment mode radio [Cash/Bank/Mobile/Cheque], conditional bank+reference panel, notes), and a "Payments on this invoice" history list with print-receipt buttons. Form posts to the existing `admin.customer-payments.store` route — no new write endpoint created (R2 idempotency-token flow protects against double-submit; fresh UUID generated server-side on every modal open). New `SalesInvoice::allocations()` HasMany relationship added (uses existing `InvoicePaymentAllocation` model). Frontend in `admin/sales-invoices/index.blade.php`: each row with `due_amount > 0.01 && status !== 'cancelled' && !is_reversed` gets a green "Receive payment" button; clicking fetches the modal body via AJAX and injects into a single reusable `#receivePaymentModal` shell. Submit does a traditional form POST so the store endpoint's redirect to `customer-payments.show` works normally — no SPA-style response handling needed. Over-payment scenarios trigger a SweetAlert confirm before submit. Fixes audit risk §6.1 item #12 (inline receive-payment modal) + item #13 (quick-amount chips — implemented as part of this modal). |
| R20 | Port quick-amount chips (50% / Full due / Clear)                              | ✅ done (via R19) | Implemented as part of the R19 receive-payment modal — no separate work. Four chips appear below the amount input: 25% (quarter), 50% (half), Full due, Clear. Each computes against the current `balance` data attribute and triggers `input` on the field so the validation hint re-renders. Mirrors Legacy `receive_modal.php` L110–114. Fixes audit risk §6.1 item #13. |
| R21 | Port server-side DataTables with smart sort + smart search on sales-invoices index | ✅ done      | New backend endpoint `GET /admin/sales-invoices/datatable` returns DataTables SSP JSON (draw / recordsTotal / recordsFiltered / data). New `SalesInvoiceController::datatable()` method (~85 lines) builds a filter query via shared `buildInvoiceFilterQuery()` helper, applies DataTables column ordering OR smart sort OR default ordering, paginates via skip/take, and returns row data (id, invoice_code, invoice_date, customer_name, customer_code, branch_name, items_count, total_amount, paid_amount, due_amount, status, is_soft_hold, is_reversed, show_receive bool, show_url). Smart sort: when `#filterSmartSort` is checked AND no column header clicked, server applies `CASE WHEN due_amount > 0.01 AND status NOT IN ('cancelled','reversed') THEN 0 ELSE 1 END ASC, invoice_date ASC, id ASC` — unpaid first, then oldest. Column-click sort overrides smart sort. Smart search matches invoice_code + customer name/code/mobile + branch name/code (ILIKE). The index blade was rewritten: replaced the Blade `@forelse` tbody + Laravel paginator with a server-side DataTables instance. Filter form (date / customer / branch / search / smart_sort / status_chip) is injected into every AJAX request via the `data` callback — page never reloads on filter change. Smart search input is debounced 320ms. Fixes audit risk §6.1 item #14. |
| R22 | Port status chips with live counts on sales-invoices index                    | ✅ done      | New backend endpoint `GET /admin/sales-invoices/summary` returns JSON with counts per chip bucket (all, awaiting_payment, draft, confirmed, cancelled, reversed) + total_value. New `SalesInvoiceController::summary()` method (~30 lines) uses shared `buildInvoiceFilterQuery($request, excludeStatusChip: true)` so counts are computed against the current filter set (date / customer / branch / search) but NOT against the active chip itself — so the user always sees how many invoices are in each bucket without losing filter context. Six chips (All / Awaiting payment / Draft / Confirmed / Cancelled / Reversed) replace the old Status `<select>` dropdown. Each chip has a count badge refreshed via AJAX (debounced 280ms) whenever filters change. Clicking a chip sets hidden `#status_chip` input + reloads DataTable + refreshes summary. Chip colours: All=indigo, Awaiting=red, Draft=amber, Confirmed=green, Cancelled=slate, Reversed=dark red. Bucket definitions adapted to Laravel's status model (draft/confirmed/cancelled + is_reversed flag) since Laravel doesn't have Legacy's godown_issued/challan_completed invoice statuses. Shared `buildInvoiceFilterQuery()` private method on the controller keeps chip counts and table rows in sync. Mirrors Legacy `sales/today_filter_summary` endpoint. Fixes audit risk §6.1 item #15. |
| R23 | Port mobile cards variant for Today's Sales / sales-invoices index           | ✅ done      | New `#invoiceCards` container above the desktop table in `admin/sales-invoices/index.blade.php`, hidden on desktop by CSS `@media (max-width: 767.98px)` and shown on narrow screens. Populated by DataTables `drawCallback` → `renderMobileCards(api)` from the current page's data — same data as the desktop table, just a different layout. Each card shows: invoice code (link) + date + customer name + branch name + status badge + total + due/Paid + soft-hold badge + View/Receive buttons. Card left border color signals status: red=due, green=paid, slate=cancelled, dark red=reversed. Window resize handler (debounced 180ms) re-renders cards on viewport changes. The delegated `.btn-receive-payment` click handler (from R19) works for both desktop table rows AND mobile card buttons (same class) — no duplicate wiring needed. Mirrors Legacy `sales-today-index.js::renderInvoiceCards`. Fixes audit risk §6.1 item #16. |
| ~~R24~~ | ~~Port Telegram notifications~~                                       | ❌ **dropped** | **Removed by user request (2026-07-22).** Telegram notifications are NOT being ported. Laravel's native database + broadcast notification system (`ERPNotification` + `NotificationService` + `ListenNotifyService` for PostgreSQL NOTIFY) covers operational visibility without external chat-bot dependency. Migration `2025_01_20_000010_drop_fcm_and_telegram_fields.php` drops the `users.telegram_user_id` column. Stale tests in `tests/Feature/User/*` referencing `telegram_user_id` were cleaned up; `Tests\Helpers\InsertsUserDependencies::makeTelegramUser()` removed. |
| ~~R25~~ | ~~Port FCM push notifications (or SSE via Listen/Notify)~~            | ❌ **dropped** | **Removed by user request (2026-07-22).** FCM push notifications are NOT being ported. The existing in-app inbox (`admin/notifications/inbox` + `ListenNotifyService` realtime fanout) is sufficient. `fcm_tokens` table dropped in migration `2025_01_20_000010_drop_fcm_and_telegram_fields.php`. `laravel/public/assets/js/notification.js` header comment updated to document the deliberate non-implementation. |
| R26 | Add `min:10` to `override_reason` in `FinalizeInvoiceRequest`               | ✅ done      | Validation rule is now `nullable\|string\|min:10\|max:500` in three places: (a) `app/Http/Requests/Api/V1/Sales/FinalizeInvoiceRequest.php` (mobile API), (b) `app/Http/Controllers/Admin/SalesInvoiceController::store()` validate call (web finalize), (c) `app/Http/Controllers/Admin/SalesInvoiceController::update()` validate call (web edit). Mirrors Legacy `SalesInvoiceOperationsTrait::finalizeInvoice()` runtime check `if (strlen($overrideReason) < 10) { return error; }`. The service-layer `strlen($overrideReason) < 10` re-check inside the DB transaction (R5 authoritative re-check) is unchanged — now the request fails fast at validation instead of after the credit-limit check. |
| R27 | Add `min:5` to reversal reason in payment cancel                          | ✅ done      | Two controller `validate()` calls tightened: (a) `app/Http/Controllers/Admin/CustomerPaymentController::cancel()` web controller — `cancel_reason` rule changed from `required\|string\|max:500` → `required\|string\|min:5\|max:500`. (b) `app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController::cancel()` API controller — `reason` rule changed from `required\|string\|min:10\|max:500` → `required\|string\|min:5\|max:500` (relaxed to match Legacy exactly). Mirrors Legacy `SalesPaymentOperationsTrait::reverseCustomerPayment()` runtime check `if (strlen($reason) < 5) { return error; }`. The service-layer `CustomerPaymentService::cancelPayment` runs unchanged. |
| R28 | Add PWA installability meta tags to cart blade                            | ✅ done      | New `@stack('head_meta')` added to `layouts/admin.blade.php` `<head>` (after the existing meta tags). Cart blade pushes PWA meta via `@push('head_meta')`: manifest link, favicon, apple-touch-icon, theme-color (#4f46e5), application-name, mobile-web-app-capable, apple-mobile-web-app-capable, apple-mobile-web-app-status-bar-style, apple-mobile-web-app-title, msapplication-TileColor, msapplication-tap-highlight. New `laravel/public/manifest.json` (name=RC ERP — Sales Cart, short_name=RC POS, start_url=/admin/sales/cart, scope=/admin/sales/, display=standalone, theme_color=#4f46e5, background_color=#ffffff, icons SVG 192+512 maskable+any, 2 shortcuts to Today's Sales + Customer Payments). New `laravel/public/sw.js` minimal service worker (cache version `rc-erp-pos-v1`, pre-caches 17 offline-shell assets on install, cleans old caches on activate, fetch handler: cache-first for /assets/* + /manifest.json, network-first with cart-shell fallback for HTML navigations, pass-through for everything else including all non-GET). New `laravel/public/assets/images/icon.svg` POS-themed SVG icon (shopping cart on indigo→purple gradient with RC badge, 512×512, maskable-safe). SW registration snippet added to cart blade `@push('scripts')` (feature-detected via `'serviceWorker' in navigator` + `window.isSecureContext`, registered only on HTTPS/localhost, non-fatal on failure). Chrome/Edge now shows the "Install app" prompt on the cart page. Fixes audit risk §6.1 item #33. |

> When the user assigns the next R# item, add a row here and create a
> matching section in `REMEDIATION_LOG.md`. **Do not start work without
> updating both tables.**

---

## 4. Conventions & Rules (binding on all agents)

1. **No analysis-only mode any more.** The audit phase is over. From
   R1 onward, we are modifying code.
2. **Every code change MUST be committed with a descriptive message
   and pushed to GitHub** at the end of each R# item — the user wants
   the work to survive even if Z.ai loses the conversation.
3. **Every R# item MUST update three docs:**
   - `docs/SESSION_CONTEXT.md` (this file — update the backlog table)
   - `docs/REMEDIATION_LOG.md` (add a new section with diff summary)
   - `docs/sales_entry_Lg_vs_La.md` (mark the relevant gap as "Fixed by R#")
4. **Do not skip reading the audit report.** The audit lists specific
   risks (e.g. "wrong customer due", "duplicate invoices") that must
   be respected when porting features.
5. **PHP is NOT installed in the Z.ai sandbox.** Use careful manual
   review of braces / closures / use() captures. Do not run `php -l`.
6. **The Laravel app uses PostgreSQL**, not MySQL. Use `ILIKE` for
   case-insensitive `LIKE`. The `Customer` and `Product` models already
   have `scopeSearch()` that uses `tsvector + GIN` full-text search
   with an ILIKE fallback — prefer reusing that scope over writing
   raw SQL.
7. **RBAC is enforced at the route group level** — see
   `laravel/routes/web.php` line ~452 for the
   `role:salesman,manager,admin` + `branch.isolation` middleware on
   the `admin/sales` group. New endpoints inside that group inherit
   the same RBAC automatically.
8. **Rate limiting:** Legacy uses `guardJsonApi('sales.X', 90, 60)`
   (90 req / 60 s). The Laravel equivalent is the `throttle:90,1`
   route middleware. The R1 endpoints already use it.
9. **All file paths MUST be absolute under `/home/z/my-project/`.**
   Scripts go in `/home/z/my-project/scripts/`, deliverables in
   `/home/z/my-project/download/`. The repo itself is at
   `/home/z/my-project/debugRC/`.
10. **Worklog protocol:** every agent (including the main agent when
    it does work directly) appends a new `---` section to
    `/home/z/my-project/worklog.md` with Task ID, Agent name, Task
    description, Work Log, Stage Summary. Do not overwrite existing
    content.

---

## 5. Key Technical Decisions Made in This Session

### 5.1 R1: Live search endpoint design

- **Endpoint shape:** `GET /admin/sales/cart/search-customer?term=…`
  and `GET /admin/sales/cart/search-product?term=…&branch_id=…`.
  These mirror Legacy `sales/search_customer` and `sales/search_product`
  exactly (same query params, same JSON shape) so the JS port is
  trivial.
- **Customer search:** reuses the existing `Customer::scopeSearch()`
  (tsvector + GIN on PostgreSQL, ILIKE fallback on MySQL/SQLite).
  This is BETTER than the Legacy SQL (which uses plain `LIKE` on
  MySQL) — we get ranked full-text search for free.
- **Product search:** ported the Legacy `StockAvailabilityService::
  searchProductsWithStock()` SQL verbatim (correlated subqueries for
  latest `product_price_history` row, branch-level physical stock via
  `warehouse_stock` JOIN `warehouses`, branch-level pipeline via
  `sales_invoice_dispatches` JOIN `sales_invoices`). Used Laravel's
  `leftJoinSub()` for both subqueries so the SQL is identical to
  Legacy but expressed in the query builder.
- **Rate limit:** `throttle:90,1` on both search endpoints, matching
  Legacy's `guardJsonApi('sales.search_customer', 90, 60)`.
- **Barcode scanner endpoint:** `GET /admin/sales/cart/product-by-code?code=…&branch_id=…`,
  rate-limited at 120/min (matches Legacy `sales.product_by_code`).
  Currently no UI uses it — wiring it into the cart page's barcode
  input is a future R# item.

### 5.2 R1: Blade changes

- The customer `<select>` no longer loops `$customers` (500 rows).
  It only renders a single `<option>` for the pre-selected customer
  (so refresh/URL-share preserves the label). All other options are
  fetched on-demand by Select2 AJAX.
- The product `<select>` is now empty initially — Select2 AJAX
  populates it as the user types.
- A new `productCache` JS object (id → full product payload) is
  populated by the AJAX `processResults` callback. This lets the
  `addProduct` change handler read `default_rate`/`min_rate`/`max_rate`
  /`available_qty` without another round-trip, and lets
  `renderAvailability` render the product label even when the
  underlying `<option>` has been evicted by Select2.
- `renderAvailability` now prefers `productCache` over the option
  text, with a graceful fallback to the option (for back-compat with
  any code path that still sets options imperatively).

### 5.3 R2: Payment idempotency token design

- **Scope:** Both the Blade (web) and API V1 payment-create endpoints.
  This mirrors how `finalize` is already protected in both tiers
  (`SalesInvoiceController::finalize` web, `SalesInvoiceApiController::store` API).
- **Web — `POST /admin/customer-payments`:**
  - Validation rule added to inline `$request->validate()`:
    `'idempotency_token' => 'required|string|uuid'`.
  - Cache key: `'payment:' . $token` (mirrors `'finalize:' . $token`).
  - Cache TTL: **600 seconds (10 min)** — same as finalize web.
  - Cache value: `['payment_id', 'payment_code', 'success_message']`.
  - On replay: redirects to `admin.customer-payments.show` with the
    original `success` flash AND an additional `warning` flash reading
    "Duplicate submission detected — returning the original result.
    No new payment was created." The `layouts.admin` template already
    renders both flashes, so no view changes were needed.
  - UUID is generated **server-side** via `\Illuminate\Support\Str::uuid()`
    on first page render, and preserved across validation failures via
    `old('idempotency_token', ...)`. This is intentionally different from
    the finalize pattern (which generates the UUID client-side in JS),
    because the payment form is a regular POST-redirect form, not an
    AJAX flow — server-side generation is more robust to no-JS and
    validation-failure re-renders.
- **API — `POST /api/v1/sales/payments`:**
  - Validation rule added to `StorePaymentRequest::rules()`:
    `'idempotency_token' => 'required|string|uuid'`.
  - Cache key: `'api:payment:' . $token` (mirrors `'api:finalize:' . $token`).
  - Cache TTL: **5 min** (`now()->addMinutes(5)`) — same as finalize API.
  - Cache value: the full JSON response payload (`message`, `data`, `confirmed`).
  - On replay: returns the cached payload with `idempotent_replay: true`
    and a replay `message` added.
  - The idempotency check runs BEFORE `assertBranchAccessible()` so a
    replay is fully side-effect-free (no DB reads, no locks).
- **Why the cache-key prefix differs between web and API:** to keep the
  web and API idempotency windows independent (a web submit and an API
  submit with the same UUID do not collide). This matches the existing
  finalize convention.
- **What was NOT changed:** `CustomerPaymentService` was not touched.
  The idempotency layer is a controller concern — the service remains
  free of cache coupling. The `confirm` and `cancel` endpoints were not
  given idempotency tokens (out of scope for R2; the user may want it
  for R3 or later).

### 5.4 R3: Challan issue idempotency token design

- **Scope:** Both the Blade (web) and API V1 challan-issue endpoints.
  Mirrors how `finalize` (R-finalize / P2-6) and `payment-create` (R2)
  are already protected in both tiers.
- **Web — `POST /admin/sales-challans/issue/{invoiceId}`:**
  - Validation rule added to inline `$request->validate()` in
    `SalesChallanController::issueChallan()`:
    `'idempotency_token' => 'required|string|uuid'`.
  - Cache key: `'challan:' . $token` (mirrors `'finalize:' . $token`
    and `'payment:' . $token`).
  - Cache TTL: **600 seconds (10 min)** — same as finalize + payment web.
  - Cache value: `['challan_id', 'challan_code', 'success_message']`.
  - On replay: redirects to `admin.sales-challans.show` with the
    original `success` flash AND an additional `warning` flash reading
    "Duplicate submission detected — returning the original result.
    No new challan was created."
  - UUID generated **server-side** via `\Illuminate\Support\Str::uuid()`
    in `issue.blade.php`, preserved across validation failures via
    `old('idempotency_token', ...)`. Same approach as R2's payment form.
  - Important: this changes the **user-visible behavior on a double-
    submit**. Before R3, a double-submit would: (1) succeed on the
    first request, (2) throw "Challan already issued for this invoice"
    on the second request, showing an error flash to the user. After
    R3, the second request silently redirects to the already-issued
    challan with a warning flash — much friendlier UX, and matches
    how finalize + payment already behave.
- **API — `POST /api/v1/sales/challans/issue`:**
  - Validation rule added to `IssueChallanRequest::rules()`:
    `'idempotency_token' => 'required|string|uuid'`.
  - Cache key: `'api:challan:' . $token` (mirrors `'api:finalize:' . $token`
    and `'api:payment:' . $token`).
  - Cache TTL: **5 min** (`now()->addMinutes(5)`) — same as finalize + payment API.
  - Cache value: the full JSON response payload (`message`, `data`).
  - On replay: returns the cached payload with `idempotent_replay: true`
    and a replay `message` added.
  - The idempotency check runs BEFORE `SalesInvoice::findOrFail()` and
    `assertBranchAccessible()` so a replay is fully side-effect-free.
- **Service-level mitigation that R3 wraps:** `SalesChallanService::issueChallan()`
  already had a defense — it locks the invoice `FOR UPDATE` and throws
  `RuntimeException("Challan already issued for this invoice.")` if
  `is_challan_issued` is true. So concurrent calls would serialize
  (one succeeds, one fails) even without R3. R3 doesn't replace this
  guard; it sits in front of it as a friendlier fast-path for the
  common double-submit case (where the first request already
  succeeded and the user is just refreshing/retrying).
- **What was NOT changed:**
  - `SalesChallanService` was not touched. The idempotency layer is a
    controller concern.
  - The `godown` (step 1) endpoint was not given an idempotency token.
    Godown prep is idempotent by accident — the same warehouse
    assignments submitted twice would `sync()` to the same end state
    (no duplicate `sales_invoice_dispatches` rows). Out of scope for R3.
  - The challan `cancel` endpoint was not given an idempotency token.
    Cancel has a `cancel_reason` guard and reverses a known existing
    challan, so duplicate-cancellation fails with "challan is already
    reversed." Low priority.

### 5.6 R5: Customer row lock before credit-limit check

**Problem:** `SalesInvoiceService::checkCreditLimit` was called
BEFORE the DB transaction opened — so two concurrent finalize
(or edit) calls for the same customer could both read the same
`customer_ledger` SUM, both pass the credit-limit check, and both
post debits — pushing the customer over the limit. Audit risk V5
(Medium), common risk C1 (Medium).

**Decision: check TWICE — fast-fail UX check + authoritative
in-transaction check.**

The original pre-transaction `checkCreditLimit` call is kept as
a fast-fail UX gate: it gives the user immediate feedback
("credit limit exceeded, override?") without opening a transaction.
This check is **non-authoritative** — it can pass and the
transaction can still fail if a concurrent finalize posts the
debit first.

The new authoritative check lives inside the transaction via a
new private helper:

```php
private function assertCreditLimitUnderLock(
    int $customerId, float $amount,
    bool $isOverride, string $overrideReason,
    string $messageTpl
): void {
    $customer = Customer::lockForUpdate()->find($customerId);
    if (!$customer) { throw new \RuntimeException(...); }
    $creditCheck = $this->checkCreditLimit($customerId, $amount);
    if (!$creditCheck['exceeds']) return;
    if ($isOverride && strlen($overrideReason) >= 10) return;
    if ($isOverride && strlen($overrideReason) < 10) {
        throw new \RuntimeException('Override reason must be at least 10 characters...');
    }
    throw new \RuntimeException(sprintf($messageTpl, ...));
}
```

This is called as the **first thing** inside `DB::transaction()`
in BOTH `finalizeFromCart` (line ~144) and `updateInvoice`
(line ~496). Concurrent finalize/edit calls for the same customer
will block on `Customer::lockForUpdate()` until the first
transaction commits/rolls back.

**Why lock the customer row, not the customer_ledger row?**

The `customer_ledger` table can have many rows per customer (one
per transaction). Locking all of them would be expensive. Locking
just the latest row doesn't help (a new ledger entry is what
changes the SUM). Locking the `customers` row is the natural
serialization point — it's a single row, and the credit-limit
check is conceptually about the customer as a whole.

**Why keep the pre-transaction check?**

Removing it would mean every credit-limit-exceeded attempt opens
a transaction, locks the customer row, runs the SUM query, throws,
and rolls back — wasted work + holds the lock briefly. The
pre-transaction check short-circuits the common case (user clearly
over the limit, no override) before any of that happens.

**One extra `checkCreditLimit` call per finalize/edit:** the cost
is a single `SELECT SUM(debit) - SUM(credit) FROM customer_ledger
WHERE customer_id = ? AND is_reversed = false` — negligible
(~1ms with the existing indexes).

**Override semantics:** the in-transaction check honors the same
override (`credit_limit_override=true` + `override_reason` >= 10
chars) as the pre-transaction check. If the user supplies a valid
override, the in-transaction check returns successfully even if
the limit is exceeded.

### 5.7 H1: `customer_payments.status` column bugfix

**Problem reported by user (2026-07-21):**

> SQLSTATE[42703]: Undefined column: 7 ERROR: column "status" does
> not exist ... SQL: select sum("amount") as aggregate from
> "customer_payments" where "customer_id" = 454 and "is_reversed"
> = 0 and "status" not in (cancelled) ...

**Root cause:** `CustomerController::show` (the customer "360°
Hub" page) had three KPI queries that filtered on a `status`
column that doesn't exist on `customer_payments` (and is a
misleading no-op on `sales_returns`):

1. `CustomerPayment::...->whereNotIn('status', ['cancelled'])` —
   `customer_payments` has NO `status` column. Schema is `is_reversed`
   boolean flag only. **This was the user's reported error.**
2. `CustomerPayment::...->whereNotIn('status', ['cancelled'])` —
   same as #1, on the `lastPayment` query.
3. `SalesReturn::...->whereNotIn('status', ['cancelled'])` —
   `sales_returns` DOES have a `status` column, but its CHECK
   constraint only allows `('created','confirmed','reversed')`.
   `'cancelled'` is not a valid value, so this filter was a no-op
   (filtered out nothing) but didn't error.

**Fix:**

- Removed all three `->whereNotIn('status', ...)` filters from
  `CustomerController::show`. The `is_reversed = false` predicate
  already excludes reversed/cancelled rows.
- Removed the dead `isDraft()` / `isConfirmed()` / `isCancelled()`
  methods from `CustomerPayment` model — they referenced the
  non-existent `status` column and were never called anywhere
  in the codebase (verified by grep).
- Updated the `CustomerPayment` class docblock to clarify that
  the table has no `status` column.

**Why this was caught only now:** the `show` page is hit when
viewing an existing customer. New customers with no payments
also hit the queries (returning 0/empty), so the bug surfaced
when viewing ANY customer — including during new-customer setup
flows that redirect to the show page.

**Similar bug class to watch for:** other Laravel controllers
may have copy-pasted `->whereNotIn('status', ['cancelled'])`
filters onto tables that don't have a `status` column. A grep
for this pattern on `CustomerPayment` queries was done — only
the 2 instances in `CustomerController::show` were affected.

### 5.8 What was NOT changed (deliberately)

- The Legacy sales entry code (`legacy/`) was not touched. Legacy
  is still the production system; the audit is a parallel analysis.
- The Laravel `SalesInvoiceService`, `SalesChallanService`, and
  `CustomerPaymentService` were not touched.
  R1 was a UI/search concern; R2 + R3 are controller/validation
  concerns; R4 only touches `SalesCartService` + `SalesAuditLogger`.
- The Laravel API V1 controllers OTHER than `CustomerPaymentApiController`
  and `SalesChallanApiController` were not touched. R4 specifically
  did NOT need to touch `SalesCartApiController` — the audit calls
  live in the shared `SalesCartService`, so the API path is covered
  transparently.
- The `confirm` / `cancel` / `godown` / `cancelChallan` endpoints were
  not given idempotency tokens. They either have natural guards
  (`is_reversed` / `is_challan_issued` status checks) or are
  idempotent by accident (`sync()` semantics). Out of scope.
- R4 does NOT add cart mutation idempotency tokens. Cart mutations
  are not financially material (no GL / ledger / stock movement
  until finalize), and the user has not requested it. Audit logging
  is the right pattern here, not idempotency.
- The audit rows are written AFTER `$cart->save()` in the same
  logical operation. They are NOT wrapped in an explicit
  `DB::transaction()`. If the audit insert fails after a successful
  cart save, there will be a cart mutation without an audit row.
  This is an acceptable trade-off — `UserAuditLogger::log()` is a
  single INSERT and unlikely to fail unless the DB is down, in which
  case the user's request errors out anyway. Wrapping in a
  transaction would be a bigger refactor and was deferred.

### 5.9 R6: branch_id in sales_draft_carts unique key

**Problem:** the `sales_draft_carts` unique constraint was
`UNIQUE (user_id, customer_id)` — `branch_id` was stored on the row
but NOT part of the key. A salesman switching branches with the same
customer would share the SAME cart row, leading to cross-branch
stock reservation contamination (the cart's items could reference
stock from the wrong branch). Audit risks V11 (Laravel) and C7
(common to both systems).

**Decision: extend the unique key to 3 columns + align the column
with Legacy semantics.**

The new constraint is `UNIQUE (user_id, customer_id, branch_id)`.
A salesman switching branches now gets a separate cart per branch —
matching Legacy semantics (Legacy's `020_sales_draft_carts.sql`
declares `branch_id INT NOT NULL DEFAULT 0`).

**Why drop the FK on `branch_id`?**

The original Laravel `04_sales.sql` declared `branch_id integer
REFERENCES branches(id)` (nullable, with FK). Legacy semantics use
`branch_id = 0` as a "no specific branch" sentinel — there is no
`branches(0)` row. Keeping the FK would block the backfill and
break the sentinel convention. Legacy doesn't enforce this FK
either, so dropping it is a Legacy parity fix, not a regression.
The `idx_sdc_branch` index on `branch_id` is kept for query
performance.

**Why `NOT NULL DEFAULT 0`?**

PostgreSQL `UNIQUE` constraints treat NULL as distinct — two rows
with `(user_id=5, customer_id=10, branch_id=NULL)` would NOT
conflict, defeating the purpose of the unique key. Making the
column `NOT NULL DEFAULT 0` ensures the unique constraint works
correctly. `0` is the pre-existing Legacy convention for "no
specific branch".

**Migration safety:**

1. Drop the FK on `branch_id` (looked up dynamically from
   `pg_constraint` — the name is auto-generated as
   `sales_draft_carts_branch_id_foreign` but we don't hardcode it).
2. Backfill `NULL → 0` (in practice a no-op — the Laravel app
   has always passed a non-null `branch_id` via
   `session('branch_id', 0)`).
3. `ALTER COLUMN branch_id SET NOT NULL` + `SET DEFAULT 0`.
4. Drop old `uq_sales_draft_user_customer`.
5. Add new `uq_sales_draft_user_customer_branch`.

All DDL is wrapped in a single transaction so a failure at any
step rolls back the entire migration.

**Code changes outside the migration:**

- `SalesDraftCart::getOrCreate()` updated to include `branch_id`
  in the `firstOrCreate` search attributes and normalize `null → 0`.
  Without this, `firstOrCreate` would search by `(user_id,
  customer_id)` only and return the wrong cart (or create a new
  empty cart at `branch_id=null`, which would now fail the NOT NULL
  constraint).
- `SalesCartService::setSoftHold()` signature changed to accept
  `?int $branchId = null` (was previously not a parameter). The
  null is normalized to 0 inside `getOrCreate`.
- All `clearCart()` and `setSoftHold()` callers updated to pass
  `branch_id` explicitly:
  - `Admin\SalesCartController::clear()` — passes `session('branch_id', 0)`
  - `Admin\SalesCartController::softHold()` — passes `session('branch_id', 0)`
  - `Api\V1\Sales\SalesCartApiController::clear()` — passes `$this->resolveBranchId($request)`
  - `Api\V1\Sales\SalesCartApiController::softHold()` — passes `$this->resolveBranchId($request)`
  - `SalesInvoiceService::finalizeFromCart()` — passes `$branchId`
    (was previously omitted, which was a latent bug — clearCart
    would have created a new empty cart at `branch_id=0` and left
    the actual cart untouched. R6 fixes this as a side effect.)

**Why this fixes a latent clearCart bug:**

Before R6, `SalesInvoiceService::finalizeFromCart` called
`$this->cartService->clearCart($userId, $customerId)` without
passing `branch_id`. The cart's `getOrCreate` searched by
`(user_id, customer_id)` only and matched the actual cart (which
had a real `branch_id` like 5) — so `clearCart` accidentally
worked, because the 2-column unique key made the search
branch-agnostic. After R6, the search is 3-column, so omitting
`branch_id` would normalize to 0 and target a non-existent cart
at `branch_id=0` — leaving the actual cart un-cleared (a bug).
R6 fixes this by passing `$branchId` explicitly.

**What was NOT changed:**

- The Legacy sales entry code (`legacy/`) was not touched. The
  Legacy schema still has the 2-column unique key. Legacy uses
  `$_SESSION`-keyed carts as primary storage (with DB as optional
  backup gated by `SALES_DB_DRAFT_CARTS`), so the cross-branch
  contamination risk is lower in practice on Legacy.
- The `customers` table was not touched. The unique key change is
  isolated to `sales_draft_carts`.
- The `sales_invoice_dispatches` / `sales_invoice_items` tables
  were not touched. They already have `branch_id` as part of their
  business keys (via `sales_invoices.branch_id`).

### 5.10 R10: Barcode scanning UI for the cart blade

**Problem:** R1 ported the Legacy `sales/product_by_code` endpoint to
Laravel (`SalesCartController::productByCode` →
`admin.sales.cart.product-by-code`) but left it without a UI consumer.
The cart blade still had no way for a cashier with a USB HID barcode
scanner to scan a product into the cart — they had to use the Select2
search dropdown, which is fine for typing names but slow for POS-style
scanning where each scan should add an item in one keystroke.

Audit risk: §6.1 item #1 in `sales_entry_Lg_vs_La.md`.

**Decision: add a dedicated barcode input field, collapsed by default,
with an Enter-key + button handler that calls the existing R1 endpoint.**

Why a dedicated input rather than reusing the Select2 search box?
Select2 captures keyboard input inside its own search field and
manages the dropdown lifecycle internally. There is no clean way to
intercept Enter on the Select2 search field, run an exact-code
lookup, and inject a synthesized option. A separate input is simpler,
more predictable, and matches the Legacy UX of having a dedicated scan
box.

Why collapsed by default? Most users don't have a barcode scanner —
they pick products via Select2 search. Showing a permanently-visible
scan box would add visual noise. The "Barcode" toggle button in the
card header makes the feature discoverable without forcing it on
everyone.

**The flow (mirrors Legacy `fetchSalesProductByExactCode` +
`selectProductCreate` in `sales-create.js` L324–381):**

1. User clicks the "Barcode" button in the Add Product card header →
   `#barcodeRow` is revealed and `#barcodeInput` is focused.
2. User scans (or types) a product code and presses Enter (or clicks
   "Scan & Add").
3. The `scanAndSelect()` async function:
   - Trims the input; bails if empty or if no customer is selected.
   - Sets a "Looking up…" hint and disables the input.
   - Fetches `ENDPOINTS.productByCode?code=…&branch_id=…`.
   - On network error: shows the error, re-enables + refocuses.
   - On `status !== 'success'`: shows "No product with code X",
     toasts a warning, refocuses + selects the input.
   - On success:
     - Caches the product in `productCache[p.id]` (so the existing
       change handler / availability renderer can see it without
       a re-fetch).
     - **Stock guard** (mirrors Legacy): if `available_qty <= 0`,
       blocks the add with a toast and clears the input.
     - Injects a fresh `<option>` into `#addProduct` and sets its
       value (Select2 AJAX only renders options the user typed for,
       so we synthesize one).
     - Triggers `change` on the Select2 — this reuses the existing
       change handler which fills the rate field, rate hint, and
       fires `checkAvailability`.
     - Pre-fills `#addRate` (default_rate, fall back to min_rate)
       and resets `#addQty` to 1.
     - Sets the hint to "✓ Product Name · avail N · rate ৳X".
     - If "Auto-add after scan" is checked (default on): calls
       `addToCart()` directly, then clears + refocuses the barcode
       input so the cashier can scan the next item without reaching
       for the mouse.
     - If auto-add is off: focuses `#addQty` for editing before the
       manual Add click.

**Files modified:**

- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added "Barcode" toggle button to the Add Product card header.
  - Added `#barcodeRow` (collapsed by default via `d-none`) with
    `#barcodeInput`, `#barcodeHint`, `#btnBarcodeAdd`, and
    `#barcodeAutoAdd` checkbox.
  - Added `scanAndSelect()` async function (~80 lines).
  - Wired three event handlers: `#btnToggleBarcode` click,
    `#barcodeInput` keydown Enter, `#btnBarcodeAdd` click.

**What was NOT changed:**

- The Legacy sales entry code (`legacy/`) was not touched. Legacy's
  barcode scanner is still on the Legacy create/edit pages.
- The Laravel `SalesCartController::productByCode` endpoint was NOT
  changed — R10 only adds a UI consumer for the endpoint that R1
  already added. No new routes, no new DB queries.
- The Laravel API V1 (`SalesCartApiController`) was NOT touched.
  The user's R10 brief explicitly named "cart blade" — the API tier
  is out of scope. If a mobile/AI client wants barcode lookup, the
  existing `GET /api/v1/lookups/products?term=…` already supports
  exact-code matching (LIKE %term% includes exact codes).
- The Laravel sales invoice **edit** page
  (`admin/sales-invoices/{id}/edit`) was NOT touched. Legacy has
  barcode scanning on both create and edit; R10 only covers the
  cart/create flow. Porting to edit is a follow-up.
- No keyboard shortcut (e.g. `Alt+B`) was added to focus the barcode
  input without clicking the toggle. Low priority follow-up.
- No "scan beep" audio feedback was added. Low priority follow-up.

---

### 5.11 R11: Multi-customer cart tabs dock

**Problem:** The Laravel cart blade supported one customer per page — switching customers required a URL change (`?customer_id=…`) or clicking "Load Cart" with a different customer selected. For a high-volume POS cashier ringing up 5–10 customers in parallel (e.g. a busy retail afternoon with several walk-up customers waiting), this is friction: every switch costs a round-trip, the previously-edited cart is hidden behind a URL change, and there's no at-a-glance overview of which carts are open and how many items each has.

The Legacy system solved this with a `#draft-tabs` dock in `sales/create.php` (L144–163) + `sales-create.js::createOrSwitchTab / closeTab / refreshTabBadge / restoreSessionCarts` (L643–803): one pill per customer-cart, item-count badge, × close button, instant in-page switching.

Audit risk: §6.1 item #2 in `sales_entry_Lg_vs_La.md`.

**Decision: add a `#draftTabsCard` dock above the customer selector that mirrors the Legacy UX, backed by a new `list-drafts` endpoint.**

Why a new endpoint instead of reusing `/cart/load`? `load` is per-customer (one cart at a time); we need an enumeration of all open carts for the user + branch. Legacy had a dedicated `sales/list_draft_carts` endpoint for exactly this reason.

Why reuse `/cart/clear` for the close-tab action? It already does the right thing (clears the cart for a (user, customer, branch) tuple and writes the R4 audit-log entry). Adding a separate `clear-tab` endpoint would just duplicate the logic.

**The flow (mirrors Legacy `createOrSwitchTab` + `restoreSessionCarts` + `closeTab`):**

1. **Page load** → `restoreSessionCarts()` fires → `GET /admin/sales/cart/list-drafts` → returns `[{customer_id, label, shop_name, customer_name, mobile, item_count, subtotal, is_soft_hold, updated_at}, …]` sorted by `item_count DESC` then `updated_at DESC`.
2. For each cart: cache the customer metadata in `customerCache` (so the tab label can render without an extra fetch), then call `ensureTab(customerId, {label, itemCount, softHold, active:false})` which appends a `<li class="draft-tab-item">` pill to `#draftTabs`.
3. After all pills are rendered: activate the busiest cart (or the `?customer_id=` one if present in the URL). If state.customerId wasn't already set (no `?customer_id=`), also call `switchToCustomer(busiestCid)` to load its cart + sync the Select2.
4. **User picks a new customer from Select2** → `change` handler calls `ensureTab(cid, {active:true, label})` immediately (pill appears) → `loadCart(cid)` fires (AJAX) → on success, `updateActiveTabBadge(itemCount, {softHold})` refreshes the badge from the response payload.
5. **User clicks a pill body** → `switchToCustomer(cid)` sets `#customerSelect.val(cid)` and triggers `change` (which runs the existing customer-change handler that calls `loadCart`). No page reload. The pill is highlighted as active; the previous pill loses its highlight.
6. **User clicks × on a pill** → SweetAlert confirm → `POST /cart/clear` with that customer_id → on success, `removeTab(customerId)` deletes the `<li>` from the DOM → if the closed tab was active, switch to the next remaining tab (or show the empty state if no tabs remain).
7. **After any cart mutation** (`add` / `update` / `remove` / `clear`) → `updateActiveTabBadge(itemCount, {softHold})` is called inside the response handler — reads the new `items.length` from the response payload and updates the badge. No extra round-trip.
8. **After `clearCart`** (the explicit "Clear Cart" button) → also calls `removeTab(state.customerId)` because the cart is now empty (matches Legacy behavior where empty session slots don't earn a tab).

**Files modified:**

- `laravel/app/Services/Sales/SalesCartService.php`
  - Added `listCarts(int $userId, ?int $branchId): array` method (~80 lines).
  - Queries `sales_draft_carts` for the user (+ optional branch), skips empty carts, looks up each customer's name/mobile from `customers`, computes `item_count` + `subtotal`, sorts by `item_count DESC, updated_at DESC`. Capped at 50 rows for safety.
- `laravel/app/Http/Controllers/Admin/SalesCartController.php`
  - Added `listDrafts(Request $request)` method that calls `listCarts(auth()->id(), session('branch_id', 0))` and returns JSON.
- `laravel/routes/web.php`
  - Added `Route::get('cart/list-drafts', [SalesCartController::class, 'listDrafts'])->middleware('throttle:60,1')->name('cart.list-drafts')` (throttle matches Legacy `guardJsonApi` limit of 60/min).
- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added `#draftTabsCard` dock above the customer selector.
  - Added `ENDPOINTS.listDrafts` route binding.
  - Added `customerCache` (in-memory), `tabLabelFor()`, `tabTitleFor()`, `ensureTab()`, `activateTab()`, `removeTab()`, `refreshTabDockVisibility()`, `updateActiveTabBadge()`, `restoreSessionCarts()`, `switchToCustomer()`, `closeTabCart()`, `initDraftTabsDock()` (~330 lines of new JS).
  - Modified the customer Select2 `processResults` to populate `customerCache` as the user types (so newly-picked customers get a properly-labeled tab immediately).
  - Modified the customer `<select>` change handler to call `ensureTab()` before `loadCart()`.
  - Modified `loadCart()` to call `updateActiveTabBadge()` + `activateTab()` on success.
  - Modified `addToCart()` / `updateItem()` / `removeItem()` / `clearCart()` to call `updateActiveTabBadge()` on success (and `removeTab()` for clearCart).
  - Added the bootstrap sequence at the bottom of `$(function () { ... })`: pre-populate `customerCache` for the server-rendered selected customer, render the initial tab, then fire `restoreSessionCarts()`.

**What was NOT changed:**

- The Legacy sales entry code (`legacy/`) was not touched.
- The Laravel API V1 (`SalesCartApiController`) was NOT touched. The user's R11 brief explicitly named "cart blade" — the API tier is out of scope. If a mobile/AI client wants the multi-cart enumeration, the new `GET /admin/sales/cart/list-drafts` endpoint could be mirrored at `GET /api/v1/sales/cart/list-drafts` in a follow-up (low effort — the service method already exists).
- The Laravel sales invoice **edit** page (`admin/sales-invoices/{id}/edit`) was NOT touched. Legacy has multi-customer tabs only on the create page; R11 matches that scope.
- No new migration was needed — the existing `sales_draft_carts` schema (with the R6 3-column unique key `(user_id, customer_id, branch_id)`) is sufficient. `listCarts()` is a pure read query.
- The API V1 cart endpoints (`/api/v1/sales/cart/*`) were NOT touched — they continue to operate on a single (user, customer, branch) cart per request. Multi-cart enumeration is a Blade-only convenience.
- No keyboard shortcut (e.g. `Ctrl+Tab` to cycle carts) was added. Low priority follow-up.
- No "drag to reorder tabs" was added. Low priority follow-up.
- The dock does not (yet) show the per-cart subtotal on the pill — only the item count. Legacy shows only the count too, so this matches. Could be added as a tooltip in a follow-up.

---

### 5.12 R13: Price-range slider band UI

**Problem:** When the cashier typed a rate into `#addRate`, the only
feedback was a static "Min X / Max Y" hint below the input (added in
R1). The Legacy system showed a visual band with a thumb that tracked
the typed rate, plus a default-rate mark and a green/amber/red status
badge — much faster to parse at a glance during a busy POS session.

Audit risk: §6.1 item #5 in `sales_entry_Lg_vs_La.md`.

**Decision: add a `#priceRangePanel` dock inside the Add Product card
(below the rate input) that mirrors Legacy `#priceRangePanel` +
`updatePriceBandUi` (sales-create.js L129–187).**

Why a separate panel instead of enriching the existing `#rateHint`
text? Two reasons: (1) a slider communicates *position within the
range* at a glance — text can't; (2) the Legacy cashier's muscle
memory expects the band in a specific spot. Mirroring the Legacy
layout minimizes retraining.

Why read from `productCache` instead of fetching the price range
separately? R1 already populates `productCache[p.id]` with
`{min_rate, max_rate, default_rate, available_qty}` from the live
search endpoint (and R10's barcode scan does the same). No extra
round-trip is needed — the band renders instantly when the product is
selected.

**The flow (mirrors Legacy `updatePriceBandUi` + `selectProduct`):**

1. **User picks a product** (Select2 change OR R10 barcode scan) →
   the `#addProduct` change handler calls `setActivePriceRange(p)`
   with the cached product payload.
2. `setActivePriceRange` reads `p.min_rate`, `p.max_rate`,
   `p.default_rate` and stashes them in `state.activePriceRange`.
   If `min ≤ 0` or `max ≤ 0` (no usable range), it clears the state
   and `updatePriceBandUi()` hides the panel — matching Legacy's
   early-return.
3. `updatePriceBandUi()` shows the panel, sets the Min/Max/Default
   labels in ৳, sets `#addRate`'s `min`/`max` HTML attributes (so the
   browser's own stepper also respects the range), positions the
   gradient fill + thumb + default-mark as percentages of the span,
   and sets the status badge to `bg-success` / `bg-warning` (within
   10 % of min) / `bg-danger` (out of range).
4. **User types/edits the rate** → `#addRate` `input` event fires
   (60 ms debounce) → `updatePriceBandUi()` re-positions the thumb +
   refreshes the status badge.
5. **User clicks "Use default"** → `#addRate` is set to
   `default_rate.toFixed(2)` and `.trigger('change')` fires, which
   runs the band update again (thumb snaps to the default position).

**Files modified:**

- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added `#priceRangePanel` HTML inside the Add Product card (below
    the rate/qty/Add row, above the availability row) — track + fill
    + default-mark + thumb + Min/Max/Default labels + status badge +
    "Use default" button. All inline-styled with Bootstrap 5 utility
    classes + a few `position-absolute` elements. No new CSS file.
  - Added `state.activePriceRange` to the `state` object.
  - Added 3 new JS functions in the rendering section:
    `setActivePriceRange(product)`, `rateRangeStatus(rate, min, max)`,
    `updatePriceBandUi()` — ~70 lines total.
  - Modified the `#addProduct` change handler to call
    `setActivePriceRange(p)` (and clear it when no product is
    selected).
  - Modified the R10 `scanAndSelect()` function to call
    `setActivePriceRange(p)` after the rate is filled (so the thumb
    snaps to the right position immediately, not at 0 %).
  - Added `#addRate` `input` handler with 60 ms debounce →
    `updatePriceBandUi()`.
  - Added `#btnUseDefaultRate` click handler → sets rate to
    `default_rate.toFixed(2)` + triggers `change`.

**What was NOT changed:**

- The Legacy sales entry code (`legacy/`) was not touched.
- No backend changes — R13 is purely additive UI. The price-range
  data was already in `productCache` (populated by R1).
- Server-side rate validation in `SalesCartService::validateCartItems`
  + the finalize flow is still authoritative. The slider band is
  purely informational — a red badge does NOT block the Add button.
  (If we wanted to block, we'd add a check inside `addToCart()` —
  deferred to a follow-up if the user requests it.)
- The cart-table inline rate editor (per-row `#cart-rate` input) does
  NOT get a slider band — only the Add-Product rate input does.
  Legacy matches this scope (slider only on the create-page add
  form, not the cart table). Could be added per-row in a follow-up.
- No new CSS file was added. All slider styling is inline `style="…"`
  on the band elements. Keeps the blade self-contained; if a future
  designer wants to theme it, the inline styles are easy to find and
  extract into a CSS class.

---

### 5.13 R14: Live credit-limit display on cart page

**Problem:** Laravel only checked the customer's credit limit at
finalize time, via `GET /admin/sales/credit-check?customer_id=X&amount=Y`.
A cashier could spend 5 minutes building a 50-item cart only to be
blocked at the finalize dialog — wasted effort and a poor UX,
especially for high-volume POS sessions.

The Legacy system solved this with a `#customerDetailsPanel` in
`sales/create.php` (L72–80) that showed the customer's `credit_limit`,
`recent_due`, and `due_left` inline, fetched via
`sales/customer_details` endpoint. The cashier could see at a glance
whether the customer had headroom before adding items.

Audit risk: §6.1 item #6 in `sales_entry_Lg_vs_La.md`.

**Decision: port the Legacy `customer_details` endpoint + panel, AND
extend it with a projected new balance row** (`current_due + cart
subtotal`) **that updates in real time as the cart changes.**

Why extend beyond Legacy parity? The Legacy panel shows the *current*
AR position only — it doesn't reflect the in-progress cart. The
cashier still has to mentally add `cart_subtotal + current_due` and
compare against `credit_limit`. R14 does that math for them and
colour-codes the result (green/amber/red), which directly addresses
the audit risk's "prevents wasted cart-building" rationale.

Why fetch the snapshot once per customer change (vs on every cart
mutation)? The customer ledger doesn't change between cart mutations
— only at finalize / payment / reverse time. So one fetch per
customer change is sufficient; the projected row recomputes locally
using the cached snapshot + the latest cart subtotal. This avoids
hammering the customer_ledger SUM query on every keystroke.

Why reuse `SalesInvoiceService::checkCreditLimit`'s exact formula
(`SUM(debit) − SUM(credit) WHERE is_reversed = false`)? Consistency.
The live panel and the finalize-time check must show the same number
or the cashier will lose trust in the UI. The `is_reversed = false`
filter is important — Legacy didn't have `is_reversed` on
`customer_ledger` (Laravel added it in migration
`2025_01_02_000002`); without the filter, reversed transactions
would inflate the apparent due.

**The flow:**

1. **User picks a customer** (Select2 change) → `fetchCustomerDetails(cid)`
   fires → `GET /admin/sales/cart/customer-details?customer_id=…` →
   returns `{credit_limit, current_due, due_left, customer_name,
   shop_name, mobile, address}`.
2. The response is cached in `state.customerCredit` and
   `renderCustomerDetails()` renders the panel: 4 stat cells +
   projected new balance row + colour-coded status badge.
3. **User adds/updates/removes/clears a cart item** → `renderAll()`
   runs (existing flow) → `renderCustomerDetails()` is now called
   inside `renderAll()` → it recomputes the projected balance using
   `state.customerCredit.current_due + state.cart.subtotal` and
   re-renders the projected row + status badge. **No extra
   round-trip** — the snapshot is reused.
4. **User clicks "Refresh"** → re-fetches the snapshot (in case a
   payment was posted in another tab since the customer was first
   selected). Useful for long-running cart sessions.
5. **User clears the customer** (Select2 cleared) →
   `fetchCustomerDetails(null)` clears `state.customerCredit` →
   `renderCustomerDetails()` hides the panel.

**Files modified:**

- `laravel/app/Services/Sales/SalesCartService.php`
  - Added `getCustomerDetails(int $customerId): array` method (~45
    lines). Loads the customer record, computes `current_due` as
    `SUM(debit) − SUM(credit)` from `customer_ledger` (is_reversed =
    false), returns `{customer_id, customer_name, shop_name, mobile,
    address, credit_limit, current_due, due_left}`. Returns sane
    zeros when the customer is not found (matches Legacy's
    `$this->sendJson($data ?: […defaults])`).
- `laravel/app/Http/Controllers/Admin/SalesCartController.php`
  - Added `customerDetails(Request $request)` method that calls
    `getCustomerDetails((int) $request->input('customer_id', 0))` and
    returns JSON. Returns sane zeros when customer_id is missing/0.
- `laravel/routes/web.php`
  - Added `Route::get('cart/customer-details',
    [SalesCartController::class, 'customerDetails'])`
    ->middleware('throttle:60,1')
    ->name('cart.customer-details')`.
- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added `#customerDetailsPanel` HTML inside the customer selector
    card (below the customer row) — 4 stat cells (Credit limit /
    Current due / Balance left / Cart subtotal) + projected new
    balance row + status badge + Refresh button.
  - Added `ENDPOINTS.customerDetails` route binding.
  - Added `state.customerCredit` to the `state` object.
  - Added 2 new JS functions: `fetchCustomerDetails(customerId)`,
    `renderCustomerDetails()` — ~80 lines total.
  - Modified `renderAll()` to call `renderCustomerDetails()` so the
    projected row stays in sync with every cart mutation.
  - Modified the `#customerSelect` change handler to call
    `fetchCustomerDetails(cid)` (or `fetchCustomerDetails(null)` when
    cleared).
  - Added `#btnRefreshCredit` click handler (re-fetches the snapshot
    with a spinning icon).
  - Added bootstrap call: `if (state.customerId) {
    fetchCustomerDetails(state.customerId); }` so the panel renders
    immediately when the page loads with `?customer_id=…`.

**What was NOT changed:**

- The Legacy sales entry code (`legacy/`) was not touched.
- The Laravel API V1 was NOT touched. The new
  `/admin/sales/cart/customer-details` endpoint is Blade-only. If a
  mobile/AI client wants the same data, the service method
  `SalesCartService::getCustomerDetails()` is reusable — a future
  `GET /api/v1/sales/customers/{id}/credit-snapshot` endpoint would
  be a thin wrapper (low effort).
- The finalize-time credit-check endpoint (`credit-check`) was NOT
  touched. R14 is informational only; the authoritative check still
  happens inside `SalesInvoiceService::finalizeFromCart` (with the R5
  in-transaction lock). The live panel might say "Will breach" but
  the cashier can still proceed to the finalize dialog and use the
  override + reason flow.
- The customer 360° Hub page (`admin/customers/{id}`) was NOT
  touched. That page already shows the customer's AR balance via
  `CustomerController::show` — R14 only adds the inline panel to the
  cart page.
- No new migration was needed — `customer_ledger` already has
  `debit`, `credit`, `is_reversed` columns from the original schema
  + migration `2025_01_02_000002`.
- The panel does NOT auto-refresh on a timer. The "Refresh" button is
  manual. A timer-based refresh would add a polling load for little
  benefit (the cashier is looking at the page; if they want fresh
  data they click Refresh). A future enhancement could use SSE
  (already wired up via `SseController`) to push balance updates when
  a payment posts — low priority.

---

### 5.14 R15: Customer recents chips (localStorage)

Mirrors Legacy `sales.js::rememberCustomerRecent` (L1306–1323) +
`renderCustomerRecents` (L1325–1354) + `#customerRecents` div in
`create.php` L47. The cashier picks dozens of customers per shift;
for repeat customers they shouldn't have to type the name each time.

**Storage shape:**
```
localStorage['rcerp_sales_customer_recents']
  = JSON.stringify([{id:int, label:string, ts:int(unix_ms)}, ...])
```
Capped at 5 entries, deduped by id, most-recent-first. Namespaced
with `rcerp_` prefix so a multi-tenant deploy doesn't cross
-contaminate.

**Files modified:**
- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#customerRecentsRow` (d-none by default) + `#customerRecents`
    chip container in the customer selector card (below the Select2,
    above the credit panel).
  - New JS: `CUSTOMER_RECENTS_KEY`, `CUSTOMER_RECENTS_MAX`,
    `rememberCustomerRecent(id, label)`, `loadCustomerRecents()`,
    `renderCustomerRecents()`.
  - `#customerSelect` change handler now also calls
    `rememberCustomerRecent(cid, label)` + `renderCustomerRecents()`.
  - Delegated click handler on `#customerRecents .btn[data-customer-id]`
    calls `switchToCustomer(cid)` (R11's flow — Select2 value + tab
    ensure + cart load + credit fetch).
  - Bootstrap: `renderCustomerRecents()` is called on page load +
    the server-rendered pre-selected customer is also remembered.

**What was NOT changed:**
- No backend changes — purely client-side.
- No new endpoint — clicking a chip reuses R11's
  `switchToCustomer()` + the existing `/cart/load` endpoint.
- No expiry / TTL on entries — Legacy doesn't have one either. The
  5-entry cap is the implicit cleanup.
- Storage failures (private mode, quota) are caught + warned via
  `console.warn` — non-fatal, chips just won't persist across
  sessions but the rest of the page works normally.

**Why 5 entries (not 10 or 3):** Mirrors Legacy's
`recents.slice(0, 5)` — same UX calibration, no reason to diverge.

---

### 5.15 R16: Sticky bottom bar (item count + grand total + Finalize)

Mirrors Legacy `#posStickyBar` in `create.php` L166–173 +
`sales.js::updatePosStickyBar` L1363–1380 + `initPosStickyBar`
L1356–1361. On long carts the cashier shouldn't have to scroll back
to the top to finalize — the button should always be one tap away.

**Files modified:**
- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#posStickyBar` fixed-position bottom bar (outside the
    container, before `@endsection`).
  - New `@push('css')` block scoped to the cart page with:
    `position: fixed; left:0; right:0; bottom:0; z-index:1040;
    background:#fff; border-top:1px solid #dee2e6; box-shadow:
    0 -4px 16px rgba(0,0,0,.08); padding:0.65rem 1rem
    calc(0.65rem + env(safe-area-inset-bottom,0px))`.
  - Bar visible iff cart has items + customer is selected.
  - `#posStickySummary` shows `<span class="sticky-count">N</span>
    items · ৳<span class="sticky-total">X</span>`.
  - `#posStickyFinalize` button enabled iff cart is valid
    (mirrors `#btnFinalize` disabled logic); clicking calls the
    same `finalizeInvoice()` function — same idempotency-token +
    credit-check flow + SweetAlert dialog.
  - New JS `updatePosStickyBar()` called from `renderAll()` on
    every cart mutation.
  - Body gets `pos-sticky-visible` class while the bar is visible
    so the page padding-bottom (5.5rem) keeps the last cart row
    uncovered even on browsers without `:has()`.

**What was NOT changed:**
- No backend changes — purely client-side.
- No new endpoint — clicking the sticky Finalize button calls the
  existing `finalizeInvoice()` function.
- No new route — the bar is just an additional UI element.
- The bar is hidden when the cart is empty (Legacy keeps it visible
  with opacity 0.85). We chose to hide it entirely because the
  Legacy "always visible but disabled" pattern can confuse users
  into thinking the button is broken.

**Why `env(safe-area-inset-bottom)`:** iOS notched devices clip
fixed-position bottom bars. The CSS env() function returns the
safe-area inset (e.g., 34px on iPhone X+) so the bar sits above
the home indicator.

**Why `:has()` + body class fallback:** Modern browsers support
`:has()` (Chrome 105+, Safari 15.4+, Firefox 121+). For older
browsers, the JS toggles a `pos-sticky-visible` class on `<body>`.
Both rules target the same padding-bottom (5.5rem) so behaviour
is identical.

---

### 5.16 R17: Mobile-cart cards with swipe-to-delete

Mirrors Legacy `sales-cart-mobile` + `initCartSwipeRemove`
(sales.js L1422–1434). On mobile, the desktop table is unreadable
(horizontally scrolling, tiny inputs, hard to tap "Delete"). Legacy
solves this with a card-based layout + a swipe-left-to-delete
gesture.

**Files modified:**
- `laravel/resources/views/admin/sales/cart.blade.php`:
  - Wrapped the existing desktop `<table>` in a
    `<div class="sales-cart-desktop table-responsive">` wrapper.
  - Added a sibling `<div class="sales-cart-mobile" id="cartItemsMobile">`
    that gets populated by the same `renderCartTable()` loop.
  - Each cart item now renders as BOTH a `<tr>` (desktop) AND a
    `<div class="sales-cart-line" data-product-id="ID">` card (mobile).
    Both share the same `.cart-qty` / `.cart-rate` / `.cart-remove` /
    `.cart-total` classes so the existing delegated handlers + the
    `debouncedUpdate()` helper work for both — no duplicated logic.
  - CSS in the `@push('css')` block:
    - `.sales-cart-desktop { display:block; }` (default)
    - `.sales-cart-mobile { display:none; }` (default)
    - `@media (max-width: 767.98px)` swaps the two displays.
    - `.sales-cart-line` card styling: border, border-radius:10px,
      padding:0.75rem, margin:0.5rem, position:relative,
      overflow:hidden, transition:transform .2s ease.
    - `.sales-cart-line::before` — a red `::before` pseudo-element
      with a Font Awesome trash icon (`\f1f8`) positioned at
      right:0, width:80px, background:#dc2626, color:#fff. Hidden
      behind the card content (z-index:0) and revealed as the card
      slides left during a swipe.
    - `.sales-cart-line > *` gets `position:relative; z-index:1;
      background:#fff;` so the card content sits above the red
      ::before pseudo-element.
    - `.sales-cart-line .cart-qty, .cart-rate { min-height:44px;
      font-size:16px; }` — large tap targets + iOS no-zoom font size.
  - Generalized `debouncedUpdate(productId)` from
    `$('#cartItemsBody tr[data-product-id="X"]')` to
    `$('[data-product-id="X"]').first()` so it reads from whichever
    view (desktop or mobile) is currently visible.
  - Generalized the `.cart-remove` click handler from
    `closest('tr')` to `closest('[data-product-id]')` so it works
    for both `<tr>` and `<div class="sales-cart-line">` containers.
  - New JS `initCartSwipeRemove()` is called at the end of every
    `renderCartTable()` (touch handlers don't survive
    `$mobile.empty()`). Uses modern Pointer Events (covers touch +
    pen, ignores mouse) instead of Legacy's `touchstart`/`touchend`
    pair — same gesture, broader input coverage, simpler code.

**Why Pointer Events instead of Touch Events:**
- Pointer Events unify touch + pen + mouse under one API. We
  filter to touch/pen only via `if (e.pointerType === 'mouse') return;`
  so mouse users don't accidentally trigger swipe-delete by dragging.
- Touch Events would require two separate handlers (`touchstart` +
  `touchend`) and don't cover pen input.
- Pointer Events have better browser support than Touch Events
  for some edge cases (e.g., Surface stylus).

**Why -80px threshold:** Mirrors Legacy's
`if (e.changedTouches[0].clientX - startX < -80)` — same UX
calibration. Smaller thresholds cause accidental deletes; larger
thresholds feel sluggish.

**Why 600ms time limit:** Prevents a slow drag from triggering
delete. A real swipe gesture is fast (~200-400ms); a slow drag is
the user repositioning. Adding a time limit makes the gesture
detection more reliable.

**What was NOT changed:**
- No backend changes — purely client-side.
- No new endpoint — the swipe gesture triggers the existing
  `.cart-remove` button's click handler, which calls the existing
  `removeItem()` → SweetAlert confirm → server call.
- No new route — the card view is just an alternative rendering
  of the same cart data.

---

### 5.17 R10s: Barcode scanning simplified (single product search box)

R10 wired up barcode scanning with a separate `#barcodeInput` field
(toggle-revealed via `#btnToggleBarcode`) + a "Scan & Add" button +
an "Auto-add after scan" checkbox. This duplicated the Select2
search box and made the page feel cluttered. R10s consolidates to
a single Select2 search box that doubles as the barcode entry.

**The user's brief:**
> "about Port barcode scanning: keep only product search just like
> customer search, no need 2 option searching product and scan,
> just keep search product and make the UI/UX better like lagachy"

**Two-layer barcode support:**

1. **Primary path (no JS needed):** The R1 AJAX search endpoint
   matches on `product_code` via ILIKE. Barcode scanners type the
   code rapidly + Enter; Select2's 250ms debounce catches the full
   scan; `selectOnClose: true` (newly added) makes Enter pick the
   highlighted first result. This handles 99% of scans correctly
   because the scanned code is a substring (or exact match) of the
   product's `product_code` field.

2. **Fallback path (delegated keydown handler):** If the user
   types/scans a code that returns NO matches from the ILIKE search
   (rare — happens when the code has whitespace differences, or
   the user is typing a SKU that doesn't match any product_code
   substring), we intercept Enter on the Select2 search input and
   fire an exact-match lookup against the R1 `productByCode`
   endpoint. On success: inject the matched product as a fresh
   `<option>` + select it + trigger `change` (so rate/qty/price
   -band/availability populate via the existing handlers) + focus
   `#addQty`.

**Files modified:**
- `laravel/resources/views/admin/sales/cart.blade.php`:
  - REMOVED: `#btnToggleBarcode` button from card header.
  - REMOVED: entire `#barcodeRow` HTML block (input + hint +
    "Scan & Add" button + auto-add checkbox).
  - REMOVED: all R10 JS — `$barcodeInput`, `$barcodeHint`,
    `$barcodeAutoAdd` vars; `#btnToggleBarcode` click handler;
    `#barcodeInput` keydown handler; `#btnBarcodeAdd` click handler;
    the entire `scanAndSelect()` function.
  - ADDED: `selectOnClose: true` to the `#addProduct` Select2 init.
  - ADDED: delegated `keydown` handler on `.select2-search__field`
    that intercepts Enter when the dropdown belongs to `#addProduct`
    AND no result is highlighted → calls new
    `lookupProductByCodeAndSelect(term)` function.
  - ADDED: `lookupProductByCodeAndSelect(code)` function (~50 lines)
    that fetches the R1 `productByCode` endpoint, on success
    injects the matched product as a fresh `<option>` + selects it +
    triggers `change` + focuses `#addQty`. On failure, shows a
    toast + reopens the Select2 dropdown so the user can re-search.
  - UPDATED: `#addProduct` Select2 placeholder changed from
    "— Type product name / code —" to "— Type name / scan code —"
    to make the dual-purpose nature clear.
  - ADDED: a small `<span class="badge bg-light text-secondary">`
    next to the "Product" label that says "scan ok" with a barcode
    icon, so the cashier knows the field accepts scanner input.

**What was NOT changed:**
- No backend changes — same R1 endpoints
  (`admin.sales.cart.search-product` + `admin.sales.cart.product-by-code`).
- No new route — the fallback uses the existing `productByCode`
  endpoint via `ajaxGet`.
- No new migration — purely client-side.
- The R10 behaviour of "auto-add after scan" is NOT preserved. The
  R10s flow stops at "product selected, rate filled, qty focused" —
  the cashier reviews the rate/qty and clicks "Add" themselves.
  This is safer (no accidental adds from mis-scans) and matches
  Legacy's `selectProductCreate` behaviour (which also doesn't
  auto-add). If the user later wants auto-add back, it's a 2-line
  addition: append `addToCart();` to `lookupProductByCodeAndSelect`.

**Why a delegated handler instead of binding directly to the
Select2 search input:** Select2 re-creates the `.select2-search__field`
input every time the dropdown opens, so a direct bind would be
lost. A delegated handler on `document` survives across open/close
cycles. We check `aria-controls` attribute (which Select2 sets to
`select2-addProduct-results`) to ensure we only intercept Enter on
the product search, not on the customer search or any other Select2.

---

### 5.18 R18: Keyboard shortcuts (Enter on qty → focus rate; Enter on rate → Add)

Mirrors Legacy `sales-create.js` keyboard flow. Legacy implemented
the suggestion-list ArrowUp/ArrowDown/Enter handling manually
(`productSearch.addEventListener('keydown', ...)` L324–351) because
Legacy used a plain `<input>` with a custom suggestion `<div>`.
Laravel uses Select2 which already provides built-in ArrowUp/ArrowDown/
Enter handling natively, so that part needs no porting work.

The gap R18 closes is the page-level keyboard flow *around* the
Select2 — specifically the qty → rate → submit → refocus loop that
lets a cashier run a full session without touching the mouse.

#### What changed

1. **Auto-focus `#addQty` after product pick** — in the existing
   `$('#addProduct').on('change', ...)` handler, after the rate is
   populated + price band rendered + availability checked, we now
   `$('#addQty').focus()` and `$('#addQty')[0].select()`. This means
   the cashier can immediately type a new qty (e.g. "5") to replace
   the default of "1", or just press Enter to accept 1.

2. **`#addQty` Enter → focus + select `#addRate`** — was previously
   `$('#addQty, #addRate').on('keydown', ...)` shared handler that
   called `addToCart()` on Enter. Now split into two separate
   handlers: `#addQty` Enter → `$rate.focus(); $rate[0].select();`
   (NOT submit). This matches Legacy `sales-create.js` L615–621 where
   Enter on quantity moves focus to rate so the cashier can
   review/override the rate before submitting.

3. **`#addRate` Enter → `addToCart()`** — unchanged from before,
   but now in its own dedicated handler (was shared with `#addQty`).

4. **After successful add, refocus `#addProduct` Select2 search box**
   — in `addToCart().done()`, after the form reset, we
   `setTimeout(() => $('#addProduct').select2('open'), 50)`. Select2
   needs `open` to focus the search input — calling `.focus()` on
   the original `<select>` doesn't bring up the search box. The 50 ms
   delay lets the Select2 reset its internal state after the
   `val('').trigger('change')` call. Mirrors Legacy
   `sales-create.js::resetProductEntry` L632 which calls
   `document.getElementById('productSearch')?.focus()`.

#### What was NOT changed

- No backend changes — R18 is purely client-side JS in
  `cart.blade.php`.
- No new routes, no new endpoints, no new migrations.
- The R10s Enter-on-empty `productByCode` fallback is unchanged —
  R18 just adds the qty/rate/post-add focus flow on top.
- Select2's built-in keyboard handling (ArrowUp/Down/Enter in the
  dropdown) is not modified or replaced.

#### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - `addToCart()` function: added refocus block in the success branch
  - `$('#addProduct').on('change', ...)`: added `#addQty` focus + select
    at the end
  - Replaced shared `$('#addQty, #addRate').on('keydown', ...)` with
    two separate handlers
  - Added R18 explanation comment block

#### Why this matters

Without R18, the cashier had to Tab between fields and click the
product search after each add. With R18, a cashier with a barcode
scanner + numeric keypad can run a full session without touching the
mouse: scan → Enter (accepts qty 1) → Enter (submits with default
rate) → scan next product → … The keyboard loop is unbroken.

---

### 5.19 R19: Inline receive-payment modal on sales-invoices index

Mirrors Legacy `sales/receive_modal/{id}` + `sales-receive-payment.js`.

Before R19, collecting a payment on an invoice required the user to:
1. Note the invoice code from the sales-invoices index page
2. Navigate to `/admin/customer-payments/create`
3. Pick the customer from a 500-row dropdown
4. Find the invoice in the outstanding-invoices list
5. Enter the payment details
6. Submit

With R19, the user clicks the green "Receive" button on a row in the
sales-invoices index page → modal opens with the form pre-populated
for that specific invoice → enter amount + mode → submit. Two clicks
instead of six navigations.

#### Backend changes

1. **New route** `GET /admin/sales-invoices/{id}/receive-modal`
   named `admin.sales-invoices.receive-modal`, middleware
   `role:salesman,accountant,manager,admin`. Returns HTML (Blade
   partial), not JSON — mirrors Legacy which returned the rendered
   `receive_modal.php` view.

2. **New controller method** `SalesInvoiceController::receiveModal(int $id)`:
   - Loads the invoice with `customer`, `branch`, and `allocations`
     (with nested `payment.branch`, `payment.bank`)
   - Resolves `received_by_name` from the `users` table via the
     `customer_payments.created_by` FK (no formal model relationship
     on CustomerPayment — we look it up via `User::whereIn('id', ...)`)
   - Loads active banks + branches for the form dropdowns
   - Computes `balance = max(0, total_amount - paid_amount)`
   - Generates a fresh R2 idempotency-token UUID (server-side, so
     the client can't accidentally reuse one)
   - Returns the `_receive_modal_body.blade.php` partial

3. **New model relationship** `SalesInvoice::allocations()`:
   `HasMany(InvoicePaymentAllocation::class, 'invoice_id')`. The
   `InvoicePaymentAllocation` model already existed with the inverse
   `payment()` and `invoice()` relations. This new forward relation
   lets the modal query "what payments have been allocated to this
   invoice?" without writing a manual join.

#### Frontend changes (admin/sales-invoices/index.blade.php)

1. **New "Receive" button on each row** — shown iff
   `due_amount > 0.01 && status !== 'cancelled' && !is_reversed`.
   Green button with `fa-hand-holding-dollar` icon. Carries
   `data-invoice-id` and `data-invoice-code` attributes.

2. **New modal shell** `#receivePaymentModal` — Bootstrap 5 modal,
   `modal-lg`, `modal-dialog-centered`, `modal-dialog-scrollable`,
   `data-bs-focus="false"` (so SweetAlert inside the modal can
   receive focus — same fix as Legacy `sales-receive-payment.js`
   `ensureSwalBootstrapFocusFix`). Empty `#receivePaymentModalContent`
   div is populated by AJAX on each open.

3. **New JS** (in `@push('scripts')`):
   - Lazy `bootstrap.Modal` instance (created on first open)
   - `.btn-receive-payment` click handler: shows loading spinner,
     opens modal, fires `GET /admin/sales-invoices/{id}/receive-modal`,
     injects HTML, calls `initReceiveModalBody()` to wire up the
     form handlers
   - `initReceiveModalBody()`: wires up amount validation (with
     balance check + sync to hidden `alloc_amount[]` field),
     quick-amount chips (25%/50%/Full due/Clear), payment-mode
     radio → bank-panel toggle, submit handler with over-payment
     SweetAlert confirm, traditional form POST (so the store
     endpoint's redirect works)

4. **New CSS** (in `@push('css')`): modal-body max-height + scroll,
   smaller font sizes for the modal context (matches Legacy
   `sales-receive-payment.css` feel).

#### What was NOT changed

- The `admin.customer-payments.store` endpoint is unchanged — R19
  reuses the existing route, the existing `CustomerPaymentService`,
  the existing R2 idempotency-token flow. No new write path was
  created.
- The existing `admin/customer-payments/create.blade.php` page is
  unchanged. It's still useful for collecting payments against
  multiple invoices at once (the modal handles only single-invoice
  allocation). Power users (accountants) may still prefer the full
  create page.
- The customer-payments `show` and `print-receipt` pages are
  unchanged. The modal links to print-receipt for prior payments
  on the same invoice.
- The Laravel API V1 (`CustomerPaymentApiController`) was NOT
  touched — R19 is admin/Blade only, matching the user's brief
  ("Today's Sales" is an admin page, not an API consumer).

#### Files modified

- `laravel/app/Http/Controllers/Admin/SalesInvoiceController.php`:
  new `receiveModal(int $id)` method (~50 lines)
- `laravel/app/Models/SalesInvoice.php`: new `allocations()`
  HasMany relationship (~10 lines)
- `laravel/routes/web.php`: new `receive-modal` route registration
- `laravel/resources/views/admin/sales-invoices/_receive_modal_body.blade.php`:
  NEW file (~200 lines)
- `laravel/resources/views/admin/sales-invoices/index.blade.php`:
  added "Receive" button on each row + modal shell + JS + CSS

#### Why a traditional form POST instead of AJAX?

The `admin.customer-payments.store` endpoint redirects to
`admin.customer-payments.show` on success and back-with-input on
error. Replaying this with AJAX would require either:
- Changing the endpoint to return JSON (breaks the existing create
  page), or
- Building a parallel JSON-returning endpoint (duplicates business
  logic).

A traditional form POST follows the redirect naturally — the browser
ends up on the `customer-payments.show` page with the success flash.
This matches Legacy `sales-receive-payment.js::doSubmit` which also
does `form.submit()` (not `$.ajax`). Simpler + no logic duplication.

### 5.20 R21: Server-side DataTables with smart sort + smart search

Before R21, the sales-invoices index page used Laravel's built-in
paginator (25 rows/page) + a client-side DataTable layered on top
of just the current page's rows. This meant:

- Sorting and searching only worked on the current 25 rows, not
  the full filtered set.
- Page reload on every filter change (the filter form was a
  traditional GET form).
- No "smart sort" — the user couldn't say "show me unpaid invoices
  first, then by oldest date" which is the Legacy default.

With R21, the index page now uses DataTables' server-side processing
mode. The browser sends `draw / start / length / order[i][column] /
order[i][dir] / search[value]` to `GET /admin/sales-invoices/datatable`,
and the server returns the matching rows + total counts as JSON.
The page never reloads on filter change — every filter input change
triggers `dt.ajax.reload()` instead.

**Smart sort** is implemented as a checkbox (`#filterSmartSort`,
default ON). When checked AND the user hasn't clicked a column
header to sort, the server applies:

```sql
ORDER BY
  (CASE WHEN due_amount > 0.01 AND status NOT IN ('cancelled','reversed')
        THEN 0 ELSE 1 END) ASC,
  invoice_date ASC,
  id ASC
```

— unpaid invoices first, then oldest date, then by id. This matches
the Legacy `sales-today-index.js::#filterSmartSort` checkbox. When
the user clicks a column header, DataTables sends `order[i]` and
the server applies that instead — smart sort is overridden. This
gives the user the best of both worlds: a sensible default order,
plus per-column sort on demand.

**Smart search** matches the `#filterSearch` input (debounced 320ms)
against: invoice_code + customer name/code/mobile + branch
name/code (ILIKE). The Legacy hint says "invoice, customer, mobile,
branch, salesman, product" — we cover everything except salesman
(Laravel doesn't have a salesman relationship on the invoice) and
product (would require a join through `sales_invoice_items`, which
is expensive on large datasets — left for a future optimization).

Files modified:
- `laravel/app/Http/Controllers/Admin/SalesInvoiceController.php`:
  added `datatable()` method (~85 lines) + shared private
  `buildInvoiceFilterQuery()` helper.
- `laravel/routes/web.php`: added `GET admin/sales-invoices/datatable`
  route (named `admin.sales-invoices.datatable`, middleware
  `role:salesman,accountant,manager,admin`).
- `laravel/resources/views/admin/sales-invoices/index.blade.php`:
  full rewrite — replaced Blade `@forelse` tbody + Laravel paginator
  with empty `<tbody>` that DataTables fills via AJAX. Added
  `#filterSmartSort` checkbox to filter form. Smart search input
  debounced 320ms.

The 5 global stat cards at the top of the page (Total / Draft /
Confirmed / Cancelled / Total value) are unchanged — they show
GLOBAL counts (not filter-aware), complementing the R22 status
chips which ARE filter-aware.

### 5.21 R22: Status chips with live counts

The Legacy sales-today page has 6 status chips above the invoice
table: All / Awaiting payment / In progress / Draft / Godown
issued / Challan done. Each chip shows a live count fetched via
`sales/today_filter_summary`. Clicking a chip sets a hidden status
filter and reloads the table.

Before R22, the Laravel sales-invoices index had a simple Status
`<select>` dropdown with no counts. The user had to guess which
status would have results before clicking.

With R22, the Laravel page now has 6 status chips that mirror the
Legacy pattern (adapted to Laravel's status model — Laravel doesn't
have Legacy's godown_issued/challan_completed invoice statuses,
so those are replaced with Confirmed and Reversed):

- **All** (indigo) — total in current filter scope
- **Awaiting payment** (red) — `due_amount > 0.01 AND status NOT IN
  ('cancelled') AND is_reversed = false`
- **Draft** (amber) — `status = 'draft' AND is_reversed = false`
- **Confirmed** (green) — `status = 'confirmed' AND is_reversed = false`
- **Cancelled** (slate) — `status = 'cancelled'`
- **Reversed** (dark red) — `is_reversed = true`

Each chip has a count badge that's refreshed via AJAX (debounced
280ms) whenever filters change. Clicking a chip sets hidden
`#status_chip` input + reloads DataTable + refreshes summary.

A critical detail: the summary endpoint excludes the status_chip
filter (via `buildInvoiceFilterQuery($request, excludeStatusChip:
true)`). This means the counts always reflect the size of EVERY
bucket, regardless of which chip is currently active. Without this,
clicking "Draft" would zero-out every other chip's count, making
it impossible to compare bucket sizes.

Files modified:
- `laravel/app/Http/Controllers/Admin/SalesInvoiceController.php`:
  added `summary()` method (~30 lines) reusing the shared
  `buildInvoiceFilterQuery()` helper.
- `laravel/routes/web.php`: added `GET admin/sales-invoices/summary`
  route (named `admin.sales-invoices.summary`).
- `laravel/resources/views/admin/sales-invoices/index.blade.php`:
  removed the Status `<select>` dropdown; added 6-chip row with
  count badges; added JS to fetch summary + update chip counts +
  handle chip clicks.

### 5.22 R23: Mobile cards variant for Today's Sales

The Legacy sales-today page renders invoice rows as cards on
mobile (window width < 768px) and as a table on desktop. The cards
are populated from the DataTables API on every draw — same data,
just a different layout. This is critical for field staff using
phones to collect payments.

Before R23, the Laravel sales-invoices index had no mobile variant.
The desktop table was usable on mobile (thanks to Bootstrap's
`table-responsive` horizontal scrolling), but reading a wide
invoice row required horizontal scrolling — a poor UX.

With R23, the Laravel page now has a `#invoiceCards` container
above the desktop table. CSS `@media (max-width: 767.98px)` hides
the desktop table and shows the cards container. The DataTables
`drawCallback` calls `renderMobileCards(api)` which iterates the
current page's data and renders each invoice as a card.

Each card shows:
- Invoice code (as a link to the show page) + date
- Customer name (large)
- Branch name (small, muted)
- Status badge + Total + Due (or "Paid" if 0)
- Soft-hold badge if applicable
- View + Receive buttons

Card left border color signals status at a glance:
- Red = due amount > 0
- Green = paid in full
- Slate = cancelled (dimmed)
- Dark red = reversed (red background tint)

A window resize handler (debounced 180ms) re-renders the cards on
viewport changes — important for users who rotate their phone or
resize their browser window.

The R19 `.btn-receive-payment` delegated click handler works for
both desktop table rows AND mobile card buttons because both use
the same CSS class. No duplicate wiring needed — the handler is
bound to `document` and survives DataTables redraws.

Files modified:
- `laravel/resources/views/admin/sales-invoices/index.blade.php`:
  added `#invoiceCards` container, `renderMobileCards()` JS
  function, resize handler, and CSS for the cards + the desktop/
  mobile visibility toggle.

### 5.23 R26: min:10 on override_reason (validation-time parity)

**What changed:** The `override_reason` field on sales invoice
finalization (and edit) is now validated as `nullable|string|min:10
|max:500` instead of `nullable|string|max:500`. Applied in three
places: (a) `app/Http/Requests/Api/V1/Sales/FinalizeInvoiceRequest
.php` (mobile API Form Request), (b) `app/Http/Controllers/Admin/
SalesInvoiceController::store()` inline `validate()` call (web
finalize), (c) `app/Http/Controllers/Admin/SalesInvoiceController::
update()` inline `validate()` call (web edit).

**Why:** Mirrors Legacy `SalesInvoiceOperationsTrait::finalizeInvoice
()` L42: `if (strlen($overrideReason) < 10) { return error; }`. The
Laravel service layer (`SalesInvoiceService::finalizeFromCart` +
`updateInvoice`) already enforced this at runtime inside the DB
transaction (R5 authoritative re-check), but only after the credit
limit check had already passed — meaning the user got a runtime
exception after they thought they were past validation. Moving the
rule into the Form Request + controller `validate()` lets the
request fail fast at validation time with a friendly error message.

**What was NOT changed:**
- The service-layer `strlen($overrideReason) < 10` re-check inside
  the DB transaction is kept as defense-in-depth (R5 race-condition
  protection — a concurrent finalize could change the credit-limit
  state between validation and transaction commit).
- Legacy's "only enforced when `credit_limit_override = true` AND
  `creditCheck['exceeds'] = true`" conditional is NOT replicated at
  the validation-rule level. Laravel validates `override_reason`
  length regardless of whether the override is actually triggered.
  This is intentionally stricter — it catches the case where a user
  submits `override_reason = "ok"` without checking the override
  box, then later toggles the override box (e.g. via the API) and
  expects the same payload to work. The service layer still does
  the conditional check before posting the override.

**Files modified:**
- `laravel/app/Http/Requests/Api/V1/Sales/FinalizeInvoiceRequest.php`
- `laravel/app/Http/Controllers/Admin/SalesInvoiceController.php`
  (2 validate calls — store + update)

### 5.24 R27: min:5 on payment cancel reason (validation-time parity)

**What changed:** Two controller `validate()` calls tightened to
match Legacy `SalesPaymentOperationsTrait::reverseCustomerPayment()`
L200: `if (strlen($reason) < 5) { return error; }`:
- Web: `CustomerPaymentController::cancel()` — `cancel_reason` rule
  changed from `required|string|max:500` →
  `required|string|min:5|max:500`.
- API: `CustomerPaymentApiController::cancel()` — `reason` rule
  changed from `required|string|min:10|max:500` →
  `required|string|min:5|max:500` (relaxed from min:10 down to
  Legacy's min:5 — the API was previously stricter than Legacy,
  which would have caused client-side friction).

**Why:** Legacy requires ≥5 chars; Laravel previously enforced
nothing on the web path and 10 chars on the API path. Both are now
exactly min:5, matching Legacy parity. The service-layer
`CustomerPaymentService::cancelPayment` runs unchanged — it never
had a length check (Legacy's check is in the controller-layer trait).

**What was NOT changed:**
- No service-layer re-check added. The Legacy check is in the
  controller-layer trait, not the service, so there's no
  defense-in-depth re-check to port.
- The `max:500` cap is kept (Legacy doesn't specify one but the
  column is `text` so any reasonable cap is fine).
- The cancel endpoint is not changed to use a Form Request class
  (it uses inline `$request->validate([...])`). Converting to a
  Form Request is out of scope for R27.

**Files modified:**
- `laravel/app/Http/Controllers/Admin/CustomerPaymentController.php`
- `laravel/app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php`

### 5.25 R28: PWA installability for cart blade

**What changed:** The sales cart page (`/admin/sales/cart`) is now
installable as a Progressive Web App on Chrome/Edge/Firefox. New
files:
- `laravel/public/manifest.json` — PWA web app manifest. Name="RC
  ERP — Sales Cart", short_name="RC POS", start_url=/admin/sales/cart
  (deep-links straight into the cart after install), scope=/
  admin/sales/ (the SW controls the sales module namespace),
  display=standalone (no browser chrome), theme_color=#4f46e5
  (matches the hero header gradient), background_color=#ffffff,
  2 shortcuts to Today's Sales + Customer Payments (long-press the
  installed icon on mobile to see them), 2 icon entries (SVG, both
  `any` and `maskable` purpose so Android adaptive icons render
  correctly).
- `laravel/public/sw.js` — minimal service worker. Cache version
  `rc-erp-pos-v1`. Install: pre-caches 17 offline-shell assets
  (cart route + all CSS/JS/fonts from /assets/). Activate: cleans
  up old cache versions. Fetch handler: cache-first for /assets/*
  and /manifest.json (immutable static assets), network-first for
  HTML navigations with cart-shell fallback (so the page can be
  opened offline after first visit), pass-through for everything
  else (including all non-GET — never intercept writes). The SW is
  intentionally minimal: its job is to make Chrome show the install
  prompt, not to be a full offline-first POS.
- `laravel/public/assets/images/icon.svg` — 512×512 SVG icon.
  Indigo→purple gradient background (matches the cart hero header),
  white shopping-cart glyph centered (maskable-safe: cart sits
  inside the inner 80%), small "RC" badge in the bottom-right
  corner. Single SVG scales from favicon (16px) to install icon
  (512px) without needing multiple PNGs.

**Layout change:** New `@stack('head_meta')` added to `layouts/
admin.blade.php` `<head>` (after the existing meta tags, before
the title). This is a per-page meta-tag stack — empty by default,
pushed by individual blade templates via `@push('head_meta')`.

**Cart blade changes:**
- New `@push('head_meta')` block: manifest link, favicon,
  apple-touch-icon, theme-color (#4f46e5), application-name,
  mobile-web-app-capable, apple-mobile-web-app-capable,
  apple-mobile-web-app-status-bar-style (black-translucent),
  apple-mobile-web-app-title (RC POS), msapplication-TileColor,
  msapplication-tap-highlight.
- New `<script>` block at the end of `@push('scripts')`: SW
  registration. Feature-detected via `'serviceWorker' in
  navigator` + `window.isSecureContext` (Chrome requires HTTPS or
  localhost). Registered on `window.load` with scope `/`. Failure
  is non-fatal — the page works fine without a SW; it just won't
  show the install prompt. Logs to `console.debug` on success,
  `console.warn` on failure.
- The `@push` directive inside the Blade comment is escaped as
  `@@push` (lesson from HOTFIX-CART commit fcf1927 — Blade scans
  the whole template regardless of context).

**Why:** The cart page is the primary POS kiosk surface. Making it
installable means a kiosk device can run it as a standalone app —
no browser chrome, larger viewport, native install prompt, can be
launched from the home screen / start menu. Audit risk §6.1 item
#33.

**What was NOT changed:**
- No offline invoice creation. POS workflows that need to post
  invoices while offline are out of scope (would require IndexedDB
  queue + sync logic — significant work).
- No push notifications (R25 was dropped per user request).
- No background sync.
- Other admin pages (sales-invoices index, customer-payments, etc.)
  are NOT installable — only the cart. The manifest's `scope` is
  `/admin/sales/` so the install prompt only appears on sales
  pages.
- The `start_url` is `/admin/sales/cart` — when launched from the
  home screen, the user lands directly on the cart (after auth).

**Files modified:**
- `laravel/resources/views/layouts/admin.blade.php` (added
  `@stack('head_meta')`)
- `laravel/resources/views/admin/sales/cart.blade.php` (added
  `@push('head_meta')` + SW registration script)
- `laravel/public/manifest.json` (new)
- `laravel/public/sw.js` (new)
- `laravel/public/assets/images/icon.svg` (new)

---

## 6. Open Work Items

(Items the user has asked for but that are not yet done.)

- **None outstanding.** R1, R2, R3, R4, R5, R6, R10, R10s, R11, R12,
  R13, R14, R15, R16, R17, R18, R19, R20 (via R19), R21, R22, R23,
  R26, R27, R28, and H1 (bugfix) are complete and pushed.
  R24/R25 (Telegram + FCM notifications) were **dropped by user
  request** (2026-07-22) — explicitly NOT being ported. The user
  has not yet assigned R7, R8, R9 (numbers reserved for future
  items; R10+ were the user's explicit asks after R6).

When the user gives the next instruction, append it here as a
checkbox item. When done, move it to the "Completed Work Items"
section below.

---

## 7. Completed Work Items

- [x] **Initial clone** of `sajidchowdhury/debugRC.git` to
      `/home/z/my-project/debugRC/` (done in earlier session).
- [x] **Audit report** `docs/sales_entry_Lg_vs_La.md` produced
      (analysis-only, 9 sections, 1100+ lines).
- [x] **R1** — Replace select2 500-row dropdowns with live search
      endpoints. Committed & pushed. See `REMEDIATION_LOG.md` for
      the full diff summary.
- [x] **R2** — Add idempotency token to payment create (mirror
      finalize pattern — UUID v4 + 5–10 min cache). Applied to both
      the Blade `POST /admin/customer-payments` (10-min cache) and the
      API `POST /api/v1/sales/payments` (5-min cache). Committed &
      pushed. See `REMEDIATION_LOG.md` §R2 for the full diff.
- [x] **R3** — Add idempotency token to challan issue (mirror
      finalize + payment pattern — UUID v4 + 5–10 min cache). Applied
      to both the Blade `POST /admin/sales-challans/issue/{invoiceId}`
      (10-min cache) and the API `POST /api/v1/sales/challans/issue`
      (5-min cache). Committed & pushed. See `REMEDIATION_LOG.md` §R3
      for the full diff.
- [x] **R4** — Add cart mutation audit logging. Extended
      `SalesAuditLogger` with 4 new methods (`cartItemAdded`,
      `cartItemUpdated`, `cartItemRemoved`, `cartCleared`) and wired
      them into `SalesCartService` via DI. Both Blade
      (`SalesCartController`) and API (`SalesCartApiController`)
      paths covered by virtue of the shared service. Committed &
      pushed. See `REMEDIATION_LOG.md` §R4 for the full diff.
- [x] **R5** — Lock the customer row before credit-limit check.
      Added `assertCreditLimitUnderLock()` helper that does
      `Customer::lockForUpdate()->find()` + `checkCreditLimit` +
      throw-on-exceed. Called at the top of the transaction in
      BOTH `finalizeFromCart` and `updateInvoice`. The
      pre-transaction `checkCreditLimit` call is kept for fast
      UX feedback. Committed & pushed. See `REMEDIATION_LOG.md` §R5
      for the full diff.
- [x] **H1 (bugfix)** — Fixed `SQLSTATE[42703]: column "status"
      does not exist` on `customer_payments` queries in
      `CustomerController::show`. Removed broken
      `->whereNotIn('status', ['cancelled'])` filters (the table has
      no `status` column — only `is_reversed`). Also removed dead
      `isDraft/isConfirmed/isCancelled` methods on `CustomerPayment`
      model. Committed & pushed. See `REMEDIATION_LOG.md` §H1 for
      the full diff.
- [x] **R6** — Add `branch_id` to `sales_draft_carts` unique key.
      New migration `2025_01_23_000001_r6_add_branch_id_to_sales_draft_carts_unique_key.php`
      drops `uq_sales_draft_user_customer` and adds
      `uq_sales_draft_user_customer_branch` on `(user_id, customer_id, branch_id)`.
      Also drops the FK on `branch_id` (Legacy doesn't enforce it),
      backfills NULL → 0, and makes the column `NOT NULL DEFAULT 0`
      (Legacy "no specific branch" sentinel). `SalesDraftCart::getOrCreate()`
      updated to include `branch_id` in `firstOrCreate` search attrs +
      normalize null → 0. All `clearCart()` / `setSoftHold()` callers
      updated to pass `branch_id` explicitly (Admin web + API V1 +
      `SalesInvoiceService::finalizeFromCart`). `04_sales.sql` updated
      for fresh installs. Fixes V11, mitigates C7 (Laravel side).
      Committed & pushed. See `REMEDIATION_LOG.md` §R6 for the full diff.
- [x] **R10** — Wire up barcode scanning in the cart blade. R1 had
      ported the Legacy `sales/product_by_code` endpoint to Laravel
      (`SalesCartController::productByCode` →
      `admin.sales.cart.product-by-code`) but left it without a UI
      consumer. R10 adds a toggle-revealed `#barcodeInput` field to
      `cart.blade.php` with an Enter-key + "Scan & Add" button
      handler that calls the existing endpoint. On success: caches
      the product in `productCache`, injects a fresh `<option>` into
      the Select2 and triggers `change` (so rate/qty/availability
      populate via the existing handlers), then auto-adds to cart if
      "Auto-add after scan" is checked (default on). Out-of-stock
      guard matches Legacy `selectProductCreate` (blocks add, shows
      toast). After auto-add the field is cleared and refocused for
      the next scan. No backend changes — purely additive UI. See
      `REMEDIATION_LOG.md` §R10 for the full diff.
- [x] **R11** — Port multi-customer cart tabs. Legacy had a
      `#draft-tabs` dock in `sales/create.php` that let a cashier
      keep N customer-carts open at once, switch between them with
      one click, and close any with a × button. R11 ports this to
      the Laravel cart blade: new `GET /admin/sales/cart/list-drafts`
      endpoint (mirrors Legacy `sales/list_draft_carts`), new
      `SalesCartService::listCarts()` method, new `#draftTabsCard`
      dock above the customer selector with one Bootstrap nav-pill
      per open cart (shop_name + mobile label + item-count badge +
      × close). On page load, `restoreSessionCarts()` fetches
      list-drafts and renders one pill per cart. Clicking a pill
      switches carts in-page (no reload) by setting `#customerSelect`
      and triggering `change`. The × button shows a SweetAlert
      confirm, calls the existing `/cart/clear` endpoint (which
      writes the R4 audit-log entry), then removes the pill and
      switches to the next remaining tab. Badges update on every
      successful cart mutation from the response payload. No new
      migration. See `REMEDIATION_LOG.md` §R11 for the full diff.
- [x] **R12** — Port live customer/product typeahead. Same root
      cause as R1; R1 already wired both Select2 widgets into AJAX
      mode (`minimumInputLength: 1`, `delay: 250`, `processResults`
      populating `customerCache` + `productCache`). Select2 AJAX
      mode *is* a debounced AJAX typeahead — no separate typeahead
      library was introduced. R12 was the audit-tracking label; the
      implementation was the R1 work. Marked ✅ in the audit doc
      §6.1 items #3 + #4 + §9.3 R12 row.
- [x] **R13** — Port price-range slider band UI. New
      `#priceRangePanel` dock in `cart.blade.php` (inside Add
      Product card, below the rate input) renders a visual band:
      grey track + green→purple gradient fill (current rate
      position) + indigo default-rate mark + circular thumb that
      follows `#addRate` on every keystroke (60 ms debounce).
      Min/Max/Default labels in ৳; status badge flips `bg-success`
      / `bg-warning` (within 10 % of min — margin heads-up) /
      `bg-danger` (out of range). "Use default" button snaps rate
      back to `default_rate`. Reads from `productCache` (populated
      by R1 live search + R10 barcode scan) — no extra round-trip.
      Band auto-hides when the product has no usable range. New JS:
      `setActivePriceRange()`, `rateRangeStatus()`,
      `updatePriceBandUi()` + new state field `activePriceRange`.
      No backend changes. See `REMEDIATION_LOG.md` §R13 for the
      full diff.
- [x] **R14** — Port live credit-limit display on cart page. New
      backend endpoint `GET /admin/sales/cart/customer-details`
      (throttle 60/min) + new `SalesCartService::getCustomerDetails()`
      method compute `current_due = SUM(debit) − SUM(credit)` from
      `customer_ledger WHERE is_reversed = false` (same formula as
      `SalesInvoiceService::checkCreditLimit`). New
      `#customerDetailsPanel` in the customer selector card shows
      4 stat cells (Credit limit / Current due / Balance left /
      Cart subtotal) + a projected new balance row (`current_due +
      cart subtotal`) with `bg-success` / `bg-warning` /
      `bg-danger` status. Snapshot fetched once per customer change
      (and on explicit "Refresh" button click); projected row
      recomputes locally on every cart mutation (no extra
      round-trip). New JS: `fetchCustomerDetails()`,
      `renderCustomerDetails()` + new state field `customerCredit`.
      New route `admin.sales.cart.customer-details`. See
      `REMEDIATION_LOG.md` §R14 for the full diff.
- [x] **R15** — Port customer recents chips (localStorage). New
      `#customerRecentsRow` + `#customerRecents` block in the
      customer selector card. New JS `rememberCustomerRecent(id, label)`
      + `loadCustomerRecents()` + `renderCustomerRecents()` mirror
      Legacy `sales.js::rememberCustomerRecent` + `renderCustomerRecents`.
      localStorage key `rcerp_sales_customer_recents` holds
      `[{id, label, ts}, ...]` capped at 5, deduped by id, most-recent
      -first. On every `#customerSelect` change, the picked customer
      is unshifted to the top and the chips re-render. Clicking a chip
      calls the R11 `switchToCustomer(id)` flow. Storage failures
      caught + warned (non-fatal). Fixes audit gap §6.1 item #7.
- [x] **R16** — Port sticky bottom bar (item count + grand total +
      Finalize always visible). New `#posStickyBar` fixed-position
      bottom bar with `#posStickySummary` (item count + subtotal) +
      `#posStickyFinalize` button. CSS in a new `@push('css')` block
      with `position: fixed; bottom: 0; z-index: 1040;
      env(safe-area-inset-bottom)` padding. New JS
      `updatePosStickyBar()` called from `renderAll()` on every cart
      mutation; button enabled iff cart is valid (mirrors
      `#btnFinalize`); clicking calls the same `finalizeInvoice()`
      function — same idempotency-token + credit-check flow. Body
      gets `pos-sticky-visible` class so page padding-bottom (5.5rem)
      keeps the last cart row uncovered. Fixes audit gap §6.1 item #9.
- [x] **R17** — Port mobile-cart cards with swipe-to-delete. Cart
      items now render in TWO views: desktop `<tbody>` (existing,
      wrapped in `.sales-cart-desktop`) + new `#cartItemsMobile` div
      of `.sales-cart-line` cards (Legacy-style: title + delete
      button + rate/qty inputs side-by-side with 44px-min tap
      targets). CSS media query (max-width: 767.98px) toggles which
      is visible. Both views share `.cart-qty`/`.cart-rate`/
      `.cart-remove`/`.cart-total` classes — no duplicated logic.
      `debouncedUpdate()` generalized to look up by `[data-product-id]`
      on any element. New `initCartSwipeRemove()` uses modern Pointer
      Events (touch + pen, ignores mouse): 80px left swipe within
      600ms triggers `.cart-remove` click. Red `::before` pseudo
      -element with trash icon revealed behind card during swipe.
      Fixes audit gap §6.1 item #10.
- [x] **R10s** — Barcode scanning simplified. R10's dual-mode UI
      (separate `#barcodeInput` toggle + Scan & Add button + auto
      -add checkbox) was REMOVED because it duplicated the Select2
      search box. The single `#addProduct` Select2 now doubles as
      the barcode entry via `selectOnClose: true` (scanner Enter
      picks the highlighted first AJAX result) + a delegated
      `keydown` handler on `.select2-search__field` that falls back
      to the R1 `productByCode` endpoint for an exact-code lookup
      when no result is highlighted. New
      `lookupProductByCodeAndSelect(code)` function injects the
      matched product as a fresh `<option>` + triggers `change`
      (so rate/qty/price-band/availability populate via existing
      handlers) + focuses `#addQty`. Same backend (R1's
      `SalesCartController::productByCode` + `findProductByExactCode`)
      — purely a UI simplification, no backend/route/migration
      changes. The user's brief: "keep only product search just
      like customer search, no need 2 option searching product and
      scan, just keep search product and make the UI/UX better
      like lagachy."
- [x] **R18** — Port keyboard shortcuts (Enter on qty → focus rate;
      Enter on rate → Add to Cart; refocus product search after add).
      Mirrors Legacy `sales-create.js` keyboard flow. After product
      pick, `#addQty` is auto-focused + content-selected; Enter on
      `#addQty` focuses + selects `#addRate` (NOT submit — Legacy's
      two-step confirmation pattern); Enter on `#addRate` calls
      `addToCart()`; after successful add, `#addProduct` Select2 is
      re-opened so the cashier can immediately scan/type the next
      product without reaching for the mouse. Select2's built-in
      ArrowUp/ArrowDown/Enter already covers suggestion-list
      navigation that Legacy implemented manually. Closes the
      keyboard-only POS operation gap.
- [x] **R19** — Port inline receive-payment modal on Today's Sales /
      sales-invoices index. New backend endpoint
      `GET /admin/sales-invoices/{id}/receive-modal` returns a Blade
      partial `_receive_modal_body.blade.php` with: invoice summary
      (3 stat cells), payment form (amount with quick-amount chips
      [25%/50%/Full due/Clear], payment mode radio [Cash/Bank/Mobile/
      Cheque], conditional bank+reference panel, notes), and a
      "Payments on this invoice" history list with print-receipt
      buttons. Form posts to the existing `admin.customer-payments.store`
      route (R2 idempotency token, fresh UUID on every open). New
      `SalesInvoice::allocations()` HasMany relationship added.
      Frontend: each row with `due_amount > 0.01 && status !==
      'cancelled' && !is_reversed` gets a green "Receive payment"
      button; clicking fetches the modal body via AJAX. Submit does
      a traditional form POST so the store endpoint's redirect works
      normally. Over-payment triggers a SweetAlert confirm. Closes
      the workflow gap of "navigate to a separate create page, pick
      customer, find invoice in a long list" — now it's a single
      click from the invoice list.
- [x] **R20** — Port quick-amount chips (50% / Full due / Clear).
      Implemented as part of R19 — no separate work. Four chips
      appear below the amount input: 25% (quarter), 50% (half),
      Full due, Clear. Each computes against the current `balance`
      and triggers `input` so the validation hint re-renders. Mirrors
      Legacy `receive_modal.php` L110–114.
- [x] **R21** — Port server-side DataTables with smart sort + smart
      search on the sales-invoices index page. New backend endpoint
      `GET /admin/sales-invoices/datatable` returns DataTables SSP
      JSON (draw / recordsTotal / recordsFiltered / data). New
      `SalesInvoiceController::datatable()` method (~85 lines)
      builds a filter query via shared `buildInvoiceFilterQuery()`
      helper, applies DataTables column ordering OR smart sort OR
      default ordering, paginates via skip/take, and returns row
      data. Smart sort: when `#filterSmartSort` is checked AND no
      column header clicked, server applies
      `CASE WHEN due_amount > 0.01 AND status NOT IN
      ('cancelled','reversed') THEN 0 ELSE 1 END ASC, invoice_date
      ASC, id ASC` — unpaid first, then oldest. Column-click sort
      overrides smart sort. Smart search matches invoice_code +
      customer name/code/mobile + branch name/code (ILIKE). The
      index blade was rewritten: replaced the Blade `@forelse`
      tbody + Laravel paginator with a server-side DataTables
      instance. Filter form (date / customer / branch / search /
      smart_sort / status_chip) is injected into every AJAX request
      via the `data` callback — page never reloads on filter change.
      Smart search input is debounced 320ms. Mirrors Legacy
      `sales/datatable_invoices` endpoint.
- [x] **R22** — Port status chips with live counts on the
      sales-invoices index page. New backend endpoint
      `GET /admin/sales-invoices/summary` returns JSON with counts
      per chip bucket (all, awaiting_payment, draft, confirmed,
      cancelled, reversed) + total_value. New
      `SalesInvoiceController::summary()` method (~30 lines) uses
      shared `buildInvoiceFilterQuery($request, excludeStatusChip:
      true)` so counts are computed against the current filter set
      but NOT against the active chip itself — so the user always
      sees how many invoices are in each bucket without losing
      filter context. Six chips (All / Awaiting payment / Draft /
      Confirmed / Cancelled / Reversed) replace the old Status
      `<select>` dropdown. Each chip has a count badge refreshed via
      AJAX (debounced 280ms) whenever filters change. Clicking a
      chip sets hidden `#status_chip` input + reloads DataTable +
      refreshes summary. Chip colours: All=indigo, Awaiting=red,
      Draft=amber, Confirmed=green, Cancelled=slate, Reversed=dark
      red. Bucket definitions adapted to Laravel's status model
      (draft/confirmed/cancelled + is_reversed flag) since Laravel
      doesn't have Legacy's godown_issued/challan_completed invoice
      statuses. Mirrors Legacy `sales/today_filter_summary` endpoint.
- [x] **R23** — Port mobile cards variant for Today's Sales /
      sales-invoices index. New `#invoiceCards` container above the
      desktop table in `admin/sales-invoices/index.blade.php`,
      hidden on desktop by CSS `@media (max-width: 767.98px)` and
      shown on narrow screens. Populated by DataTables
      `drawCallback` → `renderMobileCards(api)` from the current
      page's data — same data as the desktop table, just a
      different layout. Each card shows: invoice code (link) + date
      + customer name + branch name + status badge + total + due/Paid
      + soft-hold badge + View/Receive buttons. Card left border
      color signals status: red=due, green=paid, slate=cancelled,
      dark red=reversed. Window resize handler (debounced 180ms)
      re-renders cards on viewport changes. The delegated
      `.btn-receive-payment` click handler (from R19) works for both
      desktop table rows AND mobile card buttons (same class) — no
      duplicate wiring needed. Mirrors Legacy
      `sales-today-index.js::renderInvoiceCards`.
- [x] **R24/R25 DROPPED (2026-07-22)** — Telegram + FCM push
      notifications are NOT being ported, per explicit user request.
      Migration `2025_01_20_000010_drop_fcm_and_telegram_fields.php`
      drops `fcm_tokens` table + `users.telegram_user_id` column.
      Stale tests in `tests/Feature/User/{UserValidationTest,
      UserCrudTest, UserAuditTest}.php` cleaned up;
      `Tests\Helpers\InsertsUserDependencies::makeTelegramUser()`
      helper removed. `laravel/public/assets/js/notification.js`
      header comment + `2025_01_09_000003_seed_return_notification_
      rules.php` docblock updated to document the deliberate
      non-implementation. `README.md` "Removed features" + "Manual
      action still required" sections updated to reflect that
      Telegram/FCM are gone entirely (not just "replaced"). Audit
      report `sales_entry_Lg_vs_La.md` §6.2 notifications table +
      §9.3 remediation backlog updated with `~~R24~~` / `~~R25~~`
      struck-through rows pointing at this entry. Laravel's native
      `ERPNotification` + `NotificationService` + `ListenNotifyService`
      (PostgreSQL NOTIFY) cover operational visibility + realtime
      fanout without external chat-bot or web-push infrastructure.
- [x] **R26** — Add `min:10` to `override_reason` validation in
      `FinalizeInvoiceRequest`. Rule is now
      `nullable|string|min:10|max:500` in 3 places: the API Form
      Request + both controller-side `validate()` calls (store +
      update). Mirrors Legacy
      `SalesInvoiceOperationsTrait::finalizeInvoice()` runtime
      `if (strlen($overrideReason) < 10) { return error; }`.
      Service-layer re-check inside the DB transaction (R5
      authoritative re-check) is unchanged — now the request fails
      fast at validation instead of after the credit-limit check.
- [x] **R27** — Add `min:5` to reversal reason in payment cancel.
      Web controller `CustomerPaymentController::cancel()`:
      `cancel_reason` rule changed from `required|string|max:500` →
      `required|string|min:5|max:500`. API controller
      `CustomerPaymentApiController::cancel()`: `reason` rule
      changed from `min:10` → `min:5` (relaxed to match Legacy
      exactly). Mirrors Legacy
      `SalesPaymentOperationsTrait::reverseCustomerPayment()`
      runtime `if (strlen($reason) < 5) { return error; }`.
      Service-layer `CustomerPaymentService::cancelPayment` runs
      unchanged.
- [x] **R28** — Add PWA installability meta tags to cart blade.
      New `@stack('head_meta')` in `layouts/admin.blade.php` `<head>`
      (after the existing meta tags). Cart blade pushes PWA meta via
      `@push('head_meta')`: manifest link + favicon + apple-touch-icon
      + theme-color (#4f46e5) + application-name + mobile-web-app-
      capable + apple-mobile-web-app-capable + apple-mobile-web-app-
      status-bar-style + apple-mobile-web-app-title + msapplication-
      TileColor + msapplication-tap-highlight. New
      `laravel/public/manifest.json` (name=RC ERP — Sales Cart,
      short_name=RC POS, start_url=/admin/sales/cart,
      scope=/admin/sales/, display=standalone, theme_color=#4f46e5,
      background_color=#ffffff, icons SVG 192+512 maskable+any,
      2 shortcuts to Today's Sales + Customer Payments). New
      `laravel/public/sw.js` minimal service worker (cache version
      `rc-erp-pos-v1`, pre-caches 17 offline-shell assets on install,
      cleans old caches on activate, fetch handler: cache-first for
      /assets/* + /manifest.json, network-first with cart-shell
      fallback for HTML navigations, pass-through for everything else
      including all non-GET). New
      `laravel/public/assets/images/icon.svg` POS-themed SVG icon
      (shopping cart on indigo→purple gradient with RC badge,
      512×512, maskable-safe). SW registration snippet added to
      cart blade `@push('scripts')` (feature-detected via
      `'serviceWorker' in navigator` + `window.isSecureContext`,
      registered only on HTTPS/localhost, non-fatal on failure).
      Chrome/Edge now shows the "Install app" prompt on the cart
      page. Fixes audit risk §6.1 item #33 (PWA installability for
      POS kiosk deployment).

---

## 8. Useful Commands

```bash
# Where everything lives
ls /home/z/my-project/debugRC/

# Quick state check
cd /home/z/my-project/debugRC && git log --oneline -10 && git status

# Find a Legacy endpoint's SQL
rg -n "function search_customer|function search_product" legacy/app/controllers/

# Find a Laravel route
rg -n "admin.sales.cart" laravel/routes/web.php

# Push work to GitHub (PAT is provided by the user per-session —
# NEVER commit it to a file)
cd /home/z/my-project/debugRC && git add -A && git commit -m "..." && git push origin main
```

---

## 9. Security Note

The user has shared a GitHub Personal Access Token (PAT) in chat to
allow `git push`. **Never write the PAT to any file in the repo.**
Use it only as a one-shot env var or inline in the push URL:

```bash
git push https://<PAT>@github.com/sajidchowdhury/debugRC.git main
```

The PAT is rotated periodically by the user; if push fails with 401,
ask the user for a fresh PAT.
