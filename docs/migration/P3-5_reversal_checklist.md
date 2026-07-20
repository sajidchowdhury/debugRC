# P3-5: Reversal Verification Checklist

> **Command:** `php artisan reversal:verify`
> **Status:** Code enhanced — ready to run in PHP/PostgreSQL environment
> **Prerequisite:** P3-1 (stock replay) + P3-2 (journal replay) + P3-3 (reconciliation)

---

## Running the Verification

```bash
php artisan reversal:verify
```

## Core Reversal Checks (Phase 9.4 — existing)

| # | Check | Pass Criteria |
|---|-------|---------------|
| 1 | Reversal summary | Informational (count + amount) |
| 2 | Reversals by reference type | Informational |
| 3 | Unbalanced reversals | 0 (original + reversal nets to zero) |
| 4 | Orphan reversals | 0 (reversals without an original) |
| 5 | Reversed entries without reversal entry | 0 |
| 6 | Sub-ledger reversal consistency | 0 (GL reversed but sub-ledger not) |

## Sales-Specific Reversal Checks (P3-5 — NEW)

| # | Check | What It Verifies |
|---|-------|------------------|
| 7 | Invoice reversal consistency | Cancelled invoices → GL JE reversed + customer_ledger reversed |
| 8 | Challan reversal consistency | Cancelled challans → COGS JE reversed + stock_transactions reversed |
| 9 | Return reversal consistency | Reversed returns → revenue JE + COGS JE + stock_transactions all reversed |
| 10 | Payment reversal consistency | Cancelled payments → GL JE reversed |
| 11 | Stock transaction reversal consistency | Reversed business records → stock_transactions reversed (challan + return + damage) |
| 12 | Append-only integrity | Reversed entries retain original lines (not mutated/deleted) |

## Acceptance Criteria

All checks must show 0 issues for sign-off.

## Sign-Off

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Lead Developer | _______________ | _______________ | _______________ |
| Accountant | _______________ | _______________ | _______________ |
