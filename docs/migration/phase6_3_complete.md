# Phase 6.3 — Stock Adjustments (Complete)

**Date:** Phase 6.3 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 6.3 implements Stock Adjustments — the first module that calls `StockService::applyTransaction()` with a real business reference_type + posts a GL journal entry. This validates the Phase 6.1 SSOT + avg-cost logic in a real business flow.

### Key design decision: Two-phase (better than legacy)

The legacy PHP posted stock + GL immediately on create (no draft state). Phase 6.3 implements a **two-phase flow** per the MIGRATION_PLAN:

1. **Create (draft):** header + items saved, NO stock movement, NO GL. User can review.
2. **Confirm:** applies stock via `StockService::applyTransaction()` + posts GL journal. status → confirmed.
3. **Cancel:** if confirmed, reverses stock (append-only reversal) + reverses GL (swap Dr/Cr); if draft, just marks cancelled.

This is safer than the legacy immediate-post — a user can create a draft, have a manager review it, then confirm. Mistakes are caught before they hit the inventory ledger or GL.

### Models ✅
- **`StockAdjustment`** — header with adjustment_code, warehouse_id, branch_id, adjustment_type (increase|decrease), total_amount, status (draft|confirmed|cancelled), journal_entry_id, reversal tracking. Helpers: isDraft(), isConfirmed(), isCancelled(), isIncrease(), isDecrease().
- **`StockAdjustmentItem`** — one row per product: product_id, qty, rate, reason. `amount()` helper = qty × rate.

### JournalPostingService ✅ (minimal version for Phase 6.3)
**`app/Services/Accounting/JournalPostingService.php`** — the GL posting engine (full version with ~40 methods comes in Phase 9). This minimal version provides:
- `createJournalEntry(array $entry, array $lines)` — creates journal_entries + journal_lines with Dr=Cr validation (the DB trigger is defense-in-depth)
- `reverseJournalEntry(int $id, int $by, string $reason)` — swap Dr/Cr, mark original is_reversed
- `lookupLedgerByNature(string $nature)` — finds the active ledger_id for a given nature (e.g. 'inventory', 'inventory_shrinkage', 'inventory_surplus')
- `generateEntryNo()` — atomic JE-YYYY-NNNNNN using document_sequences with SELECT FOR UPDATE (**fixes the legacy COUNT+1 race condition**)

### StockAdjustmentService ✅ (the core business logic)
**`app/Services/Stock/StockAdjustmentService.php`** — three methods:
- `createAdjustment()` — creates draft (header + items, no stock/GL). Pre-checks availability for decrease. Auto-generates ADJ-YYYYMMDD-NNNN code. Auto-fills rate from current avg_cost if not provided.
- `confirmAdjustment()` — applies stock via StockService (reference_type='stock_adjustment') + posts GL. All in one DB transaction (atomicity contract).
- `cancelAdjustment()` — if confirmed: reverses GL + reverses each stock_transaction (append-only). If draft: just marks cancelled.

**GL posting rules (re-derived from double-entry principles):**
- **Increase (stock goes UP):** Dr Inventory / Cr Inventory Surplus (gain)
- **Decrease (stock goes DOWN):** Dr Inventory Shrinkage (loss) / Cr Inventory

### Controller ✅
**`StockAdjustmentController`** — 7 methods:
- `index()` — searchable/filterable list with 5 stats cards
- `create()` — form with warehouse select + dynamic items table
- `store()` — validates + calls createAdjustment (creates draft)
- `show()` — detail with items + stock movements + GL journal lines
- `confirm()` — POST: calls confirmAdjustment (applies stock + GL)
- `cancel()` — POST with required reason: calls cancelAdjustment
- `getProductRate()` — AJAX: returns avg_cost + available_qty for a product/warehouse
- `audit()` — 4 health checks (missing GL, unbalanced JE, missing stock tx, stale drafts)

### Views ✅ (4 Blade files, ~1,372 lines)
- **`index.blade.php`** — 5 stats cards, filters, DataTables with status/type badges
- **`create.blade.php`** — dynamic items table with AJAX rate lookup, live total computation, SweetAlert2 submit guard
- **`show.blade.php`** — two-column layout: details + items (left), status + actions (right). Conditional stock movements card + GL journal card. SweetAlert2 confirm/cancel dialogs.
- **`audit.blade.php`** — health-check checklist with pass/fail/warn badges

### Routes ✅
8 routes under `admin/stock-adjustments/*`:
- GET index, create, show, audit, product-rate (AJAX)
- POST store, confirm, cancel

### Sidebar ✅
Added "Adjustments" link to admin sidebar with active-state highlighting.

---

## How the two-phase flow works (example)

```
1. User creates adjustment (increase, 10 units of Product A @ 45.00)
   → stock_adjustments row (status=draft, total_amount=450.00)
   → stock_adjustment_items row (product_id=A, qty=10, rate=45.00)
   → NO stock_transactions row
   → NO journal_entries row

2. Manager reviews + clicks "Confirm"
   → StockService::applyTransaction():
     - INSERT stock_transaction (qty=+10, rate=45.00, ref=stock_adjustment, ref_id=adj_id)
     - UPDATE warehouse_stock (qty += 10, avg_cost recalculated)
   → JournalPostingService::createJournalEntry():
     - INSERT journal_entries (entry_no=JE-2025-000123)
     - INSERT journal_lines (Dr Inventory 450 / Cr Inventory Surplus 450)
   → stock_adjustments.status = 'confirmed', journal_entry_id = 123

3. User realizes mistake, clicks "Cancel" with reason "wrong product"
   → JournalPostingService::reverseJournalEntry(123):
     - INSERT journal_entries (reversal, swap Dr/Cr)
     - UPDATE journal_entries SET is_reversed=true WHERE id=123
   → StockService::reverseTransaction() for each item:
     - INSERT stock_transaction (qty=-10, rate=45.00, ref=reversal)
     - UPDATE warehouse_stock (qty -= 10, avg_cost unchanged on OUT)
     - UPDATE original stock_transaction SET is_reversed=true
   → stock_adjustments.status = 'cancelled', is_reversed = true
```

---

## Total Phase 6.3 deliverables

| Category | Count |
|---|---|
| Models | 2 (StockAdjustment, StockAdjustmentItem) |
| Services | 2 (StockAdjustmentService, JournalPostingService minimal) |
| Controllers | 1 (StockAdjustmentController) |
| Blade views | 4 (index, create, show, audit) |
| Routes | 8 |
| **Total new PHP files** | **5** |
| **Total new Blade views** | **4** |

---

## Verification checklist (for VPS)

- [ ] `php artisan migrate` (no new migration needed — tables exist from Phase 2)
- [ ] Navigate to `/admin/stock-adjustments` — list loads
- [ ] Create a draft increase adjustment — stock doesn't change, no GL
- [ ] Confirm it — stock_transaction created, warehouse_stock updated, GL journal created (Dr=Cr)
- [ ] Cancel a confirmed adjustment — stock reversed, GL reversed, status=cancelled
- [ ] Cancel a draft adjustment — no stock/GL changes, status=cancelled
- [ ] Audit page shows all 4 checks passing
- [ ] Reconciliation hub: Inventory section still green after adjustments

---

## Next sub-phase

**Phase 6.4 — Stock Take (Variance).** Stock take sessions with per-warehouse counts, variance calculation (physical - system), and posting (adjustment + GL at current avg_cost). Calls StockAdjustmentService under the hood for the actual stock movement.
