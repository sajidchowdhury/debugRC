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
