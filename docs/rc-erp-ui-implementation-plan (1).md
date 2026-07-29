# RC ERP — UI/UX Revamp Implementation Plan (Laravel)

> **Goal:** Port the design shown in `rc-erp-ui-showcase.html` into the **existing Laravel** RC ERP project, replacing the current unsatisfactory UI for the **Invoice → Godown → Challan** workflow. This is a UI/UX improvement on top of the existing backend — **not a rewrite of business logic.**

---

## 0. Context & Scope

### 0.1 What this plan is
A **phase-by-phase implementation guide** for a Laravel engineer. Each phase is independently deliverable, testable in the browser, and leaves the app in a working state. No phase breaks the existing flow.

### 0.2 What already exists (do NOT rebuild)
The existing Laravel project already has the working backend (mirrored by the Next.js reference repo `debugRC`). The data model & workflow are:

- **Models:** `Branch`, `Employee`, `Warehouse`, `Product`, `ProductGroup`, `Customer`, `SalesInvoice`, `SalesInvoiceItem`, `SalesInvoiceDispatch`, `SalesInvoiceDispatcher`, `SalesChallan`, `SalesChallanItem`, `WarehouseStock`, `StockTransaction`, `Notification`, `DocumentSequence`, `Ledger`, `JournalEntry`, `JournalLine`, `CustomerLedger`.
- **Invoice status state machine:** `draft → finalized → blank_godown_created → godown_prepared → challan_issued` (+ `cancelled`).
- **Endpoints / controller actions** already exist for: list/create/edit/delete invoice, finalize, blank-godown, prepare-godown, issue-challan, list/view challan, plus reads for customers/products/warehouses/stock/dispatchers/branches/notifications.

> ✅ **This plan touches only the View layer (Blade/Tailwind/Alpine) and thin controller changes for new print routes.** No schema migration, no business-rule rewrite.

### 0.3 The 4-step physical workflow the UI must express
```
Step 1  SM finalizes invoice            →  status = finalized
Step 2  WM creates BLANK godown copy     →  status = blank_godown_created  (print, hand to dispatcher)
Step 3  WM enters warehouse + CTN data   →  status = godown_prepared
Step 4  WM issues challan                →  status = challan_issued         (stock deducted, COGS journal posted)
```

### 0.4 Tech stack assumptions (confirm with the team before starting)
| Layer | Choice | Notes |
|---|---|---|
| Templating | **Blade** | Prefer Blade + Blade components over Livewire for static pages; use **Livewire 3** only where reactivity is needed (warehouse assignment table, dispatcher picker, stock badge live updates) |
| CSS | **Tailwind CSS 4** via Vite | Match the showcase exactly |
| JS | **Alpine.js** | Dropdowns, modals, print dialogs, tab switching |
| Icons | **Blade UI Icons** (`blade-ui-icons`) with the Lucide set, OR a single `<x-icon name="...">` Blade component wrapping inline SVGs | Showcase uses inline Lucide-style SVGs |
| Fonts | **Inter** + **Noto Sans Bengali** from Google Fonts | Bengali webfont is currently missing — must add |
| Build | **Vite** | Standard Laravel-Vite pipeline |

> ⚠️ If the project currently uses Laravel Mix or an older Tailwind (v3), **Phase 0 includes the migration to Vite + Tailwind 4.** If Tailwind 4 isn't feasible, Tailwind 3.4 is acceptable — the design uses only standard utility classes.

---

## Design Tokens (reference — used throughout all phases)

### Status → color map (canonical)
| Status (UI label) | DB status value | Tailwind | Hex (badge bg/text/border) |
|---|---|---|---|
| Needs Godown | `finalized` | amber | `#fef3c7` / `#b45309` / `#fcd34d` |
| Blank Godown | `blank_godown_created` | orange | `#ffedd5` / `#c2410c` / `#fdba74` |
| Ready for Challan | `godown_prepared` | cyan | `#cffafe` / `#0e7490` / `#67e8f9` |
| Completed | `challan_issued` | green | `#dcfce7` / `#15803d` / `#86efac` |
| Draft | `draft` | gray | `#f3f4f6` / `#374151` / `#d1d5db` |
| Cancelled | `cancelled` | red (muted) | `#fee2e2` / `#b91c1c` / `#fca5a5` |

### Branch → color map
| Code | Name | Color | Hex | Status |
|---|---|---|---|---|
| HO | Head Office ("Red Branch") | Red | `#dc2626` | ✅ confirmed in showcase |
| PAT | Paton | _define_ | _define_ | ⚠️ confirm with stakeholder (suggest Blue `#2563eb`) |
| NOW | Nawabganj | _define_ | _define_ | ⚠️ confirm (suggest Green `#16a34a`) |
| TAR | Tangail | _define_ | _define_ | ⚠️ confirm (suggest Orange `#ea580c` or Purple `#9333ea`) |

> **Branch theming uses inline `style` with alpha tints** (not Tailwind classes) so colors are runtime-configurable from the DB. Convention: 8% / 13% / 22% alpha layers (e.g. `#dc262622`, `#dc262615`, `#dc262611`).

### Brand palette
- Primary gradient: `from-amber-500 via-amber-600 to-orange-500` (`#f59e0b → #d97706 → #ea580c`)
- Logo chip: `bg-gradient-to-r from-amber-500 to-orange-500`
- Page bg: `bg-gradient-to-b from-amber-50/30 to-white`
- Nav bg: `bg-white/90 backdrop-blur-md border-b border-amber-200`
- Footer: `bg-amber-900 text-amber-100`

### Typography & spacing conventions
- Font: Inter (Latin) + Noto Sans Bengali (বাংলা), weights 300–800
- Card radius: `rounded-xl` (content cards), `rounded-lg` (inputs/buttons), `rounded-full` (pills/badges)
- Shadows: `shadow-sm` (cards), `shadow-md` (buttons), `shadow-lg` (hero, sticky bars)
- Section rhythm: `space-y-6`; card padding `p-4`
- **Signature card style:** `bg-white rounded-xl shadow-sm border-l-4 border-l-{color}-500 p-4`

---

## Phase Overview

| # | Phase | Est. | Depends on | Risk |
|---|---|---|---|---|
| 0 | Audit & Build Tooling Setup | 1 day | — | Low |
| 1 | Design System & Blade Component Library | 3 days | 0 | Low |
| 2 | App Shell & Global Layout | 1.5 days | 1 | Low |
| 3 | Dashboard / Invoice List Page | 2 days | 1, 2 | Low |
| 4 | Invoice Detail View | 1 day | 1, 2 | Low |
| 5 | Blank Godown Creation (Step 2) | 2 days | 1, 2 | Medium (print) |
| 6 | Godown Preparation / Warehouse Assignment (Step 3) | 2.5 days | 1, 2 | Medium (Livewire) |
| 7 | Challan Issue (Step 4) | 2 days | 1, 2 | Medium (confirm) |
| 8 | Print Layouts (3 copies) | 3 days | 5, 6, 7 | Medium |
| 9 | Branch Theming System | 1.5 days | 1, 8 | Low |
| 10 | Notifications Panel | 1.5 days | 2 | Low |
| 11 | Polish & Gap-Filling | 2.5 days | 3–10 | Low |
| 12 | End-to-End QA & Rollout | 1.5 days | all | Low |

**Total estimate: ~25 dev-days** (one engineer). Phases 3–8 can partially overlap if two engineers work in parallel (one on print, one on interactive pages).

---

## Phase 0 — Audit & Build Tooling Setup

### Goal
Establish a clean, modern frontend build pipeline and confirm the existing Laravel routes/controllers so subsequent phases have a stable foundation.

### Tasks
1. **Audit existing structure**
   - Inventory current Blade views for the sales module (`resources/views/sales/*` or similar). Document what exists and what gets replaced.
   - List existing routes (`php artisan route:list`) for invoices, challans, godown, notifications. Confirm controller method names — these will be reused.
   - Confirm the Eloquent models & relationships match the reference schema (especially `SalesInvoice.status` values, `SalesInvoiceDispatch`, `SalesInvoiceDispatcher`, `SalesChallan`).
2. **Build pipeline**
   - Ensure **Vite** + **Tailwind CSS** are installed (`laravel-vite-plugin`, `tailwindcss`). If on Mix/Tailwind v3, migrate to Vite + Tailwind v4 (or stay on v3.4 if v4 isn't stable for the team).
   - Add Inter + Noto Sans Bengali via `@fontsource` or Google Fonts `@import` in `resources/css/app.css`.
   - Configure `tailwind.config.js` content paths to scan `resources/views/**/*` and `app/View/Components/**/*`.
3. **Fonts & base CSS**
   - In `resources/css/app.css`: import fonts, set `font-sans` to `['Inter','Noto Sans Bengali','system-ui','sans-serif']`, add the 6 custom utility classes from the showcase (`.custom-scroll`, `.write-in`, `.write-in-hint`, `.watermark`, `.nav-btn`, `.pulse`) and the `@media print` block.
4. **Icon strategy**
   - Install `blade-ui-icons` and configure the Lucide set, OR create `app/View/Components/Icon.php` + `resources/views/components/icon.blade.php` that renders inline SVG by name (copy the SVG paths from the showcase). **Recommend the latter** — zero new deps, full control.
5. **Asset config for branch colors**
   - Add a `config/branches.php` returning the branch→color map (or store `color_hex` column on the `branches` table via a small migration in a later phase — keep config-file first for speed).

### Files
- `package.json` (add deps), `vite.config.js`, `resources/css/app.css`, `tailwind.config.js`, `config/branches.php`, `app/View/Components/Icon.php` + `resources/views/components/icon.blade.php`
- `docs/ui-audit.md` (the route/view inventory output)

### Acceptance criteria
- `npm run dev` + `php artisan serve` shows the default page with Inter font loaded and Tailwind utilities working.
- `<x-icon name="warehouse" />` renders the warehouse SVG.
- A printed route inventory doc exists.

---

## Phase 1 — Design System & Blade Component Library

### Goal
Build the full reusable Blade component set so all later phases are pure composition. This is the highest-leverage phase.

### Component inventory to build (all under `resources/views/components/erp/`)

| Component | Props | Purpose |
|---|---|---|
| `<x-erp.layout :title :role :branch>` | app shell slot wrapper | Sticky nav, footer, main container |
| `<x-erp.nav-bar :tabs :activeTab :role :branch :unreadNotifications>` | top navigation | Brand, role switcher, tab strip, bell |
| `<x-erp.role-switcher :current>` | — | WM/SM pill toggle (links to route that sets session role) |
| `<x-erp.branch-switcher :branches :current>` | — | `<select>` wired to a route/Alpine form |
| `<x-erp.journey-stepper>` | hero 3-step (Invoice→Godown→Challan) | dashboard hero |
| `<x-erp.step-indicator :steps :current :completed>` | 4-step with checkmarks | workflow sub-pages |
| `<x-erp.stat-card :label :value :color :icon>` | dashboard metric | `border-l-4` accent style |
| `<x-erp.status-pill :status>` | maps DB status → color/icon/label | used everywhere |
| `<x-erp.left-accent-card :color :title :icon>` | workhorse panel | wraps every section |
| `<x-erp.data-table :columns :rows>` (slot-based) | generic table | sticky amber thead, hover rows, custom scroll |
| `<x-erp.filter-chips :filters :active>` | status filter row | pill chips with counts |
| `<x-erp.form.input :label :name :value :type :bilingual>` | text/number input | `focus:ring-2 focus:ring-amber-300` |
| `<x-erp.form.select :label :name :options :value>` | select | same styling |
| `<x-erp.form.textarea :label :name :value :rows>` | textarea | dispatcher notes etc. |
| `<x-erp.checkbox-card :name :value :label :sublabel :selected :color>` | dispatcher picker card | selectable card |
| `<x-erp.primary-button :color :href>` | solid button | amber/orange |
| `<x-erp.gradient-button :href>` | CTA gradient button | `from-amber-500 to-orange-500` |
| `<x-erp.outline-button :href>` | secondary button | Cancel |
| `<x-erp.sticky-action-bar>` (slot) | bottom sticky bar | Save/Issue bars |
| `<x-erp.warning-callout :title>` (slot) | irreversible-action warning | amber box + alert-triangle |
| `<x-erp.branch-pill :branch>` | branch indicator | colored pill |
| `<x-erp.signature-row :signers>` | print signatures | array of EN/BN label pairs |
| `<x-erp.empty-state :icon :title :message>` | no-data state | (gap-fill from showcase) |
| `<x-erp.skeleton :type>` | loading state | (gap-fill) |

### Tasks
1. Create each component as an anonymous Blade component (`<x-erp.stat-card>`) or class-based (`app/View/Components/...`) for those needing logic.
2. Build a **storybook-style demo route** `GET /ui-preview` that renders every component in isolation with sample data — invaluable for QA and for showing stakeholders.
3. Implement `<x-erp.status-pill>` with a PHP enum/assoc-array mapping all 6 statuses to `{label, labelBn, color, icon}`.
4. Implement `<x-erp.icon>` to accept `name`, `class`, `size` and render the correct inline SVG. Build a static map of ~25 icon names used in the showcase.

### Files
- `resources/views/components/erp/*.blade.php` (~24 files)
- `app/View/Components/Erp/*.php` (class-based ones, ~6 files)
- `app/Support/StatusPalette.php` (status→color enum/map)
- `resources/views/ui-preview.blade.php` + `routes/web.php` (`/ui-preview` route, dev-only)

### Acceptance criteria
- `/ui-preview` shows every component styled identically to the showcase.
- `<x-erp.status-pill status="blank_godown_created" />` renders the orange pill with the clipboard icon.
- Components accept bilingual labels cleanly.

---

## Phase 2 — App Shell & Global Layout

### Goal
Replace the existing layout chrome (header/footer/nav) with the showcase's sticky two-row nav + role/branch switchers + sticky footer.

### Tasks
1. Build `<x-erp.layout>` extending a master `layouts/app.blade.php`:
   - **Sticky top nav** (`sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-amber-200 shadow-sm`, `no-print`):
     - Row 1: `RC ERP` gradient chip + bilingual tagline (left); role switcher pills (right).
     - Row 2: horizontal-scrollable tab strip. Tabs are **route-driven** (not JS `showPage` like the showcase): Dashboard, Prepare Blank Godown, Warehouse Info, Issue Challan, + Print tabs contextual to the active invoice.
   - **Main:** `max-w-7xl mx-auto px-4 py-6` slot.
   - **Sticky footer:** `bg-amber-900 text-amber-100`, sticks to viewport bottom when content is short, pushed down naturally when long. Use `min-h-screen flex flex-col` on `<body>` wrapper + `mt-auto` on footer.
2. **Role switching** — add `POST /role/switch` (or use session) that sets `session('active_role', 'warehouse_manager'|'sales_manager')` and `session('active_employee_id')`. Role switcher pill links here. (Replaces the mock; if real auth exists later, this becomes the user's actual role.)
3. **Branch switching** — `POST /branch/switch` sets `session('active_branch_id')`. All sales queries read this from session. Default to the user's home branch.
4. **Notifications bell** — wired in Phase 10; for now render the bell with the live unread count from `Notification::unread()->count()`.

### Routes
- `POST /role/switch` → `RoleSwitchController`
- `POST /branch/switch` → `BranchSwitchController`

### Files
- `resources/views/layouts/app.blade.php`, `resources/views/components/erp/layout.blade.php`, `resources/views/components/erp/nav-bar.blade.php`
- `app/Http/Controllers/RoleSwitchController.php`, `app/Http/Controllers/BranchSwitchController.php`
- `routes/web.php` (add 2 routes)

### Acceptance criteria
- Every sales-module page uses `<x-erp.layout>` and shows the consistent nav + footer.
- Switching role/branch updates the session and reloads with role/branch-appropriate content.
- Footer sticks to bottom on short pages, pushed down on long pages (verify with browser).

---

## Phase 3 — Dashboard / Invoice List Page

### Goal
Rebuild the invoice list (the WM landing page) to match the showcase dashboard: hero with journey stepper, 4 stat cards, status filter chips, status-aware invoice table with inline printed/returned tracker.

### Tasks
1. **Controller:** reuse existing `InvoiceController@index` — ensure it returns: invoices (with customer, items count, status, total) scoped to `session('active_branch_id')` and `session('active_role')`; plus stat counts per status; plus filter support via `?status=` query.
2. **Hero header** — gradient card with bilingual title `গোডাউন ও চালান / Godown & Challan Management`, branch switcher, WM/SM chip, notifications bell (pulsing badge). Center the `<x-erp.journey-stepper>`.
3. **Stat cards** — 4-up grid (`grid-cols-2 md:grid-cols-4`): Needs Godown (amber), Blank Godown (orange), Ready for Challan (cyan), Completed (green). Values from the controller's stat counts. **Role-aware:** SM sees Drafts / Finalized / Awaiting / Completed instead.
4. **Filter chips** — `<x-erp.filter-chips>` wired to `?status=` query param; clicking reloads with the filter and updates counts.
5. **Invoice table** — `<x-erp.data-table>` with columns: Invoice Code | Date | Customer (+code) | Items (pill) | Total (৳) | Status | Action.
   - **Status cell:** `<x-erp.status-pill>` + (for `blank_godown_created` rows) inline mini-pills `🖨️ Printed` / `📝 Returned?` that toggle a client-side flag stored in `localStorage` (keys `rc-erp-printed-invoices`, `rc-erp-hardcopy-returned`) — purely a UX aid, matches the showcase. (Phase 11 may promote these to server-side.)
   - **Action cell:** status-aware button:
     - `finalized` → "Prepare Blank Godown" (amber) → route to Phase 5 page
     - `blank_godown_created` → "Enter Warehouse Info" (orange) → route to Phase 6 page
     - `godown_prepared` → "Issue Challan" (gradient) → route to Phase 7 page
     - `challan_issued` → "View" (outline green) → route to challan detail
6. **SM-specific view:** when role = sales_manager, show tabs "My Drafts" / "Finalized & Beyond" instead. (Note: fix the reference bug — actually filter drafts by `salesman_id = session('active_employee_id')`.)
7. **Empty state** when no invoices match the filter.

### Files
- `app/Http/Controllers/InvoiceController.php` (refactor `index`)
- `resources/views/sales/index.blade.php`
- Reuses: `<x-erp.journey-stepper>`, `<x-erp.stat-card>`, `<x-erp.filter-chips>`, `<x-erp.data-table>`, `<x-erp.status-pill>`

### Acceptance criteria
- Dashboard visually matches the showcase's Page 1.
- Each status shows the correct colored pill and the correct next-action button.
- Clicking a chip filters the table; counts update.
- Printed/Returned mini-pills persist across reloads (localStorage).

---

## Phase 4 — Invoice Detail View

### Goal
A universal read-only invoice detail page showing header, items, and the **Dispatch Pipeline** card (ordered qty/CTN vs dispatched qty/warehouse per product). Role- and status-aware action buttons.

### Tasks
1. **Route:** `GET /invoices/{invoice}` → `InvoiceController@show` (reuse; ensure it eager-loads `items.product`, `dispatches.product.warehouse`, `dispatchers.employee`, `challan.items`).
2. **Layout:**
   - Invoice info `<x-erp.left-accent-card color="amber">`: code, date, customer, salesman, branch, subtotal/discount/transport/total.
   - Items table: Product | Qty | Rate | Amount. **Hide warehouse column when status = draft.**
   - **Dispatch Pipeline card** (only for `finalized`+): per `SalesInvoiceDispatch` row — Product | Ordered Qty | Ordered CTN | Warehouse | Dispatched Qty | Dispatched CTN.
3. **Role/status action bar:**
   - SM + draft → Edit / Finalize (confirm modal) / Cancel
   - SM + finalized+ → Back only
   - WM + finalized → "Prepare Blank Godown"
   - WM + blank_godown_created → "Enter Warehouse Info"
   - WM + godown_prepared → "Issue Challan"
   - WM + challan_issued → "View Challan"
4. **Finalize action:** `POST /invoices/{invoice}/finalize` with an Alpine-driven confirm dialog ("This will notify the Warehouse Manager. Continue?"). On success, toast + redirect to dashboard.
5. **Cancel (draft only):** `DELETE /invoices/{invoice}` with confirm dialog.

### Files
- `resources/views/sales/show.blade.php`
- `app/Http/Controllers/InvoiceController.php` (`show` — likely already exists, just ensure includes)

### Acceptance criteria
- Detail page renders for every status without errors.
- Action buttons appear only for the correct role+status combination.
- Finalize/Cancel work and redirect with a success toast.

---

## Phase 5 — Blank Godown Creation (Step 2 of 4)

### Goal
The WM page where they select dispatchers, write instructions, and create the blank godown copy (which can auto-print).

### Tasks
1. **Route:** `GET /invoices/{invoice}/blank-godown` (form) + `POST /invoices/{invoice}/blank-godown` (submit, reuses existing controller action that sets status → `blank_godown_created`).
2. **Page header:** back button, branch pill, **4-step `<x-erp.step-indicator>`** with `Invoice ✓` (green) / `Blank Godown` (active) / `Godown Prep` (gray) / `Challan Issue` (gray). Bilingual subtitle "Step 2 of 4".
3. **Invoice info card** (`border-l-amber-400`): code + status pill, customer/branch/subtotal/total grid, phone & address.
4. **Product Demand Summary card** (`border-l-amber-400`): 3 mini-stat tiles (Products / Total Qty / Est. CTN) + 5-col table (#, Product, Demand Qty, Est. CTN, Rate). Est. CTN = `ceil(qty / pcs_per_carton)`.
5. **Dispatcher Selection card** (`border-l-orange-500`): grid of `<x-erp.checkbox-card>` for each `Employee` with `role=dispatcher`, `branch_id` matching invoice, `is_active`. Selected cards get the branch color border. Multi-select. **Validation: at least 1 required.**
6. **Dispatcher Notes card** (`border-l-yellow-500`): `<x-erp.form.textarea>` bilingual placeholder, prefilled with default Bengali instructions.
7. **Auto-Print + Create card** (amber callout): checkbox "Auto-print after creation" + `<x-erp.gradient-button>` "Create & Print". On submit:
   - POST to the existing blank-godown endpoint with `{ dispatcher_ids, notes }`.
   - If auto-print checked → on success redirect to `GET /invoices/{invoice}/print/blank-godown` (Phase 8) which opens the print view.
   - Else → toast + redirect to dashboard.
8. **Validation errors** rendered inline (Alpine + Laravel `$errors` bag).

### Files
- `resources/views/sales/blank-godown.blade.php`
- `app/Http/Controllers/InvoiceController.php` (`blankGodownForm` + reuse existing `blankGodown` store)

### Acceptance criteria
- Form validates dispatcher selection (≥1, all belong to branch).
- Creating transitions the invoice to `blank_godown_created` and fires the existing notification to WM.
- Auto-print flow opens the blank godown print view (Phase 8).

---

## Phase 6 — Godown Preparation / Warehouse Assignment (Step 3 of 4)

### Goal
The WM page where they assign a warehouse + dispatched CTN per product, see live stock availability, and adjust transport cost.

### Tech choice: **Use Livewire 3** for this page — the table needs live stock-badge updates and CTN auto-calc without full reloads. (If Livewire isn't desired, Alpine + fetch is acceptable but more code.)

### Tasks
1. **Route:** `GET /invoices/{invoice}/godown-preparation` + `POST /invoices/{invoice}/prepare-godown` (reuse existing controller).
2. **Page header:** step indicator with steps 1–2 done, step 3 active.
3. **Invoice Summary card** (`border-l-orange-500`): code, customer, totals.
4. **Assigned Dispatchers card:** read-only chips (from Phase 5).
5. **Bulk Toolbar card** (`border-l-amber-500`):
   - "Apply warehouse to all" `<select>` (lists active warehouses in branch) — sets all rows' warehouse.
   - "Fill All CTN Auto" button — sets each row's CTN to `ceil(ordered_qty / pcs_per_carton)`.
6. **Warehouse Assignment Table** (6 cols): Product | Ordered Qty | CTN/Unit | Warehouse (`<select>`, branch-scoped) | Stock badge | CTN (`<input type="number">`).
   - **Stock badge:** on warehouse selection, query `WarehouseStock` for that product+warehouse; show `bg-green-100 text-green-700` (in stock) or `bg-yellow-100 text-yellow-700` (low/out). Sub-text "In WH: {qty} {unit}". **Livewire `wire:change`** updates the badge live.
   - **Validation (client + server):** dispatched CTN × pcs_per_carton ≤ stock.qty. Show inline red error if exceeded; block save.
7. **Transport Cost Adjustment card** (`border-l-cyan-500`): 3-col grid — Original (static, from invoice) / New (input) / Total Preview (bold, recalculated live: `subtotal − discount + newTransport`).
8. **Sticky Save Bar** (`<x-erp.sticky-action-bar>`): "Save Godown Copy" (amber) + Cancel. On submit → existing `prepare-godown` endpoint with `{ warehouse_assignments: [{ product_id, warehouse_id, dispatched_ctn }], transport_cost }`.
9. **Error handling:** map the existing `STOCK_INSUFFICIENT` / `STOCK_NOT_FOUND` error codes to bilingual inline + toast messages.

### Files
- `app/Livewire/GodownPreparation.php` (Livewire component)
- `resources/views/livewire/godown-preparation.blade.php`
- `app/Http/Controllers/InvoiceController.php` (`godownPreparationForm`)

### Acceptance criteria
- Selecting a warehouse per row updates the stock badge without reload.
- "Fill All CTN Auto" populates all CTN inputs.
- Over-committing stock shows an inline error and blocks save.
- Save transitions invoice to `godown_prepared` and recalculates transport/total.

---

## Phase 7 — Challan Issue (Step 4 of 4)

### Goal
The final WM page: COGS preview, transport details, irreversible-action warning, and the issue-challan confirmation.

### Tasks
1. **Route:** `GET /invoices/{invoice}/challan-issue` + `POST /invoices/{invoice}/issue-challan` (reuse existing; ensure it accepts `idempotency_key`).
2. **Page header:** step indicator with steps 1–3 done, step 4 active.
3. **Invoice Summary card** (`border-l-cyan-500`).
4. **COGS Preview card** (`border-l-green-500`): 5-col table — Product | Warehouse (pill with warehouse icon) | Qty | Avg Cost (৳) | COGS Amount (৳). Avg Cost = `WarehouseStock.avg_cost` for the assigned warehouse (**NOT** `product.purchase_rate` — fix the reference preview bug). Footer: Total COGS (green). Subtitle: "ক্রয় মূল্য প্রাক্কলন — Will be posted as a journal entry".
5. **Transport Details card** (`border-l-amber-500`): `md:grid-cols-2` form — Transport Name, Vehicle Number, Driver Name, Transport Cost. Sub-box (`md:grid-cols-4`): Subtotal / Discount / Transport / Grand Total preview (live recalc with Alpine).
6. **Warning Callout** (`<x-erp.warning-callout>`): "Important: This action is irreversible" — explains stock deduction, COGS journal, transport-adjustment journal.
7. **Sticky Issue Bar:** "Issue Challan" gradient button → opens an **Alpine confirm modal** summarizing COGS / transport adjustment / final total. Confirm sends POST with a client-generated `idempotency_key` (`crypto.randomUUID()`).
8. **Post-issuance success view:** card with "Challan Issued Successfully" + 3 print buttons (Challan Copy / Godown Copy / Invoice Copy) → Phase 8 routes.
9. **Error handling:** `INVOICE_ALREADY_HAS_CHALLAN` → treat as success (show the post-issuance view with the existing challan). `STOCK_INSUFFICIENT` → long-duration bilingual toast.

### Files
- `resources/views/sales/challan-issue.blade.php`
- `app/Http/Controllers/InvoiceController.php` (`challanIssueForm`)

### Acceptance criteria
- COGS preview uses `WarehouseStock.avg_cost`.
- Confirmation modal shows the financial summary before the irreversible action.
- Issue transitions invoice to `challan_issued`, creates the challan, deducts stock, posts journals (all via existing controller).
- Idempotency: double-submit doesn't create a duplicate challan.
- Success view offers the 3 print buttons.

---

## Phase 8 — Print Layouts (3 copies)

### Goal
Build the three print views exactly matching the showcase, with branch-colored headers, write-in cells, watermarks, and signature rows.

### Shared foundation
- **Print base layout** `<x-erp.print.layout :branch :watermark :title>`: centered `max-w-3xl`/`max-w-4xl` container, branch-colored header, watermark div, signature row slot, `no-print` action bar (Print + Close). Print button uses `window.print()` (showcase behavior) — same-window print relying on `@media print` to hide `.no-print`. *(Alternative: open a dedicated print route in a new tab — preferred for Laravel since the main app stays mounted. Confirm with team.)*
- **Branch color application:** all branch-colored elements use inline `style="border-color:{hex}; background:{hex}22"` etc., reading from `config/branches.php` or `$branch->color_hex`.
- **Pad-to-fixed-rows:** blank godown pads the items table to 17 rows (3 data + 14 blank `<tr>` of height 22px) for consistent paper layout.

### Tasks

#### 8a. Blank Godown Copy — `GET /invoices/{invoice}/print/blank-godown`
- Watermark: `BLANK GODOWN` (diagonal, 72px, 15% gray).
- Branch header: custom SVG logo box (branch-colored border/fill + branch code), branch name EN+BN, `border-bottom: 3px solid {branch_color}`.
- Title block: `খালি গোডাউন কপি / BLANK GODOWN COPY` in a branch-colored border-2 box.
- Info grid (2-col, 8 fields, all bilingual): Customer, Invoice No, Date, Phone, Address, Branch, Subtotal, Transport.
- Items table (6 cols, **bilingual stacked headers**): `# / ক্রম`, `Product Name / পণ্যের নাম`, `Demand / চাহিদা`, `Warehouse (লিখুন)`, `CTN / কার্টন`, `Picked / পিস তোলা`. **Last 3 cols use `.write-in` class** with `.write-in-hint` floats (`(লিখুন / write)`, `≈1` estimate hint). Pad to 17 rows. Total row (branch-tinted bg).
- Amount summary (3-col, branch-bordered): Subtotal / Transport / Total.
- Dispatcher block: dispatcher name + mobile + WM instructions + dashed write-in "Additional Notes / অতিরিক্ত নোট" box.
- Instructions box (branch border-2): bilingual rules for the dispatcher.
- Signatures (3): Dispatcher / Godown Manager / Verifier.
- Footer line: `Page 1 of 1 • {invoice_code} • {branch_name}`.

#### 8b. Godown Copy — `GET /invoices/{invoice}/print/godown-copy`
- No watermark. Card chrome kept (`max-w-3xl bg-white p-4 rounded-xl shadow-sm`).
- Header: `RC DISTRIBUTION / আর সি বণিক`, `Godown Copy / গোডাউন কপি`, branch name.
- Info grid (2-col, 6 fields).
- Items table (9 cols, `bg-orange-100` header): Sl | Product / পণ্য | Code | Demand / চাহিদা | Warehouse / গোডাউন | CTN / কার্টন | PCS / টুকরা | Rate (৳) | Amount (৳). **Filled-in** values from `invoice.dispatches`. Orange-50 tfoot total.
- Transport & Total (2-col).
- Signatures (3): WM Signature / Dispatcher / Received By.

#### 8c. Delivery Challan — `GET /challans/{challan}/print/challan-copy`
- Widest container (`max-w-4xl`).
- Header: `RC DISTRIBUTION / আর সি বণিক`, `Delivery Challan / চালানপত্র (ডেলিভারি)`, branch name, **challan number `CHL-{branch}-{NNNNN}`** prominent (amber-600).
- Info grid (2-col, 8 fields): Challan No, Date, Invoice Ref, Dispatchers | Customer, Phone, Address, Total.
- Transport box (`bg-orange-50 border-2 border-orange-300`): transport name, vehicle, driver, cost.
- Challan items table (7 cols, `bg-amber-100` header): Sl | Product / পণ্য | Code | Warehouse / গোডাউন | Qty / পরিমাণ | Rate (৳) | COGS (৳). Amber-50 tfoot `Total COGS`.
- Grand Total box (amber-100 border-2 amber-500): Sales Total / COGS / Transport.
- Terms (bilingual validity note).
- Signatures (3): Authorized By / Dispatcher / Received By (Customer).
- Stamp placeholder: dashed border-2 gray-300 rounded-lg `Company Seal / কোম্পানি সিল`.

### Routes
- `GET /invoices/{invoice}/print/blank-godown` → `PrintController@blankGodown`
- `GET /invoices/{invoice}/print/godown-copy` → `PrintController@godownCopy`
- `GET /challans/{challan}/print/challan-copy` → `PrintController@challanCopy`

### Files
- `app/Http/Controllers/PrintController.php`
- `resources/views/components/erp/print/layout.blade.php`, `signature-row.blade.php`
- `resources/views/sales/print/blank-godown.blade.php`, `godown-copy.blade.php`, `challan-copy.blade.php`
- `routes/web.php` (3 routes)

### Acceptance criteria
- Each print view, when opened and printed (Ctrl+P / browser print dialog), produces a clean A4 with no nav/footer/action bars.
- Blank godown shows dashed write-in cells and the watermark.
- All three show the correct branch color (HO = red; others per config).
- Multi-product invoices paginate cleanly (define `.page-break` usage if >17 items).

---

## Phase 9 — Branch Theming System

### Goal
Make every branch-colored surface runtime-configurable so adding/editing a branch (and its color) requires no code change.

### Tasks
1. **Migration:** add `color_hex` column to `branches` table (`string('color_hex')->default('#dc2626')`). Backfill HO=`#dc2626`, PAT/NOW/TAR per stakeholder confirmation.
2. **Helper:** `branch_color(string $branchId): string` and `branch_tint(string $branchId, int $alphaPct): string` (returns rgba/hex-alpha). Put in `app/Support/BranchColor.php`.
3. **Apply everywhere:** every place that currently hardcodes `#dc2626` (print headers, branch pills, "Awaiting Godown" badges, write-in borders, instruction boxes) reads from `$branch->color_hex` via the helper.
4. **Update `config/branches.php`** as a fallback only; the DB column is the source of truth.
5. **Print components** receive `:branch="$invoice->branch"` and use the helper inside.

### Files
- `database/migrations/{ts}_add_color_hex_to_branches_table.php`
- `app/Support/BranchColor.php`
- Update print + nav-bar + branch-pill components.

### Acceptance criteria
- Changing a branch's `color_hex` in the DB instantly changes its color across the dashboard, all print views, and pills — no deploy needed.
- HO stays red; the other 3 branches get their confirmed colors.

---

## Phase 10 — Notifications Panel

### Goal
Make the bell icon functional: a slide-out (or dropdown) panel listing notifications, with unread count, mark-as-read, and smart routing to the next workflow step.

### Tasks
1. **Controller:** reuse existing `NotificationController@index` (filter by `recipient_role = session role` + `branch_id`) and `PATCH /notifications/{id}/read`.
2. **Panel UI:** Alpine-driven right-side **Sheet/Drawer** (slides in from right, `fixed inset-y-0 right-0 w-96 bg-white shadow-xl`). Triggered by the bell button. Lists notifications newest-first.
3. **Notification card styling** per `event`:
   - `sales_finalize` → FileCheck icon, amber
   - `blank_godown_create` → ClipboardList icon, orange
   - `godown_create` → Warehouse icon, cyan
   - `challan_create` → Truck icon, green
4. **Click handler:** `PATCH /notifications/{id}/read` then redirect to the **next** workflow view based on event:
   - `sales_finalize` → blank-godown page
   - `blank_godown_create` → godown-preparation page
   - `godown_create` → challan-issue page
   - `challan_create` → challan-detail page
5. **Unread badge:** the bell's pulsing red count updates via a lightweight Livewire poll (every 30s) or a meta refresh on the layout.

### Files
- `resources/views/components/erp/notification-panel.blade.php`
- `app/Livewire/NotificationBell.php` (or Alpine + fetch)
- Reuse existing notification routes.

### Acceptance criteria
- Bell shows live unread count; panel opens on click.
- Clicking a notification marks it read and routes to the correct next step.

---

## Phase 11 — Polish & Gap-Filling

### Goal
Fill the gaps the showcase doesn't cover but a production ERP needs.

### Tasks
1. **SM role full view** — the showcase only renders WM. Build the SM-equivalent: dashboard tabs (My Drafts / Finalized & Beyond), invoice create form, invoice edit form, matching the same design language. (These likely already exist in the Laravel app — restyle them with the new components.)
2. **Invoice Create/Edit forms** — customer select, product picker (auto-fill `sales_rate`), qty/rate/amount live calc, discount/transport/notes, live total preview. Use `<x-erp.form.*>` components.
3. **Loading states** — `<x-erp.skeleton>` on table & card mounts while data loads (Livewire `wire:loading`).
4. **Empty states** — `<x-erp.empty-state>` for "No invoices match this filter", "No notifications", etc.
5. **Validation error states** — red borders + bilingual messages on all forms; map backend error codes to bilingual strings.
6. **Mobile responsive** — add `sm:`/`lg:` breakpoints: collapsible nav (hamburger/drawer), stackable form grids, horizontal-scroll tables with sticky first column, collapsing step indicator.
7. **Bengali webfont** — ensure Noto Sans Bengali loads on all pages (Phase 0 set this up; verify rendering of all Bengali strings).
8. **Pagination** — add Laravel pagination to the invoice table (showcase only scrolls; production needs paging for large datasets).
9. **Toast notifications** — wire a global toast (Alpine + session flash) for all success/error feedback, bilingual.
10. **Printed/Returned tracker (optional)** — promote the localStorage mini-pills to a server-side `invoice_printed_at` / `hardcopy_returned_at` timestamp on `SalesInvoice` if stakeholders want it shared across users. (Small migration + 2 toggle routes.)

### Files
- `resources/views/sales/create.blade.php`, `edit.blade.php`
- `resources/views/components/erp/empty-state.blade.php`, `skeleton.blade.php`
- `resources/views/components/erp/toast.blade.php` (Alpine global)
- Mobile refinements across components.

### Acceptance criteria
- SM can create/edit/finalize invoices with the new UI.
- All loading/empty/error states are styled and bilingual.
- Layout holds on mobile (375px) and desktop (1440px).
- Toasts appear for every mutation.

---

## Phase 12 — End-to-End QA & Rollout

### Goal
Verify the full workflow end-to-end in the browser before declaring done.

### Test script (manual, per role)
1. **As SM:** create invoice → edit → finalize → (notified).
2. **As WM:** open notification → create blank godown (auto-print) → print blank godown.
3. **As WM:** enter warehouse info (test stock-overcommit rejection) → save godown copy → print godown copy.
4. **As WM:** issue challan (confirm modal) → print challan copy → view challan detail.
5. **As WM:** switch branch → verify branch color changes everywhere.
6. **Edge cases:** draft cancel, double-submit issue-challan (idempotency), insufficient stock, no dispatchers selected, empty filter.

### Checks
- Browser console clean; no Laravel errors in `storage/logs/laravel.log`.
- Print outputs are clean A4 with no chrome.
- Footer sticky on short pages; pushed on long pages.
- Bilingual rendering correct on all screens.
- Responsive at 375 / 768 / 1280 / 1920px.

### Rollout
- Feature-flag the new UI behind a config/ENV flag (`UI_VERSION=v2`) so stakeholders can A/B compare with the old UI during rollout. Flip default once approved.
- Remove old Blade views only after sign-off.

---

## Cross-Cutting Notes

### Backend changes required (minimal)
- 2 new session routes (role/branch switch) — Phase 2.
- 3 new print routes — Phase 8.
- 1 small migration (`branches.color_hex`) — Phase 9.
- Optional migration (`invoice_printed_at`, `hardcopy_returned_at`) — Phase 11.
- **No changes** to the core invoice/challan business logic, stock deduction, or journal posting — those controllers are reused as-is.

### Risk register
| Risk | Mitigation |
|---|---|
| Tailwind v4 instability on Laravel | Use Tailwind v3.4 if v4 proves flaky |
| Livewire not installed | Phase 6 can fall back to Alpine+fetch (more code, same UX) |
| Bengali font rendering on print | Test print on Chrome + Firefox; embed font via `@font-face` base64 if needed |
| Multi-page print overflow | Implement `.page-break` + repeat header row in print tables |
| Stakeholder branch-color disagreement | Confirm PAT/NOW/TAR colors in Phase 0 kickoff before Phase 9 |

### Component → showcase section mapping (quick reference)
| Showcase section | Laravel deliverable | Phase |
|---|---|---|
| Nav bar + role switcher | `<x-erp.nav-bar>`, `<x-erp.role-switcher>` | 2 |
| Dashboard hero + stepper | `<x-erp.journey-stepper>` in `sales/index.blade.php` | 3 |
| Stat cards | `<x-erp.stat-card>` ×4 | 3 |
| Filter chips | `<x-erp.filter-chips>` | 3 |
| Invoice table + status pills + action buttons | `<x-erp.data-table>` + `<x-erp.status-pill>` | 3 |
| Prepare Blank Godown page | `sales/blank-godown.blade.php` + `<x-erp.step-indicator>` + `<x-erp.checkbox-card>` | 5 |
| Warehouse Info page | `livewire/godown-preparation.blade.php` | 6 |
| Issue Challan page | `sales/challan-issue.blade.php` + `<x-erp.warning-callout>` | 7 |
| Print Blank Godown | `sales/print/blank-godown.blade.php` | 8a |
| Print Godown Copy | `sales/print/godown-copy.blade.php` | 8b |
| Print Challan Copy | `sales/print/challan-copy.blade.php` | 8c |
| Notifications bell | `<x-erp.notification-panel>` | 10 |

---

### Done definition
The UI/UX revamp is complete when:
- ✅ All 4 workflow steps render matching the showcase, bilingual, branch-colored.
- ✅ All 3 print layouts produce clean A4 output.
- ✅ Both SM and WM roles have complete, styled flows.
- ✅ Mobile + desktop responsive verified.
- ✅ No regressions in the existing backend (statuses, stock, journals behave identically).
- ✅ Stakeholder sign-off on visual fidelity vs. `rc-erp-ui-showcase.html`.
