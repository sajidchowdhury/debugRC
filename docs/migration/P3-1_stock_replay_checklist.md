# P3-1: Stock Replay Verification Checklist

> **Command:** `php artisan stock:replay-verify`
> **Status:** Code enhanced — ready to run in PHP/PostgreSQL environment
> **Prerequisite:** Database must have production data loaded (via pgloader + post_load_fixes.sql + sync_sequences.sql)

---

## Pre-Run Checklist

- [ ] PostgreSQL database is running with production data loaded
- [ ] `php artisan migrate` has been run (all migrations through 2025_01_09_000005 applied)
- [ ] `post_load_fixes.sql` has been run (status conversions, branch_id backfill, etc.)
- [ ] `sync_sequences.sql` has been run (IDENTITY sequences set to MAX(id))
- [ ] Redis is running (for cache, if configured)
- [ ] No concurrent users writing to the database during replay

## Running the Verification

```bash
# Full replay (all transactions)
php artisan stock:replay-verify

# Replay a single product (for investigation)
php artisan stock:replay-verify --product=42

# Limit to first 1000 transactions (quick smoke test)
php artisan stock:replay-verify --limit=1000

# Resume from a specific transaction ID (after fixing an issue)
php artisan stock:replay-verify --from-id=5000

# Keep previous drift rows (for comparison)
php artisan stock:replay-verify --keep-drift
```

## What the Command Does

### Phase 1: Main Replay (Phase 6.2)
1. Clears previous `avg_cost_drift` rows (status='open') + truncates `warehouse_stock_shadow`
2. Replays ALL non-reversed `stock_transactions` (sorted by created_at, id) through the avg-cost logic:
   - IN (qty > 0): `new_avg = (old_qty × old_avg + in_qty × in_rate) / new_qty`
   - OUT (qty < 0): avg_cost UNCHANGED, qty decremented
3. Writes replayed balances to `warehouse_stock_shadow` table
4. Compares shadow to live `warehouse_stock` — logs any drift > 0.0001 (qty) or > 0.01 (cost) to `avg_cost_drift` table
5. Reports: total transactions, shadow balances, drift count, max drift

### Phase 2: Sales-Specific Data Integrity Checks (P3-1 — NEW)
After the main replay passes (zero drift), 3 additional checks run:

#### Check 1: Sales Return Original Cost Verification
- **What:** Verifies that `stock_transactions` with `reference_type='sales_return'` have a `rate` that matches the `original_cost` on the corresponding `sales_return_items` row
- **Why:** This is the CRITICAL correctness check from the audit — legacy used current avg_cost (a COGS-integrity bug); Laravel should use the snapshotted `original_cost`
- **Tolerance:** 0.01
- **Mismatches indicate:** Either the return was created before the P0-3 migration (original_cost not populated) or there's a bug in the original_cost lookup logic

#### Check 2: Challan Issue Rate Verification
- **What:** Verifies that `stock_transactions` with `reference_type='sales_challan'` have a `rate` matching the `issue_rate` on `sales_challan_items`
- **Why:** The `sales_challan_items` table (restored in P0-5) snapshots the per-line issue cost; the stock_transaction rate should match
- **Tolerance:** 0.01
- **Mismatches indicate:** The challan was created before P0-5 (table was missing) or the issue_rate wasn't populated correctly

#### Check 3: Linked Damage Transaction Verification
- **What:** Two sub-checks:
  1. Each `damage_invoices` row with `sales_return_id` (linked to a return) should have at least one `stock_transactions` row with `reference_type='damage'`
  2. The damage transaction rate should match the return item's `original_cost` (since P1-5 uses `original_cost` as the damage rate)
- **Why:** Verifies the P1-5 linked damage write-off feature created consistent stock movements
- **Orphan damages indicate:** The damage_invoice was created but the stock OUT wasn't applied (possibly created before P1-5 was implemented)
- **Rate mismatches indicate:** The damage was recorded at a different rate than the return's original_cost

## Acceptance Criteria

| Check | Pass Criteria | Action on Fail |
|-------|---------------|----------------|
| Main replay (Phase 6.2) | Zero drift (exit code 0) | Investigate `avg_cost_drift` table; fix root cause; re-run |
| Insufficient-stock errors | 0 errors | Investigate each — legacy may have allowed transient negatives |
| Sales return original_cost | 0 mismatches | Backfill `original_cost` from `stock_transactions` rate (ETL fix 14) |
| Challan issue rates | 0 mismatches | Backfill `sales_challan_items` from `stock_transactions` rate |
| Linked damage orphans | 0 orphans | Investigate — may need to create missing stock_transactions |
| Linked damage rates | 0 mismatches | Investigate — may need to correct damage transaction rates |

## Post-Run Investigation

### If drift is detected:
```sql
-- View all open drift rows (sorted by severity)
SELECT * FROM avg_cost_drift
WHERE status = 'open'
ORDER BY qty_drift DESC, cost_drift DESC;

-- View shadow vs live for a specific product
SELECT ws.warehouse_id, ws.product_id,
       ws.qty AS live_qty, wss.qty AS shadow_qty,
       ws.avg_cost AS live_cost, wss.avg_cost AS shadow_cost
FROM warehouse_stock ws
LEFT JOIN warehouse_stock_shadow wss
  ON wss.warehouse_id = ws.warehouse_id
  AND wss.product_id = ws.product_id
WHERE ws.product_id = 42;
```

### If sales return rate mismatches are found:
```sql
-- View the mismatches
SELECT st.id, st.reference_id, st.product_id, st.warehouse_id,
       st.rate AS transaction_rate, sri.original_cost,
       ABS(st.rate - sri.original_cost) AS diff
FROM stock_transactions st
JOIN sales_return_items sri
  ON sri.product_id = st.product_id
  AND sri.warehouse_id = st.warehouse_id
  AND sri.sales_return_id = st.reference_id
WHERE st.reference_type = 'sales_return'
  AND st.qty > 0
  AND st.is_reversed = false
  AND ABS(st.rate - COALESCE(sri.original_cost, 0)) > 0.01;
```

### If all checks pass:
```
✓ ZERO DRIFT — StockService avg-cost logic matches live warehouse_stock.
✓ All sales-specific data integrity checks passed.
  - Sales return rates match original_cost snapshots
  - Challan issue rates match sales_challan_items
  - Linked damage transactions are consistent
Phase 6.2 + P3-1 replay verification complete.
```

## Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Lead Developer | _______________ | _______________ | _______________ |
| Accountant | _______________ | _______________ | _______________ |

---

*This checklist is part of Phase 3 (Verification & QA). All prior phases (0-2) must be complete before running this verification.*
