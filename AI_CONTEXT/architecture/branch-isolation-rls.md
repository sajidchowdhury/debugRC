# Branch Isolation & Row-Level Security (RLS)

> **Module:** Architecture (cross-cutting)
> **Audience:** Engineers, AI assistants, DBAs
> **Status:** Canonical
> **Last reviewed:** Phase 1 (initial)
> **Source of truth:** This file + `laravel/app/Http/Middleware/SetAppBranchId.php`, `EnforceBranchIsolation.php`, `SetApiBranchContext.php` + `laravel/app/Models/Scopes/BranchScope.php` + `laravel/database/sql/07_views_triggers_constraints.sql`

---

## 1. What is it?

**Branch isolation** is the mechanism that ensures a user (or API consumer) in Branch A
can only read and write Branch A's data. RC_ERP is **multi-branch**: every transactional
row carries a `branch_id`, and the system enforces that users only see/touch their own
branch's rows.

Isolation is implemented as **three defense-in-depth layers**, so that a failure in any
one layer does not leak data:

1. **Query layer** — `BranchScope` Eloquent global scope (filters reads).
2. **Route layer** — `EnforceBranchIsolation` middleware (validates writes).
3. **Database layer** — PostgreSQL **Row-Level Security (RLS)** policies (cannot be
   bypassed even by raw SQL).

The whole mechanism is driven by a per-request PostgreSQL GUC called `app.branch_id`,
set by middleware from the user's session.

---

## 2. Why does it exist?

- **Multi-tenant safety.** Branches are separate operating units (different physical
  locations, different stock, different customers). A salesman in Branch X must never see
  Branch Y's invoices, customers, or stock — neither accidentally (a forgotten `WHERE`)
  nor maliciously (forging a `branch_id` in a POST body).
- **Defense-in-depth.** The legacy system relied solely on app-level helpers, which were
  easy to forget. The new system pushes isolation to the **database**, so even a bug in
  app code or a raw SQL query cannot leak cross-branch data.
- **Auditability.** Admin/superadmin CAN operate cross-branch, but every such override is
  logged to `user_audit_log` for forensic review.

---

## 3. The GUC: `app.branch_id`

The core of the mechanism is a PostgreSQL **custom GUC** (Grand Unified Configuration
variable) named `app.branch_id`, set **per request** on the DB connection.

| GUC | Set by | Purpose |
|---|---|---|
| `app.branch_id` | `SetAppBranchId` (web) / `SetApiBranchContext` (API) | The user's current branch. RLS policies compare `branch_id = current_setting('app.branch_id')::int`. |
| `app.is_admin` | `SetAppBranchId` / `SetApiBranchContext` | `true` for admin/superadmin → RLS bypass. |
| `app.request_path` | `SetAppBranchId` | Request path, read by `fn_financial_audit_trigger()`. |
| `app.request_ip` | `SetAppBranchId` | Request IP, read by the audit trigger. |
| `app.request_id` | `SetAppBranchId` | Request ID (header `X-Request-ID` or generated), read by the audit trigger. |

### 3.1 How the GUC is set (web)

`SetAppBranchId` middleware (appended globally in `bootstrap/app.php`):

```php
// Runs AFTER SyncLegacySession (which populates session('branch_id')).
$branchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
$isAdmin = $user->isAdmin() ? 'true' : 'false';
DB::unprepared("SET app.branch_id = {$safeBranchId}");
DB::unprepared("SET app.is_admin = {$safeIsAdmin}");
DB::unprepared("SET app.request_path = '{$safePath}'");
DB::unprepared("SET app.request_ip = '{$safeIp}'");
DB::unprepared("SET app.request_id = '{$safeRid}'");
```

Key facts (from the middleware docblock):

- `SET` (not `SET LOCAL`) scopes the GUC to the **session (connection)** — it persists for
  all queries in this request and resets when the connection is recycled back to the pool.
  No explicit `RESET` is needed.
- **PostgreSQL `SET` does NOT accept PDO bound parameters** (`?` / `$1`). `PDO::prepare()`
  would fail. The values are safely cast (`(int)`, known `'true'|'false'` string), and
  strings are `addcslashes`-escaped, so inlining via `DB::unprepared()` is
  SQL-injection-safe.
- If the GUC doesn't exist yet (migrations not run), the error is caught and logged at
  debug level — this lets code deploy before migrations.

### 3.2 How the GUC is set (API)

`SetApiBranchContext` route middleware runs **after** `api.auth` (so `Auth::user()` is
available). The global `SetAppBranchId` runs before route middleware and **skips API
requests** because `Auth::check()` is false at that point (Sanctum token auth hasn't run
yet). So for `/api/v1/*`, `set.api.branch` is the middleware that sets the GUC.

### 3.3 Console commands

`SetAppBranchId` does **NOT** run for artisan commands. CLI code must set the GUC manually:

```php
DB::unprepared("SET app.branch_id = " . (int)$branchId);
DB::unprepared("SET app.is_admin = true"); // or false
```

…before running branch-scoped queries, or run unscoped (admin mode). Never use `?`
placeholders for GUCs.

---

## 4. Layer 1 — Query layer (`BranchScope`)

`app/Models/Scopes/BranchScope.php` is an Eloquent global scope that auto-filters reads.

```php
class BranchScope implements Scope {
    public function apply(Builder $builder, Model $model): void {
        if (!Auth::check()) return;             // no-op for console/unauthenticated
        if (Auth::user()->isAdmin()) return;     // admin sees all branches
        $branchId = (int)(session('branch_id') ?? Auth::user()->getBranchId() ?? 0);
        if ($branchId > 0) {
            $builder->where($model->getTable() . '.branch_id', '=', $branchId);
        }
    }
}
```

**Applied to** (via `static::addGlobalScope(new BranchScope)` in the model's `booted()`):
`SalesInvoice`, `SalesChallan`, `SalesReturn`, `CustomerPayment`, and others. Variants:
`MoneyTransferBranchScope`, `WarehouseTransferBranchScope` (cross-branch variants that
filter by `from_branch_id` OR `to_branch_id`).

**Bypassing (admin-only contexts):**

```php
SalesInvoice::withoutGlobalScope(BranchScope::class)->get();
```

> This should be rare and audited. RLS is the backstop.

---

## 5. Layer 2 — Route layer (`EnforceBranchIsolation`)

`app/Http/Middleware/EnforceBranchIsolation.php` (alias `branch.isolation`) validates that
the `branch_id` in an incoming **write** request matches the user's session branch.

### 5.1 Enforcement rules

| User type | Rule |
|---|---|
| Non-admin | `request.branch_id` MUST equal `session.branch_id`. Mismatch → 403 (JSON for AJAX, redirect for web). |
| Admin/superadmin | Bypass allowed, BUT cross-branch overrides are logged to `user_audit_log` with action `branch_override`. |

### 5.2 Where it looks for `branch_id`

1. `request->input('branch_id')` — form fields / JSON body.
2. `request->route('invoiceId')` — URL param; resolves the invoice's `branch_id` from DB.
3. `request->route('id')` — resource ID; resolves via `inferTableFromUri()` (see below).
4. `request->route('session')` — stock-take session ID (custom verb).

`inferTableFromUri()` maps the request path to the table whose `branch_id` to load. It
covers: `sales_invoices`, `sales_challans`, `sales_returns`, `customer_payments`,
`supplier_payments`, `employee_transactions`, `manual_journals`, `purchase_orders`,
`purchase_receives`, `purchase_returns`, `stock_take_sessions`, `stock_adjustments`,
`damage_invoices`, `other_incomes`, `other_expenses`.

### 5.3 Cross-branch modules (deliberately skipped)

These modules are cross-branch by nature (`from_branch_id` + `to_branch_id`), so single-
branch inference does not apply. The middleware returns `null` for them, and the
controller authorizes based on the user's role in the transaction:

- **Branch Demands** (`/admin/branch-demands`) — requester branch vs supplier branch.
- **Money Transfers** (`/admin/money-transfers`) — from-branch vs to-branch.

---

## 6. Layer 3 — Database layer (PostgreSQL RLS)

This is the **non-bypassable** backstop. Even raw SQL or a forgotten scope cannot leak
cross-branch data, because PostgreSQL itself filters the rows.

### 6.1 How RLS policies are structured

For each protected table, `database/sql/07_views_triggers_constraints.sql` defines five
policies:

```sql
ALTER TABLE <table> ENABLE ROW LEVEL SECURITY;

CREATE POLICY rls_<table>_select ON <table> FOR SELECT
    USING (current_setting('app.is_admin', true) = 'true'
           OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_<table>_insert ON <table> FOR INSERT
    WITH CHECK (current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int);
CREATE POLICY rls_<table>_update ON <table> FOR UPDATE
    USING (...) WITH CHECK (...);
CREATE POLICY rls_<table>_delete ON <table> FOR DELETE
    USING (...);
CREATE POLICY rls_<table>_admin ON <table> FOR ALL
    USING (current_setting('app.is_admin', true) = 'true')
    WITH CHECK (current_setting('app.is_admin', true) = 'true');
```

- `current_setting('app.is_admin', true)` — the `true` arg means "return NULL if unset"
  (avoids errors before migrations run); the comparison `= 'true'` is FALSE for NULL, so
  unset → non-admin → enforced.
- The `*_admin` policy gives admin/superadmin full access (the bypass).

### 6.2 Protected tables (sample)

RLS is enabled on at least: `employees`, `customers`, `suppliers`, `warehouses`,
`journal_entries`, `customer_ledger`, `supplier_ledger`, `employee_ledger`,
`document_sequences` (global, `branch_id = 0`), and all transactional tables
(`sales_invoices`, `purchase_orders`, `stock_transactions`, `money_transfers`, etc.).

### 6.3 Special case: `document_sequences`

`document_sequences` uses `branch_id = 0` (global rows) — its policies allow access only
to rows where `branch_id = 0`, plus the admin bypass. This is because document sequences
are global-per-type, not per-branch.

### 6.4 `FORCE ROW LEVEL SECURITY`

The DDL uses `ENABLE ROW LEVEL SECURITY` (not `FORCE`). This means the **table owner**
bypasses RLS. In production, the app DB role is NOT the table owner, so RLS applies. Do
not run the app as the PostgreSQL superuser or table owner in production.

---

## 7. Admin bypass & audit

When `app.is_admin = true` (admin/superadmin), all RLS policies allow access. The
`EnforceBranchIsolation` middleware additionally logs cross-branch overrides:

```php
DB::table('user_audit_log')->insert([
    'user_id' => $user->id,
    'action'  => 'branch_override',
    'branch_id' => $targetBranchId,
    'details' => json_encode([
        'session_branch_id' => $sessionBranchId,
        'target_branch_id'  => $targetBranchId,
        'method' => $request->method(),
        'path'   => $request->path(),
        'ip'     => $request->ip(),
    ]),
    ...
]);
```

This gives a forensic trail of every time an admin touched another branch's data.

---

## 8. End-to-end flow

```mermaid
sequenceDiagram
    participant U as User (Branch 3)
    participant MW as Middleware
    participant C as Controller
    participant S as Service
    participant M as Model (BranchScope)
    participant DB as PostgreSQL (RLS)

    U->>MW: POST /admin/sales-invoices (branch_id=3)
    MW->>MW: SyncLegacySession → session.branch_id=3
    MW->>DB: SET app.branch_id = 3; SET app.is_admin = false
    MW->>MW: EnforceBranchIsolation: input branch_id (3) == session (3) ✓
    MW->>C: dispatch
    C->>S: finalizeInvoice(...)
    S->>M: SalesInvoice::where('id', 999)->first()
    M->>DB: SELECT ... (BranchScope adds branch_id=3)
    DB->>DB: RLS: USING (branch_id = current_setting('app.branch_id')::int)
    DB-->>M: row (only if branch_id=3)
    S->>DB: INSERT INTO sales_invoices (..., branch_id=3, ...)
    DB->>DB: RLS WITH CHECK (branch_id = 3) ✓
    DB-->>S: inserted
```

If the user tried `POST /admin/sales-invoices (branch_id=5)`:
- `EnforceBranchIsolation` rejects with 403 (route layer).
- Even if that check were bypassed, RLS `WITH CHECK` would reject the INSERT at the DB.

If the user tried `GET /admin/sales-invoices/999` where invoice 999 belongs to branch 5:
- `BranchScope` adds `branch_id=3` → query returns 0 rows → 404.
- RLS would also hide the row even without the scope.

---

## 9. Important database tables

| Table | Role in isolation |
|---|---|
| `branches` | The branch master. `id` is the `branch_id` referenced everywhere. |
| `users` | `users.branch_id` (via employee) is the user's home branch; `credential_version` for session invalidation. |
| `user_audit_log` | Logs `branch_override` actions by admins. |
| (all transactional tables) | Carry `branch_id`; protected by RLS policies. |

---

## 10. Related modules / files

| Topic | File |
|---|---|
| High-level architecture | `high-level-architecture.md` |
| Layered design (scopes) | `layered-design.md` |
| Audit trails (full) | `security/audit-trails.md` (Phase 5) |
| RBAC (roles) | `security/rbac-roles-permissions.md` (Phase 5) |
| API branch context | `security/api-security.md` (Phase 5) |
| Middleware source | `laravel/app/Http/Middleware/SetAppBranchId.php`, `EnforceBranchIsolation.php`, `SetApiBranchContext.php` |
| Scope source | `laravel/app/Models/Scopes/BranchScope.php`, `MoneyTransferBranchScope.php`, `WarehouseTransferBranchScope.php` |
| RLS DDL | `laravel/database/sql/07_views_triggers_constraints.sql` (lines ~509+) + migration `2025_01_20_000007_add_rls_branch_isolation.php` |

---

## 11. Known edge cases & rules for AI

- **Never write `Model::all()` or unscoped cross-branch queries.** If you genuinely need
  all branches, use `withoutGlobalScope(BranchScope::class)` AND ensure the user is admin
  (RLS will still enforce).
- **Never use `?` placeholders for GUC `SET` statements.** PostgreSQL `SET` does not
  support parameter binding. Cast/escape and use `DB::unprepared()`.
- **Console commands have no GUC.** Set it manually or run unscoped (admin mode).
- **`current_setting('app.is_admin', true)` returns NULL if unset**, not an error — the
  `= 'true'` comparison is FALSE for NULL, so safety is preserved pre-migration.
- **Branch Demand and Money Transfer are cross-branch** — they use
  `from_branch_id`/`to_branch_id` and special scopes; do not apply `BranchScope` to them.
- **`document_sequences` uses `branch_id = 0`** (global) — its RLS policies match on 0,
  not the session branch.
- **The table owner bypasses RLS** (only `FORCE ROW LEVEL SECURITY` would change this).
  Run the app as a non-owner role in production.

---

## 12. Future improvements

- Consider `FORCE ROW LEVEL SECURITY` on the most sensitive tables so even the owner
  role is filtered (requires careful migration of owner-only maintenance queries).
- Add an automated test that asserts every transactional table has RLS enabled (extend
  `tests/Feature/StockTake/RlsCrossBranchTest.php` style to all modules).
- Document the full list of RLS-protected tables in `database/triggers-views-constraints.md`
  (Phase 3) by scanning the DDL.

---

*This is the canonical reference for branch isolation. Any new multi-tenant table MUST
get RLS policies + a `branch_id` column + (where applicable) the `BranchScope`.*
