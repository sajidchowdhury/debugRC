# Phase 6.6 — Damages (Complete)

**Date:** Phase 6.6 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 6.6 implements Damages — the final sub-phase of Phase 6 (Inventory Module). Damaged/write-off stock is removed from inventory at current avg_cost, with a GL loss posted (Dr Damage Loss / Cr Inventory).

### Two-phase flow (same as 6.3/6.4/6.5)
1. **Create (draft):** header + items, no stock, no GL
2. **Confirm:** stock OUT via `StockService::applyTransaction()` + GL (Dr Damage Loss / Cr Inventory)
3. **Cancel:** if confirmed, reverses stock + GL; if draft, marks cancelled

### GL posting (re-derived from double-entry)
```
Dr Damage Loss (nature: damage_loss, fallback: inventory_shrinkage)
   Cr Inventory (nature: inventory)
```
The loss is valued at the current avg_cost at time of damage. The service looks up `damage_loss` nature first, falls back to `inventory_shrinkage` if not configured (same ledger as stock adjustment decreases).

### Rate semantics (per avg_cost_rule.md §3)
- Stock OUT at current avg_cost (cost flows out at average, avg_cost unchanged on OUT)
- This is the standard OUT rule from the moving-average method

### Models ✅ (2)
- **`DamageInvoice`** — header with damage_code, warehouse_id, branch_id, total_value, reason, status (draft|confirmed|cancelled), journal_entry_id, reversal tracking
- **`DamageInvoiceItem`** — product_id, qty, rate

### DamageService ✅ (3 methods)
- `createDamage()` — creates draft. Pre-checks availability. Auto-generates DMG-YYYYMMDD-NNNN code. Auto-fills rate from avg_cost.
- `confirmDamage()` — stock OUT via StockService + GL. All in one DB transaction.
- `cancelDamage()` — if confirmed: reverses GL + reverses each stock_transaction (append-only). If draft: marks cancelled.

### Controller ✅
**`DamageController`** — 6 methods: index (5 stats + filters), create, store, show (detail + stock movements + GL journal), confirm, cancel, getProductStock (AJAX)

### Views ✅ (3 Blade files)
- **`index.blade.php`** — 5 stats cards, filters, DataTables with red/danger theme
- **`create.blade.php`** — dynamic items with AJAX rate lookup, warning banner, live total
- **`show.blade.php`** — two-column: details + items + stock movements + GL journal (left), status + actions (right). SweetAlert2 confirm/cancel.

### Routes ✅
5 routes under `admin/damages/*` + resource routes

### Sidebar ✅
Added "Damages" link to admin sidebar with active-state highlighting.

---

## Phase 6 Inventory Module — Complete Summary

Phase 6.6 completes the entire Inventory Module. Here's the full picture:

| Sub-phase | Module | Stock Movements | GL Posting | Key Feature |
|---|---|---|---|---|
| 6.1 | Stock Transactions (SSOT) | Foundation | — | Re-derived avg-cost logic, replay verify |
| 6.2 | Verification Infrastructure | — | — | Drift logging, accountant manual verify |
| 6.3 | Stock Adjustments | IN/OUT | Dr Inv/Cr Surplus or Dr Shrinkage/Cr Inv | Two-phase draft→confirm→cancel |
| 6.4 | Stock Take | Variance IN/OUT | Dr Inv/Cr Surplus or Dr Shrinkage/Cr Inv | Per-warehouse counts, GENERATED difference |
| 6.5 | Warehouse Transfers | Source OUT + Dest IN | Same-branch: none. Cross-branch: intercompany | Due-from/Due-to-Branch control accounts |
| 6.6 | Damages | OUT | Dr Damage Loss / Cr Inventory | Stock write-off at avg_cost |

### All 5 stock modules use the same patterns:
1. **StockService::applyTransaction()** — the single entry point for all stock movements
2. **Two-phase flow** — draft → confirm → cancel (safer than legacy immediate-post)
3. **Append-only reversals** — originals never mutated except is_reversed flag
4. **Atomicity** — stock + GL in single DB transaction
5. **JournalPostingService** — Dr=Cr enforced at DB level (trigger + service validation)
6. **Document sequence** — atomic code generation (ADJ-/ST-/WT-/DMG- with SELECT FOR UPDATE)

### Inventory reconciliation gate (per MIGRATION_PLAN §3.2):
- Σ `stock_transactions.qty` per (product, warehouse) == `warehouse_stock.qty` ✓ (DB trigger)
- `warehouse_stock.avg_cost` recomputed from scratch matches stored value ✓ (replay test)
- Σ stock value = Σ (qty × avg_cost) ✓ (verified by reconciliation hub)

---

## Total Phase 6.6 deliverables

| Category | Count |
|---|---|
| Models | 2 (DamageInvoice, DamageInvoiceItem) |
| Services | 1 (DamageService) |
| Controllers | 1 (DamageController) |
| Blade views | 3 (index, create, show) |
| Routes | 5 |
| **Total new PHP files** | **4** |
| **Total new Blade views** | **3** |

---

## Next phase

**Phase 7 — Purchase Module.** PO → GRN → Return. The GRN is the economic event (Dr Inventory / Cr AP + supplier_ledger credit). Calls `StockService::applyTransaction()` with reference_type='purchase_receive' (stock IN at purchase rate, avg_cost recalculated).
