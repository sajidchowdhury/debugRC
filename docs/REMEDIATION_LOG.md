# Remediation Log — Legacy → Laravel Sales Module

> Companion to `docs/SESSION_CONTEXT.md` and `docs/sales_entry_Lg_vs_La.md`.
> Each R# item gets its own section. Append new sections at the bottom;
> never edit a closed section.

---

## R1 — Replace select2 500-row dropdowns with live search endpoints

- **Status:** ✅ Done
- **Date:** 2026-07-21
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md` §6 (Missing Features
  in Laravel — "Live search for customer/product dropdowns")
- **Legacy ports:**
  - `sales/search_customer` → `admin.sales.cart.search-customer`
  - `sales/search_product`  → `admin.sales.cart.search-product`
  - `sales/product_by_code` → `admin.sales.cart.product-by-code`

### Problem

The Laravel cart page (`admin/sales/cart`) was pre-loading the entire
active customer list AND the entire active product list (capped at 500
rows each via `Customer::active()->limit(500)` and
`Product::active()->limit(500)` in `SalesCartController::index()`), then
dumping them as `<option>` tags inside two Select2 dropdowns. For any
real-world dataset (>500 customers or products) the dropdowns were:

1. **Incomplete** — the 500-row cap silently dropped rows beyond 500.
2. **Slow** — every page load fetched 1000+ rows from PostgreSQL and
   serialised them into HTML, even though the user typically only
   picks one customer and a handful of products per invoice.
3. **Memory-heavy on the client** — Select2 over 1000 `<option>`s
   noticeably janks the page on low-end devices.
4. **Inconsistent with Legacy** — Legacy uses live AJAX search
   (`sales/search_customer`, `sales/search_product`) returning 20/30
   rows per query, never pre-rendering the full list.

### Solution

Ported the Legacy live-search endpoints to Laravel, then rewired the
Blade Select2 dropdowns to AJAX mode.

### Files modified

1. **`laravel/app/Services/Stock/StockAvailabilityService.php`**
   - Added `searchProductsWithStock(string $term, int $branchId): array`
   - Added `findProductByExactCode(string $code, int $branchId): ?array`
   - Both use `DB::table(...)->leftJoinSub(...)` to express the
     Legacy correlated-subquery SQL in the Laravel query builder.
     The generated SQL is functionally identical to Legacy's
     `StockAvailabilityService::searchProductsWithStock()`.

2. **`laravel/app/Http/Controllers/Admin/SalesCartController.php`**
   - Added `searchCustomer(Request)` — uses `Customer::active()
     ->search($term)` (existing tsvector + GIN scope) and shapes the
     JSON to match Legacy `searchCustomers()`.
   - Added `searchProduct(Request)` — delegates to
     `StockAvailabilityService::searchProductsWithStock()`.
   - Added `productByCode(Request)` — delegates to
     `StockAvailabilityService::findProductByExactCode()`.
   - Added private `resolveBranchIdForRead(int)` helper — mirrors
     Legacy `SalesModel::resolveBranchIdForRead()`.
   - **Removed** the `->limit(500)` queries from `index()`. The
     `customers` and `products` variables are no longer passed to
     the view. Only the single pre-selected customer is loaded
     (so the `<select>` can render its label on first paint).

3. **`laravel/routes/web.php`**
   - Added three routes inside the existing `admin/sales` group
     (so they inherit `role:salesman,manager,admin` +
     `branch.isolation` middleware):
     - `GET cart/search-customer` → `cart.search-customer` (throttle 90/min)
     - `GET cart/search-product`  → `cart.search-product`  (throttle 90/min)
     - `GET cart/product-by-code` → `cart.product-by-code` (throttle 120/min)

4. **`laravel/resources/views/admin/sales/cart.blade.php`**
   - Customer `<select>`: removed the `@foreach ($customers as $customer)`
     loop. Now renders only the pre-selected customer `<option>` (if any).
   - Product `<select>`: removed the `@foreach ($products as $product)`
     loop. Now renders only the empty placeholder `<option>`.
   - Added three new entries to the JS `ENDPOINTS` object:
     `searchCustomer`, `searchProduct`, `productByCode`.
   - Added a new `productCache` JS object (id → full product payload)
     populated by the AJAX `processResults` callback.
   - Converted both Select2 initialisations to AJAX mode
     (`ajax: { url, delay: 250, data, processResults, cache: true }`,
     `minimumInputLength: 1`).
   - Rewrote the `addProduct` change handler to consult `productCache`
     for `default_rate` / `min_rate` / `max_rate` / `available_qty`,
     with graceful fallback to `<option>` data attributes for back-compat.
     Rate hint now shows the min–max range when available.
   - Rewrote `renderAvailability` to label the product from
     `productCache` (with fallbacks to the option text and a bare
     `#id` placeholder), since the option list is no longer
     pre-rendered.

### Verification

- Manual code review of all four files. No syntax errors found.
- Confirmed no remaining references to `$customers` or `$products`
  in `cart.blade.php`.
- Confirmed RBAC inheritance: the new routes are inside the existing
  `admin/sales` route group with `role:salesman,manager,admin` +
  `branch.isolation` middleware.
- Confirmed rate-limit parity with Legacy (90 req/min for searches,
  120 req/min for barcode lookup).
- PHP binary is not available in the sandbox; `php -l` was not run.
  The user should run `php artisan route:list | grep sales.cart` in
  their dev environment to verify the three new routes are registered.

### Risks introduced

- **No new risks.** The new endpoints reuse existing scopes
  (`Customer::scopeSearch`, `Product::scopeSearch`) and existing
  services (`StockAvailabilityService`). No new DB writes, no new
  privileged operations.
- **Minor UX change:** the customer dropdown placeholder now reads
  "Type customer name / code / mobile" instead of "Select a customer"
  (because typing is now the only way to populate it). This is
  intentional and matches Legacy UX.
- **Minor perf consideration:** each search request hits PostgreSQL
  with an ILIKE query (or tsvector query if the migration has run).
  The `throttle:90,1` rate limiter caps abuse. Existing GIN indexes
  on `search_vector` (migration `2025_01_20_000005`) make the
  tsvector path fast.

### Follow-ups (NOT part of R1)

- Wire the barcode-scanner input field (if/when added to the cart
  page) to `admin.sales.cart.product-by-code`.
- Consider porting the same live-search pattern to other Laravel
  Blade pages that still use 500-row dropdowns (e.g. purchase order,
  sales return). Out of scope for R1.

---

## R2 — Add idempotency token to payment create (mirror finalize pattern)

- **Status:** ✅ Done
- **Date:** 2026-07-21
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md`
  - §1 Executive Summary (top risk #4)
  - §3.8 Payment Handling
  - §8 Risks V2 (High) + V6 (Medium)
  - §9 Recommendations table row R2

### Problem

Both payment-create endpoints lacked an idempotency token:

1. **Web** — `POST /admin/customer-payments` (`CustomerPaymentController::store`):
   a regular POST-redirect form. The auto-confirm path means one
   request creates the draft payment AND immediately posts GL +
   customer_ledger + allocations. A double-click on "Record Payment"
   or a refresh-after-submit (browser's "Confirm Form Resubmission")
   would create two payments, two GL entries, two ledger entries, and
   double-allocate against the same invoices.

2. **API** — `POST /api/v1/sales/payments` (`CustomerPaymentApiController::store`):
   the mobile client commonly uses `auto_confirm=true`, which has the
   same create+confirm semantics. A network timeout on the response
   (when the server has actually committed) would cause the mobile
   client to retry — same duplicate-payment problem.

The Legacy system had no idempotency protection here either (audit
risk L12), so this is a net new safety guarantee on the Laravel side.

### Solution

Mirrored the existing finalize pattern (which already protects both
`SalesInvoiceController::finalize` web and `SalesInvoiceApiController::store`
API). The client must send a UUID v4 `idempotency_token`. The server
caches the successful response keyed by that token; a replay within
the TTL returns the cached response instead of creating a second
payment.

### Files modified

1. **`laravel/app/Http/Controllers/Admin/CustomerPaymentController.php`**
   - Added `'idempotency_token' => 'required|string|uuid'` to the
     inline `validate()` rules in `store()`.
   - Added a cache check at the top of `store()`: if
     `Cache::get('payment:' . $token)` is non-null, redirect to the
     cached `admin.customer-payments.show` URL with the cached
     `success` message plus a `warning` flash reading
     "Duplicate submission detected — returning the original result.
     No new payment was created."
   - After the successful `confirmPayment` call, cache
     `['payment_id', 'payment_code', 'success_message']` under
     `'payment:' . $token` for **600 seconds (10 min)** — same TTL
     as the web finalize pattern.
   - Updated the class-level docblock to mention R2.
   - Uses `\Illuminate\Support\Facades\Cache::` (full namespace) for
     consistency with the finalize controller (which does the same).

2. **`laravel/resources/views/admin/customer-payments/create.blade.php`**
   - Added a hidden `<input name="idempotency_token" id="idempotencyToken">`
     immediately after `@csrf` inside `<form id="paymentForm">`.
   - The value is generated server-side via
     `old('idempotency_token', (string) \Illuminate\Support\Str::uuid())`
     so the same UUID survives a validation-failure re-render (the
     user can fix the validation error and resubmit with the same
     token — safe because no cache entry was created on the failed
     attempt).
   - Added an inline `{-- R2 --}}` comment block explaining the
     rationale.
   - **No JS changes** — the existing submit-button disable on
     `$form.on('submit')` already prevents accidental double-clicks
     in the common case. The idempotency token is the
     defense-in-depth backstop for refresh-after-submit, browser-back,
     and network-retry scenarios.

3. **`laravel/app/Http/Requests/Api/V1/Sales/StorePaymentRequest.php`**
   - Added `'idempotency_token' => 'required|string|uuid'` to the
     `rules()` array.
   - Added an `idempotency_token` entry to `bodyParameters()` with
     description and example UUID.
   - Updated the class-level docblock to mention R2 and that the
     token is cached for 5 minutes.

4. **`laravel/app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php`**
   - Added `use Illuminate\Support\Facades\Cache;` to the imports.
   - Added a cache check at the top of `store()`: if
     `Cache::get('api:payment:' . $token)` is non-null, return the
     cached JSON payload merged with `idempotent_replay: true` and a
     replay `message`.
   - The idempotency check runs **before** `assertBranchAccessible()`
     so a replay is fully side-effect-free (no DB reads, no locks).
   - After the successful create(+optional confirm) call, cache the
     full response payload under `'api:payment:' . $token` for
     **5 min** (`now()->addMinutes(5)`) — same TTL as the API finalize.
   - Updated the class-level docblock to mention R2.

### Verification

- Manual code review of all four files. No syntax errors found.
- Confirmed the validation rule name (`idempotency_token`) and the
  cache-key prefixes (`payment:` / `api:payment:`) are consistent
  with the existing finalize convention (`finalize:` / `api:finalize:`).
- Confirmed the TTLs (10 min web, 5 min API) match the existing
  finalize TTLs.
- Confirmed the `layouts/admin.blade.php` already renders
  `session('warning')` flashes, so no view-layer changes were needed
  for the warning to appear on the show page after a replay.
- Searched `laravel/tests/` for any existing tests that POST to
  `customer-payments/store` or `api/v1/sales/payments` — none found,
  so no tests needed updating. (Future R# may add idempotency-replay
  tests per audit recommendation R44.)
- PHP binary is not available in the Z.ai sandbox; `php -l` was not
  run. The user should run `php artisan route:list` and
  `php artisan test` in their dev environment to verify.

### Risks introduced

- **No new risks.** The idempotency layer is purely additive: it
  short-circuits duplicate submissions and otherwise leaves the
  existing payment flow untouched. `CustomerPaymentService` was not
  modified.
- **Minor cache-storage consideration:** each successful payment
  creates one cache entry (web: 10 min, API: 5 min). At any realistic
  submit volume this is negligible. Laravel's default cache driver
  (file/database/redis) handles this trivially.
- **Mobile client contract change (breaking):** the API now REQUIRES
  `idempotency_token` in the request body. Mobile clients that do
  not send it will receive a 422 validation error. **Action item for
  the user:** deploy the mobile-app update that generates and sends
  a UUID v4 per payment request before deploying this server change
  to production. (The web Blade generates the UUID automatically, so
  no browser-side action is needed.)

### Follow-ups (NOT part of R2)

- **R3 (next):** apply the same pattern to `POST /admin/sales-challans/issue/{invoiceId}` (audit risk V3).
- **R44 (audit recommendation):** add integration tests that submit
  the same `idempotency_token` twice and assert the second response
  is the cached one (no duplicate row in `customer_payments`).
- Consider adding a short TTL cache (30 s) on `POST /admin/customer-payments/{id}/cancel`
  as well — currently a double-click on "Cancel Payment" would fire
  two POSTs, but the second would fail with "payment is already
  reversed" because of the `is_reversed` check. So this is low priority.

---

## R3 — Add idempotency token to challan issue

- **Status:** ✅ Done
- **Date:** 2026-07-21
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md`
  - §3.7 (Laravel Workflow — Issue Challan step)
  - §8 Risks V3 (Medium)
  - §9 Recommendations table row R3

### Problem

The challan-issue endpoints lacked an idempotency token:

1. **Web** — `POST /admin/sales-challans/issue/{invoiceId}` (`SalesChallanController::issueChallan`):
   a regular POST-redirect form. The service call inside does
   significant work in one transaction: locks the invoice FOR UPDATE,
   creates a `sales_challan` header, moves stock OUT via
   `StockService::applyTransaction` (decrementing `warehouse_stock.qty`
   and inserting immutable `stock_transactions` rows), posts a COGS
   GL journal entry (Dr COGS / Cr Inventory), updates
   `sales_invoice_dispatches` to mark items as dispatched, optionally
   posts a transport-cost adjustment journal + `customer_ledger`
   `invoice_adjustment` entry, and sets `is_challan_issued=true` on
   the invoice.
   A double-click on "Yes, issue challan" (the SweetAlert confirm) or
   a refresh-after-submit would attempt all of this twice. The
   `is_challan_issued` check inside the lock catches the duplicate
   and throws `RuntimeException("Challan already issued for this invoice.")`,
   but the user sees an error flash — confusing UX, since the first
   submission actually succeeded.

2. **API** — `POST /api/v1/sales/challans/issue` (`SalesChallanApiController::issue`):
   the mobile client typically issues a challan immediately after
   preparing godown. A network timeout on the response (when the
   server has actually committed) would cause the mobile client to
   retry — same "Challan already issued" 409 error, same confusing UX.

The audit (§8 V3) rated this Medium severity because the
service-level guard does prevent duplicate stock movements and GL
entries — the real impact was poor UX, not data corruption. R3 fixes
both the UX (silent replay with a warning) and provides a
defense-in-depth layer in front of the service.

### Solution

Mirrored the existing finalize (P2-6) and payment-create (R2)
patterns. The client must send a UUID v4 `idempotency_token`. The
server caches the successful response keyed by that token; a replay
within the TTL returns the cached response (or redirects to the
cached challan's show page) instead of calling the service.

### Files modified

1. **`laravel/app/Http/Controllers/Admin/SalesChallanController.php`**
   - Added `use Illuminate\Support\Facades\Cache;` to the imports.
   - Added `'idempotency_token' => 'required|string|uuid'` to the
     inline `validate()` rules in `issueChallan()`.
   - Added a cache check at the top of `issueChallan()`: if
     `Cache::get('challan:' . $token)` is non-null, redirect to the
     cached `admin.sales-challans.show` URL with the cached `success`
     message plus a `warning` flash reading "Duplicate submission
     detected — returning the original result. No new challan was
     created."
   - The cache check runs BEFORE the service call, so a replay is
     fully side-effect-free (no DB reads, no locks, no stock reads).
   - After the successful `issueChallan` service call, cache
     `['challan_id', 'challan_code', 'success_message']` under
     `'challan:' . $token` for **600 seconds (10 min)** — same TTL
     as the web finalize + web payment patterns.
   - Updated the class-level docblock to mention R3.

2. **`laravel/resources/views/admin/sales-challans/issue.blade.php`**
   - Added a hidden `<input name="idempotency_token" id="idempotencyToken">`
     immediately after `@csrf` inside `<form id="issueForm">`.
   - The value is generated server-side via
     `old('idempotency_token', (string) \Illuminate\Support\Str::uuid())`
     so the same UUID survives a validation-failure re-render.
   - Added an inline `{-- R3 --}}` comment block explaining the
     rationale.
   - **No JS changes** — the existing SweetAlert-confirm-then-submit
     flow already prevents accidental double-clicks in the common
     case (the confirm dialog swallows the first click). The
     idempotency token is the defense-in-depth backstop for
     refresh-after-submit, browser-back, and network-retry scenarios.

3. **`laravel/app/Http/Requests/Api/V1/Sales/IssueChallanRequest.php`**
   - Added `'idempotency_token' => 'required|string|uuid'` to the
     `rules()` array.
   - Added an `idempotency_token` entry to `bodyParameters()` with
     description and example UUID.
   - Updated the class-level docblock to mention R3 and that the
     token is cached for 5 minutes.

4. **`laravel/app/Http/Controllers/Api/V1/Sales/SalesChallanApiController.php`**
   - Added `use Illuminate\Support\Facades\Cache;` to the imports.
   - Added a cache check at the top of `issue()`: if
     `Cache::get('api:challan:' . $token)` is non-null, return the
     cached JSON payload merged with `idempotent_replay: true` and a
     replay `message`.
   - The idempotency check runs **before** `SalesInvoice::findOrFail()`
     and `assertBranchAccessible()` so a replay is fully
     side-effect-free.
   - After the successful `issueChallan` service call, cache the
     full response payload under `'api:challan:' . $token` for
     **5 min** (`now()->addMinutes(5)`) — same TTL as the API finalize
     + API payment patterns.
   - Updated the class-level docblock to mention R3.

### Verification

- Manual code review of all four files. No syntax errors found.
- Confirmed the validation rule name (`idempotency_token`) and the
  cache-key prefixes (`challan:` / `api:challan:`) are consistent
  with the existing finalize (`finalize:` / `api:finalize:`) and
  payment (`payment:` / `api:payment:`) conventions.
- Confirmed the TTLs (10 min web, 5 min API) match the existing
  finalize + payment TTLs.
- Confirmed the `layouts/admin.blade.php` already renders
  `session('warning')` flashes, so no view-layer changes were needed
  for the warning to appear on the challan show page after a replay.
- Searched `laravel/tests/` for any existing tests that POST to
  `sales-challans/issue` or `challans/issue` — none found, so no
  tests needed updating.
- Confirmed `SalesChallanService::issueChallan()` was NOT modified.
  The service still has its `is_challan_issued` guard inside the
  `lockForUpdate()` transaction; R3 sits in front of it as a
  friendlier fast-path. The guard remains as the last line of
  defense against any race that somehow bypasses the cache.
- PHP binary is not available in the Z.ai sandbox; `php -l` was not
  run. The user should run `php artisan route:list` and
  `php artisan test` in their dev environment to verify.

### Risks introduced

- **No new risks.** The idempotency layer is purely additive: it
  short-circuits duplicate submissions and otherwise leaves the
  existing challan-issue flow untouched. `SalesChallanService` was
  not modified.
- **Minor cache-storage consideration:** each successful challan
  issue creates one cache entry (web: 10 min, API: 5 min). At any
  realistic submit volume this is negligible.
- **Mobile client contract change (breaking):** the API now REQUIRES
  `idempotency_token` in the request body. Mobile clients that do
  not send it will receive a 422 validation error. **Action item for
  the user:** deploy the mobile-app update that generates and sends
  a UUID v4 per challan-issue request before deploying this server
  change to production. (The web Blade generates the UUID
  automatically, so no browser-side action is needed.)
- **UX change for web users (positive):** a refresh-after-submit on
  the challan issue form previously showed an error flash ("Challan
  already issued for this invoice"). It now silently redirects to
  the already-issued challan's show page with a warning flash. This
  matches how finalize + payment already behave.

### Follow-ups (NOT part of R3)

- **R4 (next):** add cart mutation audit logging (audit risk V4).
- **R44 (audit recommendation):** add integration tests that submit
  the same `idempotency_token` twice and assert the second response
  is the cached one (no duplicate row in `sales_challans`).
- Consider adding a short TTL cache (30 s) on
  `POST /admin/sales-challans/{id}/cancel` as well — currently a
  double-click on "Cancel Challan" would fire two POSTs, but the
  second would fail with "challan is already reversed" because of
  the `is_reversed` check. So this is low priority.
- The `godown` (step 1) endpoint does NOT have an idempotency token.
  It uses `sync()` semantics for `sales_invoice_dispatches`, which
  is idempotent by accident (same end state on resubmit). If the
  user wants symmetry, R-later could add one — but it's not
  currently a risk.


---

## R4 — Cart mutation audit logging

**Date:** 2026-07-21
**Status:** ✅ Done
**Audit risk closed:** V4 (Medium) — "Cart mutations not audit logged"
**Audit risk mitigated:** C2 (Medium) — "No cart mutation audit log" (Laravel side only; Legacy still has no equivalent)

### Problem

`SalesCartService` mutates `sales_draft_carts.items_json` on every
add / update / remove / clear, but no audit trail was written. If a
salesman tampered with another salesman's cart (e.g. via shared
user_ids or session hijack), there was no trail. The Legacy system
had the same gap (common risk C2).

Cart mutations are not financially material — no GL posting, no
customer-ledger entry, no stock movement happens until finalize —
so an **idempotency token** (R2 / R3 pattern) is not the right fix
here. The right fix is an **audit trail** of who changed what and
when, so any later dispute about an unexpected cart state can be
reconstructed.

### Solution

1. **Extended `SalesAuditLogger`** with four new methods, mirroring
   the existing `saleCreated` / `paymentReceived` / etc.
   convention:

   | Method            | Action            | Captures                                                                                                          |
   |-------------------|-------------------|-------------------------------------------------------------------------------------------------------------------|
   | `cartItemAdded`   | `cart_item_added` | customer_id, product_id, qty_added, rate, merged (bool), cart_item_count, cart_subtotal                           |
   | `cartItemUpdated` | `cart_item_updated` | customer_id, product_id, old_qty, new_qty, old_rate, new_rate, cart_subtotal                                    |
   | `cartItemRemoved` | `cart_item_removed` | customer_id, product_id, removed_qty, removed_rate, removed_total, cart_item_count, cart_subtotal               |
   | `cartCleared`     | `cart_cleared`    | customer_id, items_cleared_count, items_cleared_value                                                             |

   All four delegate to `UserAuditLogger::log()` which dual-writes
   (PG `user_audit_log` table + `logs/user_audit.log` file).

2. **Updated `SalesAuditLogger::recentSalesEvents`** to include
   the four new action names so any future audit dashboard sees them.

3. **Wired the logger into `SalesCartService`** via DI:
   - Added `private SalesAuditLogger $auditLogger` to the constructor.
   - `addItem` now calls `cartItemAdded` after a successful save
     (with the `merged` flag set appropriately).
   - `updateItem` captures `old_qty` / `old_rate` before overwriting
     and calls `cartItemUpdated` with before+after values.
   - `removeItem` captures the removed line's `qty` / `rate` and
     calls `cartItemRemoved`.
   - `clearCart` captures the count + value of items being cleared
     and calls `cartCleared`.

4. **Backward-compatible signature change to `clearCart`:**
   - Before: `clearCart(int $userId, int $customerId): array`
   - After:  `clearCart(int $userId, int $customerId, ?int $branchId = null): array`
   - Both existing callers (`SalesCartController::clear`,
     `SalesCartApiController::clear`) pass only two args; the audit
     row reads `$cart->branch_id` for the branch context.

### Files modified

| File | Change |
|------|--------|
| `laravel/app/Services/Sales/SalesAuditLogger.php` | Added 4 new public methods (`cartItemAdded`, `cartItemUpdated`, `cartItemRemoved`, `cartCleared`); updated class docblock; added 4 new action names to `recentSalesEvents` whitelist. |
| `laravel/app/Services/Sales/SalesCartService.php` | Injected `SalesAuditLogger` via DI; added audit calls in `addItem` / `updateItem` / `removeItem` / `clearCart`; updated class docblock; expanded `clearCart` signature with optional `?int $branchId = null` parameter. |

### Files NOT modified (deliberately)

- `laravel/app/Http/Controllers/Admin/SalesCartController.php` — no
  controller changes needed; the audit calls live in the service.
- `laravel/app/Http/Controllers/Api/V1/Sales/SalesCartApiController.php` —
  same; the shared service covers both surfaces transparently.
- `laravel/app/Models/SalesDraftCart.php` — no model changes needed.
- `laravel/app/Services/Auth/UserAuditLogger.php` — already supports
  arbitrary action strings; no schema change needed.
- `legacy/` — out of scope; Legacy has no equivalent audit log
  infrastructure.

### Verification

- PHP binary not available in the Z.ai sandbox — `php -l` and
  `php artisan test` could not be run. User should verify in their
  dev environment:
  ```bash
  cd laravel
  php -l app/Services/Sales/SalesAuditLogger.php
  php -l app/Services/Sales/SalesCartService.php
  php artisan test
  # Smoke-test: add an item to a cart and check
  psql -c "select * from user_audit_log where action like 'cart_%' order by id desc limit 5;"
  ```

- Manual verification steps:
  1. Open the cart page (`/admin/sales/cart?customer_id=X`).
  2. Add a product → check `user_audit_log` for a `cart_item_added` row.
  3. Edit qty → check for a `cart_item_updated` row with `old_qty` ≠ `new_qty`.
  4. Remove the product → check for a `cart_item_removed` row.
  5. Add 2 products then Clear → check for a `cart_cleared` row with
     `items_cleared_count = 2` and `items_cleared_value` matching
     the sum of (qty × rate).
  6. Repeat steps 2-5 via the mobile API (`POST /api/v1/sales/cart`,
     `PUT`, `DELETE`, `POST /cart/clear`) and confirm the same
     audit rows are written.

### Risks introduced

- **Mild audit-row volume increase.** A busy salesman editing a cart
  dozens of times in a session will produce dozens of audit rows.
  This is the intended behavior (the audit trail is the point), but
  if storage becomes a concern, consider:
  - Periodic archival of `user_audit_log` rows older than N days.
  - Or, sampling (only log 1-in-N updates for the same product in
    a short window) — but this defeats the audit purpose, so it's
    not recommended.

- **No transactional guarantee between cart save and audit insert.**
  If `UserAuditLogger::log()` fails AFTER `$cart->save()` succeeds
  (e.g., the DB goes down between the two writes), the cart will
  have a mutation without an audit row. This is an acceptable
  trade-off because:
  - The audit insert is a single INSERT — unlikely to fail unless
    the DB is down, in which case the user's request errors out
    anyway.
  - Wrapping both in `DB::transaction()` would be a larger refactor
    across all 4 methods.
  If stronger guarantees are needed, R-later could wrap each
  mutation in `DB::transaction(fn() => ...)`.

- **No new breaking API contract change.** Unlike R2/R3, R4 does
  NOT add a required `idempotency_token` field, so mobile clients
  do not need to be updated. R4 is a pure server-side observability
  improvement.

### Follow-ups (NOT part of R4)

- **R5 (next, TBD):** Likely candidate is to lock the customer row
  before the credit-limit check (audit risk V5 / common risk C1).
  Add `Customer::lockForUpdate()->find($customerId)` inside the
  finalize transaction, before `checkCreditLimit`.

- **R44 (audit recommendation, carried from R3):** Add integration
  tests for the idempotency token (R2 / R3) — duplicate submission
  with same token should return cached result, not create a new row.

- **R45 (new, suggested):** Add integration tests for the cart
  audit logging (R4) — verify each mutation writes the expected
  audit row with the expected `details` JSON.

- Consider exposing a `GET /admin/sales-audit/cart-events` page
  that calls `SalesAuditLogger::recentSalesEvents()` filtered to
  the 4 cart actions, so managers can review cart tampering without
  querying the DB directly.


---

## R5 — Lock customer row before credit-limit check

**Date:** 2026-07-21
**Status:** ✅ Done
**Audit risk closed:** V5 (Medium) — "Credit-limit check race"
**Audit risk mitigated:** C1 (Medium) — "Credit-limit check before customer row lock" (Laravel side only; Legacy still has the race)

### Problem

`SalesInvoiceService::checkCreditLimit` was called BEFORE the
DB transaction opened. Two concurrent `finalizeFromCart` (or
`updateInvoice`) calls for the same customer could both:

1. Read the same `customer_ledger` SUM (debit − credit).
2. Both pass the credit-limit check.
3. Both open their transactions.
4. Both post new `customer_ledger` debit entries.
5. Both commit — and the customer's AR balance now exceeds the
   credit limit.

The existing per-product `lockForUpdate` in the finalize transaction
serializes concurrent finalizes that touch the same products, but it
does nothing for two finalizes against the same customer with
**different** products.

### Solution

The credit-limit check is now performed TWICE:

1. **OUTSIDE the transaction** (kept from the original code) —
   fast-fail UX gate. No lock, no transaction. Gives the user
   immediate feedback ("credit limit exceeded, override?") without
   paying the cost of opening a transaction for the common
   rejection case. This check is non-authoritative.

2. **INSIDE the transaction** (new) — authoritative. Performed
   immediately after `Customer::lockForUpdate()->find($customerId)`.
   The row lock serializes concurrent finalize/edit calls for the
   same customer — the second one blocks until the first transaction
   commits or rolls back, then re-reads the (now-updated)
   `customer_ledger` SUM.

Both checks call the existing `checkCreditLimit()` helper. The
in-transaction check lives in a new private method
`assertCreditLimitUnderLock()` that also handles the override
semantics (override allowed if `credit_limit_override=true` AND
`override_reason` is ≥ 10 chars).

### The new helper

```php
private function assertCreditLimitUnderLock(
    int $customerId, float $amount,
    bool $isOverride, string $overrideReason,
    string $messageTpl
): void {
    $customer = Customer::lockForUpdate()->find($customerId);
    if (!$customer) {
        throw new \RuntimeException("Customer {$customerId} not found while locking for credit check.");
    }

    $creditCheck = $this->checkCreditLimit($customerId, $amount);

    if (!$creditCheck['exceeds']) return;

    if ($isOverride && strlen($overrideReason) >= 10) return;

    if ($isOverride && strlen($overrideReason) < 10) {
        throw new \RuntimeException(
            'Override reason must be at least 10 characters when exceeding credit limit.'
        );
    }

    throw new \RuntimeException(sprintf(
        $messageTpl,
        $creditCheck['current_balance'],
        $creditCheck['credit_limit'],
        $amount
    ));
}
```

The `$messageTpl` parameter lets the two callers (`finalizeFromCart`
and `updateInvoice`) use their own wording in the error message
without duplicating the lock + check + override logic.

### Files modified

| File | Change |
|------|--------|
| `laravel/app/Services/Sales/SalesInvoiceService.php` | Added `use App\Models\Customer;` import. Updated class docblock. Added `assertCreditLimitUnderLock()` private method. Called it at the top of the transaction in both `finalizeFromCart` (after `Customer::lockForUpdate()->find()`) and `updateInvoice` (same). The pre-transaction `checkCreditLimit` calls are kept with a comment explaining they are non-authoritative fast-fail UX gates. |

### Files NOT modified (deliberately)

- `laravel/app/Services/Sales/CustomerPaymentService.php` — payments
  don't increase the customer's AR balance (they credit it), so the
  credit-limit check doesn't apply.
- `laravel/app/Services/Sales/SalesChallanService.php` — challan
  issue doesn't change the AR balance (the GL was already posted at
  finalize). No credit-limit check there.
- `legacy/` — out of scope. The Legacy system has the same race
  (common risk C1 applies to both). A future R# item could port
  this fix.

### Verification

- PHP binary not available in the Z.ai sandbox — `php -l` and
  `php artisan test` could not be run. User should verify in their
  dev environment:
  ```bash
  cd laravel
  php -l app/Services/Sales/SalesInvoiceService.php
  php artisan test
  ```

- Manual race-condition test (best done in dev):
  1. Set a customer's `credit_limit` to 1000.
  2. Confirm their AR balance is 0.
  3. Open two `psql` sessions, both `BEGIN;`.
  4. In session 1: `SELECT * FROM customers WHERE id = X FOR UPDATE;`
     (this holds the lock).
  5. In session 2: try to finalize an invoice for customer X via
     the web UI — the request should hang (blocked on the lock).
  6. In session 1: `INSERT INTO customer_ledger (...) VALUES (...);`
     (a 600 debit) then `COMMIT;`.
  7. Session 2 should now resume, re-read the customer_ledger SUM
     (which is now 600), and the credit check should pass for an
     invoice of 300 (total 900 ≤ 1000) but fail for an invoice of
     500 (total 1100 > 1000).

### Risks introduced

- **Mild contention on `customers` row for high-volume customers.**
  If many salesmen are simultaneously finalizing invoices for the
  same customer (e.g. a chain store with multiple cashiers on the
  same account), they will serialize on the customer row lock.
  This is the intended trade-off — without the lock, the
  credit-limit check is meaningless under concurrency. The lock
  is held for the duration of the finalize transaction (~10–50ms
  typical), so contention should be minimal in practice.

- **One extra `checkCreditLimit` query per finalize/edit.** The
  pre-transaction call was already there; R5 adds the in-transaction
  call. The query is `SELECT SUM(debit) - SUM(credit) FROM
  customer_ledger WHERE customer_id = ? AND is_reversed = false`
  — runs in ~1ms with the existing `idx_cl_customer` index. The
  `Customer::lockForUpdate()->find()` is also a single-row primary
  key lookup. Negligible overhead.

- **Potential for deadlocks if other code paths also lock the
  customer row.** The `Customer` model is widely used but
  `lockForUpdate()` on it was not common before R5. A grep for
  `Customer::lockForUpdate` showed no other call sites in the
  codebase, so deadlock risk is low. If future code adds another
  `Customer::lockForUpdate()` call, ensure the lock ordering is
  consistent (customer row first, then product rows, then invoice
  row — which is what R5 does).

### Follow-ups (NOT part of R5)

- **R6 (next, TBD):** Likely candidate is to add `branch_id` to
  the `sales_draft_carts` unique key (audit risk V11 / common
  risk C7 — prevents cross-branch cart contamination).

- **R43 (audit recommendation, carried from before):** Add
  integration tests for the credit-limit race (R5) — two
  concurrent finalizes for the same customer.

- **R44 (audit recommendation):** Add integration tests for the
  idempotency token (R2 / R3) — duplicate submission with same
  token.

- **R45 (new, suggested):** Add integration tests for the cart
  audit logging (R4) — verify each mutation writes the expected
  audit row with the expected `details` JSON.

- Consider porting the R5 fix to the Legacy system. Legacy
  `customer_ledger.running_balance` race is a similar problem
  (audit risk L1, top of the Legacy risks list) — a customer-row
  lock before reading `running_balance` would close it.


---

## H1 — Hotfix: `customer_payments.status` column does not exist

**Date:** 2026-07-21 (reported and fixed in the same session as R5)
**Status:** ✅ Done
**Type:** Production bug (not part of the R# backlog)

### Problem reported by user

When setting up / viewing a new customer, the customer "360° Hub"
page (`CustomerController::show`) threw:

```
SQLSTATE[42703]: Undefined column: 7 ERROR: column "status" does
not exist
LINE 1: ...ere "customer_id" = $1 and "is_reversed" = $2 and "status" n...
^ (Connection: pgsql, Host: rcerp_postgres, Port: 5432, Database:
rcerp, SQL: select sum("amount") as aggregate from
"customer_payments" where "customer_id" = 454 and "is_reversed" = 0
and "status" not in (cancelled) and "customer_payments"."deleted_at"
is null)
```

### Root cause

`CustomerController::show` had three KPI queries with broken
`->whereNotIn('status', ...)` filters:

1. `CustomerPayment::where('customer_id', ...)->where('is_reversed', false)->whereNotIn('status', ['cancelled'])->sum('amount')`
   — **broken**: `customer_payments` table has NO `status` column.
   Schema is `is_reversed boolean` + `reversed_at` + `reversed_by`
   + `reverse_reason` only. This was the user's reported error.

2. `CustomerPayment::...->whereNotIn('status', ['cancelled'])->orderBy('payment_date', 'desc')->first()`
   — **same bug** on the `lastPayment` query.

3. `SalesReturn::...->whereNotIn('status', ['cancelled'])->sum('total_amount')`
   — `sales_returns` DOES have a `status` column, but its CHECK
   constraint only allows `('created','confirmed','reversed')`.
   `'cancelled'` is not a valid value, so this filter was a no-op
   (filtered out nothing) but didn't error. Misleading code.

The `CustomerPayment` model also had three dead methods — `isDraft()`,
`isConfirmed()`, `isCancelled()` — that all referenced `$this->status`,
which would also error out if any code called them. A grep confirmed
no callers anywhere in the codebase.

### Fix

| File | Change |
|------|--------|
| `laravel/app/Http/Controllers/Admin/CustomerController.php` | Removed `->whereNotIn('status', ['cancelled'])` from the 2 `CustomerPayment` queries in `show()` (totalPaid + lastPayment). Removed `->whereNotIn('status', ['cancelled'])` from the `SalesReturn` query in `show()` (totalReturns). `is_reversed = false` already excludes reversed/cancelled rows on both tables. |
| `laravel/app/Models/CustomerPayment.php` | Removed the 3 dead methods `isDraft()`, `isConfirmed()`, `isCancelled()` (they referenced a non-existent `status` column). Updated class docblock to clarify that `customer_payments` has no `status` column — only `is_reversed`. Added an inline comment marking the removal. |

### Files NOT modified

- `SalesInvoice` / `SalesReturn` / `DamageInvoice` / `WarehouseTransfer` / `CommissionEntry` models — they all have a `status` column on their respective tables, so their `isDraft/isConfirmed/isCancelled` methods are valid.
- `CustomerPaymentApiController.php` line 256 — `whereNotIn('status', ['cancelled'])` is on `SalesInvoice`, not `CustomerPayment`. Valid.
- All other `whereNotIn('status', ...)` usages across the codebase — verified to be on tables that actually have a `status` column (`sales_invoices`, `purchase_orders`, `purchase_receives`, `purchase_returns`, `damages`, `warehouse_transfers`, `stock_take_sessions`).

### Verification

- Manual: open any customer's "360° Hub" page (`/admin/customers/{id}`)
  — the page should now load without the SQL error. The `totalPaid`
  and `lastPayment` KPIs should reflect the customer's non-reversed
  payments (same as before the bug, since `is_reversed = false` was
  already in the WHERE clause).
- For a brand-new customer with no payments, both `totalPaid` and
  `lastPayment` should be 0 / null respectively — the page should
  load cleanly.

### Risks introduced

- **None.** The fix is purely removing broken filters. The
  `is_reversed = false` predicate was already present and is the
  correct way to exclude cancelled/reversed payments on
  `customer_payments` and `sales_returns`.

### Follow-ups

- Consider adding a CI check (e.g., a Laravel static-analysis rule
  or a test that introspects table columns) that flags
  `->whereNotIn('status', ...)` calls on models whose table doesn't
  have a `status` column. This would prevent the same bug class
  from recurring.

- Other Laravel models that may have similar vestigial
  `isDraft/isConfirmed/isCancelled` methods referencing
  non-existent `status` columns should be audited. A quick grep
  shows `CustomerPayment` was the only one, but the same pattern
  may exist for other tables.


---

## R6 — Add branch_id to sales_draft_carts unique key

- **Status:** ✅ Done
- **Date:** 2026-07-21
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md` §8.2 V11 (Laravel)
  + §8.3 C7 (common). Also §9.1 R6 (Priority 1 — Critical Fix).

### Problem

The `sales_draft_carts` unique constraint was
`UNIQUE (user_id, customer_id)` — `branch_id` was stored on the row
but NOT part of the key. This meant a salesman switching branches
with the same customer would share the SAME cart row, causing
cross-branch stock reservation contamination (the cart's items
could reference stock from the wrong branch).

The Laravel `04_sales.sql` also declared `branch_id` as nullable
with an FK to `branches(id)` — an oversight vs the Legacy schema
(`020_sales_draft_carts.sql`) which declares it `INT NOT NULL
DEFAULT 0` (no FK). PostgreSQL `UNIQUE` treats NULL as distinct,
so even if we just added `branch_id` to the unique key without
the NOT NULL change, two rows with `(user_id=5, customer_id=10,
branch_id=NULL)` would not conflict — defeating the purpose.

### Solution

Extended the unique key from 2 columns to 3 columns, and aligned
the column with Legacy semantics (`NOT NULL DEFAULT 0`, no FK).
A salesman switching branches now gets a separate cart per branch —
matching Legacy behavior.

### Files modified

1. **`laravel/database/migrations/2025_01_23_000001_r6_add_branch_id_to_sales_draft_carts_unique_key.php`** (NEW)
   - Drops the FK on `branch_id` (name looked up dynamically from
     `pg_constraint` — typically `sales_draft_carts_branch_id_foreign`).
   - Backfills `NULL → 0` (defensive — in practice a no-op).
   - `ALTER COLUMN branch_id SET NOT NULL` + `SET DEFAULT 0`.
   - Drops old `uq_sales_draft_user_customer` (2-column).
   - Adds new `uq_sales_draft_user_customer_branch` (3-column).
   - All DDL wrapped in a single `DB::transaction()` so a failure
     at any step rolls back the entire migration.
   - Down migration reverts everything (re-creates the old 2-column
     constraint, drops NOT NULL + DEFAULT, re-creates the FK
     best-effort with a warning if it fails due to `branch_id=0`
     sentinel rows).

2. **`laravel/database/sql/04_sales.sql`**
   - `sales_draft_carts` schema updated for fresh installs:
     - `branch_id integer NOT NULL DEFAULT 0` (was `integer REFERENCES branches(id)`)
     - Constraint renamed `uq_sales_draft_user_customer` → `uq_sales_draft_user_customer_branch`
     - Added inline comment explaining R6 + the `0` sentinel.

3. **`laravel/app/Models/SalesDraftCart.php`**
   - Class docblock updated: "Per-user-per-customer-per-branch" +
     R6 explanation.
   - `@property int $branch_id` (was `int|null`).
   - `getOrCreate()` now:
     - Normalizes `null → 0` (matches DB `DEFAULT 0`).
     - Includes `branch_id` in `firstOrCreate` 1st arg (search attrs).
     - Removes `branch_id` from 2nd arg (no longer needed — it's
       in the search attrs).

4. **`laravel/app/Services/Sales/SalesCartService.php`**
   - `setSoftHold()` signature changed: `?int $branchId = null`
     added as 4th parameter (was 3 parameters). Null is normalized
     to 0 inside `SalesDraftCart::getOrCreate()`.
   - Docblock updated with R6 note.

5. **`laravel/app/Services/Sales/SalesInvoiceService.php`**
   - `finalizeFromCart()` Step 10 (clear the cart) now passes
     `$branchId` explicitly to `clearCart()`. This fixes a latent
     bug: before R6, `clearCart` was called without `branch_id`,
     which worked only because the 2-column unique key made the
     search branch-agnostic. After R6, omitting `branch_id` would
     normalize to 0 and target a non-existent cart at `branch_id=0`,
     leaving the actual cart un-cleared.

6. **`laravel/app/Http/Controllers/Admin/SalesCartController.php`**
   - `clear()` now reads `session('branch_id', 0)` and passes it to
     `clearCart()`.
   - `softHold()` now reads `session('branch_id', 0)` and passes it
     to `setSoftHold()`.

7. **`laravel/app/Http/Controllers/Api/V1/Sales/SalesCartApiController.php`**
   - `clear()` now calls `$this->resolveBranchId($request)` and
     passes it to `clearCart()`.
   - `softHold()` now calls `$this->resolveBranchId($request)` and
     passes it to `setSoftHold()`.

### Files NOT modified

- `laravel/app/Http/Controllers/Admin/SalesCartController.php` `index()`,
  `addItem()`, `updateItem()`, `removeItem()`, `validateCart()` —
  these already pass `$branchId` (read from `session('branch_id', 0)`)
  to the service. No change needed.
- `laravel/app/Http/Controllers/Api/V1/Sales/SalesCartApiController.php`
  `index()`, `addItem()`, `updateItem()`, `removeItem()`, `validateCart()`,
  `availability()` — these already call `$this->resolveBranchId($request)`
  and pass it to the service. No change needed.
- `laravel/app/Http/Controllers/Admin/SalesFunnelController.php` —
  queries `sales_draft_carts` only for `count()` (no row-level
  access). No change needed.
- Legacy code (`legacy/`) — out of scope. Legacy uses session-keyed
  carts as primary storage, so the cross-branch contamination risk
  is lower in practice.

### Migration design rationale

**Why drop the FK on `branch_id`?**

The Laravel `04_sales.sql` originally declared `branch_id integer
REFERENCES branches(id)` (nullable, with FK). Legacy semantics use
`branch_id = 0` as a "no specific branch" sentinel — there is no
`branches(0)` row (the `branches` table uses `GENERATED ALWAYS AS
IDENTITY`, which starts at 1). Keeping the FK would block the
backfill and break the sentinel convention. Legacy doesn't enforce
this FK either, so dropping it is a Legacy parity fix, not a
regression.

**Why `NOT NULL DEFAULT 0`?**

PostgreSQL `UNIQUE` constraints treat NULL as distinct — two rows
with `(user_id=5, customer_id=10, branch_id=NULL)` would NOT
conflict, defeating the purpose of the unique key. Making the
column `NOT NULL DEFAULT 0` ensures the unique constraint works
correctly. `0` is the pre-existing Legacy convention for "no
specific branch".

**Why lookup the FK name dynamically?**

PostgreSQL auto-generates FK constraint names (typically
`<table>_<column>_foreign`). Hardcoding the name would break if
the naming convention changes between PG versions. The dynamic
lookup uses `pg_constraint` and is the same pattern used by
`2025_01_21_000005_configure_deferred_fk_constraints.php` (Task 35).

### Verification

- Manual review of all 6 modified PHP files (PHP binary not
  available in sandbox for `php -l`).
- Confirmed all `SalesDraftCart::getOrCreate()` callers pass
  `$branchId` (or accept `null` which is normalized to 0):
  - `SalesCartService::getCart()` ✅
  - `SalesCartService::addItem()` ✅
  - `SalesCartService::updateItem()` ✅
  - `SalesCartService::removeItem()` ✅
  - `SalesCartService::clearCart()` ✅ (now called with branch_id
    by all 3 callers: Admin, API, finalizeFromCart)
  - `SalesCartService::setSoftHold()` ✅ (now called with branch_id
    by both Admin and API controllers)
- Confirmed the migration's `down()` method reverts everything
  (including a best-effort re-creation of the FK with a warning
  if it fails due to `branch_id=0` sentinel rows).
- Confirmed the SQL file `04_sales.sql` change matches the
  migration's end state (fresh installs and migrations produce
  the same schema).

### Risks introduced

- **Minimal.** The unique key change is purely additive — it
  tightens an existing constraint. Existing carts with the same
  `(user_id, customer_id)` but different `branch_id` values are
  now correctly separated (they were already separated by the
  `branch_id` column, just not enforced as unique).
- The dropped FK on `branch_id` is a slight integrity loss, but
  matches Legacy semantics. The `idx_sdc_branch` index is kept
  for query performance.
- The latent `clearCart` bug in `finalizeFromCart` is fixed as a
  side effect (see "Files modified" #5 above).

### Follow-ups

- Consider porting the R6 fix to the Legacy system. Legacy's
  `020_sales_draft_carts.sql` still has the 2-column unique key.
  Legacy uses `$_SESSION`-keyed carts as primary storage (with
  DB as optional backup gated by `SALES_DB_DRAFT_CARTS`), so the
  cross-branch contamination risk is lower in practice on Legacy
  — but porting the fix would close the gap entirely.

- Consider adding a CI check that introspects `sales_draft_carts`
  rows to ensure no orphaned (user_id, customer_id, branch_id=0)
  carts accumulate. With the new unique key, a misconfigured
  client that doesn't send `branch_id` would create carts at
  `branch_id=0` — these would not contaminate real branches but
  would be invisible to branch-scoped UI. A periodic cleanup
  script (similar to `cancel_stale_sales_drafts.php`) could
  remove abandoned `branch_id=0` carts older than 24h.

---

## R10 — Wire up barcode scanning in the cart blade (UI for the R1 endpoint)

- **Status:** ✅ Done
- **Date:** 2026-07-21
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md` §6.1 item #1
  (Barcode scanning) — now marked ✅ R10.
- **Legacy port:** `sales/product_by_code` was already ported at the
  controller level in R1 (`SalesCartController::productByCode` →
  `admin.sales.cart.product-by-code`). R10 adds the **missing UI
  consumer** — the Enter-key handler + barcode input field on the
  cart blade.

### Problem

R1 added the `productByCode` endpoint to Laravel but left it without
a UI consumer — the migration notes explicitly said:

  > "Currently no UI uses it — wiring it into the cart page's barcode
  > input is a future R# item."

(`docs/SESSION_CONTEXT.md` §5.1.) So the Laravel cart still had no
way for a cashier with a USB HID barcode scanner to scan a product
into the cart. They had to use the Select2 search dropdown, which is
fine for typing names but slow for POS-style scanning where each
scan should add an item in one keystroke.

Legacy's pattern (`legacy/public/assets/js/sales-create.js` L324–381
and `sales-edit.js` L440–540) is:

1. A free-text `#productSearch` input (always visible) doubles as
   both the typeahead-search box AND the barcode-scan box.
2. On `keydown` Enter, the JS first tries an exact-code lookup via
   `fetchSalesProductByExactCode(term, branchId)`.
3. If that returns a product, it calls `selectProductCreate(p)` which
   fills the rate, qty, and stock banner.
4. If not, it falls back to picking the first suggestion in the
   typeahead dropdown (if open).
5. If neither, it shows "Not found".

Laravel's cart uses Select2 instead of a free-text input with a
custom suggestion box, so we cannot reuse Legacy's exact flow. The
Laravel adaptation is described below.

### Solution

Added a **dedicated barcode input field** to the cart blade (toggle-
revealed, collapsed by default) and an Enter-key + button handler
that calls the existing `productByCode` endpoint and reconciles the
result with the Select2-based product picker.

### Files modified

1. **`laravel/resources/views/admin/sales/cart.blade.php`**
   - Added a "Barcode" toggle button to the "Add Product" card
     header.
   - Added a new `#barcodeRow` (collapsed by default via `d-none`)
     containing:
     - `#barcodeInput` text field (`autocomplete="off"`,
       `inputmode="text"`, placeholder hints "press Enter…")
     - `#barcodeHint` form-text element for status feedback
       ("Looking up…", "✓ Product Name · avail N · rate ৳X",
       "✗ No product with code X", "⚠ Out of stock", etc.)
     - `#btnBarcodeAdd` button ("Scan & Add")
     - `#barcodeAutoAdd` checkbox (default checked) — controls
       whether the scan auto-adds to cart or just populates the
       form
   - Added a new `scanAndSelect()` async function (~80 lines) that:
     1. Trims the input; bails if empty.
     2. Bails if no customer is selected (with a toast + hint).
     3. Sets a "Looking up…" hint + disables the input.
     4. Fetches `ENDPOINTS.productByCode?code=…&branch_id=…`.
     5. On network error: shows the error, re-enables + refocuses.
     6. On `status !== 'success'`: shows "No product with code X",
        toasts a warning, refocuses + selects the input.
     7. On success:
        - Caches the product in `productCache[p.id]` (so the
          existing change handler / availability renderer can see
          it without a re-fetch).
        - **Stock guard** (mirrors Legacy `selectProductCreate`):
          if `available_qty <= 0`, blocks the add with a toast
          and clears the input.
        - Injects a fresh `<option>` into `#addProduct` and sets
          its value (Select2 AJAX only renders options the user
          typed for, so we synthesize one).
        - Triggers `change` on the Select2 — this reuses the
          existing change handler which fills the rate field,
          rate hint, and fires `checkAvailability`.
        - Pre-fills `#addRate` (default_rate, fall back to
          min_rate) and resets `#addQty` to 1.
        - Sets the hint to "✓ Product Name · avail N · rate ৳X".
        - If auto-add is on: calls `addToCart()` directly, then
          clears + refocuses the barcode input (so the cashier
          can scan the next item without reaching for the mouse).
        - If auto-add is off: focuses `#addQty` for editing
          before the manual Add click.
   - Wired three event handlers:
     - `#btnToggleBarcode` click → toggles `d-none` on `#barcodeRow`
       + focuses the input when revealing.
     - `#barcodeInput` keydown Enter → `scanAndSelect()`.
     - `#btnBarcodeAdd` click → `scanAndSelect()`.

### Design decisions

**Why a separate input rather than reusing the Select2 search box?**

Select2 captures keyboard input inside its own search field and
manages the dropdown lifecycle internally. There is no clean way
to intercept Enter on the Select2 search field, run an exact-code
lookup, and inject a synthesized option — Select2's `query`
interface is designed for fuzzy search, not exact-match-and-select.
A separate input is simpler, more predictable, and matches the
Legacy UX of having a dedicated scan box (Legacy's box doubles as
typeahead, but we already have typeahead via Select2, so the
barcode input can be scan-only).

**Why collapsed by default?**

Most users don't have a barcode scanner — they pick products via
Select2 search. Showing a permanently-visible scan box would add
visual noise. The "Barcode" toggle button in the card header
makes the feature discoverable without forcing it on everyone.

**Why auto-add by default?**

The whole point of barcode scanning is fast POS entry: scan →
item appears → scan next. Forcing the user to click "Add" after
every scan defeats the purpose. The "Auto-add after scan"
checkbox (default on) lets power users turn it off if they want
to adjust qty/rate before adding.

**Why call `addToCart()` directly instead of replicating its logic?**

The existing `addToCart()` function already handles the AJAX call,
toast feedback, state update, and form reset. Reusing it keeps
the behaviour identical to a manual add and avoids divergence.

**Why not also support the Legacy-style "Enter on the Select2
search box" flow?**

Select2 doesn't expose a clean hook for "Enter with no highlighted
suggestion → try exact-code lookup". The Legacy flow relies on a
custom suggestion box where Enter is fully under app control.
Adding the same flow to Select2 would require either monkey-
patching Select2's keydown handler or replacing Select2 with a
custom dropdown — both are much larger changes than R10's scope.
The dedicated barcode input is the pragmatic port.

**Why not also wire up the API V1 (`SalesCartApiController`)?**

The user's R10 brief explicitly named "cart blade" — the API tier
is out of scope. If a mobile/AI client wants barcode lookup, the
existing `GET /api/v1/lookups/products?term=…` endpoint already
supports exact-code matching (the underlying
`StockAvailabilityService::searchProductsWithStock` does a `LIKE
%term%` which includes exact codes). A dedicated `/api/v1/lookups/
products/by-code` endpoint could be added later if needed.

### Verification

- Manual code review of the blade changes. No PHP or Blade syntax
  errors (no PHP added — purely HTML + JS).
- Confirmed the existing `ENDPOINTS.productByCode` route name
  matches what R1 registered (`admin.sales.cart.product-by-code`).
- Confirmed the existing `addToCart()` function signature
  unchanged (no parameters; reads from `#addProduct`, `#addQty`,
  `#addRate`, `state.customerId`).
- Confirmed the `BRANCH_ID` JS global is already defined elsewhere
  in the blade (used by the Select2 AJAX `data` callbacks).
- Confirmed the `productCache` global is already declared and
  populated by the Select2 `processResults` callback (R1).
- Confirmed the out-of-stock guard matches Legacy
  `selectProductCreate`'s `stock <= 0` check
  (`legacy/public/assets/js/sales-create.js` L394-398).

### Risks introduced

- **Minimal.** The barcode input is purely additive UI. If JS
  fails to load, the input still renders but the Enter handler
  never fires — the user can still use the Select2 search path.
- The auto-add path calls the same `addToCart()` function used
  by the existing "Add" button, so all server-side validation
  (cart service, stock check, rate check) applies unchanged.
- No new endpoints, no new routes, no new DB queries (the
  `productByCode` endpoint was already in place from R1).

### Follow-ups

- Consider porting the same barcode flow to the **sales invoice
  edit** page (`admin/sales-invoices/{id}/edit`). Legacy has it
  on both `create.php` and `edit.php`; Laravel currently has it
  on neither (R10 only covers the cart/create flow).
- Consider adding a keyboard shortcut (e.g. `Alt+B`) to focus
  the barcode input without needing to click the toggle button.
- Consider a "scan sound" beep on successful scan (Web Audio API)
  for audible feedback in noisy retail environments. Low priority.

---

## R11 — Port multi-customer cart tabs (`#draft-tabs` dock with per-tab item-count badges)

- **Status:** ✅ Done
- **Date:** 2026-07-22
- **Audit reference:** `docs/sales_entry_Lg_vs_La.md` §6.1 item #2
  (Multi-customer cart tabs) — now marked ✅ R11. Also updated
  §3 architecture comparison ("Cart tabs" + "Page architecture"
  rows), §4 cart-table comparison ("Multi-customer tabs" +
  "Per-tab item count badge" rows), §9.3 recommendations table.
- **Legacy port:** `sales/list_draft_carts` controller +
  `SalesCartOperationsTrait::listDraftCarts()` service +
  `sales-create.js::createOrSwitchTab / switchToTab / closeTab /
  refreshTabBadge / restoreSessionCarts` (L643–803) + `#draft-tabs`
  dock in `sales/create.php` (L144–163).

### Problem

The Laravel cart blade supported one customer per page. Switching
customers required either changing the URL (`?customer_id=…`) or
selecting a different customer in the Select2 and clicking "Load
Cart". For a high-volume POS cashier ringing up several customers
in parallel, this is friction:

- Every switch costs a round-trip and a page-state reset.
- The previously-edited cart disappears behind the new selection
  — the cashier can't see at a glance which carts are open and
  how many items each has.
- There is no in-page overview of "open work" (cart inventory
  per customer).

Legacy solved this with a `#draft-tabs` dock: one pill per
customer-cart, item-count badge, × close button, instant in-page
switching. The cashier can keep 5–10 carts open at once and
toggle between them with a single click.

Audit risk: §6.1 item #2 in `sales_entry_Lg_vs_La.md`.

### Decision

Add a `#draftTabsCard` dock above the customer selector that
mirrors the Legacy UX, backed by a new `list-drafts` endpoint.

**Why a new endpoint instead of reusing `/cart/load`?** `load` is
per-customer (one cart at a time); we need an enumeration of all
open carts for the user + branch. Legacy had a dedicated
`sales/list_draft_carts` endpoint for exactly this reason.

**Why reuse `/cart/clear` for the close-tab action?** It already
does the right thing — clears the cart for a (user, customer,
branch) tuple and writes the R4 audit-log entry. Adding a separate
`clear-tab` endpoint would just duplicate the logic and risk
divergent behavior.

**Why sort by item_count DESC then updated_at DESC?** Legacy
`usort` only used item_count desc; we add updated_at as a
tiebreaker so equally-busy carts surface the recently-touched one
first. This matches Legacy intent ("busiest cart leftmost") while
giving a sensible fallback when counts tie.

**Why cap at 50 rows?** Defensive — a runaway user with hundreds
of stale carts shouldn't OOM the page. 50 is well above any
realistic POS workflow (Legacy had no cap, but its session-based
storage naturally bounded things).

### Files modified

#### `laravel/app/Services/Sales/SalesCartService.php`

Added `listCarts(int $userId, ?int $branchId = null): array`
method (~80 lines):

- Queries `sales_draft_carts` for the user (+ optional branch),
  ordered by `updated_at DESC`, capped at 50 rows.
- For each row: skips if `items_json` is empty (Legacy semantics
  — empty carts don't earn a tab); looks up the customer's
  `customer_name`/`shop_name`/`mobile` via a cheap `DB::table`
  query (not Eloquent — avoids model boot overhead per row);
  computes `item_count` = `count(items)` and `subtotal` = sum of
  `item.total`.
- Builds the `label` field: `shop_name || customer_name`, with
  ` · mobile` appended if mobile is present. Falls back to
  `Customer #ID` if both are empty.
- Returns an array of `{customer_id, label, shop_name,
  customer_name, mobile, item_count, subtotal, is_soft_hold,
  updated_at}` rows.
- Sorts by `item_count DESC, updated_at DESC` (PHP `usort`) —
  matches Legacy intent with an updated_at tiebreaker.

#### `laravel/app/Http/Controllers/Admin/SalesCartController.php`

Added `listDrafts(Request $request)` method:

```php
public function listDrafts(Request $request)
{
    $branchId = (int) session('branch_id', 0);
    return response()->json(
        $this->cartService->listCarts(auth()->id(), $branchId)
    );
}
```

Thin wrapper — all logic is in the service. Returns JSON directly
(no view, no resource class — the response shape is simple and
matches Legacy).

#### `laravel/routes/web.php`

Added inside the `admin/sales` route group:

```php
Route::get('cart/list-drafts', [SalesCartController::class, 'listDrafts'])
    ->middleware('throttle:60,1')
    ->name('cart.list-drafts');
```

Throttle: 60 req/min — matches Legacy `guardJsonApi` limit for
`sales/list_draft_carts`.

#### `laravel/resources/views/admin/sales/cart.blade.php`

Multiple changes:

1. **New `#draftTabsCard` dock** above the customer selector:
   - Card with header ("Open carts — switch customers without
     losing items") + a count badge ("N carts") + an empty-state
     hint.
   - `<ul class="nav nav-pills flex-nowrap overflow-auto">` for
     the pill list — horizontal scroll when pills overflow.
   - Hidden by default (`d-none`) when no customer is selected;
     becomes visible once the first tab is rendered.

2. **New JS section "R11: MULTI-CART TABS DOCK"** (~330 lines):
   - `customerCache` (in-memory object) — keyed by customer_id,
     holds `{id, customer_code, customer_name, shop_name, mobile,
     credit_limit}`. Populated by the customer Select2's
     `processResults` (so newly-picked customers get a properly
     labeled tab immediately) and by `restoreSessionCarts()`
     (so list-drafts pills render correctly).
   - `tabLabelFor(customerId)` — formats the pill label
     (`shop_name || customer_name`, with ` · mobile` if present).
   - `tabTitleFor(customerId)` — long-form tooltip text.
   - `ensureTab(customerId, opts)` — idempotent: creates the pill
     `<li>` if absent, updates its label/badge/soft-hold icon if
     present. `opts.active = true` also calls `activateTab()`.
   - `activateTab(customerId)` — highlights only the active pill,
     dims the rest, scrolls the active pill into view (horizontal
     scroll).
   - `removeTab(customerId)` — deletes the `<li>` from the DOM,
     then calls `refreshTabDockVisibility()`.
   - `refreshTabDockVisibility()` — updates the "N carts" badge,
     toggles the empty-state hint, ensures the dock is visible
     when there's at least one tab.
   - `updateActiveTabBadge(itemCount, opts)` — convenience wrapper
     that calls `ensureTab(state.customerId, {itemCount, active:
     true, ...opts})`. Used by every cart-mutation success handler.
   - `restoreSessionCarts()` — async; calls
     `ajaxGet(ENDPOINTS.listDrafts)`, caches each customer, calls
     `ensureTab()` per cart, then activates the busiest (or the
     `?customer_id=` one if present).
   - `switchToCustomer(customerId, opts)` — sets `#customerSelect`
     value (synthesizing an `<option>` from `customerCache` if
     needed), triggers `change` (which calls `loadCart`), ensures
     the tab exists + is active, updates the URL.
   - `closeTabCart(customerId)` — SweetAlert confirm →
     `ajaxPost(ENDPOINTS.clear, {customer_id})` → on success,
     `removeTab(customerId)` and either switch to the next
     remaining tab or reset to the empty state.
   - `initDraftTabsDock()` — delegated event wiring for pill-body
     click + × close button. Called once on DOM ready.

3. **Modified customer Select2 `processResults`** to also populate
   `customerCache` (so the tab label is correct for newly-picked
   customers).

4. **Modified customer `<select>` change handler** to call
   `ensureTab(cid, {active:true, label})` before `loadCart(cid)`
   — so the pill appears immediately, even before the AJAX
   resolves.

5. **Modified `loadCart()` success handler** to call
   `updateActiveTabBadge(itemCount, {softHold, label})` +
   `activateTab(state.customerId)`.

6. **Modified `addToCart()` / `updateItem()` / `removeItem()` /
   `clearCart()` success handlers** to call
   `updateActiveTabBadge(itemCount, {softHold})` from the response
   payload. `clearCart()` also calls `removeTab(state.customerId)`
   and hides the dock if no tabs remain.

7. **Added bootstrap sequence** at the bottom of the
   `$(function () { ... })` block:
   - Calls `initDraftTabsDock()` to wire delegated handlers.
   - If `$selectedCustomer` is set (server-rendered), pre-populates
     `customerCache` and renders the initial tab with the
     already-known item count (from the server-rendered
     `$cartData`).
   - Calls `restoreSessionCarts()` to fetch the full list and
     render the remaining tabs + activate the right one.

### What was NOT changed

- The Legacy sales entry code (`legacy/`) was not touched.
- The Laravel API V1 (`SalesCartApiController`) was NOT touched.
  The user's R11 brief explicitly named "cart blade" — the API
  tier is out of scope. If a mobile/AI client wants multi-cart
  enumeration, the new `GET /admin/sales/cart/list-drafts`
  endpoint could be mirrored at `GET /api/v1/sales/cart/list-drafts`
  in a follow-up (low effort — the service method already exists).
- The Laravel sales invoice **edit** page
  (`admin/sales-invoices/{id}/edit`) was NOT touched. Legacy has
  multi-customer tabs only on the create page; R11 matches that
  scope.
- No new migration was needed — the existing `sales_draft_carts`
  schema (with the R6 3-column unique key `(user_id, customer_id,
  branch_id)`) is sufficient. `listCarts()` is a pure read query.
- No keyboard shortcut (e.g. `Ctrl+Tab` to cycle carts) was
  added. Low priority follow-up.
- No "drag to reorder tabs" was added. Low priority follow-up.
- The dock does not (yet) show the per-cart subtotal on the pill —
  only the item count. Legacy shows only the count too, so this
  matches. Could be added as a tooltip in a follow-up.

### Verification

- Confirmed blade braces balanced (366 `{` / 366 `}`).
- Confirmed no duplicate `id=` attributes in the rendered HTML.
- Confirmed all 11 new functions (`ensureTab`, `activateTab`,
  `removeTab`, `refreshTabDockVisibility`, `updateActiveTabBadge`,
  `restoreSessionCarts`, `switchToCustomer`, `closeTabCart`,
  `initDraftTabsDock`, `tabLabelFor`, `tabTitleFor`) are defined
  exactly once.
- Confirmed `@if`/`@endif`/`@push`/`@endpush`/`@section`/
  `@endsection` counts are balanced.
- Confirmed the new route is wired inside the
  `['role:salesman,manager,admin', 'branch.isolation']`
  middleware group, so only authorized users can hit it.
- Confirmed the new `SalesCartService::listCarts()` method uses
  `DB::table('customers')` (not Eloquent) for the per-customer
  lookup — avoids the BranchScope global scope that would
  incorrectly filter out customers from other branches (the
  cashier's carts may legitimately reference customers from any
  branch they've worked in; the branch filter on `sales_draft_carts`
  is what enforces scope).

### Risks introduced

- **Low.** The new endpoint is read-only and throttled (60/min).
  The dock UI is additive — if JS fails to load, the customer
  Select2 + cart workspace continue to work as before (R1-R10
  behavior).
- The `listCarts` query joins `sales_draft_carts` with `customers`
  per row (N+1 lookup pattern). For typical POS workflows (5–10
  open carts) this is fine. If a user accumulates hundreds of
  stale carts, the 50-row cap bounds the worst case. A future
  optimization could use a single `JOIN` query, but the per-row
  lookup keeps the code simple and matches Legacy's per-customer
  `Get_Customer_By_Id` call pattern.
- The `customerCache` is in-memory only — it doesn't survive page
  reload. On reload, `restoreSessionCarts()` re-populates it from
  list-drafts. This is intentional (no localStorage) — the source
  of truth is the server, not the browser.

### Follow-ups

- Consider porting the same multi-cart dock to the **API V1**
  (`SalesCartApiController`) so mobile/AI clients can enumerate
  open carts. The service method already exists; only a thin
  controller wrapper + route are needed.
- Consider adding a per-cart subtotal to the pill (as a tooltip
  or a small text line below the badge). Legacy doesn't have this
  but it would help cashiers prioritize.
- Consider a keyboard shortcut (e.g. `Ctrl+Tab` / `Ctrl+Shift+Tab`)
  to cycle through open carts without using the mouse.
- Consider a "drag to reorder" interaction so the cashier can
  arrange carts in their preferred order (Legacy doesn't have
  this; would be a net-new feature).
- Consider adding a per-cart "last updated X minutes ago" relative
  timestamp to the pill (Legacy doesn't have this). Would help
  spot stale carts.

---

## §R12 — Port live customer/product typeahead (debounced AJAX)

**Status:** ✅ Done (2026-07-22, via R1)
**Audit reference:** `sales_entry_Lg_vs_La.md` §6.1 items #3 + #4,
§9.3 R12 row.

### Problem

The audit doc's §9.3 backlog listed R12 as a separate item from R1
("Port live customer/product typeahead — replace select2 dropdowns
with debounced AJAX typeahead"). On inspection, R1 had already
satisfied this requirement: both the customer and product Select2
widgets were converted to AJAX mode with `minimumInputLength: 1` and
`delay: 250` (a debounce), and `processResults` was wired to populate
`customerCache` + `productCache` for downstream consumers (the change
handler, the availability card, the R10 barcode scanner, the R13
slider band).

### Decision

Mark R12 as ✅ Done (via R1) in the audit doc. Do NOT introduce a
separate typeahead library (e.g. Twitter Typeahead.js) — Select2's
AJAX mode *is* a debounced AJAX typeahead widget. Ripping out
Select2 would be a major UX regression (Select2 gives us
accessibility, keyboard navigation, theme consistency with the rest
of the admin, and Bootstrap 5 integration for free).

### Files modified

- `docs/sales_entry_Lg_vs_La.md` — §6.1 items #3 + #4 rewritten to
  cite "✅ R1 / R12 (2026-07-22)"; §9.3 R12 row marked ✅ Done (via
  R1) with explanation.
- `docs/SESSION_CONTEXT.md` — §3 backlog row for R12 added; §7
  Completed Work Items entry for R12 added.

### What was NOT changed

- No code changes — R12 was already implemented by R1.
- No new tests — R1's existing tests cover the AJAX endpoints.

### Verification

- `laravel/resources/views/admin/sales/cart.blade.php` lines
  ~1296–1336 (customer Select2 AJAX config) + ~1346–1376 (product
  Select2 AJAX config) confirm `minimumInputLength: 1` + `delay: 250`
  + `processResults` populating the caches.

### Risks introduced

- None — R12 is a documentation-only closure.

### Follow-ups

- If the user later wants a true Twitter-Typeahead-style UI (custom
  suggestion box with rich formatting, keyboard arrow navigation,
  recent-picks section), that would be a separate R# item (R15+).
  The audit doc's §6.1 item #7 (customer recents chips) + #11
  (keyboard shortcuts) are the natural follow-ups.

---

## §R13 — Port price-range slider band UI

**Status:** ✅ Done (2026-07-22)
**Audit reference:** `sales_entry_Lg_vs_La.md` §6.1 item #5,
§9.3 R13 row.

### Problem

The Laravel cart blade's only rate feedback was a static "Min X /
Max Y" hint below the `#addRate` input (added in R1). The Legacy
system showed a visual band with a thumb that tracked the typed
rate, plus a default-rate mark and a green/amber/red status badge —
much faster to parse at a glance during a busy POS session. Audit
risk §6.1 item #5 flagged this as "Missing — Laravel shows plain
text hint".

### Decision

Add a `#priceRangePanel` dock inside the Add Product card (below the
rate/qty/Add row, above the availability row) that mirrors Legacy
`#priceRangePanel` + `updatePriceBandUi` (sales-create.js L129–187).

The band reads from `productCache` (populated by R1's live search
+ R10's barcode scan) — no extra round-trip. When the user types in
`#addRate`, a 60 ms-debounced `input` handler re-positions the thumb
and refreshes the status badge. The band auto-hides when the
selected product has no usable range (`min_rate ≤ 0` or `max_rate ≤
0`), matching Legacy's early-return.

The band is purely informational — a red badge does NOT block the
Add button. Server-side rate validation in
`SalesCartService::validateCartItems` + the finalize flow is still
authoritative. This matches Legacy, where the band is advisory and
the server-side check is the hard gate.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added `#priceRangePanel` HTML inside the Add Product card —
    track + gradient fill + default-mark + thumb + Min/Max/Default
    labels + status badge + "Use default" button. All
    inline-styled with Bootstrap 5 utility classes + a few
    `position-absolute` elements (no new CSS file).
  - Added `state.activePriceRange` to the `state` object.
  - Added 3 new JS functions in the rendering section:
    - `setActivePriceRange(product)` — validates min/max > 0,
      stashes into `state.activePriceRange`, calls
      `updatePriceBandUi()`. Clears state + hides panel when
      `product` is null or has no usable range.
    - `rateRangeStatus(rate, min, max)` — returns `'ok' | 'warn' |
      'bad'`. `warn` fires when the rate is within range but
      within 10 % of the minimum (margin heads-up). Mirrors
      Legacy `salesRateRangeStatus` in `sales.js`.
    - `updatePriceBandUi()` — shows/hides the panel, sets
      Min/Max/Default labels in ৳, sets `#addRate`'s `min`/`max`
      HTML attributes, positions the gradient fill + thumb +
      default-mark as percentages of the span, sets the status
      badge to `bg-success` / `bg-warning` / `bg-danger`, and
      re-colours the thumb border to match the status.
  - Modified the `#addProduct` change handler to call
    `setActivePriceRange(p)` after auto-filling the rate (and
    `setActivePriceRange(null)` when no product is selected).
  - Modified the R10 `scanAndSelect()` function to call
    `setActivePriceRange(p)` after the rate is filled (so the
    thumb snaps to the right position immediately, not at 0 %).
  - Added `#addRate` `input` handler with 60 ms debounce →
    `updatePriceBandUi()`.
  - Added `#btnUseDefaultRate` click handler → sets rate to
    `state.activePriceRange.default_rate.toFixed(2)` + triggers
    `change` + shows a toast.

### What was NOT changed

- The Legacy sales entry code (`legacy/`) was not touched.
- No backend changes — R13 is purely additive UI. The price-range
  data was already in `productCache` (populated by R1).
- Server-side rate validation in
  `SalesCartService::validateCartItems` + the finalize flow is
  still authoritative. A red badge does NOT block the Add button.
- The cart-table inline rate editor (per-row `#cart-rate` input)
  does NOT get a slider band — only the Add-Product rate input
  does. Legacy matches this scope (slider only on the create-page
  add form, not the cart table).
- No new CSS file was added. All slider styling is inline
  `style="…"` on the band elements. Keeps the blade
  self-contained.

### Verification

- Blade file integrity: braces 405/405, parens 1309/1309,
  brackets 64/64, `@if`/`@endif` 6/6, `@push`/`@endpush` 1/1.
- All 9 new element IDs verified unique: `#priceRangePanel`,
  `#priceBandMin`, `#priceBandMax`, `#priceBandDefault`,
  `#priceBandFill`, `#priceBandDefaultMark`, `#priceBandThumb`,
  `#priceRangeStatus`, `#btnUseDefaultRate`.
- The 3 new JS functions are each defined exactly once.

### Risks introduced

- **Low.** The band is informational only; it cannot block cart
  mutations. Worst case: a mis-positioned thumb due to a
  malformed product payload — the `setActivePriceRange` early
  return on `min ≤ 0 || max ≤ 0` guards against this.

### Follow-ups

- Consider blocking the Add button when the status is `bad`
  (out of range). Currently the band is advisory only. If the
  user wants a hard client-side gate, a 1-line check inside
  `addToCart()` would do it. (Server-side validation is already
  authoritative.)
- Consider adding a slider band to the cart-table inline rate
  editor (per-row `#cart-rate` input). Legacy doesn't have this;
  would be a net-new feature.
- Consider extracting the inline slider styles into a CSS class
  (e.g. `.price-band-track`, `.price-band-thumb`) in the layout
  stylesheet for themeability. Currently self-contained.

---

## §R14 — Port live credit-limit display on cart page

**Status:** ✅ Done (2026-07-22)
**Audit reference:** `sales_entry_Lg_vs_La.md` §6.1 item #6,
§9.3 R14 row.

### Problem

Laravel only checked the customer's credit limit at finalize time
via `GET /admin/sales/credit-check?customer_id=X&amount=Y`. A
cashier could spend 5 minutes building a 50-item cart only to be
blocked at the finalize dialog — wasted effort and a poor UX,
especially for high-volume POS sessions. Audit risk §6.1 item #6
flagged this as "Missing — Laravel checks credit only at finalize".

The Legacy system solved this with a `#customerDetailsPanel` in
`sales/create.php` (L72–80) that showed the customer's
`credit_limit`, `recent_due`, and `due_left` inline, fetched via
`sales/customer_details` endpoint.

### Decision

Port the Legacy `customer_details` endpoint + panel, AND extend it
with a projected new balance row (`current_due + cart subtotal`)
that updates in real time as the cart changes. The projection
directly addresses the audit risk's "prevents wasted
cart-building" rationale — the cashier sees the projected post-cart
balance immediately, colour-coded green/amber/red.

The snapshot is fetched once per customer change (and on explicit
"Refresh" button click). The projected row recomputes locally on
every cart mutation (add/update/remove/clear) using the cached
snapshot + the latest cart subtotal. This avoids hammering the
`customer_ledger` SUM query on every keystroke.

The `current_due` formula is `SUM(debit) − SUM(credit) FROM
customer_ledger WHERE customer_id = ? AND is_reversed = false` —
identical to `SalesInvoiceService::checkCreditLimit` (L875–L879).
Consistency between the live panel and the finalize-time check is
critical; if they showed different numbers, the cashier would lose
trust in the UI. The `is_reversed = false` filter is important —
Legacy didn't have `is_reversed` on `customer_ledger` (Laravel
added it in migration `2025_01_02_000002`); without the filter,
reversed transactions would inflate the apparent due.

### Files modified

- `laravel/app/Services/Sales/SalesCartService.php`
  - Added `getCustomerDetails(int $customerId): array` method
    (~45 lines). Loads the customer record from `customers`,
    computes `current_due` as `SUM(debit) − SUM(credit)` from
    `customer_ledger` (is_reversed = false), returns
    `{customer_id, customer_name, shop_name, mobile, address,
    credit_limit, current_due, due_left}`. Returns sane zeros
    when the customer is not found (matches Legacy's
    `$this->sendJson($data ?: […defaults])`).
- `laravel/app/Http/Controllers/Admin/SalesCartController.php`
  - Added `customerDetails(Request $request)` method that calls
    `getCustomerDetails((int) $request->input('customer_id', 0))`
    and returns JSON. Returns sane zeros when customer_id is
    missing/0.
- `laravel/routes/web.php`
  - Added `Route::get('cart/customer-details',
    [SalesCartController::class, 'customerDetails'])`
    ->middleware('throttle:60,1')
    ->name('cart.customer-details')`. Throttle matches Legacy
    `guardJsonApi` limit for `sales/customer_details`.
- `laravel/resources/views/admin/sales/cart.blade.php`
  - Added `#customerDetailsPanel` HTML inside the customer
    selector card (below the customer row, d-none by default) —
    4 stat cells (Credit limit / Current due / Balance left /
    Cart subtotal) + projected new balance row + status badge
    + Refresh button. All Bootstrap 5 utility classes.
  - Added `ENDPOINTS.customerDetails` route binding.
  - Added `state.customerCredit` to the `state` object.
  - Added 2 new JS functions in the rendering section:
    - `fetchCustomerDetails(customerId)` — AJAX GET to
      `/cart/customer-details`, caches response in
      `state.customerCredit`, calls `renderCustomerDetails()`.
      Clears state + hides panel when `customerId` is null.
    - `renderCustomerDetails()` — renders the 4 stat cells +
      projected new balance row + colour-coded status badge
      (`bg-success` OK / `bg-warning` Tight — within 10 % of
      limit / `bg-danger` Will breach — finalize will require
      override / "No limit set" when `credit_limit = 0`).
      Recomputes the projection locally from
      `state.customerCredit.current_due + state.cart.subtotal`
      — no extra round-trip.
  - Modified `renderAll()` to call `renderCustomerDetails()` so
    the projected row stays in sync with every cart mutation.
  - Modified the `#customerSelect` change handler to call
    `fetchCustomerDetails(cid)` (or
    `fetchCustomerDetails(null)` when cleared).
  - Added `#btnRefreshCredit` click handler (re-fetches the
    snapshot with a spinning icon — useful for long-running
    sessions where a payment may have posted in another tab).
  - Added bootstrap call: `if (state.customerId) {
    fetchCustomerDetails(state.customerId); }` so the panel
    renders immediately when the page loads with
    `?customer_id=…`.

### What was NOT changed

- The Legacy sales entry code (`legacy/`) was not touched.
- The Laravel API V1 was NOT touched. The new
  `/admin/sales/cart/customer-details` endpoint is Blade-only.
  If a mobile/AI client wants the same data, the service method
  `SalesCartService::getCustomerDetails()` is reusable — a
  future `GET /api/v1/sales/customers/{id}/credit-snapshot`
  endpoint would be a thin wrapper (low effort).
- The finalize-time credit-check endpoint (`credit-check`) was
  NOT touched. R14 is informational only; the authoritative
  check still happens inside
  `SalesInvoiceService::finalizeFromCart` (with the R5
  in-transaction lock). The live panel might say "Will breach"
  but the cashier can still proceed to the finalize dialog and
  use the override + reason flow.
- The customer 360° Hub page (`admin/customers/{id}`) was NOT
  touched. That page already shows the customer's AR balance via
  `CustomerController::show` — R14 only adds the inline panel to
  the cart page.
- No new migration was needed — `customer_ledger` already has
  `debit`, `credit`, `is_reversed` columns from the original
  schema + migration `2025_01_02_000002`.
- The panel does NOT auto-refresh on a timer. The "Refresh"
  button is manual. A timer-based refresh would add a polling
  load for little benefit. A future enhancement could use SSE
  (already wired up via `SseController`) to push balance
  updates when a payment posts — low priority.

### Verification

- PHP file integrity: `SalesCartService.php` braces 78/78, parens
  302/302; `SalesCartController.php` braces 36/36, parens 231/231;
  `routes/web.php` braces 114/114, parens 898/898.
- Blade file integrity: braces 405/405, parens 1309/1309,
  brackets 64/64, `@if`/`@endif` 6/6, `@push`/`@endpush` 1/1.
- All 9 new element IDs verified unique: `#customerDetailsPanel`,
  `#cdCreditLimit`, `#cdCurrentDue`, `#cdDueLeft`,
  `#cdCartSubtotal`, `#cdProjectedBalance`, `#cdStatus`,
  `#cdStatusText`, `#btnRefreshCredit`.
- The 2 new JS functions are each defined exactly once.
- The new route `admin.sales.cart.customer-details` is registered
  exactly once.
- The `current_due` SQL formula matches
  `SalesInvoiceService::checkCreditLimit` exactly (same
  `is_reversed = false` filter, same `SUM(debit) − SUM(credit)`
  expression, same `COALESCE(…, 0)` default).

### Risks introduced

- **Low.** The panel is informational only; it cannot block cart
  mutations or finalize. Worst case: a stale snapshot (if a
  payment posted in another tab since the customer was selected)
  — the "Refresh" button is the mitigation. The authoritative
  check still runs at finalize time with the R5 row lock.
- **Throttle risk:** 60 req/min per user. A cashier switching
  customers rapidly could hit the limit, but the panel would
  just stop updating until the window resets — non-fatal.

### Follow-ups

- Consider mirroring the endpoint at
  `GET /api/v1/sales/customers/{id}/credit-snapshot` for the
  API V1 tier. The service method already exists; only a thin
  controller wrapper + route are needed.
- Consider using SSE (`SseController` is already wired up) to
  push balance updates to the cart page when a payment posts.
  Currently the cashier must click "Refresh" to see the new
  balance. Low priority — the typical POS flow doesn't have
  concurrent payment posting on the same customer.
- Consider adding the same panel to the sales invoice **edit**
  page (`admin/sales-invoices/{id}/edit`). Legacy has it only
  on the create page; matching that scope for now.
- Consider surfacing the customer's last-payment date + amount
  in the panel (Legacy doesn't have this). Would give the
  cashier more context for credit decisions.


---

## §R15 — Port customer recents chips (localStorage)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #7 — "Customer recents chips (last 5 in localStorage)"

### Problem

Legacy renders click-to-pick chips beneath the customer search box
for the last 5 customers the cashier picked. The chips persist
across page reloads via `localStorage["sales_customer_recents"]`.
The Laravel cart blade had no equivalent — every customer pick
required re-typing the name (or scrolling the Select2 dropdown).
For repeat-customer workflows (the same shopkeeper coming back
twice in an hour), this is a real friction point.

### Decision

Port the Legacy pattern faithfully:
- One chip per recent customer, capped at 5.
- Click a chip → switch to that customer (reusing R11's
  `switchToCustomer()` flow — Select2 value + tab ensure + cart
  load + credit fetch).
- localStorage key namespaced `rcerp_sales_customer_recents` to
  avoid cross-tenant contamination on a shared deploy.
- Storage shape: `[{id:int, label:string, ts:int(unix_ms)}, ...]`
  (deduped by id, most-recent-first).
- Storage failures (private mode, quota) caught + warned —
  non-fatal; chips just won't persist across sessions.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#customerRecentsRow` (d-none by default) + `#customerRecents`
    chip container in the customer selector card (below the Select2,
    above the credit panel).
  - New JS: `CUSTOMER_RECENTS_KEY`, `CUSTOMER_RECENTS_MAX`,
    `rememberCustomerRecent(id, label)`, `loadCustomerRecents()`,
    `renderCustomerRecents()`.
  - `#customerSelect` change handler now also calls
    `rememberCustomerRecent(cid, label)` + `renderCustomerRecents()`.
  - Delegated click handler on
    `#customerRecents .btn[data-customer-id]` calls
    `switchToCustomer(cid)` (R11's flow).
  - Bootstrap: `renderCustomerRecents()` is called on page load +
    the server-rendered pre-selected customer is also remembered.
  - CSS in the `@push('css')` block styles the chips with a
    pill-shape (border-radius:999px) + indigo accent.

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — clicking a chip reuses R11's
  `switchToCustomer()` + the existing `/cart/load` endpoint.
- No expiry / TTL on entries — Legacy doesn't have one either.
  The 5-entry cap is the implicit cleanup.
- The Laravel API V1 (`SalesCartApiController`) was NOT touched.
  Recents are a per-cashier UX concern; the API tier has no
  equivalent (and shouldn't — server-side recents storage would
  require a new table for marginal value).
- The Legacy cart-draft backup (`saveCartDraftBackup` /
  `restoreCartIfNeeded` — sales.js L1382–1419) is NOT ported.
  Laravel is DB-backed so the cart survives a page reload natively.
  A localStorage backup would only matter for offline support,
  which is a separate feature (R28 PWA installability).

### Verification

- File integrity: blade braces balanced (478/478), parens balanced
  (1504/1504), `@if/@endif` balanced (7/7), `@push/@endpush`
  balanced (2/2 — the 3rd `@push` match is inside a JS comment).
- All 79 element IDs in the blade are unique (no duplicates after
  adding `#customerRecentsRow`, `#customerRecents`).
- All 5 new functions defined exactly once:
  `rememberCustomerRecent`, `loadCustomerRecents`,
  `renderCustomerRecents`, plus R16/R17 functions documented in
  their own sections below.

### Risks introduced

- **Very low.** localStorage is well-supported; failures are
  caught + warned. Worst case: chips don't persist — the rest of
  the page works normally.
- **Privacy consideration:** localStorage is per-browser, per
  -origin. On a shared kiosk the chips would be visible to the
  next user. Mitigation: the chips show only customer names
  (already visible to any authenticated user); no sensitive data
  (no balances, no contact info beyond what's in the customer
  name label). For a kiosk deployment, a "Clear recents" button
  could be added (low priority — not in this commit).

### Follow-ups

- Consider adding a "Clear recents" link next to the chips for
  kiosk deployments.
- Consider raising the cap from 5 to 8 if the cashier regularly
  handles more repeat customers per shift. Mirrors Legacy for
  now (5 is the existing UX calibration).

---

## §R16 — Port sticky bottom bar (item count + grand total + Finalize)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #9 — "Sticky bottom bar (item count + grand total + Finalize always visible)"

### Problem

Laravel's Finalize button lives in the "Cart actions" card below
the cart table. On long carts the cashier has to scroll past 20+
rows to reach it. Legacy solves this with a fixed-position bottom
bar that always shows the item count + grand total + a Finalize
button — one tap away from any scroll position.

### Decision

Port the Legacy `#posStickyBar` pattern:
- Fixed-position bottom bar (`position: fixed; bottom: 0`).
- Shows `<N items · ৳X`> on the left, Finalize button on the right.
- Button enabled iff cart is valid (mirrors the in-page
  `#btnFinalize` disabled logic).
- Clicking the button calls the SAME `finalizeInvoice()` function
  as the in-page button — no duplicated logic, no separate endpoint.
- Bar hidden when cart is empty or no customer is selected.
- iOS safe-area inset respected (`env(safe-area-inset-bottom)`)
  so the bar isn't clipped by the home indicator on notched
  devices.
- Page gets extra `padding-bottom: 5.5rem` while the bar is
  visible so the last cart row isn't covered.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#posStickyBar` HTML block (outside the container, before
    `@endsection`).
  - New `@push('css')` block (the cart blade's first CSS push)
    with the sticky bar styles + the page-padding-bottom rules.
  - New JS `updatePosStickyBar()` function called from
    `renderAll()` on every cart mutation.
  - `#posStickyFinalize` click handler wired to
    `finalizeInvoice()` (same as `#btnFinalize`).
  - Customer-change handler now calls `updatePosStickyBar()` when
    the customer is cleared (hides the bar).
  - Bootstrap: `updatePosStickyBar()` called on initial render.

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — the sticky Finalize button calls the
  existing `finalizeInvoice()` function.
- The in-page `#btnFinalize` button is NOT removed — both
  buttons coexist. The in-page button is useful for cashiers
  who prefer the "Cart actions" card layout; the sticky bar is
  for cashiers who prefer the always-visible pattern. Legacy
  has both too (the in-page `finalSubmitBtn` + the sticky
  `#posStickyFinalize`).
- The bar is hidden (not "visible but disabled") when the cart
  is empty. Legacy keeps it visible with opacity 0.85; we
  diverged because the "always visible but disabled" pattern
  can confuse users into thinking the button is broken.

### Verification

- File integrity: blade braces + parens + directives balanced
  (see R15 verification).
- New element IDs unique: `#posStickyBar`, `#posStickySummary`,
  `#posStickyFinalize`.
- New function `updatePosStickyBar()` defined exactly once.
- CSS `@media` query not needed for the sticky bar — it's
  visible on all screen sizes (Legacy's behaviour).

### Risks introduced

- **Low.** The bar is purely additive UI; it can't block cart
  mutations or finalize. Worst case: a CSS conflict with the
  existing layout — mitigated by the high z-index (1040, below
  SweetAlert's 10000+) and the page-padding-bottom rule.
- **Mobile overlap risk:** the bar covers 5.5rem at the bottom
  of the viewport. The page-padding-bottom rule ensures the
  last cart row is never covered. R17's mobile cart cards have
  their own padding so they don't overlap either.
- **`:has()` browser support:** Chrome 105+, Safari 15.4+,
  Firefox 121+. The JS-added `body.pos-sticky-visible` class is
  the fallback for older browsers — same padding-bottom rule
  applies via the `.pos-sticky-visible` selector.

### Follow-ups

- Consider adding a "soft-hold" toggle button to the sticky bar
  (Legacy doesn't have this, but it would be a natural extension).
- Consider showing the projected new balance (from R14's credit
  snapshot) in the sticky bar when the customer has a credit
  limit. Would give the cashier a one-glance "can I finalize?"
  signal. Low priority — R14's panel already shows this.

---

## §R17 — Port mobile-cart cards with swipe-to-delete

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #10 — "Mobile-cart cards with swipe-to-delete"

### Problem

Laravel renders cart items as a `<table>` with `.table-responsive`
wrapping. On mobile this means horizontal scrolling, tiny inputs
(`form-control-sm`), and a "Delete" button that's hard to tap.
Legacy solves this with a card-based layout that swaps in below
768px viewport width + a swipe-left-to-delete gesture (80px
threshold, matches iOS Mail / Messages conventions).

### Decision

Port the Legacy pattern with one modernization: use Pointer
Events instead of Touch Events (broader input coverage, simpler
code).

- Cart items render in TWO views simultaneously: a desktop `<tbody>`
  (existing) + a new `#cartItemsMobile` div of `.sales-cart-line`
  cards. CSS media query (`max-width: 767.98px`) toggles which is
  visible.
- Both views share the same `.cart-qty` / `.cart-rate` /
  `.cart-remove` / `.cart-total` classes — the existing delegated
  handlers work for both, no duplicated logic.
- Mobile cards have 44px-min tap targets + 16px font size (iOS
  no-zoom threshold).
- Swipe gesture: 80px left within 600ms triggers the existing
  `.cart-remove` click handler. A red `::before` pseudo-element
  with a trash icon is revealed behind the card during the swipe
  (visual affordance).

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - Wrapped the existing desktop `<table>` in
    `<div class="sales-cart-desktop table-responsive">`.
  - Added sibling `<div class="sales-cart-mobile" id="cartItemsMobile">`.
  - `renderCartTable()` now builds BOTH a `<tr>` (desktop) and a
    `<div class="sales-cart-line">` (mobile) per cart item, in
    the same loop.
  - Generalized `debouncedUpdate(productId)` from
    `$('#cartItemsBody tr[data-product-id="X"]')` to
    `$('[data-product-id="X"]').first()`.
  - Generalized the `.cart-remove` click handler from
    `closest('tr')` to `closest('[data-product-id]')`.
  - Generalized the `.cart-qty, .cart-rate` `input change`
    handler to update `.cart-total` cells in ALL views of the
    same product (desktop + mobile stay in sync during optimistic
    local updates).
  - New JS `initCartSwipeRemove()` is called at the end of every
    `renderCartTable()` (touch handlers don't survive
    `$mobile.empty()`). Uses Pointer Events:
    - `pointerdown` records startX + startedAt (only fires for
      touch/pen, not mouse).
    - `pointermove` translates the card left by the delta (clamped
      to -120px) + adds `.swiping` CSS class for visual feedback.
    - `pointerup` checks if delta < -80px AND elapsed < 600ms →
      triggers `.cart-remove` click (which calls existing
      `removeItem()` → SweetAlert confirm → server call).
    - `pointercancel` resets the card position.
  - CSS in the `@push('css')` block:
    - `.sales-cart-desktop { display:block; }` (default)
    - `.sales-cart-mobile { display:none; }` (default)
    - `@media (max-width: 767.98px)` swaps them.
    - `.sales-cart-line` card styling: border, border-radius:10px,
      padding:0.75rem, margin:0.5rem, position:relative,
      overflow:hidden, transition:transform .2s ease.
    - `.sales-cart-line::before` red pseudo-element with trash
      icon (`\f1f8`) — revealed as the card slides left.
    - `.sales-cart-line > *` gets `position:relative; z-index:1;
      background:#fff;` to sit above the red pseudo-element.
    - `.sales-cart-line .cart-qty, .cart-rate { min-height:44px;
      font-size:16px; }` (iOS no-zoom + accessible tap target).

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — the swipe gesture triggers the existing
  `.cart-remove` button's click handler.
- The desktop table is NOT removed — both views coexist. CSS
  media query determines which is visible.
- The Laravel sales invoice **edit** page is NOT touched.
  Legacy has mobile cards only on the create page; R17 matches
  that scope.
- The Legacy qty stepper (− button + display + + button) is NOT
  ported — Laravel uses a regular `<input type="number">` for
  qty, which works fine on mobile (the browser's native stepper
  handles +/−). Legacy needed the custom stepper because its
  qty display was a non-input `<span>`.
- The Legacy `cart-line` "delete-item" button is NOT separately
  wired — we reuse the existing `.cart-remove` class so the same
  SweetAlert confirm flow applies to both desktop and mobile.

### Verification

- File integrity: blade braces + parens + directives balanced.
- New element ID `#cartItemsMobile` unique.
- New function `initCartSwipeRemove()` defined exactly once.
- Mobile card HTML shares classes with desktop row HTML →
  existing delegated handlers cover both.
- `pointermove` clamped to -120px max so the card can't be
  dragged off-screen.
- `pointercancel` resets the card position (e.g., if a
  notification interrupts the gesture).

### Risks introduced

- **Medium.** Touch gestures can conflict with the page's
  horizontal scroll. Mitigation: the gesture only fires when
  the user starts a swipe LEFT (delta < 0); rightward swipes
  + vertical scrolls are ignored. The 600ms time limit also
  filters out slow drags (which are likely repositioning, not
  delete gestures).
- **Accidental delete risk:** mitigated by the SweetAlert
  confirm dialog (already in `removeItem()`). The swipe just
  triggers the click; the user still has to confirm.
- **Pointer Events browser support:** Chrome 55+, Safari 13+,
  Firefox 59+. Effectively universal in 2026.
- **Z-index stacking:** the red `::before` pseudo-element sits
  at z-index:0; the card content sits at z-index:1. The card
  itself has `overflow:hidden` so the red is hidden until the
  card slides left.

### Follow-ups

- Consider porting the Legacy qty stepper (− / display / +) for
  the mobile card — some cashiers prefer tap-to-increment over
  typing. Low priority; the native `<input type="number">`
  stepper works fine.
- Consider adding a "long-press to delete" alternative gesture
  for users who can't swipe (e.g., trackpad users on desktop).
  Low priority; the explicit Delete button is always visible in
  the card header.
- Consider porting the same mobile-card pattern to the sales
  invoice edit page. Legacy doesn't have it there; matching
  scope for now.

---

## §R10s — Barcode scanning simplified (single product search box)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #1 — supersedes R10's dual-mode UI

### Problem

R10 wired up barcode scanning with a separate `#barcodeInput` field
(toggle-revealed via `#btnToggleBarcode` in the card header) + a
"Scan & Add" button + an "Auto-add after scan" checkbox. This
duplicated the Select2 product search box and made the Add Product
card feel cluttered — two ways to do the same thing (find a
product by code).

The user's brief was explicit:
> "about Port barcode scanning: keep only product search just like
> customer search, no need 2 option searching product and scan,
> just keep search product and make the UI/UX better like lagachy"

### Decision

Consolidate to a single Select2 search box that doubles as the
barcode entry. Two layers of barcode support:

1. **Primary path (no extra JS):** The R1 AJAX search endpoint
   matches on `product_code` via ILIKE. Barcode scanners type
   the code rapidly + Enter; Select2's 250ms debounce catches
   the full scan; `selectOnClose: true` (newly added) makes
   Enter pick the highlighted first result.

2. **Fallback path (delegated keydown handler):** If the user
   types/scans a code that returns NO matches from the ILIKE
   search, intercept Enter on the Select2 search input and fire
   an exact-match lookup against the R1 `productByCode`
   endpoint. On success: inject the matched product as a fresh
   `<option>` + select it + trigger `change` (so rate/qty/price
   -band/availability populate via the existing handlers) + focus
   `#addQty`.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - REMOVED: `#btnToggleBarcode` button from the Add Product card
    header.
  - REMOVED: entire `#barcodeRow` HTML block (input + hint +
    "Scan & Add" button + auto-add checkbox).
  - REMOVED: all R10 JS — `$barcodeInput`, `$barcodeHint`,
    `$barcodeAutoAdd` vars; `#btnToggleBarcode` click handler;
    `#barcodeInput` keydown handler; `#btnBarcodeAdd` click
    handler; the entire `scanAndSelect()` function (~110 lines).
  - ADDED: `selectOnClose: true` to the `#addProduct` Select2 init.
  - ADDED: delegated `keydown` handler on `.select2-search__field`
    that intercepts Enter when the dropdown belongs to
    `#addProduct` AND no result is highlighted → calls new
    `lookupProductByCodeAndSelect(term)` function.
  - ADDED: `lookupProductByCodeAndSelect(code)` function (~50
    lines) that fetches the R1 `productByCode` endpoint, on
    success injects the matched product as a fresh `<option>` +
    selects it + triggers `change` + focuses `#addQty`. On
    failure, shows a toast + reopens the Select2 dropdown so
    the user can re-search.
  - UPDATED: `#addProduct` Select2 placeholder changed from
    "— Type product name / code —" to "— Type name / scan code —"
    to make the dual-purpose nature clear.
  - ADDED: a small `<span class="badge bg-light text-secondary">`
    next to the "Product" label that says "scan ok" with a
    barcode icon, so the cashier knows the field accepts scanner
    input.

### What was NOT changed

- No backend changes — same R1 endpoints
  (`admin.sales.cart.search-product` +
  `admin.sales.cart.product-by-code`).
- No new route — the fallback uses the existing `productByCode`
  endpoint via `ajaxGet`.
- No new migration — purely client-side.
- The R1 `productByCode` controller + service code is unchanged.
- The R10 "auto-add after scan" behaviour is NOT preserved. The
  R10s flow stops at "product selected, rate filled, qty focused"
  — the cashier reviews the rate/qty and clicks "Add" themselves.
  This is safer (no accidental adds from mis-scans) and matches
  Legacy's `selectProductCreate` behaviour (which also doesn't
  auto-add). If the user later wants auto-add back, it's a 2-line
  addition: append `addToCart();` to `lookupProductByCodeAndSelect`.

### Verification

- File integrity: blade braces + parens + directives balanced.
- All R10 element IDs (`#barcodeInput`, `#barcodeHint`,
  `#barcodeAutoAdd`, `#btnBarcodeAdd`, `#btnToggleBarcode`,
  `#barcodeRow`) are GONE from the file (0 references).
- The `scanAndSelect` function is GONE (0 references).
- New function `lookupProductByCodeAndSelect` defined exactly once.
- New `selectOnClose: true` option added to the `#addProduct`
  Select2 init.
- The delegated `keydown` handler correctly filters to only
  intercept Enter on the `#addProduct` Select2 (via
  `aria-controls` attribute check).

### Risks introduced

- **Low.** The fallback path is purely additive — if the
  delegated handler fails for any reason, the user can still
  type a code, see no results, and click away. The Select2
  behaves normally.
- **Scanner timing risk:** if a scanner's Enter arrives BEFORE
  the AJAX search resolves (extremely fast scanner + slow
  network), the highlighted-result check would find nothing
  (because results haven't loaded yet) → the fallback
  `productByCode` lookup fires. This is actually MORE robust
  than the R10 behaviour (which would have shown "No results"
  and required the user to click "Scan & Add" manually).
- **`aria-controls` attribute reliance:** Select2 v4.1 sets
  `aria-controls="select2-addProduct-results"` on the search
  input. If a future Select2 version changes this attribute
  naming, the filter would break and Enter would be intercepted
  on ALL Select2 search boxes (including the customer search).
  Mitigation: the handler checks `resultsId.indexOf('addProduct')`
  which is permissive enough to catch minor naming changes.

### Follow-ups

- If the user reports that scans sometimes don't resolve (because
  the scanner is faster than the 250ms debounce), consider
  lowering the Select2 `delay` from 250ms to 150ms. Trade-off:
  more AJAX requests while typing.
- If the user wants auto-add back, append `addToCart();` to the
  end of `lookupProductByCodeAndSelect` (after the focus call).
  This would restore R10's "scan → item appears in cart" flow.
- Consider porting the same single-box pattern to the sales
  invoice edit page (currently uses the old R10 dual-mode UI).
  Out of scope for this commit.
