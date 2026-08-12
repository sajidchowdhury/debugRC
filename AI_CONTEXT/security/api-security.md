# API Security

> **Module:** Security / API
> **Audience:** Engineers + AI assistants + API consumers + security reviewers
> **Status:** Draft
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Http/Middleware/ApiAuth.php` + `laravel/app/Http/Middleware/ApiRateLimit.php` + `laravel/routes/api.php` + `laravel/app/Console/Commands/GenerateApiToken.php`

## 1. What is it?

The REST API (`/api/v1/*`) is secured by a **custom bearer-token mechanism** (NOT Laravel
Sanctum, despite `sanctum:^4.0` being in `composer.json`). Tokens are 60–64 character random
strings, stored as a SHA-256 hash in `users.api_token`, and validated per-request by the `ApiAuth`
middleware. Rate limiting is Redis-backed (60 req/min default, configurable per route) via
`ApiRateLimit`. Branch isolation on the API is established by the `set.api.branch` route
middleware (which runs after `api.auth`). Role-based access on the API reuses the same 10-role
matrix as the web UI via the `api.auth:role1,role2,...` middleware parameter.

## 2. Why does it exist?

- The API serves the future mobile app and the Phase 13 AI sidecar (see
  `../PROJECT_OVERVIEW.md`). It needs stateless auth (no session) so it can be called from
  non-browser clients.
- Sanctum was declared in `composer.json` but never configured (no `config/sanctum.php`, the
  `sanctum` guard has `provider => null`). Rather than adopt Sanctum's `personal_access_tokens`
  table mid-migration, the team kept the legacy single-column `users.api_token` approach and wrote
  a thin `ApiAuth` middleware. This is a deliberate "keep what works" decision.
- Rate limiting is essential because the API is reachable from BDIX (Bangladesh's internal
  internet exchange) and could be abused.

## 3. When is it used?

- **Every `/api/v1/*` request** — `ApiAuth` validates the bearer token; `ApiRateLimit` throttles.
- **Token issuance** — via the `php artisan api:token {username}` command (or programmatically via
  `User::generateApiToken()`).
- **Branch-scoped API routes** — `set.api.branch` middleware sets the GUC after auth (see
  `branch-context-security.md`).

## 4. Who uses it?

- **API consumers:** mobile app (future), AI sidecar (future Phase 13), external integrations.
- **Admins** issue tokens via the artisan command.
- **System/automated:** `ApiAuth` + `ApiRateLimit` run on every API request.

## 5. Related modules

- `auth-and-sessions.md` — the `sanctum` guard is declared but unused; `ApiAuth` logs in on the
  `web` guard.
- `rbac-roles-permissions.md` — the same 10 roles gate API routes via `api.auth:role,...`.
- `branch-context-security.md` — `SetApiBranchContext` (`set.api.branch`) sets the RLS GUC.
- `credential-versioning.md` — API tokens are NOT covered by credential versioning (gap — §12).
- `../api/` (Phase 17, pending) — the full API reference.

## 6. Business rules

- **MUST** authenticate every `/api/v1/*` request with a `Authorization: Bearer <token>` header.
- **MUST** store only the SHA-256 hash of the token in `users.api_token`; the plain token is
  shown once at generation and never recoverable.
- **MUST** reject disabled/deleted users: `User::findByApiToken()` requires `is_active = true`
  AND `deleted_at IS NULL`.
- **MUST** log the API user in on the default `web` guard (not the `sanctum` guard) so RBAC role
  checks work identically to web.
- **MUST** rate-limit: default 60 req/min per identity (token+IP), overridable per route via
  `api.rate:N`.
- **MUST** set `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` headers on every
  rate-limited response.
- **MUST** return 429 JSON with `Retry-After` header when the limit is exceeded.
- **MUST** set the `app.branch_id` + `app.is_admin` GUCs on RLS-protected API routes (via
  `set.api.branch` middleware).
- **MUST NOT** use Sanctum token abilities / scopes — they are not implemented. A token's
  permissions = the user's role.
- **MUST NOT** issue tokens via a web UI — only via the `api:token` artisan command (or
  programmatic `User::generateApiToken()`).
- **SHOULD** rotate tokens periodically (no expiry is enforced today — gap, §13).

## 7. Technical implementation

### 7.1 `ApiAuth` middleware — `laravel/app/Http/Middleware/ApiAuth.php`

Alias: `api.auth`. Optional role args: `->middleware('api.auth:admin')` or
`->middleware('api.auth:admin,manager')`.

**Logic:**
1. Extract bearer token: reads `Authorization` header, requires prefix `Bearer `, returns the
   trimmed remainder.
2. `if ($token === '') return $this->unauthorized('Missing or invalid Authorization header.');`
   → **401 JSON**.
3. `$user = User::findByApiToken($token);` — SHA-256 hash lookup against `users.api_token`,
   requires `is_active=true` AND `deleted_at IS NULL`.
4. `if ($user === null) return $this->unauthorized('Invalid or expired API token.');` → 401.
5. **`Auth::login($user);`** on the **default `web` guard** (NOT the `sanctum` guard) — so RBAC
   checks work identically to web sessions.
6. Optional role enforcement:

```php
if ($roles !== []) {
    $userRole = $user->getRole();
    $passes = ($userRole === 'superadmin')
        || in_array($userRole, $roles, true)
        || (in_array('admin', $roles, true) && $userRole === 'admin');
    if (!$passes) {
        return response()->json([
            'message' => 'Forbidden. Requires role: ' . implode(',', $roles),
        ], 403);
    }
}
```

**401 response shape:**

```json
{
  "message": "Unauthenticated.",
  "detail": "Missing or invalid Authorization header."
}
```

(or `"Invalid or expired API token."`)

### 7.2 `ApiRateLimit` middleware — `laravel/app/Http/Middleware/ApiRateLimit.php`

Alias: `api.rate`. Optional limit arg: `->middleware('api.rate:60')` (60 req/min) or
`->middleware('api.rate:120')`.

**Constants:** `WINDOW_SECONDS = 60`, `KEY_NAMESPACE = 'api_rate:'`.

**Limit resolution (`resolveLimit`):**
1. Middleware parameter (`?string $maxAttempts`) — if provided and > 0, use it.
2. Else `config('api.rate_limit.default', 60)`.
3. Else hardcoded 60.

**Bucket key:**

```php
$token = $this->bearerToken($request);
$ip    = $request->ip() ?? 'unknown';
$identity = $token !== ''
    ? hash('sha256', $token) . ':' . $ip
    : 'anonymous:' . hash('sha256', $ip);
return self::KEY_NAMESPACE . $identity;
```

The token is SHA-256 hashed before going into the Redis key (defense in depth). Anonymous
requests bucket by IP only.

**Increment (`increment`):**
1. **Redis primary:** `$redis->incr($key)` — atomic. On first sighting (`$count === 1`), set TTL
   via `$redis->expire($key, 60)`. Reads TTL via `$redis->ttl($key)`.
2. **Cache fallback:** if Redis throws, marks `$this->redisAvailable = false` for the rest of the
   request, falls back to `Cache::get/put` (file/array). Best-effort, not atomic.

**Response headers (always set):** `X-RateLimit-Limit`, `X-RateLimit-Remaining`,
`X-RateLimit-Reset`.

**On limit exceeded:** **429 JSON**:

```json
{
  "message": "Rate limit exceeded. Maximum 60 requests per minute.",
  "retry_after": 42
}
```

+ `Retry-After: 42` header + `X-RateLimit-Remaining: 0`.

### 7.3 `config/api.php` — rate-limit thresholds

```php
'rate_limit' => [
    'default'   => (int) env('API_RATE_LIMIT', 60),
    'dashboard' => (int) env('API_RATE_LIMIT_DASHBOARD', 120),
    'lookups'   => (int) env('API_RATE_LIMIT_LOOKUPS', 120),
],
```

### 7.4 `User::findByApiToken()` and `generateApiToken()`

```php
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

### 7.5 API routes — `laravel/routes/api.php`

- `GET /api/docs` — public API docs page (`ApiDocController::index`), NOT behind `api.auth` or
  `api.rate`.
- `Route::prefix('v1')->middleware('api.auth')->group(function () { ... });` — all authenticated
  v1 routes.

**Route groups inside v1 (with their middleware stacks):**

| Prefix | Middleware | Notes |
|---|---|---|
| `/branches` | `api.rate:60` (reads), `api.auth:admin,api.rate:60` (writes) | Branch CRUD |
| `/dashboard`, `/dashboard/sales-trend`, `/dashboard/top-products` | `api.rate:120` | Read-only dashboard |
| `/lookups/{branches,warehouses,products,customers,suppliers,ledgers}` | `api.rate:120` | Dropdown data |
| `/sales/cart` | `api.rate:60` (reads), `api.rate:30` (writes) | Sales cart |
| `/sales/invoices` | `api.rate:60` (reads), `api.rate:30` (writes) | Invoices |
| `/sales/challans` | `api.auth:warehouse_manager,dispatcher,manager,admin,api.rate:30` (godown/issue), `api.auth:manager,admin,api.rate:30` (cancel) | Challans (BUG-52 fix noted) |
| `/sales/returns` | `api.rate:30` (writes) | Returns |
| `/sales/payments` | `api.rate:30` (writes) | Customer payments |
| `/sales/commission/*` | `api.auth:admin,api.rate:30` (rule writes), `api.rate:60` (reads) | Commission rules + entries |
| `/stock-take/sessions/*` | `set.api.branch` + `api.rate:60` (reads), `api.rate:30` (writes), `api.auth:admin,manager,api.rate:30` (post/cancel/reverse/re-open) | Stock-take sessions |
| `/warehouse-transfers/*` | `set.api.branch` + `api.rate:60` (reads), `api.auth:manager,admin,api.rate:30` (confirm/cancel) | Warehouse transfers |
| `/stock-adjustments/*` | `set.api.branch` + `api.auth:admin,manager,accountant,api.rate:30` (writes) | Stock adjustments |
| `/branch-demands/*` | `set.api.branch` + `api.auth:admin,manager,warehouse_manager,api.rate:30` (most writes) | Branch demands |

**Versioning:** all authenticated routes use the `v1` prefix. No `v2` exists.

### 7.6 Token scopes/abilities

**NOT FOUND / NOT IMPLEMENTED.** The API does NOT use Sanctum token abilities. Grep confirms:
- No `tokenCan(` calls.
- No `abilities()` calls.
- No `HasApiTokens` trait on `User`.
- No `createToken(` calls (the `User::generateApiToken()` is a custom method, not Sanctum's).

**Role-based access control on the API** is enforced via the `api.auth:role1,role2,...`
middleware parameter — the same 10-role matrix as web. There are no per-token scopes; a token's
permissions = the user's role.

### 7.7 CORS configuration

**NOT FOUND: no `config/cors.php` file exists.** Laravel 11+ removed the default `cors.php`
config; CORS is handled by the framework's built-in defaults. `bootstrap/app.php` does not
configure CORS. The API is intended to be consumed by mobile/AI sidecar clients on the same
network (BDIX); CORS is not a primary concern. If a browser-based SPA ever consumes the API, a
`config/cors.php` will need to be added.

### 7.8 `config/sanctum.php`

**NOT FOUND: no `config/sanctum.php` file exists.** The `'sanctum'` guard declared in
`config/auth.php` is unused. API tokens are managed via `users.api_token` (SHA-256 hashed) and
the `ApiAuth` middleware.

### 7.9 Token issuance — `laravel/app/Console/Commands/GenerateApiToken.php`

Artisan command: `php artisan api:token {username} {--role=}`.

**Behavior:**
1. Case-insensitive username lookup (with `withTrashed()`).
2. Optional role update via the linked Employee (`--role` validated against `config('roles')`).
3. Re-activates the user if `is_active=false` (so the new token works).
4. Generates `$plain = Str::random(64);` (64 chars — note: the `User::generateApiToken()` method
   uses 60 chars; the command overrides to 64).
5. Stores `hash('sha256', $plain)` in `users.api_token`.
6. Prints the plain token to stdout with a warning that it's shown only once.

**No admin UI for token generation** — tokens are issued only via this artisan command (or
programmatically via `User::generateApiToken()`).

## 8. Important database tables

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Holds the hashed API token | `id, api_token (varchar 64, unique, nullable), is_active, deleted_at` |

> `users.api_token` is `NULL` for users who have never been issued a token. `findByApiToken()`
> returns `null` for a NULL `api_token` (the `where('api_token', hash(...))` won't match NULL).

## 9. Related services

- `laravel/app/Http/Middleware/ApiAuth.php` — bearer-token auth.
- `laravel/app/Http/Middleware/ApiRateLimit.php` — Redis-backed rate limiter.
- `laravel/app/Http/Middleware/SetApiBranchContext.php` — branch GUC setter (see
  `branch-context-security.md`).
- `laravel/app/Console/Commands/GenerateApiToken.php` — token issuance.

## 10. Related models

- `laravel/app/Models/User.php` — `generateApiToken()`, `findByApiToken()`, `api_token` (hidden).

## 11. Important workflows

### 11.1 API request → auth → rate-limit → branch-context → handler

```mermaid
sequenceDiagram
    participant C as API Client
    participant RL as ApiRateLimit
    participant AA as ApiAuth
    participant U as User::findByApiToken
    participant SAB as SetApiBranchContext
    participant DB as PostgreSQL (RLS)
    participant H as Route handler

    C->>RL: GET /api/v1/stock-adjustments (Bearer <token>)
    RL->>RL: bucket = sha256(token):ip
    RL->>RL: Redis INCR (atomic)
    alt limited
        RL-->>C: 429 JSON + Retry-After
    end
    RL->>AA: X-RateLimit-* headers set
    AA->>AA: extract Bearer token
    AA->>U: findByApiToken(plain)
    alt !user
        AA-->>C: 401 JSON 'Invalid or expired API token.'
    end
    AA->>AA: Auth::login($user)  ;; web guard
    alt role mismatch
        AA-->>C: 403 JSON 'Forbidden. Requires role: ...'
    end
    AA->>SAB: next(request)
    SAB->>DB: SET app.branch_id = user.branch_id
    SAB->>DB: SET app.is_admin = user.isAdmin
    SAB->>H: next(request)
    H->>DB: query (RLS-enforced)
    DB-->>H: rows for user's branch only
    H-->>C: 200 JSON
```

### 11.2 Token issuance

```mermaid
sequenceDiagram
    actor A as Admin
    participant CLI as artisan api:token
    participant U as User
    participant DB as PostgreSQL

    A->>CLI: php artisan api:token jsmith --role=manager
    CLI->>U: User::where(username) withTrashed
    opt --role provided
        CLI->>U: employee->role = 'manager'; save
    end
    CLI->>U: is_active = true; save
    CLI->>CLI: plain = Str::random(64)
    CLI->>U: api_token = sha256(plain); save
    U->>DB: UPDATE users SET api_token = ...
    CLI-->>A: print plain token (shown once)
```

## 12. Known edge cases

- **API tokens are NOT covered by credential versioning.** `CheckCredentialVersion` is global but
  `Auth::check()` is false for API requests at global-middleware time (bearer auth runs as route
  middleware). `ApiAuth` does not consult `credential_version`. To revoke an API token, regenerate
  `users.api_token` (via `User::generateApiToken()` or the artisan command) or set `api_token =
  NULL`. A password change does NOT invalidate the API token.
- **No token expiry.** `users.api_token` has no `expires_at`. A token is valid until manually
  regenerated or the user is disabled/deleted. (Gap — §13.)
- **One token per user.** `users.api_token` is a single column — a user has at most one active
  token. Regenerating replaces the old one. There is no multi-token / named-token support
  (Sanctum's `personal_access_tokens` would provide this).
- **Token length inconsistency.** `User::generateApiToken()` uses `Str::random(60)`; the artisan
  command uses `Str::random(64)`. Both are stored as a 64-char SHA-256 hex hash, so the column
  length is fine, but the plain-token length varies.
- **`api:token` re-activates disabled users.** The command sets `is_active = true` so the new
  token works. This is intentional (issuing a token implies the user should be active) but could
  surprise an admin who expected to issue a token for a disabled user without enabling them.
- **No CORS config.** Browser-based SPA consumers will hit CORS defaults. Add `config/cors.php`
  if needed.
- **Rate-limit fallback is not atomic.** If Redis is down, `ApiRateLimit` falls back to
  `Cache::get/put` (file/array), which is not atomic under concurrency. A Redis outage could
  briefly allow over-limit traffic. Acceptable for an internal API.
- **`GET /api/docs` is public.** The API docs page is not behind `api.auth` or `api.rate`. It
  must not expose sensitive information (it currently documents endpoints; verify it doesn't leak
  internal paths or example tokens).
- **Anonymous rate-limit bucketing by IP only.** Unauthenticated requests are bucketed by IP —
  behind a NAT/proxy, many clients could share an IP and hit the limit together. The
  `X-Forwarded-For` header is trusted via `trustProxies(at: '*')` in `bootstrap/app.php`, so the
  real client IP is used when behind the Nginx reverse proxy.

## 13. Future improvements

- **Migrate to Sanctum `personal_access_tokens`** (or remove the dead `sanctum` guard). Sanctum
  gives named tokens, per-token abilities, expiry, and revocation — all of which the current
  single-column approach lacks. This is the single biggest API-security improvement available.
- **Add token expiry + rotation.** Either a `api_token_expires_at` column or a full
  `personal_access_tokens` table with `expires_at`.
- **Cover API tokens in credential versioning.** Either have `ApiAuth` consult
  `credential_version` (store it on the token row) or revoke the API token on password change.
- **Add `config/cors.php`** if a browser SPA will consume the API.
- **Add an admin UI for token management** (issue, list, revoke) so non-CLI admins can manage
  tokens.
- **Standardize token length** (60 vs 64) — pick one.
- **Add a `GET /api/v1/me` endpoint** so clients can verify a token and discover the user's role
  + branch without a separate lookup.
- **Document the API** at `laravel/docs/api/API_REFERENCE.md` and link from `../api/` (Phase 17).
- **Consider per-route rate-limit tuning** — writes are 30/min, reads 60–120/min today; review
  whether these match real client needs.
