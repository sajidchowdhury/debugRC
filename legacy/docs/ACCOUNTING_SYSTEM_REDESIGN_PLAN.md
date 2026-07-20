# Professional Accounting System Redesign Plan

> **Status: Superseded — June 2026**  
> This document is kept for **historical context** only. Do not use it for current implementation planning.

---

## Use these documents instead

| Document | Purpose |
|----------|---------|
| **[ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md)** | **Single source of truth** — phased roadmap, verification gates, file map, architecture |
| **[ACCOUNTING_PROGRESS.md](./ACCOUNTING_PROGRESS.md)** | Release changelog (what shipped, when) |
| **[ACCOUNTING_USER_GUIDE.md](./ACCOUNTING_USER_GUIDE.md)** | Day-to-day guide for accountants (workflows, reports, period close) |
| **[ACCOUNTING_BACKUP_RESTORE.md](./ACCOUNTING_BACKUP_RESTORE.md)** | Backup / restore runbook for accounting tables |

**In the app:** open **Accounting → User guide** (`/Accounting/guide`) for the same accountant workflows in the browser.

---

## What changed since this redesign doc was written

When this plan was drafted, sales/purchase GL integration, reconciliation hub, financial statements, period close, and the accounting home dashboard were still future work. **Phases 0–8 of the master plan are now largely complete**, including:

- Full double-entry posting via `JournalPostingService` across sales, purchase, stock, and money modules
- GL reconciliation hub with traffic-light sections
- Trial Balance, General Ledger, P&L, Balance Sheet, Cash Flow, aging reports
- Manual journals, period close, year-end checklist
- Accounting home (`/Accounting/index`), sidebar menu, mobile-friendly money screens

For **current status**, open [ACCOUNTING_PROGRESS.md](./ACCOUNTING_PROGRESS.md) or the changelog at the bottom of [ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md).

---

## Archive note

The detailed phased outline, gap analysis, and “recommended next steps” that originally lived in this file described the **pre–master-plan** state (e.g. “Sales not integrated”, “Migration 002 not applied”). Those sections are **obsolete** and were removed to avoid contradicting the master plan.

If you need the original long-form text, retrieve it from git history before June 2026.

---

*Last synced with master plan: June 2026 (Phase 8D documentation sync).*
