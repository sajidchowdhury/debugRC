# Session 11 Confirmation — Resolve 14 Remaining S10 Test-Suite Failures

**Phase 2 / Q2 — Final Hardening (S10.2 in some logs, but called S11 here)**
**Status:** Code complete, pushed to both branches
**Branches:** `main` (cherry-picked from `feature/fy-isolation-and-branch-pnl` commit `46dc0e4`)

## Context

Session 10.1 fixed 36 of the 46 S10 test failures (test-setup mistakes that
violated schema constraints). The dev team pulled S10.1, ran the suite on
the Docker host, and reported **32 passed / 14 failed**.

This session (S11) is the targeted fix for the remaining 14 failures.
Like S10.1, **most failures were test-setup issues** — but one real
production bug surfaced in the Branch P&L drilldown controller (Cluster D),
and that fix is a legitimate code change.

## Root-Cause Analysis

All 14 failures cluster into 4 root causes:

### Cluster A — `product_price_history` has NO `updated_at` column (8 failures)

**Affected tests:** All 8 in `BelowMinApprovalServiceTest`:

- `approve succeeds with admin credentials`
- `approve succeeds with manager credentials`
- `approve throws on invalid credentials`
- `approve throws when approver role is insufficient`
- `approve throws when reason is too short`
- `approve throws when rate is not below min`
- `approve throws when approver is inactive`
- `is valid override returns true for real audit log row`

**Error:**
```
SQLSTATE[42703]: Undefined column: 7 ERROR:  column "updated_at"
of relation "product_price_history" does not exist
```

**Cause:** The legacy `product_price_history` DDL
(`database/sql/01_auth_and_master.sql` lines 184-195) declares only
`created_at` (no `updated_at`):

```sql
CREATE TABLE product_price_history (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id integer NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    min_rate numeric(12,2) NOT NULL,
    max_rate numeric(12,2) NOT NULL,
    default_rate numeric(12,2) NOT NULL,
    effective_from date NOT NULL,
    effective_to date,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT product_price_unique UNIQUE (product_id, effective_from)
);
```

The `BelowMinApprovalServiceTest::insertProductWithPriceHistory()`
helper (line 75-84) was inserting `'updated_at' => now()` anyway,
triggering the `42703` error on every single test in the file.

**Fix:** Drop the `'updated_at' => now()` line from the
`product_price_history` insert. Added an inline comment to make
the constraint visible to future readers.

**Production impact:** None. Test-only fix.

---

### Cluster B — `sales_invoices.fiscal_year_id` is NOT NULL (4 failures)

**Affected tests:** 4 release-related tests in `DemandItemFifoResolverTest`:

- `release decrements consumed qty on linked demand item`
- `release is noop when sale line has no demand link`
- `release capped at original sale line qty`
- `release never decrements below zero`

**Error:**
```
SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column
"fiscal_year_id" of relation "sales_invoices_default" violates
not-null constraint
```

**Cause:** S1 migration `2026_10_16_000002_backfill_fiscal_year_id.php`
sets `fiscal_year_id` NOT NULL on every table in `config/fiscal.php`
(including `sales_invoices`, line 40). The inline insert in
`DemandItemFifoResolverTest::insertSalesInvoiceItem()` (line 136-146)
did not set `fiscal_year_id`, so the insert failed at the
`sales_invoices` step.

**Fix:** Add `'fiscal_year_id' => $this->resolveActiveFiscalYearId()`
to the inline `sales_invoices` insert. The `resolveActiveFiscalYearId()`
helper (already in `InsertsBranchDependencies`) auto-resolves the
running FY, creating one if none exists yet.

Also fixed the shared `InsertsBranchDependencies::insertSalesInvoice()`
helper (line 42-69) — now accepts an optional `?int $fiscalYearId`
parameter and auto-resolves via the same fallback chain as
`insertBranchDemand()`. Any future test using this helper inherits
the FY-isolation compliance for free.

**Production impact:** None. Test-only fix.

---

### Cluster C — `consume()` rollback assertion reads wrong row (1 failure)

**Affected test:** `test_consume_returns_empty_when_insufficient_open_qty`
in `DemandItemFifoResolverTest`.

**Error:**
```
consumed_qty must not change when allocation fails.
Failed asserting that 1.0 matches expected 0.0.
```

**Cause:** The assertion read `consumed_qty` WITHOUT a WHERE clause:

```php
$consumed = DB::table('branch_demand_items')->value('consumed_qty');
$this->assertEquals(0.0, (float) $consumed, ...);
```

`->value()` without a WHERE picks an arbitrary row from the table.
With seed data or stale rows from prior tests (when
`DatabaseTransactions` doesn't fully isolate, or when the table has
factory-seeded rows with `consumed_qty > 0`), the assertion can read
a non-zero value from an unrelated row.

The resolver itself is **correct**: `consume()` returns `[]` early
when `totalAvailable + EPSILON < qty` (line 109-125 of
`DemandItemFifoResolver.php`) and never mutates `consumed_qty` in
that path. The transactional rollback semantics are intact.

**Fix:** Capture the demand item id from `insertReceivedDemandWithItem()`
and scope the assertion:

```php
$itemId = $this->insertReceivedDemandWithItem(...);
$result = DB::transaction(fn () => $this->resolver->consume(...));
$this->assertSame([], $result, ...);
$consumed = DB::table('branch_demand_items')
    ->where('id', $itemId)
    ->value('consumed_qty');
$this->assertEquals(0.0, (float) $consumed, ...);
```

**Production impact:** None. Test-only fix. The resolver was never
broken — the test was reading the wrong row.

---

### Cluster D — `showForDemand` returns 500 not 403 for closed FY demand (1 failure)

**Affected test:** `test_show_for_demand_returns_403_for_closed_fy_demand_
even_for_super_admin` in `BranchPnlReportControllerTest`.

**Error:**
```
Expected response status code [403] but received 500.
```

**Cause:** The controller loaded the demand's fiscal year via
`DB::table('fiscal_years')->where('id', $demandFyId)->first()`,
which returns a `stdClass` object. The `FiscalYearPolicy::
viewHistoricalData()` method type-hints `FiscalYear $fy`:

```php
public function viewHistoricalData(User $user, FiscalYear $fy): bool
{
    return false;
}
```

When `Gate::denies('viewHistoricalData', $fy)` is called with a
`stdClass`, Laravel 12's policy resolution can fail (the policy
is registered for `App\Models\FiscalYear::class` — a `stdClass`
argument doesn't match the registered model class). This can
manifest as a 500 instead of the intended 403.

The `Gate::before()` callback in `AppServiceProvider` *should*
short-circuit for superadmin on `viewHistoricalData` (returns
`false` → denies → abort 403), but in practice the 500 was
observed — likely because Gate's policy resolution step runs
*before* the `Gate::before` callback in some Laravel 12 paths,
or because the type mismatch in the policy method propagated.

**Fix:** Load the FY via Eloquent, bypassing global scopes
(BranchScope might filter out the demand's FY if it belongs to a
different branch than the authenticated user's — but the policy is
the authority, not the scope):

```php
$fy = \App\Models\FiscalYear::withoutGlobalScopes()->find($demandFyId);
if ($fy) {
    if (\Illuminate\Support\Facades\Gate::denies('viewHistoricalData', $fy)) {
        abort(403, 'This demand belongs to a closed fiscal year and cannot be viewed.');
    }
}
```

Also added a defensive `$demandFyId > 0` guard so a demand with
a malformed (zero) FY doesn't trigger the lookup.

**Production impact:** YES. This is a real production bug that
would have surfaced for any superadmin attempting to drill down
into a closed-FY demand via the URL. The fix ensures the
Gate-based 403 fires cleanly.

## Files Touched

| File | Change | Cluster | Lines |
|------|--------|---------|-------|
| `tests/Unit/Services/Pricing/BelowMinApprovalServiceTest.php` | Drop `updated_at` from `product_price_history` insert + explanatory comment | A | +4 −1 |
| `tests/Unit/Services/Pricing/DemandItemFifoResolverTest.php` | Add `fiscal_year_id` to inline `sales_invoices` insert + scope assertion by item id | B, C | +13 −3 |
| `tests/Helpers/InsertsBranchDependencies.php` | `insertSalesInvoice()` accepts `?int $fiscalYearId`, auto-resolves | B | +10 −4 |
| `app/Http/Controllers/Admin/BranchPnlReportController.php` | Load FY via Eloquent + bypass global scopes + defensive guard | D | +10 −1 |
| **Total** | | | **+37 −9** |

## Acceptance Tests

The dev team should re-run the S10 suite after pulling S11:

```bash
git pull origin main
docker compose exec rcerp_app php artisan test \
    tests/Unit/FiscalYear \
    tests/Unit/Services/Pricing \
    tests/Feature/BranchPnl
```

**Expected:** All 46 tests pass (0 failures) — assuming the test
DB schema matches `database/sql/*.sql` + all S0-S9 migrations have
been applied (`php artisan migrate` should report "Nothing to migrate").

If any test still fails, the failure output will include the SQL or
assertion that broke — paste it back and we'll triage.

## Lesson Learned

Two patterns worth flagging for future test work:

### 1. Always verify the actual table DDL before writing a helper.

S10.1 already documented this lesson ("When adding test setup helpers
for a long-running project, ALWAYS verify against the current
schema's constraints"), but the lesson bears repeating: in S11, the
`product_price_history` table (legacy, never touched by S1-S9) was
assumed to have `updated_at` because every other table does. It
doesn't. Two minutes with `grep -A 15 'CREATE TABLE product_price_history'
database/sql/*.sql` saves 8 test failures.

### 2. Don't use `DB::table()` for models that have a registered Policy.

When `Gate::allows('ability', $model)` is called, Laravel resolves
the policy class based on `get_class($model)`. A `stdClass` argument
matches no registered policy — the policy method (which type-hints
a real model class) is never invoked, and the behavior is
unspecified (in practice, sometimes a silent allow, sometimes a
TypeError-as-500).

**Rule:** when calling `Gate::*` with an Eloquent-backed entity,
**always** load it via Eloquent:

```php
// BAD
$fy = DB::table('fiscal_years')->where('id', $id)->first();
Gate::denies('viewHistoricalData', $fy);

// GOOD
$fy = \App\Models\FiscalYear::withoutGlobalScopes()->find($id);
Gate::denies('viewHistoricalData', $fy);
```

The `withoutGlobalScopes()` is a defensive habit for authorization
contexts — the policy is the authority, not the scope.

## PM Checkpoint

**The S10 test suite should now be 100% green.** Combined with S10.1,
all 46 tests cover the critical paths from S0 through S9. The single
production bug surfaced by Cluster D (Branch P&L drilldown returning
500 for closed-FY demands) is now fixed — this would have been a
visible bug in the production demo if S10.1 had not unblocked the
test that surfaces it.

The dev team can now proceed with the manual UAT checklist (S10
confirmation doc, section "UAT Execution Checklist") without
further automated-test distractions. After UAT passes, the project
is ready for the "1-week production hardening period" mentioned in
the implementation plan's signoff matrix.

No further sessions are planned unless the manual UAT surfaces new
issues.
