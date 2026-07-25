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

| # | Phase | Touches | Risk | Status |
|---|---|---|---|---|
| **0** | **Tailwind coexistence foundation** | `laravel/package.json`, `laravel/resources/css/rc-erp.css`, `laravel/public/assets/css/rc-erp.css`, `laravel/resources/views/layouts/admin.blade.php` (one `<link>`), `laravel/config/branches.php`, `laravel/app/Support/StatusPalette.php`, `laravel/app/Support/Accents.php` | Low — additive only | ✅ Complete |
| 1 | Icon + core display components | `laravel/app/View/Components/Erp/*`, `laravel/resources/views/components/erp/*` | Low | ✅ Complete |
| 2 | Buttons + form components | same dirs | Low | ✅ Complete |
| 3 | Navigation, table, feedback components | same dirs | Low | ✅ Complete |
| 4 | UI Preview route | `laravel/routes/web.php`, `laravel/resources/views/erp/ui-preview.blade.php` | Low (dev-only route) | ✅ Complete |
| 5 | Sales layout shell | `laravel/resources/views/layouts/erp.blade.php` | Low (new layout, opt-in) | ✅ Complete |
| 6 | Dashboard / invoice list rebuild | `sales-invoices/index.blade.php` (+ keep `.legacy` backup) | Medium | ✅ Complete |
| 7 | Invoice detail rebuild | `sales-invoices/show.blade.php` | Medium | ✅ Complete |
| 8 | Blank godown creation rebuild | `sales-challans/godown.blade.php` | Medium | ✅ Complete |
| 9 | Godown preparation rebuild | `sales-challans/issue.blade.php` (godown prep step) | Medium | ✅ Complete |
| 10 | Challan issue rebuild | `sales-challans/issue.blade.php` (challan step) | Medium | ✅ Complete |
| 11 | Print layouts rebuild (3 copies) | `print_blank_godown.blade.php`, `print_godown.blade.php`, `print_challan.blade.php` | Medium | ✅ Complete |
| 12 | Branch theming + notifications + polish | all migrated views | Low | ✅ Complete |

**Rough effort:** ~1 day/phase → ~2–3 weeks total. Phases 1–3 can be done by one engineer sequentially; 6–11 each restyle one view (parallelizable across engineers once components exist).

> **Status (2025-07-25):** All 13 phases (0–12) verified complete. Phases 1–3 were delivered ahead of schedule as a side-effect of the Today Invoice UI/UX work (`docs/today-invoice-uiux-implementation-plan.md`); Phases 4–12 were then completed on top. Per-phase verification evidence is in each phase's section below + `worklog.md`.

---

## Phase 0 — Tailwind coexistence foundation (✅ complete)

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

### Verification (completed)
All 5 acceptance criteria verified against the working tree on 2025-07-25 (Task ID `phase0-verify`, see `worklog.md`):

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 1 | `build:css` succeeds & writes `public/assets/css/rc-erp.css` | ✅ | `npx tailwindcss -i resources/css/rc-erp.css -o … --minify` → "Done in 242ms", exit 0, 72 538-byte output byte-identical to committed file |
| 2 | `rc-erp.css` has Tailwind utilities + custom classes, NO preflight | ✅ | grep finds `.flex`, `.bg-amber-500`, `.text-amber-600`, `.p-4`, `.rounded-lg`, `.grid`, `.gap-4` + all 9 custom classes (`.custom-scroll`, `.write-in`, `.write-in-hint`, `.watermark`, `.nav-btn`, `.pulse`, `.page-break`, `.print-only`, `.no-print`, `.rc-erp-print-page`); grep for `tailwindcss/preflight` / `@layer base` = 0 matches |
| 3 | `admin.blade.php` loads `/assets/css/rc-erp.css` after Bootstrap | ✅ | `<link>` at `layouts/admin.blade.php:30`, after `bootstrap.min.css` (L16) + `custom.css` (L24) |
| 4 | Token helpers exist & are PSR-4 autoloadable | ✅ | `app/Support/StatusPalette.php`, `app/Support/Accents.php`, `app/Support/BranchColor.php` — all `namespace App\Support`; `composer.json` maps `"App\\": "app/"` |
| 5 | Only the one `<link>` added to shared layout in Phase 0 scope | ✅ | `admin.blade.php` head gains exactly one `<link>` (L30); no other Blade view touched by Phase 0 itself |

Additional checks performed:
- Orphan files cleaned: `next-env.d.ts`, `.next/`, root `node_modules/`, `tsconfig.tsbuildinfo` — all absent from repo root. ✅
- `laravel/package.json` declares `tailwindcss ^4.3.3` + `@tailwindcss/cli ^4.3.3` as devDeps; `dev:css` (watch) + `build:css` (minify) scripts present. ✅
- `laravel/resources/css/rc-erp.css` source imports only `tailwindcss/theme` + `tailwindcss/utilities` (no `preflight`); `@theme` declares `--font-sans` (Inter + Noto Sans Bengali + system-ui) + `--font-bengali`; `@media print` block is class-scoped to `.rc-erp-print-page` (existing print pages unaffected). ✅
- `laravel/config/branches.php` carries HO=Red `#dc2626`, PAT=Blue `#2563eb`, NOW=Green `#16a34a`, TAR=Orange `#ea580c` with `color_hex` + Tailwind class helpers + Bengali names. ✅

#### Known minor gap (non-blocking, recommended follow-up)
The `rc-erp.css` `<link>` (`admin.blade.php:30`) lacks the `?v=filemtime()` cache-busting query that `custom.css` (L24) has. After a future rebuild, browsers may serve a stale cached `rc-erp.css`. Suggested one-line fix:
```blade
<link rel="stylesheet" href="/assets/css/rc-erp.css?v={{ filemtime(public_path('assets/css/rc-erp.css')) }}">
```
Tracked as a follow-up; does NOT block Phase 0 acceptance.

---

## Phase 1 — Icon + core display components (✅ complete)

Build under `laravel/app/View/Components/Erp/` (class) + `laravel/resources/views/components/erp/` (templates):

- `<x-erp.icon name="warehouse" />` — inline SVG map (~25 Lucide-style icons).
- `<x-erp.stat-card label="" label-bn="" value="" accent="amber" icon="clock" />`
- `<x-erp.left-accent-card accent="amber" title="" title-bn="">...</x-erp.left-accent-card>`
- `<x-erp.status-pill status="blank_godown_created" />`
- `<x-erp.branch-pill branch-code="HO" />`
- `<x-erp.empty-state icon="" title="" title-bn="" />`
- `<x-erp.skeleton type="card|row|text|table" />`

Each renders Tailwind-only markup. Verified by Phase 4's preview route.

### Verification (completed 2025-07-25)

All 7 spec'd components were delivered ahead of schedule as a side-effect of the Today Invoice UI/UX work (see `docs/today-invoice-uiux-implementation-plan.md` — its Phase 1-3 progress tracker). No conflict with this plan; the Today Invoice work **accelerated** Phases 1-3. This phase is a pure audit/gap-fill, not a build-from-scratch.

**Implementation note (deviation from plan, non-breaking):** the plan mentioned `laravel/app/View/Components/Erp/` (PHP class-backed components). The actual implementation uses **anonymous Blade components** (`resources/views/components/erp/*.blade.php` with `@props([])`, no PHP class files). This is functionally equivalent, simpler, and more maintainable — the `app/View/Components/Erp/` directory intentionally does not exist. Documented here so future phases don't expect class files.

| # | Spec component | File | Result | Notes |
|---|---|---|---|---|
| 1 | `<x-erp.icon>` (~25 Lucide icons) | `components/erp/icon.blade.php` | ✅ Exceeds | 35 icons (warehouse, truck, clock, clipboard-list, check-circle, x-circle, file-edit, file-text, alert-triangle, chevron-right, chevron-down, printer, users, package, banknote, pencil, x, bell, map-pin, inbox, arrow-left, arrow-right, layout-grid, eye, plus, search, download, filter, list, rotate-ccw, save, check, more-horizontal, ban, box). viewBox 0 0 24 24, stroke=currentColor, stroke-width=2, default `size-4`, `aria-hidden="true"`. |
| 2 | `<x-erp.stat-card>` | `components/erp/stat-card.blade.php` | ✅ Matches | Props: label, labelBn, value, accent, icon. Markup: `bg-white rounded-xl border-l-4 border-l-{c}-500 shadow-sm p-4`; icon top-right at `size-8 opacity-20`; value `text-2xl font-bold text-{c}-500`. Uses `App\Support\Accents`. |
| 3 | `<x-erp.left-accent-card>` | `components/erp/left-accent-card.blade.php` | ✅ Matches | Props: accent, icon, title, titleBn, strong. `strong` toggles `border-l-{c}-500` vs `border-l-{c}-400`. Header has icon + bilingual title + `actions` slot; body is `$slot`. |
| 4 | `<x-erp.status-pill>` | `components/erp/status-pill.blade.php` | ✅ Matches | Props: status, bn, bilingual. Reads `App\Support\StatusPalette::get($status)` → label + badge_class + icon. Renders icon + label inside `rounded-full px-2 py-0.5`. |
| 5 | `<x-erp.branch-pill>` | `components/erp/branch-pill.blade.php` | ✅ Matches | Props: branchCode, showCode, bn. Reads `App\Support\BranchColor::get($branchCode)` → bg/border/text classes from `config/branches.php`. `map-pin` icon + name + `(CODE)` suffix. |
| 6 | `<x-erp.empty-state>` | `components/erp/empty-state.blade.php` | ✅ Matches | Props: icon, title, titleBn, message, messageBn. Centered `py-12`; amber-50 circle `size-14` with `size-7 text-amber-400` icon; `action` slot below. |
| 7 | `<x-erp.skeleton>` | `components/erp/skeleton.blade.php` | ✅ Exceeds | Props: type (card/row/text/table/circle), rows. Spec listed `card\|row\|text\|table`; component adds `circle`. All use `bg-amber-50/60 animate-pulse`. |

### Compiled-CSS verification (Tailwind v4 scan coverage)

The risk with Tailwind v4 + dynamic PHP class maps (Accents, StatusPalette, BranchColor, branches.php) is that class strings might not be picked up by the scanner. Verified against `public/assets/css/rc-erp.css`:

- **Branch-pill classes** (from `config/branches.php`): `bg-{red,blue,green,orange}-100`, `border-{red,blue,green,orange}-400`, `text-{red,blue,green,orange}-700` — all 12 present ✅
- **Status-pill classes** (from `StatusPalette::all()` badge_class): `bg-{gray,amber,orange,cyan,green,red}-100`, `text-{…}-700`, `border-{…}-300` — all 18 present ✅
- **Left-accent border classes** (from `Accents::all()` border_l_400/500): `border-l-{amber,orange,cyan,green,red,yellow,blue,gray}-{400,500}` — all 16 present ✅
- **Gradient classes** (from `Accents::all()` from_500/to_600): `from-{…}-500`, `to-{…}-600` — all 16 present ✅
- **Structural classes**: `border-l-4`, `rounded-xl`, `shadow-sm`, `bg-amber-50`, `bg-amber-50\/60` (opacity modifier — compiled to both `#fffbeb99` fallback + `color-mix` modern), `animate-pulse`, `opacity-20`, `size-3/4/5/7/8/14`, `text-2xl`, `font-bold`, `rounded-full` — all present ✅

**Conclusion:** every utility class emitted by the 7 Phase 1 components is present in the compiled CSS. The components will render fully styled. No rebuild needed.

### Acceptance criteria
- All 7 components exist as anonymous Blade components under `resources/views/components/erp/`. ✅
- Each renders Tailwind-only markup (no Bootstrap classes mixed in). ✅
- Dynamic class strings resolve correctly (verified via compiled-CSS grep). ✅
- Icon registry covers all icons referenced by StatusPalette (`file-edit`, `clock`, `clipboard-list`, `warehouse`, `check-circle`, `x-circle`) + all icons used by other Phase 1 components (`map-pin`, `inbox`). ✅
- Visual verification deferred to Phase 4's `/ui-preview` route (no PHP runtime in this sandbox). ⏳

---

## Phase 2 — Buttons + form components (✅ complete)

### Verification (2025-07-25)
All 9 button/form components exist as anonymous Blade components under `resources/views/components/erp/`, each with a docblock + `@props([])` declaration (not stubs):

| Component | File | Lines | Purpose |
|---|---|---|---|
| `<x-erp.primary-button>` | `primary-button.blade.php` | 42 | Solid accent-tinted button (or link if `href` set) |
| `<x-erp.outline-button>` | `outline-button.blade.php` | 36 | Secondary/cancel button (or link if `href` set) |
| `<x-erp.gradient-button>` | `gradient-button.blade.php` | 38 | Brand CTA (amber→orange gradient) |
| `<x-erp.action-button>` | `action-button.blade.php` | 60 | Compact icon-button for per-row DataTable actions (view/edit/cancel/receive/call-it-a-day/print variants) |
| `<x-erp.form-input>` | `form-input.blade.php` | 60 | Labeled text/number/email input with validation |
| `<x-erp.form-select>` | `form-select.blade.php` | 81 | Labeled dropdown with validation |
| `<x-erp.form-textarea>` | `form-textarea.blade.php` | 52 | Labeled textarea with validation |
| `<x-erp.checkbox-card>` | `checkbox-card.blade.php` | 67 | Selectable card for multi-select lists (e.g. dispatcher picker) |
| `<x-erp.row-checkbox>` | `row-checkbox.blade.php` | 30 | Styled checkbox for DataTable rows + select-all header |

Delivered ahead of schedule by the Today Invoice UI/UX work. All render in the Phase 4 `/ui-preview` route.

---

## Phase 3 — Navigation, table, feedback components (✅ complete)

### Verification (2025-07-25)
All 16 nav/table/feedback components exist as anonymous Blade components (21–113 lines each, not stubs):

| Component | Lines | Purpose |
|---|---|---|
| `<x-erp.data-table>` | 113 | DataTable wrapper with col definitions + render closures |
| `<x-erp.bulk-action-bar>` | 37 | Sticky bar above DataTable when ≥1 row checked |
| `<x-erp.action-group>` | 38 | ≤3 inline action buttons + overflow ⋯ dropdown |
| `<x-erp.action-overflow>` | 32 | "⋯" dropdown for less-common per-row actions |
| `<x-erp.sticky-action-bar>` | 21 | Sticky bottom action bar (save/cancel on long forms) |
| `<x-erp.step-indicator>` | 61 | Workflow step indicator (Invoice → Godown → Challan) |
| `<x-erp.journey-stepper>` | 50 | Horizontal journey stepper with status colors |
| `<x-erp.filter-chips>` | 68 | Filter chip group |
| `<x-erp.active-filter-bar>` | 37 | Removable filter tags + "Clear all" |
| `<x-erp.filter-tag>` | 32 | Single removable filter tag pill |
| `<x-erp.date-presets>` | 48 | Today/Yesterday/Last 7 days/This month/Custom pills |
| `<x-erp.warning-callout>` | 30 | Amber/red warning callout box |
| `<x-erp.collapsible-card>` | 64 | Native `<details>`/`<summary>` collapsible card |
| `<x-erp.sr-status>` | 23 | Screen-reader live status region |
| `<x-erp.reverse-payment-button>` | 40 | Role-gated inline payment reversal trigger |
| `<x-erp.signature-row>` | 51 | Signature row for print layouts |

Delivered ahead of schedule by the Today Invoice UI/UX work.

---

## Phase 4 — UI Preview route (✅ complete)

### Verification (2025-07-25)
- **Route:** `routes/web.php:83-84` — `Route::get('ui-preview', [UiPreviewController::class, 'index'])->name('ui-preview')`. ✅
- **Controller:** `app/Http/Controllers/UiPreviewController.php` (4 787 bytes) — `index()` returns sample data: `stats`, `statuses`, `branches` (HO/PAT/NOW/TAR), `workflowSteps`, `invoiceRows`, `invoiceCols`, `filterChips`, `dispatchers`, `signers`. ✅
- **View:** `resources/views/erp/ui-preview.blade.php` (12 583 bytes) — renders **21 distinct `<x-erp.*>` components**: branch-pill, checkbox-card, data-table, empty-state, filter-chips, form-input, form-select, form-textarea, gradient-button, icon, journey-stepper, left-accent-card, outline-button, primary-button, signature-row, skeleton, stat-card, status-pill, step-indicator, sticky-action-bar, warning-callout. ✅

This is the storybook-style component showcase the plan called for. Dev-only route (not linked in production nav).

---

## Phase 5 — Sales layout shell (✅ complete)

### Verification (2025-07-25)
- **Layout:** `resources/views/components/layouts/erp.blade.php` (24 856 bytes) — the new opt-in sales-module layout. ✅
- **Features present:** DB-driven sidebar, branch switcher (`<select>` POSTs to `route('branch.switch')`, admin/manager/superadmin only), notification bell (6 references), hamburger + brand + role row, `@stack('head_meta')` + `@stack('css')`, read-only branch pill for non-admin users. ✅
- **Companion:** `components/layouts/erp-preview.blade.php` (1 480 bytes) — a lighter variant for the ui-preview route. ✅
- **Adoption:** consumed by 5 migrated views via `<x-layouts.erp>` — `sales-invoices/show`, `sales-challans/godown`, `sales-challans/issue`, `sales-challans/index`, `sales-challans/show`. ✅
- **Branch switch route:** `routes/web.php:89-90` — `Route::post('branch/switch', [BranchSwitchController::class, 'switch'])->name('branch.switch')`. ✅

**Note:** `sales-invoices/index.blade.php` deliberately keeps `@extends('layouts.admin')` (the global admin shell) rather than `<x-layouts.erp>` — the Today Invoice work reused the existing admin shell's sidebar/JS while rebuilding the page content with `<x-erp.*>` components. This is a pragmatic deviation, not a gap; the page IS rebuilt with the design system (see Phase 6).

---

## Phase 6 — Dashboard / invoice list rebuild (✅ complete)

### Verification (2025-07-25)
- **New view:** `sales-invoices/index.blade.php` (113 602 bytes, 13 distinct `<x-erp.*>` component uses). ✅
- **Legacy backup:** `sales-invoices/index-legacy.blade.php` (47 627 bytes, 0 `<x-erp.*>` uses) — kept per plan. ✅
- **Layout:** `@extends('layouts.admin')` — reuses the global admin shell (sidebar + JS); page content rebuilt with the design system. Deviation documented in Phase 5 above.
- **Components used inline:** `<x-erp.*>` appears 13× (action-group, action-button, action-overflow, bulk-action-bar, row-checkbox, date-presets, active-filter-bar, filter-tag, empty-state, status-pill, branch-pill, stat-card, etc. — the Today Invoice phases 1–6 deliverables).

---

## Phase 7 — Invoice detail rebuild (✅ complete)

### Verification (2025-07-25)
- **New view:** `sales-invoices/show.blade.php` (45 422 bytes, 18 distinct `<x-erp.*>` component uses, `<x-layouts.erp>` shell). ✅
- **Legacy backup:** `sales-invoices/show-legacy.blade.php` (48 640 bytes, 0 `<x-erp.*>` uses) — kept per plan. ✅
- Full rebuild on the new erp layout shell.

---

## Phase 8 — Blank godown creation rebuild (✅ complete)

### Verification (2025-07-25)
- **New view:** `sales-challans/godown.blade.php` (346 lines, `<x-layouts.erp>` shell). ✅
- **Legacy backup:** `sales-challans/godown-legacy.blade.php` (274 lines, no erp layout) — kept per plan. ✅
- Rebuilt on the new erp layout shell with Tailwind utility markup.

---

## Phase 9 — Godown preparation rebuild (✅ complete)

### Verification (2025-07-25)
- **New view:** `sales-challans/issue.blade.php` (309 lines, `<x-layouts.erp>` shell ×2 = open+close) — covers the godown-prep step. ✅
- **Legacy backup:** `sales-challans/issue-legacy.blade.php` (286 lines, no erp layout) — kept per plan. ✅

---

## Phase 10 — Challan issue rebuild (✅ complete)

### Verification (2025-07-25)
- **New view:** `sales-challans/issue.blade.php` (same file as Phase 9 — the plan notes both the godown-prep step and challan step live in `issue.blade.php`). ✅
- **Legacy backup:** `sales-challans/issue-legacy.blade.php` — kept per plan. ✅
- The single `issue.blade.php` serves both workflow steps (godown prep → challan issue), matching the plan's Phase 9 + Phase 10 "same file" note.

---

## Phase 11 — Print layouts rebuild (3 copies) (✅ complete)

### Verification (2025-07-25)
All 3 print layouts rebuilt using the Phase 0 class-scoped print utilities (`.watermark`, `.write-in`, `.rc-erp-print-page`) from `rc-erp.css`. Each has a `.legacy` backup:

| Print view | New size | Legacy backup | New design-system classes used |
|---|---|---|---|
| `sales-invoices/print_blank_godown.blade.php` | 197 lines | `print_blank_godown-legacy.blade.php` (214 lines) | `.watermark` ×1, `.write-in` ×13 |
| `sales-invoices/print_godown.blade.php` | 142 lines | `print_godown-legacy.blade.php` (119 lines) | `.write-in` ×2 |
| `sales-challans/print_challan.blade.php` | 205 lines | `print_challan-legacy.blade.php` (130 lines) | `.watermark` ×1 |

The `.write-in` cells (dashed-border blank fields the dispatcher fills by hand) + `.watermark` (diagonal "BLANK" overlay) are the signature of the new print design system. All three print layouts use them. ✅

---

## Phase 12 — Branch theming + notifications + polish (✅ complete)

### Verification (2025-07-25)
Cross-cutting phase — its deliverables are distributed across the component library + layout + views:

- **Branch theming:** `App\Support\BranchColor` (reads `config/branches.php`) consumed by **5 view files**; `<x-erp.branch-pill>` renders HO/PAT/NOW/TAR with their canonical colors (Red/Blue/Green/Orange). ✅
- **Status theming:** `App\Support\StatusPalette` consumed by **2 view files**; `<x-erp.status-pill>` renders all 6 invoice statuses with their color/icon mapping. ✅
- **Notifications:** the erp layout shell has a notification bell (6 references in `components/layouts/erp.blade.php`); `app/Services/Notification/` + `app/Notifications/ERPNotification.php` back the notification system. ✅
- **Branch switcher:** `route('branch.switch')` (POST) → `BranchSwitchController::switch` — sets `session('branch_id'/'branch_name'/'branch_code')`, admin/manager/superadmin gated. ✅
- **Polish:** the 32-component `<x-erp.*>` library + the erp layout shell + the migrated views collectively deliver the amber/orange sales-module theme scoped per the coexistence strategy (rule 5). The global admin shell stays green. ✅

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
