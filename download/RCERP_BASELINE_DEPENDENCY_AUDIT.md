# RC_ERP Baseline Schema — Dependency Audit

**Target migration:** `2025_01_01_000001_create_rcerp_schema.php`
**Symptom reported:** `column "is_reversed" does not exist` on `customer_ledger` during `CREATE INDEX idx_cl_balance_covering`
**Secondary symptom reported:** `\d customer_ledger` returns "Did not find any relation"
**Goal:** Make `php artisan migrate:fresh` succeed from an empty PostgreSQL database.

---

## 1. Executive Summary — Root Cause

The baseline migration **does** create `customer_ledger` (in `02_accounting.sql` line 115). The table is then **rolled back** by PostgreSQL when a *later* statement inside the **same** migration fails.

That later failure is a single **forward-dependency violation** in `07_views_triggers_constraints.sql`:

```sql
-- 07_views_triggers_constraints.sql lines 197–199
CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
    ON customer_ledger (customer_id, is_reversed)
    INCLUDE (debit, credit);
```

`customer_ledger.is_reversed` does **not** exist in the baseline schema. It is added later by migration `2025_01_02_000002_add_is_reversed_to_sub_ledgers.php`, which runs *after* `2025_01_01_000001_create_rcerp_schema.php`.

Because Laravel wraps each migration in a single DB transaction, when this `CREATE INDEX` fails, PostgreSQL rolls back **every** `CREATE TABLE`, `CREATE INDEX`, `CREATE FUNCTION`, `CREATE POLICY`, etc. issued earlier in the same migration — including `customer_ledger`. That is why `\d customer_ledger` returns "Did not find any relation": the table was created and then atomically rolled back.

This is **not** a missing-table bug. It is a column-existence bug that manifests as a missing-table symptom because of transactional DDL.

---

## 2. Execution Order Trace

`2025_01_01_000001_create_rcerp_schema.php::up()` calls `executeSqlFile()` in this order:

| # | File | Lines | Tables created (selection) | Status |
|---|------|-------|----------------------------|--------|
| 1 | `01_auth_and_master.sql` | 253 | branches, employees, users, menus, product_categories, products, customers, suppliers, banks, warehouses | OK |
| 2 | `02_accounting.sql` | 272 | ledgers, journal_entries, journal_lines, journal_posting_logs, document_sequences, **customer_ledger (L115)**, supplier_ledger, employee_ledger, branch_ledger, branch_cash, branch_expenses, branch_product_cost, cash_ledger, accounting_periods, manual_journals, schema_migrations + 1 function `enforce_balanced_journal_entry` + 1 trigger | OK |
| 3 | `03_stock.sql` | 274 | stock_transactions (partitioned), warehouse_stock, stock_adjustments, stock_take_sessions, warehouse_transfers, damage_invoices, daily_warehouse_stock_summary, branch_demands + 1 function `prevent_negative_stock` + 2 triggers | OK |
| 4 | `04_sales.sql` | 226 | sales_invoices (partitioned, `is_reversed` IS in baseline L52), sales_invoice_items, sales_challans (`is_reversed` L138), sales_returns (`is_reversed` L184) | OK |
| 5 | `05_purchase.sql` | 152 | purchase_orders, purchase_receives (`is_reversed` L58), purchase_returns (`is_reversed` L100), invoice_payment_allocations | OK |
| 6 | `06_payment_and_misc.sql` | 231 | customer_payments (`is_reversed` L19), supplier_payments (`is_reversed` L62), money_transfers, other_incomes, other_expenses, employee_transactions, notifications, login_rate_limits, user_audit_log | OK |
| 7 | `07_views_triggers_constraints.sql` | 844 | 1 view, 2 functions, 1 DO block, 5 ALTER TABLE ADD CONSTRAINT, ~30 B-tree indexes, ~12 BRIN indexes, 1 GIN, 2 tsvector + 2 GIN FTS, ~31 tables × 5 RLS policies = 155 policies, 1 final helper function + 1 covering index | **FAILS at L197** |

The PHP splitter (`splitSql()` in the migration file) correctly handles every `$$ … $$` dollar-quoted block in these files (verified by manual trace of `enforce_balanced_journal_entry`, `prevent_negative_stock`, `update_updated_at_column`, the `DO $$ … $$;` block at L51–81, and `doc_seq_advisory_key` at L818–828). All custom dollar-quote tags are absent — every block uses the bare `$$` form, which the splitter recognizes.

No statement is silently dropped or merged. The failure is purely a column-existence issue, **not** a splitter bug.

---

## 3. The Single Forward-Dependency (the only blocker)

**Location:** `database/sql/07_views_triggers_constraints.sql` lines 197–199.

```sql
-- P0: Customer ledger balance (every invoice finalize + credit check)
-- Query: SELECT SUM(debit) - SUM(credit) FROM customer_ledger
--        WHERE customer_id = ? AND is_reversed = false
CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
    ON customer_ledger (customer_id, is_reversed)
    INCLUDE (debit, credit);
```

**Why it fails:**

| Object | Defined in | In baseline? |
|--------|-----------|--------------|
| `customer_ledger` (table) | `02_accounting.sql` L115–130 | YES |
| `customer_ledger.customer_id` | `02_accounting.sql` L117 | YES |
| `customer_ledger.debit` | `02_accounting.sql` L123 | YES |
| `customer_ledger.credit` | `02_accounting.sql` L124 | YES |
| **`customer_ledger.is_reversed`** | `2025_01_02_000002_add_is_reversed_to_sub_ledgers.php` L19–25 | **NO** |

The `is_reversed` column is added by an *incremental* migration (`2025_01_02_000002`) that runs *after* the baseline (`2025_01_01_000001`). The baseline has no way to satisfy this dependency at the time it executes.

**Evidence the author was aware of this category of bug:**

Lines 461–467 of the same file contain an explicit "REMOVED FROM THIS FILE" note explaining that materialized views which need `customer_ledger.is_reversed` were moved to `2025_01_20_000006_add_running_balance_reconciliation.php` for exactly this reason. The author relocated the MVs but **forgot to relocate this single index**.

---

## 4. Why the Symptom Is "Table Doesn't Exist"

1. Laravel's `migrate:fresh` wraps each migration file in a single DB transaction (`BEGIN … COMMIT`).
2. Inside that transaction, `02_accounting.sql` executes `CREATE TABLE customer_ledger (…)` successfully.
3. The transaction continues through `03_stock`, `04_sales`, `05_purchase`, `06_payment_and_misc`, and into `07_views_triggers_constraints`.
4. At L197, `CREATE INDEX … ON customer_ledger (customer_id, is_reversed)` is attempted.
5. PostgreSQL reports `column "is_reversed" does not exist` (SQLSTATE 42703) and aborts the transaction.
6. The transaction is rolled back. **Every** DDL statement issued since `BEGIN` is undone — including the `CREATE TABLE customer_ledger` from step 2.
7. After the failed migration, `\d customer_ledger` returns "Did not find any relation" because the table genuinely doesn't exist anymore — it was created and then erased atomically.

This is why the user's direct psql inspection showed both:
- `SELECT column_name FROM information_schema.columns WHERE table_name='customer_ledger'` → 0 rows
- `\d customer_ledger` → "Did not find any relation"

Both are downstream symptoms of the rollback, **not** evidence that the `CREATE TABLE` was never attempted.

---

## 5. Verification That This Is the ONLY Blocker in the Baseline

A full column-existence audit was performed against every `is_reversed` reference in `07_views_triggers_constraints.sql`:

| Line | Object | Column exists in baseline? |
|------|--------|---------------------------|
| 19 | `journal_entries.is_reversed` (view column) | YES — `02_accounting.sql` L36 |
| 137, 141 | `sales_invoices.is_reversed` (partial index WHERE) | YES — `04_sales.sql` L52 |
| 146, 154 | `customer_payments.is_reversed` (partial index WHERE) | YES — `06_payment_and_misc.sql` L19 |
| 150, 158 | `supplier_payments.is_reversed` (partial index WHERE) | YES — `06_payment_and_misc.sql` L62 |
| 163 | `sales_returns.is_reversed` (partial index WHERE) | YES — `04_sales.sql` L184 |
| 167 | `purchase_returns.is_reversed` (partial index WHERE) | YES — `05_purchase.sql` L100 |
| 184 | `journal_entries.is_reversed` (partial index WHERE) | YES |
| **198** | **`customer_ledger.is_reversed` (covering index key)** | **NO — added by `2025_01_02_000002`** |
| 205 | `sales_invoices.is_reversed` (covering index key) | YES |
| 211 | `journal_entries.is_reversed` (covering index key) | YES |
| 228 | `sales_invoices.is_reversed` (INCLUDE column) | YES |
| 233 | `customer_payments.is_reversed` (INCLUDE column) | YES |
| 238 | `supplier_payments.is_reversed` (INCLUDE column) | YES |
| 253 | `sales_challans.is_reversed` (INCLUDE column) | YES — `04_sales.sql` L138 |
| 258 | `purchase_receives.is_reversed` (INCLUDE column) | YES — `05_purchase.sql` L58 |

**Only line 198 is broken.** Every other `is_reversed` reference resolves to a column that already exists in the baseline schema.

Additional audits performed:

- **Duplicate `CREATE TABLE`**: none (verified via `grep + sort + uniq -d`).
- **Duplicate `CREATE INDEX` name**: none.
- **All `ALTER TABLE … ADD CONSTRAINT FOREIGN KEY`** (lines 90, 95, 99, 103, 107): every referenced parent table (`customers`, `suppliers`, `employees`, `branches`, `banks`) is created in `01_auth_and_master.sql` before `07_views_triggers_constraints.sql` runs. ✓
- **All `ALTER TABLE … ENABLE ROW LEVEL SECURITY`** (31 tables): every referenced table is created in the baseline. ✓
- **Dollar-quote splitter (`splitSql`)**: traced through every `$$ … $$` block in every SQL file. All use bare `$$` tags (no `$BODY$`, no `$fn$`), all close on a line containing `$$`, and no function body contains a stray `$$` in a comment. ✓
- **Forward references to tables**: none. Every `REFERENCES` clause in `01–06` points to a table created earlier in the same file or in an earlier file.
- **Circular FKs**: none. `branch_ledger` references `branches` (already created); `manual_journals` references `journal_entries` (already created); `warehouse_transfers` references `warehouses`, `branches`, `journal_entries` (all already created).

---

## 6. Recommended Fix (single change, no further patching needed)

Move the offending index out of the baseline file and into the migration that adds the `is_reversed` column.

### Step A — Delete from `database/sql/07_views_triggers_constraints.sql`

Remove lines 195–199 (the comment block + the `CREATE INDEX`):

```sql
-- P0: Customer ledger balance (every invoice finalize + credit check)
-- Query: SELECT SUM(debit) - SUM(credit) FROM customer_ledger
--        WHERE customer_id = ? AND is_reversed = false
CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
    ON customer_ledger (customer_id, is_reversed)
    INCLUDE (debit, credit);
```

### Step B — Add to `database/migrations/2025_01_02_000002_add_is_reversed_to_sub_ledgers.php`

Append to the end of `up()`:

```php
// Covering index moved from 07_views_triggers_constraints.sql — could not be
// created in the baseline because is_reversed did not exist yet.
DB::statement('
    CREATE INDEX IF NOT EXISTS idx_cl_balance_covering
        ON customer_ledger (customer_id, is_reversed)
        INCLUDE (debit, credit)
');
```

And add the corresponding `DROP INDEX` to `down()`:

```php
DB::statement('DROP INDEX IF EXISTS idx_cl_balance_covering');
```

### Why this is the correct fix

- The baseline schema (`01–07`) becomes internally consistent — every column referenced at baseline time exists at baseline time.
- The covering index is created in the **same migration** that adds the column it depends on, so the dependency is satisfied in the correct order on both `up` and `down`.
- No other change is required for `php artisan migrate:fresh` to reach the end of the baseline migration. Subsequent migrations (`2025_01_02_000001`, `2025_01_02_000002`, …) take over from a clean baseline.

---

## 7. Why the User Has Been Chasing Errors One-by-One

Each of the previously-fixed issues was a real, separate defect that aborted the baseline migration at an earlier point:

| Previously fixed defect | Effect on migration |
|------------------------|---------------------|
| Invalid `EXCLUDE` constraint syntax | Aborted at the `CREATE TABLE` that contained it |
| Duplicate `CREATE INDEX` statements | Aborted at the second `CREATE INDEX` (object already exists) |
| Duplicate `CREATE TABLE commission_rules` | Aborted at the second `CREATE TABLE` |
| FK to `invoice_payment_allocations` created too early | Aborted at the inline `REFERENCES` clause |

Each fix advanced the failure point further into the migration. The current failure (`is_reversed` on `customer_ledger`) is the **last** remaining forward-dependency in the baseline. After applying the fix in section 6, the baseline should complete cleanly.
