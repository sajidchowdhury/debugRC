# AI Context System — README

> **Module:** Meta / Entry point
> **Audience:** Engineers, AI assistants, accountants, new contributors
> **Status:** Canonical
> **Last reviewed:** Initial creation (Phase 0)
> **Source of truth:** This file is the entry point for the `AI_CONTEXT/` knowledge base.

---

## What this is

`AI_CONTEXT/` is the **single source of truth** for understanding the RC_ERP_v2
(Remote Center ERP) Laravel application. It is a structured, living knowledge base written
for **both humans and AI assistants**.

The goal: any new AI assistant (or senior engineer) can read this folder and understand the
ERP's business logic, architecture, workflows, accounting rules, inventory logic, security
model, API conventions, and implementation decisions — **without requiring a human to
re-explain anything**.

---

## Scope — what IS and IS NOT documented here

### In scope (documented)

- **The Laravel application only**, located at `laravel/` in the repository.
- Everything under `laravel/app`, `laravel/config`, `laravel/routes`, `laravel/database`,
  `laravel/resources/views`, `laravel/tests`, `laravel/public/assets`.
- The PostgreSQL schema that backs the Laravel app (`laravel/database/sql/`,
  `laravel/database/migrations/`).
- The REST API v1 (`laravel/app/Http/Controllers/Api/V1/`, `laravel/routes/api.php`).
- Deployment and operational concerns **of the Laravel app**.

### Out of scope (NOT documented here)

These exist in the repository but are **deliberately excluded** from this knowledge base:

| Path | Why excluded |
|---|---|
| `legacy/` | The old custom PHP/MySQL codebase used only during the transition. It is read-only history. Referenced from `archive/` docs where its existence matters, but not documented as a module. |
| `skills/` | A collection of unrelated third-party "ClawHub" AI skills shipped alongside the repo. Not part of the ERP. |
| `mysql_archive/` | Legacy MySQL archive container data. Operational only; covered briefly under `archive/` + `deployment/`. |
| `postgres/`, `docker/` (top-level) | Container/infra config — covered operationally under `deployment/`, not as modules. |
| `debugRC/` (inner) | A leftover nested folder; not part of the application. |
| `download/`, `scripts/` (top-level) | Misc helper scripts; not ERP modules. |

> **Rule for AI assistants:** When asked about a feature, first confirm it lives in the
> Laravel app. If it is in an out-of-scope folder, say so and redirect to the Laravel
> equivalent.

---

## How to use this knowledge base

### If you are an AI assistant

1. **Start here** (`README.md`), then read `PROJECT_OVERVIEW.md`, then `GLOSSARY.md` for
   terminology.
2. Read `IMPLEMENTATION_PLAN.md` to see which areas are already documented (check the
   Progress Tracker, §5) and which are pending.
3. Navigate to the module folder relevant to your task (see the map below).
4. **Obey `IMPLEMENTATION_PLAN.md` §7 (AI Instructions)** — especially: never assume
   undocumented business logic, preserve accounting integrity, never bypass services,
   respect branch isolation, and update docs when code changes.

### If you are a human

- **New to the project?** Read `PROJECT_OVERVIEW.md` → `architecture/` → the module folder
  for your task.
- **Looking for a specific term?** Use `GLOSSARY.md`.
- **Making a change?** Read the relevant module doc, follow `coding/` standards, and update
  the doc + `changelog/CHANGELOG.md` in the same task.

---

## Folder map

```
AI_CONTEXT/
├── README.md                  ← YOU ARE HERE
├── IMPLEMENTATION_PLAN.md     ← Master roadmap + standards + AI rules
├── PROJECT_OVERVIEW.md        ← What the ERP is, principles, tech stack, modules
├── GLOSSARY.md                ← Business + technical terms
│
├── architecture/              ← (Phase 1) Layers, RLS, realtime, partitioning
├── business/                  ← (Phase 2) Business model, org structure, workflows
├── database/                  ← (Phase 3) Schema, ER, triggers, partitioning, ETL
├── coding/                    ← (Phase 4) Engineering standards
├── security/                  ← (Phase 5) Auth, RBAC, audit, compliance
├── accounting/                ← (Phase 6–7) Chart of accounts, posting rules, recon  [SAFETY-CRITICAL]
├── inventory/                 ← (Phase 8) Stock costing, ledger, stock-take, damage
├── purchasing/                ← (Phase 9) PO, receive, return
├── sales/                     ← (Phase 10) Invoice, challan, cart, return, commission
├── finance/                   ← (Phase 11–13) Fixed assets, budgets, consolidation
├── workflows/                 ← (Phase 14–15, 20) End-to-end + approval + notifications
├── reports/                   ← (Phase 16) Reports catalog, MVs, dashboards
├── api/                       ← (Phase 17) REST v1
├── archive/                   ← (Phase 18) Legacy anti-corruption layer
├── deployment/                ← (Phase 19) Env, Docker, VPS, commands, cron
└── changelog/
    └── CHANGELOG.md           ← Documentation change log
```

> Folders marked `(Phase N)` are created lazily as their phase is commissioned. Most do not
> exist yet. See `IMPLEMENTATION_PLAN.md` §5 for the live status.

---

## Current status

- **Phase 0 — Foundation & Entry Points:** ✅ Complete (this file, `PROJECT_OVERVIEW.md`,
  `GLOSSARY.md`, `changelog/CHANGELOG.md`, and the `IMPLEMENTATION_PLAN.md` roadmap).
- **Phase 1 — Architecture:** ✅ Complete (`architecture/` — high-level-architecture,
  layered-design, module-map, branch-isolation-rls, realtime-events, partitioning-archival).
- **Phase 2 — Business Domain:** ✅ Complete (`business/` — business-model,
  organizational-structure, core-workflows, business-rules-catalog).
- **Phase 3 — Database Design:** ✅ Complete (`database/` — schema-overview, er-diagrams,
  migrations-conventions, triggers-views-constraints, partitioning, etl-legacy-migration).
- **Phase 4 — Coding Standards & Conventions:** ✅ Complete (`coding/` — coding-standards,
  service-layer-conventions, model-conventions, request-validation, testing-standards,
  config-driven-rules, error-handling).
- **Phase 5 — Security, Auth & RBAC:** ✅ Complete (`security/` — auth-and-sessions,
  rbac-roles-permissions, credential-versioning, password-policy, audit-trails,
  system-policy-compliance, branch-context-security, api-security).
  ⚠️ Safety-critical — pending review by the production-credential owner (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 6 — Accounting Engine:** ✅ Complete (`accounting/` — chart-of-accounts,
  journal-posting-rules, subledger-reconciliation, reversal-vs-cancellation,
  fiscal-year-period-close, running-balance, financial-audit-log).
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates). `journal-posting-rules.md` supersedes
  `docs/migration/journal_posting_rules.md`.
- **Phase 7 — Accounting Transactions:** ✅ Complete (`accounting/` — money-transfers,
  employee-transactions, supplier-transactions, customer-payments, other-income-expense,
  manual-journals, bank-reconciliation). Per-transaction: lifecycle, Dr/Cr matrix, reversal
  cascade, validation, edge cases, accountant review checklist. Documents 14 gaps including
  critical bank-reconciliation issues (admin-only RLS, non-posting adjustment JE, latent
  `reverse()` crash) and the customer-payment intercompany dead-code regression.
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 8 — Inventory:** ✅ Complete (`inventory/` — stock-costing, stock-ledger,
  warehouse-stock, stock-take, stock-adjustment, damage, warehouse-transfer, uom-conversion,
  stock-verification). Moving-average cost derivation (linking `docs/migration/avg_cost_rule.md`);
  `stock_transactions` reference_type matrix (11 DB-CHECK + 3 app-only `demand_*` values);
  per-warehouse avg_cost granularity; `StockService::applyTransaction()` canonical entry point;
  `warehouse_stock` derived snapshot (SSOT = ledger); 7-state stock-take machine + freeze
  mechanism; 6-state stock-adjustment + damage machines with maker-checker; same-branch-only
  warehouse transfers (dead `postIntercompanyGL`); UoM conversion (Phase 5, only stock-adjustment
  has UOM columns); distributed stock verification (Stock Take + Reconciliation + console
  commands — no single `StockVerification*` feature). Documents 16 gaps including the
  `inventory_revaluation` unregistered nature, stale `reference_type` DB CHECK, dead
  `postIntercompanyGL`, no RLS on `warehouse_stock`/`stock_transactions`, and
  `AuditableMasterData` trait dead for StockAdjustment/StockTake services.
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 9 — Purchasing (Procure-to-Pay):** ✅ Complete (`purchasing/` — purchase-order,
  purchase-receive, purchase-return, purchase-audit). PO is a draft document (NO stock/GL);
  GRN is the economic event (atomic stock IN + Dr `inventory` / Cr `ap` + `supplier_ledger`
  credit + PO `received_qty` auto-flip); return reverses at ORIGINAL receive rate (not current
  `avg_cost`); Phase 5 `Good`/`Damage` condition (Damage skips stock movement, still posts GL);
  BUG-5 active-returns guard blocks GRN cancel; 3-layer audit infrastructure (hash-chain
  `financial_audit_log` PARTIAL coverage — only `supplier_payments`; `user_audit_log` FULL via
  `UserAuditLogger` but `AuditableMasterData` trait BYPASSED by `DB::table()` writes; 12-section
  `PurchaseAuditService` health-check on-demand only). Documents 18 gaps including 4 CRITICAL:
  `purchase_receives.paid_amount` column missing (breaks `SupplierTransactionService::allocateToGRN`),
  no `Purchase*Policy` classes, no `fn_financial_audit_trigger` on purchase tables,
  `AuditableMasterData` trait bypassed by raw `DB::table()` writes.
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 10 — Sales (Order-to-Cash):** ✅ Complete (`sales/` — sales-overview, sales-invoice,
  sales-challan, sales-cart, sales-return, commission, transport-cost, sales-audit). Decoupled
  revenue recognition (invoice finalize: Dr AR / Cr Revenue) vs stock movement (challan issue:
  Dr COGS / Cr Inventory at current avg_cost); 3-step godown workflow (blank-godown → godown
  prep → challan issue); Phase-6 transport-edit deferred-GL workflow (snapshot + sub-ledger at
  godown, GL at issue, cascade-restore on cancel); per-user × per-customer × per-branch JSONB
  draft cart (R6 3-col unique key); sales return at ORIGINAL avg_cost (snapshot from challan's
  stock_transactions.rate — preserves cost integrity); Phase 5 Good/Damage condition (Damage
  skips stock, creates linked damage write-offs via `DamageService::confirmDamage(force_confirm=true)`);
  `SalesReturnReversalGuard` stock-shortage pre-check; 4-type commission rule engine
  (flat/tiered/product_group/target_bonus) with GiST EXCLUDE constraint; 3-layer audit
  infrastructure (hash-chain `financial_audit_log` PARTIAL coverage — only `customer_payments`;
  `user_audit_log` FULL via `SalesAuditLogger` but `AuditableMasterData` trait BYPASSED by
  `DB::table()` writes; 3-section `ReportController::computeSalesAuditChecks` on-demand only —
  gap vs `PurchaseAuditService`'s 12 sections). Documents 6 CRITICAL gaps including:
  `customers.shop_name` column MISSING (breaks cart AJAX — runtime SQLSTATE[42703]);
  `SalesInvoiceApiController::update` doesn't pass items[] (mobile API edit broken);
  `StockAvailabilityService` references nonexistent status `'challan_completed'`; DDL
  `04_sales.sql` stale (8+ columns + `sales_challan_items` table exist only in migrations);
  entire commission auto-calc pipeline is DEAD CODE (`calculateOnAllocation`/`reverseOnReturn`/
  `reverseOnPaymentReversal`/`markAsPaid` never called) + `confirmPeriod` calls non-existent
  `postCommissionExpense()` method + `commission_expense`/`commission_payable` natures NOT
  registered.
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 11 — Fixed Assets:** ✅ Complete (`finance/fixed-assets.md` — register, depreciation,
  disposal). 3 tables (`fixed_assets`, `asset_depreciation_schedules`, `asset_disposals`) +
  3 models + 2 services (`DepreciationService` 587L, `AssetDisposalService` 276L) + 1
  controller (12 actions) + 2 migrations. 3 depreciation methods (straight-line monthly
  `(cost−salvage)/months`, declining-balance `NBV×annual_rate/12`, units-of-production
  per-unit); salvage-value floor guard clamps depreciation at `NBV=salvage`; NBV ≤ salvage
  flips status to `fully_depreciated`. Disposal posts Dr acc-dep + Dr cash (proceeds) /
  Cr asset-cost (full) + Cr gain (proceeds>NBV) OR Dr loss (proceeds<NBV); 4 disposal types
  (sale/write_off/scrap/donation) all follow same GL path; gain/loss = proceeds − NBV.
  Period-close enforced indirectly via `JournalPostingService::validatePeriod`; reversals
  bypass period close via `skip_period_check=true`. 9 seeded ledgers (L-0200..L-0250,
  L-0804, L-0903, L-0904) + 4 ledger natures (`accumulated_depreciation`,
  `depreciation_expense`, `gain_on_disposal`, `loss_on_disposal`). Documents 30 gaps
  including 5 CRITICAL: G1 RLS admin-only (entire subsystem non-functional for
  accountants/managers — contradicts route middleware), G7 `fn_financial_audit_trigger`
  NOT attached to any of 3 tables (recurring cross-phase gap), G13 `postDepreciation` NOT
  wrapped in `DB::transaction` (partial-failure window), G15 asset cost/salvage/useful_life
  editable after first depreciation (silent distortion), G25 cross-branch asset creation
  (no `branch_id` access check, `EnforceBranchIsolation` doesn't cover fixed-assets URI).
  Also: no FormRequests (G2), no Policy (G3), no `config/fixed_assets.php` (G4),
  race-prone `disposal_code` generation (G5), no artisan command/scheduled monthly job
  (G8), disposal reversal hard-DELETEs the record breaking append-only audit (G9),
  concurrent schedule generation creates duplicates (G12), disposal of fully-depreciated
  asset generates loss=salvage (G16), disposal reversal does NOT restore force-reversed
  pending schedules (G19), `BranchScope` not applied to child models (G30).
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** before Canonical status (see
  `IMPLEMENTATION_PLAN.md` §5 Review gates).
- **Phase 12 — Budgeting, Dimensions & Cost Centers:** ✅ Complete (`finance/budgeting.md`,
  `finance/dimensions-cost-centers.md`). Budgeting: 4-state lifecycle (draft→active→closed/
  cancelled), spreadsheet grid entry (ledgers × periods), `budget_vs_actual` SQL VIEW with
  LATERAL join on `journal_lines` (variance = budget − actual), analytical-only (NO GL posting).
  Dimensions: 5 type enum (cost_center/profit_center/department/project/location — "cost center"
  is a dimension TYPE, not a separate table), `journal_lines.dimension_value_id` nullable FK
  (plumbed but NOT wired — G4), segment P&L (revenue−contra−COGS−OpEx buckets via
  `ledger_nature`), segment BS (point-in-time cumulative by account_type), orthogonal to ledgers.
  2 migrations create 4 tables + the `dimension_value_id` column + `budget_vs_actual` view +
  RLS on `budgets`/`dimension_values` + seed 3 dimensions + 5 dept values. Documents 58 gaps
  (30 budgeting + 28 dimensions) including 8 CRITICAL: budgeting G1 (free-text `fiscal_year`
  breaks variance for "2026-27" format), G2 (no fiscal-period integration + assumes Jan-Dec),
  G3 (audit trigger NOT attached), G20 (`checkBudgetControl` is DEAD CODE — no caller); dimensions
  G1 (`BranchScope` on `DimensionValue` excludes NULL-branch values — non-admins cannot see
  seeded dept values), G2 (audit trigger NOT attached), G3 (DDL stale — `dimension_value_id`
  not in `02_accounting.sql`), G4 (NO business module passes `dimension_value_id` — segment
  reports always return 0). Also: no FormRequests, no Policies, no per-action role
  differentiation, no MV for segment reporting, `reverseJournalEntry` does NOT propagate
  `dimension_value_id` (dimensions G14 — segment reports double-count reversed postings),
  `manual_journal_lines` has no `dimension_value_id` column (G8), no artisan/scheduler,
  `budgets` RLS has no `WITH CHECK` (G8), `budget_lines` RLS NOT enabled (G4), duplicate-active
  budget check buggy (G5 — allows company-wide + branch-specific to coexist).
  ⚠️ Budgeting: NOT SAFETY-CRITICAL (analytical-only, no GL posting). Dimensions: NOT
  SAFETY-CRITICAL (read-only reporting + master-data CRUD).
- **Phase 13 — Consolidation, Intercompany & Branch Demand:** ✅ Complete
  (`finance/consolidation-intercompany.md`, `finance/branch-demand.md`). Two interlocking
  subsystems. **Consolidation:** 3-state run lifecycle (draft→posted→reversed, NO reopen),
  `EliminationRule` 5-type enum (balance/revenue/investment/dividend/custom), per-branch-pair
  elimination from `branch_ledger` (balance-type) + per-ledger aggregate from `journal_lines`
  (aggregate-type, uses `min(debitNet, creditNet)` to avoid over-elimination — BR14), 5 seeded
  elimination contra ledgers (L-0106, L-0304, L-0403, L-0504, L-0404 all marked
  `is_elimination=true`), `mv_consolidated_trial_balance` MV refreshed on every post/reverse.
  **Intercompany posting pairs:** dual-JE pattern (creditor: Dr `interbranch_receivable` /
  Cr `inventory`; debtor: Dr `inventory` / Cr `interbranch_payable`) + `branch_ledger` mirror
  pair (debtor debit + creditor credit, shared `running_balance`). 6 intercompany posting
  sites catalogued (BranchDemand ✓, FIFO settlement DEAD CODE, MoneyTransfer silently skips
  on unregistered `'intercompany'` nature, SupplierTransaction ✓, EmployeeTransaction ✓,
  WarehouseTransfer DEAD CODE with inverted Dr/Cr). **Branch Demand:** 4-state lifecycle
  (pending→received→reversed|rejected, NO reopen) with Phase 5 receipt gate (reversal blocked
  until `received_at IS NOT NULL`); 7 services (5,652 LOC: `BranchDemandService` 1012L +
  `BranchDemandShadowService` 535L + `BranchDemandRepricingService` 785L +
  `BranchDemandAuditService` 955L + `BranchDemandAuditLogger` 171L +
  `BranchDemandWeeklyReportService` 1076L + `BranchIntercompanyService` 1128L); 5 demand
  tables + `branch_ledger` sub-ledger + `branch_demand_audit_log` (11-value action enum) +
  2 shadow tables; web (28 routes) + API v1 (14 endpoints); repricing posts dual GL adjustment
  pair (positive: Dr receivable/Cr inventory on supplier; negative: Dr inventory/Cr receivable)
  with append-only `branch_demand_repricing` audit row; FIFO settlement (oldest demand first,
  per-demand `settleAmount = min(outstanding, remainingAmount)`, single GL per batch Dr
  `interbranch_payable` / Cr `cash_bank`); shadow mode 3-state (off/passive/active) with
  7-consecutive-zero-diff-day cutover readiness; 3 anti-gaming flags (`catalog_below_locked`,
  `sales_below_cost` — references nonexistent `sales_items` table G17, `stale_outstanding`);
  6-checklist audit + per-demand audit + branch-pair reconciliation; 25-column weekly report
  replicating "MAIN BILL SHIT1.xlsx" Excel sheet. Documents 50 gaps (22 consolidation +
  28 branch-demand) including 13 CRITICAL: consolidation G1 (`ConsolidationService` BYPASSES
  `JournalPostingService` for elimination JE creation + reversal — no Dr=Cr validation,
  no period-close, no `journal_posting_logs`), G2 (FIFO demand-settlement feature is DEAD
  CODE — `settleFromCustomerPayment` / `settleFromMoneyTransfer` / `fifoSettleDemands` have
  NO caller), G3 (RLS admin-only on `consolidation_runs` / `elimination_entries` /
  `elimination_rules` / `companies` — accountants and managers blocked, mirrors Phase 11
  fixed-assets G1), G4 (`fn_financial_audit_trigger` NOT attached to 7 in-scope tables),
  G5 (DDL stale — consolidation tables + `mv_consolidated_trial_balance` missing from
  `database/sql/*.sql`); branch-demand G1 (`CustomerPaymentService::postIntercompanySettlement`
  early-returns null because `banks` has no `branch_id` column), G2
  (`MoneyTransferService::postIntercompanySettlement` uses unregistered `'intercompany'`
  nature + never calls `settleFromMoneyTransfer`), G3 (`shadow_cutover_log` schema mismatch
  — INSERT will fail `SQLSTATE[42703]`), G4 (`BranchDemandShadowService::compareOperation`
  has NO caller — shadow mode plumbed but NOT WIRED), G5 (DDL stale — `branch_demand*` +
  shadow tables missing from `database/sql/*.sql`), G6 (`fn_financial_audit_trigger` NOT
  attached to ANY `branch_demand*` table or `branch_ledger`), G7 (`'branch_demand_created'`
  notification registered but NEVER dispatched — supplier branch not notified of new demands),
  G8 (NO RLS on 5 branch_demand-related tables — cross-branch data leakage risk). Also:
  `WarehouseTransferService::postIntercompanyGL` is DEAD CODE with fossilized bugs (dropped
  `branch_ledger` columns + inverted Dr/Cr — G10 in consolidation); `BranchDemandRepricing`
  stores only `creditor_je_id` not `debtor_je_id` (G12); `BranchDemandAuditService::
  getSalesBelowLockedCost` references nonexistent tables `sales_items`+`sales` (G17);
  weekly report `profit` column excludes demand COGS (G19); `reverseDemand` uses
  `JournalPostingService::reverseJournalEntry` directly, not `JournalReversalService` for
  cascade (G13 — explicit two-step pattern with separate `reverseLedgerByReference`).
  ⚠️ **SAFETY-CRITICAL — pending accountant sign-off** for both files (elimination JEs post
  to GL; intercompany posting pairs affect branch-level TB + `branch_ledger` running balance;
  demand fulfillment moves stock + posts GL; repricing posts GL adjustments). See
  `IMPLEMENTATION_PLAN.md` §5 Review gates.
- **Phase 14 — Approval Workflow & Compliance:** ✅ Complete
  (`workflows/approval-workflow.md` new + `security/system-policy-compliance.md` re-audited +
  expanded from 418→702 lines). **Two parallel, non-intersecting approval patterns** documented.
  **Pattern A — generic configurable engine** (migration `2026_08_10_000001`): 4 tables
  (`approval_workflows` + `approval_steps` + `approval_requests` + `approval_actions`) + 4 models +
  `ApprovalService` 407L crown jewel (9 methods: `getRequiredWorkflow`, `submitForApproval`,
  `approve` with multi-level `current_level` advancement, `reject`, `cancel` DEAD CODE G14,
  `getPendingQueueForUser` with SoD `requested_by != user->id` exclusion, `getApprovalHistory`,
  private `updateEntityStatus` ONLY implements `manual_journal` case, private `notifyApprovers` +
  `notifyRequester` DEAD CODE G4) + `ApprovalController` 124L (5 actions, NO FormRequests G3) +
  2 blade views + seeded default "Manual Journal Approval" workflow with 2 levels (manager L1 +
  admin L2). **Pattern B — entity-specific maker-checker columns** (3 older migrations): each of
  `stock_adjustments` / `stock_take_sessions` / `damage_invoices` has its OWN `submitted_by/at` +
  `approved_by/at` + `approval_comments` columns + expanded `status` CHECK + own service
  `submit()/approve()/reject()` methods + own config layer (`config/stock_adjustment.php` 8 knobs
  file-backed, `stock_take_policies` DB table 4 keys runtime-configurable, `config/damage.php`
  hybrid). SoD enforced at 10 sites (services throw + `DamagePolicy` returns false/403);
  auto-approve shortcuts bypass SoD by design below thresholds (stock adj <1000 Tk, damage <5000 Tk
  by admin/manager, manual journal when no workflow matches). 5 Mermaid state machines
  (`ApprovalRequest` 4-state, `ManualJournal` 6-state, `StockAdjustment` 6-state,
  `StockTakeSession` 7-state, `Damage` 6-state) + 2 sequence diagrams. **system-policy re-audit:**
  26-item verification table (13 CONFIRMED, 4 CHANGED/PARTIAL, 7 NEW); fixed 2 incorrect Phase 5
  claims (§4 "all users see banner" → only admin page G14; §9 "UserAuditLogger invoked" → service
  writes directly via `DB::table` G16); added `§7.10` `validatePeriod` consumer verbatim +
  `§7.11` compliance matrix (`system_policies` fails 2/2 applicable checks: audit trigger G10 +
  RLS G9). **16 approval gaps** (4 CRITICAL: G1 generic engine used by 1 entity only, G2
  `ManualJournalService::postJournal` throws on `approved` status dead-ending the workflow, G4
  notification dispatch dead code — 4 event names not in `NotificationRule::EVENTS`, G7 DDL stale;
  7 HIGH: G3 no FormRequests, G5 no branch.isolation, G6 entity_id not FK, G8 branch_id string
  not integer, G11 no menu entry, G12 no fn_financial_audit_trigger, G15 no RLS; 4 MEDIUM + 1 LOW).
  **16 system-policy gaps** (G1-G8 existing reconfirmed + G9 NO RLS HIGH, G10 no audit trigger
  MEDIUM, G11 DDL stale MEDIUM, G12 PG trigger `rcerp_notify_system_policy` dead in practice
  MEDIUM — fires on `mode` change but service never UPDATEs `mode`, G13 INVESTIGATION mode has NO
  business-logic consumer HIGH — effectively a no-op, G14 no global banner LOW, G15
  `period_close_override` action not in audit-trails.md LOW, G16 writeAuditLog bypasses
  UserAuditLogger LOW). ⚠️ **Business-critical — pending compliance review** (approval gates
  themselves don't post GL but gate entities that do; INVESTIGATION mode is documented as a freeze
  switch but currently does nothing). See `IMPLEMENTATION_PLAN.md` §5 Review gates.
- **Phases 15–21:** Not started. Execute one phase at a time per the roadmap.

---

## Conventions in one paragraph

All files use GitHub-flavored Markdown, UTF-8, LF. Top-level filenames are
`UPPER_SNAKE_CASE.md`; module files are `kebab-case.md`. Every file begins with a header
block (Title + Module + Audience + Status + Last reviewed + Source of truth). Headings go
`#` → `##` → `###` → `####` (never skip). Diagrams use Mermaid. Business explanation
always precedes technical explanation. Rules use MUST / MUST NOT. Full standards are in
`IMPLEMENTATION_PLAN.md` §2; the mandatory content template (13 questions every file must
answer) is in `IMPLEMENTATION_PLAN.md` §6.

---

*For the authoritative rules governing how AI assistants work on this ERP, read
`IMPLEMENTATION_PLAN.md` §7 (AI Instructions).*
