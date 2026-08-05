# System Policy & Compliance — Phase 14 (re-audited)

> **Module:** Security / Compliance
> **Audience:** Engineers + AI assistants + security reviewers + compliance officers
> **Status:** Draft — pending compliance review (**business-critical** — investigation mode is the
> ERP's documented "freeze historical data during an audit" switch, but per G13 below it currently
> has NO business-logic consumer, so activating it does nothing except change the compliance admin
> page banner + write an audit log row. Two HIGH gaps (G9 NO RLS, G13 no enforcement) mean the
> subsystem is only partially production-ready.)
> **Last reviewed:** Phase 14 (re-audit — source code unchanged since commit `e4ed955` "Phase 11:
> Compliance & Investigation Framework" + one fix commit `83e78db`; no drift in crown-jewel method
> bodies. 13 of 26 verification items CONFIRMED, 4 CHANGED/PARTIAL, 7 NEW findings added.)
> **Source of truth:** this file + `laravel/app/Services/Compliance/SystemPolicyService.php` +
> `laravel/app/Http/Middleware/CheckSystemPolicy.php` + `laravel/app/Models/SystemPolicy.php` +
> `laravel/app/Traits/ApplySystemPolicyScope.php` + `laravel/app/Events/SystemPolicyChanged.php` +
> `laravel/app/Policies/SystemPolicyPolicy.php` + `laravel/app/Http/Controllers/Admin/SystemPolicyController.php`
> + `laravel/app/Providers/AppServiceProvider.php` (Gate + singleton) + `config/accounting.php` +
> `config/app.php` + migration `laravel/database/migrations/2025_01_07_000001_create_system_policies_table.php`
> + PG trigger in `laravel/database/migrations/2025_01_21_000001_add_listen_notify_triggers.php`.

## 1. What is it?

The **system policy** framework is the ERP's compliance switchboard. It defines a small set of
operational **modes** (currently `NORMAL` and `INVESTIGATION`; `READ_ONLY`, `MAINTENANCE`,
`EMERGENCY` are declared as future). A superadmin activates a mode from the admin panel; the
active mode is cached (5 minutes) and shared with every view + every request via the
`CheckSystemPolicy` middleware. The intended effect of `INVESTIGATION` mode is to clamp
date-bounded model queries to the current fiscal year and surface a banner — but the hard
enforcement layer (the `ApplySystemPolicyScope` trait) is scaffolded and **not yet wired to any
model**, and a re-audit in Phase 14 confirmed that **no business-logic consumer of
`isInvestigation()` exists anywhere in `app/`** (G13). Today, investigation mode is policy + audit
+ a single admin-page banner (NOT a global banner — G14 corrects the Phase 5 claim); the DB-level
enforcement is effectively absent.

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
- **All users** — would see the investigation banner IF one existed. **Phase 14 re-audit
  correction (G14):** the `$isInvestigation` shared var is consumed ONLY in
  `admin/compliance/index.blade.php` (the admin page itself). Neither `layouts/admin.blade.php`
  nor `components/layouts/erp.blade.php` renders a global banner. Regular users see NO indication
  that INVESTIGATION mode is active.
- **Accountants** — consume the accounting flags indirectly via the period-close and
  reconciliation services (`JournalPostingService::validatePeriod` reads
  `period_close_admin_override`; `ReconciliationService` + `RunningBalanceReconcile` +
  `SubLedgerReconcile` read `gl_reconciliation_tolerance`).
- **System/automated** — `CheckSystemPolicy` runs on every request. No scheduled job touches
  `system_policies` (auto-deactivation is request-driven only).

## 5. Related modules

- `audit-trails.md` — `system_policy_activate/deactivate` audit actions. **Phase 14 note (G15):**
  the `period_close_override` action (written by `JournalPostingService::validatePeriod` L319 when
  an admin bypasses the period-close check) is NOT yet listed in `audit-trails.md` §13 tracked-actions
  — should be added in a future revision.
- `rbac-roles-permissions.md` — the `manage-system-policy` Gate (superadmin-only). The Gate IS
  consumed via `@can('manage-system-policy')` in `layouts/admin.blade.php:306` +
  `components/layouts/erp.blade.php:329` to gate the Compliance menu link (Phase 14 confirmed —
  Phase 5 doc said "not consumed", which was inaccurate).
- `branch-context-security.md` — orthogonal concern (branch isolation, not mode).
- `../accounting/fiscal-year-period-close.md` (Phase 6) — period-close logic that reads the
  `period_close_admin_override` flag (`JournalPostingService::validatePeriod` L302-345).
- `../accounting/subledger-reconciliation.md` (Phase 6) — reads the `gl_reconciliation_tolerance`
  flag (`SubLedgerReconcile.php:133,183,227` via `config('accounting...')`).
- `../finance/consolidation-intercompany.md` (Phase 13) §13 compliance matrix — shares the
  recurring G4 (fn_financial_audit_trigger NOT attached) + G3/G8 (RLS gaps) pattern with this file.
  `system_policies` fails 2 of the 2 applicable compliance checks (audit trigger + RLS) — see §7.11.
- `../finance/branch-demand.md` (Phase 13) §13 compliance matrix — same recurring pattern.
- `../workflows/approval-workflow.md` (Phase 14 sibling) — the three config-storage strategies
  documented there (file-backed `config/stock_adjustment.php` vs DB-backed `stock_take_policies`
  table vs hybrid `config/damage.php`) are paralleled here by the `config/accounting.php` flags
  (file-backed) approach.

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

**Phase 14 re-audit (G12):** the PG trigger `rcerp_notify_system_policy()` (defined in
`2025_01_21_000001_add_listen_notify_triggers.php:340-358`, attached `AFTER UPDATE` on
`system_policies` at L371) is ALSO effectively dead. The trigger fires only when
`NEW.mode IS DISTINCT FROM OLD.mode`. But `SystemPolicyService::activate()` does (a) `UPDATE` the
previous policy setting `is_active=false, deactivated_by, deactivated_at` (**does NOT change
`mode`**), then (b) `INSERT` a new policy with the new mode (`INSERT`, not `UPDATE` — triggers
fire `AFTER INSERT` only if declared, which this one is not). Neither operation triggers the
function. `deactivate()` similarly only `UPDATE`s `is_active/deactivated_by/deactivated_at` — no
mode change. The JS SSE handler in `public/assets/js/notification.js:144-152` listens for
`rcerp_system` events but will NEVER receive one from real policy activations. The realtime
broadcast for system policy changes is dead code.

### 7.8 `AppServiceProvider` Gate + singleton — `laravel/app/Providers/AppServiceProvider.php`

**Singleton binding** (L32):
```php
$this->app->singleton(\App\Services\Compliance\SystemPolicyService::class);
```

**Gate definition** (L49-52):
```php
// Phase 11: Register the system policy gate.
\Illuminate\Support\Facades\Gate::define('manage-system-policy', function (\App\Models\User $user) {
    return $user->isSuperadmin();
});
```

> **Note:** `SystemPolicyPolicy` (§7.6 above) is NEVER registered via
> `Gate::policy(SystemPolicy::class, SystemPolicyPolicy::class)`. The Gate uses a closure. This is
> dead-code ambiguity — `SystemPolicyPolicy::manage()` and the Gate closure have identical logic
> (`return $user->isSuperadmin();`) but only the closure is consulted.

### 7.9 `config/accounting.php` flags

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
same value (both read the same env var `GL_RECONCILIATION_TOLERANCE`). This is intentional
redundancy (legacy code paths read `config('app....')`) but should be consolidated eventually.

**Phase 14 consumer-map (G6 expansion):** the consumers are inconsistent in WHICH key they read:
- `ReconciliationService.php:41` reads `config('app.gl_reconciliation_tolerance')`.
- `RunningBalanceReconcile.php:49` reads `config('app.gl_reconciliation_tolerance')`.
- `SubLedgerReconcile.php:133,183,227` reads `config('accounting.gl_reconciliation_tolerance')`.

Two different config keys for the same env var — a code-consistency issue, not just redundancy.

### 7.10 `period_close_admin_override` consumer — `JournalPostingService::validatePeriod`

`laravel/app/Services/Accounting/JournalPostingService.php:302-345`:

```php
public function validatePeriod(string $postingDate, int $branchId): void
{
    $closedThrough = DB::table('accounting_periods')
        ->where('branch_id', $branchId)
        ->value('closed_through_date');

    if (!$closedThrough || $postingDate > $closedThrough) {
        return; // Period is open for this date.
    }

    // P2-1: Admin bypass — check config + user role.
    if (config('accounting.period_close_admin_override', false)) {
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user && $user->isAdmin()) {
            // Log the override for audit trail.
            DB::table('user_audit_log')->insert([
                'user_id' => $user->id,
                'action' => 'period_close_override',
                'target_user_id' => null,
                'branch_id' => $branchId,
                'details' => json_encode([
                    'posting_date' => $postingDate,
                    'closed_through' => $closedThrough,
                    'branch_id' => $branchId,
                    'reason' => 'Admin override: posting to closed period',
                ]),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? mb_substr(request()?->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
            return; // Bypass — admin allowed to post.
        }
    }

    throw new \RuntimeException(
        "Posting date {$postingDate} falls within a closed accounting period "
        . "(closed through {$closedThrough} for branch {$branchId}). "
        . "Reopen the period or use a later date."
        . (config('accounting.period_close_admin_override', false)
            ? ' (Admin override is enabled — contact an admin.)'
            : '')
    );
}
```

> **G15:** the `period_close_override` audit action written at L319 is NOT listed in
> `audit-trails.md` §13 tracked-actions. Should be added in a future revision of that doc.

### 7.11 Compliance matrix for `system_policies` (Phase 14 re-audit)

Phase 13's `consolidation-intercompany.md` + `branch-demand.md` established a 7-check compliance
pattern. Applying the 2 applicable checks to `system_policies`:

| # | Check | Status | Evidence |
|---|---|---|---|
| 1 | All GL postings route through `JournalPostingService` | N/A — `system_policies` is not a GL-posting table | — |
| 2 | All reversals route through `JournalReversalService` | N/A — no GL reversals | — |
| 3 | Period-close enforced | N/A — `system_policies` writes are not journal entries | — |
| 4 | `fn_financial_audit_trigger` attached | ❌ **NOT CONFIRMED** | `02_accounting.sql:446-455` lists 10 tables with the trigger attached. `system_policies` is NOT among them. **G10.** |
| 5 | RLS enabled + per-verb policies | ❌ **NOT CONFIRMED** | `07_views_triggers_constraints.sql:509-850` enables RLS on 36 tables. `system_policies` is NOT among them. **G9.** Worse than the consolidation tables (which have admin-only RLS) — `system_policies` has NO RLS at all. |
| 6 | `EnforceBranchIsolation::inferTableFromUri` covers URIs | N/A — `system_policies` is not branch-scoped (global singleton table) | — |
| 7 | `BranchScope` global scope on models | N/A — same reason | — |

So `system_policies` fails 2 of the 2 applicable checks (audit trigger + RLS), matching the
recurring cross-phase gap pattern documented in Phase 13.

## 8. Important database tables

| Table | Purpose | Key columns |
|---|---|---|
| `system_policies` | The mode ledger (one active row at a time) | `id, mode, is_active, activated_by, activated_at, deactivated_by, deactivated_at, reason, expires_at, metadata jsonb, activation_source, ip_address, user_agent` + partial unique index `system_policies_one_active WHERE is_active = true` |
| `user_audit_log` | `system_policy_activate` / `system_policy_deactivate` rows | (see `audit-trails.md`) |

## 9. Related services

- `laravel/app/Services/Compliance/SystemPolicyService.php` — the singleton.
- `laravel/app/Services/Auth/UserAuditLogger.php` — **Phase 14 re-audit correction (G16):** this
  service exists but is **NOT** invoked by `SystemPolicyService`. The Phase 5 doc claimed it was;
  that was inaccurate. `SystemPolicyService::writeAuditLog` (L142-158) writes DIRECTLY via
  `DB::table('user_audit_log')->insert(...)`. This means the dual-write (PG + file) that
  `UserAuditLogger` provides is bypassed for system policy changes — only the PG insert happens,
  no file log. This is a defense-in-depth gap (the file log would survive even if the PG table
  were compromised).

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
    APP --> V1[view share systemPolicy - admin page only]
    V1 --> V2[view share systemPolicyMode - admin page only]
    V2 --> V3[view share isInvestigation - admin page only<br/>G14: NO global banner]
    V3 --> EXP{policy.expires_at past?}
    EXP -- yes --> DEACT[deactivate(activated_by ?? 1, 'expired')<br/>G7: fallback to user id 1]
    EXP -- no --> NEXT[next request<br/>G13: NO business-logic consumer checks isInvestigation]
    DEACT --> NEXT
```

> **Phase 14 re-audit note (UPDATED — AUDIT-TRAIL-2 + AUDIT-TRAIL-3):** the
> middleware loads + shares + auto-deactivates. As of AUDIT-TRAIL-2 (G-171),
> the `ApplySystemPolicyScope` trait is wired into 6 transactional document
> models (read-side clamp). As of AUDIT-TRAIL-3 (G-172 + G-175), the
> `BlockWritesDuringInvestigation` middleware + the
> `SystemPolicyService::assertWriteAllowed()` hook in
> `JournalPostingService::createJournalEntry()` provide write-side
> enforcement. The shared `$isInvestigation` var is still consumed ONLY by
> `admin/compliance/index.blade.php` (G14 — no global banner — remains
> open). The original "NO downstream business logic consults
> `isInvestigation()`" claim is now stale — see G1/G2/G13 for the current
> enforcement posture.

## 12. Known edge cases

### G1 — `ApplySystemPolicyScope` is unused (MAJOR)

> ✅ **RESOLVED — AUDIT-TRAIL-2 (commit pending).** The trait is now wired into
> 6 core date-bounded transactional document models, each overriding
> `policyDateColumn()` to declare its primary business date column:
>   - `SalesInvoice` → `invoice_date` (the table's RANGE partition key, so
>     INVESTIGATION-mode clamping also enables partition pruning — a bonus).
>   - `SalesReturn` → `return_date`
>   - `PurchaseReceive` → `receive_date`
>   - `PurchaseReturn` → `return_date`
>   - `CustomerPayment` → `payment_date`
>   - `SupplierPayment` → `payment_date`
>
> In NORMAL mode (the default): the global scope returns early — zero
> behavioral change, zero query overhead beyond a cached `isInvestigation()`
> lookup (the `CheckSystemPolicy` middleware already warms
> `system_policy:active` in the cache on every web request; console/queue
> contexts warm it on first query).
> In INVESTIGATION mode: each query on a traited model gets
> `WHERE <date_col> >= <fiscal_year_start>` (Bangladesh FY July 1 → June 30,
> or the `metadata.fiscal_year_start` override set at activation time). This
> is the intended forensic posture — "all users (including superadmin) see
> only current fiscal year data."
>
> **Trait refactor (PHP 8.2 compatibility):** the trait's per-model date
> column was originally a `protected string $policyDateColumn = 'created_at'`
> property that models would redeclare with their own value. PHP < 8.3
> forbids redeclaring a trait property with a different initial value (fatal
> error); this project targets `php: ^8.2`, so the property approach was
> unusable. Refactored to an overridable **method**
> `protected function policyDateColumn(): string` (default `'created_at'`)
> — method override is always allowed in PHP. Also added a fail-open
> `try/catch` around the scope body: if the policy service / cache / DB is
> unavailable (cache outage, edge-case bootstrap), the scope logs a warning
> and applies NO restriction rather than breaking every Eloquent query on the
> 6 traited models (worst case = INVESTIGATION mode temporarily does not
> clamp reads, which is preferable to a total query failure).
>
> **Scope decision — GL plumbing EXCLUDED:** the trait was intentionally NOT
> wired to `JournalEntry` / `JournalLine` / `ManualJournal` (the GL plumbing)
> or `StockTransaction` (the SSOT inventory ledger) in this wave. Rationale:
> those tables feed opening-balance, trial-balance, reconciliation, and
> stock-valuation computations that REQUIRE full history; clamping them in
> INVESTIGATION mode would silently break those computations unless every
> consumer is audited and retrofitted with `withoutPolicy()` where needed
> (a large, separate effort). The 6 transactional DOCUMENT models wired here
> are the primary "business activity" records that INVESTIGATION mode is most
> meant to restrict, and their Eloquent consumers are list/detail views where
> current-FY clamping is the desired forensic behavior. The GL-plumbing +
> MV/CTE report paths are unaffected (they read via raw SQL / materialized
> views, not Eloquent — global scopes only apply to Eloquent queries).
>
> **Bypass for audit/admin views:** consumers that need full history during
> an investigation (e.g. a superadmin audit page) can use
> `Model::withoutPolicy()->get()` (the `scopeWithoutPolicy` scope removes the
> `system_policy` global scope for that query).
>
> **Follow-up (G-175, resolved in AUDIT-TRAIL-3):** this wiring made
> INVESTIGATION mode restrict READS on the 6 transactional document models.
> G-175 ("INVESTIGATION mode has NO business-logic consumer") — WRITE-side
> enforcement — has since been resolved in AUDIT-TRAIL-3 by (1) the
> `BlockWritesDuringInvestigation` HTTP middleware (G-172) and (2)
> `SystemPolicyService::assertWriteAllowed()` called from
> `JournalPostingService::createJournalEntry()` (the GL chokepoint).
> Read-side clamping (G-171) + write-side enforcement (G-172/G-175) together
> make INVESTIGATION mode fully functional.

- **Original evidence:** `ApplySystemPolicyScope.php:29-63`; grep `use
  ApplySystemPolicyScope` across `app/Models/` returned 0 hits.
- **Impact (historical):** INVESTIGATION mode did NOT clamp model queries by
  fiscal year. The trait existed but no model used it. The hard enforcement
  layer was scaffolding only — activating INVESTIGATION mode in production
  did NOTHING to reads (all financial operations continued to see full
  history).
- **Fix (done):** refactored the trait (property → method + fail-open
  try/catch) + wired it into 6 core transactional document models with
  per-model `policyDateColumn()` overrides. GL plumbing intentionally
  excluded (see scope-decision note above).

### G2 — No write-blocking in INVESTIGATION mode (MAJOR)

> ✅ **RESOLVED — AUDIT-TRAIL-3.** Two-layer write freeze implemented:
>
>   1. **HTTP layer — `BlockWritesDuringInvestigation` middleware** (new,
>      `app/Http/Middleware/BlockWritesDuringInvestigation.php`). Appended
>      to the global middleware stack in `bootstrap/app.php` AFTER
>      `CheckSystemPolicy` (so `app('system_policy_mode')` is available).
>      When INVESTIGATION mode is active, blocks ALL non-GET/HEAD/OPTIONS
>      requests with `SystemPolicyWriteBlockedException`. Allowlist (URI
>      prefix match): `login`, `logout`, `forgot`, `reset` (auth flows),
>      `admin/compliance*` (so the superadmin can DEACTIVATE investigation
>      mode — otherwise self-inflicted lockout), `api/docs*` (public docs),
>      `up` (health check). Covers BOTH web + API requests (middleware is
>      global).
>
>   2. **Service layer — `SystemPolicyService::assertWriteAllowed()`**
>      (new method). Called from `JournalPostingService::createJournalEntry()`
>      at the top — the single GL chokepoint (`reverseJournalEntry` calls
>      it internally, so reversals are also blocked). Catches writes that
>      bypass HTTP middleware: console commands, queued jobs, scheduled
>      tasks, and any code path that posts a GL entry directly. Fail-open
>      try/catch: if the policy lookup itself throws (cache/DB outage), the
>      method logs a warning and ALLOWS the write rather than breaking
>      every GL posting during a cache outage (mirrors the
>      `ApplySystemPolicyScope` trait fail-open posture from G-171).
>
>   3. **Exception rendering** — `SystemPolicyWriteBlockedException` (new,
>      `app/Exceptions/SystemPolicyWriteBlockedException.php`, mirrors the
>      `WarehouseFrozenForCountException` pattern). Registered in
>      `bootstrap/app.php` exception handler: renders as 422 JSON for
>      API/AJAX callers, redirect-back-with-error for web. In console/queue
>      contexts (no request), the exception propagates as a plain
>      `RuntimeException` — logged + failing the job/command, which is the
>      correct forensic posture (a scheduled job that tries to post during
>      an investigation should fail loudly, not silently skip).
>
> **Forensic posture rationale:** INVESTIGATION mode is "freeze the books" —
> the investigator must examine a stable, uncontaminated data set. Allowing
> writes would (a) contaminate the evidence and (b) defeat the read-side
> clamping done by `ApplySystemPolicyScope` (G-171): if writes were allowed,
> the clamp would hide newly-created rows on the next read, producing
> confusing "I created it but cannot see it" behavior.
>
> **Resolution path for the user:** a superadmin deactivates INVESTIGATION
> mode via `/admin/compliance/deactivate` (allowlisted, so the toggle itself
> is never blocked). This is a soft block — the user escalates by toggling
> the policy off, not by escalating privileges.

- **Original evidence:** `CheckSystemPolicy.php:33-58` — middleware only
  loaded + shared + auto-deactivated; no write enforcement.
- **Impact (historical):** INVESTIGATION mode did NOT block destructive
  operations (DELETE/UPDATE on financial records). The compliance gate was
  policy + audit + a single admin-page banner (NOT a global banner — G14),
  not DB/service enforcement.
- **Fix (done):** two-layer write freeze (HTTP middleware + service-layer
  GL chokepoint hook) + custom exception + exception-handler render.

### G3 — Route group lacks `role:superadmin` middleware (MAJOR)
The `/admin/compliance/*` routes (`routes/web.php:1601-1605`, inside the outer `auth` group at
L90) rely on the in-controller `isSuperadmin()` check only. Defense-in-depth would add the
middleware. The `manage-system-policy` Gate IS consumed via `@can()` in 2 layouts (gating the menu
link — `layouts/admin.blade.php:306` + `components/layouts/erp.blade.php:329`) but NOT via
`Gate::authorize` in the controller or via route middleware.

> ✅ RESOLVED in commit b3a9fd7 — Added `role:superadmin` middleware to the `admin/compliance` prefix group at `routes/web.php:1601`. `EnsureRole` rejects any non-superadmin at the route layer before the controller is invoked; the in-controller `isSuperadmin()` check now serves as defense-in-depth layer 2. Sub-problem A (Session 1, Security/RLS cluster).

> ✅ **RESOLVED — LOW-D.** Dead `SystemPolicyPolicy.php` file deleted. The Gate uses a closure (per §7.6) — the policy class was never registered via `Gate::policy()` and misled readers into thinking `$this->authorize('manage', SystemPolicy::class)` would resolve to it. Grep confirms 0 references to `SystemPolicyPolicy` in `laravel/app/` post-deletion.

### G4 — `SystemPolicyPolicy` class is dead code (LOW)
Defined in `app/Policies/SystemPolicyPolicy.php` but never registered via `Gate::policy()`. The
Gate uses a closure. Don't assume `$this->authorize('manage', SystemPolicy::class)` resolves to
the policy class.
Evidence: `AppServiceProvider.php:50-52`; grep `Gate::policy.*SystemPolicy` returns 0 hits.

### G5 — `SystemPolicyChanged` has no listeners (LOW)
The event fires (L91, L117) but nothing consumes it. Future hooks (broadcast, alert, job pause)
would attach here. No `app/Listeners/` directory; no `#[Listen]` or `#[AsEventListener]`
attributes; no `EventServiceProvider`.

### G6 — `gl_reconciliation_tolerance` is duplicated + consumers inconsistent (LOW)
> ✅ **RESOLVED — LOW-C.** `gl_reconciliation_tolerance` consolidated to a single key in
> `config/accounting.php` only. The duplicate in `config/app.php` is removed. The 2
> consumers that read from `app.` (`ReconciliationService` + `RunningBalanceReconcile`)
> now read from `accounting.` — consistent with `SubLedgerReconcile`. Grep confirms 0
> remaining `config('app.gl_reconciliation_tolerance')` references.
Same key in `config/accounting.php:40` AND `config/app.php:27`. Both must be updated together (or
consolidate). Additionally, consumers are inconsistent: `ReconciliationService.php:41` +
`RunningBalanceReconcile.php:49` read `config('app.gl_reconciliation_tolerance')`;
`SubLedgerReconcile.php:133,183,227` reads `config('accounting.gl_reconciliation_tolerance')`.

### G7 — `expires_at` is optional + auto-deactivation uses `activated_by ?? 1` (LOW)
A policy with no `expires_at` never auto-deactivates. If the original activator was deleted, the
auto-deactivation falls back to user id 1 (the seeded superadmin). The audit row records the
reason as "Policy expired automatically at <ts>".
Evidence: `CheckSystemPolicy.php:49-55`; `SystemPolicyService.php:51-58`.

### G8 — Cache stale window (LOW)

> ✅ **RESOLVED — LOW-A (acceptance as documented).** The 5-minute TTL with immediate
> invalidation on activation is accepted as the documented trade-off: in the single-instance
> deployment posture (the documented baseline — see §1), activation calls `forget()` + `re-put()`
> so the new mode is visible to the very next request, and the 5-minute TTL only bounds the
> worst-case staleness if a cache write fails. The multi-instance stale-window (a cache write on
> instance A is not visible to instance B's local cache) is a known limitation that would require
> a cross-instance cache-invalidation broadcast (e.g. Redis pub/sub or a `SystemPolicyChanged`
> listener — see §13 #14) — that is a separate effort, not in scope for this acceptance. This
> trade-off is already documented in §3 BR row (cache 5 min + invalidate on activation) and in
> §5 (`CACHE_TTL = 300` (5 min)). No code change; this is a documentation-acceptance resolution.

Active policy cached for 5 minutes. Activation invalidates immediately (forget + re-put), but if
the cache write fails or another app instance is involved, stale cached policy may be served for
up to 5 minutes.
Evidence: `SystemPolicyService.php:26-27,89-90`.

### G9 — NEW — NO RLS on `system_policies` (HIGH)

> ✅ RESOLVED in commit dd31590 (G-174) — RLS migration `2026_08_30_000001_add_rls_missing_tables.php` (G-174 section) adds ENABLE + FORCE ROW LEVEL SECURITY and per-verb `rls_system_policies_select/insert/update/delete` + `rls_system_policies_admin` policies on `system_policies`. Unlike G-015/G-095 (where admin-only RLS was a bug), admin-only RLS is the CORRECT posture here because: (1) the route middleware is `role:superadmin` (G-173, resolved in Session 1), so only superadmin reaches the controller; (2) the table has no `branch_id` and is intentionally superadmin-only; (3) `app.is_admin = 'true'` is set for admin + superadmin by the `SetAppBranchId` middleware. The per-verb policies use condition `false` (so non-admins get zero rows / blocked writes via the `app.is_admin` bypass folded into each policy). This blocks direct DB-level modification by any non-admin role, forcing all writes through `SystemPolicyService::activate()/deactivate()` (which writes the audit log + dispatches the event + invalidates the cache). Mirrors the canonical `add_rls_branch_isolation` pattern.

36 tables have RLS enabled + FORCE in `07_views_triggers_constraints.sql:509-850`.
`system_policies` is NOT among them. Any user with DB-level INSERT/UPDATE/DELETE permission on
`system_policies` can directly modify the active policy, bypassing
`SystemPolicyService::activate()/deactivate()` (which is the only path that writes the audit log
+ dispatches the event + invalidates the cache). A direct
`UPDATE system_policies SET is_active=true WHERE mode='INVESTIGATION'` would silently activate
investigation mode with NO audit log, NO event, NO cache invalidation.
Evidence: grep `ENABLE ROW LEVEL SECURITY.*system_policies` returns 0 hits.

### G10 — NEW — `fn_financial_audit_trigger` NOT attached to `system_policies` (MEDIUM)
The hash-chain audit trigger is attached to 10 financial tables in `02_accounting.sql:446-455`.
NOT attached to `system_policies`. The compliance framework — whose entire purpose is auditable
mode switches — has NO hash-chain audit trail at the DB level. The only audit trail is the
`user_audit_log` row written by `SystemPolicyService::writeAuditLog()` (which can be bypassed —
see G9). Recurring cross-phase gap (Phase 13 found 15+ tables missing the trigger).

### G11 — NEW — `system_policies` DDL missing from `database/sql/*.sql` baseline (MEDIUM)
Phase 13 found 5 subsystems (consolidation, branch-demand, budgeting, dimensions, fixed-assets)
had stale DDL — tables existed only in migrations, not in the `01-07_*.sql` baseline.
`system_policies` fits the same pattern: `CREATE TABLE` exists only in
`2025_01_07_000001_create_system_policies_table.php`. The `basic_data_snapshot.sql:4367-4369`
INSERTs a default row but assumes the table already exists. A fresh `migrate:fresh` from the SQL
baseline + migration sequence works (migrations run after SQL files), but anyone reading only the
SQL baseline to understand the schema will miss this table.

### G12 — NEW — `rcerp_notify_system_policy()` PG trigger is dead in practice (MEDIUM)
The PG trigger fires `AFTER UPDATE ON system_policies WHEN NEW.mode IS DISTINCT FROM OLD.mode`.
But `SystemPolicyService::activate()` does (a) `UPDATE` previous policy setting
`is_active=false, deactivated_by, deactivated_at` (does NOT change `mode`), then (b) `INSERT` a
new policy with the new mode (`INSERT`, not `UPDATE`). Neither operation triggers the function.
`deactivate()` similarly only `UPDATE`s `is_active/deactivated_by/deactivated_at` — no mode change.
The JS SSE handler in `public/assets/js/notification.js:144-152` listens for `rcerp_system`
events but will NEVER receive one from real policy activations. The realtime broadcast for system
policy changes is dead code.
Evidence: `2025_01_21_000001_add_listen_notify_triggers.php:344`; `SystemPolicyService.php:67-86,109-113`.

### G13 — NEW — INVESTIGATION mode has NO business-logic consumer (HIGH)

> ✅ **RESOLVED — AUDIT-TRAIL-3** (cross-ref G2, resolved in the same wave).
> The FIRST business-logic write-side consumers of `isInvestigation()` are
> now in place:
>
>   1. `BlockWritesDuringInvestigation::handle()` — checks
>      `app('system_policy_mode') === 'INVESTIGATION'` on every non-GET
>      HTTP request (web + API). The first HTTP-layer business-logic
>      consumer of the policy mode outside the loading/sharing middleware.
>
>   2. `SystemPolicyService::assertWriteAllowed()` — checks
>      `$this->isInvestigation()` and throws
>      `SystemPolicyWriteBlockedException`. Called from
>      `JournalPostingService::createJournalEntry()` — the FIRST
>      service-layer business-logic consumer. This is the single GL
>      chokepoint: every business module (sales, purchase, stock,
>      accounting) that posts a GL entry funnels through
>      `createJournalEntry()`, so one hook covers all GL write paths
>      including reversals (`reverseJournalEntry` calls
>      `createJournalEntry` internally).
>
> **Grep evidence refuted:** `rg -n 'isInvestigation\b' laravel/app/` now
> returns hits in `BlockWritesDuringInvestigation.php` (indirectly, via
> `app('system_policy_mode')`) and `SystemPolicyService.php` (the new
> `assertWriteAllowed` method) + the pre-existing
> `ApplySystemPolicyScope` trait (G-171 read-side consumer) +
> `JournalPostingService.php` (calls `assertWriteAllowed`). The
> business-logic-consumer count is now 3 distinct layers (read-side trait,
> HTTP middleware, service-layer GL hook), up from 0 at the time the gap
> was filed.
>
> **Future write-side hooks (NOT in scope for AUDIT-TRAIL-3):** the GL
> chokepoint covers all journal postings, but a few non-GL write paths
> remain unguarded by the service-layer hook (they ARE guarded by the HTTP
> middleware when invoked via web/API, but not when invoked via
> console/queue):
>   - `StockService::applyTransaction()` — SSOT inventory ledger (non-GL).
>   - `SalesInvoiceService::confirm()` etc. — status transitions that
>     don't immediately post a GL entry.
>   - Direct Eloquent `update()`/`delete()` on the 6 transactional document
>     models wired in G-171 (these bypass `JournalPostingService` until
>     confirmation).
> These are defense-in-depth gaps, not primary gaps — the HTTP middleware
> catches the vast majority of real-world write attempts (web UI + mobile
> API). A follow-up task can add `assertWriteAllowed()` calls to these
> services if the forensic posture ever needs to block console/queue-driven
> non-GL writes too. For now, the GL chokepoint + HTTP middleware together
> close G-175.

- **Original evidence:** grep `policyService->isInvestigation\|->getCurrentMode`
  returned hits ONLY in `SystemPolicyService.php` (self),
  `CheckSystemPolicy.php` (load+share), `ApplySystemPolicyScope.php`
  (unused trait), `SystemPolicyController.php` (admin view share). NO
  business-logic consumer.
- **Impact (historical):** activating INVESTIGATION mode in production did
  NOTHING except change the compliance admin page banner + write an audit
  log row. All financial operations continued normally.
- **Fix (done):** added the first 2 business-logic write-side consumers
  (HTTP middleware + service-layer GL chokepoint hook). Read-side consumer
  (ApplySystemPolicyScope trait) was wired in G-171 (AUDIT-TRAIL-2).

### G14 — NEW — No global investigation-mode banner (LOW — doc accuracy)
> ✅ **RESOLVED — LOW-F.** Global investigation-mode banner added to BOTH
> `layouts/admin.blade.php` AND `components/layouts/erp.blade.php`. Banner renders only when
> `$isInvestigation` is true (shared by middleware). Sticky-top, full-width, prominent
> red/orange styling, `role="alert"` + `aria-live="assertive"` for accessibility. Text:
> "⚠ INVESTIGATION MODE ACTIVE — All financial writes are blocked. Reads are clamped to
> current fiscal year. Contact your administrator." Regular users now see the mode change
> immediately.

The Phase 5 doc §4 claimed "All users — see the investigation banner (Blade `$isInvestigation`
shared var)". This is INCORRECT. The `$isInvestigation` shared var is consumed ONLY in
`admin/compliance/index.blade.php` (the admin page itself). Neither `layouts/admin.blade.php` nor
`components/layouts/erp.blade.php` renders a global banner. Regular users see NO indication that
INVESTIGATION mode is active.

> ✅ **RESOLVED — LOW-H.** `period_close_override` added to the tracked-actions list in
> `audit-trails.md` §7.2 (UserAuditLogger service / Tracked actions, L165-168). The entry
> describes the trigger (admin bypasses period-close check via
> `config('accounting.period_close_admin_override')` + `$user->isAdmin()`), the source code
> location (`JournalPostingService::validatePeriod` L438-470 — current line numbers; the
> original gap evidence cited L317-331/L319 from an earlier revision), and the full audit-row
> payload (`user_id`, `branch_id`, `details` JSONB with `posting_date`/`closed_through`/
> `branch_id`/`reason`, plus `ip_address`/`user_agent`/`created_at`). A corresponding bullet was
> also added to `audit-trails.md` §3 (When is it used?) and the doc's `Last reviewed` header was
> refreshed to `2026-09-06`. Doc-only resolution — no code change.

### G15 — NEW — `period_close_override` audit action not documented in audit-trails.md (LOW)
`JournalPostingService::validatePeriod` L317-331 writes a `user_audit_log` row with
`action='period_close_override'` when an admin bypasses the period-close check. This action string
is NOT listed in `audit-trails.md:162-164` tracked-actions list. The audit-trails doc should be
updated to include `period_close_override`.
Evidence: `JournalPostingService.php:319`; `audit-trails.md:162-164`.

### G16 — NEW — `SystemPolicyService::writeAuditLog` bypasses `UserAuditLogger` (LOW — doc accuracy)
The Phase 5 doc §9 claimed "UserAuditLogger — invoked by the service for audit rows". INCORRECT.
`SystemPolicyService::writeAuditLog` at L142-158 writes DIRECTLY via
`DB::table('user_audit_log')->insert(...)`. It does NOT import or call
`App\Services\Auth\UserAuditLogger`. This means the dual-write (PG + file) that `UserAuditLogger`
provides is bypassed for system policy changes — only the PG insert happens, no file log.
Evidence: `SystemPolicyService.php:142-158`; `UserAuditLogger.php` exists but is not imported.

## 13. Future improvements

Ordered by severity (HIGH first):

1. **Fix G13 — add business-logic consumers of `isInvestigation()`.** At minimum, every
   GL-posting service (`JournalPostingService::createJournalEntry`, `StockService::applyTransaction`,
   `ConsolidationService::postConsolidation`, etc.) should check
   `app(SystemPolicyService::class)->isInvestigation()` and either block the write or require an
   explicit override reason. Without this, INVESTIGATION mode is a no-op.
2. **Fix G9 — enable RLS on `system_policies`.** Add `ENABLE ROW LEVEL SECURITY` + `FORCE` + a
   policy restricting `INSERT/UPDATE/DELETE` to the `superadmin` role (mirroring the consolidation
   tables' admin-only RLS pattern). This closes the bypass where a direct DB write skips the audit
   log + event + cache invalidation.
3. **Fix G1 — wire `ApplySystemPolicyScope`** to date-bounded models (`SalesInvoice`,
   `PurchaseReceive`, `JournalEntry`, etc.) so INVESTIGATION mode actually clamps reads to the
   current fiscal year. Without this, INVESTIGATION mode doesn't even restrict READS.
4. **Fix G3 — add `role:superadmin` middleware** to the `/admin/compliance/*` route group (or
   consume the `manage-system-policy` Gate via `Gate::authorize` in the controller).
5. **Fix G10 — attach `fn_financial_audit_trigger`** to `system_policies`. Single migration could
   attach to ALL missing tables across phases (Phase 13's G4/G6 found 15+ tables missing the trigger
   — `system_policies` makes 16+).
6. **Fix G11 — add `system_policies` DDL to `database/sql/*.sql` baseline** so the schema snapshot
   is not stale.
7. **Fix G12 — change the PG trigger condition** from `NEW.mode IS DISTINCT FROM OLD.mode` to fire
   on `is_active` changes too, OR change `SystemPolicyService::activate()` to `UPDATE` the existing
   row's `mode` instead of `INSERT`-ing a new row. Pick one approach so the realtime broadcast
   actually fires.
8. **Fix G2 — add write-blocking in INVESTIGATION mode.** A middleware or a DB-level trigger that
   refuses `UPDATE`/`DELETE` on financial tables when `is_active=true AND mode='INVESTIGATION'`.
9. **Fix G6 — consolidate `gl_reconciliation_tolerance`** into one config file AND standardize the
   consumer reads (all should read `config('accounting.gl_reconciliation_tolerance')`).
10. **Fix G16 — route `SystemPolicyService::writeAuditLog` through `UserAuditLogger`** so the
    dual-write (PG + file) defense-in-depth applies to system policy changes.
11. **Fix G14 — add a global investigation-mode banner** in `layouts/admin.blade.php` +
    `components/layouts/erp.blade.php` so regular users see the mode change.
12. **Fix G15 — add `period_close_override` to `audit-trails.md` §13** tracked-actions list.
13. **Register `SystemPolicyPolicy`** (or delete it) to remove the G4 dead-code ambiguity.
14. **Add a listener for `SystemPolicyChanged`** that broadcasts a toast to all active sessions
    (via the realtime-events channel — Phase 15) so users see the mode change immediately.
15. **Implement `READ_ONLY` / `MAINTENANCE` / `EMERGENCY` modes** if the business needs them
    (currently declared but unused).
16. **Add a non-expiring-policy alert** — surface policies with `expires_at IS NULL` in the admin
    UI so they're not forgotten.
17. **Make `expires_at` required** for INVESTIGATION mode (force a review point).
18. **Add a scheduled job** that deactivates expired policies (currently only request-driven via
    `CheckSystemPolicy` — if no requests arrive, an expired policy stays active).
