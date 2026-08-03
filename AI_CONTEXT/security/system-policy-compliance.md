# System Policy & Compliance

> **Module:** Security / Compliance
> **Audience:** Engineers + AI assistants + security reviewers + compliance officers
> **Status:** Draft
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Compliance/SystemPolicyService.php` + `laravel/app/Http/Middleware/CheckSystemPolicy.php` + `laravel/app/Models/SystemPolicy.php` + `config/accounting.php`

## 1. What is it?

The **system policy** framework is the ERP's compliance switchboard. It defines a small set of
operational **modes** (currently `NORMAL` and `INVESTIGATION`; `READ_ONLY`, `MAINTENANCE`,
`EMERGENCY` are declared as future). A superadmin activates a mode from the admin panel; the
active mode is cached (5 minutes) and shared with every view + every request via the
`CheckSystemPolicy` middleware. The intended effect of `INVESTIGATION` mode is to clamp
date-bounded model queries to the current fiscal year and surface a banner — but the hard
enforcement layer (the `ApplySystemPolicyScope` trait) is scaffolded and **not yet wired to any
model**. Today, investigation mode is policy + audit + UI; the DB-level enforcement is partial.

This file also documents the two accounting-policy config flags that gate financial operations:
`PERIOD_CLOSE_ADMIN_OVERRIDE` and `GL_RECONCILIATION_TOLERANCE`.

## 2. Why does it exist?

- The business needs a documented, audited way to put the ERP into a "we are investigating
  something, don't let anyone touch historical data" state — e.g. during a stock-variance
  investigation or a year-end audit freeze.
- The activation/deactivation must be **superadmin-only** and **fully audit-logged** (who, when,
  why, from where).
- The accounting flags exist to make two business decisions configurable without code changes:
  (a) can an admin post to a closed period? (b) what sub-ledger ↔ GL drift is acceptable as
  rounding noise?

## 3. When is it used?

- **Superadmin activates INVESTIGATION** from `/admin/compliance` — typically before a stock take,
  a year-end close, or a forensic review.
- **Every web request** — `CheckSystemPolicy` middleware loads the active policy (cached) and
  shares `systemPolicy`, `systemPolicyMode`, `isInvestigation` with all views.
- **Auto-deactivation** — if `expires_at` is in the past, the middleware deactivates the policy on
  the next request.
- **Accounting flag reads** — the period-close service checks
  `config('accounting.period_close_admin_override')` before allowing a post to a closed period;
  the reconciliation service checks `config('accounting.gl_reconciliation_tolerance')` when
  comparing sub-ledger to GL balances.

## 4. Who uses it?

- **Superadmin** — the only role that can change the system policy (Gate `manage-system-policy`).
- **All users** — see the investigation banner (Blade `$isInvestigation` shared var).
- **Accountants** — consume the accounting flags indirectly via the period-close and
  reconciliation services.
- **System/automated** — `CheckSystemPolicy` runs on every request.

## 5. Related modules

- `audit-trails.md` — `system_policy_activate/deactivate` audit actions.
- `rbac-roles-permissions.md` — the `manage-system-policy` Gate (superadmin-only).
- `branch-context-security.md` — orthogonal concern (branch isolation, not mode).
- `../accounting/fiscal-year-period-close.md` (Phase 6, pending) — period-close logic that reads
  the override flag.
- `../accounting/subledger-reconciliation.md` (Phase 6, pending) — reads the tolerance flag.

## 6. Business rules

- **MUST** make system-policy activation/deactivation **superadmin-only**. The Gate
  `manage-system-policy` enforces this; the controller re-checks `auth()->user()?->isSuperadmin()`
  as defense-in-depth.
- **MUST** audit-log every activation and deactivation to `user_audit_log` (action
  `system_policy_activate` / `system_policy_deactivate`) with reason, previous mode, new mode,
  actor, IP, user agent.
- **MUST** allow only one active policy at a time (DB partial unique index
  `system_policies_one_active WHERE is_active = true`).
- **MUST** cache the active policy (5 min, key `system_policy:active`) and invalidate on
  activate/deactivate.
- **MUST** auto-deactivate a policy whose `expires_at` is past (handled by `CheckSystemPolicy`).
- **MUST** dispatch `SystemPolicyChanged` on activate/deactivate (for future listeners).
- **MUST** require a reason (10–500 chars) for both activate and deactivate.
- **MUST NOT** allow non-superadmin to change the policy. (Gap: the route group has no
  `role:superadmin` middleware — it relies on the in-controller check. See §13.)
- **SHOULD** wire `ApplySystemPolicyScope` to date-bounded models so INVESTIGATION mode actually
  clamps queries (currently unused — see §12).

### Accounting flags

- **`PERIOD_CLOSE_ADMIN_OVERRIDE`** (default `false`): when `true`, admin + superadmin can post
  journal entries to closed accounting periods (the override is audit-logged). When `false`, all
  users — including admin — are blocked; the period must be reopened first.
- **`GL_RECONCILIATION_TOLERANCE`** (default `0.02` Taka): maximum acceptable difference between a
  sub-ledger and its GL control account during reconciliation. Differences at or below this
  threshold are treated as balanced (rounding noise).

## 7. Technical implementation

### 7.1 `SystemPolicy` model — `laravel/app/Models/SystemPolicy.php`

Table: `system_policies`. Fillable: `mode, is_active, activated_by, activated_at, deactivated_by,
deactivated_at, reason, expires_at, metadata, activation_source, ip_address, user_agent`.

Modes:

```php
public const MODES = [
    'NORMAL'        => 'Normal Operation',
    'INVESTIGATION' => 'Investigation Mode',
    'READ_ONLY'     => 'Read-Only Mode (Future)',
    'MAINTENANCE'   => 'Maintenance Mode (Future)',
    'EMERGENCY'     => 'Emergency Lockdown (Future)',
];
```

Activation sources:

```php
public const ACTIVATION_SOURCES = [
    'admin_panel' => 'Admin Panel',
    'qr_code'     => 'QR Code (Future)',
    'mobile_app'  => 'Mobile App (Future)',
    'api'         => 'API (Future)',
    'scheduled'   => 'Scheduled Task (Future)',
];
```

Helpers: `scopeActive`, `getModeLabelAttribute`, `isInvestigation()`, `isNormal()`,
`getFiscalYearStart()`, `getFiscalYearEnd()`.

**Bangladesh fiscal year default** (when `metadata` missing): July 1 → June 30.

```php
$now = now();
$year = $now->month >= 7 ? $now->year : $now->year - 1;
return "{$year}-07-01";
```

Overridable via `$metadata['fiscal_year_start']` / `$metadata['fiscal_year_end']`.

### 7.2 `system_policies` table — migration `2025_01_07_000001`

```php
Schema::create('system_policies', function (Blueprint $table) {
    $table->id();
    $table->string('mode', 30)->default('NORMAL')->index();
    $table->boolean('is_active')->default(false)->index();
    $table->foreignId('activated_by')->nullable();
    $table->timestamp('activated_at')->nullable();
    $table->foreignId('deactivated_by')->nullable();
    $table->timestamp('deactivated_at')->nullable();
    $table->text('reason')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->jsonb('metadata')->nullable();
    $table->string('activation_source', 30)->default('admin_panel');
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent', 255)->nullable();
    $table->timestamps();
});

// Partial unique index: only one active policy at a time.
DB::statement("CREATE UNIQUE INDEX system_policies_one_active ON system_policies (is_active) WHERE is_active = true");
```

### 7.3 `SystemPolicyService` — `laravel/app/Services/Compliance/SystemPolicyService.php`

Singleton (bound in `AppServiceProvider::register()`).

Constants: `CACHE_KEY = 'system_policy:active'`, `CACHE_TTL = 300` (5 min).

Methods:

- `getCurrentPolicy(): ?SystemPolicy` — `Cache::remember('system_policy:active', 300, fn =>
  SystemPolicy::active()->first())`.
- `getCurrentMode(): string` — `return $this->getCurrentPolicy()?->mode ?? 'NORMAL';`
- `isInvestigation(): bool` — `=== 'INVESTIGATION'`.
- `isNormal(): bool` — `=== 'NORMAL'`.
- `activate(string $mode, int $activatedBy, string $reason, array $metadata = [],
  string $activationSource = 'admin_panel', ?\DateTime $expiresAt = null): SystemPolicy`:
  - Validates `mode` against `SystemPolicy::MODES` (throws `InvalidArgumentException`).
  - In a `DB::transaction`: deactivates the previous policy (`is_active=false`,
    `deactivated_by`, `deactivated_at`), creates a new row with `is_active=true`, audit-logs
    `system_policy_activate`, forgets the cache, re-caches the new policy, dispatches
    `SystemPolicyChanged`.
- `deactivate(int $deactivatedBy, string $reason): bool` — symmetric.
- `getFiscalYearStart(): ?string` / `getFiscalYearEnd(): ?string` — null in NORMAL mode; in
  INVESTIGATION mode delegate to `SystemPolicy::getFiscalYearStart()`.
- `getHistory(int $limit = 50): Collection` — for the admin UI.
- `private writeAuditLog(int $userId, string $previousMode, string $newMode, string $reason,
  string $action)` — inserts into `user_audit_log`.

### 7.4 `CheckSystemPolicy` middleware — `laravel/app/Http/Middleware/CheckSystemPolicy.php`

Appended globally in `bootstrap/app.php`.

```php
public function handle(Request $request, Closure $next): Response {
    $policy = $this->policyService->getCurrentPolicy();
    $mode = $this->policyService->getCurrentMode();
    app()->instance('system_policy', $policy);
    app()->instance('system_policy_mode', $mode);
    view()->share('systemPolicy', $policy);
    view()->share('systemPolicyMode', $mode);
    view()->share('isInvestigation', $mode === 'INVESTIGATION');

    if ($policy && $policy->expires_at && $policy->expires_at->isPast()) {
        $this->policyService->deactivate(
            $policy->activated_by ?? 1,
            'Policy expired automatically at ' . $policy->expires_at->toISOString()
        );
    }
    return $next($request);
}
```

**What it does NOT do:** block requests, restrict writes, or enforce read-only mode. It only loads
+ shares. Actual enforcement is supposed to happen via `ApplySystemPolicyScope` (currently unused
on any model — see §7.6) and via in-controller `$service->isInvestigation()` checks.

### 7.5 `SystemPolicyController` — `laravel/app/Http/Controllers/Admin/SystemPolicyController.php`

Routes (`routes/web.php`):

```php
Route::prefix('admin/compliance')->name('admin.compliance.')->group(function () {
    Route::get('/',             [SystemPolicyController::class, 'index'])->name('index');
    Route::post('/activate',    [SystemPolicyController::class, 'activate'])->name('activate');
    Route::post('/deactivate',  [SystemPolicyController::class, 'deactivate'])->name('deactivate');
});
```

`activate(Request)`:
- Validates: `'mode' => 'required|string|in:NORMAL,INVESTIGATION'`, `'reason' => 'required|string|
  min:10|max:500'`, `'expires_at' => 'nullable|date|after:now'`.
- Superadmin check (defense-in-depth): `if (!auth()->user()?->isSuperadmin()) return
  back()->with('error', 'Only superadmin can change system policy.');`
- If `mode === 'NORMAL'` → `$this->policyService->deactivate(...)`.
- Else → `$this->policyService->activate($mode, auth()->id(), $reason, [], 'admin_panel',
  $expiresAt)`.

`deactivate(Request)`: validates `'reason' => 'required|string|min:10|max:500'`, superadmin
check, then `deactivate(auth()->id(), $reason)`.

> **Gap:** these routes do **not** have `role:superadmin` middleware; they rely solely on the
> in-controller `isSuperadmin()` check. The `manage-system-policy` Gate is defined but not
> consumed by these routes via `@can` or `Gate::allows`. (See §13.)

### 7.6 `ApplySystemPolicyScope` trait — `laravel/app/Traits/ApplySystemPolicyScope.php`

**Defined but NOT used on any model** (grep across `app/Models/` returns zero hits).

```php
public static function bootApplySystemPolicyScope(): void {
    static::addGlobalScope('system_policy', function (Builder $builder) {
        $service = app(SystemPolicyService::class);
        if (!$service->isInvestigation()) return; // NORMAL mode — no restriction.
        $fiscalStart = $service->getFiscalYearStart();
        if ($fiscalStart) {
            $column = (new static())->policyDateColumn;
            $builder->whereDate($column, '>=', $fiscalStart);
        }
    });
}

public function scopeWithoutPolicy(Builder $query): Builder {
    return $query->withoutGlobalScope('system_policy');
}
```

`$policyDateColumn` default: `'created_at'` (override per-model).

> The trait is scaffolding for future Investigation Mode enforcement at the model layer. Today
> INVESTIGATION mode only affects views (via `$isInvestigation`) and the `SystemPolicyService`
> API.

### 7.7 `SystemPolicyChanged` event — `laravel/app/Events/SystemPolicyChanged.php`

```php
class SystemPolicyChanged {
    use Dispatchable, SerializesModels;
    public function __construct(
        public SystemPolicy $policy,
        public string $previousMode,
        public string $newMode,
        public int $changedBy
    ) {}
}
```

Dispatched by `SystemPolicyService::activate()` and `deactivate()`. **No listeners are
registered** (Laravel 11 auto-discovery; no `#[Listen]` attributes or `ShouldQueue` listeners in
`app/Listeners/`). The event is a future hook for fan-out (e.g. broadcast to all sessions, write a
compliance alert, pause scheduled jobs).

### 7.8 `config/accounting.php` flags

```php
'period_close_admin_override'  => (bool) env('PERIOD_CLOSE_ADMIN_OVERRIDE', false),
'gl_reconciliation_tolerance'  => (float) env('GL_RECONCILIATION_TOLERANCE', 0.02),
```

**`period_close_admin_override`** (default `false`):
- `true`: admin + superadmin can post journal entries to closed accounting periods
  (`posting_date <= closed_through_date`). The override is audit-logged.
- `false`: ALL users (including admin) are blocked from posting to closed periods — the period
  must be reopened first.
- Mirrors legacy `PERIOD_CLOSE_ADMIN_OVERRIDE=false`.

**`gl_reconciliation_tolerance`** (default `0.02`):
- Maximum acceptable difference (in Taka) between sub-ledger and GL control account balances
  during reconciliation. Differences at or below this threshold are considered "balanced"
  (rounding noise).
- Mirrors legacy `GL_RECONCILIATION_TOLERANCE=0.02`.

**Duplication note:** the same `gl_reconciliation_tolerance` key exists in BOTH
`config/accounting.php` and `config/app.php` (line 27). Code that reads either config key gets the
same value. This is intentional redundancy (legacy code paths read `config('app....')`) but should
be consolidated eventually.

## 8. Important database tables

| Table | Purpose | Key columns |
|---|---|---|
| `system_policies` | The mode ledger (one active row at a time) | `id, mode, is_active, activated_by, activated_at, deactivated_by, deactivated_at, reason, expires_at, metadata jsonb, activation_source, ip_address, user_agent` + partial unique index `system_policies_one_active WHERE is_active = true` |
| `user_audit_log` | `system_policy_activate` / `system_policy_deactivate` rows | (see `audit-trails.md`) |

## 9. Related services

- `laravel/app/Services/Compliance/SystemPolicyService.php` — the singleton.
- `laravel/app/Services/Auth/UserAuditLogger.php` — invoked by the service for audit rows.

## 10. Related models

- `laravel/app/Models/SystemPolicy.php`.
- `laravel/app/Traits/ApplySystemPolicyScope.php` (unused — scaffolding).

## 11. Important workflows

### 11.1 Activate INVESTIGATION mode

```mermaid
sequenceDiagram
    actor S as Superadmin
    participant C as SystemPolicyController
    participant SPS as SystemPolicyService
    participant DB as PostgreSQL
    participant Cache as Cache (redis)
    participant AU as UserAuditLogger
    participant E as SystemPolicyChanged event

    S->>C: POST /admin/compliance/activate {mode:'INVESTIGATION', reason, expires_at?}
    C->>C: validate (mode in NORMAL,INVESTIGATION; reason 10-500)
    C->>C: isSuperadmin() check
    C->>SPS: activate(mode, userId, reason, [], 'admin_panel', expires_at)
    SPS->>DB: DB::transaction
    DB->>DB: UPDATE system_policies SET is_active=false WHERE is_active=true (deactivate prev)
    DB->>DB: INSERT INTO system_policies (mode='INVESTIGATION', is_active=true, ...)
    SPS->>AU: writeAuditLog(userId, prevMode, 'INVESTIGATION', reason, 'system_policy_activate')
    SPS->>Cache: forget('system_policy:active')
    SPS->>Cache: put('system_policy:active', newPolicy, 300)
    SPS->>E: dispatch SystemPolicyChanged
    SPS-->>C: new SystemPolicy
    C-->>S: redirect back (success)
```

### 11.2 Per-request policy load

```mermaid
flowchart TD
    R[Request] --> M[CheckSystemPolicy middleware]
    M --> SPS[SystemPolicyService::getCurrentPolicy<br/>Cache::remember 5min]
    SPS --> APP[app->instance('system_policy', ...)]
    APP --> V1[view share systemPolicy]
    V1 --> V2[view share systemPolicyMode]
    V2 --> V3[view share isInvestigation]
    V3 --> EXP{policy.expires_at past?}
    EXP -- yes --> DEACT[deactivate(activated_by, 'expired')]
    EXP -- no --> NEXT[next(request)]
    DEACT --> NEXT
```

## 12. Known edge cases

- **`ApplySystemPolicyScope` is unused.** INVESTIGATION mode does NOT currently clamp model
  queries by fiscal year. The trait exists but no model calls `use ApplySystemPolicyScope;`. The
  hard enforcement layer is scaffolding only. (See §13.)
- **No write-blocking in INVESTIGATION mode.** Destructive operations (DELETE/UPDATE on financial
  records) are NOT blocked during INVESTIGATION today. The compliance gate is policy + audit +
  UI banners, not DB enforcement.
- **Route group lacks `role:superadmin` middleware.** The `/admin/compliance/*` routes rely on the
  in-controller `isSuperadmin()` check only. Defense-in-depth would add the middleware. (See §13.)
- **`SystemPolicyPolicy` class is dead code.** Defined in `app/Policies/` but never registered;
  the `manage-system-policy` Gate uses a closure. Don't assume `$this->authorize('manage', ...)`
  resolves to the policy class.
- **`SystemPolicyChanged` has no listeners.** The event fires but nothing consumes it. Future
  hooks (broadcast, alert, job pause) would attach here.
- **Auto-deactivation uses `activated_by ?? 1`** as the deactivator for expired policies. If the
  original activator was deleted, this falls back to user id 1 (the seeded superadmin). The audit
  row records the reason as "Policy expired automatically at <ts>".
- **`gl_reconciliation_tolerance` is duplicated** in `config/accounting.php` and `config/app.php`.
  Both must be updated together (or consolidate).
- **`expires_at` is optional.** A policy with no `expires_at` never auto-deactivates — the
  superadmin must remember to deactivate manually.
- **Cache stale window:** the active policy is cached for 5 minutes. An activation is invalidated
  immediately (the service forgets + re-puts), but if the cache write fails, other app instances
  may serve the stale cached policy for up to 5 minutes.

## 13. Future improvements

- **Wire `ApplySystemPolicyScope`** to date-bounded models (`SalesInvoice`, `PurchaseReceive`,
  `JournalEntry`, etc.) so INVESTIGATION mode actually clamps reads to the current fiscal year.
- **Add `role:superadmin` middleware** to the `/admin/compliance/*` route group (or consume the
  `manage-system-policy` Gate via `@can` / `Gate::authorize`).
- **Register `SystemPolicyPolicy`** (or delete it) to remove dead-code ambiguity.
- **Add a listener for `SystemPolicyChanged`** that broadcasts a toast to all active sessions
  (via the realtime-events channel) so users see the mode change immediately.
- **Implement `READ_ONLY` / `MAINTENANCE` / `EMERGENCY` modes** if the business needs them
  (currently declared but unused).
- **Add a non-expiring-policy alert** — surface policies with `expires_at IS NULL` in the admin UI
  so they're not forgotten.
- **Consolidate `gl_reconciliation_tolerance`** into one config file.
- **Make `expires_at` required** for INVESTIGATION mode (force a review point).
