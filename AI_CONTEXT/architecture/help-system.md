# Help System — Dual-Door In-App Help (Canonical)

> **Module:** Help System (cross-cutting, opt-in per layout)
> **Audience:** Engineers, AI assistants, content authors
> **Status:** Canonical — complete (Phases 1–10). Phase 10 QA verified 215/215 coverage.
> **Last reviewed:** P10 (post Phase-9 niceties + Phase-10 QA sweep + filename-bug fix)
> **Source of truth:** This file + `laravel/app/Services/Help/HelpService.php` +
> `laravel/app/Http/Controllers/HelpController.php` + `laravel/resources/views/partials/help-system.blade.php` +
> `laravel/public/assets/js/help.js` + `laravel/public/assets/css/help-system.css` +
> `laravel/resources/help/{registry,action-registry,modules,diagrams}.php` +
> `laravel/resources/help/menus/{module}/{slug}.php` (215 files) +
> `laravel/resources/views/components/help/*.blade.php` (6 components)
>
> **Scope:** This file is the canonical reference for the **in-app help system** — the
> dual-door (floating `?` button + footer guide pill) contextual help that ships with
> every authenticated `layouts.admin` / `layouts.app` page. The companion
> [`../../../docs/HELP_AUTHORING_GUIDE.md`](../../../docs/HELP_AUTHORING_GUIDE.md) is the
> author-facing how-to; [`../../../docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md`](../../../docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md)
> is the original 10-phase design + acceptance criteria. This file is the engineering
> deep-dive for an AI assistant (or new engineer) who needs to understand how it works
> end-to-end without reading 800 lines of CSS + 810 lines of JS.
>
> **Health:** Complete and stable. All 10 phases shipped. 215/215 content coverage, 0 schema
> errors, 0 route-resolution gaps. Phase 10 found + fixed one filename bug
> (`sales/invoice.php` → `invoices.php`). One known deviation: `help.js` is 9.8 KB gzipped
> (over the §11.4 budget of 4 KB, which predated Phase 9's niceties). See §11.

---

## 1. What is it?

RC_ERP_v2 has a **contextual, dual-door in-app help system** that shows Bangla explanations
of whatever ERP page the user is currently on, plus a browsable guide to all 8 ERP modules.
It is **file-based** (no database table, no migrations, no admin panel) and **opt-in per
layout** (one `@include` line). It was built over 10 phases (discovery → schema → scaffold →
routing → Door 1 → Door 2 → content authoring → visual polish → interactive niceties → QA +
docs).

### The two doors

| Door | Trigger | What opens | Content |
|---|---|---|---|
| **Door 1** — contextual | Floating `?` button (bottom-right, above footer pill) | Right offcanvas | Help for the **current page's** menu (resolved from the route) |
| **Door 2** — browse | Fixed footer pill `🧭 My Creative Code Guide` | Bottom-up sheet of 8 module cards | → click a module → module offcanvas (intro + menu list) → click a menu → menu offcanvas (full Bangla help card) |

Both doors share the same right offcanvas for displaying a menu's content; Door 2 adds a
module offcanvas + the bottom sheet. There are never more than 2 drawers open at once
(module offcanvas closes when a menu is chosen).

### Design principles (from plan §3)

1. **Contextual first** — Door 1 shows you help for *this* page, not a generic manual.
2. **Bangla-first** — all content is in Bangla (Bengali). English titles exist for search + meta.
3. **File-based** — edit a PHP file, commit, done. No CMS, no DB, no migrations.
4. **Graceful degradation** — unmapped routes show a friendly empty-state card with a
   mailto link, never a 404 or a crash.
5. **Enhancement-only extras** — Mermaid diagrams lazy-load from CDN; if blocked, the
   diagram block hides silently.
6. **Zero-dependency** — no `composer require`, no `npm install`. Mermaid is a CDN `<script>`.

---

## 2. The resolution chain — route → menu_key → content

The heart of the system is `HelpService::menuKeyForRoute()`. It resolves the current
Laravel route name to a help menu key via a 4-layer fallback:

```
admin.sales-invoices.index  (current route name)
        │
        ├─ 1. registry.php exact match?        'admin.sales-invoices.index' => 'sales.invoices'  ✓
        │      → returns 'sales.invoices'
        │
        ├─ 2. action-registry Controller@action?  SalesInvoiceController@index => 'sales.invoices'
        │      → returns 'sales.invoices'
        │
        ├─ 3. action-registry Controller@* wildcard?  SalesInvoiceController@* => 'sales.invoices'
        │      → returns 'sales.invoices'  (catches create/show/edit/store/update/destroy)
        │
        └─ 4. null  →  empty-state card  (no help mapped)
```

**Layer 1** (`registry.php`, 214 mappings) is the primary, explicit map — route name →
menu key. This is where every curated `index` page is registered.

**Layer 2** (`action-registry.php`, 214 `Controller@action` mappings) catches routes that
weren't in `registry.php` by their controller+method. Rarely the resolution path in practice
(Layer 1 or 3 usually wins), but it's the documented fallback.

**Layer 3** (`action-registry.php`, 59 `Controller@*` wildcards) is the workhorse for
resource sub-actions: one wildcard per controller maps `create`/`show`/`edit`/`store`/
`update`/`destroy` to the controller's primary menu key (usually its `index` page's help).
This means a standard Laravel resource controller needs only ONE registry entry (for `index`)
+ ONE wildcard (for `@*`) and all 7 resource actions get help.

**Layer 4** is the graceful empty state. The Phase 10 sweep confirmed **0** of the 215
curated routes fall through to Layer 4. Orphan named routes (not in the CSV inventory) may
fall to Layer 4 by design — that's the "this page has no help yet" card with a mailto link.

### Content loading

Once a menu_key is resolved, `HelpService::loadMenuContent($key)` loads
`resources/help/menus/{module}/{slug}.php` (where `key = "{module}.{slug}"`). The file
returns an array; if it references a `diagram` key, the matching Mermaid snippet from
`diagrams.php` is attached as `_diagram_mermaid`. Path traversal is guarded — only
`[a-z0-9-]` is allowed in module/slug.

**Critical naming invariant:** the `slug` (filename without `.php`) MUST equal the slug
portion of the menu_key. `HelpService` loads by filename, not by the `key` field inside the
file. (Phase 10 QA found + fixed a violation: `sales/invoice.php` had internal
`key => 'sales.invoices'` — the file wouldn't load because `HelpService` looked for
`invoices.php`. The validator now catches this in Check 5.)

---

## 3. The content schema

Every content file returns a 12-key array. See [`docs/HELP_AUTHORING_GUIDE.md`](../../../docs/HELP_AUTHORING_GUIDE.md)
§1 for the full annotated template. The keys:

| Key | Type | Purpose |
|---|---|---|
| `key` | string | `"{module}.{slug}"` — must match filename. |
| `module` | string | parent module key (must match dir name). |
| `title_bn` | string | Bangla title (header + breadcrumb). |
| `title_en` | string | English title (search + meta). |
| `icon` | string | FontAwesome 6 solid class (e.g. `fa-file-invoice-dollar`). |
| `summary` | string | One Bangla sentence — the page's purpose. (Soft limit: 1 danda ।) |
| `for_roles` | string[] | Who the page is for (e.g. `['salesman','manager','admin','superadmin']`). |
| `what_you_can_do` | array | 3–6 `{icon, text}` bullets — actions on this page. |
| `impacts` | array | `{who, what}` rows — what changes when you use the page. |
| `cautions` | string[] | 1–3 risk callouts (rendered as an amber box). |
| `related` | string[] | Other menu_keys (clickable chips). |
| `diagram` | string? | Optional Mermaid key into `diagrams.php`. |
| `updated_at` | string | ISO date — bump when you edit. |

The validator (`docs/help-sweep/phase7_validate.py`) enforces all 12 keys + the two
non-negotiable invariants (`key` == path-derived key, `module` == dir) + cross-references
(every `related` key exists in `modules.php`, every `diagram` key exists in `diagrams.php`).

---

## 4. The module metadata

`modules.php` defines the 8 modules shown in the Door-2 bottom sheet. Each module entry:

```php
'key'       => 'sales',
'title_bn'  => 'সেলস',
'title_en'  => 'Sales',
'icon'      => 'fa-cart-shopping',
'color'     => 'emerald',     // → CSS --help-tint-c1/c2 (drives all tinted surfaces)
'tagline'   => 'খদ্দেরকে পণ্য বিক্রি, বিল তৈরি, পাঠানো, টাকা আদায় — সব এখানে।',
'intro'     => 'এই মডিউলে পুরো বিক্রয় সাইকল চলে: কার্ট → ইনভয়েস → চালান → ডেলিভারি → পেমেন্ট।',
'menus'     => ['sales.cart', 'sales.invoices', /* 25 total */],
'diagram'   => 'sales-cycle',   // optional module-level Mermaid
'updated_at'=> '2026-08-07',
```

The 8 modules + their colours:

| Module | Colour | Menus | Tagline (short) |
|---|---|---:|---|
| Master Data | slate | 30 | পণ্য, খদ্দের, সাপ্লায়ার, কর্মচারী — ব্যবসার মূল তথ্য |
| Inventory | amber | 28 | স্টক লেজার, ফিজিক্যাল কাউন্ট, ক্ষতি, ট্রান্সফার |
| Purchasing | sky | 8 | পি. অর্ডার, রিসিভ, রিটার্ন — সাপ্লায়ার থেকে মাল |
| Sales | emerald | 25 | কার্ট, ইনভয়েস, চালান, রিটার্ন, পেমেন্ট |
| Accounting | violet | 33 | জার্নাল, ট্রান্সফার, সাব-লেজার, রিকন, পিরিয়ড ক্লোজ |
| Finance | rose | 38 | ফিক্সড অ্যাসেট, বাজেট, কনসলিডেশন, ব্র্যাঞ্চ ডিমান্ড |
| Reports | teal | 6 (+30 secondary) | ড্যাশবোর্ড, রিপোর্ট হাব, সিএসভি এক্সপোর্ট |
| System | indigo | 15 (+2 secondary) | ইউজার, নোটিফিকেশন, অডিট, পলিসি, আর্কাইভ |

The `color` token propagates everywhere via a CSS custom property chain: the Blade
component emits `data-help-color="emerald"` → `help.js` maps it via `COLOR_MAP` → sets
`--help-tint-c1` / `--help-tint-c2` on the offcanvas root → 10 tinted surfaces (header
gradient, summary card, role chips, impacts border, callout, related chips, back button,
focus-visible rings, etc.) all pick it up.

---

## 5. The two endpoints

`HelpController` serves two HTML-partial endpoints (throttled `30,1` = 30 req/min):

| Route | Controller method | Returns |
|---|---|---|
| `GET /help/menu/{key}` | `menu()` | `components.help.menu-content` — the full Bangla help card for one menu |
| `GET /help/module/{key}` | `module()` | `components.help.module-content` — a module's intro + its menu list |

Both return HTTP 200 with an empty-state view when content doesn't exist (graceful, not
404). They're called via `fetch()` from `help.js` when a drawer opens — content is **never**
loaded on initial page render (lazy). The endpoints are auth-only (inside the `auth`
middleware group in `routes/web.php`).

---

## 6. The frontend (`help.js`, 810 lines)

`help.js` is one IIFE, no dependencies, vanilla JS. It manages:

### 6.1 Door 1 — contextual help
- Reads `window.HELP_CONFIG.currentMenuKey` (injected by the partial, resolved server-side).
- `?` floating button click → fetches `/help/menu/{key}` → injects into the right offcanvas.
- If `currentMenuKey` is null (unmapped route), shows the empty-state card with a mailto link.

### 6.2 Door 2 — the guide
- Footer pill click → opens the bottom-up module sheet (8 cards, server-rendered).
- Module card click → fetches `/help/module/{key}` → module offcanvas (intro + menu list).
- Menu row click → fetches `/help/menu/{key}` → menu offcanvas (full content). Module
  offcanvas closes; only one content drawer open at a time.
- Back button + breadcrumb navigation throughout.

### 6.3 Content-swap fade
When new content loads into a drawer, the old content fades out (120ms), the drawer's
`min-height` is preserved (CLS-safe), new content injects + fades in (200ms). The
min-height releases 260ms after inject. This prevents the drawer from collapsing during
the fetch.

### 6.4 Mermaid lazy-load
Mermaid is loaded from `cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js` **only** when
a `[data-mermaid-key]` block is injected into the DOM. A `mermaidLoading` flag prevents
double-loading; `pendingMermaid[]` queues blocks that arrive while the script is in flight.
If the CDN is blocked, the block silently hides. (Project rule: Mermaid is enhancement-only.)

### 6.5 The Phase 9 niceties
- **§9.1 In-guide search:** a search box at the top of the module sheet filters the 8 module
  cards (Bangla + English title + tagline) AND shows a flat menu-results list (label + key,
  capped 30) from `window.HELP_CONFIG.searchIndex` (215 menus, emitted by the partial).
  Clicking a result opens that menu's offcanvas directly. Client-side, no endpoint.
- **§9.2 Recently-viewed:** a `★` button beside the footer pill (hidden until history
  exists) opens a popover with the last 5 menus opened (colour-tinted, clickable).
  `localStorage` key `help:recent`, feature-detected with try/catch — degrades silently to
  hidden if unavailable (private mode / disabled / quota).
- **§9.3 Keyboard shortcuts:** `?` opens current-page help (guarded against open drawers +
  input fields); `Shift+G` opens the module sheet.
- **§9.4 Empty-state polish:** the server-rendered empty-state shows a 72px amber
  illustration (feather icon) + a gradient "অনুরোধ পাঠান" mailto button pre-filled with the
  menu key + module title.
- **§9.5 Print:** a "প্রিন্ট করুন" button appears in the menu offcanvas (actions bar) only
  when real content is loaded; opens a new window with a clean print stylesheet + the menu's
  full content, then triggers `window.print()`. Pop-up-blocked fallback alerts in Bangla.

### 6.6 `window.HELP_CONFIG`
Emitted by the `partials/help-system.blade.php` include. Contains:
- `endpoints.menu` / `endpoints.module` — the two route URLs (with `__KEY__` placeholder).
- `currentMenuKey` — the resolved menu key for the current page (or null).
- `csrfToken` — for any future POST needs (currently GET-only).
- `moduleTitles` — 8 module key → `{title_bn, color}` for the module offcanvas header.
- `searchIndex` — flat array of all 215 menus `{key, title_bn, title_en, tagline, color,
  icon, menus:[{key,label}]}` for the client-side search (Phase 9).

---

## 7. The CSS (`help-system.css`, 1,184 lines)

The stylesheet is organised in phase-order blocks (P2 base → P4 Door 1 → P5 Door 2 → P8
premium theme + a11y + responsive → P9 niceties). Key systems:

### 7.1 The theme
- **rounded-2xl** cards (16px radius), layered soft shadows (`shadow-sm` + `shadow-lg`).
- **Glassmorphism:** footer pill (`backdrop-filter: blur(16px) saturate(180%)`), bottom
  sheet header (`blur(8px)`).
- **Spring easing:** `cubic-bezier(.22, 1, .36, 1)` 280ms on all transitions — a confident,
  slightly-overshooting ease.
- **Module colour tinting:** the `--help-tint-c1/c2` variable chain (§4).

### 7.2 The motion
- FAB pulse animation (subtle, attention-grabbing).
- Content-swap fade-out (120ms) → fade-in (200ms) keyframes (`.help-body--fade-out`).
- Card hover lift (translateY -2px), menu-item hover translateX(4px).
- Pop-in animation for the recently-viewed popover.

### 7.3 Accessibility
- **Focus-visible rings:** 3px outline tinted to the module colour on every interactive
  element.
- **Focus trap:** Tab cycles within each open offcanvas; Shift+Tab reverses; focus returns
  to the trigger button on close. Managed by `help.js` (`wireFocusManagement()`).
- **ARIA:** all 3 offcanvas containers have `role="dialog" aria-modal="true"
  aria-labelledby`. The FAB + footer pill have `aria-haspopup` + `aria-expanded` +
  `aria-controls`.
- **Tap targets ≥ 44px** on mobile (≤ 575.98px breakpoint) — WCAG 2.5.5.
- **`prefers-reduced-motion`:** a single final CSS block nullifies every animation +
  transition across all phases. The JS also gates the fade-out path behind
  `prefersReducedMotion()`.

### 7.4 Responsive
- Mobile (≤ 575.98px): FAB shrinks to 40px and moves to `bottom: 70px` (above footer pill);
  bottom sheet becomes a true 85vh bottom drawer with 18px top corners; right offcanvases
  fill 100% viewport height with fixed header + independently-scrolling body (flex column).
- `-webkit-overflow-scrolling: touch` for iOS momentum.

---

## 8. The 6 Blade components

All in `laravel/resources/views/components/help/` (anonymous components, `x-help.*`):

| Component | Role |
|---|---|
| `help-button.blade.php` | Door 1 floating `?` FAB. Carries `data-menu-key`. |
| `guide-footer.blade.php` | Door 2 footer pill + the `★` recently-viewed button + popover. |
| `help-offcanvas.blade.php` | The shared right offcanvas (back bar + actions bar + body). Hosts menu content. |
| `module-offcanvas.blade.php` | The module intro offcanvas (shown between sheet → menu). |
| `module-sheet.blade.php` | The bottom-up sheet (8 module cards + search box). Server-renders all 8 cards. |
| `menu-content.blade.php` | The content renderer (turns a content array into HTML). Also renders empty-state. |
| `module-content.blade.php` | The module renderer (intro + menu list). |

(The `partials/help-system.blade.php` include wires all of these + the two `<asset>` tags +
the `window.HELP_CONFIG` script into one drop-in line.)

---

## 9. Caching

`HelpService` caches the 4 registries in Laravel's cache for 1 day (`CACHE_TTL = 86400`):

| Cache key | What | When to clear |
|---|---|---|
| `help:registry` | `registry.php` array | After editing `registry.php` |
| `help:action-registry` | `action-registry.php` array | After editing `action-registry.php` |
| `help:modules` | `modules.php` array | After editing `modules.php` |
| `help:diagrams` | `diagrams.php` array | After editing `diagrams.php` |

**Content files (`menus/**/*.php`) are NOT cached** — they're `require`d per-request and
opcode-cached by PHP. Editing a content file needs no cache clear.

Clear all four: `php artisan cache:clear`, or call `HelpService::clearCache()`. The
in-memory `$this->registry` etc. properties are also nulled.

---

## 10. Phase history (10 phases, all shipped)

| Phase | Scope | Commit |
|---|---|---|
| P1 | Discovery: `docs/help-inventory.csv` (215 rows) | (pre-history) |
| P2–3 | Scaffold + routing: HelpService, controller, registries, layout include | `31ba74a`–`929fc31` |
| P4 | Door 1: FAB + right offcanvas + Mermaid lazy-load + 3 demo files | `31ba74a` |
| P5 | Door 2: footer pill + module sheet + module offcanvas + content-swap UX | `38f7aa5` |
| P6 | Empty-state sweep + registry backfill (0 gaps) | `afe6979` |
| P7 | Author 212 Bangla content files → 215/215 coverage | `37e6ede` |
| P8 | Visual polish (premium theme) + a11y (focus trap, aria) + responsive | `10af839` |
| P9 | Interactive niceties: search, recently-viewed, shortcuts, print | `98ead71` |
| P10 | QA + docs + handoff (this phase) | *(this commit)* |

See `docs/worklog.md` (in-repo) for the full per-phase work logs.

---

## 11. Known limitations + future work

1. **`help.js` size:** 9.8 KB gzipped (over the §11.4 budget of 4 KB). The budget was set
   before Phase 9's search/recent/shortcuts/print niceties were scoped. Minification would
   shave ~30% but the gzip saving is marginal (whitespace/comments already compress well).
   Acceptable as a one-time cached download. If it must shrink: split the Phase 9 niceties
   into a separate `help-niceties.js` loaded only after first interaction.

2. **Mermaid CDN dependency:** if `cdn.jsdelivr.net` is blocked (BDIX VPS), diagrams hide
   silently. Future: bundle Mermaid locally (adds ~1 MB to the repo, violates the
   "no npm deps" rule — trade-off to revisit if diagrams become critical).

3. **22 soft content warnings:** 22 content files have a `summary` with 2 sentences instead
   of 1. Acceptable (the second sentence adds context), but a content-quality pass could
   tighten them. Not a blocker.

4. **No CI check for the filename invariant:** the Phase 7 validator checks `key` ==
   path-derived key (Check 5), but only via the `key` *field*, not by verifying the
   *filename* matches the slug. The Phase 10 bug (`invoice.php` vs `invoices.php`) slipped
   through because the `key` field was correct but the filename was wrong. A future
   enhancement: add a Check 5b that verifies `menus/{module}/{slug}.php` exists for every
   `modules.php` menu_key (the `phase6_sweep.py` Sweep A already does this implicitly).

5. **Bangla review:** per plan §11.3, all Bangla should be reviewed by a native speaker
   (Sajid/team) before each module merge. This is a human-process dependency, not a code one.

6. **Blade `@json()` multi-statement gotcha:** the `@json()` directive splits its argument
   on the first comma (to inject the default JSON-flags argument). Passing a multi-statement
   closure / IIFE / any expression with commas inside `(...)` produces mangled PHP and a
   `ParseError: syntax error, unexpected token ";"` at runtime. **This bit us in Phase 9:**
   the `searchIndex` emission in `partials/help-system.blade.php` originally used
   `@json((function () use ($helpService) { ... })())` — it passed all static (regex-based)
   validators but crashed the real PHP runtime on `/admin/sales-invoices`. Fixed by moving
   the computation into the `@php` block and emitting `@json($searchIndex)` on a single
   line. See `docs/HELP_AUTHORING_GUIDE.md` §2 "Blade gotcha" for the do/don't pattern.
   **Root cause of the gap:** the sandbox has no PHP runtime, so the Phase 9/10 validators
   (pure-Python regex checkers) couldn't catch a compile-time PHP error. Future: add a
   `php -l` lint pass to CI, or at minimum a Blade-compile dry-run.

---

## 12. File map

```
laravel/
├── app/
│   ├── Http/Controllers/HelpController.php          # 2 endpoints (menu + module)
│   └── Services/Help/HelpService.php               # 4-layer resolver + content loader
├── resources/
│   ├── help/
│   │   ├── registry.php                            # 214 route_name → menu_key
│   │   ├── action-registry.php                     # 214 Controller@action + 59 @* wildcards
│   │   ├── modules.php                             # 8 modules + their menus[] (183 keys)
│   │   ├── diagrams.php                            # Mermaid snippets (keyed)
│   │   └── menus/{module}/{slug}.php               # 215 content files
│   └── views/
│       ├── partials/help-system.blade.php         # the 1-line @include drop-in
│       └── components/help/
│           ├── help-button.blade.php               # Door 1 FAB
│           ├── guide-footer.blade.php              # Door 2 pill + ★ recent popover
│           ├── help-offcanvas.blade.php            # shared right offcanvas (menu content)
│           ├── module-offcanvas.blade.php          # module intro offcanvas
│           ├── module-sheet.blade.php               # bottom-up 8-card sheet + search
│           ├── menu-content.blade.php              # content renderer + empty-state
│           └── module-content.blade.php            # module renderer
├── public/assets/
│   ├── css/help-system.css                         # 1,184 lines, 7.8 KB gzip
│   └── js/help.js                                  # 810 lines, 9.8 KB gzip
└── routes/web.php                                  # Route::prefix('help')->throttle:30,1

docs/
├── HELP_SYSTEM_IMPLEMENTATION_PLAN.md              # 10-phase design + AC + Appendix A/B
├── HELP_AUTHORING_GUIDE.md                        # author how-to (1 page)
├── HELP_SYSTEM_DEMO.md                             # handoff walkthrough
├── help-coverage-report.md                         # auto-generated by phase6_sweep.py
├── help-coverage-matrix.csv                       # auto-generated route-level matrix
├── help-inventory.csv                              # the 215 curated page routes (P1 source)
└── help-sweep/
    ├── phase6_sweep.py                             # route-resolution sweep
    └── phase7_validate.py                           # content schema validator
```

---

*For the author-facing how-to, see `docs/HELP_AUTHORING_GUIDE.md`. For the original design
+ acceptance criteria + the full 10-phase plan, see `docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md`.
For the handoff demo walkthrough, see `docs/HELP_SYSTEM_DEMO.md`.*
