# RC_ERP REST API Reference

**Version:** Phase 18 (v1) — expanded API-6 to cover all 100 `/v1` endpoints
**Base URL:** `/api/v1`
**Auth:** Bearer token (see [Authentication](#authentication) below)
**Response format:** JSON (`Content-Type: application/json`)

The RC_ERP REST API exposes **100 authenticated `/api/v1/*` endpoints** across
**13 module groups** for mobile applications and AI sidecars to read master
data, run dashboard queries, manage sales transactions (cart → invoice →
challan → return → payment), run stock operations (transfers, adjustments,
stock-take), and manage inter-branch demands + commission.

An interactive documentation page (with a built-in "Try it" panel) is also
served at [`/api/docs`](/api/docs). Since the G2 fix, that page is
**reflectively generated** from `routes/api.php` — its endpoint count always
matches the live route registry, so it can never drift behind the code. This
static reference complements it with full request/response schemas and
business-rule notes.

---

## Table of Contents

- [Authentication](#authentication)
- [Pagination format](#pagination-format)
- [Rate limiting](#rate-limiting)
- [Error responses](#error-responses)
- [Module index](#module-index)
- [Branches](#branches)
  - [GET /branches](#get-branches)
  - [GET /branches/{id}](#get-branchesid)
  - [POST /branches](#post-branches)
  - [PUT /branches/{id}](#put-branchesid)
  - [DELETE /branches/{id}](#delete-branchesid)
- [Dashboard](#dashboard)
  - [GET /dashboard](#get-dashboard)
  - [GET /dashboard/sales-trend](#get-dashboardsales-trend)
  - [GET /dashboard/top-products](#get-dashboardtop-products)
- [Lookups](#lookups)
  - [GET /lookups/branches](#get-lookupsbranches)
  - [GET /lookups/warehouses](#get-lookupswarehouses)
  - [GET /lookups/products](#get-lookupsproducts)
  - [GET /lookups/customers](#get-lookupscustomers)
  - [GET /lookups/suppliers](#get-lookupssuppliers)
  - [GET /lookups/ledgers](#get-lookupsledgers)
- [Sales Cart](#sales-cart)
- [Sales Invoices](#sales-invoices)
- [Sales Challans](#sales-challans)
- [Sales Returns](#sales-returns)
- [Customer Payments](#customer-payments)
- [Commission](#commission)
- [Warehouse Transfers](#warehouse-transfers)
- [Stock Adjustments](#stock-adjustments)
- [Stock Take](#stock-take)
- [Branch Demands](#branch-demands)
- [Changelog](#changelog)

---

## Authentication

All `/api/v1/*` endpoints require a **Bearer token** in the
`Authorization` header:

```
Authorization: Bearer <your-token>
```

### Obtaining a token

Tokens are issued by an administrator using the `api:token` Artisan
command on the server:

```bash
php artisan api:token jane.doe --role=admin
```

```
=== API Token Issued ===
  User:      jane.doe
  Role:      admin
  API Token: 1abc23def45ghi67jkl89mno012pqr345stu678vwx901yz234ab567cde890fgh123

  ⚠️  This token is shown only ONCE. Store it securely.
  Use as: Authorization: Bearer 1abc23def45ghi67jkl89mno012pqr345stu678vwx901yz234ab567cde890fgh123
```

The plain-text token is **64 characters**, alphanumeric. The DB column
`users.api_token` stores only the **SHA-256 hash** of the token — a DB
leak does NOT expose live tokens. Issuing a new token invalidates the
previous one.

### Role enforcement

Write endpoints (create, update, cancel, reverse, post, approve, destroy,
reprice) require an elevated role, enforced via the `api.auth:role1,role2`
route middleware. Read endpoints and draft-creation endpoints allow any
authenticated user. The role is read from the user's linked `Employee`
record.

`superadmin` always passes any role check. The full per-endpoint role
matrix is in the [Module index](#module-index) table below and in
`AI_CONTEXT/api/api-modules.md` §7.

### Using the token

```bash
curl -H "Authorization: Bearer <your-token>" \
     -H "Accept: application/json" \
     https://erp.example.com/api/v1/branches
```

---

## Pagination format

List endpoints (`GET /branches`, `GET /sales/invoices`, etc.) return
Laravel's standard paginator JSON shape:

```json
{
  "data": [
    { "id": 1, "branch_code": "HO", "branch_name": "Head Office", ... }
  ],
  "meta": {
    "current_page": 1,
    "last_page":    5,
    "per_page":     25,
    "total":        120,
    "from":         1,
    "to":           25
  }
}
```

| Query param | Default | Notes                                    |
|-------------|---------|------------------------------------------|
| `page`      | 1       | Page number (1-indexed).                 |
| `per_page`  | 25      | Page size. Clamped to 1–100.             |
| `q` / `search` | —    | Optional search term (ILIKE match).      |

Lookup endpoints (`GET /lookups/*`) return a flat `{data: [...]}` array
with no `meta` — they are not paginated (slim pickers, always < 200 rows).

---

## Rate limiting

The API uses a **custom `ApiRateLimit` middleware** (Redis-backed with a
cache fallback — NOT Laravel's stock `throttle`). Three tiers, applied
per-route in `routes/api.php`:

| Tier | Endpoints                                          |
|------|----------------------------------------------------|
| 120 req/min | Dashboard, Lookups (polled reads — mobile home screen + pickers) |
| 60 req/min  | List / show / read endpoints (default for reads)   |
| 30 req/min  | Transactional writes (store, update, cancel, reverse, post, confirm, reprice, destroy) |

When rate-limited, the response is HTTP **429 Too Many Requests** with:

```json
{
  "message": "Rate limit exceeded. Maximum 30 requests per minute.",
  "retry_after": 42
}
```

and a `Retry-After` header (seconds).

---

## Error responses

All errors return JSON with a `message` field. Validation errors also
include `errors` (Laravel's standard shape).

| Status | Meaning                                                                  |
|--------|--------------------------------------------------------------------------|
| 400    | Bad request — e.g. trying to deactivate a branch with active dependents. |
| 401    | Missing or invalid Bearer token.                                         |
| 403    | Token is valid but user lacks the required role.                         |
| 404    | Resource with the given ID was not found.                                |
| 409    | Conflict — e.g. updating a non-draft invoice.                            |
| 422    | Validation error (response includes `errors` keyed by field).            |
| 429    | Rate limit exceeded.                                                     |
| 500    | Unexpected server error.                                                |

Example 401:

```json
{
  "message": "Unauthenticated.",
  "detail":  "Missing or invalid Authorization header."
}
```

Example 422 (missing required field on `POST /branches`):

```json
{
  "message": "The branch code field is required.",
  "errors": {
    "branch_code": ["The branch code field is required."]
  }
}
```

---

## Module index

100 authenticated `/api/v1/*` endpoints across 13 module groups. The
`GET /api/docs` page (public, not counted here) lists every card
reflectively.

| # | Module group | Endpoints | Rate | RLS GUC | Write role gate | Controller |
|---|---|---|---|---|---|---|
| 1 | [Branches](#branches) | 5 | 60 | no | `admin` (POST/PUT/DELETE) | `BranchApiController` |
| 2 | [Dashboard](#dashboard) | 3 | 120 | no | any authenticated | `DashboardApiController` |
| 3 | [Lookups](#lookups) | 6 | 120 | no | any authenticated | `LookupApiController` |
| 4 | [Sales Cart](#sales-cart) | 8 | 30/60 | no | `salesman,manager,admin` (writes) | `SalesCartApiController` |
| 5 | [Sales Invoices](#sales-invoices) | 7 | 30/60 | no | `salesman,manager,admin` (writes) | `SalesInvoiceApiController` |
| 6 | [Sales Challans](#sales-challans) | 5 | 30/60 | no | `warehouse_manager,dispatcher,manager,admin` (godown/issue); `manager,admin` (cancel) | `SalesChallanApiController` |
| 7 | [Sales Returns](#sales-returns) | 6 | 30/60 | no | `salesman,manager,admin` (store); `warehouse_manager,accountant,manager,admin` (confirm); `accountant,manager,admin` (reverse) | `SalesReturnApiController` |
| 8 | [Customer Payments](#customer-payments) | 6 | 30/60 | no | `salesman,manager,admin` (writes) | `CustomerPaymentApiController` |
| 9 | [Commission](#commission) | 8 | 30/60 | no | `admin` (rule writes + confirm-period) | `CommissionApiController` |
| 10 | [Warehouse Transfers](#warehouse-transfers) | 6 | 30/60 | yes | `manager,admin` (confirm/cancel) | `WarehouseTransferApiController` |
| 11 | [Stock Adjustments](#stock-adjustments) | 8 | 30/60 | yes | `admin,manager,accountant` (store/submit); `admin,manager` (approve/reject); `admin,accountant` (confirm/cancel) | `StockAdjustmentApiController` |
| 12 | [Stock Take](#stock-take) | 17 | 30/60 | yes | `admin,manager` (approve/reject/post/cancel/reverse/re-open) | `StockTakeSessionApiController` + `StockTakeItemApiController` |
| 13 | [Branch Demands](#branch-demands) | 15 | 30/60 | yes | `admin,manager,warehouse_manager` (store/send/confirm-receipt/reject); `admin,manager` (reverse/destroy/reprice) | `BranchDemandApiController` |
| | **Total** | **100** | | | | |

---

## Branches

### GET /branches

List branches with pagination + optional search.

**Required role:** any authenticated user. **Rate limit:** 60 req/min.

**Query parameters:**

| Param      | Type    | Required | Description                                                        |
|------------|---------|----------|--------------------------------------------------------------------|
| `q`        | string  | no       | Search term (matches `branch_code` or `branch_name`, case-insensitive). |
| `page`     | integer | no       | Page number (default 1).                                           |
| `per_page` | integer | no       | Page size (default 25, max 100).                                   |

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "branch_code": "HO",
      "branch_name": "Head Office",
      "address": "123 Main St, Dhaka",
      "phone": "02-1234567",
      "email": "ho@rcerp.test",
      "is_active": true,
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-01-15T12:34:56.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page":    5,
    "per_page":     25,
    "total":        120,
    "from":         1,
    "to":           25
  }
}
```

**Errors:** `401` (missing/invalid token).

---

### GET /branches/{id}

Show a single branch by ID. Includes soft-deleted branches (so admin
clients can inspect deactivated branches).

**Required role:** any authenticated user. **Rate limit:** 60 req/min.

**Path parameters:**

| Param | Type    | Required | Description                                  |
|-------|---------|----------|----------------------------------------------|
| `id`  | integer | yes      | Branch ID (must be a positive integer).      |

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "branch_code": "HO",
    "branch_name": "Head Office",
    "address": "123 Main St, Dhaka",
    "phone": "02-1234567",
    "email": "ho@rcerp.test",
    "is_active": true,
    "deleted_at": null,
    "created_at": "2025-01-01T00:00:00.000000Z",
    "updated_at": "2025-01-15T12:34:56.000000Z"
  }
}
```

**Errors:** `401`, `404`.

---

### POST /branches

Create a new branch.

**Required role:** `admin`. **Rate limit:** 60 req/min.

**Request body:**

```json
{
  "branch_code": "NEW-001",
  "branch_name": "New Branch",
  "address":     "Optional address",
  "phone":       "Optional phone",
  "email":       "optional@example.com",
  "is_active":   true
}
```

| Field          | Type    | Required | Validation rules                                              |
|----------------|---------|----------|---------------------------------------------------------------|
| `branch_code`  | string  | yes      | max 20, regex `^[A-Za-z0-9\-_.]+$`, unique in `branches`. Upper-cased before insert. |
| `branch_name`  | string  | yes      | max 100. Trimmed.                                             |
| `address`      | string  | no       | —                                                             |
| `phone`        | string  | no       | max 20.                                                       |
| `email`        | string  | no       | email format, max 100.                                        |
| `is_active`    | boolean | no       | Default `true`.                                               |

**Response (201):**

```json
{
  "data": {
    "id": 10,
    "branch_code": "NEW-001",
    "branch_name": "New Branch",
    ...
  },
  "message": "Branch created."
}
```

**Errors:** `401`, `403` (non-admin), `422` (validation or duplicate `branch_code`).

---

### PUT /branches/{id}

Update an existing branch. Only the supplied fields are updated.

**Required role:** `admin`. **Rate limit:** 60 req/min.

**Path parameters:** `id` (integer, required).

**Request body** (all fields optional — `sometimes`):

```json
{
  "branch_name": "Updated Name",
  "is_active":   false
}
```

Same field rules as `POST /branches`, except `branch_code` uses
`unique:branches,branch_code,{id}` (excludes the current row).

**Response (200):**

```json
{
  "data":    { "id": 1, "branch_code": "HO", "branch_name": "Updated Name", ... },
  "message": "Branch updated."
}
```

**Errors:** `401`, `403`, `404`, `422`.

---

### DELETE /branches/{id}

Deactivate (soft-delete) a branch. **Blocked** if the branch has:

- Active warehouses (`warehouses.is_active = true AND branch_id = {id}`)
- Active employees (`employees.is_active = true AND branch_id = {id}`)
- Open sales invoices (non-reversed, non-cancelled, status not in `cancelled|reversed`)

**Required role:** `admin`. **Rate limit:** 60 req/min.

**Path parameters:** `id` (integer, required).

**Response (200):**

```json
{
  "message": "Branch deactivated."
}
```

**Errors:**

| Status | When                                                              |
|--------|-------------------------------------------------------------------|
| 400    | Outstanding dependencies. Response includes a `blockers` array. |
| 401    | Missing/invalid token.                                            |
| 403    | Non-admin.                                                        |
| 404    | Branch not found.                                                 |

Example 400:

```json
{
  "message":  "Cannot deactivate branch — outstanding dependencies.",
  "blockers": [
    "3 active warehouse(s)",
    "12 active employee(s)",
    "5 open sales invoice(s)"
  ]
}
```

---

## Dashboard

### GET /dashboard

Summary stats for the mobile home screen — active master-data counts +
today's sales + today's collection.

**Required role:** any authenticated user. **Rate limit:** 120 req/min.

**Response (200):**

```json
{
  "data": {
    "counts": {
      "active_branches":   4,
      "active_warehouses":  8,
      "active_products":    1200,
      "active_customers":   340,
      "active_suppliers":   45,
      "active_employees":   120
    },
    "today": {
      "date":          "2025-01-19",
      "invoice_count": 12,
      "total_sales":   45000.50,
      "collection":    32000.00
    }
  }
}
```

**Errors:** `401`.

---

### GET /dashboard/sales-trend

Last 7 days of sales totals. Days with no sales are filled with zeros so
chart libraries draw a continuous line.

**Required role:** any authenticated user. **Rate limit:** 120 req/min.

**Response (200):**

```json
{
  "data": [
    { "date": "2025-01-13", "invoice_count": 8, "total_sales": 21000.00 },
    { "date": "2025-01-14", "invoice_count": 0, "total_sales": 0.00 }
  ],
  "meta": { "range_days": 7, "start": "2025-01-13", "end": "2025-01-19" }
}
```

**Errors:** `401`.

---

### GET /dashboard/top-products

Top 10 products by revenue over the last 30 days. Aggregates
`sales_invoice_items` joined with non-reversed, non-cancelled
`sales_invoices`.

**Required role:** any authenticated user. **Rate limit:** 120 req/min.

**Response (200):**

```json
{
  "data": [
    {
      "product_id":   5,
      "product_code": "P-001",
      "product_name": "Widget A",
      "qty_sold":     120.0,
      "revenue":      36000.00
    }
  ],
  "meta": { "range_days": 30, "since": "2024-12-20" }
}
```

**Errors:** `401`.

---

## Lookups

The `/lookups/*` family returns **slim** id+label records for active
master-data entities. Mobile clients use these to populate pickers
without pulling full records.

All lookup endpoints:

- **Required role:** any authenticated user.
- **Rate limit:** 120 req/min.
- **Errors:** `401` only.

### GET /lookups/branches

**Response:**

```json
{
  "data": [
    { "id": 1, "branch_code": "HO", "branch_name": "Head Office" }
  ]
}
```

---

### GET /lookups/warehouses

**Query parameters:**

| Param       | Type    | Required | Description                                          |
|-------------|---------|----------|------------------------------------------------------|
| `branch_id` | integer | no       | Filter warehouses by their parent branch.            |

**Response:**

```json
{
  "data": [
    { "id": 1, "warehouse_code": "WH-01", "warehouse_name": "Main Godown", "branch_id": 1 }
  ]
}
```

---

### GET /lookups/products

**Response:**

```json
{
  "data": [
    { "id": 1, "product_code": "P-001", "product_name": "Widget A", "unit": "Pcs", "sales_rate": 300.00 }
  ]
}
```

---

### GET /lookups/customers

**Response:**

```json
{
  "data": [
    { "id": 1, "customer_code": "CUS-2025-000001", "customer_name": "Acme Corp", "mobile": "01712345678" }
  ]
}
```

---

### GET /lookups/suppliers

**Response:**

```json
{
  "data": [
    { "id": 1, "supplier_code": "SUP-000001", "supplier_name": "Acme Supplier", "mobile": "01812345678" }
  ]
}
```

---

### GET /lookups/ledgers

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "ledger_code": "1000",
      "ledger_name": "Cash in Hand",
      "account_type": "Asset",
      "ledger_nature": "cash_bank"
    }
  ]
}
```

---

## Sales Cart

Per-user draft cart for building a sales invoice before finalizing.
Controller: `SalesCartApiController`. Services: `SalesCartService`,
`StockAvailabilityService`. FormRequests: `StoreCartRequest`,
`UpdateCartRequest`. Resource: `CartResource`.

**Middleware:** `api.auth`. Writes require `salesman,manager,admin`
(route-level role gate added by the Security/RLS cluster). The cart is
scoped per `Auth::id()` — one draft per salesman.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/cart` | any auth | 60 | Show the current user's draft cart. |
| POST | `/sales/cart` | `salesman,manager,admin` | 30 | Add an item to the cart (upsert by `product_id`). |
| PUT | `/sales/cart` | `salesman,manager,admin` | 30 | Update cart item qty/discount. |
| DELETE | `/sales/cart/{productId}` | `salesman,manager,admin` | 30 | Remove an item from the cart. |
| POST | `/sales/cart/clear` | `salesman,manager,admin` | 30 | Clear all cart items. |
| POST | `/sales/cart/validate` | any auth | 30 | Dry-run validation (no save). Returns availability + price check. |
| POST | `/sales/cart/soft-hold` | `salesman,manager,admin` | 30 | Mark cart as soft-held (draft dispatch hold). |
| GET | `/sales/cart/availability` | any auth | 60 | Check pipeline-aware stock availability for cart items. |

**Notes:**
- `validateCart` is a dry-run — it does NOT save; it returns the same shape
  as `show` plus per-item availability flags.
- `availability` calls `StockAvailabilityService::checkPipeline()` — the
  same service the web cart uses. See `AI_CONTEXT/sales/sales-cart.md`.

---

## Sales Invoices

The crown-jewel module — finalizes a cart into a posted invoice with GL
+ stock decrement. Controller: `SalesInvoiceApiController`. Services:
`SalesInvoiceService`, `SalesAccess`. FormRequest: `FinalizeInvoiceRequest`
(with `bodyParameters()` for Scribe docs). Resources: `SalesInvoiceResource`
+ `SalesInvoiceItemResource` + `SalesInvoiceDispatchResource`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/invoices` | any auth | 60 | List invoices (paginated; filters: `from_date`, `to_date`, `customer_id`, `branch_id`, `status`, `search`). |
| GET | `/sales/invoices/credit-check` | any auth | 60 | Check customer credit limit: `?customer_id=X&amount=Y`. Returns `{data: {customer_id, credit_limit, current_due, would_exceed, can_override}}`. |
| POST | `/sales/invoices/call-it-a-day` | any auth | 30 | Batch mark invoices as "called" (end-of-day). Body: `{invoice_ids: [...]}`. Returns `{message, updated_count}`. |
| GET | `/sales/invoices/{id}` | any auth | 60 | Show one invoice with items, dispatches, customer. |
| POST | `/sales/invoices` | `salesman,manager,admin` | 30 | **Idempotent** (requires `idempotency_token` UUID). Finalize cart → draft invoice + GL (Dr-AR / Cr-Revenue / Cr-COGS) + stock decrement. Returns 201. |
| PUT | `/sales/invoices/{id}` | `salesman,manager,admin` | 30 | Update a draft invoice (discount/transport/notes/soft-hold). 409 if not draft. |
| POST | `/sales/invoices/{id}/cancel` | `salesman,manager,admin` | 30 | Cancel/reverse an invoice. Body: `{reason: "..."}` (min 10 chars). Posts reversal journal. |

### POST /sales/invoices (detailed — idempotent finalize)

**Required role:** `salesman,manager,admin`. **Rate limit:** 30 req/min.

**Idempotency:** send an `idempotency_token` (UUID v4) in the body. If
the same token is replayed within 5 minutes, the original 201 response is
returned verbatim with `idempotent_replay: true` appended (HTTP 200, not
201). No new invoice is created.

**Request body:**

```json
{
  "customer_id": 1,
  "branch_id": 1,
  "invoice_date": "2025-01-19",
  "idempotency_token": "550e8400-e29b-41d4-a716-446655440000",
  "items": [
    {
      "product_id": 5,
      "warehouse_id": 1,
      "qty": 2,
      "unit_price": 300.00,
      "discount_percent": 0
    }
  ],
  "discount_amount": 0,
  "transport_cost": 0,
  "notes": "Walk-in sale"
}
```

**Response (201):**

```json
{
  "data": {
    "id": 1001,
    "invoice_code": "INV-2025-001001",
    "customer_id": 1,
    "branch_id": 1,
    "invoice_date": "2025-01-19",
    "subtotal": 600.00,
    "discount_amount": 0,
    "transport_cost": 0,
    "grand_total": 600.00,
    "paid_amount": 0,
    "status": "finalized",
    "items": [ { "product_id": 5, "qty": 2, "unit_price": 300.00, "line_total": 600.00 } ]
  },
  "message": "Invoice created successfully."
}
```

**Idempotent replay (200):** same body + `idempotent_replay: true` added.

**Errors:** `401`, `403`, `409` (cart empty / stock unavailable — mapped
from `RuntimeException`), `422` (validation / service guard — mapped from
`InvalidArgumentException`).

See `AI_CONTEXT/sales/sales-invoice.md` for the full business workflow
(GL posting rules, COGS computation, stock decrement, dispatch linkage).

---

## Sales Challans

Godown copies + dispatch (issue) for delivery tracking. Controller:
`SalesChallanApiController`. Services: `SalesChallanService`, `SalesAccess`.
FormRequests: `PrepareGodownRequest`, `IssueChallanRequest`. Resources:
`SalesChallanResource` + `SalesChallanItemResource`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/challans` | any auth | 60 | List challans (paginated, filtered). |
| POST | `/sales/challans/godown` | `warehouse_manager,dispatcher,manager,admin` | 30 | Prepare a godown copy (pre-dispatch). Posts no GL. |
| POST | `/sales/challans/issue` | `warehouse_manager,dispatcher,manager,admin` | 30 | **Idempotent** (requires `idempotency_token`). Issue a challan (dispatch). Posts COGS journal + stock decrement. |
| GET | `/sales/challans/{id}` | any auth | 60 | Show one challan with items. |
| POST | `/sales/challans/{id}/cancel` | `manager,admin` | 30 | Cancel a challan (reversal). Posts reversal journal. |

**Notes:**
- `issue` is idempotent (same 5-min cache-key pattern as invoice finalize,
  cache prefix `api:challan:{token}`). On replay, the message is overwritten
  with "Duplicate submission detected — returning the original result."
- BUG-52 fix: godown/issue/cancel previously had no role enforcement. Now
  restricted to `warehouse_manager,dispatcher,manager,admin`.
- See `AI_CONTEXT/sales/sales-challan.md` for the full business workflow.

---

## Sales Returns

Return + restock workflow. Controller: `SalesReturnApiController`.
Services: `SalesReturnService`, `SalesAccess`. FormRequest:
`StoreReturnRequest`. Resources: `SalesReturnResource` +
`SalesReturnItemResource`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/returns` | any auth | 60 | List returns (paginated, filtered). |
| GET | `/sales/returns/invoice-details` | any auth | 60 | Get invoice + challan + stock-transaction details for a return. Query: `?invoice_id=X`. |
| GET | `/sales/returns/{id}` | any auth | 60 | Show one return with items. |
| POST | `/sales/returns` | `salesman,manager,admin` | 30 | Create a return (draft). No idempotency. |
| POST | `/sales/returns/{id}/confirm` | `warehouse_manager,accountant,manager,admin` | 30 | Confirm a return — posts reversal journal + restocks at **original cost** (not moving-average). |
| POST | `/sales/returns/{id}/reverse` | `accountant,manager,admin` | 30 | Reverse a confirmed return. |

**Notes:**
- `confirm` restocks at the **original cost** (not the current
  moving-average) — this is a deliberate business rule. See
  `AI_CONTEXT/sales/sales-return.md`.
- `invoiceDetails` is a read helper that joins `sales_invoices` +
  `sales_challans` + `stock_transactions` to give the mobile client
  everything it needs to build a return form.

---

## Customer Payments

Payment collection + invoice allocation. Controller:
`CustomerPaymentApiController`. Services: `CustomerPaymentService`,
`SalesAccess`. FormRequest: `StorePaymentRequest`. Resources:
`CustomerPaymentResource` + `PaymentAllocationResource`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/payments` | any auth | 60 | List payments (paginated, filtered). |
| GET | `/sales/payments/outstanding-invoices` | any auth | 60 | List outstanding invoices for a customer. Query: `?customer_id=X`. |
| GET | `/sales/payments/{id}` | any auth | 60 | Show one payment with allocations. |
| POST | `/sales/payments` | `salesman,manager,admin` | 30 | **Idempotent** (requires `idempotency_token`). Create a payment + allocate to invoices. Posts Dr-Cash / Cr-AR. Optional `auto_confirm` flag. |
| POST | `/sales/payments/{id}/confirm` | `salesman,manager,admin` | 30 | Confirm a draft payment — posts the GL. |
| POST | `/sales/payments/{id}/cancel` | `salesman,manager,admin` | 30 | Cancel a payment — posts reversal. |

### POST /sales/payments (detailed — idempotent)

**Required role:** `salesman,manager,admin`. **Rate limit:** 30 req/min.

**Idempotency:** same `idempotency_token` pattern as invoice finalize
(cache key prefix `api:payment:{token}`). On replay, the message is
overwritten with "Duplicate submission detected — returning the original
result. No new payment was created."

**Request body:**

```json
{
  "customer_id": 1,
  "branch_id": 1,
  "payment_mode": "cash",
  "transaction_type": "receipt",
  "amount": 5000.00,
  "payment_date": "2025-01-19",
  "idempotency_token": "550e8400-e29b-41d4-a716-446655440000",
  "allocations": [
    { "invoice_id": 1001, "amount": 5000.00 }
  ],
  "auto_confirm": true,
  "notes": "Full settlement of invoice 1001"
}
```

**Response (201):**

```json
{
  "data": {
    "id": 501,
    "customer_id": 1,
    "branch_id": 1,
    "payment_mode": "cash",
    "amount": 5000.00,
    "status": "confirmed",
    "payment_date": "2025-01-19",
    "allocations": [ { "invoice_id": 1001, "amount": 5000.00 } ]
  },
  "message": "Payment created successfully."
}
```

**Errors:** `401`, `403`, `422` (over-allocation / invalid invoice /
negative amount).

See `AI_CONTEXT/accounting/customer-payments.md` for the GL workflow.

---

## Commission

API-only module (no web mirror). Controller: `CommissionApiController`.
Services: `CommissionService`, `SalesAccess`. FormRequest:
`StoreCommissionRuleRequest`. Resources: `CommissionRuleResource` +
`CommissionEntryResource`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/sales/commission/rules` | any auth | 60 | List rules (paginated). `per_page` clamped to 1–100 (G4 fix). |
| GET | `/sales/commission/rules/{id}` | any auth | 60 | Show one rule with tiers, product groups, targets. |
| POST | `/sales/commission/rules` | `admin` | 30 | Create a rule. Body: `{rule_type: flat\|tiered\|product_group\|target_bonus, ...}`. Returns 201. |
| POST | `/sales/commission/rules/{id}/deactivate` | `admin` | 30 | Deactivate a rule (sets `effective_to=today`, `is_active=false`). |
| GET | `/sales/commission/entries` | any auth | 60 | List commission entries (paginated). Filter: `commission_period`. |
| GET | `/sales/commission/salesman-summary` | any auth | 60 | Per-salesman commission summary. Query: `?from_date=&to_date=&salesman_id=`. |
| GET | `/sales/commission/branch-summary` | any auth | 60 | Per-branch commission summary. Query: `?from_date=&to_date=&branch_id=`. |
| POST | `/sales/commission/confirm-period` | `admin` | 30 | Confirm a commission period (locks entries). Body: `{from_date, to_date, branch_id}`. |

**Notes:**
- The ONLY API-only module — `routes/web.php` has zero commission routes.
- See `AI_CONTEXT/sales/commission.md` for the business rules (flat /
  tiered / product-group / target-bonus rule types, entry calculation,
  period confirmation).

---

## Warehouse Transfers

Inter-warehouse stock moves (same branch). Controller:
`WarehouseTransferApiController`. Services: `WarehouseTransferService`,
`StockService`, `StockAvailabilityService`. Resource:
`WarehouseTransferResource` + `WarehouseTransferItemResource`.

**Middleware:** `api.auth` + `set.api.branch` (sets the GUC so RLS on
`warehouse_transfers` filters by the authenticated user's branch).

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/warehouse-transfers` | any auth | 60 | List transfers (paginated, filtered). |
| GET | `/warehouse-transfers/product-stock` | any auth | 60 | Pipeline-aware availability for a product. Query: `?product_id=X&warehouse_id=Y`. |
| GET | `/warehouse-transfers/{id}` | any auth | 60 | Show one transfer with items. |
| POST | `/warehouse-transfers` | any auth | 30 | Create a draft transfer. No idempotency. Controller-level same-branch guard. |
| POST | `/warehouse-transfers/{id}/confirm` | `manager,admin` | 30 | Confirm a transfer — moves stock + posts GL (Dr-new-warehouse / Cr-old-warehouse). Confirm-time same-branch guard. |
| POST | `/warehouse-transfers/{id}/cancel` | `manager,admin` | 30 | Cancel a transfer — reverses stock + GL if confirmed. |

**Notes:**
- Same-branch enforcement at THREE levels: controller, service, DB trigger.
- `productStock` calls `StockAvailabilityService::checkPipeline()`.
- See `AI_CONTEXT/inventory/warehouse-transfer.md` for the full workflow.

---

## Stock Adjustments

Draft → submit → approve → confirm → cancel lifecycle with maker-checker.
Controller: `StockAdjustmentApiController` (695L — the largest API
controller). Services: `StockAdjustmentService`,
`StockAdjustmentPolicyService`. Resource: `StockAdjustmentResource` +
`StockAdjustmentItemResource`.

**Middleware:** `api.auth` + `set.api.branch`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/stock-adjustments` | any auth | 60 | List adjustments (paginated; filters: `from_date`, `to_date`, `status`, `category`, `branch_id`, `search`). |
| GET | `/stock-adjustments/{id}` | any auth | 60 | Show one adjustment with items + stock_movements + GL + audit. |
| POST | `/stock-adjustments` | `admin,manager,accountant` | 30 | Create a draft adjustment. Body: `{adjustment_category, branch_id, warehouse_id, items: [...], notes, reason}`. No idempotency. |
| POST | `/stock-adjustments/{id}/submit` | `admin,manager,accountant` | 30 | Submit draft for approval. Maker-checker: approver ≠ submitter. |
| POST | `/stock-adjustments/{id}/approve` | `admin,manager` | 30 | Approve (maker-checker). Auto-approve below threshold. |
| POST | `/stock-adjustments/{id}/reject` | `admin,manager` | 30 | Reject → back to draft. |
| POST | `/stock-adjustments/{id}/confirm` | `admin,accountant` | 30 | Confirm — apply stock + post GL. Force-confirm (admin only) via `Policy::canForceConfirm`. |
| POST | `/stock-adjustments/{id}/cancel` | `admin,accountant` | 30 | Cancel — reverse stock + GL if confirmed (reversal by exact `stock_transaction_id`). |

**Notes:**
- Reuses the SAME `StockAdjustmentService` +
  `StockAdjustmentPolicyService` as the web controller — every Phase 1–7
  protection is in force (role gating, category routing, maker-checker,
  audit log, UOM conversion, pipeline-aware availability, reversal safety).
- Model module for defense-in-depth: route-level role gate + controller
  `Policy::canSubmit/Approve/Confirm` re-check + service-level re-check.
- See `AI_CONTEXT/inventory/stock-adjustment.md` for the full lifecycle.

---

## Stock Take

Full count-session lifecycle. Controllers:
`StockTakeSessionApiController` (13 endpoints) +
`StockTakeItemApiController` (4 endpoints). Services:
`StockTakeService`, `StockTakePolicyService`. FormRequests:
`StoreSessionRequest`, `SaveCountsRequest`, `ImportCountsRequest`,
`ApproveSessionRequest`, `ReasonRequest`. Resources: `StockTakeResource`,
`StockTakeItemResource`, `StockTakeWarehouseResource`.

**Middleware:** `api.auth` + `set.api.branch`.

### Session endpoints (13)

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/stock-take/sessions` | any auth | 60 | List sessions (paginated; filters: `status`, `warehouse_id`, `branch_id`). |
| GET | `/stock-take/sessions/{id}` | any auth | 60 | Show one session with warehouses + items. |
| POST | `/stock-take/sessions` | any auth | 30 | Create a draft session. Body: `{branch_id, warehouse_ids: [...], session_date, notes}`. No idempotency. |
| POST | `/stock-take/sessions/{id}/setup/{warehouseId}` | any auth | 30 | Set up counts for a warehouse (loads products for counting). Heavier query — 30/min. |
| PUT | `/stock-take/sessions/{id}/counts/{warehouseId}` | any auth | 30 | Save physical counts for a warehouse. Body: `{counts: {product_id: physical_qty, ...}, reasons: {...}}`. |
| POST | `/stock-take/sessions/{id}/import/{warehouseId}` | any auth | 30 | CSV import (multipart `csv_file`). Columns: `product_code, physical_qty, reason`. Max 2MB. |
| POST | `/stock-take/sessions/{id}/submit` | any auth | 30 | Submit for approval (counter → approver). |
| POST | `/stock-take/sessions/{id}/approve` | `admin,manager` | 30 | Approve the count. |
| POST | `/stock-take/sessions/{id}/reject` | `admin,manager` | 30 | Reject → back to counting. |
| POST | `/stock-take/sessions/{id}/post` | `admin,manager` | 30 | Post — apply variances + GL (Dr/Cr-Inventory vs Stock-Take-Variance). Destructive. |
| POST | `/stock-take/sessions/{id}/cancel` | `admin,manager` | 30 | Cancel (draft/counting only). |
| POST | `/stock-take/sessions/{id}/reverse` | `admin,manager` | 30 | Reverse a posted session → reversed. Posts reversal journals. |
| POST | `/stock-take/sessions/{id}/re-open` | `admin,manager` | 30 | Re-open a reversed session → counting. Capped by `max_reopens`. |

### Item endpoints (4)

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/stock-take/sessions/{id}/items` | any auth | 60 | List items. Query: `?warehouse_id=X&variance_only=true`. |
| GET | `/stock-take/sessions/{id}/items/{itemId}` | any auth | 60 | Show one item. |
| PUT | `/stock-take/sessions/{id}/items/{itemId}` | any auth | 30 | Autosave one count line. |
| GET | `/stock-take/sessions/{id}/variance` | any auth | 60 | Variance report + summary (gain/loss/net value). |

**Notes:**
- Full lifecycle: `draft → counting → submitted → approved → posted →
  reversed → (re-open → counting)`. See
  `AI_CONTEXT/inventory/stock-take.md` for the state machine.
- `post` is destructive — it applies variances and posts GL. Cannot be
  undone except via `reverse`.
- Items controller uses `DB::table` (not Eloquent) for the list query —
  performance optimisation for large count sessions.

---

## Branch Demands

Inter-branch demand + FIFO settlement + repricing + audit. The most
complex API module — 6 service dependencies. Controller:
`BranchDemandApiController`. Services: `BranchDemandService`,
`BranchIntercompanyService`, `BranchDemandRepricingService`,
`BranchDemandAuditService`, `StockService`, `StockAvailabilityService`.
Resource: `BranchDemandResource`.

**Middleware:** `api.auth` + `set.api.branch`.

| Method | URL | Role | Rate | Description |
|---|---|---|---|---|
| GET | `/branch-demands` | any auth | 60 | List demands (paginated, filtered). Non-admins see only their branch's demands. |
| GET | `/branch-demands/{id}` | any auth | 60 | Show one demand with items + audit. |
| GET | `/branch-demands/outstanding` | any auth | 60 | Outstanding demands (un-settled). |
| GET | `/branch-demands/ledger-history` | any auth | 60 | Intercompany ledger history. |
| GET | `/branch-demands/settlement-preview` | any auth | 60 | Dry-run settlement preview (FIFO). |
| GET | `/branch-demands/{id}/audit` | any auth | 60 | Audit trail for one demand. |
| GET | `/branch-demands/warehouses/{branchId}` | any auth | 60 | Warehouses for a branch (helper for the create form). |
| GET | `/branch-demands/product-stock/{productId}/{branchId}` | any auth | 60 | Pipeline-aware availability for a product at a branch. |
| POST | `/branch-demands` | `admin,manager,warehouse_manager` | 30 | Create a demand. No idempotency. Branch isolation check. |
| POST | `/branch-demands/{id}/send` | `admin,manager,warehouse_manager` | 30 | Send a demand (draft → sent). Posts intercompany journals (dual creditor + debtor). |
| POST | `/branch-demands/{id}/confirm-receipt` | `admin,manager,warehouse_manager` | 30 | Confirm receipt (sent → received). Required before reversal. |
| POST | `/branch-demands/{id}/reverse` | `admin,manager` | 30 | Reverse a demand. Posts reversal. Blocked if receipt not confirmed. |
| POST | `/branch-demands/{id}/reject` | `admin,manager,warehouse_manager` | 30 | Reject a demand. |
| DELETE | `/branch-demands/{id}` | `admin,manager` | 30 | Delete a demand (draft only). |
| POST | `/branch-demands/{id}/reprice` | `admin,manager` | 30 | Reprice a demand. Body: `{new_total_value, reason, approved_by}`. Posts GL adjustment. |

### POST /branch-demands/{id}/reprice (detailed)

**Required role:** `admin,manager`. **Rate limit:** 30 req/min.

Adjusts the total value of a **received** demand (status = `received`,
`is_reversed = false`). Posts a GL adjustment journal for the delta. The
new total must differ from the current total and must not produce a
negative outstanding balance (new_total ≥ settlement_amount).

**Request body:**

```json
{
  "new_total_value": 1200.00,
  "reason": "Negotiated price increase with partner branch.",
  "approved_by": 1
}
```

| Field             | Type    | Required | Validation rules                                              |
|-------------------|---------|----------|---------------------------------------------------------------|
| `new_total_value` | number  | yes      | > 0. Must differ from current total. Must be ≥ settlement amount. |
| `reason`          | string  | yes      | min 10 chars.                                                 |
| `approved_by`     | integer | yes      | User ID of the approver.                                      |

**Response (200):**

```json
{
  "data": {
    "id": 42,
    "original_total": 1000.00,
    "new_total": 1200.00,
    "adjustment_amount": 200.00,
    "journal_entry_id": 9876
  },
  "message": "Demand repriced successfully."
}
```

**Errors:** `401`, `403`, `404`, `422` (status not `received`, same total,
or new total < settlement amount → negative outstanding balance).

See `AI_CONTEXT/finance/branch-demand.md` for the full business workflow.

---

## Changelog

| Date       | Phase / Task | Change                                                            |
|------------|--------------|-------------------------------------------------------------------|
| 2025-01-17 | 13           | Initial API — 14 endpoints, Bearer auth, ApiAuth middleware.      |
| 2025-01-19 | 18           | Added interactive docs at `/api/docs` + `api:token` Artisan command + this reference document. |
| 2025-01-22 | Phase 8/9/10 + Task 37 | Added Sales Cart (8), Sales Invoices (7), Sales Challans (5), Sales Returns (6), Customer Payments (6), Commission (8), Warehouse Transfers (6), Stock Adjustments (8), Stock Take (17), Branch Demands (15) — 86 new endpoints. |
| 2025-01-23 | API-3        | Extracted 8 FormRequests across 5 modules + clamped `per_page` to 1–100 (G4 fix). |
| 2025-01-23 | API-6 (G1+G2) | **Rewrote this document to cover all 100 `/v1` endpoints** (was 14, 86% drift — G1). `/api/docs` page now reflectively generated from `routes/api.php` with a drift-guard test (G2). Compact per-module tables added for Sales Cart / Invoices / Challans / Returns / Payments / Commission / Warehouse Transfers / Stock Adjustments / Stock Take / Branch Demands; detailed examples added for `POST /sales/invoices` (idempotent finalize), `POST /sales/payments` (idempotent), and `POST /branch-demands/{id}/reprice`. |
