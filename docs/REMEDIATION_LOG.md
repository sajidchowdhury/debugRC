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
