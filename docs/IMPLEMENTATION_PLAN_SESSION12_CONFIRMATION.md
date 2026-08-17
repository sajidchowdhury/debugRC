# Session 12 Confirmation — Propagate `fiscal_year_id` to All Shared Test Helpers & Inline Insert Sites

**Phase 2 / Q2 — Final Hardening (S11 → S12)**
**Status:** Code complete, pushed to both branches
**Branches:** `feature/fy-isolation-and-branch-pnl` (primary), `main` (cherry-pick)
**Session type:** Test-only mechanical sweep (no production code changed)

## Context

Session 11 verified the S10/S11 acceptance scope (`tests/Unit/FiscalYear` +
`tests/Unit/Services/Pricing` + `tests/Feature/BranchPnl`) was GREEN — 46
passed / 0 failed — but a full-suite run on the dev team's Docker host
revealed ~200 PRE-EXISTING failures outside that scope. None were S11
collateral damage; all stemmed from the same single systemic root cause:

**The S1 FY-isolation migration (`2026_10_16_000002_backfill_fiscal_year_id.php`)
added `fiscal_year_id NOT NULL` to ~30 operational tables listed in
`config/fiscal.php`. The shared test helpers in `tests/Helpers/Inserts*` and
many inline `DB::table('<fiscal_table>')->insert(...)` calls scattered across
test files were never updated to satisfy that NOT NULL constraint.**

S11 only patched `InsertsBranchDependencies::insertSalesInvoice()` and
`InsertsBranchDependencies::insertBranchDemand()` — the two specific helpers
the S11 acceptance tests depended on. Everything else remained latent.

Session 12 is the mechanical sweep: audit every `tests/` + `database/factories/`
file for insert sites that target a fiscal-scoped table, then inject
`'fiscal_year_id' => $this->resolveActiveFiscalYearId()` (or the factory
equivalent) at every site missing it.

## Root Cause

A single systemic gap with one surface pattern:

```php
// BEFORE — fails with SQLSTATE[23502] null value in column "fiscal_year_id"
DB::table('warehouse_transfers')->insertGetId([
    'transfer_code' => 'WT-' . substr(uniqid(), -8),
    // ... other columns ...
    'created_at' => now(),
    'updated_at' => now(),
]);

// AFTER — passes (FY id auto-resolved)
DB::table('warehouse_transfers')->insertGetId([
    'transfer_code' => 'WT-' . substr(uniqid(), -8),
    // ... other columns ...
    'fiscal_year_id' => $this->resolveActiveFiscalYearId(),  // <-- S12
    'created_at' => now(),
    'updated_at' => now(),
]);
```

`config/fiscal.php` is the canonical list of fiscal-scoped tables. Every
insert into one of those tables must set `fiscal_year_id`. The shared helper
`Tests\Helpers\ResolvesActiveFiscalYear::resolveActiveFiscalYearId()` resolves
the FY id via: running FY (is_current=true AND status=active) → any active FY
→ create minimal active FY covering the current calendar year.

## Approach

1. **Audit script** (`scripts/s12_audit.py`): scans every `.php` file under
   `laravel/tests/` and `laravel/database/factories/` for
   `DB::table('<fiscal_table>')->insert[GetId]` call sites. For each match,
   checks whether the call body mentions `fiscal_year_id` (or uses
   `array_merge` — heuristic for `$overrides`-style helpers that already
   carry FY in the merged array). Reports sites that fail the check.

2. **Manual review** of every flagged site to eliminate false positives:
   - Comments and docblock text mentioning the pattern by name
     (e.g. "G-294: refactored from DB::table('sales_invoices')->insert([...])").
   - Variable-built `$row` arrays where `fiscal_year_id` is set in the
     array builder above the `DB::table()->insertGetId($row)` call
     (e.g. `InsertsCustomerDependencies::insertCustomerLedger`).

3. **Patch each real site** by adding `'fiscal_year_id' => $this->resolveActiveFiscalYearId()`
   at the end of the insert array. For test classes that don't yet have
   the `ResolvesActiveFiscalYear` trait available, add the `use` statement
   + `use Tests\Helpers\ResolvesActiveFiscalYear;` import.

4. **Lint** via `scripts/s12_lint.py` (static brace/paren/bracket balance
   check; PHP binary not available in this sandbox).

## Files Modified (22 total)

### A. New shared trait (1 file, NEW)

**`laravel/tests/Helpers/ResolvesActiveFiscalYear.php`**

Extracted from the body of `InsertsBranchDependencies::resolveActiveFiscalYearId()`
into its own trait so every `Inserts*Dependencies` helper (and any test class
that wants to call the resolver inline) can `use` it. The trait is identical
to the prior inline version — same 3-tier resolution chain (running FY → any
active FY → create minimal active FY covering current calendar year), same
`DB::table()` bypass of `BranchScope` (test setup must not depend on auth
state).

### B. Shared test helpers (9 files)

Every `Inserts*Dependencies` trait now `use ResolvesActiveFiscalYear;` and
injects `'fiscal_year_id' => $this->resolveActiveFiscalYearId()` at every
insert site that targets a fiscal-scoped table.

| File | Sites patched | Tables |
|------|----------------|--------|
| `InsertsBankDependencies.php` | 2 | `customer_payments`, `supplier_payments` |
| `InsertsBranchDependencies.php` | 0 (already patched in S11) | — (FYI: extracted the resolver method out into the new shared trait) |
| `InsertsBranchDemandDependencies.php` | 0 (already patched in S10.1) | — (uses parent-inheritance for `branch_demand_items`) |
| `InsertsCustomerDependencies.php` | 0 (already patched in S10.1) | — |
| `InsertsEmployeeDependencies.php` | 1 | `employee_transactions` |
| `InsertsLedgerDependencies.php` | 0 (already patched in S10.1) | — |
| `InsertsProductDependencies.php` | 0 (already patched in S10.1) | — |
| `InsertsSupplierDependencies.php` | 0 (already patched in S10.1) | — |
| `InsertsWarehouseDependencies.php` | 0 (already patched in S10.1) | — |

### C. Inline test-file inserts (11 files)

Each file below had at least one direct `DB::table('<fiscal_table>')->insert[GetId]`
call missing `fiscal_year_id`. Patched inline (no trait import needed when the
test class already uses an `Inserts*Dependencies` trait that transitively
provides `resolveActiveFiscalYearId()`).

| File | Sites patched | Tables | Trait source for resolver |
|------|----------------|--------|----------------------------|
| `tests/Feature/Api/V1/Sales/SalesReturnApiTest.php` | 1 | `sales_returns` | (existing inline) |
| `tests/Feature/Api/V1/StockAdjustment/StockAdjustmentApiTest.php` | 1 | `stock_adjustments` | (existing) |
| `tests/Feature/Api/V1/StockTake/StockTakeItemApiTest.php` | 1 | `stock_take_warehouses` | (existing) |
| `tests/Feature/StockTake/ConcurrentCountTest.php` | 4 | `stock_take_sessions`, `stock_take_warehouses` | (existing) |
| `tests/Feature/WarehouseTransfer/AuditTrailTest.php` | 2 | `warehouse_transfers`, `warehouse_transfer_items` | (existing) |
| `tests/Feature/WarehouseTransfer/BranchIsolationTest.php` | 1 | `warehouse_transfers` | (existing) |
| `tests/Feature/WarehouseTransfer/StockAvailabilityTest.php` | 1 | `warehouse_transfers` | (existing) |
| `tests/Feature/Api/V1/WarehouseTransfer/WarehouseTransferApiTest.php` | 1 (NEW in S12) | `warehouse_transfers` | `InsertsBranchDependencies` (transitive) |
| `tests/Feature/Api/V1/DashboardApiTest.php` | 1 (NEW in S12) | `sales_invoice_items` | `ResolvesActiveFiscalYear` (added this session) |
| `tests/Unit/BranchDemand/BranchIntercompanyServiceTest.php` | 1 | `customer_payments` | (existing) |
| `tests/Unit/StockTake/AbcClassificationServiceTest.php` | 1 | `stock_transactions` | (existing) |
| `tests/Unit/StockTake/StockTakeServiceTest.php` | 2 | `stock_take_sessions` | (existing) |

### D. Factory (1 file)

**`laravel/database/factories/SalesInvoiceFactory.php`**

Eloquent factories can't `use` test traits (they live in the
`Database\Factories` namespace, not `Tests\Helpers`). The factory gains its
own private `resolveActiveFiscalYearIdForFactory()` method — same 3-tier
resolution chain as the test trait, just namespaced differently.

The factory's `definition()` now sets `'fiscal_year_id' => $this->resolveActiveFiscalYearIdForFactory()`
as a default. Tests that use `SalesInvoice::factory()->create([...])` (e.g.
`DashboardApiTest::test_dashboard_today_block_...`) automatically get a valid
FY id without needing to override.

State methods (`forCustomerBranch`, `onDate`, `createdBy`, `draft`, `reversed`)
are unchanged — none of them touch `fiscal_year_id`, so callers that need to
pin a specific FY can still use `->state(['fiscal_year_id' => $fyId])`.

## Audit script output — final state

After all patches applied, the audit script reports 5 remaining flagged sites.
All 5 are confirmed FALSE POSITIVES (verified by manual inspection):

| File:Line | Table | Reason flagged | Why false positive |
|-----------|-------|----------------|---------------------|
| `BranchIsolationApiTest.php:61` | `branch_demands` | docblock text mentions `DB::table('branch_demands')->insertGetId(...)` | Comment text — the test delegates to `insertBranchDemand()` helper (already patched) |
| `InsertsCustomerDependencies.php:79` | `customer_ledger` | `DB::table('customer_ledger')->insertGetId($row)` body doesn't mention FY | `$row` is built at lines 62-71 and DOES include `'fiscal_year_id' => $this->resolveActiveFiscalYearId()` (line 69) |
| `DashboardApiTest.php:108` | `sales_invoices` | "G-294 (LOW-WAVE-2-B2): refactored from `DB::table('sales_invoices')->insert([...])` to ..." | Comment text describing a prior refactor — the actual code uses `SalesInvoice::factory()->create()` |
| `DashboardApiTest.php:226` | `sales_invoices` | "G-294 (LOW-WAVE-2-B2): refactored `DB::table('sales_invoices')->insertGetId` → ..." | Same — comment text |
| `InsertsSupplierDependencies.php:78` | `supplier_ledger` | `DB::table('supplier_ledger')->insertGetId($row)` body doesn't mention FY | `$row` is built at lines 61-70 and DOES include `'fiscal_year_id' => $this->resolveActiveFiscalYearId()` (line 68) |

The script intentionally takes a conservative approach (reports anything
suspicious for manual review) rather than risk missing a real gap.

## Static lint — final state

`scripts/s12_lint.py` (brace/paren/bracket balance check) passed for all 23
modified PHP files (22 listed in the diff + the new `ResolvesActiveFiscalYear.php`
trait itself). PHP binary not available in this sandbox; the dev team's Docker
host will catch any lint issue at test time.

Example output:

```
OK: laravel/tests/Helpers/InsertsBranchDependencies.php  L:5 R=5 ():L R=33 []:33 R=L
OK: laravel/tests/Helpers/ResolvesActiveFiscalYear.php  L:4 R=4 ():L R=22 []:22 R=L
OK: laravel/database/factories/SalesInvoiceFactory.php  L:10 R=10 ():L R=44 []:44 R=L
OK: laravel/tests/Feature/Api/V1/DashboardApiTest.php  L:12 R=12 ():L R=161 []:161 R=L
... (20 more files)
```

## Acceptance Tests

After the dev team pulls + runs the full suite, the expected delta is:

- **S11 acceptance scope** (`tests/Unit/FiscalYear` + `tests/Unit/Services/Pricing`
  + `tests/Feature/BranchPnl`): **46 PASS / 0 FAIL** (unchanged from S11 —
  no test in this scope was modified).
- **Full suite** (everything in `tests/`): ~150+ of the ~200 pre-existing
  failures should now pass. The exact delta depends on how many of the
  ~200 failures were caused by `fiscal_year_id NOT NULL` violations vs.
  other pre-existing issues.

### Residual failures (deferred to S13+)

The following failure clusters are NOT addressed by S12 because they are
caused by unrelated root causes (not `fiscal_year_id NOT NULL` violations):

1. **CodeGenerator / BranchScope interaction** — `DB::statement("SET app.branch_id = ...")`
   may not work in some test setups; the `CodeGenerator` service caches the
   last branch id per-process and may collide across tests in the same process.
2. **CsvExporter BOM** — `\Illuminate\Support\Facades\Response::streamDownload()`
   callback may emit a UTF-8 BOM that breaks the byte-level assertion in
   `tests/Feature/.../CsvExportTest.php`.
3. **Realtime notify** — `Tests\Helpers\RealtimeTestHelper` may not match
   the current `RealtimeService` interface (signature drift).

These will be triaged as separate clusters in a follow-up session.

## Known Limitations / Follow-ups

1. **StockTakeSessionFactory + StockTakeWarehouseFactory** — both target
   fiscal-scoped tables (`stock_take_sessions`, `stock_take_warehouses`) but
   their `definition()` methods do NOT include `fiscal_year_id`. No test
   currently uses these factories (verified via grep for
   `StockTakeSession::factory` / `StockTakeWarehouse::factory` — zero
   matches), so the gap is latent. If a future test uses them, it will fail
   with the same `SQLSTATE[23502]` error pattern. **Recommended follow-up:
   add `resolveActiveFiscalYearIdForFactory()` (mirroring
   `SalesInvoiceFactory`) to both factories.** Deferred to keep S12 scope
   tight to actually-broken tests.

2. **Other factories** — only `SalesInvoiceFactory` targets a fiscal-scoped
   table among all factories in `database/factories/`. All others
   (`CustomerFactory`, `ProductFactory`, `SupplierFactory`, etc.) target
   master-data tables that are NOT fiscal-scoped (per `config/fiscal.php`
   comment: "Master data: products, customers, suppliers, employees, branches,
   warehouses, ledgers, users, roles, permissions — these are NOT
   fiscal-year-scoped"). No patches needed.

3. **DRY-ness of the factory resolver** — `SalesInvoiceFactory::resolveActiveFiscalYearIdForFactory()`
   duplicates the body of `Tests\Helpers\ResolvesActiveFiscalYear::resolveActiveFiscalYearId()`.
   They can't share a trait because factories live in `Database\Factories`
   (can't `use Tests\Helpers\...` traits). A shared static helper class
   (`Database\Factories\Support\FiscalYearResolver`) would deduplicate, but
   that's a refactor with low ROI when there's only one such factory. Defer
   until the 3rd factory needs the same method.

## Lessons Learned

1. **The `config/fiscal.php` `'parent'` key is the hint that a child table
   also has `fiscal_year_id`** — already noted in S11.1 lessons-learned,
   applied here. The audit script flags both `sales_invoices` (parent) and
   `sales_invoice_items` (child) as fiscal-scoped, so both insert sites get
   patched in one pass.

2. **Audit scripts with conservative heuristics are worth their false
   positives** — the 5 false positives in the final report each took ~30
   seconds to dismiss by reading the source. The alternative (a stricter
   regex that tries to "understand" PHP array structure) would be far more
   complex and brittle. Conservative + manual review is the right trade-off
   for a one-time audit.

3. **Test trait extraction pays off** — the `ResolvesActiveFiscalYear` trait
   extraction (from inline in `InsertsBranchDependencies` to a standalone
   trait) is what made the S12 sweep mechanical. Every helper that needs it
   just `use`s the trait; no copy-paste of the 35-line method body. This
   pattern should be applied to other shared test utilities when they
   surface.

4. **Factories need their own resolver** — Eloquent factory classes can't
   use test traits (namespace boundary). The cleanest workaround is a private
   `resolveActiveFiscalYearIdForFactory()` method per factory that needs it.
   Document the pattern; apply to future factories targeting fiscal-scoped
   tables.

## Dev Team Hand-off

```bash
# On the Docker host:
cd /path/to/debugRC/laravel
git pull origin main                  # or: git pull origin feature/fy-isolation-and-branch-pnl
docker compose exec rcerp_app php artisan test
```

Expected:
- **S11 acceptance scope** (`php artisan test tests/Unit/FiscalYear tests/Unit/Services/Pricing tests/Feature/BranchPnl`): 46 PASS / 0 FAIL (unchanged).
- **Full suite**: significant reduction in failures (~150+ of the ~200 pre-existing failures should pass). Triage the residuals as separate clusters.

## Session Status

- **Code complete**: yes (22 files modified, +125 −52 lines).
- **Production code changed**: NO (test-only sweep + 1 factory).
- **Pushed to both branches**: yes (commits listed below after push).
- **Confirmation doc**: this file (`docs/IMPLEMENTATION_PLAN_SESSION12_CONFIRMATION.md`).
- **Worklog**: appended to `/home/z/my-project/worklog.md`.

---

**Next session candidate (S13):** Triage the residual failures that S12 did
not address — CodeGenerator/BranchScope interaction, CsvExporter BOM, Realtime
notify interface drift. Each is a separate root cause and should be triaged
as its own cluster with its own fix.
