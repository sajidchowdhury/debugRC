# Phase 0 — Style Parity Audit (sales-pos.css port)

**Date**: 2026-07-22
**Goal**: Make `laravel/resources/views/admin/sales/cart.blade.php` look visually identical to `legacy/app/views/sales/create.php` by linking and adopting `laravel/public/assets/css/sales-pos.css`.

---

## 1. Question 1 answer — Is `sales-pos.css` linked?

**NO.** The Laravel cart blade does not load `sales-pos.css` (or any module-specific CSS file). Verified by `grep` — zero matches for `sales-pos.css` anywhere under `laravel/`.

The cart blade is currently styled by:
- Bootstrap 5 defaults (`bootstrap.min.css`)
- Global `custom.css` + `footer-dropup.css`
- A 148-line **inline** `<style>` block at lines 598–746 of `cart.blade.php` defining only the Laravel-specific extensions (R15 chips, R16 sticky bar, R17 mobile swipe).

The legacy CSS file `sales-pos.css` (960 lines) **is present** at `laravel/public/assets/css/sales-pos.css` — copied from legacy but never linked.

---

## 2. Question 2 answer — Conversion plan summary

See `STYLE_PARITY_PLAN.md` (sibling file) for the full 8-phase plan. This audit document is Phase 0; subsequent phases will execute the plan.

---

## 3. Source files audited

| File | Lines | Role |
|---|---|---|
| `legacy/app/views/sales/create.php` | 186 | **Gold reference** — HTML structure that the Laravel cart must match |
| `legacy/public/assets/css/sales-pos.css` | 960 | **Gold reference** — full legacy stylesheet (already copied to `laravel/public/assets/css/sales-pos.css`) |
| `laravel/resources/views/admin/sales/cart.blade.php` | 3005 | **Target** — current Laravel cart blade |
| `laravel/resources/views/admin/sales/cart.blade.php` lines 598–746 | 148 | Inline `<style>` block — Laravel-only style extensions |
| `laravel/resources/views/layouts/admin.blade.php` | 231 | Layout — loads global Bootstrap/Select2/DataTables but no module CSS |

---

## 4. Legacy `sales/create.php` HTML structure (the target)

```
<div id="sales-create-app" class="sales-create-app">       ← wrapper sets CSS vars
  <header class="sales-create-header">                     ← indigo gradient header
    <h1 class="sales-create-title">New Sale</h1>
    <p class="sales-create-sub">…</p>
    <a class="btn btn-light btn-sm sales-header-btn">Today</a>
  </header>

  <form id="kt_form" class="sales-create-form">
    <div class="row g-3">
      <div class="col-12 col-xl-4">                        ← LEFT: Customer panel
        <section class="sales-panel">
          <div class="sales-panel-head">…Customer…</div>
          <div class="sales-panel-body">
            <label id="customerSearchLabel">…</label>
            <div class="sales-customer-picker">
              <input id="customerSearch" class="sales-search-input">
              <div id="customerSuggestions" class="sales-suggest-list"></div>
              <button id="btnChangeCustomer" class="sales-change-customer">Change</button>
            </div>
            <div id="customerRecents" class="sales-recents"></div>
            <div class="sales-meta-grid">…Branch/Date/SalesBy/SalesPerson/Narration…</div>
          </div>
          <div id="customerDetailsPanel" class="sales-customer-due">  ← dark credit box
            Credit limit / Current due / Balance left
          </div>
        </section>
      </div>

      <div class="col-12 col-xl-8">                        ← RIGHT: Product entry panel
        <section class="sales-panel sales-panel-product">
          <div class="sales-panel-head">…Add products…</div>
          <div class="sales-panel-body">
            <input id="productSearch" class="sales-search-input">
            <div id="productSuggestions" class="sales-suggest-list"></div>
            <div id="BranchStock" class="sales-stock-banner">…teal stock bar…</div>
            <div id="priceRangePanel" class="sales-price-band">…price slider…</div>
            <div class="sales-entry-toolbar">
              <input id="sales_rate" class="sales-entry-input">
              <input id="quantity"  class="sales-entry-input">
              <button id="addToCartBtn" class="sales-add-btn">Add</button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </form>

  <section class="sales-cart-dock mt-3">                   ← Multi-cart tabs dock
    <div class="sales-cart-dock-head">…</div>
    <div class="sales-cart-tabs-wrap">
      <ul id="draft-tabs" class="nav sales-cart-tabs"></ul>
    </div>
    <div id="draft-tab-content" class="sales-tab-panels">
      <div id="emptyCartHint" class="sales-empty-cart">…</div>
    </div>
  </section>
</div>

<div class="sales-pos-sticky-bar" id="posStickyBar">        ← sticky bottom finalize bar
  <div class="sticky-summary">…</div>
  <button id="posStickyFinalize" class="btn btn-success btn-finalize">Finalize</button>
</div>
```

Key observations:
- Two-column layout: **Customer = 4/12, Product = 8/12** (not Laravel's 8/4)
- Customer + Product panels sit **side-by-side**, not stacked
- Cart table + summary live in the **dock below** (full width), not in the right aside
- No right sidebar at all on the create page — the right aside is a Laravel addition
- Header text is "New Sale" (Laravel says "Sales Cart")
- Header has only a single "Today" button (Laravel has "Customers" + "Products")

---

## 5. Laravel `cart.blade.php` HTML structure (current)

```
<div class="container-fluid py-2" id="salesCartApp">       ← NO sales-create-app wrapper

  <header style="background:linear-gradient(135deg,#7c3aed,#4f46e5)">  ← inline-styled hero
    <h1><i class="fas fa-cart-shopping"></i>Sales Cart</h1>
    <a>Customers</a>  <a>Products</a>                      ← two buttons (legacy has one)
  </header>

  <div id="draftTabsCard" class="card border-0 shadow-sm"> ← multi-cart dock (Bootstrap card)
    <ul id="draftTabs" class="nav nav-pills">…</ul>        ← Bootstrap pills (legacy uses .sales-cart-tabs)
  </div>

  <div class="card border-0 shadow-sm mb-3">               ← Customer selector (Bootstrap card)
    <select id="customerSelect" class="form-select select2"> ← Select2 (legacy uses text input)
    <div id="customerRecents" class="sales-recents"></div>
    <div id="customerDetailsPanel">  ← R14 live credit snapshot (Bootstrap-styled, not .sales-customer-due)
  </div>

  <div id="cartEmptyState" class="card border-0 shadow-sm">…empty state…</div>

  <div id="workspace" class="row g-3">
    <div class="col-12 col-lg-8">                          ← LEFT (main) — wider
      <div class="card border-0 shadow-sm">                ← Add Product (Bootstrap card)
        <select id="addProduct" class="form-select select2">  ← Select2 (legacy uses text input)
        <input id="addQty"> <input id="addRate">
        <button id="btnAddToCart" class="btn btn-success">Add</button>
        <div id="priceRangePanel" class="row g-2 mt-2 d-none">  ← R13 slider (inline styles, not .sales-price-band)
        <div class="border rounded-3 bg-light">Available (branch)</div>  ← pill (legacy uses .sales-stock-banner)
      </div>

      <div class="card border-0 shadow-sm">                ← Cart Items (Bootstrap card)
        <table class="table table-sm table-hover">
          <thead class="table-light">
            <th>Product | Qty | Rate | Total | Available | Action</th>  ← 6 cols (legacy would be 6 in dock)
          <tbody id="cartItemsBody"></tbody>
          <tfoot class="table-light"><tr>Subtotal</tr></tfoot>
        </table>
        <div class="sales-cart-mobile" id="cartItemsMobile"></div>  ← R17 mobile cards
        <div id="cartEmptyRow">Cart is empty — add a product above.</div>
      </div>

      <div class="card border-0 shadow-sm">                ← Cart Actions row (Bootstrap card)
        <button id="btnClear" class="btn btn-outline-danger">Clear Cart</button>
        <button id="btnSoftHold" class="btn btn-outline-warning">Soft Hold</button>
        <button id="btnValidate" class="btn btn-outline-info">Validate Cart</button>
        <button id="btnFinalize" class="btn btn-primary ms-auto">Finalize Invoice</button>
      </div>
    </div>

    <div class="col-12 col-lg-4">                          ← RIGHT (aside) — Laravel-only addition
      <div class="card border-0 shadow-sm">Cart Summary</div>
      <div class="card border-0 shadow-sm">Validation</div>
    </div>
  </div>

  <div class="sales-pos-sticky-bar" id="posStickyBar">…</div>  ← sticky bar (correctly uses legacy class)
</div>
```

Key differences vs legacy:
- **No `sales-create-app` wrapper** → CSS variables (`--sales-primary` etc.) don't get set; Laravel hardcodes `#4f46e5` in inline styles instead
- **Bootstrap `.card` everywhere** instead of `.sales-panel` — different border-radius (Bootstrap 0.375rem vs legacy 14px), different shadow, different header style
- **Select2 dropdowns** for customer + product search, instead of legacy text input + custom `.sales-suggest-list` dropdown
- **Layout is 8/4 (L→R)** instead of legacy **4/8 (Customer→Product)**
- **Right aside (Summary + Validation cards) is a Laravel addition** — legacy has no right sidebar
- **Cart items live in the LEFT column** in Laravel, not below as a full-width dock like legacy
- **Header text "Sales Cart"** with shopping-cart icon + two buttons (legacy: "New Sale" + one "Today" button)
- **R13 price-range slider uses inline styles** + Bootstrap utilities, not the `.sales-price-band*` classes from sales-pos.css
- **R14 live credit snapshot** is a Bootstrap card, not the dark `.sales-customer-due` box
- **Stock availability pill** uses Bootstrap `border rounded-3 bg-light`, not the teal-gradient `.sales-stock-banner`

---

## 6. CSS conflict table — classes defined in BOTH legacy file and Laravel inline block

| Selector | Legacy (sales-pos.css) | Laravel inline (cart.blade.php 598–746) | Conflict? | Resolution |
|---|---|---|---|---|
| `.sales-recents .btn` | L638–641 — `font-size:0.78rem; border-radius:999px` | L602–611 — adds `padding`, `background:#f3f4ff`, `border`, `color:#4f46e5`, transitions | **DUPLICATE** | Laravel overrides (loaded later) — keep Laravel version (it's an enhancement) |
| `.sales-pos-sticky-bar` | L789–800 — `position:fixed; bottom:0; z-index:1040; background:#fff; display:none` | L623–634 — same plus `env(safe-area-inset-bottom, 0px)` | **DUPLICATE** | Laravel version is the strict superset — keep |
| `.sales-pos-sticky-bar.visible` | L802–804 — `display:block` | L635–637 — same | **DUPLICATE** | Identical — drop from inline (legacy covers it) |
| `.sales-pos-sticky-bar .sticky-summary` | L806–809 — `font-size:1rem; font-weight:600` | L638–642 — same plus `line-height:1.2` | **DUPLICATE** | Laravel superset — keep |
| `.sales-pos-sticky-bar .sticky-summary .sticky-count` | (not in legacy) | L643–647 — `font-size:1.1rem; color:#4f46e5` | NEW | Laravel-only addition — keep |
| `.sales-pos-sticky-bar .sticky-summary .sticky-total` | (not in legacy) | L648–652 — `font-size:1.05rem; color:#059669` | NEW | Laravel-only addition — keep |
| `.sales-pos-sticky-bar .btn-finalize` | L811–815 — `min-height:48px; font-size:1.05rem; font-weight:600` | L653–659 — same plus explicit `padding-left/right:1.25rem` | **DUPLICATE** | Laravel superset — keep |
| `.sales-cart-desktop` | L741–743 — `display:block` | L672 — same | **DUPLICATE** | Identical — drop from inline |
| `.sales-cart-mobile` | L745–747 — `display:none` | L673 — same | **DUPLICATE** | Identical — drop from inline |
| `.sales-cart-line` | L749–755 — border, radius, padding, margin, bg | L675–684 — same plus `position:relative; overflow:hidden; transition` | **DUPLICATE** | Laravel superset (adds swipe-to-delete layout) — keep |
| `.sales-cart-line.swiping` | (not in legacy) | L685–688 — `transform:translateX(-80px); opacity:0.85` | NEW | Laravel-only — keep |
| `.sales-cart-line .line-title` | L757–761 — `font-size:1rem; font-weight:600; line-height:1.3` | L689–694 — same plus `word-break:break-word` | **DUPLICATE** | Laravel superset — keep |
| `.sales-cart-line .line-meta` | L763–766 — `font-size:0.9rem; color:#6b7280` | L695–699 — slightly smaller (0.85rem) plus margin-top | **DUPLICATE** | Slight conflict (0.9 vs 0.85) — Laravel wins, acceptable |
| `.sales-cart-line .line-total` | (not in legacy) | L700–703 — `font-weight:700; color:#059669` | NEW | Laravel-only — keep |
| `.sales-cart-line .cart-qty` / `.cart-rate` | (not in legacy) | L704–708 — `min-height:44px; font-size:16px` | NEW | Laravel-only — keep |
| `.sales-cart-line .cart-remove` | (not in legacy) | L709–712 — min dimensions | NEW | Laravel-only — keep |
| `.sales-cart-line::before` | (not in legacy) | L715–731 — red trash-icon swipe-delete background | NEW | Laravel-only — keep |
| `.sales-cart-line > *` | (not in legacy) | L732–736 — z-index stacking for swipe | NEW | Laravel-only — keep |
| `.sales-cart-line .line-title, .line-meta` override | (not in legacy) | L737–740 — `background:transparent` | NEW | Laravel-only — keep |
| `@media (max-width:767.98px) { .sales-cart-desktop / -mobile }` | L846–856 | L742–745 | **DUPLICATE** | Identical — drop from inline |

**Conclusion**: 11 selectors are duplicates. Loading both files is safe — Laravel inline wins by cascade order (loaded after the linked stylesheet), and Laravel's versions are supersets that preserve all legacy behavior plus add R15/R16/R17 enhancements.

---

## 7. Classes legacy HAS that Laravel cart blade is MISSING

These styles are **defined in `sales-pos.css` but NOT used by the Laravel cart blade** because the blade uses Bootstrap utility classes / inline styles instead. Linking the CSS file alone will NOT make them take effect — the HTML must also be restructured to use the legacy class names.

| Legacy class | Legacy purpose | Laravel current equivalent | Phase-3 work to adopt |
|---|---|---|---|
| `.sales-create-app` | Wrapper, sets CSS vars `--sales-primary` etc. | `<div class="container-fluid py-2" id="salesCartApp">` — no class | Add `class="sales-create-app"` to wrapper (or wrap inside it) |
| `.sales-create-header` | Indigo gradient header | Inline `style="background:linear-gradient(135deg,#7c3aed,#4f46e5)"` on `<header>` | Replace inline with class |
| `.sales-create-title` / `.sales-create-sub` / `.sales-header-btn` | Header title/subtitle/button | `<h1 class="h3 mb-1">` + `<p class="mb-0 opacity-75">` | Add legacy classes |
| `.sales-panel` / `.sales-panel-head` / `.sales-panel-body` | Rounded 14px card with surface-bg header | `<div class="card border-0 shadow-sm">` + `<div class="card-header bg-white">` + `<div class="card-body">` | Replace Bootstrap card with legacy panel class — **biggest visual impact** |
| `.sales-search-input` | 48px-tall input with indigo focus ring | `<select class="form-select select2">` | Either: (a) keep Select2 but apply `.sales-search-input` class to it, or (b) revert to legacy text input + custom dropdown |
| `.sales-suggest-list` / `.sales-suggest-item` | Custom autocomplete dropdown below search | Select2's built-in dropdown | Decision needed — see §9 below |
| `.sales-meta-grid` / `.col-span-2` | 2-col grid for Branch/Date/SalesBy/SalesPerson/Narration | Bootstrap `<div class="row g-2">` with cols | Replace with `.sales-meta-grid` |
| `.sales-customer-picker` / `.sales-change-customer` | Flex row: input + Change button | Just a Select2 dropdown (no Change button) | Add `.sales-customer-picker` wrapper and a Change button — but only if reverting to text input (§9) |
| `#customerSearch.is-locked` | Indigo-tinted locked state | Not used (Select2 doesn't have a locked state) | N/A unless reverting |
| `.sales-customer-due` | Dark slate box with credit limit / due / balance | R14 "live credit snapshot" Bootstrap card | Restyle R14 card to use `.sales-customer-due` — same data, different look |
| `.sales-stock-banner` / `.stock-banner-inner` / `.stock-stat` | Teal-gradient stock availability bar with large number | `<div class="border rounded-3 bg-light px-3 py-2">` pill | Replace pill with `.sales-stock-banner` |
| `.sales-price-band` + 11 child classes | Price-range slider with track / fill / thumb / default-mark / status | R13 slider using inline styles + Bootstrap utilities | Restructure R13 to use legacy `.sales-price-band*` classes — biggest CSS porting work |
| `.sales-entry-toolbar` / `.sales-entry-group` / `.sales-entry-rate` / `.sales-entry-qty` / `.sales-entry-label` / `.sales-entry-input` / `.sales-add-btn` | Flex toolbar for Rate/Qty/Add | Bootstrap `<div class="row g-2 align-items-end">` with form cols | Replace with `.sales-entry-toolbar` layout |
| `.sales-cart-dock` / `.sales-cart-dock-head` / `.sales-cart-tabs-wrap` / `.sales-cart-tabs` / `.sales-tab-pill` / `.sales-tab-link` / `.tab-badge` / `.close-tab-btn` | Multi-cart tabs dock with pill-style tabs | `<div class="card border-0 shadow-sm">` + `<ul class="nav nav-pills">` | Replace Bootstrap card+pills with `.sales-cart-dock` classes |
| `.sales-toast` / `@keyframes salesToastIn` | Green toast pill at top-center | SweetAlert2 toasts (different look) | Optional — SweetAlert2 works fine, legacy toast is cosmetic |
| `#productSuggestions .list-group-item` | Custom product dropdown item styling | Select2's built-in items | N/A unless reverting (§9) |
| `.sales-qty-stepper` | +/- quantity stepper buttons | Plain `<input type="number">` | Optional — Laravel uses inline edit in cart row, no stepper needed on entry |
| `.sales-pos-page` | Page padding-bottom to clear sticky bar | `body:has(#posStickyBar.visible) .container-fluid#salesCartApp { padding-bottom:5.5rem; }` + JS fallback | Already solved differently — no change needed |
| `.sales-edit-*` (8 classes) | Edit-draft-invoice page styling | (Different blade — `edit.blade.php`) | Out of scope for this audit (cart blade only) |
| `.sales-list-card` / `.sales-list-card .card-actions` | Today's-sales list cards | (Today's-sales blade, not cart) | Out of scope |
| `#godownItemsTable.godown-mobile-table` | Mobile table → card transform | (Different page) | Out of scope |

---

## 8. Classes Laravel HAS that legacy DOESN'T (Laravel-only extensions to preserve)

These are **R-feature enhancements** that must be preserved even after the legacy CSS is linked. All are defined in the inline `<style>` block at cart.blade.php L598–746.

| Selector | Purpose | Source |
|---|---|---|
| `.sales-recents .btn:hover` (transform) | R15 — hover lift effect on customer-recent chips | L612–615 |
| `.sales-recents .btn:active` (transform) | R15 — press feedback | L616–618 |
| `.sales-pos-sticky-bar .sticky-summary .sticky-count` | R16 — item count chip styling | L643–647 |
| `.sales-pos-sticky-bar .sticky-summary .sticky-total` | R16 — total amount styling | L648–652 |
| `body:has(#posStickyBar.visible) .container-fluid#salesCartApp` | R16 — modern `:has()` padding-bottom rule | L661–663 |
| `body.pos-sticky-visible .container-fluid#salesCartApp` | R16 — JS-class fallback for browsers without `:has()` | L665–667 |
| `.sales-cart-line.swiping` | R17 — swipe gesture state (card slides left to reveal delete) | L685–688 |
| `.sales-cart-line .line-total` | R17 — green bold total in mobile card | L700–703 |
| `.sales-cart-line .cart-qty` / `.cart-rate` | R17 — large tap targets for inline edits | L704–708 |
| `.sales-cart-line .cart-remove` | R17 — square remove button | L709–712 |
| `.sales-cart-line::before` | R17 — red trash-icon background revealed by swipe | L715–731 |
| `.sales-cart-line > *` (z-index) | R17 — stacking context so children sit above the ::before | L732–736 |
| `.sales-cart-line .line-title, .line-meta` (bg:transparent) | R17 — let the red ::before show through text | L737–740 |

**Preservation strategy**: These stay in the inline `<style>` block (or get extracted to `sales-cart-laravel.css` in Phase 2). Load order: `sales-pos.css` first, Laravel extensions second — Laravel extensions win by cascade and never need `!important`.

---

## 9. Architectural decision points (need user input before Phase 3)

### Decision A — Customer/Product search: Select2 vs legacy text-input+autocomplete

The Laravel cart uses **Select2 AJAX dropdowns** for both customer and product search. Legacy uses **plain text inputs with a custom `.sales-suggest-list` dropdown** wired up by `sales-create.js`.

| Option | Pros | Cons |
|---|---|---|
| **A1 — Keep Select2, just style it** | Less JS rework; Select2 has better accessibility, keyboard nav, remote search debouncing | Won't look exactly like legacy dropdown items; `.sales-suggest-list` styles unused |
| **A2 — Revert to legacy text input + custom dropdown** | Pixel-faithful to legacy; uses `.sales-suggest-list` styles | Requires porting `sales-create.js` autocomplete logic (or replicating in Laravel JS); loses Select2's i18n/accessibility |
| **A3 — Hybrid: keep Select2 internals, override its CSS to mimic `.sales-suggest-item`** | Best of both — Select2 mechanics, legacy look | Most CSS work; brittle (Select2 markup may change between versions) |

**Recommendation**: **A1** for Phase 1–2 (instant visual win), revisit A3 in Phase 5 if the Select2 dropdown still looks too different.

### Decision B — Layout: keep right aside or move summary below table?

Legacy has **no right aside** — Subtotal/Payable/Transport/Discount live in a row below the cart table. Laravel added a right aside with Summary + Validation cards (an enhancement).

| Option | Pros | Cons |
|---|---|---|
| **B1 — Keep right aside, just restyle to look like legacy panels** | Preserves Laravel's at-a-glance summary; less HTML rework | Not pixel-faithful to legacy |
| **B2 — Move Summary below the cart table, remove right aside** | Pixel-faithful to legacy; cart table goes full-width | Loses always-visible summary; Validation card needs a new home (alert banner above table?) |
| **B3 — Keep right aside on desktop, collapse to below-table on mobile** | Responsive compromise | More CSS work; legacy doesn't do this |

**Recommendation**: **B1** for Phase 1–2 (less rework), decide on B2 vs B3 in Phase 3 based on user preference.

### Decision C — R13 price-range slider: inline styles or `.sales-price-band*` classes?

Legacy `sales-pos.css` defines a complete `.sales-price-band*` class family (L249–402, ~150 lines). Laravel's R13 port replicates the same visual using **Bootstrap utilities + inline `style="..."` attributes** on each element.

| Option | Pros | Cons |
|---|---|---|
| **C1 — Leave R13 as-is (inline styles)** | No rework; R13 already works | Doesn't benefit from `sales-pos.css` theming; if user changes `--sales-primary` later, R13 won't follow |
| **C2 — Restructure R13 to use `.sales-price-band*` classes** | Uses legacy theming; cleaner blade; matches legacy exactly | Requires rewriting the R13 HTML in the blade + removing inline styles |

**Recommendation**: **C2** in Phase 3 (when we're already restructuring the product panel).

### Decision D — Header text and buttons

Legacy header: "New Sale" + subtitle "Fast billing · multiple customers · live stock" + single "Today" button.
Laravel header: "Sales Cart" + branch/subtitle + "Customers" + "Products" buttons.

| Option | Description |
|---|---|
| **D1** | Adopt legacy text "New Sale" + subtitle; replace Laravel's two buttons with the single "Today" button |
| **D2** | Keep Laravel text "Sales Cart" + Laravel buttons; just apply `.sales-create-header` class for the gradient look |
| **D3** | Keep Laravel text but add the "Today" button alongside (three buttons) |

**Recommendation**: **D2** — Laravel's "Customers"/"Products" quick-links are useful, keep them. Just apply the legacy class for visual consistency.

---

## 10. Phase 0 deliverables checklist

- [x] Read legacy `sales/create.php` end-to-end (186 lines)
- [x] Read legacy `sales-pos.css` end-to-end (960 lines)
- [x] Read Laravel cart blade inline `<style>` block (lines 598–746)
- [x] Read Laravel cart blade HTML structure (lines 1–572)
- [x] Build conflict table (§6) — 11 duplicate selectors, all safe to keep both
- [x] List legacy classes missing from Laravel (§7) — 18 class families need HTML restructuring
- [x] List Laravel-only extensions to preserve (§8) — 13 R-feature selectors
- [x] Identify 4 architectural decision points needing user input (§9)
- [x] Snapshot of current Laravel cart exists (provided by user: `Remote-Center-ERP-—-Sales-Cart-07-22-2026_07_07_AM.png`)
- [x] Snapshot of legacy cart exists (provided by user: `Remote-Center-ERP-Create-Sales-Invoice-07-22-2026_06_55_AM.png`)
- [x] VLM-generated Top-10 visual diff (in conversation history)

---

## 11. Recommended next step — Phase 1

**Phase 1 action**: Add `<link rel="stylesheet" href="/assets/css/sales-pos.css">` (and `sales-receive-payment.css` for the modal) to the top of the `@push('css')` block in `cart.blade.php`.

**Expected outcome**: ~40–60% visual jump toward legacy look. The sticky bar, mobile cart cards, and customer-recent chips will look identical (they already use the right class names). The Bootstrap cards / Select2 dropdowns / inline-styled header **will not change much** — those need Phase 3 HTML restructuring.

**Risk**: Low. The 11 duplicate selectors are all Laravel-supersets, so cascade order (legacy first, Laravel inline second) produces the correct merged result. No `!important` wars expected.

**Time estimate**: 30 minutes (the edit + a screenshot refresh + documenting what changed).

---

## 12. Audit metadata

- **Auditor**: Z.ai assistant (GLM)
- **Audit date**: 2026-07-22
- **Project**: RC_ERP Laravel port (`/home/z/my-project/debugRC`)
- **Reference repo**: `https://github.com/sajidchowdhury/debugRC.git`
- **Related docs**: `sales_entry_Lg_vs_La.md`, `SESSION_CONTEXT.md`, `worklog.md`, `STYLE_PARITY_PLAN.md` (to be written)
