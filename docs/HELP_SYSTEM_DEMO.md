# Help System — Handoff Demo Walkthrough

> **What this is:** a guided tour of the RC_ERP_v2 in-app help system, covering both doors
> across **Sales + Accounting + Inventory** (the three modules the implementation plan §10
> requires for the handoff demo). Read this alongside the running app, or as a standalone
> narrative of what users will see.
>
> **Status:** Phases 1–10 complete. 215/215 menus have authored Bangla content. 0 QA errors.
> **How to run the app:** see §6 (Launch + preview) below. The help system ships with the
> standard ERP — no separate build step.

---

## 0. The 30-second pitch

Every authenticated ERP page now has **two ways to get help**:

1. A floating **`?` button** (bottom-right) that opens a right-side panel showing **Bangla
   help for the exact page you're on** — what it does, who it's for, what changes, what to
   watch out for, and related pages.
2. A fixed **`🧭 My Creative Code Guide` pill** (bottom-centre) that opens a bottom sheet of
   **8 colourful module cards** — click any module to browse its intro + every menu inside it,
   with full Bangla explanations + diagrams + cross-links.

Both are file-based (no DB, no admin panel), opt-in per layout (one `@include` line), and
revertible with a single `git revert`. They degrade gracefully (unmapped pages show a
friendly "request help" card with a mailto link, never a 404).

---

## 1. Demo A — Door 1: contextual help (Sales → Invoices)

**Goal:** show that the `?` button always knows what page you're on.

### Step 1 — Land on the Sales Invoices page
```
URL: /admin/sales-invoices
Login as: any authenticated user (salesman / manager / admin / superadmin)
```
The page loads normally. In the bottom-right corner, above the footer pill, a **floating
`?` button** pulses gently (slate-grey circle, white question mark). It's small enough to
not cover important UI (44px on desktop, 40px on mobile, positioned `bottom: 70px`).

### Step 2 — Click the `?` button
A **right-side offcanvas** slides in (spring-eased, 280ms). Its header is a gradient tinted
to the **Sales module's emerald colour**, showing:

- **Title:** `সেলস ইনভয়েস` (Sales Invoice)
- **Breadcrumb:** `Sales` (clickable → module offcanvas)
- **Back bar** (if you navigated from Door 2)

The body renders the content from `resources/help/menus/sales/invoices.php`:

> **Summary (one Bangla sentence):**
> খদ্দেরকে পণ্য বিক্রি করে যে বিল তৈরি হয়, এটি সেই বিল। এখান থেকেই খদ্দেরের বকেয়া ও আপনার আয় শুরু হয়।
>
> **এই পেজে আপনি কী করতে পারেন:** (5 icon bullets)
> - ➕ নতুন ইনভয়েস তৈরি করা
> - 📋 আগের সব ইনভয়েস দেখা ও খুঁজা
> - 🖨️ ইনভয়েস প্রিন্ট বা কাস্টমারকে পাঠানো
> - ↩️ ভুল হলে রিটার্ন করা
> - ✅ কাস্টমারের পেমেন্ট এন্ট্রি করা
>
> **কাদের ডেটা পরিবর্তন করে:** (role chips)
> | কে | কী |
> |---|---|
> | খদ্দের | বকেয়া বাড়ে |
> | স্টক | পণ্য কমে যায় |
> | হিসাব | বিক্রয় আয় ও ভ্যাট লেজারে লেখা হয় |
>
> **⚠️ সাবধানতা:** (amber callout)
> - ইনভয়েস একবার ফাইনাল হলে সরাসরি এডিট করা যায় না — রিটার্ন দিতে হবে।
> - পর্যাপ্ত স্টক না থাকলে ইনভয়েস তৈরি হবে না।
>
> **সম্পর্কিত:** (clickable chips)
> `🛒 কার্ট` · `🚚 গোডাউন চালান` · `↩️ সেলস রিটার্ন` · `💵 কাস্টমার পেমেন্ট`
>
> *(optional Mermaid diagram: কার্ট → ইনভয়েস → চালান → ডেলিভারি → পেমেন্ট)*

### Step 3 — Click a "Related" chip (e.g. `↩️ সেলস রিটারন`)
The offcanvas body **fades out (120ms)**, the drawer's height holds steady (no collapse —
CLS-safe), then the new menu's content **fades in (200ms)**. The header re-tints to the
Sales emerald (same module). The breadcrumb updates. No page navigation — you stay on the
invoices page, just browsing help.

### Step 4 — Press `Esc` or click the backdrop
The offcanvas closes. **Focus returns to the `?` button** (accessibility: focus-return).

> **What just happened:** the help button resolved `/admin/sales-invoices` → route name
> `admin.sales-invoices.index` → `registry.php` Layer-1 match → menu key `sales.invoices` →
> loaded `menus/sales/invoices.php`. Total: one `fetch()` to `/help/menu/sales.invoices`.

---

## 2. Demo B — Door 2: browse the whole guide (Inventory → Accounting → Sales)

**Goal:** show that a user can browse help for any module, not just the current page.

### Step 1 — From any page, click the footer pill
The **`🧭 My Creative Code Guide` pill** sits fixed at the bottom-centre (glassmorphism:
`backdrop-blur(16px) saturate(180%)`). Clicking it opens a **bottom-up sheet** that rises
to ~60vh on desktop / 85vh on mobile (a true bottom drawer).

The sheet shows **8 colourful module cards** in a responsive grid:

| 🗄️ মাস্টার ডেটা (slate) | 📦 ইনভেন্টরি (amber) | 🚚 ক্রয় (sky) | 🛒 সেলস (emerald) |
|---|---|---|---|
| **🧮 হিসাব** (violet) | **💰 ফাইন্যান্স** (rose) | **📊 রিপোর্ট** (teal) | **⚙️ সিস্টেম** (indigo) |

Each card shows the module's Bangla title, English title, icon, tagline, and menu count.
A **search box** sits at the top ("মডিউল বা মেনু খুঁজুন…").

### Step 2 — Click the Inventory card (amber)
The bottom sheet closes; a **module offcanvas** slides in from the right, header tinted
amber. It shows:

- **Module intro:** "এই মডিউলে মালামালের পুরো হিসাব চলে — স্টক লেজার, ফিজিক্যাল কাউন্ট, ক্ষতি,
  ওয়্যারহাউস ট্রান্সফার।"
- **Menu list:** all 28 inventory menus as rows (Stock Ledger, Physical Count, Stock
  Adjustment, Warehouse Transfer, Damage, + their audit/print/show/checklist sub-pages).
  Each row has a colour-tinted icon + Bangla label.

### Step 3 — Click "ফিজিক্যাল কাউন্ট" (Physical Count)
The module offcanvas closes; the **menu offcanvas** opens with the full Bangla help card for
`inventory.stock-take`. Header tinted amber (Inventory's colour). Content includes:
- What a physical count is + when to do it.
- The count cycle (setup → count → reconcile → audit) with a Mermaid flow diagram.
- Cautions (e.g. "পেন্ডিং কাউন্ট থাকলে পিরিয়ড ক্লোজ হবে না").
- Related: `stock-adjustments`, `stock-take-checklist`, `stock-take-audit`.

### Step 4 — Use the back button / breadcrumb
The offcanvas's back bar lets you return to the Inventory module offcanvas, or jump to a
different module via the breadcrumb. You can also click **"প্রিন্ট করুন"** (Print) in the
actions bar to open a clean print view of this help card.

### Step 5 — Switch to Accounting
From the module offcanvas, the breadcrumb `Home` → click → reopens the bottom sheet. Click
the **Accounting card (violet)**. Same flow: module intro → 33 menus (Manual Journal, Money
Transfer, Bank Reconciliation, Period Close, Approvals, + sub-pages). Click `accounting.period-close`
to see its help — header re-tints to violet.

### Step 6 — Switch to Sales
Repeat for the **Sales card (emerald)** → 25 menus. The colour tinting follows you: each
module's drawer is visually distinct.

> **What just happened:** Door 2 is pure browse — no current-page dependency. The 8 module
> cards are server-rendered into the sheet on first page load (no fetch). Each module click
> fetches `/help/module/{key}` (intro + menu list); each menu click fetches
> `/help/menu/{key}` (full content). Three fetches total for a full Sales→Accounting→Inventory
> tour, all cached thereafter.

---

## 3. Demo C — The 5 interactive niceties (Phase 9)

### C.1 In-guide search
Open the Door 2 bottom sheet. Type in the search box: "রিকনসিল" (reconcile). The 8 module
cards filter live, AND a flat results list appears below showing every menu whose Bangla
title, English title, or tagline matches — across ALL modules (Bank Reconciliation, Stock
Adjustment Reconcile, Warehouse Transfer Reconcile, Branch Demand Reconcile, Intercompany
Reconciliation, etc.). Click any result → jumps straight to that menu's offcanvas. No
endpoint — pure client-side over a 215-menu search index emitted into `window.HELP_CONFIG`.

### C.2 Recently-viewed (★)
After you've opened ≥1 menu, a **★ button** appears beside the footer pill. Click it → a
popover shows your **last 5 viewed menus** (colour-tinted icons, clickable to re-open). A
"মুছুন" (clear) button wipes history. Stored in `localStorage` key `help:recent` — if
localStorage is unavailable (private mode / disabled), the ★ button silently stays hidden.
No console errors.

### C.3 Keyboard shortcuts
- Press **`?`** anywhere (no drawer open, not in an input) → opens Door 1 contextual help
  for the current page.
- Press **`Shift+G`** → opens the Door 2 module sheet.
Both are guarded against open drawers so they don't fight focus management.

### C.4 Empty-state with mailto
Visit a page that has no help mapped (or trigger the empty state). Instead of a blank card,
you see a **72px amber illustration** (feather icon) + a gradient **"অনুরোধ পাঠান"** (Send
Request) button. Clicking it opens your mail client with a pre-filled email: subject
"সাহায্য লেখার অনুরোধ: {menu_key}", body includes the menu key + module title. The
recipient comes from `config('app.help_support_email')` (default `support@example.com`).

### C.5 Print
Open any menu's offcanvas (with real content). An actions bar appears with a
**"প্রিন্ট করুন"** button (tinted to the module colour). Click → opens a new window with a
clean print stylesheet (720px max-width, tinted summary card, role chips, impacts table,
amber caution callout, footer with the menu key) → triggers `print()`. If pop-ups are
blocked, alerts a Bangla message. The print bar only shows for real content (not loading
or empty states), so you never print a spinner.

---

## 4. Demo D — Mobile + accessibility

### Responsive
On a 360px-wide phone:
- The `?` FAB shrinks to 40px and sits at `bottom: 70px` (above the footer pill).
- The footer pill spans full width, glassmorphic.
- The bottom sheet becomes a true **85vh bottom drawer** with 18px top corners.
- Right offcanvases fill 100% viewport height: fixed header + independently-scrolling body
  (flex column), with iOS momentum scrolling (`-webkit-overflow-scrolling: touch`).
- All tap targets ≥ 44px (WCAG 2.5.5).

### Accessibility
- **Focus trap:** open any offcanvas, press Tab — focus cycles within the drawer. Shift+Tab
  reverses. Close the drawer → focus returns to the trigger button.
- **Screen readers:** every offcanvas has `role="dialog" aria-modal="true"
  aria-labelledby`. The FAB + footer pill have `aria-haspopup` + `aria-expanded` +
  `aria-controls`.
- **Keyboard:** every interactive element has a 3px focus-visible ring (tinted to the
  module colour). `Esc` closes any drawer (Bootstrap 5 built-in). Backdrop click closes.
- **Reduced motion:** enable `prefers-reduced-motion` in your OS → all animations + transitions
  nullify (FAB pulse, card hovers, content-swap fades, popover pop-in). The JS also skips
  the fade-out path. Everything still works, just instantly.

---

## 5. What to verify (owner QA checklist)

When reviewing the handoff, confirm these across Sales + Accounting + Inventory:

- [ ] On `/admin/sales-invoices`, the `?` button opens help titled `সেলস ইনভয়েস` (not an
      empty state).
- [ ] On `/admin/manual-journals`, the `?` button opens `হিসাব`-tinted (violet) help for
      `accounting.manual-journals`.
- [ ] On `/admin/stock-take`, the `?` button opens `inventory.stock-take` with a Mermaid
      diagram (count cycle). If the CDN is blocked, the diagram hides but the rest renders.
- [ ] The footer pill opens the 8-card sheet. Each card is a different colour.
- [ ] Search for "জার্নাল" finds Manual Journal (Accounting) + Journal Entries (Reports).
- [ ] Open 3 menus → the ★ button appears → click it → 3 items in the popover → click one
      → reopens that menu.
- [ ] Press `?` → opens Door 1. Press `Shift+G` → opens Door 2.
- [ ] On mobile width (360px): FAB is 40px, sheet is 85vh drawer, offcanvas is full-height.
- [ ] Tab through an open offcanvas → focus stays inside. Esc → focus returns to trigger.
- [ ] Print a menu offcanvas → clean print view in a new window.
- [ ] Run `python3 docs/help-sweep/phase7_validate.py` → `TOTAL ERRORS: 0`.
- [ ] Run `python3 docs/help-sweep/phase6_sweep.py` → `COVERAGE: 215/215`, 0 gaps.

---

## 6. Launch + preview

The help system ships with the ERP — no separate build. To run locally:

```bash
cd laravel/
composer install              # if not already
php artisan key:generate      # if first run
php artisan serve --port=8000 # or use Docker (see docs/DOCKER_README.md)
```

Visit any authenticated page (e.g. `/admin/sales-invoices`). The `?` button + footer pill
appear automatically (they're gated on `auth()->check()` in the layout include).

**To verify content changes without a full runtime**, use the two static validators:

```bash
python3 docs/help-sweep/phase7_validate.py   # schema + cross-refs on all 215 files
python3 docs/help-sweep/phase6_sweep.py      # route-resolution sweep + coverage report
```

Both are pure Python (regex-based, no PHP runtime needed) and run in ~2 seconds.

---

## 7. Reverting

The entire help system is **opt-in via one line** in two layout files:

```blade
@include('partials.help-system')   // in layouts/admin.blade.php + layouts/app.blade.php
```

Remove those two lines (or `git revert <the help-system commits>`) and the help UI vanishes
with zero trace — no DB changes, no composer deps, no npm packages, no migrations. Content
files remain on disk but are unreachable. Re-adding the include brings everything back.

---

## 8. The numbers (Phase 10 final)

| Metric | Value |
|---|---|
| Modules | 8 |
| Primary menus (in `modules.php`) | 183 |
| Secondary content files (linked from Related) | 32 |
| Total authored Bangla content files | 215 |
| Route → menu_key mappings (`registry.php`) | 214 |
| Controller@action mappings (`action-registry.php`) | 214 + 59 wildcards |
| Curated page routes resolving to content | 215 / 215 (Layer 1) |
| Resource-expanded runtime routes resolving | 81 / 81 (Layer 1 or 3) |
| Schema validation errors | 0 |
| `help-system.css` (gzipped) | 7.8 KB (≤ 8 KB budget ✓) |
| `help.js` (gzipped) | 9.8 KB (over 4 KB §11.4 budget — justified by Phase 9 niceties) |
| Mermaid | lazy-loaded from CDN, only when a diagram block renders |
| New composer deps | 0 |
| New npm deps | 0 |
| DB migrations | 0 |
| Lines of help content (Bangla) | ~6,500 across 215 files |
| Phases shipped | 10 / 10 |

---

*Full architecture: `AI_CONTEXT/architecture/help-system.md`. Author how-to:
`docs/HELP_AUTHORING_GUIDE.md`. Original design + acceptance criteria:
`docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md`. Per-phase work logs: `docs/worklog.md`.*
