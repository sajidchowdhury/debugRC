# 📚 RC_ERP Menu & Module Helper System — Implementation Plan

> **Vision:** A two-tier, Bangla-first, colourful, premium in-app guide that explains
> *every menu* and *every module* in plain human language — so no user (and no developer)
> ever stares at a screen wondering *"eita ki ar keno?"*.
>
> **Audience:** The implementation team (you + the AI assistant) executing phase by phase.
> **Owner:** Sajid (RC_ERP maintainer)
> **Status:** Planning — awaiting kickoff approval

---

## 0. TL;DR — What are we building?

A **help system with two doors**, both leading into the same rich, Bangla, diagram-rich
explainer content:

```mermaid
flowchart LR
    subgraph Door1["🚪 Door 1 — Micro (current page)"]
        H1["Help button<br/>fixed in corner"] --> OC1["Right Offcanvas<br/>explains THIS menu"]
    end
    subgraph Door2["🚪 Door 2 — Macro (whole system)"]
        F["Fixed footer pill<br/>'🧭 My Creative Code Guide'"] --> BS["Bottom-up Sheet<br/>lists all modules"]
        BS --> MO["Module Offcanvas<br/>what it includes + its menus"]
        MO -->|click a menu| OC2["Menu Offcanvas<br/>(same component as Door 1)"]
    end

    OC1 -. shared.-> OC2
```

| Door | Trigger | Opens | Explains |
|---|---|---|---|
| **1. Page help** | Floating `?` button (per page, corner) | **Right offcanvas** | The **current menu/page** the user is on |
| **2. System guide** | Fixed footer pill `🧭 My Creative Code Guide` | **Bottom-up sheet** → **module offcanvas** → **menu offcanvas** | The **whole system**: modules + every menu under them |

**One reusable `<x-help-offcanvas />` component** is used by both doors. Door 1 just
pre-selects the menu key from the current route; Door 2 lets the user browse from module → menu.

---

## 1. Why this exists

The ERP has **328 Blade views, 58 admin controllers, ~150 menus** across 13 modules. Even
the maintainer forgets what a menu does. New operators get lost. The result: mistakes,
support tickets, and fear of clicking.

This plan builds a **single, joyful, colourful guide** that:

1. Tells the user on **any page** what this menu is, who it's for, what data it touches,
   and what to be careful about — in **simple Bangla**, with icons and mini-diagrams.
2. Lets anyone explore the **whole system from the footer** — module by module, menu by menu.
3. Is **fun to use**, not a wall of text: colour-coded modules, chips, callouts, flow diagrams.

---

## 2. Goals & Non-Goals

### ✅ Goals
- Every authenticated page shows a floating **Help** button → opens a **right offcanvas**
  with the current menu's Bangla explanation.
- A **fixed footer pill** `🧭 My Creative Code Guide` is visible on every page → opens a
  **bottom-up sheet** with all modules as colourful cards.
- Clicking a module opens a **module offcanvas** (right side) describing the module + listing
  its menus; clicking a menu opens the **menu offcanvas** (same as Door 1).
- Content is **100% Bangla**, plain-language, non-technical, role-aware ("কাদের জন্য"), with
  "কী কাজ করা যায়", "কাদের ডেটা পরিবর্তন করে", "সাবধানতা", and a mini flow diagram.
- Visually: **colourful, premium, modern, responsive, mobile-friendly, fun.**
- Works **offline / no new backend services** — pure Laravel + Blade + Bootstrap + a little JS.

### ❌ Non-Goals
- **Not** a full documentation site (we already have `AI_CONTEXT/`). This is in-app
  contextual help, not a wiki.
- **Not** technical/developer docs — end-user + operator facing only. No SQL, no class names.
- **Not** editable by admins in v1 (content is file-based; admin editor = future enhancement).
- **Not** a chatbot / search engine — that's Phase 13 (AI sidecar). This is structured
  explainer content. (A lightweight in-guide search is included as a nice-to-have in Phase 8.)
- **Not** a replacement for training — it's a memory aid / discoverability layer.

---

## 3. Design Principles (the "fun, not boring" rules)

| # | Principle | How we enforce it |
|---|---|---|
| 1 | **Bangla first, simple Bangla** | All user-facing copy in Bangla. English label kept as a small subtitle only. No jargon, no English-only acronyms. |
| 2 | **Scannable, not wall-of-text** | Max 1 intro line + 5 bullets per section. Use icons on every bullet. |
| 3 | **Show, don't tell** | A tiny Mermaid/SVG flow diagram per menu where it helps (workflow menus). Skip diagrams for trivial CRUD menus. |
| 4 | **Colour = module identity** | Each module has a fixed colour (Sales=emerald, Inventory=amber…). The whole help UI for that module tints to its colour. |
| 5 | **Role chips, not paragraphs** | "কাদের জন্য" shown as coloured chips (Salesman / Accountant…), not sentences. |
| 6 | **Always 2 taps away** | From any page → footer → module → menu ≤ 2 taps. From any page → help button → explanation = 1 tap. |
| 7 | **Premium feel** | Gradients, soft shadows, rounded-2xl, glassmorphism on the sheets, spring-easing animations, icon accents. |
| 8 | **Mobile-first** | Offcanvas = full-screen on mobile; sheets = bottom drawer; tap targets ≥ 44px. |
| 9 | **Consistent everywhere** | One layout partial injected into `layouts.admin` → every page gets it free. |
| 10 | **Graceful fallback** | If no help content exists for a route yet → show a friendly "এই পেজের সাহায্য এখনও তৈরি হয়নি" card with a link to the module guide. Never crash, never show a 404. |

---

## 4. High-Level Architecture

```mermaid
flowchart TB
    subgraph Client["Browser (per page)"]
        L["layouts/admin.blade.php"] -->|injects| PARTIAL["@include('partials.help-system')"]
        PARTIAL --> HB["<x-help-button>"]
        PARTIAL --> FF["<x-guide-footer>"]
        PARTIAL --> OCC["<x-help-offcanvas> (shared, hidden)"]
        PARTIAL --> MS["<x-module-sheet> (hidden)"]
        PARTIAL --> MOC["<x-module-offcanvas> (hidden)"]
        HB -.click.-> OCC
        FF -.click.-> MS
        MS -.click module.-> MOC
        MOC -.click menu.-> OCC
    end

    subgraph Server["Laravel"]
        HS["HelpService<br/>(route → menu key → content)"]
        HC["HelpController<br/>GET /help/menu/{key}<br/>GET /help/module/{key}"]
        FILES["resources/help/<br/>modules/*.php + menus/*.php + registry.php"]
        HC --> FILES
        HS --> FILES
    end

    OCC -.lazy-load content.-> HC
    MOC -.lazy-load content.-> HC
    HS -.resolves current menu.-> PARTIAL
```

### 4.1 Tech choices (using what the project already has — no new deps)

| Concern | Choice | Why |
|---|---|---|
| UI shell | **Bootstrap 5.3 `offcanvas`** (right + bottom variants) | Already shipped in `laravel/public/assets/css/bootstrap.min.css` + JS. Zero new JS framework. |
| Icons | **FontAwesome** (already loaded) | Consistent with the rest of the ERP. |
| Animations | **CSS transitions + keyframes** (custom) | Spring-ease, fade-up, shimmer. No Anime.js needed. |
| Diagrams | **Mermaid.js via CDN** (loaded lazily, only when a diagram exists) | One `<script>` tag, renders inline. ~80KB, cached. Falls back to a static SVG if CDN blocked (the project already avoids CDNs for core assets — Mermaid is enhancement-only, so acceptable). |
| Interactions | **Vanilla JS + a tiny `help.js`** (no Alpine, no jQuery dependency) | Bootstrap's data-API handles show/hide; we add ~120 lines for content loading + search. |
| Content storage | **Structured PHP files** in `resources/help/` returning arrays | Version-controlled, reviewable, no migration needed, easy to diff. (DB editor is a future enhancement.) |
| Theme layer | One new CSS file `assets/css/help-system.css` (additive, scoped to `.help-*` classes) | Does **not** touch existing `custom.css` / `rc-erp.css`. Safe. |

> **Why not the database?** Help content is static, versioned, and reviewed by people —
> files + PR review beat a DB editor for this. The existing `menus` table gives us the
> route↔menu mapping; we add only a `help_key` concept in a PHP registry, no schema change.

### 4.2 The content resolution flow (Door 1)

```mermaid
sequenceDiagram
    participant U as User
    participant B as Browser
    participant L as Layout
    participant HS as HelpService
    participant HC as HelpController
    participant F as help/*.php files

    U->>B: Visits /admin/customers
    B->>L: Renders page (extends layouts.admin)
    L->>HS: HelpService::menuKeyForRoute('admin.customers.index')
    HS->>F: registry.php lookup
    F-->>HS: 'master-data.customers'
    HS-->>L: key='master-data.customers', module='master-data'
    L-->>B: Renders help button with data-menu-key
    U->>B: Clicks help button
    B->>HC: GET /help/menu/master-data.customers (fetch HTML partial)
    HC->>F: load menus/master-data/customers.php
    F-->>HC: array(Bangla content)
    HC-->>B: rendered <x-help-menu-content> HTML
    B->>B: Inject into offcanvas body, show()
    B-->>U: Right offcanvas slides in with Bangla explanation
```

### 4.3 The browsing flow (Door 2)

```mermaid
sequenceDiagram
    participant U as User
    participant B as Browser
    participant HC as HelpController

    U->>B: Clicks footer pill "🧭 My Creative Code Guide"
    B->>B: Show bottom sheet (pre-rendered module cards, no network)
    U->>B: Clicks "Sales" module card
    B->>HC: GET /help/module/sales (HTML partial)
    HC-->>B: module offcanvas HTML (intro + menu list)
    B->>B: Open module offcanvas (right)
    U->>B: Clicks "Sales Invoice" menu chip
    B->>HC: GET /help/menu/sales.invoice
    HC-->>B: menu content HTML
    B->>B: Replace module offcanvas content with menu content (or open menu offcanvas stacked)
    B-->>U: Sees the menu explanation
```

> **UX decision:** When a menu chip is clicked from inside a module offcanvas, we
> **swap the same right offcanvas's content** (with a slide/fade transition) rather than
> stacking two offcanvases. A small breadcrumb at the top (`মডিউল: সেলস › সেলস ইনভয়েস`)
> keeps context. This is simpler, faster, and feels like a single flowing guide.

---

## 5. Content Schema (the contract for every help file)

### 5.1 Menu content file — `resources/help/menus/{module}/{menu}.php`

```php
<?php
// resources/help/menus/sales/invoice.php
return [
    'key'        => 'sales.invoice',
    'module'     => 'sales',
    'title_bn'   => 'সেলস ইনভয়েস',
    'title_en'   => 'Sales Invoice',
    'icon'       => 'fa-file-invoice-dollar',
    'summary'    => 'খদ্দেরকে পণ্য বিক্রি করে যে বিল তৈরি হয়, এটি সেই বিল। এখান থেকেই খদ্দেরের বকেয়া ও আপনার আয় শুরু হয়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',        'text' => 'নতুন ইনভয়েস তৈরি করা'],
        ['icon' => 'fa-list',        'text' => 'আগের সব ইনভয়েস দেখা ও খুঁজা'],
        ['icon' => 'fa-print',       'text' => 'ইনভয়েস প্রিন্ট বা কাস্টমারকে পাঠানো'],
        ['icon' => 'fa-undo',        'text' => 'ভুল হলে রিটার্ন করা'],
        ['icon' => 'fa-circle-check', 'text' => 'কাস্টমারের পেমেন্ট এন্ট্রি করা'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',     'what' => 'বকেয়া বাড়ে'],
        ['who' => 'স্টক',        'what' => 'পণ্য কমে যায়'],
        ['who' => 'হিসাব',       'what' => 'বিক্রয় আয় ও ভ্যাট লেজারে লেখা হয়'],
    ],

    'cautions' => [
        'ইনভয়েস একবার ফাইনাল হলে সরাসরি এডিট করা যায় না — রিটার্ন দিতে হবে।',
        'পর্যাপ্ত স্টক না থাকলে ইনভয়েস তৈরি হবে না।',
    ],

    'related' => ['sales.cart', 'sales.challan', 'sales.return', 'sales.customer-payment'],

    'diagram' => 'sales-invoice-flow',  // key into a Mermaid snippet, see §5.3

    'updated_at' => '2026-08-06',
];
```

### 5.2 Module content file — `resources/help/modules/{module}.php`

```php
<?php
// resources/help/modules/sales.php
return [
    'key'         => 'sales',
    'title_bn'    => 'সেলস (বিক্রি)',
    'title_en'    => 'Sales',
    'icon'        => 'fa-cart-shopping',
    'color'       => 'emerald',   // maps to the palette in §6.1
    'tagline'     => 'খদ্দেরকে পণ্য বিক্রি, বিল তৈরি, পাঠানো, টাকা আদায় — সব এখানে।',
    'intro'       => 'এই মডিউলে পুরো বিক্রয় সাইকেল চলে: কার্ট তৈরি → ইনভয়েস → গোডাউন চালান → ডেলিভারি → পেমেন্ট → কমিশন।',
    'menus'       => ['sales.cart', 'sales.invoice', 'sales.challan',
                      'sales.return', 'sales.customer-payment', 'sales.commission'],
    'diagram'     => 'sales-cycle',
    'updated_at'  => '2026-08-06',
];
```

### 5.3 Diagrams — `resources/help/diagrams.php`

```php
return [
    'sales-invoice-flow' => <<<MERMAID
flowchart LR
    A[কার্ট] --> B[ইনভয়েস তৈরি]
    B --> C[গোডাউন চালান]
    C --> D[ডেলিভারি]
    D --> E[পেমেন্ট গ্রহণ]
    B -.-> F[(স্টক কমে)]
    B -.-> G[(খদ্দের বকেয়া বাড়ে)]
    MERMAID,
    'sales-cycle' => '...',
];
```

> Diagrams are **optional per menu**. Author one only when the workflow has ≥3 steps
> and a picture genuinely helps. Don't force a diagram onto a trivial "list of X" menu.

### 5.4 Route registry — `resources/help/registry.php`

Maps a **route name** (or `controller@action` fallback) → **menu key**.

```php
return [
    'admin.customers.index'       => 'master-data.customers',
    'admin.customers.create'      => 'master-data.customers',
    'admin.customers.show'        => 'master-data.customers',
    'admin.sales-invoice.index'   => 'sales.invoice',
    // ... one entry per route that should map to a help page
    // (index/create/show/edit all collapse to the same menu key)
];
```

Resolution priority in `HelpService::menuKeyForRoute()`:
1. Exact route name match.
2. `controller@action` match.
3. `controller@*` wildcard match (any action of that controller → same menu key).
4. Fallback: `null` → the help button shows the "no help yet" friendly card.

---

## 6. Design System

### 6.1 Module colour palette

| Module | Colour token | Hex (gradient start → end) | Icon |
|---|---|---|---|
| Master Data | `slate` | `#475569 → #1e293b` | `fa-database` |
| Inventory | `amber` | `#f59e0b → #b45309` | `fa-boxes-stacked` |
| Purchasing | `sky` | `#0ea5e9 → #0369a1` | `fa-truck-ramp-box` |
| Sales | `emerald` | `#10b981 → #047857` | `fa-cart-shopping` |
| Accounting | `violet` | `#8b5cf6 → #6d28d9` | `fa-calculator` |
| Finance (Assets/Budget/Consolidation/Branch Demand) | `rose` | `#f43f5e → #be123c` | `fa-coins` |
| Reports | `teal` | `#14b8a6 → #0f766e` | `fa-chart-pie` |
| System/Admin | `indigo` | `#6366f1 → #4338ca` | `fa-gear` |
| (Neutral / fallback) | `slate-soft` | `#94a3b8 → #475569` | `fa-circle-question` |

> ⚠️ Indigo/violet above are module-identity colours chosen by the business domain, **not**
> the generic app chrome. The chrome (buttons, footer pill, default text) stays neutral
> slate/amber to avoid the "all indigo" anti-pattern. The user's "no indigo/blue" styling
> rule applies to the *app's primary brand chrome*; here indigo is scoped to the System
> module badge only. We'll confirm this with the owner before Phase 8.

### 6.2 Component visual rules

- **Help button (corner):** 48×48px circular, gradient amber→orange, soft shadow
  `0 8px 24px -6px rgba(245,158,11,.5)`, hover lifts `-2px` + pulse ring. Icon: `fa-question`.
  Aria-label: "সাহায্য". Position: `fixed bottom-right: 20px` (above the footer pill).
- **Footer pill:** `fixed bottom: 0`, full-width translucent bar with a centered pill
  `🧭 My Creative Code Guide`. Glassmorphism (`backdrop-filter: blur(12px)`, `bg: rgba(255,255,255,.7)`).
  On mobile it stays a single-line pill; tapping opens the bottom sheet.
- **Right offcanvas:** width 420px desktop / 100% mobile. Header = gradient tinted to the
  module colour + icon + Bangla title + close `×`. Body: white, scannable sections.
- **Bottom-up sheet (module list):** auto-height up to 70vh, scrollable. Grid of module
  cards (2 cols desktop, 1 col mobile). Each card: gradient header strip + icon + Bangla
  name + one-line tagline.
- **Module offcanvas:** same shell as menu offcanvas, tinted to the module colour.
- **Section components inside content:**
  - Role chips: pill `bg-{colour}-soft text-{colour}-dark`, 12px, rounded-full.
  - "কী কাজ করা যায়" → icon-bullet list, 1.05rem, gap-2.
  - "কাদের ডেটা পরিবর্তন করে" → mini table: `who | what`, coloured left border.
  - "সাবধানতা" → amber/red callout card with `fa-triangle-exclamation`.
  - Diagram → rendered Mermaid block, centred, max-width 100%.
  - Related menus → chip row, clickable, opens their offcanvas.

### 6.3 Motion (spring-ease, ≤300ms, never nauseating)

| Element | Enter | Exit |
|---|---|---|
| Right offcanvas | slide+fade from right, 280ms `cubic-bezier(.22,1,.36,1)` | slide right, 200ms |
| Bottom sheet | slide+fade from bottom, 280ms same ease | slide down, 200ms |
| Help button | idle: gentle float 4px every 3s; hover: scale 1.08 + ring pulse | — |
| Module card hover | lift `-3px`, shadow grow, gradient brighten | — |
| Content swap (module→menu) | old fades out 120ms, new fades+slides-up 200ms | — |
| Diagram | fade+scale-in 350ms after Mermaid renders | — |

> Respect `prefers-reduced-motion`: all animations collapse to instant show/hide.

---

## 7. File / Code Layout (what we will create)

```
laravel/
├── app/
│   ├── Services/Help/HelpService.php          # route → menu key resolver + content loader
│   └── Http/Controllers/HelpController.php     # GET /help/menu/{key}, /help/module/{key}
├── resources/
│   ├── help/                                   # ★ new — the content library
│   │   ├── registry.php                        # route name → menu key
│   │   ├── diagrams.php                        # Mermaid snippets, keyed
│   │   ├── modules/
│   │   │   ├── master-data.php
│   │   │   ├── inventory.php
│   │   │   ├── purchasing.php
│   │   │   ├── sales.php
│   │   │   ├── accounting.php
│   │   │   ├── finance.php
│   │   │   ├── reports.php
│   │   │   └── system.php
│   │   └── menus/
│   │       ├── master-data/{products,customers,suppliers,employees,banks,ledgers,branches,warehouses,users}.php
│   │       ├── inventory/{stock-ledger,stock-take,damage,warehouse-transfer,uom,stock-adjustment}.php
│   │       ├── purchasing/{po,receive,return,audit}.php
│   │       ├── sales/{cart,invoice,challan,return,customer-payment,commission}.php
│   │       ├── accounting/{manual-journal,money-transfer,supplier-txn,customer-txn,employee-txn,other-income,other-expense,period-close,fiscal-year,bank-recon}.php
│   │       ├── finance/{fixed-assets,budgets,dimensions,consolidation,branch-demand}.php
│   │       ├── reports/{reports-hub,dashboards,csv-export}.php
│   │       └── system/{notifications,system-policy,archive,audit,shadow-mode,partition-health,users,employees}.php
│   ├── views/
│   │   ├── components/help/                    # ★ new Blade components
│   │   │   ├── help-button.blade.php
│   │   │   ├── guide-footer.blade.php
│   │   │   ├── help-offcanvas.blade.php         # shared right offcanvas shell
│   │   │   ├── module-sheet.blade.php           # bottom-up module list
│   │   │   ├── module-offcanvas.blade.php
│   │   │   ├── menu-content.blade.php           # renders a menu's Bangla content
│   │   │   └── module-content.blade.php
│   │   └── partials/help-system.blade.php       # ★ includes all the above + wires JS
│   └── ...
└── public/assets/
    ├── css/help-system.css                      # ★ scoped styles (.help-*)
    └── js/help.js                               # ★ ~150 lines vanilla JS
```

> **One-line wiring into the existing app:** add `@include('partials.help-system')` at the
> end of `resources/views/layouts/admin.blade.php` (and optionally `app.blade.php`).
> That single include pulls in every component + CSS + JS. No other layout changes.

---

## 8. The Phased Plan (phases → sessions)

> Each **session** = one focused work block (roughly one chat/work day). A phase may span
> multiple sessions. Every session ends with **runnable, committed** code (no half-states).
> Acceptance criteria (AC) at the end of each phase must pass before the next phase starts.

```mermaid
gantt
    title Help System — phases (approx sessions)
    dateFormat YYYY-MM-DD
    axisFormat %d
    section Foundation
    P1 Discovery          :p1, 2026-08-07, 1d
    P2 Schema + Scaffold  :p2, after p1, 1d
    P3 Core wiring        :p3, after p2, 2d
    section Doors
    P4 Door 1 (page help):p4, after p3, 2d
    P5 Door 2 (footer+sheet+module):p5, after p4, 2d
    section Content (split by module)
    P7a Master Data      :p7a, after p5, 1d
    P7b Inventory        :p7b, after p7a, 1d
    P7c Purchasing       :p7c, after p7b, 1d
    P7d Sales            :p7d, after p7c, 1d
    P7e Accounting       :p7e, after p7d, 2d
    P7f Finance          :p7f, after p7e, 1d
    P7g Reports          :p7g, after p7f, 1d
    P7h System           :p7h, after p7g, 1d
    section Polish + Ship
    P8 Visual polish     :p8, after p7h, 2d
    P9 Niceties          :p9, after p8, 1d
    P10 QA + handoff     :p10, after p9, 1d
```

---

### 🧱 Phase 1 — Discovery & Inventory  *(1 session)*

**Goal:** Know exactly what we're documenting. Produce a complete menu inventory so no
menu is missed later.

**Tasks**
1. List every route in `routes/web.php` (1,939 lines) tagged `auth` → extract
   `{method, uri, name, controller@action}`.
2. Cross-reference with the `menus` table (`menu_label, controller, action, icon, parent_id`)
   via `MenuService::getAllMenus()` to get the **human label + parent (module)** for each.
3. Group routes into the **8 modules** defined in §6.1 (decide edge cases: e.g., does
   "Branch Demand" live under Finance or its own? → Finance per the colour table).
4. Produce `docs/help-inventory.csv` with columns:
   `module, menu_key, route_name, uri, controller, action, menu_label_bn, has_help_content`.
5. Identify the **layout(s)** to inject into (`layouts/admin.blade.php` confirmed; check if
   `app.blade.php` is also used by authenticated pages).

**Deliverable:** `docs/help-inventory.csv` + a short "Module → menus" summary appended
to this plan as **Appendix A**.

**AC:**
- [ ] Every authenticated route has a row in the inventory CSV.
- [ ] Every row is assigned to exactly one module.
- [ ] A human-readable module→menu summary exists.

---

### 🏗️ Phase 2 — Content Schema + Scaffold  *(1 session)*

**Goal:** Create the empty skeleton — directories, service, controller, registry, layout
include — so the rest of the phases just *fill in* content and components.

**Tasks**
1. Create directory tree from §7 (`resources/help/...`).
2. Create `HelpService` with stubs:
   - `menuKeyForRoute(string $routeName): ?string`
   - `loadMenuContent(string $key): ?array`
   - `loadModuleContent(string $key): ?array`
   - `modules(): array` (list of all module keys + meta for the bottom sheet)
3. Create `HelpController` with two endpoints returning `view('components.help.menu-content', [...])`:
   - `GET /help/menu/{key}`
   - `GET /help/module/{key}`
   - Both return 404 → a friendly "not yet written" partial (HTTP 200 with empty state).
4. Add routes to `routes/web.php` (auth + `role` middleware, throttled).
5. Create `resources/help/registry.php` (empty array + a few example mappings).
6. Create the empty Blade components from §7 (stubs that render a placeholder).
7. Create `partials/help-system.blade.php` that includes the components + CSS/JS tags.
8. Add `@include('partials.help-system')` to `layouts/admin.blade.php`.
9. Create `public/assets/css/help-system.css` (scoped `.help-*`, empty rules).
10. Create `public/assets/js/help.js` (empty IIFE).

**AC:**
- [ ] Visiting any authenticated page shows the help button + footer pill (placeholder
      styling, but visible) with **no console errors**.
- [ ] `GET /help/menu/any-key` returns the "not yet written" friendly card.
- [ ] `GET /help/module/sales` returns the module skeleton with empty menu list.
- [ ] `php artisan route:list | grep help` shows the two routes, auth-protected.

---

### ⚙️ Phase 3 — Core Wiring (HelpService + layout integration)  *(2 sessions)*

#### Session 3.1 — HelpService resolution + registry population
- Implement the full resolution priority (route name → `controller@action` → wildcard → null).
- Populate `registry.php` from the Phase 1 inventory CSV (every authenticated route).
- Implement `loadMenuContent` / `loadModuleContent` with file-existence guards + caching
  (cache the parsed array per request via a static property; Laravel's `cache()` for
  cross-request — optional, tag `help:content:{key}`, TTL 1 day, cleared by `php artisan
  cache:clear`).

#### Session 3.2 — Layout integration + context injection
- In `layouts/admin.blade.php`, expose the current menu key to the page via
  `@php($helpMenuKey = app(\App\Services\Help\HelpService::class)->menuKeyForRoute(Route::currentRouteName()))
  ` — then pass it as a `data-menu-key` attribute on the help button.
- Add the CSRF token + the help API base path as `window.HELP_CONFIG = {...}` in the partial.
- Build the `partials/help-system.blade.php` include so it's a single line addition.
- Test on 5 representative pages (dashboard, customer list, sales invoice list, journal
  create, stock take) — confirm the help button gets the right `data-menu-key`.

**AC (end of Phase 3):**
- [ ] `HelpService::menuKeyForRoute()` returns the correct key for ≥95% of sampled routes.
- [ ] The help button's `data-menu-key` matches the page it's on, on all sampled pages.
- [ ] No help content files exist yet — button opens the "not yet written" card gracefully.

---

### 🚪 Phase 4 — Door 1: Help Button + Right Offcanvas  *(2 sessions)*

#### Session 4.1 — The right offcanvas shell + content renderer
- Build `components/help/help-offcanvas.blade.php` (Bootstrap offcanvas-end, 420px, full-screen
  mobile, custom header).
- Build `components/help/menu-content.blade.php` that renders the §5.1 schema:
  - header (icon + Bangla title + English subtitle)
  - summary card (tinted to module colour)
  - role chips row ("কাদের জন্য")
  - "কী কাজ করা যায়" icon bullet list
  - "কাদের ডেটা পরিবর্তন করে" mini table
  - "সাবধানতা" callout (only if `cautions` non-empty)
  - Mermaid diagram block (only if `diagram` set)
  - related-menu chips
- Implement Mermaid lazy-load: `help.js` detects `[data-mermaid]` blocks, injects the Mermaid
  script tag once, calls `mermaid.run()` on the visible block.

#### Session 4.2 — The floating help button + open interaction
- Build `components/help/help-button.blade.php` (fixed corner, gradient, accessible label,
  `data-menu-key`).
- `help.js`: on click → `fetch('/help/menu/' + key)` → inject HTML into offcanvas body →
  `bootstrap.Offcanvas.getInstance(el).show()`.
- Add the §6 motion (float, hover ring, slide-in).
- Write **3 real content files** as a vertical slice proof:
  `menus/master-data/customers.php`, `menus/sales/invoice.php`, `menus/sales/cart.php`.
- Test: open each of the 3 pages → help button → see the Bangla explanation with diagram.

**AC (end of Phase 4):**
- [ ] Help button visible on every authenticated page.
- [ ] Clicking it opens the right offcanvas with the correct Bangla content for the 3 demo
      pages, including a Mermaid diagram on `sales.invoice`.
- [ ] Mobile: offcanvas is full-screen, tap targets ≥44px, no horizontal scroll.
- [ ] Keyboard: `Tab` reaches the help button, `Enter` opens, `Esc` closes.

---

### 🧭 Phase 5 — Door 2: Footer Pill + Bottom Sheet + Module Offcanvas  *(2 sessions)*

#### Session 5.1 — Footer pill + bottom-up module sheet
- Build `components/help/guide-footer.blade.php`: fixed footer, glassmorphism, centered pill.
  Ensure it **does not overlap** the existing footer — see §11.1 (sticky-footer rule).
- Build `components/help/module-sheet.blade.php`: Bootstrap offcanvas-bottom, grid of module
  cards. Each card coloured per §6.1, shows icon + Bangla name + tagline.
- `help.js`: footer click → open module sheet. Module card click → fetch
  `/help/module/{key}` → open module offcanvas.

#### Session 5.2 — Module offcanvas + menu navigation + content swap
- Build `components/help/module-offcanvas.blade.php` and `module-content.blade.php`:
  header (gradient), intro, mini cycle diagram, then a list of menu chips (coloured).
- Implement the **content-swap UX** (§4.3): clicking a menu chip in the module offcanvas
  fetches `/help/menu/{key}` and **replaces** the right offcanvas content with a slide/fade,
  while keeping the module offcanvas open underneath OR closing it — **decision: close the
  module offcanvas** and let the menu offcanvas take over (less clutter on mobile).
  Add a small "← মডিউলে ফিরে যান" back button + breadcrumb.
- Populate `modules/*.php` for all 8 modules (just metadata + menu list + 1-paragraph intro;
  deep content comes in Phase 7).

**AC (end of Phase 5):**
- [ ] Footer pill visible on every page, sticky, does not overlap the existing footer.
- [ ] Click → bottom sheet with 8 colourful module cards.
- [ ] Click a module → module offcanvas with intro + menu chips.
- [ ] Click a menu chip → menu offcanvas opens with that menu's content.
- [ ] Breadcrumb + back button works on both desktop and mobile.
- [ ] The two doors are fully wired end-to-end on the 3 demo menus from Phase 4.

---

### ✍️ Phase 7 — Content Authoring (Bangla)  *(split into 8 sub-sessions)*

> This is the bulk of the work. Each sub-session writes the content files for one module
> and updates `registry.php` if any new route→key mappings are needed. **Quality bar**:
> every file reviewed against §3 principles (scannable, role-aware, has a diagram only if
> it helps, plain Bangla). No file merged with TODO placeholders.

**Shared authoring checklist (paste at the top of each sub-session's PR):**
- [ ] Every menu under this module has a content file.
- [ ] `summary` ≤ 1 sentence, ≤ 25 words.
- [ ] `what_you_can_do` has 3–6 items, each with an icon.
- [ ] `impacts` lists every party whose data moves (customer/supplier/stock/ledger/cash).
- [ ] `cautions` only present where there's a real footgun.
- [ ] `related` cross-links to real existing keys.
- [ ] At least 1 menu in the module has a diagram; the rest skip it if trivial.
- [ ] Bangla is plain and operator-friendly (no English-only jargon).

| Sub-session | Module | Files to author | Diagram count target |
|---|---|---|---|
| **7a** | Master Data | products, customers, suppliers, employees, banks, ledgers, branches, warehouses, users (9) | 1 (chart-of-accounts tree) |
| **7b** | Inventory | stock-ledger, stock-take, damage, warehouse-transfer, uom, stock-adjustment (6) | 2 (stock-take cycle, warehouse-transfer flow) |
| **7c** | Purchasing | po, receive, return, audit (4) | 1 (procure-to-pay) |
| **7d** | Sales | cart, invoice, challan, return, customer-payment, commission (6) | 2 (order-to-cash, commission calc) |
| **7e** | Accounting | manual-journal, money-transfer, supplier-txn, customer-txn, employee-txn, other-income, other-expense, period-close, fiscal-year, bank-recon (10) | 2 (journal posting, period-close) |
| **7f** | Finance | fixed-assets, budgets, dimensions, consolidation, branch-demand (5) | 1 (consolidation/intercompany) |
| **7g** | Reports | reports-hub, dashboards, csv-export (3) | 0 (mostly self-explanatory) |
| **7h** | System | notifications, system-policy, archive, audit, shadow-mode, partition-health, users, employees (8) | 1 (notification fan-out) |

> **7e (Accounting) is the longest** — split into 7e.1 (transactions: 7 files) and
> 7e.2 (period close + fiscal year + bank recon: 3 files) across two work blocks if needed.

**AC (end of Phase 7):**
- [ ] Every authenticated route's menu key resolves to a real content file (or a documented
      intentional "no help needed" skip in the inventory CSV).
- [ ] Random sampling: open 20 menus across all modules → each shows correct, non-empty,
      Bangla content with role chips + impacts + at least one having a diagram.

---

### 🎨 Phase 8 — Visual Polish & Responsive Pass  *(2 sessions)*

#### Session 8.1 — Premium theme application
- Apply the §6.2 component visuals: gradients, shadows, glassmorphism, rounded-2xl.
- Wire the **module colour tinting** (the offcanvas header + chips tint to the module's
  colour token).
- Add the §6.3 motion (spring-ease, float, content-swap fade).
- Add `prefers-reduced-motion` guard.

#### Session 8.2 — Mobile + cross-browser + accessibility
- Test on mobile widths (360 / 414 / 768). Offcanvases go full-screen; sheets become
  true bottom drawers; tap targets ≥44px.
- Test on Chrome, Firefox, Edge (Safari if available).
- Accessibility pass: focus trap inside open offcanvas, `aria-labelledby`, `Esc` closes,
  focus returns to the trigger button.
- Add a `?` keyboard shortcut to toggle the current page's help (Phase 9 nice-to-have, but
  we add the handler now).

**AC (end of Phase 8):**
- [ ] Lighthouse mobile accessibility ≥ 95 on a sampled page with the helper open.
- [ ] No layout shifts when the helper opens (CLS ≈ 0).
- [ ] Module colour tinting is visible and consistent across all 8 modules.
- [ ] `prefers-reduced-motion` disables all animations.

---

### ✨ Phase 9 — Interactive Niceties  *(1 session)*

- **In-guide search:** a search box at the top of the module sheet → filters modules + menus
  by Bangla/English text live (client-side, no new endpoint).
- **Recently viewed:** the footer pill's long-press (or a small `★` button beside it) shows
  the last 5 menus the user opened — stored in `localStorage`.
- **Keyboard shortcuts:** `?` opens current page help; `Shift+G` opens the module sheet.
- **Empty-state polish:** the "not yet written" card gets a friendly illustration + a mailto
  link to request it.
- **Print:** a "প্রিন্ট করুন" button in the menu offcanvas → opens a clean print view.

**AC:** all four niceties work; no console errors; localStorage degrades gracefully.

---

### 🧪 Phase 10 — QA, Documentation & Handoff  *(1 session)*

- **QA sweep:** visit every page in the inventory CSV's top-level menu set (≈40 pages),
  confirm the help button shows the right content (or the graceful empty state).
- **Performance:** initial payload of `help-system.css` + `help.js` ≤ 12KB gzipped; Mermaid
  loaded only when needed.
- **Docs:**
  - Append **Appendix A** (module → menu map) to this file.
  - Add `docs/HELP_AUTHORING_GUIDE.md` — a 1-page guide for adding/editing help content.
  - Add a section in `AI_CONTEXT/` (`architecture/help-system.md`) for future AI assistants.
- **Handoff demo:** record a 2-minute walkthrough (or a sequence of screenshots) showing both
  doors working across Sales + Accounting + Inventory.

**AC (final):**
- [ ] All Phase 1–9 ACs pass.
- [ ] `docs/HELP_AUTHORING_GUIDE.md` exists and is accurate.
- [ ] `AI_CONTEXT/architecture/help-system.md` exists.
- [ ] Demo artefact delivered.

---

## 9. Acceptance — the definition of "done"

The whole system is done when **all** of these are true:

1. On **any** authenticated page, a colourful help button is visible → opens a right
   offcanvas with the **current menu's** Bangla explanation (or a graceful empty state).
2. On **any** authenticated page, a fixed footer pill `🧭 My Creative Code Guide` is visible →
   opens a bottom-up sheet of 8 colourful module cards.
3. From the module sheet → module offcanvas → menu offcanvas works for **every** documented
   menu, with breadcrumb + back navigation.
4. Every content file follows the §5 schema and the §3 principles.
5. Visually: colourful, premium, modern, responsive, mobile-friendly, fun. Passes Lighthouse
   mobile a11y ≥ 95 and CLS ≈ 0.
6. No new backend service, no DB migration, no new composer dependency (Mermaid is a lazily
   loaded CDN script, enhancement-only).
7. The existing ERP pages are **unchanged** except for the single `@include` line + 2 asset
   tags. Reverting is `git revert` of one commit.

---

## 10. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Content authoring is the long pole (51 files of Bangla) | High | High | Phase 7 is split into 8 sub-sessions; ship module-by-module so partial value lands early. |
| Route↔menu mapping drifts as the ERP evolves | Medium | Medium | `registry.php` uses route *names* (stable). Add a CI check: `php artisan help:audit` lists routes with no key. |
| Mermaid CDN blocked on BDIX VPS | Medium | Low | Diagrams are enhancement-only; a missing diagram degrades to a hidden block, not a crash. Future: bundle Mermaid locally. |
| Footer pill overlaps existing sticky footer | Medium | Medium | Phase 5 verifies against the sticky-footer rule (§11.1); use `position: fixed` only for the pill, push existing footer up if needed. |
| Offcanvas a11y (focus trap) regressions | Low | Medium | Phase 8.2 enforces focus trap + `Esc` + focus-return; re-check after every Phase 9 change. |
| Help button covers important UI on small screens | Medium | Medium | Mobile: button shrinks to 40px and moves to `bottom: 70px` (above footer pill); test on 360px width. |
| Two offcanvases (module + menu) feel cluttered | Medium | Medium | Decision in §4.3: module offcanvas closes when a menu is chosen; one open at a time. |
| Owner dislikes indigo/violet module colours | Low | Low | §6.1 flagged for owner confirmation in Phase 8; easy to swap palette token. |

---

## 11. Cross-cutting constraints (must respect)

### 11.1 Sticky-footer rule
The ERP's existing footer (when present) must **stay sticky to the bottom** and never be
covered by the helper. Implementation:
- The footer pill is a `position: fixed; bottom: 0` bar that **sits above** the existing
  footer's z-index, but the existing footer gets `margin-bottom: 44px` (pill height) so it
  isn't visually overlapped. On short pages the footer still sticks; on long pages it's
  pushed naturally.
- Verified in Phase 5 + Phase 8.2.

### 11.2 No new dependencies, no DB migration
- Zero `composer require`. Zero `npm install`. Mermaid via CDN `<script>` only.
- No `php artisan migrate`. The `menus` table is read-only for us.

### 11.3 Bangla correctness
- All Bangla reviewed by a native speaker (Sajid or a team member) before each module's
  sub-session is merged. A typo in "বকেয়া" (due/outstanding) is not acceptable.

### 11.4 Performance budget
- `help-system.css` ≤ 8KB gzipped, `help.js` ≤ 4KB gzipped.
- No help content is loaded on initial page render (lazy via fetch) — the inventory is
  pre-rendered into the module sheet (8 cards, ~3KB).

---

## 12. Session Sequencing Summary

| # | Session | Phase | Output |
|---|---|---|---|
| 1 | Discovery | P1 | `docs/help-inventory.csv` |
| 2 | Schema + scaffold | P2 | dirs, service, controller, registry, layout include (placeholders) |
| 3 | HelpService + registry population | P3.1 | route→key resolver working |
| 4 | Layout integration + context injection | P3.2 | help button carries correct `data-menu-key` on sampled pages |
| 5 | Right offcanvas + content renderer | P4.1 | offcanvas shell + Mermaid lazy-load |
| 6 | Help button + 3 demo content files | P4.2 | Door 1 live on 3 menus |
| 7 | Footer pill + module sheet | P5.1 | bottom-up sheet with 8 module cards |
| 8 | Module offcanvas + content swap | P5.2 | Door 2 live end-to-end |
| 9 | Content: Master Data | P7a | 9 menu files |
| 10 | Content: Inventory | P7b | 6 menu files |
| 11 | Content: Purchasing | P7c | 4 menu files |
| 12 | Content: Sales | P7d | 6 menu files |
| 13 | Content: Accounting (transactions) | P7e.1 | 7 menu files |
| 14 | Content: Accounting (close/recon) | P7e.2 | 3 menu files |
| 15 | Content: Finance | P7f | 5 menu files |
| 16 | Content: Reports | P7g | 3 menu files |
| 17 | Content: System | P7h | 8 menu files |
| 18 | Premium theme application | P8.1 | gradients, shadows, motion |
| 19 | Mobile + a11y pass | P8.2 | Lighthouse a11y ≥ 95 |
| 20 | Niceties (search, recents, shortcuts, print) | P9 | all four |
| 21 | QA + docs + handoff | P10 | authoring guide + AI_CONTEXT entry + demo |

**~21 sessions** end-to-end. Sessions 1–8 build the skeleton + both doors; 9–17 are pure
content (parallelisable in theory, but sequenced to ship module-by-module); 18–21 polish
and ship.

---

## Appendix A — Module → Menu Map  *(populated by Phase 1)*

> **Source of truth:** `docs/help-inventory.csv` (215 data rows, generated by the P1-INVENTORY agent).
> The CSV is authoritative; this appendix is a human-readable summary.
>
> **How to read:** Each module has **primary menus** (sidebar entries that get their own help
> card in the bottom-up sheet + module offcanvas) and **sub-pages** (audit trails, print views,
> detail/show pages, slips, create forms) that are *children* of a primary menu. Sub-pages will
> get a **short** help card in Phase 7 — usually 2–3 bullets that say "এটি মূল পেজের অডিট ট্রেইল"
> — rather than a full content file. The primary menus get the full §5.1 treatment.

### Summary counts

| Module | Primary menus | Sub-pages (audit/print/show/slip/…) | Total rows in CSV |
|---|---:|---:|---:|
| Master Data | 12 | 18 | 30 |
| Inventory | 19 | 9 | 28 |
| Purchasing | 3 | 5 | 8 |
| Sales | 11 | 14 | 25 |
| Accounting | 13 | 20 | 33 |
| Finance | 24 | 14 | 38 |
| Reports | 34 | 2 | 36 |
| System | 13 | 4 | 17 |
| **Total** | **129** | **86** | **215** |

> The original plan estimated ~51 menus; the real count is **129 primary menus** (because the
> reports hub alone has ~30 distinct report pages, and finance has consolidation sub-reports +
> fixed-asset depreciation/disposals + branch-demand variants). Phase 7's per-module session
> budgets (§8) may need to grow proportionally — see the note at the bottom of this appendix.

### A.1 Master Data (30 rows · colour: slate)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `master-data.products` | পণ্য | `admin.products.index` |
| `master-data.product-categories` | প্রোডাক্ট ক্যাটাগরি | `admin.product-categories.index` |
| `master-data.product-groups` | প্রোডাক্ট গ্রুপ | `admin.product-groups.index` |
| `master-data.customers` | খদ্দের | `admin.customers.index` |
| `master-data.suppliers` | সাপ্লায়ার | `admin.suppliers.index` |
| `master-data.employees` | কর্মচারী | `admin.employees.index` |
| `master-data.employees-account` | Employee Account | `admin.employees.account` |
| `master-data.banks` | ব্যাংক | `admin.banks.index` |
| `master-data.ledgers` | লেজার (Chart of Accounts) | `admin.ledgers.index` |
| `master-data.branches` | ব্র্যাঞ্চ | `admin.branches.index` |
| `master-data.warehouses` | গুদাম | `admin.warehouses.index` |
| `master-data.products-price-history` | Product Price History | `admin.products.price-history` |
| *(+18 sub-pages: audit/print/show/create for the above)* | | |

### A.2 Inventory (28 rows · colour: amber)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `inventory.stock-transactions` | স্টক লেজার | `admin.stock-transactions.index` |
| `inventory.stock-transactions-warehouse-stock` | Warehouse Stock | `admin.stock-transactions.warehouse-stock` |
| `inventory.stock-transactions-drift` | Stock Drift | `admin.stock-transactions.drift` |
| `inventory.stock-take` | ফিজিক্যাল কাউন্ট | `admin.stock-take.index` |
| `inventory.stock-take-setup` / `-count` / `-checklist` / `-abc-report` / `-health-summary` | Stock Take sub-pages | various |
| `inventory.stock-adjustments` | স্টক অ্যাডজাস্টমেন্ট | `admin.stock-adjustments.index` |
| `inventory.stock-adjustments-checklist` / `-reconcile` | Stock Adj. sub-pages | various |
| `inventory.warehouse-transfers` | ওয়্যারহাউস ট্রান্সফার | `admin.warehouse-transfers.index` |
| `inventory.warehouse-transfers-summary` / `-checklist` / `-reconcile` | WH Transfer sub-pages | various |
| `inventory.damages` | ক্ষতি (Damage) | `admin.damages.index` |
| `inventory.damages-view-attachment` / `-download-attachment` | Damage sub-pages | various |

### A.3 Purchasing (8 rows · colour: sky)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `purchasing.purchase-orders` | পি. অর্ডার | `admin.purchase-orders.index` |
| `purchasing.purchase-receives` | পি. রিসিভ (GRN) | `admin.purchase-receives.index` |
| `purchasing.purchase-returns` | পি. রিটার্ন | `admin.purchase-returns.index` |
| *(+5 sub-pages: audit/show/create for the above + purchase-audit checklist)* | | |

### A.4 Sales (25 rows · colour: emerald)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `sales.cart` | সেলস কার্ট | `admin.sales.cart` |
| `sales.invoices` | সেলস ইনভয়েস | `admin.sales-invoices.index` |
| `sales.invoices-audit` | Sales Audit Trail | `admin.sales.audit` |
| `sales.challans` | চালান | `admin.sales-challans.index` |
| `sales.challans-godown` / `-challan-form` / `-blank-godown-form` / `-print-challan` | Challan sub-pages | various |
| `sales.returns` | সেলস রিটার্ন | `admin.sales-returns.index` |
| `sales.customer-payments` | কাস্টমার পেমেন্ট | `admin.customer-payments.index` |
| `sales.commission-rules` | কমিশন রুল | `admin.commission-rules.index` |
| `sales.guide` | সেলস গাইড | `admin.sales.guide` |
| `sales.go-live-checklist` | গো-লাইভ চেকলিস্ট | `admin.sales.go-live-checklist` |
| *(+14 sub-pages: audit/show/slip/print/reverse-preview/receive-modal for the above)* | | |

### A.5 Accounting (33 rows · colour: violet)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `accounting.manual-journals` | ম্যানুয়াল জার্নাল | `admin.manual-journals.index` |
| `accounting.money-transfers` | মানি ট্রান্সফার | `admin.money-transfers.index` |
| `accounting.supplier-transactions` | সাপ্লায়ার পেমেন্ট | `admin.supplier-transactions.index` |
| `accounting.employee-transactions` | কর্মচারী লেনদেন | `admin.employee-transactions.index` |
| `accounting.other-incomes` | অন্যান্য আয় | `admin.other-incomes.index` |
| `accounting.other-expenses` | অন্যান্য খরচ | `admin.other-expenses.index` |
| `accounting.bank-reconciliation` | ব্যাংক রিকনসিলিয়েশন | `admin.bank-reconciliation.index` |
| `accounting.bank-reconciliation-unreconciled` | Unreconciled Entries | `admin.bank-reconciliation.unreconciled` |
| `accounting.reconciliation` | রিকনসিলিয়েশন (Sub-ledger) | `admin.reconciliation.index` |
| `accounting.reconciliation-section` | Reconciliation Section | `admin.reconciliation.section` |
| `accounting.period-close` | পিরিয়ড ক্লোজ | `admin.accounting.period-close` |
| `accounting.approvals` | অ্যাপ্রুভাল কিউ | `admin.approvals.queue` |
| `accounting.approvals-workflows` | Approval Workflows | `admin.approvals.workflows` |
| *(+20 sub-pages: audit/show/slip/create/import-statement for the above)* | | |

> **Note:** `customer-transactions` (CustomerTransactionController) is referenced in the menu
> seeder but the routes were not found as a separate page — customer payments live under
> `sales.customer-payments`. The `CustomerTransactionController` may be the legacy bridge.
> Phase 2 will confirm whether it needs a help card or maps to `sales.customer-payments`.

### A.6 Finance (38 rows · colour: rose)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `finance.fixed-assets` | ফিক্সড অ্যাসেট | `admin.fixed-assets.index` |
| `finance.fixed-assets-depreciation` | Depreciation Schedule | `admin.fixed-assets.depreciation` |
| `finance.fixed-assets-disposals` | Asset Disposals | `admin.fixed-assets.disposals` |
| `finance.budgets` | বাজেট | `admin.budgets.index` |
| `finance.budgets-variance` | Budget Variance Report | `admin.budgets.variance` |
| `finance.dimensions` | ডাইমেনশন | `admin.dimensions.index` |
| `finance.dimensions-segment-bs` / `-segment-pnl` | Segment reports | various |
| `finance.consolidation` | কনসলিডেশন | `admin.consolidation.index` |
| `finance.consolidation-companies` / `-rules` / `-intercompany-reconciliation` | Consolidation sub-pages | various |
| `finance.consolidation-consolidated-tb` / `-consolidated-bs` / `-consolidated-pnl` | Consolidated statements | various |
| `finance.branch-demand` | ব্র্যাঞ্চ ডিমান্ড | `admin.branch-demands.index` |
| `finance.branch-demand-pending` / `-pending-receipt` / `-checklist` / `-reconcile` / `-weekly-report` | Branch Demand sub-pages | various |
| `finance.branch-demand-shadow` | ব্র্যাঞ্চ ডিমান্ড শ্যাডো | `admin.branch-demand-shadow.index` |
| `finance.fiscal-years` | ফিসকাল ইয়ার | `admin.fiscal-years.index` |
| `finance.fiscal-years-close-log` | Fiscal Year Close Log | `admin.fiscal-years.close-log` |
| `finance.shadow-mode` | শ্যাডো মোড | `admin.shadow-mode.index` |
| `inventory.branch-demand` *(duplicate placement — legacy Inventory menu)* | ব্র্যাঞ্চ ডিমান্ড | `admin.branch-demands.index` |

> The `inventory.branch-demand` row is the **legacy sidebar placement** (under Inventory) of
> the same Branch Demand page. Both rows point to `admin.branch-demands.index`. Phase 7 will
> write ONE help card (`finance.branch-demand`) and the legacy menu link will reuse it.

### A.7 Reports (36 rows · colour: teal)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `reports.dashboard` | ড্যাশবোর্ড | `dashboard` |
| `reports.reports-hub` | রিপোর্ট হাব | `admin.reports.index` |
| `reports.sales-funnel` | সেলস ফানেল | `admin.sales-funnel.index` |
| `reports.customer-performance` | কাস্টমার পারফরম্যান্স | `admin.customer-performance.index` |
| `reports.csv-export` | CSV এক্সপোর্ট | `admin.csv-export.export-invoices` |
| `reports.csv-export-export-challans` | Export Challans CSV | `admin.csv-export.export-challans` |
| **Reports hub report-types (each a page):** | | |
| `reports.reports-hub-trialBalance` | Trial Balance | `admin.reports.trialBalance` |
| `reports.reports-hub-balanceSheet` | Balance Sheet | `admin.reports.balanceSheet` |
| `reports.reports-hub-profitAndLoss` | Profit & Loss | `admin.reports.profitAndLoss` |
| `reports.reports-hub-cashFlow` | Cash Flow | `admin.reports.cashFlow` |
| `reports.reports-hub-generalLedger` / `-generalLedgerCte` | General Ledger | various |
| `reports.reports-hub-journalEntries` | Journal Entries | `admin.reports.journalEntries` |
| `reports.reports-hub-dailyCashBook` | Daily Cash Book | `admin.reports.dailyCashBook` |
| `reports.reports-hub-receivableAging` / `-payableAging` / `-arAgingCte` | Aging reports | various |
| `reports.reports-hub-grossMargin` / `-grossMarginCte` | Gross Margin | various |
| `reports.reports-hub-revenueOverview` | Revenue Overview | `admin.reports.revenueOverview` |
| `reports.reports-hub-productMovement` / `-productStockAnalysis` | Product reports | various |
| `reports.reports-hub-supplierWisePurchase` | Supplier-wise Purchase | `admin.reports.supplierWisePurchase` |
| `reports.reports-hub-branchWiseLedger` / `-branchIntercompany` | Branch reports | various |
| `reports.reports-hub-damageReport` / `-damageReportExport` | Damage reports | various |
| `reports.reports-hub-stocktakeVariance` / `-export` / `-weekly` / `-weeklyExport` | Stocktake reports | various |
| `reports.reports-hub-branchDemandWeekly` | Branch Demand Weekly (legacy) | `admin.reports.branchDemandWeekly` |
| `reports.reports-hub-todaySummaryCte` | Today Summary (CTE) | `admin.reports.todaySummaryCte` |

> The reports hub has **~30 distinct report pages** — far more than the skeleton's "3".
> Phase 7g will treat the hub as ONE primary menu with a master help card listing all
> report types, plus lightweight per-report cards (1-line summary each). This keeps the
> bottom-up sheet clean while still documenting every report.

### A.8 System (17 rows · colour: indigo)

| menu_key | বাংলা লেবেল | route_name |
|---|---|---|
| `system.users` | ইউজার | `admin.users.index` |
| `system.users-menu-permissions` | User Menu Permissions | `admin.users.menu-permissions` |
| `system.notifications` | নোটিফিকেশন রুল | `admin.notifications.rules` |
| `system.notifications-inbox` | Notification Inbox | `admin.notifications.inbox` |
| `system.sse` | SSE ইভেন্ট | `sse.events` |
| `system.sse-status` | SSE Status | `sse.status` |
| `system.compliance` | সিস্টেম পলিসি | `admin.compliance.index` |
| `system.audit` | গ্লোবাল অডিট | `admin.audit.index` |
| `system.system-health` | সিস্টেম হেলথ | `admin.system-health.index` |
| `system.partition-health` | পার্টিশন হেলথ | `admin.system.partition-health.index` |
| `system.archive` | আর্কাইভ | `admin.archive.index` |
| `system.archive-customerLedger` / `-supplierLedger` | Archive sub-pages | various |
| *(+4 sub-pages: users-audit/users-print/users-security-audit/compliance)* | | |

### A.9 Layouts confirmed (for Phase 2 wiring)

| Layout | Extended by | Help needed? |
|---|---:|---|
| `layouts.admin` | **236 files** | ✅ YES — the `@include('partials.help-system')` goes here |
| `layouts.print` | 17 files | ❌ No (print views) |
| `admin.partials.print-layout` | 9 files | ❌ No (master-data directory prints) |
| `layouts.app` | 7 files | ⚠️ Partial — 3 are auth pages (login/forgot/reset, no help), **4 are branch-demand-shadow pages** that DO need help. **Phase 3 decision:** either extend the include to `layouts.app` too, or migrate those 4 pages to `layouts.admin`. |
| `layouts.plain` / `layouts.print-legacy` | 0 files | ❌ Unused |

### A.10 Impact on Phase 7 session budget

The original plan budgeted 1 session per module for content authoring (~51 files). The real
count is **129 primary menus + 86 sub-pages = 215 files**. To keep quality high without
exhausting any single session:

- **Sub-pages** (audit/print/show/slip) will get a **templated short card** (auto-generated
  from the parent's help: "এটি [parent] এর [audit trail / print view / detail page]") rather
  than a hand-written file. This collapses 86 sub-pages into ~15 templates. ✓ manageable.
- **Primary menus** (129) still need hand-written Bangla content. The per-module session count
  should grow roughly proportional to the menu count:

  | Module | Plan budget (sessions) | Recommended | Primary menus |
  |---|---:|---:|---:|
  | Master Data | 1 | 1 | 12 |
  | Inventory | 1 | 1.5 | 19 |
  | Purchasing | 1 | 0.5 | 3 |
  | Sales | 1 | 1 | 11 |
  | Accounting | 2 | 2 | 13 |
  | Finance | 1 | 2 | 24 |
  | Reports | 1 | 1.5 | 34 (but most are 1-line report cards) |
  | System | 1 | 1 | 13 |

  → ~10 content sessions total (vs the planned 8). The §8 Gantt + §12 session table will be
  updated at the start of Phase 7 to reflect this.

> **Bottom line:** Phase 1's discovery revealed a larger surface than estimated, but the
> architecture (one reusable offcanvas, file-based content, templated sub-page cards) absorbs
> it without redesign. We proceed to Phase 2.

---

## Appendix B — Sample Authoring Checklist (for every content file)

```
[ ] key, module, title_bn, title_en, icon, summary present
[ ] for_roles: ≥1 role, all from {superadmin,admin,manager,accountant,salesman,
                                  warehouse_manager,dispatcher,hr,user,other}
[ ] what_you_can_do: 3–6 items, each {icon, text}
[ ] impacts: lists every party whose data moves
[ ] cautions: only if there's a real footgun; else omit key
[ ] related: 1–4 real keys that exist in the inventory
[ ] diagram: a key from diagrams.php, OR omitted
[ ] updated_at: today's date
[ ] Bangla proofread by a native speaker
```

---

## Appendix C — Sample Bangla Content (the quality bar)

**Menu: `sales.invoice` (Sales Invoice)** — what a great help card looks like:

> **সেলস ইনভয়েস** · *Sales Invoice* · 🧾
>
> খদ্দেরকে পণ্য বিক্রি করে যে বিল তৈরি হয়, এটি সেই বিল। এখান থেকেই খদ্দেরের বকেয়া ও
> আপনার আয় শুরু হয়।
>
> **কাদের জন্য:**  `সেলসম্যান`  `ম্যানেজার`  `অ্যাডমিন`
>
> **কী কাজ করা যায়:**
> - ➕ নতুন ইনভয়েস তৈরি করা
> - 📋 আগের সব ইনভয়েস দেখা ও খুঁজা
> - 🖨️ ইনভয়েস প্রিন্ট বা কাস্টমারকে পাঠানো
> - ↩️ ভুল হলে রিটার্ন করা
> - ✅ কাস্টমারের পেমেন্ট এন্ট্রি করা
>
> **কাদের ডেটা পরিবর্তন করে:**
> | কে | কী |
> |---|---|
> | খদ্দের | বকেয়া বাড়ে |
> | স্টক | পণ্য কমে যায় |
> | হিসাব | বিক্রয় আয় ও ভ্যাট লেজারে লেখা হয় |
>
> ⚠️ **সাবধানতা:**
> - ইনভয়েস একবার ফাইনাল হলে সরাসরি এডিট করা যায় না — রিটার্ন দিতে হবে।
> - পর্যাপ্ত স্টক না থাকলে ইনভয়েস তৈরি হবে না।
>
> *(mini flow diagram here: কার্ট → ইনভয়েস → চালান → ডেলিভারি → পেমেন্ট)*
>
> **সম্পর্কিত:**  `🛒 কার্ট` · `🚚 গোডাউন চালান` · `↩️ সেলস রিটার্ন` · `💵 কাস্টমার পেমেন্ট`

This is the **style** every content file must hit: short, visual, role-aware, honest about
risk, and quietly delightful.

---

*End of plan. Phase 1 starts on approval. Every phase ends with committed, runnable code
and a short demo before the next begins.*
