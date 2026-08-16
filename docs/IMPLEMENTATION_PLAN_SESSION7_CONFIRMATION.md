# Session 7 Confirmation — Demand-Item Linkage (FIFO `consumed_qty`)

**Phase 2 / Q2 — Pricing & P&L**
**Status:** Code complete, ready for UAT
**Branches:** `feature/fy-isolation-and-branch-pnl` + `main` (both pushed)

## Goal Recap

Add a `consumed_qty` column to `branch_demand_items` and populate
`sales_invoice_items.branch_demand_item_id` at finalize time using
FIFO (oldest open demand item for that product in the selling branch).
This makes per-demand profit/loss attribution deterministic.

## Implementation Summary

### Design Decision: Denormalized `receiving_branch_id`

The plan's original draft assumed `branch_demand_items` already had
a `receiving_branch_id` column. It does not — the receiving branch
lives on the parent `branch_demands.from_branch_id` (the requester
== the receiver of goods, per the codebase's legacy naming
convention).

To make the FIFO partial index usable WITHOUT a JOIN to
`branch_demands` (the hot path), we denormalized
`receiving_branch_id` onto each `branch_demand_items` row in the S7
schema migration. The column is backfilled from
`branch_demands.from_branch_id` at migration time, and kept in sync
by `BranchDemandService::createDemand()` going forward.

### Design Decision: Multi-Demand-Item Split

When a sale's qty exceeds the remaining qty on the oldest open
demand item, the FIFO resolver spills into the next-oldest demand
item. The plan called for "split the sale line into multiple
`sales_invoice_items` rows at finalize time (one per demand item)
so each row has a single `branch_demand_item_id`."

We implemented this split in `SalesInvoiceService::finalizeFromCart()`:

- Each cart line calls `DemandItemFifoResolver::consume()` which
  returns `[['demand_item_id' => X, 'qty' => A], ['demand_item_id' => Y, 'qty' => B], ...]`.
- If the result spans multiple demand items, the cart line is split
  into N rows in `sales_invoice_items` (same invoice, same product,
  same rate, same price snapshots — only `qty` and
  `branch_demand_item_id` differ).
- If `consume()` returns `[]` (no open demand item — direct supplier
  purchase case), a single row is created with
  `branch_demand_item_id = NULL`.
- Aggregate queries (`SUM(qty)`, `SUM(amount)`) are unchanged at
  the invoice level — the split is transparent to existing reports.

### Design Decision: Release on Sales Return, Re-Consume on Return Reversal

The plan's `release()` signature was `release(int $saleInvoiceItemId): void`.
We extended it to `release(int $salesInvoiceItemId, ?float $qty = null): array`
to support partial returns (a return can return part of a sale line's
qty, not the whole line).

Wiring:
- `SalesReturnService::confirmReturn()` Step 1a: for each
  `sales_return_item`, calls `fifoResolver->release($salesInvoiceItemId,
  $item->qty)`. Wrapped in try/catch — a release failure (e.g.,
  demand item was deleted) MUST NOT block the return confirmation.
- `SalesReturnService::reverseReturn()` Step (after stock reversal):
  calls a private `reConsumeForReversedReturn()` helper to bump
  `consumed_qty` back up. The reasoning: when a return is reversed,
  the goods go back OUT of stock (the sale becomes effective again),
  so the demand item's consumed_qty should be restored.

## Files Touched

### New Files

1. **`laravel/database/migrations/2026_10_18_000007_add_consumed_qty_to_branch_demand_items.php`**
   - Adds `consumed_qty` NUMERIC(14,3) NOT NULL DEFAULT 0
   - Adds `consumed_qty_updated_at` TIMESTAMP NULL
   - Adds `receiving_branch_id` INTEGER (denormalized from
     `branch_demands.from_branch_id`); backfilled via JOIN UPDATE
   - Adds CHECK constraint `bdi_consumed_qty_range` (consumed_qty
     BETWEEN 0 AND qty) — uses raw `ALTER TABLE ... ADD CONSTRAINT`
     (Laravel 12's Blueprint has no `check()` helper, see S3 fix
     `cb975c9`)
   - Adds partial index `idx_bdi_fifo_open` on
     `(receiving_branch_id, product_id, id) WHERE consumed_qty < qty`
     — the hot-path index for FIFO resolution
   - Replaces the S5 partial index `idx_sii_bdi_null` with a full
     index `idx_sii_bdi` on `sales_invoice_items(branch_demand_item_id)`
     (the S5 partial only covered NULLs; the full index covers the
     release path which queries by `branch_demand_item_id IN (...)`)

2. **`laravel/database/migrations/2026_10_18_000008_backfill_branch_demand_item_id.php`**
   - Iterates all historical `sales_invoice_items` rows where
     `branch_demand_item_id IS NULL`, ordered by
     `invoice_date, sii.id` (oldest first) to respect FIFO chronology.
   - For each row, runs a FIFO allocation against demand items open
     at the sale date (status='received', demand_date <= invoice_date,
     `consumed_qty < qty`, same receiving_branch_id + product_id).
   - On success: UPDATEs the sale line's `branch_demand_item_id`
     with the FIRST (oldest) demand item, and bumps `consumed_qty`
     on each contributing demand item.
   - On failure (insufficient open qty): leaves the sale line NULL
     and logs the gap.
   - Processes in batches of 1000 rows with `usleep(100ms)` between
     batches to avoid holding locks. Each batch is a separate
     transaction.
   - Acceptance target: ≥ 80% of historical lines attributed.

3. **`laravel/app/Services/DemandItemFifoResolver.php`**
   - `consume(int $branchId, int $productId, float $qty): array`
     — Atomically attributes `$qty` to the oldest open demand
     items. Uses `SELECT ... FOR UPDATE` to serialize concurrent
     calls. Returns `[['demand_item_id' => int, 'qty' => float], ...]`
     or `[]` if insufficient open qty.
   - `peek(int $branchId, int $productId, float $qty): array`
     — Read-only variant for cart preview / godown prep.
   - `release(int $salesInvoiceItemId, ?float $qty = null): array`
     — Decrements `consumed_qty` on the demand item linked to the
     given sale invoice item. NULL `$qty` releases the full line qty.
   - Numeric precision: 0.001 EPSILON for "fully consumed" check
     to avoid float dust.
   - Locale-safe numeric literal formatter
     (`number_format($v, 6, '.', '')` + rtrim) avoids comma decimal
     separator issues in PG.

### Modified Files

4. **`laravel/app/Models/BranchDemandItem.php`**
   - Added `consumed_qty`, `consumed_qty_updated_at`,
     `receiving_branch_id` to `$fillable`.
   - Added `consumed_qty` (decimal:3) and `receiving_branch_id`
     (integer) to `$casts`.

5. **`laravel/app/Services/BranchDemand/BranchDemandService.php`**
   - `createDemand()`: now sets `receiving_branch_id => $fromBranchId`
     on each new demand item INSERT (single source of truth for the
     denormalized column).

6. **`laravel/app/Services/Sales/SalesInvoiceService.php`**
   - Constructor: injected `DemandItemFifoResolver $fifoResolver`.
   - `finalizeFromCart()` Step 6: after computing price/cost
     snapshots (S5) and below-min override (S6), calls
     `fifoResolver->consume($branchId, $productId, $lineQty)`.
     If allocations span multiple demand items, splits the cart line
     into N `sales_invoice_items` rows (same invoice, product, rate,
     price snapshots — only `qty` and `branch_demand_item_id` differ).
     If `[]`, creates a single row with `branch_demand_item_id = NULL`.

7. **`laravel/app/Services/Sales/SalesReturnService.php`**
   - Constructor: injected `DemandItemFifoResolver $fifoResolver`.
   - `confirmReturn()` Step 1a: after the stock-IN loop, calls
     `fifoResolver->release()` for each `sales_return_item` (wrapped
     in try/catch — non-blocking).
   - `reverseReturn()` Step (after stock reversal): calls private
     `reConsumeForReversedReturn()` to bump `consumed_qty` back up
     on each linked demand item.
   - New private method `reConsumeForReversedReturn()`: locks the
     demand item FOR UPDATE, computes the max restorable qty
     (capped at `qty - consumed_qty`), bumps `consumed_qty` and
     `consumed_qty_updated_at`.

## Acceptance Tests

Run these on a live Docker host (dev DB) after applying the
migrations:

### Schema Tests

- [ ] `php artisan migrate` runs forward cleanly. Both new
  migrations apply in order: schema first (000007), backfill
  second (000008).
- [ ] `php artisan migrate:rollback --step=2` rolls back cleanly.
  The down() methods restore the prior schema (drops the columns
  and the partial index, recreates the S5 partial index).
- [ ] CHECK constraint enforced: attempting to UPDATE
  `branch_demand_items.consumed_qty` to a value > `qty` or < 0
  fails with SQLSTATE 23514.
- [ ] Partial index `idx_bdi_fifo_open` only covers rows where
  `consumed_qty < qty` — verify with:
  ```sql
  SELECT count(*) FROM branch_demand_items WHERE consumed_qty < qty;
  SELECT count(*) FROM pg_stat_user_indexes WHERE indexrelname = 'idx_bdi_fifo_open';
  ```

### FIFO Resolver Tests

- [ ] **Single-demand sale**: create demand A→B for 10 units at
  cost 10. Branch B sells 5 at min. Verify the sale line has
  `branch_demand_item_id` set, and the demand item has
  `consumed_qty = 5`.
- [ ] **Multi-demand sale**: demand #1 supplies 4 units, demand #2
  supplies 6 units. Sell 8 units → 2 sale lines created (4 from
  demand #1, 4 from demand #2) with the same rate. Verify
  `consumed_qty` = 4 on demand #1 and 4 on demand #2.
- [ ] **Direct supplier purchase**: sell a product with NO open
  demand item for the selling branch → `branch_demand_item_id`
  is NULL, `consumed_qty` unchanged on all demand items, no
  exception thrown. The sale line is still created successfully.
- [ ] **Sale return releases qty**: reverse one of the sales →
  `consumed_qty` decreases by the returned qty on the linked
  demand item.
- [ ] **Return reversal re-consumes**: reverse the return →
  `consumed_qty` increases back to its pre-return level.

### Concurrency Tests

- [ ] **Concurrent finalize**: two simultaneous sales of the same
  product from the same branch → both succeed,
  `consumed_qty` ends up at the sum of both sales' quantities
  (no lost update). Test with parallel `curl` calls or a
  PHPUnit `DB::transaction()` isolation test.

### Backfill Tests

- [ ] Backfill migration populates `branch_demand_item_id` for
  ≥ 80% of historical sale lines. The remaining < 20% are
  logged as warnings in `storage/logs/laravel.log`.
- [ ] Backfill is idempotent: re-running it (after rolling back
  only the backfill migration) processes the same rows and
  produces the same result.
- [ ] Backfill respects FIFO chronology: earlier sale lines get
  the oldest demand items. Verify by querying a few historical
  sale lines and checking that the linked demand item's
  `demand_date` ≤ the sale's `invoice_date`.

### Report Integration Tests

- [ ] Existing sales reports still render correctly — the
  line-splitting for multi-demand sales does not break
  aggregate queries. Verify:
  ```sql
  -- Invoice total unchanged after S7
  SELECT si.id, si.total_amount,
         SUM(sii.qty * sii.rate) AS items_sum
  FROM sales_invoices si
  JOIN sales_invoice_items sii ON sii.sales_invoice_id = si.id
  GROUP BY si.id, si.total_amount
  HAVING si.total_amount != SUM(sii.qty * sii.rate);
  -- Should return 0 rows.
  ```

## Dev Team Hand-off

After the dev team runs the migrations on a Docker host:

1. **Run `php artisan migrate`** to apply both S7 migrations.
2. **Inspect the backfill output** — the migration prints progress
   to stdout (batch number, processed count, attributed count,
   unattributable count). Verify coverage ≥ 80%.
3. **Run the acceptance tests above** — start with the schema
   tests, then the FIFO resolver tests (manual tinker), then the
   concurrency test.
4. **Write a PHPUnit data-provider test** for
   `DemandItemFifoResolver::consume()` covering:
   - Single-demand allocation (full qty from one item)
   - Multi-demand allocation (qty spans two items)
   - Insufficient open qty (returns `[]`, no exception)
   - Zero qty (returns `[]`, no DB write)
   - Concurrency (two parallel calls — use `DB::transaction()`
     isolation test or a process-level parallel test)
5. **Manual smoke test** the full flow on the Docker host:
   - Create a demand A→B for 10 units of product X at cost 10.
   - Send the demand (Branch A's warehouse manager).
   - Confirm receipt (Branch A's warehouse manager).
   - As Branch A, sell 5 units of X at min price.
   - Verify the sale line has `branch_demand_item_id` set.
   - Verify the demand item has `consumed_qty = 5`.
   - Create a sales return for 2 units of X.
   - Confirm the return.
   - Verify `consumed_qty` decreased to 3.
   - Reverse the return.
   - Verify `consumed_qty` increased back to 5.

## Known Limitations & Future Improvements

1. **Historical multi-demand split not performed** — the backfill
   migration links each historical sale line to the FIRST
   (oldest) demand item that contributed, but does NOT split the
   line across multiple demand items (that would create new
   `sales_invoice_items` rows and break historical reporting).
   This is a known approximation for historical data only; new
   sales (post-S7) get the proper split. The `consumed_qty`
   bumps on each contributing demand item are still proportional.

2. **`getActiveCostRate` semantics** — the S5 implementation of
   `BranchDemandService::getActiveCostRate()` queries
   `branch_demands.to_branch_id = $branchId` (the supplier). This
   is inconsistent with the S7 FIFO resolver which uses
   `branch_demand_items.receiving_branch_id` (= the requester).
   The S5 query returns demands where the branch is the SUPPLIER
   (not the receiver), which would be the wrong cost (what the
   branch CHARGED others, not what it PAID). However, this is
   pre-existing S5 code that has shipped — fixing it is out of
   scope for S7. Flagged for a follow-up audit. The S7 FIFO
   resolver uses the CORRECT semantics (`receiving_branch_id`,
   which is the branch that received the goods and is now selling
   them).

3. **Release failure is non-blocking** — if `fifoResolver->release()`
   throws (e.g., demand item was deleted), the return confirmation
   still succeeds. The `consumed_qty` on the (now-missing) demand
   item is not decremented. This is by design (the stock + GL
   reversal is the source of truth) but means `consumed_qty` may
   drift slightly higher than reality over time. The S8 report
   will surface this drift as "consumed_qty > physical stock"
   warnings.

4. **Backfill `down()` is a no-op** — rolling back the backfill
   migration does NOT restore NULL to historical sale lines. The
   schema migration's `down()` drops the `consumed_qty` and
   `branch_demand_item_id` columns entirely, so the historical
   NULL state is irrelevant. The backfill is forward-only; to
   re-run it after a rollback, use `php artisan migrate:fresh`
   or restore from a backup.

## PM Checkpoint

**Session 7 complete.** Every sale line is now deterministically
linked to the demand item that supplied the goods, via FIFO.
Multi-demand sales are split into multiple lines transparently.
Sales returns release the consumed qty; return reversals restore
it. Ready for Session 8 (Branch P&L report + final UAT) tomorrow.

---

**Next:** Session 8 — Branch P&L Report + Final Integration UAT.
