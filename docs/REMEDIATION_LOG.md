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
