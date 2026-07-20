# ERP Role Definitions

Canonical role list for **Remote Center ERP**. Roles are stored in `employees.role` (VARCHAR) and copied into the session at login.

**Source of truth (code):** `app/config/roles.php`  
**Runtime helper:** `core/RoleRegistry.php`  
**Route permissions:** `app/config/route_roles.php`  
**Sales-specific matrix:** `docs/SALES_ROLE_MATRIX.md`

---

## Access tiers

| Tier | Roles | Auth helpers |
|------|-------|--------------|
| **Superadmin** | `superadmin` | `Auth::isSuperadmin()`, `Auth::requireSuperadmin()` |
| **Admin** | `admin` (superadmin counts as admin-or-above) | `Auth::isAdmin()`, `Auth::requireAdmin()` |
| **Operational** | `manager`, `accountant`, `salesman`, `warehouse_manager`, `dispatcher`, `hr`, `user`, `other` | `Auth::hasRole(...)`, menu permissions, route matrix |

Superadmin is the only role that can grant or modify another superadmin account.

---

## Role catalog

| Role | Label | Tier | Typical use |
|------|-------|------|-------------|
| `superadmin` | Super Admin | superadmin | Full system control, company-critical settings |
| `admin` | Admin | admin | Users, employees, permissions, admin modules |
| `manager` | Manager | operational | Branch oversight, reversals, reports |
| `accountant` | Accountant | operational | Payments, GL, reconciliation |
| `salesman` | Salesman | operational | Sales invoices and day-to-day selling |
| `warehouse_manager` | Warehouse Manager | operational | Godown, challans, stock execution |
| `dispatcher` | Dispatcher | operational | Dispatch / challan operations |
| `hr` | HR | operational | Employee master (as granted by menus) |
| `user` | User | operational | Generic login; access via menu permissions |
| `other` | Other | operational | Custom profiles |

Full descriptions and `assignable_by` rules live in `app/config/roles.php`.

---

## Who can assign which role?

| Actor | Can assign |
|-------|------------|
| `superadmin` | All roles in `roles.php` |
| `admin` | All except `superadmin` |
| Everyone else | None (employee write actions are admin-gated) |

Employee forms use `RoleRegistry::assignableForActor()` for dropdown options and `Auth::canAssignRole()` / `RoleRegistry::canActorAssign()` on save.

---

## Enforcement layers

1. **Admin tier** — `requireAdmin()` on user/employee management controllers.
2. **Route matrix** — `app/config/route_roles.php` + `RouteAccess` for module actions (sales, challan, etc.).
3. **Menu permissions** — `user_menu_permissions` enforced on URLs via `MenuAccess` (sidebar + direct URL). Admin-tier users bypass menu checks.

Admin-tier users bypass the route matrix but remain subject to branch rules where implemented.

---

## Adding a new role

1. Add an entry to `app/config/roles.php` (label, tier, description, `assignable_by`).
2. Register routes in `app/config/route_roles.php` if the role needs module access.
3. Update this doc and any module-specific matrix (e.g. `SALES_ROLE_MATRIX.md`).
4. Grant menu permissions to users as needed.

No database migration is required — `employees.role` is free-form VARCHAR.
