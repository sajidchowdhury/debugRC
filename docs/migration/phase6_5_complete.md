# Phase 6.5 — Warehouse Transfers (Complete)

**Date:** Phase 6.5 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 6.5 implements Warehouse Transfers with the critical same-branch vs cross-branch distinction. This is the third module (after Phase 6.3 + 6.4) that calls `StockService::applyTransaction()` + posts GL — and the first to post **intercompany GL** with Due-from/Due-to-Branch control accounts.

### Key design decision: Same-branch vs Cross-branch

The legacy PHP only allowed same-branch transfers (threw an exception if branches differed). Phase 6.5 supports BOTH:

| Transfer type | Stock movement | GL journal |
|---|---|---|
| **Same-branch** (from_branch == to_branch) | Source OUT + Dest IN | **NO GL** (inventory reallocated within branch) |
| **Cross-branch** (from_branch != to_branch) | Source OUT + Dest IN | **TWO intercompany journals** (creditor + debtor) |

### Intercompany GL (re-derived from intercompany accounting principles)

For a cross-branch transfer of amount X from Branch A to Branch B:

**1. From-branch (creditor) journal — posted to Branch A's books:**
```
Dr Due-to-Branch (Branch B)     X
   Cr Inventory                    X
```
Branch A loses inventory, gains a receivable from Branch B.

**2. To-branch (debtor) journal — posted to Branch B's books:**
```
Dr Inventory                     X
   Cr Due-from-Branch (Branch A)   X
```
Branch B gains inventory, owes Branch A.

This creates the intercompany settlement tracked via `branch_ledger`. The Due-from/Due-to-Branch accounts must **net to zero** across all branches (verified by the reconciliation hub).

### Rate semantics (per avg_cost_rule.md §3)

- **Source OUT:** rate = current avg_cost (cost flows out at average)
- **Dest IN:** rate = **source avg_cost** (transferred at source cost, NOT dest's avg)

This is critical: the dest branch receives stock at the same cost the source had it. No phantom gain/loss on transfer. The dest's avg_cost is then recalculated based on this incoming rate.

### Two-phase flow (same as 6.3/6.4)
1. **Create (draft):** header + items, no stock, no GL
2. **Confirm:** applies stock (source OUT + dest IN) + posts GL (if cross-branch)
3. **Cancel:** if confirmed, reverses stock + GL; if draft, marks cancelled

### Models ✅ (2)
- **`WarehouseTransfer`** — header with from/to warehouse + branch, is_interbranch flag, status, dual journal_entry_id (creditor + debtor), reversal tracking
- **`WarehouseTransferItem`** — product_id, qty, rate

### WarehouseTransferService ✅ (the core business logic)
**`app/Services/Stock/WarehouseTransferService.php`** — 3 methods:
- `createTransfer()` — creates draft. Auto-detects interbranch. Pre-checks source availability. Auto-fills rate from avg_cost.
- `confirmTransfer()` — applies stock (source OUT + dest IN via StockService) + posts GL (same-branch: none; cross-branch: TWO intercompany journals). All in one DB transaction.
- `cancelTransfer()` — if confirmed: reverses both GL journals + reverses each stock_transaction (append-only). If draft: marks cancelled.

**`postIntercompanyGL()`** — the re-derived intercompany posting:
- Looks up 3 ledgers: inventory, interbranch_receivable (Due-from), interbranch_payable (Due-to)
- Creates creditor journal (from-branch): Dr Due-to / Cr Inventory
- Creates debtor journal (to-branch): Dr Inventory / Cr Due-from
- Records the settlement in `branch_ledger` table

### Controller ✅
**`WarehouseTransferController`** — 6 methods:
- `index()` — list with 6 stats cards + filters + DataTables
- `create()` — form with from/to warehouse + dynamic items
- `store()` — validates + creates draft
- `show()` — detail with items + stock movements + dual GL journals
- `confirm()` — applies stock + GL
- `cancel()` — reverses if confirmed, or marks draft cancelled
- `getProductStock()` — AJAX: returns avg_cost + available_qty

### Views ✅ (3 Blade files, ~1,530 lines)
- **`index.blade.php`** — 6 stats cards (incl. interbranch count), filters, DataTables with interbranch badge
- **`create.blade.php`** — dynamic items with AJAX stock lookup, **live interbranch detection** (client-side branch comparison shows info banner when cross-branch)
- **`show.blade.php`** — two-column: details + items + stock movements + **dual GL journal cards** (creditor + debtor, interbranch only) + same-branch info banner

### Routes ✅
5 routes under `admin/warehouse-transfers/*`:
- GET index, create, show, product-stock (AJAX)
- POST store, confirm, cancel

### Sidebar ✅
Added "Transfers" link to admin sidebar with active-state highlighting.

---

## How a cross-branch transfer works (example)

```
Transfer 10 units of Product A @ 45.00 from WH1 (Head Office) to WH2 (Patuatuli)

1. Create draft → warehouse_transfers (status=draft, is_interbranch=true) + items

2. Confirm:
   a. StockService::applyTransaction (source OUT):
      - stock_transaction (qty=-10, rate=45.00, ref=warehouse_transfer, wh=WH1)
      - warehouse_stock WH1: qty -= 10, avg_cost UNCHANGED (OUT rule)
   
   b. StockService::applyTransaction (dest IN):
      - stock_transaction (qty=+10, rate=45.00, ref=warehouse_transfer, wh=WH2)
      - warehouse_stock WH2: qty += 10, avg_cost RECALCULATED (IN rule)
        (weighted average of WH2's existing stock + the 10 units at 45.00)
   
   c. Intercompany GL — Creditor journal (Head Office books):
      - journal_entries (branch_id=Head Office, ref=warehouse_transfer)
      - journal_lines: Dr Due-to-Branch (Patuatuli) 450 / Cr Inventory 450
   
   d. Intercompany GL — Debtor journal (Patuatuli books):
      - journal_entries (branch_id=Patuatuli, ref=warehouse_transfer)
      - journal_lines: Dr Inventory 450 / Cr Due-from-Branch (Head Office) 450
   
   e. branch_ledger: from=Head Office, to=Patuatuli, amount=450, is_settled=false

3. Cancel (if confirmed):
   - reverseJournalEntry (creditor) + reverseJournalEntry (debtor)
   - reverseTransaction for each stock_transaction (source OUT reversal + dest IN reversal)
   - status=cancelled, is_reversed=true
```

---

## Total Phase 6.5 deliverables

| Category | Count |
|---|---|
| Models | 2 (WarehouseTransfer, WarehouseTransferItem) |
| Services | 1 (WarehouseTransferService) |
| Controllers | 1 (WarehouseTransferController) |
| Blade views | 3 (index, create, show) |
| Routes | 5 |
| **Total new PHP files** | **4** |
| **Total new Blade views** | **3** |

---

## Verification checklist (for VPS)

- [ ] Create a same-branch transfer → confirm → stock moves, NO GL posted
- [ ] Create a cross-branch transfer → confirm → stock moves + TWO GL journals (creditor + debtor)
- [ ] Cancel a confirmed cross-branch transfer → both GL journals reversed + stock reversed
- [ ] Reconciliation hub: Branch Intercompany section shows the Due-from/Due-to balances
- [ ] Branch Intercompany report shows the transfer in mv_branch_intercompany

---

## Next sub-phase

**Phase 6.6 — Damages.** Damage invoices post stock OUT + GL (Dr Damage Loss / Cr Inventory at current avg_cost). Calls `StockService::applyTransaction()` with reference_type='damage'. This completes the Phase 6 Inventory Module.
