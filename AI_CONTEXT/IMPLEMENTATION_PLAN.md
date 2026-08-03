# AI Context System — Master Implementation Plan

> **Document type:** Master roadmap (living document)
> **Project:** RC_ERP_v2 — Remote Center ERP (Laravel 12 + PostgreSQL 16 + Redis)
> **Repository:** `debugRC/` (cloned from `github.com/sajidchowdhury/debugRC`)
> **Status:** Phases 0–6 COMPLETE. Phase 7 (Accounting Transactions, SAFETY-CRITICAL) pending commission.
> **Last updated:** Phase 6 complete

---

## 0. How to read this document

This file is the **single source of truth** for how the `AI_CONTEXT/` knowledge base is
built. It is *not* the documentation itself — it is the **blueprint** that controls the
order, standards, and quality of every future documentation file.

Execution model:

1. Pick the next **not-started** phase from §4 (Documentation Phases).
2. Read its objective, dependencies, and files-to-create list.
3. Follow §6 (Documentation Rules) and §2 (Documentation Standards) while writing.
4. When the phase is complete, update §5 (Progress Tracker).
5. **Stop** and wait for the next phase to be commissioned.

> **CRITICAL:** Do not generate all phases at once. One phase at a time. Quality over
> speed. Each phase must be reviewed before the next begins.

---

## 1. Overall Goal

### 1.1 Purpose

The **AI Context System** (`AI_CONTEXT/`) is a structured, living knowledge base that lets
**any AI assistant** (or any new senior engineer) understand the RC_ERP codebase
**without requiring a human to re-explain** the business logic, architecture, workflows,
accounting rules, or implementation decisions.

The ERP is large (384 PHP files, 326 Blade views, 160 migrations, 107 tests, 66 tables +
7 materialized views, ~40 accounting posting methods, multi-branch + multi-warehouse
inventory, full purchase/sales/accounting cycles). Years of accumulated business logic
live in services, migrations, triggers, and scattered markdown notes under `docs/`. No
single place today gives a complete, consistent picture.

`AI_CONTEXT/` exists to **consolidate, deduplicate, and standardize** that knowledge into
one authoritative hierarchy.

### 1.2 Why it exists

- **Onboarding cost:** A new contributor (human or AI) currently has to read dozens of
  unrelated markdown files, migrations, and service classes to understand even one
  workflow (e.g. "how a sales invoice finalizes and posts to the GL").
- **Knowledge fragmentation:** Business rules are split across `docs/migration/`
  (avg cost rule, journal posting rules, schema mapping), inline code comments, config
  files (`config/accounting.php`, `config/roles.php`), DB triggers, and the README.
- **Accounting integrity risk:** Accounting rules (Dr=Cr, reversal-not-mutation,
  sub-ledger reconciliation, period close) are safety-critical. An AI that guesses them
  can corrupt the books. A canonical reference prevents that.
- **Audit & compliance:** The ERP has an investigation/compliance framework, audit trails,
  RLS branch isolation, and a legacy read-only archive. These must be documented
  precisely so they are never accidentally bypassed.

### 1.3 How future AI assistants should use it

A new AI assistant working on this ERP should:

1. **Always read `AI_CONTEXT/` first** — start with `README.md`, then
   `PROJECT_OVERVIEW.md`, then the relevant module folder for the task.
2. **Treat it as authoritative.** If code contradicts the docs, *flag it* and update the
   docs after confirming with a human — do not silently follow the code if the docs warn
   of a business rule (especially in accounting/inventory).
3. **Never assume undocumented business logic.** If a rule is not in `AI_CONTEXT/`, ask a
   human or derive it from first principles + code, then **document it** (see §7).
4. **Respect the four non-negotiable principles** (see `PROJECT_OVERVIEW.md`, sourced from
   the repo README): DB conversion done, app conversion done, keep existing UI,
   **re-derive business logic — never copy-paste**.
5. **Update the docs whenever significant code changes.** This is a living system (see §7).

### 1.4 Relationship to existing `docs/`

The repo already contains rich, high-quality markdown under `docs/` and `docs/migration/`
(e.g. `journal_posting_rules.md`, `avg_cost_rule.md`, `schema_mapping.md`, phase reports).
`AI_CONTEXT/` does **not** delete or replace those — it **consolidates and cross-references**
them. Where an existing doc is the source of truth, `AI_CONTEXT/` links to it; where the
existing doc is scattered or stale, `AI_CONTEXT/` becomes the new canonical home and notes
the supersession.

---

## 2. Documentation Standards

All files in `AI_CONTEXT/` MUST follow these standards. Consistency is mandatory because
the audience is both humans and AI agents that may parse the structure programmatically.

### 2.1 Format

- **Markdown only** (GitHub-flavored). No proprietary formats.
- **UTF-8**, LF line endings.
- Filenames: `UPPER_SNAKE_CASE.md` for top-level files; `kebab-case.md` inside module
  folders. One concept per file.
- Every file starts with a **front-matter header block** (not YAML, just a fixed table):

  ```markdown
  # <Title>

  > **Module:** <e.g. Accounting / Inventory / Sales>
  > **Audience:** Engineers + AI assistants + accountants (where relevant)
  > **Status:** Draft | Reviewed | Canonical
  > **Last reviewed:** YYYY-MM-DD
  > **Source of truth:** <this file | code path | external doc>
  ```

### 2.2 Heading hierarchy

- `#` — File title (exactly one per file).
- `##` — Top-level sections (match the §6 mandatory question set where applicable).
- `###` — Subsections.
- `####` — Sub-subsections (avoid going deeper than `####`; split into a new file instead).
- Never skip levels (no `#` → `###`).

### 2.3 Cross-references

- Use **relative Markdown links** to other `AI_CONTEXT/` files:
  `../accounting/journal-posting-rules.md`.
- When referencing code, use the path from repo root:
  `laravel/app/Services/Accounting/JournalPostingService.php`.
- When referencing an existing external doc, link its repo path:
  `docs/migration/journal_posting_rules.md`.
- Use **stable anchor IDs** (`## 2-ledger-natures`) so deep links survive renames.

### 2.4 Diagrams

- Use **Mermaid** for all diagrams (flowcharts, sequence, ER, state). It renders in
  GitHub and is plain text (diff-friendly, AI-parseable).
- Use **tables** for rule matrices, field lists, status enums, role-permission maps.
- Use **code blocks** only for: SQL DDL snippets, PHP signatures, request/response JSON,
  or shell commands. Never paste whole files — link to them instead.

### 2.5 Writing style

- **Business explanation before technical explanation.** Every file opens with the
  business *why*, then the technical *how*.
- Short paragraphs, imperative voice for rules ("MUST", "MUST NOT", "SHOULD").
- Define every acronym on first use (CoA, GL, AR, AP, COGS, RLS, MV, CTE, UoM, ABC…).
- No marketing language. No emojis.
- Quantify where possible ("~40 posting methods", "66 tables", "7 critical ledger natures").

### 2.6 Mandatory section template

Every module/rule file MUST contain the §6 question set as `##` sections (omit a section
only if genuinely N/A, and state "N/A — reason"). See §6.

### 2.7 Versioning & change log

- Each file carries `Last reviewed` in its header.
- Material changes are appended to `AI_CONTEXT/changelog/CHANGELOG.md` with date, file,
  and one-line summary. This is separate from the *product* changelog
  (`changelog/PRODUCT_CHANGELOG.md`) which tracks ERP feature changes.

---

## 3. Folder Structure

The target structure for the completed knowledge base. Folders are created lazily as
their phase begins (do **not** pre-create empty folders).

```
AI_CONTEXT/
├── README.md                         # Entry point: how to use this knowledge base
├── IMPLEMENTATION_PLAN.md            # THIS FILE — the master roadmap
├── PROJECT_OVERVIEW.md               # What the ERP is, principles, scope, tech stack
├── GLOSSARY.md                       # Business + technical terms (CoA, GL, AR, RLS…)
│
├── architecture/                     # System architecture
│   ├── high-level-architecture.md    # Layers, request lifecycle, service-oriented design
│   ├── layered-design.md             # Controllers → Services → Models → DB
│   ├── module-map.md                 # Every module and its entry points
│   ├── branch-isolation-rls.md       # Row-Level Security + branch scoping
│   ├── realtime-events.md            # Listen/Notify, SSE, notifications fan-out
│   └── partitioning-archival.md      # PG partitioning, pg_partman, archive strategy
│
├── business/                         # Business domain (non-technical first)
│   ├── business-model.md             # What Remote Center sells/operates
│   ├── organizational-structure.md   # Branches, warehouses, roles, employees
│   ├── core-workflows.md             # End-to-end value chain (buy→stock→sell→collect)
│   └── business-rules-catalog.md     # Cross-cutting rules not owned by one module
│
├── database/                         # Database design
│   ├── schema-overview.md            # 66 tables + 7 MVs, grouped by domain
│   ├── er-diagrams.md                # Mermaid ER per domain
│   ├── migrations-conventions.md     # Naming, idempotency, raw SQL vs migration
│   ├── triggers-views-constraints.md # Balanced-journal trigger, sync triggers, RLS
│   ├── partitioning.md               # Partition scheme, pg_partman, retention
│   └── etl-legacy-migration.md       # pgloader, sequence sync, post-load fixes
│
├── accounting/                       # The safety-critical core
│   ├── chart-of-accounts.md          # CoA, ledger natures (7 critical + extended)
│   ├── journal-posting-rules.md      # ~40 posting methods, Dr=Cr, reversal rules
│   ├── subledger-reconciliation.md   # AR/AP/employee/bank sub-ledger recon
│   ├── reversal-vs-cancellation.md   # Why reversals, never mutation
│   ├── fiscal-year-period-close.md   # Period open/close, year-end close
│   ├── running-balance.md            # Running balance + reconciliation
│   ├── money-transfers.md            # Cash/bank transfers
│   ├── employee-transactions.md      # Advances, salary, settlements
│   ├── supplier-transactions.md      # Supplier payments + AP
│   ├── customer-payments.md          # Customer receipts + AR + allocations
│   ├── other-income-expense.md       # Non-core income/expense postings
│   ├── manual-journals.md            # Manual GL adjustments
│   ├── bank-reconciliation.md        # Bank statement reconciliation
│   └── financial-audit-log.md        # Append-only audit, xmin triggers
│
├── inventory/                        # Inventory & warehouse operations
│   ├── stock-costing.md              # Moving-average cost (first-principles)
│   ├── stock-ledger.md               # stock_transactions, reference types, reversal
│   ├── warehouse-stock.md            # Per-warehouse quantities, availability service
│   ├── stock-take.md                 # Physical count, freeze, variance, ABC
│   ├── stock-adjustment.md           # Increase/decrease, approval, recon
│   ├── damage.md                     # Damage write-off, witness/accountable, workflow
│   ├── warehouse-transfer.md         # Inner + cross-branch transfers, intercompany
│   ├── uom-conversion.md             # Unit-of-measure conversions
│   └── stock-verification.md         # stock:replay-verify, drift recon
│
├── manufacturing/                    # (Currently N/A — placeholder for future)
│   └── README.md                     # States manufacturing is out of current scope
│
├── purchasing/                       # Procure-to-pay
│   ├── purchase-order.md             # PO create/approve/cancel
│   ├── purchase-receive.md           # GRN, stock-in, AP posting
│   ├── purchase-return.md            # Return to supplier, reversal
│   └── purchase-audit.md             # Purchase audit checklist/logs
│
├── sales/                            # Order-to-cash
│   ├── sales-overview.md             # Invoice vs challan vs cart flow
│   ├── sales-invoice.md              # Create/finalize, revenue+AR+COGS posting
│   ├── sales-challan.md              # Godown/challan dispatch, COGS posting
│   ├── sales-cart.md                 # Draft cart, concurrency, auto-cancel
│   ├── sales-return.md               # Return, restock, reversal guard
│   ├── commission.md                 # Commission rules, targets, calculation
│   ├── transport-cost.md             # Transport revenue/expense handling
│   └── sales-audit.md                # Sales audit logs & checklists
│
├── finance/                          # Cross-cutting finance
│   ├── fixed-assets.md               # Asset register, depreciation, disposal
│   ├── budgeting.md                  # Budgets, lines, variance
│   ├── dimensions-cost-centers.md    # Analytical dimensions, cost centers
│   ├── consolidation-intercompany.md # Multi-branch consolidation, intercompany
│   └── branch-demand.md              # Inter-branch demand/requisition + settlement
│
├── reports/                          # Reporting layer
│   ├── reports-catalog.md            # All reports + ReportService, ReportsCatalog helper
│   ├── materialized-views.md         # 7 MVs, refresh strategy (refresh:report-views)
│   ├── cte-reports.md                # Complex CTE-based reports
│   ├── csv-export.md                 # CsvExporter, export patterns
│   └── dashboards.md                 # Dashboard, performance dashboard, sales cockpit
│
├── api/                              # REST API (v1)
│   ├── api-overview.md               # Versioning, auth (Sanctum token), rate limit
│   ├── api-conventions.md            # Naming, JSON shape, pagination, errors
│   ├── api-modules.md                # Endpoints per module (Sales/StockTake/…)
│   └── api-reference-index.md        # Index → links to laravel/docs/api/API_REFERENCE.md
│
├── security/                         # Security & compliance
│   ├── auth-and-sessions.md          # Login, legacy session bridge, remember-me
│   ├── rbac-roles-permissions.md     # Roles config, menus, EnsureRole, menu permissions
│   ├── credential-versioning.md      # credential_version, force-logout, lockout
│   ├── password-policy.md            # PasswordPolicy service rules
│   ├── audit-trails.md               # Global audit, user audit, master-data audit trait
│   ├── system-policy-compliance.md   # Investigation mode, SystemPolicy, gate
│   ├── branch-context-security.md    # SetAppBranchId, EnforceBranchIsolation, API context
│   └── api-security.md               # ApiAuth, ApiRateLimit, token scopes
│
├── workflows/                        # End-to-end business workflows (cross-module)
│   ├── procure-to-pay.md             # PO → Receive → AP → Supplier payment
│   ├── order-to-cash.md              # Cart → Invoice → Challan → Customer payment
│   ├── inventory-to-gl.md            # Every stock movement → journal posting mapping
│   ├── period-close-workflow.md      # Month/year-end close sequence
│   ├── approval-workflow.md          # Approval engine (multi-step)
│   └── notification-workflow.md      # Event → rule → recipient → fan-out
│
├── coding/                           # Engineering standards
│   ├── coding-standards.md           # PSR-12, typing, naming, folder conventions
│   ├── service-layer-conventions.md  # One service per operation, no logic in controllers
│   ├── model-conventions.md          # Scopes, traits, casts, relationships
│   ├── request-validation.md         # Form requests, web vs API
│   ├── testing-standards.md          # Unit vs Feature, helpers, factories
│   ├── config-driven-rules.md        # config/*.php as rule source
│   └── error-handling.md             # Exceptions, custom exception classes
│
├── deployment/                       # Ops & deployment
│   ├── environment.md                # .env vars, app/DB/Redis/archive config
│   ├── docker-setup.md               # docker-compose, containers, networking
│   ├── vps-bdix-deployment.md        # Phase 1 VPS provisioning (pending)
│   ├── nginx-config.md               # Nginx config reference
│   ├── artisan-commands.md           # All console commands (verify/migrate/cron)
│   ├── cron-scheduled-jobs.md        # pg_cron + Laravel scheduler
│   └── go-live-checklist.md          # Pre-go-live verification commands
│
├── archive/                          # Legacy anti-corruption layer
│   ├── legacy-overview.md            # Custom PHP/MySQL origin, what was replaced
│   ├── anti-corruption-layer.md      # Archive DTOs + Repository + Service
│   └── legacy-read-only.md           # MySQL archive, read-only enforcement
│
└── changelog/
    ├── CHANGELOG.md                  # Docs change log (this knowledge base)
    └── PRODUCT_CHANGELOG.md          # ERP product/feature change log
```

### 3.1 Design notes on the structure

- **Domain-first, not layer-first.** A reader looking up "sales return" finds everything
  in `sales/`, instead of hunting across architecture/database/api folders.
- **`workflows/` is deliberately cross-cutting** — it stitches modules together so an AI
  can follow a full business process (e.g. order-to-cash) end to end.
- **`manufacturing/` is a placeholder** — the ERP currently has no manufacturing module;
  the folder documents that gap explicitly so an AI does not hallucinate one.
- **`changelog/` is split** — documentation changes vs product changes, because they move
  at different speeds and have different audiences.
- The structure **may evolve**; any structural change MUST be reflected here in §3 and
  recorded in `changelog/CHANGELOG.md`.

---

## 4. Documentation Phases

Phases are executed **sequentially**, one at a time. Each phase lists: Objective, Files to
create, Expected output, Dependencies, Estimated complexity (S/M/L/XL).

> Complexity is relative to *documentation effort* (reading code + writing), not code size.
> **S** ≤ 1 file · **M** 2–4 files · **L** 5–9 files · **XL** 10+ files or safety-critical.

### Phase 0 — Foundation & Entry Points

- **Objective:** Establish the knowledge base skeleton, entry points, and shared glossary
  so every later phase can link to stable anchors.
- **Files to create:**
  - `README.md` (how to use `AI_CONTEXT/`)
  - `PROJECT_OVERVIEW.md` (what the ERP is, 4 principles, tech stack, scope, modules list)
  - `GLOSSARY.md` (business + technical terms)
  - `changelog/CHANGELOG.md` (initialized)
- **Expected output:** A reader can open `README.md` and understand the project's purpose,
  principles, and where to go next.
- **Dependencies:** This `IMPLEMENTATION_PLAN.md` (done) + repo `README.md`.
- **Complexity:** M

### Phase 1 — Architecture

- **Objective:** Document the system architecture, layers, module map, branch isolation,
  realtime events, and partitioning/archival design.
- **Files to create:** `architecture/high-level-architecture.md`,
  `architecture/layered-design.md`, `architecture/module-map.md`,
  `architecture/branch-isolation-rls.md`, `architecture/realtime-events.md`,
  `architecture/partitioning-archival.md`.
- **Expected output:** Mermaid diagrams of layers + module map + request lifecycle; RLS
  isolation explained; Listen/Notify + SSE flow; partition strategy summary.
- **Dependencies:** Phase 0.
- **Complexity:** L

### Phase 2 — Business Domain

- **Objective:** Capture the non-technical business model, org structure, core value
  chain, and cross-cutting business rules.
- **Files to create:** `business/business-model.md`,
  `business/organizational-structure.md`, `business/core-workflows.md`,
  `business/business-rules-catalog.md`.
- **Expected output:** Plain-English description of what Remote Center does, branches/
  warehouses/roles, the buy→stock→sell→collect chain, and rules not owned by one module.
- **Dependencies:** Phase 0.
- **Complexity:** M

### Phase 3 — Database Design

- **Objective:** Document the PostgreSQL schema (66 tables + 7 MVs), triggers, RLS,
  partitioning, and the legacy ETL.
- **Files to create:** `database/schema-overview.md`, `database/er-diagrams.md`,
  `database/migrations-conventions.md`, `database/triggers-views-constraints.md`,
  `database/partitioning.md`, `database/etl-legacy-migration.md`.
- **Expected output:** Per-domain ER diagrams; trigger catalog (balanced journal, sync,
  audit); partition scheme; migration conventions; ETL pipeline.
- **Dependencies:** Phase 1.
- **Complexity:** XL

### Phase 4 — Coding Standards & Conventions

- **Objective:** Codify engineering standards so AI-generated code matches the codebase.
- **Files to create:** `coding/coding-standards.md`,
  `coding/service-layer-conventions.md`, `coding/model-conventions.md`,
  `coding/request-validation.md`, `coding/testing-standards.md`,
  `coding/config-driven-rules.md`, `coding/error-handling.md`.
- **Expected output:** Concrete rules with code snippets; references to real files as
  exemplars; do/don't lists.
- **Dependencies:** Phase 1.
- **Complexity:** L

### Phase 5 — Security, Auth & RBAC

- **Objective:** Document authentication, sessions, RBAC, credential versioning, password
  policy, audit trails, system policy/compliance, branch context, and API security.
- **Files to create:** `security/auth-and-sessions.md`, `security/rbac-roles-permissions.md`,
  `security/credential-versioning.md`, `security/password-policy.md`,
  `security/audit-trails.md`, `security/system-policy-compliance.md`,
  `security/branch-context-security.md`, `security/api-security.md`.
- **Expected output:** Login flow diagram; role/menu matrix; credential-bump flow; audit
  trait behavior; investigation mode; RLS + middleware chain.
- **Dependencies:** Phase 1, Phase 4.
- **Complexity:** L

### Phase 6 — Accounting Engine (SAFETY-CRITICAL)

- **Objective:** Document the Chart of Accounts, ledger natures, ~40 journal posting
  methods, sub-ledger reconciliation, reversal rules, fiscal year/period close, running
  balance, and the financial audit log. This is the highest-risk phase.
- **Files to create:** `accounting/chart-of-accounts.md`,
  `accounting/journal-posting-rules.md`, `accounting/subledger-reconciliation.md`,
  `accounting/reversal-vs-cancellation.md`, `accounting/fiscal-year-period-close.md`,
  `accounting/running-balance.md`, `accounting/financial-audit-log.md`.
- **Expected output:** Consolidated posting-rules matrix (superseding but linking
  `docs/migration/journal_posting_rules.md`); ledger-nature table; reversal state
  diagrams; period-close sequence.
- **Dependencies:** Phase 3 (schema), Phase 4 (conventions). **MUST be reviewed by an
  accountant before marking Canonical.**
- **Complexity:** XL

### Phase 7 — Accounting Transactions

- **Objective:** Document the transactional accounting operations (money transfers,
  employee/supplier/customer transactions, other income/expense, manual journals, bank
  reconciliation).
- **Files to create:** `accounting/money-transfers.md`,
  `accounting/employee-transactions.md`, `accounting/supplier-transactions.md`,
  `accounting/customer-payments.md`, `accounting/other-income-expense.md`,
  `accounting/manual-journals.md`, `accounting/bank-reconciliation.md`.
- **Expected output:** Per-transaction: trigger, posting entries (Dr/Cr), reversal, edge
  cases, related services/policies/requests.
- **Dependencies:** Phase 6.
- **Complexity:** XL

### Phase 8 — Inventory

- **Objective:** Document stock costing (moving-average), the stock ledger, warehouse
  stock, stock take, stock adjustment, damage, warehouse transfers, UoM conversion, and
  stock verification.
- **Files to create:** `inventory/stock-costing.md`, `inventory/stock-ledger.md`,
  `inventory/warehouse-stock.md`, `inventory/stock-take.md`,
  `inventory/stock-adjustment.md`, `inventory/damage.md`,
  `inventory/warehouse-transfer.md`, `inventory/uom-conversion.md`,
  `inventory/stock-verification.md`.
- **Expected output:** Moving-average cost derivation (linking
  `docs/migration/avg_cost_rule.md`); stock_transaction reference-type matrix; stock-take
  state machine; damage workflow; transfer intercompany postings.
- **Dependencies:** Phase 3, Phase 6 (postings).
- **Complexity:** XL

### Phase 9 — Purchasing (Procure-to-Pay)

- **Objective:** Document purchase orders, receives (GRN), returns, and purchase audit.
- **Files to create:** `purchasing/purchase-order.md`, `purchasing/purchase-receive.md`,
  `purchasing/purchase-return.md`, `purchasing/purchase-audit.md`.
- **Expected output:** PO lifecycle states; GRN → stock-in + AP posting; return reversal;
  audit checklist.
- **Dependencies:** Phase 6, Phase 8.
- **Complexity:** L

### Phase 10 — Sales (Order-to-Cash)

- **Objective:** Document sales overview, invoice, challan, cart, return, commission,
  transport cost, and sales audit.
- **Files to create:** `sales/sales-overview.md`, `sales/sales-invoice.md`,
  `sales/sales-challan.md`, `sales/sales-cart.md`, `sales/sales-return.md`,
  `sales/commission.md`, `sales/transport-cost.md`, `sales/sales-audit.md`.
- **Expected output:** Invoice-vs-challan-vs-cart flow; finalize posting (revenue+AR+COGS);
  return reversal guard; commission rule engine; transport handling.
- **Dependencies:** Phase 6, Phase 8.
- **Complexity:** XL

### Phase 11 — Fixed Assets

- **Objective:** Document the fixed asset register, depreciation schedules, and disposals.
- **Files to create:** `finance/fixed-assets.md`.
- **Expected output:** Asset lifecycle; depreciation method + posting; disposal posting;
  related `DepreciationService`, `AssetDisposalService`.
- **Dependencies:** Phase 6.
- **Complexity:** M

### Phase 12 — Budgeting, Dimensions & Cost Centers

- **Objective:** Document budgets, budget lines, variance, analytical dimensions, and cost
  centers.
- **Files to create:** `finance/budgeting.md`, `finance/dimensions-cost-centers.md`.
- **Expected output:** Budget model; variance reporting; dimension tagging on postings.
- **Dependencies:** Phase 6.
- **Complexity:** M

### Phase 13 — Consolidation, Intercompany & Branch Demand

- **Objective:** Document multi-branch consolidation, intercompany (Due-to/Due-from
  Branch), and the inter-branch demand/requisition + settlement system.
- **Files to create:** `finance/consolidation-intercompany.md`,
  `finance/branch-demand.md`.
- **Expected output:** Consolidation run model; intercompany posting pairs; branch-demand
  workflow + money-transfer/customer-payment settlements; shadow mode.
- **Dependencies:** Phase 6, Phase 8, Phase 10.
- **Complexity:** L

### Phase 14 — Approval Workflow & Compliance

- **Objective:** Document the multi-step approval engine and the system policy /
  investigation-mode compliance framework.
- **Files to create:** `workflows/approval-workflow.md`,
  `security/system-policy-compliance.md` (cross-linked from Phase 5).
- **Expected output:** Approval workflow/steps/actions model; policy gate; investigation
  mode behavior; SystemPolicyService.
- **Dependencies:** Phase 5.
- **Complexity:** M

### Phase 15 — Notifications & Realtime

- **Objective:** Document Laravel-native notifications, notification rules/recipients,
  Listen/Notify fan-out, and SSE.
- **Files to create:** `architecture/realtime-events.md` (from Phase 1, expanded),
  `workflows/notification-workflow.md`.
- **Expected output:** Event → rule → recipient resolution → fan-out; ERPNotification
  model; ListenNotifyService + SSE controller; removed Telegram/FCM rationale.
- **Dependencies:** Phase 1.
- **Complexity:** M

### Phase 16 — Reporting & Exports

- **Objective:** Document the reports catalog, materialized views, CTE reports, CSV
  export, and dashboards.
- **Files to create:** `reports/reports-catalog.md`, `reports/materialized-views.md`,
  `reports/cte-reports.md`, `reports/csv-export.md`, `reports/dashboards.md`.
- **Expected output:** ReportsCatalog helper inventory; MV refresh strategy
  (`refresh:report-views`); CTE report patterns; dashboard data sources.
- **Dependencies:** Phase 3.
- **Complexity:** L

### Phase 17 — API Layer (REST v1)

- **Objective:** Document the REST API: overview, conventions, per-module endpoints.
- **Files to create:** `api/api-overview.md`, `api/api-conventions.md`,
  `api/api-modules.md`, `api/api-reference-index.md`.
- **Expected output:** Versioning + Sanctum token auth + rate limits; JSON shape/error
  contract; endpoint table per module; index into `laravel/docs/api/API_REFERENCE.md`.
- **Dependencies:** Phase 5, Phase 4.
- **Complexity:** L

### Phase 18 — Archive & Legacy Anti-Corruption Layer

- **Objective:** Document the legacy PHP/MySQL origin, the anti-corruption layer
  (DTOs/Repository/Service), and the read-only MySQL archive.
- **Files to create:** `archive/legacy-overview.md`, `archive/anti-corruption-layer.md`,
  `archive/legacy-read-only.md`.
- **Expected output:** What was replaced vs retained; ACL boundary; read-only enforcement
  plan; `config/archive.php`.
- **Dependencies:** Phase 1, Phase 3.
- **Complexity:** M

### Phase 19 — Deployment, DevOps & Partitioning/Archival Ops

- **Objective:** Document environment config, Docker, VPS BDIX deployment (pending),
  Nginx, artisan commands, cron jobs, and the go-live checklist.
- **Files to create:** `deployment/environment.md`, `deployment/docker-setup.md`,
  `deployment/vps-bdix-deployment.md`, `deployment/nginx-config.md`,
  `deployment/artisan-commands.md`, `deployment/cron-scheduled-jobs.md`,
  `deployment/go-live-checklist.md`.
- **Expected output:** .env reference; container topology; command catalog; scheduler +
  pg_cron jobs; go-live verification sequence.
- **Dependencies:** Phase 1, Phase 3.
- **Complexity:** L

### Phase 20 — Cross-Cutting Workflows

- **Objective:** Stitch modules into end-to-end workflows so an AI can follow a full
  business process.
- **Files to create:** `workflows/procure-to-pay.md`, `workflows/order-to-cash.md`,
  `workflows/inventory-to-gl.md`, `workflows/period-close-workflow.md`,
  `workflows/notification-workflow.md` (from Phase 15).
- **Expected output:** Sequence diagrams spanning controllers→services→models→DB→triggers
  for each end-to-end flow, with all journal postings inline.
- **Dependencies:** Phases 6–13.
- **Complexity:** XL

### Phase 21 — Changelog, Known Limitations & Roadmap

- **Objective:** Capture the product changelog, known limitations, removed features, and
  the future roadmap (incl. pending Phase 13 AI Sidecar).
- **Files to create:** `changelog/PRODUCT_CHANGELOG.md`,
  `PROJECT_OVERVIEW.md` (append Known Limitations + Roadmap sections), and a new
  `ROADMAP.md` at root.
- **Expected output:** Consolidated product history; explicit limitations; roadmap
  (VPS provisioning, AI sidecar, demand forecasting, invoice OCR, anomaly detection).
- **Dependencies:** All prior phases.
- **Complexity:** M

### Phase summary table

| Phase | Name | Complexity | Safety-critical |
|---|---|---|---|
| 0 | Foundation & Entry Points | M | No |
| 1 | Architecture | L | No |
| 2 | Business Domain | M | No |
| 3 | Database Design | XL | No |
| 4 | Coding Standards & Conventions | L | No |
| 5 | Security, Auth & RBAC | L | Yes |
| 6 | Accounting Engine | XL | **Yes** |
| 7 | Accounting Transactions | XL | **Yes** |
| 8 | Inventory | XL | Yes |
| 9 | Purchasing | L | Yes |
| 10 | Sales | XL | Yes |
| 11 | Fixed Assets | M | Yes |
| 12 | Budgeting, Dimensions & Cost Centers | M | No |
| 13 | Consolidation, Intercompany & Branch Demand | L | Yes |
| 14 | Approval Workflow & Compliance | M | No |
| 15 | Notifications & Realtime | M | No |
| 16 | Reporting & Exports | L | No |
| 17 | API Layer (REST v1) | L | No |
| 18 | Archive & Legacy Anti-Corruption Layer | M | No |
| 19 | Deployment, DevOps & Partitioning/Archival Ops | L | No |
| 20 | Cross-Cutting Workflows | XL | Yes |
| 21 | Changelog, Known Limitations & Roadmap | M | No |

---

## 5. Progress Tracker

Update this checklist as each phase completes. States: `[ ]` Not started · `[~]` In
progress · `[x]` Complete.

```
Phase 0  — Foundation & Entry Points                    [x] Complete
Phase 1  — Architecture                                 [x] Complete
Phase 2  — Business Domain                              [x] Complete
Phase 3  — Database Design                              [x] Complete
Phase 4  — Coding Standards & Conventions               [x] Complete
Phase 5  — Security, Auth & RBAC                        [x] Complete
Phase 6  — Accounting Engine (SAFETY-CRITICAL)          [x] Complete
Phase 7  — Accounting Transactions                      [ ] Not Started
Phase 8  — Inventory                                    [ ] Not Started
Phase 9  — Purchasing (Procure-to-Pay)                  [ ] Not Started
Phase 10 — Sales (Order-to-Cash)                        [ ] Not Started
Phase 11 — Fixed Assets                                 [ ] Not Started
Phase 12 — Budgeting, Dimensions & Cost Centers         [ ] Not Started
Phase 13 — Consolidation, Intercompany & Branch Demand  [ ] Not Started
Phase 14 — Approval Workflow & Compliance               [ ] Not Started
Phase 15 — Notifications & Realtime                     [ ] Not Started
Phase 16 — Reporting & Exports                          [ ] Not Started
Phase 17 — API Layer (REST v1)                          [ ] Not Started
Phase 18 — Archive & Legacy Anti-Corruption Layer       [ ] Not Started
Phase 19 — Deployment, DevOps & Partitioning/Archival   [ ] Not Started
Phase 20 — Cross-Cutting Workflows                      [ ] Not Started
Phase 21 — Changelog, Known Limitations & Roadmap       [ ] Not Started
```

### Review gates

- **Accounting gate (Phase 6):** MUST be reviewed + signed off by the accountant before
  status flips to Canonical (mirrors the existing `journal_posting_rules.md` sign-off rule).
- **Security gate (Phase 5):** MUST be reviewed by whoever owns production credentials.
- **Final gate (Phase 21):** A full read-through by a human maintainer to confirm
  cross-references resolve and no contradictions remain.

---

## 6. Documentation Rules (Mandatory Content per File)

Every module/rule file in `AI_CONTEXT/` MUST answer the following questions, each as a
`##` section. Omit a section only if genuinely N/A, and write "N/A — <reason>".

1. **What is it?** — One-paragraph plain-English definition.
2. **Why does it exist?** — The business/technical motivation.
3. **When is it used?** — Trigger conditions, lifecycle stage, frequency.
4. **Who uses it?** — Roles (superadmin, accountant, branch manager, salesperson,
   warehouse staff, API consumer, system/automated job).
5. **Related modules** — Links to sibling `AI_CONTEXT/` files.
6. **Business rules** — The non-negotiable rules (e.g. "Dr = Cr", "reversals never
   mutate", "stock valued at moving-average cost"). Use MUST/MUST NOT.
7. **Technical implementation** — Service(s), controller(s), model(s), middleware,
   trigger(s), config file(s) involved. Link real file paths.
8. **Important database tables** — Table name + purpose + key columns. Link the ER diagram.
9. **Related services** — The service classes that own the logic.
10. **Related models** — The Eloquent models.
11. **Important workflows** — Step-by-step or Mermaid sequence/flowchart.
12. **Known edge cases** — Concurrency, reversals, partials, cross-branch, period-closed,
    negative stock guards, etc.
13. **Future improvements** — Planned enhancements or known tech debt.

Additionally, where relevant, include:
- **Posting entries** (accounting/inventory files): a Dr/Cr table per operation.
- **State machine** (lifecycle files): a Mermaid stateDiagram.
- **Role-permission matrix** (security/sales/inventory files): a table.
- **Verification commands** (operational files): the artisan command(s) that validate this
  area (e.g. `php artisan journal:replay-verify`).

### Quality bar

- A new senior developer should be able to implement a change in this area **using only
  this file + the linked source files**, without asking a human.
- Every business rule must be traceable to code (cite the file) or to an external
  accounting/first-principles source (cite the doc).
- No shallow summaries. If a section would be one sentence, it is incomplete — investigate
  deeper or mark it "Draft — needs investigation" and log it in `changelog/CHANGELOG.md`.

---

## 7. AI Instructions

These rules govern how **any AI assistant** (including future instances of the assistant
building this knowledge base) MUST behave when working on this ERP.

### 7.1 Reading & trust

- **Read `AI_CONTEXT/` before acting.** Start at `README.md` → `PROJECT_OVERVIEW.md` →
  the relevant module folder.
- **Trust the docs over your assumptions**, especially for accounting and inventory. The
  docs encode first-principles derivations and accountant sign-off.
- **If code and docs disagree**, do not silently pick code. Flag the discrepancy, prefer
  the documented business rule for safety-critical areas, and request a human decision.

### 7.2 Business-logic integrity

- **Never assume undocumented business logic.** If a rule is not in `AI_CONTEXT/` and not
  derivable from first principles + code, STOP and ask a human. Then document the answer.
- **Preserve accounting integrity at all times.** Every journal entry MUST balance
  (Dr=Cr), reference an active ledger, fall in an open period, and be reversible — never
  mutated. See `accounting/journal-posting-rules.md`.
- **Preserve audit trails.** Never delete or overwrite audited rows. Use the audit trait
  and append-only audit logs. Reversals create new entries; they do not edit originals.
- **Never bypass services.** Controllers MUST stay thin. All business logic lives in
  `app/Services/*`. Do not post journals, move stock, or mutate ledgers directly from
  controllers, jobs, or Blade. Always go through the owning service.
- **Respect reversal-over-deletion.** To undo a posted transaction, create a reversal —
  do not hard-delete or flip status flags on the original.

### 7.3 Architecture discipline

- **Prefer existing architecture over introducing new patterns.** Reuse the service layer,
  form requests, policies, scopes, traits, and config-driven rules. Do not import new
  patterns (repositories, CQRS, event sourcing) without explicit human approval.
- **Keep the existing UI.** Blade views reproduce legacy markup; no SPA rewrite (project
  principle #3). Match the Bootstrap-based `rc-erp.css` styling.
- **Re-derive, don't copy-paste.** When implementing new logic, derive it from first
  principles and the documented rules — do not copy legacy PHP verbatim (project
  principle #4).

### 7.4 Security & isolation

- **Respect branch isolation.** Every multi-tenant query is scoped by `branch_id` via RLS
  + global scopes + middleware. Never write `Model::all()` or unscoped cross-branch queries.
- **Respect RBAC.** Check roles via `EnsureRole` / policies / menu permissions. Never
  expose admin-only actions to lower roles.
- **Respect credential versioning.** Changing a user's password/auth state MUST bump
  `credential_version` to invalidate stale sessions.
- **Never log secrets.** Do not write tokens, password hashes, or PII to logs/docs.

### 7.5 Documentation hygiene (living system)

- **Document newly discovered business rules.** If you find a rule by reading code that
  isn't in `AI_CONTEXT/`, add it to the right file and log it in `changelog/CHANGELOG.md`.
- **Update docs on significant code change.** Any change to a service's public behavior,
  a posting method, a state machine, a table schema, or a security rule MUST trigger a
  doc update in the same task.
- **Update cross-references.** When renaming/moving a file, fix every link to it.
- **Update the progress tracker** (§5) when a phase completes.
- **Keep `AI_CONTEXT/` synchronized with the codebase.** It must always represent the
  current state of the ERP. Stale docs are worse than no docs.

### 7.6 Scope discipline

- **One phase at a time.** Do not start Phase N+1 until Phase N is reviewed.
- **Do not document out-of-scope artifacts.** The repo contains a top-level `skills/`
  folder of unrelated AI tooling and a `legacy/` PHP codebase used only during transition.
  These are referenced where relevant (archive/legacy) but are **not** in-scope for
  module documentation.
- **No test code in docs.** Reference test files for behavior examples, but do not paste
  test suites into documentation.

---

## 8. Execution Checklist for Each Phase

When a phase is commissioned, the executing agent MUST:

1. Read this `IMPLEMENTATION_PLAN.md` (especially the phase's entry in §4 and §6 rules).
2. Read prior phases' outputs (per Dependencies) in `AI_CONTEXT/`.
3. Read the **existing** related docs under `docs/` and `docs/migration/` (consolidate,
  don't duplicate).
4. Read the actual code: services, models, controllers, migrations, requests, policies,
   jobs, events, observers, triggers (raw SQL in `database/sql/`), tests, config.
5. Write each file per §2 standards and §6 mandatory sections.
6. Add cross-references (relative links) to related `AI_CONTEXT/` files and source files.
7. Append a `changelog/CHANGELOG.md` entry per file created/updated.
8. Update §5 Progress Tracker (flip the phase to `[~]` while working, `[x]` on completion).
9. Stop and wait for review before the next phase.

---

*End of IMPLEMENTATION_PLAN.md. The next action is to commission **Phase 0 — Foundation &
Entry Points**. Do not begin Phase 0 until explicitly instructed.*
