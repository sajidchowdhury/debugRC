# RC_ERP REST API Reference

**Version:** Phase 18 (v1)
**Base URL:** `/api/v1`
**Auth:** Bearer token (see [Authentication](#authentication) below)
**Response format:** JSON (`Content-Type: application/json`)

The RC_ERP REST API exposes 14 endpoints for mobile applications and AI
sidecars to read master data, run dashboard queries, and (for admin
tokens) manage branches.

An interactive documentation page (with a built-in "Try it" panel) is
also served at [`/api/docs`](/api/docs).

---

## Table of Contents

- [Authentication](#authentication)
- [Pagination format](#pagination-format)
- [Rate limiting](#rate-limiting)
- [Error responses](#error-responses)
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

Some endpoints require an elevated role (`admin` only — for write
operations on the Branch resource). The role is read from the user's
linked `Employee` record (matching the legacy schema).

| Endpoint                                | Required role   |
|-----------------------------------------|-----------------|
| `GET /branches`, `/branches/{id}`       | any authenticated |
| `POST /branches`                        | `admin`         |
| `PUT /branches/{id}`                    | `admin`         |
| `DELETE /branches/{id}`                 | `admin`         |
| `GET /dashboard*`                       | any authenticated |
| `GET /lookups/*`                        | any authenticated |

`superadmin` always passes any role check.

### Using the token

```bash
curl -H "Authorization: Bearer <your-token>" \
     -H "Accept: application/json" \
     https://erp.example.com/api/v1/branches
```

---

## Pagination format

List endpoints (`GET /branches`) return Laravel's standard paginator
JSON shape:

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
| `q`         | —       | Optional search term (ILIKE match).      |

---

## Rate limiting

The API is rate-limited by Laravel's standard `throttle` middleware
(default: 60 requests/minute per IP, configurable in
`routes/api.php` / `bootstrap/app.php`).

When rate-limited, the response is HTTP **429 Too Many Requests** with a
`Retry-After` header (seconds).

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

## Branches

### GET /branches

List branches with pagination + optional search.

**Required role:** any authenticated user.

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
      "created_by": 1,
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

**Required role:** any authenticated user.

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

**Required role:** `admin`.

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

**Required role:** `admin`.

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

**Required role:** `admin`.

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

**Required role:** any authenticated user.

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

**Required role:** any authenticated user.

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

**Required role:** any authenticated user.

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

## Changelog

| Date       | Phase  | Change                                                            |
|------------|--------|-------------------------------------------------------------------|
| 2025-01-17 | 13     | Initial API — 14 endpoints, Bearer auth, ApiAuth middleware.      |
| 2025-01-19 | 18     | Added interactive docs at `/api/docs` + `api:token` Artisan command + this reference document. |
