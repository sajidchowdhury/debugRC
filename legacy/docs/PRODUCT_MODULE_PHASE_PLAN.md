# Product Setup & Price Range — Feature Inventory & Phase Plan

**Project:** Remote Center ERP  
**Scope:** Product master, Product Groups, price range (Min / Max / Default), audit, RBAC, sales API contract  
**Goal:** Governed product catalog with range-based pricing ready for sales integration  
**Last updated:** 2026-06-18

**Status legend:** ✅ Done · ⚠️ Partial / needs work · ❌ Not done

---

## Goals

1. **Product Group** — master list (like categories), default **China** on new products.
2. **Price range** — each price entry: **Min**, **Max**, **Default (suggested)**; rule `min <= default <= max`.
3. **Sales prep** — APIs expose range; sales UI enforcement deferred to Phase 8+.
4. **Audit & RBAC** — role-gated product/price actions; rich audit trail.

---

## Files in scope

| Area | Files |
|------|-------|
| Controller | `app/controllers/ProductController.php` |
| Model | `app/models/ProductModel.php` |
| Views | `app/views/products/*`, `app/views/products/groups/*`, `app/views/products/categories/*` |
| Helpers / services | `app/helpers/Helper.php`, `app/services/Stock/StockAvailabilityService.php` |
| Sales (contract only) | `app/services/Sales/traits/SalesCartOperationsTrait.php` |
| RBAC | `app/config/route_roles.php` |
| Migrations | `database/migrations/033_product_groups_and_price_range.sql`, `034_drop_product_price_sales_rate.sql` |
| Audit checklist | `app/models/PurchaseAuditModel.php` |

---

## Feature inventory

### 1. Product master

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 1.1 | Product catalog (server-side DataTable) | ✅ | Filters: category, unit, group |
| 1.2 | Create / edit product (name, category, group, unit, packaging, image) | ✅ | Group default China |
| 1.3 | Auto product code (`P-0001` style) | ✅ | |
| 1.4 | Soft deactivate / restore | ✅ | Blocked when used in transactions |
| 1.5 | Bulk deactivate / restore | ✅ | Admin only |
| 1.6 | Server-side validation on store/update | ✅ | Name, unit whitelist, category/group |
| 1.7 | Category management | ✅ | Pre-existing |
| 1.8 | **Product Group management** | ✅ | Master list; China protected |
| 1.9 | RBAC on product routes | ✅ | `route_roles.php` + `requireRouteAccess()` |

### 2. Price range

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 2.1 | Price history (append-only) | ✅ | Min, Max, Default per entry |
| 2.2 | Validation `min <= default <= max` | ✅ | Server-side |
| 2.3 | `getCurrentPrice()` SSOT | ✅ | `ProductModel` |
| 2.4 | Catalog shows range + default | ✅ | |
| 2.5 | Price delete disabled (append-only) | ✅ | |
| 2.6 | `created_by` on price history UI | ✅ | Join employees |
| 2.7 | Rich audit (old vs new rates) | ✅ | `product_price_added` |
| 2.8 | `sales_rate` column deprecated | ✅ | Migration 034 drops after backfill sync |

### 3. Security & audit

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 3.1 | CSRF on all POST actions | ✅ | Pre-existing |
| 3.2 | Login required | ✅ | Pre-existing |
| 3.3 | Route role matrix | ✅ | Phase 5 |
| 3.4 | Product audit log viewer | ✅ | Shows min/max/default |
| 3.5 | Deactivate not blocked by price history alone | ✅ | `canDelete()` fixed |
| 3.6 | Secure image upload | ✅ | Pre-existing |

### 4. Sales API contract (no sales UI yet)

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 4.1 | `searchProductsWithStock` returns range fields | ✅ | `min_rate`, `max_rate`, `default_rate`, `price` alias |
| 4.2 | `Helper::Product_Price_Now` returns full range | ✅ | |
| 4.3 | `validateRateInRange()` stub in cart trait | ✅ | Not wired to cart yet |
| 4.4 | Sales UI range hint + enforcement | ❌ | Phase 8+ |

---

## RBAC matrix (`ProductController`)

| Action | Allowed roles |
|--------|----------------|
| `index`, `price_history` | admin, manager, warehouse_manager |
| `create`, `store`, `edit`, `update` | admin, manager |
| `add_price`, `categories`, `categoryCreate`, `categoryStore`, `categoryEdit`, `categoryUpdate` | admin, manager |
| `groups`, `groupCreate`, `groupStore`, `groupEdit`, `groupUpdate` | admin, manager |
| `delete`, `restore`, `bulkAction`, `delete_price` | admin |
| `categoryDelete`, `categoryRestore`, `groupDelete`, `groupRestore` | admin |
| `audit` | admin |

Admin-tier users (`admin`, `superadmin`) always pass via `RouteAccess::allows()`.

---

## Sales API contract

### `GET /sales/search_product` (via `StockAvailabilityService::searchProductsWithStock`)

Each product object includes:

```json
{
  "id": 42,
  "product_code": "P-0042",
  "product_name": "Sample SKU",
  "min_rate": 120.00,
  "max_rate": 130.00,
  "default_rate": 125.00,
  "price": 125.00,
  "available_qty": 50
}
```

- **`price`** — backward-compatible alias of **`default_rate`** (pre-fill for sales rate input).
- **`min_rate` / `max_rate`** — admin-defined bounds for the current effective price entry.

### Future cart validation (Phase 8+)

```
min_rate <= entered_rate <= max_rate
```

Invoice line `rate` column unchanged — stores actual sold rate at finalize time.

### Price resolution rule

Latest `product_price_history` row by: `effective_from DESC, created_at DESC, id DESC`.

---

## Phase-by-phase plan

### Phase 0 — Documentation — ✅ DONE
- [x] Create this tracking document.

### Phase 1 — Database foundation — ✅ DONE
- [x] Migration `033_product_groups_and_price_range.sql`
- [x] `product_groups` + seed China
- [x] `products.group_id`
- [x] `product_price_history.min_rate`, `max_rate`, `default_rate`

**Apply:** `php database/run_migrations.php`

### Phase 2 — Product Groups master UI — ✅ DONE
- [x] CRUD routes mirroring categories
- [x] China group protected from deactivate

### Phase 3 — Group on product forms — ✅ DONE
- [x] Create/edit dropdown, catalog column + filter

### Phase 4 — Price range setup — ✅ DONE
- [x] Three-field price form + history UI
- [x] `getCurrentPrice()` SSOT
- [x] Fix `canDelete()`, append-only prices, rich audit

### Phase 5 — Security & RBAC — ✅ DONE
- [x] `route_roles.php` entries
- [x] `requireRouteAccess()` on all actions
- [x] Server validation + DataTable orderDir whitelist

### Phase 6 — Sales-readiness contract — ✅ DONE
- [x] API fields on search; Helper delegation; cart stub

### Phase 7 — Audit polish & QA — ✅ DONE
- [x] Audit UI min/max/default + user names
- [x] PurchaseAudit checks
- [x] Migration `034_drop_product_price_sales_rate.sql`
- [x] Manual QA checklist below

### Phase 8+ — Sales integration (out of scope)
- [ ] Sales UI: `Rec. 125 · Range 120–130`
- [ ] Client + server rate-in-range validation on add-to-cart
- [ ] Scheduled / branch-specific prices

---

## Manual QA checklist

- [ ] Run migrations on dev DB
- [ ] Open `/product/groups` — China exists, create "Local"
- [ ] Create product — default group China; change to Local; save
- [ ] Catalog filter by group works
- [ ] Price history: set min 120, max 130, default 125 — saves with validation errors if invalid
- [ ] Catalog shows range; edit sidebar shows range
- [ ] Deactivate product with price history (no transactions) — succeeds
- [ ] Salesman role cannot open add_price (403 or redirect)
- [ ] `search_product` JSON includes `min_rate`, `max_rate`, `default_rate`, `price`
- [ ] Product audit log shows price change with old/new values

---

## Progress log

| Date | Phase | Change | By |
|------|-------|--------|----|
| 2026-06-18 | 0 | Document created | — |
| 2026-06-18 | 1–7 | Full product module phase implementation | — |
