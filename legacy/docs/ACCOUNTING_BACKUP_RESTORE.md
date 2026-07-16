# Accounting backup & restore drill (Phase 7)

Critical tables for sales GL and AR sub-ledger:

| Table | Purpose |
|-------|---------|
| `journal_entries` / `journal_lines` | General ledger |
| `customer_ledger` | AR sub-ledger running balances |
| `customer_payments` / `invoice_payment_allocations` | Receipts |
| `document_sequences` | Invoice/payment code counters |
| `sales_invoices` / `sales_challans` | Operational links to journals |

## Backup (recommended weekly + before migrations)

```bash
php database/scripts/backup_accounting_core.php
# Or specify folder:
php database/scripts/backup_accounting_core.php C:\backups\remote-center-erp
```

On Windows without `mysqldump` in PATH, use phpMyAdmin **Export** for the tables above, or add MySQL `bin` to PATH.

## Restore drill (staging only)

1. Copy production backup to staging server.
2. Stop web app (prevent new postings during restore).
3. Restore SQL file into **empty** staging DB or rename tables first.
4. Run `php database/tests/sales_core_smoke.php` and `php database/scripts/run_gl_reconciliation.php`.
5. Open **Sales Audit** checklist — AR/GL items should be green or explain known drift.
6. Spot-check one customer: latest `customer_ledger.running_balance` vs sum of debits−credits.

## Scheduled monitoring (Phase 4C)

These scripts should run on **every environment** (local, staging, production) via Windows Task Scheduler or Linux cron. Use the project root as the working directory.

| Script | Purpose | Suggested schedule |
|--------|---------|-------------------|
| `php database/scripts/run_gl_reconciliation.php` | AR / inventory / COGS vs GL; exit code 1 on drift | Daily after business close |
| `php database/scripts/cancel_stale_sales_drafts.php` | Cancel old POS draft invoices | Weekly |
| `php database/scripts/backup_accounting_core.php` | Dump critical accounting tables | Weekly + before migrations |
| `php database/scripts/review_reconciliation_alerts.php` | Print recent alert log (manual triage) | As needed |

When reconciliation finds issues:

- JSON lines append to **`logs/reconciliation_alerts.log`**
- **Telegram** notifies users with roles `admin` and `accountant` (requires `TELEGRAM_BOT_TOKEN` + `telegram_user_id` on users)
- Optional email for **sales audit** failures: `RECON_ALERT_EMAIL` in `config/local.php`
- Review in-app on **`sales/reconcile`** (Recent alert log) or via the review CLI script

Tolerance for “within range” checks: `GL_RECONCILIATION_TOLERANCE` (default `0.02`).

See also: `docs/ACCOUNTING_MASTER_PLAN.md` (Phase 4C), `docs/TELEGRAM_ALERTS.md`.

## Rollback policy

- Do not partial-restore only `customer_ledger` without matching `journal_entries` — balances will diverge.
- After restore, re-run migration `016` sequence sync if invoice codes were regenerated.

## Year-end checklist (Phase 6C)

Before applying **period close** for a fiscal year, open **`AccountingPeriod/year_end`**. The checklist verifies:

- Trial balance balanced for the year
- GL reconciliation within tolerance (`Reconciliation/index`)
- A recent `accounting_core_*.sql` file in `/backups` (within 14 days — warning only, does not block close)

Export **Trial Balance** and **GL archive** CSV from the same screen for audit retention.

## Retention

Keep at least 4 weekly backups; store off-server (encrypted zip).