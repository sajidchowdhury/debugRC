# Sales Ecosystem — JSON API Access & Security

All routes below require an **active PHP session** (user logged in via `Auth::requireLogin()`), except where noted.

Rate limits are **per user, per endpoint bucket**, session-based (see `core/RateLimiter.php`). Defaults shown; adjust in controller `guardJsonApi()` calls.

## CSRF

| Method | Requirement |
|--------|-------------|
| POST (form) | Hidden field `csrf_token` |
| POST (JSON) | Body `csrf_token` and/or header `X-CSRF-Token` |
| GET JSON | No CSRF (read-only); login + rate limit still apply |

Global token: `window.CSRF_TOKEN` in layout (from `$_SESSION['csrf_token']`).

## Roles (`users.role`)

| Role | Sales create/edit | Today list scope | Branch override |
|------|-------------------|------------------|-----------------|
| `admin` | Yes | All branch invoices | Yes |
| `manager` | Yes | All branch invoices | No |
| Other (`user`, `salesman`, etc.) | Yes | Own invoices only | No |

Warehouse push notifications target employees with designation **`warehouse_manager`** (not `users.role`).

Branch data is scoped by `$_SESSION['branch_id']` unless admin overrides branch on invoice.

---

## `/sales/*` JSON endpoints

| Endpoint | Limit (per min) | Roles | Notes |
|----------|-----------------|-------|-------|
| `search_customer` | 90 | Any logged-in | Autocomplete |
| `search_product` | 90 | Any logged-in | Stock-aware search |
| `get_branch` | 60 | Any logged-in | `?all=1` only if admin |
| `product_stock_at_branch` | 120 | Any logged-in | |
| `get_warehouse_stock` | 120 | Any logged-in | |
| `get_employees` | 60 | Any logged-in | |
| `customer_details` | 120 | Any logged-in | Credit / due |
| `list_draft_carts` | 60 | Any logged-in | Session carts |
| `today_filter_summary` | 120 | Any logged-in | |
| `datatable_invoices` | 180 | Any logged-in | DataTables |
| `get_invoice_for_edit` | 120 | Any logged-in | Draft only |
| `save_fcm_token` | 30 | Any logged-in | **CSRF required** |
| `add_to_cart`, `load_cart`, … | — | Any logged-in | **CSRF required** |
| `final_sales`, `update`, payments | — | Any logged-in | **CSRF required** |

Print/HTML routes (`edit`, `invoice_copy`, `print_receipt`) use flash + redirect on error, not raw `die()`.

---

## Related modules

Challan and Sales Return controllers use the same login gate; add `guardJsonApi()` there in a later phase if needed.

---

## Production

Set environment before deployment:

```
APP_ENV=production
```

This disables `display_errors` and hides exception details from JSON responses (`APP_DEBUG` false). Errors are written to the PHP error log.

See `docs/ENV.example` and `config/local.php.example` for FCM keys.