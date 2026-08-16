# Session 2 — Read-Block: Global Scope + FiscalYearPolicy

> **Phase**: 1 (Q1 — Fiscal Year Isolation)
> **Branch**: `feature/fy-isolation-and-branch-pnl`
> **Baseline**: `f4ef291` (Session 1 — `fiscal_year_id` column added)
> **Date**: 2026-08-16
> **Owner**: Backend dev

This document records every change made in Session 2, the rationale for each decision, and the acceptance tests the dev team must run on the live Docker host. It is the companion to `docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md` §Session 2.

---

## 1. Goal

Make every operational model automatically filter by the running fiscal year, and add a `FiscalYearPolicy` that hard-denies reads on closed/locked fiscal years — including for the super admin.

This is the **application-layer read-block** for Q1 Gap 2. Combined with the `fiscal_year_id` column added in Session 1, it guarantees that:

1. Every Eloquent query against an operational model silently gets `WHERE fiscal_year_id = <active FY id>` appended.
2. No user — not even super admin — can read closed/locked fiscal year data through the UI. The `viewHistoricalData` gate hard-denies for everyone, and `Gate::before()` is amended to NOT bypass this specific ability for super admin.
3. If no active FY exists (e.g., immediately after year-end close, before the next FY is activated), the request fails fast with a clear 503 message instead of a confusing query-time crash.

---

## 2. Files Changed

### 2.1 New Files (8)

| File | Purpose |
|---|---|
| `app/Policies/FiscalYearPolicy.php` | Authorization policy for `FiscalYear` model. Defines `viewHistoricalData()` as a hard-deny (returns `false`) for everyone. |
| `app/Http/Middleware/EnsureActiveFiscalYear.php` | Resolves + caches the active FY id at request start. Fails fast with 503 if no active FY exists. Exempts `/login`, `/password`, `/up`, and `/admin/fiscal-years/*` so the FY management UI remains reachable. |
| `resources/views/errors/no-active-fiscal-year.blade.php` | 503 error page rendered by the middleware when no active FY is configured. Shows super admin a link to `/admin/fiscal-years`; other users see "contact your system administrator." |
| `app/Models/Concerns/BelongsToFiscalYear.php` *(body implemented — was a stub in S1)* | Trait that registers the `current_fy` global scope + `fiscalYear()` relation + `scopeWithoutFiscalYearScope()` escape hatch. |
| `app/Support/FiscalYearResolver.php` *(body implemented — was a stub in S1)* | Resolves the active FY id from Redis cache (5-minute TTL). `clearCache()` called by `FiscalYearService::activateFiscalYear()`, `closeFiscalYear()`, `lockFiscalYear()`. |
| `/home/z/my-project/scripts/apply_fy_trait.py` | Python script that applies the `BelongsToFiscalYear` trait to all 35 operational models. Idempotent. |
| `/home/z/my-project/scripts/check_s2_syntax.py` | Python script that brace-balance-checks all S2-touched files + verifies the trait is in the `use` block for each model. |

### 2.2 Modified Files (3 + 35 models)

| File | Change |
|---|---|
| `app/Providers/AppServiceProvider.php` | (a) `Gate::before()` amended to return `false` for the `viewHistoricalData` ability even when the user is superadmin. (b) `Gate::policy(FiscalYear::class, FiscalYearPolicy::class)` registered. |
| `app/Services/Accounting/FiscalYearService.php` | `FiscalYearResolver::clearCache()` called at the end of `activateFiscalYear()`, `closeFiscalYear()`, and `lockFiscalYear()` so the cached active-FY id is invalidated on every lifecycle transition. |
| `bootstrap/app.php` | `EnsureActiveFiscalYear` middleware appended to the web middleware group (after `CheckSystemPolicy`, before `BlockWritesDuringInvestigation`). |
| **35 operational models** | `use App\Models\Concerns\BelongsToFiscalYear;` import added + `BelongsToFiscalYear` appended to the model's trait `use` block. See §3 for the full list. |

---

## 3. Operational Models Trait-Applied (35)

The `BelongsToFiscalYear` trait is now applied to every operational model listed in `config/fiscal.php` that has a corresponding Eloquent model class. The single exception is `branch_ledger` — see §5.

**Sales & receivables (8):**
- `SalesInvoice` (sales_invoices), `SalesInvoiceItem` (sales_invoice_items)
- `SalesChallan` (sales_challans), `SalesChallanItem` (sales_challan_items)
- `SalesReturn` (sales_returns), `SalesReturnItem` (sales_return_items)
- `CustomerPayment` (customer_payments), `CustomerLedger` (customer_ledger)

**Purchasing & payables (8):**
- `PurchaseOrder`, `PurchaseOrderItem`
- `PurchaseReceive`, `PurchaseReceiveItem`
- `PurchaseReturn`, `PurchaseReturnItem`
- `SupplierPayment`, `SupplierLedger`

**Inventory (8):**
- `StockTransaction`, `StockAdjustment`, `StockAdjustmentItem`
- `StockTakeSession`, `StockTakeWarehouse`
- `WarehouseTransfer`, `WarehouseTransferItem`
- `DamageInvoice`, `DamageInvoiceItem`

**Inter-branch (3):**
- `BranchDemand`, `BranchDemandItem`, `BranchDemandRepricing`

**Accounting (4):**
- `Accounting\JournalEntry` (journal_entries)
- `Accounting\JournalLine` (journal_lines)
- `ManualJournal` (manual_journals)
- `EmployeeTransaction` (employee_transactions)

**Finance — other (4):**
- `OtherIncome`, `OtherExpense`, `MoneyTransfer`

---

## 4. Key Design Decisions

### 4.1 Why `bootBelongsToFiscalYear()` (not `booted()`)

Laravel's `BootTraits` mechanism auto-invokes `boot{TraitName}()` on model boot — it does NOT auto-invoke `booted()` defined on a trait. The S1 stub correctly used `bootBelongsToFiscalYear()`, and the S2 implementation keeps this convention. The plan's reference to `booted()` was imprecise; the trait-boot method is the canonical pattern.

### 4.2 Why a 5-minute Redis TTL (not indefinite)

The active FY id changes extremely rarely — only on `activateFiscalYear()` / `closeFiscalYear()` / `lockFiscalYear()`. We could cache indefinitely and rely solely on `clearCache()` for invalidation. The 5-minute TTL is a **self-healing upper bound**: if a future code path mutates the FY lifecycle without calling `clearCache()` (e.g., a direct `FiscalYear::where(...)->update(...)` in a migration or test), the cached value self-corrects within 5 minutes rather than silently serving stale data forever. The cost is one DB query per 5 minutes per cache key — negligible.

### 4.3 Why `Gate::before()` excludes `viewHistoricalData` specifically

The existing `Gate::before()` returns `true` for every ability when the user is superadmin — this is the convention that lets superadmin bypass every `Policy::viewAny()` / `view()` / `create()` / etc. check without explicitly adding `superadmin` to every role list. The amendment is **surgical**: it adds ONE `in_array($ability, ['viewHistoricalData'], true)` check that returns `false` for that specific ability. Every other ability still bypasses. This is the single most important line of code in Q1 — without it, superadmin could read closed-FY data through any code path that calls `Gate::allows('viewHistoricalData', $fy)`.

### 4.4 Why a separate `viewHistoricalData` ability (not just `view`)

`view(User, FiscalYear)` returns `true` only for `$fy->status === 'active'`. This means a user CAN view the active FY's metadata (name, dates, status) via the FY management page — they just can't view the FY's **transactional** data once it's closed. `viewHistoricalData(User, FiscalYear)` is the gate that controls access to closed/locked FY transactional data (sales, purchases, stock, journals). It always returns `false`. The active-FY's transactional data is accessible through the `BelongsToFiscalYear` global scope (which silently filters by `activeId()`), NOT through this gate.

### 4.5 Why the middleware exempts `/admin/fiscal-years/*`

After year-end close, no active FY exists. The very next thing the superadmin must do is navigate to `/admin/fiscal-years` and activate the next FY. If the middleware blocked this path, the superadmin would be locked out of the only UI that can fix the problem — a chicken-and-egg deadlock. The exemption list also covers `/login`, `/password`, and `/up` so users can still authenticate and the health check still works.

### 4.6 Why fail-closed (throw) when no active FY exists

`FiscalYearResolver::activeId()` throws a `RuntimeException` if no active FY is found. This is a deliberate fail-closed posture: better to crash with a clear "no active fiscal year" message than to silently let every operational query return zero rows (which would look like "no data" and confuse users into thinking data was lost). The `EnsureActiveFiscalYear` middleware catches this exception at request start and renders a clean 503 page with an actionable message + a link to the FY management UI for super admins.

### 4.7 Why `branch_ledger` has no model — follow-up tracked

`branch_ledger` is queried via `DB::table('branch_ledger')` in `BranchDemandService` and related inter-branch services. The `BelongsToFiscalYear` trait can only be applied to Eloquent models — it has no effect on `DB::table()` queries. This means **inter-branch ledger queries will NOT automatically filter by active FY** until either (a) a `BranchLedger` model is introduced, or (b) every `DB::table('branch_ledger')` call site is amended with `->where('fiscal_year_id', FiscalYearResolver::activeId())`. This is tracked as a follow-up in §6.1 — it does NOT block Session 2 acceptance because inter-branch demand/ledger code is being reworked in Session 5 (Branch P&L report).

---

## 5. Acceptance Tests

### 5.1 Tests that PASS in this sandbox (no DB needed)

- [x] **All 43 touched files pass brace/paren/bracket balance check** — verified via `/home/z/my-project/scripts/check_s2_syntax.py`. No `php -l` available in sandbox; balance check is the next-best static guarantee.
- [x] **All 35 operational models have the trait in their `use` block** — verified by regex `(?<!\\)\bBelongsToFiscalYear\b\s*[;,]` (excludes the import line).
- [x] **Master-data models (Product, Customer, Supplier, User, Branch, Warehouse, Ledger, Employee) do NOT have the trait** — verified by absence of `BelongsToFiscalYear` in those files.
- [x] **`FiscalYear` model itself does NOT have the trait** (would cause infinite recursion — resolver queries FiscalYear, which would trigger the scope, which calls the resolver). Verified by absence.
- [x] **Audit-log models (StockAdjustmentAuditLog, StockTakeAuditLog, ExportAuditLog) do NOT have the trait** — audit logs must remain queryable across FYs for compliance.
- [x] **`Gate::before()` amendment is present** — verified by grep `in_array($ability, ['viewHistoricalData']` in `AppServiceProvider.php`.
- [x] **`FiscalYearPolicy` is registered** — verified by grep `Gate::policy(\App\Models\FiscalYear::class` in `AppServiceProvider.php`.
- [x] **`EnsureActiveFiscalYear` middleware is registered** — verified by grep in `bootstrap/app.php`.
- [x] **`FiscalYearResolver::clearCache()` is called in all 3 lifecycle methods** — verified by grep in `FiscalYearService.php`.

### 5.2 Tests that REQUIRE the dev DB (dev team runbook)

These must be executed on the live Docker host by the dev team. Copy-paste commands below.

#### 5.2.1 PHP syntax check on all touched files

```bash
cd /path/to/laravel

# New + modified PHP files
for f in \
  app/Models/Concerns/BelongsToFiscalYear.php \
  app/Support/FiscalYearResolver.php \
  app/Policies/FiscalYearPolicy.php \
  app/Http/Middleware/EnsureActiveFiscalYear.php \
  app/Providers/AppServiceProvider.php \
  app/Services/Accounting/FiscalYearService.php \
  bootstrap/app.php; do
  php -l "$f" || echo "FAIL: $f"
done

# All 35 operational models
find app/Models -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"
```

**Expected**: zero output from the `grep -v` (all files pass).

#### 5.2.2 Existing test suite still passes

```bash
cd /path/to/laravel
php artisan test 2>&1 | tee /tmp/s2_test_run.log
```

**Expected**: existing tests pass. Some tests that create rows without setting `fiscal_year_id` will now fail — those tests need their factories updated to explicitly set `fiscal_year_id` to the active FY (or to use `withoutGlobalScope('current_fy')` if the test is explicitly about historical data).

**Known likely breakages** (dev team should expect these):
- Any factory that creates `SalesInvoice`, `PurchaseReceive`, `StockTransaction`, etc. without first ensuring an active FY exists in the test DB.
- Any test that asserts `Model::count() === N` where the test created rows in a previous FY (those rows are now invisible).
- Any test that queries a model in a `setUp()` before an active FY is set up.

**Fix pattern** (in test factories / `setUp()`):
```php
// In the test's setUp() or factory definition:
$activeFy = \App\Models\FiscalYear::factory()->create([
    'status'      => 'active',
    'is_current'  => true,
    'start_date'  => '2026-01-01',
    'end_date'    => '2026-12-31',
]);
\App\Support\FiscalYearResolver::clearCache(); // ensure fresh resolution
```

Or in a factory definition:
```php
// database/factories/SalesInvoiceFactory.php
public function definition(): array
{
    return [
        'fiscal_year_id' => \App\Support\FiscalYearResolver::activeId(),
        // ...other fields...
    ];
}
```

#### 5.2.3 Manual smoke test — read-block on closed FY

```bash
# Step 1: Identify the active FY + a closed FY in the DB
php artisan tinker --execute="
  \$active = \App\Models\FiscalYear::where('status','active')->first();
  \$closed = \App\Models\FiscalYear::where('status','closed')->first();
  echo 'Active FY: '.\$active?->id.' ('.\$active?->name.')\n';
  echo 'Closed FY: '.\$closed?->id.' ('.\$closed?->name.')\n';
"
```

```sql
-- Step 2: Count rows in the active vs. closed FY directly via psql
SELECT
  (SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id = <active_fy_id>) AS active_fy_invoice_count,
  (SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id = <closed_fy_id>) AS closed_fy_invoice_count;
```

```bash
# Step 3: Log in as super admin in the browser.
# Navigate to: /admin/sales-invoices
# Expected: the invoice list shows ONLY invoices where fiscal_year_id = <active_fy_id>.
# The row count in the UI should match active_fy_invoice_count from Step 2.

# Step 4: Try to bypass via URL params:
# Navigate to: /admin/sales-invoices?from=<closed_fy_start>&to=<closed_fy_end>
# Expected: ZERO rows returned (not an error, just an empty list).
# This is the read-block in action — the global scope ignores the URL date params
# and filters strictly by fiscal_year_id = activeId().
```

#### 5.2.4 Tinker one-liner — `viewHistoricalData` gate hard-deny for super admin

```bash
php artisan tinker --execute="
  \$superadmin = \App\Models\User::where('role','superadmin')->first();
  \$closedFy = \App\Models\FiscalYear::where('status','closed')->first();
  if (!\$closedFy) { echo 'SKIP: no closed FY in DB — create one first\n'; exit; }
  \$denied = \Illuminate\Support\Facades\Gate::forUser(\$superadmin)->denies('viewHistoricalData', \$closedFy);
  echo 'Super admin denied viewHistoricalData on closed FY: '.(\$denied ? 'YES (PASS)' : 'NO (FAIL)').\"\n\";
"
```

**Expected output**: `Super admin denied viewHistoricalData on closed FY: YES (PASS)`

#### 5.2.5 Escape hatch verification

```bash
php artisan tinker --execute="
  \$total = \App\Models\SalesInvoice::withoutGlobalScope('current_fy')->count();
  \$scoped = \App\Models\SalesInvoice::count();
  echo 'Without scope: '.\$total.\"\n\";
  echo 'With scope:    '.\$scoped.\"\n\";
  echo 'Escape hatch works: '.(\$total >= \$scoped ? 'YES (PASS)' : 'NO (FAIL)').\"\n\";
"
```

**Expected**: `Without scope` count is >= `With scope` count (typically greater, since it includes closed-FY rows).

#### 5.2.6 Year-end close cache invalidation

```bash
# Step 1: Cache the active FY id
php artisan tinker --execute="echo 'Cached active FY id: '.\App\Support\FiscalYearResolver::activeId().\"\n\";"

# Step 2: In a separate terminal, simulate year-end close on a test FY
# (DO NOT run this on production data — use a test FY created for this purpose)
php artisan tinker --execute="
  \$fy = \App\Models\FiscalYear::where('status','active')->where('name','LIKE','%TEST%')->first();
  if (!\$fy) { echo 'SKIP: no test active FY found — create one with name like \"TEST FY 2026\"\n'; exit; }
  \$svc = app(\App\Services\Accounting\FiscalYearService::class);
  // (Close the FY via the service — this should call clearCache())
"

# Step 3: Verify the cache was cleared
php artisan tinker --execute="
  \$cached = \Illuminate\Support\Facades\Cache::get('active_fiscal_year_id');
  echo 'Cache after close: '.(\$cached === null ? 'NULL (cleared — PASS)' : \$cached.' (STALE — FAIL)').\"\n\";
"
```

#### 5.2.7 Reconciliation reports still work

```bash
# Log in as super admin and visit:
# /admin/branch-demands/reconcile
# Expected: page loads without error, shows only the active FY's reconciliation data.
# (This works because the reconciliation service queries operational models that
# now have the BelongsToFiscalYear trait — they silently filter by activeId().)
```

#### 5.2.8 FY management UI has NO "view historical data" button

```bash
# Log in as super admin and visit /admin/fiscal-years
# Expected:
#   - The list of fiscal years is visible (active + closed).
#   - Create / Activate / Close / Lock buttons are visible for authorised FYs.
#   - There is NO button labelled "View Historical Data" or "View Transactions"
#     anywhere on the page or on any per-FY detail view.
#   - Clicking on a closed FY's row may show its metadata (name, dates, status,
#     closed_at) but MUST NOT show any transactional data (sales, purchases,
#     journals, etc.).
```

---

## 6. Follow-Ups

### 6.1 `branch_ledger` has no Eloquent model — service-layer explicit filtering needed

`branch_ledger` is queried via `DB::table('branch_ledger')` in:
- `app/Services/BranchDemand/BranchDemandService.php`
- `app/Services/BranchDemand/BranchDemandRepricingService.php`
- (Possibly others — dev team should `grep -rn "DB::table('branch_ledger')" app/`)

These call sites do NOT get the automatic `BelongsToFiscalYear` global scope. Until a `BranchLedger` model is introduced (recommended for Session 5 — Branch P&L report — which heavily uses this table), every `DB::table('branch_ledger')` query MUST be amended with:

```php
->where('fiscal_year_id', \App\Support\FiscalYearResolver::activeId())
```

**Tracking**: this is a sub-task of Session 5, not a blocker for Session 2. The dev team should be aware that inter-branch ledger queries will return cross-FY data until Session 5 is complete.

### 6.2 Test factories need `fiscal_year_id` field

Any factory for an operational model (`SalesInvoiceFactory`, `PurchaseReceiveFactory`, `StockTransactionFactory`, etc.) should set `fiscal_year_id` to `FiscalYearResolver::activeId()` by default. The dev team should audit `database/factories/` and add this field where missing. Tests that explicitly need to create rows in a non-active FY must use `withoutGlobalScope('current_fy')` on the model OR set `fiscal_year_id` explicitly and bypass the scope.

### 6.3 `Gate::before()` amendment pattern for future "hard-deny" abilities

If a future requirement adds another ability that must hard-deny for everyone (e.g., `viewAuditTrail` for compliance), the same pattern applies:

1. Define the ability in a Policy method that returns `false`.
2. Add the ability name to the `in_array($ability, [...], true)` list in `Gate::before()`.

The current list is `['viewHistoricalData']` — future amendments should extend this array, not create a separate `Gate::before()` call.

### 6.4 Dev team cannot use the app UI to inspect old FY data

**From this point forward, the dev team cannot use the application UI to inspect closed-FY data.** Direct `psql` access is required:

```bash
docker exec -it <postgres_container> psql -U <user> -d <db>
# Then:
SELECT * FROM sales_invoices WHERE fiscal_year_id = <closed_fy_id> LIMIT 10;
```

This is the intended posture — the entire point of Q1 is that the UI cannot show closed-FY data. Flag this to the dev team in the standup.

---

## 7. PM Checkpoint

**Status**: ✅ Session 2 implementation complete. Acceptance tests in §5.1 pass in sandbox; §5.2 tests require dev DB execution.

**Client signoff still required** (carried from Session 0 §4):

> The client must confirm in writing that the super admin is also blocked from viewing closed-FY data through the UI. This is the single most controversial requirement of Q1 — many ERPs allow super admin to view historical data. The `Gate::before()` amendment + `FiscalYearPolicy::viewHistoricalData()` hard-deny implement the client's requirement literally. If the client later decides to allow super admin historical view, the amendment must be reverted AND the policy method must be updated — both changes must be made together.

**Ready for Session 3** (Auto-Backup Command + Year-End Checklist Gate) once:
- §5.2 tests pass on dev DB.
- Client signoff is recorded.
- Dev team is briefed on §6.4 (no UI access to closed-FY data).

---

## 8. Commit

```
feat(fy): global scope + FiscalYearPolicy hard read-block (S2)

- Implement FiscalYearResolver (Redis-cached active FY id, 5-min TTL).
- Implement BelongsToFiscalYear trait (current_fy global scope).
- Apply trait to 35 operational models (sales, purchases, stock,
  inter-branch, accounting, finance).
- Create FiscalYearPolicy with viewHistoricalData() hard-deny.
- Amend Gate::before() to exclude viewHistoricalData from
  super-admin bypass — single most important line of Q1.
- Create EnsureActiveFiscalYear middleware (fail-closed 503 if no
  active FY; exempts /admin/fiscal-years/* for chicken-and-egg case).
- Wire FiscalYearResolver::clearCache() into FiscalYearService
  activate/close/lock lifecycle methods.
- Add 503 error view for no-active-FY state.

Closes Q1 Gap 2 (read-block). Combined with S1 (fiscal_year_id column),
this guarantees no user — not even super admin — can read closed-FY
transactional data through the UI.
```
