# P3-2: Journal Replay Verification Checklist

> **Command:** `php artisan journal:replay-verify`
> **Status:** Code enhanced — ready to run in PHP/PostgreSQL environment
> **Prerequisite:** `php artisan stock:replay-verify` (P3-1) should pass first

---

## Running the Verification

```bash
# Full verification (all 8 core checks + 5 sales-specific checks)
php artisan journal:replay-verify

# With orphan fix
php artisan journal:replay-verify --fix-orphans
```

## Core GL Checks (Phase 9.2 — existing)

| # | Check | Pass Criteria |
|---|-------|---------------|
| 1 | Chart of Accounts validation | All 7 critical natures resolve to exactly 1 active ledger |
| 2 | Per-entry balance (Dr=Cr) | 0 unbalanced entries |
| 3 | Total Dr=Cr | Total debits = total credits (diff < 0.01) |
| 4 | Orphan journal lines | 0 orphan lines (lines without parent entry) |
| 5 | Inactive ledger references | 0 journal lines referencing inactive ledgers |
| 6 | AR reconciliation | Sub-ledger total = GL AR control (drift < 0.02) |
| 7 | AP reconciliation | Sub-ledger total = GL AP control (drift < 0.02) |
| 8 | Entry counts by reference type | Informational (no pass/fail) |

## Sales-Specific GL Checks (P3-2 — NEW)

| # | Check | What It Verifies | Issue Indicators |
|---|-------|------------------|------------------|
| 9 | Sales invoice GL | Every non-draft invoice has journal_entry_id + JE not reversed | Missing JE = invoice created without GL; Stale JE = JE reversed but invoice still active |
| 10 | Challan COGS GL | Every active challan has journal_entry_id + COGS JE matches issue_cost | Missing JE = challan without COGS; COGS mismatch = JE amount ≠ issue_cost |
| 11 | Sales return GL | Every confirmed return has both journal_entry_id + cogs_journal_entry_id; COGS JE matches cogs_amount | Missing JE = return without GL; COGS mismatch = JE amount ≠ cogs_amount (original_cost) |
| 12 | Customer payment GL | Every active payment has journal_entry_id | Missing JE = payment without GL |
| 13 | Transport adjustment GL | Adjustment JEs on active challans are not reversed; transport_adjustment ≠ 0 when adjustment JE exists | Stale adjustment = JE reversed but challan active; Zero adjustment = JE exists but amount=0 |

## Acceptance Criteria

| Check | Pass Criteria | Action on Fail |
|-------|---------------|----------------|
| Core GL (1-8) | All pass (exit 0) | Fix root cause; re-run |
| Sales-specific (9-13) | 0 issues | Investigate — may be pre-migration data; informational only |

## Post-Run Investigation

### If invoices are missing GL JEs:
```sql
SELECT id, invoice_code, status FROM sales_invoices
WHERE status NOT IN ('draft', 'cancelled')
  AND is_reversed = false
  AND journal_entry_id IS NULL;
```

### If challan COGS mismatches:
```sql
SELECT sc.id, sc.challan_code, sc.issue_cost, jl.debit AS cogs_je_amount,
       ABS(sc.issue_cost - jl.debit) AS diff
FROM sales_challans sc
JOIN journal_entries je ON je.id = sc.journal_entry_id
JOIN journal_lines jl ON jl.journal_entry_id = je.id
JOIN ledgers l ON l.id = jl.ledger_id
WHERE sc.is_reversed = false AND je.is_reversed = false
  AND l.ledger_nature = 'cogs'
  AND ABS(sc.issue_cost - jl.debit) > 0.01;
```

### If return COGS mismatches:
```sql
SELECT sr.id, sr.return_code, sr.cogs_amount, jl.credit AS cogs_je_credit,
       ABS(sr.cogs_amount - jl.credit) AS diff
FROM sales_returns sr
JOIN journal_entries je ON je.id = sr.cogs_journal_entry_id
JOIN journal_lines jl ON jl.journal_entry_id = je.id
JOIN ledgers l ON l.id = jl.ledger_id
WHERE sr.status = 'confirmed' AND sr.is_reversed = false
  AND je.is_reversed = false AND l.ledger_nature = 'cogs'
  AND ABS(sr.cogs_amount - jl.credit) > 0.01;
```

## Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Lead Developer | _______________ | _______________ | _______________ |
| Accountant | _______________ | _______________ | _______________ |

---

*This checklist is part of Phase 3 (Verification & QA). Run after P3-1 (stock replay) passes.*
