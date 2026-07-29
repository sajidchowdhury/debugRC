# Warehouse Transfer — Shadow Mode Integration

**Date:** 2025-07-28  
**Scope:** Warehouse-to-Warehouse Transfer (inner-branch only)  
**Module:** WarehouseTransfer  
**Target stack:** Laravel 11 + PostgreSQL 16  

---

## 1. Overview

Shadow Mode is the final validation stage before cutover from the legacy PHP/MySQL system to the Laravel/PostgreSQL system for the Warehouse Transfer module. Both systems run against the same PostgreSQL database, every transfer operation is executed by both systems, and results are compared. Zero diffs for 7 consecutive days trigger cutover.

---

## 2. Architecture

```
┌───────────────────┐      ┌───────────────────┐
│  Legacy PHP/MySQL  │      │  Laravel 11/PG16   │
│  (shadow writer)   │      │  (primary writer)  │
│                    │      │                    │
│  WarehouseTransfer │      │  WarehouseTransfer │
│  Controller        │      │  Controller        │
│  → WarehouseTransfer│     │  → WarehouseTransfer│
│    Model            │     │    Service          │
│  → StockTransaction│     │  → StockService     │
│    Model            │     │  → AuditLogger      │
│  → JournalPosting  │      │  → JournalPosting   │
│    Service          │     │    Service          │
└──────────┬─────────┘      └──────────┬─────────┘
           │                            │
           │    ┌───────────────────┐   │
           │    │  PostgreSQL 16     │   │
           └───→│  (single DB)       │←──┘
                │                    │
                │  warehouse_transfers│
                │  stock_transactions │
                │  warehouse_stock    │
                │  journal_entries    │
                │  user_audit_log     │
                └────────────────────┘
```

---

## 3. Shadow Mode Phases

### Phase A: Dual-Write Setup (Week 1)

1. **Legacy system** continues to be the **primary writer** — all real operations go through the legacy system.
2. **Laravel system** acts as the **shadow writer** — every operation is also executed by the Laravel WarehouseTransferService.
3. Results are written to the same PostgreSQL tables but with distinguishing markers:
   - `reference_type` = `warehouse_transfer` for both systems
   - The `transfer_code` format differs: legacy uses `WT-YYYYMMDD-NNNN`, Laravel uses `WT-YYYYMMDD-NNNN` (same format via DocumentSequenceService)
4. A **comparison cron job** runs every 15 minutes to check for diffs.

### Phase B: Laravel Primary (Week 2)

1. **Laravel system** becomes the **primary writer** — all real operations go through Laravel.
2. **Legacy system** is switched to read-only mode for warehouse transfers (no INSERT/UPDATE on warehouse_transfers or stock_transactions).
3. Comparison cron continues to run but now compares legacy reads vs Laravel writes.

### Phase C: Cutover (After 7 days of zero diffs)

1. **Legacy system** is fully decommissioned for the Warehouse Transfer module.
2. All warehouse transfer routes point exclusively to Laravel.
3. The legacy WarehouseTransferController, WarehouseTransferModel, and WarehouseTransferAuditModel are archived.

---

## 4. Comparison Criteria

The shadow comparison checks the following invariants for each transfer:

| # | Check | Expected | Failure Action |
|---|-------|----------|----------------|
| 1 | `warehouse_transfers` row exists in both systems | Same transfer_code, same status, same from/to warehouses, same is_reversed flag | Log diff, alert ops team |
| 2 | `warehouse_transfer_items` rows match | Same product_id, same qty, same rate for each line | Log diff, alert ops team |
| 3 | `stock_transactions` rows match | Same warehouse_id, same product_id, same qty (±0.01 tolerance), same rate | Log diff, alert ops team |
| 4 | `warehouse_stock` balances match | Same qty, same avg_cost (±0.01 tolerance) | Log diff, alert ops team |
| 5 | `journal_entries` match (if interbranch) | Same ledger_id, same debit/credit amounts | Log diff, alert ops team |
| 6 | Same-branch enforcement | No cross-branch transfers exist in Laravel system | CRITICAL — stop shadow mode, investigate |
| 7 | Reversal ordering | For cancelled confirmed transfers, dest IN reversed before source OUT | Log diff, alert ops team |

---

## 5. Comparison Cron Job

### Artisan Command

```bash
php artisan warehouse-transfer:shadow-compare
```

### Implementation

```php
// app/Console/Commands/WarehouseTransferShadowCompare.php

class WarehouseTransferShadowCompare extends Command
{
    protected $signature = 'warehouse-transfer:shadow-compare 
        {--date-from= : Start date for comparison} 
        {--date-to= : End date for comparison}
        {--branch= : Branch ID to scope comparison}';

    protected $description = 'Compare legacy vs Laravel warehouse transfer results for shadow mode validation';

    public function handle(): int
    {
        // 1. Get all transfers in the date range
        // 2. For each transfer, compare:
        //    - Header fields (status, from/to, is_reversed)
        //    - Item rows (product_id, qty, rate)
        //    - Stock transactions (warehouse_id, product_id, qty, rate)
        //    - Warehouse stock balances (qty, avg_cost)
        // 3. Write results to shadow_comparison_log table
        // 4. Return 0 if zero diffs, 1 if diffs found
    }
}
```

---

## 6. Shadow Comparison Log Table

```sql
CREATE TABLE warehouse_transfer_shadow_comparison (
    id                   integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    comparison_date      date NOT NULL DEFAULT CURRENT_DATE,
    transfer_id          integer NOT NULL REFERENCES warehouse_transfers(id),
    transfer_code        varchar(30) NOT NULL,
    check_type           varchar(50) NOT NULL,
    -- 'header', 'items', 'stock_tx', 'warehouse_stock', 'gl'
    legacy_value         text,
    laravel_value        text,
    diff_description     text,
    is_match             boolean NOT NULL DEFAULT true,
    created_at           timestamp NOT NULL DEFAULT NOW()
);
```

---

## 7. Cutover Checklist

Before cutover from shadow mode to production:

- [ ] Shadow comparison has produced **zero diffs for 7 consecutive days**
- [ ] All Phase 7 tests pass (9 test files, ≥85% coverage)
- [ ] Same-branch enforcement verified (no cross-branch transfers in Laravel)
- [ ] Stock availability check verified (pipeline-aware availability correct)
- [ ] Reversal ordering verified (dest IN reversed before source OUT)
- [ ] Audit trail verified (all events logged correctly)
- [ ] CSV export verified (matches legacy output)
- [ ] Summary report verified (correct aggregates)
- [ ] Health checks pass (runHealthChecks() returns zero failures)
- [ ] Stock reconciliation pass (reconcileStock() returns zero mismatches)
- [ ] Branch isolation verified (non-admin only sees own branch)
- [ ] Performance benchmark: Laravel transfer creation < 500ms
- [ ] Performance benchmark: Laravel transfer confirmation < 2s (including stock movements)
- [ ] Operations team sign-off received

---

## 8. Rollback Plan

If critical diffs are found during shadow mode:

1. **Immediate:** Stop Laravel shadow writing, revert to legacy-only
2. **Investigate:** Identify the root cause (service logic, SQL, schema mapping)
3. **Fix:** Apply the fix to Laravel service, re-run tests
4. **Re-enter shadow mode:** Start from Phase A again
5. **No data loss:** All PostgreSQL data is preserved (append-only design)

---

## 9. Post-Cutover Monitoring

After cutover, continue monitoring for 30 days:

- Daily health checks via WarehouseTransferAuditService::runHealthChecks()
- Daily stock reconciliation via WarehouseTransferAuditService::reconcileStock()
- Weekly summary report review
- Alert on any new cross-branch transfer attempts (should be impossible)
