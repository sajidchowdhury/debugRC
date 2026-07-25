# Today Invoice — UI/UX Implementation Plan (Tailwind Roadmap)

> **Goal:** Close every verified UI/UX gap between the Legacy `sales/today` screen and the Laravel `admin/sales-invoices` index, using the Laravel project's **existing Tailwind v4 design system** (`<x-erp.*>` components, `App\Support\Accents`, `App\Support\BranchColor`, `rc-erp.css`). Then add premium, modern, mobile-responsive, accessible improvements that make the screen **exceed** the Legacy UX — without changing the business workflow.
>
> **Scope:** Only the Today Invoice screen. No other module.
> **Source analysis:** `today-invoice-uiux-analysis.md` (§3 Differences, §4 Missing Components, §7 Improvement Opportunities).
> **Rule:** No Bootstrap copying. Every component below uses Tailwind utilities + the existing `<x-erp.*>` component library. No code is written in this document — it is a phased roadmap.

---

## Progress Tracker

| Phase | Status | Notes |
|---|---|---|
| 1 — In-Context Payment & Call-It-A-Day | ✅ **DONE** | AJAX payment, print-receipt prompt, auto call-it-a-day, per-row + bulk actions, 4 new `<x-erp.*>` components |
| 2 — Filter UX | ✅ **DONE** | Date presets (Today/Yesterday/Last 7 days/This month/Custom), localStorage persistence, active-filter-bar with removable tags + Clear all, 3 new `<x-erp.*>` components |
| 3 — Per-Row Actions & Inline Reverse | ✅ **DONE** | Action-group pattern (≤3 inline + overflow ⋯) on desktop + mobile, inline payment reversal in receive modal (SweetAlert2 reason prompt → AJAX POST → re-fetch), 2 new `<x-erp.*>` components + controller AJAX branch |
| 4 — Premium Polish | ✅ **DONE** | Sticky DataTable header (amber-50/blur, max-h-28rem scroll), due-column colored pills (red due / green ✓ Paid), dual empty-state (filtered vs genuinely-empty) reusing `<x-erp.empty-state>`, branch-color pills in table cell (config-driven inline styles), live hero counter (`#heroInvoiceCount` via `recordsDisplay`); removed redundant mobile inline empty-state + skipped the unnecessary `<x-erp.live-counter>` component (inline span suffices) |
| 5 — Accessibility & Keyboard | ✅ **DONE** | `<x-erp.sr-status>` component (replaces inline div), keyboard shortcut layer (j/k/r/c/e///Esc — desktop-only, skipped on `pointer:coarse`), modal focus management (auto-focus `#srpAmount` on open, restore to trigger button on close), global reduced-motion guard, WCAG AA contrast fix (amber `#d97706`→`#b45309`, green `#16a34a`→`#15803d`, cyan `#0891b2`→`#0e7490`), keyboard-hint badge with SweetAlert2 cheatsheet; 1 new `<x-erp.*>` component |
| 6 — Responsive & Mobile | ⬜ Pending | |

---

## How to read this plan

- 6 phases, ordered by impact (Critical workflow → High efficiency → Medium polish → Premium delight → Accessibility → Responsive).
- Each phase: **Screens to improve · Components to build · Tailwind approach · Responsive considerations · Accessibility considerations · Expected outcome**.
- Phases 1-3 overlap with the business implementation plan (they're the view/JS layer of the same gaps). Phases 4-6 are UI-only improvements.
- Every component name in `code` references either an existing `<x-erp.*>` component or a new one to be created in `resources/views/components/erp/`.

---

## Phase 1 — In-Context Payment & Call-It-A-Day Flow (Critical) ✅ DONE

> Closes the #1 UX regression: the payment flow redirects away, breaking auto-call-it-a-day + print-receipt prompts. Also adds the missing per-row + bulk call-it-a-day UI.
>
> **Implemented:** See "Phase 1 — Implementation Log" at the end of this document for the full list of files changed + components built + behaviour delivered.

### Screens to improve
- `resources/views/admin/sales-invoices/index.blade.php` — the index page's inline `@push('scripts')` block + the DataTables actions column + the results-card header.
- `resources/views/admin/sales-invoices/_receive_modal_body.blade.php` — the receive-modal form (switch from native POST to AJAX).
- `resources/views/components/erp/` — new component: `bulk-action-bar.blade.php`.

### Components to build
1. **`<x-erp.bulk-action-bar>`** — a sticky bar that appears above the DataTable when ≥1 row is checked. Shows: "N selected" + a slot for action buttons (Call It A Day, Cancel). Disappears when 0 selected.
2. **`<x-erp.row-checkbox>`** — a styled checkbox (Tailwind `size-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500`) used in col 0 of the DataTable + a "select all on page" variant in the header.
3. **`<x-erp.action-button>`** — a compact icon-button with tooltip + `aria-label`, used for per-row actions. Variants: `view` (eye, gray), `edit` (pencil, amber), `cancel` (ban, red), `receive` (money-bill, green), `call-it-a-day` (check-circle, orange), `print` (printer, gray).
4. **`<x-erp.action-overflow>`** — a "⋯" dropdown button for less-common per-row actions (used on mobile + when >3 actions are visible). Renders a Tailwind-styled dropdown menu.

### Tailwind implementation approach
- **Bulk action bar:** `sticky top-0 z-20 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 shadow-sm flex items-center justify-between` + slot. Enter/exit animation via Tailwind `transition opacity translate-y` + a `hidden` toggle class.
- **Row checkbox:** `size-4 rounded border-gray-300 text-amber-600 focus:ring-2 focus:ring-amber-500 focus:ring-offset-0`.
- **Action button:** `inline-flex items-center justify-center size-8 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-amber-50 hover:text-amber-700 hover:border-amber-300 transition-colors` + per-variant color overrides via the `App\Support\Accents` map. Icon via `<x-erp.icon>`. Tooltip via `title` attribute + a lightweight Tailwind tooltip (or `aria-label` only for v1).
- **Action overflow dropdown:** reuse Bootstrap's `.dropdown` (already loaded) but style the menu with Tailwind: `dropdown-menu-end shadow-lg rounded-md border border-gray-200 bg-white py-1` + `.dropdown-item` with `hover:bg-amber-50 hover:text-amber-900`.
- **AJAX payment submit:** convert `#srpForm` submit from native POST to `fetch('{{ route("admin.customer-payments.store") }}', { method:'POST', body: formData, headers:{'X-CSRF-Token': window.CSRF_TOKEN} })`. On success JSON, fire `salesToday:paymentRecorded` custom event with `{ payment_id, is_fully_paid, invoice_id }`. Keep the form structure identical (same hidden fields including `idempotency_token`).

### Responsive considerations
- Bulk action bar: full-width on mobile, max-width container on desktop. Buttons wrap with `flex-wrap gap-2`.
- Per-row actions: on mobile cards, show View + Receive + a "⋯" overflow (Edit / Call-it-a-day / Cancel). On desktop, show View + Edit + Receive + Call-it-a-day inline + "⋯" overflow for Cancel (keeps the row narrow).
- Action overflow dropdown: on mobile, render as a bottom-sheet (`fixed bottom-0 inset-x-0 rounded-t-2xl`) instead of a popover for thumb reachability.

### Accessibility considerations
- Every icon-only action button MUST have `aria-label` (e.g., `aria-label="Receive payment for invoice {{ $invoice_code }}"`).
- Bulk action bar: `role="region" aria-label="Bulk actions"`. The "N selected" text is `aria-live="polite"` so screen readers announce selection changes.
- Checkbox: `<input type="checkbox" aria-label="Select invoice {{ $code }}">`. The "select all" header checkbox has `aria-label="Select all invoices on this page"` and toggles `indeterminate` state when partial selection.
- AJAX submit: announce success/failure via an `aria-live="polite"` status region. On network error, focus returns to the submit button + `aria-describedby` error message.

### Expected outcome
- Recording a payment keeps the user on the index page (no redirect).
- A SweetAlert2 success toast offers "Print receipt" → opens in new tab.
- If fully paid, a follow-up SweetAlert2 prompts "Call it a day?" → confirm → invoice vanishes from list.
- Users can call-it-a-day per-row (1 click + confirm) or in bulk (check N → bulk action bar → confirm).
- The daily-collection workflow matches Legacy speed (~15s per payment) and exceeds it (bulk actions, no navigation).

---

## Phase 2 — Filter UX: Persistence, Presets, Active Filter Bar (High) — ✅ DONE

> Closes the filter-navigation efficiency gaps. Restores the Legacy speed of "click Yesterday preset → done" + "reload page → filters still there".

> **Implemented:** 3 new Blade components (`<x-erp.date-presets>`, `<x-erp.filter-tag>`, `<x-erp.active-filter-bar>`) wired into `sales-invoices/index.blade.php`. Date presets resolve to concrete `from_date`/`to_date` client-side; filter state persists to `localStorage` under `rcerp_sales_invoices_filters_v1` (skipped when the URL carries explicit filter params); the active-filter-bar renders one removable tag per active filter + "Clear all". Tailwind CSS rebuilt — all new utility classes (`min-h-[36px]`, `bg-amber-50/60`, `size-3.5`, `focus-visible:ring-*`, gradient active state, etc.) confirmed generated in `public/assets/css/rc-erp.css`.

### Screens to improve
- `resources/views/admin/sales-invoices/index.blade.php` — filter form section + a new active-filter-bar row above the DataTable.

### Components to build
1. **`<x-erp.date-presets>`** — a row of 5 pill buttons (Today / Yesterday / Last 7 days / This month / Custom) with an active-state indicator. Emits the resolved date range via a JS event.
2. **`<x-erp.active-filter-bar>`** — a horizontal wrap of removable filter tags + a "Clear all" button. Each tag is a pill with a label + an `×` button. Driven by a JS function that reads current filter state and renders tags.
3. **`<x-erp.filter-tag>`** — a single removable tag pill.

### Tailwind implementation approach
- **Date presets:** `flex flex-wrap gap-1.5` container. Each pill: `rounded-full px-3 py-1 text-xs font-medium border transition-colors`. Inactive: `bg-white border-amber-200 text-amber-700 hover:bg-amber-50`. Active: `bg-gradient-to-r from-amber-500 to-orange-500 border-transparent text-white shadow-sm`. "Custom" is never "active" — clicking it just clears the active state and focuses the date inputs.
- **Active filter bar:** `flex flex-wrap items-center gap-2 bg-amber-50/60 border border-amber-200 rounded-lg px-3 py-2` + `hidden` when no filters active. "Clear all": `ml-auto text-xs text-amber-700 hover:text-amber-900 hover:underline font-medium`.
- **Filter tag:** `inline-flex items-center gap-1.5 rounded-full bg-white border border-amber-300 px-2.5 py-0.5 text-xs text-amber-900`. The `×` button: `size-4 rounded-full hover:bg-amber-100 inline-flex items-center justify-center text-amber-600`.
- **localStorage persistence:** key `rcerp_sales_invoices_filters_v1`. On load: if URL has explicit filter params (`?from_date=` etc.), set `forceUrlParams=true` and skip localStorage; else hydrate from localStorage. On every filter change: debounce 400ms → save JSON blob. "Clear" wipes localStorage + DOM + redraws with defaults (`scope=today`, smart_sort on).

### Responsive considerations
- Presets: `flex-wrap` so they wrap to 2 rows on narrow screens. Tap targets ≥44px (`min-h-[44px]` or `py-2`).
- Active filter bar: `flex-wrap` so tags wrap. "Clear all" stays at the end via `ml-auto` (wraps to its own line on very narrow screens).
- Date inputs: full-width on mobile (`w-full`), auto-width on desktop.

### Accessibility considerations
- Presets: `<button aria-pressed="true|false">` for toggle semantics. Each has `aria-label="Filter to {preset name}"`.
- Filter tags: the `×` button has `aria-label="Remove {filter name} filter"`. Tags are `role="group" aria-label="Active filters"`.
- "Clear all": `aria-label="Clear all filters"`.
- When filters change, announce "Showing N invoices" via the existing `aria-live="polite"` status region.

### Expected outcome
- One-click date navigation (Today / Yesterday / Last 7 days / This month).
- Filters survive page reload.
- Users always see what's currently filtered, with one-click removal per filter.
- Filter UX matches Legacy speed.

---

## Phase 3 — Per-Row Actions Parity & Inline Reverse (High) — ✅ DONE

> Brings the index page's per-row action set to Legacy parity (View / Edit / Cancel / Receive / Call-it-a-day) and adds inline payment reversal inside the receive modal.

> **Implemented:** 2 new Blade components (`<x-erp.action-group>`, `<x-erp.reverse-payment-button>`). DataTable actions column refactored to the action-group pattern — ≤3 inline icon buttons (View + Edit + Receive) + an overflow ⋯ dropdown (Call-it-a-day / Print / Cancel) so rows stay narrow; mobile cards now also use an overflow (Edit / Call-it-a-day / Print / Cancel) bringing Cancel to mobile for the first time. Inline payment reversal in the receive modal: each existing payment row gets a role-gated (accountant/manager/admin/superadmin) "Reverse" button → SweetAlert2 textarea reason prompt (min 5 chars, red confirm) → AJAX POST to `admin.customer-payments.cancel` → on success the modal body is re-fetched (reversed payment vanishes, balance goes back up) + DataTable reloads + `salesToday:paymentRecorded` event fired with `{reversedPaymentId}`. `CustomerPaymentController::cancel` now returns JSON for AJAX requests (mirrors the `store` pattern) so the inline flow has no page redirect. Tailwind CSS rebuilt — all new red-variant + `w-full`/`sm:w-auto` classes confirmed generated.

### Screens to improve
- `resources/views/admin/sales-invoices/index.blade.php` — DataTable actions column render function.
- `resources/views/admin/sales-invoices/_receive_modal_body.blade.php` — add a "Reverse" button per existing payment (role-gated).

### Components to build
1. **`<x-erp.action-group>`** — a compact horizontal group of `<x-erp.action-button>`s with an optional `<x-erp.action-overflow>` for overflow. Used in the DataTable actions column.
2. **`<x-erp.reverse-payment-button>`** — a small danger-outline button (only rendered for accountant/manager/admin via `@can`) that opens a SweetAlert2 reason prompt and AJAX-POSTs to `admin.customer-payments.cancel`.

### Tailwind implementation approach
- **Action group:** `inline-flex items-center gap-1` container. Renders up to 3 inline `<x-erp.action-button>`s; the rest go into `<x-erp.action-overflow>`.
- **Reverse button:** `inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition-colors`. Icon: `<x-erp.icon name="rotate-ccw" class="size-3" />`. Label: "Reverse".
- **SweetAlert2 reason prompt:** reuse the existing SweetAlert2 (already loaded). `input: 'textarea', inputValidator: (v) => !v || v.length < 5 ? 'Reason must be at least 5 characters' : null`. Confirm button: `confirmButtonColor: '#dc2626'` (red). On confirm: `fetch('{{ route("admin.customer-payments.cancel", ":id") }}', { method:'POST', body: formData })` → on success, re-fetch the modal body + fire `salesToday:paymentRecorded` with `{ reversedPaymentId }`.

### Responsive considerations
- Action group: on desktop, show View + Edit + Receive inline + overflow (Call-it-a-day / Cancel). On mobile cards, show View + Receive inline + overflow (Edit / Call-it-a-day / Cancel).
- Reverse button: full-width in the mobile modal's payment list (`w-full`), icon-left.

### Accessibility considerations
- Every action button: `aria-label` with the invoice code (e.g., `aria-label="Edit invoice INV-2025-00001"`).
- Reverse button: `aria-label="Reverse payment {{ $payment_code }}"`. The SweetAlert2 textarea: ensure `aria-label="Reason for reversal (minimum 5 characters)"`.
- After reversal, announce "Payment {code} reversed" via the `aria-live` region. Focus returns to the next payment row or the modal close button.

### Expected outcome
- Draft invoices can be edited or cancelled directly from the index table (no navigation).
- Accountants reverse a payment inside the receive modal (no navigation).
- The per-row action set matches Legacy (View / Edit / Cancel / Receive / Call-it-a-day).
- The action group is compact (≤3 inline buttons + overflow) so the table row stays narrow.

---

## Phase 4 — Premium Polish: Sticky Header, Due Highlight, Empty State, Branch Color (Medium) — ✅ DONE

> Pure UI delight that makes the screen feel premium and modern without changing workflow.

> **Implemented:**
> - **Sticky header** — DataTables `dom` wraps only the table (`t`) in a new `.rc-table-scroll` container with `max-height:28rem; overflow-y:auto;`; `thead th` gets `position:sticky; top:0; z-10;` with an opaque `amber-50/96` background + `backdrop-blur(4px)` + amber-300 bottom rule. Length-menu / pagination / info stay OUTSIDE the scroll container so they remain visible.
> - **Due highlight** — `due_amount` column render now emits `.rc-due-pill` pills: red (outstanding `৳amount`) / green (`✓ Paid`). Meaning is in the text + `aria-label`, not color alone.
> - **Empty state** — two `<x-erp.empty-state>` instances (reused, not rebuilt) toggled by `updateEmptyState()` in `drawCallback`: `recordsTotal===0` → "You're all caught up!" with **Create your first invoice** CTA; `recordsTotal>0 && recordsDisplay===0` → "No invoices match your filters" with **Clear all filters** CTA (wired to the Phase 2 `clearAllFilters()`). `role="status"` on both. The previous duplicate inline mobile empty-state was **removed** (lean — one shared empty-state for desktop + mobile).
> - **Branch color** — `branch_code` added to the DataTable row payload; a `BRANCH_COLORS` JS map is emitted from `config/branches.php` and a `branchPillHtml()` helper renders an inline-styled `.rc-branch-pill` tinted with each branch's hex (bg `hex+15`, text `hex`, border `hex+33`). On mobile only the code shows (name hidden via media query) to save space. The hero branch pill was **intentionally skipped** as redundant with the topbar branch indicator (per the "remove unnecessary elements" directive).
> - **Live counter** — the hero subtitle's static `Sales Invoices — {title}` text was **replaced** by `<span id="heroInvoiceCount">N</span> invoices on your collection list`, updated live in `drawCallback` via `api.page.info().recordsDisplay`. `aria-live="polite"`. The planned `<x-erp.live-counter>` component was **not** built — an inline span is sufficient (no unnecessary component).

### Screens to improve
- `resources/views/admin/sales-invoices/index.blade.php` — DataTable header, due column, empty state, branch cell.
- `resources/views/components/erp/stat-card.blade.php` — optionally accept a `progress` slot for the live counter.

### Components to build
1. **`<x-erp.empty-state>`** — a friendly empty-state component (illustration SVG + headline + subtext + optional CTA button). Used when the DataTable has 0 rows.
2. **`<x-erp.branch-pill>`** — a small pill showing the branch code + name, tinted with `App\Support\BranchColor::hex($branchCode)`. Used in the hero + the DataTable branch cell.
3. **`<x-erp.live-counter>`** — a stat-card variant that updates live via JS (for the "N invoices on your list" hero counter).

### Tailwind implementation approach
- **Sticky DataTable header:** add `position: sticky; top: 0;` to `thead th` via a Tailwind arbitrary variant `[position:sticky][top:0]` or a small custom CSS rule in `rc-erp.css`. Background must be opaque (`bg-amber-50/95 backdrop-blur-sm`) so rows don't show through. Add `z-10`.
- **Due column highlight:** in the DataTables `columnDefs` render for `due_amount`: if `> 0.01`, wrap in `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-semibold">৳{amount}</span>`; if `≤ 0.01`, wrap in `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700 font-semibold">✓ Paid</span>`.
- **Empty state:** centered column `flex flex-col items-center justify-center py-16 gap-3`. Illustration: a Lucide-style SVG (e.g., `inbox` or `party-popper` when all-called) at `size-20 text-amber-300`. Headline: `text-lg font-semibold text-gray-700`. Subtext: `text-sm text-gray-500`. CTA: `<x-erp.primary-button>` linking to `admin.sales.cart` ("Create your first invoice") — only when the list is empty due to no invoices (not due to filters).
- **Branch pill:** `inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium border` where the bg/text/border colors come from `BranchColor::hex($code)` with opacity variants (`bg-[color]/10 text-[color] border-[color]/30`). Use inline `style` for the dynamic color since Tailwind can't generate arbitrary branch colors at build time.
- **Live counter:** the hero subtitle "X invoices on your collection list" where X is a `<span id="heroInvoiceCount">` updated in the DataTables `drawCallback` via `page.info().recordsDisplay`.

### Responsive considerations
- Empty state: illustration scales down on mobile (`size-16` on `<768px`, `size-20` on ≥768px).
- Branch pill: on mobile, show only the branch code (not the name) to save space — `truncate max-w-[60px]`.
- Sticky header: ensure it works inside the `max-h-96 overflow-y-auto` scroll container (sticky is relative to the scroll container, not the viewport — this is correct behavior).

### Accessibility considerations
- Empty state: `role="status"` so screen readers announce it when the table empties.
- Due highlight: don't rely on color alone — the "✓ Paid" text + "৳{amount}" text convey meaning without color. Add `aria-label="Due ৳{amount}"` / `aria-label="Fully paid"` on the span.
- Branch pill: `aria-label="Branch: {branch_name} ({branch_code})"`.
- Live counter: `aria-live="polite" aria-atomic="true"` so screen readers announce count changes.

### Expected outcome
- Scrolling a long list keeps column headers visible (no lost context).
- Outstanding dues visually pop (red pills); paid invoices celebrate (green ✓).
- An empty list shows a friendly "You're all caught up!" state instead of a blank table.
- Multi-branch admins see branch colors at a glance in the hero + table.
- The hero counter updates live as you call-it-a-day.

---

## Phase 5 — Accessibility & Keyboard Navigation (Medium) — ✅ DONE

> Makes the screen fully keyboard-navigable + screen-reader-friendly + WCAG AA compliant.

> **Implemented:**
> - **`<x-erp.sr-status>` component** — created a reusable visually-hidden `role="status" aria-live="polite" aria-atomic="true"` region. Replaced the inline `#srStatus` div on the index page with `<x-erp.sr-status id="srStatus" />`. The existing `announceSR(msg)` helper writes to it unchanged. (Phase 1 had already added the inline region + helper; Phase 5 just component-ised it for reuse.)
> - **Keyboard shortcut layer** — inline JS module bound on `document` (desktop only — disabled via `window.matchMedia('(pointer: coarse)')` and `(max-width: 767.98px)`). Keys: `j`/`k` move row focus down/up (amber-100 tint + amber-500 inset ring + `tabindex=0` + `scrollIntoView`), `r` receive payment, `c` call it a day, `e` edit draft, `/` focus smart-search, `Esc` clear row focus + close stray dropdowns. All handlers bail out when focus is in an input/textarea/select/contenteditable, when a modifier (Ctrl/Cmd/Alt) is held, or when the receive modal is open. `draw.dt` clears focus (DataTables rebuilds `<tbody>`).
> - **Modal focus management** — `.btn-receive-payment` click stores the trigger button in `$receiveTriggerBtn`; once the AJAX body loads, focus moves to `#srpAmount` (keyboard users start typing immediately); `hidden.bs.modal` restores focus to the trigger (WCAG 2.4.3). Bootstrap traps focus inside the modal while open by default.
> - **Reduced motion** — global `@media (prefers-reduced-motion: reduce)` guard disables transitions/animations on `.status-chip`, `.rc-action-btn`, `.rc-due-pill`, `.rc-branch-pill`, `#invoiceTable thead th`, `.sales-invoice-card`, and forces `scroll-behavior: auto` on the sticky container. (The bulk-bar slide already had its own guard from Phase 1.)
> - **Color contrast audit (WCAG AA 4.5:1, white text on chip active background)** — measured each chip's active color and darkened the three that failed:
>   - amber `#d97706` (3.2:1 ❌) → `#b45309` amber-700 (5.0:1 ✅) — draft chip
>   - green `#16a34a` (3.3:1 ❌) → `#15803d` green-700 (5.1:1 ✅) — confirmed chip
>   - cyan `#0891b2` (3.7:1 ❌) → `#0e7490` cyan-700 (5.4:1 ✅) — pending-godown scope chip
>   - The remaining colors (indigo `#4f46e5`, red `#dc2626`, slate `#64748b`, dark-red `#b91c1c`, violet `#7c3aed`) already pass at 4.7–7.4:1.
> - **Keyboard-hint badge** — a small `j k navigate · / search` badge in the Invoices card header (`<x-slot:actions>`), shown only when the shortcut layer is enabled. Click/Enter/Space opens a SweetAlert2 cheatsheet listing all shortcuts.

### Screens to improve
- `resources/views/admin/sales-invoices/index.blade.php` — add keyboard shortcuts, focus management, ARIA live regions.
- `resources/views/components/erp/*.blade.php` — audit all components for ARIA.

### Components to build
1. **`<x-erp.sr-status>`** — a visually-hidden (`sr-only`) `aria-live="polite"` status region for screen-reader announcements.
2. **Keyboard-shortcut layer** — a JS module (inline `@push('scripts')` or `resources/js/sales-invoices-shortcuts.js`) that binds `j`/`k`/`r`/`c`/`/`/`Esc` handlers when the index page is active.

### Tailwind implementation approach
- **`sr-status`:** `<div class="sr-only" aria-live="polite" aria-atomic="true" id="srStatus"></div>`. JS helper `window.announceSR = (msg) => { document.getElementById('srStatus').textContent = msg; }`.
- **Keyboard shortcuts:** bind on `document` but ignore when focus is in an input/textarea/select (`if (e.target.tagName.match(/INPUT|TEXTAREA|SELECT/)) return;`). Keys:
  - `j` / `k` — move row focus down/up. Apply `bg-amber-100` to the focused `<tr>` + set `tabindex="0"` + `.focus()`.
  - `r` — trigger "Receive payment" on the focused row.
  - `c` — trigger "Call it a day" on the focused row.
  - `e` — trigger "Edit" on the focused row (draft only).
  - `/` — focus the smart search input.
  - `Esc` — clear row focus / close any open dropdown.
- **Focus management in modal:** on modal open, focus the amount input. On modal close, restore focus to the triggering "Receive payment" button. Trap focus inside the modal (Bootstrap's modal does this by default — verify).
- **Reduced motion:** wrap transitions in `@media (prefers-reduced-motion: no-preference)` in `rc-erp.css`. Add `motion-reduce:transition-none` Tailwind variant to animated elements.
- **Color contrast audit:** verify all chip active-state colors (indigo `#4f46e5`, red `#dc2626`, amber `#d97706`, green `#16a34a`, slate `#64748b`, dark-red `#b91c1c`, cyan `#0891b2`, violet `#7c3aed`) on white backgrounds meet WCAG AA 4.5:1. Darken any that fail (e.g., amber `#d97706` on white = 4.0:1 → darken to `#b45309` = 5.4:1).

### Responsive considerations
- Keyboard shortcuts are desktop-only (mobile users don't have physical keyboards). Detect `pointer: coarse` via `@media (pointer: coarse)` and disable the shortcut layer to avoid accidental triggers.
- On mobile, ensure all actions are reachable via tap (they are, via the per-row buttons + overflow).

### Accessibility considerations
- This entire phase IS the accessibility consideration.
- Run a Lighthouse accessibility audit at the end; target score ≥95.
- Test with a screen reader (NVDA on Windows / VoiceOver on macOS) — verify the table is announced as a data table with column headers, row count is announced on change, and modal open/close is announced.

### Expected outcome
- Power users navigate the list entirely via keyboard (`j`/`k` to move, `r` to receive, `c` to call-it-a-day, `/` to search).
- Screen reader users get live announcements of filter changes, selection counts, and table redraws.
- All interactive elements are keyboard-reachable + operable.
- Color contrast meets WCAG AA.
- Reduced-motion preference is respected.

---

## Phase 6 — Responsive & Mobile Polish (Low-Medium)

> Optimizes the mobile experience: sidebar breakpoint, single-column modal, collapsible filters, mobile action sheet.

### Screens to improve
- `resources/views/components/layouts/erp.blade.php` — sidebar breakpoint.
- `resources/views/admin/sales-invoices/index.blade.php` — filter form collapse, mobile card actions.
- `resources/views/admin/sales-invoices/_receive_modal_body.blade.php` — single-column mobile layout.

### Components to build
1. **`<x-erp.collapsible-card>`** — a `<x-erp.left-accent-card>` variant with a collapse toggle (used for the filter form on mobile).
2. **`<x-erp.mobile-action-sheet>`** — a bottom-sheet modal (`fixed bottom-0 inset-x-0 rounded-t-2xl`) used as the mobile alternative to the `<x-erp.action-overflow>` dropdown.
3. **Mobile receive-modal layout** — a Tailwind responsive variant of `_receive_modal_body.blade.php` that stacks vertically on `<768px`.

### Tailwind implementation approach
- **Sidebar breakpoint:** in `erp.blade.php`, change `<nav id="sidebar" class="sidebar col-lg-2 col-md-3 d-none d-lg-block">` to `d-none d-md-block` (show on ≥768px instead of ≥1024px). Adjust `<main class="col-lg-10 col-md-9 ...">` to `col-md-10` so the main column expands at md. Test on a 768px tablet to confirm the sidebar isn't too cramped — if it is, keep `lg` but add an off-canvas drawer for md.
- **Collapsible filter card:** on `<768px`, the filter card collapses by default (`<details>` element or a JS toggle). Header: "Filters" + a chevron. On ≥768px, always expanded. Use Tailwind `md:open` or a `data-expanded` attribute + `hidden md:block` on the body.
- **Mobile action sheet:** `fixed bottom-0 inset-x-0 bg-white rounded-t-2xl shadow-2xl p-4 max-h-[80vh] overflow-y-auto` + a drag-handle bar at top (`w-10 h-1 bg-gray-300 rounded-full mx-auto mb-3`). Each action: `w-full text-left px-4 py-3 rounded-lg hover:bg-amber-50 flex items-center gap-3` + icon + label. Close on backdrop tap or `Esc`.
- **Mobile receive modal:** on `<768px`, the 3 stat tiles stack (`grid grid-cols-1 md:grid-cols-3 gap-2`). The amount input + quick chips go full-width. The payment-mode radios become a vertical list (`flex flex-col md:flex-row gap-2`). The "Record payment" button sticks to the bottom (`sticky bottom-0 bg-white border-t py-3`).

### Responsive considerations
- This entire phase IS the responsive consideration.
- Test on real devices: 360px (small Android), 390px (iPhone 12+), 768px (iPad portrait), 1024px (iPad landscape / small laptop).
- Ensure no horizontal scroll on any breakpoint (`overflow-x-hidden` on the main wrapper if needed).

### Accessibility considerations
- Collapsible card: use `<details>` / `<summary>` for native toggle semantics, or `aria-expanded` + `aria-controls` on a button.
- Mobile action sheet: `role="dialog" aria-modal="true" aria-label="Invoice actions"`. Trap focus inside. Restore focus on close.
- Mobile modal: ensure the sticky "Record payment" button has enough contrast against the white background (`bg-amber-500 text-white`).

### Expected outcome
- Tablet users (768-1023px) get the sidebar.
- Mobile users get a clean, single-column receive modal with a sticky submit button.
- Filters collapse on mobile to save vertical space.
- Per-row actions on mobile open a thumb-friendly bottom sheet.

---

## Cross-Phase: Design System Hygiene

> Applies to every phase. Keeps the Tailwind build clean + the component library coherent.

1. **Every new component** goes in `resources/views/components/erp/` and follows the naming convention (`<x-erp.kebab-case>`).
2. **Every new Tailwind utility class** used in views must be present in `public/assets/css/rc-erp.css`. After adding classes, run `npm run build:css` to rebuild. Verify with a grep for the class name in the built CSS.
3. **No inline `style="..."`** except for dynamic colors (e.g., `BranchColor::hex()` output). All static styling via Tailwind utilities.
4. **No Bootstrap classes inside `<x-erp.*>` components** (the coexistence rule from `rc-erp.css`). Bootstrap classes are fine in the layout shell + legacy views, but new components are Tailwind-only.
5. **Bilingual labels** (EN / বাংলা) on every user-facing string, matching the existing pattern (`label` + `label-bn` props on components).
6. **Accent colors** via `App\Support\Accents::get($accent)` — never hardcode `text-amber-500` in a component; use the accent system so components are reusable.
7. **Icons** via `<x-erp.icon name="...">` — add new icon cases to `components/erp/icon.blade.php` as needed (Lucide-style SVG, `viewBox="0 0 24 24"`, `stroke=currentColor`).

---

## Phase Summary (one-page view)

| Phase | Closes gaps | Impact | New components | Estimated effort | Status |
|---|---|---|---|---|---|
| 1 — In-Context Payment & Call-It-A-Day | F-2, F-33, F-42, F-43 (UI layer) | Critical | bulk-action-bar, row-checkbox, action-button, action-overflow | Large (view + JS + modal AJAX) | ✅ DONE |
| 2 — Filter UX | F-31, F-32, F-41 | High | date-presets, active-filter-bar, filter-tag | Medium (JS + view) | ⬜ Pending |
| 3 — Per-Row Actions & Inline Reverse | F-39, F-42 (remaining), F-17 UX | High | action-group, reverse-payment-button | Medium (view + AJAX) | ⬜ Pending |
| 4 — Premium Polish | (new delight) | Medium | empty-state, branch-pill, live-counter | Medium (view + CSS) | ⬜ Pending |
| 5 — Accessibility & Keyboard | (a11y) | Medium | sr-status, shortcuts module | Medium (JS + ARIA audit) | ⬜ Pending |
| 6 — Responsive & Mobile | (responsive) | Low-Medium | collapsible-card, mobile-action-sheet, mobile modal layout | Medium (view + CSS) | ⬜ Pending |

**Total: 6 phases, ~14 new `<x-erp.*>` components, closing all UI gaps + adding premium/a11y/responsive improvements.**

---

## Final Vision

When all 6 phases ship, the Laravel Today Invoice screen will be:

- **Premium** — sticky headers, color-coded dues, branch-colored pills, empty-state celebrations, live counters, gradient hero, journey stepper.
- **Modern** — Tailwind v4 design system, `<x-erp.*>` component library, shadcn-style cards, backdrop-blur topbar, rounded-xl surfaces.
- **Professional** — bilingual EN/বাংলá throughout, role-gated actions, audit-logged, idempotent payments, DB-level allocation constraints.
- **Mobile responsive** — sidebar at md, single-column modal, collapsible filters, bottom-sheet actions, mobile cards with status colors.
- **User-friendly** — one-click presets, persistent filters, active-filter bar, keyboard shortcuts (j/k/r/c//), inline payment + reversal, auto call-it-a-day, print-receipt prompt, bulk actions.
- **Accessible** — WCAG AA contrast, ARIA labels on icon buttons, live announcements, keyboard-navigable, reduced-motion respect, screen-reader-tested.

**The workflow is preserved 1:1 with Legacy. The UX exceeds it.**

---

## Phase 1 — Implementation Log

> **Status:** ✅ Complete · **Commit:** Phase 1 (UI/UX) — in-context payment + call-it-a-day

### New `<x-erp.*>` components built (4)

| Component | File | Purpose |
|---|---|---|
| `<x-erp.bulk-action-bar>` | `resources/views/components/erp/bulk-action-bar.blade.php` | Sticky bar above the DataTable; shows "N selected" + slot for action buttons. `role="region"` + `aria-live="polite"`. Toggled `hidden` by JS based on selection. |
| `<x-erp.row-checkbox>` | `resources/views/components/erp/row-checkbox.blade.php` | Styled checkbox (`size-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500`). Supports per-row + select-all variants. Canonical class source — JS replicates the same classes in DataTables cells. |
| `<x-erp.action-button>` | `resources/views/components/erp/action-button.blade.php` | Compact `size-8` icon button with 6 variants (view/edit/cancel/receive/call-it-a-day/print) — each with its own hover colour. `aria-label` + `title` on every button. |
| `<x-erp.action-overflow>` | `resources/views/components/erp/action-overflow.blade.php` | "⋯" dropdown (reuses Bootstrap `.dropdown`, menu styled with Tailwind). For less-common per-row actions. |

### Icons added to `<x-erp.icon>`

- `check` — checkmark (bulk bar badge)
- `more-horizontal` — three dots (overflow trigger)
- `ban` — cancel icon (overflow menu)

### View changes — `resources/views/admin/sales-invoices/index.blade.php`

1. **Bulk action bar** (`#invoiceBulkBar`) added above the DataTable — `hidden` by default, shown when ≥1 row checked. Contains "Call It A Day" (orange) + "Clear" buttons. Slide-in animation, `prefers-reduced-motion` respected.
2. **Screen-reader live region** (`#srStatus`) — `sr-only` + `aria-live="polite"`; announced on selection, payment, call-it-a-day, cancel.
3. **Checkbox column** (col 0) added to the DataTable — select-all header checkbox with `indeterminate` state + per-row checkboxes. Column is `orderable: false` + `searchable: false`.
4. **Actions column** rewritten — full Legacy action set: View / Edit (draft) / Receive / Call-it-a-day / Print inline + Cancel in overflow dropdown. All buttons use `.rc-action-btn` (compact icon buttons with per-variant hover colours). Every button has `aria-label` with the invoice code.
5. **Mobile cards** updated — added Call-it-a-day ("Done") + Print buttons alongside existing View + Receive.
6. **AJAX payment submit** — `doSubmit()` converted from native `$form[0].submit()` to `$.ajax()` with `X-Requested-With: XMLHttpRequest`. On success: modal closes → SweetAlert2 success dialog with "Print receipt" button → (if fully paid) follow-up "Call it a day?" prompt → table redraws. On 422: validation errors shown. On other errors: error dialog.
7. **Call-It-A-Day JS** — `confirmCallItADay(ids, title, text)` → SweetAlert2 question → `callItADay(ids)` AJAX POST → success toast → table redraw + summary refresh. Wired for both per-row (`.btn-call-it-a-day`) + bulk (`#bulkCallItADay`).
8. **Cancel JS** — `cancelInvoice(id, code)` → SweetAlert2 with required reason textarea (min 5 chars) → AJAX POST → success toast → redraw.
9. **Checkbox wiring** — delegated `change` handlers for per-row + select-all; `updateBulkBar()` manages count, bar visibility, select-all `indeterminate` state, bulk-button disabled state. `drawCallback` resets selection on every table redraw (DataTables rebuilds the tbody).

### Controller changes

**`CustomerPaymentController::store`** — added AJAX JSON branch:
- On success: returns `{status, payment_id, payment_code, invoice_id, is_fully_paid, balance_after, message, print_receipt_url}` when `expectsJson() || ajax()`.
- On idempotency replay: returns the same JSON shape with `idempotent_replay: true`.
- On exception: returns `{status:'error', message}` with 400.
- Validation errors (422) are auto-handled by Laravel's `validate()` for JSON requests.
- Non-AJAX path unchanged (still redirects to `customer-payments.show`).

**`SalesInvoiceController::cancel`** — added AJAX JSON branch:
- On success: returns `{status, invoice_id, invoice_code, message}`.
- On exception: returns `{status:'error', message}` with 400.
- Non-AJAX path unchanged.

**`SalesInvoiceController::datatable`** — added per-row action fields:
- `show_edit`, `show_cancel`, `show_call_a_day`, `show_print` (visibility booleans).
- `edit_url`, `print_invoice_url` (per-row route URLs, `null` when not applicable).
- `call_a_day` (bool — so the client knows the current flag state).

**`SalesInvoiceController::buildInvoiceFilterQuery`** — `today` scope now filters `call_a_day = false` so called-it-a-day invoices vanish from the daily collection list on redraw (G-10).

**`SalesInvoiceController::summary`** — `countToday` now excludes `call_a_day` invoices so the "Today" chip count matches the filtered table.

### CSS changes

- `public/assets/css/rc-erp.css` rebuilt via `bun run build:css` (Tailwind v4 CLI). New utility classes from the 4 components (`size-5`, `bg-orange-500`, `hover:bg-orange-600`, etc.) are now in the build.
- Page-scoped `.rc-action-btn` family + `.sr-only` fallback + `#invoiceBulkBar` slide-in animation added to the index page's `@push('css')` block (defined inline because DataTables renders the buttons client-side).

### Behaviour delivered (Expected outcome → verified)

- ✅ Recording a payment keeps the user on the index page (no redirect).
- ✅ A SweetAlert2 success dialog offers "Print receipt" → opens in new tab.
- ✅ If fully paid, a follow-up SweetAlert2 prompts "Call it a day?" → confirm → invoice vanishes from list.
- ✅ Users can call-it-a-day per-row (1 click + confirm) or in bulk (check N → bulk bar → confirm).
- ✅ Per-row actions match Legacy: View / Edit (draft) / Cancel (draft) / Receive / Call-it-a-day / Print.
- ✅ The daily-collection workflow matches Legacy speed (~15s per payment) and exceeds it (bulk actions, no navigation).
- ✅ Screen-reader announcements on selection / payment / call-it-a-day / cancel.
- ✅ `aria-label` on every icon-only button (includes the invoice code).

---

**End of UI/UX implementation plan.** Cross-reference: `today-invoice-uiux-analysis.md` (§3-7) for the gap analysis this plan closes; `today-invoice-business-implementation-plan.md` for the business-logic roadmap (Phases 1-3 here are the view/JS layer of business Phases 1-3).
