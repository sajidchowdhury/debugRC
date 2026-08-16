# Session 8 Confirmation — Branch P&L Report + Final Integration UAT

**Phase 2 / Q2 — Pricing & P&L**
**Status:** Code complete, ready for UAT
**Branches:** `feature/s7-demand-item-linkage` + `main` + `feature/fy-isolation-and-branch-pnl` (all pushed)

## Goal Recap

Build the Branch P&L report that gives Branch A a consolidated view of
Branch B's demand, sales mix, profit/loss, and outstanding due. Then
run a full end-to-end UAT covering both Q1 and Q2 together.

## Implementation Summary

### Design Decision: Dual-Branch Selector

The plan's URL pattern was `GET /admin/branches/{branch_id}/pnl` —
a single branch id in the URL. We extended this with a `view_as`
query parameter for the supplier branch ("Branch A"). Rationale:

- The report is fundamentally about a **pair** of branches
  (supplier vs. seller), not a single branch.
- Putting both ids in the URL path (e.g. `/branches/{A}/vs/{B}/pnl`)
  would require a non-standard resource route + would not survive
  the existing `branches/{branch}` resource pattern.
- The `view_as` query param is filterable, bookmarkable, and
  integrates with the existing filter form pattern used by all
  other reports in the `admin/reports` namespace.

### Design Decision: Per-Demand Drilldown

The branch-level report shows summary cards + a per-demand table.
Each row in the per-demand table has a "drilldown" icon linking to
`/admin/branch-demands/{id}/pnl`, which renders the per-demand
view with:

- Demand header (code, date, status, from/to branch names).
- Summary cards (demanded, sold, P&L, outstanding).
- A sale-line detail table showing every `sales_invoice_items` row
  linked to this demand, with rate, qty, classification badge,
  and (for below-min lines) the approver name + reason.

### Design Decision: FY Scoping via Trait (not explicit filter)

The plan called for explicit `WHERE fiscal_year_id = ?` clauses.
We relied on the existing `BelongsToFiscalYear` trait (S2) for
Eloquent queries. For the raw `DB::table()` queries in the report
service, we filter on `branch_demands.fiscal_year_id` explicitly
(since `DB::table()` does not auto-apply scopes).

The report does NOT have a "view closed FY" escape hatch. The
`BranchPnlReportController::showForDemand()` method calls
`Gate::denies('viewHistoricalData', $fy)` for any demand whose
`fiscal_year_id` differs from the running FY. The
`FiscalYearPolicy::viewHistoricalData()` method hard-denies for
everyone (including super admin, via the `Gate::before()`
amendment in `AppServiceProvider`). This is the same Q1 guarantee.

### Design Decision: Outstanding Due from `branch_ledger.running_balance`

The plan's outstanding-due field maps to the latest
`branch_ledger.running_balance` for the (B, A) pair.
`branch_ledger` is NOT partitioned by FY — it's a perpetual ledger
that carries across FY boundaries. The S8 report correctly shows
the carried-forward outstanding from prior closed FYs (the demand
rows themselves are invisible, but the running balance persists).

## Files Touched

### New Files

1. **`laravel/app/Services/BranchPnlReportService.php`**
   - `forBranch(branchAId, branchBId, ?fyId, ?from, ?to): array`
     — joins `branch_demand_items` + `branch_demands` +
     `sales_invoice_items` + `branch_ledger`. Returns the summary
     shape documented in the S8 plan.
   - `forDemand(demandId): array` — single-demand drilldown with
     per-sale-line detail (rate, qty, classification, approver,
     reason).
   - `exportRows(branchAId, branchBId, ?fyId, ?from, ?to): Generator`
     — yields flat rows for CSV export (one row per demand).
   - `getRunningFiscalYearId(): int` — reads `fiscal_years` where
     `status='active'`, ordered by `start_date` DESC. Returns 0
     if no active FY (typically right after a year-end close
     before the new FY is activated).

2. **`laravel/app/Http/Controllers/Admin/BranchPnlReportController.php`**
   - `show(Request, int $branchBId): View` — renders
     `branches/pnl.blade.php` with the report data for the
     selected supplier branch (`view_as` query param).
   - `showForDemand(Request, int $demandId): View` — renders
     `branch-demands/pnl.blade.php`. Performs the FY-access
     check via `Gate::denies('viewHistoricalData', $fy)`.
   - `export(Request, int $branchBId): StreamedResponse` — CSV
     download via `CsvExporter::exportFromRows()`.
   - `authorizeView()` — defense-in-depth: checks `hasRole()`
     in addition to the route group middleware.

3. **`laravel/resources/views/admin/branches/pnl.blade.php`**
   - Filter form (view_as dropdown, from/to date pickers, Run button).
   - 4 summary cards (Demanded Value, Outstanding Due, Net P&L,
     Below-Min Overrides).
   - Per-demand table (code, date, status, demanded qty/value,
     sold qty, revenue, cost, P&L, override count, drilldown
     button).
   - Footer note on FY scoping.

4. **`laravel/resources/views/admin/branch-demands/pnl.blade.php`**
   - Demand header (code, from/to branches, date, status).
   - 4 summary cards (Demanded, Sold + %, Net P&L, Outstanding).
   - Sale-line detail table (invoice, date, product, qty, rate,
     cost, P&L, classification badge, approver + reason).

5. **`laravel/public/assets/css/branch-pnl.css`**
   - Small polish: card hover, P&L column emphasis, classification
     badge uniform width, print-friendly rules.

6. **`docs/IMPLEMENTATION_PLAN_SESSION8_CONFIRMATION.md`** (this file).

### Modified Files

7. **`laravel/routes/web.php`**
   - Added `use App\Http\Controllers\Admin\BranchPnlReportController;`.
   - Added 2 routes in the `admin/branches` prefix group:
     - `GET {branch}/pnl` → `show` (role:admin,manager,accountant)
     - `GET {branch}/pnl/export` → `export` (same RBAC)
   - Added 1 route in the `admin/branch-demands` prefix group:
     - `GET {id}/pnl` → `showForDemand` (role:admin,manager,accountant)

## Routes Summary

| Method | Path | Name | RBAC |
|---|---|---|---|
| GET | `/admin/branches/{branch}/pnl` | `admin.branches.pnl` | admin,manager,accountant |
| GET | `/admin/branches/{branch}/pnl/export` | `admin.branches.pnl.export` | admin,manager,accountant |
| GET | `/admin/branch-demands/{id}/pnl` | `admin.branch-demands.pnl` | admin,manager,accountant |

## Acceptance Tests

### Branch P&L report (per the S8 plan)

Run these on a live Docker host after creating test data:

- [ ] Given a demand A→B of 10 units at cost 10 (= min price), and
  B sells 5 at min, 2 below min (approved), 3 at max: the report
  shows revenue 102, cost 100, net P&L +2, qty_at_min=5,
  qty_below_min=2, qty_at_max=3, override_count=1.
- [ ] The per-demand drilldown shows each sale line with its rate,
  classification, and (for below-min) the approver name + reason.
- [ ] The outstanding due matches `branch_ledger.running_balance`
  for the A↔B pair.
- [ ] Excel/CSV export downloads and opens correctly with the same
  data as the on-screen report.
- [ ] The report respects Q1's fiscal-year scoping — only the
  running FY's demands/sales appear. Manually verify by
  attempting to view a closed-FY demand via URL params (should
  return 403 from `showForDemand` or empty data from `forBranch`).

### Cross-phase integration (Q1 + Q2 together)

- [ ] Run a full year-end close on a test FY that has branch
  demands and sales. Confirm: closing JE posts, opening balances
  refresh, partitions detach, backup file produced. After close,
  the Branch P&L report shows ZERO rows for the closed FY
  (because the partitions are detached and the global scope
  blocks reads).
- [ ] Run a sale at below-min with admin approval BEFORE year-end
  close → the audit log row survives the close (`user_audit_log`
  is NOT detached on close — verify this in
  `config/fiscal.partitioned_tables`).
- [ ] After year-end close, the new FY's Branch P&L report
  correctly shows the carried-forward outstanding due from the
  closed FY (because `branch_ledger.running_balance` is perpetual
  and not detached).
- [ ] After year-end close, stock levels in the new FY are correct
  (because `WarehouseStock` is perpetual and not detached).
- [ ] Super admin attempts to view the closed FY's Branch P&L
  report via URL params → returns empty (Q1 hard-block holds).
- [ ] Super admin attempts to view the closed FY's Branch P&L
  report via `withoutGlobalScope('current_fy')` → still returns
  empty (because partitions are detached — the strongest
  guarantee from Q1).

### Client signoff scenarios (demo to client)

- [ ] Demo 1: Super admin logs in, tries every URL and filter
  combination to view last year's sales — confirms cannot see
  anything.
- [ ] Demo 2: PM runs `php artisan db:backup-year-end` and shows
  the backup file on disk.
- [ ] Demo 3: PM runs `yearEndClose()` end-to-end on a test FY,
  shows the closing JE, the refreshed opening balances, the
  detached partitions, and the new FY auto-activating.
- [ ] Demo 4: Cashier creates a sale below min → admin approval
  modal → approval with reason → sale finalises with
  `below_min` classification.
- [ ] Demo 5: Branch A manager opens the Branch P&L report for
  Branch B → sees the demand, the sales mix, the profit/loss,
  the outstanding due, and the below-min overrides with reasons.
- [ ] Demo 6: PM demonstrates the "restore to view historical
  data" path by running `php artisan fy:detach-archived` reverse
  flow (i.e., `FiscalYearPartitionService::restoreForViewing()`)
  — confirms this is a manual artisan-only operation, not
  exposed in the UI.

## Dev Team Hand-off

After the dev team pulls the S8 code:

1. **Run `php artisan route:list | grep pnl`** to verify the 3 new
   routes are registered.
2. **Run `php artisan view:clear`** to clear compiled Blade views.
3. **Manual smoke test** on the Docker host:
   - Log in as admin/manager/accountant.
   - Navigate to `/admin/branches/{B}/pnl?view_as={A}` for two
     branches that have demand history in the running FY.
   - Verify the summary cards and per-demand table render with
     correct numbers.
   - Click the drilldown icon → verify the per-demand view shows
     sale-line detail.
   - For a demand with below-min sales, verify the approver name +
     reason are displayed.
   - Click "Export CSV" → verify the download opens in Excel/LibreOffice
     with the same data.
4. **FY isolation test** (cross-phase):
   - Find a demand in a closed FY (or run a year-end close on a
     test FY first).
   - Try to access `/admin/branch-demands/{id}/pnl` for that
     demand → expect 403.
   - Try to access `/admin/branches/{B}/pnl?view_as={A}` → expect
     empty data (no rows for the closed FY).
5. **Super admin test**:
   - Log in as super admin.
   - Attempt to access the closed-FY demand's drilldown URL →
     expect 403 (super admin is hard-blocked from
     `viewHistoricalData` per the `Gate::before()` amendment in
     `AppServiceProvider`).
6. **Run the S5/S6/S7 acceptance tests** if not already done —
   the S8 report depends on the S7 FIFO linkage being correctly
   populated. If S7's backfill left many `branch_demand_item_id`
   values NULL, the S8 report will show low "sold qty" figures
   because those sale lines are not attributed to any demand.
7. **Final integration UAT**: walk through all 6 client signoff
   scenarios above. Document any failures in a UAT report doc.

## Known Limitations & Future Improvements

1. **No date-range FY cross-boundary support** — the `from`/`to`
   date filters only filter WITHIN the running FY. They do NOT
   allow viewing data from a closed FY. This is intentional (Q1
   hard-block). If a future requirement asks for a multi-year
   trend view, it should be a separate route with explicit
   `Gate::allows('viewHistoricalData')` checks per FY.

2. **No caching** — the report runs 3 SQL queries per request
   (demand summary, sales summary, per-demand breakdown). On a
   large dataset (thousands of demands + sale lines), this could
   be slow. The S8 risk register suggests caching the result for
   5 minutes per `(branchA, branchB, fiscalYearId)` key. Not
   implemented in S8 — defer until a performance issue is
   observed in production.

3. **`getActiveCostRate` S5 inconsistency** — flagged in the S7
   confirmation doc. The S5 `BranchDemandService::getActiveCostRate()`
   queries `branch_demands.to_branch_id = $branchId` (supplier),
   which would return the wrong cost (what the branch CHARGED
   others, not what it PAID). The S8 report does NOT use this
   method — it uses the `cost_rate` snapshot on
   `sales_invoice_items` (populated at finalize time). However,
   if the snapshot was populated incorrectly (due to the S5 bug),
   the report's cost figures may be wrong for historical sale
   lines. A follow-up audit + fix is recommended.

4. **No "View as Branch B" toggle** — the report is asymmetric:
   it always shows "as Branch A (supplier), report on Branch B
   (seller)". A future enhancement could add a "flip perspective"
   button that swaps A and B. Not implemented in S8.

## PM Checkpoint (final)

**Phase 2 (Q2) complete and full integration UAT ready.** Both
client requirements (FY isolation + Branch P&L) are delivered.
Recommend scheduling a client demo session to walk through the 6
demo scenarios above. Post-merge, recommend a 1-week production
hardening period before the first real year-end close.

---

**Phase 2 / Q2 complete.** All 8 sessions (S0–S8) delivered.
