# Authentication, Users & Permissions — Feature Inventory & Phase Plan

**Project:** Remote Center ERP
**Scope:** Login / Authentication → User Management (create, edit, delete) → Menu Permissions
**Goal:** Secure, consistent, auditable access control with no lockout risk
**Last updated:** 2026-06-18

**Status legend:** ✅ Done · ⚠️ Partial / needs work · ❌ Not done

---

## Role model (access tiers)

Roles are stored in the single `employees.role` column. Access tiers:

| Tier | Meaning |
|------|---------|
| `superadmin` | Top tier. Full control incl. company-critical actions (e.g. all-date-range reports). Only a superadmin can grant the superadmin role or modify a superadmin account. |
| `admin` | Handles normal admin work (user accounts, employees, permissions). "Admin-or-above" — `Auth::isAdmin()` returns true for superadmin too. |
| `user` + operational roles (`manager`, `accountant`, `salesman`, `warehouse_manager`, `dispatcher`, `hr`, `other`) | Regular users; scope is granted by an admin via menu permissions and the route role matrix. |

**Helpers (`core/Auth.php`):** `isSuperadmin()`, `isAdmin()` (admin-or-above), `requireAdmin()`, `requireSuperadmin()`, `canAssignRole()`, plus `Auth::ROLE_SUPERADMIN/ROLE_ADMIN/ROLE_USER` constants. `BaseController` exposes `requireAdmin()` / `requireSuperadmin()` wrappers.

> **Setup note:** Promote your first superadmin manually once, e.g.
> `UPDATE employees SET role = 'superadmin' WHERE id = <your_employee_id>;`
> Existing `admin` users keep working unchanged. The `role` column is free-form (VARCHAR), so no schema change is required.

---

## Files in scope

| Area | Files |
|------|-------|
| Login / Auth | `app/controllers/AuthController.php`, `app/views/auth/login.php`, `core/Auth.php`, `core/Session.php`, `core/RateLimiter.php`, `core/LoginAudit.php`, `core/RememberMe.php`, `core/TwoFactorAuth.php`, `core/Totp.php`, `core/PendingLogin.php` |
| User management | `app/controllers/UserController.php`, `app/models/UserModel.php`, `app/views/user/*.php` |
| Permissions | `app/views/user/permission.php`, `UserModel::saveUserPermissions()`, `user_menu_permissions` table, `app/config/route_roles.php`, `app/config/roles.php`, `core/RoleRegistry.php`, `app/services/Security/RouteAccess.php` |
| Infra | `public/index.php` (router), `core/BaseController.php`, `core/Database.php`, `app/services/Security/RouteAccess.php`, `app/services/Security/MenuAccess.php` |

---

## Feature inventory

### 1. Login / Authentication

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 1.1 | Username + password login form | ✅ | `auth/login.php` |
| 1.2 | CSRF token on login form + server validation | ✅ | `Session::start()` + `validateCSRF()` (timing-safe `hash_equals`) |
| 1.3 | Passwords hashed (`password_hash` / `password_verify`) | ✅ | `PASSWORD_DEFAULT` |
| 1.4 | Prepared statements (no SQL injection) | ✅ | PDO bind everywhere |
| 1.5 | Generic error message (no user enumeration) | ✅ | "Invalid username or password" |
| 1.6 | Session fixation prevention | ✅ | `session_regenerate_id(true)` on login + periodic |
| 1.7 | Secure cookie flags (HttpOnly, SameSite, Secure-on-HTTPS) | ✅ | `Session::start()` |
| 1.8 | `is_active = 1` enforced at login | ✅ | login query |
| 1.9 | Login attempt auditing (IP, UA, result) | ✅ | `logs/login_audit.log` |
| 1.10 | Brute-force rate limiting | ✅ | DB-backed `login_rate_limits` table keyed on IP + username (Phase 2); session bypass no longer possible |
| 1.11 | Rehash password on login when needed | ✅ | `password_needs_rehash()` + transparent DB upgrade on successful login (Phase 3) |
| 1.12 | Logout protected against CSRF | ✅ | POST-only logout + CSRF token in header form (Phase 3) |
| 1.13 | Forgot / reset password flow | ✅ | `auth/forgot`, `auth/reset/{token}`, `password_reset_tokens` table; admin link on user edit (Phase 5) |
| 1.14 | "Remember me" / persistent login | ✅ | Selector+validator cookie, `remember_tokens` table, 30-day default (Phase 6) |
| 1.15 | Track last login IP / device | ✅ | `last_login_ip`, `last_login_user_agent` on login (Phase 5) |
| 1.16 | Persisted account lockout (DB) | ✅ | `failed_login_count` + `locked_until`; admin unlock (Phase 5) |
| 1.17 | Two-factor authentication (2FA) | ✅ | TOTP via `TwoFactorAuth` + `user/two_factor`; login step `auth/verify_2fa` (Phase 6) |

### 2. User Management

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 2.1 | List users (server-side DataTables, search, filters) | ✅ | `getUsersForDataTable()` |
| 2.2 | Create user (link to employee, username, password) | ✅ | `create()` / `store()` / `createUser()` |
| 2.3 | Edit user (username, password, status) | ✅ | `edit()` / `update()` / `updateUser()` |
| 2.4 | CSRF on all user forms | ✅ | create/edit/permission |
| 2.5 | Username uniqueness check | ✅ | `usernameExists()` |
| 2.6 | One-account-per-employee check | ✅ | `employeeHasUser()` |
| 2.7 | Password strength policy | ✅ | Min 8, letter + number + special char, max 128, HIBP breach check (Phase 5) |
| 2.8 | Password confirmation match | ✅ | create + change password |
| 2.9 | Soft delete (with `deleted_by`) | ✅ | `softDeleteUser()` |
| 2.10 | Restore soft-deleted user | ✅ | `restoreUser()` + `user/restore/{id}` + deleted tab UI (Phase 5) |
| 2.11 | Toggle active/inactive | ✅ | `toggle()` |
| 2.12 | Last-active-user protection | ✅ | Enforced in `toggle()`, `softDeleteUser()`, and `updateUser()` via shared `getDeactivationBlockReason()` (Phase 2); blocks self-deactivation too |
| 2.13 | Change own password (logged-in) | ✅ | `change_password()` / `update_password()` |
| 2.14 | **Authorization gate on user management** | ✅ | All `UserController` management actions call `requireAdmin()` (admin tier); `change_password`/`update_password` stay open to every user. `EmployeeController` write actions also gated + role self-escalation blocked (Phase 1) |
| 2.15 | Accurate audit on user create | ✅ | `createUser()` returns `user_id`; audit logs correct target (Phase 3) |
| 2.16 | Audit on edit / toggle / delete / permissions | ✅ | `user_updated` logged on successful `update()`; toggle/delete/permission already covered |
| 2.17 | `created_by` integrity | ✅ | `createUser()` requires logged-in `user_id`; no fallback to user `1` (Phase 2) |
| 2.18 | User audit log viewer | ✅ | `audit()` |

### 3. Permissions / Access Control

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 3.1 | Per-user menu permissions (view/edit) | ✅ | `user_menu_permissions`, `saveUserPermissions()` |
| 3.2 | Transactional permission save w/ rollback | ✅ | `beginTransaction` + rollback |
| 3.3 | Self-lockout protection (can't remove own User Mgmt access) | ✅ | `saveUserPermissions()` |
| 3.4 | Role matrix for routes | ✅ | `route_roles.php` now also covers `UserController` + `EmployeeController` privileged actions (Phase 1) |
| 3.5 | Server-side route enforcement (global) | ✅ | Router calls `RouteAccess::require()` for all protected routes (Phase 8) |
| 3.6 | Menu permissions enforced on actual URLs | ✅ | Router calls `MenuAccess::require()`; admin-tier bypass unchanged (Phase 8) |
| 3.7 | Role definitions are centralized / documented | ✅ | `app/config/roles.php`, `RoleRegistry`, `docs/ROLE_DEFINITIONS.md` (Phase 6) |

### 4. Database / Schema

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| 4.1 | `UNIQUE` on `users.username` + `users.employee_id` | ✅ | confirmed in dump |
| 4.2 | `UNIQUE` on `user_menu_permissions(user_id, menu_id)` | ✅ | confirmed |
| 4.3 | utf8mb4 charset | ✅ | |
| 4.4 | FK `users.employee_id → employees.id` | ✅ | Migration `029_auth_schema_hardening.sql` |
| 4.5 | FK `employees.branch_id → branches.id` | ✅ | Migration `029_auth_schema_hardening.sql` |
| 4.6 | FK `user_menu_permissions.user_id → users.id` | ✅ | ON DELETE CASCADE; orphan rows cleaned first |
| 4.7 | Consistent NULL handling for `deleted_at` | ✅ | Migration + PHP queries use `deleted_at IS NULL` only |
| 4.8 | Secrets / live data not committed | ✅ | `.gitignore` + `local.php.example` + schema reference; untrack live dump manually if still in git |

---

## Phase-by-phase plan

Ordered by impact and risk. Each phase is small and shippable.

### Phase 1 — Lock down authorization (Critical) — ✅ DONE
- [x] Establish role tiers (superadmin / admin / user) + helpers in `Auth` and `BaseController`.
- [x] 2.14 Add admin gate to `UserController` (per-action `requireAdmin()`; password self-service untouched).
- [x] Lock down `EmployeeController` write actions + block role self-escalation and superadmin tampering (closes the escalation path that would defeat the user gate).
- [x] 3.4 Register `UserController` + `EmployeeController` privileged actions in `route_roles.php`.
- [x] 3.5 / 3.6 Enforcement model decided for this phase: **per-controller `requireAdmin()`** for admin-tier areas (handles HTML redirect). Global router-level / menu-permission URL enforcement deferred to a later phase.

### Phase 2 — Close lockout & data-integrity gaps (High) — ✅ DONE
- [x] 2.12 Add last-active-user + self-deactivation guard to `updateUser()` (shared helper also used by `toggle()` / `softDeleteUser()`).
- [x] 1.10 Replace session login rate limiter with DB table `login_rate_limits` keyed on IP + username (`database/migrations/028_login_rate_limits.sql`).
- [x] 2.17 Remove `created_by` fallback to `1` in `createUser()`.

**Apply migration once:** `php database/run_migrations.php`

### Phase 3 — Audit & correctness (Medium) — ✅ DONE
- [x] 2.15 Return new user id from `createUser()`; log correct target in audit.
- [x] 2.16 Add audit entry to `update()` (`user_updated` with username, status, password-changed flag).
- [x] 1.11 Add `password_needs_rehash()` upgrade on successful login.
- [x] 1.12 Make logout POST + CSRF (header dropdown form; GET `/auth/logout` no longer signs out).

### Phase 4 — Schema hardening (Medium) — ✅ DONE
- [x] 4.4 / 4.5 / 4.6 Foreign keys: `employees.branch_id → branches`, `users.employee_id → employees`, `user_menu_permissions → users/menus` (`029_auth_schema_hardening.sql`; orphan cleanup runs first).
- [x] 4.7 `deleted_at` normalized to NULL-only in DB migration + PHP queries (`UserModel`, `EmployeeModel`, `Helper`).
- [x] 4.8 `.gitignore` updated (`config/local.php`, SQL dump exceptions for migrations/schema); `config/local.php.example` + `database/schema/auth_core_tables.sql` added; `config.php` loads local overrides before DB defaults.

**Apply migration once:** `php database/run_migrations.php`

**If the live dump is still tracked in git:** `git rm --cached osudlagb_remotecenter.sql` (file stays on disk; rotate any secrets that were committed, e.g. FCM keys in `config/local.php`).

### Phase 5 — Feature completeness (Lower) — ✅ DONE
- [x] 2.10 Add restore-user route + UI (`user/restore/{id}`, deleted-users tab on index).
- [x] 1.13 Forgot/reset password flow (`auth/forgot`, `auth/reset/{token}`, admin generate-reset-link on edit).
- [x] 1.15 Track last login IP/device (`users.last_login_ip`, `last_login_user_agent`; updated on login).
- [x] 1.16 Persisted lockout (`failed_login_count`, `locked_until`; admin unlock on edit).
- [x] 2.7 Strengthen password policy (special char, 128-char cap, HIBP breach check via `PasswordPolicy`).

**Apply migration once:** `php database/run_migrations.php` (applies `030_auth_phase5_features.sql`).

### Phase 6 — Optional / future — ✅ DONE
- [x] 1.14 "Remember me" — secure cookie + `remember_tokens` table; restores session (2FA users go to verify step).
- [x] 1.17 Two-factor authentication — TOTP setup at `user/two_factor`, verify at `auth/verify_2fa`.
- [x] 3.7 Centralize & document role definitions — `app/config/roles.php`, `RoleRegistry`, `docs/ROLE_DEFINITIONS.md`.

**Apply migration once:** `php database/run_migrations.php` (applies `031_auth_phase6_features.sql`).

### Phase 7 — Investigation access window — ✅ DONE
- [x] QR scan by pre-declared activators → activate global investigation mode.
- [x] Admin/superadmin logins restricted (effective admin, branch-scoped reports, read-only) while active.
- [x] Deactivate via company-email OTP after second QR scan.
- [x] Superadmin setup UI at `investigation/settings`; guide in `docs/INVESTIGATION_MODE.md`.

**Apply migration once:** `php database/run_migrations.php` (applies `032_investigation_mode.sql`).

**Configure in `config/local.php`:** `INVESTIGATION_QR_SECRET`, `INVESTIGATION_COMPANY_EMAIL`.

### Phase 8 — Workforce UX & session hardening — ✅ DONE
- [x] Global `RouteAccess::require()` + `MenuAccess::require()` on all protected routes.
- [x] Dedicated `users.credential_version` column (`038_users_credential_version.sql`); bumps on password/username/2FA/employee-role changes; `updated_at` no longer overloaded for session invalidation.
- [x] Unified security audit viewer at `user/security_audit` — login + user + employee timeline with source/outcome filters.
- [x] Employee validation (B1), unique mobile/email (B2), audit performer names (D2), session sync on employee update (C2).
- [x] Unified employee + account hub `employee/account/{id}` (C3); admin 2FA recovery (C4); self-hosted 2FA QR (D5); DB-backed `user_audit_log` dual-write (D4).
- [x] Modern hub UX for account page, user create form, permission/edit hub links; post-create redirect to account hub.
- [x] Self-hosted investigation setup QR (no external API).

**Apply migrations:** `036_user_audit_log.sql`, `037_backfill_users_updated_at.sql`, `038_users_credential_version.sql`

---

## Progress log

| Date | Phase | Change | By |
|------|-------|--------|----|
| 2026-06-18 | — | Document created (inventory + plan) | — |
| 2026-06-18 | 1 | Role tiers (superadmin/admin/user) + `Auth`/`BaseController` helpers; admin gate on all `UserController` mgmt actions; `EmployeeController` write gate + role-escalation/superadmin protection; routes registered in `route_roles.php`. Lint clean. | — |
| 2026-06-18 | 2 | `updateUser()` lockout guards (last active user + self-deactivation); DB login rate limiter (`028_login_rate_limits.sql`); `created_by` requires session user. | — |
| 2026-06-18 | 3 | Audit fixes (create returns id, update logged); password rehash on login; POST+CSRF logout. | — |
| 2026-06-18 | 4 | Schema hardening migration (FKs, deleted_at cleanup); gitignore/local.example/schema reference; PHP deleted_at queries simplified. | — |
| 2026-06-18 | 5 | Restore user UI/route; forgot+reset password; last login IP/UA; persisted lockout + admin unlock; PasswordPolicy (special char, max length, HIBP). Migration `030`. | — |
| 2026-06-18 | 6 | Remember-me tokens; TOTP 2FA (setup + login verify); centralized roles (`roles.php`, `RoleRegistry`, `ROLE_DEFINITIONS.md`). Migration `031`. | — |
| 2026-06-18 | 7 | Investigation mode: QR activate, admin/superadmin session restriction, email OTP deactivate, activator setup. Migration `032`. | — |
| 2026-06-19 | 8 | Dedicated `users.credential_version` column + `CredentialVersion` helper; session bumps decoupled from `updated_at`. Migration `038`. | — |
| 2026-06-19 | 8 | Menu/route enforcement global; credential-version sessions; employee hub C3; admin 2FA recovery C4; DB audit D4; self-hosted QR D5; UX polish (create user hub, account hub); login NULL updated_at fix. Migrations `036`, `037`. | — |
