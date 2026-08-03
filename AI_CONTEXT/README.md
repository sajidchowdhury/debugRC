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
- **Phases 3–21:** Not started. Execute one phase at a time per the roadmap.

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
