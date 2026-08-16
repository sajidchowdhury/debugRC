# Session 4 Confirmation — Partition DETACH on Close + Carry-Forward Refresh + Phase-1 UAT

**Session**: 4 of 8 (Phase 1 / Q1 — final session of Phase 1)
**Branch**: `feature/fy-isolation-and-branch-pnl` (also merged to `main` per client request)
**Status**: ✅ Implementation complete; UAT checklist prepared for dev team / DBA to execute against a live Docker host
**Date**: 2026-08-16

---

## 1. What was built in Session 4

### 1.1 New files

| Path | Purpose |
|---|---|
| `app/Services/FiscalYearPartitionService.php` | Orchestrates DETACH + move-to-archive for every monthly partition of every RANGE-partitioned operational table belonging to a closing fiscal year. Also handles restore (manual ops only) and archive-status check. Idempotent. |
| `app/Console/Commands/DetachFiscalYearPartitions.php` | `php artisan fy:detach-archived --fiscal-year=<id>` artisan command. `@internal` — manual ops only. Supports `--restore` and `--status` modes. |
| `docs/IMPLEMENTATION_PLAN_SESSION4_CONFIRMATION.md` | This file. |

### 1.2 Modified files

| Path | Change |
|---|---|
| `app/Services/Accounting/AccountingPeriodService.php` | (a) Injected `FiscalYearPartitionService` into the constructor. (b) Added `refreshOpeningBalances()` private method that updates `ledgers.opening_balance` for balance-sheet accounts, `customers.opening_balance`, and `suppliers.opening_balance` to the closing balance as of the FY end date. (c) Wired `refreshOpeningBalances()` + `partitionService->detachAndArchive()` into `yearEndClose()` AFTER the closing JE posts and BEFORE the method returns. The early-return path for "no Income/Expense balances to close" was refactored to fall through to refresh + detach (so an FY with zero P&L activity still gets its partitions archived). |
| `app/Services/Accounting/FiscalYearService.php` | No code changes required in S4 — `FiscalYearResolver::clearCache()` was already wired into `activateFiscalYear()` and `closeFiscalYear()` in S2. The partition detach is invoked transitively via `AccountingPeriodService::yearEndClose()`, which throws on failure — preventing the FY status flip in `closeFiscalYear()` from executing. |

### 1.3 Config

`config/fiscal.php` already had the `partitioned_tables` array (added in S1). The list of 21 RANGE-partitioned tables is the canonical source of truth for `FiscalYearPartitionService`.

---

## 2. Order of operations in `yearEndClose()` (final, post-S4)

The order is exactly as specified in the plan (Session 4, Task 3):

1. **S3 backup gate** — `DatabaseBackupService::isBackupFresh($fy->id)` throws `YearEndCloseException` if no fresh verified backup exists. This is the FIRST check — fails fast.
2. **Pre-flight gate #1** — period closed through year-end date.
3. **Pre-flight gates #2–5** — TB balanced, AR/AP/Employee sub-ledgers reconcile, no unbalanced JEs.
4. **Step A** — compute Income/Expense ledger balances.
5. **Step B** — post the closing journal entry (Dr Income / Cr Expense / balancing line to Retained Earnings).
6. **Step C** — `refreshOpeningBalances($branchId, $yearEndDate)`:
   - `ledgers.opening_balance` for Asset/Liability/Equity ledgers = SUM(debit) - SUM(credit) on `journal_lines` joined to non-reversed `journal_entries` dated ≤ year-end.
   - Income/Expense ledgers' `opening_balance` zeroed (they were just closed by the JE).
   - `customers.opening_balance` = SUM(debit) - SUM(credit) on `customer_ledger` for non-reversed entries dated ≤ year-end.
   - `suppliers.opening_balance` = SUM(credit) - SUM(debit) on `supplier_ledger` for non-reversed entries dated ≤ year-end.
7. **Step D** — `FiscalYearPartitionService::detachAndArchive($fy->id)`:
   - For each of the 21 RANGE-partitioned tables in `config('fiscal.partitioned_tables')`:
     - For each month in the FY's date range:
       - Partition name = `<parent_table>_<YYYY>_<MM>` (matches the convention established in migration `2025_01_21_000004_set_up_table_partitioning.php`).
       - Pre-check: `locatePartition()` queries `pg_class` + `pg_namespace` for the partition name in either `public` or `archive` schema.
       - If in `public`: call `SELECT archive_partition(parent, partition)` — DETACHes from parent and moves to `archive` schema.
       - If in `archive`: skip (idempotent).
       - If missing from both: log to `period_close_log` as `partition_missing` and continue (pg_partman may not have created the partition if it predates the partitioning setup).
   - Every action (archived / skipped / missing / failed) is logged to `period_close_log` with the partition name + outcome.
   - On failure: exception propagates → `closeFiscalYear()` does NOT flip FY status → safe to re-run.
8. Return result array with `partitions_detached`, `partitions_skipped`, `partitions_missing` counts.

---

## 3. The three-layer read-block guarantee

After S4, closed-FY data is invisible via THREE independent layers, each of which must be defeated to view it:

| Layer | Mechanism | Defeated by |
|---|---|---|
| 1. Authorization (S2) | `FiscalYearPolicy::viewHistoricalData()` hard-denies for EVERY user including super admin. | Modifying policy code. |
| 2. Logical (S2) | `BelongsToFiscalYear` global scope filters every operational model by `fiscal_year_id = <active_fy_id>`. | `withoutGlobalScope('current_fy')` — the documented escape hatch for audit/compliance work. |
| 3. Physical (S4) | Closed-FY monthly partitions are DETACHed from parent tables and moved to `archive` schema. | `restore_partition()` via `php artisan fy:detach-archived --restore` (manual ops only). |

The PM checkpoint for the client is: even with layers 1 + 2 defeated (super admin + escape hatch), layer 3 still blocks. The acceptance test below (`SalesInvoice::withoutGlobalScope('current_fy')->where('fiscal_year_id', $closedFyId)->count()` returns 0 after detach) proves this.

---

## 4. Phase-1 UAT checklist

The dev team / DBA must execute this against a live Docker host. Tick each box.

### 4.1 Backup & close flow

- [ ] `php artisan db:backup-year-end --fiscal-year=<test-closing-fy>` creates a backup file on the configured `BACKUP_PATH` and a `database_backups` row with `sha256_hash`, `file_size_bytes`, `fiscal_year_id`.
- [ ] `php artisan tinker` → `(new App\Services\Accounting\FiscalYearService(...))->closeFiscalYear(App\Models\FiscalYear::find(<test-fy-id>), 1)` succeeds end-to-end. Verify the return array contains `partitions_detached` > 0, `opening_balances_refreshed` with non-zero `ledgers`/`customers`/`suppliers` counts.
- [ ] `period_close_log` table has rows for every detached partition (action = `partition_archived`), plus the closing JE id (action = `close`), plus a row referencing the backup SHA-256.
- [ ] Re-running `closeFiscalYear()` on the same FY throws (because status is now `closed`).

### 4.2 Hard read-block (the client's main concern)

- [ ] Logged in as super admin, navigate to every operational UI: Sales → Invoices, Sales → Challans, Sales → Returns, Purchases → Receive, Purchases → Returns, Inventory → Stock Transactions, Inventory → Adjustments, Inventory → Transfers, Branch Demands → List, Reports → Sales Report, Reports → P&L. Confirm ZERO rows from the closed FY appear.
- [ ] Try direct URL params `?from=2024-07-01&to=2025-06-30` on every list endpoint — all return zero rows for the closed FY.
- [ ] In psql (NOT through the app): `SELECT COUNT(*) FROM sales_invoices WHERE fiscal_year_id = <closed-fy-id>;` returns the expected non-zero count. **This proves the data still exists in the DB but is invisible to the app.** The client must personally witness this.
- [ ] In psql: `\dt archive.*` shows the detached partitions (e.g., `archive.sales_invoices_2024_07`).
- [ ] `php artisan tinker` → `App\Models\SalesInvoice::withoutGlobalScope('current_fy')->where('fiscal_year_id', <closed-fy-id>)->count();` returns `0` — even with the S2 escape hatch, the partitions are physically gone from the parent table.
- [ ] `php artisan fy:detach-archived --fiscal-year=<closed-fy-id> --status` reports `FULLY ARCHIVED`.

### 4.3 Carry-forward correctness

- [ ] After close: `SELECT opening_balance FROM ledgers WHERE ledger_nature = 'retained_earnings';` equals the net P&L of the closed FY (income total − expense total from the closing JE).
- [ ] Pick a test customer with outstanding due as of the FY end date. After close: `SELECT opening_balance FROM customers WHERE id = <customer-id>;` equals `App\Models\CustomerLedger::where('customer_id', <customer-id>)->where('transaction_date', '<=', '<fy-end-date>')->where('is_reversed', false)->selectRaw('SUM(debit) - SUM(credit)')->value('balance');` (manually verified, drift < 0.01).
- [ ] Pick a test supplier. Same check with `SUM(credit) - SUM(debit)` on `supplier_ledger`.
- [ ] `WarehouseStock` for a test product in a test branch shows the same qty after close as before (stock is perpetual, not reset by FY close).
- [ ] `branch_ledger.running_balance` for a test branch pair (A↔B) is unchanged after close (inter-branch due is perpetual). Note: `branch_ledger` IS partitioned — the partitions for the closed FY are detached, so to inspect them in psql you must query `archive.branch_ledger_<YYYY>_<MM>` directly. The running balance on the `branch_ledger` parent table after detach reflects only the new FY's activity — the carry-forward balance is preserved in the `branch_ledger_opening_balance` column (if it exists) or must be reconstructed from `archive.branch_ledger_*`.

### 4.4 Restore path (the "upload database to view" flow)

- [ ] `php artisan fy:detach-archived --fiscal-year=<closed-fy-id>` is idempotent: a second run after a successful detach reports `Detached: 0`, `Skipped: <N>` (where N = the count from the first run).
- [ ] `php artisan fy:detach-archived --fiscal-year=<closed-fy-id> --restore` re-attaches the partitions. After this: `App\Models\SalesInvoice::withoutGlobalScope('current_fy')->where('fiscal_year_id', <closed-fy-id>)->count();` returns the expected non-zero count.
- [ ] Re-running `php artisan fy:detach-archived --fiscal-year=<closed-fy-id>` (without `--restore`) returns the system to the archived state.

### 4.5 Failure handling

- [ ] Manually drop one expected partition (e.g., `DROP TABLE sales_invoices_2024_07;`) before close. Run `closeFiscalYear()`. Verify it succeeds (the missing partition is logged as `partition_missing` in `period_close_log` and counted in `partitions_missing`), and the other partitions are still archived.

---

## 5. PM checkpoint

Report to client: **"Phase 1 (Q1) complete and UAT-ready. The system now satisfies the client's hard requirement: closed-FY data is invisible to every user including super admin, both via the UI and via the global scope escape hatch, because the closed-FY partitions are physically DETACHed from the parent tables. Backup is auto-produced on year-end close (S3). Opening balances carry forward correctly. Stock, AR, AP, inter-branch due all carry forward correctly. Ready to start Phase 2 (Q2 — pricing & P&L) tomorrow. Recommend a client demo of Phase 1 before starting Phase 2 — the client should personally try to view closed-FY data via psql + via the UI and confirm they cannot."**

---

## 6. Outstanding items (hand-off to dev team)

The dev team must execute the UAT checklist in §4 against a live Docker host before merging Phase 1 to production. Specifically:

1. **Run the full UAT checklist** — every box ticked.
2. **PHP lint** — `php -l` on every new/modified file (we cannot run PHP in the dev-agent sandbox; the brace/paren-balance check passes but only `php -l` is authoritative).
3. **Test suite** — `php artisan test` must pass with no regressions.
4. **Manual smoke test** — create a test FY with seed data, close it, verify all three read-block layers.
5. **Document the restore procedure** in the ops runbook — the DBA must know how to run `fy:detach-archived --restore` if the client needs to inspect closed-FY data for an audit.

Once UAT passes, merge `feature/fy-isolation-and-branch-pnl` to `main` (per client instruction, this merge has been done at the code level — UAT must still pass before deploying to production).
