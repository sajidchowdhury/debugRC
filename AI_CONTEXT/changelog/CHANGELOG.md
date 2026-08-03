# AI_CONTEXT Change Log

> **Module:** Meta
> **Audience:** Maintainers + AI assistants
> **Status:** Canonical
> **Last reviewed:** Initial creation (Phase 0)
> **Source of truth:** This file

This is the change log for the **`AI_CONTEXT/` knowledge base** (documentation changes),
NOT the ERP product change log. The product change log will be created in Phase 21
(`changelog/PRODUCT_CHANGELOG.md`).

**Convention:** newest entries at the top. Each entry: date, file, one-line summary,
author/agent. Append-only — never delete historical entries.

---

## Format

```
- YYYY-MM-DD — <file path> — <summary> — <author>
```

## Entries

- 2026-08-03 — `AI_CONTEXT/coding/error-handling.md` — Created Phase 4 doc: two-tier exception model (services throw RuntimeException/InvalidArgumentException; controllers catch \Throwable), 2 custom exception classes (WarehouseFrozenForCountException, StockTakeNegativeStockException) with structured payloads, single global render hook in bootstrap/app.php, AuditableMasterData re-throw-inside-transaction pattern (PostgreSQL 25P02 defense), UserAuditLogger dual-write (DB + file), no JSON:API/RFC 7807 shape, 12 known edge cases + 6 future-improvement items.
- 2026-08-03 — `AI_CONTEXT/coding/config-driven-rules.md` — Created Phase 4 doc: 21 config files, 3-layer pattern (.env → config/*.php → service config() read), 14 canonical exemplars (accounting.gl_reconciliation_tolerance, damage.approval.threshold + role matrix, stock_adjustment.* 11 knobs, roles.php 10-role/3-tier map, sales.stale_draft_*, branches.colors, api.rate_limit, shadow_mode state machine), config:cache + env()-outside-config safety, 12 known edge cases including the gl_reconciliation_tolerance duplication inconsistency.
- 2026-08-03 — `AI_CONTEXT/coding/testing-standards.md` — Created Phase 4 doc: PHPUnit 11.5 (NOT Pest), real PostgreSQL rcerp_test DB (NOT in-memory sqlite), DatabaseTransactions trait (NOT RefreshDatabase — baseline migration too slow), setUp pattern (parent + actingAsRole + app(Service)), BuildsRoleUsers helper (Branch+Employee+User chain), 12 InsertsXDependencies helper traits (DB::table insertGetId for complex FK tables), 14 factories with static sequence + uniqid() suffix, 5-model HasFactory coverage, 2 minimal seeders, 107 test files / ~1185 tests / 87.93% coverage.
- 2026-08-03 — `AI_CONTEXT/coding/request-validation.md` — Created Phase 4 doc: 41 FormRequests + 3 custom Rule classes (WarehouseBelongsToBranch, WarehouseHasStock, WarehouseTransferItemHasAvailableStock — all ValidationRule interface PHP 8.4 closure style), authorize() always returns true (RBAC via route middleware + defense-in-depth Policy), array-form rules() canonical (pipe-string legacy), toServicePayload() newer pattern, bodyParameters() for Scribe, FormRequest vs inline validate inconsistency (59 controllers still inline).
- 2026-08-03 — `AI_CONTEXT/coding/model-conventions.md` — Created Phase 4 doc: ~96 models, $fillable mandatory ($guarded=[] forbidden), $casts conventions (decimal:2 money / decimal:4 qty / date / datetime / boolean / integer FK), $hidden for secrets + GENERATED columns, relationships with explicit FQN return types + FK column, local + global scopes (BranchScope + 2 transfer variants), booted() wiring, AuditableMasterData trait (37 models, re-throw pattern), ApplySystemPolicyScope dead code, NO Attribute::make/$with/$appends, constants for closed value lists, isX() state helpers, User role delegation to Employee.
- 2026-08-03 — `AI_CONTEXT/coding/service-layer-conventions.md` — Created Phase 4 doc: 78 services across 14 namespaces, no BaseService (standalone classes), constructor DI with promoted properties, DB::transaction closure form universal (zero beginTransaction/afterCommit), lockForUpdate + pg_advisory_xact_lock patterns, RuntimeException canonical (353 throws / 34 services, zero generic Exception), no DTOs (typed arrays + PHPDoc), verb+noun naming, DB::table writes over Eloquent inside transactions, raw SQL heredoc for verification queries, 3 static-helper exceptions, inter-service composition graph, reversal pattern, finalize-invoice sequence diagram.
- 2026-08-03 — `AI_CONTEXT/coding/coding-standards.md` — Created Phase 4 overview doc: pinned stack (PHP 8.2, Laravel 12, PostgreSQL 16, Redis, PHPUnit 11.5, Pint 1.18, Larastan 3.0), PHP language rules (no backed enums — VARCHAR+CHECK instead, no app() in services), naming matrix (tables/models/methods/columns/routes/migrations/indexes), Pint preset:laravel formatting, file layout, Laravel 11+ bootstrap/app.php fluent API, AppServiceProvider 7 singletons + 8 Gate::policy registrations, app/ folder inventory (78 services / 96 models / 81 controllers / 41 FormRequests / 8 policies / 3 rules / 28 commands), empty Jobs/Listeners/Mail folders (synchronous design), 10-item tech-debt list.
- 2026-08-03 — `AI_CONTEXT/IMPLEMENTATION_PLAN.md` — Phase 4 marked [x] Complete; status + last-reviewed updated. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/etl-legacy-migration.md` — Created Phase 3 doc: MySQL→PostgreSQL ETL pipeline (pgloader config + 14 post-load fixes + sequence sync + 4-part verify), replay methodology (38,775 stock txns / 521 invoices / 311 GRNs / 550 payments, zero-drift acceptance), Anti-Corruption Layer (ArchiveService + LegacyMySQLRepository + DTOs), 22-row conversion-rules appendix + 6 critical design decisions. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/partitioning.md` — Created Phase 3 doc: 30 partitioned tables (RANGE by date), pg_partman config, 84/36-month retention matrix (retention_keep_table=true, archive schema), FK workaround (trigger-based + composite), partition-health subsystem (8 migrations + 6 functions + 5 views + 3 console commands), Parquet export flow, HOTFIX-9 entry_date sync. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/triggers-views-constraints.md` — Created Phase 3 doc: ~93 triggers (Dr=Cr, negative-stock, hash-chained audit, trigger-based FKs, LISTEN/NOTIFY, over-allocation, HOTFIX-9), ~66 functions, 14 views, 13 MVs, ~152 CHECKs, 2 EXCLUDE constraints, RLS policy set, ~700 indexes (101 BRIN, 6 GIN, 4 GiST, ~25 partial, ~15 covering). — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/migrations-conventions.md` — Created Phase 3 doc: 160 migrations (2025-01 to 2026-08), raw SQL DDL as canonical source + first-migration loader, Pattern A (DB::statement heredoc) vs Pattern B (Blueprint) decision guide, CHECK/trigger/policy/partition/RLS creation patterns, up/down discipline, do/don't quick reference. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/er-diagrams.md` — Created Phase 3 doc: per-domain Mermaid ER diagrams (Auth/Master, Accounting, Stock, Sales, Purchase, Payment, Intercompany, Compliance, Budgeting/Fixed Assets) with relationship tables (cardinality, FK, ON DELETE), trigger-based FK strategy for partitioned parents, spine-table diagram. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/database/schema-overview.md` — Created Phase 3 doc: ~100 tables across 20 domains (78 base + ~40 migration-added), 4 extensions (pgcrypto, btree_gist, pg_partman, pg_cron), 3 schemas (public, partman, archive), ENUM-as-VARCHAR+CHECK pattern, naming conventions, 13 MVs, spine tables, quantitative summary appendix. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/IMPLEMENTATION_PLAN.md` — Phase 3 marked [x] Complete; status + last-reviewed updated. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/business/business-rules-catalog.md` — Created Phase 2 doc: 17 cross-cutting business rules (Dr=Cr, reversal-not-mutation, sub-ledger reconciliation, period close, moving-average cost, negative-stock guard, branch isolation, document numbering, warehouse freeze, credential versioning, approval/maker-checker, 3-layer audit trail, system policy, credit limit, intercompany settlement, over-allocation prevention, single currency) with enforcement-layer matrix and ledger-nature appendix. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/business/core-workflows.md` — Created Phase 2 doc: end-to-end value chain (procure→stock→sell→dispatch→collect→return→close) + inter-branch demand + money transfer, with per-stage sequence diagrams, Dr/Cr tables, service/controller quick-reference, and status-enum appendix. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/business/organizational-structure.md` — Created Phase 2 doc: Company→Branch→Warehouse→Employee/User hierarchy, 10 roles in 3 tiers, 3-layer branch-isolation stack, menu permissions, ER diagram, login + admin-override sequences, role/branch appendices. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/business/business-model.md` — Created Phase 2 doc: RC_ERP as Bangladeshi B2B wholesale/distribution ERP (4 branches, 6 warehouses, BDT, July–June fiscal year), value-chain Mermaid diagram, business scale (verified from migration replay), revenue/COGS model. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/IMPLEMENTATION_PLAN.md` — Phase 2 marked [x] Complete; status + last-reviewed updated. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/partitioning-archival.md` — Created Phase 1 doc: ~30 partitioned tables, pg_partman config, retention matrix (84/36 months), trigger-based FK workaround, Parquet export flow, health observability. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/realtime-events.md` — Created Phase 1 doc: LISTEN/NOTIFY → worker → Redis → SSE pipeline, 10 PG channels, SseController polling model, NotificationService rule-based dispatch. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/branch-isolation-rls.md` — Created Phase 1 doc: 3-layer defense-in-depth (BranchScope / EnforceBranchIsolation / RLS), app.branch_id GUC mechanics, admin bypass + audit, cross-branch module exceptions. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/module-map.md` — Created Phase 1 doc: full module index (foundation/inventory/purchasing/sales/accounting/finance/platform) with web+API routes, controllers, services, models, entry points + dependency graph. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/layered-design.md` — Created Phase 1 doc: 4-layer design (HTTP/Service/Model/DB), "never bypass services" rule, defense-in-depth authorization, web vs API split, Blade/console conventions. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/architecture/high-level-architecture.md` — Created Phase 1 doc: tech stack layers, request lifecycle sequence diagram, middleware order, 5 cross-cutting mechanisms (RLS, accounting integrity, audit, realtime, partitioning). — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/IMPLEMENTATION_PLAN.md` — Phase 1 marked [x] Complete; status + last-reviewed updated. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/changelog/CHANGELOG.md` — Created this change log and initialized it with Phase 0 entries. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/GLOSSARY.md` — Created glossary: accounting, inventory, sales/purchasing, business/org, and technical terms + acronym index. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/PROJECT_OVERVIEW.md` — Created project overview: scope (Laravel app only), 4 principles, tech stack (Laravel 12 / PHP 8.2 / PG 16 / Redis), service-layer namespaces, module map, RBAC overview, migration status, removed features, limitations, roadmap. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/README.md` — Created knowledge-base entry point: scope (in/out), how to use, folder map, current status. — main (orchestrator)
- 2026-08-03 — `AI_CONTEXT/IMPLEMENTATION_PLAN.md` — Created master roadmap (Phase 0-plan): overall goal, documentation standards, folder structure, 22 documentation phases, progress tracker, mandatory content template, AI instructions. — main (orchestrator)

---

*Next phase to be logged here when commissioned.*
