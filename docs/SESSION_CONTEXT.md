# Session Context — debugRC Sales Module Remediation

> **Purpose:** This file is the persistent memory of the long-running
> Super Z ↔ user chat session that is auditing the Legacy vs Laravel
> Sales Entry systems in this repo. When the chat context is lost
> (long conversation, model restart, etc.), any future agent MUST read
> this file FIRST to recover full context before doing any work.
>
> **Last updated:** 2026-07-21 (R2 pushed)
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
│   ├── app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php # R2 added idempotency_token + cache check to store()
│   ├── app/Http/Requests/Api/V1/Sales/StorePaymentRequest.php # R2 added idempotency_token rule
│   ├── app/Services/Sales/                                  # SalesCartService, SalesInvoiceService, CustomerPaymentService, …
│   ├── app/Services/Stock/StockAvailabilityService.php      # R1 added searchProductsWithStock() + findProductByExactCode()
│   ├── app/Models/Customer.php                              # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── app/Models/Product.php                               # has scopeSearch() — tsvector + GIN, ILIKE fallback
│   ├── resources/views/admin/sales/cart.blade.php           # R1 replaced 500-row dropdowns with AJAX select2
│   ├── resources/views/admin/customer-payments/create.blade.php # R2 added hidden idempotency_token input (Str::uuid())
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
| R3  | Add idempotency token to challan issue                                    | ⏳ pending   | Mirror the same pattern on `POST /admin/sales-challans/issue/{invoiceId}`. Mitigated today by the `is_challan_issued` status check inside the lock. |
| R4  | Add cart mutation audit logging                                           | ⏳ pending   | Extend `SalesAuditLogger` with `cartItemAdded`, `cartItemUpdated`, `cartItemRemoved`, `cartCleared` methods. Closes V4. |
| R5  | (TBD)                                                                     | ⏳ pending   | Likely candidate: lock the customer row before credit-limit check (V5). |

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

### 5.4 What was NOT changed (deliberately)

- The Legacy sales entry code (`legacy/`) was not touched. Legacy
  is still the production system; the audit is a parallel analysis.
- The Laravel `SalesCartService`, `SalesInvoiceService`, and
  `SalesPaymentService` were not touched. R1 was purely a UI/search
  concern; R2 is purely a controller/validation concern.
- The Laravel API V1 controllers OTHER than `CustomerPaymentApiController`
  were not touched.
- The `confirm` and `cancel` payment endpoints were not given
  idempotency tokens. The `cancel` endpoint already has a `cancel_reason`
  guard and reverses a known existing payment, so duplicate-cancellation
  is not a real risk (it would fail with "payment is already reversed").

---

## 6. Open Work Items

(Items the user has asked for but that are not yet done.)

- **None outstanding.** R1 and R2 are complete and pushed. The user has
  not yet assigned R3.

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
