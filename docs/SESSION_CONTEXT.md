# Session Context — debugRC Sales Module Remediation

> **Purpose:** This file is the persistent memory of the long-running
> Super Z ↔ user chat session that is auditing the Legacy vs Laravel
> Sales Entry systems in this repo. When the chat context is lost
> (long conversation, model restart, etc.), any future agent MUST read
> this file FIRST to recover full context before doing any work.
>
> **Last updated:** 2026-07-21 (R5 + H1 bugfix pushed)
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
│   ├── app/Http/Controllers/Admin/SalesCartController.php    # Laravel cart controller (R1 owner)
│   ├── app/Http/Controllers/Admin/SalesInvoiceController.php # Laravel invoice finalize/edit/cancel (has idempotency_token since P2-6)
│   ├── app/Http/Controllers/Admin/CustomerPaymentController.php # R2 added idempotency_token + cache check to store()
│   ├── app/Http/Controllers/Admin/SalesChallanController.php    # R3 added idempotency_token + cache check to issueChallan()
│   ├── app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php # R2 added idempotency_token + cache check to store()
│   ├── app/Http/Controllers/Api/V1/Sales/SalesChallanApiController.php    # R3 added idempotency_token + cache check to issue()
│   ├── app/Http/Requests/Api/V1/Sales/StorePaymentRequest.php # R2 added idempotency_token rule
│   ├── app/Http/Requests/Api/V1/Sales/IssueChallanRequest.php # R3 added idempotency_token rule
│   ├── app/Services/Sales/                                  # SalesCartService, SalesInvoiceService, SalesChallanService, CustomerPaymentService, …
│   ├── app/Services/Sales/SalesAuditLogger.php              # R4 added cartItemAdded/Updated/Removed/Cleared methods + recentSalesEvents entries
│   ├── app/Services/Sales/SalesCartService.php              # R4 wired SalesAuditLogger into addItem/updateItem/removeItem/clearCart
│   ├── app/Services/Sales/SalesInvoiceService.php           # R5 added assertCreditLimitUnderLock() — Customer::lockForUpdate() inside finalize + update transactions
│   ├── app/Models/CustomerPayment.php                      # H1 bugfix: removed dead isDraft/isConfirmed/isCancelled (status column doesn't exist)
│   ├── app/Http/Controllers/Admin/CustomerController.php   # H1 bugfix: removed whereNotIn('status') on CustomerPayment/SalesReturn queries in show()
│   ├── app/Services/Stock/StockAvailabilityService.php      # R1 added searchProductsWithStock() + findProductByExactCode()
│   ├── app/Models/Customer.php                              # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── app/Models/Product.php                               # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── resources/views/admin/sales/cart.blade.php           # R1 replaced 500-row dropdowns with AJAX select2
│   ├── resources/views/admin/customer-payments/create.blade.php # R2 added hidden idempotency_token input (Str::uuid())
│   ├── resources/views/admin/sales-challans/issue.blade.php # R3 added hidden idempotency_token input (Str::uuid())
│   └── routes/web.php                                       # R1 added cart/search-customer, cart/search-product, cart/product-by-code
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
| R6  | (TBD)                                                                     | ⏳ pending   | Likely candidate: add `branch_id` to `sales_draft_carts` unique key (V11, C7). |

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

---

## 6. Open Work Items

(Items the user has asked for but that are not yet done.)

- **None outstanding.** R1, R2, R3, R4, R5, and H1 (bugfix) are
  complete and pushed. The user has not yet assigned R6.

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
