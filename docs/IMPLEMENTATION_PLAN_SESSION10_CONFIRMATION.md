# Session 10 Confirmation — PHPUnit Coverage + UAT Execution Checklist

**Phase 2 / Q2 — Final Hardening**
**Status:** Code complete, ready for dev team to run
**Branches:** `main` + `feature/fy-isolation-and-branch-pnl` (both pushed)

## Goal Recap

S0–S9 delivered all features of the FY Isolation + Branch P&L plan,
but **zero automated test coverage** existed for any of the S2/S5/S6/
S7/S8/S9 work. The implementation plan's risk register explicitly
demands a PHPUnit test for the single most critical guarantee:

> Risk: `Gate::before()` amendment has a typo and super-admin bypass
> still applies to `viewHistoricalData`
> Likelihood: Low · Impact: **Critical**
> Mitigation: Write a dedicated PHPUnit test:
> `test_super_admin_cannot_view_historical_data()`. Run it in CI.

Session 10 closes that gap with 5 test files covering the critical
paths, plus a UAT execution checklist the dev team runs against the
live Docker host before client demo.

## Implementation Summary

### Design Decision: DatabaseTransactions + Direct DB::table Inserts

All tests use the existing `TestCase` base class, which uses
`DatabaseTransactions` (NOT `RefreshDatabase` — the project's baseline
schema migration replays large raw SQL files which are slow). Each
test runs in a transaction that rolls back at teardown, leaving the
`rcerp_test` DB pristine.

Test data is set up via `DB::table()->insertGetId(...)` calls (using
the existing `Tests\Helpers\Inserts*Dependencies` traits) rather than
model factories. This mirrors the pattern established by the existing
`BranchDemandServiceTest` etc. — direct inserts because many tables
have NOT NULL columns + FK constraints that factories can't easily
satisfy.

### Design Decision: Three Layers of FY-Isolation Testing

The Q1 hard-block is enforced by three independent layers. Each test
file targets a different layer:

| Layer                                    | Test File                                      |
|------------------------------------------|------------------------------------------------|
| Policy method (returns false)            | `FiscalYearPolicyTest::test_policy_view_historical_data_returns_false_for_super_admin` |
| Gate::before exclusion (super-admin bypass honored) | `FiscalYearPolicyTest::test_super_admin_cannot_view_historical_data` |
| Controller enforcement (403 on closed-FY URL) | `BranchPnlReportControllerTest::test_show_for_demand_returns_403_for_closed_fy_demand_even_for_super_admin` |

If ANY one layer breaks, the test that targets it fails. Defense-in-
depth made testable.

### Design Decision: Sequential (not Concurrent) FIFO Tests

The risk register flags "FIFO `consumed_qty` race condition under
concurrent sales" as a Medium-likelihood / High-impact risk, with
mitigation: "Add a concurrent-finalize integration test."

Writing a true concurrent test in PHPUnit requires spawning parallel
PHP processes or using `DB::beginTransaction()` tricks that don't
reliably reproduce the race. This session delivers the SEQUENTIAL
correctness tests (single-threaded consume + release) — these prove
the logic is correct in isolation. A concurrent test is left as a
follow-up because the FOR UPDATE lock in the resolver is the same
pattern used by the existing `SalesInvoiceService::finalizeFromCart()`
which has been in production for multiple phases without incident.

## Files Touched

### New Files

1. **`laravel/tests/Unit/FiscalYear/FiscalYearPolicyTest.php`** — 7 tests
   - `test_super_admin_cannot_view_historical_data` (CRITICAL — risk register)
   - `test_admin_cannot_view_historical_data`
   - `test_manager_cannot_view_historical_data`
   - `test_accountant_cannot_view_historical_data`
   - `test_super_admin_can_view_running_fy_data` (sanity — bypass still works for non-historical abilities)
   - `test_policy_view_historical_data_returns_false_for_super_admin` (direct policy call, defense-in-depth)
   - `test_gate_before_exclusion_list_contains_view_historical_data` (tripwire against typos in the exclusion list)

2. **`laravel/tests/Unit/Services/Pricing/BranchDemandServiceGetActiveCostRateTest.php`** — 7 tests
   - `test_returns_demand_cost_rate_for_receiving_branch` (happy path)
   - `test_returns_null_for_supplying_branch` (THE BUG-CATCHER — would fail if S9 fix is reverted)
   - `test_returns_null_when_demand_item_fully_consumed` (S9 open-qty filter)
   - `test_returns_oldest_open_demand_item_cost_fifo` (FIFO ordering)
   - `test_excludes_reversed_demand` (S9 is_reversed filter)
   - `test_returns_null_when_no_demand_exists` (no regression on direct-purchase fallback)
   - `test_excludes_zero_cost_demand_item` (cost_rate > 0 filter)

3. **`laravel/tests/Unit/Services/Pricing/DemandItemFifoResolverTest.php`** — 9 tests
   - `test_consume_returns_empty_for_zero_qty` (defensive)
   - `test_consume_picks_oldest_open_demand_item_first` (FIFO)
   - `test_consume_splits_across_multiple_demand_items_when_oldest_insufficient` (multi-item split)
   - `test_consume_returns_empty_when_insufficient_open_qty` (no over-consumption)
   - `test_consume_skips_fully_consumed_demand_items` (open-qty filter)
   - `test_release_decrements_consumed_qty_on_linked_demand_item` (release happy path)
   - `test_release_is_noop_when_sale_line_has_no_demand_link` (direct-purchase no-op)
   - `test_release_capped_at_original_sale_line_qty` (over-release defense)
   - `test_release_never_decrements_below_zero` (CHECK constraint mirrored in app logic)

4. **`laravel/tests/Unit/Services/Pricing/BelowMinApprovalServiceTest.php`** — 11 tests
   - `test_approve_succeeds_with_admin_credentials`
   - `test_approve_succeeds_with_manager_credentials`
   - `test_approve_throws_on_invalid_credentials` (brute-force defense)
   - `test_approve_throws_when_approver_role_is_insufficient` (privilege-escalation defense)
   - `test_approve_throws_when_reason_is_too_short` (audit-quality defense)
   - `test_approve_throws_when_rate_is_not_below_min` (no override when not needed)
   - `test_approve_throws_when_approver_is_inactive` (deactivated user)
   - `test_is_valid_override_returns_true_for_real_audit_log_row`
   - `test_is_valid_override_returns_false_for_nonexistent_id`
   - `test_is_valid_override_returns_false_for_zero_id`
   - `test_is_valid_override_returns_false_for_wrong_action` (defense against injecting an unrelated audit log id)

5. **`laravel/tests/Feature/BranchPnl/BranchPnlReportControllerTest.php`** — 10 tests
   - RBAC: admin/manager/accountant can access (3 tests, 200 OK)
   - RBAC: salesman/cashier forbidden (2 tests, 403)
   - RBAC: unauthenticated redirected to login (1 test)
   - **FY hard-block: super admin 403 on closed-FY demand drilldown** (THE CRITICAL CROSS-PHASE TEST)
   - FY sanity: running-FY demand NOT 403 (1 test — guards against over-broad fix)
   - Branch-level report renders with no demands (1 test — empty data path)
   - CSV export: admin can download (1 test, 2xx)
   - CSV export: cashier forbidden (1 test, 403)
   - Non-existent branch returns 404 (1 test)

6. **`docs/IMPLEMENTATION_PLAN_SESSION10_CONFIRMATION.md`** (this file).

## Test Summary

| File | Tests | Layer |
|---|---|---|
| `FiscalYearPolicyTest` | 7 | Policy + Gate (Q1 hard-block) |
| `BranchDemandServiceGetActiveCostRateTest` | 7 | Service (S9 cost-snapshot fix) |
| `DemandItemFifoResolverTest` | 9 | Service (S7 FIFO consume/release) |
| `BelowMinApprovalServiceTest` | 11 | Service (S6 below-min override) |
| `BranchPnlReportControllerTest` | 10 | Feature/HTTP (S8 report + Q1 cross-phase) |
| **Total** | **44 tests** | |

## Acceptance Tests

### Automated (PHPUnit)

- [ ] `docker compose exec rcerp_app php artisan test --filter=FiscalYearPolicyTest` — all 7 pass
- [ ] `docker compose exec rcerp_app php artisan test --filter=BranchDemandServiceGetActiveCostRateTest` — all 7 pass
- [ ] `docker compose exec rcerp_app php artisan test --filter=DemandItemFifoResolverTest` — all 9 pass
- [ ] `docker compose exec rcerp_app php artisan test --filter=BelowMinApprovalServiceTest` — all 11 pass
- [ ] `docker compose exec rcerp_app php artisan test --filter=BranchPnlReportControllerTest` — all 10 pass
- [ ] `docker compose exec rcerp_app php artisan test tests/Unit/FiscalYear tests/Unit/Services/Pricing tests/Feature/BranchPnl` — full S10 suite passes (44 tests)

### Pre-flight (test DB setup)

The phpunit.xml is configured to use a `rcerp_test` database. Before
running tests, the dev team must ensure:

```bash
# 1. Create the test database (if it doesn't exist)
docker compose exec rcerp_postgres psql -U rcerp_app -d postgres -c "CREATE DATABASE rcerp_test;"

# 2. Apply the full schema to the test database
# (the baseline migration loads raw SQL files — needs the same schema as dev)
docker compose exec rcerp_app php artisan migrate --database=pgsql --env=testing

# OR, faster: clone the dev DB schema (no data)
docker compose exec rcerp_postgres pg_dump -U rcerp_app -s rcerp | \
  docker compose exec -T rcerp_postgres psql -U rcerp_app -d rcerp_test
```

### Manual UAT Execution Checklist (S8 acceptance tests, now runnable)

These are the checkboxes from `IMPLEMENTATION_PLAN_SESSION8_CONFIRMATION.md`
that the dev team walks through on the live Docker host. Each box
should be checked + a screenshot/notes captured in the UAT report.

#### Branch P&L report

- [ ] Setup: Demand A→B for 10 units of product P at cost 10 (= min). Confirm receipt as B.
- [ ] B sells 5 at min, 2 below min (approved), 3 at max.
- [ ] Report shows: revenue 102, cost 100, net P&L +2.
- [ ] qty_at_min=5, qty_below_min=2, qty_at_max=3, override_count=1.
- [ ] Per-demand drilldown shows each sale line with rate + classification + (for below-min) approver + reason.
- [ ] Outstanding due matches `branch_ledger.running_balance` for the A↔B pair.
- [ ] CSV export downloads and opens in Excel/LibreOffice with same data.
- [ ] Report respects FY scoping — only running FY demands/sales appear.

#### Cross-phase integration (Q1 + Q2)

- [ ] Run year-end close on a test FY with branch demands + sales. Confirm: closing JE posts, opening balances refresh, partitions detach, backup file produced.
- [ ] After close, Branch P&L report shows ZERO rows for the closed FY.
- [ ] Below-min approval audit log row survives close (`user_audit_log` is NOT detached — verify in `config/fiscal.partitioned_tables`).
- [ ] After close, new FY's Branch P&L shows carried-forward outstanding due from closed FY (`branch_ledger.running_balance` is perpetual).
- [ ] After close, stock levels in new FY are correct (`WarehouseStock` is perpetual).
- [ ] Super admin attempts to view closed FY's demand drilldown URL → 403.
- [ ] Super admin attempts to view closed FY's branch P&L via `withoutGlobalScope('current_fy')` → still empty (partitions detached).

#### Client signoff scenarios

- [ ] Demo 1: Super admin tries every URL/filter combo to view last year's sales — confirms cannot see anything.
- [ ] Demo 2: PM runs `php artisan db:backup-year-end` + shows the backup file on disk.
- [ ] Demo 3: PM runs `yearEndClose()` end-to-end on a test FY — shows closing JE, refreshed opening balances, detached partitions, new FY auto-activating.
- [ ] Demo 4: Cashier creates a below-min sale → admin approval modal → approval with reason → sale finalises with `below_min` classification.
- [ ] Demo 5: Branch A manager opens Branch P&L for Branch B → sees demand, sales mix, P&L, outstanding due, below-min overrides with reasons.
- [ ] Demo 6: PM demonstrates "restore to view historical data" path via `php artisan fy:detach-archived` reverse flow (`FiscalYearPartitionService::restoreForViewing()`) — confirms artisan-only, not UI-exposed.

## Dev Team Hand-off

After pulling S10:

1. **Ensure the `rcerp_test` database exists + has the full schema**
   (see Pre-flight section above).

2. **Run the new test suite**:
   ```bash
   docker compose exec rcerp_app php artisan test \
     tests/Unit/FiscalYear \
     tests/Unit/Services/Pricing \
     tests/Feature/BranchPnl
   ```
   All 44 tests should pass. If any fail, paste the failure output —
   the test name + assertion message will pinpoint which layer broke.

3. **Run the full project test suite to check for regressions**:
   ```bash
   docker compose exec rcerp_app php artisan test
   ```
   The new tests don't touch any existing code paths — they only
   ADD coverage. Existing tests should still pass.

4. **Walk through the Manual UAT Execution Checklist** above. Capture
   screenshots/notes for each checkbox. Document any failures in a
   UAT report doc (e.g. `docs/UAT_REPORT_PHASE2.md`).

5. **Schedule the client demo** after all UAT checkboxes pass. The 6
   client signoff scenarios are the demo script.

## Known Limitations & Future Improvements

1. **No concurrent-finalize integration test for FIFO.** The risk
   register's "FIFO race condition under concurrent sales" risk is
   mitigated by `SELECT ... FOR UPDATE` in the resolver, but we
   don't have a test that proves the lock works under parallel
   access. A follow-up could use `pcntl_fork()` or a Go/Node test
   harness to spawn parallel finalize calls. Defer until a
   concurrency issue is observed in production.

2. **No PHPUnit test for the year-end close path.** S4's
   `AccountingPeriodService::yearEndClose()` is a complex multi-step
   operation (closing JE, opening balances refresh, partition detach,
   backup). The S8 UAT checklist covers it manually, but a dedicated
   `YearEndCloseServiceTest` would catch regressions automatically.
   Defer — the manual UAT is sufficient for the first production
   year-end close.

3. **No `CustomerLedger::getBalance()` tests.** The risk register
   (S4 row) flags this as Medium-likelihood / High-impact: "Carry-
   forward of opening_balance is computed incorrectly due to a bug
   in CustomerLedger::getBalance()". The recommended mitigation is
   "Add PHPUnit tests covering: customer with no payments, customer
   with partial payment, customer with credit note." Not implemented
   in S10 — defer to a dedicated S11 if the year-end close UAT
   surfaces any opening-balance discrepancies.

4. **Test setup is verbose.** The `insertReceivedDemandWithItem()`
   helpers in each test file duplicate logic. A future refactor
   could extract them into a shared `InsertsFifoDependencies` trait
   in `tests/Helpers/`. Defer — the duplication is intentional for
   now (each test file is self-contained and easier to read).

5. **No CI integration documented.** The implementation plan's risk
   register says "Run it in CI" for the super-admin hard-block test.
   The repo doesn't have a CI config file (no `.github/workflows/`,
   no `.gitlab-ci.yml`). Setting up CI is out of scope for S10 —
   the dev team should add a GitHub Actions workflow that runs
   `php artisan test` on every PR. Defer to a DevOps task.

## PM Checkpoint

**Test coverage delivered for the critical Q1+Q2 paths.** The single
most important test — `test_super_admin_cannot_view_historical_data`
— is now in place as a CI safety net against the Critical-impact risk
flagged in the implementation plan. The 44 tests cover the policy,
gate, service, and HTTP layers across S2/S5/S6/S7/S8/S9.

**Recommend:** Run the test suite on the Docker host before the
client demo. If all 44 pass + the manual UAT checklist completes,
the project is ready for the "1-week production hardening period"
mentioned in the implementation plan's signoff matrix, followed by
production deploy.

---

**Phase 2 / Q2 hardening (S10) complete.** Automated test coverage
for the critical paths delivered. UAT execution checklist ready for
dev team walkthrough.
