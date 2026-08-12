# Stock Ledger

> **Module:** Inventory (Phase 8)
> **Audience:** Engineers + AI assistants + **accountants** (must review before Canonical)
> **Status:** Draft — pending accountant sign-off
> **Last reviewed:** 2025-08-03
> **Source of truth:** this file + `laravel/app/Services/Stock/StockService.php` (the SOLE writer — no module writes directly to `stock_transactions`) + `laravel/database/sql/03_stock.sql:19-58` (`stock_transactions` DDL) + `laravel/app/Models/StockTransaction.php`

## 1. What is it?

The **stock ledger** (`stock_transactions` table) is the immutable, append-only record of every
stock movement in the ERP. It is the single source of truth (SSOT) for stock quantities and
costs — the `warehouse_stock` table is a **derived snapshot** that can always be rebuilt from
the ledger.

The ledger is **quantity-based** (not double-entry): each row records a signed `qty` (positive =
stock IN, negative = stock OUT) plus a `rate` (per-unit cost snapshot). The Dr/Cr equivalent for
the GL is handled separately by each module's GL-posting method (see `../accounting/journal-posting-rules.md`).

## 2. Why does it exist?

- An immutable ledger preserves the full history of every stock movement, with the exact rate at
  the time of the movement. This is the audit trail required for inventory valuation, cost-of-goods
  reconciliation, and forensic investigation.
- The ledger is **partitioned by RANGE(transaction_date)** (monthly partitions) — supports
  high-volume transaction throughput and time-bounded queries without indexing the entire history.
- The ledger is the SSOT for `warehouse_stock` — if the snapshot ever drifts, the
  `StockAdjustmentReconcileService::rebuildSnapshot()` command can rebuild it from the ledger.

## 3. When is it used?

- **On every stock movement** — purchase receive, purchase return, sales challan, sales return,
  stock adjustment, stock take, damage, warehouse transfer, branch demand, opening balance.
  All routed through `StockService::applyTransaction()`.
- **On every reversal** — `StockService::reverseTransaction()` inserts a new row with
  `reference_type='reversal'` and opposite-sign qty.
- **At reconciliation** — `ReconciliationService::reconcileCOGS()` compares the GL `cogs` ledger
  against `stock_transactions` (reference_type=sales_challan MINUS sales_return).
- **At replay-verify** — `php artisan stock:replay-verify` replays every row through the avg-cost
  logic and compares against `warehouse_stock`.
- **At drift detection** — `StockAdjustmentReconcileService::computeDrift()` compares
  `warehouse_stock.qty` against `SUM(stock_transactions.qty) FILTER (WHERE NOT is_reversed)`.

## 4. Who uses it?

- **The system** writes to the ledger via `StockService::applyTransaction()`. No user-facing UI
  writes directly.
- **Accountants** read the ledger via the `admin/stock-transactions` index (read-only listing +
  drift viewer).
- **Engineers / AI assistants** MUST understand the ledger schema before modifying any
  stock-posting code.

## 5. Related modules

- `stock-costing.md` — the moving-average formula that uses the ledger's `rate` snapshots.
- `warehouse-stock.md` — the derived snapshot that the ledger feeds.
- `stock-verification.md` — the drift-detection + replay-verify mechanisms that compare the
  ledger against the snapshot.
- `../accounting/subledger-reconciliation.md` §5 (Inventory) + §6 (COGS) — the reconciliation
  formulas.
- `../accounting/reversal-vs-cancellation.md` — the reversal principle (the ledger uses the same
  append-only + `is_reversed` pattern as `journal_entries`).
- `../accounting/financial-audit-log.md` §12 — scope gap (stock_transactions NOT audited by
  `fn_financial_audit_trigger`).

## 6. Business rules (the Core Rule)

- **MUST** route every stock movement through `StockService::applyTransaction()`. NO module writes
  directly to `stock_transactions`.
- **MUST** set `reference_type` + `reference_id` on every row, linking back to the originating
  transaction (e.g. `('stock_adjustment', 42)`).
- **MUST** snapshot the `rate` (per-unit cost) at transaction time. The snapshot is **immutable** —
  never updated after insert.
- **MUST** use signed `qty`: positive = stock IN, negative = stock OUT. NO separate `in_qty` /
  `out_qty` columns.
- **MUST** reverse (not edit) by calling `StockService::reverseTransaction()`, which inserts a NEW
  row with opposite-sign qty + `reference_type='reversal'` and marks the original `is_reversed=true`.
- **MUST NOT** delete a posted ledger row. The only mutation allowed is the reversal-flag columns
  (`is_reversed`, `reversal_of_transaction_id`, `reversed_at`, `reversed_by`, `reverse_reason`).
- **MUST** use `(reference_type, reference_id)` as the GL linkage — `stock_transactions` has NO
  `journal_entry_id` column. Both the ledger row and the journal entry carry the same pair.
- **MUST NOT** rely on a `balance` column — running balance is always computed at query time via
  `SUM(qty) FILTER (WHERE NOT is_reversed)`. (No `balance` column exists; this is intentional to
  avoid denormalized drift.)

## 7. Technical implementation

### 7.1 The `stock_transactions` table — `03_stock.sql:19-58`

```sql
CREATE TABLE stock_transactions (
    id integer GENERATED ALWAYS AS IDENTITY,
    transaction_date date NOT NULL,
    warehouse_id integer NOT NULL REFERENCES warehouses(id),
    product_id integer NOT NULL REFERENCES products(id),
    qty numeric(14,4) NOT NULL,              -- signed: negative = OUT
    rate numeric(12,2) NOT NULL DEFAULT 0,
    total_value numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
    reference_type varchar(30) NOT NULL CHECK (reference_type IN (
        'purchase_receive','purchase_return','sales_challan','sales_return',
        'stock_adjustment','stock_take','warehouse_transfer','damage',
        'branch_demand','opening_balance','reversal'
    )),
    reference_id integer NOT NULL,
    branch_demand_item_id integer,
    notes text,
    is_reversed boolean DEFAULT false,
    reversal_of_transaction_id integer,      -- FK enforced by trigger (partitioned table)
    reversed_at timestamp(0),
    reversed_by integer,
    reverse_reason text,
    created_by integer,
    created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, transaction_date)       -- composite PK for partition routing
) PARTITION BY RANGE (transaction_date);
```

- **NO `branch_id` column** — branch scoping is via `warehouse_id → warehouses.branch_id`.
- **NO RLS** — warehouse-scoped, not branch-scoped. App layer enforces branch scoping.
- **NO `balance` column** — running balance derived from `SUM(qty) FILTER (WHERE NOT is_reversed)`.
- **NO `in_qty`/`out_qty` columns** — signed qty convention (positive=IN, negative=OUT).
- **`total_value` is GENERATED** `qty * rate` (signed: IN is positive value, OUT is negative).
- **`reversal_of_transaction_id` FK** is enforced by trigger `trg_st_reversal_fk` because PG 12-17
  doesn't support FK references TO partitioned tables (`03_stock.sql:13-17`).
- **Partitioned by RANGE(transaction_date)** — monthly partitions + default partition.

### 7.2 The `reference_type` matrix — DB CHECK (11 values) vs Model (14 values)

| reference_type | DB CHECK | Model `REFERENCE_TYPES` | Used by |
|---|---|---|---|
| `purchase_receive` | ✅ | ✅ | PurchaseReceiveService |
| `purchase_return` | ✅ | ✅ | PurchaseReturnService |
| `sales_challan` | ✅ | ✅ | SalesChallanService |
| `sales_return` | ✅ | ✅ | SalesReturnService |
| `stock_adjustment` | ✅ | ✅ | StockAdjustmentService |
| `stock_take` | ✅ | ✅ | StockTakeService |
| `warehouse_transfer` | ✅ | ✅ | WarehouseTransferService |
| `damage` | ✅ | ✅ | DamageService |
| `branch_demand` | ✅ | ✅ | BranchDemandService (generic) |
| `opening_balance` | ✅ | ✅ | StockAdjustmentService (opening_balance category) |
| `reversal` | ✅ | ✅ | StockService::reverseTransaction |
| `demand_send` | ❌ | ✅ | BranchDemandService (per-leg) |
| `demand_receive` | ❌ | ✅ | BranchDemandService (per-leg) |
| `demand_reversal` | ❌ | ✅ | BranchDemandService (reversal) |

> ⚠️ **GAP:** The DB CHECK constraint on `reference_type` allows only 11 values. The model
> `StockTransaction::REFERENCE_TYPES` lists 14 values — the 3 `demand_*` values are NOT in the DB
> CHECK. `BranchDemandService.php:274-299` writes `reference_type='demand_send'` and
> `'demand_receive'`. If the migration that adds these to the CHECK was not applied, these writes
> would FAIL at the DB level. The canonical `03_stock.sql` is stale — confirm the actual
> production constraint state.

### 7.3 Dr/Cr equivalent — quantity-based, signed qty

The stock ledger is **NOT** double-entry. It is a quantity-based ledger:

- `qty > 0` = stock IN (receipt, gain, return-in)
- `qty < 0` = stock OUT (issue, loss, return-out)
- `rate` = per-base-unit cost snapshot at transaction time
- `total_value` = GENERATED `qty * rate` (signed: IN is positive value, OUT is negative value)
- `is_reversed` + `reversal_of_transaction_id` = the reversal chain (mirror of `journal_entries`
  pattern)
- Originals NEVER mutated except the reversal-flag columns.

### 7.4 GL linkage — implicit via (reference_type, reference_id)

`stock_transactions` does **NOT** have a `journal_entry_id` column. Linkage is via
`(reference_type, reference_id)` — both the stock_transaction and the journal_entry carry the
same pair (e.g. both reference `('stock_adjustment', 42)`).

For per-line GL traceability (Stock Take only): `stock_take_items.journal_line_id` (FK to
`journal_lines.id`) and `stock_take_items.revaluation_line_id` — written at postSession, see
`StockTakeService.php:1898-1909`.

For Stock Adjustment: `stock_adjustment_items.stock_transaction_id` +
`stock_transaction_date` (composite FK into the partitioned ledger — `03_stock.sql:176-204`) —
Phase 6.2 G11 fix for exact-row reversal.

### 7.5 The canonical writer — `StockService::applyTransaction()` L58

Every stock movement in the entire codebase routes through this single method. NO module writes
directly to `stock_transactions`. The method:

1. Validates the input (warehouse_id, product_id, qty, rate, reference_type, reference_id).
2. Inserts a `stock_transactions` row (the immutable ledger entry).
3. `SELECT ... FROM warehouse_stock FOR UPDATE` (locks the row).
4. Computes new qty + avg_cost per the moving-average formula (see `stock-costing.md`).
5. `UPDATE warehouse_stock SET qty, avg_cost` (applies the movement).
6. Returns the `stock_transactions` row (for the caller to persist any linkage columns).

The method does **NOT** open its own `DB::transaction` — the caller wraps the entire operation
(stock movement + GL posting + audit log) in one outer transaction.

### 7.6 The canonical reversal — `StockService::reverseTransaction()` L194

```php
public function reverseTransaction(int $transactionId, int $reversedBy, string $reason, ?string $reversalDate = null): StockTransaction
{
    return DB::transaction(function () use ($transactionId, $reversedBy, $reason, $reversalDate) {
        $original = StockTransaction::lockForUpdate()->findOrFail($transactionId);
        if ($original->is_reversed) {
            throw new \RuntimeException('Transaction is already reversed.');
        }
        // Insert the reversal row (opposite-sign qty, same rate, reference_type='reversal').
        $reversal = StockTransaction::create([
            'transaction_date' => $reversalDate ?? $original->transaction_date,
            'warehouse_id' => $original->warehouse_id,
            'product_id' => $original->product_id,
            'qty' => -$original->qty,             // opposite sign
            'rate' => $original->rate,            // same rate (preserves cost integrity)
            'reference_type' => 'reversal',
            'reference_id' => $original->id,
            'reversal_of_transaction_id' => $original->id,
            'notes' => 'Reversal of #' . $original->id . ': ' . $reason,
            'created_by' => $reversedBy,
        ]);
        // Apply the reversal to warehouse_stock (re-computes avg_cost back to pre-original state).
        $this->applyWarehouseStockUpdate($reversal);
        // Mark the original as reversed.
        $original->update([
            'is_reversed' => true,
            'reversal_of_transaction_id' => $reversal->id,  // wait — see §12 #1 naming gotcha
            'reversed_at' => now(),
            'reversed_by' => $reversedBy,
            'reverse_reason' => $reason,
        ]);
        return $reversal;
    });
}
```

> ⚠️ **Naming gotcha** (carried from Phase 6 `reversal-vs-cancellation.md`): the
> `reversal_of_transaction_id` column lives on the **ORIGINAL** row and points to the **NEW**
> reversal row (not the other way around). This mirrors the `journal_entries.reversal_of_entry_id`
> convention. The new reversal row's `reversal_of_transaction_id` is null (it is not itself
> reversed). Query "show me the reversal of transaction X" →
> `SELECT * FROM stock_transactions WHERE id = (SELECT reversal_of_transaction_id FROM stock_transactions WHERE id = X)`.

## 8. Intercompany / cross-branch

The stock ledger itself is warehouse-scoped (no `branch_id`). Cross-branch stock movements go
through the Branch Demand module (Phase 13), which posts:

- A `stock_transactions` row at the source warehouse (qty negative, reference_type=`branch_demand`
  or `demand_send`).
- A `stock_transactions` row at the dest warehouse (qty positive, reference_type=`branch_demand`
  or `demand_receive`).
- An intercompany GL entry (Dr `interbranch_receivable` at dest, Cr `interbranch_payable` at
  source) — see `../accounting/journal-posting-rules.md` §5 #25-26.

The WarehouseTransfer module is **same-branch only** — its `postIntercompanyGL` method is dead
code (see `warehouse-transfer.md` §8).

## 9. Workflow / state machine

The stock ledger has no state machine of its own — each row is either `is_reversed=false` (live)
or `is_reversed=true` (reversed). The state machines are on the parent transactions (Stock
Adjustment, Stock Take, Damage, Warehouse Transfer — see those files).

## 10. Validation & input rules

- `qty` is `numeric(14,4)` — fractional quantities allowed.
- `rate` is `numeric(12,2)` — per-base-unit cost.
- `reference_type` is `varchar(30)` with DB CHECK (11 values — see §7.2 GAP for the 3 missing
  `demand_*` values).
- `reference_id` is `integer NOT NULL`.
- `transaction_date` is `date NOT NULL` — required for partition routing.
- `warehouse_id` + `product_id` are FK-constrained.

## 11. Reversal & correction flow

See §7.6 for the canonical `reverseTransaction` method. The reversal cascade for a parent
transaction (e.g. Stock Adjustment cancel) is:

1. The parent service (e.g. `StockAdjustmentService::cancelAdjustment`) opens a `DB::transaction`.
2. For each `stock_adjustment_items` row, look up the exact `stock_transaction_id` (composite FK
   for partitioned ledger — Phase 6.2 G11 fix).
3. Call `StockService::reverseTransaction(stockTransactionId, userId, reason, reversalDate)`.
4. The reversal row is inserted with opposite-sign qty + `reference_type='reversal'`.
5. The original is marked `is_reversed=true`.
6. The parent GL journal entry is reversed via `JournalPostingService::reverseJournalEntry`.
7. The parent (e.g. `stock_adjustments`) is marked `is_reversed=true` + `status='cancelled'`.

The `reversalDate` parameter (Phase 6.3 G10 fix) allows back-dated reversals — the reversal row
is dated as the original transaction's date, not today, so the ledger history lines up.

## 12. Open questions / known gaps

1. **DB CHECK on `reference_type` is stale** (§7.2) — 11 values in DB, 14 in model. The 3
   `demand_*` values would FAIL at the DB level if the migration adding them to the CHECK was not
   applied. The canonical `03_stock.sql` is stale. **Recommended:** confirm the actual production
   constraint state and update `03_stock.sql` to match.
2. **No `journal_entry_id` on `stock_transactions`** (§7.4) — GL linkage is implicit via
   `(reference_type, reference_id)`. A future auditor query must JOIN on both columns. Compare to
   `customer_ledger` / `supplier_ledger` / `employee_ledger` which all have explicit
   `journal_entry_id` FKs. **Recommended:** add a `journal_entry_id` column for direct GL
   traceability (would require a backfill migration).
3. **No `balance` column** (§7.6) — running balance is always computed at query time. This is
   intentional (denormalized balance would drift), but it means queries like "show the running
   balance over time for product X in warehouse Y" require a window function:
   `SUM(qty) OVER (ORDER BY transaction_date, id) FILTER (WHERE NOT is_reversed)`.
4. **No `financial_audit_log` trigger** — `stock_transactions` is NOT in the 10-table audit
   trigger scope (see `../accounting/financial-audit-log.md` §12). The ledger itself IS the audit
   trail (append-only + `is_reversed`), but it is not in the SHA-256 hash chain. **Recommended:**
   consider adding `stock_transactions` to the hash chain for forensic integrity.
5. **No RLS** — warehouse-scoped, no `branch_id` column. App layer enforces branch scoping. A
   non-admin user querying `stock_transactions` directly via DB would see ALL branches'
   transactions. Defense-in-depth gap.
6. **Reversal-of-reversal not blocked** — a reversal row has `is_reversed=false`, so technically
   it could be reversed again. The app layer does not block this. Carried from Phase 6
   `reversal-vs-cancellation.md` §12.
7. **`reversal_of_transaction_id` naming gotcha** (§7.6) — lives on the ORIGINAL pointing to the
   NEW reversal row. Confusing for new engineers. Documented but not fixed.

## 13. Accountant review checklist

> **This is a SAFETY-CRITICAL document.** Before marking it Canonical, an accountant with
> production credentials MUST review and sign off on each item below.

- [ ] The signed-qty convention (positive=IN, negative=OUT) matches the actual movement
      direction for each reference_type.
- [ ] The `reference_type` matrix (§7.2) — including the 3 `demand_*` values — matches the actual
      production constraint state. Confirm the DB CHECK migration was applied.
- [ ] The implicit GL linkage via `(reference_type, reference_id)` (§7.4) is sufficient for audit,
      OR should a `journal_entry_id` column be added (§12 #2)?
- [ ] The lack of a `balance` column (§12 #3) — confirm running-balance queries are acceptable via
      window functions.
- [ ] The lack of `financial_audit_log` trigger (§12 #4) — should `stock_transactions` join the
      SHA-256 hash chain?
- [ ] The lack of RLS (§12 #5) — is the app-layer branch scoping sufficient, or should RLS be
      added (would require a `branch_id` column)?
- [ ] The reversal cascade (§11) correctly undoes the stock movement + GL + parent status in the
      right order.
- [ ] The back-dated reversal date (§11, Phase 6.3 G10 fix) — confirm the reversal row should be
      dated as the original transaction's date, not today.
