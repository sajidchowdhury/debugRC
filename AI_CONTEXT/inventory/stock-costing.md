# Stock Costing — Moving Average

> **Module:** Inventory (Phase 8)
> **Audience:** Engineers + AI assistants + **accountants** (must review before Canonical)
> **Status:** Draft — pending accountant sign-off
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Stock/StockService.php` (the SOLE entry point for stock movements + avg_cost computation) + `laravel/docs/migration/avg_cost_rule.md` (first-principles derivation) + `laravel/database/sql/03_stock.sql:62-97` (`warehouse_stock` DDL + non-negative trigger)

## 1. What is it?

The **moving-average cost** (also "weighted-average cost") is the per-unit cost at which stock is
valued in the ERP. Every time stock is received into a warehouse, the average cost is recomputed
to blend the old cost with the new purchase rate. Every time stock is issued, the issue is valued
at the **current** average cost — the average itself is **unchanged** by an issue.

The formula (re-derived from first principles in `docs/migration/avg_cost_rule.md`):

```
new_avg = (old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)
```

Stock OUT does **not** change the average:

```
new_qty = old_qty − out_qty
new_avg = old_avg           (cost flows out at average, avg unchanged)
```

## 2. Why does it exist?

- **Moving average** is the simplest costing method that absorbs purchase-price fluctuations
  smoothly. Compared to FIFO (which requires per-lot tracking) and Standard Cost (which requires
  variance postings), it needs only one column per `(warehouse, product)` pair.
- The ERP is a distribution/retail system with high transaction volume — moving average keeps the
  per-transaction cost computation O(1) (just read the current `avg_cost`).
- Bangladesh VAT/tax rules accept moving-average costing for inventory valuation.

## 3. When is it used?

- **On every stock receipt** (purchase receive, sales return, stock adjustment increase, stock
  take gain, warehouse transfer dest-in, opening balance) — `avg_cost` is recomputed via the
  formula above.
- **On every stock issue** (sales challan, purchase return, damage, stock adjustment decrease,
  stock take loss, warehouse transfer source-out) — the issue is valued at the current `avg_cost`,
  which does **not** change.
- **At GL posting time** — every stock movement that affects the GL posts Dr/Cr `inventory` at
  `qty × avg_cost` (or `qty × rate` where `rate` is the snapshot of avg_cost at transaction time).
- **At reconciliation** — `mv_stock_valuation` MV computes `stock_value = qty × avg_cost` for
  every warehouse/product, and `ReconciliationService::reconcileInventory` compares the total
  against the GL `inventory` ledger balance.

## 4. Who uses it?

- **The system** computes avg_cost automatically inside `StockService::applyTransaction()`. No
  user ever sets avg_cost directly (except at opening-balance seeding).
- **Accountants** review the `mv_stock_valuation` MV and the Inventory reconciliation report.
- **Engineers / AI assistants** MUST understand the formula before modifying any stock-posting
  code, or the GL will silently desync from the stock ledger.

## 5. Related modules

- `stock-ledger.md` — the `stock_transactions` immutable ledger that records every movement with
  its `rate` snapshot.
- `warehouse-stock.md` — the `warehouse_stock` table that holds the live `qty` + `avg_cost`.
- `../accounting/chart-of-accounts.md` — the `inventory` (critical), `inventory_shrinkage`,
  `inventory_surplus`, `damage_loss`, `inventory_revaluation` (extended) ledger natures.
- `../accounting/subledger-reconciliation.md` §5 (Inventory) + §6 (COGS) — the reconciliation
  formulas that depend on avg_cost.
- `../accounting/journal-posting-rules.md` §5 #9-12 — the Dr/Cr matrix for StockAdjustment,
  StockTake, Damage, WarehouseTransfer (already documented in Phase 6).

## 6. Business rules (the Core Rule)

- **MUST** compute avg_cost using the moving-average formula on every stock-IN.
- **MUST NOT** change avg_cost on a stock-OUT (cost flows out at average, avg unchanged).
- **MUST** snapshot the `rate` (per-unit cost) on every `stock_transactions` row at transaction
  time. The snapshot is **immutable** — never updated after insert.
- **MUST** maintain avg_cost **per warehouse**, not per product or per branch. The
  `warehouse_stock` composite PK is `(warehouse_id, product_id)`.
- **MUST** preserve the **original cost** on a sales return — the `rate` passed to
  `applyTransaction` is the `original_cost` from the challan, NOT the current avg_cost. This
  preserves cost integrity: a product sold at avg_cost X and returned later (when avg_cost is Y)
  is restored at X, not Y.
- **MUST** preserve the **original receive rate** on a purchase return — the `rate` is the avg_cost
  at the time of the original receive, NOT the current avg_cost.
- **MUST** transfer stock between warehouses at the **source's avg_cost** — the dest warehouse
  inherits the source's cost basis (no P&L impact on the transfer itself).
- **MUST NOT** allow negative stock. Three layers enforce this: DB CHECK `qty >= -0.0001`, DB
  trigger `prevent_negative_stock()`, and app-level throw in `StockService::applyTransaction`.
- **MUST** open a single DB transaction around the `stock_transactions` INSERT + `warehouse_stock`
  SELECT FOR UPDATE + `warehouse_stock` UPDATE. `StockService::applyTransaction` does **not** open
  its own transaction — the caller (e.g. `StockAdjustmentService::confirmAdjustment`) wraps
  everything in one outer `DB::transaction()` closure.
- **MUST NOT** support FIFO or standard-cost methods — moving average is hardcoded in
  `StockService`. There is no `config/inventory.php` cost-method flag.

## 7. Technical implementation

### 7.1 The `warehouse_stock` table — `03_stock.sql:62-73`

```sql
CREATE TABLE warehouse_stock (
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL DEFAULT 0,
    avg_cost numeric(12,2) NOT NULL DEFAULT 0,
    total_qty numeric(14,4) NOT NULL DEFAULT 0,
    total_value numeric(14,2) NOT NULL DEFAULT 0,
    updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (warehouse_id, product_id)   -- NO id column
);
ALTER TABLE warehouse_stock ADD CONSTRAINT ws_qty_nonnegative CHECK (qty >= -0.0001);
-- prevent_negative_stock() trigger raises check_violation with business-friendly error
```

NO `reserved_quantity`, NO `available_quantity`, NO `reorder_level`, NO `safety_stock` columns —
those are computed at runtime by `StockAvailabilityService` (see `warehouse-stock.md`). NO RLS
(warehouse-scoped, no `branch_id`).

A `stock_value` GENERATED column is added by migration
`2025_01_20_000000_add_generated_columns.php`: `GENERATED ALWAYS AS (ROUND(qty * avg_cost, 2))
STORED`.

### 7.2 The costing formula — `StockService::applyTransaction()` L120-170

```php
if ($qty > 0) {
    // === STOCK IN — recalculate moving average cost ===
    $newQty = $oldQty + $qty;
    $newAvgCost = $this->computeAvgCostOnIn($oldQty, $oldAvgCost, $qty, $rate);
} else {
    // === STOCK OUT — reduce qty at current avg_cost (avg_cost unchanged) ===
    $outQty = abs($qty);
    $newQty = $oldQty - $outQty;
    if ($newQty < -self::QTY_TOLERANCE) {
        throw new \RuntimeException(
            "Insufficient stock for product {$productId} in warehouse {$warehouseId}. "
            . "Available: {$oldQty}, Requested: {$outQty}"
        );
    }
    $newAvgCost = $oldAvgCost;   // cost flows out at average, avg unchanged
}
```

### 7.3 `computeAvgCostOnIn()` — L161-170 (verbatim)

```php
private function computeAvgCostOnIn(float $oldQty, float $oldAvgCost, float $inQty, float $inRate): float
{
    $newQty = $oldQty + $inQty;
    if ($newQty <= 0) {
        return $inRate;
    }
    $oldValue = $oldQty * $oldAvgCost;
    $inValue = $inQty * $inRate;
    return ($oldValue + $inValue) / $newQty;
}
```

### 7.4 Rate semantics by reference_type — from `avg_cost_rule.md §3`

| reference_type | rate meaning |
|---|---|
| `purchase_receive` | Net purchase rate (after discount, before tax) — drives avg_cost UP |
| `purchase_return` | The avg_cost at time of the original receive (NOT current avg) |
| `sales_challan` | Current avg_cost at time of challan — drives COGS (qty negative) |
| `sales_return` | **ORIGINAL avg_cost** at time of the original challan's stock-out (CRITICAL — preserves cost integrity) |
| `stock_adjustment` | 0 (or specified per adjustment) — gain/loss to GL adjustment |
| `stock_take` | Current avg_cost — variance posted at current cost |
| `warehouse_transfer` (source) | Current avg_cost (cost flows out) |
| `warehouse_transfer` (dest) | Source avg_cost (preserves cost basis — no P&L impact) |
| `damage` | Current avg_cost (loss posted at current cost) |
| `branch_demand` | Current avg_cost |
| `opening_balance` | Specified per product |
| `reversal` | Original transaction's rate (opposite-sign qty at original rate) |

### 7.5 Sales return ORIGINAL cost preservation — `SalesReturnService.php:192-205` (verbatim)

```php
// 1. Stock IN at ORIGINAL avg_cost (CRITICAL: not current avg_cost).
foreach ($return->items as $item) {
    $this->stockService->applyTransaction([
        'warehouse_id' => $item->warehouse_id,
        'product_id' => $item->product_id,
        'qty' => (float) $item->qty, // positive = IN
        'rate' => (float) $item->original_cost, // ORIGINAL avg_cost from challan
        'reference_type' => 'sales_return',
        'reference_id' => $return->id,
        'notes' => 'Sales Return ' . $return->return_code . ' — stock restored at original cost',
        ...
    ]);
}
```

### 7.6 Warehouse transfer cost inheritance — `WarehouseTransferService.php:320-350` (verbatim)

```php
foreach ($transfer->items as $item) {
    $rate = (float) $item->rate;
    $qty = (float) $item->qty;
    // Source OUT (negative qty, at current avg_cost — rate is the cost).
    $this->stockService->applyTransaction([
        'warehouse_id' => $fromWh, 'product_id' => $item->product_id,
        'qty' => -$qty, 'rate' => $rate,
        'reference_type' => 'warehouse_transfer',
        'reference_id' => $transfer->id,
        ...
    ]);
    // Dest IN (positive qty, at SOURCE avg_cost — transferred at source cost).
    $this->stockService->applyTransaction([
        'warehouse_id' => $toWh, 'product_id' => $item->product_id,
        'qty' => $qty, 'rate' => $rate, // source avg_cost (preserves cost integrity)
        'reference_type' => 'warehouse_transfer',
        'reference_id' => $transfer->id,
        ...
    ]);
}
```

### 7.7 Atomicity contract — `avg_cost_rule.md §4` (verbatim)

```
BEGIN
  1. INSERT INTO stock_transactions (...)          -- the immutable ledger row
  2. SELECT ... FROM warehouse_stock FOR UPDATE    -- lock the row
  3. UPDATE warehouse_stock SET qty, avg_cost       -- apply the movement
COMMIT  (or ROLLBACK on any failure)
```

`StockService::applyTransaction` does **not** open its own `DB::transaction` — the caller wraps
the entire operation (stock movement + GL posting + audit log) in one outer transaction. This
ensures atomicity across stock + GL + audit.

### 7.8 The `mv_stock_valuation` materialized view — migration `2025_01_03_000001:127`

```sql
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_stock_valuation AS
SELECT
    ws.warehouse_id, ws.product_id,
    p.product_code, p.product_name, p.unit,
    w.warehouse_name, w.branch_id, b.branch_name,
    ws.qty AS on_hand_qty, ws.avg_cost,
    (ws.qty * ws.avg_cost) AS stock_value
FROM warehouse_stock ws
INNER JOIN products p ON p.id = ws.product_id
INNER JOIN warehouses w ON w.id = ws.warehouse_id
INNER JOIN branches b ON b.id = w.branch_id
WHERE ws.qty > 0;
```

Plus UNIQUE INDEX on `(warehouse_id, product_id)` for `REFRESH MATERIALIZED VIEW CONCURRENTLY`.

### 7.9 Replay-verify command — `php artisan stock:replay-verify`

`laravel/app/Console/Commands/StockReplayVerify.php`. Replays every `stock_transactions` row
through the avg-cost logic, compares against `warehouse_stock`, logs drift to `avg_cost_drift`
table. Zero drift required for sign-off (acceptance criteria in `avg_cost_rule.md §6`). Options:
`--limit=N`, `--from-id=N`, `--product=N`, `--keep-drift`. Exit code 0 = PASS, 1 = FAIL.

## 8. Intercompany / cross-branch cost

- Warehouse transfers between warehouses in the **same branch**: no GL, no intercompany. The dest
  warehouse inherits the source's avg_cost.
- Warehouse transfers between warehouses in **different branches**: **NOT supported** by the
  WarehouseTransfer module (enforced at `createTransfer` L114-126 + `confirmTransfer` L269-280).
  Cross-branch stock movement MUST go through the Branch Demand module (Phase 13), which posts
  the intercompany Dr `interbranch_receivable` / Cr `interbranch_payable` pair.
- The `postIntercompanyGL` method on `WarehouseTransferService` (L531-617) is **dead code** —
  retained "for potential future use by the Branch Demand module" but never invoked.

## 9. Workflow / state machine

Moving-average cost has no state machine of its own — it is a pure function of stock movements.
The state machines are on the parent transactions (Stock Adjustment, Stock Take, Damage,
Warehouse Transfer — see those files).

## 10. Validation & input rules

- `qty` is `numeric(14,4)` — fractional quantities allowed (e.g. `1.5 KG`).
- `rate` is `numeric(12,2)` — per-base-unit cost.
- `QTY_TOLERANCE = 0.0001` (constant in `StockService`) — handles floating-point dust. The DB
  CHECK uses the same tolerance.
- No min/max rate validation — a rate of 0 is allowed (e.g. for stock adjustment gain with no
  cost basis).

## 11. Reversal & correction flow

`StockService::reverseTransaction()` at L194 is the canonical reversal. It is **append-only**:
the original `stock_transactions` row gets `is_reversed=true` plus `reversal_of_transaction_id`,
`reversed_at`, `reversed_by`, `reverse_reason`; a **NEW** row with opposite-sign qty +
`reference_type='reversal'` is inserted. The original is **never mutated** except for the
reversal-flag columns.

The reversal row uses the **original transaction's rate** (opposite-sign qty at original rate),
so the avg_cost computation is exactly undone — the warehouse_stock returns to its pre-original
state.

## 12. Open questions / known gaps

1. **No config flag for cost method** (`METHOD=MOVING_AVERAGE`). Hardcoded in `StockService.php`.
   NO FIFO support. NO standard-cost support. If a future product line requires FIFO, the entire
   `StockService` would need to be refactored.
2. **`mv_stock_valuation` includes only `qty > 0` rows** — zero-qty + negative-qty warehouses are
   invisible in the MV. The underlying `warehouse_stock` rows exist; the MV is a reporting
   convenience that hides them. Cosmetic gap.
3. **Edge case — zero-qty reset:** avg_cost is **NOT** reset to 0 when qty hits 0 (it stays at
   last value). The next IN recomputes correctly because `oldQty * oldAvg + inQty * inRate` with
   `oldQty=0` produces `inRate`. Documented in `avg_cost_rule.md §2`. Intentional.
4. **`inventory_revaluation` nature unregistered** — referenced by `StockTakeService::postStockTakeGL`
   (Phase 9 cost-drift revaluation) but NOT in `LedgerNatureService::EXTENDED_NATURES`. Flagged
   in `../accounting/chart-of-accounts.md` L325. If the ledger doesn't exist, the revaluation
   throws `'Inventory revaluation ledger not found'`.
5. **No multi-currency inventory** — all rates are `numeric(12,2)` in BDT. NO FX gain/loss
   booking on inventory. The system is single-currency (Bangladesh Taka).
6. **`warehouse_stock` + `stock_transactions` have NO RLS** — warehouse-scoped, no `branch_id`
   column. Branch isolation is enforced at the app layer. A non-admin user querying these tables
   directly via DB would see ALL branches' stock. Defense-in-depth gap (see `warehouse-stock.md`
   §12 #3).

## 13. Accountant review checklist

> **This is a SAFETY-CRITICAL document.** Before marking it Canonical, an accountant with
> production credentials MUST review and sign off on each item below.

- [ ] The moving-average formula (§7.3) is the correct costing method for this business.
- [ ] The rate semantics table (§7.4) — especially the sales_return ORIGINAL cost preservation
      (§7.5) — matches the actual treatment. A product sold at avg_cost X and returned later
      (when avg_cost is Y) is restored at X, not Y.
- [ ] The warehouse-transfer cost inheritance (§7.6) — dest inherits source's avg_cost — is
      correct (no P&L on the transfer itself).
- [ ] The per-warehouse granularity (not per-product-branch) is correct. Each warehouse maintains
      its own independent avg_cost.
- [ ] The zero-qty reset behaviour (§12 #3) — avg_cost stays at last value when qty hits 0 — is
      acceptable.
- [ ] The atomicity contract (§7.7) — single transaction around INSERT + SELECT FOR UPDATE +
      UPDATE — is sufficient to prevent race conditions under concurrency.
- [ ] The `inventory_revaluation` nature gap (§12 #4) — should it be registered in
      `LedgerNatureService::EXTENDED_NATURES` to support Phase 9 cost-drift revaluation?
- [ ] The lack of FIFO / standard-cost support (§12 #1) — is moving average sufficient for all
      product lines, or will a future product line require a different method?
- [ ] The `mv_stock_valuation` exclusion of `qty <= 0` rows (§12 #2) — is this acceptable for
      reporting, or should the MV include all rows?
- [ ] The single-currency assumption (§12 #5) — confirm the system will never need multi-currency
      inventory.
