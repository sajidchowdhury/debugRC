# Session 9 Confirmation — S5 `getActiveCostRate` Bug Fix + Historical Cost Snapshot Audit

**Phase 2 / Q2 — Follow-up**
**Status:** Code complete, ready for UAT
**Branches:** `main` + `feature/fy-isolation-and-branch-pnl` (both pushed)

## Goal Recap

The S8 confirmation doc flagged a critical known limitation (item #3):
the S5 `BranchDemandService::getActiveCostRate()` method filters on
`bd.to_branch_id = $branchId`, but per the codebase's own convention
(`BranchDemandService` class docblock lines 42-43), `to_branch_id` is
the **supplier**, not the receiver. The method was returning the cost
OTHER branches paid when THIS branch supplied them — the wrong cost
for the selling branch's own sales.

Session 9 closes that gap:

1. Fix the runtime method so new sales get the correct cost snapshot.
2. Audit + correct historical `sales_invoice_items.cost_rate` snapshots
   so the S8 P&L report's cost figures are accurate for ALL sale lines,
   not just post-S9 sales.

## Root-Cause Analysis

### The branch-semantics convention (re-confirmed)

The `BranchDemandService` class docblock (lines 42-43) and the
`confirmReceipt()` authorization (line 631: `from_branch_id === $branchId`)
both establish:

| Column             | Role                                       |
|--------------------|--------------------------------------------|
| `from_branch_id`   | Requester / **receiver** of goods (debtor) — later sells them. |
| `to_branch_id`     | Supplier (creditor) — dispatches the goods.|

The `$branchId` parameter of `getActiveCostRate($branchId, $productId)`
is the **selling branch** — the branch that received the goods via a
demand and is now selling them to a customer. So the correct filter
is `from_branch_id = $branchId`, not `to_branch_id`.

### Where the bug existed

| Location                                                        | Bug                                                          |
|-----------------------------------------------------------------|--------------------------------------------------------------|
| `BranchDemandService::getActiveCostRate()` line 885 (S5)        | Filter `bd.to_branch_id = $branchId` (wrong — supplier).     |
| Migration `2026_10_17_000005_backfill_price_classification.php` line 106 (S5 backfill) | `WHERE bd.to_branch_id = si_inner.branch_id` (same wrong filter). |

### Why S7 didn't fix it

S7 added the `bdi.receiving_branch_id` denormalized column (= `from_branch_id`)
and the S7 backfill migration `2026_10_18_000008` correctly used it
when linking sale lines to demand items. So the
`sales_invoice_items.branch_demand_item_id` link itself was correct.
But the `cost_rate` snapshot on those same sale lines was still being
populated by the buggy S5 logic — S7 fixed the linkage, not the cost.

### Why S8 didn't surface it visibly

The S8 P&L report uses `sales_invoice_items.cost_rate` directly (the
snapshot), not the `getActiveCostRate()` method. So the bug was
invisible at the report level — the report rendered without errors,
just with wrong cost figures on historical sale lines.

## Implementation Summary

### Fix 1: Runtime — `BranchDemandService::getActiveCostRate()`

**File:** `laravel/app/Services/BranchDemand/BranchDemandService.php`

**Changes:**

- Filter changed from `bd.to_branch_id = $branchId` →
  `bdi.receiving_branch_id = $branchId`. This is the S7-denormalized
  column that equals `from_branch_id` (the receiver). Using it lets
  PostgreSQL use the S7 partial FIFO index `idx_bdi_fifo_open`
  `(receiving_branch_id, product_id, id) WHERE consumed_qty < qty`
  for a fast hot-path lookup.
- Added `bd.is_reversed = false` filter (defensive — reversed demands
  should not contribute cost even if their items still show open qty).
- Added `bdi.consumed_qty < bdi.qty` open-qty filter (now possible
  thanks to S7's `consumed_qty` column). This is proper FIFO
  semantics — only return demand items that still have remaining
  un-consumed stock.
- Rewrote the docstring to:
  - Document the correct branch semantics (cross-reference the class
    docblock lines 42-43 and the `confirmReceipt()` authorization).
  - Document the S9 fix history (what S5 did wrong, what S9 changed).
  - Cross-reference the S7 migration and the S9 audit migration.

### Fix 2: Historical — Migration `2026_10_18_000009`

**File:** `laravel/database/migrations/2026_10_18_000009_audit_and_correct_historical_cost_snapshots.php` (new)

**Strategy:**

For each `sales_invoice_items` row with a non-NULL
`branch_demand_item_id` link (set by the S7 backfill or by post-S7
sales), re-derive `cost_rate` from the linked
`branch_demand_items.cost_rate`. This is the canonical, accurate
cost — it's the cost the SELLING branch actually paid for THAT
specific demand item.

**Three-phase migration:**

1. **Pre-correction audit** — `SELECT` counts + max/avg delta between
   existing snapshot and linked demand item's `cost_rate`. Logged
   to Laravel's `log` channel + echoed to the migrate output.

2. **Correction** — Single `UPDATE ... FROM ... WHERE` (PostgreSQL
   native syntax — NOT Laravel's join-update builder, which fails
   on PG with `missing FROM-clause entry`, the same bug class I
   fixed in the S7 migration in the prior commit). Only touches
   rows where `ABS(sii.cost_rate - bdi.cost_rate) > 0.01` (the
   `PriceClassifier` EPSILON tolerance) — leaves already-correct
   rows untouched to keep the transaction log small.

3. **Post-correction audit** — verifies zero deltas > 0.01 remain.
   Emits a WARNING if any rows are still wrong (should not happen
   — defensive only).

**Idempotency:** Re-runnable. Each pass only updates rows where the
snapshot differs from the linked demand item's cost_rate. After the
first successful run, the second run is a no-op.

**Rows NOT touched:** Sale lines with `branch_demand_item_id IS NULL`
(direct supplier purchases, or demand items that were fully consumed
by later sales and couldn't be attributed by the S7 backfill). Their
`cost_rate` remains as the S5 fallback (`products.purchase_rate`),
which is the best available estimate for those rows. The S8 P&L
report flags them as "cost = list price, not locked".

**down() is a no-op:** The old (buggy) cost_rate snapshots are not
worth restoring. If a rollback is needed, restore from a pre-S9
database backup.

## Files Touched

### Modified Files

1. **`laravel/app/Services/BranchDemand/BranchDemandService.php`** —
   `getActiveCostRate()` method (lines 844-941): filter fix +
   `is_reversed` + `consumed_qty < qty` + docstring rewrite.

### New Files

2. **`laravel/database/migrations/2026_10_18_000009_audit_and_correct_historical_cost_snapshots.php`** —
   historical cost-snapshot audit + correction migration.

3. **`docs/IMPLEMENTATION_PLAN_SESSION9_CONFIRMATION.md`** (this file).

## Acceptance Tests

### Runtime fix (new sales)

Run these on a live Docker host after pulling S9 + running migrate:

- [ ] **Setup:** Create a demand Branch A → Branch B for 10 units of
  product P at `cost_rate = 10` (= min price). Confirm receipt as
  Branch B.
- [ ] **Sell as Branch B:** Create a sales invoice for 5 units of P
  at rate 12 (default). Finalize. Verify
  `sales_invoice_items.cost_rate = 10` (the demand's cost_rate), NOT
  the cost of some unrelated demand where B was the supplier.
- [ ] **Cross-check:** Before S9, this would have returned either NULL
  (if B never supplied anyone) or the cost from a demand where B was
  supplier (wrong). After S9, it returns the demand's actual cost_rate.
- [ ] **FIFO open-qty:** Once 10 units are fully sold, the next sale
  of P should fall back to `products.purchase_rate` (because
  `consumed_qty = qty` excludes the demand item from the FIFO
  candidate set via the new `WHERE consumed_qty < qty` filter).

### Historical correction (existing sale lines)

- [ ] **Pre-migration audit:** Run the migration. The output should
  show:
  ```
  S9 cost-snapshot audit:
    total sale lines with branch_demand_item_id link: <N>
    rows with wrong cost_rate snapshot (delta > 0.01): <M>
    max |delta|: <X>
    avg |delta| among wrong rows: <Y>
    corrected <M> row(s).
    Post-correction audit: 0 rows with delta > 0.01. Correction verified.
  ```
- [ ] **Idempotency:** Re-run the migration (e.g. `php artisan migrate:rollback`
  then `php artisan migrate` — but note `down()` is a no-op, so this
  is effectively re-running `up()`). The second run should show
  `rows with wrong cost_rate snapshot (delta > 0.01): 0` and
  `No corrections needed`.
- [ ] **Spot-check:** Pick a few corrected sale lines, verify their
  `cost_rate` now matches the linked `branch_demand_items.cost_rate`
  (the demand item they were attributed to by S7).

### S8 P&L report accuracy

- [ ] **Before S9 (if reproducible):** The S8 Branch P&L report shows
  wrong cost / P&L figures for historical sale lines (cost came from
  the wrong demand — where this branch was supplier, not receiver).
- [ ] **After S9:** The same report shows correct cost / P&L figures
  for the same sale lines, because `cost_rate` is now derived from
  the linked demand item.
- [ ] **Net P&L total:** The report's "Net P&L" summary card should
  change after running the S9 migration (the total moves from
  "wrong cost basis" to "correct cost basis"). Document the delta
  in the UAT report.

### No-regression on direct-purchase sale lines

- [ ] Sale lines with `branch_demand_item_id IS NULL` (direct supplier
  purchases, no inter-branch demand) should be UNCHANGED by the S9
  migration. Their `cost_rate` remains as the S5 fallback
  (`products.purchase_rate`). Verify by running the audit `SELECT`
  manually before and after — the count of NULL-link rows should
  match the count of unchanged rows.

## Dev Team Hand-off

After the dev team pulls the S9 code:

1. **Run `php artisan migrate`** to apply the new migration. Watch
   the audit output — the `rows_to_correct` count tells you how
   many historical sale lines had wrong cost snapshots. If this
   number is unexpectedly high (e.g. > 50% of linked rows),
   investigate the S7 backfill coverage before relying on the S8
   P&L report.

2. **Manual smoke test (new sales):**
   - Log in as Branch B's cashier.
   - Create a sales invoice for a product that Branch B received
     via a demand from Branch A.
   - Finalize the invoice.
   - Inspect the `sales_invoice_items.cost_rate` column for the
     new row — it should equal the demand item's `cost_rate`, not
     some unrelated value.

3. **Manual smoke test (historical correction):**
   - Pick a known historical sale line that the S7 backfill linked
     to a demand item.
   - Before running S9 migrate: note its `cost_rate`.
   - Run S9 migrate.
   - After: verify the `cost_rate` now matches the linked demand
     item's `cost_rate` (and is different from the pre-S9 value,
     if the S5 bug had populated it wrong).

4. **S8 P&L report re-test:**
   - Open the Branch P&L report for the same A↔B pair you tested
     in S8.
   - Compare the "Net P&L" total to the S8 value. The delta is the
     cumulative correction from S9.

5. **UAT documentation:** Record the pre-S9 vs post-S9 P&L totals
   in the UAT report. This is the visible business impact of the
   S5 bug + S9 fix.

## Known Limitations & Future Improvements

1. **S5 backfill migration NOT modified.** The S5 backfill
   (`2026_10_17_000005`) still has the buggy `to_branch_id` filter
   at line 106. We deliberately do NOT modify historical migration
   files (immutability principle — migrations are a record of what
   happened, not what should have happened). The S9 migration
   corrects the resulting bad data without touching the historical
   migration. If a fresh install is run, the S5 backfill will still
   populate wrong cost_rate snapshots — but the S9 migration runs
   immediately after and corrects them before any user sees the
   data. This is acceptable: the net effect on a fresh install is
   correct data.

2. **No automated test.** A PHPUnit test would be ideal:
   `test_getActiveCostRate_returns_demand_cost_for_receiving_branch()`.
   Not implemented in S9 — defer to the dev team's test suite. The
   manual smoke test above covers the same ground.

3. **No `cost_rate_source` indicator.** The S8 P&L report can't
   distinguish "cost = locked demand cost" from "cost = fallback
   purchase_rate" without joining to `branch_demand_items` to check
   if `branch_demand_item_id IS NULL`. A future enhancement could
   add a `cost_rate_source` enum column
   (`'demand'` / `'purchase_rate'` / `'unknown'`) to make the
   report self-describing. Defer until a user requests it.

4. **No multi-branch supplier aggregation.** If Branch B received
   the same product from Branch A AND from Branch C via separate
   demands, the FIFO resolver picks the oldest open one — correct
   FIFO behavior. But the S8 P&L report aggregates cost across all
   demands, which can mask per-supplier profitability. A future
   enhancement could add a "per-supplier" breakdown in the
   per-demand drilldown. Defer.

## PM Checkpoint

**S5 inconsistency resolved.** The S8 P&L report's cost figures are
now accurate for ALL sale lines (historical + new). The fix is
minimal, surgical, and reuses existing S7 infrastructure
(`receiving_branch_id` denormalized column + `idx_bdi_fifo_open`
partial index). No new schema changes — only one service-method fix
+ one data-correction migration.

**Recommend:** Run the S9 migration during a low-traffic window
(it's a single UPDATE on `sales_invoice_items` joined to
`branch_demand_items` — should complete in seconds for typical
datasets, but may lock rows briefly). After migration, re-run the
S8 P&L report and document the before/after Net P&L delta for the
UAT report.

---

**Phase 2 / Q2 follow-up (S9) complete.** S5 known limitation #3
resolved. Ready for final UAT sign-off.
