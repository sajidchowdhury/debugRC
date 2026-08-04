# Product Changelog — RC_ERP_v2 (Remote Center ERP)

> **Module:** Changelog / Product history
> **Audience:** Engineers, AI assistants, product owners, accountants, auditors
> **Status:** Living document — append new entries at the bottom; never edit a closed entry.
> **Last reviewed:** Phase 21 (initial creation)
> **Source of truth:** This file consolidates the product/feature history of RC_ERP_v2.
  It is distinct from `AI_CONTEXT/changelog/CHANGELOG.md` (which tracks changes to the
  `AI_CONTEXT/` knowledge base itself). Product changes are sourced from:
  - Repo `README.md` §Migration Progress (the 13-phase migration table),
  - `docs/REMEDIATION_LOG.md` (R# remediation items for the sales module),
  - `docs/worklog.md` (SA-HOTFIX-# items for partitioning + shadow mode),
  - `laravel/docs/Phase_10.1_Remaining_Phases_Roadmap.md` (Phase 10.1 partitioning),
  - `git log --oneline` (commit history, esp. `fix:` / `HOTFIX-` prefixes),
  - The `AI_CONTEXT/` module folders (per-phase detail).

---

## How to read this changelog

- **Two time tracks.** The product has two parallel histories:
  1. **Migration phases (Phase 0–13)** — the legacy PHP/MySQL → Laravel/PostgreSQL
     migration, executed 2025-01 through 2026-07. Each phase is a product milestone.
  2. **Partitioning sub-phases (Phase 10.1.0–10.1.8)** — the PostgreSQL partitioning +
     archival effort, executed 2026-08. Tracked separately because it cuts across all
     modules.
  3. **AI_CONTEXT knowledge-base phases (Phase 0–21)** — the documentation effort,
     executed 2026-08. Tracked in `CHANGELOG.md` (docs change log), NOT here.
- **Dates are ISO 8601 (YYYY-MM-DD)**, in the Asia/Dhaka timezone where the team operates.
- **Commit hashes** are short SHA-1 references into the `debugRC` git history.
- **Severity** is one of: `feature` (new capability), `fix` (bug fix), `security`
  (security hardening), `breaking` (breaking change), `removed` (feature removed),
  `ops` (operational/deployment change), `perf` (performance improvement).

---

## 1. Product origin & principles (pre-migration)

### 1.1 Legacy system (pre-2025)

- **Origin:** Custom PHP MVC application on MySQL. ~384 PHP files, ~326 Blade-style
  views, accumulated over years of organic growth.
- **Pain points:** Aging bespoke framework, MySQL limitations (no RLS, no partitioning,
  weak trigger support), scattered business logic across `docs/` markdown, code comments,
  and config files. No single source of truth for accounting rules.
- **Decision:** Migrate to Laravel 12 + PostgreSQL 16 + Redis. Preserve the existing UI
  (Blade reproduces legacy Bootstrap markup — no SPA rewrite). Re-derive business logic
  from first principles (do not copy-paste legacy code).

### 1.2 The four non-negotiable principles

| # | Principle | Status |
|---|---|---|
| 1 | Database conversion — MySQL → PostgreSQL | ✅ Complete (Migration Phase 2) |
| 2 | Application conversion — custom PHP MVC → Laravel | ✅ Complete (Migration Phases 3–9) |
| 3 | Keep the existing UI — Blade reproduces legacy markup; no SPA rewrite | ✅ Complete |
| 4 | Re-derive business logic, don't copy-paste — stock costing, journal posting, reconciliation re-derived from first principles | ✅ Complete |

Source: repo `README.md` §Four non-negotiable principles.

---

## 2. Migration phases (Phase 0–13)

The legacy → Laravel migration was executed in 13 sequential phases. Phases 0–12 are
code-complete; Phase 13 (AI sidecar) is pending.

| Phase | Name | Status | Commit | Date (approx.) |
|---|---|---|---|---|
| 0 | Pre-Migration Security Cleanup | ✅ Complete | `8f0b7ce` | 2025-01 |
| 1 | VPS BDIX Provisioning | ⬜ Pending (manual — needs VPS) | — | — |
| 2 | Database Migration to PostgreSQL | ✅ Complete | `a76b194` | 2025-01 |
| 3 | Laravel Foundation + Auth | ✅ Complete | `92a5024` | 2025-02 |
| 4 | Master Data Modules | ✅ Complete | `845efee` | 2025-03 |
| 5 | Reporting Layer | ✅ Complete | `787d909` | 2025-04 |
| 6 | Inventory Module (6.1–6.6) | ✅ Complete | `4aa7ecf` | 2025-05 |
| 7 | Purchase Module (7.1–7.3) | ✅ Complete | `91d54ca` | 2025-06 |
| 8 | Sales Module (8.1–8.5) | ✅ Complete | `739ed4b` | 2025-07 |
| 9 | Accounting Engine (9.1–9.6) | ✅ Complete | `76240de` | 2025-09 |
| 10 | Notifications (Laravel native) | ✅ Complete | `c9631ff` | 2025-11 |
| 11 | Compliance & Investigation Framework | ✅ Complete | `e4ed955` | 2026-01 |
| 12 | Enterprise Cutover & Archive | ✅ Complete | `204aa72` | 2026-03 |
| 13 | AI Sidecar (Python FastAPI) | ⬜ Pending | — | — |

### 2.1 Phase 0 — Pre-Migration Security Cleanup (`8f0b7ce`, 2025-01)

- **Severity:** security
- Removed hardcoded credentials from the legacy PHP codebase before publishing the
  migration plan.
- Dropped the `users.totp_secret` and `users.totp_enabled` columns (TOTP 2FA was removed
  by project decision — see §4 below).
- Rotated the Telegram bot token (later removed entirely — see R24/R25 below).
- Reset all production user passwords (bcrypt hashes were in a public SQL dump).

### 2.2 Phase 2 — Database Migration to PostgreSQL (`a76b194`, 2025-01)

- **Severity:** breaking
- Migrated the schema from MySQL to PostgreSQL 16. Schema defined by raw SQL DDL in
  `laravel/database/sql/01–07_*.sql` (7 files, ~66 tables + 7 materialized views).
- ETL pipeline: `pgloader` config + 14 post-load fixes + sequence sync + 4-part verify.
  See [`../database/etl-legacy-migration.md`](../database/etl-legacy-migration.md) for
  the canonical reference.
- Replay verification: 38,775 stock transactions, 521 invoices, 311 GRNs, 550 payments —
  zero drift acceptance.
- Anti-Corruption Layer (ArchiveService + LegacyMySQLRepository + DTOs) introduced to
  preserve read-only access to legacy MySQL data. See
  [`../archive/anti-corruption-layer.md`](../archive/anti-corruption-layer.md).

### 2.3 Phase 3 — Laravel Foundation + Auth (`92a5024`, 2025-02)

- **Severity:** feature
- Bootstrapped the Laravel 12 application (`laravel/` folder). `bootstrap/app.php`
  registers middleware (session bridge, branch-id GUC, credential version, system policy,
  trust proxies) and route middleware aliases (`role`, `branch.isolation`, `api.auth`,
  `api.rate`, `set.api.branch`, `menu.permission`).
- Session-based web auth + custom bearer-token API auth (NOT Sanctum, despite
  `config/auth.php` declaring a `sanctum` guard — see
  [`../security/auth-and-sessions.md`](../security/auth-and-sessions.md)).
- Shared-session bridge with legacy PHP (`SyncLegacySession` middleware +
  `LegacySessionBridge` over PHPSESSID/Redis).

### 2.4 Phase 4 — Master Data Modules (`845efee`, 2025-03)

- **Severity:** feature
- Master data: branches, warehouses, suppliers, customers, products, employees, users,
  ledgers, chart of accounts, UoM conversions.
- 10 roles in 3 tiers (`config/roles.php`): superadmin/admin; manager tier
  (sales_manager, purchase_manager, warehouse_manager, accountant, hr_manager,
  branch_manager); operational tier (salesman, warehouse_staff).
- `EnsureRole` / `EnsureMenuPermission` middleware + 8 policies + `MenuService`
  (5-min cache, 3-level tree).

### 2.5 Phase 5 — Reporting Layer (`787d909`, 2025-04)

- **Severity:** feature
- 7 materialized views (running balance, AR aging, AP aging, stock summary, etc.).
- `refresh:report-views` artisan command (every 5 min via pg_cron + Laravel scheduler).
- `ReportsCatalog` helper + `ReportService`. CTE-based reports for complex queries.
- `CsvExporter` for CSV exports.

### 2.6 Phase 6 — Inventory Module (`4aa7ecf`, 2025-05)

- **Severity:** feature
- Stock costing (moving-average cost, first-principles derivation — see
  [`../inventory/stock-costing.md`](../inventory/stock-costing.md)).
- Stock ledger (`stock_transactions`, 11 reference_type values + 3 reversal variants).
- Warehouse stock, stock take (with freeze), stock adjustment (with maker-checker),
  damage (with type-aware loss ledger + employee recovery), warehouse transfer,
  UoM conversion, stock verification (`stock:replay-verify`, `stock:reconcile-drift`).
- Negative-stock guard trigger (absolute — no override).

### 2.7 Phase 7 — Purchase Module (`91d54ca`, 2025-06)

- **Severity:** feature
- Purchase orders (non-posting), GRN (draft → confirm → cancel with `postReceiveGL`),
  purchase returns (`postReturnGL`), purchase audit.
- AP sub-ledger (`supplier_ledger`) reconciliation.

### 2.8 Phase 8 — Sales Module (`739ed4b`, 2025-07)

- **Severity:** feature
- Sales cart (with stale-draft TTL + cron cancellation), sales invoice (draft → finalize
  → cancel with `postInvoiceGL`), sales challan (godown dispatch with `postCogsGL`),
  sales return (two-entry: `postRevenueReversalGL` + `postCogsReversalGL` using ORIGINAL
  avg_cost snapshot), commission (accrue → payable → paid), transport cost handling,
  sales audit.
- AR sub-ledger (`customer_ledger`) reconciliation.
- Credit-limit enforcement on invoice finalize.

### 2.9 Phase 9 — Accounting Engine (`76240de`, 2025-09)

- **Severity:** feature (SAFETY-CRITICAL)
- Chart of accounts, 7 critical + extended ledger natures.
- ~40 journal posting methods (`JournalPostingService::createJournalEntry` + 28
  `postXxxGL` methods + 16 type-aware sub-variants = 44 distinct Dr/Cr patterns — see
  [`../accounting/journal-posting-rules.md`](../accounting/journal-posting-rules.md) §7.6).
- `enforce_balanced_journal_entry()` trigger (the Dr=Cr crown jewel).
- Sub-ledger reconciliation (AR/AP/employee/bank/inventory).
- Reversal-over-mutation rule (counter entries, never hard-delete).
- Fiscal year/period close + year-end Retained Earnings clearing.
- Running balance + reconciliation tolerance.
- Financial audit log (append-only, hash-chained).

### 2.10 Phase 10 — Notifications, Laravel native (`c9631ff`, 2025-11)

- **Severity:** feature
- Laravel-native notifications (`ERPNotification` + `NotificationService`).
- Rule-based, DB-driven, multi-recipient dispatcher (10 recipient types).
- LISTEN/NOTIFY → worker → Redis → SSE pipeline for realtime toasts.
- Replaced Telegram + Firebase FCM (removed R24/R25 — see §4 below).

### 2.11 Phase 11 — Compliance & Investigation Framework (`e4ed955`, 2026-01)

- **Severity:** feature
- `SystemPolicyService` + investigation mode + `SystemPolicy` model.
- Policy gate (every sensitive action checked against system policy).
- Branch-isolation RLS (3-layer defense: BranchScope / EnforceBranchIsolation / RLS
  policies).

### 2.12 Phase 12 — Enterprise Cutover & Archive (`204aa72`, 2026-03)

- **Severity:** ops
- Anti-Corruption Layer fully wired (ArchiveService + LegacyMySQLRepository + DTOs).
- Legacy MySQL intended to be set to read-only on production (not yet enforced at DB
  level everywhere — see Known Limitations §3.5 in `PROJECT_OVERVIEW.md`).
- Enterprise cutover playbook documented.

### 2.13 Phase 13 — AI Sidecar (PENDING)

- **Severity:** feature (planned)
- Python FastAPI sidecar for: report chatbot, demand forecasting, invoice OCR, anomaly
  detection. See [`../ROADMAP.md`](../ROADMAP.md) §3 for the detailed roadmap.

---

## 3. Partitioning sub-phases (Phase 10.1.0–10.1.8, 2026-08)

A focused effort to partition ~30 high-volume tables by RANGE(date) using pg_partman, with
FK workaround (trigger-based + composite), retention matrix (84/36 months), and Parquet
export for archival. Tracked in `laravel/docs/Phase_10.1_Remaining_Phases_Roadmap.md`.

| Sub-phase | Name | Status | Tables | Notes |
|---|---|---|---|---|
| 10.1.1 | Audit log partitioning | ✅ Complete | 6 | 1 regression (financial_audit_log later un-partitioned then re-partitioned) |
| 10.1.2 | Sub-ledger partitioning | ✅ Complete | 5 | customer_ledger, supplier_ledger, etc. |
| 10.1.3 | Time-series summary | ✅ Complete | 1 | daily_warehouse_stock_summary |
| 10.1.4 | Low-FK transaction headers | ✅ Complete | 9 | missing retention (added later) |
| 10.1.5 | Multi-FK transaction headers | ✅ Complete | 6 | schema audit + migration |
| 10.1.6 | journal_entries + journal_lines (CRITICAL) | ✅ Complete | 2 + 27 FK conversions | HOTFIX-9 entry_date sync trigger |
| 10.1.7 | Archival, retention & consolidation | ✅ Complete | — | pg_partman retention rows for 12 tables + archive schema + Parquet export |
| 10.1.8 | Monitoring & validation framework | ✅ Complete | — | 6 functions + 5 views + 3 console commands |

**Outcome:** 30 partitioned tables (RANGE by date), pg_partman config, 84/36-month
retention matrix, trigger-based FK workaround, Parquet export flow, partition-health
observability subsystem (8 migrations + 6 functions + 5 views + 3 console commands).

### 3.1 HOTFIX-9 — `fn_jl_sync_entry_date` partition-move crash (`acdb299`, 2026-08-29)

- **Severity:** fix (SAFETY-CRITICAL)
- The `fn_jl_sync_entry_date()` trigger function crashed when a `journal_lines` row was
  moved between partitions (which happens when `entry_date` is updated on the parent
  `journal_entries` row). The crash left the sync trigger in a broken state, blocking
  all journal updates.
- Fixed by replacing `fn_jl_sync_entry_date()` with a self-defensive version that guards
  against the partition-move edge case. Migration:
  `laravel/database/migrations/2026_08_29_000001_fix_journal_lines_sync_trigger_fk_guard.php`.
- See [`../architecture/partitioning-archival.md`](../architecture/partitioning-archival.md)
  for the canonical reference.

### 3.2 Partitioning fix commits (2026-08)

A cluster of fix commits around the partitioning effort:

| Commit | Severity | Summary |
|---|---|---|
| `7d5251c` | fix | drop+recreate 5 dependent views/MVs around journal_entries/journal_lines partitioning |
| `b778b2d` | fix | run ALTER SYSTEM migration outside a transaction block |
| `c81a722` | fix | guard partman.part_config access when pg_partman not installed |
| `d4762ea` | fix | recreate damage_attachments RLS policies before dropping old damage_invoices |
| `17749e5` | fix | resolve FK naming mismatch + missing commission_entries FK |
| `c911ec1` | fix | convert money_transfers inbound FK to trigger-based before partitioning |
| `43b9fc1` | fix | drop UNIQUE constraints before indexes in dropIndexesExceptPK |
| `69d2e65` | fix | drop damage_invoices FK before dropping employee_ledger_unpartitioned |
| `b670343` | fix | flush deferred FK triggers before CREATE INDEX in partitioning migrations |

---

## 4. Removed features (by project decision)

An AI assistant MUST NOT reintroduce these, and MUST NOT assume they exist.

### 4.1 TOTP 2FA (removed Phase 0, 2025-01)

- **Severity:** removed
- TOTP 2FA on login (Google Authenticator) — removed.
- `PendingLogin` intermediate 2FA state — removed.
- `verify_2fa` view and route — removed.
- `users.totp_secret`, `users.totp_enabled` columns — dropped.

### 4.2 Telegram integration (removed R24, 2026-07-22)

- **Severity:** removed
- Telegram login notifications — removed.
- Telegram business alerts — removed.
- `users.telegram_user_id` column — dropped (migration
  `2025_01_20_000010_drop_fcm_and_telegram_fields.php`).
- Replaced by Laravel-native notifications (`ERPNotification` + `NotificationService`) +
  Listen/Notify + SSE. See `docs/sales_entry_Lg_vs_La.md` R24.

### 4.3 Firebase FCM push (removed R25, 2026-07-22)

- **Severity:** removed
- Firebase FCM push notifications — removed.
- `fcm_tokens` table — dropped.
- Replaced by in-app inbox + Listen/Notify realtime fanout. See
  `docs/sales_entry_Lg_vs_La.md` R25.

---

## 5. Sales module remediation log (R# items, 2026-07)

Companion to `docs/REMEDIATION_LOG.md` and `docs/sales_entry_Lg_vs_La.md`. Each R# item
is a remediation of a legacy-vs-Laravel parity gap in the sales module.

| # | Item | Status | Date |
|---|---|---|---|
| R1 | Replace select2 500-row dropdowns with live search endpoints | ✅ Done | 2026-07-21 |
| R2 | (reserved) | — | — |
| ... | ... | ... | ... |
| R24 | Remove Telegram integration | ✅ Done | 2026-07-22 |
| R25 | Remove Firebase FCM push | ✅ Done | 2026-07-22 |

> **Note:** The full R# catalog lives in `docs/REMEDIATION_LOG.md`. Only the most
> impactful items (R1, R24, R25) are summarized here. Future R# items should be appended
> to `docs/REMEDIATION_LOG.md` AND cross-referenced here.

---

## 6. Cross-cutting fixes (selected, 2025–2026)

A non-exhaustive list of notable fix commits outside the phase structure:

| Commit | Date (approx.) | Severity | Summary |
|---|---|---|---|
| `cf5f928` | 2025-Q2 | fix | drop source CHECK constraint on journal_entries — it was too restrictive |
| `40c59f6` | 2025-Q2 | fix | RLS policy uses `app.is_admin` GUC instead of non-existent `u.role` |
| `b906c86` | 2025-Q2 | fix | use correct `employees→users` relationship in superadmin lookup |
| `96e1d7c` | 2025-Q3 | fix | move static consolidation routes before `{consolidationRun}` wildcard |
| `b3a3cab` | 2025-Q3 | fix | change rules toggle route from POST to PATCH to match view |
| `9a75016` | 2025-Q3 | fix | ConsolidationService param count mismatch + ConsolidationCompany model reference |
| `e560ab2` | 2025-Q4 | fix | trial_balance view checks iteration — each check is an array not scalar |
| `4c548dc` | 2025-Q4 | fix | use int IDs instead of route model binding for schedule/disposal routes |
| `d8640f2` | 2026-Q1 | fix | rename view directory `fixed-assets` → `fixed_assets` to match controller view references |
| `82f13ad` | 2026-Q2 | fix | Phase 4 — add missing financial audit triggers + sales_returns FK trigger |
| `acdb299` | 2026-08-29 | fix (SAFETY-CRITICAL) | HOTFIX-9 — fix `fn_jl_sync_entry_date` partition-move crash |

---

## 7. AI_CONTEXT knowledge-base phases (Phase 0–21, 2026-08)

The `AI_CONTEXT/` knowledge base was built in 22 sequential documentation phases. These
are **documentation milestones**, NOT product milestones — they are tracked in
[`./CHANGELOG.md`](./CHANGELOG.md) (the docs change log), NOT here. They are mentioned
here only for completeness:

| Phase | Name | Complexity | Status |
|---|---|---|---|
| 0 | Foundation & Entry Points | M | ✅ Complete |
| 1 | Architecture | L | ✅ Complete |
| 2 | Business Domain | M | ✅ Complete |
| 3 | Database Design | XL | ✅ Complete |
| 4 | Coding Standards & Conventions | L | ✅ Complete |
| 5 | Security, Auth & RBAC | L | ✅ Complete |
| 6 | Accounting Engine (SAFETY-CRITICAL) | XL | ✅ Complete |
| 7 | Accounting Transactions (SAFETY-CRITICAL) | XL | ✅ Complete |
| 8 | Inventory | XL | ✅ Complete |
| 9 | Purchasing (Procure-to-Pay) | L | ✅ Complete |
| 10 | Sales (Order-to-Cash) | XL | ✅ Complete |
| 11 | Fixed Assets | M | ✅ Complete |
| 12 | Budgeting, Dimensions & Cost Centers | M | ✅ Complete |
| 13 | Consolidation, Intercompany & Branch Demand | L | ✅ Complete |
| 14 | Approval Workflow & Compliance | M | ✅ Complete |
| 15 | Notifications & Realtime | M | ✅ Complete |
| 16 | Reporting & Exports | L | ✅ Complete |
| 17 | API Layer (REST v1) | L | ✅ Complete |
| 18 | Archive & Legacy Anti-Corruption Layer | M | ✅ Complete |
| 19 | Deployment, DevOps & Partitioning/Archival Ops | L | ✅ Complete |
| 20 | Cross-Cutting Workflows (SAFETY-CRITICAL) | XL | ✅ Complete |
| 21 | Changelog, Known Limitations & Roadmap | M | ✅ Complete (this file) |

---

## 8. Current product state (as of 2026-08-04)

### 8.1 Code-complete

- **137 PHP files + 123 Blade views** in the Laravel app (verified by file count — see
  [`../PROJECT_OVERVIEW.md`](../PROJECT_OVERVIEW.md) §4.3 for the full breakdown).
- **Full ERP**: master data, inventory, purchase, sales, accounting, reports,
  reconciliation, notifications, compliance, archive.
- **PostgreSQL schema** (66 tables + 7 materialized views + ~93 triggers + ~152 CHECKs +
  2 EXCLUDE constraints + RLS policies + ~700 indexes).
- **160 migrations** (2025-01 to 2026-08).
- **107 test files / ~1185 tests / 87.93% coverage** (PHPUnit 11.5).
- **30 partitioned tables** (RANGE by date) + pg_partman config + retention matrix +
  Parquet export + partition-health observability.
- **REST API v1** (`/api/v1`, 555 lines in `routes/api.php`).
- **Anti-Corruption Layer** for legacy MySQL archive (read-only historical search).

### 8.2 Pending (not yet code-complete)

- **Phase 1 — VPS BDIX Provisioning** (manual): provision Ubuntu 22.04 VPS, install
  PHP 8.3 + PostgreSQL 16 + Redis + Nginx. See
  [`../deployment/vps-bdix-deployment.md`](../deployment/vps-bdix-deployment.md).
- **Phase 13 — AI Sidecar** (Python FastAPI): report chatbot, demand forecasting, invoice
  OCR, anomaly detection. See [`../ROADMAP.md`](../ROADMAP.md) §3.

### 8.3 Manual actions still required (cannot be done in code)

- [ ] Reset all production user passwords (bcrypt hashes were in a public SQL dump).
- [ ] Delete or make-private the old public repo `sajidchowdhury/RC_ERP`.
- [ ] Delete or make-private the public repo `sajidchowdhury/RC_ERP_Laravel`.
- [ ] Provision BDIX VPS (Phase 1).
- [ ] Set production `.env` with new credentials (chmod 600, never committed).
- [ ] Legacy MySQL set to READ-ONLY (revoke write privileges) on production.

### 8.4 Known gaps (cross-cutting)

These are cross-module gaps documented across the `AI_CONTEXT/` knowledge base. They are
NOT product blockers, but they should be resolved before the VPS cutover. See
[`../PROJECT_OVERVIEW.md`](../PROJECT_OVERVIEW.md) §11 Known Limitations (expanded) for
the consolidated list.

- **G1/G2/G3 (notifications):** double-dispatch, wrong-event-on-update,
  worker-forward-missing-context. See
  [`../workflows/notification-workflow.md`](../workflows/notification-workflow.md) §1.1.
- **Dead intercompany settlement methods:** `CustomerPaymentService::postIntercompanySettlement`
  (L772, `return null;`) and `SupplierTransactionService::postIntercompanySettlement`
  (L616) and `WarehouseTransferService::postIntercompanyGL` (L531, not called from
  `confirm()`). See [`../workflows/inventory-to-gl.md`](../workflows/inventory-to-gl.md)
  §12.1 and [`../accounting/customer-payments.md`](../accounting/customer-payments.md) §8.
- **Avg-cost snapshot backfill:** legacy `stock_transactions.unit_cost` and
  `sales_invoice_items.avg_cost_snapshot` are NULL for pre-migration data — drift risk on
  reversals. See [`../inventory/stock-costing.md`](../inventory/stock-costing.md) §13.
- **Period close does not enforce recon:** the close command succeeds even if
  `subledger:reconcile-*` reports drift. See
  [`../workflows/period-close-workflow.md`](../workflows/period-close-workflow.md) §12.8.
- **Legacy vs enhanced period close reconciliation:** `accounting_periods` (legacy) and
  `fiscal_periods` (enhanced) can disagree. See
  [`../workflows/period-close-workflow.md`](../workflows/period-close-workflow.md) §12.12.
- **Approval engine inconsistency (G1–G7):** Pattern A (generic engine) vs Pattern B
  (entity-specific maker-checker) do not share infrastructure; only `manual_journal` is
  wired into Pattern A, and even there the post-step is broken. See
  [`../workflows/approval-workflow.md`](../workflows/approval-workflow.md) §1.

---

## 9. Change-log conventions (for future entries)

When appending a new entry to this file:

1. **Append at the bottom** of the relevant section (§2 migration phases, §3 partitioning,
   §4 removed features, §5 sales remediation, §6 cross-cutting fixes, or a new §N section
   for a new category).
2. **Never edit a closed entry.** If an entry needs correction, add a new entry referencing
   the original (e.g. "Supersedes §2.5 — Phase 6 inventory module ...").
3. **Include:** date (ISO 8601), severity, commit hash (if applicable), one-line summary,
   and a brief explanation (2–5 sentences) with cross-references to `AI_CONTEXT/` files.
4. **For SAFETY-CRITICAL changes** (accounting, inventory, security), explicitly mark the
   severity as `(SAFETY-CRITICAL)` and link the canonical `AI_CONTEXT/` reference.
5. **For removed features**, also update
   [`../PROJECT_OVERVIEW.md`](../PROJECT_OVERVIEW.md) §9 Removed features so the list
   stays canonical.

---

*This is the canonical product changelog. For documentation (AI_CONTEXT knowledge-base)
changes, see [`./CHANGELOG.md`](./CHANGELOG.md). For the future roadmap, see
[`../ROADMAP.md`](../ROADMAP.md). For known limitations, see
[`../PROJECT_OVERVIEW.md`](../PROJECT_OVERVIEW.md) §11.*
