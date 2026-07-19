# Administration Module — Complete Migration Audit

> **Status:** READ-ONLY AUDIT COMPLETE — awaiting approval before implementation
> **Scope:** Branch, Warehouse, Product, Customer, Supplier, Employee, User, Bank, Accounts (Ledger)
> **Method:** Direct source code comparison (legacy PHP/MySQL vs Laravel 11/PostgreSQL)
> **Date:** 2025-01-10
> **Auditors:** 4 parallel audit agents (Z.ai Code)

---

## Executive Summary

All 9 Administration modules were audited by comparing legacy PHP source code, MySQL schema, and business rules against the Laravel 11 implementation and PostgreSQL schema. **None of the 9 modules are production-ready.** The average feature coverage is ~43%, with critical security gaps (no RBAC on master-data routes) and data integrity risks (missing deactivation safety checks).

### Overall Scores

| Module | Feature Coverage | Database | Laravel | Routes | UI | CRUD | Business Rules | Security | Production Ready |
|--------|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Branch | 55% | 85% | 65% | 80% | 50% | 70% | 35% | 15% | ❌ NO |
| Warehouse | 45% | 75% | 50% | 70% | 35% | 60% | 20% | 10% | ❌ NO |
| Product | 65% | 70% | 70% | 80% | 55% | 75% | 40% | 20% | ❌ NO |
| Customer | 45% | 60% | 45% | 70% | 35% | 60% | 25% | 20% | ❌ NO |
| Supplier | 58% | 70% | 55% | 75% | 45% | 65% | 30% | 15% | ❌ NO |
| Employee | 43% | 55% | 40% | 70% | 35% | 55% | 20% | 10% | ❌ NO |
| User | 35% | 50% | 30% | 40% | 20% | 30% | 25% | 15% | ❌ NO |
| Bank | 30% | 65% | 35% | 60% | 25% | 50% | 15% | 10% | ❌ NO |
| Accounts | 20% | 40% | 25% | 50% | 20% | 40% | 10% | 5% | ❌ NO |

### Top 10 Critical Issues (Cross-Module)

| # | Issue | Affected Modules | Risk |
|---|-------|-----------------|------|
| 1 | **No RBAC middleware** on any master-data route (only `auth`) — any authenticated user can CRUD all entities | ALL 9 | Critical |
| 2 | **No deactivation safety checks** — can soft-delete customers/suppliers/banks/employees with outstanding balances or references | Customer, Supplier, Bank, Employee | Critical |
| 3 | **No UserController exists** — entire user admin module missing (CRUD, permissions, security audit, unlock, reset links) | User | Critical |
| 4 | **Employee credential bump missing** — editing role/branch doesn't invalidate sessions; demoted users keep old access | Employee | Critical |
| 5 | **`shop_name` column dropped from customers** — 60+ legacy code references break | Customer | High |
| 6 | **`ledgers` table missing critical columns** (`is_system`, `normal_balance`, `description`, `created_by`) — all business-rule queries fail | Accounts | Critical |
| 7 | **Critical-nature list mismatch** — legacy uses `customer_receivable/supplier_payable`; Laravel uses `ar/ap` — breaks JournalPostingService | Accounts | Critical |
| 8 | **System ledger protection missing** — can edit/delete Cash/AR/AP/Inventory/Sales/COGS ledgers | Accounts | Critical |
| 9 | **No BranchScope on master-data models** — non-admin users see ALL branches' data | Branch, Warehouse, Customer, Supplier, Employee | High |
| 10 | **`user_audit_log` column naming mismatch** — legacy writes fail on PG (`performed_by` vs `user_id`, `target_id` vs `target_user_id`, etc.) | ALL | High |

### Cross-Cutting Patterns

1. **RBAC regression:** Sales routes have `role:` middleware; Administration routes have only `auth`. Every module needs role middleware added.
2. **Toggle folded into destroy:** Legacy had separate `toggle` (activate/deactivate) with safety checks; Laravel uses `destroy()` (soft delete) with no checks.
3. **Audit labels generic:** Legacy used specific action names (`product_created`, `customer_updated`); Laravel uses generic `master_data_created`, `master_data_updated`.
4. **`created_by` column dropped** on most PG tables — legacy INSERT statements fail.
5. **Export buttons dropped** from all DataTables views.
6. **Hub/detail pages downgraded:** Legacy had multi-tab hubs (ledger + transactions + stats); Laravel has flat detail pages.
7. **Mobile/email uniqueness dropped** on Customer, Supplier, Employee.
8. **Opening balance not posted:** Customer/Supplier/Employee capture `opening_balance` but never write the sub-ledger entry.

---

## Implementation Priority Order

| Priority | Module | Estimated Effort | Key Risk |
|----------|--------|:-:|------|
| 1 | **Branch** | 30h | Foundation for all branch isolation |
| 2 | **Warehouse** | 40h | Foundation for stock management |
| 3 | **Accounts (Ledger)** | 35h | Foundation for all GL posting |
| 4 | **Bank** | 20h | Foundation for payments |
| 5 | **Product** | 50h | Foundation for sales + purchase |
| 6 | **Customer** | 65h | `shop_name` column critical for sales |
| 7 | **Supplier** | 45h | AP sub-ledger + safety checks |
| 8 | **Employee** | 55h | Credential bump security risk |
| 9 | **User** | 60h | Entire module missing |
| **Total** | | **~400h** | |

---

## Module-by-Module Audit

> Each module's detailed 10-section audit was performed by a dedicated agent. The full findings are in `/home/z/my-project/worklog.md` under the following task IDs:
> - `ADMIN-AUDIT-BRANCH-WH` — Branch + Warehouse
> - `ADMIN-AUDIT-PROD-CUST` — Product + Customer
> - `ADMIN-AUDIT-SUPP-EMP` — Supplier + Employee
> - `ADMIN-AUDIT-USER-BANK-ACCT` — User + Bank + Accounts

### Progress Checklist

#### Branch Module
- [ ] **Phase 1: Database fixes** — add `created_by` column; verify `address`/`location` rename in ETL
- [ ] **Phase 2: RBAC** — add `role:admin` middleware to all branch routes
- [ ] **Phase 3: Toggle action** — restore separate toggle with `canDeactivateBranch()` safety check
- [ ] **Phase 4: Audit logging** — fix audit query to show `target_id` + `performed_by_name`
- [ ] **Phase 5: Business rules** — `canDeactivateBranch()` dependency checks
- [ ] **Phase 6: Export + Print** — restore export button + print view
- [ ] **Phase 7: Testing** — full CRUD + toggle + audit + RBAC
- [ ] **Phase 8: Production ready** — sign-off

#### Warehouse Module
- [ ] **Phase 1: Database fixes** — add `created_by`; verify `address`→`location` rename
- [ ] **Phase 2: RBAC** — add `role:admin` middleware
- [ ] **Phase 3: Toggle action** — restore toggle with `canDeactivateWarehouse()` + `canChangeBranch()`
- [ ] **Phase 4: BranchScope** — apply `BranchScope` to Warehouse model
- [ ] **Phase 5: Stock search AJAX** — restore `stock_search` endpoint + JS
- [ ] **Phase 6: Audit logging** — fix audit query
- [ ] **Phase 7: Export + Print** — restore
- [ ] **Phase 8: Testing**
- [ ] **Phase 9: Production ready**

#### Product Module
- [ ] **Phase 1: Database fixes** — verify `pcs_per_carton`, `safety_stock` columns; `image`→`product_image` rename
- [ ] **Phase 2: RBAC** — add role middleware to product/category/group routes
- [ ] **Phase 3: Auto-gen product_code** — restore `P-NNNN` generation
- [ ] **Phase 4: Delete safety** — `canDelete()` referential integrity check
- [ ] **Phase 5: Default group protection** — prevent deleting 'China' group
- [ ] **Phase 6: Bulk actions** — restore bulk activate/deactivate/delete
- [ ] **Phase 7: Audit views** — create category + group audit blade views
- [ ] **Phase 8: Export + Print** — restore
- [ ] **Phase 9: Testing**
- [ ] **Phase 10: Production ready**

#### Customer Module
- [ ] **Phase 1: Database fixes** — **add `shop_name` column back** (60+ references); add `created_by`
- [ ] **Phase 2: RBAC** — add role middleware
- [ ] **Phase 3: Toggle action** — restore with `canDeactivateCustomer()` (outstanding AR check)
- [ ] **Phase 4: Mobile uniqueness** — add unique constraint + validation
- [ ] **Phase 5: Customer code** — restore `C-NNNN` format
- [ ] **Phase 6: Customer hub** — restore 4-tab hub (summary/ledger/invoices/payments)
- [ ] **Phase 7: Opening balance posting** — write customer_ledger entry on create
- [ ] **Phase 8: Export + Print**
- [ ] **Phase 9: Testing**
- [ ] **Phase 10: Production ready**

#### Supplier Module
- [ ] **Phase 1: Database fixes** — add `created_by`; verify `running_balance`→`balance`, `remarks`→`description` renames
- [ ] **Phase 2: RBAC** — add role middleware
- [ ] **Phase 3: Toggle action** — restore with `getDeactivationSafetyStatus()` (outstanding AP check)
- [ ] **Phase 4: Mobile uniqueness** — add unique constraint + validation
- [ ] **Phase 5: Supplier hub** — restore 3-tab hub (ledger/receives/payments)
- [ ] **Phase 6: AP stats** — restore `getSupplierIndexStats` (4 stats)
- [ ] **Phase 7: Opening balance posting** — write supplier_ledger entry
- [ ] **Phase 8: Export + Print**
- [ ] **Phase 9: Testing**
- [ ] **Phase 10: Production ready**

#### Employee Module
- [ ] **Phase 1: Database fixes** — restore 9 HR columns (`father_name`, `mother_name`, `date_of_birth`, `nid`, `designation`, `department`, `bank_account`, `blood_group`); add `created_by`; fix `role` CHECK (add 'user' value)
- [ ] **Phase 2: RBAC** — add role middleware + superadmin protection + role escalation guard
- [ ] **Phase 3: Credential bump** — restore `touchLinkedUserCredential()` on role/branch change
- [ ] **Phase 4: Toggle action** — restore with `hasActiveUserAccount()` safety check
- [ ] **Phase 5: Photo cleanup** — delete photo file on soft-delete
- [ ] **Phase 6: Session sync** — restore `syncSessionAfterEmployeeUpdate()`
- [ ] **Phase 7: Mobile/email uniqueness**
- [ ] **Phase 8: Account hub** — restore with salary/advance/repayment tabs
- [ ] **Phase 9: Export + Print**
- [ ] **Phase 10: Testing**
- [ ] **Phase 11: Production ready**

#### User Module
- [ ] **Phase 1: Database fixes** — fix `user_audit_log` column naming; fix `employees.role` CHECK; verify `menus` schema (menu_label vs menu_name)
- [ ] **Phase 2: Build UserController** — create controller with index/create/store/edit/update/toggle/delete/restore
- [ ] **Phase 3: Permission management** — wire MenuService to user edit page (can_view/can_edit per menu)
- [ ] **Phase 4: Security audit** — restore security_audit view + action
- [ ] **Phase 5: Unlock + reset link** — restore unlock + generate_reset_link actions
- [ ] **Phase 6: Change password** — restore change_password action
- [ ] **Phase 7: Telegram ID** — restore update_telegram action
- [ ] **Phase 8: Audit logging** — write user-specific audit events (user_created, user_updated, etc.)
- [ ] **Phase 9: RBAC** — add role middleware (admin/superadmin only)
- [ ] **Phase 10: Testing**
- [ ] **Phase 11: Production ready**

#### Bank Module
- [ ] **Phase 1: Database fixes** — **add `deleted_at` column** (SoftDeletes trait crashes without it); add `created_by`
- [ ] **Phase 2: RBAC** — add role middleware
- [ ] **Phase 3: Account number uniqueness** — add unique constraint + validation
- [ ] **Phase 4: Toggle action** — restore with deactivation safety (non-zero balance check)
- [ ] **Phase 5: Audit logging** — write bank-specific audit events
- [ ] **Phase 6: Bank hub** — restore show view with recent transactions + usage stats
- [ ] **Phase 7: Export + Print**
- [ ] **Phase 8: Testing**
- [ ] **Phase 9: Production ready**

#### Accounts (Ledger) Module
- [ ] **Phase 1: Database fixes** — **add `is_system`, `normal_balance`, `description`, `created_by` columns** to `ledgers` table
- [ ] **Phase 2: Critical-nature alignment** — reconcile legacy (`customer_receivable/supplier_payable`) vs Laravel (`ar/ap`) naming
- [ ] **Phase 3: RBAC** — add role middleware (admin/superadmin/accountant)
- [ ] **Phase 4: System ledger protection** — prevent edit/delete of system ledgers (Cash, AR, AP, Inventory, Sales, COGS)
- [ ] **Phase 5: Critical-nature uniqueness** — enforce 1 active ledger per critical nature
- [ ] **Phase 6: Nature validation** — validate account_type ↔ ledger_nature ↔ normal_balance consistency
- [ ] **Phase 7: Toggle block reasons** — restore 4 block-reason checks
- [ ] **Phase 8: Auto-gen ledger_code** — restore `L-NNNN` generation
- [ ] **Phase 9: Audit logging**
- [ ] **Phase 10: Export + Print**
- [ ] **Phase 11: Testing**
- [ ] **Phase 12: Production ready**

---

## Evidence Index

All detailed audit findings with exact file paths, class names, method names, and line numbers are in:

| File | Task ID | Modules Covered |
|------|---------|----------------|
| `/home/z/my-project/worklog.md` | `ADMIN-AUDIT-BRANCH-WH` | Branch + Warehouse |
| `/home/z/my-project/worklog.md` | `ADMIN-AUDIT-PROD-CUST` | Product + Customer |
| `/home/z/my-project/worklog.md` | `ADMIN-AUDIT-SUPP-EMP` | Supplier + Employee |
| `/home/z/my-project/worklog.md` | `ADMIN-AUDIT-USER-BANK-ACCT` | User + Bank + Accounts |

Each section contains:
1. Legacy Feature Audit (every action, method, line number)
2. Database Audit (column-by-column comparison)
3. Laravel Feature Audit (Feature | Legacy | Laravel | Status)
4. Route & UI Verification
5. CRUD Verification
6. PostgreSQL Compatibility
7. Business Rules
8. Missing Features (description, impact, estimated work)
9. Phase-by-Phase Implementation Plan (files, effort, risk, dependencies)
10. Final Score

---

## Next Steps

1. **Review this audit document** and approve the implementation priority order
2. **Begin implementation** module-by-module in the approved order (recommended: Branch → Warehouse → Accounts → Bank → Product → Customer → Supplier → Employee → User)
3. **After each module is complete**, update the progress checklist above
4. **Run the verification commands** after each module:
   - `php artisan migrate`
   - `php artisan route:cache`
   - Manual CRUD test of every action

---

*This audit is READ-ONLY. No code was modified. Awaiting approval before implementation begins.*
