# API Overview

> **Module:** API / REST v1
> **Audience:** Engineers + AI assistants + API consumers (mobile / AI sidecar / integrators)
> **Status:** Draft — pending review (3 CRITICAL gaps; see §13)
> **Last reviewed:** Phase 17 (API Layer REST v1)
> **Source of truth:** this file + `laravel/routes/api.php` (554L, the route map) + `laravel/app/Http/Middleware/ApiAuth.php` (92L) + `laravel/app/Http/Middleware/ApiRateLimit.php` (221L) + `laravel/app/Http/Middleware/SetApiBranchContext.php` (81L) + `laravel/app/Console/Commands/GenerateApiToken.php` + `laravel/config/api.php`

---

## 1. What is it?

The **RC_ERP REST API (v1)** is a JSON-over-HTTP interface exposed at `/api/v1/*` that lets
non-browser clients — the (future) mobile sales app, the (future) Phase-13 AI sidecar, and
approved third-party integrations — read master data, run dashboard queries, and execute the
same business transactions that the Blade web UI executes. As of Phase 17 it exposes **101
endpoints** (1 public docs page + 100 authenticated v1 routes) across **14 module groups**:
Branches, Dashboard, Lookups, Sales Cart, Sales Invoices, Sales Challans, Sales Returns,
Customer Payments, Commission, Warehouse Transfers, Stock Adjustments, Stock Take Sessions,
Stock Take Items, and Branch Demands.

The API is **not** a separate application. Every API controller is a thin wrapper that
delegates to the **same service layer** the web controllers use (`SalesInvoiceService`,
`StockAdjustmentService`, `BranchDemandService`, etc.). This is deliberate: business rules,
GL postings, audit trails, branch isolation, and maker-checker enforcement live in exactly
one place and cannot be bypassed by picking a different surface.

Authentication is a **custom bearer-token mechanism** (NOT Laravel Sanctum, despite
`sanctum:^4.0` being declared in `composer.json` — see §6 and `../security/api-security.md`).
Tokens are 60–64 character random strings stored as a SHA-256 hash in `users.api_token`. Rate
limiting is Redis-backed and per-(token, IP). Branch isolation on RLS-protected modules is
established by the `set.api.branch` route middleware, which sets the same PostgreSQL GUCs
(`app.branch_id`, `app.is_admin`) the global `SetAppBranchId` middleware sets for web
requests.

---

## 2. Why does it exist?

- **Mobile app + AI sidecar need a stateless surface.** The web UI is session-based and
  Blade-rendered; mobile and AI clients cannot consume HTML. The API gives them JSON.
- **The same business rules MUST apply.** Reusing the service layer means an invoice
  finalised via `POST /api/v1/sales/invoices` posts the identical Dr-AR / Cr-Revenue /
  Cr-COGS journal entries, decrements stock at moving-average cost, writes the identical
  audit row, and respects the identical credit-limit / branch-isolation / period-close
  guards as the web `POST /admin/sales/finalize`. No second implementation exists.
- **Sanctum was declared but never wired.** `config/auth.php` declares a `sanctum` guard
  with `provider => null`, and no `config/sanctum.php` exists. Rather than adopt Sanctum's
  `personal_access_tokens` table mid-migration, the team kept the legacy single-column
  `users.api_token` approach and wrote a thin `ApiAuth` middleware. This is a deliberate
  "keep what works" decision — see §6 and `../security/api-security.md` §2.
- **BDIX exposure demands rate limiting.** The API is reachable from Bangladesh's internal
  internet exchange; runaway mobile clients or leaked tokens could hammer endpoints. The
  Redis-backed `ApiRateLimit` middleware caps traffic per (token, IP) bucket.

---

## 3. When is it used?

- **Every `/api/v1/*` request** — `ApiAuth` validates the bearer token; `ApiRateLimit`
  throttles; `SetApiBranchContext` sets the RLS GUC on the four inventory/branch-demand
  module groups.
- **Token issuance** — via the `php artisan api:token {username} {--role=}` command (or
  programmatically via `User::generateApiToken()`). No admin UI exists for token management.
- **Interactive docs** — `GET /api/docs` (publicly accessible, no auth, no rate limit) serves
  a self-contained HTML page with an in-browser "Try it" panel.
- **Read-heavy polling** — the mobile home screen polls `GET /api/v1/dashboard` and
  `GET /api/v1/lookups/*` every few seconds; these are capped at 120 req/min (vs 60 default).
- **Transactional writes** — Sales/Stock/Branch-Demand write endpoints are capped at 30
  req/min because each request posts journals and mutates stock.

---

## 4. Who uses it?

| Consumer | Auth | Typical endpoints | Notes |
|---|---|---|---|
| **Mobile sales app** (future) | Bearer token (salesman role) | `GET /lookups/*`, `GET/POST /sales/cart`, `POST /sales/invoices`, `GET /sales/invoices/credit-check`, `GET /dashboard` | Salesman finalises invoices on the road; offline-tolerant cart + idempotency token on finalize. |
| **Mobile warehouse app** (future) | Bearer token (warehouse_manager / dispatcher role) | `POST /sales/challans/godown`, `POST /sales/challans/issue`, `GET/POST /warehouse-transfers`, `GET/POST /stock-take/sessions/*` | Dispatch + count + transfer stock without a desktop. |
| **Mobile manager app** (future) | Bearer token (manager / admin role) | `POST /stock-adjustments/{id}/approve`, `POST /stock-take/sessions/{id}/post`, `POST /branch-demands/{id}/reverse`, `GET /sales/commission/*` | Approve / post / reverse / confirm — the destructive/terminal actions. |
| **AI sidecar** (future, Phase 13) | Bearer token (admin role) | All endpoints | The eventual AI assistant that will read context and propose transactions. |
| **External integrator** | Bearer token (admin role, issued ad-hoc) | `GET /branches`, `GET /lookups/*`, `GET /dashboard` | Read-only sync to BI / forecasting tools. |
| **Admin** (issuing tokens) | n/a — uses artisan CLI | `php artisan api:token {user} --role={role}` | Only way to mint a token today. |
| **System/automated** | n/a | `ApiAuth` + `ApiRateLimit` run on every request | No background job calls the API today (no webhook sender, no scheduled API poller). |

---

## 5. Related modules

- `../security/api-security.md` — the canonical, exhaustive reference for `ApiAuth`,
  `ApiRateLimit`, token issuance, role enforcement, and the 10-role matrix. **Read it
  before this file if you are implementing or auditing security.**
- `../security/branch-context-security.md` — the RLS GUC mechanism and the
  `SetApiBranchContext` (`set.api.branch`) middleware.
- `../security/auth-and-sessions.md` — the legacy session bridge; explains why `ApiAuth`
  logs the user in on the `web` guard (not the `sanctum` guard).
- `../security/rbac-roles-permissions.md` — the 10-role matrix that `api.auth:role,...`
  enforces.
- `api-conventions.md` — JSON response shape, pagination contract, error contract,
  idempotency, naming.
- `api-modules.md` — the per-module endpoint catalogue (101 routes × 14 module groups).
- `api-reference-index.md` — index into `laravel/docs/api/API_REFERENCE.md` with a coverage
  map and "how to update" maintainer note.
- `../coding/request-validation.md` — the 12 API FormRequest classes + the inline
  `$request->validate([...])` pattern used by 6 of 14 modules.
- `../coding/error-handling.md` — the exception → HTTP status mapping the API relies on.
- `../coding/service-layer-conventions.md` — why every API controller delegates to a
  service (no business logic in controllers).

---

## 6. Business rules

- **MUST** authenticate every `/api/v1/*` request with `Authorization: Bearer <token>`. The
  only public API route is `GET /api/docs`.
- **MUST** store only the SHA-256 hash of the token in `users.api_token`; the plain token is
  shown once at generation and is never recoverable.
- **MUST** reject disabled/deleted users: `User::findByApiToken()` requires `is_active = true`
  AND `deleted_at IS NULL`.
- **MUST** log the API user in on the default `web` guard (NOT the `sanctum` guard) so RBAC
  role checks work identically to web. (`ApiAuth::handle()` line 47.)
- **MUST** rate-limit: default 60 req/min per (token, IP) bucket. Read-only dashboard +
  lookup endpoints get 120 req/min. Transactional writes (Sales/Stock/Branch-Demand) get
  30 req/min. Override per route via `->middleware('api.rate:N')`.
- **MUST** set `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers on
  every rate-limited response (success or failure).
- **MUST** return HTTP 429 with `Retry-After` header + JSON `{message, retry_after}` when
  the limit is exceeded.
- **MUST** set the `app.branch_id` + `app.is_admin` GUCs on RLS-protected API routes via
  the `set.api.branch` middleware. This is required for the four inventory/branch-demand
  module groups (Warehouse Transfers, Stock Adjustments, Stock Take, Branch Demands) whose
  tables have RLS policies. The other module groups (Branches, Dashboard, Lookups, Sales)
  enforce branch isolation explicitly in the controller via `SalesAccess::assertBranchAccessible()`
  or `assertBranchAccessible()` helpers, NOT via RLS.
- **MUST NOT** use Sanctum token abilities / scopes — they are not implemented. A token's
  permissions = the user's role. (`../security/api-security.md` §7.6.)
- **MUST NOT** issue tokens via a web UI — only via the `api:token` artisan command (or
  programmatic `User::generateApiToken()`).
- **MUST NOT** bypass the service layer. API controllers are thin: validate input → call
  service → wrap result in a Resource → return JSON. No business logic in controllers.
- **MUST** reuse the same service, policy, and FormRequest classes the web controller uses.
  There is exactly one `SalesInvoiceService::finalizeFromCart()` — the web `finalize` route
  and the API `POST /sales/invoices` route both call it.
- **MUST** send an `idempotency_token` (client-generated UUID) on the three transactional
  write endpoints that mutate stock + GL: `POST /sales/invoices`, `POST /sales/challans/issue`,
  `POST /sales/payments`. The server caches the result for 5 minutes and replays it on retry.
  (See `api-conventions.md` §11.)
- **SHOULD** rotate tokens periodically. **Gap:** no expiry is enforced today (see §13 G2).
- **SHOULD** use HTTPS. The Caddy/Nginx reverse proxy terminates TLS; the Laravel app listens
  on plain HTTP inside the container.

---

## 7. Technical implementation

### 7.1 Route registration — `laravel/routes/api.php` (554L)

All API routes are registered in `routes/api.php`. The file is loaded by Laravel 11's
automatic route discovery (`bootstrap/app.php` → `withRouting()` → `api` option), which
prefixes all routes with `/api` (no separate `Route::apiResource` calls needed).

**Top-level structure:**

```php
// Phase 18: Public API docs page (NOT behind api.auth or api.rate).
Route::get('/docs', [ApiDocController::class, 'index'])->name('api.docs');

// Phase 13/19: Authenticated API group + per-route rate limiting.
Route::prefix('v1')->middleware('api.auth')->group(function (): void {
    // 14 module groups, each with its own middleware stack (see api-modules.md §7).
});
```

**Versioning:** all authenticated routes use the `v1` prefix. No `v2` exists. The version is
a URL path segment, not a header (`Accept: application/vnd.rcerp.v1+json` is NOT supported).
See §10 for the versioning strategy + deprecation policy.

### 7.2 Middleware stack — order of execution

API requests traverse middleware in this order (global → group → route):

```
[GLOBAL]  bootstrap/app.php global stack:
            CheckCredentialVersion (skips — Auth::check() is false at global time for API)
            SetAppBranchId         (skips — same reason; set.api.branch runs later instead)
            TrustProxies(*)        (so $request->ip() returns the real client IP behind Nginx)
[GROUP]   api (laravel/route-groups via withRouting('api')):
            (no group middleware — Laravel 11's 'api' group is empty by default)
[ROUTE]   api.auth              (bearer token → User::findByApiToken → Auth::login on web guard)
          api.rate:N            (Redis INCR on bucket key; 429 if exceeded)
          api.auth:role1,role2  (optional, on destructive endpoints — admin/manager/etc.)
          set.api.branch        (optional, on RLS-protected module groups — sets GUC)
          → controller@method   (FormRequest validation → service → Resource → JSON)
```

The middleware order in the route declaration matters: `api.auth` MUST come before
`api.rate` (rate limit reads `Auth::user()` indirectly via the bearer header) and before
`set.api.branch` (which needs `Auth::user()` to derive the branch_id GUC). The route file
respects this order on every route.

### 7.3 `ApiAuth` middleware — `laravel/app/Http/Middleware/ApiAuth.php` (92L)

Alias: `api.auth`. Optional role args: `->middleware('api.auth:admin')` or
`->middleware('api.auth:admin,manager,warehouse_manager')`.

**Logic (verbatim flow):**

1. Extract bearer token from the `Authorization` header (requires `Bearer ` prefix).
2. If empty → **401 JSON** `{message: "Unauthenticated.", detail: "Missing or invalid Authorization header."}`.
3. `User::findByApiToken($token)` — SHA-256 hash lookup against `users.api_token`, requires
   `is_active=true` AND `deleted_at IS NULL`.
4. If null → **401 JSON** `{message: "Unauthenticated.", detail: "Invalid or expired API token."}`.
5. `Auth::login($user)` on the **default `web` guard** (NOT the `sanctum` guard).
6. Optional role enforcement (when `api.auth:role,...` is declared): passes if the user's
   role is `superadmin`, OR matches one of the listed roles, OR (`admin` is listed AND user
   role is `admin`). On mismatch → **403 JSON** `{message: "Forbidden. Requires role: ..."}`.

> The role check has a quirk: `superadmin` always passes (correct), `admin` passes only when
> `admin` is in the allowed list (correct), but a `superadmin` is NOT treated as an implicit
> `admin` for routes that list only `admin` — it passes via the explicit superadmin branch.
> This is intentional but worth noting when reasoning about access.

### 7.4 `ApiRateLimit` middleware — `laravel/app/Http/Middleware/ApiRateLimit.php` (221L)

Alias: `api.rate`. Optional limit arg: `->middleware('api.rate:60')` or `api.rate:120`.

**Constants:** `WINDOW_SECONDS = 60`, `KEY_NAMESPACE = 'api_rate:'`.

**Limit resolution:** middleware parameter (if provided and > 0) → `config('api.rate_limit.default', 60)`
→ hardcoded 60.

**Bucket key:** `api_rate:{sha256(token)}:{ip}` for authenticated requests;
`api_rate:anonymous:{sha256(ip)}` for anonymous requests. The token is SHA-256 hashed before
going into the Redis key (defense in depth — the raw bearer never appears in Redis).

**Increment:** Redis `INCR` (atomic) on first sighting sets TTL via `EXPIRE 60`. If Redis
throws, marks `$this->redisAvailable = false` for the rest of the request and falls back to
`Cache::get/put` (file/array) — best-effort, not atomic.

**Response headers (always set on non-429 responses):** `X-RateLimit-Limit`,
`X-RateLimit-Remaining`, `X-RateLimit-Reset`.

**429 response:**

```json
{
  "message": "Rate limit exceeded. Maximum 60 requests per minute.",
  "retry_after": 42
}
```

+ `Retry-After: 42` header + `X-RateLimit-Remaining: 0`.

### 7.5 `SetApiBranchContext` middleware — `laravel/app/Http/Middleware/SetApiBranchContext.php` (81L)

Alias: `set.api.branch`. Applied to the four RLS-protected module groups: Warehouse Transfers,
Stock Adjustments, Stock Take, Branch Demands.

**Why it exists:** the global `SetAppBranchId` middleware runs in the GLOBAL stack — BEFORE
route middleware like `api.auth`. For API requests (bearer-token auth), `Auth::check()` is
false at global-middleware time, so `SetAppBranchId` skips and the GUCs stay at the database
default (`app.branch_id=0`, `app.is_admin=false`). RLS then blocks ALL rows for non-admin API
users (`branch_id=0` never matches a real branch).

`set.api.branch` runs AFTER `api.auth` (it's a route middleware), so `Auth::user()` is
available. It executes:

```php
DB::unprepared("SET app.branch_id = {$safeBranchId}");
DB::unprepared("SET app.is_admin = {$safeIsAdmin}");
```

where `$safeBranchId = (int) $user->getBranchId()` and `$safeIsAdmin = $user->isAdmin() ? 'true' : 'false'`.

Non-admin users: GUC = their own `branch_id` → RLS shows only their branch.
Admin/superadmin: `app.is_admin = true` → RLS bypass policy shows all branches.

The GUC is per-connection and resets when the connection returns to the pool, so there's no
cross-request leakage.

### 7.6 `config/api.php` — rate-limit thresholds

```php
'rate_limit' => [
    'default'   => (int) env('API_RATE_LIMIT', 60),
    'dashboard' => (int) env('API_RATE_LIMIT_DASHBOARD', 120),
    'lookups'   => (int) env('API_RATE_LIMIT_LOOKUPS', 120),
],
```

All three are env-overridable so production can tighten limits without a code deploy. The
middleware parameter (`api.rate:30`) takes precedence over the config default.

### 7.7 Token issuance — `laravel/app/Console/Commands/GenerateApiToken.php`

Artisan command: `php artisan api:token {username} {--role=}`.

**Behavior:**

1. Case-insensitive username lookup (with `withTrashed()`).
2. Optional role update via the linked `Employee` record (`--role` validated against
   `config('roles')`).
3. Re-activates the user if `is_active=false` (so the new token works).
4. Generates `$plain = Str::random(64)` (note: `User::generateApiToken()` uses 60 — see §13
   G4 for the inconsistency).
5. Stores `hash('sha256', $plain)` in `users.api_token`.
6. Prints the plain token to stdout with a one-time warning.

**No admin UI for token generation** — tokens are issued only via this artisan command (or
programmatically via `User::generateApiToken()`).

### 7.8 `User` model API-token methods

```php
// laravel/app/Models/User.php
public function generateApiToken(): string {
    $plain = Str::random(60);
    $this->api_token = hash('sha256', $plain);
    $this->save();
    return $plain;  // shown once
}

public static function findByApiToken(string $plainToken): ?self {
    return self::where('api_token', hash('sha256', $plainToken))
        ->where('is_active', true)
        ->whereNull('deleted_at')
        ->first();
}
```

`api_token` is hidden on serialization (`protected $hidden = [..., 'api_token']`).

### 7.9 API controllers — `laravel/app/Http/Controllers/Api/V1/`

15 controllers across 9 subdirectories (full per-method map in `api-modules.md` §7):

| Controller | LOC | Endpoints | Service dependencies |
|---|---|---|---|
| `ApiDocController` (top-level, not V1) | 859 | 1 (public docs) | none |
| `BranchApiController` | 241 | 5 | none (raw `Branch` model) |
| `DashboardApiController` | 167 | 3 | none (raw `DB::table`) |
| `LookupApiController` | 92 | 6 | none (raw `DB::table`) |
| `Sales/SalesCartApiController` | 250 | 8 | `SalesCartService`, `StockAvailabilityService` |
| `Sales/SalesInvoiceApiController` | 283 | 7 | `SalesInvoiceService`, `SalesAccess` |
| `Sales/SalesChallanApiController` | 221 | 5 | `SalesChallanService`, `SalesAccess` |
| `Sales/SalesReturnApiController` | 229 | 6 | `SalesReturnService`, `SalesAccess` |
| `Sales/CustomerPaymentApiController` | 279 | 6 | `CustomerPaymentService`, `SalesAccess` |
| `Sales/CommissionApiController` | 339 | 8 | `CommissionService`, `SalesAccess` |
| `StockAdjustment/StockAdjustmentApiController` | 695 | 8 | `StockAdjustmentService`, `StockAdjustmentPolicyService` |
| `StockTake/StockTakeSessionApiController` | 423 | 13 | `StockTakeService`, `StockTakePolicyService` |
| `StockTake/StockTakeItemApiController` | 283 | 4 | `StockTakeService` |
| `WarehouseTransfer/WarehouseTransferApiController` | 394 | 6 | `WarehouseTransferService`, `StockService`, `StockAvailabilityService` |
| `BranchDemand/BranchDemandApiController` | 745 | 15 | `BranchDemandService`, `BranchIntercompanyService`, `BranchDemandRepricingService`, `BranchDemandAuditService`, `StockService`, `StockAvailabilityService` |
| **Total** | **5,500** | **101** | — |

### 7.10 Public docs page — `laravel/app/Http/Controllers/Api/ApiDocController.php` (859L)

Serves `GET /api/docs` — a self-contained HTML page (CSS + JS inlined, no external deps)
with a "Try it" panel that lets developers call any endpoint from the browser using `fetch`.

**Coverage gap (G1, §13):** the page hardcodes endpoint cards in `endpoints()` (lines 62–480)
and was last updated in Phase 18. It documents 23 of the 100 `/v1` endpoints (the original
Phase-13 Branches + Dashboard + Lookups + the Phase-11 Stock Take subset). The other 77
endpoints (Sales × 5 controllers, Stock Adjustment, Warehouse Transfer, Branch Demand,
Commission) are NOT on the docs page.

---

## 8. Important database tables

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Holds the hashed API token | `id, api_token (varchar 64, unique, nullable), is_active, deleted_at` |

> `users.api_token` is `NULL` for users who have never been issued a token.
> `findByApiToken()` returns `null` for a NULL `api_token` (the `where('api_token', hash(...))`
> won't match NULL).

No other API-specific tables exist. The API reads/writes the same 66 tables the web UI does.
For the per-module table map, see `api-modules.md` §8.

---

## 9. Related services

- `laravel/app/Http/Middleware/ApiAuth.php` — bearer-token auth (92L).
- `laravel/app/Http/Middleware/ApiRateLimit.php` — Redis-backed rate limiter (221L).
- `laravel/app/Http/Middleware/SetApiBranchContext.php` — branch GUC setter (81L).
- `laravel/app/Console/Commands/GenerateApiToken.php` — token issuance artisan command.
- `laravel/app/Http/Controllers/Api/ApiDocController.php` — public docs page (859L).
- The 15 `Api\V1\*` controllers (see §7.9 table).
- All business-logic services the controllers delegate to — listed per-module in
  `api-modules.md` §7. **No service is API-only**; every service is shared with the web
  controller.

---

## 10. Related models

- `laravel/app/Models/User.php` — `generateApiToken()`, `findByApiToken()`, `api_token`
  (hidden), `getRole()`, `getBranchId()`, `isAdmin()`.
- The 60+ Eloquent models the API reads/writes — see `api-modules.md` §10 for the per-module
  list.

---

## 11. Important workflows

### 11.1 API request lifecycle (auth → rate-limit → branch-context → handler)

```mermaid
sequenceDiagram
    participant C as API Client
    participant N as Nginx/Caddy (TLS)
    participant L as Laravel
    participant RL as ApiRateLimit
    participant AA as ApiAuth
    participant U as User::findByApiToken
    participant SAB as SetApiBranchContext
    participant FR as FormRequest
    participant S as Service
    participant R as ApiResource
    participant DB as PostgreSQL (RLS)

    C->>N: GET /api/v1/stock-adjustments (Bearer <token>)
    N->>L: reverse-proxy + TrustProxies
    L->>RL: route middleware 'api.rate:60'
    RL->>RL: bucket = sha256(token):ip
    RL->>RL: Redis INCR (atomic)
    alt limited
        RL-->>C: 429 JSON + Retry-After + X-RateLimit-*
    end
    RL->>AA: next (X-RateLimit-* headers set on response later)
    AA->>AA: extract Bearer token
    AA->>U: findByApiToken(plain)
    alt !user
        AA-->>C: 401 JSON 'Invalid or expired API token.'
    end
    AA->>AA: Auth::login($user) ;; web guard
    alt role mismatch (api.auth:admin,manager)
        AA-->>C: 403 JSON 'Forbidden. Requires role: ...'
    end
    AA->>SAB: next(request)
    SAB->>DB: SET app.branch_id = user.branch_id
    SAB->>DB: SET app.is_admin = user.isAdmin
    SAB->>FR: next(request)
    FR->>FR: authorize() + rules() validated
    alt validation fails
        FR-->>C: 422 JSON {message, errors{field:[msg]}}
    end
    FR->>S: validated() data
    S->>DB: DB transaction: post journals, mutate stock, write audit
    DB-->>S: rows for user's branch only (RLS)
    S-->>R: model / array
    R-->>C: 200 JSON {data: ..., message: ...}
```

### 11.2 Token issuance

```mermaid
sequenceDiagram
    actor A as Admin
    participant CLI as artisan api:token
    participant U as User model
    participant E as Employee model
    participant DB as PostgreSQL

    A->>CLI: php artisan api:token jsmith --role=manager
    CLI->>U: User::where(username) withTrashed
    opt --role provided
        CLI->>E: employee->role = 'manager'; save
        E->>DB: UPDATE employees SET role = 'manager'
    end
    CLI->>U: is_active = true; save
    U->>DB: UPDATE users SET is_active = true
    CLI->>CLI: plain = Str::random(64)
    CLI->>U: api_token = sha256(plain); save
    U->>DB: UPDATE users SET api_token = '...'
    CLI-->>A: print plain token (shown once) + warning
```

### 11.3 Idempotent write (Sales Invoice finalize)

```mermaid
sequenceDiagram
    participant C as API Client
    participant Ctrl as SalesInvoiceApiController
    participant Cache as Laravel Cache
    participant Svc as SalesInvoiceService
    participant DB as PostgreSQL

    C->>Ctrl: POST /api/v1/sales/invoices (idempotency_token=UUID, ...)
    Ctrl->>Ctrl: FinalizeInvoiceRequest.validate()
    Ctrl->>Cache: GET api:finalize:{UUID}
    alt cache hit
        Cache-->>Ctrl: cached result
        Ctrl-->>C: 200 JSON {data, message, idempotent_replay: true}
    else cache miss
        Ctrl->>Svc: finalizeFromCart(validated + created_by)
        Svc->>DB: BEGIN; post Dr-AR/Cr-Revenue/Cr-COGS; decrement stock; write audit; COMMIT
        Svc-->>Ctrl: SalesInvoice model
        Ctrl->>Cache: PUT api:finalize:{UUID} = result, TTL 5 min
        Ctrl-->>C: 201 JSON {data, message}
    end
```

The same pattern guards `POST /sales/challans/issue` and `POST /sales/payments` (5-min
idempotency window). The other ~7 write endpoints do NOT implement idempotency — see §13 G7.

---

## 12. Known edge cases

- **API tokens are NOT covered by credential versioning.** `CheckCredentialVersion` is global
  but `Auth::check()` is false for API requests at global-middleware time. `ApiAuth` does not
  consult `credential_version`. To revoke an API token, regenerate `users.api_token` (via
  `User::generateApiToken()` or the artisan command) or set `api_token = NULL`. **A password
  change does NOT invalidate the API token.** (See `../security/credential-versioning.md`.)
- **No token expiry.** `users.api_token` has no `expires_at`. A token is valid until manually
  regenerated or the user is disabled/deleted. (Gap G2, §13.)
- **One token per user.** `users.api_token` is a single column — a user has at most one active
  token. Regenerating replaces the old one. There is no multi-token / named-token support
  (Sanctum's `personal_access_tokens` would provide this).
- **Token length inconsistency.** `User::generateApiToken()` uses `Str::random(60)`; the
  artisan command uses `Str::random(64)`. Both are stored as a 64-char SHA-256 hex hash, so
  the column length is fine, but the plain-token length varies. (Gap G4, §13.)
- **`api:token` re-activates disabled users.** The command sets `is_active = true` so the new
  token works. This is intentional (issuing a token implies the user should be active) but
  could surprise an admin who expected to issue a token for a disabled user without enabling
  them.
- **No CORS config.** `config/cors.php` does not exist (Laravel 11+ removed the default). The
  API is intended for mobile/AI sidecar clients on the same network (BDIX); CORS is not a
  primary concern. If a browser-based SPA ever consumes the API, a `config/cors.php` will
  need to be added. (Gap G3, §13.)
- **Rate-limit fallback is not atomic.** If Redis is down, `ApiRateLimit` falls back to
  `Cache::get/put` (file/array), which is not atomic under concurrency. A Redis outage could
  briefly allow over-limit traffic. Acceptable for an internal API.
- **`GET /api/docs` is public.** The API docs page is not behind `api.auth` or `api.rate`. It
  must not expose sensitive information — it currently documents endpoints with example
  payloads; verify it doesn't leak internal paths or example tokens.
- **Anonymous rate-limit bucketing by IP only.** Unauthenticated requests are bucketed by IP
  — behind a NAT/proxy, many clients could share an IP and hit the limit together. The
  `X-Forwarded-For` header is trusted via `trustProxies(at: '*')` in `bootstrap/app.php`, so
  the real client IP is used when behind the Nginx reverse proxy.
- **`set.api.branch` only runs on 4 of 14 module groups.** The Sales module groups (Cart,
  Invoices, Challans, Returns, Payments) and the master-data groups (Branches, Dashboard,
  Lookups) do NOT use it — they enforce branch isolation via explicit
  `assertBranchAccessible()` calls in the controller. This is correct (those tables either
  have no RLS or are read-only cross-branch), but it means a new Sales API endpoint that
  forgets the `assertBranchAccessible()` call would silently leak cross-branch data. (Gap G6,
  §13.)
- **Idempotency cache is not durable.** The 5-min idempotency window uses the default Laravel
  cache (file/array/Redis depending on `CACHE_STORE`). If the cache is flushed or Redis
  restarts, a retried finalize will create a duplicate invoice. The window is short enough
  that this is a low-probability event, but it is not zero.
- **`api.auth` role check has a `superadmin` quirk.** A `superadmin` always passes (correct),
  but the check `in_array('admin', $roles, true) && $userRole === 'admin'` does NOT cover the
  case where a `superadmin` hits an `api.auth:manager,accountant` route — they pass via the
  explicit `superadmin` branch, not via the admin-implicit logic. This is correct behavior,
  but worth noting when reasoning about access matrices.

---

## 13. Future improvements (gap catalogue)

Gap IDs are stable references used across `api-conventions.md`, `api-modules.md`, and
`api-reference-index.md`. Severity: **CRITICAL / HIGH / MEDIUM / LOW**.

| ID | Severity | Gap | Recommended fix |
|---|---|---|---|
| G1 | CRITICAL | `laravel/docs/api/API_REFERENCE.md` documents only 14 of 100 `/v1` endpoints (14% coverage, 86% drift). It claims Phase 18 but the API has grown through Phase 19 + Task 37 (Commission). | Rewrite `API_REFERENCE.md` to cover all 101 endpoints using the per-module tables in `api-modules.md` as the source of truth. See `api-reference-index.md` §6 for the update procedure. |
| G2 | CRITICAL | `ApiDocController::endpoints()` (the `/api/docs` page) hardcodes 23 of 100 endpoint cards (77% drift). New endpoints added since Phase 18 are invisible on the interactive docs page. | Either generate the docs page from `routes/api.php` reflection, or add a test that fails when the hardcoded card count drifts from the route count. |
| G3 | HIGH | ZERO tests for 8 of 14 modules: Sales Cart, Sales Invoices, Sales Challans, Sales Returns, Customer Payments, Commission, Warehouse Transfers, Stock Adjustments = 56 of 100 `/v1` endpoints untested (55.4%). See `../coding/testing-standards.md`. | Add a `*_ApiTest.php` per module covering the happy path + auth + RBAC + the 4 common error shapes (401/403/404/422). |
| G4 | MEDIUM | Token length inconsistency: `User::generateApiToken()` uses `Str::random(60)`, `GenerateApiToken` command uses `Str::random(64)`. | Pick one (recommend 64) and update `User::generateApiToken()`. |
| G5 | MEDIUM | `BranchDemandApiTest` hand-rolls token issuance 16× instead of using the `IssuesApiTokens` helper that the 5 other API test files use. | Refactor `BranchDemandApiTest` to use `IssuesApiTokens::apiTokenForUser()`. |
| G6 | HIGH | Role-gate inconsistency: write endpoints on Sales Cart/Invoices/Returns/Payments rely ONLY on the controller's `SalesAccess::assertBranchAccessible()` check, with NO route-level `api.auth:role` gate. Stock Adjustment has both. This is a defense-in-depth gap. | Add `api.auth:salesman,manager,admin` (or the appropriate role set) to the Sales write routes. |

> ✅ RESOLVED in commit c4acdb0 (G-087, cross-ref G-086) — Same fix as G-086 (cross-referenced). Added `api.auth:salesman,manager,admin` to the Sales Cart + Customer Payments write endpoints (POST/PUT/DELETE only — reads keep `api.auth` + `api.rate`). Sales Invoices / Returns / Challans godown-issue already had role middleware from prior gaps (G-166 / G-167 / BUG-52) — those were preserved intact (more granular per-action matrices). See the G-086 marker in `api/api-modules.md:724` for the full route list + deviation notes. Closes both G-086 + G-087 by the same `routes/api.php` edit. Sub-problem A (Session 6, Security/RLS cluster).
| G7 | HIGH | Idempotency is implemented on only 3 of ~10 transactional write endpoints (`POST /sales/invoices`, `POST /sales/challans/issue`, `POST /sales/payments`). `POST /stock-adjustments`, `POST /warehouse-transfers`, `POST /branch-demands`, `POST /stock-take/sessions`, `POST /sales/returns`, `POST /sales/cart` do NOT implement idempotency — a network retry could create a duplicate. | Add an `idempotency_token` field + 5-min cache lookup to every POST that mutates stock or GL. |
| G8 | MEDIUM | No API test verifies the `set.api.branch` middleware actually enforces RLS for any of the 4 RLS-protected modules. Cross-branch access is blocked at the GUC level but never asserted in tests. | Add a `test_non_admin_cannot_see_other_branch_*` test per module. |
| G9 | MEDIUM | `ApiRateLimitTest` verifies the 60-req/min branches cap and the 120-req/min dashboard cap, but NOT the 30-req/min transactional write cap on Sales/Stock Adjustment/Branch Demand. | Add a `test_write_endpoint_enforces_30_per_minute` test. |
| G10 | LOW | `ApiDocTest` asserts the string `"Endpoints (14)"` on the docs page, but the page actually shows 23 cards. The test is stale and only verifies the Phase-13 subset appears. | Update the assertion to the actual card count, or assert per-module sections. |
| G11 | MEDIUM | No API tests for idempotency (G7 endpoints). The `Cache::get/put` replay path is never exercised in tests. | Add a `test_finalize_with_same_idempotency_token_returns_idempotent_replay` test. |
| G12 | MEDIUM | `POST /branch-demands/{id}/reprice` has only a validation-path test, no happy-path test. | Add a `test_reprice_demand_happy_path` test. |
| G13 | LOW | The Commission module has NO web mirror (API-only) AND no API tests — 8 endpoints are completely untested on both surfaces. | Add `CommissionApiTest.php` (this also closes part of G3). |
| G14 | MEDIUM | No CORS config (`config/cors.php` absent). Browser SPA consumers will hit Laravel 11's default CORS, which blocks credentialed cross-origin requests. | Add `config/cors.php` if a browser SPA will consume the API; otherwise document the mobile-only constraint here. |
| G15 | MEDIUM | No `Accept` negotiation. The API ignores `Accept` headers and always returns JSON. A future `v2` cannot be selected via `Accept: application/vnd.rcerp.v2+json`. | Decide on a versioning strategy (URL path vs Accept header) before v2 is needed. See §10. |
| G16 | LOW | No `Sunset` / `Deprecation` header machinery. When v2 ships, v1 endpoints cannot be deprecated gracefully. | Add a `Deprecation` middleware that attaches `Sunset` + `Deprecation` headers to flagged routes. |

---

## 14. Versioning strategy

**Current state:** v1 only. Version = URL path segment (`/api/v1/...`). No `v2` exists. No
`Accept` header negotiation. No `Sunset` / `Deprecation` headers.

**Forward plan (NOT yet implemented — see G15, G16):**

- v2 (when needed) will be added as a parallel `Route::prefix('v2')` group in `routes/api.php`.
- v1 will remain the default for at least one major release after v2 ships.
- Deprecation will be signaled via `Deprecation: true` + `Sunset: <date>` response headers on
  v1 routes (requires a new middleware — G16).
- Breaking changes (status code shifts, response-shape changes, removed fields) require a v2
  bump. Additive changes (new optional fields, new endpoints) do NOT require a bump and are
  made in-place on v1.
- The `api:token` command and `ApiAuth` middleware are version-agnostic — the same token
  works on v1 and v2.

---

## 15. Verification commands

```bash
# List every registered API route (method + URI + middleware + controller@method).
php artisan route:list --path=api

# List only v1 routes, with middleware columns.
php artisan route:list --path=api/v1 --columns=method,uri,middleware,action

# Issue a token (admin shell access required).
php artisan api:token jsmith --role=manager

# Smoke-test auth (replace <token>).
curl -sS -H "Authorization: Bearer <token>" -H "Accept: application/json" \
     https://erp.example.com/api/v1/dashboard | jq .

# Verify rate-limit headers are present.
curl -sS -I -H "Authorization: Bearer <token>" \
     https://erp.example.com/api/v1/branches | grep -i 'x-ratelimit'

# Confirm the docs page is publicly reachable (no auth).
curl -sS -o /dev/null -w '%{http_code}\n' https://erp.example.com/api/docs
# expected: 200

# Run the API test suite.
php artisan test --filter='Api'
```

---

## 16. Cross-reference summary

| If you need to know… | read |
|---|---|
| How auth + rate limit + RLS middleware chain together | this file §7.2 + `../security/api-security.md` §7 |
| The exact JSON shape of a list / single / error response | `api-conventions.md` |
| Every endpoint in every module (method, URL, role, rate, service) | `api-modules.md` |
| Which endpoints are documented in `API_REFERENCE.md` + how to update it | `api-reference-index.md` |
| How to issue a token | this file §7.7 + `../security/api-security.md` §7.9 |
| How branch isolation works on the API | `../security/branch-context-security.md` + this file §7.5 |
| How idempotency works | `api-conventions.md` §11 + this file §11.3 |
| Why Sanctum is declared but unused | `../security/api-security.md` §2 + §7.8 |
| The 10-role matrix that `api.auth:role,...` enforces | `../security/rbac-roles-permissions.md` |
| How to add a new API endpoint | `../coding/request-validation.md` + `../coding/service-layer-conventions.md` + `api-modules.md` §7 (mirror an existing module) |

---

*End of `api-overview.md`. For the per-module endpoint catalogue, continue to
`api-modules.md`. For the response-shape + error contract, see `api-conventions.md`.*
