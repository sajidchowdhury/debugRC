# P3-3: Reconciliation (6 Sections) Checklist

> **Command:** `php artisan subledger:reconcile`
> **Status:** Code enhanced — ready to run in PHP/PostgreSQL environment
> **Prerequisite:** P3-1 (stock replay) + P3-2 (journal replay) should pass first

---

## Running the Verification

```bash
php artisan subledger:reconcile
```

## 6 Reconciliation Sections

### Core Sub-Ledger Reconciliation (Phase 9.3 — existing)

| # | Section | What It Compares | Tolerance |
|---|---------|------------------|-----------|
| 1 | Accounts Receivable (AR) | `customer_ledger` total (debit-credit) vs GL AR control account | 0.02 |
| 2 | Accounts Payable (AP) | `supplier_ledger` total (credit-debit) vs GL AP control account | 0.02 |
| 3 | Employee Payable | `employee_ledger` total vs GL employee_payable control | 0.02 |
| 4 | Orphan Sub-Ledger Entries | Sub-ledger rows without `journal_entry_id` (pre-GL data) | 0 orphans |

### Sales-Specific Reconciliation (P3-3 — NEW)

| # | Section | What It Compares | Tolerance |
|---|---------|------------------|-----------|
| 5 | Cash/Bank | GL `cash_bank` ledger vs SUM(`banks.balance`) + SUM(`cash_ledger.balance`) | 0.02 |
| 6 | Inventory | GL `inventory` ledger vs SUM(`warehouse_stock.qty × avg_cost`) | 0.02 |
| 7 | COGS | GL `cogs` ledger vs SUM(`sales_challan_items.cogs_amount`) + damage_loss GL | 0.02 |

## Acceptance Criteria

| Check | Pass Criteria | Action on Fail |
|-------|---------------|----------------|
| AR (1) | Drift < 0.02 | Investigate orphan customer_ledger entries; check reversed JEs |
| AP (2) | Drift < 0.02 | Same as AR but for supplier_ledger |
| Employee (3) | Drift < 0.02 | Same pattern |
| Orphans (4) | 0 orphans | Pre-GL data — may need backfill of journal_entry_id |
| Cash/Bank (5) | Drift < 0.02 | Check bank_ledger_mappings; verify cash_ledger balance |
| Inventory (6) | Drift < 0.02 | Run `stock:replay-verify` (P3-1) to find stock drift |
| COGS (7) | Drift < 0.02 | Run `journal:replay-verify` (P3-2) to find GL issues; check sales_challan_items |

## Post-Run Investigation

### If Cash/Bank mismatches:
```sql
-- Check bank_ledger_mappings coverage
SELECT b.id, b.bank_name, b.balance, blm.ledger_id
FROM banks b
LEFT JOIN bank_ledger_mappings blm ON blm.bank_id = b.id
WHERE b.is_active = true AND b.deleted_at IS NULL;
```

### If Inventory mismatches:
```sql
-- Compare GL inventory vs physical stock
SELECT
  (SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
   FROM ledgers l JOIN journal_lines jl ON jl.ledger_id = l.id
   JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
   WHERE l.ledger_nature = 'inventory' AND l.is_active = true) AS gl_inventory,
  (SELECT COALESCE(SUM(qty * avg_cost), 0) FROM warehouse_stock WHERE qty > 0) AS physical_stock;
```

### If COGS mismatches:
```sql
-- Compare GL COGS vs challan items + damage
SELECT
  (SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
   FROM ledgers l JOIN journal_lines jl ON jl.ledger_id = l.id
   JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.is_reversed = false
   WHERE l.ledger_nature = 'cogs' AND l.is_active = true) AS gl_cogs,
  (SELECT COALESCE(SUM(sci.cogs_amount), 0)
   FROM sales_challan_items sci
   JOIN sales_challans sc ON sc.id = sci.sales_challan_id
   WHERE sc.is_reversed = false) AS challan_cogs;
```

## Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Lead Developer | _______________ | _______________ | _______________ |
| Accountant | _______________ | _______________ | _______________ |

---

*Run after P3-1 (stock replay) + P3-2 (journal replay) pass. All 6 sections must be green before period close.*
