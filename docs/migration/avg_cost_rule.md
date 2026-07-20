# Moving-Average Cost Rule — First Principles Document
## Phase 6.1 — Stock Transactions (SSOT)

**Status:** Re-derived from inventory accounting principles. Must be reviewed + signed off by the accountant before implementation.

---

## 1. The Costing Method: Perpetual Moving Average (Weighted Average)

RC_ERP uses **perpetual moving-average costing**. This is standard inventory accounting for wholesale/distribution businesses where individual-item tracking is impractical but cost changes must be reflected in real-time.

### Core principle
When goods are received, the average cost is recalculated as a weighted average of the existing stock and the new stock. When goods are issued, they leave at the current average cost — the average itself does not change on outflows.

### Why moving average (not FIFO or standard cost)?
- **FIFO** requires tracking individual lot costs — impractical for electronics distribution where the same SKU arrives in many small purchase lots.
- **Standard cost** requires periodic variance adjustments — too rigid for a business with frequent purchase-rate changes.
- **Moving average** updates continuously on every receipt, gives a fair valuation at any moment, and is simple to audit. This matches the legacy system's choice.

---

## 2. The Re-Derived Rule

### On IN (qty > 0) — stock received
```
new_qty      = old_qty + in_qty
new_avg_cost = (old_qty × old_avg_cost + in_qty × in_rate) / new_qty
```
- `in_rate` depends on the `reference_type` (see §3).
- **Guard:** if `new_qty <= 0` (shouldn't happen since in_qty > 0, but defensive), set `avg_cost = in_rate`.
- **Edge case — first receipt (old_qty = 0):** `new_avg_cost = in_rate` (the formula naturally produces this: `(0 × 0 + in_qty × in_rate) / in_qty = in_rate`).

### On OUT (qty < 0) — stock issued
```
out_qty      = abs(qty)              -- the quantity leaving
new_qty      = old_qty - out_qty
avg_cost     = UNCHANGED              -- cost flows out at current average
value_removed = out_qty × old_avg_cost
```
- **Guard:** if `new_qty < -0.0001` → **throw exception** ("Insufficient stock"). The 0.0001 tolerance handles floating-point dust. The DB-level CHECK constraint (`qty >= -0.0001`) is defense-in-depth.
- **Negative stock is NOT allowed** (except transiently within a transaction before commit — the trigger tolerates -0.0001).

### On reversal
Reversals are **append-only** — the original transaction is never mutated. A new `stock_transaction` row is inserted with:
- `qty = -original.qty` (opposite sign)
- `rate = original.rate` (or `avg_cost` at time of original if rate was 0)
- `reference_type = 'reversal'`, `reference_id = original.id`
- `reversal_of_transaction_id = original.id`

The original is marked `is_reversed = true` to prevent double-reversal. The reversal applies the opposite warehouse_stock effect (IN reversal = OUT, OUT reversal = IN).

---

## 3. Rate Semantics by Reference Type

The `rate` field on a `stock_transaction` is **snapshotted at transaction time** and has different meanings depending on what caused the movement:

| reference_type | rate meaning | Notes |
|---|---|---|
| `purchase_receive` | Net purchase rate (after discount, before tax) | Drives avg_cost UP or establishes it |
| `purchase_return` | The avg_cost at time of the original receive | Restores stock at original cost — **NOT current avg** |
| `sales_challan` | Current avg_cost at time of challan | Drives COGS; qty is negative (OUT) |
| `sales_return` | **ORIGINAL avg_cost** at time of the original challan's stock-out | **CRITICAL:** return restores at original cost, not current — preserves cost integrity |
| `stock_adjustment` | 0 (or specified per adjustment) | Gain/loss goes to GL adjustment account |
| `stock_take` | Current avg_cost | Variance (physical - system) posted at current cost |
| `warehouse_transfer` (source) | Current avg_cost | Stock leaves source at current cost |
| `warehouse_transfer` (dest) | Source avg_cost | Stock arrives at dest at source's cost (no GL impact for same-branch) |
| `damage` | Current avg_cost | Loss posted at current cost |
| `branch_demand` | Current avg_cost | Inter-branch transfer |
| `opening_balance` | Specified per product | Initial stock setup |
| `reversal` | Original transaction's rate | Opposite-sign qty at original rate |

### The critical correctness point: sales return at ORIGINAL cost
When a customer returns goods, the stock must go back at the **avg_cost that was in effect when the goods were originally sold** (via the challan), NOT the current avg_cost. This is because:
1. The sale posted COGS at the original avg_cost.
2. The return must reverse that exact COGS amount.
3. If we used current avg_cost (which may have changed due to subsequent purchases), the COGS reversal wouldn't match the original COGS, creating a GL drift.

**Implementation:** When a sales return is created, the system looks up the original `stock_transaction` for the challan (reference_type='sales_challan', reference_id=challan_id, product_id) and snapshots its `rate` into `sales_return_items.original_cost`. The return's stock_transaction uses that `original_cost` as its rate.

---

## 4. Atomicity Contract

Every stock movement MUST be a single DB transaction:

```
BEGIN
  1. INSERT INTO stock_transactions (...)          -- the immutable ledger row
  2. SELECT ... FROM warehouse_stock FOR UPDATE    -- lock the row
  3. UPDATE warehouse_stock SET qty, avg_cost       -- apply the movement
COMMIT  (or ROLLBACK on any failure)
```

**Why:** If the stock_transaction is logged but the warehouse_stock update fails (or vice versa), the ledger and the balance diverge — the #1 inventory bug. The DB transaction guarantees they commit together or not at all.

The caller (e.g., SalesController finalizing a challan) wraps the ENTIRE business operation (sales_invoice update + stock_transaction + warehouse_stock + GL journal) in one transaction, so stock moves atomically with the business event.

---

## 5. Availability (Available-to-Pick)

`available_qty ≠ physical_qty`. Available stock accounts for the **sales pipeline** — invoices that are drafted/confirmed but not yet dispatched via challan.

```
branch_available = SUM(warehouse_stock.qty for branch) 
                 - SUM(open sales_invoice_dispatches.ordered_qty - dispatched_qty for branch)

warehouse_available = warehouse_stock.qty 
                    - SUM(open sales_invoice_dispatches for this warehouse)
```

**Open invoices** = status NOT IN ('challan_completed', 'reversed') AND is_reversed = false.

This prevents overselling: when a salesman creates a draft invoice for 10 units, those 10 units are "soft-held" even though physical stock hasn't moved yet. Another salesman creating an invoice for the same product sees 10 fewer available.

The availability check uses `SELECT ... FOR UPDATE` on `warehouse_stock` during the sales finalize flow to prevent race conditions (two salesmen finalizing invoices for the same product simultaneously).

---

## 6. Replay Test (Acceptance Criteria)

Before Phase 6.1 is signed off, a replay test MUST pass:

1. Take all `stock_transactions` from production (~38,775 rows), sorted by `(created_at, id)`.
2. Start with empty `warehouse_stock` (shadow table `warehouse_stock_shadow`).
3. Replay every transaction through the new `StockService::applyTransaction()`.
4. Compare `warehouse_stock_shadow` to live `warehouse_stock` for every `(warehouse_id, product_id)`.
5. **Zero drift** required: every product's `qty` and `avg_cost` must match to within 0.0001.
6. Any drift is logged to `avg_cost_drift` table with the transaction that caused it, and MUST be investigated.

The replay test command: `php artisan stock:replay-verify`

---

## 7. Sign-off

- [ ] Lead developer: code review + replay test passes with zero drift
- [ ] Accountant: this document reviewed and approved (the rate semantics in §3 are the critical business rules)
- [ ] Project owner: 7-day shadow-mode zero-diff confirmation (after Phase 6.2+6.3)

---

*This document is the single source of truth for inventory costing in RC_ERP. Any change to the avg_cost logic requires updating this document and re-running the replay test.*
