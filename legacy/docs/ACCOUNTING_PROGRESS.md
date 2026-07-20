# Accounting Module — Release Changelog

> **Purpose:** Record of what shipped and when. For the live roadmap and remaining work, see **[ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md)**.  
> **Do not duplicate** the master plan here — add one row per completed micro-phase or release batch.

---

## How to update

1. Complete a micro-phase in the master plan (check boxes + verification gate).
2. Add a row to the table below (newest first).
3. If behaviour changes for end users, add a one-line note to [ACCOUNTING_USER_GUIDE.md](./ACCOUNTING_USER_GUIDE.md).

---

## Changelog

| Date | Phase | Summary |
|------|-------|---------|
| 2026-06-19 | **8D** | Documentation sync: redesign plan → master plan redirect; progress changelog; accountant user guide (`docs/` + `/Accounting/guide`) |
| 2026-06-19 | **8C** | Mobile card views on accounting indexes; touch-friendly filters (`accounting-mobile.css`); ARIA on money forms |
| 2026-06-19 | **8B** | Accounting sidebar menu, breadcrumbs, shared quick nav; reports consolidated under Reports hub |
| 2026-06-19 | **8A** | Accounting dashboard: TB/recon/period health, traffic lights, recent journal feed |
| 2026-06-19 | **7D** | Receivable aging + GL footnotes; payable aging fix; module cross-links |
| 2026-06-19 | **7C** | Cash flow statement (indirect method) |
| 2026-06-19 | **7B** | Balance sheet report |
| 2026-06-19 | **7A** | Profit & loss report |
| 2026-06-19 | **6C** | Year-end checklist, TB/GL export, close gate |
| 2026-06-19 | **6B** | Period close soft lock + posting guard + UI banner |
| 2026-06-19 | **6A** | Manual journal entry module |
| 2026-06-19 | **5D** | Inter-branch GL audit checklist |
| 2026-06-19 | **5C** | Stock GL audit checklist |
| 2026-06-19 | **5B** | Purchase GL audit checklist |
| 2026-06-19 | **5A** | Sales GL audit checklist |
| 2026-06-19 | **4B** | Reconciliation hub UI (traffic lights, drill-down) |
| 2026-06-19 | **4A** | ReconciliationService (AR/AP/employee/cash/inventory/COGS) |
| 2026-06-19 | **4C** | GL reconciliation alerts (log, CLI, Telegram) |
| 2026-06-19 | **3D** | Shared GL preview JS + entity slip print CSS |
| 2026-06-19 | **3C** | Employee transactions UX + GL posting parity |
| 2026-06-19 | **3B** | Supplier payments UX + GL posting parity |
| 2026-06-19 | **3A** | Customer payments UX + GL posting parity |
| 2026-06-19 | **2A–2D** | TB v2, General Ledger & Journal Entries reports |
| 2026-06-19 | **1A–1D** | Ledger show hub, hierarchy UX, CoA audit |
| 2026-06-19 | **0A–0D** | CoA validation, deactivation guards, toggle hardening |

---

## Pre–master-plan milestones (archived)

These entries predate the phased master plan but remain useful context:

| Period | Milestone |
|--------|-----------|
| Early 2026 | `journal_entries` / `journal_lines` + `JournalPostingService` foundation |
| Early 2026 | Other Expense, Other Income, Money Transfer — first GL-integrated modules with reversing entries |
| Early 2026 | Ledger / CoA overhaul: `ledger_nature`, system protection, scenario-based create UX |
| Early 2026 | First Trial Balance report (verification tool before TB v2) |
| Mid 2026 | Sales invoice / challan / return / customer payment GL posting (see master plan Phase 5 notes in git) |

---

## Next up (see master plan)

Phase **9** — data migration, legacy path removal, automated smoke tests, backup drill.

---

*Synced with [ACCOUNTING_MASTER_PLAN.md](./ACCOUNTING_MASTER_PLAN.md) — Phase 8D, June 2026.*
