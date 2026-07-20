# Phase 4 — Master Data Modules (Complete)

**Date:** Phase 4 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

### Foundation (written by orchestrator)

**10 Eloquent models** with soft-delete + `AuditableMasterData` trait:
- `Product`, `ProductCategory`, `ProductGroup`, `ProductPriceHistory`
- `Customer`, `Supplier`
- `Bank`, `BankLedgerMapping`, `Ledger`
- `Branch`, `Warehouse`, `Employee` (updated from Phase 3 with trait)
- `JournalEntry`, `JournalLine` (Accounting — referenced by Ledger)

**`AuditableMasterData` trait** (`app/Traits/`) — auto-logs create/update/delete/restore to `user_audit_log` table with old/new JSON snapshots. Dual-write with file log.

**`BaseMasterDataController`** (`app/Http/Controllers/Admin/`) — abstract base with full CRUD: index (with DataTables server-side), create, store, show, edit, update, destroy (soft-delete), restore, audit. Subclasses set $modelClass, $label, $routePrefix, $viewDir, $searchFields + override hooks for stats/relations/validation.

**Admin layout** (`resources/views/layouts/admin.blade.php`) — reproduces legacy `main.php`: Bootstrap 5 + jQuery + Select2 + DataTables + SweetAlert2 (same CDNs), sidebar with links to all 8 modules, header with branch name + user dropdown, flash messages.

### 8 Master-Data Modules (written by 4 parallel subagents)

| Module | Controller(s) | Views | Key Features |
|---|---|---|---|
| **Products** | ProductController, ProductCategoryController, ProductGroupController | 12 (products×6, categories×3, groups×3) | Image upload (secure: MIME sniff, random filename, 2MB limit), price history with effective-date ranges, DataTables with category/group/unit filters, auto-close prior price range on new entry |
| **Customers** | CustomerController | 5 (index, create, edit, show, audit) | Auto-generated customer_code (CUS-YYYY-NNNNNN), live-preview aside card, credit limit tracking, Select2 for branch + sales person |
| **Suppliers** | SupplierController | 5 (index, create, edit, show, audit) | Auto-generated supplier_code (SUP-NNNNNN), contact person field, Select2 for branch |
| **Employees** | EmployeeController | 6 (index, create, edit, show, account, audit) | Photo upload (secure), auto-generated employee_code (EMP-NNNNNN), 10-role color-coded badges, linked-user account view, by-branch stats breakdown |
| **Banks** | BankController | 5 (index, create, edit, show, audit) | GL ledger mapping (BankLedgerMapping sync on save), balance display (numeric(18,2) — Phase 2 fix), cash_bank ledger select |
| **Ledgers** | LedgerController | 5 (index, create, edit, show, audit) | Hierarchical chart of accounts (parent/children), 5 account types (Asset/Liability/Equity/Income/Expense) with color badges, 18 ledger natures, 7 critical-nature callout, journal-line summary on show page |
| **Branches** | BranchController | 5 (index, create, edit, show, audit) | Employee + warehouse count per branch, branch detail shows related employees + warehouses |
| **Warehouses** | WarehouseController | 5 (index, create, edit, show, audit) | Branch assignment, by-branch stats breakdown |

### Routes (`routes/web.php`)

All 8 modules registered with:
- `Route::resource()` for standard CRUD (index, create, store, show, edit, update, destroy)
- Custom routes for `audit`, `restore` (soft-delete restore)
- Products: `priceHistory`, `addPrice`, `deletePrice`
- Employees: `account` (read-only linked-user view)
- All wrapped in `auth` middleware
- Route names: `admin.products.index`, `admin.customers.create`, etc.

### Total Phase 4 deliverables

| Category | Count |
|---|---|
| New models | 10 (Product, ProductCategory, ProductGroup, ProductPriceHistory, Customer, Supplier, Bank, BankLedgerMapping, Ledger + JournalEntry/JournalLine) |
| Updated models | 3 (Branch, Warehouse, Employee — added AuditableMasterData trait) |
| Traits | 1 (AuditableMasterData) |
| Base controllers | 1 (BaseMasterDataController) |
| Admin controllers | 10 (Product, ProductCategory, ProductGroup, Customer, Supplier, Employee, Bank, Ledger, Branch, Warehouse) |
| Admin views | 48 Blade files across 8 directories |
| Admin layout | 1 (layouts/admin.blade.php) |
| **Total new PHP files** | **~25** |
| **Total new Blade views** | **48** |

---

## Design decisions

### 1. BaseMasterDataController pattern
All 10 module controllers extend a single abstract base, reducing duplication. The base provides:
- Full CRUD (index, create, store, show, edit, update, destroy, restore, audit)
- DataTables server-side JSON response
- Eager-loading hooks (indexWith, detailWith)
- Validation rules hook (validationRules)
- Form data hook (formData — for select dropdowns)
- Stats hook (indexStats — for hero cards)
- Audit log view

Subclasses only set properties + override hooks. ProductController additionally overrides store/update for image upload; BankController wraps store/update in DB::transaction for ledger mapping sync.

### 2. AuditableMasterData trait
Eloquent model events (created/updated/deleted/restored) auto-log to `user_audit_log` with JSON old/new snapshots. No controller-level audit code needed — it's automatic. The audit view reads from this table.

### 3. UI reproduction
All views `@extends('layouts.admin')` which reproduces the legacy `main.php`:
- Same CDN versions (Bootstrap 5.3.3, jQuery 3.6, Select2 4.1, DataTables 1.13.7, SweetAlert2 11, Font Awesome 6.5.1)
- Same `/assets/css/custom.css` link (served by Nginx from legacy/public/assets/)
- Same sidebar + header structure
- Users see no visual difference between legacy and Laravel pages

### 4. Security
- `@csrf` in all forms
- Validation rules on every store/update
- Image upload: MIME sniff (finfo), random filename (bin2hex(random_bytes(8))), 2MB limit, mimes:jpeg,png,webp,gif
- All routes behind `auth` middleware
- Soft-delete + restore pattern (no hard deletes)

### 5. Code generation
- Customer codes: `CUS-YYYY-NNNNNN` (year + sequential, using MAX+SUBSTRING on PG)
- Supplier codes: `SUP-NNNNNN`
- Employee codes: `EMP-NNNNNN`
- Product codes: manual entry (user-specified)

---

## What still needs to happen ON THE VPS

Phase 4 code is **100% written**. Deployment:

```bash
cd /var/www/rcerp_v2/laravel

# 1. Install dependencies (if not already done in Phase 3)
composer install --no-dev --optimize-autoloader

# 2. Create storage symlink (for product images + employee photos)
php artisan storage:link

# 3. Run any new migrations (Phase 3 auth tables already done)
php artisan migrate

# 4. Test each module
# Navigate to /admin/products, /admin/customers, etc.
# Verify CRUD works, images upload, audit logs appear
```

---

## Verification checklist (for VPS)

- [ ] `/admin/products` — index loads, DataTables works, create form works, image uploads, price history works
- [ ] `/admin/product-categories` — CRUD works
- [ ] `/admin/product-groups` — CRUD works
- [ ] `/admin/customers` — index loads, auto-code generation, create/edit/show works, audit page works
- [ ] `/admin/suppliers` — same as customers
- [ ] `/admin/employees` — index loads, photo upload, role badges, account view, audit
- [ ] `/admin/banks` — CRUD + GL ledger mapping sync
- [ ] `/admin/ledgers` — hierarchical display, account-type badges, journal-line summary
- [ ] `/admin/branches` — CRUD + related employees/warehouses on show page
- [ ] `/admin/warehouses` — CRUD + branch assignment
- [ ] Soft-delete + restore works on all modules
- [ ] Audit log entries appear on all audit pages
- [ ] Session bridge: login via legacy → navigate to /admin/products → authenticated

---

## Next phase

**Phase 5 — Reporting Layer.** Rebuild the 18 financial reports on Laravel + PostgreSQL materialized views. Reports are read-only, so no shadow-write needed — only shadow-read diff against legacy.
