# Phase 7.1 — Purchase Orders (Complete)

**Date:** Phase 7.1 execution
**Repo:** `sajidchowdhury/RC_ERP_v2` (private)

---

## What was delivered

Phase 7.1 implements Purchase Orders — draft documents for procuring goods from suppliers. A PO moves NO stock and posts NO GL; the economic event is the GRN (Goods Received Note) in Phase 7.2.

### Key design: PO is a draft document
- **No stock movement** — stock is only affected when goods are received (GRN, Phase 7.2)
- **No GL journal** — no accounting impact until goods are received
- **`received_qty` tracking** — each PO item tracks how much has been received via GRN. When `received_qty >= qty` for all items → status auto-updates to `received`. When some but not all → `partial`.

### Status flow
```
draft → sent → partial → received → cancelled
```
- **draft:** created but not sent to supplier. Can be edited.
- **sent:** sent to supplier, awaiting delivery. Can receive goods.
- **partial:** some items received via GRN. Can receive remaining.
- **received:** all items fully received. PO is complete.
- **cancelled:** cancelled (only draft/sent can be cancelled).

### PO code generation (fixes legacy race condition)
Legacy used `COUNT(*) + 1` for PO codes (race condition — two concurrent POs could generate the same code). Phase 7.1 uses `document_sequences` with `SELECT FOR UPDATE` — atomic, race-free. Format: `PO-YYYYMMDD-NNNN`.

### Models ✅ (2)
- **`PurchaseOrder`** — header with po_code, supplier, branch, warehouse, sub_total/discount/tax/total, status, expected_date, notes. Helpers: isDraft/isSent/isPartial/isReceived/isCancelled, canEdit (draft only), canCancel (draft/sent), canReceive (sent/partial).
- **`PurchaseOrderItem`** — product_id, qty, received_qty, rate, amount (GENERATED). Helpers: remainingQty(), isFullyReceived().

### PurchaseOrderService ✅ (5 methods)
- `createOrder()` — creates draft PO. Auto-generates PO code. Calculates sub_total/total from items.
- `updateOrder()` — edits a draft PO (only draft can be edited). Deletes + re-inserts items.
- `markAsSent()` — marks draft → sent.
- `cancelOrder()` — cancels draft/sent PO with reason.
- `updateReceivedQty()` — called by GRN (Phase 7.2) to increment received_qty + auto-update status (partial/received).

### Controller ✅
**`PurchaseOrderController`** — 7 methods: index (7 stats + filters), create, store, show, edit, update, markAsSent, cancel

### Views ✅ (4 Blade files)
- **`index.blade.php`** — 7 stats cards, filters, DataTables with status badges (blue theme)
- **`create.blade.php`** — dynamic items table, discount/tax, live sub-total + total, SweetAlert2 submit guard
- **`edit.blade.php`** — same as create, pre-filled from $po, PUT method
- **`show.blade.php`** — two-column: details + items (with Qty Ordered/Received/Remaining) + reception progress + conditional actions (Edit, Mark as Sent, Cancel, GRN placeholder for Phase 7.2)

### Routes ✅
- GET index, create, show, edit
- POST store, mark-sent, cancel
- PUT update

### Sidebar ✅
Added "Purchase Orders" link to admin sidebar.

---

## Total Phase 7.1 deliverables

| Category | Count |
|---|---|
| Models | 2 (PurchaseOrder, PurchaseOrderItem) |
| Services | 1 (PurchaseOrderService) |
| Controllers | 1 (PurchaseOrderController) |
| Blade views | 4 (index, create, edit, show) |
| Routes | 7 |
| **Total new PHP files** | **4** |
| **Total new Blade views** | **4** |

---

## Next sub-phase

**Phase 7.2 — Purchase Receive (GRN).** The GRN is the economic event: Dr Inventory / Cr AP + supplier_ledger credit + PO received_qty update. Calls `StockService::applyTransaction()` with reference_type='purchase_receive' (stock IN at purchase rate, avg_cost recalculated).
