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
- **Phase 15 — Notifications & Realtime:** ✅ Complete
  (`architecture/realtime-events.md` expanded from 365→1197 lines + `workflows/notification-workflow.md`
  NEW 1629 lines). Two interlocking halves of the realtime stack. **Realtime pipeline**
  (`architecture/realtime-events.md`): 3-hop transport — PostgreSQL `LISTEN/NOTIFY` (10 trigger
  functions across 3 migrations calling shared `rcerp_notify()` helper with canonical
  `{table, action, id, branch_id, changes, triggered_at}` payload) → PHP long-running worker
  (`ListenNotifyWorker` 293L, dedicated raw PDO, `pgsqlGetNotify()` non-blocking poll every 100ms,
  60s heartbeat to Redis `rcerp:listen_notify:heartbeat` key TTL 120s, supervised ONLY by
  `docker-compose` `rcerp_listen_notify` container `restart: unless-stopped` — NOT scheduled by
  Laravel cron, NO in-repo supervisor/systemd config) → Redis Lists (`rcerp:sse:global` TTL 600s
  trim 500, `rcerp:sse:branch:{id}` TTL 600s trim 200, `rcerp:sse:user:{id}` DEAD QUEUE G1) +
  Pub/Sub (`rcerp:sse:pubsub:global` fire-and-forget) → SSE controller (`SseController` 312L,
  `/sse/events` text/event-stream polling 3 queues RPOP every 100ms, 30s heartbeat, 300s max
  connection → reconnect, branch filter at L148, `/sse/status` JSON). 10 channels classified into
  3 classes (5 notification-mapped via `CHANNEL_EVENT_MAP` → `forwardToNotificationService`,
  4 SSE-only refresh signals, 1 emit-only `rcerp_notification_dispatched` to prevent infinite loop).
  Client `notification.js` 319L with 11 EventSource listeners + custom Bootstrap toast + 30s AJAX
  polling fallback; page-specific listeners in `damages/index` + `damages/show` blade. 7 Mermaid
  diagrams (architecture flowchart, end-to-end sequence, bell toast flow, worker lifecycle state).
  **20 realtime gaps** (2 CRITICAL: G1 per-user Redis queue dead code — polled every 100ms but
  NEVER written by any code path, prior Phase 1 doc's claim was false; G2 partition migration
  `2026_08_02_000004` regresses LISTEN/NOTIFY trigger payload to `{action, id}` only — breaks
  branch-scoped SSE delivery + notification body + branch isolation when partitioning enabled;
  5 HIGH: G3 DDL stale, G4 worker not scheduled by Laravel cron + no in-repo supervisor/systemd
  config, G5 no RLS on 3 notification tables, G6 `fn_financial_audit_trigger` NOT attached to
  8/10 monitored tables; 6 MEDIUM + 7 LOW). **Notification system** (`workflows/notification-workflow.md`):
  rule-based, DB-driven, multi-recipient dispatcher layered on Laravel's `Notification` framework.
  `NotificationService` 262L crown jewel — `EVENT_META` (17 events with icon/color/title) +
  `dispatch()` (rule lookup → `resolveRecipients()` → `$user->notify(new ERPNotification(...))`
  via database channel → `times_fired++` → `emitNotify('rcerp_notification_dispatched')` for SSE
  toast) + `resolveRecipients()` 9-way match expression (6 global + 3 context-aware + 1
  specific_user, de-duped by user ID, base scope `is_active=true AND deleted_at IS NULL`).
  `ERPNotification` 67L `ShouldQueue` database-channel-only. `NotificationRule` 177L (EVENTS 14 +
  RECIPIENTS 10 + CHANNELS database-only + CONTEXT_AWARE_RECIPIENTS 3, SoftDeletes) +
  `NotificationRuleRecipient` 65L pivot (F-18b multi-recipient). `NotificationController` 248L
  (rule CRUD admin-only via `role:admin` + `view-notification-rules` Gate; bell/inbox/AJAX all
  auth users; NO `updateRule` method G8). 4 migrations + `NotificationRuleSeeder` 158L (11 default
  rules). 22-event catalogue (9 ACTIVE, 8 dead config G4, 4 dead approval code reaffirming Phase
  14 G4, 1 dead-in-practice system_policy_change). 10-recipient-type catalogue with verbatim
  resolution logic. 17-row dispatch call-site map (16 direct PHP + 1 worker-forwarded site with
  5 channel mappings). 7 Mermaid diagrams (end-to-end sequence, recipient resolution flowchart,
  rule lifecycle state, double-dispatch problem flowchart). **18 notification gaps** (3 CRITICAL:
  G1 DOUBLE DISPATCH on 4 events — `sales_finalize`/`challan_create`/`payment_receive`/`return_created`
  fire BOTH direct PHP + worker-forwarded paths producing duplicate admin notifications + inflated
  `times_fired`; G2 WRONG EVENT FORWARDED on UPDATE — `rcerp_sales_return` static map always
  forwards as `return_created` even on confirm/reverse UPDATE producing spurious "return created"
  toasts; G3 WORKER-FORWARDED EVENTS HAVE NO `$context` — context-aware recipient types
  `warehouse_manager_of_branch`/`salesman_of_invoice`/`invoice_creator` silently resolve empty
  on the worker path, only `admin` resolves; 6 HIGH: G4 8 dead-config events (godown_create/
  soft_delete/accounts_entry declared but never dispatched; branch_demand_created seeder rule but
  BranchDemandService doesn't dispatch; system_policy_change forwarded but not in EVENTS;
  damage_invoice_submitted/approved/rejected dispatched but not in EVENTS so no rule can be
  created), G5 no RLS, G6 no audit trigger, G7 DDL stale (legacy notifications schema in baseline),
  G8 no FormRequest + no updateRule route, G9 no sidebar menu entry; 6 MEDIUM + 3 LOW).
  Recommended single fix for G1-G3: remove the 5 `CHANNEL_EVENT_MAP` entries so
  `forwardToNotificationService` becomes a no-op (DB trigger still fires `pg_notify` for SSE
  refresh via `publishToRedis` which is unaffected). NOT SAFETY-CRITICAL (no GL posting) but
  business-critical (drives operational visibility).
- **Phase 16 — Reporting & Exports:** ✅ Complete (`reports/` — 5 files: `reports-catalog.md`
  740L, `materialized-views.md` 834L, `cte-reports.md` 694L, `csv-export.md` 986L,
  `dashboards.md` 780L = 4034 lines total, L complexity, depends on Phase 3). Spans the full
  reporting surface — central catalog helper + 13 materialized views + 4 CTE functions + 22
  CSV/Parquet export endpoints + 3 dashboard tiers. **Reports Catalog**
  (`reports-catalog.md`): `ReportsCatalog` helper (187L, 6 static methods, 5 categories × 21
  reports + 7 orphans = 28 total report endpoints) is the spine; `ReportController` 1079L
  with 33 public methods; 8 services (`ReportService` 1171L, `CteReportService` 304L,
  `DamageReportService` 434L, `StockTakeVarianceReport` 229L, `StockTakeWeeklyReport` 195L,
  `WarehouseTransferSummaryReport` 287L, `BranchDemandWeeklyReportService` 1076L,
  `DimensionReportingService` 261L); 4 SQL patterns (raw SQL heredoc / DB::table / MV read /
  CTE function call); 3 refresh strategies (real-time / MV-refresh @5min / on-demand CSV
  export); 4 reconciliation checks (TB Dr=Cr, BS A=L+E, CashFlow plugs_to_gl_cash, AR Aging
  matches_gl); ReportsHub.js 167L (4 lens presets + pin-to-localStorage); reports-premium.css
  529L (5 accent colors). 16 gaps (3 CRITICAL: G1 NO `role:` middleware on `admin/reports`
  prefix group — salesmen can hit Trial Balance / P&L / BS / Cash Flow / all 4 CTE reports /
  all CSV exports, RLS only enforces branch isolation not role-based read; G2 catalog drift —
  claims 18 reports but has 21, plus 7 orphan reports including all 4 CTE reports unreachable
  from hub; G3 `branch_demand_weekly` catalog entry is a STUB pointing at a 5-column list
  while the REAL 23-column report is at `admin.branch-demands.weekly-report` orphan route;
  7 HIGH: G4 DDL stale — `database/sql/07_views_triggers_constraints.sql` has ZERO matches
  for the 7 MVs / 4 CTE functions / `refresh_all_report_views()`, only in migrations; G5
  `fn_financial_audit_trigger` attached to only 9 tables — 14 transactional tables that feed
  reports bypass `financial_audit_log`; G6 ZERO FormRequests for 50+ unvalidated report
  filter inputs; G7 ZERO tests for 57 untested report service methods (P&L/BS/CF unaudited);
  G8/G9/G10 AR Aging + Gross Margin + General Ledger each have duplicate non-CTE + CTE
  implementations with no equivalence test; 4 MEDIUM + 2 LOW). **Materialized Views**
  (`materialized-views.md`): 13 MVs discovered (not 8 as initially scoped) — 7 financial
  (mv_ledger_balances, mv_ar_aging, mv_ap_aging, mv_stock_valuation, mv_journal_entry_summary,
  mv_branch_intercompany, mv_product_movement_summary) + 1 ABC + 1 consolidated trial balance
  + 4 running-balance check; `refresh_all_report_views()` PL/pgSQL function verbatim (7×
  REFRESH CONCURRENTLY in single BEGIN…END — Gap G6 may fail since PG forbids CONCURRENTLY
  inside transaction block); `reports:refresh` artisan command 43L + Laravel scheduler
  (`console.php:11-17` every 5min `withoutOverlapping` `runInBackground`) + pg_cron duplicate
  (`2025_01_20_000009:222-228` every 5min — Gap G5 no coordination); on-demand refresh claim
  in docblock is NOT WIRED (Gap G15 — grep for callers of
  `ReportService::refreshMaterializedViews()` returns 0 matches); 3 regular views
  (`v_journal_entries_with_lines`, `v_financial_audit_chain_verification`, `budget_vs_actual`).
  14 gaps (4 CRITICAL: G1 NO RLS on any of 13 MVs — pre-materialized physical rows bypass
  source-table RLS policies; G2 DDL staleness — baseline `database/sql/*.sql` has ZERO
  matches for 12 of 13 MVs (only `mv_product_abc_classification` is in `03_stock.sql`);
  G3 `mv_consolidated_trial_balance` orphaned refresh — NO scheduled refresh, only ad-hoc
  via private `ConsolidationService::refreshMaterializedViews()`; G4 ZERO tests for MV
  refresh command + ZERO MV integrity tests; 3 HIGH + 5 MEDIUM + 2 LOW). **CTE Reports**
  (`cte-reports.md`): 4 PostgreSQL `STABLE` PL/pgSQL functions returning `jsonb` —
  `rcerp_today_summary` (10+ CTEs, replaces 6+ LegacyDashboard queries), `rcerp_ar_aging_cte`
  (bucketing + reconciliation + detail + per-branch CTEs, includes `checks.matches_gl: bool`),
  `rcerp_general_ledger_cte` (opening-balance + window-function running-balance via `SUM()
  OVER (PARTITION BY ledger_id ORDER BY entry_date, entry_no, jl.id ROWS UNBOUNDED
  PRECEDING)` — replaces PHP-side `$running[$key] += $r->debit - $r->credit` loop),
  `rcerp_gross_margin_cte` (6-stage pipeline: active_invoices → invoice_items → item_cogs via
  stock_transactions join → invoice_margin → product_margin → grand_totals — accurate
  per-product COGS replacing simplified `sales_challans.issue_cost` column). `CteReportService`
  304L crown jewel — 4 public methods + 4 private fallback methods, single round-trip
  `DB::selectOne("SELECT rcerp_*_cte(...) AS result")`, `meta.source = 'cte_function'` on
  success / `'fallback'` on Throwable. 2 convenience views (`v_today_summary`,
  `v_ar_aging_today`). 11 gaps (1 CRITICAL cross-ref G1, 5 HIGH cross-ref G2/G4/G7/G8/G9/G10,
  3 MEDIUM, 2 LOW). **CSV & Parquet Export** (`csv-export.md`): 22 HTTP export endpoints + 1
  Artisan command across 3 tiers — Tier 1 `CsvExporter` service (159L, streaming via
  `response()->stream()` + `chunk(500)` + UTF-8 BOM via `fwrite($out, "\xEF\xBB\xBF")`, used
  by 9 master-data modules via `BaseMasterDataController::export`); Tier 2 inline `fputcsv`
  exports in 14 controllers/services with 4 pattern variants (cursor+fprintf BOM / get+fwrite
  BOM / php://temp buffered NO BOM / `?export=csv` query toggle); Tier 3 DuckDB-backed
  Parquet archival (`ExportArchivedPartitionsToParquet` 339L, quarterly at 04:30 on Jan 1 /
  Apr 1 / Jul 1 / Oct 1, COPY→temp CSV→DuckDB→Parquet ZSTD, DROPs archive table after
  success unless `--keep`). 30 gaps (3 CRITICAL: G1 NO `role:` middleware on 6 export
  endpoints in `admin/reports` group — any authed user can download Trial Balance / Cash
  Flow / Stocktake Variance / Stocktake Weekly / Damage CSVs; G2 DuckDB NOT in Docker image
  — `Dockerfile` has no `duckdb` binary, every quarterly run silently falls back to CSV then
  DROPS the typed archive data irretrievably; G12 reaffirms G1 — `?export=csv` query toggle
  on `trialBalance` + `cashFlow` routes means no opportunity to attach role middleware;
  9 HIGH: G3 CsvExporter not bound as singleton + no Facade, G4 BranchDemandReport export
  buffered NOT streamed + no 90-day cap = memory exhaustion DoS, G5 no `config/export.php`,
  G6 NO audit-log row on ANY export (SOX compliance gap), G7 NO FormRequest validation on
  any export, G8 NO throttle on any export endpoint, G11 14 of 22 endpoints bypass
  CsvExporter + roll their own fputcsv, G21 BranchDemand CSV missing BOM, G23 Purchase Order
  CSV missing BOM, G24 Budget Variance CSV missing BOM; 9 MEDIUM + 7 LOW). **Dashboards**
  (`dashboards.md`): 3 dashboard tiers + 1 dead demo asset — (1) PRIMARY
  `UserPerformanceDashboardController` 2246L god-class (Gap G9) with 29 methods (16 cached
  metric methods + 13 helpers), per-user attribution via `created_by = $userId` (class
  docblock: "NO company-wide metrics anywhere"), 60s `Cache::remember` per metric, 200ms
  slow-query threshold logging to `storage/logs/perf.log`, 5 period presets (today/mtd/qtd/
  last30/custom — `ytd` deliberately removed), super-admin can `?employee_id=X`, role-section
  visibility via `resolveRoleSections()` switch; (2) `DashboardApiController` 167L REST API
  for mobile apps + AI sidecar (3 endpoints: index/sales-trend/top-products, all `api.auth`
  + `api.rate:120` highest tier); (3) `LegacyDashboardController` 502L DEAD CODE (Gap G7 —
  imported but not routed, `view('dashboard.index')` references non-existent blade); (4)
  `intelligent-sales-cockpit.html` 1700L dead demo asset in `/public/` (Gap G8 — hardcoded
  "Ayesha Rahman"/"$185k" demo data accessible without auth, same pattern as Phase 15's
  dead `push.js`). 6 partial composite indexes from `2026_07_31_000001` migration optimize
  hot queries. 11 gaps (1 CRITICAL cross-ref G1, 4 HIGH: G7 LegacyDashboard dead code, G8
  intelligent-sales-cockpit.html dead asset, G9 2246L god-class with inline SQL violates
  Phase 4 coding-standards, G10 no FormRequest validation + permissive `resolveRoleSections`
  default; 3 MEDIUM + 3 LOW). NOT SAFETY-CRITICAL (no GL posting) but business-critical
  (drives financial close + audit + operational visibility). 9 Mermaid diagrams across the
  5 files (hub→report sequence, MV refresh cycle, report filter state, CTE happy path, CTE
  error path, AR aging decision tree, MV state lifecycle, dashboard page load, AJAX fragment
  switch, page states, API request lifecycle, CSV request lifecycle, master-data export
  flowchart, Parquet archival state). Total 82 gaps across 5 files (3+3+1+3+1 = 11 CRITICAL
  cross-referenced; 7+3+5+9+4 = 28 HIGH; 4+5+3+9+3 = 24 MEDIUM; 2+2+2+7+3 = 16 LOW).
- **Phases 17–21:** Not started. Execute one phase at a time per the roadmap.

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
