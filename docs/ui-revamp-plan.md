# RC ERP — UI/UX Revamp Plan (Laravel + Tailwind coexistence)

> **Goal:** Port the design in `upload/rc-erp-ui-showcase.html` into the **existing Laravel 12** RC ERP app's sales module (Invoice → Godown → Challan workflow), by introducing **Tailwind CSS v4 alongside the existing Bootstrap 5** — scoped to a new Blade design-system. Backend/business logic is reused as-is.
>
> **Approach chosen:** Option A — Tailwind v4 coexists with Bootstrap 5. Tailwind's preflight (base reset) is **skipped** so Bootstrap's Reboot remains the global base. Tailwind utilities are purely additive (opt-in by class). A new `<x-erp-*>` Blade component library emits Tailwind-only markup. Existing Bootstrap views are untouched until each is deliberately migrated.

---

## Coexistence strategy (binding rules)

1. **Tailwind v4 via standalone CLI** (`@tailwindcss/cli`), NOT the Vite plugin. Source: `laravel/resources/css/rc-erp.css`. Compiled output: `laravel/public/assets/css/rc-erp.css`. Linked in the layout exactly like the other `/assets/css/*.css` files — no `@vite` directive, no manifest, no breakage to the current (vite-free) workflow.
2. **Skip preflight** — `rc-erp.css` imports `tailwindcss/theme` + `tailwindcss/utilities` only (NOT `tailwindcss/preflight`). Bootstrap's Reboot stays global.
3. **No global `body`/element resets** in `rc-erp.css`. Every custom rule is class-scoped (`.write-in`, `.watermark`, `.rc-erp-print-page`, etc.) so it cannot leak onto Bootstrap pages.
4. **No mixing Tailwind utility classes with Bootstrap classes on the same element** inside `<x-erp-*>` components. Components use Tailwind-only markup. (Reason: Bootstrap is unlayered, Tailwind utilities are layered — unlayered wins the cascade. Different class names don't conflict, but to be safe, keep components pure.)
5. **Brand color:** the showcase uses amber/orange (`#f59e0b → #ea580c`). The rest of the app is green (`#61bc91`). The amber theme is **scoped to the sales-module design-system** (the new `<x-erp-*>` components + the sales layout). The global admin shell stays green until a future brand-migration decision.
6. **Branch colors** (HO=Red `#dc2626`, PAT=Blue `#2563eb`, NOW=Green `#16a34a`, TAR=Orange `#ea580c`) live in `config/branches.php` as the single source of truth (Phase 0), consumed by components.

---

## Phase breakdown (small, independently-committable phases)

Each phase = one commit. Each leaves the app working. Context is recoverable from `worklog.md` + this doc.

| # | Phase | Touches | Risk |
|---|---|---|---|
| **0** | **Tailwind coexistence foundation** | `laravel/package.json`, `laravel/resources/css/rc-erp.css`, `laravel/public/assets/css/rc-erp.css`, `laravel/resources/views/layouts/admin.blade.php` (one `<link>`), `laravel/config/branches.php`, `laravel/app/Support/StatusPalette.php`, `laravel/app/Support/Accents.php` | Low — additive only |
| 1 | Icon + core display components | `laravel/app/View/Components/Erp/*`, `laravel/resources/views/components/erp/*` | Low |
| 2 | Buttons + form components | same dirs | Low |
| 3 | Navigation, table, feedback components | same dirs | Low |
| 4 | UI Preview route | `laravel/routes/web.php`, `laravel/resources/views/erp/ui-preview.blade.php` | Low (dev-only route) |
| 5 | Sales layout shell | `laravel/resources/views/layouts/erp.blade.php` | Low (new layout, opt-in) |
| 6 | Dashboard / invoice list rebuild | `sales-invoices/index.blade.php` (+ keep `.legacy` backup) | Medium |
| 7 | Invoice detail rebuild | `sales-invoices/show.blade.php` | Medium |
| 8 | Blank godown creation rebuild | `sales-challans/godown.blade.php` | Medium |
| 9 | Godown preparation rebuild | `sales-challans/issue.blade.php` (godown prep step) | Medium |
| 10 | Challan issue rebuild | `sales-challans/issue.blade.php` (challan step) | Medium |
| 11 | Print layouts rebuild (3 copies) | `print_blank_godown.blade.php`, `print_godown.blade.php`, `print_challan.blade.php` | Medium |
| 12 | Branch theming + notifications + polish | all migrated views | Low |

**Rough effort:** ~1 day/phase → ~2–3 weeks total. Phases 1–3 can be done by one engineer sequentially; 6–11 each restyle one view (parallelizable across engineers once components exist).

---

## Phase 0 — Tailwind coexistence foundation (current)

### Goal
Install Tailwind v4 so it compiles to a stable `/assets/css/rc-erp.css`, linked into the admin layout. **Zero visual change** to existing pages — just makes Tailwind utilities available for Phase 1+ components.

### Tasks
1. **Clean orphan files** at repo root from the pre-reset Next.js setup (`next-env.d.ts`, `.next/`, root `node_modules/`, `tsconfig.tsbuildinfo`) — untracked, not part of the Laravel project.
2. **Install** `tailwindcss` + `@tailwindcss/cli` (v4) as devDeps in `laravel/`.
3. **Create `laravel/resources/css/rc-erp.css`** (source):
   - `@import "tailwindcss/theme" layer(theme);` + `@import "tailwindcss/utilities" layer(utilities);` — **no preflight**.
   - `@theme` tokens: `--font-sans` (Inter + Noto Sans Bengali + system-ui fallback), `--font-bengali`.
   - Custom class-scoped utilities: `.custom-scroll`, `.write-in`, `.write-in-hint`, `.watermark`, `.nav-btn`, `.pulse`, `.page-break`, `.print-only`, `.no-print`, `.rc-erp-print-page`.
   - `@media print` block — **all rules class-scoped** (no global `body` changes).
4. **Add scripts** to `laravel/package.json`: `dev:css` (watch), `build:css` (minified).
5. **Compile** `public/assets/css/rc-erp.css` via the CLI so the link works immediately.
6. **Link** `<link rel="stylesheet" href="/assets/css/rc-erp.css">` in `layouts/admin.blade.php` head, AFTER `custom.css`.
7. **Design-token PHP helpers** (single sources of truth for Phase 1+ components):
   - `config/branches.php` — branch→color map (HO/PAT/NOW/TAR with hex + Tailwind class helpers).
   - `app/Support/StatusPalette.php` — invoice status → {label, labelBn, color, badgeClass, icon} map.
   - `app/Support/Accents.php` — accent color → literal Tailwind class strings (Tailwind v4 needs verbatim class strings).
8. **Verify:** the compiled `rc-erp.css` exists and contains Tailwind utilities (e.g. `.flex`, `.bg-amber-500`). Document that the team should eyeball one existing Bootstrap page to confirm no visual regression (we have no PHP runtime here).
9. **Commit + push.**

### Acceptance criteria
- `bun run build:css` (or `npm run build:css`) succeeds and writes `public/assets/css/rc-erp.css`.
- `rc-erp.css` contains Tailwind utilities + custom classes, NO preflight resets.
- `admin.blade.php` loads `/assets/css/rc-erp.css` after Bootstrap.
- Token helpers exist and are autoloadable (PSR-4 `App\Support\*`).
- No existing Blade view is modified (only the one `<link>` added to the shared layout).

---

## Phase 1 — Icon + core display components (next)

Build under `laravel/app/View/Components/Erp/` (class) + `laravel/resources/views/components/erp/` (templates):

- `<x-erp.icon name="warehouse" />` — inline SVG map (~25 Lucide-style icons).
- `<x-erp.stat-card label="" label-bn="" value="" accent="amber" icon="clock" />`
- `<x-erp.left-accent-card accent="amber" title="" title-bn="">...</x-erp.left-accent-card>`
- `<x-erp.status-pill status="blank_godown_created" />`
- `<x-erp.branch-pill branch-code="HO" />`
- `<x-erp.empty-state icon="" title="" title-bn="" />`
- `<x-erp.skeleton type="card|row|text|table" />`

Each renders Tailwind-only markup. Verified by Phase 4's preview route.

*(Phases 2–12 follow the same pattern — see the per-phase sections in the earlier plan doc; this file focuses on the granular Laravel-specific breakdown.)*

---

## Verification limitations (honest note)

This sandbox has **no PHP/Composer** and **no MySQL**, so I cannot run `php artisan serve` or load the Laravel app in a browser here. Per-phase verification I CAN do:
- `bun run build:css` (Tailwind compiles) ✅
- static read of compiled CSS for expected utilities ✅
- `php -l` syntax check — NOT available (no PHP)

Verification that needs your team:
- Open any admin page in a browser → confirm no visual regression after adding the `rc-erp.css` `<link>`.
- After Phase 4: open `/ui-preview` → confirm components render.

I'll flag these clearly in each phase's commit message.
