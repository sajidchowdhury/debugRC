# Phase 6.2 — Warehouse Stock + Moving-Average Cost (Re-Derive) Verification (Complete)

**Date:** Phase 6.2 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 6.2 is the **verification infrastructure** for the re-derived moving-average cost logic. The avg-cost formula itself was written in Phase 6.1 (`StockService::applyTransaction()`); Phase 6.2 adds the persistent drift logging, shadow table, accountant manual verification tool, and the drift investigation UI.

### 6.2.1 — Drift Logging Tables ✅
**Migration:** `database/migrations/2025_01_04_000002_create_avg_cost_drift_tables.php`

Two tables:

**`avg_cost_drift`** — logs every (warehouse_id, product_id) where the replay's computed qty or avg_cost diverges from live warehouse_stock:
- `live_qty`, `shadow_qty`, `qty_drift`
- `live_avg_cost`, `shadow_avg_cost`, `cost_drift`
- `last_transaction_id`, `last_reference_type`, `last_reference_id` (for root-cause analysis)
- `investigation_notes` (text — accountant/developer fills in what caused the drift)
- `status` (open → investigated → resolved)
- `detected_at`, `resolved_at`

**`warehouse_stock_shadow`** — persistent snapshot of the replay result:
- Composite PK (warehouse_id, product_id)
- `qty`, `avg_cost`, `transaction_count`, `last_transaction_id`, `replayed_at`
- Truncated + repopulated on each `stock:replay-verify` run
- The accountant can query this side-by-side with live `warehouse_stock` to inspect differences

### 6.2.2 — Enhanced Replay Verification Command ✅
**`app/Console/Commands/StockReplayVerify.php`** — enhanced with:

- **Writes shadow balances** to `warehouse_stock_shadow` table (persistent, browsable)
- **Logs every drift** to `avg_cost_drift` table with last-transaction info for root-cause analysis
- **Tracks `last_transaction_id`** per shadow entry — when drift is found, you know exactly which transaction caused it
- **Clears previous open drift** on each run (unless `--keep-drift` flag)
- **Detects orphan shadow** entries (products in shadow but not in live warehouse_stock)
- Reports drift count + max drift + sample mismatches to console
- Exit code 0 = zero drift (PASS), 1 = drift detected (FAIL)

**Usage:**
```bash
# Full replay (all ~38,775 transactions)
php artisan stock:replay-verify

# Replay a single product (for focused investigation)
php artisan stock:replay-verify --product=42

# Replay a subset
php artisan stock:replay-verify --limit=1000 --from-id=5000

# Keep previous drift rows (don't clear)
php artisan stock:replay-verify --keep-drift
```

### 6.2.3 — Accountant Manual Verification Command ✅
**`app/Console/Commands/StockManualVerify.php`** — `php artisan stock:manual-verify`

The accountant sign-off tool required by `avg_cost_rule.md` §7. Picks 10 sample products (those with the most transactions — the most interesting to verify) and shows:

- Every stock transaction for the product, in chronological order
- A step-by-step table: TX# | Date | Move (IN/OUT) | Qty | Rate | OldQty | OldAvg | NewQty | NewAvg | RefType | Ref#
- The computed final qty + avg_cost
- The live warehouse_stock value
- ✓ MATCH or ✗ MISMATCH for each

**Usage:**
```bash
# Verify 10 sample products (most transactions)
php artisan stock:manual-verify

# Verify a specific product
php artisan stock:manual-verify --product=42

# Verify 20 samples
php artisan stock:manual-verify --count=20

# Filter to one warehouse
php artisan stock:manual-verify --warehouse=3
```

**Output example:**
```
Product: PROD-001 — LED BULB 12W (unit: Pcs)
  Warehouse: Head Office Warehouse (ID: 1)

  | TX# | Date       | Move | Qty    | Rate  | OldQty  | OldAvg | NewQty  | NewAvg | RefType          | Ref# |
  | 1   | 2025-01-15 | IN   | 100.00 | 45.00 | 0.00    | 0.00   | 100.00  | 45.00  | purchase_receive | 1    |
  | 2   | 2025-01-20 | OUT  | -20.00 | 45.00 | 100.00  | 45.00  | 80.00   | 45.00  | sales_challan    | 5    |
  | 3   | 2025-02-01 | IN   | 50.00  | 48.00 | 80.00   | 45.00  | 130.00  | 46.15  | purchase_receive | 8    |

  Computed final: qty=130.0000, avg_cost=46.15
  Live value:     qty=130.0000, avg_cost=46.15
  ✓ MATCH — computed values match live warehouse_stock.
```

### 6.2.4 — Drift Investigation UI ✅
**Controller:** `StockTransactionController::drift()` + `updateDrift()`
**View:** `resources/views/admin/stock/drift.blade.php`
**Routes:** `GET /admin/stock/drift` + `POST /admin/stock/drift/{id}`

A full admin UI for browsing + investigating drift rows:
- 4 stats cards (total / open / investigated / resolved)
- Sign-off banner: green if zero open, red if open drift exists
- Filter by status + warehouse
- Drift table with: warehouse, product, live vs shadow qty/cost, drift amounts, last transaction link, status badge
- Modal per row for updating status + investigation notes
- Investigation notes section at the bottom

---

## The verification workflow (on the VPS)

```bash
# Step 1: Run the full replay test
php artisan stock:replay-verify

# If zero drift → PASS. Skip to step 4.
# If drift detected → investigate:

# Step 2: Browse drift in the admin UI
# Navigate to /admin/stock/drift
# Click each row, review the last transaction, add investigation notes

# Step 3: For focused investigation, replay a single product
php artisan stock:replay-verify --product=<product_id>

# Step 4: Accountant manual verification (10 sample products)
php artisan stock:manual-verify

# Step 5: Accountant signs avg_cost_rule.md §7

# Step 6: All drift resolved → Phase 6.2 sign-off
```

---

## Sign-off gate (per avg_cost_rule.md §7)

- [ ] `php artisan stock:replay-verify` exits 0 (zero drift on all ~38,775 transactions)
- [ ] `php artisan stock:manual-verify` shows ✓ MATCH for all 10 sample products
- [ ] All `avg_cost_drift` rows marked `resolved` (zero open)
- [ ] Lead developer: code review of `StockService::applyTransaction()` + `computeAvgCostOnIn()`
- [ ] Accountant: `avg_cost_rule.md` reviewed and approved (especially §3 rate semantics)
- [ ] Accountant: 10 sample products manually verified (step-by-step calculations match)
- [ ] Project owner: final sign-off

---

## What was NOT changed

- The `StockService::applyTransaction()` logic itself — that was written + committed in Phase 6.1 and is NOT modified here. Phase 6.2 only adds verification infrastructure around it.
- The `avg_cost_rule.md` first-principles document — unchanged from Phase 6.1.
- The `StockTransaction` and `WarehouseStock` models — unchanged.

---

## Next sub-phase

**Phase 6.3 — Stock Adjustments.** Stock adjustments (qty +/- with reason) call `StockService::applyTransaction()` with reference_type='stock_adjustment' and post a GL journal (Dr/Cr Inventory vs Adjustment Gain/Loss ledger). Two-phase: create (draft) → confirm (posts). Cancel reverses.
