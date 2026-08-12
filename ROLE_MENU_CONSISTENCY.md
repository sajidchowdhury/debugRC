# RC-ERP v2 — Role-Menu Consistency System

> **Date:** 2026-08-12  
> **Problem:** Superadmin could grant menu permissions incompatible with an employee's role. E.g., granting "Sales" to a warehouse_manager who would get 403 on click.  
> **Solution:** Role-menu compatibility map + UI warnings + server-side validation that blocks incompatible grants.

---

## The Problem

There are **two independent access control systems** in RC-ERP:

| System | What It Controls | Where |
|--------|-----------------|-------|
| **Menu Permissions** (`user_menu_permissions`) | Which menus appear in sidebar + URL access | MenuService + EnsureMenuPermission middleware |
| **Role Middleware** (`EnsureRole`) | Which routes can be accessed at all | Route-level middleware in web.php |

**Before this fix:** These systems were completely disconnected. Superadmin could grant a salesman the "Chart of Accounts" menu, but when the salesman clicked it, they'd get **403 Forbidden** because the route requires `role:admin,accountant`. Poor UX and confusing.

## The Solution — 3 Layers

### Layer 1: Config Map (`config/role-menu-access.php`)

A centralized config that declares which menu controllers are restricted to which roles:

```php
return [
    'customer'    => ['admin', 'manager', 'salesman'],
    'supplier'    => ['admin', 'manager', 'accountant'],
    'challan'     => ['admin', 'manager', 'warehouse_manager', 'dispatcher'],
    'ledger'      => ['admin', 'accountant', 'manager'],
    // ... etc.
];
```

If a controller is NOT listed, it's accessible by ALL roles (e.g., Dashboard).

### Layer 2: UI Warnings (Blade View)

When viewing the menu permissions page for a user:

- **Incompatible menus** are marked with a red badge: `🔒 Not for Salesman`
- The row gets a subtle red background tint
- Checking an incompatible checkbox shows a JavaScript confirmation warning
- A new "Select Role-Compatible Only" button only checks compatible menus
- Form submit is intercepted client-side to block incompatible selections

### Layer 3: Server-Side Validation (UserController)

When saving permissions, the controller validates every granted menu against the role-menu map:

```
If can_view=true AND controller is in role-menu-access AND user's role NOT in allowed list → BLOCK with error
```

The save is **rejected** with a detailed error message:
```
Cannot grant these menus due to role9 role incompatibility:
- "Chart of Accounts" is only for Admin, Accountant — not for Salesman
- "Purchase Order" is only for Admin, Manager, Accountant — not for Salesman
```

---

## Complete Role-Menu Matrix

| Menu | admin | manager | accountant | salesman | warehouse_mgr | dispatcher | hr | user | other |
|------|-------|---------|------------|----------|---------------|------------|----|------|-------|
| **Dashboard** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Product** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Product Categories** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Product Groups** | ✅ | ✅ | ❌ | ❌ | ✅ | �9 ❌ | ❌ | ❌ | ❌ |
| **Customer** | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Supplier** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Employee** | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| **User** | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Bank** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Accounts (Ledger)** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Create Sales** | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Sales Invoice** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Challan** | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Sales Return** | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Purchase Order** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Purchase Receive** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Branch Demand** | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Damage** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Stock Take** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Stock Adjustment** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Warehouse Transfer** | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Customer Payment** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Supplier Payment** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Employee Transaction** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Other Income/Expense** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Money Transfer** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Manual Journal** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Period Close** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Reconciliation** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Bank Reconciliation** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Budget** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Dimension** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Fiscal Year** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Fixed Asset** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Consolidation** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Commission Rules** | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Report** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Approval Queue** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Notification Rules** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **System Policy** | ✅* | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Global Audit** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **System Health** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

> ✅* = superadmin only

---

## Files Changed

| File | Change |
|------|--------|
| `config/role-menu-access.php` | **NEW** — Role-menu compatibility mapping |
| `app/Http/Controllers/Admin/UserController.php` | Updated `menuPermissions()` + `updateMenuPermissions()` + `buildMenuTreeWithPermissions()` to add role compatibility check |
| `resources/views/admin/users/menu-permissions.blade.php` | Updated with role badges, incompatible row highlighting, JS confirmation, "Select Compatible Only" button |

---

## How to Add New Role Restrictions

When you add a new module with role-restricted routes:

1. Add the controller → roles mapping to `config/role-menu-access.php`
2. The UI and validation will automatically pick it up — no code changes needed

Example: Adding a new "Logistics" module only for dispatchers:
```php
'logistics' => ['admin', 'manager', 'dispatcher'],
```
