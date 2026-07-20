# Phase 6.1 — Stock Transactions (SSOT) (Complete)

**Date:** Phase 6.1 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

### First-Principles Document ✅
**File:** `docs/migration/avg_cost_rule.md`

A complete re-derivation of the moving-average cost method from inventory accounting principles (NOT copied from legacy). Documents:
- The costing method (perpetual moving average / weighted average)
- The re-derived formula: IN recalculates avg, OUT leaves avg unchanged
- Rate semantics by reference type (11 types) — including the **critical** sales-return-at-original-cost rule
- Atomicity contract (single DB transaction: log + lock + update)
- Availability formula (physical − sales pipeline)
- Replay test acceptance criteria (zero drift on 38,775 transactions)

**Must be reviewed + signed off by the accountant before implementation** (per MIGRATION_PLAN §3.3).

### Migration ✅
**`database/migrations/2025_01_04_000001_add_reversal_columns_to_stock_transactions.php`**

Adds 5 columns to `stock_transactions` for append-only reversal tracking:
- `is_reversed` (boolean, default false)
- `reversal_of_transaction_id` (nullable FK to stock_transactions.id)
- `reversed_at` (nullable timestamp)
- `reversed_by` (nullable int → users.id)
- `reverse_reason` (nullable text)

Plus 2 partial indexes for reversal lookups.

### Models ✅
**`app/Models/StockTransaction.php`** — the immutable inventory ledger (SSOT):
- Signed `qty` (positive = IN, negative = OUT)
- Snapshotted `rate` (meaning depends on reference_type — see avg_cost_rule.md §3)
- `total_value` is GENERATED (`qty × rate`)
- 11 reference types (purchase_receive, purchase_return, sales_challan, sales_return, stock_adjustment, stock_take, warehouse_transfer, damage, branch_demand, opening_balance, reversal)
- Append-only: reversals are new rows, originals marked `is_reversed`
- Scopes: `notReversed()`, `forProductInWarehouse()`, `forReference()`
- Helpers: `isIn()`, `isOut()`, `absQty()`

**`app/Models/WarehouseStock.php`** — current on-hand balance with moving-average cost:
- Composite PRIMARY KEY (warehouse_id, product_id) — no `id` column
- `qty` + `avg_cost` maintained by StockService
- `stockValue()` helper = qty × avg_cost

### Services ✅ (the re-derived core logic)

**`app/Services/Stock/StockService.php`** — the single entry point for all stock movements:
- `applyTransaction(array $data)` — atomic movement: INSERT ledger + lock warehouse_stock FOR UPDATE + update qty/avg_cost
- `computeAvgCostOnIn()` — the re-derived formula: `(old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)`
- OUT movements: reduce qty, avg_cost UNCHANGED, guard against negative stock
- `reverseTransaction()` — append-only reversal (new row with opposite-sign qty + original rate, mark original is_reversed)
- `getWarehouseAvgCost()`, `getWarehouseQty()`, `lockBranchProductsForUpdate()`
- Input validation (reference_type whitelist, non-zero qty, positive IDs)

**`app/Services/Stock/StockAvailabilityService.php`** — available-to-pick calculation:
- `getBranchAvailableQty()` = physical − open sales pipeline (branch-level)
- `getWarehouseAvailableQty()` = physical − open sales pipeline (warehouse-level)
- `getBranchPhysicalQty()`, `getWarehousePhysicalQty()`
- `getBranchPipelineQty()`, `getWarehousePipelineQty()` (open dispatches not yet challan-completed)
- `getBranchWarehouseBreakdown()` — per-warehouse breakdown for challan picker modal
- `assertBranchProductsAvailable()` — throws if insufficient

### Controller ✅
**`app/Http/Controllers/Admin/StockTransactionController.php`** — read-only listing + AJAX:
- `index()` — stock transactions ledger (searchable, filterable, paginated)
- `warehouseStock()` — current balances (qty + avg_cost + value per warehouse/product)
- `show()` — single transaction detail with reversal info
- `checkAvailability()` — AJAX endpoint for sales module (returns physical/pipeline/available/avg_cost)
- `warehouseBreakdown()` — AJAX endpoint for challan picker modal

### Replay Verification Command ✅
**`app/Console/Commands/StockReplayVerify.php`** — `php artisan stock:replay-verify`

The acceptance test for Phase 6.1:
1. Loads all stock_transactions ordered by (created_at, id)
2. Replays each through the avg-cost formula (in-memory shadow balances)
3. Compares shadow to live warehouse_stock for every (warehouse_id, product_id)
4. Reports drift count + max drift + sample mismatches
5. Exits 0 if zero drift, 1 if any drift

**This is the gate for Phase 6.1 sign-off** — must show zero drift on all ~38,775 historical transactions.

### Views ✅
**3 Blade files** at `resources/views/admin/stock/`:
- `transactions.blade.php` — immutable ledger (287 lines): filters, summary stats (total/IN/OUT), DataTables, color-coded reference-type badges, signed qty with green/red text, reversal status, pagination
- `warehouse_stock.blade.php` — current balances (254 lines): 4 summary cards, filters, DataTables with low-stock highlighting, totals footer
- `show.blade.php` — single transaction detail (300 lines): full details, reversal info with links

### Routes ✅
5 routes under `admin/stock/*` prefix:
- `GET /admin/stock/transactions` — ledger
- `GET /admin/stock/warehouse-stock` — current balances
- `GET /admin/stock/transactions/{id}` — detail
- `GET /admin/stock/availability` — AJAX availability check
- `GET /admin/stock/warehouse-breakdown` — AJAX warehouse breakdown

### Sidebar ✅
Added "Stock" link to admin sidebar with active-state highlighting.

---

## Key design decisions (re-derived, not copied)

### 1. Atomic movement (single DB transaction)
```
applyTransaction():
  1. INSERT stock_transaction (immutable ledger)
  2. SELECT warehouse_stock FOR UPDATE (lock — prevents race conditions)
  3. UPDATE warehouse_stock qty + avg_cost
  4. (caller commits the outer transaction)
```
This is the #1 inventory bug prevention: the ledger and the balance commit together or not at all.

### 2. The avg_cost formula (re-derived)
```
IN  (qty > 0): new_avg = (old_qty × old_avg + in_qty × in_rate) / (old_qty + in_qty)
OUT (qty < 0): avg UNCHANGED, qty reduced, guard against negative
```
Edge case handled: first receipt (old_qty=0) → new_avg = in_rate (naturally from the formula).

### 3. Append-only reversals
The original transaction is NEVER mutated (except the is_reversed flag). A reversal is a NEW stock_transaction row with:
- `qty = -original.qty` (opposite sign)
- `rate = original.rate` (or avg_cost if original rate was 0)
- `reference_type = 'reversal'`, `reference_id = original.id`

This preserves the complete audit trail — you can always see both the original and the reversal.

### 4. Rate semantics by reference_type (CRITICAL)
The rate field means different things for different movement types. The most critical:
- **sales_return**: rate = ORIGINAL avg_cost at time of the original challan (NOT current). This ensures the COGS reversal matches the original COGS exactly.
- **warehouse_transfer dest**: rate = source avg_cost (transferred at source cost)
- **purchase_return**: rate = avg_cost at time of original receive (restores at original cost)

These rules are documented in `avg_cost_rule.md` §3 and must be followed by all calling modules (Phase 7+8).

### 5. Availability = physical − pipeline
Available stock accounts for the sales pipeline (drafted invoices not yet dispatched). This prevents overselling when a salesman creates a draft invoice — those units are "soft-held" even though physical stock hasn't moved.

---

## What still needs to happen

### On the VPS (after Phase 1 provisioning):
1. `php artisan migrate` — adds the 5 reversal columns to stock_transactions
2. `php artisan stock:replay-verify` — **MUST pass with zero drift** before sign-off
3. Navigate to `/admin/stock/transactions` — verify the ledger loads
4. Navigate to `/admin/stock/warehouse-stock` — verify current balances match the old system

### Sign-off gate (per MIGRATION_PLAN §3):
- [ ] `php artisan stock:replay-verify` exits 0 (zero drift on all transactions)
- [ ] Lead developer: code review
- [ ] Accountant: `avg_cost_rule.md` reviewed and approved (especially §3 rate semantics)
- [ ] Project owner: shadow-mode confirmation

---

## Next sub-phase

**Phase 6.2 — Warehouse Stock + Moving-Average Cost (RE-DERIVE)** — the replay test itself, running the new StockService against a shadow table and verifying zero drift against live warehouse_stock. The replay command is already written; Phase 6.2 is executing it on the VPS + investigating any drift found.
