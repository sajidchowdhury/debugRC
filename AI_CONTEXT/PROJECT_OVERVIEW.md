# Project Overview — RC_ERP_v2 (Remote Center ERP)

> **Module:** Top-level overview
> **Audience:** Engineers, AI assistants, accountants, product owners
> **Status:** Canonical
> **Last reviewed:** Phase 21 (§11 Known Limitations + §12 Roadmap expanded)
> **Source of truth:** This file + repo `README.md` + `laravel/composer.json`

---

## 1. What is it?

**RC_ERP_v2** (Remote Center ERP) is a multi-branch, multi-warehouse enterprise resource
planning system. It is a **Laravel 12 + PostgreSQL 16 + Redis** web application that
manages the full commercial cycle of a distribution/retail business: master data,
inventory, procurement, sales, double-entry accounting, fixed assets, budgets,
inter-branch consolidation, and reporting.

The application code lives in the repository at `laravel/`. The database is PostgreSQL 16
(schema defined by raw SQL DDL in `laravel/database/sql/` plus 160 Laravel migrations).
Redis is used for sessions, cache, and queue.

> **Note on framework version:** The repository `README.md` says "Laravel 11", but
> `laravel/composer.json` requires `laravel/framework: ^12.0`. **The actual runtime is
> Laravel 12 on PHP ^8.2.** This knowledge base documents the actual (Laravel 12) state.
> The `bootstrap/app.php` file comment also says "Laravel 11"; that comment is stale.

---

## 2. Why does it exist?

The ERP was **migrated** from a legacy custom PHP/MySQL codebase to a modern Laravel +
PostgreSQL stack. The migration was executed in 13 phases (Phases 0–12 complete; Phase 13,
an AI sidecar, pending). The migration's purpose was to:

- Move off an aging, bespoke PHP MVC and a MySQL database onto a maintained framework
  (Laravel) and an enterprise-grade database (PostgreSQL).
- Preserve the **existing UI** (Blade views reproduce the legacy Bootstrap markup — no SPA
  rewrite) so end users face no retraining.
- **Re-derive** the business logic (stock costing, journal posting, reconciliation) from
  first principles rather than copy-pasting legacy code, so the new system is correct and
  maintainable.
- Keep the legacy MySQL data available **read-only** as a historical archive via an
  anti-corruption layer.

This `AI_CONTEXT/` knowledge base exists because the resulting system is large and the
business rules are safety-critical; a single authoritative reference prevents both human
onboarding cost and AI-introduced corruption.

---

## 3. The four non-negotiable principles

These are the project's founding constraints (sourced from the repo `README.md`). They
govern every decision and MUST be respected by any contributor or AI assistant.

| # | Principle | Status |
|---|---|---|
| 1 | **Database conversion** — MySQL → PostgreSQL | ✅ Complete (Phase 2) |
| 2 | **Application conversion** — custom PHP MVC → Laravel | ✅ Complete (Phases 3–9) |
| 3 | **Keep the existing UI** — Blade reproduces legacy markup; no SPA rewrite | ✅ Complete |
| 4 | **Re-derive business logic, don't copy-paste** — stock costing, journal posting, reconciliation re-derived from first principles | ✅ Complete |

---

## 4. Technology stack

### 4.1 Core stack (confirmed from `laravel/composer.json` + config)

| Layer | Technology | Version / Notes |
|---|---|---|
| Language | PHP | `^8.2` |
| Framework | Laravel | `^12.0` (App Router style bootstrap in `bootstrap/app.php`) |
| Database | PostgreSQL | 16 (schema in `laravel/database/sql/01–07_*.sql`) |
| Cache / Session / Queue | Redis | via `predis/predis ^2.0`; 3 logical DBs (default=0, legacy session=1, cache=2) |
| Auth (API) | Laravel Sanctum | `^4.0` — bearer tokens for the REST API |
| Dev tooling | Larastan `^3.0`, Pint `^1.18`, PHPUnit `^11.5`, Debugbar `^3.14`, Pail `^1.1` | |
| Frontend | Blade + Bootstrap 5 + jQuery + Select2 + DataTables + Chart.js + SweetAlert2 | Legacy UI preserved; assets in `laravel/public/assets/` |

### 4.2 Laravel application shape

- **Routing:** `routes/web.php` (1,797 lines, Blade + web controllers) and
  `routes/api.php` (555 lines, `/api/v1` REST). Console routes in `routes/console.php`.
- **Bootstrap:** `bootstrap/app.php` registers middleware (session bridge, branch-id GUC,
  credential version, system policy, trust proxies) and route middleware aliases
  (`role`, `branch.isolation`, `api.auth`, `api.rate`, `set.api.branch`,
  `menu.permission`).
- **Service container:** `app/Providers/AppServiceProvider.php` binds singletons
  (LegacySessionBridge, LedgerNatureService, SubLedgerService, JournalReversalService,
  SalesAuditLogger, MenuService, SystemPolicyService, Archive layer) and registers
  Gate policies.

### 4.3 Scale of the Laravel app (verified by file count)

| Artifact | Count | Location |
|---|---|---|
| Eloquent models | 98 | `laravel/app/Models/` (incl. `Accounting/`, `Scopes/`) |
| Web (Admin) controllers | 57 | `laravel/app/Http/Controllers/Admin/` |
| API controllers | 15 | `laravel/app/Http/Controllers/Api/V1/` |
| Service classes | 78 | `laravel/app/Services/` (14 namespaces) |
| Middleware | 10 | `laravel/app/Http/Middleware/` |
| Policies | 8 | `laravel/app/Policies/` |
| Form requests | many | `laravel/app/Http/Requests/` |
| Console commands | 27 | `laravel/app/Console/Commands/` |
| Migrations | 160 | `laravel/database/migrations/` |
| Raw SQL DDL files | 7 (+1 snapshot) | `laravel/database/sql/01–07_*.sql` |
| ETL files | 4 | `laravel/database/etl/` |
| Config files | 21 | `laravel/config/` |
| Blade views | 326 | `laravel/resources/views/` |
| Tests | 107 | `laravel/tests/` (Unit + Feature) |
| Factories | 14 | `laravel/database/factories/` |

### 4.4 Service-layer namespaces

The service layer is the heart of the business logic (controllers stay thin). Namespaces
under `laravel/app/Services/`:

| Namespace | # | Responsibility |
|---|---|---|
| `Accounting/` | 17 | Journal posting, reconciliation, reversal, sub-ledger, period close, fiscal year, depreciation, asset disposal, money transfer, manual journal, supplier/customer/employee transactions, other income/expense, bank reconciliation, document sequencing, ledger nature |
| `Stock/` | 22 | Stock service, availability, adjustment (+policy/audit/reconcile), stock-take (+policy/audit/health/variance/weekly/ABC), damage (+integrity), warehouse transfer (+audit/shadow/summary), UoM conversion |
| `Sales/` | 10 | Invoice, challan, cart, return (+reversal guard + returnable qty), customer payment, commission, access, audit logger |
| `Purchase/` | 4 | Order, receive, return, audit |
| `BranchDemand/` | 7 | Demand, repricing, intercompany, audit (+logger), shadow, weekly report |
| `Auth/` | 6 | Password policy, account lockout, credential version, user audit logger, login rate limiter, remember-me manager |
| `Reports/` | 3 | Report service, damage report, CTE report |
| `Budgeting/` | 2 | Budget service, dimension reporting |
| `Notification/` | 2 | Notification service, Listen/Notify service |
| `Approval/` | 1 | Multi-step approval engine |
| `Compliance/` | 1 | System policy service (investigation mode) |
| `Consolidation/` | 1 | Multi-branch consolidation |
| `MasterData/` | 1 | Code generator |
| `Export/` | 1 | CSV exporter |
| (top-level) | 1 | `MenuService` (DB-driven menu + permissions) |

---

## 5. Module map (high level)

The ERP is organized into the following functional modules. Each will get its own
documentation folder in a later phase.

```mermaid
flowchart LR
    subgraph Foundation["Foundation"]
        AUTH[Auth & Sessions]
        RBAC[Roles & Permissions]
        BRANCH[Branch & Warehouse]
        MASTER[Master Data<br/>Products, Customers, Suppliers, Employees, Banks, Ledgers]
    end
    subgraph Ops["Operations"]
        INV[Inventory<br/>Stock, Stock-Take, Damage, Transfer, UoM]
        PUR[Purchasing<br/>PO, Receive, Return]
        SAL[Sales<br/>Invoice, Challan, Cart, Return, Commission]
    end
    subgraph Finance["Finance"]
        ACC[Accounting Engine<br/>CoA, Posting, Reversal, Sub-ledger]
        TXN[Transactions<br/>Money, Supplier, Customer, Employee, Other I/E, Manual Journal]
        FA[Fixed Assets & Depreciation]
        BUD[Budgets & Dimensions]
        CON[Consolidation & Intercompany]
        BD[Branch Demand & Settlement]
    end
    subgraph Platform["Platform"]
        REP[Reports & Dashboards]
        API[REST API v1]
        SEC[Security, Audit, Compliance]
        NOTIF[Notifications & Realtime]
        DEP[Deployment & Ops]
        ARC[Legacy Archive (read-only)]
    end

    MASTER --> INV
    MASTER --> PUR
    MASTER --> SAL
    PUR --> INV
    INV --> SAL
    SAL --> ACC
    PUR --> ACC
    INV --> ACC
    TXN --> ACC
    FA --> ACC
    ACC --> REP
    SAL --> REP
    INV --> REP
```

### Module → folder mapping (planned)

| Module | Doc folder | Phase |
|---|---|---|
| Foundation (auth, RBAC, branch, master data) | `security/`, `business/` | 2, 5 |
| Architecture | `architecture/` | 1 |
| Database | `database/` | 3 |
| Coding standards | `coding/` | 4 |
| Accounting engine + transactions | `accounting/` | 6, 7 |
| Inventory | `inventory/` | 8 |
| Purchasing | `purchasing/` | 9 |
| Sales | `sales/` | 10 |
| Fixed assets, budgets, consolidation | `finance/` | 11, 12, 13 |
| Approval & compliance | `workflows/`, `security/` | 14 |
| Notifications & realtime | `architecture/`, `workflows/` | 15 |
| Reports | `reports/` | 16 |
| API | `api/` | 17 |
| Archive | `archive/` | 18 |
| Deployment | `deployment/` | 19 |
| Cross-cutting workflows | `workflows/` | 20 |
| Changelog & roadmap | `changelog/` | 21 |

---

## 6. Roles (RBAC overview)

Defined in `laravel/config/roles.php`: **10 canonical roles in 3 tiers**.

| Tier | Roles |
|---|---|
| superadmin | `superadmin` |
| admin | `admin` |
| operational | `manager`, `accountant`, `salesman`, `warehouse_manager`, `dispatcher`, `hr`, `user`, `other` |

Role assignment is governed by `assignable_by` rules (e.g. only superadmin can create
superadmin). Role enforcement uses the `role:` route middleware alias
(`app/Http/Middleware/EnsureRole.php`) plus per-model Gates/Policies as defense-in-depth.
Menu-level access is DB-driven (`menus` table + `UserMenuPermission` + `EnsureMenuPermission`
middleware). Full detail is documented in Phase 5 (`security/rbac-roles-permissions.md`).

---

## 7. Organizational structure (business)

- **Multi-branch:** A company has multiple branches. Each branch is isolated at the data
  layer via PostgreSQL **Row-Level Security (RLS)** driven by a per-request `app.branch_id`
  GUC, set by `SetAppBranchId` middleware (web) / `SetApiBranchContext` (API).
- **Multi-warehouse:** Each branch owns one or more warehouses. Stock is tracked per
  warehouse.
- **Employees & users:** An `Employee` belongs to a branch; a `User` authenticates and is
  linked to an employee. `credential_version` invalidates stale sessions on password/role
  change.
- Full organizational detail is documented in Phase 2 (`business/organizational-structure.md`).

---

## 8. Migration status (product)

| Phase | Name | Status |
|---|---|---|
| 0 | Pre-Migration Security Cleanup | ✅ |
| 1 | VPS BDIX Provisioning | ⬜ Pending (manual — needs VPS) |
| 2 | Database Migration to PostgreSQL | ✅ |
| 3 | Laravel Foundation + Auth | ✅ |
| 4 | Master Data Modules | ✅ |
| 5 | Reporting Layer | ✅ |
| 6 | Inventory Module (6.1–6.6) | ✅ |
| 7 | Purchase Module (7.1–7.3) | ✅ |
| 8 | Sales Module (8.1–8.5) | ✅ |
| 9 | Accounting Engine (9.1–9.6) | ✅ |
| 10 | Notifications (Laravel native) | ✅ |
| 11 | Compliance & Investigation Framework | ✅ |
| 12 | Enterprise Cutover & Archive | ✅ |
| 13 | AI Sidecar (Python FastAPI) | ⬜ Pending |

**Removed features (by project decision):** TOTP 2FA, Telegram login/alerts, Firebase FCM
push. Laravel-native notifications (`ERPNotification` + `NotificationService`) +
Listen/Notify + SSE cover operational visibility.

---

## 9. Removed features (important for AI assistants)

An AI assistant MUST NOT reintroduce these, and MUST NOT assume they exist:

- TOTP 2FA on login (Google Authenticator) — removed.
- `PendingLogin` intermediate 2FA state — removed.
- Telegram login notifications — removed.
- `verify_2fa` view and route — removed.
- `users.totp_secret`, `users.totp_enabled` columns — dropped.
- Telegram business alerts — removed (2026-07-22). Laravel native notifications cover it.
- Firebase FCM push — removed (2026-07-22). `fcm_tokens` table + `users.telegram_user_id`
  column dropped.

---

## 10. Where to go next

- **Understand the plan & rules:** `IMPLEMENTATION_PLAN.md` (especially §7 AI Instructions).
- **Find a term:** `GLOSSARY.md`.
- **Understand architecture:** `architecture/` (Phase 1, pending).
- **Understand accounting rules (safety-critical):** `accounting/` (Phase 6, pending). Until
  then, the existing `docs/migration/journal_posting_rules.md` and
  `docs/migration/avg_cost_rule.md` are the best sources.
- **Run the app locally:** repo `README.md` + `docs/SETUP_GUIDE.md` + `docs/DOCKER_README.md`.
  Operational docs will be consolidated into `deployment/` in Phase 19.

---

## 11. Known limitations (expanded in Phase 21)

These are the explicit, current limitations of RC_ERP_v2. An AI assistant or engineer
MUST be aware of these before making changes. Each limitation links to the canonical
`AI_CONTEXT/` reference for detail.

### 11.1 Deployment limitations

- **Phase 1 (VPS BDIX provisioning) is pending** — the app is code-complete but not yet
  deployed to production; local Docker is the supported dev path. See
  [`../deployment/vps-bdix-deployment.md`](../deployment/vps-bdix-deployment.md).
- **Manual security actions remain** (cannot be done in code):
  - [ ] Reset all production user passwords (bcrypt hashes were in a public SQL dump).
  - [ ] Delete or make-private the old public repo `sajidchowdhury/RC_ERP`.
  - [ ] Delete or make-private the public repo `sajidchowdhury/RC_ERP_Laravel`.
  - [ ] Provision BDIX VPS (Phase 1).
  - [ ] Set production `.env` with new credentials (chmod 600, never committed).
- **Legacy MySQL is intended to be set to read-only on production** (not yet enforced at
  the DB level everywhere). See [`../archive/legacy-read-only.md`](../archive/legacy-read-only.md).

### 11.2 Pending product phases

- **Phase 13 (AI Sidecar) is pending** — report chatbot, demand forecasting, invoice OCR,
  anomaly detection are not yet built. See [`../ROADMAP.md`](../ROADMAP.md) §3.

### 11.3 Cross-cutting technical gaps

These are gaps documented across the `AI_CONTEXT/` knowledge base. They are NOT product
blockers, but they should be resolved before the VPS cutover.

- **G1/G2/G3 (notifications):** double-dispatch, wrong-event-on-update,
  worker-forward-missing-context. Admins receive duplicate toasts; context-aware
  recipients receive only the direct-call copy. See
  [`../workflows/notification-workflow.md`](../workflows/notification-workflow.md) §1.1 + §13.
- **Dead intercompany settlement methods:**
  - `CustomerPaymentService::postIntercompanySettlement` (L772, `return null;`) —
    code exists but is unreachable. See
    [`../accounting/customer-payments.md`](../accounting/customer-payments.md) §8.
  - `SupplierTransactionService::postIntercompanySettlement` (L616) — same status.
    See [`../accounting/supplier-transactions.md`](../accounting/supplier-transactions.md) §8.
  - `WarehouseTransferService::postIntercompanyGL` (L531) — defined but NOT called from
    `confirm()`. Cross-branch transfers currently post stock movements but NO GL entries.
    See [`../inventory/warehouse-transfer.md`](../inventory/warehouse-transfer.md) §7.3 + §8
    and [`../workflows/inventory-to-gl.md`](../workflows/inventory-to-gl.md) §12.1.
- **Avg-cost snapshot backfill:** legacy `stock_transactions.unit_cost` and
  `sales_invoice_items.avg_cost_snapshot` are NULL for pre-migration data — drift risk on
  reversals. See [`../inventory/stock-costing.md`](../inventory/stock-costing.md) §13.
- **Period close does not enforce recon:** the close command succeeds even if
  `subledger:reconcile-*` reports drift. The accountant must run recon manually before
  close. See [`../workflows/period-close-workflow.md`](../workflows/period-close-workflow.md)
  §12.8.
- **Legacy vs enhanced period close reconciliation:** `accounting_periods` (legacy
  soft-close) and `fiscal_periods` (enhanced) can disagree. Reconciliation is manual.
  See [`../workflows/period-close-workflow.md`](../workflows/period-close-workflow.md) §12.12.
- **Approval engine inconsistency (G1–G7):** Pattern A (generic configurable engine) vs
  Pattern B (entity-specific maker-checker) do not share infrastructure. Only
  `manual_journal` is wired into Pattern A, and even there the post-step is broken (G2).
  See [`../workflows/approval-workflow.md`](../workflows/approval-workflow.md) §1.
- **Branch demand notification never fires:** `branch_demand_created` rule exists in the
  seeder but `BranchDemandService` doesn't call `dispatch()`. See
  [`../finance/branch-demand.md`](../finance/branch-demand.md).
- **Damage approval events functionally dead:** 3 `damage_invoice_*` approval events are
  NOT in `NotificationRule::EVENTS`. See [`../inventory/damage.md`](../inventory/damage.md).
- **RLS gap on notification tables:** `notifications`, `notification_rules`,
  `notification_rule_recipients` lack RLS policies (G5). See
  [`../architecture/branch-isolation-rls.md`](../architecture/branch-isolation-rls.md).
- **Audit trigger gap on notification tables:** `fn_financial_audit_trigger` is missing on
  `notification_rules` + `notification_rule_recipients` (G6). See
  [`../security/audit-trails.md`](../security/audit-trails.md).
- **`system_policy_change` event not in EVENTS/EVENT_META** (G4): forwarded by the
  `rcerp_system` DB trigger but not consumable by the notification rule engine. See
  [`../security/system-policy-compliance.md`](../security/system-policy-compliance.md).
- **Sanctum guard declared but UNUSED:** `config/auth.php` declares a `sanctum` guard, but
  the actual API auth is a custom bearer-token implementation. See
  [`../security/auth-and-sessions.md`](../security/auth-and-sessions.md).
- **`ApplySystemPolicyScope` dead code:** the scope is defined but never wired into any
  model. See [`../security/system-policy-compliance.md`](../security/system-policy-compliance.md).
- **`SystemPolicyPolicy` dead code:** registered in `AppServiceProvider` but never used.
  See [`../security/rbac-roles-permissions.md`](../security/rbac-roles-permissions.md).

### 11.4 Operational limitations

- **Single currency (BDT).** No multi-currency support. See
  [`../business/business-model.md`](../business/business-model.md).
- **No manufacturing module.** The `manufacturing/` folder is a placeholder. See
  [`../business/business-model.md`](../business/business-model.md).
- **No supplier portal.** Suppliers cannot self-service view their GRNs/payments.
- **No customer portal.** Customers cannot self-service view their invoices/payments.
- **No early-payment discount on supplier payments.** The `supplier_payments.discount_amount`
  column exists but is not yet wired into `postPaymentGL()`.
- **No back-order workflow.** An invoice fails atomically if any line is short-stocked.
- **No partial GRN confirmation.** A GRN is either fully confirmed or remains draft.
- **No three-way match (PO ↔ GRN ↔ Supplier Invoice).** The match is manual.
- **No AP aging auto-refresh on payment.** The MV `ap_aging_summary` exists but is not
  auto-refreshed after supplier payment confirm (only on the 5-min `refresh:report-views` cycle).

### 11.5 Documentation limitations

- **Some `docs/` markdown is stale.** The `AI_CONTEXT/` knowledge base is the canonical
  reference; `docs/migration/*` is preserved as historical context. Where they disagree,
  prefer `AI_CONTEXT/`.
- **`bootstrap/app.php` comment says "Laravel 11"** but the runtime is Laravel 12
  (`composer.json` requires `laravel/framework: ^12.0`). The comment is stale.
- **`README.md` says "Laravel 11"** in one place. Same staleness.

---

## 12. Future improvements (roadmap — detailed in [`../ROADMAP.md`](../ROADMAP.md))

This section is a summary; the full roadmap with milestones, dependencies, and
sequencing lives in [`../ROADMAP.md`](../ROADMAP.md).

### 12.1 Production deployment (Phase 1)

- Provision BDIX VPS (Ubuntu 22.04, PHP 8.3, PostgreSQL 16, Redis, Nginx).
- Run `php artisan migrate` + `php artisan chart:seed` + `php artisan migrate:master-data`.
- Verify on VPS: `php artisan chart:validate` + `php artisan stock:replay-verify` +
  `php artisan journal:replay-verify`.
- Set legacy MySQL to READ-ONLY (revoke write privileges).
- Configure Nginx (see [`../deployment/nginx-config.md`](../deployment/nginx-config.md)).

### 12.2 AI Sidecar (Phase 13 — Python FastAPI)

- Report chatbot (natural-language query → SQL → report).
- Demand forecasting (historical sales → forecast per SKU/branch).
- Invoice OCR (supplier invoice PDF → draft GRN).
- Anomaly detection (GL/stock anomalies → flagged for review).

### 12.3 Partitioning & archival hardening

- Continue pg_partman retention enforcement.
- Automate Parquet export for archived partitions.
- Add partition-health alerting (extend the existing
  `partition:measure-perf` + `partition:verify-join` commands).

### 12.4 REST API v1 coverage extension

- Extend `/api/v1` to cover all modules (currently Sales/StockTake are best-covered).
- Add OAuth2 client-credentials flow for machine-to-machine integrations.
- Publish `laravel/docs/api/API_REFERENCE.md` (Scribe-generated).

### 12.5 Cross-cutting gap remediation

- Fix G1/G2/G3 in the notification system.
- Activate dead intercompany settlement methods (now that `banks.branch_id` exists).
- Backfill avg_cost_snapshot on legacy data.
- Enforce recon before period close.
- Reconcile legacy vs enhanced period close.
- Unify Pattern A and Pattern B approval engines (or deprecate one).

### 12.6 Operational enhancements

- Back-order workflow (split cart into fulfillable invoice + back-order cart).
- Partial GRN confirmation.
- Three-way match (PO ↔ GRN ↔ Supplier Invoice).
- Supplier portal (read-only).
- Customer portal (read-only).
- Early-payment discount on supplier payments.
- AP aging auto-refresh on payment confirm.
- Multi-currency support (if business requires).

### 12.7 Documentation enhancements

- Stale-comment cleanup (`bootstrap/app.php`, `README.md` — Laravel 11 → 12).
- Year-end close dry-run command.
- Period-close dashboard (single screen for close preparation).
- Stock ledger → GL reconciliation materialized view.

---

*This overview is the foundation. Module-specific depth lives in the phase folders. Keep
this file updated whenever the tech stack, principles, or module map changes. For the
full product history, see [`../changelog/PRODUCT_CHANGELOG.md`](../changelog/PRODUCT_CHANGELOG.md).
For the detailed roadmap, see [`../ROADMAP.md`](../ROADMAP.md).*
