# Phase 6.4 — Stock Take (Variance) (Complete)

**Date:** Phase 6.4 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 6.4 implements Stock Take — periodic physical inventory counts with variance calculation and posting. This is the second module (after Phase 6.3 Stock Adjustments) that calls `StockService::applyTransaction()` + posts GL.

### Workflow (re-derived from inventory audit principles)
1. **Create Session (draft):** header + selected warehouses. No counts yet.
2. **Setup Counts:** for each warehouse, load all active products + their current system_qty (warehouse_stock.qty) + avg_cost into stock_take_items. Session status → counting.
3. **Save Counts:** user enters physical_qty per product. Warehouse status → completed.
4. **Post Session:** for each item with variance (physical ≠ system):
   - Apply stock movement via `StockService::applyTransaction()` with reference_type='stock_take'
     - Positive variance (physical > system): stock IN at current avg_cost → gain
     - Negative variance (physical < system): stock OUT at current avg_cost → loss
   - Mark item is_applied=true
   - Post single GL journal for net gain/loss
   - Session status → posted
5. **Cancel:** if posted, reverse stock + GL; if draft/counting, just mark cancelled.

### The variance formula
```
difference = physical_qty - system_qty
```
The `difference` column is a PostgreSQL **GENERATED ALWAYS AS (physical_qty - system_qty) STORED** column — the DB computes it, not the app. This guarantees the variance is always consistent with the two input quantities.

### Models ✅ (3)
- **`StockTakeSession`** — header with session_code, branch_id, status (draft|counting|posted|cancelled), journal_entry_id, reversal tracking. Helpers: isDraft(), isCounting(), isPosted(), isCancelled().
- **`StockTakeWarehouse`** — links session to warehouses being counted. Status: pending → counting → completed.
- **`StockTakeItem`** — one row per (session, warehouse, product) with system_qty, physical_qty, GENERATED difference, rate, reason, is_applied. Helpers: hasVariance(), varianceValue().

### StockTakeService ✅ (the core business logic)
**`app/Services/Stock/StockTakeService.php`** — 5 methods:
- `createSession()` — creates draft with selected warehouses. Auto-generates ST-YYYYMMDD-NNNN code.
- `setupWarehouseCounts()` — loads all active products + current warehouse_stock into stock_take_items. Warehouse status → counting.
- `saveCounts()` — saves physical_qty per product. Warehouse status → completed.
- `postSession()` — applies variances via StockService + posts GL. Single GL journal for net gain/loss.
- `cancelSession()` — if posted: reverses GL + reverses each stock_transaction (append-only). If draft/counting: just marks cancelled.

**GL posting rules (same as stock adjustments — re-derived from double-entry):**
- Net gain (physical > system): Dr Inventory / Cr Inventory Surplus
- Net loss (physical < system): Dr Inventory Shrinkage / Cr Inventory

### Controller ✅
**`StockTakeController`** — 8 methods:
- `index()` — list with 5 stats cards + filters + DataTables
- `create()` — form with branch select + warehouse checkboxes
- `store()` — validates + creates session
- `show()` — detail with warehouses, variance lines, stock movements, GL journal, progress card
- `setupCounts()` — loads products for a warehouse
- `count()` — count entry form
- `saveCounts()` — saves physical counts + per-line reasons
- `post()` — applies variances + posts GL (SweetAlert2 confirm)
- `cancel()` — reverses if posted, or marks cancelled (SweetAlert2 with required reason)

### Views ✅ (4 Blade files, ~1,520 lines)
- **`index.blade.php`** — 5 stats cards, filters, DataTables with per-session variance aggregation (single bulk query, no N+1)
- **`create.blade.php`** — branch select + warehouse checkboxes grouped by branch with "select all"
- **`show.blade.php`** — two-column: session details + warehouses + variance lines + stock movements + GL journal (left), progress card + actions (right). SweetAlert2 for post/cancel.
- **`count.blade.php`** — count entry table with live JS difference + value-impact computation, per-line reasons, footer totals

### Routes ✅
7 routes under `admin/stock-take/*`:
- GET index, create, show, setup, count
- POST store, saveCounts, post, cancel

### Sidebar ✅
Added "Stock Take" link to admin sidebar with active-state highlighting.

---

## How the variance posting works (example)

```
1. Create session for Head Office branch, warehouses WH1 + WH2
   → stock_take_sessions (status=draft)
   → stock_take_warehouses (2 rows, status=pending)

2. Setup counts for WH1
   → stock_take_items (all active products with system_qty from warehouse_stock)
   → WH1 status → counting, session status → counting

3. User counts: Product A system=100, physical=98 (variance=-2), Product B system=50, physical=52 (variance=+2)
   → saveCounts updates physical_qty
   → WH1 status → completed

4. Post session
   → For Product A: StockService::applyTransaction(qty=-2, rate=avg_cost, ref=stock_take)
     → stock_transaction (qty=-2, OUT)
     → warehouse_stock.qty -= 2 (avg unchanged on OUT)
   → For Product B: StockService::applyTransaction(qty=+2, rate=avg_cost, ref=stock_take)
     → stock_transaction (qty=+2, IN)
     → warehouse_stock.qty += 2, avg_cost recalculated
   → GL journal (single entry):
     - If net gain: Dr Inventory / Cr Inventory Surplus
     - If net loss: Dr Inventory Shrinkage / Cr Inventory
   → session status → posted

5. Cancel (if posted)
   → reverseJournalEntry (swap Dr/Cr)
   → reverseTransaction for each stock_transaction (append-only)
   → session status → cancelled, is_reversed=true
```

---

## Total Phase 6.4 deliverables

| Category | Count |
|---|---|
| Models | 3 (StockTakeSession, StockTakeWarehouse, StockTakeItem) |
| Services | 1 (StockTakeService) |
| Controllers | 1 (StockTakeController) |
| Blade views | 4 (index, create, show, count) |
| Routes | 7 |
| **Total new PHP files** | **5** |
| **Total new Blade views** | **4** |

---

## Verification checklist (for VPS)

- [ ] Create a session with 2 warehouses → status=draft
- [ ] Setup counts for a warehouse → items loaded with system_qty
- [ ] Enter physical counts → save → warehouse status=completed
- [ ] Post session → stock_transactions created, warehouse_stock updated, GL journal created (Dr=Cr)
- [ ] Variance lines show on detail page with correct difference + value
- [ ] Cancel a posted session → stock reversed, GL reversed, status=cancelled
- [ ] Reconciliation hub: Inventory section still green after stock take

---

## Next sub-phase

**Phase 6.5 — Warehouse Transfers.** Cross-warehouse stock transfers with intercompany GL (same-branch = no GL, cross-branch = intercompany GL with Due-from/Due-to-Branch control accounts). Calls `StockService::applyTransaction()` twice (source OUT + dest IN) + posts GL.
