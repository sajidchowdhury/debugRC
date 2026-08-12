---
Title: Dispatch — Task-to-File Routing Table
Module: Cross-cutting (meta)
Audience: AI assistants + engineers starting a task
Status: Living document
Last reviewed: 2026-08-04
Source of truth: This file routes tasks to the minimal AI_CONTEXT file set
---

# DISPATCH — Task-to-File Routing Table

> **Purpose:** Turn 104 AI_CONTEXT files (~54K lines, ~150K tokens) into "load 3 files,
> skip 101." This is the Tier-1 entry point — load this file FIRST after the Tier-0 boot
> files (README, PROJECT_OVERVIEW, GLOSSARY), then route to the minimal file set for your
> specific task.
>
> **Loading order:**
> 1. **Tier 0 (always):** `README.md` + `PROJECT_OVERVIEW.md` + `GLOSSARY.md` (~1,236 lines, ~5K tokens)
> 2. **Tier 1 (this file):** `DISPATCH.md` — find your task pattern below (~3K tokens)
> 3. **Tier 2 (targeted):** load ONLY the files listed in your matched row
> 4. **Tier 3 (if needed):** `ISSUES_REGISTER.md` when a routing caveat mentions a G# gap ID, or `ROADMAP.md` when checking horizon priority
> 5. **Tier 4 (deep dive):** load additional files only if the task explicitly requires it (e.g. touching the deployment environment loads `deployment/environment.md`)
>
> **Expected token spend:** A typical task loads 3-5 files (~9-15K tokens) on top of Tier 0/1
> (~8K), totalling ~17-23K tokens — versus ~150K to load everything. **~85% reduction.**

## How to use this file

1. Read the **Task pattern** column. Find the row that best matches your task.
2. If multiple rows match, prefer the more specific one (e.g. "fix sales invoice *posting*" over "fix sales invoice").
3. If NO row matches, fall back to the **Default routing** section at the bottom.
4. Load the files in the **Load order** column — order matters (overview first, then crown jewels, then dependencies).
5. Respect the **Skip** column — loading extra files wastes context.
6. Heed the **Caveats** column — it calls out non-obvious traps and gap IDs you should cross-reference in `ISSUES_REGISTER.md`.

## Legend

- **Load order** lists files in dependency order: module-overview → crown-jewel → cross-cutting dependency → workflow → related code. `→` separates sequential loads. `§X.X` points to a specific section when the file is huge (use it to focus your reading, not to skip the rest of the file).
- **Token est.** is approximate (line count × ~4 tokens/line, rounded to nearest 500). Includes only the files in the Load order column — Tier 0/1 files add ~8K on top.
- **Skip** lists modules/subfolders that are irrelevant for this task — resist the urge to load them "just in case."
- **Caveats** call out non-obvious traps (e.g. "also check `running-balance.md` if ledger looks wrong", "G2 in ISSUES_REGISTER affects this"). When a caveat cites a G# ID, look it up in `ISSUES_REGISTER.md` before editing.

---

## Task routing table

| # | Task pattern (keywords) | Load order (in sequence) | Skip | Token est. | Caveats |
|---|---|---|---|---|---|
| 1 | "fix sales invoice posting" / "invoice GL entry wrong" / "VAT on invoice" / "commission on invoice" | `sales/sales-overview.md` → `sales/sales-invoice.md` → `accounting/journal-posting-rules.md` §7.6.1 → `workflows/order-to-cash.md` | inventory, purchasing, finance, api, archive, deployment | ~8K | Also load `accounting/running-balance.md` if the ledger balance looks wrong, and `sales/commission.md` if commission auto-calc is in scope. CRITICAL gap G-059 — `SalesInvoiceApiController::update` doesn't pass `items[]` (mobile API edit broken). DDL `04_sales.sql` is stale (8+ columns live only in migrations — G-072). |
| 2 | "add/modify sales return" / "sales return GL" / "return reversal" | `sales/sales-overview.md` → `sales/sales-return.md` → `accounting/journal-posting-rules.md` §7.6.1 → `workflows/order-to-cash.md` | inventory (unless damage condition), purchasing, finance, api, archive, deployment | ~8K | Returns reverse at ORIGINAL avg_cost snapshot (from `sales_challan_items.rate` via `stock_transactions`), NOT current avg_cost. `SalesReturnReversalGuard` pre-checks stock shortage. Phase-5 Good/Damage condition — Damage skips stock movement, still posts GL. |
| 3 | "debug sales cart" / "POS hold" / "cart draft JSONB" / "cart AJAX" | `sales/sales-overview.md` → `sales/sales-cart.md` → `database/schema-overview.md` | accounting, finance, purchasing, api, archive, deployment | ~4K | CRITICAL gap G-056 — `customers.shop_name` column MISSING (runtime `SQLSTATE[42703]` from `getCustomerDetails` L487). R6 3-col unique key per-user × per-customer × per-branch. Per-user JSONB draft cart. |
| 4 | "debug transport cost on invoice" / "transport-edit deferred-GL" | `sales/sales-overview.md` → `sales/transport-cost.md` → `sales/sales-challan.md` → `accounting/journal-posting-rules.md` §7.6.1 | inventory (unless godown stock), purchasing, finance, api, archive, deployment | ~6.5K | Phase-6 transport-edit deferred-GL workflow: snapshot + sub-ledger captured at godown, GL posted at challan issue, cascade-restore on cancel. CRITICAL gap G-070 — `fn_financial_audit_trigger` NOT attached to `sales_invoices`/`sales_challans`. |
| 5 | "modify journal posting rule" / "add Dr/Cr posting method" / "debug running balance drift" | `accounting/journal-posting-rules.md` (full §7.6 matrix) → `accounting/chart-of-accounts.md` → `accounting/reversal-vs-cancellation.md` → `accounting/financial-audit-log.md` → `accounting/running-balance.md` | sales, purchasing, inventory, finance, api, archive, deployment | ~9K | SAFETY-CRITICAL — pending accountant sign-off. Any new posting method MUST register its ledger natures in `LedgerNatureService` (see Phase 11/13 commission gaps G-061). The `enforce_balanced_journal_entry()` trigger is the crown jewel — never bypass. CTE function `rcerp_general_ledger_cte` replaces PHP-side running-balance loop. |
| 6 | "add new payment type" / "customer payment flow" / "money transfer type" | `accounting/customer-payments.md` → `accounting/money-transfers.md` → `accounting/journal-posting-rules.md` §7.6.5 + §7.6.6 → `workflows/order-to-cash.md` | sales, purchasing (unless intercompany), inventory, finance, api, archive, deployment | ~8K | Intercompany dead code: `CustomerPaymentService::postIntercompanySettlement` L772 early-returns `null` (G-001 — `banks.branch_id` missing). `MoneyTransferService::postIntercompanySettlement` uses unregistered `'intercompany'` nature + never calls `settleFromMoneyTransfer` (G-002). Intercompany settlement silently SKIPS without error. |
| 7 | "debug bank reconciliation" / "bank statement match" / "recon drift" | `accounting/bank-reconciliation.md` → `accounting/subledger-reconciliation.md` → `accounting/running-balance.md` | sales, purchasing, inventory, finance, api, archive, deployment | ~4.5K | 3 CRITICAL gaps in this single file: admin-only RLS (accountants blocked), non-posting adjustment JE, latent `reverse()` crash. Run `php artisan subledger:reconcile-ar --branch={id}` first to scope drift. |
| 8 | "add/modify manual journal entry" / "manual JE approval" | `accounting/manual-journals.md` → `accounting/journal-posting-rules.md` §7.6.8 → `workflows/approval-workflow.md` (Pattern A only) → `accounting/reversal-vs-cancellation.md` | sales, purchasing, inventory, finance, api, archive, deployment | ~10.5K | Manual journal is the ONLY entity wired into Pattern A approval engine. CRITICAL gap G-077 — `ManualJournalService::postJournal` throws on `approved` status, dead-ending the workflow. `manual_journal_lines` has no `dimension_value_id` column (dimensions G8 — segment reports miss manual JEs). |
| 9 | "debug fiscal year close" / "period close" / "year-end" | `accounting/fiscal-year-period-close.md` → `workflows/period-close-workflow.md` → `accounting/subledger-reconciliation.md` → `accounting/journal-posting-rules.md` §7.6.9 | sales, purchasing, inventory, finance, api, archive, deployment | ~8K | Period close does NOT enforce recon — close succeeds even if `subledger:reconcile-*` reports drift (must run recon manually before close). Legacy `accounting_periods` (soft-close) vs enhanced `fiscal_periods` (hard-close) can disagree — reconciliation is manual. |
| 10 | "add new stock movement type" / "reference_type" / "stock transaction" | `inventory/stock-ledger.md` → `inventory/stock-costing.md` → `accounting/journal-posting-rules.md` §7.6.3 → `workflows/inventory-to-gl.md` → `database/triggers-views-constraints.md` | sales, purchasing, finance, api, archive, deployment | ~9.5K | `stock_transactions.reference_type` matrix has 11 DB-CHECK values + 3 app-only `demand_*` values. The DB-CHECK constraint is STALE — known gap. `StockService::applyTransaction()` is the canonical entry point. Also see `inventory/uom-conversion.md` if the movement involves UoM conversion. |
| 11 | "debug stock costing" / "avg-cost drift" / "moving average" | `inventory/stock-costing.md` → `inventory/stock-ledger.md` → `inventory/warehouse-stock.md` → `workflows/inventory-to-gl.md` | sales, purchasing, finance, api, archive, deployment | ~6.5K | Moving-average cost is per-warehouse granularity. Avg-cost snapshot backfill gap — legacy `stock_transactions.unit_cost` and `sales_invoice_items.avg_cost_snapshot` are NULL for pre-migration data (drift risk on reversals). `warehouse_stock` is a derived snapshot — ledger is SSOT. |
| 12 | "warehouse transfer" / "cross-branch stock move" / "intercompany GL" | `inventory/warehouse-transfer.md` → `inventory/stock-ledger.md` → `accounting/journal-posting-rules.md` §7.6.7 → `workflows/inventory-to-gl.md` §12.1 | sales, purchasing (unless supplier return), finance, api, archive, deployment | ~7.5K | Same-branch transfers post stock movements but NO GL entries. `WarehouseTransferService::postIntercompanyGL` (L531) is DEAD CODE with fossilized bugs (dropped `branch_ledger` columns + inverted Dr/Cr — see PROJECT_OVERVIEW §11.3). Cross-branch GL is currently broken — see ISSUES_REGISTER finance cluster. |
| 13 | "debug stock take" / "stock adjustment" / "damage flow" / "maker-checker stock" | `inventory/stock-take.md` → `inventory/stock-adjustment.md` → `inventory/damage.md` → `workflows/approval-workflow.md` (Pattern B only) → `accounting/journal-posting-rules.md` §7.6.3 | sales, purchasing, finance, api, archive, deployment | ~12.5K | 7-state stock-take machine + freeze mechanism. 6-state stock-adjustment + damage machines with Pattern B maker-checker. Auto-approve below thresholds: stock adj <1000 Tk, damage <5000 Tk (admin/manager). 3 `damage_invoice_*` approval events are NOT in `NotificationRule::EVENTS` (functionally dead). |
| 14 | "add/modify purchase order" / "PO flow" / "PO draft" | `purchasing/purchase-order.md` → `workflows/procure-to-pay.md` → `coding/service-layer-conventions.md` | sales, inventory (until GRN), finance, api, archive, deployment | ~5K | PO is a draft document — NO stock/GL impact. CRITICAL gap G-027 — no `PurchaseOrderPolicy` class (RBAC relies solely on route `role:` middleware + RLS). CRITICAL gap G-037 — `received_qty` updated by `product_id` not `purchase_order_item_id` (breaks on duplicate products in same PO). |
| 15 | "debug GRN posting" / "purchase receive GL" / "purchase return" | `purchasing/purchase-receive.md` → `purchasing/purchase-return.md` → `purchasing/purchase-order.md` → `accounting/journal-posting-rules.md` §7.6.2 → `workflows/procure-to-pay.md` | sales, inventory (load if stock IN impact), finance, api, archive, deployment | ~9.5K | GRN is the economic event (atomic stock IN + Dr inventory / Cr AP + `supplier_ledger` credit + PO `received_qty` auto-flip). Returns reverse at ORIGINAL receive rate (not current avg_cost). CRITICAL gap G-025 — `purchase_receives.paid_amount` column MISSING (breaks `SupplierTransactionService::allocateToGRN`). BUG-5 active-returns guard blocks GRN cancel. CRITICAL gap G-039 — no `confirmed_by`/`confirmed_at` columns. |
| 16 | "add fixed asset" / "depreciation rule" / "asset disposal" | `finance/fixed-assets.md` → `accounting/journal-posting-rules.md` §7.6.8 → `accounting/fiscal-year-period-close.md` | sales, purchasing, inventory, api, archive, deployment | ~8.5K | 3 depreciation methods (straight-line monthly / declining-balance / units-of-production). Salvage-value floor guard. CRITICAL gap G-023 — RLS admin-only (entire subsystem non-functional for accountants/managers). CRITICAL gap G-013 — `postDepreciation` NOT wrapped in `DB::transaction` (partial-failure window). No scheduled monthly depreciation job — must run manually. |
| 17 | "modify budget" / "budget vs actual" / "dimensions / cost centers" | `finance/budgeting.md` → `finance/dimensions-cost-centers.md` → `accounting/chart-of-accounts.md` | sales, purchasing, inventory, api, archive, deployment | ~7K | NOT SAFETY-CRITICAL — analytical-only, NO GL posting. `budget_vs_actual` is a SQL VIEW with LATERAL join on `journal_lines`. CRITICAL gap — free-text `fiscal_year` breaks variance for "2026-27" format. `checkBudgetControl` is DEAD CODE (no caller). `dimension_value_id` is plumbed but NO business module passes it (segment reports always return 0). |
| 18 | "debug intercompany" / "consolidation" / "branch demand" / "elimination JE" | `finance/consolidation-intercompany.md` → `finance/branch-demand.md` → `accounting/journal-posting-rules.md` §7.6.7 → `workflows/inventory-to-gl.md` §12.1 | sales, purchasing, inventory (unless warehouse transfer), api, archive, deployment | ~16K | HEAVIEST ROW IN TABLE — 4 files, ~4K lines. 6 intercompany posting sites catalogued — 2 are DEAD CODE (FIFO settlement, WarehouseTransfer). Consolidation BYPASSES `JournalPostingService` for elimination JEs (no Dr=Cr validation, no period-close, no `journal_posting_logs` — finance G-001). `BranchDemandShadowService::compareOperation` has NO caller (shadow mode plumbed but NOT WIRED — G-004). See ISSUES_REGISTER finance G-016..G-022. |
| 19 | "add new role" / "add permission" / "modify RBAC" | `security/rbac-roles-permissions.md` → `security/auth-and-sessions.md` → `security/branch-context-security.md` → `architecture/branch-isolation-rls.md` | sales, purchasing, inventory, finance, api, archive, deployment | ~5.5K | 10 canonical roles in 3 tiers (superadmin / admin / 8 operational). `assignable_by` rules — only superadmin can create superadmin. Role enforcement via `role:` route middleware + per-model Gates/Policies. Menu-level access is DB-driven (`menus` table + `UserMenuPermission`). `SystemPolicyPolicy` is dead code (registered in `AppServiceProvider` but never used). |
| 20 | "change RLS" / "branch isolation" / "policy WITH CHECK" | `architecture/branch-isolation-rls.md` → `security/branch-context-security.md` → `database/triggers-views-constraints.md` → `database/schema-overview.md` | sales, purchasing, inventory (unless target table), finance, api, archive, deployment | ~6.5K | RLS driven by per-request `app.branch_id` GUC, set by `SetAppBranchId` (web) / `SetApiBranchContext` (API). Known RLS gaps: `notifications`/`notification_rules`/`notification_rule_recipients` (G5); all 13 reporting MVs (G1 — `reports/materialized-views.md`); 5 `branch_demand*` tables (G8 — `finance/branch-demand.md`); fixed-assets subsystem (admin-only — `finance/fixed-assets.md` G1). Always test with a non-admin user. |
| 21 | "debug audit trail" / "event logging" / "hash-chain audit" | `security/audit-trails.md` → `accounting/financial-audit-log.md` → `database/triggers-views-constraints.md` → `security/auth-and-sessions.md` | sales, purchasing, inventory, finance (unless target table), api, archive, deployment | ~7K | Hash-chain `financial_audit_log` has PARTIAL coverage — only `supplier_payments` + `customer_payments` in purchasing/sales. `fn_financial_audit_trigger` NOT attached to 14 transactional tables (G4 cluster — 21 rows in `ISSUES_REGISTER`). `AuditableMasterData` trait is bypassed by raw `DB::table()` writes in many services (purchasing G-033..G-036, sales similar). |
| 22 | "add password policy rule" / "system policy scope" / "investigation mode" | `security/password-policy.md` → `security/credential-versioning.md` → `security/system-policy-compliance.md` → `security/auth-and-sessions.md` | sales, purchasing, inventory, finance, api, archive, deployment | ~6.5K | `credential_version` invalidates stale sessions on password/role change. INVESTIGATION mode is documented as a freeze switch but currently does NOTHING (no business-logic consumer — system-policy G13). `system_policy_change` event not in `NotificationRule::EVENTS` (G4 — forwarded by DB trigger but not consumable). `ApplySystemPolicyScope` is dead code (defined but never wired). |
| 23 | "add new report" / "new dashboard widget" / "CSV export" | `reports/reports-catalog.md` → `reports/cte-reports.md` (if SQL-heavy) → `reports/materialized-views.md` (if perf-critical) → `reports/csv-export.md` (if exportable) → `reports/dashboards.md` (if dashboard widget) | sales, purchasing, inventory, security, archive, deployment | ~9-13K | Register the new report in `ReportsCatalog::categories()` (see `reports-catalog.md` §7). CRITICAL gap G-045 — NO `role:` middleware on `admin/reports` route group (salesmen can hit Trial Balance / P&L / BS / Cash Flow — RLS only enforces branch isolation, not role-based read). Catalog drift: docblock claims 18 reports but `categories()` returns 21 + 7 orphans. |
| 24 | "debug materialized view refresh" / "MV stale" / "refresh_all_report_views" | `reports/materialized-views.md` → `deployment/cron-scheduled-jobs.md` → `database/triggers-views-constraints.md` | sales, purchasing, inventory, finance, security, api, archive | ~8.5K | `refresh_all_report_views()` PL/pgSQL uses 7× `REFRESH CONCURRENTLY` in a single `BEGIN…END` — gap G6 may fail (PG forbids `CONCURRENTLY` inside transaction block). On-demand refresh claim in docblock is NOT WIRED (G15). `mv_consolidated_trial_balance` orphaned — NO scheduled refresh. 3 scheduling systems (Laravel scheduler / pg_cron / supervisor) — see `cron-scheduled-jobs.md` timezone reconciliation (Laravel Asia/Dhaka vs pg_cron UTC). |
| 25 | "add new REST v1 endpoint" / "modify API docs" / "API controller" | `api/api-overview.md` → `api/api-conventions.md` → `api/api-modules.md` → `api/api-reference-index.md` → `coding/request-validation.md` | sales, purchasing, inventory, finance, security, archive, deployment, reports | ~12K | Custom bearer-token auth via `ApiAuth` middleware (NOT Sanctum despite `sanctum:^4.0` in composer.json). 3 rate-limit tiers (30/60/120 req/min). CRITICAL gap G1 — `API_REFERENCE.md` only 14% coverage (14 documented vs ~93 routed); `ApiDocController` 23% coverage. Add new endpoint to BOTH `routes/api.php` AND `api-reference-index.md` to avoid drift. |
| 26 | "debug API auth" / "role middleware" / "API RLS context" | `api/api-overview.md` → `security/api-security.md` → `security/rbac-roles-permissions.md` → `security/auth-and-sessions.md` → `security/branch-context-security.md` | sales, purchasing, inventory, finance, archive, deployment, reports | ~8.5K | `Auth::login` on `web` guard for RBAC parity (api auth + web session coexist). Sanctum guard declared in `config/auth.php` but UNUSED. `SetApiBranchContext` sets `app.branch_id` + `app.is_admin` GUCs for inventory/branch-demand module groups. CRITICAL gap G-086 — Sales Cart/Invoices/Returns/Payments write endpoints have NO route-level `api.auth:role` gate. |
| 27 | "add new migration" / "modify DDL baseline" / "schema change" | `database/migrations-conventions.md` → `database/schema-overview.md` → `database/er-diagrams.md` → `coding/coding-standards.md` | sales, purchasing, inventory, finance, security, api, archive, deployment, reports | ~6K | 160 migrations exist (verify with `php artisan migrate:status`). DDL baseline in `database/sql/01–07_*.sql` is STALE — many tables exist only in migrations (DDL-drift cluster — 40 rows in `ISSUES_REGISTER`). ALWAYS update BOTH the migration AND the matching `database/sql/*.sql` baseline file. Cross-check with `database/triggers-views-constraints.md` if your table needs `fn_financial_audit_trigger`. |
| 28 | "modify partitioning" / "archival config" / "add new MV" / "add new trigger" | `database/partitioning.md` → `architecture/partitioning-archival.md` → `database/triggers-views-constraints.md` → `reports/materialized-views.md` (if MV) → `archive/legacy-read-only.md` (if MySQL archive touched) | sales, purchasing, inventory, finance, security, api, deployment (unless cron) | ~8-12K | TWO separate "archive" systems share NO code/tables/config: legacy MySQL archive (read-only historical search) vs PostgreSQL partition archival (pg_partman). DuckDB NOT in Docker image (csv-export G-002 — Parquet export silently falls back to CSV then DROPS archive data irretrievably). CRITICAL gap G2 — partition migration `2026_08_02_000004` regresses LISTEN/NOTIFY trigger payload to `{action, id}` only (breaks branch-scoped SSE — `architecture/realtime-events.md` G2). MVs lack RLS (G1 — pre-materialized physical rows bypass source-table RLS). |
| 29 | "deploy to VPS" / "BDIX cutover" / "production go-live" / "cron + artisan setup" | `deployment/vps-bdix-deployment.md` → `deployment/go-live-checklist.md` → `deployment/environment.md` → `deployment/cron-scheduled-jobs.md` → `deployment/artisan-commands.md` → `deployment/nginx-config.md` (if HTTPS/SSE) | sales, purchasing, inventory, finance, security, api, reports, archive, coding, business | ~10-16K | Phase 1 (VPS BDIX provisioning) is PENDING — app is code-complete but NOT yet in production; local Docker is the supported dev path. 12-step provisioning + 24-hour rollback window. Verification suite: `chart:validate` + `stock:replay-verify` + `journal:replay-verify` + `subledger:reconcile-ar/ap/inventory --branch={id}`. Manual security actions remain (PROJECT_OVERVIEW §11.1). Set legacy MySQL to READ-ONLY (revoke write privileges). |
| 30 | "debug Docker setup" / "Docker compose" / "local dev container" | `deployment/docker-setup.md` → `deployment/environment.md` → `deployment/nginx-config.md` (if routing issue) → `deployment/cron-scheduled-jobs.md` (if worker issue) | sales, purchasing, inventory, finance, security, api, reports, archive, coding, business | ~5-7.5K | 5-container topology, 9-step entrypoint. Windows-NTFS bind-mount UID fix + `node_modules/.package-hash` cache-bust fix. DuckDB NOT in Docker image (csv-export G-002). `ListenNotifyWorker` is supervised ONLY by `docker-compose` `restart: unless-stopped` — NOT scheduled by Laravel cron, NO in-repo supervisor/systemd config (realtime-events G4 — production deployment gap). |
| 31 | "debug notification" / "SSE" / "realtime event" / "double dispatch" | `architecture/realtime-events.md` → `workflows/notification-workflow.md` → `security/audit-trails.md` | sales, purchasing, inventory, finance, api, archive, deployment, reports | ~14K | HEAVY ROW — 2 of the largest files in the KB (`realtime-events.md` 1197L + `notification-workflow.md` 1897L). CRITICAL gaps G-076/G-078/G-079: double-dispatch on 4 events (`sales_finalize`/`challan_create`/`payment_receive`/`return_created`); wrong-event-on-update (`rcerp_sales_return` always forwards as `return_created`); worker-forwarded events have NO `$context` (context-aware recipients silently resolve empty). Recommended single fix: remove the 5 `CHANNEL_EVENT_MAP` entries so `forwardToNotificationService` becomes a no-op. |
| 32 | "add approval workflow" / "maker-checker" / "SoD" | `workflows/approval-workflow.md` → `security/rbac-roles-permissions.md` → `coding/service-layer-conventions.md` | sales, purchasing, inventory (unless target entity), finance, api, archive, deployment, reports | ~8K | Two parallel, non-intersecting patterns — Pattern A (generic configurable engine, used by 1 entity only: `manual_journal`) vs Pattern B (entity-specific maker-checker columns, used by `stock_adjustment`/`stock_take`/`damage`). CRITICAL gap G-075 — architectural inconsistency. CRITICAL gap G-077 — `ManualJournalService::postJournal` throws on `approved` status, dead-ending Pattern A workflow. Pattern A's `cancel()` + `notifyApprovers()` + `notifyRequester()` are DEAD CODE (G4). |
| 33 | "modify coding standard" / "add config-driven business rule" / "debug legacy MySQL archive read" | `coding/coding-standards.md` → `coding/config-driven-rules.md` → `business/business-rules-catalog.md` → `coding/service-layer-conventions.md` (if service change) → `archive/legacy-overview.md` + `archive/anti-corruption-layer.md` + `archive/legacy-read-only.md` (ONLY if legacy archive subtask) | sales, purchasing, inventory, finance, security, api, deployment, reports | ~5-13K | This row covers 3 sub-tasks — pick files by subtask: (a) coding-standard change = first 4 files; (b) config-driven business rule = first 3 files + relevant module overview; (c) legacy archive = the 3 `archive/*` files. NEVER bypass services — controllers stay thin, business logic in `app/Services/`. Config files in `laravel/config/` (21 files) — use `config()` helper, not `env()`, in code. Legacy ACL = `app/Archive/` DTOs + `LegacyMySQLRepository` PDO + `ArchiveService` PG-first unified search with 1-hour Redis cache. |

---

## Default routing (fallback when no pattern matches)

If none of the 33 rows above match your task, use this fallback:

1. Load `README.md` (folder map) → identify which subfolder your task touches.
2. Load the subfolder's overview file. Every subfolder has one:
   - `architecture/high-level-architecture.md`
   - `business/business-model.md`
   - `coding/coding-standards.md`
   - `database/schema-overview.md`
   - `security/auth-and-sessions.md`
   - `accounting/chart-of-accounts.md` (or `accounting/journal-posting-rules.md` for GL)
   - `inventory/stock-ledger.md` (or `inventory/stock-costing.md` for cost logic)
   - `purchasing/purchase-order.md`
   - `sales/sales-overview.md`
   - `finance/fixed-assets.md` (or `finance/budgeting.md` for non-asset finance)
   - `workflows/order-to-cash.md` (or another workflow file matching your domain)
   - `reports/reports-catalog.md`
   - `api/api-overview.md`
   - `archive/legacy-overview.md`
   - `deployment/environment.md`
3. From that overview, identify the 1-2 crown-jewel files for your specific feature (every overview has a "Related modules" or "Cross-references" section).
4. Load `accounting/journal-posting-rules.md` (specifically §7.6.X for your module) ONLY if your task touches GL postings.
5. Load `workflows/<relevant-workflow>.md` ONLY if your task spans multiple modules.
6. If a routing caveat mentions a G# gap ID, load `ISSUES_REGISTER.md` and look up that ID before editing.

**Anti-pattern:** Do NOT load all files in a subfolder "to be safe." That defeats the purpose. If unsure, load the overview file first, then come back to `DISPATCH.md` with a more specific task description.

**When to expand scope:** If during the task you discover the change touches another module (e.g. a sales-invoice fix also requires a journal-posting-rule change), come back to DISPATCH.md, find the matching row for the new module, and load the additional files listed there. Don't proactively load them upfront.

---

## Quick-reference: file purpose cheat sheet

> One line per file. Scan this to find the right file without loading it.
> All paths are relative to `AI_CONTEXT/`. File counts in parentheses per group.

### Top-level (6)
- `README.md` — Folder map + status; the entry point for the entire `AI_CONTEXT/` knowledge base
- `PROJECT_OVERVIEW.md` — What RC_ERP_v2 is: Laravel 12 + PostgreSQL 16 ERP, 4 principles, tech stack, module map, known limitations, future roadmap summary
- `GLOSSARY.md` — Business + technical vocabulary (Accounting, Inventory, Sales/Purchasing, Business/Org, Technical)
- `IMPLEMENTATION_PLAN.md` — Master documentation roadmap (22 phases 0–21) + standards + AI Instructions §7
- `ROADMAP.md` — Forward product roadmap, 4 horizons (H1 Cutover, H2 Stabilize, H3 Extend, H4 Scale) with 38-row summary table
- `ISSUES_REGISTER.md` — Consolidated gap catalogue (356 rows, 81 CRITICAL, auto-extracted from all 104 files; re-extract via `node scripts/extract_issues_register.js`)

### architecture/ (6)
- `high-level-architecture.md` — System topology (Laravel + PostgreSQL + Redis, 4-layer design, deployment shape)
- `layered-design.md` — Controller → Service → Model → DB layer responsibilities + cross-layer conventions
- `module-map.md` — Master map of every functional module with web/API routes + controllers + services + corresponding `AI_CONTEXT/` folder
- `branch-isolation-rls.md` — Multi-branch Row-Level Security invariant via per-request `app.branch_id` GUC
- `realtime-events.md` — LISTEN/NOTIFY → PHP worker → Redis Lists/PubSub → SSE fan-out pipeline (1197L crown jewel, 20 gaps)
- `partitioning-archival.md` — PostgreSQL partition strategy + pg_partman retention config (cross-cutting view)

### business/ (4)
- `business-model.md` — Distribution/retail business model; single-currency (BDT); no manufacturing module
- `organizational-structure.md` — Multi-branch + multi-warehouse + employees + users + `credential_version` invalidation
- `core-workflows.md` — High-level end-to-end business workflows (Procure-to-Pay, Order-to-Cash, Inventory-to-GL, Period-Close, Notification)
- `business-rules-catalog.md` — Catalogue of business rules (config-driven, threshold-based, role-based, approval-gated)

### accounting/ (14)
- `chart-of-accounts.md` — CoA structure + ledger natures registry + `LedgerNatureService` (lookup for GL Dr/Cr target ledgers)
- `journal-posting-rules.md` — SAFETY-CRITICAL crown jewel: ~40 Dr/Cr posting methods (§7.6.1–§7.6.9) + `enforce_balanced_journal_entry()` trigger + `JournalPostingService` 480L
- `subledger-reconciliation.md` — AR/AP/inventory sub-ledger recon + drift detection + `subledger:reconcile-*` artisan commands
- `reversal-vs-cancellation.md` — Reversal (append-only JE via `JournalReversalService`) vs cancellation (status flag) pattern choice
- `fiscal-year-period-close.md` — Fiscal periods + `validatePeriod` consumer + soft-close vs hard-close distinction
- `running-balance.md` — `branch_ledger` + ledger running-balance maintenance + drift detection
- `financial-audit-log.md` — Hash-chain `financial_audit_log` + `fn_financial_audit_trigger` attachment matrix (which tables have it, which don't)
- `customer-payments.md` — Customer payment flow (Dr AR / Cr Cash) + dead `postIntercompanySettlement` L772
- `supplier-transactions.md` — Supplier payments + GRN allocation + dead `postIntercompanySettlement` L616
- `employee-transactions.md` — Employee advances/loans/settlements + Dr/Cr matrix (5 variants)
- `money-transfers.md` — Bank-to-bank transfers + dead `postIntercompanySettlement` (uses unregistered `'intercompany'` nature)
- `manual-journals.md` — Manual journal entries + Pattern A approval engine integration + 6-state machine
- `other-income-expense.md` — Non-operational income/expense postings (donations, fines, sundry)
- `bank-reconciliation.md` — Bank statement matching + 3 CRITICAL gaps (admin-only RLS, non-posting adjustment JE, latent `reverse()` crash)

### api/ (4)
- `api-overview.md` — REST v1 surface (`/api/v1`), custom bearer-token auth, 3 rate-limit tiers, RLS context via `SetApiBranchContext`
- `api-conventions.md` — JSON envelope `{data, message, meta}`, pagination contract, error matrix (200/201/204/400/401/403/404/409/422/429/500), idempotency pattern
- `api-modules.md` — Per-module endpoint catalogue (15 controllers, 101 endpoints across 14 module groups)
- `api-reference-index.md` — Endpoint reference index (cross-ref to STALE `laravel/docs/api/API_REFERENCE.md` which has 14% coverage)

### archive/ (3)
- `legacy-overview.md` — Legacy PHP/MySQL codebase origin (38 controllers, 43 models, 22 hand-rolled framework files, ~7 years of operation)
- `anti-corruption-layer.md` — `app/Archive/` DTOs + `LegacyMySQLRepository` PDO impl + `ArchiveService` PG-first unified search with 1-hour Redis cache
- `legacy-read-only.md` — 5-layer read-only enforcement plan (MySQL `GRANT SELECT` + PDO `ERRMODE_EXCEPTION` + interface + `ARCHIVE_ENABLED` flag + Docker profile) + `config/archive.php` anatomy

### changelog/ (2)
- `CHANGELOG.md` — `AI_CONTEXT/` documentation change log (per-phase entries, what was added/modified)
- `PRODUCT_CHANGELOG.md` — Product history (13 migration phases, 8 partitioning sub-phases + HOTFIX-9, removed features, sales remediation R-items, cross-cutting fixes)

### coding/ (7)
- `coding-standards.md` — Engineering standards (controllers thin, services own logic, Larastan `^3.0` + Pint `^1.18` enforcement)
- `service-layer-conventions.md` — Service class shape + 14 namespaces + 78 services + singleton bindings in `AppServiceProvider`
- `model-conventions.md` — Eloquent model conventions + `BranchScope` + `AuditableMasterData` trait (often bypassed — gap)
- `request-validation.md` — `FormRequest` conventions + inline `$request->validate` anti-pattern (11-row gap cluster)
- `testing-standards.md` — PHPUnit conventions + 107 tests + 14 factories + test-debt gaps
- `error-handling.md` — Exception hierarchy + error response shape + logging conventions
- `config-driven-rules.md` — Config-as-code patterns + 5 system policy scopes + Phase-5 hybrid config (file-backed + DB-runtime)

### database/ (6)
- `schema-overview.md` — PostgreSQL 16 schema overview (7 raw SQL DDL files `01–07_*.sql` + 160 migrations)
- `er-diagrams.md` — Entity-relationship diagrams (Mermaid) for major table groups (accounting, sales, purchasing, inventory, security)
- `migrations-conventions.md` — Migration standards + naming + rollback safety + 2 SQL sources rule (migrations + DDL baseline)
- `triggers-views-constraints.md` — All triggers (`fn_financial_audit_trigger` + `enforce_balanced_journal_entry()` + 10 LISTEN/NOTIFY) + views + CHECK constraints
- `partitioning.md` — Partition strategy details + pg_partman config + 8 partitioning sub-phases (Phase 10.1.1–10.1.8)
- `etl-legacy-migration.md` — pgloader config + 14 post-load fixes + sequence sync + verification commands

### deployment/ (7)
- `environment.md` — Env-var catalogue (60+ vars across `config/*.php`) + `APP_KEY` rotation procedure + production cheatsheet + hygiene audit
- `docker-setup.md` — 5-container dev stack + 9-step entrypoint + Windows-NTFS bind-mount UID fix + `node_modules/.package-hash` cache-bust fix
- `vps-bdix-deployment.md` — VPS BDIX bare-metal deployment (Ubuntu 22.04 + PHP 8.4-FPM + PG 16 + Redis 7 + Nginx + Let's Encrypt + supervisor + 12-step provisioning)
- `nginx-config.md` — Nginx reverse-proxy config (Docker + VPS variants) + SSE-specific tuning + dual-root routing + security headers + gzip
- `artisan-commands.md` — 27 custom artisan commands catalogue (10 verification + 3 partitioning ops + 3 migration + 1 setup + 10 operational)
- `cron-scheduled-jobs.md` — 3 scheduling systems (Laravel scheduler with 6 jobs + pg_cron with 7 jobs + supervisor with 2 long-running workers) + timezone reconciliation (Asia/Dhaka vs UTC)
- `go-live-checklist.md` — 12-section go-live checklist + 5 sign-offs + 24-hour rollback plan + acceptable-risk documentation

### finance/ (5)
- `fixed-assets.md` — Fixed asset register + 3 depreciation methods (straight-line / declining-balance / units-of-production) + 4 disposal types + 30 gaps (5 CRITICAL)
- `budgeting.md` — 4-state budget lifecycle (draft→active→closed/cancelled) + spreadsheet grid entry + `budget_vs_actual` SQL VIEW (analytical-only, NO GL posting)
- `dimensions-cost-centers.md` — 5 dimension types (cost_center is a TYPE not a separate table) + segment P&L/BS reporting + `dimension_value_id` plumbing (NOT wired — G4)
- `consolidation-intercompany.md` — 3-state consolidation run + 5-type `EliminationRule` + 6 intercompany posting sites (2 dead code) + `mv_consolidated_trial_balance`
- `branch-demand.md` — 4-state inter-branch demand lifecycle + 7 services (5,652 LOC) + shadow mode (off/passive/active) + FIFO settlement DEAD CODE + 25-column weekly report

### inventory/ (9)
- `stock-costing.md` — Moving-average cost derivation + per-warehouse `avg_cost` granularity + backfill gap for legacy data
- `stock-ledger.md` — `stock_transactions` table + 11 DB-CHECK + 3 app-only `demand_*` `reference_type` values + `StockService::applyTransaction()` canonical entry point
- `warehouse-stock.md` — `warehouse_stock` derived snapshot (SSOT = ledger, not the snapshot) + RLS gap (no policy)
- `stock-take.md` — 7-state stock-take machine + freeze mechanism + Pattern B maker-checker + auto-approve shortcuts
- `stock-adjustment.md` — 6-state stock-adjustment machine + Pattern B maker-checker + auto-approve below 1000 Tk + Phase-5 hybrid config
- `damage.md` — 6-state damage machine + Phase-5 Good/Damage condition + 3 dead `damage_invoice_*` approval events
- `warehouse-transfer.md` — Same-branch-only transfers + dead `postIntercompanyGL` (L531) with inverted Dr/Cr fossilized bugs
- `uom-conversion.md` — Unit-of-measure conversion (Phase 5; only `stock-adjustment` has UOM columns currently)
- `stock-verification.md` — Distributed stock verification (Stock Take + Reconciliation + console commands — no single `StockVerification*` feature)

### purchasing/ (4)
- `purchase-order.md` — PO draft document (NO stock/GL impact) + `received_qty` auto-flip on GRN + G5 `received_qty`-by-`product_id` bug
- `purchase-receive.md` — GRN atomic stock IN + Dr inventory / Cr AP + `supplier_ledger` credit + G1 `paid_amount` MISSING
- `purchase-return.md` — Returns reverse at ORIGINAL receive rate + Phase-5 Good/Damage condition + G11 no `confirmed_by`/`confirmed_at`
- `purchase-audit.md` — 12-section `PurchaseAuditService` health-check + 3-layer audit infrastructure (hash-chain + `user_audit_log` + on-demand)

### reports/ (5)
- `reports-catalog.md` — `ReportsCatalog` helper (28 reports) + `ReportController` 33 methods + 4 SQL patterns + 3 refresh strategies
- `materialized-views.md` — 13 MVs discovered + `refresh_all_report_views()` PL/pgSQL + 3 scheduling systems + 14 gaps (4 CRITICAL: no RLS, DDL stale, orphaned MV refresh, zero tests)
- `cte-reports.md` — 4 PostgreSQL `STABLE` PL/pgSQL functions (`rcerp_today_summary`, `rcerp_ar_aging_cte`, `rcerp_general_ledger_cte`, `rcerp_gross_margin_cte`)
- `csv-export.md` — 22 HTTP export endpoints + `CsvExporter` streaming service + DuckDB Parquet archival + 30 gaps (3 CRITICAL: no role middleware, no DuckDB in Docker, no BOM on some endpoints)
- `dashboards.md` — 3 dashboard tiers (`UserPerformanceDashboardController` 2246L god-class + `DashboardApiController` REST + `LegacyDashboardController` DEAD)

### sales/ (8)
- `sales-overview.md` — Order-to-Cash summary + decoupled revenue recognition (invoice) vs stock movement (challan) + 6 CRITICAL gaps (cross-cutting summary)
- `sales-invoice.md` — Invoice finalize: Dr AR / Cr Revenue + G2 mobile API `update` broken (no `items[]`) + G5 DDL `04_sales.sql` stale
- `sales-challan.md` — 3-step godown workflow (blank-godown → godown prep → challan issue) + Phase-6 transport-edit deferred-GL + G3 `StockAvailabilityService` bug
- `sales-cart.md` — Per-user × per-customer × per-branch JSONB draft cart + R6 3-col unique key + G1 `customers.shop_name` MISSING (runtime `SQLSTATE[42703]`)
- `sales-return.md` — Returns at ORIGINAL avg_cost snapshot + `SalesReturnReversalGuard` stock-shortage pre-check + Phase-5 Good/Damage condition
- `commission.md` — 4-type commission rule engine (flat/tiered/product_group/target_bonus) + GiST EXCLUDE constraint + G1/G2/G3 dead auto-calc pipeline
- `transport-cost.md` — Phase-6 transport-edit deferred-GL workflow (snapshot + sub-ledger at godown, GL at issue, cascade-restore on cancel)
- `sales-audit.md` — 3-section `ReportController::computeSalesAuditChecks` on-demand + gap vs `PurchaseAuditService`'s 12 sections

### security/ (8)
- `auth-and-sessions.md` — Laravel Auth + web sessions + custom bearer-token API auth (NOT Sanctum despite `sanctum:^4.0` in `composer.json`)
- `rbac-roles-permissions.md` — 10 canonical roles in 3 tiers + `assignable_by` rules + `role:` route middleware + per-model Gates/Policies + DB-driven menu access
- `credential-versioning.md` — `credential_version` invalidates stale sessions on password/role change + `LoginRateLimiter` + remember-me manager
- `password-policy.md` — Password complexity + bcrypt + reset flow + account lockout policy
- `audit-trails.md` — `fn_financial_audit_trigger` attachment matrix + `UserAuditLogger` + `AuditableMasterData` trait (often bypassed by `DB::table()` writes)
- `system-policy-compliance.md` — 5 system policy scopes + INVESTIGATION mode (currently a no-op, G13) + 26-item verification table (Phase 14 re-audited)
- `branch-context-security.md` — `SetAppBranchId` (web) + `SetApiBranchContext` (API) middleware + `app.branch_id` GUC + `EnforceBranchIsolation` middleware
- `api-security.md` — API auth (custom bearer + SHA-256 hashed `api_token`) + `ApiRateLimit` Redis-backed middleware (3 tiers) + RLS context for API

### workflows/ (6)
- `approval-workflow.md` — Pattern A (generic configurable engine, 1 entity only: `manual_journal`) vs Pattern B (entity-specific maker-checker) + 16 gaps (4 CRITICAL)
- `notification-workflow.md` — `NotificationService` 262L crown jewel + `EVENT_META` (17 events) + 9-way recipient resolution + 18 gaps (3 CRITICAL G1/G2/G3 — double dispatch + wrong event + missing context)
- `order-to-cash.md` — End-to-end O2C workflow (sales-order → challan → invoice → receipt → GL) with Dr/Cr postings inline per `journal-posting-rules.md` §7.6.1
- `procure-to-pay.md` — End-to-end P2P workflow (PO → receive → invoice → payment → GL) with Dr/Cr postings inline per `journal-posting-rules.md` §7.6.2
- `inventory-to-gl.md` — Stock movement → journal posting map (per movement type) + dead `postIntercompanyGL` §12.1 + 7 intercompany-posting-site inventory
- `period-close-workflow.md` — Month/year-end close (fiscal-period lock → sub-ledger recon → MV refresh → trial-balance sign-off) + 12-section checklist

**Total files listed: 104** (6 top-level + 6 architecture + 4 business + 14 accounting + 4 api + 3 archive + 2 changelog + 7 coding + 6 database + 7 deployment + 5 finance + 9 inventory + 4 purchasing + 5 reports + 8 sales + 8 security + 6 workflows = 104).

---

## Anti-patterns (what NOT to do)

| Anti-pattern | Why it's wrong | Do this instead |
|---|---|---|
| "Load all files in `sales/` to fix a sales invoice bug" | Burns ~3K tokens on files you may not need (e.g. `commission.md`, `transport-cost.md`) | Load `sales/sales-overview.md` first, then the specific file (`sales-invoice.md`) per DISPATCH row 1 |
| "Load `journal-posting-rules.md` for every accounting task" | It's 541 lines (~2K tokens) — only needed for GL postings, not for budgets or dimensions | Only load if task touches Dr/Cr entries. Use §7.6.X sub-section pointers to focus reading |
| "Load all of `deployment/` for any deploy-adjacent task" | 7 files, ~5.2K lines (~21K tokens) — extreme overkill | Load only `environment.md` for env-var questions, `cron-scheduled-jobs.md` for cron questions, `docker-setup.md` for Docker questions, `vps-bdix-deployment.md` for production cutover |
| "Skip `GLOSSARY.md` — I'll figure out terms from context" | Domain terms (`challan`, `godown`, `BDT`, `BDIX`, `subledger`, `elimination`) will confuse the AI; misreadings compound | Always load `GLOSSARY.md` in Tier 0 alongside `README.md` + `PROJECT_OVERVIEW.md` |
| "Load `accounting/journal-posting-rules.md` AND `accounting/financial-audit-log.md` AND `accounting/subledger-reconciliation.md` for any GL-adjacent task" | 3 files, ~1.4K lines (~6K tokens) — overkill for a one-line Dr/Cr tweak | Load only `journal-posting-rules.md` for posting-rule changes (DISPATCH row 5); bring in the others only if the caveat explicitly says so |
| "Load `archive/legacy-overview.md` for every task that mentions 'archive'" | The word "archive" is overloaded — legacy MySQL archive vs PostgreSQL partition archival share nothing but the name | Confirm which "archive" via DISPATCH row 28 (partition archival) vs row 33 (legacy MySQL archive). The two systems share NO code |
| "Load `workflows/notification-workflow.md` for a 'notification' tweak" | It's 1897 lines (~7.5K tokens) — only needed for the rule engine + dispatch flow | For a one-line UI toast tweak, load `architecture/realtime-events.md` instead. For rule-engine or dispatch issues, load both per DISPATCH row 31 |
| "Load `ISSUES_REGISTER.md` upfront to find known gaps" | 470 lines (~2K tokens) — useful but only when a routing caveat cites a G# ID | Load `ISSUES_REGISTER.md` in Tier 3, only when a caveat mentions a specific G# gap ID. Use `Ctrl-F`/grep to find that ID, don't read the whole register |

---

## Maintenance

- **When to update:** whenever a new `AI_CONTEXT/` file is added (bump the file count from 104), or a new task pattern becomes common (add a routing row), or a file's section numbering changes (update §X.X pointers).
- **How to update:**
  1. Add the new file to the **Quick-reference cheat sheet** with a one-line purpose (read its first 20 lines to write an accurate one-liner — never guess from filename).
  2. If the file is a crown jewel for an existing task pattern, add it to that row's Load order with a `§X.X` pointer if helpful.
  3. If the file defines a new task pattern not covered by any existing row, add a new row to the routing table. Update the total row count in this section's intro.
  4. Re-verify every file path in the new/changed row exists on disk: `ls AI_CONTEXT/<path>` must return 0 (success).
  5. Update the "Last reviewed" date in the header block (Asia/Dhaka timezone — run `date`).
- **Audit cadence:** monthly — verify all files in the cheat sheet still exist (run `find AI_CONTEXT -name "*.md" -type f | wc -l` and compare to the count in the cheat sheet's Total line), verify routing rows still point to existing files, verify §X.X pointers still resolve (use `grep -n "^#### 7\.6\." accounting/journal-posting-rules.md` style checks).
- **Re-extract issues register:** weekly — run `node scripts/extract_issues_register.js` to refresh `ISSUES_REGISTER.md`. If new G# IDs appear that affect routing caveats, add cross-references here.
- **Ownership:** this file is owned by whoever is doing the most-recent AI_CONTEXT documentation phase. Currently that is the Phase 22 subagent (post-issues-register). The next owner is whoever picks up Phase 23+ work or H1 cutover execution.

---

*Last reviewed: 2026-08-04. For the full file inventory, see `README.md` folder map. For known gaps cross-referenced from this file's Caveats column, see `ISSUES_REGISTER.md`. For horizon-priority of fixes, see `ROADMAP.md` H1–H4.*
