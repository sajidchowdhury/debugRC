#!/usr/bin/env python3
"""Append R15/R16/R17/R10s sections to docs/REMEDIATION_LOG.md."""
from pathlib import Path

LOG_PATH = Path('/home/z/my-project/debugRC/docs/REMEDIATION_LOG.md')

NEW_SECTIONS = r"""

---

## §R15 — Port customer recents chips (localStorage)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #7 — "Customer recents chips (last 5 in localStorage)"

### Problem

Legacy renders click-to-pick chips beneath the customer search box
for the last 5 customers the cashier picked. The chips persist
across page reloads via `localStorage["sales_customer_recents"]`.
The Laravel cart blade had no equivalent — every customer pick
required re-typing the name (or scrolling the Select2 dropdown).
For repeat-customer workflows (the same shopkeeper coming back
twice in an hour), this is a real friction point.

### Decision

Port the Legacy pattern faithfully:
- One chip per recent customer, capped at 5.
- Click a chip → switch to that customer (reusing R11's
  `switchToCustomer()` flow — Select2 value + tab ensure + cart
  load + credit fetch).
- localStorage key namespaced `rcerp_sales_customer_recents` to
  avoid cross-tenant contamination on a shared deploy.
- Storage shape: `[{id:int, label:string, ts:int(unix_ms)}, ...]`
  (deduped by id, most-recent-first).
- Storage failures (private mode, quota) caught + warned —
  non-fatal; chips just won't persist across sessions.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#customerRecentsRow` (d-none by default) + `#customerRecents`
    chip container in the customer selector card (below the Select2,
    above the credit panel).
  - New JS: `CUSTOMER_RECENTS_KEY`, `CUSTOMER_RECENTS_MAX`,
    `rememberCustomerRecent(id, label)`, `loadCustomerRecents()`,
    `renderCustomerRecents()`.
  - `#customerSelect` change handler now also calls
    `rememberCustomerRecent(cid, label)` + `renderCustomerRecents()`.
  - Delegated click handler on
    `#customerRecents .btn[data-customer-id]` calls
    `switchToCustomer(cid)` (R11's flow).
  - Bootstrap: `renderCustomerRecents()` is called on page load +
    the server-rendered pre-selected customer is also remembered.
  - CSS in the `@push('css')` block styles the chips with a
    pill-shape (border-radius:999px) + indigo accent.

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — clicking a chip reuses R11's
  `switchToCustomer()` + the existing `/cart/load` endpoint.
- No expiry / TTL on entries — Legacy doesn't have one either.
  The 5-entry cap is the implicit cleanup.
- The Laravel API V1 (`SalesCartApiController`) was NOT touched.
  Recents are a per-cashier UX concern; the API tier has no
  equivalent (and shouldn't — server-side recents storage would
  require a new table for marginal value).
- The Legacy cart-draft backup (`saveCartDraftBackup` /
  `restoreCartIfNeeded` — sales.js L1382–1419) is NOT ported.
  Laravel is DB-backed so the cart survives a page reload natively.
  A localStorage backup would only matter for offline support,
  which is a separate feature (R28 PWA installability).

### Verification

- File integrity: blade braces balanced (478/478), parens balanced
  (1504/1504), `@if/@endif` balanced (7/7), `@push/@endpush`
  balanced (2/2 — the 3rd `@push` match is inside a JS comment).
- All 79 element IDs in the blade are unique (no duplicates after
  adding `#customerRecentsRow`, `#customerRecents`).
- All 5 new functions defined exactly once:
  `rememberCustomerRecent`, `loadCustomerRecents`,
  `renderCustomerRecents`, plus R16/R17 functions documented in
  their own sections below.

### Risks introduced

- **Very low.** localStorage is well-supported; failures are
  caught + warned. Worst case: chips don't persist — the rest of
  the page works normally.
- **Privacy consideration:** localStorage is per-browser, per
  -origin. On a shared kiosk the chips would be visible to the
  next user. Mitigation: the chips show only customer names
  (already visible to any authenticated user); no sensitive data
  (no balances, no contact info beyond what's in the customer
  name label). For a kiosk deployment, a "Clear recents" button
  could be added (low priority — not in this commit).

### Follow-ups

- Consider adding a "Clear recents" link next to the chips for
  kiosk deployments.
- Consider raising the cap from 5 to 8 if the cashier regularly
  handles more repeat customers per shift. Mirrors Legacy for
  now (5 is the existing UX calibration).

---

## §R16 — Port sticky bottom bar (item count + grand total + Finalize)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #9 — "Sticky bottom bar (item count + grand total + Finalize always visible)"

### Problem

Laravel's Finalize button lives in the "Cart actions" card below
the cart table. On long carts the cashier has to scroll past 20+
rows to reach it. Legacy solves this with a fixed-position bottom
bar that always shows the item count + grand total + a Finalize
button — one tap away from any scroll position.

### Decision

Port the Legacy `#posStickyBar` pattern:
- Fixed-position bottom bar (`position: fixed; bottom: 0`).
- Shows `<N items · ৳X`> on the left, Finalize button on the right.
- Button enabled iff cart is valid (mirrors the in-page
  `#btnFinalize` disabled logic).
- Clicking the button calls the SAME `finalizeInvoice()` function
  as the in-page button — no duplicated logic, no separate endpoint.
- Bar hidden when cart is empty or no customer is selected.
- iOS safe-area inset respected (`env(safe-area-inset-bottom)`)
  so the bar isn't clipped by the home indicator on notched
  devices.
- Page gets extra `padding-bottom: 5.5rem` while the bar is
  visible so the last cart row isn't covered.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - New `#posStickyBar` HTML block (outside the container, before
    `@endsection`).
  - New `@push('css')` block (the cart blade's first CSS push)
    with the sticky bar styles + the page-padding-bottom rules.
  - New JS `updatePosStickyBar()` function called from
    `renderAll()` on every cart mutation.
  - `#posStickyFinalize` click handler wired to
    `finalizeInvoice()` (same as `#btnFinalize`).
  - Customer-change handler now calls `updatePosStickyBar()` when
    the customer is cleared (hides the bar).
  - Bootstrap: `updatePosStickyBar()` called on initial render.

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — the sticky Finalize button calls the
  existing `finalizeInvoice()` function.
- The in-page `#btnFinalize` button is NOT removed — both
  buttons coexist. The in-page button is useful for cashiers
  who prefer the "Cart actions" card layout; the sticky bar is
  for cashiers who prefer the always-visible pattern. Legacy
  has both too (the in-page `finalSubmitBtn` + the sticky
  `#posStickyFinalize`).
- The bar is hidden (not "visible but disabled") when the cart
  is empty. Legacy keeps it visible with opacity 0.85; we
  diverged because the "always visible but disabled" pattern
  can confuse users into thinking the button is broken.

### Verification

- File integrity: blade braces + parens + directives balanced
  (see R15 verification).
- New element IDs unique: `#posStickyBar`, `#posStickySummary`,
  `#posStickyFinalize`.
- New function `updatePosStickyBar()` defined exactly once.
- CSS `@media` query not needed for the sticky bar — it's
  visible on all screen sizes (Legacy's behaviour).

### Risks introduced

- **Low.** The bar is purely additive UI; it can't block cart
  mutations or finalize. Worst case: a CSS conflict with the
  existing layout — mitigated by the high z-index (1040, below
  SweetAlert's 10000+) and the page-padding-bottom rule.
- **Mobile overlap risk:** the bar covers 5.5rem at the bottom
  of the viewport. The page-padding-bottom rule ensures the
  last cart row is never covered. R17's mobile cart cards have
  their own padding so they don't overlap either.
- **`:has()` browser support:** Chrome 105+, Safari 15.4+,
  Firefox 121+. The JS-added `body.pos-sticky-visible` class is
  the fallback for older browsers — same padding-bottom rule
  applies via the `.pos-sticky-visible` selector.

### Follow-ups

- Consider adding a "soft-hold" toggle button to the sticky bar
  (Legacy doesn't have this, but it would be a natural extension).
- Consider showing the projected new balance (from R14's credit
  snapshot) in the sticky bar when the customer has a credit
  limit. Would give the cashier a one-glance "can I finalize?"
  signal. Low priority — R14's panel already shows this.

---

## §R17 — Port mobile-cart cards with swipe-to-delete

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #10 — "Mobile-cart cards with swipe-to-delete"

### Problem

Laravel renders cart items as a `<table>` with `.table-responsive`
wrapping. On mobile this means horizontal scrolling, tiny inputs
(`form-control-sm`), and a "Delete" button that's hard to tap.
Legacy solves this with a card-based layout that swaps in below
768px viewport width + a swipe-left-to-delete gesture (80px
threshold, matches iOS Mail / Messages conventions).

### Decision

Port the Legacy pattern with one modernization: use Pointer
Events instead of Touch Events (broader input coverage, simpler
code).

- Cart items render in TWO views simultaneously: a desktop `<tbody>`
  (existing) + a new `#cartItemsMobile` div of `.sales-cart-line`
  cards. CSS media query (`max-width: 767.98px`) toggles which is
  visible.
- Both views share the same `.cart-qty` / `.cart-rate` /
  `.cart-remove` / `.cart-total` classes — the existing delegated
  handlers work for both, no duplicated logic.
- Mobile cards have 44px-min tap targets + 16px font size (iOS
  no-zoom threshold).
- Swipe gesture: 80px left within 600ms triggers the existing
  `.cart-remove` click handler. A red `::before` pseudo-element
  with a trash icon is revealed behind the card during the swipe
  (visual affordance).

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - Wrapped the existing desktop `<table>` in
    `<div class="sales-cart-desktop table-responsive">`.
  - Added sibling `<div class="sales-cart-mobile" id="cartItemsMobile">`.
  - `renderCartTable()` now builds BOTH a `<tr>` (desktop) and a
    `<div class="sales-cart-line">` (mobile) per cart item, in
    the same loop.
  - Generalized `debouncedUpdate(productId)` from
    `$('#cartItemsBody tr[data-product-id="X"]')` to
    `$('[data-product-id="X"]').first()`.
  - Generalized the `.cart-remove` click handler from
    `closest('tr')` to `closest('[data-product-id]')`.
  - Generalized the `.cart-qty, .cart-rate` `input change`
    handler to update `.cart-total` cells in ALL views of the
    same product (desktop + mobile stay in sync during optimistic
    local updates).
  - New JS `initCartSwipeRemove()` is called at the end of every
    `renderCartTable()` (touch handlers don't survive
    `$mobile.empty()`). Uses Pointer Events:
    - `pointerdown` records startX + startedAt (only fires for
      touch/pen, not mouse).
    - `pointermove` translates the card left by the delta (clamped
      to -120px) + adds `.swiping` CSS class for visual feedback.
    - `pointerup` checks if delta < -80px AND elapsed < 600ms →
      triggers `.cart-remove` click (which calls existing
      `removeItem()` → SweetAlert confirm → server call).
    - `pointercancel` resets the card position.
  - CSS in the `@push('css')` block:
    - `.sales-cart-desktop { display:block; }` (default)
    - `.sales-cart-mobile { display:none; }` (default)
    - `@media (max-width: 767.98px)` swaps them.
    - `.sales-cart-line` card styling: border, border-radius:10px,
      padding:0.75rem, margin:0.5rem, position:relative,
      overflow:hidden, transition:transform .2s ease.
    - `.sales-cart-line::before` red pseudo-element with trash
      icon (`\f1f8`) — revealed as the card slides left.
    - `.sales-cart-line > *` gets `position:relative; z-index:1;
      background:#fff;` to sit above the red pseudo-element.
    - `.sales-cart-line .cart-qty, .cart-rate { min-height:44px;
      font-size:16px; }` (iOS no-zoom + accessible tap target).

### What was NOT changed

- No backend changes — purely client-side.
- No new endpoint — the swipe gesture triggers the existing
  `.cart-remove` button's click handler.
- The desktop table is NOT removed — both views coexist. CSS
  media query determines which is visible.
- The Laravel sales invoice **edit** page is NOT touched.
  Legacy has mobile cards only on the create page; R17 matches
  that scope.
- The Legacy qty stepper (− button + display + + button) is NOT
  ported — Laravel uses a regular `<input type="number">` for
  qty, which works fine on mobile (the browser's native stepper
  handles +/−). Legacy needed the custom stepper because its
  qty display was a non-input `<span>`.
- The Legacy `cart-line` "delete-item" button is NOT separately
  wired — we reuse the existing `.cart-remove` class so the same
  SweetAlert confirm flow applies to both desktop and mobile.

### Verification

- File integrity: blade braces + parens + directives balanced.
- New element ID `#cartItemsMobile` unique.
- New function `initCartSwipeRemove()` defined exactly once.
- Mobile card HTML shares classes with desktop row HTML →
  existing delegated handlers cover both.
- `pointermove` clamped to -120px max so the card can't be
  dragged off-screen.
- `pointercancel` resets the card position (e.g., if a
  notification interrupts the gesture).

### Risks introduced

- **Medium.** Touch gestures can conflict with the page's
  horizontal scroll. Mitigation: the gesture only fires when
  the user starts a swipe LEFT (delta < 0); rightward swipes
  + vertical scrolls are ignored. The 600ms time limit also
  filters out slow drags (which are likely repositioning, not
  delete gestures).
- **Accidental delete risk:** mitigated by the SweetAlert
  confirm dialog (already in `removeItem()`). The swipe just
  triggers the click; the user still has to confirm.
- **Pointer Events browser support:** Chrome 55+, Safari 13+,
  Firefox 59+. Effectively universal in 2026.
- **Z-index stacking:** the red `::before` pseudo-element sits
  at z-index:0; the card content sits at z-index:1. The card
  itself has `overflow:hidden` so the red is hidden until the
  card slides left.

### Follow-ups

- Consider porting the Legacy qty stepper (− / display / +) for
  the mobile card — some cashiers prefer tap-to-increment over
  typing. Low priority; the native `<input type="number">`
  stepper works fine.
- Consider adding a "long-press to delete" alternative gesture
  for users who can't swipe (e.g., trackpad users on desktop).
  Low priority; the explicit Delete button is always visible in
  the card header.
- Consider porting the same mobile-card pattern to the sales
  invoice edit page. Legacy doesn't have it there; matching
  scope for now.

---

## §R10s — Barcode scanning simplified (single product search box)

**Status:** ✅ Done (2026-07-22)
**Audit reference:** §6.1 item #1 — supersedes R10's dual-mode UI

### Problem

R10 wired up barcode scanning with a separate `#barcodeInput` field
(toggle-revealed via `#btnToggleBarcode` in the card header) + a
"Scan & Add" button + an "Auto-add after scan" checkbox. This
duplicated the Select2 product search box and made the Add Product
card feel cluttered — two ways to do the same thing (find a
product by code).

The user's brief was explicit:
> "about Port barcode scanning: keep only product search just like
> customer search, no need 2 option searching product and scan,
> just keep search product and make the UI/UX better like lagachy"

### Decision

Consolidate to a single Select2 search box that doubles as the
barcode entry. Two layers of barcode support:

1. **Primary path (no extra JS):** The R1 AJAX search endpoint
   matches on `product_code` via ILIKE. Barcode scanners type
   the code rapidly + Enter; Select2's 250ms debounce catches
   the full scan; `selectOnClose: true` (newly added) makes
   Enter pick the highlighted first result.

2. **Fallback path (delegated keydown handler):** If the user
   types/scans a code that returns NO matches from the ILIKE
   search, intercept Enter on the Select2 search input and fire
   an exact-match lookup against the R1 `productByCode`
   endpoint. On success: inject the matched product as a fresh
   `<option>` + select it + trigger `change` (so rate/qty/price
   -band/availability populate via the existing handlers) + focus
   `#addQty`.

### Files modified

- `laravel/resources/views/admin/sales/cart.blade.php`:
  - REMOVED: `#btnToggleBarcode` button from the Add Product card
    header.
  - REMOVED: entire `#barcodeRow` HTML block (input + hint +
    "Scan & Add" button + auto-add checkbox).
  - REMOVED: all R10 JS — `$barcodeInput`, `$barcodeHint`,
    `$barcodeAutoAdd` vars; `#btnToggleBarcode` click handler;
    `#barcodeInput` keydown handler; `#btnBarcodeAdd` click
    handler; the entire `scanAndSelect()` function (~110 lines).
  - ADDED: `selectOnClose: true` to the `#addProduct` Select2 init.
  - ADDED: delegated `keydown` handler on `.select2-search__field`
    that intercepts Enter when the dropdown belongs to
    `#addProduct` AND no result is highlighted → calls new
    `lookupProductByCodeAndSelect(term)` function.
  - ADDED: `lookupProductByCodeAndSelect(code)` function (~50
    lines) that fetches the R1 `productByCode` endpoint, on
    success injects the matched product as a fresh `<option>` +
    selects it + triggers `change` + focuses `#addQty`. On
    failure, shows a toast + reopens the Select2 dropdown so
    the user can re-search.
  - UPDATED: `#addProduct` Select2 placeholder changed from
    "— Type product name / code —" to "— Type name / scan code —"
    to make the dual-purpose nature clear.
  - ADDED: a small `<span class="badge bg-light text-secondary">`
    next to the "Product" label that says "scan ok" with a
    barcode icon, so the cashier knows the field accepts scanner
    input.

### What was NOT changed

- No backend changes — same R1 endpoints
  (`admin.sales.cart.search-product` +
  `admin.sales.cart.product-by-code`).
- No new route — the fallback uses the existing `productByCode`
  endpoint via `ajaxGet`.
- No new migration — purely client-side.
- The R1 `productByCode` controller + service code is unchanged.
- The R10 "auto-add after scan" behaviour is NOT preserved. The
  R10s flow stops at "product selected, rate filled, qty focused"
  — the cashier reviews the rate/qty and clicks "Add" themselves.
  This is safer (no accidental adds from mis-scans) and matches
  Legacy's `selectProductCreate` behaviour (which also doesn't
  auto-add). If the user later wants auto-add back, it's a 2-line
  addition: append `addToCart();` to `lookupProductByCodeAndSelect`.

### Verification

- File integrity: blade braces + parens + directives balanced.
- All R10 element IDs (`#barcodeInput`, `#barcodeHint`,
  `#barcodeAutoAdd`, `#btnBarcodeAdd`, `#btnToggleBarcode`,
  `#barcodeRow`) are GONE from the file (0 references).
- The `scanAndSelect` function is GONE (0 references).
- New function `lookupProductByCodeAndSelect` defined exactly once.
- New `selectOnClose: true` option added to the `#addProduct`
  Select2 init.
- The delegated `keydown` handler correctly filters to only
  intercept Enter on the `#addProduct` Select2 (via
  `aria-controls` attribute check).

### Risks introduced

- **Low.** The fallback path is purely additive — if the
  delegated handler fails for any reason, the user can still
  type a code, see no results, and click away. The Select2
  behaves normally.
- **Scanner timing risk:** if a scanner's Enter arrives BEFORE
  the AJAX search resolves (extremely fast scanner + slow
  network), the highlighted-result check would find nothing
  (because results haven't loaded yet) → the fallback
  `productByCode` lookup fires. This is actually MORE robust
  than the R10 behaviour (which would have shown "No results"
  and required the user to click "Scan & Add" manually).
- **`aria-controls` attribute reliance:** Select2 v4.1 sets
  `aria-controls="select2-addProduct-results"` on the search
  input. If a future Select2 version changes this attribute
  naming, the filter would break and Enter would be intercepted
  on ALL Select2 search boxes (including the customer search).
  Mitigation: the handler checks `resultsId.indexOf('addProduct')`
  which is permissive enough to catch minor naming changes.

### Follow-ups

- If the user reports that scans sometimes don't resolve (because
  the scanner is faster than the 250ms debounce), consider
  lowering the Select2 `delay` from 250ms to 150ms. Trade-off:
  more AJAX requests while typing.
- If the user wants auto-add back, append `addToCart();` to the
  end of `lookupProductByCodeAndSelect` (after the focus call).
  This would restore R10's "scan → item appears in cart" flow.
- Consider porting the same single-box pattern to the sales
  invoice edit page (currently uses the old R10 dual-mode UI).
  Out of scope for this commit.
"""

with LOG_PATH.open('a') as f:
    f.write(NEW_SECTIONS)

print(f"Appended {len(NEW_SECTIONS)} chars to {LOG_PATH}")
print(f"New file size: {LOG_PATH.stat().st_size} bytes")
